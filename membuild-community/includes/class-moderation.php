<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Keeping the community safe: reports, the moderation queue, pinned posts,
 * and muting or banning members.
 *
 *   POST   /reports                                     { object_type, object_id, reason, note }
 *   GET    /moderation/reports                          ?status=open|resolved&page=   (staff)
 *   POST   /moderation/reports/{post|comment}/{id}      { action: keep|remove }       (staff)
 *   GET    /moderation/members                          muted and banned members      (staff)
 *   POST   /members/{id}/restriction                    { status: muted|banned|none, days }  (staff)
 *   PUT    /posts/{id}/pin   DELETE /posts/{id}/pin     up to MAX_PINS posts           (staff)
 *
 * A post reported by AUTO_HIDE different members is hidden until a moderator looks at it.
 * Muted members can read and react but can't post, comment, share stories or send messages.
 * Banned members can't open the community at all. Admins and moderators can't be restricted.
 */
class Moderation {

	/** Report reasons, translated: key => label. */
	public static function reasons() {
		return array(
			'spam'       => __( 'Spam', 'membuild-community' ),
			'harassment' => __( 'Harassment or bullying', 'membuild-community' ),
			'hate'       => __( 'Hate speech', 'membuild-community' ),
			'nudity'     => __( 'Nudity or sexual content', 'membuild-community' ),
			'violence'   => __( 'Violence or threats', 'membuild-community' ),
			'false'      => __( 'False information', 'membuild-community' ),
			'other'      => __( 'Something else', 'membuild-community' ),
		);
	}

	const AUTO_HIDE = 3;
	const MAX_PINS  = 3;
	const PAGE_SIZE = 20;
	const META      = 'mbcom_restriction';

	/* ---------- Member restrictions ---------- */

	/** array( status => muted|banned, until => ISO or null, reason, by, at ) or null. Expired mutes clear themselves. */
	public static function restriction( $user_id ) {
		$r = get_user_meta( (int) $user_id, self::META, true );
		if ( ! is_array( $r ) || empty( $r['status'] ) ) {
			return null;
		}
		if ( ! empty( $r['until'] ) && strtotime( $r['until'] ) <= time() ) {
			delete_user_meta( (int) $user_id, self::META );
			return null;
		}
		return $r;
	}

	/**
	 * Everyone muted or banned right now, newest first: user_id => restriction.
	 * One query, however many there are; mutes that have ended are cleared on the way.
	 */
	private static function all_restrictions() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, meta_value FROM %i WHERE meta_key = %s', $wpdb->usermeta, self::META ), ARRAY_A ) as $row ) {
			$r = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $r ) || empty( $r['status'] ) ) {
				continue;
			}
			if ( ! empty( $r['until'] ) && strtotime( $r['until'] ) <= time() ) {
				delete_user_meta( (int) $row['user_id'], self::META );
				continue;
			}
			$out[ (int) $row['user_id'] ] = $r;
		}
		uasort( $out, fn( $a, $b ) => strcmp( (string) ( $b['at'] ?? '' ), (string) ( $a['at'] ?? '' ) ) );
		return $out;
	}

	public static function is_banned( $user_id ) {
		return 'banned' === ( self::restriction( $user_id )['status'] ?? '' );
	}

	/**
	 * Call before anything that creates content. Returns a WP_Error for muted
	 * (or banned) members, null when they may go ahead.
	 */
	public static function guard( $user_id = null ) {
		$r = self::restriction( $user_id ?? get_current_user_id() );
		if ( ! $r ) {
			return null;
		}
		$msg = 'banned' === $r['status']
			? __( 'You no longer have access to this community.', 'membuild-community' )
			: ( $r['until']
				/* translators: %s: date and time the mute ends */
				? sprintf( __( 'You’re muted until %s, so you can read and react but not post.', 'membuild-community' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r['until'] ) ) )
				: __( 'You’re muted, so you can read and react but not post.', 'membuild-community' ) );
		return new \WP_Error( 'mbcom_restricted', $msg, array( 'status' => 403 ) );
	}

	/** For the app: the current member's own restriction, if any. */
	public static function my_restriction() {
		$r = self::restriction( get_current_user_id() );
		return $r ? array( 'status' => $r['status'], 'until' => $r['until'] ) : null;
	}

	/* ---------- Routes ---------- */

	public static function register_routes() {
		$member = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		$staff  = fn() => Rest_Feed::logged_in() && Helpers::can_moderate();

		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/reports', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'report' ), 'permission_callback' => $member ) );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/moderation/reports',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'queue' ),
				'permission_callback' => $staff,
				'args'                => array(
					'status' => array( 'enum' => array( 'open', 'resolved' ), 'default' => 'open' ),
					'page'   => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/moderation/reports/(?P<type>post|comment|member)/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'resolve' ), 'permission_callback' => $staff ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/moderation/members', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'restricted' ), 'permission_callback' => $staff ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/members/(?P<id>\d+)/restriction', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'restrict' ), 'permission_callback' => $staff ) );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/pin',
			array(
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'pin' ), 'permission_callback' => $staff ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'unpin' ), 'permission_callback' => $staff ),
			)
		);
	}

	/* ---------- Reporting ---------- */

	/** The reported thing: array( author_id, post_id, status ) or null. */
	private static function target( $type, $id ) {
		global $wpdb;
		if ( 'member' === $type ) {
			// A profile: the member themselves. Staff profiles can't be reported here.
			return get_userdata( $id ) && ! Settings::is_staff( $id ) ? array( 'author_id' => (int) $id, 'post_id' => 0, 'status' => 'published' ) : null;
		}
		if ( 'post' === $type ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, status FROM %i WHERE id = %d', Install::table( 'posts' ), $id ), ARRAY_A );
			return $row && 'deleted' !== $row['status'] ? array( 'author_id' => (int) $row['user_id'], 'post_id' => (int) $row['id'], 'status' => $row['status'] ) : null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, post_id FROM %i WHERE id = %d', Install::table( 'comments' ), $id ), ARRAY_A );
		return $row ? array( 'author_id' => (int) $row['user_id'], 'post_id' => (int) $row['post_id'], 'status' => 'published' ) : null;
	}

	public static function report( \WP_REST_Request $req ) {
		global $wpdb;
		$me     = get_current_user_id();
		$type   = in_array( $req['object_type'], array( 'comment', 'member' ), true ) ? $req['object_type'] : 'post';
		$id     = (int) $req['object_id'];
		$reason = isset( self::reasons()[ $req['reason'] ] ) ? $req['reason'] : null;
		$target = self::target( $type, $id );

		if ( ! $target ) {
			return new \WP_Error( 'mbcom_not_found', __( 'This was already removed.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$slow = Helpers::throttle( 'report', 20, HOUR_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		if ( ! $reason ) {
			return new \WP_Error( 'mbcom_invalid', __( 'Pick a reason.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( $target['author_id'] === $me ) {
			return new \WP_Error( 'mbcom_invalid', __( 'You can’t report yourself or your own posts.', 'membuild-community' ), array( 'status' => 400 ) );
		}

		$table = Install::table( 'reports' );
		// One report per member per item; reporting again just updates the reason.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (object_type, object_id, post_id, author_id, reporter_id, reason, note, status, created_at)
				VALUES (%s, %d, %d, %d, %d, %s, %s, 'open', %s)
				ON DUPLICATE KEY UPDATE reason = VALUES(reason), note = VALUES(note), status = 'open', resolved_by = NULL, resolved_at = NULL",
				$table,
				$type,
				$id,
				$target['post_id'],
				$target['author_id'],
				$me,
				$reason,
				Helpers::clean_text( $req['note'] ?? '', 500 ),
				Helpers::now()
			)
		);

		$hidden = false;
		// Staff posts are never hidden automatically: three accounts shouldn't be able to silence an admin.
		if ( 'post' === $type && 'published' === $target['status'] && ! Settings::is_staff( $target['author_id'] ) ) {
			$reporters = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT reporter_id) FROM %i WHERE object_type = 'post' AND object_id = %d AND status = 'open'", $table, $id ) );
			if ( $reporters >= self::AUTO_HIDE ) {
				$wpdb->update( Install::table( 'posts' ), array( 'status' => 'review' ), array( 'id' => $id ) );
				$hidden = true;
			}
		}
		/**
		 * A member reported a post, comment or profile.
		 *
		 * @param string $object_type "post", "comment" or "member".
		 * @param int    $object_id   What was reported (a user ID for "member").
		 * @param int    $reporter_id Who reported it.
		 * @param string $reason      One of Moderation::reasons().
		 */
		do_action( 'membuild_community/reported', $type, $id, $me, $reason );
		return array( 'reported' => true, 'hidden' => $hidden );
	}

	public static function open_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT object_type, object_id) FROM %i WHERE status = %s', Install::table( 'reports' ), 'open' ) );
	}

	/** The number on each Moderation tab: to review, resolved, and muted or banned now. */
	public static function counts() {
		global $wpdb;
		$resolved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT object_type, object_id) FROM %i WHERE status IN ('kept','removed','muted','banned')", Install::table( 'reports' ) ) );
		return array( 'open' => self::open_count(), 'resolved' => $resolved, 'members' => count( self::all_restrictions() ) ); // ended mutes don't count
	}

	/* ---------- The queue ---------- */

	public static function queue( \WP_REST_Request $req ) {
		global $wpdb;
		$table  = Install::table( 'reports' );
		$open     = 'open' === $req['status'];
		$statuses = $open ? array( 'open' ) : array( 'kept', 'removed', 'muted', 'banned' );
		$limit    = self::PAGE_SIZE + 1;
		$offset   = ( (int) $req['page'] - 1 ) * self::PAGE_SIZE;

		// One card per reported item, however many people reported it.
		if ( $open ) {
			$groups = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_type, object_id, MAX(post_id) AS post_id, MAX(author_id) AS author_id, COUNT(*) AS reports,
						MAX(created_at) AS last_at, MAX(resolved_at) AS resolved_at, MAX(resolved_by) AS resolved_by, MAX(status) AS status
					FROM %i WHERE status = 'open' GROUP BY object_type, object_id ORDER BY reports DESC, last_at DESC LIMIT %d OFFSET %d",
					$table,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$groups = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_type, object_id, MAX(post_id) AS post_id, MAX(author_id) AS author_id, COUNT(*) AS reports,
						MAX(created_at) AS last_at, MAX(resolved_at) AS resolved_at, MAX(resolved_by) AS resolved_by, MAX(status) AS status
					FROM %i WHERE status IN ('kept','removed','muted','banned') GROUP BY object_type, object_id ORDER BY resolved_at DESC LIMIT %d OFFSET %d",
					$table,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}
		$more   = count( $groups ) > self::PAGE_SIZE;
		$groups = array_slice( $groups, 0, self::PAGE_SIZE );

		$items = array();
		foreach ( $groups as $g ) {
			$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT reporter_id, reason, note, created_at FROM %i WHERE object_type = %s AND object_id = %d AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') ORDER BY created_at DESC', array_merge( array( $table, $g['object_type'], $g['object_id'] ), $statuses ) ), ARRAY_A );
			$reasons = array_count_values( wp_list_pluck( $rows, 'reason' ) );
			arsort( $reasons );
			$notes = array_values( array_filter( $rows, fn( $r ) => '' !== (string) $r['note'] ) );
			$users = Helpers::users( array_merge( array( $g['author_id'], $g['resolved_by'] ), wp_list_pluck( $notes, 'reporter_id' ) ) );

			$items[] = array(
				'object_type' => $g['object_type'],
				'object_id'   => (int) $g['object_id'],
				'post_id'     => (int) $g['post_id'],
				'reports'     => (int) $g['reports'],
				'reasons'     => array_map( fn( $k, $n ) => array( 'reason' => $k, 'label' => self::reasons()[ $k ] ?? $k, 'count' => $n ), array_keys( $reasons ), $reasons ),
				'notes'       => array_map( fn( $n ) => array( 'by' => $users[ (int) $n['reporter_id'] ] ?? null, 'note' => $n['note'], 'at' => Helpers::iso( $n['created_at'] ) ), array_slice( $notes, 0, 3 ) ),
				'last_at'     => Helpers::iso( $g['last_at'] ),
				'author'      => ( $users[ (int) $g['author_id'] ] ?? null ),
				'author_restriction' => self::restriction( (int) $g['author_id'] ) ? array_intersect_key( self::restriction( (int) $g['author_id'] ), array_flip( array( 'status', 'until' ) ) ) : null,
				'author_is_staff'    => Settings::is_staff( (int) $g['author_id'] ),
				'preview'     => self::preview( $g['object_type'], (int) $g['object_id'] ),
				'resolution'  => $open ? null : array(
					'action' => $g['status'],
					'by'     => $users[ (int) $g['resolved_by'] ] ?? null,
					'at'     => Helpers::iso( $g['resolved_at'] ),
				),
			);
		}
		return array( 'items' => $items, 'next_page' => $more ? (int) $req['page'] + 1 : null, 'open' => self::open_count(), 'counts' => self::counts() );
	}

	/** What the moderator needs to judge: the words, a thumbnail and whether it's still up. */
	private static function preview( $type, $id ) {
		global $wpdb;
		if ( 'member' === $type ) {
			$user = get_userdata( $id );
			return $user
				? array( 'content' => (string) get_user_meta( $id, 'mbcom_bio', true ), 'thumb' => null, 'kind' => 'member', 'hidden' => false, 'gone' => false, 'created_at' => Helpers::iso( $user->user_registered ) )
				: array( 'gone' => true );
		}
		if ( 'post' === $type ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT content, type, status, course_id, created_at FROM %i WHERE id = %d', Install::table( 'posts' ), $id ), ARRAY_A );
			if ( ! $row ) {
				return array( 'gone' => true );
			}
			$media = $wpdb->get_row( $wpdb->prepare( 'SELECT kind, url, poster_url FROM %i WHERE post_id = %d ORDER BY sort_order LIMIT 1', Install::table( 'media' ), $id ), ARRAY_A );
			return array(
				'content'    => mb_substr( (string) $row['content'], 0, 600 ),
				'thumb'      => $media ? ( 'image' === $media['kind'] ? $media['url'] : $media['poster_url'] ) : null,
				'kind'       => $row['type'],
				'hidden'     => 'review' === $row['status'],
				'gone'       => 'deleted' === $row['status'],
				'created_at' => Helpers::iso( $row['created_at'] ),
				// "in Home" or "in Launch in 30 Days": where it was posted
				'course'     => (int) $row['course_id'] ? $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM %i WHERE id = %d', Install::table( 'courses' ), $row['course_id'] ) ) : null,
			);
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT c.content, c.image_url, c.created_at, p.user_id AS post_user, p.status AS post_status FROM %i c LEFT JOIN %i p ON p.id = c.post_id WHERE c.id = %d', Install::table( 'comments' ), Install::table( 'posts' ), $id ), ARRAY_A );
		$on  = $row && $row['post_user'] ? get_userdata( (int) $row['post_user'] ) : null;
		return $row
			? array( 'content' => mb_substr( (string) $row['content'], 0, 600 ), 'thumb' => $row['image_url'], 'kind' => 'comment', 'hidden' => false, 'gone' => ! $row['post_status'] || 'deleted' === $row['post_status'], 'created_at' => Helpers::iso( $row['created_at'] ), 'on' => $on ? $on->display_name : null )
			: array( 'gone' => true );
	}

	public static function resolve( \WP_REST_Request $req ) {
		global $wpdb;
		$type   = $req['type'];
		$id     = (int) $req['id'];
		$action = 'remove' === $req['action'] ? 'remove' : 'keep';
		$posts  = Install::table( 'posts' );

		if ( 'member' === $type ) {
			$action = 'keep'; // a profile isn't removed; moderators mute or ban the member from the report card
		}
		if ( 'remove' === $action ) {
			if ( 'post' === $type ) {
				Rest_Feed::remove_post( $id );
			} else {
				// The normal delete keeps counts and points right; staff are allowed to delete any comment.
				rest_do_request( new \WP_REST_Request( 'DELETE', '/' . MEMBUILD_COMMUNITY_REST_NS . '/comments/' . $id ) );
			}
		} elseif ( 'post' === $type ) {
			// Kept: a post hidden by reports comes back.
			$wpdb->update( $posts, array( 'status' => 'published' ), array( 'id' => $id, 'status' => 'review' ) );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, resolved_by = %d, resolved_at = %s WHERE object_type = %s AND object_id = %d AND status = 'open'",
				Install::table( 'reports' ),
				'remove' === $action ? 'removed' : 'kept',
				get_current_user_id(),
				Helpers::now(),
				$type,
				$id
			)
		);
		// A post taken down takes its comments with it: their open reports are settled too,
		// so nobody is asked to judge a comment on a post that's gone.
		if ( 'remove' === $action && 'post' === $type ) {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'removed', resolved_by = %d, resolved_at = %s WHERE object_type = 'comment' AND post_id = %d AND status = 'open'", Install::table( 'reports' ), get_current_user_id(), Helpers::now(), $id ) );
		}
		return array( 'resolved' => true, 'action' => $action, 'open' => self::open_count(), 'counts' => self::counts() );
	}

	/* ---------- Muting and banning ---------- */

	public static function restrict( \WP_REST_Request $req ) {
		$id     = (int) $req['id'];
		$status = in_array( $req['status'], array( 'muted', 'banned' ), true ) ? $req['status'] : 'none';
		if ( ! get_userdata( $id ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'This member was not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( $id === get_current_user_id() || Settings::is_staff( $id ) ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'Admins and moderators can’t be muted or banned.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		if ( 'none' === $status ) {
			delete_user_meta( $id, self::META );
		} else {
			$days = (int) $req['days'];
			update_user_meta(
				$id,
				self::META,
				array(
					'status' => $status,
					'until'  => $days > 0 ? gmdate( 'c', time() + $days * DAY_IN_SECONDS ) : null,
					'reason' => Helpers::clean_text( $req['reason'] ?? '', 300 ),
					'by'     => get_current_user_id(),
					'at'     => gmdate( 'c' ),
				)
			);
		}
		/**
		 * A member was muted, banned, or had a mute or ban lifted.
		 *
		 * @param int    $user_id The member.
		 * @param string $status  "muted", "banned" or "none".
		 */
		do_action( 'membuild_community/member_restricted', $id, $status );
		// Muting or banning someone settles the reports about their profile: they move to
		// Resolved as "Muted by …" / "Banned by …". Post and comment reports still need Keep or Remove.
		if ( 'none' !== $status ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s, resolved_by = %d, resolved_at = %s WHERE object_type = 'member' AND object_id = %d AND status = 'open'", Install::table( 'reports' ), $status, get_current_user_id(), Helpers::now(), $id ) );
		}
		return array( 'restriction' => self::restriction( $id ), 'open' => self::open_count(), 'counts' => self::counts() );
	}

	/** Muted and banned members, newest first, a page at a time. */
	public static function restricted( \WP_REST_Request $req ) {
		$all   = self::all_restrictions();
		$page  = max( 1, (int) ( $req['page'] ?? 1 ) );
		$slice = array_slice( $all, ( $page - 1 ) * 50, 50, true );
		$users = Helpers::users( array_filter( array_merge( array_keys( $slice ), array_map( fn( $r ) => (int) ( $r['by'] ?? 0 ), $slice ) ) ) );
		$out   = array();
		foreach ( $slice as $id => $r ) {
			if ( isset( $users[ $id ] ) ) {
				$out[] = array(
					'user'   => $users[ $id ],
					'status' => $r['status'],
					'until'  => $r['until'] ?? null,
					'reason' => $r['reason'] ?? '',
					'by'     => $users[ (int) ( $r['by'] ?? 0 ) ] ?? null,
					'at'     => $r['at'] ?? null,
				);
			}
		}
		return array(
			'items'     => $out,
			'next_page' => count( $all ) > $page * 50 ? $page + 1 : null,
			'counts'    => self::counts(),
		);
	}

	/* ---------- Pinned posts ---------- */

	public static function pin( \WP_REST_Request $req ) {
		global $wpdb;
		$posts = Install::table( 'posts' );
		$row   = Rest_Feed::row( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $row['course_id'] ) {
			return Discussions::pin( $row, (string) $req['scope'] ); // to its lesson or its course, not the feed
		}
		$pinned = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_pinned = 1 AND status = %s AND course_id = 0', $posts, 'published' ) );
		if ( ! $row['is_pinned'] && $pinned >= self::MAX_PINS ) {
			/* translators: %d: how many posts can be pinned */
			return new \WP_Error( 'mbcom_pin_limit', sprintf( __( 'Up to %d posts can be pinned. Unpin one first.', 'membuild-community' ), self::MAX_PINS ), array( 'status' => 400 ) );
		}
		$wpdb->update( $posts, array( 'is_pinned' => 1 ), array( 'id' => $row['id'] ) );
		return array( 'pinned' => true );
	}

	public static function unpin( \WP_REST_Request $req ) {
		global $wpdb;
		$wpdb->update( Install::table( 'posts' ), array( 'is_pinned' => 0 ), array( 'id' => (int) $req['id'] ) );
		return array( 'pinned' => false );
	}
}
