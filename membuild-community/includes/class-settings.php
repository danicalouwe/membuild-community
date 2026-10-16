<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Community settings, edited by admins at /community/settings.
 *
 * Everything lives in one option. Missing keys fall back to DEFAULTS, so
 * new settings never need a migration.
 *
 *   GET  /settings          the settings, plus labels the form needs
 *   PUT  /settings          save (partial updates are fine)
 *   GET  /settings/people   ?search= find members to make moderators
 */
class Settings {

	const OPTION = 'mbcom_settings';

	const FEATURES = array( 'reels', 'stories', 'classroom', 'leaderboard', 'messages', 'search', 'places' );

	/** Accent presets offered in the form; any hex color is accepted. */
	const ACCENTS = array( '#3b5bfd', '#7c4dff', '#e0407b', '#f05a28', '#f2a20c', '#1fa463', '#0e9aa7', '#16181d' );

	/** What earns points, as shown in Settings, translated. */
	public static function rule_labels() {
		return array(
			'reaction_received' => __( 'Someone reacts to your post or comment', 'membuild-community' ),
			'post'              => __( 'Write a post', 'membuild-community' ),
			'comment'           => __( 'Write a comment', 'membuild-community' ),
			'story'             => __( 'Share a story', 'membuild-community' ),
			'lesson'            => __( 'Complete a lesson', 'membuild-community' ),
			'course'            => __( 'Finish a course', 'membuild-community' ),
			'daily_visit'       => __( 'Visit the community (once a day)', 'membuild-community' ),
			'streak_7'          => __( '7-day streak', 'membuild-community' ),
			'streak_30'         => __( '30-day streak', 'membuild-community' ),
		);
	}

	public static function defaults() {
		return array(
			'name'        => '',
			'description' => '',
			'logo'        => null, // array( 'id' => media id, 'url' => ... )
			'accent'      => '',
			'slug'        => 'community',
			'landing'     => 'members', // members | everyone | off — see Portal::maybe_land()
			'login_page'  => true, // the community's own login pages; see Login
			'signup_url'  => '',   // when WordPress registration is off: where "Create new account" goes (e.g. a membership plugin's checkout)
			'min_age'     => 13,   // 0, 13, 16 or 18; asked at sign-up, never stored
			'terms_url'   => '',
			'features'    => array( 'places' => false ) + array_fill_keys( self::FEATURES, true ), // Places is the one most communities don't need
			'posting'     => 'everyone', // everyone | staff
			'messaging'   => 'everyone', // everyone | staff (members may only start chats with staff)
			'moderators'  => array(),
			'points'      => array_map( fn( $r ) => array( 'points' => $r[0], 'cap' => $r[1] ), Points::RULES ),
			'level_names' => array_fill( 0, 9, '' ),
			'email'       => array(), // see Email::defaults()
			'video'       => array(), // see Bunny::defaults()
			'gifs'        => array( 'key' => '', 'rating' => 'pg' ), // GIPHY; no key, no GIF button (see Giphy)
			'delete_data' => false, // uninstall.php removes everything only when this is on
		);
	}

	private static $cache = null;

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			$saved       = is_array( $saved ) ? $saved : array();
			$defaults    = self::defaults();
			self::$cache = array_merge( $defaults, $saved );
			// Nested maps merge key by key, so a new feature or rule gets its default.
			foreach ( array( 'features', 'points' ) as $key ) {
				self::$cache[ $key ] = array_merge( $defaults[ $key ], is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : array() );
			}
		}
		return self::$cache;
	}

	/** Forget the cached settings, after something else saved the option. */
	public static function flush() {
		self::$cache = null;
	}

	public static function get( $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function feature( $key ) {
		return ! empty( self::all()['features'][ $key ] );
	}

	public static function name() {
		return self::get( 'name' ) ?: get_bloginfo( 'name' );
	}

	/** "MemBuild - Community" → "MB" (capitals in the first word), "Growth Circle" → "GC". Matches the app header. */
	public static function initials() {
		$words = preg_split( '/[\s\-–—|·]+/u', self::name(), -1, PREG_SPLIT_NO_EMPTY );
		preg_match_all( '/[A-Z]/', $words[0] ?? '', $caps );
		if ( count( $caps[0] ) >= 2 ) {
			return implode( '', array_slice( $caps[0], 0, 2 ) );
		}
		return mb_strtoupper( implode( '', array_map( fn( $w ) => mb_substr( $w, 0, 1 ), array_slice( $words, 0, 2 ) ) ) ) ?: '•';
	}

	public static function slug() {
		return self::get( 'slug' ) ?: 'community';
	}

	/** Admins, WordPress editors (moderate_comments) and members picked in settings. */
	public static function is_staff( $user_id ) {
		return user_can( $user_id, 'moderate_comments' ) || in_array( (int) $user_id, array_map( 'intval', self::get( 'moderators' ) ), true );
	}

	public static function can_post( $user_id = null ) {
		return 'everyone' === self::get( 'posting' ) || self::is_staff( $user_id ?? get_current_user_id() );
	}

	/** May $user_id start a chat with all of $others? */
	public static function can_message( $user_id, array $others ) {
		if ( ! self::feature( 'messages' ) ) {
			return false;
		}
		if ( 'everyone' === self::get( 'messaging' ) || self::is_staff( $user_id ) ) {
			return true;
		}
		foreach ( $others as $other ) {
			if ( ! self::is_staff( $other ) ) {
				return false;
			}
		}
		return true;
	}

	/** Points rules in the shape Points expects: key => array( points, daily cap ). */
	public static function point_rules( $rules ) {
		foreach ( self::get( 'points' ) as $key => $rule ) {
			if ( isset( $rules[ $key ] ) ) {
				$rules[ $key ] = array( (int) $rule['points'], (int) $rule['cap'] );
			}
		}
		return $rules;
	}

	/** What the app needs on every page load (no admin-only details). */
	public static function public_config() {
		$s = self::all();
		return array(
			'name'        => self::name(),
			'description' => $s['description'],
			'logo'        => $s['logo']['url'] ?? null,
			'accent'      => $s['accent'],
			'features'    => $s['features'],
			'canPost'     => self::can_post(),
			'messaging'   => $s['messaging'],
			'isStaff'     => self::is_staff( get_current_user_id() ),
			'reportReasons' => Moderation::reasons(),
			'canManage'   => Helpers::can_manage(),
			'levelNames'  => $s['level_names'],
			'restriction' => Moderation::my_restriction(),
			'video'       => Bunny::enabled() ? 'bunny' : 'local',
			'mapTiles'    => self::feature( 'places' ) ? Rest_Places::tiles() : null,
			'giphy'       => Giphy::config(), // a browser key: GIPHY's terms have members' browsers search them directly
		);
	}

	/* ---------- REST ---------- */

	public static function register_routes() {
		$admin = fn() => is_user_logged_in() && Helpers::can_manage();
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/settings',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get' ), 'permission_callback' => $admin ),
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => $admin ),
			)
		);
		// The encryption key: its status, a copy for safekeeping, and putting a lost key back.
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/settings/encryption',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_encryption' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_encryption_action' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/settings/encryption/key', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_encryption_key' ), 'permission_callback' => $admin ) );
	}

	/** Where the key is and whether it's working, without the key itself. */
	public static function rest_encryption() {
		$saved = (int) get_option( Vault::SAVED_OPTION );
		return array(
			'state'    => Vault::state(),
			'source'   => Vault::key_source(),
			'saved_at' => $saved ? gmdate( 'c', $saved ) : null,
			'file'     => 'wp-content/' . Vault::KEY_FILE,
		);
	}

	/** The key itself, only when an admin asks to copy or download it. */
	public static function rest_encryption_key() {
		$key = Vault::export();
		if ( ! $key ) {
			return Vault::paused();
		}
		$res = rest_ensure_response( array( 'key' => $key ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/** {action: "saved"} · {action: "restore", key} · {action: "start_over"} */
	public static function rest_encryption_action( \WP_REST_Request $req ) {
		switch ( (string) $req['action'] ) {
			case 'saved':
				update_option( Vault::SAVED_OPTION, time(), false );
				break;
			case 'restore':
				$done = Vault::restore( (string) $req['key'] );
				break;
			case 'start_over':
				$done = Vault::start_over();
				break;
			default:
				return new \WP_Error( 'mbcom_bad_action', __( 'Unknown action.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		return isset( $done ) && is_wp_error( $done ) ? $done : self::rest_encryption();
	}

	public static function rest_get() {
		$s               = self::all();
		$s['moderators'] = array_values( Helpers::users( array_map( 'intval', $s['moderators'] ) ) );
		$s['email']      = Email::public_settings();
		// "Let people create an account" is WordPress's own registration setting, edited here, never copied.
		$s['registration'] = Login::registration_open();
		$s['video']      = Bunny::public_settings();
		return array(
			'settings'   => $s,
			'smtp_plugin' => Email::smtp_plugin(),
			'admin_email' => wp_get_current_user()->user_email,
			'site_name'  => get_bloginfo( 'name' ),
			'home_url'   => home_url( '/' ),
			'registration_locked' => is_multisite(), // on a network only the network admin can change registration
			'privacy_url'         => get_privacy_policy_url(),
			'privacy_admin_url'   => admin_url( 'options-privacy.php' ),
			'join_url'            => Login::url( 'join' ),
			'accents'    => self::ACCENTS,
			'rules'      => self::rule_labels(),
			'thresholds' => Points::thresholds(),
			// WordPress editors and admins moderate already; shown so the form isn't misleading.
			'built_in_staff' => array_values( Helpers::users( get_users( array( 'capability' => 'moderate_comments', 'fields' => 'ID', 'number' => 50 ) ) ) ),
		);
	}

	public static function rest_save( \WP_REST_Request $req ) {
		$in    = $req->get_json_params() ?: array();
		$s     = self::all();
		$old   = $s['slug'];
		$error = fn( $msg ) => new \WP_Error( 'mbcom_invalid_setting', $msg, array( 'status' => 400 ) );

		if ( array_key_exists( 'name', $in ) ) {
			$s['name'] = mb_substr( sanitize_text_field( (string) $in['name'] ), 0, 60 );
		}
		if ( array_key_exists( 'description', $in ) ) {
			$s['description'] = Helpers::clean_text( $in['description'], 300 );
		}
		if ( array_key_exists( 'accent', $in ) ) {
			$accent = (string) $in['accent'];
			if ( '' !== $accent && ! Color::valid( $accent ) ) {
				return $error( __( 'Pick a color like #3b5bfd.', 'membuild-community' ) );
			}
			$s['accent'] = strtolower( $accent );
		}
		if ( array_key_exists( 'slug', $in ) ) {
			$slug = sanitize_title( (string) $in['slug'] );
			if ( ! $slug ) {
				return $error( __( 'The address needs at least one letter or number.', 'membuild-community' ) );
			}
			// Don't take over an address WordPress already uses.
			if ( $slug !== $old && ( in_array( $slug, array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'feed', 'page', 'author', 'category', 'tag', 'search', 'embed', 'comments' ), true ) || get_page_by_path( $slug ) ) ) {
				/* translators: %s: the address the admin typed, e.g. /community */
				return $error( sprintf( __( '%s is already used on this site. Try another address.', 'membuild-community' ), '/' . $slug ) );
			}
			$s['slug'] = $slug;
		}
		$old_logo = (int) ( $s['logo']['id'] ?? 0 );
		if ( array_key_exists( 'logo', $in ) ) {
			$s['logo'] = null === $in['logo'] ? null : self::claim_logo( (int) ( $in['logo']['id'] ?? 0 ), $s['logo'] );
			if ( is_wp_error( $s['logo'] ) ) {
				return $s['logo'];
			}
		}
		if ( isset( $in['features'] ) && is_array( $in['features'] ) ) {
			foreach ( self::FEATURES as $f ) {
				if ( array_key_exists( $f, $in['features'] ) ) {
					$s['features'][ $f ] = (bool) $in['features'][ $f ];
				}
			}
		}
		if ( array_key_exists( 'landing', $in ) ) {
			$s['landing'] = in_array( $in['landing'], array( 'members', 'everyone', 'off' ), true ) ? $in['landing'] : 'members';
		}
		foreach ( array( 'posting', 'messaging' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$s[ $key ] = 'staff' === $in[ $key ] ? 'staff' : 'everyone';
			}
		}
		if ( isset( $in['moderators'] ) && is_array( $in['moderators'] ) ) {
			$ids             = array_unique( array_filter( array_map( fn( $m ) => (int) ( is_array( $m ) ? ( $m['id'] ?? 0 ) : $m ), $in['moderators'] ) ) );
			$s['moderators'] = array_values( array_filter( $ids, fn( $id ) => (bool) get_userdata( $id ) ) );
		}
		if ( isset( $in['points'] ) && is_array( $in['points'] ) ) {
			foreach ( $in['points'] as $key => $rule ) {
				if ( isset( $s['points'][ $key ] ) && is_array( $rule ) ) {
					$s['points'][ $key ] = array(
						'points' => max( 0, min( 1000, (int) ( $rule['points'] ?? 0 ) ) ),
						'cap'    => max( 0, min( 100, (int) ( $rule['cap'] ?? 0 ) ) ),
					);
				}
			}
		}
		if ( isset( $in['level_names'] ) && is_array( $in['level_names'] ) ) {
			$names = array_map( fn( $n ) => mb_substr( sanitize_text_field( (string) $n ), 0, 30 ), array_values( $in['level_names'] ) );
			$s['level_names'] = array_pad( array_slice( $names, 0, 9 ), 9, '' );
		}

		if ( array_key_exists( 'registration', $in ) && ! is_multisite() ) {
			update_option( 'users_can_register', $in['registration'] ? 1 : 0 );
		}
		if ( array_key_exists( 'signup_url', $in ) ) {
			$s['signup_url'] = esc_url_raw( (string) $in['signup_url'], array( 'http', 'https' ) );
		}
		if ( array_key_exists( 'terms_url', $in ) ) {
			$s['terms_url'] = esc_url_raw( (string) $in['terms_url'], array( 'http', 'https' ) );
		}
		if ( array_key_exists( 'min_age', $in ) ) {
			$s['min_age'] = in_array( (int) $in['min_age'], array( 0, 13, 16, 18 ), true ) ? (int) $in['min_age'] : 13;
		}
		if ( array_key_exists( 'login_page', $in ) ) {
			$s['login_page'] = (bool) $in['login_page'];
		}
		if ( array_key_exists( 'delete_data', $in ) ) {
			$s['delete_data'] = (bool) $in['delete_data'];
		}
		if ( isset( $in['video'] ) && is_array( $in['video'] ) ) {
			$video = Bunny::sanitize( $in['video'], Bunny::settings() );
			if ( is_wp_error( $video ) ) {
				return $video;
			}
			$s['video'] = $video;
		}
		if ( isset( $in['email'] ) && is_array( $in['email'] ) ) {
			$email = Email::sanitize( $in['email'], Email::settings() );
			if ( is_wp_error( $email ) ) {
				return $email;
			}
			$s['email'] = $email;
		}
		if ( isset( $in['gifs'] ) ) {
			$gifs = Giphy::clean_settings( $in['gifs'], (array) $s['gifs'] );
			if ( is_wp_error( $gifs ) ) {
				return $gifs;
			}
			$s['gifs'] = $gifs;
		}

		update_option( self::OPTION, $s, true );
		self::$cache = null;
		// A replaced or removed logo is deleted, once the new settings are saved.
		if ( $old_logo && $old_logo !== (int) ( $s['logo']['id'] ?? 0 ) ) {
			Media::delete( $old_logo );
		}

		if ( $s['slug'] !== $old ) {
			Portal::add_rewrite_rule();
			flush_rewrite_rules( false );
		}

		$out           = self::rest_get();
		$out['config'] = self::public_config();
		$out['base_path'] = wp_parse_url( home_url( '/' . $s['slug'] ), PHP_URL_PATH );
		return $out;
	}

	/** The logo is an image the admin just uploaded through /media/uploads. */
	private static function claim_logo( $media_id, $current ) {
		global $wpdb;
		if ( $current && (int) $current['id'] === $media_id ) {
			return $current;
		}
		$table = Install::table( 'media' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, url FROM %i WHERE id = %d AND user_id = %d AND kind = 'image' AND post_id IS NULL", $table, $media_id, get_current_user_id() ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_invalid_setting', __( 'Upload the logo again, please.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->update( $table, array( 'post_id' => 0 ), array( 'id' => $row['id'] ) ); // claimed, like a post's photo
		return array( 'id' => (int) $row['id'], 'url' => $row['url'] );
	}

	/* ---------- wp-admin ---------- */

	/** Redirects for the menu's Settings / Open community entries, and a "Settings" link on the Plugins screen. */
	public static function register_admin() {
		// The Community menu itself is built by Admin::menu(); its Settings and Open entries go to the app.
		add_action(
			'admin_init',
			function () {
				$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- navigation only
				if ( in_array( $page, array( 'membuild-community-open', 'membuild-community-settings' ), true ) && current_user_can( 'manage_options' ) ) {
					wp_safe_redirect( home_url( '/' . self::slug() . ( 'membuild-community-settings' === $page ? '/settings' : '/' ) ) );
					exit;
				}
			}
		);
		add_filter(
			'plugin_action_links_' . plugin_basename( MEMBUILD_COMMUNITY_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( home_url( '/' . self::slug() . '/settings' ) ) . '">' . esc_html__( 'Settings', 'membuild-community' ) . '</a>' );
				return $links;
			}
		);
	}
}
