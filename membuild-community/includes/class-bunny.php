<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Bunny Stream video hosting (optional, Settings → Video).
 *
 * With it on, post, reel and lesson videos upload from the member's browser
 * straight to Bunny (resumable "tus" uploads), so the web host never carries
 * video traffic. Bunny encodes them and serves adaptive streams from its CDN.
 * Stories stay on the site: they're short and gone after a day.
 *
 *   POST /media/bunny                 start: creates the Bunny video, returns upload credentials
 *   POST /media/bunny/{id}/complete   the upload finished; Bunny is encoding now
 *   POST /bunny/webhook?token=        Bunny reports an encode finished (or failed)
 *   POST /settings/test-video         (admin) checks the library ID, key and CDN host
 *
 * A video shows its poster with "Processing…" until Bunny is done. Deleting a
 * post deletes its Bunny videos too, so nobody pays to store what nobody can see.
 */
class Bunny {

	const API  = 'https://video.bunnycdn.com';
	const TUS  = 'https://video.bunnycdn.com/tusupload';
	const META = 'bunny';

	// Bunny's video status codes.
	const FINISHED = 4;
	const FAILED   = array( 5, 6 );

	/* ---------- Settings ---------- */

	public static function defaults() {
		return array(
			'provider'      => 'local', // local | bunny
			'library_id'    => '',
			'api_key'       => '', // encrypted
			'cdn_host'      => '', // e.g. vz-1234abcd-56.b-cdn.net
			'webhook_token' => '',
		);
	}

	public static function settings() {
		$saved = Settings::get( 'video' );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function enabled() {
		$v = self::settings();
		return 'bunny' === $v['provider'] && $v['library_id'] && $v['api_key'] && $v['cdn_host'];
	}

	public static function public_settings() {
		$v                = self::settings();
		$v['has_api_key'] = '' !== $v['api_key'];
		$v['api_key']     = '';
		$v['webhook_url'] = self::webhook_url();
		unset( $v['webhook_token'] );
		return $v;
	}

	public static function sanitize( array $in, array $current ) {
		$v = array_merge( self::defaults(), $current );
		if ( isset( $in['provider'] ) ) {
			$v['provider'] = 'bunny' === $in['provider'] ? 'bunny' : 'local';
		}
		if ( array_key_exists( 'library_id', $in ) ) {
			$v['library_id'] = preg_replace( '/\D/', '', (string) $in['library_id'] );
		}
		if ( array_key_exists( 'cdn_host', $in ) ) {
			// People paste full URLs; keep just the host name.
			$host          = strtolower( trim( (string) $in['cdn_host'] ) );
			$host          = preg_replace( '~^https?://~', '', $host );
			$v['cdn_host'] = preg_replace( '~[/?#].*$~', '', $host );
			if ( '' !== $v['cdn_host'] && ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $v['cdn_host'] ) ) {
				return new \WP_Error( 'mbcom_invalid_setting', __( 'The CDN hostname should look like vz-12345678-910.b-cdn.net.', 'membuild-community' ), array( 'status' => 400 ) );
			}
		}
		if ( ! empty( $in['api_key'] ) ) {
			if ( Vault::paused() ) {
				return Vault::paused();
			}
			$v['api_key'] = Vault::secret( trim( (string) $in['api_key'] ) );
		}
		if ( ! $v['webhook_token'] ) {
			$v['webhook_token'] = wp_generate_password( 32, false );
		}
		if ( 'bunny' === $v['provider'] && ( ! $v['library_id'] || ! $v['api_key'] || ! $v['cdn_host'] ) ) {
			return new \WP_Error( 'mbcom_invalid_setting', __( 'Bunny Stream needs the library ID, API key and CDN hostname.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		return $v;
	}

	public static function webhook_url() {
		$token = self::settings()['webhook_token'];
		return $token ? add_query_arg( 'token', $token, rest_url( MEMBUILD_COMMUNITY_REST_NS . '/bunny/webhook' ) ) : '';
	}

	/* ---------- Bunny's API ---------- */

	/** @return array|\WP_Error */
	private static function api( $method, $path, $body = null ) {
		$v   = self::settings();
		$res = wp_remote_request(
			self::API . '/library/' . rawurlencode( $v['library_id'] ) . $path,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'AccessKey'    => Vault::reveal( $v['api_key'] ),
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = 401 === $code ? __( 'Bunny didn’t accept the API key. Use the library’s Stream API key, not your account key.', 'membuild-community' )
				: ( 404 === $code ? __( 'Bunny couldn’t find that video library. Check the library ID.', 'membuild-community' )
				/* translators: %d: HTTP status code */
				: sprintf( __( 'Bunny returned an error (%d).', 'membuild-community' ), $code ) );
			return new \WP_Error( 'mbcom_bunny', $msg, array( 'status' => 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}

	public static function playlist( $guid ) {
		return 'https://' . self::settings()['cdn_host'] . '/' . $guid . '/playlist.m3u8';
	}

	public static function thumbnail( $guid ) {
		return 'https://' . self::settings()['cdn_host'] . '/' . $guid . '/thumbnail.jpg';
	}

	/* ---------- Routes ---------- */

	public static function register_routes() {
		$member = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/media/bunny', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'start' ), 'permission_callback' => $member ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/media/bunny/(?P<id>\d+)/complete', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'complete' ), 'permission_callback' => $member ) );
		// Bunny calls this; it proves itself with the secret token in the URL.
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/bunny/webhook', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'webhook' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/settings/test-video', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test' ), 'permission_callback' => fn() => is_user_logged_in() && Helpers::can_manage() ) );
	}

	public static function test() {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'mbcom_bunny', __( 'Save the Bunny Stream settings first.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$res = self::api( 'GET', '/videos?page=1&itemsPerPage=1' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'ok' => true, 'videos' => (int) ( $res['totalItems'] ?? 0 ) );
	}

	/**
	 * Creates the video in Bunny and a media row for it, and returns what the
	 * browser needs to upload straight to Bunny: a signature that only works
	 * for this one video and expires in a few hours.
	 */
	public static function start( \WP_REST_Request $req ) {
		global $wpdb;
		if ( ! self::enabled() ) {
			return new \WP_Error( 'mbcom_bunny_off', __( 'Bunny Stream is not set up.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$blocked = Moderation::guard();
		if ( $blocked ) {
			return $blocked;
		}
		if ( ! Helpers::rate_ok( 'bunny', 15, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'mbcom_slow_down', __( 'That’s a lot of videos. Please wait a while before uploading more.', 'membuild-community' ), array( 'status' => 429 ) );
		}
		$me   = get_current_user_id();
		$size = (int) $req['size'];
		if ( $size <= 0 || $size > 2 * GB_IN_BYTES ) {
			return new \WP_Error( 'mbcom_too_big', __( 'Videos can be up to 2 GB.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$title = mb_substr( sanitize_text_field( (string) ( $req['name'] ?? 'Video' ) ), 0, 100 ) ?: 'Video';
		$video = self::api( 'POST', '/videos', array( 'title' => $title ) );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		$guid = (string) ( $video['guid'] ?? '' );
		if ( ! $guid ) {
			return new \WP_Error( 'mbcom_bunny', __( 'Bunny didn’t create the video.', 'membuild-community' ), array( 'status' => 502 ) );
		}

		$poster = ! empty( $req['poster_media_id'] ) ? Media::claim_poster( $me, (int) $req['poster_media_id'] ) : null;
		$wpdb->insert(
			Install::table( 'media' ),
			array(
				'user_id'    => $me,
				'kind'       => 'video',
				'url'        => self::playlist( $guid ),
				'poster_url' => $poster ?: self::thumbnail( $guid ),
				'mime'       => 'video/bunny',
				'width'      => min( 8000, max( 0, (int) $req['width'] ) ),
				'height'     => min( 8000, max( 0, (int) $req['height'] ) ),
				'duration'   => min( 36000, max( 0, (float) $req['duration'] ) ),
				'meta'       => wp_json_encode( array( 'provider' => self::META, 'guid' => $guid, 'library' => self::settings()['library_id'], 'status' => 'uploading' ) ),
				'created_at' => Helpers::now(),
			)
		);
		$id = (int) $wpdb->insert_id;

		$v       = self::settings();
		$expires = time() + 6 * HOUR_IN_SECONDS;
		return array(
			'media' => Media::format( Media::get( $id ) ),
			'tus'   => array(
				'endpoint' => self::TUS,
				'headers'  => array(
					'AuthorizationSignature' => hash( 'sha256', $v['library_id'] . Vault::reveal( $v['api_key'] ) . $expires . $guid ),
					'AuthorizationExpire'    => (string) $expires,
					'VideoId'                => $guid,
					'LibraryId'              => $v['library_id'],
				),
				'metadata' => array( 'filetype' => (string) ( $req['mime'] ?: 'video/mp4' ), 'title' => $title ),
			),
		);
	}

	public static function complete( \WP_REST_Request $req ) {
		$row = Media::get( (int) $req['id'] );
		if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Video not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		self::set_status( $row, 'processing' );
		self::refresh( Media::get( (int) $row['id'] ), true );
		return Media::format( Media::get( (int) $row['id'] ) );
	}

	private static function set_status( array $row, $status, array $extra = array() ) {
		global $wpdb;
		$meta           = json_decode( (string) $row['meta'], true ) ?: array();
		$meta['status'] = $status;
		$update         = array( 'meta' => wp_json_encode( $meta ) ) + $extra;
		$wpdb->update( Install::table( 'media' ), $update, array( 'id' => $row['id'] ) );
	}

	/**
	 * Asks Bunny how a video is doing, at most every 20 seconds per video.
	 * Used when a processing video is shown, in case the webhook isn't set up.
	 */
	public static function refresh( $row, $force = false ) {
		if ( ! $row || ! self::enabled() ) {
			return;
		}
		$meta = json_decode( (string) $row['meta'], true ) ?: array();
		if ( ( $meta['provider'] ?? '' ) !== self::META || in_array( $meta['status'] ?? '', array( 'ready', 'failed', 'uploading' ), true ) ) {
			return;
		}
		$lock = 'mbcom_bunny_check_' . $row['id'];
		if ( ! $force && get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 20 );
		$video = self::api( 'GET', '/videos/' . rawurlencode( $meta['guid'] ) );
		if ( is_wp_error( $video ) ) {
			return;
		}
		self::apply( $row, $video );
	}

	/** Applies Bunny's report for a video to its media row. */
	private static function apply( array $row, array $video ) {
		$status = (int) ( $video['status'] ?? 0 );
		if ( self::FINISHED === $status || ( 3 === $status && ! empty( $video['availableResolutions'] ) ) ) {
			self::set_status(
				$row,
				'ready',
				array_filter(
					array(
						'width'    => (int) ( $video['width'] ?? 0 ),
						'height'   => (int) ( $video['height'] ?? 0 ),
						'duration' => (float) ( $video['length'] ?? 0 ),
					)
				)
			);
		} elseif ( in_array( $status, self::FAILED, true ) ) {
			self::set_status( $row, 'failed' );
		}
	}

	public static function webhook( \WP_REST_Request $req ) {
		global $wpdb;
		$token = self::settings()['webhook_token'];
		if ( ! $token || ! hash_equals( $token, (string) $req['token'] ) ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'Bad token.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$guid = sanitize_text_field( (string) ( $req['VideoGuid'] ?? '' ) );
		if ( ! $guid ) {
			return array( 'ok' => true );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE mime = 'video/bunny' AND meta LIKE %s", Install::table( 'media' ), '%' . $wpdb->esc_like( '"guid":"' . $guid . '"' ) . '%' ), ARRAY_A );
		if ( $row ) {
			$report = array( 'status' => (int) $req['Status'] );
			if ( self::FINISHED === $report['status'] ) {
				$video  = self::api( 'GET', '/videos/' . rawurlencode( $guid ) ); // for the final size and length
				$report = is_wp_error( $video ) ? $report : $video + $report;
			}
			self::apply( $row, $report );
		}
		return array( 'ok' => true );
	}

	/* ---------- Clean-up ---------- */

	public static function register_hooks() {
	}

	/** Deletes one media row's video from Bunny (used when an unused upload is cleaned up). */
	public static function delete_video( array $row ) {
		$meta = json_decode( (string) $row['meta'], true ) ?: array();
		if ( self::enabled() && ! empty( $meta['guid'] ) && ( $meta['library'] ?? '' ) === self::settings()['library_id'] ) {
			self::api( 'DELETE', '/videos/' . rawurlencode( $meta['guid'] ) );
		}
	}

	/** What the app needs to play a Bunny video, added to the media JSON. */
	public static function stream_info( array $row ) {
		$meta = json_decode( (string) $row['meta'], true ) ?: array();
		if ( ( $meta['provider'] ?? '' ) !== self::META ) {
			return null;
		}
		if ( 'processing' === ( $meta['status'] ?? '' ) ) {
			self::refresh( $row );
			$meta = json_decode( (string) ( Media::get( (int) $row['id'] )['meta'] ?? '' ), true ) ?: $meta;
		}
		return array( 'type' => 'hls', 'status' => $meta['status'] ?? 'processing' );
	}
}
