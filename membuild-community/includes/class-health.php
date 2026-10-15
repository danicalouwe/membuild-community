<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Checks for the hosting setups that quietly break a community, shown in
 * Tools → Site Health and, for what stops things (no pretty permalinks, a lost
 * encryption key) or must not be put off (keeping a copy of that key), as
 * notices for admins.
 */
class Health {

	public static function register_hooks() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_action( 'admin_notices', array( __CLASS__, 'permalink_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'key_notice' ) );
	}

	public static function tests( $tests ) {
		$checks = array(
			'permalinks' => __( 'Community address works', 'membuild-community' ),
			'cron'       => __( 'Community emails can be sent on schedule', 'membuild-community' ),
			'https'      => __( 'Community app can be installed on phones', 'membuild-community' ),
			'images'     => __( 'Community images can be resized', 'membuild-community' ),
			'uploads'    => __( 'Community uploads fit the server’s limits', 'membuild-community' ),
			'email'      => __( 'Community email delivery', 'membuild-community' ),
			'files'      => __( 'Protected community files are private', 'membuild-community' ),
			'encryption' => __( 'Private messages are encrypted', 'membuild-community' ),
		);
		foreach ( $checks as $key => $label ) {
			$tests['direct'][ 'mbcom_' . $key ] = array(
				'label' => $label,
				'test'  => fn() => self::result( $key, $label ),
			);
		}
		return $tests;
	}

	private static function result( $key, $label ) {
		list( $status, $description, $action ) = self::check( $key );
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'Community', 'membuild-community' ), 'color' => 'blue' ),
			'description' => '<p>' . $description . '</p>',
			'actions'     => $action,
			'test'        => 'mbcom_' . $key,
		);
	}

	/** @return array{0: string, 1: string, 2: string} status, description (HTML-safe), actions (HTML-safe) */
	public static function check( $key ) {
		switch ( $key ) {
			case 'encryption':
				if ( ! Vault::ready() ) {
					return array(
						'critical',
						esc_html( self::key_problem() ),
						'<a href="' . esc_url( self::key_settings_url() ) . '">' . esc_html__( 'Restore the key', 'membuild-community' ) . '</a>',
					);
				}
				$source = Vault::key_source();
				if ( 'constant' !== $source && ! get_option( Vault::SAVED_OPTION ) ) {
					return array(
						'recommended',
						esc_html__( 'Private messages are encrypted with a key kept on this server. Nobody has confirmed keeping a copy of it yet. If the site is moved or restored without it, members’ messages can’t be read.', 'membuild-community' ),
						'<a href="' . esc_url( self::key_settings_url() ) . '">' . esc_html__( 'Save a copy of the key', 'membuild-community' ) . '</a>',
					);
				}
				if ( 'database' === $source ) {
					return array(
						'recommended',
						esc_html__( 'Private messages are encrypted, but the key is stored in the database because wp-content isn’t writable, so a copy of the database alone could be decrypted. Ask your host to let WordPress write to wp-content, or add the key to wp-config.php as MEMBUILD_COMMUNITY_KEY (64 hexadecimal characters).', 'membuild-community' ),
						'',
					);
				}
				$where = 'constant' === $source ? esc_html__( 'in wp-config.php', 'membuild-community' ) : '<code>wp-content/' . esc_html( Vault::KEY_FILE ) . '</code>';
				return array(
					'good',
					/* translators: %s: where the key is kept, e.g. "in wp-config.php" or a file name */
					sprintf( esc_html__( 'Private messages and stored passwords are encrypted, with the key kept outside the database (%s). Keep the key in your backups and move it with the site: without it, messages can’t be read.', 'membuild-community' ), $where ),
					'',
				);
			case 'files':
				// Ask the web server for a test file in the protected folder: it should refuse.
				Files::dir();
				$url  = trailingslashit( wp_upload_dir()['baseurl'] ) . Files::DIR . '/' . Files::CANARY;
				$code = (int) wp_remote_retrieve_response_code( wp_remote_get( $url, array( 'timeout' => 3, 'sslverify' => false ) ) );
				if ( 200 === $code ) {
					return array(
						'critical',
						esc_html__( 'Lesson files and photos in private messages can be opened by anyone who knows their address, because your web server ignores the folder’s block rules (common on nginx). Add this to your site’s nginx configuration:', 'membuild-community' ) . ' <code>location ^~ /wp-content/uploads/mbcom-private/ { deny all; }</code>',
						'',
					);
				}
				if ( 0 === $code ) {
					return array( 'recommended', esc_html__( 'Your site couldn’t check its own protected files folder (loopback requests are blocked). Ask your host to confirm wp-content/uploads/mbcom-private/ isn’t reachable from the web.', 'membuild-community' ), '' );
				}
				return array( 'good', esc_html__( 'Lesson files and photos in private messages can only be opened through the community, by people allowed to see them.', 'membuild-community' ), '' );
			case 'permalinks':
				if ( get_option( 'permalink_structure' ) ) {
					return array( 'good', esc_html__( 'Pretty permalinks are on, so the community opens at its own address.', 'membuild-community' ), '' );
				}
				return array(
					'critical',
					esc_html__( 'Permalinks are set to “Plain”, so the community’s address doesn’t open. Choose any other permalink setting.', 'membuild-community' ),
					'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Change permalinks', 'membuild-community' ) . '</a>',
				);

			case 'cron':
				if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
					return array( 'recommended', esc_html__( 'WordPress’s built-in scheduler is turned off (DISABLE_WP_CRON). Make sure your host runs wp-cron.php on a schedule, every 5–15 minutes, or community emails and clean-ups won’t run.', 'membuild-community' ), '' );
				}
				$next = wp_next_scheduled( Email::CRON );
				if ( $next && $next < time() - HOUR_IN_SECONDS ) {
					return array( 'recommended', esc_html__( 'Scheduled tasks are running late. WordPress only runs them when someone visits the site, so on quiet sites emails go out late. Ask your host to run wp-cron.php every 5–15 minutes.', 'membuild-community' ), '' );
				}
				return array( 'good', esc_html__( 'Scheduled tasks are running, so email summaries and clean-ups go out on time.', 'membuild-community' ), '' );

			case 'https':
				if ( wp_is_using_https() ) {
					return array( 'good', esc_html__( 'The site uses HTTPS, so members can add the community to their home screen.', 'membuild-community' ), '' );
				}
				return array( 'recommended', esc_html__( 'The site isn’t using HTTPS. Phones only let people install the community as an app on secure (https://) sites, and browsers flag the sign-in as not secure.', 'membuild-community' ), '' );

			case 'images':
				if ( wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ) ) {
					return array( 'good', esc_html__( 'The server can resize images, so the app’s home-screen icons are created.', 'membuild-community' ), '' );
				}
				return array( 'recommended', esc_html__( 'The server has neither GD nor Imagick, so home-screen icons can’t be made from your logo. Ask your host to enable the GD extension for PHP.', 'membuild-community' ), '' );

			case 'uploads':
				$limit = wp_max_upload_size();
				if ( $limit >= 2 * Media::CHUNK_BYTES ) {
					return array( 'good', esc_html__( 'Uploads are sent in 1 MB pieces, well within the server’s limit.', 'membuild-community' ), '' );
				}
				/* translators: %s: upload limit, e.g. "1 MB" */
				return array( 'critical', sprintf( esc_html__( 'The server only accepts uploads up to %s, smaller than the 1 MB pieces the community sends. Ask your host to raise upload_max_filesize and post_max_size to at least 8 MB.', 'membuild-community' ), esc_html( size_format( $limit ) ) ), '' );

			case 'email':
				$mode = Email::settings()['mode'];
				if ( 'default' !== $mode || Email::smtp_plugin() ) {
					return array( 'good', esc_html__( 'Community emails go through a dedicated email service.', 'membuild-community' ), '' );
				}
				return array(
					'recommended',
					esc_html__( 'Community emails are sent by your web host’s mail, which is often slow, rate-limited or lands in spam. Connect Resend or another SMTP service for reliable delivery.', 'membuild-community' ),
					'<a href="' . esc_url( home_url( '/' . Settings::slug() . '/settings' ) ) . '">' . esc_html__( 'Email settings', 'membuild-community' ) . '</a>',
				);
		}
		return array( 'good', '', '' );
	}

	private static function key_settings_url() {
		return home_url( '/' . Settings::slug() . '/settings#s-advanced' );
	}

	private static function key_problem() {
		return 'wrong' === Vault::state()
			? __( 'The encryption key on this server isn’t the one private messages were locked with (for example after moving the site). Messages can’t be read or sent until you restore the right key.', 'membuild-community' )
			: __( 'The encryption key for private messages is missing (for example after moving or restoring the site without wp-content/mbcom-key.php). Messages can’t be read or sent until you restore it.', 'membuild-community' );
	}

	/**
	 * A lost key stops messaging, so it's said on every admin screen. Until
	 * someone confirms keeping a copy, admins are asked to (once it's in
	 * wp-config.php, the owner already has it).
	 */
	public static function key_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! Vault::ready() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
				esc_html__( 'Community messages are paused.', 'membuild-community' ),
				esc_html( self::key_problem() ),
				esc_url( self::key_settings_url() ),
				esc_html__( 'Restore the key', 'membuild-community' )
			);
			return;
		}
		if ( 'constant' === Vault::key_source() || get_option( Vault::SAVED_OPTION ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Save a copy of your community’s encryption key.', 'membuild-community' ),
			esc_html__( 'Private messages are encrypted with a key kept on your server. Keep a copy somewhere safe, like a password manager. If the site is ever moved or restored without it, members’ messages can’t be read.', 'membuild-community' ),
			esc_url( self::key_settings_url() ),
			esc_html__( 'Save the key', 'membuild-community' )
		);
	}

	/** Without pretty permalinks nothing works, so say so on every admin screen until it's fixed. */
	public static function permalink_notice() {
		if ( get_option( 'permalink_structure' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'MemBuild - Community needs pretty permalinks.', 'membuild-community' ),
			esc_html__( 'With “Plain” permalinks the community’s address doesn’t open.', 'membuild-community' ),
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Choose another permalink setting', 'membuild-community' )
		);
	}
}
