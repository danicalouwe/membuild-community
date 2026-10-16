<?php
/**
 * Events.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Events: a live call, a Q&A, a workshop. An event is a post with a date
 * (event_at, in UTC) and a link, so comments, reactions, the feed, search
 * and moderation all work on it with no extra code. RSVPs are reactions
 * with object_type "event" and type "going" or "interested".
 *
 * Staff create and edit them. An event can be for everyone or for one
 * member group (the same groups courses use).
 *
 *   GET    /events ?when=upcoming|past&page=     cards
 *   POST   /events                               create (staff)
 *   PUT    /events/{id}                          edit (staff); a new time tells everyone going
 *   POST   /events/{id}/cancel                   cancel (staff); tells everyone going
 *   PUT    /events/{id}/rsvp {status}            going | interested
 *   DELETE /events/{id}/rsvp
 *   GET    /events/{id}/going                    who's going
 *
 * Reminders go out with the email job (every 15 minutes): a notification
 * and an email about a day before, and again about an hour before, to
 * everyone going or interested.
 */
class Events {

	const STATUSES = array( 'going', 'interested' );

	public static function register_hooks() {
		add_action( Email::CRON, array( __CLASS__, 'remind' ) );
	}

	public static function register_routes() {
		$member = Rest_Feed::feature( 'events' );
		$staff  = fn() => Rest_Feed::logged_in() && Settings::feature( 'events' ) && Settings::is_staff( get_current_user_id() );
		$id     = '(?P<id>\d+)';
		$routes = array(
			'/events'              => array(
				array( 'GET', 'list_events', $member, array( 'when' => array( 'enum' => array( 'upcoming', 'past' ), 'default' => 'upcoming' ), 'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ) ) ),
				array( 'POST', 'create', $staff ),
			),
			"/events/{$id}"        => array( array( 'PUT', 'update', $staff ) ),
			"/events/{$id}/cancel" => array( array( 'POST', 'cancel', $staff ) ),
			"/events/{$id}/rsvp"   => array( array( 'PUT', 'rsvp', $member ), array( 'DELETE', 'unrsvp', $member ) ),
			"/events/{$id}/going"  => array( array( 'GET', 'going', $member ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				array_map( fn( $h ) => array( 'methods' => $h[0], 'callback' => array( __CLASS__, $h[1] ), 'permission_callback' => $h[2], 'args' => $h[3] ?? array() ), $handlers )
			);
		}
	}

	/* ---------- Who sees what ---------- */

	/** May the current member see this event? Everyone's events, their group's, and staff see all. */
	public static function can_see( array $row ) {
		if ( ! Settings::feature( 'events' ) ) {
			return false;
		}
		$group = (int) ( $row['event_group'] ?? 0 );
		return ! $group || Settings::is_staff( get_current_user_id() ) || in_array( $group, Access::member_groups( get_current_user_id() ), true );
	}

	/**
	 * SQL keeping posts to ones whose event (if any) the member may see.
	 *
	 * @return array{0:string,1:array} Fixed SQL with %d placeholders, and its values.
	 */
	public static function visible_sql( $alias = '' ) {
		$p = $alias ? "{$alias}." : '';
		if ( ! Settings::feature( 'events' ) ) {
			return array( "{$p}event_at IS NULL", array() );
		}
		if ( Settings::is_staff( get_current_user_id() ) ) {
			return array( '1 = 1', array() );
		}
		$groups = Access::member_groups( get_current_user_id() );
		if ( ! $groups ) {
			return array( "{$p}event_group = 0", array() );
		}
		return array( "{$p}event_group IN (0," . implode( ',', array_fill( 0, count( $groups ), '%d' ) ) . ')', $groups );
	}

	/* ---------- Lists ---------- */

	public static function list_events( \WP_REST_Request $req ) {
		global $wpdb;
		$posts  = Install::table( 'posts' );
		$per    = Rest_Feed::PAGE_SIZE;
		$page   = max( 1, (int) $req['page'] );
		$now    = Helpers::now();
		list( $seen, $vals ) = self::visible_sql();
		// Upcoming: anything that hasn't ended yet, soonest first. Past: newest first.
		$where = 'past' === $req['when']
			? 'event_at + INTERVAL event_minutes MINUTE <= %s'
			: 'event_at + INTERVAL event_minutes MINUTE > %s';
		$order = 'past' === $req['when'] ? 'event_at DESC' : 'event_at ASC';
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where, $order and $seen are fixed SQL with placeholders; values are bound.
			$wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' AND event_at IS NOT NULL AND {$where} AND {$seen} ORDER BY {$order} LIMIT %d, %d", array_merge( array( $posts, $now ), $vals, array( ( $page - 1 ) * $per, $per + 1 ) ) ),
			ARRAY_A
		);
		$more = count( $rows ) > $per;
		return array(
			'items'     => Rest_Feed::format_posts( Blocks::filter( array_slice( $rows, 0, $per ) ) ),
			'next_page' => $more ? $page + 1 : null,
			'can_add'   => Settings::is_staff( get_current_user_id() ),
		);
	}

	/* ---------- Create and edit ---------- */

	/** The event fields from a request, checked: array of columns, or an error. */
	private static function fields( \WP_REST_Request $req, ?array $row = null ) {
		$p    = $req->get_params();
		$data = array();
		if ( isset( $p['title'] ) || ! $row ) {
			$data['event_title'] = mb_substr( sanitize_text_field( $p['title'] ?? '' ), 0, 200 );
			if ( '' === $data['event_title'] ) {
				return new \WP_Error( 'mbcom_invalid_event', __( 'Give the event a title.', 'membuild-community' ), array( 'status' => 400 ) );
			}
		}
		if ( isset( $p['at'] ) || ! $row ) {
			$at = strtotime( (string) ( $p['at'] ?? '' ) ); // ISO 8601 with its offset, from the app
			if ( ! $at ) {
				return new \WP_Error( 'mbcom_invalid_event', __( 'Pick a date and time.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			$data['event_at'] = gmdate( 'Y-m-d H:i:s', $at );
		}
		if ( isset( $p['minutes'] ) || ! $row ) {
			$data['event_minutes'] = max( 5, min( 24 * 60, (int) ( $p['minutes'] ?? 60 ) ) );
		}
		if ( array_key_exists( 'url', $p ) ) {
			$url = trim( (string) $p['url'] );
			if ( '' !== $url && ( ! wp_http_validate_url( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
				return new \WP_Error( 'mbcom_invalid_event', __( 'That link doesn’t look right. Paste the full address, starting with https://', 'membuild-community' ), array( 'status' => 400 ) );
			}
			$data['event_url'] = '' === $url ? null : mb_substr( $url, 0, 500 );
		}
		if ( array_key_exists( 'recording', $p ) ) {
			$url = trim( (string) $p['recording'] );
			if ( '' !== $url && ( ! wp_http_validate_url( $url ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
				return new \WP_Error( 'mbcom_invalid_event', __( 'That recording link doesn’t look right.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			$data['event_recording'] = '' === $url ? null : mb_substr( $url, 0, 500 );
		}
		if ( isset( $p['description'] ) || ! $row ) {
			$data['content'] = Helpers::clean_text( $p['description'] ?? '', 5000 );
		}
		if ( array_key_exists( 'group', $p ) && Helpers::can_manage() ) {
			$group               = (int) $p['group'];
			$data['event_group'] = isset( Access::groups()[ $group ] ) ? $group : 0;
		}
		return $data;
	}

	public static function create( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard() ?? Helpers::throttle( 'event', 20, HOUR_IN_SECONDS );
		if ( $blocked ) {
			return $blocked;
		}
		$data = self::fields( $req );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$me  = get_current_user_id();
		$now = Helpers::now();
		$wpdb->insert( Install::table( 'posts' ), $data + array( 'user_id' => $me, 'type' => 'post', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
		$post_id = (int) $wpdb->insert_id;
		$cover   = (int) ( $req['cover_media_id'] ?? 0 );
		if ( $cover ) {
			Media::attach( $me, $post_id, array( $cover ) );
		}
		/** This action is documented in includes/class-rest-feed.php */
		do_action( 'membuild_community/post_created', $post_id, $me );
		self::tell_everyone( $post_id, (int) ( $data['event_group'] ?? 0 ) );
		$res = rest_ensure_response( Rest_Feed::format_posts( array( self::row( $post_id ) ) )[0] );
		$res->set_status( 201 );
		return $res;
	}

	public static function update( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::row( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Event not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$data = self::fields( $req, $row );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$moved = isset( $data['event_at'] ) && $data['event_at'] !== $row['event_at'];
		if ( $moved ) {
			$data['event_reminded'] = 0; // reminders follow the new time
		}
		$wpdb->update( Install::table( 'posts' ), $data + array( 'edited_at' => Helpers::now() ), array( 'id' => $row['id'] ) );
		if ( array_key_exists( 'cover_media_id', $req->get_params() ) ) {
			$wpdb->update( Install::table( 'media' ), array( 'post_id' => null ), array( 'post_id' => $row['id'] ) ); // the old cover goes back to being unused
			if ( (int) $req['cover_media_id'] ) {
				Media::attach( get_current_user_id(), (int) $row['id'], array( (int) $req['cover_media_id'] ) );
			}
		}
		if ( $moved ) {
			self::tell_attendees( (int) $row['id'], 'event_changed' );
		}
		return Rest_Feed::format_posts( array( self::row( (int) $row['id'] ) ) )[0];
	}

	public static function cancel( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::row( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Event not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( 'cancelled' !== $row['event_status'] ) {
			$wpdb->update( Install::table( 'posts' ), array( 'event_status' => 'cancelled', 'edited_at' => Helpers::now() ), array( 'id' => $row['id'] ) );
			self::tell_attendees( (int) $row['id'], 'event_cancelled' );
		}
		return Rest_Feed::format_posts( array( self::row( (int) $row['id'] ) ) )[0];
	}

	/* ---------- RSVP ---------- */

	public static function rsvp( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::open_row( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'cancelled' === $row['event_status'] ) {
			return new \WP_Error( 'mbcom_cancelled', __( 'This event was cancelled.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$status = in_array( $req['status'], self::STATUSES, true ) ? $req['status'] : 'going';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (object_type, object_id, user_id, type, created_at) VALUES ('event', %d, %d, %s, %s) ON DUPLICATE KEY UPDATE type = VALUES(type)",
				Install::table( 'reactions' ),
				$row['id'],
				get_current_user_id(),
				$status,
				Helpers::now()
			)
		);
		/**
		 * A member said they're going to (or interested in) an event, or took it back.
		 *
		 * @param int         $post_id The event.
		 * @param int         $user_id The member.
		 * @param string|null $status  "going", "interested", or null when taken back.
		 */
		do_action( 'membuild_community/event_rsvp', (int) $row['id'], get_current_user_id(), $status );
		return self::counts( (int) $row['id'] );
	}

	public static function unrsvp( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::open_row( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wpdb->delete( Install::table( 'reactions' ), array( 'object_type' => 'event', 'object_id' => $row['id'], 'user_id' => get_current_user_id() ) );
		/** This action is documented in includes/class-events.php */
		do_action( 'membuild_community/event_rsvp', (int) $row['id'], get_current_user_id(), null );
		return self::counts( (int) $row['id'] );
	}

	/** Who's going (first) and who's interested, up to 200 each. */
	public static function going( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::open_row( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, type FROM %i WHERE object_type = 'event' AND object_id = %d ORDER BY FIELD(type, 'going', 'interested'), id ASC LIMIT 400", Install::table( 'reactions' ), $row['id'] ), ARRAY_A );
		$rows  = Blocks::filter( $rows );
		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		return array( 'items' => array_values( array_filter( array_map( fn( $r ) => isset( $users[ (int) $r['user_id'] ] ) ? array( 'user' => $users[ (int) $r['user_id'] ], 'status' => $r['type'] ) : null, $rows ) ) ) );
	}

	private static function counts( $post_id ) {
		return self::details( array( array( 'id' => $post_id ) ) )[ $post_id ];
	}

	/* ---------- Formatting ---------- */

	/**
	 * What an event post carries on top of a normal post: the event itself and
	 * the RSVP state. Two queries for a whole page of posts.
	 *
	 * @return array<int,array> By post ID, for event posts only.
	 */
	public static function details( array $rows ) {
		global $wpdb;
		$rows = array_values( array_filter( $rows, fn( $r ) => ! empty( $r['event_at'] ) || ! isset( $r['event_at'] ) ) );
		if ( ! $rows ) {
			return array();
		}
		$ids    = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$reacts = Install::table( 'reactions' );
		$me     = get_current_user_id();
		$counts = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %d placeholders; values are bound.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, type, COUNT(*) n FROM %i WHERE object_type = 'event' AND object_id IN ({$in}) GROUP BY object_id, type", array_merge( array( $reacts ), $ids ) ), ARRAY_A ) as $r ) {
			$counts[ (int) $r['object_id'] ][ $r['type'] ] = (int) $r['n'];
		}
		$mine  = array_column( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, type FROM %i WHERE object_type = 'event' AND user_id = %d AND object_id IN ({$in})", array_merge( array( $reacts, $me ), $ids ) ), ARRAY_A ), 'type', 'object_id' );
		$faces = array();
		foreach ( Blocks::filter( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, user_id FROM %i WHERE object_type = 'event' AND type = 'going' AND object_id IN ({$in}) ORDER BY id ASC LIMIT 1000", array_merge( array( $reacts ), $ids ) ), ARRAY_A ) ) as $r ) {
			if ( count( $faces[ (int) $r['object_id'] ] ?? array() ) < 3 ) {
				$faces[ (int) $r['object_id'] ][] = (int) $r['user_id'];
			}
		}
		// phpcs:enable
		$users  = Helpers::users( array_merge( array(), ...array_values( $faces ) ) );
		$groups = Access::groups();
		$out    = array();
		foreach ( $rows as $row ) {
			$id         = (int) $row['id'];
			$group      = (int) ( $row['event_group'] ?? 0 );
			$out[ $id ] = array(
				'going_count'      => (int) ( $counts[ $id ]['going'] ?? 0 ),
				'interested_count' => (int) ( $counts[ $id ]['interested'] ?? 0 ),
				'my_rsvp'          => $mine[ $id ] ?? null,
				'faces'            => array_values( array_filter( array_map( fn( $u ) => $users[ $u ] ?? null, $faces[ $id ] ?? array() ) ) ),
			);
			if ( isset( $row['event_at'] ) ) {
				$out[ $id ] += array(
					'title'     => (string) $row['event_title'],
					'at'        => Helpers::iso( $row['event_at'] ),
					'minutes'   => (int) $row['event_minutes'],
					'url'       => $row['event_url'] ?: null,
					'kind'      => self::kind( $row['event_url'] ),
					'recording' => $row['event_recording'] ?: null,
					'status'    => $row['event_status'] ?: 'scheduled',
					'group'     => $group ? array( 'id' => $group, 'name' => $groups[ $group ]['name'] ?? '' ) : null,
					'can_edit'  => Settings::is_staff( get_current_user_id() ),
				);
			}
		}
		return $out;
	}

	/** What the link plays: a stream that shows inside the community, or a link that opens elsewhere. */
	public static function kind( $url ) {
		if ( ! $url ) {
			return 'none';
		}
		if ( Embeds::youtube_id( $url ) ) {
			return 'youtube';
		}
		if ( Embeds::vimeo_id( $url ) ) {
			return 'vimeo';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( preg_match( '/(^|\.)twitch\.tv$/', $host ) ) {
			return 'twitch';
		}
		if ( preg_match( '/(^|\.)zoom\.us$/', $host ) ) {
			return 'zoom';
		}
		if ( 'meet.google.com' === $host ) {
			return 'meet';
		}
		return 'link';
	}

	/* ---------- Telling people ---------- */

	/**
	 * One notification each to many members, in a few queries: no loop of
	 * lookups, so telling 10,000 people takes a moment, not a minute. The
	 * emails follow through the regular email job, in its bounded batches.
	 *
	 * @param int[]  $user_ids Who to tell.
	 * @param string $type     event_new | event_changed | event_cancelled | event_reminder.
	 * @param int    $post_id  The event.
	 * @param int    $actor_id Who did it (0 for reminders).
	 * @param array  $data     Shown in the notification and the email.
	 */
	private static function blast( array $user_ids, $type, $post_id, $actor_id, array $data ) {
		global $wpdb;
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ), fn( $u ) => $u && $u !== (int) $actor_id ) ) );
		if ( $actor_id ) {
			$user_ids = Blocks::filter( $user_ids ); // nobody hears from someone they blocked
		}
		$json = wp_json_encode( $data );
		$now  = Helpers::now();
		foreach ( array_chunk( $user_ids, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $u ) {
				$values[] = $wpdb->prepare( '(%d, %s, %s, %d, %d, %s, %d, %s, %s, %s)', $u, $type, 'event', $post_id, $post_id, $actor_id ? (string) $actor_id : '', $actor_id ? 1 : 0, $json, $now, $now );
			}
			$wpdb->query( 'INSERT INTO ' . Install::table( 'notifications' ) . ' (user_id, type, object_type, object_id, post_id, actor_ids, actors_count, data, created_at, updated_at) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- each row prepared above
		}
	}

	/** A new event: everyone who can see it hears once. */
	private static function tell_everyone( $post_id, $group ) {
		$row = self::row( $post_id );
		if ( ! $row ) {
			return;
		}
		$ids = get_users( array( 'fields' => 'ID', 'number' => 20000 ) );
		if ( $group ) {
			$ids = array_values( array_filter( $ids, fn( $u ) => in_array( $group, Access::member_groups( (int) $u ), true ) ) );
		}
		self::blast( $ids, 'event_new', $post_id, (int) $row['user_id'], array( 'title' => $row['event_title'], 'at' => Helpers::iso( $row['event_at'] ), 'when' => self::when( $row ) ) );
	}

	/** Everyone going or interested hears about a new time or a cancellation. */
	private static function tell_attendees( $post_id, $type ) {
		global $wpdb;
		$row = self::row( $post_id );
		if ( ! $row ) {
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE object_type = 'event' AND object_id = %d", Install::table( 'reactions' ), $post_id ) );
		self::blast( $ids, $type, $post_id, (int) $row['user_id'], array( 'title' => $row['event_title'], 'at' => Helpers::iso( $row['event_at'] ), 'when' => self::when( $row ) ) );
	}

	/**
	 * Reminders, run with the email job. event_reminded counts what went out:
	 * 0 nothing, 1 the day-before, 2 the hour-before (which also covers events
	 * made within the last day). Each is a notification at once; the email
	 * follows from the email job's next batches.
	 */
	public static function remind() {
		global $wpdb;
		if ( ! Settings::feature( 'events' ) ) {
			return;
		}
		$posts = Install::table( 'posts' );
		$now   = time();
		$due   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'published' AND event_at IS NOT NULL AND event_status = '' AND event_at > %s AND event_at <= %s AND event_reminded < 2 ORDER BY event_at ASC LIMIT 50",
				$posts,
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		foreach ( $due as $row ) {
			$until = strtotime( $row['event_at'] . ' UTC' ) - $now;
			$step  = $until <= HOUR_IN_SECONDS ? 2 : 1;
			if ( $step <= (int) $row['event_reminded'] ) {
				continue;
			}
			$wpdb->update( $posts, array( 'event_reminded' => $step ), array( 'id' => $row['id'], 'event_reminded' => $row['event_reminded'] ) );
			if ( ! $wpdb->rows_affected ) {
				continue; // another run got here first
			}
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE object_type = 'event' AND object_id = %d", Install::table( 'reactions' ), $row['id'] ) );
			self::blast( $ids, 'event_reminder', (int) $row['id'], 0, array( 'title' => $row['event_title'], 'at' => Helpers::iso( $row['event_at'] ), 'when' => self::when( $row ), 'soon' => 2 === $step ) );
		}
	}

	/* ---------- Helpers ---------- */

	/** A published event post, whoever is asking. */
	public static function row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND status = 'published' AND event_at IS NOT NULL", Install::table( 'posts' ), $id ), ARRAY_A );
		return $row ?: null;
	}

	/** The event, for the current member: the row, or a not-found error. */
	private static function open_row( $id ) {
		$row = self::row( $id );
		return $row && self::can_see( $row ) && ! Blocks::hides( $row['user_id'] ) ? $row : new \WP_Error( 'mbcom_not_found', __( 'Event not found.', 'membuild-community' ), array( 'status' => 404 ) );
	}

	/** The event's time in the site's time zone, for emails. */
	public static function when( array $row ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' (T)', strtotime( $row['event_at'] . ' UTC' ) );
	}

	public static function url( $post_id ) {
		return home_url( '/' . Settings::slug() . '/events/' . (int) $post_id );
	}
}
