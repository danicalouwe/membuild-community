<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The installable app (PWA): a web app manifest, a small service worker and
 * home-screen icons, all served from inside the community's own address
 * (/community/manifest.webmanifest, /community/sw.js) so the app's scope is
 * just the community.
 *
 * Icons come from the uploaded logo or, without one, from a square the app
 * draws with the community's initials and color (admins upload it from the
 * browser, which has fonts; the server may not). Resized copies are kept in
 * uploads/mbcom-icons/.
 *
 * The service worker caches only the app's own files. Posts, messages and
 * anything else from the API always come from the network.
 *
 *   PUT /settings/app-icon   (admin) { media_id, signature }
 */
class App {

	const SIZES = array( 32, 180, 192, 512 ); // browser tab, iPhone home screen, Android and install screens

	/** Called by Portal before the login check: these files are public and hold no member data. */
	public static function maybe_serve( $path ) {
		switch ( $path ) {
			case 'manifest.webmanifest':
				self::manifest();
				exit;
			case 'sw.js':
				self::service_worker();
				exit;
		}
	}

	/* ---------- Icons ---------- */

	/**
	 * The image every icon is made from (browser tab, home screen, login page):
	 * the community's logo; without one, the site's own WordPress Site Icon;
	 * without that, the square the app draws from the initials and color.
	 */
	private static function source() {
		$logo = Settings::get( 'logo' );
		if ( $logo ) {
			return $logo;
		}
		$site_icon = (int) get_option( 'site_icon' );
		if ( $site_icon && wp_get_attachment_url( $site_icon ) ) {
			return array( 'id' => 0, 'url' => wp_get_attachment_url( $site_icon ) );
		}
		$icon = Settings::get( 'app_icon' );
		if ( $icon && ( $icon['signature'] ?? '' ) === self::signature() ) {
			return $icon;
		}
		return null;
	}

	/** Whether the app should draw an initials icon: only when there's no logo or Site Icon to use. */
	public static function needs_drawn_icon() {
		return ! Settings::get( 'logo' ) && ! get_option( 'site_icon' ) && ( Settings::get( 'app_icon' )['signature'] ?? '' ) !== self::signature();
	}

	/** What a generated icon depends on; when it changes, the app draws a new one. */
	public static function signature() {
		return md5( Settings::initials() . '|' . ( Settings::get( 'accent' ) ?: '#3b5bfd' ) );
	}

	/** Square PNG icons by size, made on first use. Empty when there's no source image yet. */
	public static function icons() {
		$src = self::source();
		if ( ! $src ) {
			return array();
		}
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'mbcom-icons/';
		$url     = trailingslashit( $uploads['baseurl'] ) . 'mbcom-icons/';
		$key     = substr( md5( $src['url'] ), 0, 10 );
		$out     = array();
		foreach ( self::SIZES as $size ) {
			$file = "icon-{$key}-{$size}.png";
			if ( ! file_exists( $dir . $file ) ) {
				$path = self::local_path( $src['url'] );
				if ( ! $path ) {
					return array();
				}
				wp_mkdir_p( $dir );
				$editor = wp_get_image_editor( $path );
				if ( is_wp_error( $editor ) ) {
					return array();
				}
				$editor->resize( $size, $size, true ); // crop to a square
				$saved = $editor->save( $dir . $file, 'image/png' );
				if ( is_wp_error( $saved ) ) {
					return array();
				}
			}
			$out[ $size ] = $url . $file;
		}
		return $out;
	}

	private static function local_path( $url ) {
		$uploads = wp_upload_dir();
		if ( ! str_starts_with( $url, $uploads['baseurl'] ) ) {
			return null;
		}
		$path = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		return file_exists( $path ) ? $path : null;
	}

	/** A vector fallback so the manifest always has an icon, even before the PNG exists. */
	private static function svg_icon() {
		$accent = Color::valid( Settings::get( 'accent' ) ) ? Settings::get( 'accent' ) : '#3b5bfd';
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="112" fill="' . esc_attr( $accent ) . '"/>'
			. '<text x="256" y="256" dy=".35em" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="210" font-weight="800" fill="' . esc_attr( Color::ink( $accent ) ) . '">' . esc_html( Settings::initials() ) . '</text></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function register_routes() {
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/settings/app-icon',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'save_icon' ),
				'permission_callback' => fn() => is_user_logged_in() && Helpers::can_manage(),
			)
		);
	}

	/** Stores the icon the app drew (initials on the accent color). */
	public static function save_icon( \WP_REST_Request $req ) {
		global $wpdb;
		$media = Install::table( 'media' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, url FROM %i WHERE id = %d AND user_id = %d AND kind = 'image' AND post_id IS NULL", $media, (int) $req['media_id'], get_current_user_id() ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_bad_media', __( 'Upload the icon again.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->update( $media, array( 'post_id' => 0 ), array( 'id' => $row['id'] ) );
		$s             = get_option( Settings::OPTION, array() );
		$old           = (int) ( $s['app_icon']['id'] ?? 0 );
		$s['app_icon'] = array( 'id' => (int) $row['id'], 'url' => $row['url'], 'signature' => self::signature() );
		update_option( Settings::OPTION, $s, true );
		Settings::flush();
		if ( $old && $old !== (int) $row['id'] ) {
			Media::delete( $old ); // the icon it replaces
		}
		return array( 'icons' => self::icons() );
	}

	/* ---------- Manifest ---------- */

	private static function manifest() {
		$base   = trailingslashit( wp_parse_url( home_url( '/' . Settings::slug() ), PHP_URL_PATH ) );
		$name   = Settings::name();
		$accent = Settings::get( 'accent' ) ?: '#3b5bfd';
		$icons  = array();
		foreach ( self::icons() as $size => $url ) {
			if ( 180 === $size ) {
				continue; // that one is the iPhone home-screen icon, linked from the page
			}
			$icons[] = array( 'src' => $url, 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any' );
		}
		if ( ! $icons ) {
			$icons[] = array( 'src' => self::svg_icon(), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any' );
		}
		$short = mb_strlen( $name ) > 12 ? Settings::initials() : $name;

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		echo wp_json_encode(
			array(
				'id'               => $base,
				'name'             => $name,
				'short_name'       => $short,
				'description'      => Settings::get( 'description' ) ?: $name,
				'start_url'        => $base,
				'scope'            => $base,
				'display'          => 'standalone',
				'orientation'      => 'portrait',
				'background_color' => '#111214',
				'theme_color'      => $accent,
				'icons'            => $icons,
				'shortcuts'        => array(
					array( 'name' => 'Notifications', 'url' => $base . 'notifications' ),
					array( 'name' => 'Messages', 'url' => $base . 'messages' ),
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/* ---------- Service worker ---------- */

	private static function service_worker() {
		$base    = trailingslashit( wp_parse_url( home_url( '/' . Settings::slug() ), PHP_URL_PATH ) );
		$assets  = wp_parse_url( MEMBUILD_COMMUNITY_URL . 'assets/build/', PHP_URL_PATH );
		$version = MEMBUILD_COMMUNITY_VERSION . '-' . substr( md5( (string) @filemtime( MEMBUILD_COMMUNITY_DIR . 'assets/build/.vite/manifest.json' ) ), 0, 8 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$offline = esc_js( Settings::name() );

		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . $base );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- values are JSON-encoded below.
		echo "/* Community app worker. It caches only the app's own files; posts, messages and uploads always come from the network. */\n";
		echo 'const VERSION = ' . wp_json_encode( $version ) . ";\n";
		echo 'const ASSETS = ' . wp_json_encode( $assets ) . ";\n";
		echo 'const SCOPE = ' . wp_json_encode( $base ) . ";\n";
		echo 'const NAME = ' . wp_json_encode( Settings::name() ) . ";\n";
		// phpcs:enable
		?>
const CACHE = `mbcom-${VERSION}`

self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('mbcom-') && k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  )
})

const OFFLINE = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${NAME}</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111214;color:#eceef1;font:16px system-ui,sans-serif;text-align:center;padding:24px}button{margin-top:16px;padding:10px 18px;border:0;border-radius:10px;font:inherit;font-weight:600}</style>
<div><h1 style="font-size:22px">You're offline</h1><p>Connect to the internet to see what's new in ${NAME}.</p><button onclick="location.reload()">Try again</button></div>`

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return
  const url = new URL(req.url)
  if (url.origin !== location.origin) return

  // Opening the app: always the live page (it carries the sign-in), with a friendly offline screen.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })))
    return
  }

  // The app's built files have hashed names, so a cached copy is always right.
  if (url.pathname.startsWith(ASSETS)) {
    event.respondWith(
      caches.open(CACHE).then(async (cache) => {
        const hit = await cache.match(req)
        if (hit) return hit
        const res = await fetch(req)
        if (res.ok) cache.put(req, res.clone())
        return res
      }),
    )
  }
  // Everything else (the API, photos, videos) goes straight to the network.
})
<?php
	}
}
