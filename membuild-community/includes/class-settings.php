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

	const FEATURES = array( 'reels', 'stories', 'classroom', 'leaderboard', 'messages', 'search', 'places', 'events', 'online' ); // online: "Show who's online", needs messages

	/**
	 * Settings › Features › Courses: the parts of a course an owner can turn off. The
	 * lesson list, progress, Mark Complete and course locks are always on.
	 * questions: under lessons (a course can also turn them off for itself) · helpful: votes
	 * on them · saying: "What people are saying" on the Overview · times: "7 min" ·
	 * instructor: "By" on the course page · confetti: when a course is finished ·
	 * suggest: "Next you might like" · activity: members' faces and post counts on Classroom cards.
	 */
	const COURSE_PARTS = array( 'questions', 'helpful', 'saying', 'times', 'instructor', 'confetti', 'suggest', 'activity' );

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
			'signup_label' => '',  // that button's words ("Become a member"); empty: the plain default
			'login_headline' => '', // the login and join pages' big line; empty: "Learn, share and grow together."
			'login_text'  => '',   // the line under it; empty: the community description
			'login_picture' => null, // array( 'id', 'url' ): the owner's photo beside the form; null: our illustration
			'display'     => 'auto', // the look members start with: auto (match device) | light | dark; each can change it
			'min_age'     => 13,   // 0, 13, 16 or 18; asked at sign-up, never stored
			'terms_url'   => '',
			'features'    => array( 'places' => false ) + array_fill_keys( self::FEATURES, true ), // Places is the one most communities don't need
			'courses'     => array_fill_keys( self::COURSE_PARTS, true ),
			'member_covers' => true, // Settings › Features › Members directory: cover photos on member cards
			'posting'     => 'everyone', // everyone | staff
			'messaging'   => 'everyone', // everyone | staff (members may only start chats with staff)
			'moderators'  => array(),
			'points'      => array_map( fn( $r ) => array( 'points' => $r[0], 'cap' => $r[1] ), Points::RULES ),
			'level_names' => array_fill( 0, 9, '' ),
			'level_points' => array(), // the points each kept level needs, 3–9 of them; empty: the nine defaults (Points::LEVELS)
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
			foreach ( array( 'features', 'points', 'courses' ) as $key ) {
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

	/** Whether a part of every course is on (see COURSE_PARTS). */
	public static function course_part( $key ) {
		$parts = self::all()['courses'];
		// Helpful votes and "What people are saying" need the questions they're about.
		if ( in_array( $key, array( 'helpful', 'saying' ), true ) && empty( $parts['questions'] ) ) {
			return false;
		}
		return ! empty( $parts[ $key ] );
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
		if ( (int) $user_id === get_current_user_id() && Helpers::as_member() ) {
			return false; // an admin seeing it as a member
		}
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
			'memberCovers' => (bool) $s['member_covers'], // covers (or the default one) on member cards
			'display'     => $s['display'], // the look members start with until they pick their own
			'courses'     => array_combine( self::COURSE_PARTS, array_map( array( __CLASS__, 'course_part' ), self::COURSE_PARTS ) ),
			'canPost'     => self::can_post(),
			'messaging'   => $s['messaging'],
			'isStaff'     => self::is_staff( get_current_user_id() ),
			'reportReasons' => Moderation::reasons(),
			'canManage'   => Helpers::can_manage(),
			'canSeeAsMember' => current_user_can( 'manage_options' ), // the floating "See it as a member" button
			'asMember'    => Helpers::as_member_pick(), // { group, level } while seeing it as a member
			'memberships' => current_user_can( 'manage_options' ) ? array_values( array_map( fn( $g ) => array( 'id' => $g['id'], 'name' => $g['name'] ), Access::groups() ) ) : array(), // the picker's choices
			'levelNames'  => array_slice( $s['level_names'], 0, count( Points::thresholds() ) ), // one per kept level
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
		$s['level_points'] = Points::thresholds(); // the form always edits the real list, defaults included
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
			'login_url'           => Login::url( 'login', array( 'preview' => '1' ) ), // opens for admins too (see Login::login_page)
			'accents'    => self::ACCENTS,
			'rules'      => self::rule_labels(),
			'thresholds' => Points::thresholds(),
			'suggested_levels' => array_map( fn( $n ) => Points::suggested( $n ), array_combine( range( 3, 9 ), range( 3, 9 ) ) ),
			// Courses that unlock at a level, so the form can warn before a level they need goes away.
			'level_courses' => self::level_courses(),
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
		$old_logo    = (int) ( $s['logo']['id'] ?? 0 );
		$old_picture = (int) ( $s['login_picture']['id'] ?? 0 );
		if ( array_key_exists( 'login_picture', $in ) ) {
			$s['login_picture'] = null === $in['login_picture'] ? null : self::claim_logo( (int) ( $in['login_picture']['id'] ?? 0 ), $s['login_picture'] ?? null );
			if ( is_wp_error( $s['login_picture'] ) ) {
				return $s['login_picture'];
			}
		}
		if ( array_key_exists( 'logo', $in ) ) {
			$s['logo'] = null === $in['logo'] ? null : self::claim_logo( (int) ( $in['logo']['id'] ?? 0 ), $s['logo'] );
			if ( is_wp_error( $s['logo'] ) ) {
				return $s['logo'];
			}
		}
		if ( array_key_exists( 'member_covers', $in ) ) {
			$s['member_covers'] = (bool) $in['member_covers'];
		}
		if ( isset( $in['courses'] ) && is_array( $in['courses'] ) ) {
			foreach ( self::COURSE_PARTS as $part ) {
				if ( array_key_exists( $part, $in['courses'] ) ) {
					$s['courses'][ $part ] = (bool) $in['courses'][ $part ];
				}
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

		$relevel = false;
		if ( array_key_exists( 'level_points', $in ) ) {
			$levels = Points::clean_levels( $in['level_points'] );
			if ( null === $levels ) {
				return new \WP_Error( 'mbcom_levels', __( 'Each level needs more points than the one before, starting at 0.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			$levels  = Points::LEVELS === $levels ? array() : $levels;
			$relevel = $levels !== ( Points::clean_levels( $s['level_points'] ) ?? array() );
			$s['level_points'] = $levels;
		}

		if ( array_key_exists( 'registration', $in ) && ! is_multisite() ) {
			update_option( 'users_can_register', $in['registration'] ? 1 : 0 );
		}
		if ( array_key_exists( 'signup_url', $in ) ) {
			$s['signup_url'] = esc_url_raw( (string) $in['signup_url'], array( 'http', 'https' ) );
		}
		if ( array_key_exists( 'signup_label', $in ) ) {
			$s['signup_label'] = mb_substr( sanitize_text_field( (string) $in['signup_label'] ), 0, 40 );
		}
		if ( array_key_exists( 'login_headline', $in ) ) {
			$s['login_headline'] = mb_substr( sanitize_text_field( (string) $in['login_headline'] ), 0, 80 );
		}
		if ( array_key_exists( 'login_text', $in ) ) {
			$s['login_text'] = mb_substr( sanitize_text_field( (string) $in['login_text'] ), 0, 200 );
		}
		if ( array_key_exists( 'display', $in ) ) {
			$s['display'] = in_array( $in['display'], array( 'auto', 'light', 'dark' ), true ) ? $in['display'] : 'auto';
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
		if ( $relevel ) {
			self::apply_level_count();
		}
		// A replaced or removed logo is deleted, once the new settings are saved.
		if ( $old_logo && $old_logo !== (int) ( $s['logo']['id'] ?? 0 ) ) {
			Media::delete( $old_logo );
		}
		if ( $old_picture && $old_picture !== (int) ( $s['login_picture']['id'] ?? 0 ) ) {
			Media::delete( $old_picture );
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
	/** Published or draft courses that unlock at a level: id, title and the level. */
	private static function level_courses() {
		global $wpdb;
		return array_map(
			fn( $r ) => array( 'id' => (int) $r['id'], 'title' => $r['title'], 'level' => (int) $r['unlock_level'] ),
			$wpdb->get_results( $wpdb->prepare( "SELECT id, title, unlock_level FROM %i WHERE access = 'level' AND unlock_level > 1 ORDER BY unlock_level, sort_order", Install::table( 'courses' ) ), ARRAY_A )
		);
	}

	/** After the levels change: courses above the top level unlock at the top one, and members' levels are worked out again. */
	private static function apply_level_count() {
		global $wpdb;
		$top = count( Points::thresholds() );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET unlock_level = %d WHERE access = 'level' AND unlock_level > %d", Install::table( 'courses' ), $top, $top ) );
		Points::relevel();
	}

	private static function claim_logo( $media_id, $current ) {
		global $wpdb;
		if ( $current && (int) $current['id'] === $media_id ) {
			return $current;
		}
		$table = Install::table( 'media' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, url FROM %i WHERE id = %d AND user_id = %d AND kind = 'image' AND post_id IS NULL", $table, $media_id, get_current_user_id() ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_invalid_setting', __( 'Upload the picture again, please.', 'membuild-community' ), array( 'status' => 400 ) );
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
