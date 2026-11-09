<?php
/**
 * Two-step login, with the free Two Factor plugin (WordPress.org, by WordPress contributors).
 *
 * Two Factor keeps the secrets and checks every code (app, email, recovery codes) with its own
 * limit on wrong tries. This class only gives it the community's look and words:
 *
 * - Logging in: after the password, the community login page asks for the code (Login::maybe_handle
 *   sends ?wp-auth-id=…&wp-auth-nonce=… here). Never wp-login.php's own screen while the community
 *   login pages are on. The same after "Email me a login link".
 * - Required (Settings › Security › Who has to use it?): someone who must use it but hasn't set it
 *   up does so right after their password, before they're logged in.
 * - Members turn it on and off in their own account (/me/two-step), never in wp-admin.
 * - The owner turns it on in Settings › Security, which adds and turns on Two Factor.
 *
 *   GET|POST /me/two-step          the member's own setup
 *   GET|POST /settings/two-step    the owner's switch
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Two_Step {

	const PLUGIN      = 'two-factor/two-factor.php';
	const SLUG        = 'two-factor';
	const WHO         = array( 'anyone', 'team', 'everyone' );
	const PENDING_APP = 'mbcom_totp_pending'; // an app key being set up, until its first code is typed: { key, until }
	const PENDING_FOR = 600;                  // 10 minutes to scan it and type a code, like Two Factor's login step
	const PROVED      = 'mbcom_setup_email_ok'; // the setup step's emailed code was typed, until this time

	const TOTP   = 'Two_Factor_Totp';
	const EMAIL  = 'Two_Factor_Email';
	const BACKUP = 'Two_Factor_Backup_Codes';

	public static function register_hooks() {
		add_filter( 'authenticate', array( __CLASS__, 'hold_cookies' ), 32 );
		// Just before Two Factor's own (PHP_INT_MAX), so its wp-login.php screen never shows.
		add_action( 'wp_login', array( __CLASS__, 'after_password' ), PHP_INT_MAX - 1, 2 );
	}

	public static function register_routes() {
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/me/two-step',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_mine' ), 'permission_callback' => 'is_user_logged_in' ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_change' ), 'permission_callback' => 'is_user_logged_in' ),
			)
		);
		$owner = fn() => is_user_logged_in() && Helpers::can_manage() && current_user_can( 'activate_plugins' );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/settings/two-step',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_status' ), 'permission_callback' => $owner ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_switch' ), 'permission_callback' => $owner ),
			)
		);
	}

	/* ---------- Who uses it ---------- */

	/** Two Factor is on. */
	public static function active() {
		return class_exists( 'Two_Factor_Core' );
	}

	public static function who() {
		$who = (string) Settings::get( 'two_step_who' );
		return in_array( $who, self::WHO, true ) ? $who : 'anyone';
	}

	/** They set it up (a way to get a code is on). */
	public static function using( $user_id ) {
		return self::active() && \Two_Factor_Core::is_user_using_two_factor( (int) $user_id );
	}

	/** The owner asks this person to use it (and the community login pages can walk them through it). */
	public static function required( $user_id ) {
		if ( ! self::active() || ! Login::enabled() || ! $user_id ) {
			return false;
		}
		$who = self::who();
		if ( 'everyone' === $who ) {
			return true;
		}
		return 'team' === $who && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'moderate_comments' ) || in_array( (int) $user_id, array_map( 'intval', (array) Settings::get( 'moderators' ) ), true ) );
	}

	/** What happens after this person's password: 'code', 'setup' or '' (logged in). */
	public static function step_for( $user_id ) {
		if ( self::using( $user_id ) ) {
			return 'code';
		}
		return self::required( $user_id ) ? 'setup' : '';
	}

	/* ---------- Logging in ---------- */

	/** A password that's right for someone who must still set it up: no login cookies yet. */
	public static function hold_cookies( $user ) {
		// The "log in again" box inside wp-admin: they were logged in already, so it isn't held up.
		if ( ! $user instanceof \WP_User || isset( $_REQUEST['interim-login'] ) || self::using( $user->ID ) || ! self::required( $user->ID ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- only read
			return $user;
		}
		// XML-RPC and the REST API skip our setup step, so a password alone isn't enough there
		// (application passwords still work, as Two Factor allows for people who use it).
		if ( \Two_Factor_Core::is_api_request() && ! \Two_Factor_Core::is_user_api_login_enabled( $user->ID ) ) {
			return new \WP_Error( 'invalid_application_credentials', __( 'Set up two-step login first, from the login page.', 'membuild-community' ) );
		}
		add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		return $user;
	}

	/**
	 * The password was right (any login form, or a login link). If a code is needed, the session
	 * WordPress just made is ended and they go to the community login page for the second step.
	 */
	public static function after_password( $user_login, $user ) {
		// The "log in again" box inside wp-admin keeps Two Factor's own screen.
		if ( ! $user instanceof \WP_User || ! Login::enabled() || isset( $_REQUEST['interim-login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- only read
			return;
		}
		$step = self::step_for( $user->ID );
		if ( ! $step ) {
			return;
		}
		\Two_Factor_Core::destroy_current_session_for_user( $user );
		wp_clear_auth_cookie();
		$nonce = \Two_Factor_Core::create_login_nonce( $user->ID );
		if ( ! $nonce ) {
			wp_die( esc_html__( 'Something went wrong. Please try logging in again.', 'membuild-community' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- carried along, checked on the next page
		$args = array(
			'wp-auth-id'    => $user->ID,
			'wp-auth-nonce' => $nonce['key'],
		);
		$to = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ) : '';
		if ( $to ) {
			$args['redirect_to'] = $to;
		}
		// A login link always keeps them logged in, like "Keep me logged in".
		if ( ! empty( $_REQUEST['rememberme'] ) || ! empty( $_REQUEST['t'] ) ) {
			$args['rememberme'] = 1;
		}
		// phpcs:enable
		wp_safe_redirect( Login::url( 'login', $args ) );
		exit;
	}

	/** The second step: Login::maybe_handle calls this for /community/login?wp-auth-id=… */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the login nonce below is the proof
		$user  = get_userdata( isset( $_REQUEST['wp-auth-id'] ) ? absint( $_REQUEST['wp-auth-id'] ) : 0 );
		$nonce = isset( $_REQUEST['wp-auth-nonce'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_REQUEST['wp-auth-nonce'] ) ) ) : '';
		$to    = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ) : '';
		$keep  = ! empty( $_REQUEST['rememberme'] );
		$post  = 'POST' === Helpers::method();
		// phpcs:enable
		if ( ! self::active() || ! $user || '' === $nonce || true !== \Two_Factor_Core::verify_login_nonce( $user->ID, $nonce ) || ( $post && Login::from_elsewhere() ) ) {
			self::timed_out();
		}
		$step = self::step_for( $user->ID );
		if ( ! $step ) {
			self::finish( $user, null, $keep, $to ); // turned off meanwhile: the password was enough
		}
		$data = array(
			'u'        => $user->ID,
			'n'        => $nonce,
			'redirect' => $to,
			'keep'     => $keep,
			'error'    => '',
			'notice'   => '',
			'address'  => self::masked( $user->user_email ),
		);
		// Two Factor's login nonce lasts 10 minutes. Opening the page with under 5 left starts the clock
		// again, at an address with the new one, so reloading the page keeps working.
		$saved = get_user_meta( $user->ID, \Two_Factor_Core::USER_META_NONCE_KEY, true );
		if ( ! $post && (int) ( $saved['expiration'] ?? 0 ) - time() < 5 * MINUTE_IN_SECONDS ) {
			// phpcs:disable WordPress.Security.NonceVerification -- only carried along, and only known values
			$provider = isset( $_GET['provider'] ) && is_string( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';
			$way      = isset( $_GET['way'] ) ? sanitize_key( wp_unslash( $_GET['way'] ) ) : '';
			// phpcs:enable
			$args = array_filter(
				array(
					'wp-auth-id'    => $user->ID,
					'wp-auth-nonce' => self::fresh_nonce( $user->ID ),
					'redirect_to'   => $to,
					'rememberme'    => $keep ? 1 : '',
					'provider'      => '' !== self::way( $provider ) ? $provider : '', // Two Factor's class names: kept exactly
					'way'           => in_array( $way, array( 'email', 'app' ), true ) ? $way : '',
				)
			);
			wp_safe_redirect( Login::url( 'login', $args ) );
			exit;
		}
		if ( 'code' === $step ) {
			self::code_step( $user, $data, $post );
		} else {
			self::setup_step( $user, $data, $post );
		}
	}

	/** Their usual way to get a code, or the one they picked ("Email me a code instead"). */
	private static function code_step( \WP_User $user, array $data, $post ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- checked by the login nonce
		$want     = isset( $_REQUEST['provider'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['provider'] ) ) : '';
		$provider = \Two_Factor_Core::get_provider_for_user( $user, $want ?: null );
		if ( ! $provider || is_wp_error( $provider ) ) {
			self::timed_out();
		}
		$key = $provider->get_key();
		if ( '' === self::way( $key ) ) {
			// A way added by another plugin (a security key, say): use one we can show, else Two Factor's own screen.
			$known = array_values( array_filter( array_keys( \Two_Factor_Core::get_available_providers_for_user( $user ) ), fn( $k ) => '' !== self::way( $k ) ) );
			if ( ! $known ) {
				$args = array(
					'action'        => 'validate_2fa',
					'wp-auth-id'    => $user->ID,
					'wp-auth-nonce' => $data['n'],
				);
				if ( $data['redirect'] ) {
					$args['redirect_to'] = $data['redirect'];
				}
				if ( $data['keep'] ) {
					$args['rememberme'] = 1;
				}
				wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), site_url( 'wp-login.php', 'login_post' ) ) );
				exit;
			}
			$provider = \Two_Factor_Core::get_provider_for_user( $user, $known[0] );
			$key      = $provider->get_key();
		}
		if ( $post ) {
			// After many wrong codes Two Factor resets the password and shows its own page, which needs
			// WordPress's login header and footer (normally only there on wp-login.php).
			foreach ( array( 'login_header' => 'function.login-header.php', 'login_footer' => 'function.login-footer.php' ) as $fn => $file ) {
				if ( ! function_exists( $fn ) && defined( 'TWO_FACTOR_DIR' ) && file_exists( TWO_FACTOR_DIR . 'includes/' . $file ) ) {
					require_once TWO_FACTOR_DIR . 'includes/' . $file;
				}
			}
			$result = \Two_Factor_Core::process_provider( $provider, $user, true ); // its wrong-try limit, and the email "send it again"
			if ( true === $result ) {
				self::finish( $user, $provider, $data['keep'], $data['redirect'] );
			}
			if ( is_wp_error( $result ) ) {
				do_action( 'wp_login_failed', $user->user_login, $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, as Two Factor fires it
				$data['error'] = self::words( $result, $user );
			} elseif ( self::EMAIL === $key ) {
				$data['notice'] = __( 'We sent a new code.', 'membuild-community' );
			}
			// A failed code ends this step's nonce, as Two Factor does: a new one for the next try.
			$data['n'] = self::fresh_nonce( $user->ID );
		} elseif ( self::EMAIL === $key ) {
			$email = $provider;
			if ( ! $email->user_has_token( $user->ID ) || $email->user_token_has_expired( $user->ID ) ) {
				$email->generate_and_email_token( $user );
			}
		}
		$data['way']    = self::way( $key );
		$data['others'] = array_values( array_diff( array_map( array( __CLASS__, 'way' ), array_keys( \Two_Factor_Core::get_available_providers_for_user( $user ) ) ), array( $data['way'], '' ) ) );
		Login::show( 'twostep', $data );
	}

	/** Someone who must use it, setting it up before they're logged in: email codes or an app. */
	private static function setup_step( \WP_User $user, array $data, $post ) {
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the login nonce
		$way  = isset( $_REQUEST['way'] ) ? sanitize_key( wp_unslash( $_REQUEST['way'] ) ) : '';
		$code = isset( $_POST['code'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
		// phpcs:enable
		$data['way'] = in_array( $way, array( 'email', 'app' ), true ) ? $way : '';
		if ( 'email' === $data['way'] ) {
			$email = \Two_Factor_Email::get_instance();
			if ( $post && isset( $_POST['resend'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked by the login nonce
				$email->generate_and_email_token( $user );
				$data['notice'] = __( 'We sent a new code.', 'membuild-community' );
			} elseif ( $post ) {
				$ok = self::try_code( $user, fn() => '' !== $code && $email->validate_token( $user->ID, $code ) );
				if ( true === $ok ) {
					\Two_Factor_Core::enable_provider_for_user( $user->ID, self::EMAIL );
					self::tell_on( $user );
					self::finish( $user, $email, $data['keep'], $data['redirect'] );
				}
				$data['error'] = $ok;
			} elseif ( ! $email->user_has_token( $user->ID ) || $email->user_token_has_expired( $user->ID ) ) {
				$email->generate_and_email_token( $user );
			}
		} elseif ( 'app' === $data['way'] ) {
			// The password alone mustn't be enough to add an app (someone who stole it would add theirs
			// and lock the member out), so the emailed code comes first.
			$just = false;
			if ( (int) get_user_meta( $user->ID, self::PROVED, true ) < time() ) {
				$email         = \Two_Factor_Email::get_instance();
				$data['prove'] = true;
				if ( $post && isset( $_POST['resend'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked by the login nonce
					$email->generate_and_email_token( $user );
					$data['notice'] = __( 'We sent a new code.', 'membuild-community' );
				} elseif ( $post ) {
					$ok = self::try_code( $user, fn() => '' !== $code && $email->validate_token( $user->ID, $code ) );
					if ( true === $ok ) {
						update_user_meta( $user->ID, self::PROVED, time() + self::PENDING_FOR );
						$data['prove'] = false;
						$just          = true;
					} else {
						$data['error'] = $ok;
					}
				} elseif ( ! $email->user_has_token( $user->ID ) || $email->user_token_has_expired( $user->ID ) ) {
					$email->generate_and_email_token( $user );
				}
			}
		}
		if ( 'app' === $data['way'] && empty( $data['prove'] ) ) {
			$secret = self::pending_key( $user->ID );
			if ( $post && ! $just ) {
				$ok = self::try_code( $user, fn() => '' !== $code && \Two_Factor_Totp::is_valid_authcode( $secret, $code ) );
				if ( true === $ok ) {
					self::save_app( $user, $secret );
					delete_user_meta( $user->ID, self::PROVED );
					self::tell_on( $user );
					self::finish( $user, \Two_Factor_Totp::get_instance(), $data['keep'], $data['redirect'] );
				}
				$data['error'] = $ok;
			}
			$data['secret'] = $secret;
			$data['qr']     = \Two_Factor_Totp::generate_qr_code_url( $user, $secret );
		}
		if ( $post ) {
			$data['n'] = self::fresh_nonce( $user->ID );
		}
		Login::show( 'twosetup', $data );
	}

	/** Back to Log in with "That took too long". */
	private static function timed_out() {
		wp_safe_redirect( Login::url( 'login', array( 'timedout' => 1 ) ) );
		exit;
	}

	/** A new login nonce for the next step, or back to Log in. */
	private static function fresh_nonce( $user_id ) {
		$fresh = \Two_Factor_Core::create_login_nonce( $user_id );
		if ( ! $fresh ) {
			self::timed_out();
		}
		return $fresh['key'];
	}

	/** Tells the member it was turned on, so someone else doing it doesn't go unnoticed. */
	private static function tell_on( \WP_User $user ) {
		$name = Settings::name();
		/* translators: %s: community name */
		$subject = sprintf( __( 'Two-step login is on for %s', 'membuild-community' ), $name );
		$heading = __( 'Two-step login is on', 'membuild-community' );
		$lines   = array(
			__( 'Two-step login was just turned on for your account. From now on, you type a code after your password.', 'membuild-community' ),
			__( 'Wasn’t you? Choose a new password now, and tell us.', 'membuild-community' ),
		);
		$url  = wp_lostpassword_url();
		$body = implode( '', array_map( fn( $l ) => '<p style="margin:14px 0 0;color:#4a4f59">' . esc_html( $l ) . '</p>', $lines ) );
		$html = Email::layout( $heading, $body, $url, __( 'Choose a new password', 'membuild-community' ), '' );
		Email::send( $user->user_email, $subject, $html, $heading . "\n\n" . $lines[0] . "\n\n" . $lines[1] . "\n" . $url . "\n" );
	}

	/**
	 * Logs them in, as Two Factor does after a right code: its session marks, then the cookies,
	 * then where they were going.
	 */
	private static function finish( \WP_User $user, $provider, $keep, $to ) {
		\Two_Factor_Core::delete_login_nonce( $user->ID );
		delete_user_meta( $user->ID, \Two_Factor_Core::USER_RATE_LIMIT_KEY );
		delete_user_meta( $user->ID, \Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY );
		$mark = static function ( $session, $user_id ) use ( $provider, $user ) {
			if ( $provider && $user->ID === $user_id ) {
				$session['two-factor-login']    = time();
				$session['two-factor-provider'] = $provider->get_key();
			}
			return $session;
		};
		add_filter( 'attach_session_information', $mark, 10, 2 );
		remove_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );
		wp_set_auth_cookie( $user->ID, (bool) $keep, is_ssl() );
		if ( $provider ) {
			do_action( 'two_factor_user_authenticated', $user, $provider ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Two Factor's own hook
		}
		remove_filter( 'attach_session_information', $mark );
		$to = $to ?: apply_filters( 'login_redirect', admin_url(), '', $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
		wp_safe_redirect( $to ?: Portal::url() );
		exit;
	}

	/**
	 * A code typed while setting it up, under Two Factor's own limit on wrong tries.
	 * true, or the words to show.
	 */
	private static function try_code( \WP_User $user, callable $check ) {
		if ( \Two_Factor_Core::is_user_rate_limited( $user ) ) {
			return self::wait_words( $user );
		}
		if ( $check() ) {
			delete_user_meta( $user->ID, \Two_Factor_Core::USER_RATE_LIMIT_KEY );
			delete_user_meta( $user->ID, \Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY );
			return true;
		}
		update_user_meta( $user->ID, \Two_Factor_Core::USER_RATE_LIMIT_KEY, time() );
		update_user_meta( $user->ID, \Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, 1 + (int) get_user_meta( $user->ID, \Two_Factor_Core::USER_FAILED_LOGIN_ATTEMPTS_KEY, true ) );
		// As Two Factor does after many wrong codes: the password was probably stolen, so it's reset
		// (which also ends every login) and the member is emailed.
		if ( \Two_Factor_Core::should_reset_password( $user->ID ) ) {
			\Two_Factor_Core::reset_compromised_password( $user );
			\Two_Factor_Core::send_password_reset_emails( $user );
			return __( 'Too many wrong codes, so for safety your password was reset. Check your email to choose a new one.', 'membuild-community' );
		}
		return __( 'That code isn’t right. Check it and try again.', 'membuild-community' );
	}

	private static function wait_words( \WP_User $user ) {
		$until = (int) get_user_meta( $user->ID, \Two_Factor_Core::USER_RATE_LIMIT_KEY, true ) + \Two_Factor_Core::get_user_time_delay( $user );
		/* translators: %s: how long, like "2 mins" */
		return sprintf( __( 'Too many wrong codes. Wait %s, then try again.', 'membuild-community' ), human_time_diff( time(), max( $until, time() + 1 ) ) );
	}

	/** Two Factor's messages, in the community's words. */
	private static function words( \WP_Error $error, \WP_User $user ) {
		if ( 'two_factor_too_fast' === $error->get_error_code() ) {
			return self::wait_words( $user );
		}
		return __( 'That code isn’t right. Check it and try again.', 'membuild-community' );
	}

	/** Two Factor's provider names, as the pages say them. */
	public static function way( $key ) {
		$ways = array(
			self::TOTP   => 'app',
			self::EMAIL  => 'email',
			self::BACKUP => 'recovery',
		);
		return $ways[ $key ] ?? '';
	}

	public static function provider_for( $way ) {
		$keys = array(
			'app'      => self::TOTP,
			'email'    => self::EMAIL,
			'recovery' => self::BACKUP,
		);
		return $keys[ $way ] ?? '';
	}

	/** a•••@example.com */
	public static function masked( $email ) {
		$at = strpos( (string) $email, '@' );
		return false === $at ? '' : mb_substr( $email, 0, 1 ) . '•••' . substr( $email, $at );
	}

	/** The app key being set up, made the first time it's asked for. */
	private static function pending_key( $user_id ) {
		$pending = get_user_meta( $user_id, self::PENDING_APP, true );
		if ( is_array( $pending ) && ! empty( $pending['key'] ) && (int) ( $pending['until'] ?? 0 ) > time() ) {
			return (string) $pending['key'];
		}
		$key = \Two_Factor_Totp::generate_key();
		update_user_meta(
			$user_id,
			self::PENDING_APP,
			array(
				'key'   => $key,
				'until' => time() + self::PENDING_FOR,
			)
		);
		return $key;
	}

	private static function save_app( \WP_User $user, $key ) {
		\Two_Factor_Totp::get_instance()->set_user_totp_key( $user->ID, $key );
		\Two_Factor_Core::enable_provider_for_user( $user->ID, self::TOTP );
		delete_user_meta( $user->ID, self::PENDING_APP );
	}

	/* ---------- The member's own page ---------- */

	public static function rest_mine() {
		return self::mine( wp_get_current_user() );
	}

	/** What the member's page shows. */
	public static function mine( \WP_User $user ) {
		if ( ! self::active() ) {
			return array( 'available' => false );
		}
		$on = \Two_Factor_Core::get_enabled_providers_for_user( $user );
		return array(
			'available' => true,
			'on'        => self::using( $user->ID ),
			'app'       => in_array( self::TOTP, $on, true ),
			'app_since' => in_array( self::TOTP, $on, true ) ? self::app_since( $user->ID ) : null,
			'email'     => in_array( self::EMAIL, $on, true ),
			'codes'     => in_array( self::BACKUP, $on, true ) ? (int) \Two_Factor_Backup_Codes::codes_remaining_for_user( $user ) : null,
			'address'   => self::masked( $user->user_email ),
			'required'  => self::required( $user->ID ),
			// Changing it needs a code typed lately (Two Factor's own rule), so a borrowed session can't turn it off.
			'confirmed' => \Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ),
		);
	}

	/** When the app was set up, if we know it (kept by us when it's set up here). */
	private static function app_since( $user_id ) {
		$at = (int) get_user_meta( $user_id, 'mbcom_totp_since', true );
		return $at ? gmdate( 'c', $at ) : null;
	}

	/**
	 * { action: app_start | app_confirm (code) | app_remove | email_send | email_on (code) | email_off
	 *   | codes | off | confirm (code) | confirm_send }
	 */
	public static function rest_change( \WP_REST_Request $req ) {
		$user   = wp_get_current_user();
		$action = sanitize_key( (string) $req['action'] );
		$code   = preg_replace( '/\s+/', '', (string) $req['code'] );
		if ( ! self::active() ) {
			return self::error( 'mbcom_two_step_off', __( 'Two-step login is off for this community.', 'membuild-community' ), 409 );
		}
		if ( Helpers::as_member() ) {
			return self::error( 'mbcom_as_member', __( 'Go back to your own view to change this.', 'membuild-community' ), 403 );
		}
		$on    = \Two_Factor_Core::get_enabled_providers_for_user( $user );
		$using = self::using( $user->ID );

		// "Type a code first": proves it's them before anything changes.
		if ( 'confirm_send' === $action ) {
			if ( ! in_array( self::EMAIL, $on, true ) ) {
				return self::error( 'mbcom_two_step_no_email', __( 'Email codes aren’t on for you.', 'membuild-community' ), 400 );
			}
			\Two_Factor_Email::get_instance()->generate_and_email_token( $user );
			return self::mine( $user );
		}
		if ( 'confirm' === $action ) {
			$used = '';
			$ok   = self::try_code(
				$user,
				function () use ( $user, $on, $code, &$used ) {
					foreach ( $on as $key ) {
						if ( '' === $code ) {
							break;
						}
						if ( self::TOTP === $key && \Two_Factor_Totp::get_instance()->validate_code_for_user( $user, $code ) ) {
							$used = $key;
						} elseif ( self::EMAIL === $key && \Two_Factor_Email::get_instance()->validate_token( $user->ID, $code ) ) {
							$used = $key;
						} elseif ( self::BACKUP === $key && \Two_Factor_Backup_Codes::get_instance()->validate_code( $user, $code ) ) {
							$used = $key;
						}
						if ( $used ) {
							return true;
						}
					}
					return false;
				}
			);
			if ( true !== $ok ) {
				return self::error( 'mbcom_two_step_wrong', $ok, 400 );
			}
			\Two_Factor_Core::update_current_user_session(
				array(
					'two-factor-login'    => time(),
					'two-factor-provider' => $used,
				)
			);
			return self::mine( $user );
		}

		if ( $using && ! \Two_Factor_Core::current_user_can_update_two_factor_options( 'save' ) ) {
			return self::error( 'mbcom_two_step_confirm', __( 'Type a code first, so we know it’s you.', 'membuild-community' ), 403 );
		}
		$keeps = fn( array $left ) => (bool) array_intersect( $left, array( self::TOTP, self::EMAIL ) );
		$must  = self::required( $user->ID );
		$kept  = __( 'Your community asks everyone like you to keep two-step login on. Set up another way first.', 'membuild-community' );

		switch ( $action ) {
			case 'app_start':
				delete_user_meta( $user->ID, self::PENDING_APP );
				$key = self::pending_key( $user->ID );
				return array(
					'key' => $key,
					'uri' => \Two_Factor_Totp::generate_qr_code_url( $user, $key ),
					// Two Factor's own QR drawing, so the key never leaves the site.
					'qr_script' => plugins_url( 'includes/qrcode-generator/qrcode.js', TWO_FACTOR_DIR . 'two-factor.php' ),
				);
			case 'app_confirm':
				$pending = get_user_meta( $user->ID, self::PENDING_APP, true );
				if ( ! is_array( $pending ) || empty( $pending['key'] ) || (int) ( $pending['until'] ?? 0 ) < time() ) {
					return self::error( 'mbcom_two_step_expired', __( 'That took too long. Start again and scan the new code.', 'membuild-community' ), 400 );
				}
				$ok = self::try_code( $user, fn() => '' !== $code && \Two_Factor_Totp::is_valid_authcode( $pending['key'], $code ) );
				if ( true !== $ok ) {
					return self::error( 'mbcom_two_step_wrong', $ok, 400 );
				}
				self::save_app( $user, $pending['key'] );
				update_user_meta( $user->ID, 'mbcom_totp_since', time() );
				self::mark_session( self::TOTP, $using );
				if ( ! $using ) {
					self::tell_on( $user );
				}
				break;
			case 'app_remove':
				if ( $must && ! $keeps( array_diff( $on, array( self::TOTP ) ) ) ) {
					return self::error( 'mbcom_two_step_required', $kept, 400 );
				}
				\Two_Factor_Core::disable_provider_for_user( $user->ID, self::TOTP );
				\Two_Factor_Totp::get_instance()->delete_user_totp_key( $user->ID );
				delete_user_meta( $user->ID, 'mbcom_totp_since' );
				self::tidy( $user );
				break;
			case 'email_send':
				\Two_Factor_Email::get_instance()->generate_and_email_token( $user );
				return self::mine( $user );
			case 'email_on':
				$email = \Two_Factor_Email::get_instance();
				$ok    = self::try_code( $user, fn() => '' !== $code && $email->validate_token( $user->ID, $code ) );
				if ( true !== $ok ) {
					return self::error( 'mbcom_two_step_wrong', $ok, 400 );
				}
				\Two_Factor_Core::enable_provider_for_user( $user->ID, self::EMAIL );
				self::mark_session( self::EMAIL, $using );
				if ( ! $using ) {
					self::tell_on( $user );
				}
				break;
			case 'email_off':
				if ( $must && ! $keeps( array_diff( $on, array( self::EMAIL ) ) ) ) {
					return self::error( 'mbcom_two_step_required', $kept, 400 );
				}
				\Two_Factor_Core::disable_provider_for_user( $user->ID, self::EMAIL );
				self::tidy( $user );
				break;
			case 'codes':
				if ( ! $keeps( $on ) ) {
					return self::error( 'mbcom_two_step_first', __( 'Turn on the app or email codes first.', 'membuild-community' ), 400 );
				}
				$codes = \Two_Factor_Backup_Codes::get_instance()->generate_codes( $user );
				\Two_Factor_Core::enable_provider_for_user( $user->ID, self::BACKUP );
				$res = rest_ensure_response( self::mine( $user ) + array( 'new_codes' => $codes ) );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			case 'off':
				if ( $must ) {
					return self::error( 'mbcom_two_step_required', __( 'Your community asks everyone like you to keep two-step login on.', 'membuild-community' ), 400 );
				}
				foreach ( array( self::TOTP, self::EMAIL, self::BACKUP ) as $key ) {
					\Two_Factor_Core::disable_provider_for_user( $user->ID, $key );
				}
				\Two_Factor_Totp::get_instance()->delete_user_totp_key( $user->ID );
				delete_user_meta( $user->ID, \Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY );
				delete_user_meta( $user->ID, 'mbcom_totp_since' );
				break;
			default:
				return self::error( 'mbcom_bad_action', __( 'Unknown action.', 'membuild-community' ), 400 );
		}
		return self::mine( $user );
	}

	/** The first way turned on counts as a code just typed, so recovery codes can follow straight away. */
	private static function mark_session( $key, $was_using ) {
		if ( ! $was_using ) {
			\Two_Factor_Core::update_current_user_session(
				array(
					'two-factor-login'    => time(),
					'two-factor-provider' => $key,
				)
			);
		}
	}

	/** With no app and no email codes left, recovery codes alone would be a way in: they go too. */
	private static function tidy( \WP_User $user ) {
		$left = \Two_Factor_Core::get_enabled_providers_for_user( $user );
		if ( ! array_intersect( $left, array( self::TOTP, self::EMAIL ) ) ) {
			\Two_Factor_Core::disable_provider_for_user( $user->ID, self::BACKUP );
			delete_user_meta( $user->ID, \Two_Factor_Backup_Codes::BACKUP_CODES_META_KEY );
		}
	}

	private static function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/* ---------- The owner's switch ---------- */

	/** The plugin file, if Two Factor is installed (in its usual folder). */
	private static function plugin_file() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( self::PLUGIN === $file || ( 0 === strpos( $file, self::SLUG . '/' ) && str_ends_with( $file, '/two-factor.php' ) ) ) {
				return $file;
			}
		}
		return '';
	}

	/** Whether this site lets plugins be added from here. */
	private static function can_add() {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return current_user_can( 'install_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) && 'direct' === get_filesystem_method();
	}

	/** { state: on | off | missing, version, can_add, plugins_url, who } */
	public static function status() {
		$file = self::active() ? '' : self::plugin_file();
		return array(
			'state'       => self::active() ? 'on' : ( $file ? 'off' : 'missing' ),
			'version'     => defined( 'TWO_FACTOR_VERSION' ) ? TWO_FACTOR_VERSION : null,
			'can_add'     => self::active() || $file ? true : self::can_add(),
			'plugins_url' => admin_url( 'plugin-install.php?s=two-factor&tab=search&type=term' ),
			'needs_login_page' => ! Login::enabled(),
		);
	}

	public static function rest_status() {
		return self::status();
	}

	/** { on: true } adds Two Factor from WordPress.org if needed and turns it on; { on: false } turns it off. */
	public static function rest_switch( \WP_REST_Request $req ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! $req['on'] ) {
			$file = self::plugin_file();
			if ( $file && is_plugin_active( $file ) ) {
				deactivate_plugins( $file );
			}
			return array_merge( self::status(), array( 'state' => $file ? 'off' : 'missing' ) );
		}
		if ( self::active() ) {
			return self::status();
		}
		$file = self::plugin_file();
		if ( ! $file ) {
			$added = self::add_plugin();
			if ( is_wp_error( $added ) ) {
				return $added;
			}
			$file = self::plugin_file();
		}
		$on = $file ? activate_plugin( $file ) : new \WP_Error( 'mbcom_two_step_add_failed', '' );
		if ( is_wp_error( $on ) ) {
			return self::error( 'mbcom_two_step_add_failed', __( 'Two Factor was added but didn’t turn on. Open Plugins and choose Activate under Two Factor.', 'membuild-community' ), 500 );
		}
		return array_merge(
			self::status(),
			array(
				'state'   => 'on',
				'version' => get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'] ?? null,
			)
		);
	}

	/** Downloads Two Factor from WordPress.org, the way Plugins › Add New does. */
	private static function add_plugin() {
		$blocked = self::error( 'mbcom_two_step_blocked', __( 'Your host doesn’t let plugins be added from here. Add it by hand, then come back.', 'membuild-community' ), 409 );
		if ( ! self::can_add() ) {
			return $blocked;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => self::SLUG,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			return self::error( 'mbcom_two_step_add_failed', __( 'WordPress.org didn’t answer. Try again in a minute, or add it by hand.', 'membuild-community' ), 502 );
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$done     = $upgrader->install( $api->download_link );
		if ( true !== $done ) {
			return is_wp_error( $skin->get_errors() ) && $skin->get_errors()->has_errors() && 'fs_unavailable' === $skin->get_errors()->get_error_code() ? $blocked : self::error( 'mbcom_two_step_add_failed', __( 'Two Factor couldn’t be added. Try again, or add it by hand.', 'membuild-community' ), 500 );
		}
		wp_clean_plugins_cache();
		return true;
	}
}
