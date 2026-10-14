<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The community's own sign-up, login, forgot-password and reset-password
 * pages, in the Facebook style: /community/join, /login, /forgot-password and
 * /reset-password. They work through WordPress itself (wp_insert_user,
 * wp_signon, retrieve_password, reset_password) and fire core's hooks, so
 * other plugins' login, registration and anti-spam checks still run.
 *
 * Sign-up is open exactly when WordPress's own "Anyone can register" setting
 * is on. wp-login.php keeps working; Settings → "Community login page" turns
 * these pages off for sites whose security plugin needs the standard ones.
 */
class Login {

	const PAGES             = array( 'login', 'join', 'forgot-password', 'reset-password' );
	const MIN_PASSWORD      = 8;
	const MAX_FAILS         = 10;  // failed logins per address …
	const MAX_ACCOUNT_FAILS = 20;  // … or per account, from anywhere …
	const FAIL_WINDOW       = 900; // … in 15 minutes
	const MAX_RESETS        = 5;   // reset emails per address per hour
	const MAX_SIGNUPS       = 5;   // new accounts per address per hour
	const AGE_COOKIE        = 'mbcom_age_check'; // set after a too-young answer, so the form can't simply be retried

	public static function register_hooks() {
		add_filter( 'login_url', array( __CLASS__, 'login_url' ), 10, 2 );
		// Early, so a plugin with its own account pages (WooCommerce's My Account) keeps its link; the community's pages link to their own.
		add_filter( 'lostpassword_url', array( __CLASS__, 'lostpassword_url' ), 5, 2 );
		add_filter( 'logout_redirect', array( __CLASS__, 'logout_redirect' ), 10, 2 );
		add_filter( 'register_url', array( __CLASS__, 'register_url' ) );
		add_filter( 'retrieve_password_title', array( __CLASS__, 'reset_email_title' ), 10, 1 );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'reset_email_message' ), 10, 4 );
	}

	public static function enabled() {
		return (bool) Settings::get( 'login_page' );
	}

	/** Whether people can create their own account: WordPress's "Anyone can register" (or the network's setting). */
	public static function registration_open() {
		return is_multisite()
			? in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true )
			: (bool) get_option( 'users_can_register' );
	}

	/** Where "Create new account" goes: the community's sign-up page, the owner's own link, or nowhere. */
	public static function signup_link() {
		if ( self::registration_open() ) {
			return self::enabled() ? self::url( 'join' ) : wp_registration_url();
		}
		return (string) Settings::get( 'signup_url' );
	}

	public static function register_url( $url ) {
		return self::enabled() && self::registration_open() ? self::url( 'join' ) : $url;
	}

	public static function url( $page = 'login', array $args = array() ) {
		return add_query_arg( array_map( 'rawurlencode', $args ), home_url( '/' . Settings::slug() . '/' . $page ) );
	}

	/* ---------- Pointing WordPress at these pages ---------- */

	public static function login_url( $url, $redirect ) {
		if ( ! self::enabled() ) {
			return $url;
		}
		return self::url( 'login', $redirect ? array( 'redirect_to' => $redirect ) : array() );
	}

	public static function lostpassword_url( $url ) {
		return self::enabled() ? self::url( 'forgot-password' ) : $url;
	}

	/** Logging out lands on the login page, unless the link asked for somewhere else. */
	public static function logout_redirect( $to, $requested ) {
		if ( ! self::enabled() || ( $requested && home_url( '/' ) !== $requested && home_url() !== $requested ) ) {
			return $to;
		}
		return self::url( 'login', array( 'loggedout' => '1' ) );
	}

	/* ---------- The reset email links here instead of wp-login.php ---------- */

	public static function reset_email_title( $title ) {
		/* translators: %s: community name */
		return self::enabled() ? sprintf( __( 'Reset your password for %s', 'membuild-community' ), Settings::name() ) : $title;
	}

	public static function reset_email_message( $message, $key, $login, $user ) {
		if ( ! self::enabled() ) {
			return $message;
		}
		$link = self::url( 'reset-password', array( 'login' => $login, 'key' => $key ) );
		/* translators: %s: community name */
		return sprintf( __( 'Someone asked to reset the password for your account on %s.', 'membuild-community' ), Settings::name() ) . "\r\n\r\n"
			. __( 'If this was you, choose a new password here:', 'membuild-community' ) . "\r\n" . $link . "\r\n\r\n"
			. __( 'The link works for 24 hours. If you didn’t ask for this, ignore this email and your password stays the same.', 'membuild-community' ) . "\r\n";
	}

	/* ---------- The pages ---------- */

	/** Called by Portal for /community/{page}. Returns false when the path isn't a login page. */
	public static function maybe_handle( $path ) {
		if ( ! in_array( $path, self::PAGES, true ) ) {
			return false;
		}
		if ( ! self::enabled() ) {
			wp_safe_redirect( 'login' === $path ? wp_login_url() : ( 'forgot-password' === $path ? wp_lostpassword_url() : site_url( 'wp-login.php?action=lostpassword' ) ) );
			exit;
		}
		Helpers::no_page_cache();
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: no-referrer' ); // the reset link carries a key

		if ( 'login' === $path ) {
			self::login_page();
		} elseif ( 'join' === $path ) {
			self::join_page();
		} elseif ( 'forgot-password' === $path ) {
			self::forgot_page();
		} else {
			self::reset_page();
		}
		exit;
	}

	private static function redirect_target() {
		// phpcs:ignore WordPress.Security.NonceVerification -- only read to decide where to go next, validated below
		$requested = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		return $requested ? wp_validate_redirect( $requested, Portal::url() ) : '';
	}

	private static function login_page() {
		$redirect = self::redirect_target();
		if ( is_user_logged_in() ) {
			wp_safe_redirect( $redirect ?: Portal::url() );
			exit;
		}

		$error   = '';
		$notice  = '';
		$login   = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices
		if ( isset( $_GET['loggedout'] ) ) {
			$notice = __( 'You’ve logged out.', 'membuild-community' );
		} elseif ( isset( $_GET['reset'] ) ) {
			$notice = __( 'Your password was changed. Log in with your new password.', 'membuild-community' );
		}
		// phpcs:enable

		if ( 'POST' === Helpers::method() ) {
			$login = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
			if ( ! isset( $_POST['_mbcom_login'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_mbcom_login'] ), 'mbcom_login' ) ) {
				$error = __( 'The page timed out. Please try again.', 'membuild-community' );
			} elseif ( self::too_many( 'fail', self::MAX_FAILS ) || self::too_many( 'fail_' . strtolower( $login ), self::MAX_ACCOUNT_FAILS ) ) {
				$error = __( 'Too many tries. Wait a few minutes, then try again.', 'membuild-community' );
			} else {
				$user = wp_signon(
					array(
						'user_login'    => $login,
						'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not altered
						'remember'      => true, // like Facebook: stay logged in on this device
					),
					is_ssl()
				);
				if ( is_wp_error( $user ) ) {
					// Counted per address and per account, so one shared address (a proxy) can't lock everyone out alone.
					self::count( 'fail', self::FAIL_WINDOW );
					self::count( 'fail_' . strtolower( $login ), self::FAIL_WINDOW );
					// One message for every failure, so the form doesn't reveal which emails have accounts.
					$error = '' === $login || empty( $_POST['pwd'] )
						? __( 'Enter your email or username and your password.', 'membuild-community' )
						: __( 'The email, username or password you entered isn’t right.', 'membuild-community' );
				} else {
					$to = $redirect ?: apply_filters( 'login_redirect', admin_url(), '', $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
					wp_safe_redirect( $to ?: Portal::url() );
					exit;
				}
			}
		}

		self::render(
			'login',
			array(
				'error'    => $error,
				'notice'   => $notice,
				'login'    => $login,
				'redirect' => $redirect,
			)
		);
	}

	/* ---------- Sign-up ---------- */

	private static function join_page() {
		if ( is_user_logged_in() ) {
			wp_safe_redirect( Portal::url() );
			exit;
		}
		if ( ! self::registration_open() ) {
			$elsewhere = (string) Settings::get( 'signup_url' );
			wp_redirect( $elsewhere ? $elsewhere : self::url( 'login' ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the admin's own sign-up link (e.g. a membership plugin's checkout), possibly on another site
			exit;
		}
		$min_age = (int) Settings::get( 'min_age' );
		$values  = array( 'first' => '', 'last' => '', 'email' => '' );
		$error   = '';
		$blocked = $min_age && isset( $_COOKIE[ self::AGE_COOKIE ] );

		if ( ! $blocked && 'POST' === Helpers::method() ) {
			if ( ! isset( $_POST['_mbcom_join'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_mbcom_join'] ), 'mbcom_join' ) ) {
				$error = __( 'The page timed out. Please try again.', 'membuild-community' );
			} else {
				$values = array(
					'first' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
					'last'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
					'email' => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				);
				$pass   = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not altered
				$born   = array( isset( $_POST['birth_month'] ) ? absint( $_POST['birth_month'] ) : 0, isset( $_POST['birth_year'] ) ? absint( $_POST['birth_year'] ) : 0 );
				$result = self::create_account( $values, $pass, $born, $min_age, ! empty( $_POST['website'] ) );
				if ( is_wp_error( $result ) ) {
					$error   = $result->get_error_message();
					$blocked = 'mbcom_too_young' === $result->get_error_code();
				} else {
					wp_safe_redirect( Portal::url() );
					exit;
				}
			}
		}
		self::render(
			'join',
			array(
				'error'   => $error,
				'values'  => $values,
				'min_age' => $min_age,
				'blocked' => $blocked,
				'terms'   => (string) Settings::get( 'terms_url' ),
				'privacy' => get_privacy_policy_url(),
			)
		);
	}

	/**
	 * Checks a sign-up and creates the account, then signs the new member in.
	 *
	 * @param array    $values  first, last, email (sanitized).
	 * @param string   $pass    The chosen password.
	 * @param int[]    $born    Birth month and year (0 when not asked).
	 * @param int      $min_age 0 when there's no minimum.
	 * @param bool     $trapped The hidden "website" field was filled in (a bot).
	 * @return int|\WP_Error The new user ID.
	 */
	private static function create_account( array $values, $pass, array $born, $min_age, $trapped ) {
		if ( $trapped || self::too_many( 'join', self::MAX_SIGNUPS ) ) {
			return new \WP_Error( 'mbcom_slow_down', __( 'Too many tries. Wait a while, then try again.', 'membuild-community' ) );
		}
		if ( '' === $values['first'] ) {
			return new \WP_Error( 'mbcom_name', __( 'Enter your first name.', 'membuild-community' ) );
		}
		if ( ! is_email( $values['email'] ) ) {
			return new \WP_Error( 'mbcom_email', __( 'Enter a valid email address.', 'membuild-community' ) );
		}
		if ( email_exists( $values['email'] ) ) {
			return new \WP_Error( 'mbcom_email_taken', __( 'There’s already an account with this email. Log in, or reset your password if you’ve forgotten it.', 'membuild-community' ) );
		}
		if ( mb_strlen( $pass ) < self::MIN_PASSWORD ) {
			/* translators: %d: minimum number of characters */
			return new \WP_Error( 'mbcom_password', sprintf( __( 'Use at least %d characters for your password.', 'membuild-community' ), self::MIN_PASSWORD ) );
		}
		if ( $min_age ) {
			list( $month, $year ) = $born;
			if ( $month < 1 || $month > 12 || $year < 1900 ) {
				return new \WP_Error( 'mbcom_birthday', __( 'Choose the month and year you were born.', 'membuild-community' ) );
			}
			if ( self::age( $month, $year ) < $min_age ) {
				// A neutral answer that can't simply be changed and resent (as the FTC's COPPA guidance suggests).
				setcookie( self::AGE_COOKIE, '1', time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
				return new \WP_Error( 'mbcom_too_young', __( 'Sorry, you can’t create an account right now.', 'membuild-community' ) );
			}
		}
		self::count( 'join', HOUR_IN_SECONDS );

		$login  = self::unique_login( $values['email'] );
		$errors = new \WP_Error();
		do_action( 'register_post', $login, $values['email'], $errors ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
		$errors = apply_filters( 'registration_errors', $errors, $login, $values['email'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, so anti-spam plugins can refuse a sign-up
		if ( $errors->has_errors() ) {
			return new \WP_Error( 'mbcom_refused', wp_strip_all_tags( $errors->get_error_message() ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $values['email'],
				'user_pass'    => $pass,
				'first_name'   => $values['first'],
				'last_name'    => $values['last'],
				'display_name' => trim( $values['first'] . ' ' . $values['last'] ),
				'role'         => self::signup_role(),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new \WP_Error( 'mbcom_signup_failed', __( 'Your account couldn’t be created. Please try again.', 'membuild-community' ) );
		}
		if ( $min_age ) {
			update_user_meta( $user_id, 'mbcom_age_screened', $min_age ); // the minimum they passed, never their birthday
		}
		Email::through_delivery( fn() => wp_new_user_notification( $user_id, null, 'admin' ) ); // tell the site admin, as WordPress does
		wp_signon( array( 'user_login' => $login, 'user_password' => $pass, 'remember' => true ), is_ssl() );
		return $user_id;
	}

	/** Age in whole years, counting from the last day of the birth month so nobody is let in early. */
	private static function age( $month, $year ) {
		$now      = getdate( current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- the site's local date is what counts for a birthday
		$last_day = (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) );
		$had      = $now['mon'] > $month || ( $now['mon'] === $month && $now['mday'] >= $last_day );
		return $now['year'] - $year - ( $had ? 0 : 1 );
	}

	/** A username made from the email address, unique on this site. */
	private static function unique_login( $email ) {
		$base  = sanitize_user( strstr( $email, '@', true ), true ) ?: 'member';
		$login = $base;
		for ( $n = 2; username_exists( $login ); $n++ ) {
			$login = $base . $n;
		}
		return $login;
	}

	/**
	 * The role new members get: WordPress's default role, unless that role could
	 * edit other people's content or run the site (a risky setting for open sign-up).
	 */
	private static function signup_role() {
		$role = get_role( (string) get_option( 'default_role', 'subscriber' ) );
		return $role && ! $role->has_cap( 'edit_others_posts' ) && ! $role->has_cap( 'manage_options' ) && ! $role->has_cap( 'promote_users' ) ? $role->name : 'subscriber';
	}

	private static function forgot_page() {
		$error = '';
		$sent  = '';
		$value = '';
		if ( 'POST' === Helpers::method() ) {
			$value = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
			if ( ! isset( $_POST['_mbcom_forgot'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_mbcom_forgot'] ), 'mbcom_forgot' ) ) {
				$error = __( 'The page timed out. Please try again.', 'membuild-community' );
			} elseif ( '' === $value ) {
				$error = __( 'Enter your email or username.', 'membuild-community' );
			} elseif ( self::too_many( 'reset', self::MAX_RESETS ) ) {
				$error = __( 'Too many tries. Wait a while, then try again.', 'membuild-community' );
			} else {
				self::count( 'reset', HOUR_IN_SECONDS );
				$user = is_email( $value ) ? get_user_by( 'email', $value ) : get_user_by( 'login', $value );
				if ( $user && ! Moderation::is_banned( $user->ID ) ) {
					Email::through_delivery( fn() => retrieve_password( $user->user_login ) ); // through Resend/SMTP when set up
				}
				// The same answer either way, so nobody can test which emails have accounts.
				$sent = $value;
			}
		}
		self::render( 'forgot', array( 'error' => $error, 'sent' => $sent, 'value' => $value ) );
	}

	private static function reset_page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the reset key is the proof here; the form also carries a nonce
		$login = isset( $_REQUEST['login'] ) ? sanitize_user( wp_unslash( $_REQUEST['login'] ) ) : '';
		$key   = isset( $_REQUEST['key'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) ) : '';
		// phpcs:enable
		$user  = check_password_reset_key( $key, $login );
		$error = '';

		if ( is_wp_error( $user ) ) {
			self::render( 'expired', array() );
			return;
		}

		if ( 'POST' === Helpers::method() ) {
			if ( ! isset( $_POST['_mbcom_reset'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_mbcom_reset'] ), 'mbcom_reset' ) ) {
				$error = __( 'The page timed out. Please try again.', 'membuild-community' );
			} else {
				$pass  = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords are not altered
				$error = self::reset_error( $pass, $user );
			}
			if ( '' === $error ) {
				reset_password( $user, $pass );
				// Like Facebook, the new password logs you straight in.
				$signed = wp_signon( array( 'user_login' => $user->user_login, 'user_password' => $pass, 'remember' => true ), is_ssl() );
				wp_safe_redirect( is_wp_error( $signed ) ? self::url( 'login', array( 'reset' => '1' ) ) : Portal::url() );
				exit;
			}
		}
		self::render( 'reset', array( 'error' => $error, 'login' => $login, 'key' => $key, 'name' => $user->display_name ) );
	}

	/** Why a new password can't be used, or '' when it can. Password-policy plugins add rules through the core hook. */
	private static function reset_error( $pass, \WP_User $user ) {
		if ( mb_strlen( $pass ) < self::MIN_PASSWORD ) {
			/* translators: %d: minimum number of characters */
			return sprintf( __( 'Use at least %d characters.', 'membuild-community' ), self::MIN_PASSWORD );
		}
		if ( false !== stripos( $pass, $user->user_login ) ) {
			return __( 'Choose a password that doesn’t include your username.', 'membuild-community' );
		}
		$errors = new \WP_Error();
		do_action( 'validate_password_reset', $errors, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
		return $errors->has_errors() ? wp_strip_all_tags( $errors->get_error_message() ) : '';
	}

	/* ---------- Attempt limits (per address and per account) ---------- */

	private static function bucket( $kind ) {
		if ( str_starts_with( $kind, 'fail_' ) ) {
			return 'mbcom_fail_acct_' . md5( substr( $kind, 5 ) ); // one account, from any address
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'mbcom_' . $kind . '_' . md5( $ip );
	}

	private static function too_many( $kind, $max ) {
		return (int) get_transient( self::bucket( $kind ) ) >= $max;
	}

	private static function count( $kind, $window ) {
		$key = self::bucket( $kind );
		set_transient( $key, (int) get_transient( $key ) + 1, $window );
	}

	/* ---------- Output ---------- */

	private static function render( $view, array $data ) {
		status_header( 200 );
		self::styles();
		$name     = Settings::name();
		$tagline  = (string) Settings::get( 'description' );
		$logo     = Settings::get( 'logo' )['url'] ?? '';
		$initials = Settings::initials();
		$signup   = self::signup_link();
		$icons    = App::icons();
		include MEMBUILD_COMMUNITY_DIR . 'templates/login.php';
	}

	/** Registers the page's stylesheet and script, with the accent tokens inline. */
	private static function styles() {
		$c = Color::tokens( Settings::get( 'accent' ) );
		// The file's own date in the address, so a page cache or CDN never serves an older copy after an update.
		$ver = fn( $file ) => MEMBUILD_COMMUNITY_VERSION . '.' . (int) @filemtime( MEMBUILD_COMMUNITY_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file just means no date
		wp_register_style( 'mbcom-login', MEMBUILD_COMMUNITY_URL . 'assets/login/login.css', array(), $ver( 'assets/login/login.css' ) );
		wp_add_inline_style(
			'mbcom-login',
			":root{--accent:{$c['accent']};--accent-ink:{$c['ink']};--accent-text:{$c['text']}}"
			. "@media (prefers-color-scheme: dark){:root{--accent:{$c['dark']};--accent-ink:{$c['dark_ink']};--accent-text:{$c['dark_text']}}}"
		);
		wp_register_script( 'mbcom-login', MEMBUILD_COMMUNITY_URL . 'assets/login/login.js', array(), $ver( 'assets/login/login.js' ), array( 'in_footer' => true ) );
	}
}
