<?php
/**
 * Places: meetup spots, member perks and venues, on a map.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * A small directory of places, shown as a list and a map. Off by default
 * (Settings → Features → Places).
 *
 * Admins and moderators add places; members suggest them, and a suggestion
 * shows only to its author and to staff until it's approved. Members can say
 * "I go here", stored as a reaction on the place. Places have locations;
 * members never do: "Near me" sorts in the browser and nothing is saved.
 *
 * An address becomes a map position once, when a place is added or edited:
 * the server asks OpenStreetMap's Nominatim (only the typed address is sent)
 * and caches the answer. The pin can then be dragged to the exact spot.
 *
 *   GET    /places                 published places, plus my suggestions (all of them for staff)
 *   POST   /places                 add (staff) or suggest (members)
 *   GET    /places/{id}            one place, with who goes there
 *   PUT    /places/{id}            edit, or approve with {status: "published"} (staff)
 *   DELETE /places/{id}            (staff, or the member who suggested it while it waits)
 *   PUT    /places/{id}/going      "I go here"; DELETE takes it back
 *   GET    /places/geocode ?q=     address → candidates [{label, lat, lng}]
 */
class Rest_Places {

	const CATEGORIES = array( 'meetup', 'perk', 'venue', 'other' );
	const LIMIT      = 1000; // a community directory, not Google Maps: everything loads at once

	public static function register_routes() {
		$member = Rest_Feed::feature( 'places' );
		$id     = '(?P<id>\d+)';
		$routes = array(
			'/places'               => array( array( 'GET', 'list_places' ), array( 'POST', 'create' ) ),
			'/places/geocode'       => array( array( 'GET', 'geocode' ) ),
			"/places/{$id}"         => array( array( 'GET', 'get_place' ), array( 'PUT', 'update' ), array( 'DELETE', 'delete' ) ),
			"/places/{$id}/going"   => array( array( 'PUT', 'go' ), array( 'DELETE', 'not_go' ) ),
			"/places/{$id}/news"    => array( array( 'POST', 'add_news' ) ),
			'/places/news'          => array( array( 'GET', 'pending_news' ) ),
			"/places/news/{$id}"    => array( array( 'PUT', 'approve_news' ), array( 'DELETE', 'delete_news' ) ),
			'/places/members'       => array( array( 'GET', 'members' ) ),
			'/places/me'            => array( array( 'PUT', 'show_me' ), array( 'DELETE', 'hide_me' ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				array_map( fn( $h ) => array( 'methods' => $h[0], 'callback' => array( __CLASS__, $h[1] ), 'permission_callback' => $member ), $handlers )
			);
		}
	}

	/** Who can add, edit, approve and delete places without review. */
	public static function can_edit() {
		return Settings::is_staff( get_current_user_id() );
	}

	/**
	 * Who can see a place: everyone (access_group 0), or members of one membership.
	 * Staff see them all.
	 *
	 * @return array{0:string,1:array} Fixed SQL with %d placeholders, and its values.
	 */
	public static function access_sql( $alias = '' ) {
		$p = $alias ? "{$alias}." : '';
		if ( self::can_edit() ) {
			return array( '1 = 1', array() );
		}
		$groups = Access::member_groups( get_current_user_id() );
		if ( ! $groups ) {
			return array( "{$p}access_group = 0", array() );
		}
		return array( "{$p}access_group IN (0," . implode( ',', array_fill( 0, count( $groups ), '%d' ) ) . ')', $groups );
	}

	private static function allowed( array $row ) {
		$group = (int) ( $row['access_group'] ?? 0 );
		return ! $group || self::can_edit() || in_array( $group, Access::member_groups( get_current_user_id() ), true );
	}

	/** Of these place IDs, the ones this member can't see (so an event there doesn't link to them). */
	public static function hidden_ids( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || self::can_edit() ) {
			return array();
		}
		list( $ok, $vals ) = self::access_sql();
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in and $ok are fixed SQL with placeholders; every value is bound.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE id IN ({$in}) AND NOT ({$ok})", array_merge( array( Install::table( 'places' ) ), $ids, $vals ) ) ) );
	}

	/* ---------- Reading ---------- */

	public static function list_places() {
		global $wpdb;
		$places = Install::table( 'places' );
		$rows   = self::can_edit()
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY status = %s DESC, name ASC LIMIT %d', $places, 'pending', self::LIMIT ), ARRAY_A )
			: array_values( array_filter( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' OR created_by = %d ORDER BY name ASC LIMIT %d", $places, get_current_user_id(), self::LIMIT ), ARRAY_A ), array( __CLASS__, 'allowed' ) ) );
		$items = self::format( $rows, false );
		$news  = self::news_for( array_map( fn( $p ) => $p['id'], $items ) );
		foreach ( $items as &$item ) {
			$live         = array_values( array_filter( $news[ $item['id'] ] ?? array(), fn( $n ) => 'published' === $n['status'] ) );
			$item['news'] = $live[0] ?? null; // the newest, for the card and the map tag
		}
		unset( $item );
		return array(
			'items'    => $items,
			'can_edit' => self::can_edit(),
		);
	}

	public static function get_place( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::visible( (int) $req['id'] );
		if ( ! $row ) {
			return self::not_found();
		}
		$place = self::format( array( $row ) )[0];
		$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE object_type = 'place' AND object_id = %d ORDER BY id DESC LIMIT 60", Install::table( 'reactions' ), $row['id'] ) ) );
		$place['members'] = array_values( Helpers::users( Blocks::filter( $ids ) ) );
		$place['news']    = self::news_for( array( (int) $row['id'] ) )[ (int) $row['id'] ] ?? array();
		// Coming up here: the next events held at this place (that this member may see).
		list( $seen, $vals ) = Events::visible_sql();
		$events = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $seen is fixed SQL with placeholders; values are bound.
			$wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' AND event_place_id = %d AND event_status <> 'cancelled' AND event_at + INTERVAL event_minutes MINUTE > %s AND {$seen} ORDER BY event_at ASC LIMIT 3", array_merge( array( Install::table( 'posts' ), $row['id'], Helpers::now() ), $vals ) ),
			ARRAY_A
		);
		$place['events'] = Rest_Feed::format_posts( $events );
		return $place;
	}

	/** A place this member may see: published, or their own suggestion, or anything for staff. */
	private static function visible( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'places' ), $id ), ARRAY_A );
		if ( ! $row || ( 'published' !== $row['status'] && ! self::can_edit() && (int) $row['created_by'] !== get_current_user_id() ) || ! self::allowed( $row ) ) {
			return null;
		}
		return $row;
	}

	/** Rows as the app shows them, with "I go here" counts and a few faces. The list leaves out descriptions (it never shows them). */
	private static function format( array $rows, $full = true ) {
		global $wpdb;
		if ( ! $rows ) {
			return array();
		}
		$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$reacts  = Install::table( 'reactions' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $in is one %d per ID; every value is bound.
		$counts  = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, COUNT(*) n FROM %i WHERE object_type = 'place' AND object_id IN ({$in}) GROUP BY object_id", array_merge( array( $reacts ), $ids ) ), OBJECT_K );
		$mine    = array_flip( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM %i WHERE object_type = 'place' AND user_id = %d AND object_id IN ({$in})", array_merge( array( $reacts, get_current_user_id() ), $ids ) ) ) ) );
		$recent  = $wpdb->get_results( $wpdb->prepare( "SELECT object_id, user_id FROM %i WHERE object_type = 'place' AND object_id IN ({$in}) ORDER BY id DESC LIMIT 3000", array_merge( array( $reacts ), $ids ) ), ARRAY_A );
		// phpcs:enable
		$faces = array();
		foreach ( Blocks::filter( $recent ) as $r ) {
			if ( count( $faces[ $r['object_id'] ] ?? array() ) < 3 ) {
				$faces[ $r['object_id'] ][] = (int) $r['user_id'];
			}
		}
		$users = Helpers::users( array_merge( array_map( fn( $r ) => (int) $r['created_by'], $rows ), ...array_values( $faces ) ) );
		$me    = get_current_user_id();
		return array_map(
			fn( $r ) => array(
				'id'          => (int) $r['id'],
				'name'        => $r['name'],
				'category'    => $r['category'],
				'perk'        => $r['perk'],
				'description' => $full || 'pending' === $r['status'] ? $r['description'] : '', // a suggestion's reason shows on its review card
				'address'     => $r['address'],
				'lat'         => (float) $r['lat'],
				'lng'         => (float) $r['lng'],
				'website'     => $r['website'],
				'phone'       => $r['phone'],
				'phone_country' => ( $r['phone_country'] ?? '' ) ?: null, // "PH": the number is dialled from there
				'hours'       => $r['hours'],
				'tz'          => $r['tz'] ?? null, // where it is, for events held here
				'week'        => self::week( $r['week'] ?? '' ), // hours by day, or null (then `hours` is free text)
				'access'      => self::can_edit() && (int) ( $r['access_group'] ?? 0 ) ? array( 'id' => (int) $r['access_group'], 'name' => Access::groups()[ (int) $r['access_group'] ]['name'] ?? '' ) : null,
				'cover'       => $r['cover_url'] ?: null,
				'status'      => $r['status'],
				'mine'        => (int) $r['created_by'] === $me,
				'suggested_by' => 'pending' === $r['status'] ? ( $users[ (int) $r['created_by'] ] ?? null ) : null,
				'created_at'   => Helpers::iso( $r['created_at'] ), // "2 days ago" on a suggestion
				'going_count' => (int) ( $counts[ $r['id'] ]->n ?? 0 ),
				'going'       => isset( $mine[ (int) $r['id'] ] ),
				'faces'       => array_values( array_filter( array_map( fn( $u ) => $users[ $u ] ?? null, $faces[ $r['id'] ] ?? array() ) ) ),
			),
			$rows
		);
	}

	/* ---------- Writing ---------- */

	public static function create( \WP_REST_Request $req ) {
		global $wpdb;
		if ( ! self::can_edit() ) {
			$blocked = Moderation::guard() ?: Helpers::throttle( 'place', 5, HOUR_IN_SECONDS );
			if ( $blocked ) {
				return $blocked;
			}
		}
		$data = self::fields( $req->get_params(), null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$now = Helpers::now();
		$wpdb->insert(
			Install::table( 'places' ),
			$data + array(
				'status'     => self::can_edit() ? 'published' : 'pending',
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		/**
		 * A place was added (status "published") or suggested ("pending").
		 *
		 * @param int    $place_id The place.
		 * @param string $status   published | pending.
		 */
		do_action( 'membuild_community/place_created', $id, self::can_edit() ? 'published' : 'pending' );
		$res = rest_ensure_response( self::format( array( self::visible( $id ) ) )[0] );
		$res->set_status( 201 );
		return $res;
	}

	public static function update( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::visible( (int) $req['id'] );
		if ( ! $row || ! self::can_edit() ) {
			return $row ? new \WP_Error( 'mbcom_forbidden', __( 'Only admins and moderators can edit places.', 'membuild-community' ), array( 'status' => 403 ) ) : self::not_found();
		}
		$data = self::fields( $req->get_params(), $row );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( in_array( $req['status'] ?? '', array( 'published', 'pending' ), true ) ) {
			$data['status'] = $req['status'];
		}
		$data['updated_at'] = Helpers::now();
		$wpdb->update( Install::table( 'places' ), $data, array( 'id' => $row['id'] ) );
		// Events held here carry its name, address and pin: keep them in step.
		$now = self::visible( (int) $row['id'] );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET event_venue = %s, event_address = %s, event_lat = %f, event_lng = %f WHERE event_place_id = %d', Install::table( 'posts' ), $now['name'], $now['address'], $now['lat'], $now['lng'], $row['id'] ) );
		// A new time zone moves the events still to come here (the past ones happened where they happened).
		if ( array_key_exists( 'tz', $data ) && $data['tz'] && $data['tz'] !== $row['tz'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET event_tz = %s WHERE event_place_id = %d AND event_at >= %s', Install::table( 'posts' ), $data['tz'], $row['id'], Helpers::now() ) );
		}
		return self::format( array( $now ) )[0];
	}

	public static function delete( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::visible( (int) $req['id'] );
		$own = $row && 'pending' === $row['status'] && (int) $row['created_by'] === get_current_user_id();
		if ( ! $row || ( ! self::can_edit() && ! $own ) ) {
			return $row ? new \WP_Error( 'mbcom_forbidden', __( 'Only admins and moderators can remove places.', 'membuild-community' ), array( 'status' => 403 ) ) : self::not_found();
		}
		$wpdb->delete( Install::table( 'places' ), array( 'id' => $row['id'] ) );
		$wpdb->delete( Install::table( 'reactions' ), array( 'object_type' => 'place', 'object_id' => $row['id'] ) );
		$wpdb->delete( Install::table( 'place_news' ), array( 'place_id' => $row['id'] ) );
		// Events held here keep the name and address they had, but no longer point at a place that's gone.
		$wpdb->update( Install::table( 'posts' ), array( 'event_place_id' => 0 ), array( 'event_place_id' => $row['id'] ) );
		return array( 'deleted' => true );
	}

	public static function go( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::visible( (int) $req['id'] );
		if ( ! $row || 'published' !== $row['status'] ) {
			return self::not_found();
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (object_type, object_id, user_id, type, created_at) VALUES ('place', %d, %d, 'go', %s)", Install::table( 'reactions' ), $row['id'], get_current_user_id(), Helpers::now() ) );
		return self::format( array( $row ) )[0];
	}

	public static function not_go( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::visible( (int) $req['id'] );
		if ( ! $row ) {
			return self::not_found();
		}
		$wpdb->delete( Install::table( 'reactions' ), array( 'object_type' => 'place', 'object_id' => $row['id'], 'user_id' => get_current_user_id() ) );
		return self::format( array( $row ) )[0];
	}

	/** The editable fields, checked. $row is the place being edited, or null for a new one. */
	private static function fields( array $in, $row ) {
		$out   = array();
		$error = fn( $msg ) => new \WP_Error( 'mbcom_invalid_place', $msg, array( 'status' => 400 ) );
		if ( null === $row || array_key_exists( 'name', $in ) ) {
			$out['name'] = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 120 );
			if ( '' === $out['name'] ) {
				return $error( __( 'Give the place a name.', 'membuild-community' ) );
			}
		}
		if ( null === $row || array_key_exists( 'category', $in ) ) {
			$out['category'] = in_array( $in['category'] ?? '', self::CATEGORIES, true ) ? $in['category'] : 'meetup';
		}
		if ( null === $row || array_key_exists( 'lat', $in ) || array_key_exists( 'lng', $in ) ) {
			$lat = isset( $in['lat'] ) ? (float) $in['lat'] : null;
			$lng = isset( $in['lng'] ) ? (float) $in['lng'] : null;
			if ( null === $lat || null === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
				return $error( __( 'Find the address on the map first.', 'membuild-community' ) );
			}
			$out['lat'] = round( $lat, 6 );
			$out['lng'] = round( $lng, 6 );
		}
		foreach ( array( 'address' => 255, 'perk' => 120, 'phone' => 40, 'hours' => 200 ) as $key => $max ) {
			if ( null === $row || array_key_exists( $key, $in ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( (string) ( $in[ $key ] ?? '' ) ), 0, $max );
			}
		}
		if ( null === $row || array_key_exists( 'description', $in ) ) {
			$out['description'] = Helpers::clean_text( $in['description'] ?? '', 1000 );
		}
		if ( null === $row || array_key_exists( 'website', $in ) ) {
			$url = trim( (string) ( $in['website'] ?? '' ) );
			if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) && ! preg_match( '#^https?://#i', $url ) ) {
				$url = ''; // javascript:, data:, mailto: … only web addresses here
			}
			$url            = $url && ! preg_match( '#^https?://#i', $url ) ? 'https://' . $url : $url;
			$out['website'] = $url ? mb_substr( esc_url_raw( $url, array( 'http', 'https' ) ), 0, 300 ) : '';
		}
		// The phone's country ("PH"), so the number can be called from anywhere.
		if ( array_key_exists( 'phone_country', $in ) ) {
			$cc                   = strtoupper( (string) $in['phone_country'] );
			$out['phone_country'] = preg_match( '/^[A-Z]{2}$/', $cc ) ? $cc : '';
		}
		// Who can see it: owners and moderators pick everyone, or one membership.
		if ( array_key_exists( 'access_group', $in ) && self::can_edit() ) {
			$g                   = (int) $in['access_group'];
			$out['access_group'] = isset( Access::groups()[ $g ] ) ? $g : 0;
		}
		// Hours by day: { thu: [["09:00","11:00"]], … }. Saving them clears the old free-text line.
		if ( array_key_exists( 'week', $in ) ) {
			$week         = self::clean_week( $in['week'] );
			$out['week']  = $week ? wp_json_encode( $week ) : null;
			if ( $week ) {
				$out['hours'] = '';
			}
		}
		// The time zone it's in: owners and moderators set it (or the first event held here does).
		if ( array_key_exists( 'tz', $in ) && self::can_edit() ) {
			$tz = (string) $in['tz'];
			if ( in_array( $tz, timezone_identifiers_list(), true ) ) { // anything else keeps what it had
				$out['tz'] = $tz;
			}
		}
		if ( array_key_exists( 'cover_media_id', $in ) ) {
			$media            = $in['cover_media_id'] ? Media::get( (int) $in['cover_media_id'] ) : null;
			$ok               = $media && 'image' === $media['kind'] && (int) $media['user_id'] === get_current_user_id() && 'message' !== $media['scope'];
			$out['cover_url'] = $ok ? $media['url'] : null;
			if ( $ok ) {
				global $wpdb;
				$wpdb->update( Install::table( 'media' ), array( 'post_id' => 0 ), array( 'id' => $media['id'] ) ); // in use: not an orphan upload
			}
		}
		return $out;
	}

	const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/** Up to three from–to times a day, "HH:MM" on a 24-hour clock, each ending after it starts. */
	private static function clean_week( $in ) {
		$out = array();
		foreach ( self::DAYS as $day ) {
			$ranges = is_array( $in ) && isset( $in[ $day ] ) && is_array( $in[ $day ] ) ? $in[ $day ] : array();
			foreach ( array_slice( $ranges, 0, 3 ) as $r ) {
				$from = is_array( $r ) ? (string) ( $r[0] ?? '' ) : '';
				$to   = is_array( $r ) ? (string) ( $r[1] ?? '' ) : '';
				if ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $from ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $to ) && $from < $to ) {
					$out[ $day ][] = array( $from, $to );
				}
			}
		}
		return $out;
	}

	private static function week( $json ) {
		$week = $json ? json_decode( (string) $json, true ) : null;
		return is_array( $week ) && $week ? $week : null;
	}

	/* ---------- Address lookup ---------- */

	/**
	 * Address → up to 5 candidates, from OpenStreetMap's Nominatim. Only the
	 * typed text is sent, from this server, with a User-Agent naming the site
	 * (their policy asks for one). Answers are cached for 30 days, and each
	 * member gets 30 lookups every 10 minutes.
	 */
	/* ---------- What's new at a place ---------- */

	/**
	 * Live What's new notes by place: approved and not ended yet, newest first, plus this
	 * member's own while they wait.
	 *
	 * @return array<int,array[]>
	 */
	public static function news_for( array $place_ids ) {
		global $wpdb;
		if ( ! $place_ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $place_ids ), '%d' ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is one %d per ID; every value is bound.
			$wpdb->prepare( "SELECT * FROM %i WHERE place_id IN ({$in}) AND ends_on >= %s AND ( status = 'published' OR user_id = %d ) ORDER BY id DESC", array_merge( array( Install::table( 'place_news' ) ), $place_ids, array( current_time( 'Y-m-d' ), get_current_user_id() ) ) ),
			ARRAY_A
		);
		$users = Helpers::users( array_map( fn( $r ) => (int) $r['user_id'], $rows ) );
		$out   = array();
		foreach ( Blocks::filter( $rows ) as $r ) {
			$out[ (int) $r['place_id'] ][] = self::format_news( $r, $users );
		}
		return $out;
	}

	private static function format_news( array $r, array $users ) {
		return array(
			'id'       => (int) $r['id'],
			'place_id' => (int) $r['place_id'],
			'text'     => $r['content'],
			'ends_on'  => $r['ends_on'],
			'status'   => $r['status'],
			'by'       => $users[ (int) $r['user_id'] ] ?? null,
			'mine'     => (int) $r['user_id'] === get_current_user_id(),
			'at'       => Helpers::iso( $r['created_at'] ),
		);
	}

	/** Share what's new here (any member; staff's show right away, members' after a check). */
	public static function add_news( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard() ?? Helpers::throttle( 'place_news', 10, HOUR_IN_SECONDS );
		if ( $blocked ) {
			return $blocked;
		}
		$place = self::visible( (int) $req['id'] ); // published, or your own suggestion while it waits
		if ( ! $place ) {
			return self::not_found();
		}
		$text = mb_substr( trim( sanitize_text_field( (string) $req['text'] ) ), 0, 200 );
		$ends = (string) $req['ends_on'];
		if ( '' === $text ) {
			return new \WP_Error( 'mbcom_invalid_news', __( 'Write what’s new, in a short line.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ends ) || $ends < current_time( 'Y-m-d' ) || $ends > gmdate( 'Y-m-d', strtotime( '+1 year' ) ) ) {
			return new \WP_Error( 'mbcom_invalid_news', __( 'Pick the day it ends: today or later, up to a year ahead.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->insert(
			Install::table( 'place_news' ),
			array(
				'place_id'   => (int) $place['id'],
				'user_id'    => get_current_user_id(),
				'content'    => $text,
				'ends_on'    => $ends,
				'status'     => self::can_edit() ? 'published' : 'pending',
				'created_at' => Helpers::now(),
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'place_news' ), $wpdb->insert_id ), ARRAY_A );
		return self::format_news( $row, Helpers::users( array( get_current_user_id() ) ) );
	}

	/** Staff: every What's new waiting for a check, oldest first, with its place. */
	public static function pending_news() {
		global $wpdb;
		if ( ! self::can_edit() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' AND ends_on >= %s ORDER BY id ASC LIMIT 200", Install::table( 'place_news' ), current_time( 'Y-m-d' ) ), ARRAY_A );
		$users  = Helpers::users( array_map( fn( $r ) => (int) $r['user_id'], $rows ) );
		$places = array();
		foreach ( $rows as $r ) {
			$places[ (int) $r['place_id'] ] = true;
		}
		$names = $places ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, address, lat, lng, category, cover_url FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $places ), '%d' ) ) . ')', array_merge( array( Install::table( 'places' ) ), array_keys( $places ) ) ), OBJECT_K ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- one %d per ID; every value is bound.
		return array(
			'items' => array_values(
				array_map(
					fn( $r ) => self::format_news( $r, $users ) + array(
						'place' => isset( $names[ $r['place_id'] ] ) ? array(
							'id'       => (int) $r['place_id'],
							'name'     => $names[ $r['place_id'] ]->name,
							'address'  => $names[ $r['place_id'] ]->address,
							'lat'      => (float) $names[ $r['place_id'] ]->lat,
							'lng'      => (float) $names[ $r['place_id'] ]->lng,
							'category' => $names[ $r['place_id'] ]->category,
							'cover'    => $names[ $r['place_id'] ]->cover_url ?: null,
						) : null,
					),
					$rows
				)
			),
		);
	}

	public static function approve_news( \WP_REST_Request $req ) {
		global $wpdb;
		if ( ! self::can_edit() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$wpdb->update( Install::table( 'place_news' ), array( 'status' => 'published', 'resolved_by' => get_current_user_id() ), array( 'id' => (int) $req['id'] ) );
		return array( 'approved' => true );
	}

	/** Not now (staff), or take your own back. */
	public static function delete_news( \WP_REST_Request $req ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'place_news' ), (int) $req['id'] ), ARRAY_A );
		if ( ! $row || ( ! self::can_edit() && (int) $row['user_id'] !== get_current_user_id() ) ) {
			return self::not_found();
		}
		$wpdb->delete( Install::table( 'place_news' ), array( 'id' => (int) $row['id'] ) );
		return array( 'deleted' => true );
	}

	/* ---------- Members on the map ---------- */

	const MAP_META = 'mbcom_map'; // array( lat, lng, city ): only for members who chose Show me on the map

	/**
	 * Members who chose to show on the map: their face at their city (never an address;
	 * positions are rounded to about a kilometre when saved).
	 */
	public static function members() {
		$ids = get_users(
			array(
				'meta_key'     => self::MAP_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- small set: members who opted in
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => 2000,
			)
		);
		$ids   = Blocks::filter( array_map( 'intval', $ids ) );
		$users = Helpers::users( $ids );
		$out   = array();
		foreach ( $ids as $id ) {
			$m = get_user_meta( $id, self::MAP_META, true );
			if ( is_array( $m ) && isset( $users[ $id ], $m['lat'], $m['lng'] ) ) {
				$out[] = array( 'user' => $users[ $id ], 'lat' => (float) $m['lat'], 'lng' => (float) $m['lng'], 'city' => (string) ( $m['city'] ?? '' ), 'bio' => mb_substr( (string) get_user_meta( $id, 'mbcom_bio', true ), 0, 140 ) );
			}
		}
		$me = get_user_meta( get_current_user_id(), self::MAP_META, true );
		return array( 'items' => $out, 'me' => is_array( $me ) ? $me : null );
	}

	/** Show me on the map: at a city (from the map search), rounded to about a kilometre. */
	public static function show_me( \WP_REST_Request $req ) {
		$lat  = is_numeric( $req['lat'] ) ? (float) $req['lat'] : null;
		$lng  = is_numeric( $req['lng'] ) ? (float) $req['lng'] : null;
		$city = mb_substr( trim( sanitize_text_field( (string) $req['city'] ) ), 0, 80 );
		if ( null === $lat || null === $lng || abs( $lat ) > 90 || abs( $lng ) > 180 || '' === $city ) {
			return new \WP_Error( 'mbcom_invalid_map', __( 'Find your city first.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$m = array( 'lat' => round( $lat, 2 ), 'lng' => round( $lng, 2 ), 'city' => $city );
		update_user_meta( get_current_user_id(), self::MAP_META, $m );
		return array( 'me' => $m );
	}

	public static function hide_me() {
		delete_user_meta( get_current_user_id(), self::MAP_META );
		return array( 'me' => null );
	}

	public static function geocode( \WP_REST_Request $req ) {
		$q = mb_substr( trim( sanitize_text_field( (string) $req['q'] ) ), 0, 200 );
		if ( mb_strlen( $q ) < 3 ) {
			return array( 'items' => array() );
		}
		$key    = 'mbcom_geo2_' . md5( mb_strtolower( $q ) ); // 2: answers carry the country
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array( 'items' => $cached );
		}
		$slow = Helpers::throttle( 'geocode', 30, 10 * MINUTE_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		/**
		 * The geocoding service: a Nominatim-compatible search URL.
		 *
		 * @param string $url Default https://nominatim.openstreetmap.org/search
		 */
		$url      = apply_filters( 'membuild_community/geocode_url', 'https://nominatim.openstreetmap.org/search' );
		$response = wp_remote_get(
			add_query_arg( array( 'q' => rawurlencode( $q ), 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 5, 'accept-language' => get_locale() ? str_replace( '_', '-', get_locale() ) : 'en' ), $url ),
			array(
				'timeout' => 8,
				'headers' => array( 'User-Agent' => 'MemBuild-Community/' . MEMBUILD_COMMUNITY_VERSION . ' (' . home_url( '/' ) . ')' ),
			)
		);
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'mbcom_geocode_failed', __( 'The map search isn’t answering right now. Try again in a minute, or drop the pin yourself.', 'membuild-community' ), array( 'status' => 502 ) );
		}
		$items = array_values(
			array_map(
				fn( $r ) => array(
					'label'   => (string) ( $r['display_name'] ?? '' ),
					'lat'     => (float) ( $r['lat'] ?? 0 ),
					'lng'     => (float) ( $r['lon'] ?? 0 ),
					'country' => strtoupper( substr( (string) ( $r['address']['country_code'] ?? '' ), 0, 2 ) ) ?: null, // for the phone number's country code
				),
				array_slice( (array) json_decode( wp_remote_retrieve_body( $response ), true ), 0, 5 )
			)
		);
		set_transient( $key, $items, 30 * DAY_IN_SECONDS );
		return array( 'items' => $items );
	}

	/** The map's background tiles, for the app. A site can switch to its own or a paid provider. */
	public static function tiles() {
		/**
		 * The map tiles: a {z}/{x}/{y} URL and the attribution its provider requires.
		 *
		 * @param array $tiles { url, attribution }
		 */
		return apply_filters(
			'membuild_community/map_tiles',
			array(
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
			)
		);
	}

	private static function not_found() {
		return new \WP_Error( 'mbcom_not_found', __( 'Place not found.', 'membuild-community' ), array( 'status' => 404 ) );
	}
}
