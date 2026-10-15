<?php
/**
 * Protected files: message photos and lesson files.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Files that only some members may see live in uploads/mbcom-private/ under
 * random names, behind deny rules, and are served through the community at
 * /{slug}/file/{media id} after a permission check:
 *
 * - "message": photos in a chat, for the people in that chat;
 * - "lesson":  lesson videos and downloads, for members who can open the lesson.
 *
 * Admins can open lesson files; message photos are for the chat's members only,
 * in keeping with admins never seeing private messages.
 */
class Files {

	const DIR          = 'mbcom-private';
	const MIGRATE_HOOK = 'mbcom_migrate_private_files';
	const BATCH        = 100;
	const CANARY       = 'canary.txt'; // Site Health asks for this to check the folder is blocked

	public static function register_hooks() {
		add_action( self::MIGRATE_HOOK, array( __CLASS__, 'migrate_batch' ) );
	}

	/** The protected folder, created with deny rules for Apache and IIS on first use. */
	public static function dir() {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . self::DIR . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			self::CANARY => "If you can read this in a browser, protected community files are reachable from the web.\n",
		);
		foreach ( $guards as $name => $body ) {
			if ( ! file_exists( $dir . $name ) ) {
				file_put_contents( $dir . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard files in the plugin's own folder inside uploads; WP_Filesystem may need FTP credentials during a REST request.
			}
		}
		return $dir;
	}

	/** The address the app uses for a protected file. */
	public static function url( $media_id ) {
		return home_url( '/' . Settings::slug() . '/file/' . (int) $media_id );
	}

	/**
	 * Moves a stored upload into the protected folder and records who it belongs to.
	 * Media without a file of its own (YouTube embeds, Bunny videos) only gets the scope.
	 *
	 * @param int    $media_id Media row.
	 * @param string $scope    "message" or "lesson".
	 * @param int    $scope_id Thread or lesson ID.
	 * @return string|null The URL to use for the file, or null when the media doesn't exist.
	 */
	public static function protect( $media_id, $scope, $scope_id ) {
		global $wpdb;
		$row = Media::get( (int) $media_id );
		if ( ! $row ) {
			return null;
		}
		$data = array( 'scope' => $scope, 'scope_id' => (int) $scope_id );
		$path = $row['attachment_id'] ? get_attached_file( (int) $row['attachment_id'] ) : '';
		if ( $path && file_exists( $path ) ) {
			if ( ! str_starts_with( wp_normalize_path( $path ), wp_normalize_path( self::dir() ) ) ) {
				$target = self::dir() . wp_generate_password( 32, false ) . '.' . strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				if ( @rename( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- moving the plugin's own upload within uploads; a failure leaves it where it was.
					update_attached_file( (int) $row['attachment_id'], $target );
				}
			}
			$data['url'] = self::url( $row['id'] );
		}
		$wpdb->update( Install::table( 'media' ), $data, array( 'id' => $row['id'] ) );
		return $data['url'] ?? $row['url'];
	}

	/** Whether the current visitor may open a protected file. */
	public static function can_view( array $row ) {
		global $wpdb;
		$me = get_current_user_id();
		if ( ! $me ) {
			return false;
		}
		if ( 'message' === $row['scope'] ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare( 'SELECT 1 FROM %i WHERE thread_id = %d AND user_id = %d AND left_at IS NULL', Install::table( 'thread_members' ), $row['scope_id'], $me )
			);
		}
		if ( 'lesson' === $row['scope'] ) {
			return Helpers::can_manage() || Rest_Courses::can_open_lesson( (int) $row['scope_id'] );
		}
		return false;
	}

	/**
	 * Sends a protected file, or a 403/404. Supports byte ranges, so lesson
	 * videos can be scrubbed. Hosts that can hand files to the web server
	 * (X-Sendfile, X-Accel-Redirect) can take over through the
	 * membuild_community/send_protected_file filter.
	 */
	public static function serve( $media_id ) {
		$row  = Media::get( (int) $media_id );
		$path = $row && $row['attachment_id'] ? get_attached_file( (int) $row['attachment_id'] ) : '';
		if ( ! $row || ! $row['scope'] || ! $path || ! is_readable( $path ) ) {
			self::fail( 404 );
		}
		if ( Moderation::is_banned( get_current_user_id() ) || ! self::can_view( $row ) ) {
			self::fail( is_user_logged_in() ? 403 : 401 );
		}

		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $row['mime'] );
		if ( 'file' === $row['kind'] ) {
			$name = sanitize_file_name( get_the_title( (int) $row['attachment_id'] ) ?: 'download' ) . '.' . pathinfo( $path, PATHINFO_EXTENSION );
			header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		}

		/**
		 * Lets a host send the file itself (e.g. with X-Accel-Redirect). Return true when handled.
		 *
		 * @param bool   $handled Whether the file was sent.
		 * @param string $path    Absolute path of the file.
		 * @param array  $row     The media row.
		 */
		if ( apply_filters( 'membuild_community/send_protected_file', false, $path, $row ) ) {
			exit;
		}
		self::stream( $path );
		exit;
	}

	/** Streams a file, honouring a single "Range: bytes=a-b" request. */
	private static function stream( $path ) {
		$size  = filesize( $path );
		$start = 0;
		$end   = $size - 1;
		header( 'Accept-Ranges: bytes' );
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] ); // "the last N bytes"
			} else {
				$start = (int) $m[1];
				$end   = '' === $m[2] ? $end : min( $end, (int) $m[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				return;
			}
			status_header( 206 );
			header( "Content-Range: bytes {$start}-{$end}/{$size}" );
		} else {
			status_header( 200 );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 'HEAD' === Helpers::method() ) {
			return;
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download in chunks keeps memory flat on small hosts
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$chunk = fread( $fh, min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw file bytes
			flush();
			$left -= strlen( $chunk );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	private static function fail( $status ) {
		status_header( $status );
		nocache_headers();
		exit;
	}

	/* ---------- Moving files from earlier versions ---------- */

	public static function schedule_migration() {
		update_option( 'mbcom_files_migration', array( 'phase' => 'messages', 'after' => 0 ), false );
		if ( ! wp_next_scheduled( self::MIGRATE_HOOK ) ) {
			wp_schedule_single_event( time(), self::MIGRATE_HOOK );
		}
	}

	/** One batch per run, rescheduling itself until message photos and lesson files are all protected. */
	public static function migrate_batch() {
		global $wpdb;
		$state = get_option( 'mbcom_files_migration' );
		if ( ! is_array( $state ) ) {
			return;
		}
		if ( 'messages' === $state['phase'] ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, thread_id, media FROM %i WHERE id > %d AND media IS NOT NULL ORDER BY id LIMIT %d', Install::table( 'messages' ), $state['after'], self::BATCH ), ARRAY_A );
			foreach ( $rows as $msg ) {
				$media = json_decode( (string) $msg['media'], true ) ?: array();
				foreach ( $media as $i => $m ) {
					$media[ $i ]['url'] = self::protect( (int) ( $m['id'] ?? 0 ), 'message', (int) $msg['thread_id'] ) ?? ( $m['url'] ?? '' );
				}
				$wpdb->update( Install::table( 'messages' ), array( 'media' => wp_json_encode( $media ) ), array( 'id' => $msg['id'] ) );
				$state['after'] = (int) $msg['id'];
			}
			if ( count( $rows ) < self::BATCH ) {
				$state = array( 'phase' => 'lessons', 'after' => 0 );
			}
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, media_id, resources FROM %i WHERE id > %d ORDER BY id LIMIT %d', Install::table( 'lessons' ), $state['after'], self::BATCH ), ARRAY_A );
			foreach ( $rows as $lesson ) {
				if ( $lesson['media_id'] ) {
					self::protect( (int) $lesson['media_id'], 'lesson', (int) $lesson['id'] );
				}
				$resources = json_decode( (string) $lesson['resources'], true ) ?: array();
				foreach ( $resources as $i => $res ) {
					$resources[ $i ]['url'] = self::protect( (int) ( $res['media_id'] ?? 0 ), 'lesson', (int) $lesson['id'] ) ?? ( $res['url'] ?? '' );
				}
				if ( $resources ) {
					$wpdb->update( Install::table( 'lessons' ), array( 'resources' => wp_json_encode( $resources ) ), array( 'id' => $lesson['id'] ) );
				}
				$state['after'] = (int) $lesson['id'];
			}
			if ( count( $rows ) < self::BATCH ) {
				delete_option( 'mbcom_files_migration' );
				return;
			}
		}
		update_option( 'mbcom_files_migration', $state, false );
		wp_schedule_single_event( time() + 30, self::MIGRATE_HOOK );
	}
}
