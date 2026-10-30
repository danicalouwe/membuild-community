<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Email notifications: new messages, comments on your posts and replies to
 * your comments, sent as one short email per batch instead of one per event.
 *
 * Delivery is chosen in Settings → Email:
 *   default  WordPress's own mail (the web host), or an SMTP plugin if one is installed
 *   resend   Resend, through its SMTP server, with just an API key
 *   smtp     any SMTP server
 * Our SMTP settings apply to community emails only; the rest of the site's mail is untouched.
 *
 * Members choose instant (checked every 15 minutes), a daily summary, or off.
 * Nobody is emailed about what they just saw: members active in the last
 * ACTIVE_WINDOW are skipped. Every email has a one-click unsubscribe link.
 *
 *   POST /settings/test-email    (admin) send a test to yourself
 *   GET  /me/email               your email preferences
 *   PUT  /me/email               { frequency: instant|daily|off, messages, activity }
 *   POST /me/email/confirm       send the welcome email (with its confirm button) again
 *
 * Soft confirm: someone who joins is in at once and gets a welcome email whose button
 * confirms the address. Until they press it, no activity emails go to them (password
 * resets always do), and after a few days Home shows a calm banner to send it again.
 */
class Email {

	const CRON          = 'mbcom_send_emails';
	const ACTIVE_WINDOW = 10 * MINUTE_IN_SECONDS;
	const BATCH         = 50; // members handled per run, so a big community never times out
	const TIME_BUDGET   = 20; // seconds per run; slow SMTP servers stop early and continue next time
	const PREFS         = 'mbcom_email_prefs';
	const LAST_SENT     = 'mbcom_email_last';
	const LAST_ACTIVE   = 'mbcom_last_active';
	const UNCONFIRMED   = 'mbcom_email_unconfirmed'; // set when someone joins; removed when they confirm
	const BANNER_AFTER  = 3 * DAY_IN_SECONDS;         // Home asks only after a few days

	/* ---------- Settings ---------- */

	public static function defaults() {
		return array(
			'enabled'    => true,
			'mode'       => 'default', // default | resend | smtp
			'host'       => '',
			'port'       => 587,
			'secure'     => 'tls', // tls | ssl | none
			'username'   => '',
			'password'   => '', // stored encrypted; never sent to the browser
			'from_name'  => '',
			'from_email' => '',
		);
	}

	public static function settings() {
		$saved = Settings::get( 'email' );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** For the settings form: everything except the password itself. */
	public static function public_settings() {
		$e                 = self::settings();
		$e['has_password'] = '' !== $e['password'];
		$e['password']     = '';
		return $e;
	}

	/** Validates the Email section of a settings save. An empty password keeps the stored one. */
	public static function sanitize( array $in, array $current ) {
		$e = array_merge( self::defaults(), $current );
		if ( array_key_exists( 'enabled', $in ) ) {
			$e['enabled'] = (bool) $in['enabled'];
		}
		if ( isset( $in['mode'] ) ) {
			$e['mode'] = in_array( $in['mode'], array( 'default', 'resend', 'smtp' ), true ) ? $in['mode'] : 'default';
		}
		foreach ( array( 'host', 'username', 'from_name' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$e[ $key ] = mb_substr( sanitize_text_field( (string) $in[ $key ] ), 0, 120 );
			}
		}
		if ( array_key_exists( 'port', $in ) ) {
			$e['port'] = max( 1, min( 65535, (int) $in['port'] ) );
		}
		if ( isset( $in['secure'] ) ) {
			$e['secure'] = in_array( $in['secure'], array( 'tls', 'ssl', 'none' ), true ) ? $in['secure'] : 'tls';
		}
		if ( array_key_exists( 'from_email', $in ) ) {
			$from = sanitize_email( (string) $in['from_email'] );
			if ( '' !== (string) $in['from_email'] && ! $from ) {
				return new \WP_Error( 'mbcom_invalid_setting', __( 'The “from” address isn’t a valid email.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			$e['from_email'] = $from;
		}
		if ( ! empty( $in['password'] ) ) {
			if ( Vault::paused() ) {
				return Vault::paused();
			}
			$e['password'] = Vault::secret( (string) $in['password'] );
		}
		if ( ! empty( $in['clear_password'] ) ) {
			$e['password'] = '';
		}
		if ( 'smtp' === $e['mode'] && '' === $e['host'] ) {
			return new \WP_Error( 'mbcom_invalid_setting', __( 'Add your SMTP server’s address.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		return $e;
	}

	/** An SMTP plugin the site already uses; WordPress's mail then goes through it. */
	public static function smtp_plugin() {
		if ( function_exists( 'wp_mail_smtp' ) ) {
			return 'WP Mail SMTP';
		}
		if ( defined( 'FLUENTMAIL' ) || defined( 'FLUENTMAIL_PLUGIN_FILE' ) ) {
			return 'FluentSMTP';
		}
		if ( defined( 'POST_SMTP_VER' ) ) {
			return 'Post SMTP';
		}
		return null;
	}

	/* Secrets (the SMTP password, the Bunny key) are encrypted with the site's own salts, so a database export doesn't reveal them. */

	/* ---------- Sending ---------- */

	/**
	 * Sends one community email through the chosen delivery.
	 *
	 * @return true|\WP_Error
	 */
	public static function send( $to, $subject, $html, $text, array $headers = array() ) {
		// Names come from WordPress with "&" stored as "&amp;": the subject and plain text are not HTML.
		$subject   = html_entity_decode( wp_strip_all_tags( $subject ), ENT_QUOTES, 'UTF-8' );
		$text      = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$e         = self::settings();
		$from_mail = $e['from_email'] ?: get_option( 'admin_email' );
		$from_name = $e['from_name'] ?: Settings::name();
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$headers[] = sprintf( 'From: %s <%s>', wp_specialchars_decode( $from_name, ENT_QUOTES ), $from_mail );

		$plain = function ( $mailer ) use ( $text ) {
			$mailer->AltBody = $text; // a plain-text version for mail apps that prefer it
		};
		$error = null;
		$fail  = function ( $err ) use ( &$error ) {
			$error = $err;
		};

		add_action( 'phpmailer_init', $plain );
		add_action( 'wp_mail_failed', $fail );
		$ok = self::through_delivery( fn() => wp_mail( $to, $subject, $html, $headers ) );
		remove_action( 'phpmailer_init', $plain );
		remove_action( 'wp_mail_failed', $fail );

		if ( $ok ) {
			return true;
		}
		return $error instanceof \WP_Error ? $error : new \WP_Error( 'mbcom_mail_failed', __( 'The email could not be sent.', 'membuild-community' ) );
	}

	/**
	 * Runs $send with the community's chosen email delivery (Resend or SMTP) in
	 * place, and its sender name and address. Used for community emails and for
	 * the account emails the community sends (password resets, new-account
	 * notices), which matter most to arrive. Other site email is left alone.
	 *
	 * @param callable $send Sends one or more emails with wp_mail().
	 * @return mixed What $send returned.
	 */
	public static function through_delivery( callable $send ) {
		$e     = self::settings();
		$smtp  = array( __CLASS__, 'configure_mailer' );
		$from  = fn( $address ) => $e['from_email'] ?: $address;
		$name  = fn( $sender ) => $e['from_name'] ?: Settings::name();
		$using = 'default' !== $e['mode'];
		if ( $using ) {
			add_action( 'phpmailer_init', $smtp, 999 );
		}
		add_filter( 'wp_mail_from', $from );
		add_filter( 'wp_mail_from_name', $name );
		try {
			return $send();
		} finally {
			remove_action( 'phpmailer_init', $smtp, 999 );
			remove_filter( 'wp_mail_from', $from );
			remove_filter( 'wp_mail_from_name', $name );
		}
	}

	/** Points PHPMailer at Resend or the configured SMTP server. */
	public static function configure_mailer( $mailer ) {
		$e = self::settings();
		$mailer->isSMTP();
		if ( 'resend' === $e['mode'] ) {
			$mailer->Host       = 'smtp.resend.com';
			$mailer->Port       = 465;
			$mailer->SMTPSecure = 'ssl';
			$mailer->Username   = 'resend';
		} else {
			$mailer->Host        = $e['host'];
			$mailer->Port        = (int) $e['port'];
			$mailer->SMTPSecure  = 'none' === $e['secure'] ? '' : $e['secure'];
			$mailer->SMTPAutoTLS = 'none' !== $e['secure'];
			$mailer->Username    = $e['username'];
		}
		$mailer->SMTPAuth = '' !== $mailer->Username;
		$mailer->Password = Vault::reveal( $e['password'] );
		$mailer->Timeout  = 15;
	}

	/* ---------- Routes ---------- */

	public static function register_routes() {
		$admin  = fn() => is_user_logged_in() && Helpers::can_manage();
		$member = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/settings/test-email', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test' ), 'permission_callback' => $admin ) );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/me/email',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_prefs' ), 'permission_callback' => $member ),
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'save_prefs' ), 'permission_callback' => $member ),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/me/email/confirm', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'resend_welcome' ), 'permission_callback' => $member ) );
	}

	public static function test() {
		$user = wp_get_current_user();
		$name = Settings::name();
		$how  = self::delivery_label();
		$spam = 'default' === self::settings()['mode']
			? __( 'Members’ emails may land there too. Choose Resend in Settings › Email.', 'membuild-community' )
			: __( 'Members’ emails may land there too. Check the sender settings in Settings › Email.', 'membuild-community' );
		$html = self::layout(
			__( 'Your emails are working', 'membuild-community' ),
			self::para( esc_html( $how ) )
				. '<div style="margin-top:16px;padding:14px 16px;border-radius:12px;background:#fff3d6;color:#7a4e00;font-size:16px;line-height:1.5"><strong>' . esc_html__( 'Found this in spam?', 'membuild-community' ) . '</strong> ' . esc_html( $spam ) . '</div>',
			home_url( '/' . Settings::slug() . '/settings?s=email' ),
			__( 'Back to Settings', 'membuild-community' ),
			'',
			/* translators: %s: community name */
			sprintf( __( '%s · sent from Settings › Email', 'membuild-community' ), $name )
		);
		$result = self::send( $user->user_email, __( 'Your community emails are working', 'membuild-community' ), $html, __( 'Your emails are working.', 'membuild-community' ) . ' ' . $how );
		if ( true === $result ) {
			update_option( 'mbcom_test_email_ok', time(), false ); // setup guide: emails were checked
		}
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'mbcom_mail_failed', $result->get_error_message(), array( 'status' => 502 ) );
		}
		return array( 'sent' => true, 'to' => $user->user_email );
	}

	private static function delivery_label() {
		$mode = self::settings()['mode'];
		if ( 'resend' === $mode ) {
			return __( 'This test came through Resend.', 'membuild-community' );
		}
		if ( 'smtp' === $mode ) {
			return __( 'This test came through your SMTP server.', 'membuild-community' );
		}
		$plugin = self::smtp_plugin();
		/* translators: %s: the name of an email plugin */
		return $plugin ? sprintf( __( 'This test came through %s.', 'membuild-community' ), $plugin ) : __( 'This test came through your web host’s mail.', 'membuild-community' );
	}

	/* ---------- Member preferences ---------- */

	public static function prefs( $user_id ) {
		$p = get_user_meta( (int) $user_id, self::PREFS, true );
		return array_merge(
			array( 'frequency' => 'instant', 'messages' => true, 'activity' => true, 'events' => true ), // events: reminders and changes for events you're going to
			is_array( $p ) ? $p : array()
		);
	}

	public static function get_prefs() {
		return self::prefs( get_current_user_id() ) + array( 'email' => wp_get_current_user()->user_email, 'enabled' => (bool) self::settings()['enabled'] );
	}

	public static function save_prefs( \WP_REST_Request $req ) {
		$p = self::prefs( get_current_user_id() );
		if ( isset( $req['frequency'] ) ) {
			$p['frequency'] = in_array( $req['frequency'], array( 'instant', 'daily', 'off' ), true ) ? $req['frequency'] : 'instant';
		}
		foreach ( array( 'messages', 'activity', 'events' ) as $key ) {
			if ( isset( $req[ $key ] ) ) {
				$p[ $key ] = (bool) $req[ $key ];
			}
		}
		update_user_meta( get_current_user_id(), self::PREFS, $p );
		return self::get_prefs();
	}

	/* ---------- Activity tracking ---------- */

	/** Called by the pulse: remembers when each member was last in the community (at most once a minute). */
	public static function touch( $user_id ) {
		$last = (int) get_user_meta( $user_id, self::LAST_ACTIVE, true );
		if ( time() - $last > MINUTE_IN_SECONDS ) {
			update_user_meta( $user_id, self::LAST_ACTIVE, time() );
		}
	}

	/* ---------- The batch ---------- */

	public static function register_hooks() {
		add_filter(
			'cron_schedules',
			function ( $s ) {
				$s['mbcom_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes' );
				return $s;
			}
		);
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'mbcom_15min', self::CRON );
				}
			}
		);
		add_action( 'template_redirect', array( __CLASS__, 'maybe_unsubscribe' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_confirm' ), 0 );
		// Anyone who joins (our join page, wp-admin, a membership plugin's checkout) gets the welcome email.
		add_action( 'user_register', array( __CLASS__, 'on_register' ), 20 );
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'reset_email' ), 10, 4 );
	}

	/* ---------- Welcome and soft confirm ---------- */

	/** A new account: mark the address as not confirmed yet and send the welcome email once the request is done. */
	public static function on_register( $user_id ) {
		if ( ! self::settings()['enabled'] || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return; // no community emails, or an import from the command line: nothing to confirm
		}
		update_user_meta( $user_id, self::UNCONFIRMED, time() );
		add_action( 'shutdown', fn() => self::send_welcome( (int) $user_id ) ); // after other plugins have saved the name
	}

	public static function is_confirmed( $user_id ) {
		return ! get_user_meta( (int) $user_id, self::UNCONFIRMED, true );
	}

	/** For the app: whether Home should show "Confirm your email" (only after a few days). */
	public static function banner( $user_id ) {
		$since = (int) get_user_meta( (int) $user_id, self::UNCONFIRMED, true );
		return $since && time() - $since > self::BANNER_AFTER && self::settings()['enabled'];
	}

	public static function confirm_url( $user_id ) {
		$user = get_userdata( $user_id );
		return add_query_arg( array( 'mbcom_confirm' => (int) $user_id, 'sig' => substr( hash_hmac( 'sha256', 'confirm|' . (int) $user_id . '|' . strtolower( $user ? $user->user_email : '' ), wp_salt( 'nonce' ) ), 0, 24 ) ), home_url( '/' ) );
	}

	/** The welcome email: three first steps, and the button that confirms their address. */
	public static function send_welcome( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return false;
		}
		$name  = Settings::name();
		$first = $user->first_name ?: $user->display_name;
		$about = Settings::get( 'login_text' ) ?: Settings::get( 'description' );
		$steps = array_filter(
			array(
				array( __( 'Say hello', 'membuild-community' ), __( 'Tell everyone what you’re working on in Home.', 'membuild-community' ) ),
				Settings::feature( 'classroom' ) ? array( __( 'Start a course', 'membuild-community' ), __( 'Your first lesson takes about 10 minutes.', 'membuild-community' ) ) : null,
				array( __( 'Add a photo', 'membuild-community' ), __( 'People reply more to a face.', 'membuild-community' ) ),
			)
		);
		$list = '';
		$text = '';
		foreach ( array_values( $steps ) as $i => $st ) {
			$list .= '<tr><td valign="top" style="width:28px;padding:0 12px 16px 0"><div style="width:28px;height:28px;border-radius:50%;background:#f2fbd9;color:#4d6b00;font-size:15px;font-weight:800;line-height:28px;text-align:center">' . ( $i + 1 ) . '</div></td>'
				. '<td valign="top" style="padding:2px 0 16px"><div style="font-size:17px;font-weight:700;color:#16181d">' . esc_html( $st[0] ) . '</div><div style="font-size:16px;color:#4a4f59">' . esc_html( $st[1] ) . '</div></td></tr>';
			$text .= ( $i + 1 ) . '. ' . $st[0] . ': ' . $st[1] . "\n";
		}
		$intro = $about
			/* translators: 1: community description */
			? esc_html( $about ) . ' ' . esc_html__( 'Here’s a good place to start:', 'membuild-community' )
			: esc_html__( 'Here’s a good place to start:', 'membuild-community' );
		$confirmed = self::is_confirmed( $user_id );
		$body      = self::para( $intro )
			. '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:20px">' . $list . '</table>'
			. ( $confirmed ? '' : '<div style="margin-top:4px;padding:14px 16px;border-radius:12px;background:#f2fbd9;color:#16181d;font-size:16px;line-height:1.5"><strong>' . esc_html__( 'One quick thing:', 'membuild-community' ) . '</strong> ' . esc_html__( 'press the button below so we know this email address is yours. Then we can send you updates when people reply to you.', 'membuild-community' ) . '</div>' );
		$url  = $confirmed ? Portal::url() : self::confirm_url( $user_id );
		$html = self::layout(
			/* translators: %s: member's first name */
			sprintf( __( 'Welcome, %s. You’re in!', 'membuild-community' ), $first ),
			$body,
			$url,
			$confirmed ? __( 'Open the community', 'membuild-community' ) : __( 'Confirm my email and open the community', 'membuild-community' ),
			'',
			/* translators: %s: community name */
			sprintf( __( 'You’re getting this because you joined %s.', 'membuild-community' ), $name )
		);
		/* translators: 1: community name, 2: member's first name */
		$subject = sprintf( __( 'Welcome to %1$s, %2$s', 'membuild-community' ), $name, $first );
		/* translators: %s: member's first name */
		$plain = sprintf( __( 'Welcome, %s. You’re in!', 'membuild-community' ), $first ) . "\n\n" . $text . "\n" . ( $confirmed ? '' : __( 'Confirm your email:', 'membuild-community' ) . ' ' ) . $url;
		return true === self::send( $user->user_email, $subject, $html, $plain );
	}

	/** "Send it again" on the Home banner: at most three an hour. */
	public static function resend_welcome() {
		$me   = get_current_user_id();
		$slow = Helpers::throttle( 'welcome', 3, HOUR_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		if ( self::is_confirmed( $me ) ) {
			return array( 'sent' => false, 'confirmed' => true );
		}
		$user = wp_get_current_user();
		if ( ! self::send_welcome( $me ) ) {
			return new \WP_Error( 'mbcom_mail_failed', __( 'The email could not be sent. Try again in a few minutes.', 'membuild-community' ), array( 'status' => 502 ) );
		}
		return array( 'sent' => true, 'to' => $user->user_email );
	}

	/** The welcome email's button: confirms the address, then opens the community. */
	public static function maybe_confirm() {
		if ( ! isset( $_GET['mbcom_confirm'], $_GET['sig'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- signed link
			return;
		}
		$user_id = (int) $_GET['mbcom_confirm']; // phpcs:ignore WordPress.Security.NonceVerification
		$parts   = wp_parse_args( wp_parse_url( self::confirm_url( $user_id ), PHP_URL_QUERY ) ?: '' );
		if ( get_userdata( $user_id ) && hash_equals( (string) ( $parts['sig'] ?? '' ), sanitize_text_field( wp_unslash( $_GET['sig'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_user_meta( $user_id, self::UNCONFIRMED );
		}
		wp_safe_redirect( Portal::url() );
		exit;
	}

	/** WordPress's password reset email, in the same look as every other community email. */
	public static function reset_email( $email, $key, $login, $user ) {
		if ( ! Login::enabled() ) {
			return $email;
		}
		$link  = Login::url( 'reset-password', array( 'login' => $login, 'key' => $key ) );
		$name  = Settings::name();
		$email['message'] = self::layout(
			__( 'Choose a new password', 'membuild-community' ),
			/* translators: %s: community name */
			self::para( esc_html( sprintf( __( 'Someone asked to reset the password for your %s account. If it was you, press the button. It works for 24 hours.', 'membuild-community' ), $name ) ) ),
			$link,
			__( 'Choose a new password', 'membuild-community' ),
			'',
			__( 'Didn’t ask for this? Ignore this email and your password stays the same.', 'membuild-community' ),
			esc_html__( 'Button not working? Copy this link:', 'membuild-community' ) . ' <a href="' . esc_url( $link ) . '" style="color:#4a4f59;word-break:break-all">' . esc_html( $link ) . '</a>'
		);
		/* translators: %s: community name */
		$email['subject'] = sprintf( __( 'Reset your %s password', 'membuild-community' ), $name );
		$email['headers'] = array_merge( (array) $email['headers'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		return $email;
	}

	/**
	 * One pass: find members with something new and unread since their last
	 * email, and send each of them a single summary.
	 */
	public static function run() {
		global $wpdb;
		if ( ! self::settings()['enabled'] ) {
			return 0;
		}
		// One run at a time: WordPress's own cron lock lasts a minute, and slow mail can outlast it.
		if ( ! add_option( 'mbcom_email_lock', time(), '', false ) ) {
			if ( (int) get_option( 'mbcom_email_lock' ) > time() - 10 * MINUTE_IN_SECONDS ) {
				return 0;
			}
			update_option( 'mbcom_email_lock', time(), false ); // a run that died long ago
		}
		$started = time();
		$notes   = Install::table( 'notifications' );
		$members = Install::table( 'thread_members' );
		$msgs    = Install::table( 'messages' );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::ACTIVE_WINDOW ); // give people a moment to see it live first
		// A week back is plenty: send_summary() only includes what's new since each member's last email,
		// and on quiet sites WordPress's scheduled tasks can run hours late.
		$recent  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		// Everyone with an unseen comment or reply, or an unread message, that is a few minutes old.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM %i WHERE type IN ('post_comment','comment_reply','event_reminder','event_changed','event_cancelled') AND read_at IS NULL AND seen_at IS NULL AND updated_at <= %s AND updated_at > %s
				UNION
				SELECT DISTINCT tm.user_id FROM %i tm JOIN %i m ON m.thread_id = tm.thread_id
				WHERE tm.left_at IS NULL AND m.id > tm.last_read_id AND m.user_id <> tm.user_id AND m.status = 'sent' AND m.created_at <= %s AND m.created_at > %s
				LIMIT 500",
				$notes,
				$cutoff,
				$recent,
				$members,
				$msgs,
				$cutoff,
				$recent
			)
		);

		$sent = 0;
		foreach ( $ids as $user_id ) {
			if ( $sent >= self::BATCH || time() - $started > self::TIME_BUDGET ) {
				break; // the rest go on the next run, well inside PHP's time limit
			}
			if ( self::send_summary( (int) $user_id ) ) {
				++$sent;
			}
		}
		delete_option( 'mbcom_email_lock' );
		return $sent;
	}

	/** Builds and sends one member's summary. Returns true when an email went out. */
	public static function send_summary( $user_id ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email || Moderation::is_banned( $user_id ) ) {
			return false;
		}
		$prefs = self::prefs( $user_id );
		if ( 'off' === $prefs['frequency'] || ! self::is_confirmed( $user_id ) ) {
			return false; // emails off, or an address nobody has confirmed yet
		}
		$last_sent = (int) get_user_meta( $user_id, self::LAST_SENT, true );
		if ( 'daily' === $prefs['frequency'] && time() - $last_sent < DAY_IN_SECONDS ) {
			return false;
		}
		if ( time() - (int) get_user_meta( $user_id, self::LAST_ACTIVE, true ) < self::ACTIVE_WINDOW ) {
			return false; // they're here right now and have seen it in the app
		}

		$since  = gmdate( 'Y-m-d H:i:s', $last_sent ?: 0 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::ACTIVE_WINDOW );
		$base   = home_url( '/' . Settings::slug() );
		$sections = array(); // each: html, text, count (updates it stands for)

		// Comments and replies, and event reminders, each as the member chose.
		$types = array_merge(
			$prefs['activity'] ? array( 'post_comment', 'comment_reply' ) : array(),
			$prefs['events'] && Settings::feature( 'events' ) ? array( 'event_reminder', 'event_changed', 'event_cancelled' ) : array()
		);
		if ( $types ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d AND type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND read_at IS NULL AND seen_at IS NULL AND updated_at > %s AND updated_at <= %s ORDER BY updated_at DESC LIMIT 10', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per type, built above.
					array_merge( array( Install::table( 'notifications' ), $user_id ), $types, array( $since, $cutoff ) )
				),
				ARRAY_A
			);
			$comments = Install::table( 'comments' );
			foreach ( $rows as $r ) {
				$data = json_decode( (string) $r['data'], true ) ?: array();
				$post = (int) $r['post_id'];
				if ( str_starts_with( $r['type'], 'event_' ) ) {
					$sections[] = self::event_section( $r['type'], $post, $data );
					continue;
				}
				if ( 'post_comment' === $r['type'] ) {
					// The newest few comments on their post, each as a bubble with the person's photo.
					$list  = Blocks::filter( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, content FROM %i WHERE post_id = %d AND user_id <> %d AND created_at > %s ORDER BY id DESC LIMIT 3', $comments, $post, $user_id, $since ), ARRAY_A ) );
					$users = Helpers::users( wp_list_pluck( $list, 'user_id' ) );
					$html  = '<p style="margin:0 0 12px;font-size:16px;color:#4a4f59">' . esc_html( sprintf( /* translators: %s: the start of the post */ __( 'On “%s”', 'membuild-community' ), $data['snippet'] ?? '' ) ) . '</p>';
					$text  = '';
					foreach ( array_reverse( $list ) as $c ) {
						$u     = $users[ (int) $c['user_id'] ] ?? null;
						$html .= self::bubble( $u, self::short( $c['content'] ) );
						$text .= '  ' . ( $u['name'] ?? '' ) . ': ' . self::short( $c['content'] ) . "\n";
					}
					$n          = max( 1, (int) $r['actors_count'] );
					$sections[] = array(
						'html'  => self::section( _n( 'New comment on your post', 'New comments on your post', $n, 'membuild-community' ), $html, $base . '/?post=' . $post, __( 'See all comments', 'membuild-community' ) ),
						'text'  => strtoupper( _n( 'New comment on your post', 'New comments on your post', $n, 'membuild-community' ) ) . "\n" . $text . '  ' . $base . '/?post=' . $post . "\n",
						'count' => max( 1, count( $list ) ),
						'title' => sprintf( /* translators: %s: who */ __( '%s commented on your post', 'membuild-community' ), self::who( array_column( $users, 'name' ), $n ) ),
					);
					continue;
				}
				// A reply: their own comment, faded, with the replies indented under it.
				$mine    = $wpdb->get_var( $wpdb->prepare( 'SELECT content FROM %i WHERE id = %d', $comments, $r['object_id'] ) );
				$list    = Blocks::filter( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, content FROM %i WHERE parent_id = %d AND user_id <> %d AND created_at > %s ORDER BY id DESC LIMIT 3', $comments, $r['object_id'], $user_id, $since ), ARRAY_A ) );
				$users   = Helpers::users( array_merge( array( $user_id ), wp_list_pluck( $list, 'user_id' ) ) );
				$me      = $users[ $user_id ] ?? null;
				$html    = $mine ? self::bubble( $me ? array( 'name' => __( 'You', 'membuild-community' ) ) + $me : null, self::short( $mine ), true ) : '';
				$text    = $mine ? '  ' . __( 'You', 'membuild-community' ) . ': ' . self::short( $mine ) . "\n" : '';
				foreach ( array_reverse( $list ) as $c ) {
					$u     = $users[ (int) $c['user_id'] ] ?? null;
					$html .= self::bubble( $u, self::short( $c['content'] ), false, true );
					$text .= '    ' . ( $u['name'] ?? '' ) . ': ' . self::short( $c['content'] ) . "\n";
				}
				$n          = max( 1, (int) $r['actors_count'] );
				$sections[] = array(
					'html'  => self::section( _n( 'A reply to your comment', 'Replies to your comment', $n, 'membuild-community' ), $html, $base . '/?post=' . $post, __( 'Reply', 'membuild-community' ) ),
					'text'  => strtoupper( _n( 'A reply to your comment', 'Replies to your comment', $n, 'membuild-community' ) ) . "\n" . $text . '  ' . $base . '/?post=' . $post . "\n",
					'count' => max( 1, count( $list ) ),
					'title' => sprintf( /* translators: %s: who */ __( '%s replied to your comment', 'membuild-community' ), self::who( array_column( array_diff_key( $users, array( $user_id => 1 ) ), 'name' ), $n ) ),
				);
			}
		}

		if ( $prefs['messages'] && Settings::feature( 'messages' ) ) {
			$unread_chats = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT m.thread_id, COUNT(*) AS n, MAX(m.id) AS last_id, SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT m.user_id ORDER BY m.id DESC), ",", 2) AS senders
					FROM %i tm JOIN %i m ON m.thread_id = tm.thread_id
					WHERE tm.user_id = %d AND tm.left_at IS NULL AND m.id > tm.last_read_id AND m.user_id <> %d AND m.status = \'sent\' AND m.created_at > %s AND m.created_at <= %s' . Rest_Messages::not_muted_sql( $user_id, 'm.thread_id' ) . '
					GROUP BY m.thread_id ORDER BY last_id DESC LIMIT 5',
					Install::table( 'thread_members' ),
					Install::table( 'messages' ),
					$user_id,
					$user_id,
					$since,
					$cutoff
				),
				ARRAY_A
			);
			if ( $unread_chats ) {
				$senders = Helpers::users( array_merge( ...array_map( fn( $c ) => array_map( 'intval', explode( ',', $c['senders'] ) ), $unread_chats ) ) );
				$html    = '';
				$text    = '';
				$total   = 0;
				$title   = '';
				foreach ( $unread_chats as $c ) {
					$ids   = array_map( 'intval', explode( ',', $c['senders'] ) );
					$names = array_values( array_filter( array_map( fn( $id ) => $senders[ $id ]['name'] ?? null, $ids ) ) );
					$n     = (int) $c['n'];
					$total += $n;
					$who   = $names ? wp_sprintf( '%l', $names ) : __( 'Someone', 'membuild-community' ); // "Ana and Ben", in the site's language
					/* translators: 1: who sent it, 2: number of messages */
					$line  = sprintf( _n( '%1$s sent you %2$d message', '%1$s sent you %2$d messages', $n, 'membuild-community' ), $who, $n );
					$title = $title ?: $line;
					// Only who and how many: private messages are never copied into emails (email isn't private).
					$html .= self::bubble( $senders[ $ids[0] ] ?? null, '', false, false, '<strong>' . esc_html( $who ) . '</strong> ' . esc_html( sprintf( _n( 'sent you %d message', 'sent you %d messages', $n, 'membuild-community' ), $n ) ) );
					$text .= '  ' . $line . "\n";
				}
				$html      .= '<p style="margin:2px 0 0 52px;font-size:14px;color:#6b7280">&#128274; ' . esc_html__( 'Messages are private, so we never copy them into emails.', 'membuild-community' ) . '</p>';
				$sections[] = array(
					'html'  => self::section( __( 'New messages', 'membuild-community' ), $html, $base . '/messages/' . (int) $unread_chats[0]['thread_id'], _n( 'Read it', 'Read them', $total, 'membuild-community' ) ),
					'text'  => strtoupper( __( 'New messages', 'membuild-community' ) ) . "\n" . $text . '  ' . $base . '/messages/' . (int) $unread_chats[0]['thread_id'] . "\n",
					'count' => count( $unread_chats ),
					'title' => $title,
				);
			}
		}

		// Mark the time either way, so the same items are never emailed twice.
		update_user_meta( $user_id, self::LAST_SENT, time() );
		$sections = array_values( array_filter( $sections ) );
		if ( ! $sections ) {
			return false;
		}

		$name    = Settings::name();
		$count   = array_sum( array_column( $sections, 'count' ) );
		$subject = 1 === count( $sections )
			? wp_strip_all_tags( $sections[0]['title'] )
			/* translators: 1: number of updates, 2: community name */
			: sprintf( _n( '%1$d new update in %2$s', '%1$d new updates in %2$s', $count, 'membuild-community' ), $count, $name );

		$unsub = self::unsubscribe_url( $user_id );
		$body  = '';
		foreach ( $sections as $i => $sec ) {
			$body .= '<div style="' . ( $i ? 'margin-top:24px;padding-top:24px;border-top:1px solid #eceef1' : 'margin-top:20px;padding-top:20px;border-top:1px solid #eceef1' ) . '">' . $sec['html'] . '</div>';
		}
		$html = self::layout(
			/* translators: %s: member's first name */
			sprintf( __( 'Hi %s, here’s what you missed', 'membuild-community' ), ( $user->first_name ?: $user->display_name ) ),
			$body,
			$base . '/',
			/* translators: %s: community name */
			sprintf( __( 'Open %s', 'membuild-community' ), $name ),
			$unsub,
			/* translators: %s: community name */
			sprintf( 'daily' === $prefs['frequency'] ? __( 'You’re a member of %s. You get one summary a day.', 'membuild-community' ) : __( 'You’re a member of %s. You get these as things happen.', 'membuild-community' ), $name )
		);
		$text = implode( "\n", array_column( $sections, 'text' ) ) . "\n" . __( 'Choose which emails you get:', 'membuild-community' ) . ' ' . $base . '/me/emails' . "\n" . __( 'Stop these emails:', 'membuild-community' ) . ' ' . $unsub;

		$result = self::send(
			$user->user_email,
			$subject,
			$html,
			$text,
			array( 'List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' )
		);
		return true === $result;
	}

	/* ---------- Pieces of the activity email ---------- */

	/** One section: a small grey heading, its content, then a "See all ›" link. */
	private static function section( $label, $html, $url, $link ) {
		return '<p style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#6b7280">' . esc_html( $label ) . '</p>'
			. $html
			. '<p style="margin:14px 0 0"><a href="' . esc_url( $url ) . '" style="color:#16181d;font-size:16px;font-weight:700;text-decoration:underline">' . esc_html( $link ) . ' &#8250;</a></p>';
	}

	/**
	 * A chat bubble with the person's photo, like in the community. $faded for your own
	 * comment above a reply; $indent for a reply under it; $raw for a ready-escaped line.
	 */
	private static function bubble( $user, $words, $faded = false, $indent = false, $raw = '' ) {
		$photo = $user && ! empty( $user['avatar'] )
			? '<img src="' . esc_url( $user['avatar'] ) . '" width="36" height="36" alt="' . esc_attr( $user['name'] ?? '' ) . '" style="display:block;width:36px;height:36px;border-radius:50%">'
			: '<div style="width:36px;height:36px;border-radius:50%;background:#e6e9ee"></div>';
		$inner = $raw
			? '<div style="font-size:16px;line-height:1.45;color:#16181d">' . $raw . '</div>'
			: '<div style="font-size:15px;font-weight:700;color:' . ( $faded ? '#6b7280' : '#16181d' ) . '">' . esc_html( $user['name'] ?? __( 'A member', 'membuild-community' ) ) . '</div>'
				. '<div style="margin-top:2px;font-size:16px;line-height:1.45;color:' . ( $faded ? '#6b7280' : '#16181d' ) . '">' . esc_html( $words ) . '</div>';
		$bg    = $raw ? '#f2fbd9' : ( $faded ? '#f6f7f9' : '#f0f2f5' );
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 10px ' . ( $indent ? '44px' : '0' ) . '"><tr>'
			. '<td valign="top" style="width:36px;padding-right:12px">' . $photo . '</td>'
			. '<td valign="top" style="background:' . $bg . ';border-radius:18px;padding:10px 14px">' . $inner . '</td>'
			. '</tr></table>';
	}

	/** An event reminder, a new time or a cancellation, as a date card. */
	private static function event_section( $type, $post_id, array $data ) {
		global $wpdb;
		$e = $wpdb->get_row( $wpdb->prepare( 'SELECT event_title, event_at, event_minutes, event_where, event_venue, event_tz FROM %i WHERE id = %d', Install::table( 'posts' ), $post_id ), ARRAY_A );
		if ( ! $e || ! $e['event_at'] ) {
			return null;
		}
		$tz    = $e['event_tz'] ? new \DateTimeZone( $e['event_tz'] ) : wp_timezone();
		$start = strtotime( $e['event_at'] . ' UTC' );
		$end   = $start + 60 * (int) $e['event_minutes'];
		$label = match ( $type ) {
			'event_changed'   => __( 'New time for an event', 'membuild-community' ),
			'event_cancelled' => __( 'Event cancelled', 'membuild-community' ),
			default           => ! empty( $data['soon'] ) ? __( 'Event starting soon', 'membuild-community' ) : __( 'Event tomorrow', 'membuild-community' ),
		};
		$where = 'online' === $e['event_where'] ? __( 'Online, link on the event page', 'membuild-community' ) : ( $e['event_venue'] ?: __( 'In person', 'membuild-community' ) );
		/* translators: 1: start time, 2: end time */
		$when  = sprintf( __( '%1$s to %2$s', 'membuild-community' ), wp_date( get_option( 'time_format' ), $start, $tz ), wp_date( get_option( 'time_format' ) . ' T', $end, $tz ) );
		$gone  = 'event_cancelled' === $type;
		$card  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e6ea;border-radius:14px"><tr>'
			. '<td valign="middle" style="width:66px;padding:14px 0 14px 14px">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" style="width:66px;border:1px solid #e3e6ea;border-radius:10px;text-align:center">'
			. '<tr><td style="background:' . esc_attr( self::accent() ) . ';color:' . esc_attr( self::ink() ) . ';font-size:12px;font-weight:800;padding:3px 0;border-radius:9px 9px 0 0">' . esc_html( strtoupper( wp_date( 'M', $start, $tz ) ) ) . '</td></tr>'
			. '<tr><td style="font-size:24px;font-weight:800;line-height:1;padding:6px 0 2px;color:#16181d">' . esc_html( wp_date( 'j', $start, $tz ) ) . '</td></tr>'
			. '<tr><td style="font-size:11px;font-weight:700;color:#6b7280;padding:0 0 6px">' . esc_html( strtoupper( wp_date( 'D', $start, $tz ) ) ) . '</td></tr>'
			. '</table></td>'
			. '<td valign="middle" style="padding:14px 16px">'
			. '<div style="font-size:17px;font-weight:700;color:#16181d;' . ( $gone ? 'text-decoration:line-through' : '' ) . '">' . esc_html( $e['event_title'] ) . '</div>'
			. '<div style="margin-top:4px;font-size:15px;color:#4a4f59">' . esc_html( $gone ? __( 'This event won’t go ahead.', 'membuild-community' ) : $when . ' · ' . $where ) . '</div>'
			. '</td></tr></table>';
		$url   = Events::url( $post_id );
		return array(
			'html'  => self::section( $label, $card, $url, __( 'See the event', 'membuild-community' ) ),
			'text'  => strtoupper( $label ) . "\n  " . $e['event_title'] . ( $gone ? '' : "\n  " . $when . ' · ' . $where ) . "\n  " . $url . "\n",
			'count' => 1,
			/* translators: 1: what happened ("Event tomorrow"), 2: event title */
			'title' => sprintf( __( '%1$s: %2$s', 'membuild-community' ), $label, $e['event_title'] ),
		);
	}

	/** Words for a bubble: plain text, one paragraph, at most about 200 characters. */
	private static function short( $text ) {
		$text = Search::plain( (string) $text );
		return mb_strlen( $text ) > 200 ? rtrim( mb_substr( $text, 0, 190 ) ) . '…' : $text;
	}

	private static function who( array $names, $count ) {
		if ( ! $names ) {
			return __( 'Someone', 'membuild-community' );
		}
		if ( $count <= 1 ) {
			return $names[0];
		}
		$others = $count - count( $names );
		if ( $others <= 0 ) {
			return wp_sprintf( '%l', $names );
		}
		/* translators: 1: a name, 2: how many more people */
		return sprintf( _n( '%1$s and %2$d other', '%1$s and %2$d others', $others, 'membuild-community' ), implode( ', ', $names ), $others );
	}

	/* ---------- Template ---------- */

	private static function accent() {
		return Color::tokens( Settings::get( 'accent' ) )['accent'];
	}

	private static function ink() {
		return Color::tokens( Settings::get( 'accent' ) )['ink'];
	}

	/**
	 * One layout for every email: the community's logo (or initials) and name, one white card
	 * with a 24px heading, 17px words and one pill button in the community's colour, then a
	 * footer saying why it came and, for activity emails, Choose which emails you get · Unsubscribe.
	 * Tables and inline styles, system fonts, so it reads the same in every mail app
	 * (Outlook for Windows just shows square corners).
	 *
	 * @param string $why   The footer line ("You're a member of …"). Empty: just the community name.
	 * @param string $after Small lines under the card, already escaped (the reset link written out).
	 */
	public static function layout( $heading, $body, $button_url, $button_label, $unsubscribe_url, $why = '', $after = '' ) {
		$name   = Settings::name();
		$accent = self::accent();
		$logo   = Settings::get( 'logo' )['url'] ?? null;
		$font   = '-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif';
		$mark   = $logo
			? '<img src="' . esc_url( $logo ) . '" width="40" height="40" alt="' . esc_attr( $name ) . '" style="display:block;width:40px;height:40px;border-radius:10px">'
			: '<div style="width:40px;height:40px;border-radius:10px;background:' . esc_attr( $accent ) . ';color:' . esc_attr( self::ink() ) . ';font:800 15px/40px ' . $font . ';text-align:center">' . esc_html( Settings::initials() ) . '</div>';
		$links  = $unsubscribe_url
			? '<br><a href="' . esc_url( home_url( '/' . Settings::slug() . '/me/emails' ) ) . '" style="color:#4a4f59;font-weight:700">' . esc_html__( 'Choose which emails you get', 'membuild-community' ) . '</a> &nbsp;·&nbsp; <a href="' . esc_url( $unsubscribe_url ) . '" style="color:#4a4f59;font-weight:700">' . esc_html__( 'Unsubscribe', 'membuild-community' ) . '</a>'
			: '';
		$button = $button_url
			? '<tr><td style="padding-top:28px"><a href="' . esc_url( $button_url ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:' . esc_attr( self::ink() ) . ';font-size:17px;font-weight:700;text-decoration:none;padding:15px 28px;border-radius:999px">' . esc_html( $button_label ) . '</a></td></tr>'
			: '';
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>'
			. '<body style="margin:0;background:#f3f4f6;padding:28px 12px;font:17px/1.55 ' . $font . ';color:#16181d">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
			. '<tr><td style="padding:0 4px 16px"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td>' . $mark . '</td><td style="padding-left:12px;font-size:17px;font-weight:700;color:#16181d">' . esc_html( $name ) . '</td></tr></table></td></tr>'
			. '<tr><td style="background:#ffffff;border-radius:16px;padding:32px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
			. '<tr><td><h1 style="margin:0;font-size:24px;line-height:1.3;color:#16181d">' . esc_html( $heading ) . '</h1>' . $body . '</td></tr>'
			. $button
			. '</table></td></tr>'
			. '<tr><td style="padding:18px 8px 0;font-size:14px;line-height:1.7;color:#6b7280;text-align:center">' . esc_html( $why ?: $name ) . $links . ( $after ? '<br>' . $after : '' ) . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** A paragraph in the card: 17px, grey-black, the space above built in. */
	public static function para( $text, $tone = '#4a4f59' ) {
		return '<p style="margin:14px 0 0;font-size:17px;line-height:1.55;color:' . $tone . '">' . $text . '</p>';
	}

	/* ---------- Unsubscribe ---------- */

	public static function unsubscribe_url( $user_id ) {
		return add_query_arg( array( 'mbcom_unsubscribe' => (int) $user_id, 'sig' => self::sign( $user_id ) ), home_url( '/' ) );
	}

	private static function sign( $user_id ) {
		return substr( hash_hmac( 'sha256', 'unsubscribe|' . (int) $user_id, wp_salt( 'nonce' ) ), 0, 24 );
	}

	/**
	 * No login needed: the signed link turns emails off for that member.
	 * Opening the link shows a button and only a POST unsubscribes, so mail
	 * scanners that open links can't unsubscribe anyone; mail apps' one-click
	 * unsubscribe (RFC 8058) POSTs to the same address.
	 */
	public static function maybe_unsubscribe() {
		if ( ! isset( $_GET['mbcom_unsubscribe'], $_GET['sig'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- signed link
			return;
		}
		$user_id = (int) $_GET['mbcom_unsubscribe']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! hash_equals( self::sign( $user_id ), sanitize_text_field( wp_unslash( $_GET['sig'] ) ) ) || ! get_userdata( $user_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_die( esc_html__( 'This unsubscribe link isn’t valid.', 'membuild-community' ), '', array( 'response' => 400 ) );
		}
		$prefs = self::prefs( $user_id );
		$url   = self::unsubscribe_url( $user_id );
		if ( 'POST' === Helpers::method() ) {
			// "daily" and "on" come from the page's buttons; anything else (a mail app's one-click
			// unsubscribe, or Stop all community emails) turns emails off.
			$do                 = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- signed link
			$prefs['frequency'] = 'daily' === $do ? 'daily' : ( 'on' === $do ? 'instant' : 'off' );
			update_user_meta( $user_id, self::PREFS, $prefs );
			self::unsubscribe_page( 'done', $prefs['frequency'], $url );
		}
		// Opening the link only shows the choices (mail scanners open links); a POST changes anything.
		self::unsubscribe_page( 'off' === $prefs['frequency'] ? 'done' : 'ask', $prefs['frequency'], $url );
	}

	/**
	 * The unsubscribe pages, in the community's own look (its logo, name and main colour),
	 * no login: "ask" offers a daily summary before stopping; "done" says what changed.
	 */
	private static function unsubscribe_page( $step, $frequency, $url ) {
		$name    = Settings::name();
		$accent  = Settings::get( 'accent' ) ?: '#c8f542';
		$rgb     = sscanf( ltrim( $accent, '#' ), '%02x%02x%02x' );
		$ink     = ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) > 150 ? '#16181d' : '#ffffff'; // readable on the main colour
		$logo    = Settings::get( 'logo' )['url'] ?? '';
		$home    = home_url( '/' . Settings::slug() . '/' );
		$choose  = home_url( '/' . Settings::slug() . '/me/emails' );
		$button  = fn( $do, $label, $main ) => '<form method="post" action="' . esc_url( $url ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><button type="submit" class="btn' . ( $main ? ' main' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		if ( 'ask' === $step ) {
			/* translators: %s: community name */
			$title = sprintf( __( 'Fewer emails from %s?', 'membuild-community' ), $name );
			$body  = '<h1>' . esc_html( $title ) . '</h1><p>'
				. esc_html( 'daily' === $frequency ? __( 'You’re getting one summary a day. Pick what suits you.', 'membuild-community' ) : __( 'You’re getting an email each time something happens. Pick what suits you.', 'membuild-community' ) ) . '</p>'
				. ( 'daily' === $frequency ? '' : $button( 'daily', __( 'Send me one summary a day instead', 'membuild-community' ), true ) )
				. $button( 'off', __( 'Stop all community emails', 'membuild-community' ), 'daily' === $frequency )
				/* translators: %s: a link, "choose exactly which emails you get" */
				. '<p class="or">' . sprintf( esc_html__( 'or %s', 'membuild-community' ), '<a href="' . esc_url( $choose ) . '">' . esc_html__( 'choose exactly which emails you get', 'membuild-community' ) . '</a>' ) . '</p>';
		} else {
			$off   = 'off' === $frequency;
			$title = $off ? __( 'Done. No more community emails.', 'membuild-community' ) : ( 'daily' === $frequency ? __( 'Done. One summary a day from now on.', 'membuild-community' ) : __( 'Emails are back on.', 'membuild-community' ) );
			$body  = '<span class="tick" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>'
				. '<h1>' . esc_html( $title ) . '</h1><p>'
				. esc_html( $off ? __( 'You’ll still see everything under the bell when you visit. Password reset emails still arrive.', 'membuild-community' ) : __( 'You can change this any time in Emails you get.', 'membuild-community' ) ) . '</p>'
				. '<a class="btn main" href="' . esc_url( $home ) . '">' . esc_html__( 'Open the community', 'membuild-community' ) . '</a>'
				. ( $off ? $button( 'on', __( 'Changed my mind, turn emails back on', 'membuild-community' ), false ) : '' );
		}
		$mark = $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : '<span class="mark">' . esc_html( Settings::initials() ) . '</span>';
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title . ' · ' . $name ) . '</title><style>'
			. 'body{margin:0;background:#f3f4f6;color:#16181d;font:16px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}'
			. '.wrap{max-width:560px;margin:90px auto;padding:0 16px;display:flex;flex-direction:column;align-items:center;gap:22px}'
			. '.brand{display:flex;align-items:center;gap:12px;font-size:20px;font-weight:800}.brand img{width:48px;height:48px;border-radius:12px;object-fit:cover}'
			. '.mark{display:grid;place-items:center;width:48px;height:48px;border-radius:12px;background:' . esc_attr( $accent ) . ';color:' . $ink . ';font-size:17px}'
			. '.card{box-sizing:border-box;width:100%;display:flex;flex-direction:column;gap:16px;padding:34px 34px 30px;border:1px solid #e3e6ea;border-radius:20px;background:#fff;box-shadow:0 8px 24px rgba(16,24,40,.06)}'
			. 'h1{margin:0;font-size:26px;line-height:1.25}p{margin:0;color:#4a4f59;font-size:17px;line-height:1.55}form{margin:0}'
			. '.btn{display:flex;box-sizing:border-box;width:100%;height:54px;align-items:center;justify-content:center;border:0;border-radius:999px;background:#e6e9ee;color:#16181d;font-family:inherit;font-size:17px;font-weight:700;line-height:1;text-decoration:none;cursor:pointer}'
			. '.btn.main{background:' . esc_attr( $accent ) . ';color:' . $ink . '}'
			. '.or{text-align:center;font-size:16px}.or a{color:#16181d;font-weight:700}'
			. '.tick{display:grid;place-items:center;width:60px;height:60px;border-radius:50%;background:#e3f5ea;color:#1f7a49}'
			. '@media(max-width:600px){.wrap{margin:40px auto}.card{padding:26px 20px 22px}h1{font-size:23px}}'
			. '</style></head><body><main class="wrap"><div class="brand">' . $mark . esc_html( $name ) . '</div><div class="card">' . $body . '</div></main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every piece is escaped where it is built.
		exit;
	}
}
