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
				'permission_callback' => 'is_user_logged_in',
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
			$all = array_reverse( $all, true );
			uasort( $all, fn( $a, $b ) => $b['last'] <=> $a['last'] );
			$all = array_slice( $all, 0, self::LIMIT, true );
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
		// The built file's hash changes every release; the plugin version says which build it was.
		$source = preg_replace( '/-[A-Za-z0-9_-]{8}\.js$/', '.js', basename( (string) ( $in['source'] ?? '' ) ) );
		$source = mb_substr( sanitize_text_field( $source ), 0, 80 );
		$stack  = mb_substr( sanitize_textarea_field( (string) ( $in['stack'] ?? '' ) ), 0, self::STACK );
		$route  = mb_substr( sanitize_text_field( (string) ( $in['route'] ?? '' ) ), 0, 80 );
		self::record( 'js', $source ?: 'app', (int) ( $in['line'] ?? 0 ), $message, $stack, $route );
		return array( 'ok' => true );
	}

	/* ---------- wp-admin ---------- */

	/** "N problems in the last 7 days" for the overview strip; nothing when there are none. */
	public static function strip_item() {
		$n = self::recent_count();
		if ( ! $n ) {
			return '';
		}
		return sprintf(
			'<li class="mbcom-alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span> %s <a href="%s">%s</a></li>',
			/* translators: %s: a number */
			esc_html( sprintf( _n( '%s problem in the last 7 days', '%s problems in the last 7 days', $n, 'membuild-community' ), number_format_i18n( $n ) ) ),
			esc_url( Admin::url( 'problems' ) ),
			esc_html__( 'See what', 'membuild-community' )
		);
	}

	public static function page() {
		if ( ! Helpers::can_manage() ) {
			return;
		}
		$all   = self::all();
		$share = Usage::state()['share'];
		echo '<div class="wrap mbcom-admin">';
		printf( '<h1>%s</h1>', esc_html__( 'Problems', 'membuild-community' ) );
		printf(
			'<p class="mbcom-intro">%s</p>',
			esc_html__( 'Errors in the Community plugin’s own code, caught on this site: PHP crashes in its files, and errors the community app hit in the browser. Each line is one place in the code, with how often it happened. Nothing here is a member’s doing, and nothing from this page leaves the site unless “Share usage” is on, which sends the lines below without the stack or the site’s address.', 'membuild-community' )
		);
		if ( ! $all ) {
			printf( '<section class="mbcom-panel mbcom-problems-empty"><span class="mbcom-stat-icon"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></span><h2>%s</h2><p class="mbcom-muted">%s</p></section></div>', esc_html__( 'No problems caught', 'membuild-community' ), esc_html__( 'If something goes wrong in the plugin’s code, it shows up here.', 'membuild-community' ) );
			return;
		}
		$dump = '';
		echo '<section class="mbcom-panel mbcom-problems"><div class="mbcom-problems-head">';
		printf( '<h2>%s</h2>', esc_html( sprintf( /* translators: %s: a number */ _n( '%s problem', '%s problems', count( $all ), 'membuild-community' ), number_format_i18n( count( $all ) ) ) ) );
		printf(
			'<form method="post" action="%s">%s<input type="hidden" name="action" value="mbcom_problems"><button class="button" name="do" value="clear">%s</button></form></div>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'mbcom_problems', '_wpnonce', true, false ),
			esc_html__( 'Clear the list', 'membuild-community' )
		);
		echo '<div class="mbcom-table-wrap"><table class="widefat striped mbcom-problem-table"><thead><tr>';
		foreach ( array( __( 'Last seen', 'membuild-community' ), __( 'What happened', 'membuild-community' ), __( 'Where', 'membuild-community' ), __( 'Times', 'membuild-community' ), __( 'Versions', 'membuild-community' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $all as $sig => $p ) {
			$place = $p['file'] . ( $p['line'] ? ':' . $p['line'] : '' );
			/* translators: %s: how long ago, e.g. "3 hours" */
			$ago = sprintf( __( '%s ago', 'membuild-community' ), human_time_diff( $p['last'] ) );
			printf(
				'<tr><td class="mbcom-nowrap"><abbr title="%s">%s</abbr></td><td><span class="mbcom-tag %s">%s</span> <strong>%s</strong>%s</td><td><code>%s</code>%s</td><td>%s</td><td class="mbcom-muted mbcom-nowrap">%s</td></tr>',
				esc_attr( wp_date( 'Y-m-d H:i', $p['last'] ) ),
				esc_html( $ago ),
				'js' === $p['kind'] ? 'warn' : 'bad',
				'js' === $p['kind'] ? esc_html__( 'Browser', 'membuild-community' ) : esc_html__( 'Server', 'membuild-community' ),
				esc_html( $p['message'] ),
				$p['stack'] ? '<details class="mbcom-stack"><summary>' . esc_html__( 'Stack', 'membuild-community' ) . '</summary><pre>' . esc_html( $p['stack'] ) . '</pre></details>' : '',
				esc_html( $place ),
				$p['where'] ? '<br><small class="mbcom-muted">' . esc_html( $p['where'] ) . '</small>' : '',
				esc_html( number_format_i18n( $p['count'] ) ),
				esc_html( 'Community ' . $p['plugin'] . ' · WP ' . $p['wp'] . ' · PHP ' . $p['php'] )
			);
			$dump .= sprintf( "[%s] %s x%d  %s  %s\n  %s\n  Community %s, WP %s, PHP %s%s\n\n", $sig, strtoupper( $p['kind'] ), $p['count'], gmdate( 'Y-m-d', $p['last'] ), $place, $p['message'], $p['plugin'], $p['wp'], $p['php'], $p['stack'] ? "\n  " . str_replace( "\n", "\n  ", $p['stack'] ) : '' );
		}
		echo '</tbody></table></div>';
		printf(
			'<details class="mbcom-copy"><summary>%s</summary><p class="mbcom-muted">%s</p><textarea readonly rows="10" onclick="this.select()">%s</textarea></details></section></div>',
			esc_html__( 'Copy for support', 'membuild-community' ),
			esc_html__( 'Paste this when you write to MemBuild or your developer. It holds the lines above and nothing about your members.', 'membuild-community' ),
			esc_textarea( $dump )
		);
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
