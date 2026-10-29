<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the member app at /community/*.
 *
 * The app shell is printed directly (no theme, no wp_head) so the page only
 * loads our own small bundle. Public, SEO-facing pages come in a later phase.
 */
class Portal {

	public static function register_hooks() {
		self::add_rewrite_rule();
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_land' ), 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'fresh_nonce' ), 10, 3 );
		add_action( 'wp_ajax_mbcom_nonce', array( __CLASS__, 'ajax_nonce' ) );
	}

	/**
	 * Every community API response carries a fresh REST nonce, so an app left
	 * open for days keeps working: nonces expire after 12–24 hours.
	 */
	public static function fresh_nonce( $response, $server, $request ) {
		if ( is_user_logged_in() && str_starts_with( $request->get_route(), '/' . MEMBUILD_COMMUNITY_REST_NS ) ) {
			$response->header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return $response;
	}

	/**
	 * For a tab whose nonce already expired: the login cookie alone gets a new one.
	 * Other sites can trigger this request but never read the answer (same-origin policy).
	 */
	public static function ajax_nonce() {
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public static function url() {
		return home_url( '/' . Settings::slug() . '/' );
	}

	/**
	 * Settings → "Where members land". Only the site's home page is affected;
	 * every other page opens as usual. ?mbcom_site=1 shows the home page anyway
	 * (the community's "Visit site" link uses it).
	 */
	public static function maybe_land() {
		$mode = Settings::get( 'landing' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view switch
		if ( 'off' === $mode || ! is_front_page() || is_paged() || is_preview() || is_customize_preview() || isset( $_GET['mbcom_site'] ) ) {
			return;
		}
		if ( 'members' === $mode && ! is_user_logged_in() ) {
			return; // Visitors keep the public home page.
		}
		if ( is_user_logged_in() && Moderation::is_banned( get_current_user_id() ) ) {
			return;
		}
		wp_safe_redirect( self::url(), 302 );
		exit;
	}

	/**
	 * After logging in, members go to the community instead of their WordPress
	 * profile. Admins keep wp-admin, and a specific page someone was heading to wins.
	 */
	public static function login_redirect( $to, $requested, $user ) {
		if ( 'off' === Settings::get( 'landing' ) || ! $user instanceof \WP_User || user_can( $user, 'manage_options' ) || Moderation::is_banned( $user->ID ) ) {
			return $to;
		}
		$defaults = array( '', admin_url(), admin_url( '/' ), admin_url( 'profile.php' ), home_url(), home_url( '/' ) );
		return in_array( (string) $requested, $defaults, true ) && in_array( (string) $to, $defaults, true ) ? self::url() : $to;
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^' . preg_quote( Settings::slug(), '/' ) . '(?:/(.*))?/?$', 'index.php?mbcom_portal=1&mbcom_path=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'mbcom_portal';
		$vars[] = 'mbcom_path';
		return $vars;
	}

	public static function maybe_render() {
		if ( ! get_query_var( 'mbcom_portal' ) ) {
			return;
		}

		// The app manifest and worker are public files; everything else needs a sign-in.
		$path = trim( (string) get_query_var( 'mbcom_path' ), '/' );
		App::maybe_serve( $path );
		Login::maybe_handle( $path );
		// Protected files answer with a status code (never a login page), since they load in <img> and <video>.
		// file/12, or file/12/Its name.pdf (Preview: so the browser's viewer shows the name, not "12").
		if ( preg_match( '#^file/(\d+)(?:/[^/]+)?$#', $path, $m ) ) {
			Files::serve( (int) $m[1] );
		}

		if ( ! is_user_logged_in() ) {
			$mbcom_request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' . Settings::slug();
			wp_safe_redirect( wp_login_url( home_url( $mbcom_request ) ) );
			exit;
		}

		Helpers::no_page_cache();
		if ( Moderation::is_banned( get_current_user_id() ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'You no longer have access to this community.', 'membuild-community' ),
				esc_html( Settings::name() ),
				array( 'response' => 403, 'link_url' => esc_url( home_url( '/' ) ), 'link_text' => esc_html__( 'Back to the site', 'membuild-community' ) )
			);
		}
		status_header( 200 );

		$config = array(
			'restUrl'  => esc_url_raw( rest_url( MEMBUILD_COMMUNITY_REST_NS ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ), // for refreshing the nonce in tabs left open overnight
			'basePath' => wp_parse_url( home_url( '/' . Settings::slug() ), PHP_URL_PATH ),
			'siteName' => Settings::name(),
			'community' => Settings::public_config(),
			// wp_logout_url() is HTML-escaped (&amp;); the app needs the plain URL or the nonce gets lost.
			'logoutUrl' => html_entity_decode( wp_logout_url( Login::enabled() ? Login::url( 'login', array( 'loggedout' => '1' ) ) : home_url() ) ),
			'siteUrl'   => add_query_arg( 'mbcom_site', '1', home_url( '/' ) ),
			'adminUrl'  => Helpers::can_manage() ? admin_url( 'admin.php?page=membuild-community' ) : '', // the plugin's Community overview in wp-admin
			'shortcuts' => array(
				'items'   => Menu::items(),
				'editUrl' => current_user_can( 'edit_theme_options' ) ? Menu::edit_url() : '',
			),
			'user'     => Helpers::user( wp_get_current_user() ),
			'canModerate' => Helpers::can_moderate(),
			'firstRun'    => First_Run::config(),
			// "Confirm your email" on Home, a few days after joining while the welcome email is unanswered.
			'confirmEmail' => Email::banner( get_current_user_id() ) ? array( 'email' => wp_get_current_user()->user_email, 'profileUrl' => get_edit_profile_url() ) : null,
			'app'      => array(
				'worker' => wp_parse_url( home_url( '/' . Settings::slug() . '/sw.js' ), PHP_URL_PATH ),
				// Admins' browsers draw the home-screen icon when there's no logo and the name or color changed.
				'drawIcon'  => Helpers::can_manage() && App::needs_drawn_icon(),
				'initials'  => Settings::initials(),
			),
			'limits'   => array(
				'reelSeconds' => 60,
				'chunkBytes'  => Media::chunk_bytes(),
			),
		);

		$built    = self::register_assets( $config );
		$icons    = App::icons();
		$manifest = home_url( '/' . Settings::slug() . '/manifest.webmanifest' );
		$accent   = Settings::get( 'accent' ) ?: '#3b5bfd';
		include MEMBUILD_COMMUNITY_DIR . 'templates/portal.php';
		exit;
	}

	/**
	 * Registers the app's script and stylesheet the WordPress way, so they load
	 * with core's translation library (wp-i18n) and the community's language.
	 * The template prints them with wp_print_styles() / wp_print_scripts().
	 */
	private static function register_assets( array $config ) {
		$manifest_file = MEMBUILD_COMMUNITY_DIR . 'assets/build/.vite/manifest.json';
		$entry         = is_readable( $manifest_file ) ? ( json_decode( (string) file_get_contents( $manifest_file ), true )['index.html'] ?? null ) : null;
		if ( ! $entry ) {
			return false;
		}
		$base = MEMBUILD_COMMUNITY_URL . 'assets/build/';
		foreach ( array_values( $entry['css'] ?? array() ) as $i => $css ) {
			wp_register_style( 'mbcom-app' . ( $i ? "-{$i}" : '' ), $base . $css, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the file name carries a content hash
		}
		wp_register_script( 'mbcom-app', $base . $entry['file'], array( 'wp-i18n' ), null, array( 'in_footer' => true ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- hashed file name
		wp_add_inline_script( 'mbcom-app', 'window.membuildCommunity = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
		$strings = self::translations();
		if ( $strings ) {
			wp_add_inline_script( 'mbcom-app', 'wp.i18n.setLocaleData(' . wp_json_encode( $strings, JSON_HEX_TAG ) . ', "membuild-community");', 'before' );
		}
		// The app is an ES module.
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle ) {
				if ( 'mbcom-app' !== $handle ) {
					return $tag;
				}
				return preg_replace( '#<script(?:\s+type=([\'"])[^\'"]*\1)?([^>]*\ssrc=)#', '<script type="module"$2', $tag, 1 );
			},
			10,
			2
		);
		return true;
	}

	/**
	 * The app's translated strings for the current language. Language packs
	 * split them into one JSON file per built script; the app is one bundle
	 * plus lazily loaded pieces, so all of them are merged and handed over at once.
	 */
	private static function translations() {
		$locale = determine_locale();
		if ( 'en_US' === $locale ) {
			return null;
		}
		$files = array_merge(
			glob( WP_LANG_DIR . '/plugins/membuild-community-' . $locale . '-*.json' ) ?: array(),
			glob( MEMBUILD_COMMUNITY_DIR . 'languages/membuild-community-' . $locale . '-*.json' ) ?: array()
		);
		$messages = array();
		foreach ( $files as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			$set  = $data['locale_data']['membuild-community'] ?? ( $data['locale_data']['messages'] ?? null );
			if ( is_array( $set ) ) {
				$messages = array_merge( $set, $messages ); // keeps the first header ("" entry) found
			}
		}
		return $messages ?: null;
	}
}
