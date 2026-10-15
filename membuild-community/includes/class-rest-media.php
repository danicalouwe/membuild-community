<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Chunked upload endpoints:
 *   POST /media/uploads                    start   {mime, size, name}
 *   POST /media/uploads/{id}/chunks/{n}    append  raw bytes (application/octet-stream)
 *   POST /media/uploads/{id}/complete      finish  {width, height, duration, poster_media_id}
 *   POST /media/embed                      {url}   a YouTube video, played in YouTube's own player
 *   DELETE /media/{id}                     an upload the member didn't post after all
 */
class Rest_Media {

	public static function register_routes() {
		$auth = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );

		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/media/uploads',
			array(
				'methods'             => 'POST',
				'callback'            => fn( $req ) => self::respond( Media::start( get_current_user_id(), (string) $req['mime'], (int) $req['size'], (string) $req['name'] ), 201 ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/media/uploads/(?P<id>[A-Za-z0-9]+)/chunks/(?P<index>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => fn( $req ) => self::respond( Media::append( get_current_user_id(), $req['id'], (int) $req['index'], $req->get_body() ) ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/media/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => fn( $req ) => self::respond( Media::discard( get_current_user_id(), (int) $req['id'] ) ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/media/embed',
			array(
				'methods'             => 'POST',
				// Each preview fetches from YouTube or Vimeo, so it's rate limited like uploads.
				'callback'            => fn( $req ) => self::respond( Helpers::throttle( 'embed', 30, 10 * MINUTE_IN_SECONDS ) ?? Embeds::create( get_current_user_id(), (string) $req['url'] ), 201 ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/media/uploads/(?P<id>[A-Za-z0-9]+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => fn( $req ) => self::respond( Media::complete( get_current_user_id(), $req['id'], (array) $req->get_json_params() ), 201 ),
				'permission_callback' => $auth,
			)
		);
	}

	private static function respond( $result, $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->set_status( $status );
		return $response;
	}
}
