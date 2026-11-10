<?php
/**
 * Paid add-ons: the list, keys, installing and updates (Settings › Add-ons).
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * A paid add-on (CRM Connect first) is its own WordPress plugin; its code never ships in
 * this one. Settings › Add-ons lists the add-ons tools.membuild.com sells, takes the key
 * the owner bought, and installs the add-on's zip from there, after checking it is the
 * exact file MemBuild signed (Ed25519, keys mb1/mb2, message
 * "membuild-release/v1\n{slug}\n{version}\n{sha256}\n").
 *
 * Which site a key may be used on (1 live site, 1 test copy, the owner's own computer
 * free) is decided only by the server; this class sends the site's address and its
 * install ID (the one usage reports use) and shows what comes back.
 *
 * Keys are saved in mbcom_addon_keys (not autoloaded):
 *   { slug: { key, last: { the last addon_check reply }, checked: time, error: { code, message, … } | null } }
 * and the last daily addon_update reply per add-on in mbcom_addon_updates.
 *
 * Updates for installed add-ons come through WordPress's own update screens: once a day
 * each installed add-on asks addon_update; a newer version is offered only while the key
 * gets updates, and the download is checked the same way before WordPress unpacks it.
 *
 *   GET  /settings/addons            the add-ons, with this site's keys and what's installed
 *   POST /settings/addons/check      {slug, key, move?, use_here?}
 *   POST /settings/addons/install    {slug}
 *   POST /settings/addons/download   {slug}: a fresh 1-hour link to the zip, for installing by hand
 *   POST /settings/addons/remove     {slug, which: 'this' | 'test'}
 *
 * The routes sit under /settings/, never /addons/: that space belongs to the add-ons' own routes.
 */
class Addons {

	const OPTION     = 'mbcom_addon_keys';
	const UPDATES    = 'mbcom_addon_updates';
	const LIST_CACHE = 'mbcom_addon_list';
	const CRON       = 'mbcom_addon_updates';
	const PACKAGE    = 'mbcom-addon:'; // WordPress's update "package" for an add-on: a marker, never a link
	const SERVER     = 'https://tools.membuild.com/api.php';

	/** Public keys that may sign an add-on: key id => base64 Ed25519 public key (the same as MemBuild's other plugins). */
	const KEYS = array(
		'mb1' => 'zFI4mr4DGwQRfXgsYkB1UpA9GIUIqu0GAixEmOyS/Vc=',
		'mb2' => 'KKHGJGIS2Pi9NEvpSifam7dpDCFgzVUTdJYXEoQZf74=',
	);

	/** Replaced only by the tests (through reflection), so they can sign a zip of their own. */
	private static $keys = null;

	/** Shown when tools.membuild.com can't be reached, so the owner still sees what there is. */
	public static function fallback() {
		return array(
			array(
				'slug'     => 'crm-connect',
				'name'     => 'CRM Connect',
				'line'     => __( 'One add-on for FluentCRM, GoHighLevel, Salesforce and Keap. Tags open memberships, and what members do comes back as tags.', 'membuild-community' ),
				'price'    => '$69',
				'page_url' => 'https://membuild.com/add-ons/crm-connect',
				'version'  => '',
			),
		);
	}

	public static function register_hooks() {
		add_action( self::CRON, array( __CLASS__, 'check_updates' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
				}
			}
		);
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'offer' ) );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
	}

	public static function register_routes() {
		if ( ! self::here() ) {
			return;
		}
		$owner = fn() => is_user_logged_in() && Helpers::can_manage();
		// Putting a plugin on the site takes WordPress's own rights for it (on multisite: super admins).
		$installer = fn() => $owner() && current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
		$ns        = MEMBUILD_COMMUNITY_REST_NS;
		register_rest_route( $ns, '/settings/addons', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $owner ) );
		register_rest_route( $ns, '/settings/addons/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_check' ), 'permission_callback' => $owner ) );
		register_rest_route( $ns, '/settings/addons/install', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_install' ), 'permission_callback' => $installer ) );
		register_rest_route( $ns, '/settings/addons/download', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_download' ), 'permission_callback' => $installer ) );
		register_rest_route( $ns, '/settings/addons/remove', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_remove' ), 'permission_callback' => $owner ) );
	}

	/* ---------- Talking to tools.membuild.com ---------- */

	/** The API's address: tools.membuild.com, or MBCOM_TOOLS_URL (a staging or local server; its api.php is added when left out). */
	public static function server() {
		if ( defined( 'MBCOM_TOOLS_URL' ) && is_string( MBCOM_TOOLS_URL ) && '' !== MBCOM_TOOLS_URL ) {
			$url = rtrim( MBCOM_TOOLS_URL, '/' );
			return str_ends_with( $url, '.php' ) ? $url : $url . '/api.php';
		}
		return self::SERVER;
	}

	/**
	 * One request to the server: the decoded reply, or null when there was no usable answer.
	 * Error replies ({ok:false, code}) come back as they are, whatever the HTTP status.
	 */
	private static function ask( $action, ?array $body = null, $timeout = 15 ) {
		$args = array(
			'timeout' => $timeout,
			'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'MemBuild-Community/' . MEMBUILD_COMMUNITY_VERSION ),
		);
		$url  = self::server() . '?action=' . rawurlencode( $action );
		if ( null === $body ) {
			$response = wp_remote_get( $url, $args );
		} else {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$response                        = wp_remote_post( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && isset( $data['ok'] ) ? $data : null;
	}

	/** What every key request carries about this site. */
	private static function about_site( $slug, $key ) {
		return array(
			'key'        => $key,
			'slug'       => $slug,
			'site_url'   => home_url(),
			'install_id' => self::install_id(),
		);
	}

	/** The plugin's install ID, shared with usage reports. Made on first use, whether or not usage sharing is on. */
	public static function install_id() {
		return (string) Usage::state()['id'];
	}

	/** This site's address the way the server compares them: lowercase, no scheme, no www., no trailing /. */
	public static function normalize( $url ) {
		$url = strtolower( trim( (string) $url ) );
		$url = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $url );
		$url = preg_replace( '#^www\.#', '', $url );
		return rtrim( $url, '/' );
	}

	/** A plain message for when the server didn't answer. */
	private static function no_answer() {
		return array(
			'ok'      => false,
			'code'    => 'no_answer',
			'message' => __( 'MemBuild couldn’t be reached. Please try again in a few minutes.', 'membuild-community' ),
		);
	}

	/* ---------- The list ---------- */

	/** The add-ons for sale, from tools.membuild.com (kept 12 hours), or the built-in entry when it can't be reached. */
	public static function products() {
		$cached = get_transient( self::LIST_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$reply    = self::ask( 'addon_list', null, 8 );
		$products = array();
		foreach ( (array) ( $reply['products'] ?? array() ) as $p ) {
			$slug = sanitize_key( (string) ( $p['slug'] ?? '' ) );
			$page = esc_url_raw( (string) ( $p['page_url'] ?? '' ), array( 'https' ) );
			if ( '' === $slug || empty( $p['name'] ) ) {
				continue;
			}
			$products[] = array(
				'slug'     => $slug,
				'name'     => sanitize_text_field( (string) $p['name'] ),
				'line'     => sanitize_text_field( (string) ( $p['line'] ?? '' ) ),
				'price'    => sanitize_text_field( (string) ( $p['price'] ?? '' ) ),
				'page_url' => $page,
				'version'  => sanitize_text_field( (string) ( $p['version'] ?? '' ) ),
			);
		}
		$ok = ! empty( $reply['ok'] ) && $products;
		if ( ! $ok ) {
			$products = self::fallback();
		}
		// A missed answer is asked again within the hour.
		set_transient( self::LIST_CACHE, $products, $ok ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $products;
	}

	private static function product( $slug ) {
		foreach ( self::products() as $p ) {
			if ( $p['slug'] === $slug ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Add-ons live on single sites and, on a multisite network, on the main site only: plugins
	 * are shared by the whole network, so one place holds the keys and offers the updates.
	 */
	public static function here() {
		return ! is_multisite() || is_main_site();
	}

	/** One copy for the whole network (on a single site this is the site's own option). */
	private static function opt( $name ) {
		$v = get_site_option( $name, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Saved keys: { slug: { key, last, checked, error } }. */
	public static function keys() {
		return self::opt( self::OPTION );
	}

	private static function save_keys( array $keys ) {
		update_site_option( self::OPTION, $keys );
	}

	/** The installed add-on: { file, version, active }, found by its folder (the slug), or null. */
	public static function installed( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( dirname( $file ) === $slug ) {
				return array( 'file' => $file, 'version' => (string) $data['Version'], 'active' => is_plugin_active( $file ) );
			}
		}
		return null;
	}

	/** Where "Open …" goes: the add-on's own settings, which the add-on can name with this filter. */
	private static function open_url( $slug ) {
		/**
		 * Where Settings › Add-ons' "Open" button goes for an installed add-on.
		 *
		 * @param string $url  Default: Settings › Add-ons. An add-on with its own Settings page
		 *                     points this at it (/settings/<its id>).
		 * @param string $slug The add-on's slug.
		 */
		return (string) apply_filters( 'membuild_community/addon_open_url', Portal::url() . 'settings/add-ons', $slug );
	}

	/** One add-on as the Settings screen shows it. */
	private static function view( array $p ) {
		$keys    = self::keys();
		$updates = self::opt( self::UPDATES );
		$saved   = $keys[ $p['slug'] ] ?? null;
		$update  = is_array( $updates ) ? ( $updates[ $p['slug'] ] ?? null ) : null;
		return $p + array(
			'installed' => self::installed( $p['slug'] ),
			'open_url'  => self::open_url( $p['slug'] ),
			'key'       => $saved ? array(
				'key'     => (string) $saved['key'],
				'last'    => $saved['last'] ?? null,
				'error'   => $saved['error'] ?? null,
				'checked' => (int) ( $saved['checked'] ?? 0 ),
			) : null,
			'update'    => $update ? array(
				'version'   => (string) ( $update['version'] ?? '' ),
				'newer'     => ! empty( $update['newer'] ),
				'updates'   => ! empty( $update['updates'] ),
				'changelog' => (string) ( $update['changelog'] ?? '' ),
				'checked'   => (int) ( $update['checked'] ?? 0 ),
			) : null,
		);
	}

	/** Asks again about saved keys checked more than 12 hours ago, so dates and status stay current. */
	private static function refresh_stale() {
		foreach ( self::keys() as $slug => $saved ) {
			if ( time() - (int) ( $saved['checked'] ?? 0 ) > 12 * HOUR_IN_SECONDS && ! empty( $saved['key'] ) ) {
				self::check( $slug, (string) $saved['key'] );
			}
		}
	}

	public static function rest_list() {
		self::refresh_stale();
		return array(
			'site'     => self::normalize( home_url() ),
			'products' => array_map( array( __CLASS__, 'view' ), self::products() ),
		);
	}

	/* ---------- Keys ---------- */

	/** A key the way it's typed: uppercase, spaces gone. */
	public static function clean_key( $key ) {
		return strtoupper( preg_replace( '/\s+/', '', (string) $key ) );
	}

	/**
	 * Checks a key with the server. A good key is saved with the reply; a saved key that
	 * the server now turns down keeps the reply as its error, so the screen can say why.
	 */
	private static function check( $slug, $key, array $extra = array() ) {
		$reply = self::ask( 'addon_check', self::about_site( $slug, $key ) + $extra );
		$keys  = self::keys();
		if ( null === $reply ) {
			return self::no_answer();
		}
		if ( ! empty( $reply['ok'] ) ) {
			$keys[ $slug ] = array( 'key' => $key, 'last' => self::clean_reply( $reply ), 'checked' => time(), 'error' => null );
			self::save_keys( $keys );
		} elseif ( isset( $keys[ $slug ] ) && $keys[ $slug ]['key'] === $key && 'rate_limited' !== ( $reply['code'] ?? '' ) ) {
			$keys[ $slug ]['error']   = self::clean_reply( $reply );
			$keys[ $slug ]['checked'] = time();
			self::save_keys( $keys );
		}
		return self::clean_reply( $reply );
	}

	/** Only the fields the contract names, as plain text. */
	private static function clean_reply( array $r ) {
		$out = array( 'ok' => ! empty( $r['ok'] ) );
		foreach ( array( 'code', 'message', 'slug', 'name', 'role', 'site', 'live_site', 'test_site', 'status', 'paid_until', 'next_move', 'test_added' ) as $f ) {
			if ( isset( $r[ $f ] ) && is_scalar( $r[ $f ] ) ) {
				$out[ $f ] = sanitize_text_field( (string) $r[ $f ] );
			}
		}
		if ( isset( $r['updates'] ) ) {
			$out['updates'] = (bool) $r['updates'];
		}
		if ( isset( $r['moves_left'] ) ) {
			$out['moves_left'] = (int) $r['moves_left'];
		}
		return $out;
	}

	private static function slug_from( \WP_REST_Request $req ) {
		$slug = sanitize_key( (string) ( $req->get_json_params()['slug'] ?? $req['slug'] ?? '' ) );
		return '' !== $slug && self::product( $slug ) ? $slug : '';
	}

	private static function unknown() {
		return new \WP_Error( 'mbcom_addon_unknown', __( 'That add-on isn’t in the list.', 'membuild-community' ), array( 'status' => 404 ) );
	}

	public static function rest_check( \WP_REST_Request $req ) {
		$slug = self::slug_from( $req );
		if ( ! $slug ) {
			return self::unknown();
		}
		$in  = $req->get_json_params() ?: array();
		$key = self::clean_key( $in['key'] ?? '' );
		if ( ! preg_match( '/^MBC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key ) ) {
			return array(
				'ok'      => false,
				'code'    => 'not_found',
				'message' => __( 'We couldn’t find that key.', 'membuild-community' ),
				'product' => self::view( self::product( $slug ) ),
			);
		}
		$extra = array();
		if ( ! empty( $in['move'] ) ) {
			$extra['move'] = true;
		}
		if ( ! empty( $in['use_here'] ) ) {
			$extra['use_here'] = true;
		}
		$reply = self::check( $slug, $key, $extra );
		return $reply + array( 'product' => self::view( self::product( $slug ) ) );
	}

	public static function rest_remove( \WP_REST_Request $req ) {
		$slug  = self::slug_from( $req );
		$keys  = self::keys();
		$which = (string) ( $req->get_json_params()['which'] ?? '' );
		if ( ! $slug || empty( $keys[ $slug ] ) || ! in_array( $which, array( 'this', 'test' ), true ) ) {
			return self::unknown();
		}
		$key   = (string) $keys[ $slug ]['key'];
		$reply = self::ask( 'addon_remove', array( 'key' => $key, 'slug' => $slug, 'site_url' => home_url(), 'which' => $which ) );
		if ( null === $reply ) {
			return self::no_answer();
		}
		if ( empty( $reply['ok'] ) ) {
			return self::clean_reply( $reply );
		}
		if ( 'this' === $which ) {
			unset( $keys[ $slug ] );
			self::save_keys( $keys );
		} else {
			self::check( $slug, $key );
		}
		return array( 'ok' => true, 'product' => self::view( self::product( $slug ) ) );
	}

	/* ---------- Installing ---------- */

	/** A download from the server for a saved key: { ok, version, url, sha256, signature } or an error reply. */
	private static function download_link( $slug ) {
		$keys = self::keys();
		if ( empty( $keys[ $slug ]['key'] ) ) {
			return array( 'ok' => false, 'code' => 'not_found', 'message' => __( 'Paste your key first.', 'membuild-community' ) );
		}
		$reply = self::ask( 'addon_download', self::about_site( $slug, (string) $keys[ $slug ]['key'] ) );
		if ( null === $reply ) {
			return self::no_answer();
		}
		if ( empty( $reply['ok'] ) ) {
			return self::clean_reply( $reply );
		}
		$url = self::zip_url( (string) ( $reply['url'] ?? '' ) );
		if ( '' === $url ) {
			return array( 'ok' => false, 'code' => 'bad_reply', 'message' => __( 'MemBuild sent a link we can’t use. Please try again in a few minutes.', 'membuild-community' ) );
		}
		return array(
			'ok'        => true,
			'version'   => (string) ( $reply['version'] ?? '' ),
			'url'       => $url,
			'sha256'    => strtolower( (string) ( $reply['sha256'] ?? '' ) ),
			'signature' => $reply['signature'] ?? null,
		);
	}

	/** A zip link from the server, made whole when it's relative ("api.php?action=addon_zip&t=…"); only links to the same server. */
	private static function zip_url( $url ) {
		$server = self::server();
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = substr( $server, 0, strrpos( $server, '/' ) + 1 ) . ltrim( $url, '/' );
		}
		return wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( $server, PHP_URL_HOST ) ? esc_url_raw( $url ) : '';
	}

	/** Whether WordPress can put plugins on this site without asking for FTP details. */
	public static function can_write_plugins() {
		if ( ! wp_is_file_mod_allowed( 'mbcom_addon_install' ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		ob_start();
		$creds = request_filesystem_credentials( '', '', false, WP_PLUGIN_DIR, null, true );
		ob_end_clean();
		/**
		 * Whether this site can install add-ons by itself. The tests use it to act like a host that can't.
		 *
		 * @param bool $can True when WordPress can write the plugins folder without asking for details.
		 */
		return (bool) apply_filters( 'membuild_community/addon_can_install', false !== $creds && WP_Filesystem( $creds, WP_PLUGIN_DIR, true ) );
	}

	/**
	 * Is this downloaded zip exactly the add-on MemBuild signed? '' when it is, otherwise a plain why not.
	 *
	 * @param string $file      the downloaded zip.
	 * @param string $slug      the add-on it should be.
	 * @param string $expected  the version the server says it is ('' when not known).
	 * @param string $sha256    the sha256 the server sent with it ('' when none).
	 * @param mixed  $sig       the signature: { v: 1, key, slug, version, sha256, sig }.
	 * @param string $installed the version installed now ('' for a first install).
	 */
	public static function problem( $file, $slug, $expected, $sha256, $sig, $installed = '' ) {
		if ( ! is_array( $sig ) || 1 !== (int) ( $sig['v'] ?? 0 ) ) {
			return __( 'It isn’t signed by MemBuild.', 'membuild-community' );
		}
		foreach ( array( 'key', 'slug', 'version', 'sha256', 'sig' ) as $f ) {
			if ( empty( $sig[ $f ] ) || ! is_string( $sig[ $f ] ) ) {
				return __( 'It isn’t signed by MemBuild.', 'membuild-community' );
			}
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return __( 'This site can’t check signatures.', 'membuild-community' );
		}
		$keys = null === self::$keys ? self::KEYS : self::$keys;
		$pub  = $keys[ $sig['key'] ] ?? null;
		if ( null === $pub ) {
			return __( 'It’s signed with a key MemBuild doesn’t use.', 'membuild-community' );
		}
		if ( $sig['slug'] !== $slug ) {
			return __( 'The signature is for a different add-on.', 'membuild-community' );
		}
		if ( '' !== $expected && $sig['version'] !== $expected ) {
			return __( 'The signature is for a different version.', 'membuild-community' );
		}
		$sha = is_file( $file ) ? hash_file( 'sha256', $file ) : '';
		if ( '' === $sha || ! hash_equals( strtolower( $sig['sha256'] ), $sha ) || ( '' !== $sha256 && ! hash_equals( strtolower( $sha256 ), $sha ) ) ) {
			return __( 'The download isn’t the file MemBuild signed.', 'membuild-community' );
		}
		$raw = base64_decode( $sig['sig'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the signature is sent as base64.
		$key = base64_decode( $pub, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- so is the public key.
		if ( false === $raw || false === $key || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $raw ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return __( 'The signature is damaged.', 'membuild-community' );
		}
		$message = "membuild-release/v1\n{$slug}\n{$sig['version']}\n{$sha}\n";
		try {
			$good = sodium_crypto_sign_verify_detached( $raw, $message, $key );
		} catch ( \Throwable $e ) {
			$good = false;
		}
		if ( ! $good ) {
			return __( 'The signature doesn’t match.', 'membuild-community' );
		}
		// A genuine older release, sent again, must not replace a newer one.
		if ( '' !== $installed && version_compare( $sig['version'], $installed, '<' ) ) {
			return __( 'It’s older than the version on your site.', 'membuild-community' );
		}
		return '';
	}

	/** Downloads a link from download_link() and checks it: the local file, or a WP_Error with a plain message. */
	private static function fetch_checked( $slug, array $link, $installed = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$file = download_url( $link['url'], 300 );
		if ( is_wp_error( $file ) ) {
			return new \WP_Error( 'download_failed', __( 'The download didn’t finish. Nothing changed on your site. Please try again.', 'membuild-community' ) );
		}
		$bad = self::problem( $file, $slug, $link['version'], $link['sha256'], $link['signature'], $installed );
		if ( '' !== $bad ) {
			wp_delete_file( $file );
			/* translators: %s: why the check failed, e.g. "The signature doesn't match." */
			return new \WP_Error( 'signature_bad', sprintf( __( 'It wasn’t installed, because it didn’t pass the safety check. %s Nothing changed on your site.', 'membuild-community' ), $bad ) );
		}
		return $file;
	}

	public static function rest_install( \WP_REST_Request $req ) {
		$slug = self::slug_from( $req );
		if ( ! $slug ) {
			return self::unknown();
		}
		ignore_user_abort( true ); // "You can leave this page. It finishes on its own."
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- some hosts forbid it; the install still tries.
		}
		$link = self::download_link( $slug );
		if ( empty( $link['ok'] ) ) {
			return $link;
		}
		if ( ! self::can_write_plugins() ) {
			return array(
				'ok'      => false,
				'code'    => 'host_blocked',
				'url'     => $link['url'],
				'message' => __( 'Your host doesn’t let WordPress install plugins on its own', 'membuild-community' ),
			);
		}
		$before = self::installed( $slug );
		$file   = self::fetch_checked( $slug, $link, $before['version'] ?? '' );
		if ( is_wp_error( $file ) ) {
			return array( 'ok' => false, 'code' => $file->get_error_code(), 'message' => $file->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $file, array( 'overwrite_package' => true ) );
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
		$now = self::installed( $slug );
		if ( true !== $result || ! $now ) {
			return array( 'ok' => false, 'code' => 'install_failed', 'message' => __( 'WordPress couldn’t put it on your site. Nothing else changed. Please try again, or install it by hand.', 'membuild-community' ) );
		}
		if ( ! $now['active'] ) {
			$activated = activate_plugin( $now['file'] );
			if ( is_wp_error( $activated ) ) {
				/* translators: %s: WordPress's own reason */
				return array( 'ok' => false, 'code' => 'activate_failed', 'message' => sprintf( __( 'It’s on your site, but WordPress couldn’t switch it on: %s', 'membuild-community' ), wp_strip_all_tags( $activated->get_error_message() ) ) );
			}
		}
		self::remember_update( $slug, array( 'ok' => true, 'version' => $link['version'], 'newer' => false, 'updates' => true ) );
		return array( 'ok' => true, 'product' => self::view( self::product( $slug ) ) );
	}

	public static function rest_download( \WP_REST_Request $req ) {
		$slug = self::slug_from( $req );
		if ( ! $slug ) {
			return self::unknown();
		}
		$link = self::download_link( $slug );
		return empty( $link['ok'] ) ? $link : array( 'ok' => true, 'url' => $link['url'], 'version' => $link['version'] );
	}

	/* ---------- Updates through WordPress ---------- */

	private static function remember_update( $slug, array $reply ) {
		$all = self::opt( self::UPDATES );
		$all = is_array( $all ) ? $all : array();
		$all[ $slug ] = array(
			'version'      => sanitize_text_field( (string) ( $reply['version'] ?? '' ) ),
			'newer'        => ! empty( $reply['newer'] ),
			'updates'      => ! empty( $reply['updates'] ),
			'changelog'    => sanitize_textarea_field( (string) ( $reply['changelog'] ?? '' ) ),
			'requires'     => sanitize_text_field( (string) ( $reply['requires'] ?? '' ) ),
			'requires_php' => sanitize_text_field( (string) ( $reply['requires_php'] ?? '' ) ),
			'tested'       => sanitize_text_field( (string) ( $reply['tested'] ?? '' ) ),
			'url'          => ! empty( $reply['url'] ) ? self::zip_url( (string) $reply['url'] ) : '',
			'checked'      => time(),
		);
		update_site_option( self::UPDATES, $all );
	}

	/** Once a day: asks about each installed add-on that has a key, and lets WordPress know. */
	public static function check_updates() {
		if ( ! self::here() ) {
			return;
		}
		$keys = self::keys();
		foreach ( $keys as $slug => $saved ) {
			$now = self::installed( $slug );
			if ( ! $now || empty( $saved['key'] ) ) {
				continue;
			}
			$reply = self::ask( 'addon_update', self::about_site( $slug, (string) $saved['key'] ) + array( 'version' => $now['version'] ) );
			if ( null === $reply || empty( $reply['ok'] ) ) {
				continue;
			}
			self::remember_update( $slug, $reply );
			// The reply carries the key's dates too.
			if ( isset( $reply['paid_until'] ) || isset( $reply['status'] ) ) {
				$keys = self::keys();
				$last = (array) ( $keys[ $slug ]['last'] ?? array() );
				foreach ( array( 'paid_until', 'status' ) as $f ) {
					if ( isset( $reply[ $f ] ) ) {
						$last[ $f ] = sanitize_text_field( (string) $reply[ $f ] );
					}
				}
				$last['updates']        = ! empty( $reply['updates'] );
				$keys[ $slug ]['last'] = $last;
				self::save_keys( $keys );
			}
		}
		delete_site_transient( 'update_plugins' ); // WordPress asks again, and offer() adds what's new
	}

	/** Adds a newer add-on to WordPress's update list, only while its key gets updates. */
	public static function offer( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$updates = self::opt( self::UPDATES );
		foreach ( array_keys( self::keys() ) as $slug ) {
			$now = self::installed( $slug );
			if ( ! $now ) {
				continue;
			}
			$u    = is_array( $updates ) ? ( $updates[ $slug ] ?? null ) : null;
			$item = (object) array(
				'id'           => 'tools.membuild.com/' . $slug,
				'slug'         => $slug,
				'plugin'       => $now['file'],
				'new_version'  => $u['version'] ?? $now['version'],
				'url'          => 'https://membuild.com/add-ons/' . $slug,
				'package'      => '',
				'requires'     => $u['requires'] ?? '',
				'requires_php' => $u['requires_php'] ?? '',
				'tested'       => $u['tested'] ?? '',
			);
			// Never what wordpress.org says about a plugin with the same folder name.
			unset( $transient->response[ $now['file'] ], $transient->no_update[ $now['file'] ] );
			if ( $u && $u['newer'] && $u['updates'] && $u['url'] && version_compare( $u['version'], $now['version'], '>' ) ) {
				// A marker, not a link: only pre_download() turns it into a checked file, so if that
				// check is ever skipped WordPress has nothing to download instead of an unchecked zip.
				$item->package                         = self::PACKAGE . $slug;
				$transient->response[ $now['file'] ] = $item;
			} else {
				$transient->no_update[ $now['file'] ] = $item;
			}
		}
		return $transient;
	}

	/** The add-on an update package marker (mbcom-addon:<slug>) belongs to, or ''. */
	private static function slug_for_package( $package ) {
		if ( ! preg_match( '/^' . preg_quote( self::PACKAGE, '/' ) . '([a-z0-9-]{1,60})$/', $package, $m ) ) {
			return '';
		}
		return isset( self::keys()[ $m[1] ] ) ? $m[1] : '';
	}

	/**
	 * Before WordPress downloads an add-on's update: a fresh 1-hour link (the daily one may be
	 * old), downloaded and checked here, so WordPress unpacks only the file that passed.
	 */
	public static function pre_download( $reply, $package, $upgrader = null ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$slug = self::slug_for_package( $package );
		if ( '' === $slug ) {
			return $reply;
		}
		$now  = self::installed( $slug );
		$link = self::download_link( $slug );
		if ( empty( $link['ok'] ) ) {
			return new \WP_Error( 'mbcom_addon_update', ( 'ended' === ( $link['code'] ?? '' ) ? __( 'Updates have ended for this key. Renew to get this version.', 'membuild-community' ) : (string) ( $link['message'] ?? '' ) ) );
		}
		$file = self::fetch_checked( $slug, $link, $now['version'] ?? '' );
		return is_wp_error( $file ) ? new \WP_Error( 'mbcom_addon_update', $file->get_error_message() ) : $file;
	}

	/** The "View details" window on the Plugins screen. */
	public static function details( $result, $action, $args ) {
		$slug = (string) ( $args->slug ?? '' );
		if ( 'plugin_information' !== $action || '' === $slug || ! isset( self::keys()[ $slug ] ) ) {
			return $result;
		}
		$p   = self::product( $slug );
		$u   = self::opt( self::UPDATES )[ $slug ] ?? array();
		$now = self::installed( $slug );
		return (object) array(
			'name'         => $p['name'] ?? $slug,
			'slug'         => $slug,
			'version'      => $u['version'] ?? ( $now['version'] ?? '' ),
			'author'       => '<a href="https://membuild.com/">MemBuild</a>',
			'homepage'     => $p['page_url'] ?? 'https://membuild.com/',
			'requires'     => $u['requires'] ?? '',
			'requires_php' => $u['requires_php'] ?? '',
			'tested'       => $u['tested'] ?? '',
			'sections'     => array( 'changelog' => wpautop( esc_html( $u['changelog'] ?? '' ) ) ),
		);
	}
}
