<?php
/**
 * Problems: errors in our own code, kept on the site for the admin to see.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Two sources: PHP fatals and uncaught exceptions whose file is inside this
 * plugin (a shutdown handler reads error_get_last()), and errors the app's
 * JavaScript reports (window error / unhandledrejection, POST /problems).
 *
 * Each becomes a signature (kind, file, line, scrubbed message) counted in a
 * small option: at most LIMIT signatures, the least recent dropped first.
 * The message is scrubbed before it's stored: emails, URLs, quoted strings and
 * long numbers become "…", absolute paths become plugin-relative. Request data,
 * SQL values and stack arguments are never kept. Admins see the list on
 * Community → Problems (and a count on the overview); members never do.
 *
 * With "Share usage" on, the weekly report carries the signatures and counts
 * (never the site address), so MemBuild learns which versions break where.
 *
 *   POST /problems   {message, source, line, col, stack, route}  members, rate limited
 */
class Problems {

	const OPTION = 'mbcom_problems';
	const LIMIT  = 50;   // signatures kept
	const SHARE  = 20;   // newest signatures in the weekly report
	const MSG    = 300;  // stored message length
	const STACK  = 2000; // stored stack length

	public static function register_hooks() {
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		add_action( 'admin_post_mbcom_problems', array( __CLASS__, 'handle_clear' ) );
	}

	public static function register_routes() {
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/problems',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_report' ),
				'permission_callback' => array( 'MemBuild\\Community\\Rest_Feed', 'logged_in' ), // members of the community; not banned accounts
			)
		);
	}

	/* ---------- Capture ---------- */

	/** The last PHP error, if it was fatal and in our files. */
	public static function on_shutdown() {
		$e = error_get_last();
		if ( $e ) {
			self::from_php( $e );
		}
	}

	/** Records a PHP error array (as error_get_last() gives it) when it's fatal and ours. Returns the signature, or null. */
	public static function from_php( array $e ) {
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( ! in_array( (int) ( $e['type'] ?? 0 ), $fatal, true ) || ! self::ours( (string) ( $e['file'] ?? '' ) ) ) {
			return null;
		}
		$message = (string) ( $e['message'] ?? '' );
		$stack   = '';
		// "Uncaught Exception: msg in /path:line\nStack trace:\n#0 …": keep the first line as the message, the frames as the stack.
		if ( preg_match( '/^(.*?)\nStack trace:\n(.*)$/s', $message, $m ) ) {
			$message = $m[1];
			$stack   = $m[2];
		}
		return self::record( 'php', self::relative( (string) $e['file'] ), (int) ( $e['line'] ?? 0 ), $message, $stack, is_admin() ? 'wp-admin' : 'site' );
	}

	/** True for a path inside this plugin (never for the theme or other plugins). */
	public static function ours( $file ) {
		return false !== strpos( str_replace( '\\', '/', $file ), '/membuild-community/' );
	}

	/** /var/www/…/plugins/membuild-community/includes/x.php → includes/x.php. Any other absolute path loses its directory. */
	public static function relative( $file ) {
		$file = str_replace( '\\', '/', $file );
		$at   = strpos( $file, '/membuild-community/' );
		return false !== $at ? substr( $file, $at + strlen( '/membuild-community/' ) ) : basename( $file );
	}

	/** Takes what could identify a person or a secret out of a message. */
	public static function scrub( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '…', $text );
		// Browser stack frames: keep the script's name (without its build hash) and the line, drop the address.
		$text = preg_replace( '#https?://\S*?/([A-Za-z0-9_.-]+?)(?:-[A-Za-z0-9_-]{8})?\.js(:\d+(?::\d+)?)?#i', '$1.js$2', $text );
		$text = preg_replace( '#https?://\S+#i', '…', $text );
		$text = preg_replace( '/(["\'`])(?:(?!\1).){4,}\1/', '…', $text ); // quoted strings of any length past a few characters
		$text = preg_replace( '#(?:[A-Za-z]:)?(?:/[^/\s:]+){2,}/(membuild-community/[^\s:]+)#', '$1', $text ); // absolute paths to our files
		$text = preg_replace( '#(?:[A-Za-z]:)?(?:/[^/\s:]+){2,}/([^/\s:]+)#', '…/$1', $text ); // any other absolute path
		$text = preg_replace( '/\b\d{5,}\b/', '…', $text ); // IDs, keys, phone numbers; line numbers stay
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\s*\n\s*/', "\n", $text ); // stacks keep their lines
		return trim( wp_strip_all_tags( $text ) );
	}

	/** One stored signature; counted when seen again. Returns the signature key. */
	public static function record( $kind, $file, $line, $message, $stack = '', $where = '' ) {
		$file    = mb_substr( self::scrub( $file ), 0, 120 );
		$message = mb_substr( self::scrub( $message ), 0, self::MSG );
		$stack   = mb_substr( self::scrub( $stack ), 0, self::STACK );
		$where   = mb_substr( self::scrub( $where ), 0, 80 );
		$sig     = substr( md5( "{$kind}|{$file}|{$line}|{$message}" ), 0, 12 );
		$all     = self::raw();
		$now     = time();
		if ( isset( $all[ $sig ] ) ) {
			$all[ $sig ]['count']++;
			$all[ $sig ]['last']   = $now;
			$all[ $sig ]['plugin'] = MEMBUILD_COMMUNITY_VERSION;
			if ( '' !== $where ) {
				$all[ $sig ]['where'] = $where;
			}
		} else {
			$all[ $sig ] = array(
				'kind'    => 'js' === $kind ? 'js' : 'php',
				'file'    => $file,
				'line'    => (int) $line,
				'message' => $message,
				'stack'   => $stack,
				'where'   => $where,
				'count'   => 1,
				'first'   => $now,
				'last'    => $now,
				'plugin'  => MEMBUILD_COMMUNITY_VERSION,
				'wp'      => $GLOBALS['wp_version'] ?? '',
				'php'     => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			);
		}
		if ( count( $all ) > self::LIMIT ) {
			// Newest first; within the same second, the one recorded last wins (the sort is stable, so reverse first).
			// The app's reports (sent from members' browsers) and the server's are kept apart, so a flood of
			// one kind never pushes the other off the page.
			$all = array_reverse( $all, true );
			uasort( $all, fn( $a, $b ) => $b['last'] <=> $a['last'] );
			$keep = array();
			foreach ( array( 'php', 'js' ) as $k ) { // each kind is sure of half …
				$keep += array_slice( array_filter( $all, fn( $p ) => ( 'js' === $p['kind'] ? 'js' : 'php' ) === $k ), 0, (int) ( self::LIMIT / 2 ), true );
			}
			foreach ( $all as $other => $p ) { // … and the newest of the rest fill what's left
				if ( count( $keep ) >= self::LIMIT ) {
					break;
				}
				$keep += array( $other => $p );
			}
			uasort( $keep, fn( $a, $b ) => $b['last'] <=> $a['last'] );
			$all = $keep;
		}
		update_option( self::OPTION, $all, false );
		return $sig;
	}

	/** As stored: in the order first recorded. */
	private static function raw() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/** Every stored signature, newest first (within one second, the one recorded last). */
	public static function all() {
		$all = array_reverse( self::raw(), true );
		uasort( $all, fn( $a, $b ) => ( $b['last'] ?? 0 ) <=> ( $a['last'] ?? 0 ) );
		return $all;
	}

	/** How many signatures were seen in the last $days days. */
	public static function recent_count( $days = 7 ) {
		$since = time() - $days * DAY_IN_SECONDS;
		return count( array_filter( self::all(), fn( $p ) => ( $p['last'] ?? 0 ) >= $since ) );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/** Takes one entry away, when what it reported has healed. */
	public static function forget( $sig ) {
		$all = self::raw();
		unset( $all[ $sig ] );
		update_option( self::OPTION, $all, false );
	}

	/** What the weekly report carries: the newest signatures, counts and versions. No stack, no where. */
	public static function for_report() {
		$out = array();
		foreach ( array_slice( self::all(), 0, self::SHARE, true ) as $sig => $p ) {
			$out[] = array(
				'sig'     => $sig,
				'kind'    => $p['kind'],
				'file'    => $p['file'],
				'line'    => (int) $p['line'],
				'message' => mb_substr( $p['message'], 0, 160 ),
				'count'   => (int) $p['count'],
				'plugin'  => $p['plugin'],
				'last'    => gmdate( 'Y-m-d', $p['last'] ),
			);
		}
		return $out;
	}

	/* ---------- The app reports a JavaScript error ---------- */

	public static function rest_report( \WP_REST_Request $req ) {
		$slow = Helpers::throttle( 'problem', 10, MINUTE_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		$in      = $req->get_json_params() ?: array();
		$message = mb_substr( sanitize_text_field( (string) ( $in['message'] ?? '' ) ), 0, 500 );
		if ( '' === $message ) {
			return new \WP_Error( 'mbcom_bad_report', __( 'Nothing to report.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( self::from_extension( $message, (string) ( $in['source'] ?? '' ), (string) ( $in['stack'] ?? '' ) ) ) {
			return array( 'ok' => true ); // nothing for the site owner to do, so nothing to tell them
		}
		// The built file's hash changes every release; the plugin version says which build it was.
		$source = preg_replace( '/-[A-Za-z0-9_-]{8}\.js$/', '.js', basename( (string) ( $in['source'] ?? '' ) ) );
		$source = mb_substr( sanitize_text_field( $source ), 0, 80 );
		$stack  = mb_substr( sanitize_textarea_field( (string) ( $in['stack'] ?? '' ) ), 0, self::STACK );
		$route  = mb_substr( sanitize_text_field( (string) ( $in['route'] ?? '' ) ), 0, 80 );
		self::record( 'js', $source ?: 'app', (int) ( $in['line'] ?? 0 ), $message, $stack, $route );
		return array( 'ok' => true );
	}

	/**
	 * A browser extension's own error, caught because it ran on the page: every place in its stack is in an
	 * extension. An error that also passes through the plugin's code stays reported, so a real one is never lost.
	 */
	public static function from_extension( $message, $source, $stack ) {
		// The app cuts a stack at 2,000 characters, so our code could be in the part that's gone.
		if ( strlen( $stack ) >= 1990 ) {
			return false;
		}
		$extension = '#^(chrome|moz|safari|safari-web|ms-browser)-extension://#i';
		$places    = array();
		foreach ( explode( "\n", $stack ) as $line ) {
			// "at f (https://…)" in Chrome, "f@https://…" in Firefox and Safari. Anything else is the message.
			if ( preg_match( '#^\s*at\s.*?([a-z-]+://[^\s)]+)#i', $line, $m ) || preg_match( '#^[^@]*@(?:blob:)?([a-z-]+://\S+)#i', $line, $m ) ) {
				$places[] = $m[1];
			}
		}
		if ( $places ) {
			return count( preg_grep( $extension, $places ) ) === count( $places );
		}
		return (bool) preg_match( $extension, trim( $source ) )
			|| (bool) preg_match( '/\b(?:runtime\.(?:sendMessage|connect)\b|Extension context invalidated|Receiving end does not exist)/i', $message );
	}

	/* ---------- wp-admin ---------- */

	/** "N problems in the last 7 days" for the overview strip; nothing when there are none. */
	public static function strip_item() {
		$n = self::recent_count();
		if ( ! $n ) {
			return '';
		}
		return sprintf(
			'<a class="mb-badge mb-badge--warn" href="%s">%s · %s</a>',
			esc_url( Admin::url( 'problems' ) ),
			/* translators: %s: a number */
			esc_html( sprintf( _n( '%s problem in the last 7 days', '%s problems in the last 7 days', $n, 'membuild-community' ), number_format_i18n( $n ) ) ),
			esc_html__( 'See what', 'membuild-community' )
		);
	}

	public static function page() {
		if ( ! Helpers::can_manage() ) {
			return;
		}
		$all  = self::all();
		$seen = (int) get_user_meta( get_current_user_id(), 'mbcom_problems_seen', true ); // when this person last opened the page
		update_user_meta( get_current_user_id(), 'mbcom_problems_seen', time() );
		$note = '<span class="mbwa-muted">' . esc_html( Usage::state()['share'] ? __( 'Sharing problems with MemBuild is on', 'membuild-community' ) : __( 'Sharing problems with MemBuild is off', 'membuild-community' ) ) . ' · ' . Admin::link( Admin::app_url( 'settings/news' ), __( 'Change', 'membuild-community' ), false, 'mbcom-lnk mbcom-lnk-plain' ) . '</span>';
		Admin::shell_open( 'problems', '', '', '', '<div class="mbwa-title-row"><h1 class="mbwa-title">' . esc_html__( 'Problems', 'membuild-community' ) . '</h1>' . $note . '</div>' );
		if ( ! $all ) {
			echo '<div class="mbwa-card mbwa-card-pad mbcom-problems-none"><span class="mbwa-strong">' . esc_html__( 'No problems caught', 'membuild-community' ) . '</span>' . Admin::pill( 'fine', __( 'Fine', 'membuild-community' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill()
			Admin::shell_close();
			return;
		}
		$grid = ' style="--mbwa-cols:minmax(0,1fr) 140px 70px 130px 110px 150px;--mbwa-cols-medium:minmax(0,1fr) 70px 130px 150px"';
		echo '<div class="mbwa-card mbcom-list mbcom-problems"><div class="mbwa-card-bar"><h2 class="mbwa-card-title">' . esc_html__( 'Caught on this site', 'membuild-community' ) . '</h2><span class="mbcom-grow"></span>';
		printf(
			'<form method="post" action="%s">%s<input type="hidden" name="action" value="mbcom_problems"><button class="mbcom-text-btn" name="do" value="clear">%s</button></form></div>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'mbcom_problems', '_wpnonce', true, false ),
			esc_html__( 'Clear list', 'membuild-community' )
		);
		echo '<div class="mbwa-thead"' . $grid . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed
		foreach ( array( array( __( 'Problem', 'membuild-community' ), 'mbcom-keep' ), array( __( 'Where', 'membuild-community' ), 'mbwa-hide-medium' ), array( __( 'Times', 'membuild-community' ), '' ), array( __( 'Last seen', 'membuild-community' ), '' ), array( __( 'Status', 'membuild-community' ), 'mbwa-hide-medium' ), array( '', 'mbcom-keep' ) ) as list( $h, $c ) ) {
			echo '<span class="' . esc_attr( $c ) . '">' . ( '' !== $h ? esc_html( $h ) : '<span class="screen-reader-text">' . esc_html__( 'Actions', 'membuild-community' ) . '</span>' ) . '</span>';
		}
		echo '</div>';
		foreach ( $all as $sig => $p ) {
			/* translators: 1: a file, 2: a line number */
			$place = $p['line'] ? sprintf( __( '%1$s, line %2$s', 'membuild-community' ), $p['file'], $p['line'] ) : $p['file'];
			$day   = wp_date( 'Y-m-d', $p['last'] );
			$when  = wp_date( 'Y-m-d' ) === $day
				/* translators: %s: a time of day */
				? sprintf( __( 'Today, %s', 'membuild-community' ), wp_date( get_option( 'time_format' ), $p['last'] ) )
				: ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ? __( 'Yesterday', 'membuild-community' ) : wp_date( 'j M', $p['last'] ) );
			$new   = ( $p['first'] ?? 0 ) > $seen;
			printf(
				'<div class="mbwa-trow"%s><div class="mbcom-keep"><div class="mbwa-strong mbcom-one" title="%s">%s</div><div class="mbwa-muted mbcom-one">%s</div></div><div class="mbwa-hide-medium">%s</div><div>%s</div><div title="%s">%s</div><div class="mbwa-hide-medium">%s</div><div class="mbcom-keep"><div class="mbcom-acts">%s</div></div></div>',
				$grid, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed
				esc_attr( $p['message'] ),
				esc_html( $p['message'] ),
				esc_html( $place . ( $p['where'] ? ' · ' . $p['where'] : '' ) ),
				esc_html( 'js' === $p['kind'] ? __( 'Community app', 'membuild-community' ) : __( 'Website', 'membuild-community' ) ),
				esc_html( number_format_i18n( $p['count'] ) ),
				esc_attr( wp_date( 'Y-m-d H:i', $p['last'] ) . ' · Community ' . $p['plugin'] . ' · WP ' . $p['wp'] . ' · PHP ' . $p['php'] ),
				esc_html( $when ),
				$new ? Admin::pill( 'watch', __( 'New', 'membuild-community' ) ) : Admin::pill( 'note', __( 'Seen before', 'membuild-community' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill()
				Admin::link( Admin::url( 'help', array( 'problem' => $sig ) ), __( 'Send to MemBuild', 'membuild-community' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link()
			);
		}
		echo '</div>';
		Admin::shell_close();
	}

	/** One problem as plain text for a message to MemBuild: what, where, how often, versions and the stack. */
	public static function as_text( $sig ) {
		$p = self::raw()[ $sig ] ?? null;
		if ( ! $p ) {
			return '';
		}
		$place = $p['file'] . ( $p['line'] ? ':' . $p['line'] : '' );
		return sprintf( "%s\n%s (%s), %d times, last %s\nCommunity %s, WP %s, PHP %s%s", $p['message'], $place, 'js' === $p['kind'] ? 'community app' : 'website', $p['count'], gmdate( 'Y-m-d', $p['last'] ), $p['plugin'], $p['wp'], $p['php'], $p['stack'] ? "\n\n" . $p['stack'] : '' );
	}

	public static function handle_clear() {
		if ( ! Helpers::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'membuild-community' ), 403 );
		}
		check_admin_referer( 'mbcom_problems' );
		self::clear();
		wp_safe_redirect( Admin::url( 'problems' ) );
		exit;
	}
}
