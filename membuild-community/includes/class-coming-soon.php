<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Coming soon: before the community opens, visitors see one page with an email box, and the
 * owner and their team see the whole site. Log in keeps working.
 *
 * Only the email is asked, and it stays in the community (the waitlist table): never sent
 * anywhere else. On opening day (or when the owner presses "Open the doors now") everyone on
 * the list gets one "We're open" email with a Join link, and Coming soon can turn itself off.
 * Every email has a one-click unsubscribe.
 *
 *   POST /waitlist                 a visitor's email (no login; a spam trap and a limit)
 *   GET  /coming-soon              Settings › Main website › Coming soon (admins)
 *   POST /coming-soon              change it
 *   GET  /coming-soon/list         the list, for the CSV download
 *   POST /coming-soon/open         Open the doors now
 */
class Coming_Soon {

	const OPTION = 'mbcom_coming_soon';
	const SEND   = 'mbcom_waitlist_send'; // the "We're open" emails, a batch at a time
	const BATCH  = 40;

	public static function register_hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 0 ); // before the community app (priority 1)
		add_action( 'template_redirect', array( __CLASS__, 'maybe_unsubscribe' ), 0 );
		add_action( self::SEND, array( __CLASS__, 'send_batch' ) );
	}

	public static function register_routes() {
		$admin = fn() => is_user_logged_in() && Helpers::can_manage();
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/waitlist', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_join' ), 'permission_callback' => '__return_true' ) );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/coming-soon',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_state' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/coming-soon/list', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $admin ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/coming-soon/open', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_open' ), 'permission_callback' => $admin ) );
	}

	/* ---------- Settings ---------- */

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'on'       => false,
				'opens'    => '',    // Y-m-d, the site's own day; '' for no date
				'email'    => true,  // on opening day, email everyone "We're open"
				'auto_off' => true,  // and turn Coming soon off by itself
				'opened'   => 0,     // when the doors opened (the emails went)
			),
			is_array( $s ) ? $s : array()
		);
	}

	private static function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	/** The Coming soon page made with the starter pages, or 0. */
	public static function page_id() {
		$id = (int) ( get_option( Starter_Pages::OPTION, array() )['pages']['coming-soon'] ?? 0 );
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/** Whether visitors see Coming soon right now (on, and its page is there). */
	public static function active() {
		return ! empty( self::settings()['on'] ) && self::page_id();
	}

	/** "Mon, Dec 1", or '' with no opening day. */
	public static function opens_text() {
		$day = (string) self::settings()['opens'];
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return '';
		}
		$at = date_create_immutable( $day . ' 12:00', wp_timezone() );
		return $at ? wp_date( 'D, M j', $at->getTimestamp() ) : '';
	}

	/* ---------- Who sees what ---------- */

	/**
	 * Visitors (and members who aren't on the team) see the Coming soon page in place of every
	 * other page. Log in, the password pages, robots.txt and the sitemaps keep working, so
	 * staff can sign in and launch news is findable.
	 */
	public static function gate() {
		if ( ! self::active() || Settings::is_staff( get_current_user_id() ) || Helpers::can_manage() ) {
			return;
		}
		if ( is_robots() || is_favicon() || get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' ) ) {
			return;
		}
		if ( get_query_var( 'mbcom_portal' ) ) {
			$path = trim( (string) get_query_var( 'mbcom_path' ), '/' );
			if ( in_array( $path, array( 'login', 'forgot-password', 'reset-password', 'login-link', 'manifest.webmanifest', 'sw.js' ), true ) || ! is_user_logged_in() ) {
				return; // signing in still works; a visitor opening the community is sent to Log in by it
			}
		} elseif ( is_page( self::page_id() ) ) {
			Helpers::no_page_cache();
			return;
		}
		nocache_headers();
		wp_safe_redirect( get_permalink( self::page_id() ), 302 );
		exit;
	}

	/* ---------- The list ---------- */

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE off_at IS NULL', Install::table( 'waitlist' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** A visitor leaves their email. Saying it twice is fine: they're on the list once. */
	public static function rest_join( \WP_REST_Request $req ) {
		if ( '' !== trim( (string) $req['website'] ) ) {
			return array( 'ok' => true ); // the hidden field only robots fill in
		}
		$slow = Helpers::throttle( 'waitlist', 10, HOUR_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		$email = sanitize_email( (string) $req['email'] );
		if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
			return new \WP_Error( 'mbcom_waitlist', __( 'Please type your email, like you@example.com.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$t   = Install::table( 'waitlist' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, off_at FROM %i WHERE email = %s', $t, strtolower( $email ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $row ) {
			if ( $row->off_at ) {
				$wpdb->update( $t, array( 'off_at' => null, 'emailed_at' => null ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- back on the list
			}
		} else {
			$wpdb->insert( $t, array( 'email' => strtolower( $email ), 'token' => wp_generate_password( 32, false ), 'created_at' => current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return array( 'ok' => true );
	}

	public static function rest_state() {
		global $wpdb;
		$s      = self::settings();
		$id     = self::page_id();
		$newest = $wpdb->get_row( $wpdb->prepare( 'SELECT email, created_at FROM %i WHERE off_at IS NULL ORDER BY id DESC LIMIT 1', Install::table( 'waitlist' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'on'       => (bool) $s['on'],
			'opens'    => (string) $s['opens'],
			'email'    => (bool) $s['email'],
			'autoOff'  => (bool) $s['auto_off'],
			'opened'   => (int) $s['opened'],
			'count'    => self::count(),
			'newest'   => $newest ? array(
				'email' => $newest->email,
				/* translators: %s: a time, like "2 hours" */
				'ago'   => sprintf( __( '%s ago', 'membuild-community' ), human_time_diff( strtotime( $newest->created_at . ' UTC' ) ) ),
			) : null,
			'page'     => $id ? array( 'view' => get_permalink( $id ), 'edit' => get_edit_post_link( $id, 'raw' ) ) : null,
		);
	}

	public static function rest_save( \WP_REST_Request $req ) {
		$s = self::settings();
		if ( isset( $req['on'] ) ) {
			$s['on'] = (bool) $req['on'] && self::page_id();
			if ( $s['on'] ) {
				$s['opened'] = 0; // closed again: a new opening day can email again
			}
		}
		if ( isset( $req['opens'] ) ) {
			$day        = sanitize_text_field( (string) $req['opens'] );
			$s['opens'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && checkdate( (int) substr( $day, 5, 2 ), (int) substr( $day, 8, 2 ), (int) substr( $day, 0, 4 ) ) ? $day : '';
		}
		foreach ( array( 'email' => 'email', 'autoOff' => 'auto_off' ) as $in => $key ) {
			if ( isset( $req[ $in ] ) ) {
				$s[ $key ] = (bool) $req[ $in ];
			}
		}
		self::save( $s );
		return self::rest_state();
	}

	/** Everyone still on the list, for the CSV download. */
	public static function rest_list() {
		global $wpdb;
		return array_map(
			fn( $r ) => array( 'email' => $r['email'], 'joined' => get_date_from_gmt( $r['created_at'], 'Y-m-d H:i' ) ),
			(array) $wpdb->get_results( $wpdb->prepare( 'SELECT email, created_at FROM %i WHERE off_at IS NULL ORDER BY id ASC', Install::table( 'waitlist' ) ), ARRAY_A ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	public static function rest_open() {
		self::open( true );
		return self::rest_state();
	}

	/* ---------- Opening day ---------- */

	/** Hourly: on the opening day, open (when the owner asked for anything to happen then). */
	public static function tick() {
		$s = self::settings();
		if ( empty( $s['on'] ) || $s['opened'] || '' === (string) $s['opens'] || wp_date( 'Y-m-d' ) < $s['opens'] ) {
			return;
		}
		if ( $s['email'] || $s['auto_off'] ) {
			self::open( false );
		}
	}

	/**
	 * The doors open: Coming soon goes off (always when the owner presses the button, or on the
	 * day when "Turn Coming soon off by itself" is on), and the "We're open" emails start.
	 */
	public static function open( $now ) {
		$s = self::settings();
		if ( $now || $s['auto_off'] ) {
			$s['on'] = false;
		}
		$s['opened'] = time();
		self::save( $s );
		if ( $s['email'] && ! wp_next_scheduled( self::SEND ) ) {
			wp_schedule_single_event( time(), self::SEND );
		}
	}

	/** The next few "We're open" emails; another run follows while anyone is left. */
	public static function send_batch() {
		global $wpdb;
		$t    = Install::table( 'waitlist' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, token FROM %i WHERE off_at IS NULL AND emailed_at IS NULL ORDER BY id ASC LIMIT %d', $t, self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $r ) {
			$wpdb->update( $t, array( 'emailed_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $r->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- marked first, so a slow run never emails anyone twice
			self::send_open( $r->email, $r->token );
		}
		if ( count( $rows ) === self::BATCH ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SEND );
		}
	}

	private static function send_open( $email, $token ) {
		$name  = Settings::name();
		$off   = self::unsubscribe_url( $token );
		/* translators: %s: community name */
		$title = sprintf( __( '%s is open', 'membuild-community' ), $name );
		$body  = Email::para( esc_html__( 'You asked us to tell you when the doors open. They’re open now, so come on in.', 'membuild-community' ) )
			. Email::para( esc_html__( 'Joining takes a minute: your name, email and a password.', 'membuild-community' ) );
		$after = esc_html__( 'You’re getting this because you left your email on our Coming soon page.', 'membuild-community' ) . ' <a href="' . esc_url( $off ) . '" style="color:#4a4f59;font-weight:700">' . esc_html__( 'Unsubscribe', 'membuild-community' ) . '</a>';
		$html  = Email::layout( __( 'We’re open!', 'membuild-community' ), $body, Login::url( 'join' ), __( 'Join now', 'membuild-community' ), '', '', $after );
		/* translators: 1: community name, 2: the Join link, 3: the unsubscribe link */
		$text  = sprintf( __( "%1\$s is open. Come on in: %2\$s\n\nUnsubscribe: %3\$s", 'membuild-community' ), $name, Login::url( 'join' ), $off );
		return Email::send( $email, $title, $html, $text, array( 'List-Unsubscribe: <' . $off . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' ) );
	}

	/* ---------- Unsubscribe ---------- */

	public static function unsubscribe_url( $token ) {
		return add_query_arg( 'mbcom_waitlist_off', rawurlencode( (string) $token ), home_url( '/' ) );
	}

	/**
	 * The link in every email. Opening it asks (mail scanners open links); a POST takes the
	 * address off the list, the same as a mail app's one-click unsubscribe (RFC 8058).
	 */
	public static function maybe_unsubscribe() {
		if ( ! isset( $_GET['mbcom_waitlist_off'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the token is the proof
			return;
		}
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['mbcom_waitlist_off'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- letters and digits only
		$row   = 32 === strlen( $token ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, off_at FROM %i WHERE token = %s', Install::table( 'waitlist' ), $token ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			wp_die( esc_html__( 'This link isn’t valid any more. You may already be off the list.', 'membuild-community' ), '', array( 'response' => 404 ) );
		}
		$done = (bool) $row->off_at;
		if ( 'POST' === Helpers::method() && ! $done ) {
			$wpdb->update( Install::table( 'waitlist' ), array( 'off_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$done = true;
		}
		nocache_headers();
		$name = esc_html( Settings::name() );
		$body = $done
			? '<h1>' . esc_html__( 'Done. You’re off the list.', 'membuild-community' ) . '</h1><p>' . esc_html__( 'We won’t email you about the opening.', 'membuild-community' ) . '</p>'
			: '<h1>' . esc_html__( 'Take me off the list?', 'membuild-community' ) . '</h1><p>' . esc_html__( 'You won’t get the email when the doors open.', 'membuild-community' ) . '</p><form method="post"><button type="submit">' . esc_html__( 'Yes, take me off', 'membuild-community' ) . '</button></form>';
		wp_die( $body, $name, array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
	}
}
