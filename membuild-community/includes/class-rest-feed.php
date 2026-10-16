<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Feed, posts and post reactions. Comments live in Rest_Comments.
 */
class Rest_Feed {

	const PAGE_SIZE = 15;

	/** How many of the newest posts the Hot feed ranks. */
	const HOT_POOL = 500;

	public static function register_routes() {
		$auth = array( __CLASS__, 'logged_in' );

		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/feed',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'feed' ),
				'permission_callback' => $auth,
				'args'                => array(
					'sort'   => array( 'enum' => array( 'latest', 'hot' ), 'default' => 'latest' ),
					'type'   => array( 'enum' => array( 'all', 'post', 'reel' ), 'default' => 'all' ),
					'cursor' => array( 'type' => 'integer', 'default' => 0 ),
					'page'   => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/feed/new',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'new_count' ),
				'permission_callback' => $auth,
				'args'                => array(
					'since' => array( 'type' => 'integer', 'required' => true ),
					'type'  => array( 'enum' => array( 'all', 'post', 'reel' ), 'default' => 'all' ),
				),
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_post' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_post' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_post' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_post' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/reactions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'reactors' ),
				'permission_callback' => $auth,
				'args'                => array(
					'type' => array( 'type' => 'string', 'default' => '' ),
					'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/share',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'share' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/reaction',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'react' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'unreact' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	public static function logged_in() {
		return is_user_logged_in() && current_user_can( 'read' ) && ! Moderation::is_banned( get_current_user_id() );
	}

	/** A permission callback for a feature that admins can switch off in settings. */
	public static function feature( $key ) {
		return function () use ( $key ) {
			if ( ! self::logged_in() ) {
				return false;
			}
			return Settings::feature( $key ) ?: new \WP_Error( 'mbcom_feature_off', __( 'This part of the community is turned off.', 'membuild-community' ), array( 'status' => 403 ) );
		};
	}

	/* ---------- Feed ---------- */

	public static function feed( \WP_REST_Request $req ) {
		global $wpdb;
		$posts = Install::table( 'posts' );
		// $where only ever holds fixed SQL with placeholders; the values go in $args, in order.
		// Posts inside courses stay in their course: never in the feed or on profiles.
		$where = "status = 'published' AND course_id = 0";
		$args  = array( $posts );
		if ( in_array( $req['type'], array( 'post', 'reel' ), true ) ) {
			$where .= ' AND type = %s';
			$args[] = $req['type'];
		}
		$pinned = array();
		if ( ! empty( $req['author'] ) ) {
			$where .= ' AND user_id = %d'; // one member's profile
			$args[] = (int) $req['author'];
		} elseif ( 'reel' !== $req['type'] ) {
			// Pinned posts sit above the feed, once, instead of in the timeline.
			if ( ! (int) $req['cursor'] && (int) $req['page'] <= 1 ) {
				$pinned = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} AND is_pinned = 1 ORDER BY id DESC LIMIT %d", array_merge( $args, array( Moderation::MAX_PINS ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built only from fixed SQL fragments with %s/%d placeholders; every value is bound through $args in this prepare().
			}
			$where .= ' AND is_pinned = 0';
		}
		if ( ! Settings::feature( 'reels' ) ) {
			$where .= " AND type = 'post'"; // reels switched off: hide them everywhere
		}
		$limit = self::PAGE_SIZE + 1; // One extra row tells us whether there is a next page.

		if ( 'hot' === $req['sort'] ) {
			$offset = ( (int) $req['page'] - 1 ) * self::PAGE_SIZE;
			$ids    = array_slice( self::hot_ids( $where, $args ), $offset, $limit );
			$rows   = $ids ? self::rows_in_order( $ids ) : array();
		} else {
			// Cursor paging: stable even while new posts arrive at the top.
			$cursor = (int) $req['cursor'];
			if ( $cursor > 0 ) {
				$where .= ' AND id < %d';
				$args[] = $cursor;
			}
			$args[] = $limit;
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built only from fixed SQL fragments with %s/%d placeholders; every value is bound through $args in this prepare().
		}

		$has_more = count( $rows ) > self::PAGE_SIZE;
		$rows     = Blocks::filter( array_slice( $rows, 0, self::PAGE_SIZE ) ); // after paging, so the shared rankings stay shared
		$pinned   = Blocks::filter( $pinned );
		$items    = self::format_posts( $rows );
		$last     = end( $rows );

		return array(
			'items'       => $items,
			'pinned'      => $pinned ? self::format_posts( $pinned ) : array(),
			'next_cursor' => $has_more && $last ? (int) $last['id'] : null,
			'next_page'   => $has_more ? (int) $req['page'] + 1 : null,
		);
	}

	/**
	 * The "Hot" order: engagement that decays with age, so fresh lively posts rise
	 * and old ones sink. Only the newest HOT_POOL posts are scored (older ones could
	 * never outrank them), and the ranking is shared for a minute: it's the same for everyone.
	 *
	 * @param string $where Fixed SQL with placeholders, from feed().
	 * @param array  $args  Values for $where, starting with the table name.
	 * @return int[] Post IDs, hottest first.
	 */
	private static function hot_ids( $where, array $args ) {
		global $wpdb;
		$key = 'mbcom_hot_' . md5( $where . wp_json_encode( $args ) );
		$ids = get_transient( $key );
		if ( ! is_array( $ids ) ) {
			$ids = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built only from fixed SQL fragments with %s/%d placeholders; every value is bound through $args in this prepare().
						"SELECT id FROM (SELECT id, reactions_count, comments_count, created_at FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d) recent
						ORDER BY (reactions_count + 2 * comments_count + 1) / POW(TIMESTAMPDIFF(MINUTE, created_at, UTC_TIMESTAMP()) / 60 + 2, 1.5) DESC, id DESC",
						array_merge( $args, array( self::HOT_POOL ) )
					)
				)
			);
			set_transient( $key, $ids, MINUTE_IN_SECONDS );
		}
		return $ids;
	}

	/** Full rows for these post IDs, in the given order. */
	private static function rows_in_order( array $ids ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'posts' ) ), $ids ) ),
			ARRAY_A
		);
		$by_id = array_column( $rows, null, 'id' );
		return array_values( array_filter( array_map( fn( $id ) => $by_id[ $id ] ?? null, $ids ) ) );
	}

	public static function new_count( \WP_REST_Request $req ) {
		global $wpdb;
		if ( in_array( $req['type'], array( 'post', 'reel' ), true ) ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND course_id = 0 AND id > %d AND type = %s", Install::table( 'posts' ), (int) $req['since'], $req['type'] )
			);
		} else {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND course_id = 0 AND id > %d", Install::table( 'posts' ), (int) $req['since'] )
			);
		}
		return array( 'count' => $count );
	}

	/* ---------- Posts ---------- */

	public static function create_post( \WP_REST_Request $req ) {
		global $wpdb;
		$user_id   = get_current_user_id();
		$content   = Helpers::clean_text( $req['content'] ?? '' );
		$type      = 'reel' === ( $req['type'] ?? 'post' ) ? 'reel' : 'post';
		$media_ids = array_filter( array_map( 'intval', (array) ( $req['media_ids'] ?? array() ) ) );
		$feeling   = in_array( $req['feeling'] ?? '', Helpers::FEELINGS, true ) ? $req['feeling'] : null;
		// A background only applies to short, text-only posts, as on Facebook.
		$background = ( ! $media_ids && mb_strlen( $content ) <= 130 && in_array( $req['background'] ?? '', Helpers::BACKGROUNDS, true ) ) ? $req['background'] : null;

		$blocked = Moderation::guard( $user_id );
		if ( $blocked ) {
			return $blocked;
		}
		// A post under a lesson or in a course's Discussion tab. Course discussions are for
		// questions, so "only staff can post" (a feed setting) doesn't apply there.
		$place = array( 0, 0 );
		if ( ! empty( $req['lesson_id'] ) || ! empty( $req['course_id'] ) ) {
			$place = Discussions::place( (int) $req['lesson_id'], (int) $req['course_id'] );
			if ( is_wp_error( $place ) ) {
				return $place;
			}
			$type = 'post';
		}
		if ( ! $place[0] && ! Settings::can_post( $user_id ) ) {
			return new \WP_Error( 'mbcom_posting_staff', __( 'Only admins and moderators can post here.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$slow = Helpers::throttle( 'post', 10, 10 * MINUTE_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		if ( 'reel' === $type && ! Settings::feature( 'reels' ) ) {
			return new \WP_Error( 'mbcom_feature_off', __( 'Reels are turned off.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		// A YouTube or Vimeo link in a text-only post becomes a playable video, like on Facebook,
		// unless the author removed the preview. A post that is only the link drops the bare URL.
		if ( 'post' === $type && ! $media_ids && empty( $req['no_embed'] ) ) {
			$link = Embeds::first_link( $content );
			if ( $link ) {
				$embed = Embeds::create( $user_id, $link );
				if ( ! is_wp_error( $embed ) ) {
					$media_ids  = array( $embed['id'] );
					$background = null;
					if ( trim( $content ) === $link ) {
						$content = '';
					}
				}
			}
		}
		if ( '' === $content && ! $media_ids && ! $feeling ) {
			return new \WP_Error( 'mbcom_empty', __( 'Write something or add a photo or video.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( 'reel' === $type && 1 !== count( $media_ids ) ) {
			return new \WP_Error( 'mbcom_reel_media', __( 'A reel needs exactly one video.', 'membuild-community' ), array( 'status' => 400 ) );
		}

		$now = Helpers::now();
		$wpdb->insert(
			Install::table( 'posts' ),
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'content'    => $content,
				'background' => 'reel' === $type ? null : $background,
				'feeling'    => $feeling,
				'course_id'  => $place[0],
				'lesson_id'  => $place[1],
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$post_id = (int) $wpdb->insert_id;
		$media   = Media::attach( $user_id, $post_id, $media_ids );

		// Reels are videos uploaded to the community; links to other sites belong in a normal post.
		if ( 'reel' === $type && ( 1 !== count( $media ) || 'video' !== $media[0]['kind'] ) ) {
			$wpdb->delete( Install::table( 'posts' ), array( 'id' => $post_id ) );
			$wpdb->update( Install::table( 'media' ), array( 'post_id' => null ), array( 'post_id' => $post_id ) );
			return new \WP_Error( 'mbcom_reel_media', __( 'A reel needs exactly one video.', 'membuild-community' ), array( 'status' => 400 ) );
		}

		/**
		 * A post or reel was published. Points are given on this hook.
		 *
		 * @param int $post_id The post.
		 * @param int $user_id Its author.
		 */
		do_action( 'membuild_community/post_created', $post_id, $user_id );

		$response = rest_ensure_response( self::find_post( $post_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function get_post( \WP_REST_Request $req ) {
		$post = self::find_post( (int) $req['id'] );
		return $post ?: new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
	}

	/** Authors can change the words, feeling and background of their own post; photos stay as they are. */
	public static function update_post( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard(); // muted members can't rewrite old posts either
		if ( $blocked ) {
			return $blocked;
		}
		$row = self::row( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $row['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'You can only edit your own posts.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$has_media = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', Install::table( 'media' ), $row['id'] ) );
		$content   = Helpers::clean_text( $req['content'] ?? '' );
		$feeling   = in_array( $req['feeling'] ?? '', Helpers::FEELINGS, true ) ? $req['feeling'] : null;
		if ( '' === $content && ! $has_media && ! $feeling ) {
			return new \WP_Error( 'mbcom_empty', __( 'Write something or delete the post instead.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$background = ( 'post' === $row['type'] && ! $has_media && mb_strlen( $content ) <= 130 && in_array( $req['background'] ?? '', Helpers::BACKGROUNDS, true ) ) ? $req['background'] : null;

		$changed = $content !== (string) $row['content'] || $feeling !== $row['feeling'] || $background !== $row['background'];
		if ( $changed ) {
			$wpdb->update(
				Install::table( 'posts' ),
				array( 'content' => $content, 'feeling' => $feeling, 'background' => $background, 'edited_at' => Helpers::now() ),
				array( 'id' => $row['id'] )
			);
		}
		return self::find_post( (int) $row['id'] );
	}

	public static function delete_post( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::row( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $row['user_id'] !== get_current_user_id() && ! Helpers::can_moderate() ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'You cannot delete this post.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		self::remove_post( (int) $row['id'] );
		return array( 'deleted' => true );
	}

	/**
	 * Deletes a post: the one place every delete goes through (author, moderators,
	 * wp-admin and privacy erasure). The row stays as "deleted" so counters and
	 * links don't break.
	 *
	 * @param int  $post_id The post.
	 * @param bool $wipe    Also blank its text (personal-data erasure).
	 * @return bool False when it was already gone.
	 */
	public static function remove_post( $post_id, $wipe = false ) {
		global $wpdb;
		$posts  = Install::table( 'posts' );
		$author = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM %i WHERE id = %d AND status <> 'deleted'", $posts, $post_id ) );
		if ( null === $author ) {
			return false;
		}
		$wpdb->update( $posts, $wipe ? array( 'status' => 'deleted', 'content' => '' ) : array( 'status' => 'deleted' ), array( 'id' => $post_id ) );
		/**
		 * A post was deleted. Points it earned are taken back on this hook.
		 *
		 * @param int $post_id The post.
		 * @param int $author  Its author.
		 */
		do_action( 'membuild_community/post_deleted', $post_id, (int) $author );
		return true;
	}

	/* ---------- Who reacted / shares ---------- */

	/**
	 * The "who reacted" dialog: per-type counts (for the tabs) and one page of
	 * people, optionally filtered to a single reaction type.
	 */
	public static function reactors( \WP_REST_Request $req ) {
		global $wpdb;
		$post = self::row( (int) $req['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$table  = Install::table( 'reactions' );
		$counts = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT type, COUNT(*) AS n FROM %i WHERE object_type = 'post' AND object_id = %d GROUP BY type ORDER BY n DESC", $table, $post['id'] ), ARRAY_A ) as $r ) {
			$counts[ $r['type'] ] = (int) $r['n'];
		}

		$per    = 50;
		$offset = ( (int) $req['page'] - 1 ) * $per;
		$type   = in_array( $req['type'], Helpers::REACTIONS, true ) ? $req['type'] : '';
		if ( $type ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT user_id, type FROM %i WHERE object_type = 'post' AND object_id = %d AND type = %s ORDER BY id DESC LIMIT %d, %d", $table, $post['id'], $type, $offset, $per + 1 ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT user_id, type FROM %i WHERE object_type = 'post' AND object_id = %d ORDER BY id DESC LIMIT %d, %d", $table, $post['id'], $offset, $per + 1 ),
				ARRAY_A
			);
		}
		$has_more = count( $rows ) > $per;
		$rows     = array_slice( $rows, 0, $per );
		$users    = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );

		return array(
			'total'     => array_sum( $counts ),
			'counts'    => $counts,
			'items'     => array_values( array_filter( array_map( fn( $r ) => isset( $users[ (int) $r['user_id'] ] ) ? array( 'user' => $users[ (int) $r['user_id'] ], 'type' => $r['type'] ) : null, $rows ) ) ),
			'next_page' => $has_more ? (int) $req['page'] + 1 : null,
		);
	}

	/** Counts a share (copy link or the system share sheet). */
	public static function share( \WP_REST_Request $req ) {
		global $wpdb;
		$post = self::row( (int) $req['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$table = Install::table( 'posts' );
		// Each member's share counts once a day per post, so the counter can't be pumped.
		$once = 'mbcom_shared_' . $post['id'] . '_' . get_current_user_id();
		if ( ! get_transient( $once ) ) {
			set_transient( $once, 1, DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET shares_count = shares_count + 1 WHERE id = %d', $table, $post['id'] ) );
		}
		return array( 'shares_count' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT shares_count FROM %i WHERE id = %d', $table, $post['id'] ) ) );
	}

	/* ---------- Reactions ---------- */

	public static function react( \WP_REST_Request $req ) {
		global $wpdb;
		$post_id = (int) $req['id'];
		$type    = in_array( $req['type'], Helpers::REACTIONS, true ) ? $req['type'] : 'like';
		if ( ! self::row( $post_id ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$table = Install::table( 'reactions' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (object_type, object_id, user_id, type, created_at) VALUES ('post', %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE type = VALUES(type)",
				$table,
				$post_id,
				get_current_user_id(),
				$type,
				Helpers::now()
			)
		);
		/** This action is documented in includes/class-rest-comments.php */
		do_action( 'membuild_community/reaction_changed', 'post', $post_id, get_current_user_id(), $type );
		return self::reaction_state( $post_id );
	}

	public static function unreact( \WP_REST_Request $req ) {
		global $wpdb;
		$post_id = (int) $req['id'];
		$wpdb->delete(
			Install::table( 'reactions' ),
			array( 'object_type' => 'post', 'object_id' => $post_id, 'user_id' => get_current_user_id() )
		);
		/** This action is documented in includes/class-rest-comments.php */
		do_action( 'membuild_community/reaction_changed', 'post', $post_id, get_current_user_id(), null );
		return self::reaction_state( $post_id );
	}

	/** Recounts instead of incrementing, so the stored counter can never drift. */
	/** The post's reaction counter after a change: two small queries, not a full post rebuild. */
	private static function reaction_state( $post_id ) {
		global $wpdb;
		$reacts = Install::table( 'reactions' );
		$types  = $wpdb->get_results(
			$wpdb->prepare( "SELECT type, COUNT(*) n FROM %i WHERE object_type = 'post' AND object_id = %d GROUP BY type ORDER BY n DESC", $reacts, $post_id ),
			ARRAY_A
		);
		$count  = array_sum( array_map( 'intval', wp_list_pluck( $types, 'n' ) ) );
		$wpdb->update( Install::table( 'posts' ), array( 'reactions_count' => $count ), array( 'id' => $post_id ) );
		return array(
			'reactions_count' => $count,
			'my_reaction'     => $wpdb->get_var( $wpdb->prepare( "SELECT type FROM %i WHERE object_type = 'post' AND object_id = %d AND user_id = %d", $reacts, $post_id, get_current_user_id() ) ),
			'top_reactions'   => array_slice( wp_list_pluck( $types, 'type' ), 0, 3 ),
		);
	}

	/* ---------- Formatting ---------- */

	/**
	 * What posts in a course carry on top of a normal post: where they were
	 * posted, "Helpful" votes, the best answer, the kind of pin, and whether the
	 * author is an admin ("Instructor") or a moderator. Three queries for the whole page.
	 *
	 * @return array<int,array> Extra fields by post ID (course posts only).
	 */
	private static function course_details( array $rows ) {
		global $wpdb;
		$rows = array_filter( $rows, fn( $r ) => (int) ( $r['course_id'] ?? 0 ) );
		if ( ! $rows ) {
			return array();
		}
		$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$lessons = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $rows, 'lesson_id' ) ) ) ) );
		$courses = array_values( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'course_id' ) ) ) );
		$titles  = $lessons ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, title FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $lessons ), '%d' ) ) . ')', array_merge( array( Install::table( 'lessons' ) ), $lessons ) ), OBJECT_K ) : array();
		$names   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $courses ), '%d' ) ) . ')', array_merge( array( Install::table( 'courses' ) ), $courses ) ), OBJECT_K );
		$voted   = array_flip(
			array_map(
				'intval',
				$wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM %i WHERE object_type = 'helpful' AND user_id = %d AND object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'reactions' ), get_current_user_id() ), $ids ) ) )
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$id         = (int) $row['id'];
			$lesson     = (int) $row['lesson_id'];
			$out[ $id ] = array(
				'course'          => array( 'id' => (int) $row['course_id'], 'title' => $names[ $row['course_id'] ]->title ?? '' ),
				'lesson'          => $lesson && isset( $titles[ $lesson ] ) ? array( 'id' => $lesson, 'title' => $titles[ $lesson ]->title ) : null,
				'helpful_count'   => (int) $row['helpful_count'],
				'my_helpful'      => isset( $voted[ $id ] ),
				'best_comment_id' => (int) $row['best_comment_id'] ?: null,
				'can_choose_best' => Discussions::can_choose( $row ),
				'pin'             => array( 0 => null, 1 => 'lesson', 2 => 'course' )[ (int) $row['is_pinned'] ] ?? null,
				'staff_role'      => Helpers::staff_role( (int) $row['user_id'] ),
			);
		}
		return $out;
	}

	/** A published post, or null; posts by someone hidden from the current member (a block) count as missing. */
	public static function row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND status = 'published'", Install::table( 'posts' ), $id ),
			ARRAY_A
		);
		if ( ! $row || Blocks::hides( $row['user_id'] ) ) {
			return null;
		}
		return (int) $row['course_id'] && ! Discussions::can_read( $row ) ? null : $row;
	}

	private static function find_post( $id ) {
		$row = self::row( $id );
		return $row ? self::format_posts( array( $row ) )[0] : null;
	}

	/**
	 * Formats a page of posts with a fixed number of queries, whatever the page size:
	 * authors, media, my reactions and reaction breakdowns are each fetched once.
	 */
	public static function format_posts( array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return array();
		}
		$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$me      = get_current_user_id();
		$reacts  = Install::table( 'reactions' );
		$users   = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		$media   = Media::for_posts( $ids );
		$preview = Rest_Comments::previews( $rows );
		$mine    = $wpdb->get_results(
			$wpdb->prepare( "SELECT object_id, type FROM %i WHERE object_type = 'post' AND user_id = %d AND object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $reacts, $me ), $ids ) ),
			OBJECT_K
		);
		$summary = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, type, COUNT(*) AS n FROM %i WHERE object_type = 'post' AND object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY object_id, type ORDER BY n DESC', array_merge( array( $reacts ), $ids ) ), ARRAY_A ) as $r ) {
			$summary[ (int) $r['object_id'] ][] = $r['type'];
		}
		$course = self::course_details( $rows );

		return array_map(
			function ( $row ) use ( $users, $media, $mine, $summary, $me, $preview, $course ) {
				$id = (int) $row['id'];
				return ( $course[ $id ] ?? array() ) + array(
					'id'              => $id,
					'type'            => $row['type'],
					'content'         => $row['content'],
					'background'      => $row['background'] ?? null,
					'feeling'         => $row['feeling'] ?? null,
					'created_at'      => Helpers::iso( $row['created_at'] ),
					'author'          => $users[ (int) $row['user_id'] ] ?? null,
					'media'           => $media[ $id ] ?? array(),
					'reactions_count' => (int) $row['reactions_count'],
					'comments_count'  => (int) $row['comments_count'],
					'shares_count'    => (int) ( $row['shares_count'] ?? 0 ),
					'my_reaction'     => isset( $mine[ $id ] ) ? $mine[ $id ]->type : null,
					'top_reactions'   => array_slice( $summary[ $id ] ?? array(), 0, 3 ),
					'can_delete'      => (int) $row['user_id'] === $me || Helpers::can_moderate(),
					'can_edit'        => (int) $row['user_id'] === $me,
					'pinned'          => ! empty( $row['is_pinned'] ),
					'edited'          => ! empty( $row['edited_at'] ),
					'preview_comment' => $preview[ $id ] ?? null,
				);
			},
			$rows
		);
	}

}
