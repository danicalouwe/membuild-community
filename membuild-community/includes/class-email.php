<?php
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
 */
class Email {

	const CRON          = 'mbcom_send_emails';
	const ACTIVE_WINDOW = 10 * MINUTE_IN_SECONDS;
	const BATCH         = 50; // members handled per run, so a big community never times out
	const TIME_BUDGET   = 20; // seconds per run; slow SMTP servers stop early and continue next time
	const PREFS         = 'mbcom_email_prefs';
	const LAST_SENT     = 'mbcom_email_last';
	const LAST_ACTIVE   = 'mbcom_last_active';

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
	}

	public static function test() {
		$user = wp_get_current_user();
		$name = Settings::name();
		$html = self::layout(
			__( 'Test email', 'membuild-community' ),
			'<p style="margin:0 0 12px">' . esc_html__( 'If you can read this, community emails are working.', 'membuild-community' ) . '</p>'
				. '<p style="margin:0;color:#6b7280">' . esc_html( self::delivery_label() ) . '</p>',
			home_url( '/' . Settings::slug() . '/settings' ),
			__( 'Back to settings', 'membuild-community' ),
			''
		);
		/* translators: %s: community name */
		$result = self::send( $user->user_email, sprintf( __( 'Test email from %s', 'membuild-community' ), $name ), $html, __( 'If you can read this, community emails are working.', 'membuild-community' ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'mbcom_mail_failed', $result->get_error_message(), array( 'status' => 502 ) );
		}
		return array( 'sent' => true, 'to' => $user->user_email );
	}

	private static function delivery_label() {
		$mode = self::settings()['mode'];
		if ( 'resend' === $mode ) {
			return 'Sent through Resend.';
		}
		if ( 'smtp' === $mode ) {
			return 'Sent through your SMTP server.';
		}
		$plugin = self::smtp_plugin();
		return $plugin ? "Sent through {$plugin}." : 'Sent through your web host’s mail.';
	}

	/* ---------- Member preferences ---------- */

	public static function prefs( $user_id ) {
		$p = get_user_meta( (int) $user_id, self::PREFS, true );
		return array_merge(
			array( 'frequency' => 'instant', 'messages' => true, 'activity' => true ),
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
		foreach ( array( 'messages', 'activity' ) as $key ) {
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
				"SELECT DISTINCT user_id FROM %i WHERE type IN ('post_comment','comment_reply') AND read_at IS NULL AND seen_at IS NULL AND updated_at <= %s AND updated_at > %s
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
		if ( 'off' === $prefs['frequency'] ) {
			return false;
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
		$items  = array();

		if ( $prefs['activity'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE user_id = %d AND type IN ('post_comment','comment_reply') AND read_at IS NULL AND seen_at IS NULL AND updated_at > %s AND updated_at <= %s ORDER BY updated_at DESC LIMIT 10",
					Install::table( 'notifications' ),
					$user_id,
					$since,
					$cutoff
				),
				ARRAY_A
			);
			$actors = Helpers::users( array_merge( ...array_map( fn( $r ) => array_slice( array_map( 'intval', explode( ',', (string) $r['actor_ids'] ) ), 0, 2 ), $rows ?: array( array( 'actor_ids' => '' ) ) ) ) );
			foreach ( $rows as $r ) {
				$data    = json_decode( (string) $r['data'], true ) ?: array();
				$names   = array_values( array_filter( array_map( fn( $id ) => $actors[ (int) $id ]['name'] ?? null, array_slice( explode( ',', (string) $r['actor_ids'] ), 0, 2 ) ) ) );
				$who     = self::who( $names, (int) $r['actors_count'] );
				$what    = 'comment_reply' === $r['type'] ? __( 'replied to your comment', 'membuild-community' ) : __( 'commented on your post', 'membuild-community' );
				$items[] = array(
					'title' => "{$who} {$what}",
					'quote' => $data['comment'] ?? ( $data['snippet'] ?? '' ),
					'url'   => home_url( '/' . Settings::slug() . '/?post=' . (int) $r['post_id'] ),
				);
			}
		}

		$unread_chats = array();
		if ( $prefs['messages'] && Settings::feature( 'messages' ) ) {
			$unread_chats = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT m.thread_id, COUNT(*) AS n, MAX(m.id) AS last_id, SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT m.user_id ORDER BY m.id DESC), ",", 2) AS senders
					FROM %i tm JOIN %i m ON m.thread_id = tm.thread_id
					WHERE tm.user_id = %d AND tm.left_at IS NULL AND m.id > tm.last_read_id AND m.user_id <> %d AND m.status = \'sent\' AND m.created_at > %s AND m.created_at <= %s
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
			$senders = Helpers::users( array_merge( ...array_map( fn( $c ) => array_map( 'intval', explode( ',', $c['senders'] ) ), $unread_chats ?: array( array( 'senders' => '' ) ) ) ) );
			foreach ( $unread_chats as $c ) {
				$names = array_values( array_filter( array_map( fn( $id ) => $senders[ (int) $id ]['name'] ?? null, explode( ',', $c['senders'] ) ) ) );
				$n     = (int) $c['n'];
				$who   = $names ? wp_sprintf( '%l', $names ) : __( 'Someone', 'membuild-community' ); // "Ana and Ben", in the site's language
				if ( 1 === $n ) {
					/* translators: %s: who sent it */
					$title = sprintf( __( '%s sent you a message', 'membuild-community' ), $who );
				} else {
					/* translators: 1: who sent it, 2: number of messages */
					$title = sprintf( _n( '%1$s sent you %2$d message', '%1$s sent you %2$d messages', $n, 'membuild-community' ), $who, $n );
				}
				$items[] = array(
					'title' => $title,
					'quote' => '', // private messages are never copied into emails: email isn't private
					'url'   => home_url( '/' . Settings::slug() . '/messages/' . (int) $c['thread_id'] ),
				);
			}
		}

		// Mark the time either way, so the same items are never emailed twice.
		update_user_meta( $user_id, self::LAST_SENT, time() );
		if ( ! $items ) {
			return false;
		}

		$name    = Settings::name();
		$subject = 1 === count( $items )
			? wp_strip_all_tags( $items[0]['title'] )
			/* translators: 1: number of updates, 2: community name */
			: sprintf( __( '%1$d new updates in %2$s', 'membuild-community' ), count( $items ), $name );

		$rows_html = '';
		$rows_text = '';
		foreach ( $items as $it ) {
			$rows_html .= '<tr><td style="padding:12px 0;border-top:1px solid #eceef1">'
				. '<a href="' . esc_url( $it['url'] ) . '" style="color:#111214;font-weight:600;text-decoration:none">' . esc_html( $it['title'] ) . '</a>'
				. ( $it['quote'] ? '<div style="margin-top:4px;color:#5d6370">“' . esc_html( $it['quote'] ) . '”</div>' : '' )
				. '</td></tr>';
			$rows_text .= "• {$it['title']}" . ( $it['quote'] ? "\n  “{$it['quote']}”" : '' ) . "\n  {$it['url']}\n\n";
		}
		$unsub = self::unsubscribe_url( $user_id );
		$html  = self::layout(
			/* translators: %s: member's first name */
			sprintf( __( 'Hi %s, here’s what you missed', 'membuild-community' ), ( $user->first_name ?: $user->display_name ) ),
			'<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows_html . '</table>',
			home_url( '/' . Settings::slug() . '/' ),
			/* translators: %s: community name */
			sprintf( __( 'Open %s', 'membuild-community' ), $name ),
			$unsub
		);
		$text = $rows_text . "\n" . __( 'Stop these emails:', 'membuild-community' ) . ' ' . $unsub;

		$result = self::send(
			$user->user_email,
			$subject,
			$html,
			$text,
			array( 'List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' )
		);
		return true === $result;
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

	/** A simple email that reads well in every mail app: logo or initials, a heading, the body, one button. */
	private static function layout( $heading, $body, $button_url, $button_label, $unsubscribe_url ) {
		$name   = Settings::name();
		$accent = Settings::get( 'accent' ) ?: '#3b5bfd';
		$logo   = Settings::get( 'logo' )['url'] ?? null;
		$mark   = $logo
			? '<img src="' . esc_url( $logo ) . '" width="44" height="44" alt="" style="border-radius:10px;display:block">'
			: '<div style="width:44px;height:44px;border-radius:10px;background:' . esc_attr( $accent ) . ';color:#fff;font:800 17px/44px system-ui,sans-serif;text-align:center">' . esc_html( Settings::initials() ) . '</div>';
		$foot   = $unsubscribe_url
			? '<a href="' . esc_url( $unsubscribe_url ) . '" style="color:#8b909b">' . esc_html__( 'Unsubscribe from these emails', 'membuild-community' ) . '</a>'
			: '';
		return '<!doctype html><html><body style="margin:0;background:#f4f5f7;padding:24px 12px;font:15px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#16181d">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#fff;border-radius:14px;padding:24px">'
			. '<tr><td style="padding-bottom:16px">' . $mark . '</td></tr>'
			. '<tr><td><h1 style="margin:0 0 12px;font-size:20px">' . esc_html( $heading ) . '</h1>' . $body . '</td></tr>'
			. '<tr><td style="padding-top:20px"><a href="' . esc_url( $button_url ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#fff;font-weight:700;text-decoration:none;padding:11px 18px;border-radius:10px">' . esc_html( $button_label ) . '</a></td></tr>'
			. '</table>'
			. '<p style="max-width:520px;margin:14px auto 0;font-size:12px;color:#8b909b;text-align:center">' . esc_html( $name ) . ( $foot ? ' · ' . $foot : '' ) . '</p>'
			. '</td></tr></table></body></html>';
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
		if ( 'POST' !== Helpers::method() ) {
			$form = '<p>' . esc_html__( 'Stop getting community emails?', 'membuild-community' ) . '</p>'
				. '<form method="post" action="' . esc_url( self::unsubscribe_url( $user_id ) ) . '"><button type="submit" class="button button-primary">' . esc_html__( 'Unsubscribe', 'membuild-community' ) . '</button></form>';
			wp_die( wp_kses( $form, array( 'p' => array(), 'form' => array( 'method' => true, 'action' => true ), 'button' => array( 'type' => true, 'class' => true ) ) ), esc_html__( 'Unsubscribe', 'membuild-community' ), array( 'response' => 200 ) );
		}
		$p              = self::prefs( $user_id );
		$p['frequency'] = 'off';
		update_user_meta( $user_id, self::PREFS, $p );
		wp_die(
			esc_html__( 'You won’t get community emails any more. You can turn them back on from Edit profile.', 'membuild-community' ),
			esc_html__( 'Unsubscribed', 'membuild-community' ),
			array( 'response' => 200, 'link_url' => esc_url( home_url( '/' . Settings::slug() . '/me' ) ), 'link_text' => esc_html__( 'Open the community', 'membuild-community' ) )
		);
	}
}
