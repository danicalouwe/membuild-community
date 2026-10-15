<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Chunked uploads into the WordPress uploads folder.
 *
 * Files arrive in small chunks so cheap hosts with a 2 MB upload limit can
 * still take a 60-second reel. Each upload session is a pair of files in
 * uploads/mbcom-tmp: <id>.part (data) and <id>.json (owner, size, progress).
 */
class Media {

	const CHUNK_BYTES = 1048576; // 1 MB: the smallest piece, safely under PHP's default 2 MB limit.
	const MAX_CHUNK   = 8388608; // 8 MB: fewer, larger pieces where the host allows them.

	// The app sends photos at 2048 px at most; these limits only stop "pixel bombs",
	// small files that claim huge dimensions and freeze the phones that show them.
	const MAX_IMAGE_SIDE   = 8000;
	const MAX_IMAGE_PIXELS = 40000000;

	const DAILY_BYTES = 2147483648; // 2 GB a member can upload per day, so one account can't fill the host's disk.

	const TYPES = array(
		'image/jpeg'      => array( 'image', 'jpg', 20 ),
		'image/png'       => array( 'image', 'png', 20 ),
		'image/webp'      => array( 'image', 'webp', 20 ),
		'image/gif'       => array( 'image', 'gif', 20 ),
		'video/mp4'       => array( 'video', 'mp4', 250 ),
		'video/webm'      => array( 'video', 'webm', 250 ),
		'video/quicktime' => array( 'video', 'mov', 250 ),
		// Lesson resources (downloads).
		'application/pdf' => array( 'file', 'pdf', 50 ),
		'application/zip' => array( 'file', 'zip', 50 ),
		'text/plain'      => array( 'file', 'txt', 5 ),
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => array( 'file', 'docx', 50 ),
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => array( 'file', 'xlsx', 50 ),
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => array( 'file', 'pptx', 50 ),
	); // mime => [kind, extension, max MB]

	/**
	 * Upload piece size for this server: half of PHP's post_max_size (each piece is
	 * one request body), between 1 and 8 MB. A 250 MB video then takes ~30 requests, not 250.
	 */
	public static function chunk_bytes() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		return (int) max( self::CHUNK_BYTES, min( self::MAX_CHUNK, $limit > 0 ? intdiv( $limit, 2 ) : self::CHUNK_BYTES ) );
	}

	public static function tmp_dir() {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'mbcom-tmp/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard file in our own temp folder inside uploads; WP_Filesystem may need FTP credentials during a REST request.
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard file in our own temp folder inside uploads; WP_Filesystem may need FTP credentials during a REST request.
		}
		return $dir;
	}

	/** @return array|\WP_Error */
	public static function start( $user_id, $mime, $size, $name ) {
		if ( ! isset( self::TYPES[ $mime ] ) ) {
			return new \WP_Error( 'mbcom_bad_type', __( 'This file type is not supported.', 'membuild-community' ), array( 'status' => 415 ) );
		}
		list( $kind, , $max_mb ) = self::TYPES[ $mime ];
		// Documents are only for lesson downloads, which only admins create.
		if ( 'file' === $kind && ! Helpers::can_manage() ) {
			return new \WP_Error( 'mbcom_bad_type', __( 'This file type is not supported.', 'membuild-community' ), array( 'status' => 415 ) );
		}
		$blocked = Moderation::guard( $user_id );
		if ( $blocked ) {
			return $blocked;
		}
		if ( ! Helpers::rate_ok( 'upload', 60, 10 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'mbcom_slow_down', __( 'That’s a lot of uploads. Please wait a few minutes.', 'membuild-community' ), array( 'status' => 429 ) );
		}
		$size = (int) $size;
		if ( $size <= 0 ) {
			return new \WP_Error( 'mbcom_empty', __( 'This file is empty.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( $size > $max_mb * MB_IN_BYTES ) {
			/* translators: %d: maximum file size in megabytes */
			return new \WP_Error( 'mbcom_too_big', sprintf( __( 'Files must be under %d MB.', 'membuild-community' ), $max_mb ), array( 'status' => 413 ) );
		}

		if ( ! Settings::is_staff( $user_id ) && self::uploaded_today( $user_id ) + $size > self::daily_bytes() ) {
			return new \WP_Error( 'mbcom_daily_limit', __( 'You’ve uploaded a lot today. Please try again tomorrow.', 'membuild-community' ), array( 'status' => 429 ) );
		}

		self::cleanup_stale();

		$id      = wp_generate_password( 24, false );
		$session = array(
			'id'         => $id,
			'user_id'    => (int) $user_id,
			'mime'       => $mime,
			'kind'       => $kind,
			'size'       => $size,
			'name'       => sanitize_file_name( $name ?: 'upload' ),
			'received'   => 0,
			'next_index' => 0,
			'created'    => time(),
		);
		self::save_session( $session );
		touch( self::tmp_dir() . $id . '.part' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- creates our own empty upload piece in the plugin's temp folder inside uploads; WP_Filesystem may need FTP credentials during a REST request.

		return array(
			'upload_id'   => $id,
			'chunk_bytes' => self::chunk_bytes(),
			'chunks'      => (int) ceil( $size / self::chunk_bytes() ),
		);
	}

	/** How much a member may upload per day, in bytes. Admins and moderators have no limit. */
	public static function daily_bytes() {
		/**
		 * Filters how much a member may upload per day.
		 *
		 * @param int $bytes Bytes per day. Default 2 GB.
		 */
		return (int) apply_filters( 'membuild_community/daily_upload_bytes', self::DAILY_BYTES );
	}

	private static function uploaded_today( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(bytes), 0) FROM %i WHERE user_id = %d AND created_at >= %s', Install::table( 'media' ), $user_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	/** @return array|\WP_Error */
	public static function append( $user_id, $id, $index, $bytes ) {
		$session = self::load_session( $user_id, $id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$index = (int) $index;

		// A retried chunk we already stored (the response got lost): accept it again.
		if ( $index < $session['next_index'] ) {
			return array( 'received' => $session['received'] );
		}
		if ( $index > $session['next_index'] ) {
			return new \WP_Error( 'mbcom_chunk_order', __( 'Chunks must arrive in order.', 'membuild-community' ), array( 'status' => 409 ) );
		}

		$length = strlen( $bytes );
		if ( 0 === $length || $length > self::chunk_bytes() || $session['received'] + $length > $session['size'] ) {
			return new \WP_Error( 'mbcom_chunk_size', __( 'Part of the upload arrived damaged. Please try again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		// The file on disk is the source of truth: two copies of the same chunk sent at once
		// would both pass the checks above, but only the first can match the stored size.
		$part = self::tmp_dir() . $id . '.part';
		clearstatcache( true, $part );
		if ( filesize( $part ) !== $session['received'] ) {
			return new \WP_Error( 'mbcom_chunk_order', __( 'Part of the upload arrived out of order. Please try again.', 'membuild-community' ), array( 'status' => 409 ) );
		}

		if ( false === file_put_contents( $part, $bytes, FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appends a chunk to our own temp piece inside uploads; WP_Filesystem has no append and may need FTP credentials.
			return new \WP_Error( 'mbcom_write', __( 'Could not store the upload.', 'membuild-community' ), array( 'status' => 500 ) );
		}

		$session['received']  += $length;
		$session['next_index'] = $index + 1;
		self::save_session( $session );

		return array( 'received' => $session['received'] );
	}

	/**
	 * Moves the finished file into uploads, registers it as an attachment and
	 * records it in the media table.
	 *
	 * @return array|\WP_Error Media row as returned to the app.
	 */
	public static function complete( $user_id, $id, array $meta ) {
		$session = self::load_session( $user_id, $id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$part = self::tmp_dir() . $id . '.part';
		clearstatcache( true, $part );
		if ( $session['received'] !== $session['size'] || filesize( $part ) !== $session['size'] ) {
			self::delete_session( $id );
			return new \WP_Error( 'mbcom_incomplete', __( 'The upload didn’t finish. Please try again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		list( $kind, $ext ) = self::TYPES[ $session['mime'] ];

		$uploads  = wp_upload_dir();
		$basename = pathinfo( $session['name'], PATHINFO_FILENAME ) ?: 'upload';
		// A random name, so nobody can guess the address of someone else's upload from its original filename.
		$filename = wp_unique_filename( $uploads['path'], wp_generate_password( 24, false ) . '.' . $ext );
		$target   = trailingslashit( $uploads['path'] ) . $filename;

		// Verify the real file content, not just what the browser claimed, before it reaches the uploads folder.
		// When WordPress can't tell, the file's own signature must match exactly what was announced.
		$check = wp_check_filetype_and_ext( $part, $filename );
		$real  = $check['type'] ?: ( function_exists( 'mime_content_type' ) && mime_content_type( $part ) === $session['mime'] ? $session['mime'] : '' );
		if ( ! isset( self::TYPES[ $real ] ) || self::TYPES[ $real ][0] !== $kind ) {
			self::delete_session( $id );
			return new \WP_Error( 'mbcom_bad_type', __( 'This file type is not supported.', 'membuild-community' ), array( 'status' => 415 ) );
		}
		// Images: the real dimensions from the file, never the ones the browser claimed.
		if ( 'image' === $kind ) {
			$dimensions = wp_getimagesize( $part );
			if ( ! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 ) {
				self::delete_session( $id );
				return new \WP_Error( 'mbcom_bad_type', __( 'This file type is not supported.', 'membuild-community' ), array( 'status' => 415 ) );
			}
			if ( max( $dimensions[0], $dimensions[1] ) > self::MAX_IMAGE_SIDE || $dimensions[0] * $dimensions[1] > self::MAX_IMAGE_PIXELS ) {
				self::delete_session( $id );
				/* translators: %s: maximum width or height in pixels, such as "8,000" */
				return new \WP_Error( 'mbcom_too_big', sprintf( __( 'This image is too large. Please use one under %s pixels wide and tall.', 'membuild-community' ), number_format_i18n( self::MAX_IMAGE_SIDE ) ), array( 'status' => 413 ) );
			}
			$meta['width']  = $dimensions[0];
			$meta['height'] = $dimensions[1];
		}
		if ( ! @rename( $part, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- moving our own temp file within uploads, like core's _wp_handle_upload; failure is handled below.
			return new \WP_Error( 'mbcom_move', __( 'Could not save the file.', 'membuild-community' ), array( 'status' => 500 ) );
		}
		self::delete_session( $id );

		$url           = trailingslashit( $uploads['url'] ) . $filename;
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => $basename,
				'post_mime_type' => $session['mime'],
				'post_status'    => 'inherit',
				'guid'           => $url,
				'post_author'    => $user_id,
			),
			$target,
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $target );
			return $attachment_id;
		}
		update_post_meta( $attachment_id, self::PRIVATE_META, 1 );

		$width    = min( 8000, max( 0, (int) ( $meta['width'] ?? 0 ) ) );
		$height   = min( 8000, max( 0, (int) ( $meta['height'] ?? 0 ) ) );
		$duration = min( 3600, max( 0, (float) ( $meta['duration'] ?? 0 ) ) );

		// Images are already resized in the browser, so skip WordPress's sub-size generation.
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'    => $width,
				'height'   => $height,
				'file'     => _wp_relative_upload_path( $target ),
				'filesize' => filesize( $target ),
			)
		);

		$poster_url = null;
		if ( 'video' === $kind && ! empty( $meta['poster_media_id'] ) ) {
			$poster_url = self::claim_poster( $user_id, (int) $meta['poster_media_id'] );
		}

		global $wpdb;
		$wpdb->insert(
			Install::table( 'media' ),
			array(
				'user_id'       => $user_id,
				'kind'          => $kind,
				'attachment_id' => $attachment_id,
				'url'           => $url,
				'poster_url'    => $poster_url,
				'mime'          => $session['mime'],
				'width'         => $width,
				'height'        => $height,
				'duration'      => $duration,
				'bytes'         => (int) filesize( $target ),
				'created_at'    => Helpers::now(),
			)
		);

		return self::format( self::get( (int) $wpdb->insert_id ) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'media' ), $id ), ARRAY_A );
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		$meta = $row['meta'] ? json_decode( $row['meta'], true ) : null;
		return array(
			'embed'    => 'embed' === $row['kind'] ? Embeds::public_meta( $meta ) : null,
			'stream'   => 'video/bunny' === $row['mime'] ? Bunny::stream_info( $row ) : null, // HLS from Bunny Stream
			'id'       => (int) $row['id'],
			'kind'     => $row['kind'],
			'url'      => $row['url'],
			'poster'   => $row['poster_url'],
			'mime'     => $row['mime'],
			'width'    => (int) $row['width'],
			'height'   => (int) $row['height'],
			'duration' => (float) $row['duration'],
		);
	}

	/**
	 * Attaches the caller's unused media to a post, in the given order.
	 *
	 * @param int[] $media_ids
	 * @return array[] The attached media rows, formatted.
	 */
	public static function attach( $user_id, $post_id, array $media_ids ) {
		global $wpdb;
		$table = Install::table( 'media' );
		$out   = array();
		foreach ( array_slice( array_map( 'intval', $media_ids ), 0, 10 ) as $order => $media_id ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET post_id = %d, sort_order = %d WHERE id = %d AND user_id = %d AND post_id IS NULL AND kind IN ('image','video','embed')",
					$table,
					$post_id,
					$order,
					$media_id,
					$user_id
				)
			);
			if ( $updated ) {
				$out[] = self::format( self::get( $media_id ) );
			}
		}
		return $out;
	}

	/**
	 * Media for many posts at once, keyed by post ID.
	 *
	 * @param int[] $post_ids
	 */
	public static function for_posts( array $post_ids ) {
		global $wpdb;
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( ! $post_ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE post_id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ') ORDER BY sort_order ASC',
				Install::table( 'media' ),
				...$post_ids
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['post_id'] ][] = self::format( $row );
		}
		return $out;
	}

	public static function claim_poster( $user_id, $media_id ) {
		global $wpdb;
		$table = Install::table( 'media' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND user_id = %d AND kind = 'image' AND post_id IS NULL", $table, $media_id, $user_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$wpdb->update( $table, array( 'kind' => 'poster', 'post_id' => 0 ), array( 'id' => $media_id ) );
		return $row['url'];
	}

	// Upload sessions live in the database, so nothing but raw pieces sits in the uploads folder.
	private static function save_session( array $session ) {
		set_transient( 'mbcom_up_' . $session['id'], $session, DAY_IN_SECONDS );
	}

	/** @return array|\WP_Error */
	private static function load_session( $user_id, $id ) {
		$data = get_transient( 'mbcom_up_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $id ) );
		if ( ! is_array( $data ) || (int) $data['user_id'] !== (int) $user_id ) {
			return new \WP_Error( 'mbcom_no_upload', __( 'Upload not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		return $data;
	}

	private static function delete_session( $id ) {
		delete_transient( 'mbcom_up_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $id ) );
		wp_delete_file( self::tmp_dir() . preg_replace( '/[^A-Za-z0-9]/', '', $id ) . '.part' );
	}

	/** Removes abandoned upload pieces older than a day. */
	private static function cleanup_stale() {
		foreach ( glob( self::tmp_dir() . '*.part' ) ?: array() as $file ) {
			if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				self::delete_session( basename( $file, '.part' ) );
			}
		}
	}

	/* ---------- Privacy and clean-up ---------- */

	const PRIVATE_META = '_mbcom_private';

	/**
	 * Community uploads (message photos, lesson files…) are WordPress attachments
	 * for storage only: keep them out of the public media endpoint, the media
	 * library and attachment pages.
	 */
	public static function register_hooks() {
		add_action( 'membuild_community/post_deleted', array( __CLASS__, 'delete_for_post' ) );
		$hide = function ( $args ) {
			$args['meta_query']   = (array) ( $args['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- needed to keep community uploads out of the public media list.
			$args['meta_query'][] = array( 'key' => self::PRIVATE_META, 'compare' => 'NOT EXISTS' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- needed to keep community uploads out of the public media list.
			return $args;
		};
		add_filter( 'rest_attachment_query', $hide );
		add_filter( 'ajax_query_attachments_args', $hide );
		add_action(
			'template_redirect',
			function () {
				if ( is_attachment() && get_post_meta( get_queried_object_id(), self::PRIVATE_META, true ) ) {
					global $wp_query;
					$wp_query->set_404();
					status_header( 404 );
				}
			}
		);
		// Asking for one by ID (/wp/v2/media/123) answers "not found", except to admins.
		add_filter(
			'rest_request_before_callbacks',
			function ( $response, $handler, $request ) {
				if ( preg_match( '#^/wp/v2/media/(\d+)#', $request->get_route(), $m ) && get_post_meta( (int) $m[1], self::PRIVATE_META, true ) && ! current_user_can( 'manage_options' ) ) {
					return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own message
				}
				return $response;
			},
			10,
			3
		);
	}

	/** Deletes a media row and its file (or its Bunny video). */
	/** Deletes an upload: its file, its video's poster frame, the Bunny copy and the row. */
	public static function delete( $media_id ) {
		global $wpdb;
		$row = self::get( (int) $media_id );
		if ( ! $row ) {
			return;
		}
		if ( $row['attachment_id'] ) {
			wp_delete_attachment( (int) $row['attachment_id'], true );
		}
		if ( 'video/bunny' === $row['mime'] ) {
			Bunny::delete_video( $row );
		}
		if ( $row['poster_url'] ) {
			$poster = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE url = %s AND kind = 'poster'", Install::table( 'media' ), $row['poster_url'] ) );
			if ( $poster ) {
				self::delete( (int) $poster );
			}
		}
		$wpdb->delete( Install::table( 'media' ), array( 'id' => $row['id'] ) );
	}

	/** A deleted post's photos and videos go with it, so their links stop working too. */
	public static function delete_for_post( $post_id ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE post_id = %d', Install::table( 'media' ), $post_id ) ) as $id ) {
			self::delete( (int) $id );
		}
	}

	/**
	 * A member took back something they uploaded but didn't post (removed it,
	 * or closed the post box). Only their own uploads that nothing uses yet.
	 */
	public static function discard( $user_id, $media_id ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND user_id = %d AND post_id IS NULL', Install::table( 'media' ), $media_id, $user_id ) );
		if ( ! $id ) {
			return new \WP_Error( 'mbcom_not_found', __( 'File not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		self::delete( $id );
		return array( 'deleted' => true );
	}

	/** Hourly: uploads nobody used within a day (never posted, sent or saved) are removed. */
	public static function cleanup_orphans() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE post_id IS NULL AND kind <> 'poster' AND created_at < %s LIMIT 100", Install::table( 'media' ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
	}
}
