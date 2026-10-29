<?php
/**
 * GIFs from GIPHY.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * A GIF button in posts, comments and chats, once an admin pastes a GIPHY API
 * key in Settings → GIFs. Without a key there's no button (uploading a GIF
 * file still works, like any photo).
 *
 * GIPHY's API terms shape this:
 * - members' browsers search GIPHY directly (no proxying through the site), so
 *   the key is a browser key and goes to the app like the map tiles do;
 * - the site never copies GIPHY files: a chosen GIF is stored as GIPHY's own
 *   address, unchanged, in a media row of kind "image", so posts, comments and
 *   chats take it like a photo;
 * - "Powered by GIPHY" shows wherever the picker is open.
 *
 *   POST /media/gif {id, url, width, height, mp4?, still?, title?}   the chosen GIF, as media
 *
 * Like Facebook, GIFs play as GIPHY's MP4 version where there is one (a silent
 * looping video, often a tenth of the GIF's size); `url` is the image version,
 * used where video can't play, and `still` the first frame.
 */
class Giphy {

	const RATINGS = array( 'g', 'pg', 'pg-13', 'r' );

	public static function register_routes() {
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/media/gif',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'choose' ),
				'permission_callback' => fn() => Rest_Feed::logged_in() && (bool) self::config(),
			)
		);
		// Settings › GIFs: does GIPHY take this key? (Working / Not working beside the field.)
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/settings/gifs/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'check' ),
				'permission_callback' => fn() => is_user_logged_in() && Helpers::can_manage(),
				'args'                => array( 'key' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
	}

	/**
	 * Asks GIPHY for one trending GIF with the key: 200 means it works, 401/403 that it
	 * doesn't. Only the key goes to GIPHY. The answer is kept a day per key.
	 */
	public static function check( \WP_REST_Request $req ) {
		$key = trim( (string) $req['key'] );
		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $key ) ) {
			return array( 'working' => false );
		}
		$cache  = 'mbcom_giphy_ok_' . md5( $key );
		$cached = get_transient( $cache );
		if ( false !== $cached ) {
			return array( 'working' => '1' === $cached );
		}
		$res  = wp_remote_get( add_query_arg( array( 'api_key' => rawurlencode( $key ), 'limit' => 1 ), 'https://api.giphy.com/v1/gifs/trending' ), array( 'timeout' => 6 ) );
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( ! $code ) {
			return array( 'working' => null ); // GIPHY didn't answer: say nothing either way
		}
		$ok = 200 === $code;
		set_transient( $cache, $ok ? '1' : '0', DAY_IN_SECONDS );
		return array( 'working' => $ok );
	}

	/** { key, rating } for the app, or null while no key is set. */
	public static function config() {
		$gifs = (array) Settings::get( 'gifs' );
		$key  = (string) ( $gifs['key'] ?? '' );
		return '' === $key ? null : array(
			'key'    => $key,
			'rating' => in_array( $gifs['rating'] ?? '', self::RATINGS, true ) ? $gifs['rating'] : 'pg',
		);
	}

	/** Settings → GIFs, checked: a key is letters and numbers, the rating one of GIPHY's. */
	public static function clean_settings( $in, array $old ) {
		$in  = is_array( $in ) ? $in : array();
		$key = array_key_exists( 'key', $in ) ? trim( (string) $in['key'] ) : (string) ( $old['key'] ?? '' );
		if ( '' !== $key && ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $key ) ) {
			return new \WP_Error( 'mbcom_invalid_setting', __( 'That doesn’t look like a GIPHY API key. Copy it again from your GIPHY dashboard.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$rating = $in['rating'] ?? ( $old['rating'] ?? 'pg' );
		return array(
			'key'    => $key,
			'rating' => in_array( $rating, self::RATINGS, true ) ? $rating : 'pg',
		);
	}

	/**
	 * A GIF the member picked in the picker becomes a media row of their own,
	 * ready to attach. Only GIPHY's media addresses are accepted, unchanged.
	 */
	public static function choose( \WP_REST_Request $req ) {
		global $wpdb;
		$slow = Helpers::throttle( 'gif', 60, 10 * MINUTE_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		$id     = (string) $req['id'];
		$url    = (string) $req['url'];
		$width  = (int) $req['width'];
		$height = (int) $req['height'];
		$mp4    = (string) $req['mp4'];
		$still  = (string) $req['still'];
		$ok     = preg_match( '/^[A-Za-z0-9]{4,40}$/', $id )
			&& self::is_giphy( $url )
			&& ( '' === $mp4 || self::is_giphy( $mp4 ) )
			&& ( '' === $still || self::is_giphy( $still ) )
			&& $width > 0 && $width <= 4000 && $height > 0 && $height <= 4000;
		if ( ! $ok ) {
			return new \WP_Error( 'mbcom_bad_gif', __( 'That GIF couldn’t be added. Pick another one.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$now = Helpers::now();
		$wpdb->insert(
			Install::table( 'media' ),
			array(
				'user_id'    => get_current_user_id(),
				'kind'       => 'image',
				'url'        => $url,
				'mime'       => 'image/gif',
				'width'      => $width,
				'height'     => $height,
				'poster_url' => $still ?: null,
				'meta'       => wp_json_encode( array_filter( array( 'giphy' => $id, 'mp4' => $mp4, 'title' => mb_substr( sanitize_text_field( (string) $req['title'] ), 0, 120 ) ) ) ),
				'created_at' => $now,
			)
		);
		$res = rest_ensure_response( Media::format( Media::get( (int) $wpdb->insert_id ) ) );
		$res->set_status( 201 );
		return $res;
	}

	/** An address on GIPHY's media servers: https, their host, and short enough to store. */
	public static function is_giphy( $url ) {
		$parts = wp_parse_url( (string) $url );
		return 'https' === ( $parts['scheme'] ?? '' )
			&& (bool) preg_match( '/^(media\d*|i)\.giphy\.com$/', (string) ( $parts['host'] ?? '' ) )
			&& str_starts_with( (string) ( $parts['path'] ?? '' ), '/' )
			&& strlen( (string) $url ) <= 500;
	}

	/** The video version of a GIPHY media row, or null (photos, uploaded GIFs). */
	public static function mp4( $row ) {
		$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
		return ! empty( $meta['giphy'] ) && ! empty( $meta['mp4'] ) ? $meta['mp4'] : null;
	}
}
