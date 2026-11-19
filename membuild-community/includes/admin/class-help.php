<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The Help tab: a message to MemBuild (a problem, an idea or kind words), the
 * messages sent before with MemBuild's answers, and a way to support the plugin.
 *
 * Messages live on tools.membuild.com, numbered like #1042. This site keeps only
 * a random secret that lets it read its own messages back. Screenshots go
 * straight from the upload to MemBuild and are never saved on this site.
 */
class Help {

	const SECRET = 'mbcom_help_secret';
	const CACHE  = 'mbcom_help_tickets';
	const MAX    = 4000;
	const SHOTS  = 3;
	const BYTES  = 5 * MB_IN_BYTES;
	const PAYPAL = 'https://paypal.me/louwewds';
	const LOOM   = '~^https://(www\.)?loom\.com/share/[A-Za-z0-9]+([?/#].*)?$~';

	public static function register_hooks() {
		add_action( 'admin_post_mbcom_help_send', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_mbcom_help_reply', array( __CLASS__, 'handle_reply' ) );
		add_action( 'admin_post_mbcom_help_solve', array( __CLASS__, 'handle_solve' ) );
		add_action( 'admin_post_mbcom_help_shot', array( __CLASS__, 'handle_shot' ) );
	}

	/** The most one screenshot may weigh here: 5 MB, or less when this host takes less. */
	public static function max_shot() {
		return (int) min( self::BYTES, wp_max_upload_size() );
	}

	/** The most one whole message may weigh, from the host's post_max_size (0 = no limit). */
	private static function max_post() {
		return (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
	}

	/** "5 MB", "2 MB": the screenshot limit in words. */
	private static function shot_words() {
		return size_format( self::max_shot() );
	}

	public static function kinds() {
		return array(
			'bug'  => __( 'Something’s not working', 'membuild-community' ),
			'idea' => __( 'I have an idea', 'membuild-community' ),
			'nice' => __( 'Say something nice', 'membuild-community' ),
		);
	}

	/* ---------- Talking to tools.membuild.com ---------- */

	/** The secret that lets this site read its own messages back; made once, here. */
	private static function secret() {
		$s = (string) get_option( self::SECRET, '' );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $s ) ) {
			$s = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET, $s, false );
		}
		return $s;
	}

	/**
	 * One call to MemBuild. $files: array of array( path, name, type ) sent as shots[].
	 * Returns the decoded answer, or array( ok => false, message ) when it can't be reached.
	 */
	private static function call( $action, array $fields, array $files = array(), $raw = false ) {
		if ( ! Usage::allowed() ) {
			return array( 'ok' => false, 'message' => __( 'This site is set not to contact MemBuild.', 'membuild-community' ) );
		}
		$fields += array( 'install_id' => Usage::state()['id'], 'secret' => self::secret() );
		$args = array(
			'timeout' => $files ? 45 : 12,
			'headers' => array( 'User-Agent' => 'MemBuild-Community/' . MEMBUILD_COMMUNITY_VERSION ),
		);
		if ( $files ) {
			$boundary                       = 'mbcom' . wp_generate_password( 24, false );
			$args['headers']['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$body                           = '';
			foreach ( $fields as $k => $v ) {
				$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
			}
			foreach ( $files as list( $path, $name, $type ) ) {
				$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"shots[]\"; filename=\"" . str_replace( array( '"', "\r", "\n" ), '', $name ) . "\"\r\nContent-Type: {$type}\r\n\r\n" . file_get_contents( $path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the upload's own temporary file
			}
			$args['body'] = $body . "--{$boundary}--\r\n";
		} else {
			$args['body'] = $fields;
		}
		$res = wp_remote_post( Usage::ENDPOINT . '?action=' . $action, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => __( 'MemBuild couldn’t be reached just now. Please try again in a minute.', 'membuild-community' ) );
		}
		if ( $raw ) {
			return 200 === wp_remote_retrieve_response_code( $res ) ? wp_remote_retrieve_body( $res ) : '';
		}
		$out = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $out ) ) {
			return array( 'ok' => false, 'message' => __( 'MemBuild couldn’t be reached just now. Please try again in a minute.', 'membuild-community' ) );
		}
		return $out;
	}

	/** This site's messages, newest first; kept for a few minutes. Null when they couldn't be loaded. */
	private static function tickets( $fresh = false ) {
		if ( ! get_option( self::SECRET ) ) {
			return array(); // never sent one
		}
		$cached = $fresh ? false : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = self::call( 'help_list', array() );
		if ( empty( $out['ok'] ) || ! is_array( $out['tickets'] ?? null ) ) {
			return null;
		}
		set_transient( self::CACHE, $out['tickets'], 5 * MINUTE_IN_SECONDS );
		return $out['tickets'];
	}

	private static function status_pill( $status ) {
		if ( 'answered' === $status ) {
			return Admin::pill( 'fine', __( 'Answered', 'membuild-community' ) );
		}
		return 'solved' === $status ? Admin::pill( 'note', __( 'Solved', 'membuild-community' ) ) : Admin::pill( 'watch', __( 'Waiting', 'membuild-community' ) );
	}

	private static function when( $iso, $with_time = false ) {
		$ts = strtotime( (string) $iso );
		return $ts ? wp_date( $with_time ? 'j M, ' . get_option( 'time_format' ) : 'j M', $ts ) : '';
	}

	/* ---------- The page ---------- */

	public static function page() {
		$number = isset( $_GET['ticket'] ) ? absint( $_GET['ticket'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- which message to show
		if ( $number ) {
			self::thread( $number );
			return;
		}
		Admin::shell_open( 'help', __( 'Help', 'membuild-community' ) );
		$sent = isset( $_GET['sent'] ) ? absint( $_GET['sent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- display only
		echo '<div class="mbcom-help">';
		if ( $sent ) {
			printf(
				'<div class="mbwa-card mbwa-card-pad mbcom-sent"><span><span class="mbwa-strong">%s</span> <span class="mbwa-muted">%s</span></span>%s</div>',
				/* translators: %s: ticket number, e.g. 1043 */
				esc_html( sprintf( __( 'Sent. Your ticket number is #%s.', 'membuild-community' ), $sent ) ),
				esc_html__( 'Danica usually answers within a day. You’ll get an email with the same number.', 'membuild-community' ),
				Admin::link( Admin::url( 'help', array( 'ticket' => $sent ) ), __( 'View message', 'membuild-community' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
			);
		} else {
			self::form();
		}
		echo '<div class="mbcom-help-side">';
		self::mine();
		self::donate();
		echo '</div></div>';
		Admin::shell_close();
	}

	/** What was typed last time, and what was wrong with it, after a refused send. */
	private static function kept() {
		$k = get_transient( 'mbcom_help_form_' . get_current_user_id() );
		delete_transient( 'mbcom_help_form_' . get_current_user_id() );
		return is_array( $k ) ? $k : array();
	}

	private static function form() {
		$kept    = self::kept();
		$errors  = $kept['errors'] ?? array();
		$kind    = $kept['kind'] ?? 'bug';
		$message = $kept['message'] ?? '';
		$problem = isset( $_GET['problem'] ) ? sanitize_key( wp_unslash( $_GET['problem'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- prefills the form
		if ( '' === $message && '' !== $problem ) {
			$message = Problems::as_text( $problem );
		}
		$user = wp_get_current_user();
		echo '<form class="mbwa-card mbcom-help-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php?action=mbcom_help_send' ) ) . '" data-max-shot="' . esc_attr( self::max_shot() ) . '" data-max-post="' . esc_attr( self::max_post() ) . '" novalidate>';
		wp_nonce_field( 'mbcom_help_send' );
		echo '<input type="hidden" name="action" value="mbcom_help_send">';
		echo '<div class="mbwa-card-bar"><h2 class="mbwa-card-title">' . esc_html__( 'Send a message to MemBuild', 'membuild-community' ) . '</h2></div><div class="mbcom-help-body">';
		if ( ! empty( $errors['send'] ) ) {
			echo '<div class="mbcom-err" role="alert">' . esc_html( $errors['send'] ) . '</div>';
		}

		echo '<fieldset class="mbcom-field"><legend>' . esc_html__( 'What is it about?', 'membuild-community' ) . '</legend><div class="mbwa-filters">';
		foreach ( self::kinds() as $value => $label ) {
			printf( '<label class="mbwa-filter mbcom-kind"><input type="radio" name="kind" value="%s"%s>%s</label>', esc_attr( $value ), checked( $kind, $value, false ), esc_html( $label ) );
		}
		echo '</div></fieldset>';

		printf(
			'<div class="mbcom-field"><label for="mbcom-help-message">%s</label><textarea id="mbcom-help-message" name="message" rows="6" maxlength="%d" required%s>%s</textarea>%s</div>',
			esc_html__( 'Your message', 'membuild-community' ),
			(int) self::MAX,
			! empty( $errors['message'] ) ? ' aria-invalid="true" aria-describedby="mbcom-help-message-err"' : '',
			esc_textarea( $message ),
			! empty( $errors['message'] ) ? '<div class="mbcom-err" id="mbcom-help-message-err">' . esc_html( $errors['message'] ) . '</div>' : ''
		);

		echo '<div class="mbcom-show" data-kinds="bug idea">';
		printf(
			'<div class="mbcom-prompt"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/></svg><div><div class="mbwa-strong">%s</div><div class="mbwa-muted">%s %s</div></div></div>',
			esc_html__( 'Show us what you see', 'membuild-community' ),
			esc_html__( 'A Loom video or a screenshot helps us fix it faster.', 'membuild-community' ),
			Admin::link( 'https://support.loom.com/hc/en-us/articles/360002158057', __( 'How to record a Loom', 'membuild-community' ), true, 'mbcom-lnk mbcom-lnk-plain' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
		);
		printf(
			'<div class="mbcom-field"><label for="mbcom-help-loom">%s <span class="mbwa-muted">%s</span></label><input class="mbwa-input mbwa-input-wide" type="text" id="mbcom-help-loom" name="loom" value="%s" placeholder="https://www.loom.com/share/…" inputmode="url"%s>%s</div>',
			esc_html__( 'Loom link', 'membuild-community' ),
			esc_html__( 'optional', 'membuild-community' ),
			esc_attr( $kept['loom'] ?? '' ),
			! empty( $errors['loom'] ) ? ' aria-invalid="true" aria-describedby="mbcom-help-loom-err"' : '',
			'<div class="mbcom-err" id="mbcom-help-loom-err"' . ( empty( $errors['loom'] ) ? ' hidden' : '' ) . '>' . esc_html( $errors['loom'] ?? __( 'That isn’t a Loom link. It should start with loom.com/share', 'membuild-community' ) ) . '</div>'
		);
		printf(
			'<div class="mbcom-field"><span class="mbcom-label" id="mbcom-shots-label">%s <span class="mbwa-muted">%s</span></span><div class="mbcom-drop"><span class="mbcom-shots" aria-live="polite"></span><label class="mbwa-btn mbcom-add-shot">%s<input type="file" name="shots[]" accept="image/png,image/jpeg,image/webp" multiple aria-labelledby="mbcom-shots-label"></label><span class="mbwa-muted">%s</span></div><div class="mbcom-err" id="mbcom-shots-err"%s>%s</div></div>',
			esc_html__( 'Screenshots', 'membuild-community' ),
			/* translators: %d: how many screenshots at most */
			esc_html( sprintf( __( 'optional, up to %d', 'membuild-community' ), self::SHOTS ) ),
			esc_html__( 'Add a screenshot', 'membuild-community' ),
			/* translators: %s: size limit, e.g. 5 MB */
			esc_html( sprintf( __( 'PNG, JPG or WebP, %s each', 'membuild-community' ), self::shot_words() ) ),
			empty( $errors['shots'] ) ? ' hidden' : '',
			esc_html( $errors['shots'] ?? '' )
		);
		echo '</div>';

		printf(
			'<div class="mbcom-show" data-kinds="nice"><label class="mbcom-check"><input type="checkbox" name="share" value="1"%s>%s</label></div>',
			checked( ! empty( $kept['share'] ), true, false ),
			esc_html__( 'MemBuild may share this on membuild.com, with my first name and community name', 'membuild-community' )
		);

		printf(
			'<div class="mbcom-help-foot"><span class="mbwa-muted">%s</span><button type="submit" class="mbwa-btn mbwa-btn-primary">%s</button></div>',
			/* translators: 1: the admin's email, 2: plugin version, 3: WordPress version */
			esc_html( sprintf( __( 'Sent with it: your email (%1$s), your site address, MB Community %2$s, WordPress %3$s', 'membuild-community' ), $user->user_email, MEMBUILD_COMMUNITY_VERSION, get_bloginfo( 'version' ) ) ),
			esc_html__( 'Send', 'membuild-community' )
		);
		echo '</div></form>';
	}

	private static function mine() {
		$tickets = self::tickets();
		echo '<div class="mbwa-card mbcom-mine"><div class="mbwa-card-bar"><h2 class="mbwa-card-title">' . esc_html__( 'Your messages', 'membuild-community' ) . '</h2></div>';
		if ( null === $tickets ) {
			echo '<div class="mbcom-pad mbwa-muted">' . esc_html__( 'Your messages couldn’t be loaded just now.', 'membuild-community' ) . '</div></div>';
			return;
		}
		if ( ! $tickets ) {
			echo '<div class="mbcom-pad mbwa-muted">' . esc_html__( 'Messages you send show here, with MemBuild’s answers.', 'membuild-community' ) . '</div></div>';
			return;
		}
		$kinds = array(
			'bug'  => __( 'Problem', 'membuild-community' ),
			'idea' => __( 'Idea', 'membuild-community' ),
			'nice' => __( 'Kind words', 'membuild-community' ),
		);
		foreach ( array_slice( array_filter( $tickets, 'is_array' ), 0, 10 ) as $t ) {
			printf(
				'<div class="mbwa-trow mbcom-ticket-row"><div><a class="mbwa-strong mbcom-one mbcom-quiet-link" href="%s">%s</a><div class="mbwa-muted mbcom-one">%s</div></div><div>%s</div></div>',
				esc_url( Admin::url( 'help', array( 'ticket' => (int) ( $t['number'] ?? 0 ) ) ) ),
				esc_html( (string) ( $t['subject'] ?? '' ) ),
				esc_html( '#' . (int) ( $t['number'] ?? 0 ) . ' · ' . ( $kinds[ $t['kind'] ?? '' ] ?? '' ) . ' · ' . self::when( $t['created'] ?? '' ) ),
				self::status_pill( (string) ( $t['status'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill()
			);
		}
		echo '</div>';
	}

	private static function donate() {
		printf(
			'<div class="mbwa-card mbwa-card-pad"><h2 class="mbwa-card-title">%s</h2><p class="mbcom-donate-text">%s</p><a class="mbwa-btn" href="%s" target="_blank" rel="noopener" title="%s">%s%s</a></div>',
			esc_html__( 'Support this plugin', 'membuild-community' ),
			esc_html__( 'MB Community is free. If it helps your business, you can buy Danica a coffee.', 'membuild-community' ),
			esc_url( self::PAYPAL ),
			esc_attr__( 'Opens paypal.me/louwewds in a new tab', 'membuild-community' ),
			esc_html__( 'Buy me a coffee', 'membuild-community' ),
			Admin::EXT // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg
		);
	}

	/** One message and its replies, with a reply box. */
	private static function thread( $number ) {
		$tickets = self::tickets( true );
		$t       = null;
		foreach ( (array) $tickets as $row ) {
			if ( is_array( $row ) && (int) ( $row['number'] ?? 0 ) === $number ) {
				$t = $row;
			}
		}
		$crumb = '<a class="mbcom-lnk" href="' . esc_url( Admin::url( 'help' ) ) . '">' . esc_html__( 'Help', 'membuild-community' ) . '</a> <span class="mbwa-muted mbcom-crumb" aria-hidden="true">›</span> ';
		if ( ! $t ) {
			Admin::shell_open( 'help', '', '', '', Admin::title_row( $crumb . esc_html( '#' . $number ) ) );
			echo '<div class="mbwa-card mbwa-card-pad mbwa-muted">' . esc_html( null === $tickets ? __( 'Your messages couldn’t be loaded just now.', 'membuild-community' ) : __( 'This message wasn’t found. It may have been sent from another site.', 'membuild-community' ) ) . '</div>';
			Admin::shell_close();
			return;
		}
		Admin::shell_open( 'help', '', '', '', Admin::title_row( $crumb . esc_html( (string) ( $t['subject'] ?? '' ) ) ) );
		$me = wp_get_current_user();
		echo '<div class="mbwa-card mbcom-thread">';
		printf(
			'<div class="mbwa-card-bar"><h2 class="mbwa-card-title">%s <span class="mbwa-muted mbcom-plain">· %s</span></h2><span class="mbcom-grow"></span>%s</div>',
			esc_html( (string) ( $t['subject'] ?? '' ) ),
			/* translators: %d: ticket number */
			esc_html( sprintf( __( 'Ticket #%d', 'membuild-community' ), $number ) ),
			self::status_pill( (string) ( $t['status'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill()
		);
		foreach ( array_filter( (array) ( $t['messages'] ?? array() ), 'is_array' ) as $i => $m ) {
			$ours = 'membuild' === ( $m['from'] ?? '' );
			$face = $ours
				? '<span class="mbcom-mb-av">' . Admin::mark() . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				: '<img src="' . esc_url( get_avatar_url( $me->ID, array( 'size' => 64 ) ) ) . '" width="32" height="32" alt="">';
			echo '<div class="mbcom-msg' . ( $ours ? ' mbcom-msg-ours' : '' ) . '">' . $face . '<div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above
			printf( '<div class="mbcom-msg-head"><span class="mbwa-strong">%s</span><span class="mbwa-muted">%s</span></div>', esc_html( $ours ? __( 'Danica from MemBuild', 'membuild-community' ) : __( 'You', 'membuild-community' ) ), esc_html( self::when( $m['at'] ?? '', true ) ) );
			echo '<div class="mbcom-msg-text">' . wpautop( esc_html( (string) ( $m['text'] ?? '' ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then paragraphs
			if ( ! empty( $m['loom'] ) && preg_match( self::LOOM, (string) $m['loom'] ) ) {
				printf(
					'<div class="mbcom-loom"><span class="mbcom-loom-play" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span><div><div class="mbwa-strong">%s</div>%s</div></div>',
					esc_html__( 'Loom video', 'membuild-community' ),
					Admin::link( (string) $m['loom'], __( 'Watch on Loom', 'membuild-community' ), true, 'mbcom-lnk mbcom-lnk-plain' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
				);
			}
			$shots = min( self::SHOTS, (int) ( $m['shots'] ?? 0 ) );
			if ( $shots ) {
				echo '<div class="mbcom-shots-row">';
				for ( $n = 0; $n < $shots; $n++ ) {
					$src = wp_nonce_url( add_query_arg( array( 'action' => 'mbcom_help_shot', 'ticket' => $number, 'm' => $i, 'n' => $n ), admin_url( 'admin-post.php' ) ), 'mbcom_help_shot' );
					/* translators: %d: screenshot number */
					printf( '<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="%2$s" loading="lazy"></a>', esc_url( $src ), esc_attr( sprintf( __( 'Screenshot %d', 'membuild-community' ), $n + 1 ) ) );
				}
				echo '</div>';
			}
			echo '</div></div>';
		}
		$kept = get_transient( 'mbcom_help_reply_' . get_current_user_id() );
		delete_transient( 'mbcom_help_reply_' . get_current_user_id() );
		echo '<form class="mbcom-reply" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		if ( is_array( $kept ) ) {
			echo '<div class="mbcom-err" role="alert">' . esc_html( $kept['error'] ) . '</div>';
		}
		wp_nonce_field( 'mbcom_help_reply' );
		printf(
			'<input type="hidden" name="action" value="mbcom_help_reply"><input type="hidden" name="ticket" value="%d"><label class="screen-reader-text" for="mbcom-reply">%s</label><textarea id="mbcom-reply" name="message" rows="4" maxlength="%d" placeholder="%s" required>%s</textarea>',
			(int) $number,
			esc_html__( 'Write a reply', 'membuild-community' ),
			(int) self::MAX,
			esc_attr__( 'Write a reply', 'membuild-community' ),
			esc_textarea( is_array( $kept ) ? (string) $kept['message'] : '' )
		);
		echo '<div class="mbcom-help-foot">';
		if ( 'solved' !== ( $t['status'] ?? '' ) ) {
			printf( '<button type="submit" class="mbcom-text-btn mbcom-solve" name="solve" value="1" formnovalidate>%s</button>', esc_html__( 'Mark as solved', 'membuild-community' ) );
		} else {
			echo '<span class="mbwa-muted">' . esc_html__( 'Solved. Writing again opens it.', 'membuild-community' ) . '</span>';
		}
		echo '<button type="submit" class="mbwa-btn mbwa-btn-primary">' . esc_html__( 'Send reply', 'membuild-community' ) . '</button></div></form></div>';
		Admin::shell_close();
	}

	/* ---------- Sending ---------- */

	private static function guard( $nonce ) {
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'membuild-community' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function too_big( $name ) {
		/* translators: 1: a file name, 2: size limit, e.g. 5 MB */
		return sprintf( __( '“%1$s” is bigger than %2$s. Try a smaller screenshot.', 'membuild-community' ), $name, self::shot_words() );
	}

	/** Pictures only: checked by what's inside the file, not its name. Returns an error, or ''. */
	private static function check_shot( $tmp, $name, $size ) {
		/* translators: %s: a file name */
		$not_picture = sprintf( __( '“%s” wasn’t added. Only pictures can be sent: PNG, JPG or WebP', 'membuild-community' ), $name );
		if ( ! is_uploaded_file( $tmp ) ) {
			return $not_picture;
		}
		if ( $size > self::max_shot() ) {
			return self::too_big( $name );
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that isn't a picture is an answer, not an error
		$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $tmp ) : (string) ( $info['mime'] ?? '' );
		$ok   = array( 'image/png', 'image/jpeg', 'image/webp' );
		return $info && in_array( $info['mime'], $ok, true ) && in_array( $mime, $ok, true ) ? '' : $not_picture;
	}

	public static function handle_send() {
		// Too much for this host: PHP drops the whole form, nonce and all. Say so plainly.
		if ( empty( $_POST ) && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- there is nothing to verify; only an error is kept
			if ( ! current_user_can( Admin::CAP ) ) {
				wp_die( esc_html__( 'You don’t have permission to do that.', 'membuild-community' ), 403 );
			}
			set_transient( 'mbcom_help_form_' . get_current_user_id(), array( 'errors' => array( 'send' => __( 'Those screenshots were too big to send together on this site. Try fewer or smaller ones, then write your message again.', 'membuild-community' ) ) ), 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( Admin::url( 'help' ) );
			exit;
		}
		self::guard( 'mbcom_help_send' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard()
		$kind    = isset( $_POST['kind'] ) && is_string( $_POST['kind'] ) && isset( self::kinds()[ $_POST['kind'] ] ) ? sanitize_key( $_POST['kind'] ) : 'bug';
		$message = trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ) );
		$loom    = 'nice' === $kind ? '' : trim( (string) wp_unslash( $_POST['loom'] ?? '' ) );
		$loom    = '' === $loom ? '' : esc_url_raw( preg_replace( '~^(?:https?://)?~i', 'https://', $loom ) );
		$share   = 'nice' === $kind && ! empty( $_POST['share'] );
		// phpcs:enable
		$errors = array();
		if ( '' === $message ) {
			$errors['message'] = __( 'Write a few words first.', 'membuild-community' );
		} elseif ( mb_strlen( $message ) > self::MAX ) {
			/* translators: %s: the most characters allowed */
			$errors['message'] = sprintf( __( 'That’s a long one. Keep it under %s characters.', 'membuild-community' ), number_format_i18n( self::MAX ) );
		}
		if ( '' !== $loom && ! preg_match( self::LOOM, $loom ) ) {
			$errors['loom'] = __( 'That isn’t a Loom link. It should start with loom.com/share', 'membuild-community' );
		}
		$files = array();
		if ( 'nice' !== $kind && ! empty( $_FILES['shots']['name'] ) && is_array( $_FILES['shots']['name'] ) ) {
			foreach ( $_FILES['shots']['name'] as $i => $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each part is checked below
				if ( '' === $name || UPLOAD_ERR_NO_FILE === (int) $_FILES['shots']['error'][ $i ] ) {
					continue;
				}
				$name = sanitize_file_name( (string) $name );
				if ( count( $files ) >= self::SHOTS ) {
					/* translators: %d: how many screenshots at most */
					$errors['shots'] = sprintf( __( 'Up to %d screenshots can be sent.', 'membuild-community' ), self::SHOTS );
					break;
				}
				$code = (int) $_FILES['shots']['error'][ $i ];
				if ( UPLOAD_ERR_OK === $code ) {
					$why = self::check_shot( $_FILES['shots']['tmp_name'][ $i ], $name, (int) $_FILES['shots']['size'][ $i ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				} elseif ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
					$why = self::too_big( $name );
				} else {
					/* translators: %s: a file name */
					$why = sprintf( __( '“%s” didn’t upload. Please try adding it again.', 'membuild-community' ), $name );
				}
				if ( '' !== $why ) {
					$errors['shots'] = $why;
					break;
				}
				$files[] = array( $_FILES['shots']['tmp_name'][ $i ], $name, (string) getimagesize( $_FILES['shots']['tmp_name'][ $i ] )['mime'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}
		$keep = compact( 'kind', 'message', 'loom', 'share' );
		if ( ! $errors ) {
			$user = wp_get_current_user();
			$out  = self::call(
				'help_new',
				array(
					'site_url'  => home_url(),
					'site_name' => get_bloginfo( 'name' ),
					'name'      => $user->display_name,
					'email'     => $user->user_email,
					'kind'      => $kind,
					'message'   => $message,
					'loom'      => $loom,
					'share'     => $share ? '1' : '0',
					'plugin'    => MEMBUILD_COMMUNITY_VERSION,
					'wp'        => get_bloginfo( 'version' ),
					'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
				),
				$files
			);
			if ( ! empty( $out['ok'] ) && ! empty( $out['number'] ) ) {
				delete_transient( self::CACHE );
				wp_safe_redirect( Admin::url( 'help', array( 'sent' => (int) $out['number'] ) ) );
				exit;
			}
			$errors['send'] = (string) ( $out['message'] ?? __( 'That didn’t send. Please try again.', 'membuild-community' ) );
		}
		set_transient( 'mbcom_help_form_' . get_current_user_id(), $keep + array( 'errors' => $errors ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Admin::url( 'help' ) );
		exit;
	}

	public static function handle_reply() {
		self::guard( 'mbcom_help_reply' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard()
		$number  = absint( $_POST['ticket'] ?? 0 );
		$message = trim( sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ) );
		$solve   = ! empty( $_POST['solve'] );
		// phpcs:enable
		if ( '' !== $message ) {
			$out = self::call( 'help_reply', array( 'number' => $number, 'message' => mb_substr( $message, 0, self::MAX ) ) );
			if ( empty( $out['ok'] ) ) {
				set_transient( 'mbcom_help_reply_' . get_current_user_id(), array( 'message' => $message, 'error' => (string) ( $out['message'] ?? __( 'That didn’t send. Please try again.', 'membuild-community' ) ) ), 10 * MINUTE_IN_SECONDS );
				$solve = false;
			}
		}
		if ( $solve ) {
			self::call( 'help_solve', array( 'number' => $number ) );
		}
		delete_transient( self::CACHE );
		wp_safe_redirect( Admin::url( 'help', array( 'ticket' => $number ) ) );
		exit;
	}

	public static function handle_solve() {
		self::guard( 'mbcom_help_solve' );
		$number = absint( $_POST['ticket'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard()
		self::call( 'help_solve', array( 'number' => $number ) );
		delete_transient( self::CACHE );
		wp_safe_redirect( Admin::url( 'help', array( 'ticket' => $number ) ) );
		exit;
	}

	/** A screenshot of this site's own message, fetched from MemBuild and shown only if it really is a PNG. */
	public static function handle_shot() {
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'mbcom_help_shot' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above
		$png = self::call( 'help_shot', array( 'number' => absint( $_GET['ticket'] ?? 0 ), 'message' => absint( $_GET['m'] ?? 0 ), 'n' => absint( $_GET['n'] ?? 0 ) ), array(), true );
		// phpcs:enable
		if ( ! is_string( $png ) || "\x89PNG\r\n\x1a\n" !== substr( $png, 0, 8 ) ) {
			wp_die( '', 404 );
		}
		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PNG bytes, checked above
		exit;
	}
}
