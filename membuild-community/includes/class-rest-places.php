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

	/* ---------- Reading ---------- */

	public static function list_places() {
		global $wpdb;
		$places = Install::table( 'places' );
		$rows   = self::can_edit()
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY status = %s DESC, name ASC LIMIT %d', $places, 'pending', self::LIMIT ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' OR created_by = %d ORDER BY name ASC LIMIT %d", $places, get_current_user_id(), self::LIMIT ), ARRAY_A );
		return array(
			'items'    => self::format( $rows, false ),
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
		return $place;
	}

	/** A place this member may see: published, or their own suggestion, or anything for staff. */
	private static function visible( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'places' ), $id ), ARRAY_A );
		if ( ! $row || ( 'published' !== $row['status'] && ! self::can_edit() && (int) $row['created_by'] !== get_current_user_id() ) ) {
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
				'description' => $full ? $r['description'] : '',
				'address'     => $r['address'],
				'lat'         => (float) $r['lat'],
				'lng'         => (float) $r['lng'],
				'website'     => $r['website'],
				'phone'       => $r['phone'],
				'hours'       => $r['hours'],
				'cover'       => $r['cover_url'] ?: null,
				'status'      => $r['status'],
				'mine'        => (int) $r['created_by'] === $me,
				'suggested_by' => 'pending' === $r['status'] ? ( $users[ (int) $r['created_by'] ] ?? null ) : null,
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
		return self::format( array( self::visible( (int) $row['id'] ) ) )[0];
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

	/* ---------- Address lookup ---------- */

	/**
	 * Address → up to 5 candidates, from OpenStreetMap's Nominatim. Only the
	 * typed text is sent, from this server, with a User-Agent naming the site
	 * (their policy asks for one). Answers are cached for 30 days, and each
	 * member gets 30 lookups every 10 minutes.
	 */
	public static function geocode( \WP_REST_Request $req ) {
		$q = mb_substr( trim( sanitize_text_field( (string) $req['q'] ) ), 0, 200 );
		if ( mb_strlen( $q ) < 3 ) {
			return array( 'items' => array() );
		}
		$key    = 'mbcom_geo_' . md5( mb_strtolower( $q ) );
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
			add_query_arg( array( 'q' => rawurlencode( $q ), 'format' => 'jsonv2', 'limit' => 5, 'accept-language' => get_locale() ? str_replace( '_', '-', get_locale() ) : 'en' ), $url ),
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
				fn( $r ) => array( 'label' => (string) ( $r['display_name'] ?? '' ), 'lat' => (float) ( $r['lat'] ?? 0 ), 'lng' => (float) ( $r['lon'] ?? 0 ) ),
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
