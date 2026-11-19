<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The community in wp-admin, for admins who want to see and manage their data
 * without opening the database: an overview, tables of members, posts,
 * comments, files and courses, and CSV exports.
 *
 * Private messages are never shown here, only counted: admins see how many
 * messages and chats a member has, not what they say.
 */
class Admin {

	const CAP = 'manage_options';

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mbcom_admin', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_mbcom_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mbcom_hide_guide', array( __CLASS__, 'hide_guide' ) );
		Help::register_hooks();
		add_action( 'current_screen', array( __CLASS__, 'quiet_core_update' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'menu_icon' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'menu_links' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer' ) );
		add_filter( 'set-screen-option', fn( $keep, $option, $value ) => str_starts_with( $option, 'mbcom_' ) ? (int) $value : $keep, 10, 3 );
	}

	/**
	 * The "MB Community" menu, last in the sidebar, with the MemBuild person mark
	 * (an SVG WordPress paints in the menu's own colors). "Settings ↗" goes to the app.
	 */
	public static function menu() {
		add_menu_page( __( 'MB Community', 'membuild-community' ), __( 'MB Community', 'membuild-community' ), self::CAP, 'membuild-community', array( __CLASS__, 'page_overview' ), self::menu_icon_uri(), 1000 );
		add_submenu_page( 'membuild-community', __( 'Overview', 'membuild-community' ), __( 'Overview', 'membuild-community' ), self::CAP, 'membuild-community', array( __CLASS__, 'page_overview' ) );
		$tables = array(
			'members'  => __( 'Members', 'membuild-community' ),
			'posts'    => __( 'Posts', 'membuild-community' ),
			'comments' => __( 'Comments', 'membuild-community' ),
			'files'    => __( 'Files', 'membuild-community' ),
			'courses'  => __( 'Courses', 'membuild-community' ),
		);
		foreach ( $tables as $key => $label ) {
			$hook = add_submenu_page( 'membuild-community', $label, $label, self::CAP, 'membuild-community-' . $key, fn() => self::page_table( $key ) );
			add_action( "load-{$hook}", fn() => add_screen_option( 'per_page', array( 'default' => 20, 'option' => "mbcom_{$key}_per_page" ) ) );
		}
		add_submenu_page( 'membuild-community', __( 'Export', 'membuild-community' ), __( 'Export', 'membuild-community' ), self::CAP, 'membuild-community-export', array( __CLASS__, 'page_export' ) );
		add_submenu_page( 'membuild-community', __( 'Problems', 'membuild-community' ), __( 'Problems', 'membuild-community' ), self::CAP, 'membuild-community-problems', array( Problems::class, 'page' ) );
		add_submenu_page( 'membuild-community', __( 'Help', 'membuild-community' ), __( 'Help', 'membuild-community' ), self::CAP, 'membuild-community-help', array( Help::class, 'page' ) );
		add_submenu_page( 'membuild-community', __( 'Community settings', 'membuild-community' ), __( 'Settings ↗', 'membuild-community' ), self::CAP, 'membuild-community-settings', '__return_null' );
	}

	/** The person mark as a data address, so WordPress colors it like every other menu icon. */
	private static function menu_icon_uri() {
		$svg = (string) file_get_contents( MEMBUILD_COMMUNITY_DIR . 'assets/admin/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of this plugin
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress's own way to give a menu an SVG icon
	}

	/** "Built by MemBuild" at the foot of the Community pages, in place of "Thank you for creating with WordPress". */
	public static function footer( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'membuild-community' ) ) {
			return $text;
		}
		return sprintf(
			/* translators: 1: plugin name and version, e.g. "MB Community 1.23.1", 2: "MemBuild", linked */
			esc_html__( '%1$s · Built by %2$s', 'membuild-community' ),
			esc_html( 'MB Community ' . MEMBUILD_COMMUNITY_VERSION ),
			'<a href="https://membuild.com/community" target="_blank" rel="noopener">MemBuild</a>'
		);
	}

	/** The Product System sheet's version (shared across plugins; bump when membuild-admin.css changes). */
	const SYSTEM_VERSION = '1.0.0';

	public static function styles( $hook ) {
		if ( false === strpos( (string) $hook, 'membuild-community' ) ) {
			return;
		}
		// The MemBuild Product System: one handle shared by every MemBuild plugin, so a
		// site running two of them loads it once, and the newest copy wins. Pages not yet
		// on the WP admin look (below) still use its cards and tables.
		if ( ! wp_style_is( 'membuild-admin', 'registered' ) || version_compare( (string) wp_styles()->registered['membuild-admin']->ver, self::SYSTEM_VERSION, '<' ) ) {
			wp_deregister_style( 'membuild-admin' );
			wp_register_style( 'membuild-admin', MEMBUILD_COMMUNITY_URL . 'assets/admin/membuild-admin.css', array(), self::SYSTEM_VERSION );
		}
		wp_enqueue_style( 'membuild-admin' );
		// The MemBuild WP Admin design system, scoped to these pages (.mbcom-admin.mbwa).
		wp_enqueue_style( 'mbcom-wpa', MEMBUILD_COMMUNITY_URL . 'assets/admin/mbwa.css', array( 'membuild-admin' ), MEMBUILD_COMMUNITY_VERSION );
		wp_register_style( 'mbcom-admin', false, array( 'mbcom-wpa' ), MEMBUILD_COMMUNITY_VERSION );
		wp_enqueue_style( 'mbcom-admin' );
		wp_add_inline_style( 'mbcom-admin', self::css() );
		wp_register_script( 'mbcom-admin', false, array(), MEMBUILD_COMMUNITY_VERSION, true );
		wp_enqueue_script( 'mbcom-admin' );
		wp_add_inline_script( 'mbcom-admin', self::js() );
		wp_enqueue_script( 'mbcom-lists', MEMBUILD_COMMUNITY_URL . 'assets/admin/lists.js', array(), MEMBUILD_COMMUNITY_VERSION, true );
		wp_localize_script(
			'mbcom-lists',
			'mbcomLists',
			array(
				'one'    => __( '1 selected', 'membuild-community' ),
				/* translators: {n} stays as it is: it becomes how many */
				'many'   => __( '{n} selected', 'membuild-community' ),
				'cancel' => __( 'Cancel', 'membuild-community' ),
				'close'  => __( 'Close', 'membuild-community' ),
				/* translators: {name} stays as it is: it becomes a file name */
				'shotType' => __( '“{name}” wasn’t added. Only pictures can be sent: PNG, JPG or WebP', 'membuild-community' ),
				/* translators: {name} stays as it is: it becomes a file name. %s: size limit, e.g. 5 MB */
				'shotBig'  => sprintf( __( '“{name}” is too big to send. Screenshots can be up to %s each.', 'membuild-community' ), size_format( Help::max_shot() ) ),
				'shotMax'  => __( 'Up to 3 screenshots can be sent.', 'membuild-community' ),
				/* translators: {name} stays as it is: it becomes a file name */
				'remove'   => __( 'Remove {name}', 'membuild-community' ),
			)
		);
	}

	/** A tab that was pressed dims the old page after a moment (the design system's is-loading). */
	private static function js() {
		return <<<'JS'
(function () {
	document.querySelectorAll('.mbcom-admin .mbwa-tab:not([target]), .mbcom-admin .mbwa-seg a').forEach(function (a) {
		a.addEventListener('click', function (e) {
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
			var root = a.closest('.mbwa');
			if (root) root.classList.add('is-loading');
		});
	});
	window.addEventListener('pageshow', function () {
		document.querySelectorAll('.mbcom-admin.is-loading').forEach(function (r) { r.classList.remove('is-loading'); });
	});
})();
JS;
	}

	/** "Settings ↗" in the WP submenu leads to the community itself, so it opens a new tab. */
	public static function menu_links() {
		wp_add_inline_script(
			'common',
			'document.querySelectorAll(\'#toplevel_page_membuild-community a[href$="page=membuild-community-settings"]\').forEach(function (a) { a.target = "_blank"; a.rel = "noopener"; a.href = ' . wp_json_encode( self::app_url( 'settings' ) ) . '; });',
			'after'
		);
	}

	/**
	 * WordPress's "WordPress x.y is available" line stays off our pages; it still shows on the
	 * Dashboard, on Updates and as the count in the top bar. Other notices are left alone.
	 */
	public static function quiet_core_update( $screen ) {
		if ( $screen && false !== strpos( (string) $screen->id, 'membuild-community' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'network_admin_notices', 'update_nag', 3 );
			// No Screen Options tab: lists show 20 rows (a number set there before still counts),
			// and its floating tab would squeeze the page beside it.
			add_filter( 'screen_options_show_screen', '__return_false' );
		}
	}

	/** Kept for older hooks: the menu icon is now the SVG given to add_menu_page. */
	public static function menu_icon() {
	}

	public static function url( $page = '', array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=membuild-community' . ( $page ? '-' . $page : '' ) ) );
	}

	public static function app_url( $path = '' ) {
		return home_url( '/' . Settings::slug() . '/' . ltrim( $path, '/' ) );
	}

	/** A link that performs a change (with a nonce) and comes back here. */
	public static function action_url( $do, $id, $back ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'mbcom_admin', 'do' => $do, 'id' => (int) $id, 'back' => rawurlencode( $back ) ), admin_url( 'admin-post.php' ) ), "mbcom_admin_{$do}_{$id}" );
	}

	/* ---------- The frame every page sits in ---------- */

	const EXT = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3H3v10h10v-3M9 3h4v4M13 3L7 9"/></svg>';

	/** The tabs across the top: what members make, then the owner's tools. */
	private static function tabs() {
		return array(
			array( '', __( 'Overview', 'membuild-community' ) ),
			array( 'members', __( 'Members', 'membuild-community' ) ),
			array( 'posts', __( 'Posts', 'membuild-community' ) ),
			array( 'comments', __( 'Comments', 'membuild-community' ) ),
			array( 'files', __( 'Files', 'membuild-community' ) ),
			array( 'courses', __( 'Courses', 'membuild-community' ) ),
			array( 'export', __( 'Export', 'membuild-community' ) ),
			array( 'problems', __( 'Problems', 'membuild-community' ) ),
			array( 'help', __( 'Help', 'membuild-community' ) ),
			array( 'settings', __( 'Settings', 'membuild-community' ) ),
		);
	}

	/** The newer version on offer, from what the update check already cached (a page never waits on GitHub). */
	public static function newer_version() {
		$latest = get_site_transient( Updater::CACHE );
		return is_array( $latest ) && ! empty( $latest['version'] ) && version_compare( $latest['version'], MEMBUILD_COMMUNITY_VERSION, '>' ) ? (string) $latest['version'] : '';
	}

	/** The MemBuild mark: the person on its dark square. */
	public static function mark() {
		$svg = (string) file_get_contents( MEMBUILD_COMMUNITY_DIR . 'assets/admin/mark.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of this plugin
		return str_replace( '<svg ', '<svg aria-hidden="true" focusable="false" ', $svg );
	}

	/** A plain text link in the accent color. */
	public static function link( $href, $text, $external = false, $class = 'mbcom-lnk' ) {
		return sprintf(
			'<a class="%s" href="%s"%s>%s%s</a>',
			esc_attr( $class ),
			esc_url( $href ),
			$external ? ' target="_blank" rel="noopener"' : '',
			esc_html( $text ),
			$external ? ' ' . self::EXT : ''
		);
	}

	/** A status pill: action, watch, fine or note. */
	public static function pill( $kind, $word ) {
		$k = 'note' === $kind ? '' : ' mbwa-pill-' . $kind;
		$d = 'note' === $kind ? '' : ' mbwa-dot-' . $kind;
		return '<span class="mbwa-pill' . $k . '"><span class="mbwa-dot' . $d . '"></span>' . esc_html( $word ) . '</span>';
	}

	/**
	 * Opens the frame: the tabs, "Version · by MemBuild" on the right, then the page's
	 * title row with its one main action. $current is the tab key ('' = overview).
	 * $desc is a short line under the title, for pages that still need one.
	 */
	public static function shell_open( $current, $title, $desc = '', $actions = '', $before_head = '' ) {
		echo '<div class="wrap mbcom-admin mbwa ' . esc_attr( $current ?: 'overview' ) . '">';
		echo '<div class="mbwa-header"><nav class="mbwa-tabs" aria-label="' . esc_attr__( 'MB Community pages', 'membuild-community' ) . '">';
		foreach ( self::tabs() as list( $key, $label ) ) {
			if ( 'settings' === $key ) {
				printf( '<a class="mbwa-tab" href="%s" target="_blank" rel="noopener">%s%s</a>', esc_url( self::app_url( 'settings' ) ), esc_html( $label ), self::EXT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg
				continue;
			}
			printf( '<a class="mbwa-tab" href="%s"%s>%s</a>', esc_url( self::url( $key ) ), $key === $current ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		printf(
			'</nav><span class="mbwa-by"><span>%s</span><span aria-hidden="true">·</span><span>%s</span>%s</span></div>',
			/* translators: %s: the plugin's version, e.g. 1.23.1 */
			esc_html( sprintf( __( 'Version %s', 'membuild-community' ), MEMBUILD_COMMUNITY_VERSION ) ),
			esc_html__( 'by MemBuild', 'membuild-community' ),
			self::mark() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own svg file
		);
		echo '<hr class="wp-header-end">'; // WordPress puts its notices here, not inside the title row
		echo '<div class="mbwa-page">';
		self::notice();
		echo $before_head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller
		if ( '' !== $title ) {
			printf(
				'<div class="mbwa-title-row"><h1 class="mbwa-title">%s%s</h1>%s</div>',
				esc_html( $title ),
				$desc ? '<span class="mbwa-muted" style="display:block;font-size:13px;font-weight:400;margin-top:4px">' . wp_kses( $desc, array( 'a' => array( 'href' => array() ), 'strong' => array() ) ) . '</span>' : '',
				$actions // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller
			);
		}
	}

	/** Closes the frame. */
	public static function shell_close() {
		echo '</div></div>';
	}

	/** "Open community ↗", the Overview's one button. */
	private static function open_button() {
		return '<a class="mbwa-btn" href="' . esc_url( self::app_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open community', 'membuild-community' ) . self::EXT . '</a>';
	}

	/* ---------- Overview ---------- */

	/** 7 or 30, from the period buttons. */
	private static function period() {
		return isset( $_GET['days'] ) && '30' === $_GET['days'] ? 30 : 7; // phpcs:ignore WordPress.Security.NonceVerification -- a view choice
	}

	/**
	 * The overview's numbers for a period and the one before it. Counting big tables is slow on
	 * small hosts, so they're reused for a few minutes; reports waiting are always live.
	 */
	private static function numbers( $days ) {
		global $wpdb;
		$cache = get_transient( 'mbcom_admin_numbers_' . $days );
		if ( is_array( $cache ) && isset( $cache['v'] ) && 3 === $cache['v'] ) {
			return $cache;
		}
		$now = time();
		// Whole days in the site's time zone, today included, the same days the chart shows.
		$start  = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' )->getTimestamp();
		$from   = gmdate( 'Y-m-d H:i:s', $start );
		$before = gmdate( 'Y-m-d H:i:s', $start - $days * DAY_IN_SECONDS );
		$posts  = Install::table( 'posts' );
		$pair   = function ( $sql, ...$args ) use ( $wpdb, $from, $before ) {
			// $sql has two %s at the end: the start and the end of the window.
			return array(
				(int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( $args, array( $from, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed strings below
				(int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( $args, array( $before, $from ) ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		};
		$n = array(
			'v'        => 3,
			'all'      => Helpers::member_total(),
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- site_members_sql() returns a prepared fragment.
			'joined'   => $pair( 'SELECT COUNT(*) FROM %i u WHERE 1=1' . Helpers::site_members_sql() . ' AND u.user_registered >= %s AND u.user_registered < %s', $wpdb->users ),
			'active'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i s JOIN %i u ON u.ID = s.user_id WHERE s.last_active >= %s', Install::table( 'member_stats' ), $wpdb->users, wp_date( 'Y-m-d', $start ) ) ),
			'quiet'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i s JOIN %i u ON u.ID = s.user_id WHERE s.last_active < %s', Install::table( 'member_stats' ), $wpdb->users, gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS ) ) ),
			'posts'    => $pair( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND created_at >= %s AND created_at < %s", $posts ),
			'comments' => $pair( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND created_at < %s', Install::table( 'comments' ) ),
			'lessons'  => $pair( 'SELECT COUNT(*) FROM %i WHERE completed_at >= %s AND completed_at < %s', Install::table( 'lesson_progress' ) ),
			'quizzes'  => $pair( 'SELECT COUNT(*) FROM %i WHERE passed = 1 AND preview = 0 AND finished_at >= %s AND finished_at < %s', Install::table( 'quiz_attempts' ) ),
			'certs'    => $pair( "SELECT COUNT(*) FROM %i WHERE status = 'valid' AND created_at >= %s AND created_at < %s", Install::table( 'certificates' ) ),
			'courses'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'courses' ) ) ),
			'storage'  => self::storage_bytes(),
		);
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT id, event_at FROM %i WHERE status = 'published' AND event_at IS NOT NULL AND event_at >= %s ORDER BY event_at ASC LIMIT 1", $posts, gmdate( 'Y-m-d H:i:s', $now ) ), ARRAY_A );
		$n['event'] = $event ? array(
			'at'    => strtotime( $event['event_at'] . ' UTC' ),
			'id'    => (int) $event['id'],
			'going' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'event' AND object_id = %d AND type = 'going'", Install::table( 'reactions' ), $event['id'] ) ),
		) : null;
		// The chart: posts and comments per day, in the site's time zone.
		$chart = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$chart[ wp_date( 'Y-m-d', $start + ( $days - 1 - $i ) * DAY_IN_SECONDS + 12 * HOUR_IN_SECONDS ) ] = array( 0, 0 );
		}
		$offset = sprintf( '%+d:%02d', (int) ( wp_timezone()->getOffset( new \DateTime() ) / 3600 ), abs( (int) ( wp_timezone()->getOffset( new \DateTime() ) % 3600 / 60 ) ) );
		foreach ( array( 0 => array( "SELECT DATE(CONVERT_TZ(created_at, '+00:00', %s)) d, COUNT(*) c FROM %i WHERE status = 'published' AND created_at >= %s GROUP BY d", $posts ), 1 => array( "SELECT DATE(CONVERT_TZ(created_at, '+00:00', %s)) d, COUNT(*) c FROM %i WHERE created_at >= %s GROUP BY d", Install::table( 'comments' ) ) ) as $k => list( $sql, $table ) ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $offset, $table, $from ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed strings above
				if ( isset( $chart[ $r['d'] ] ) ) {
					$chart[ $r['d'] ][ $k ] = (int) $r['c'];
				}
			}
		}
		$n['chart'] = $chart;
		set_transient( 'mbcom_admin_numbers_' . $days, $n, 5 * MINUTE_IN_SECONDS );
		return $n;
	}

	/** "Up from 5", "Down from 27" or "Same as before". */
	private static function compared( array $pair ) {
		list( $now, $before ) = $pair;
		if ( $now === $before ) {
			return __( 'Same as before', 'membuild-community' );
		}
		/* translators: %s: the number in the period before */
		return sprintf( $now > $before ? __( 'Up from %s', 'membuild-community' ) : __( 'Down from %s', 'membuild-community' ), number_format_i18n( $before ) );
	}

	/** The 7 days / 30 days buttons. */
	private static function period_buttons( $days ) {
		$out = '<div class="mbwa-seg" role="group" aria-label="' . esc_attr__( 'Period', 'membuild-community' ) . '">';
		foreach ( array( 7 => __( '7 days', 'membuild-community' ), 30 => __( '30 days', 'membuild-community' ) ) as $d => $label ) {
			$out .= '<a href="' . esc_url( self::url( '', 7 === $d ? array() : array( 'days' => $d ) ) ) . '"' . ( $d === $days ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $out . '</div>';
	}

	/** Number tiles: label, number, a quiet line under it. */
	private static function tiles( array $tiles ) {
		$out = '<div class="mbwa-stats">';
		foreach ( $tiles as list( $label, $value, $note ) ) {
			$out .= '<div><div class="mbwa-muted">' . esc_html( $label ) . '</div><div class="mbwa-stat-number">' . esc_html( $value ) . '</div><div class="mbwa-muted">' . esc_html( $note ) . '</div></div>';
		}
		return $out . '</div>';
	}

	/** The six steps: what's left first, each with a quiet button; done ones below with a Done pill. */
	private static function setup_guide() {
		$done  = First_Run::owner_steps();
		$steps = array(
			'look'   => array( __( 'Name, logo and color', 'membuild-community' ), __( 'How your community looks to members', 'membuild-community' ), __( 'Choose', 'membuild-community' ), self::app_url( 'settings/community' ) ),
			'email'  => array( __( 'Choose how emails are sent', 'membuild-community' ), __( 'So sign-up and password emails arrive', 'membuild-community' ), __( 'Choose', 'membuild-community' ), self::app_url( 'settings/email' ) ),
			'key'    => array( __( 'Save your safety key', 'membuild-community' ), __( 'Keeps private messages readable after a move', 'membuild-community' ), __( 'Save the key', 'membuild-community' ), self::app_url( 'settings/address#s-key' ) ),
			'course' => array( __( 'Add your first course', 'membuild-community' ), __( 'Optional, skip it if you only want a community', 'membuild-community' ), __( 'Create a course', 'membuild-community' ), self::app_url( 'classroom' ) ),
			'post'   => array( __( 'Write a welcome post', 'membuild-community' ), __( 'The first thing new members see', 'membuild-community' ), __( 'Write it', 'membuild-community' ), self::app_url( '?write=1' ) ),
			'invite' => array( __( 'Invite your members', 'membuild-community' ), __( 'Share your join link', 'membuild-community' ), __( 'Invite', 'membuild-community' ), self::app_url( '?invite=1' ) ),
		);
		$count = count( array_filter( $done ) );
		$hide  = wp_nonce_url( add_query_arg( array( 'action' => 'mbcom_hide_guide' ), admin_url( 'admin-post.php' ) ), 'mbcom_hide_guide' );
		echo '<section class="mbwa-card">';
		printf(
			'<div class="mbwa-card-bar"><h2 class="mbwa-card-title">%s<span class="mbwa-card-note">%s</span></h2>%s</div>',
			esc_html__( 'Get your community ready', 'membuild-community' ),
			/* translators: 1: steps done, 2: all steps */
			esc_html( sprintf( __( '%1$d of %2$d done', 'membuild-community' ), $count, count( $steps ) ) ),
			self::link( $hide, __( 'Hide', 'membuild-community' ), false, 'mbcom-lnk mbcom-lnk-quiet' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
		);
		printf( '<div style="padding:0 var(--space-24) var(--space-16)"><div class="mbwa-bar" style="margin:0"><span style="width:%d%%"></span></div></div>', (int) round( 100 * $count / count( $steps ) ) );
		$order = array_merge( array_keys( array_filter( $done, fn( $d ) => ! $d ) ), array_keys( array_filter( $done ) ) );
		$n     = 0;
		foreach ( $order as $key ) {
			if ( ! isset( $steps[ $key ] ) ) {
				continue;
			}
			++$n;
			$st      = $steps[ $key ];
			$is_done = ! empty( $done[ $key ] );
			printf(
				'<div class="mbcom-step"><span class="mbcom-num%s">%s</span><div><div class="mbwa-strong">%s</div>%s</div>%s</div>',
				$is_done ? ' done' : '',
				$is_done ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-label="' . esc_attr__( 'Done', 'membuild-community' ) . '"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>' : (int) $n,
				esc_html( $st[0] ),
				$is_done ? '' : '<div class="mbwa-muted">' . esc_html( $st[1] ) . '</div>',
				$is_done ? self::pill( 'fine', __( 'Done', 'membuild-community' ) ) : '<a class="mbwa-btn" href="' . esc_url( $st[3] ) . '">' . esc_html( $st[2] ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
			);
		}
		echo '</section>';
	}

	/** The setup guide shows until its steps are done or the owner hides it. */
	private static function guide_showing() {
		return Helpers::can_manage() && ! First_Run::owner_done() && ! get_option( 'mbcom_guide_hidden' );
	}

	/** Hides the setup guide for good (Hide on its card). */
	public static function hide_guide() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'membuild-community' ), 403 );
		}
		check_admin_referer( 'mbcom_hide_guide' );
		update_option( 'mbcom_guide_hidden', 1, false );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function page_overview() {
		$days = self::period();
		$n    = self::numbers( $days );
		/* translators: %s: number of days, 7 or 30 */
		$last = sprintf( __( 'Last %s days', 'membuild-community' ), $days );

		self::shell_open( '', __( 'Overview', 'membuild-community' ), '', self::open_button() );
		if ( self::guide_showing() ) {
			self::setup_guide();
		}

		// Activity.
		/* translators: 1: comparison, e.g. "Up from 5", 2: all members */
		$joined_note = sprintf( __( '%1$s · %2$s in all', 'membuild-community' ), self::compared( $n['joined'] ), number_format_i18n( $n['all'] ) );
		/* translators: %s: share of all members, e.g. "21" */
		$active_note = sprintf( __( '%s%% of members', 'membuild-community' ), number_format_i18n( $n['all'] ? (int) round( 100 * $n['active'] / $n['all'] ) : 0 ) );
		printf(
			'<section class="mbwa-card"><div class="mbwa-card-bar"><h2 class="mbwa-card-title">%s</h2>%s</div><div class="mbcom-pad">%s</div></section>',
			esc_html__( 'Activity', 'membuild-community' ),
			self::period_buttons( $days ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
			self::tiles( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
				array(
					array( __( 'New members', 'membuild-community' ), number_format_i18n( $n['joined'][0] ), $joined_note ),
					array( __( 'Active members', 'membuild-community' ), number_format_i18n( $n['active'] ), $active_note ),
					array( __( 'Posts', 'membuild-community' ), number_format_i18n( $n['posts'][0] ), self::compared( $n['posts'] ) ),
					array( __( 'Comments', 'membuild-community' ), number_format_i18n( $n['comments'][0] ), self::compared( $n['comments'] ) ),
				)
			)
		);

		// Courses and events, once there is a course or an event coming.
		if ( $n['courses'] || $n['event'] ) {
			$event = $n['event']
				/* translators: %s: number of members going */
				? array( __( 'Next event', 'membuild-community' ), wp_date( 'D j M', $n['event']['at'] ), sprintf( _n( '%s going', '%s going', $n['event']['going'], 'membuild-community' ), number_format_i18n( $n['event']['going'] ) ) )
				: array( __( 'Next event', 'membuild-community' ), __( 'None planned', 'membuild-community' ), '' );
			printf(
				'<section class="mbwa-card"><div class="mbwa-card-bar"><h2 class="mbwa-card-title">%s<span class="mbwa-card-note">%s</span></h2>%s</div><div class="mbcom-pad">%s</div></section>',
				esc_html__( 'Courses and events', 'membuild-community' ),
				esc_html( $last ),
				self::link( self::url( 'courses' ), __( 'View courses', 'membuild-community' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
				self::tiles( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
					array(
						array( __( 'Lessons finished', 'membuild-community' ), number_format_i18n( $n['lessons'][0] ), self::compared( $n['lessons'] ) ),
						array( __( 'Quizzes passed', 'membuild-community' ), number_format_i18n( $n['quizzes'][0] ), self::compared( $n['quizzes'] ) ),
						array( __( 'Certificates earned', 'membuild-community' ), number_format_i18n( $n['certs'][0] ), self::compared( $n['certs'] ) ),
						$event,
					)
				)
			);
		}

		self::activity_chart( $n['chart'], $last );

		echo '<div class="mbcom-two">';
		self::checks( $n );
		echo '<section class="mbwa-card">';
		printf( '<div class="mbwa-card-bar"><h2 class="mbwa-card-title">%s</h2>%s</div>', esc_html__( 'Latest activity', 'membuild-community' ), self::link( self::url( 'posts' ), __( 'View all', 'membuild-community' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
		self::recent_activity();
		echo '</section></div>';

		Usage::setup_card();
		self::shell_close();
	}

	/**
	 * Checks: one row each for what may need the owner. Name, the fact, a pill, one link.
	 * The version row shows only when an update is out; the safety key only until it's saved.
	 */
	private static function checks( array $n ) {
		$rows    = array();
		$reports = Moderation::open_count();
		$rows[]  = $reports
			/* translators: %s: number of reports */
			? array( __( 'Reports from members', 'membuild-community' ), sprintf( _n( '%s waiting', '%s waiting', $reports, 'membuild-community' ), number_format_i18n( $reports ) ), 'action', __( 'Action', 'membuild-community' ), self::app_url( 'moderation' ), __( 'View reports', 'membuild-community' ), true )
			: array( __( 'Reports from members', 'membuild-community' ), __( 'None waiting', 'membuild-community' ), 'fine', __( 'Fine', 'membuild-community' ), '', '', false );
		if ( '' !== Health::key_card() && ! self::guide_showing() ) {
			$rows[] = array( __( 'Safety key', 'membuild-community' ), __( 'No spare copy saved', 'membuild-community' ), 'action', __( 'Action', 'membuild-community' ), Health::key_settings_url(), __( 'Save the key', 'membuild-community' ), true );
		}
		$issues = 0;
		$bad    = false;
		foreach ( array( 'permalinks', 'cron', 'https', 'images', 'uploads', 'email' ) as $key ) {
			$status = Health::check( $key )[0];
			if ( 'good' !== $status ) {
				++$issues;
				$bad = $bad || 'critical' === $status;
			}
		}
		$rows[] = $issues
			/* translators: %s: number of things */
			? array( __( 'Hosting', 'membuild-community' ), sprintf( _n( '%s thing to fix', '%s things to fix', $issues, 'membuild-community' ), number_format_i18n( $issues ) ), $bad ? 'action' : 'watch', $bad ? __( 'Action', 'membuild-community' ) : __( 'Watch', 'membuild-community' ), admin_url( 'site-health.php' ), __( 'Open Site Health', 'membuild-community' ), false )
			: array( __( 'Hosting', 'membuild-community' ), __( 'Nothing to fix', 'membuild-community' ), 'fine', __( 'Fine', 'membuild-community' ), '', '', false );
		$rows[] = $n['quiet']
			/* translators: %s: number of members */
			? array( __( 'Quiet members', 'membuild-community' ), sprintf( _n( '%s not seen in 30 days', '%s not seen in 30 days', $n['quiet'], 'membuild-community' ), number_format_i18n( $n['quiet'] ) ), 'watch', __( 'Watch', 'membuild-community' ), self::url( 'members', array( 'status' => 'quiet' ) ), __( 'View members', 'membuild-community' ), false )
			: array( __( 'Quiet members', 'membuild-community' ), __( 'Everyone seen lately', 'membuild-community' ), 'fine', __( 'Fine', 'membuild-community' ), '', '', false );
		$errors = Problems::recent_count();
		$rows[] = $errors
			/* translators: %s: number of errors */
			? array( __( 'Plugin errors', 'membuild-community' ), sprintf( _n( '%s in 7 days', '%s in 7 days', $errors, 'membuild-community' ), number_format_i18n( $errors ) ), 'watch', __( 'Watch', 'membuild-community' ), self::url( 'problems' ), __( 'View problems', 'membuild-community' ), false )
			: array( __( 'Plugin errors', 'membuild-community' ), __( 'None caught', 'membuild-community' ), 'fine', __( 'Fine', 'membuild-community' ), self::url( 'problems' ), __( 'View problems', 'membuild-community' ), false );
		$newer = self::newer_version();
		if ( $newer ) {
			/* translators: %s: the newer version */
			$rows[] = array( __( 'Plugin version', 'membuild-community' ), sprintf( __( '%s is out', 'membuild-community' ), $newer ), 'watch', __( 'Watch', 'membuild-community' ), admin_url( 'plugins.php' ), __( 'Update', 'membuild-community' ), false );
		}
		echo '<section class="mbwa-card mbcom-checks">';
		printf(
			'<div class="mbwa-card-bar"><h2 class="mbwa-card-title">%s</h2></div><div class="mbwa-thead"><span>%s</span><span>%s</span><span>%s</span><span></span></div>',
			esc_html__( 'Checks', 'membuild-community' ),
			esc_html__( 'Check', 'membuild-community' ),
			esc_html__( 'Fact', 'membuild-community' ),
			esc_html__( 'Status', 'membuild-community' )
		);
		foreach ( $rows as list( $name, $fact, $kind, $word, $href, $link, $app ) ) {
			printf(
				'<div class="mbwa-trow"><div class="mbwa-strong">%s</div><div class="mbwa-muted">%s</div><div>%s</div><div>%s</div></div>',
				esc_html( $name ),
				esc_html( $fact ),
				self::pill( $kind, $word ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
				$href ? self::link( $href, $link, $app ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
			);
		}
		echo '</section>';
	}

	/** Posts and comments per day as two lines, drawn on the server (no scripts, no outside services). */
	private static function activity_chart( array $days, $last ) {
		$posts = array_column( $days, 0 );
		$comms = array_column( $days, 1 );
		$wide  = self::chart_svg( $days, $posts, $comms, 1186, 220, 1 );
		$small = self::chart_svg( $days, $posts, $comms, 340, 200, count( $days ) > 7 ? 2 : 1 );
		$keys  = array_keys( $days );
		$summary = array();
		foreach ( $keys as $i => $day ) {
			/* translators: 1: date, 2: posts, 3: comments */
			$summary[] = sprintf( __( '%1$s: %2$d posts, %3$d comments', 'membuild-community' ), wp_date( 'j M', strtotime( $day . ' 12:00 ' . wp_timezone_string() ) ), $posts[ $i ], $comms[ $i ] );
		}
		printf(
			'<section class="mbwa-card"><div class="mbwa-card-bar"><h2 class="mbwa-card-title">%s<span class="mbwa-card-note">%s</span></h2><div class="mbcom-legend"><span><svg width="22" height="8" aria-hidden="true"><line x1="0" x2="22" y1="4" y2="4" stroke="var(--accent)" stroke-width="2"/></svg>%s</span><span><svg width="22" height="8" aria-hidden="true"><line x1="0" x2="22" y1="4" y2="4" stroke="var(--ink-2)" stroke-width="2" stroke-dasharray="5 4"/></svg>%s</span></div></div><div style="padding:0 var(--space-24) var(--space-20)" role="img" aria-label="%s"><div class="mbcom-chart-wide">%s</div><div class="mbcom-chart-small">%s</div></div></section>',
			esc_html__( 'Posts and comments by day', 'membuild-community' ),
			esc_html( $last ),
			esc_html__( 'Posts', 'membuild-community' ),
			esc_html__( 'Comments', 'membuild-community' ),
			esc_attr( implode( '; ', $summary ) ),
			$wide, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numbers and escaped labels only
			$small // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numbers and escaped labels only
		);
	}

	/** One drawing of the chart at a given size; $thin keeps every $thin-th label on small screens. */
	private static function chart_svg( array $days, array $posts, array $comms, $w, $h, $thin ) {
		$count = count( $days );
		$top   = max( 1, max( $posts ), max( $comms ) );
		$step  = max( 1, (int) ceil( $top / 3 ) );
		$top   = $step * 3;
		list( $pl, $pr, $pt, $pb ) = array( 36, 16, 10, 28 );
		$x = fn( $i ) => round( $pl + ( $w - $pl - $pr ) * ( $count > 1 ? $i / ( $count - 1 ) : 0.5 ), 1 );
		$y = fn( $v ) => round( $pt + ( $h - $pt - $pb ) * ( 1 - $v / $top ), 1 );
		$svg = '';
		for ( $v = 0; $v <= $top; $v += $step ) {
			$svg .= '<line x1="' . $pl . '" x2="' . ( $w - $pr ) . '" y1="' . $y( $v ) . '" y2="' . $y( $v ) . '" stroke="var(--chart-grid)"/><text x="' . ( $pl - 8 ) . '" y="' . ( $y( $v ) + 4 ) . '" text-anchor="end" font-size="12" fill="var(--ink-3)">' . (int) $v . '</text>';
		}
		$keys  = array_keys( $days );
		$every = ( $count > 7 ? 5 : 1 ) * $thin;
		foreach ( $keys as $i => $day ) {
			$is_last = $i === $count - 1;
			if ( ( $count - 1 - $i ) % $every ) {
				continue;
			}
			$label = $is_last ? __( 'Today', 'membuild-community' ) : wp_date( 0 === $i || $count > 7 ? 'j M' : 'j', strtotime( $day . ' 12:00 ' . wp_timezone_string() ) );
			$svg  .= '<text x="' . $x( $i ) . '" y="' . ( $h - 6 ) . '" text-anchor="middle" font-size="12" fill="var(--ink-3)">' . esc_html( $label ) . '</text>';
		}
		foreach ( array( array( $posts, 'var(--accent)', '' ), array( $comms, 'var(--ink-2)', ' stroke-dasharray="6 5"' ) ) as list( $series, $color, $dash ) ) {
			$pts  = implode( ' ', array_map( fn( $i, $v ) => $x( $i ) . ',' . $y( $v ), array_keys( $series ), $series ) );
			$svg .= '<polyline points="' . $pts . '" fill="none" stroke="' . $color . '" stroke-width="2"' . $dash . '/>';
			if ( $count <= 7 && 1 === $thin && $w > 400 ) {
				foreach ( $series as $i => $v ) {
					$svg .= '<circle cx="' . $x( $i ) . '" cy="' . $y( $v ) . '" r="4" fill="' . ( $i === $count - 1 ? 'var(--surface)' : $color ) . '" stroke="' . $color . '" stroke-width="2"/>';
				}
			}
		}
		return '<svg class="mbcom-chart" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" aria-hidden="true">' . $svg . '</svg>';
	}

	/** The latest posts, comments and new members, grouped by day. */
	private static function recent_activity() {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- site_members_sql() returns a prepared fragment.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"(SELECT 'post' AS kind, id, user_id, content, created_at, id AS post_id FROM %i WHERE status = 'published' ORDER BY id DESC LIMIT 8)
				UNION ALL (SELECT 'comment', id, user_id, content, created_at, post_id FROM %i ORDER BY id DESC LIMIT 8)
				UNION ALL (SELECT 'join', u.ID, u.ID, '', u.user_registered, 0 FROM %i u WHERE 1=1" . Helpers::site_members_sql() . " ORDER BY u.ID DESC LIMIT 4)
				ORDER BY created_at DESC LIMIT 7",
				Install::table( 'posts' ),
				Install::table( 'comments' ),
				$wpdb->users
			),
			ARRAY_A
		);
		// phpcs:enable
		echo '<div class="mbcom-activity">';
		if ( ! $rows ) {
			echo '<p class="mbwa-muted" style="margin:var(--space-14) 0 0">' . esc_html__( 'Nothing yet.', 'membuild-community' ) . '</p></div>';
			return;
		}
		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		$today = wp_date( 'Y-m-d' );
		$yday  = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$open  = '';
		foreach ( $rows as $r ) {
			$at  = strtotime( $r['created_at'] . ' UTC' );
			$day = wp_date( 'Y-m-d', $at );
			if ( $day !== $open ) {
				if ( '' !== $open ) {
					echo '</div>';
				}
				$open = $day;
				echo '<div class="mbcom-day">' . esc_html( $today === $day ? __( 'Today', 'membuild-community' ) : ( $yday === $day ? __( 'Yesterday', 'membuild-community' ) : wp_date( 'j M', $at ) ) ) . '</div><div class="mbwa-changes">';
			}
			$name = $users[ (int) $r['user_id'] ]['name'] ?? __( 'Someone', 'membuild-community' );
			if ( 'join' === $r['kind'] ) {
				/* translators: %s: member name */
				$what = sprintf( __( '%s joined', 'membuild-community' ), $name );
				$text = __( 'New member', 'membuild-community' );
				$href = self::url( 'members' );
			} else {
				/* translators: %s: member name */
				$what = sprintf( 'post' === $r['kind'] ? __( '%s posted', 'membuild-community' ) : __( '%s commented', 'membuild-community' ), $name );
				$text = Helpers::words( (string) $r['content'], 12, '…' ) ?: __( 'A photo or video', 'membuild-community' );
				$href = self::app_url( '?post=' . (int) $r['post_id'] );
			}
			printf(
				'<span class="mbwa-muted">%s</span><span class="mbwa-dot" aria-hidden="true"></span><a href="%s" style="min-width:0"><div class="mbwa-strong mbcom-one">%s</div><div class="mbwa-muted mbcom-one">%s</div></a>',
				esc_html( wp_date( get_option( 'time_format' ), $at ) ),
				esc_url( $href ),
				esc_html( $what ),
				esc_html( $text )
			);
		}
		echo '</div></div>';
	}

	/** Disk space of community uploads, recomputed at most hourly. */
	public static function storage_bytes() {
		$cached = get_transient( 'mbcom_storage_bytes' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		$media = Install::table( 'media' );
		// Sizes are stored at upload; uploads from before 0.1 get theirs filled in, a few hundred per visit.
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, attachment_id FROM %i WHERE bytes = 0 AND attachment_id IS NOT NULL LIMIT 300', $media ), ARRAY_A ) as $row ) {
			$file = get_attached_file( (int) $row['attachment_id'] );
			$wpdb->update( $media, array( 'bytes' => $file && file_exists( $file ) ? max( 1, (int) filesize( $file ) ) : 1 ), array( 'id' => $row['id'] ) );
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(bytes), 0) FROM %i WHERE attachment_id IS NOT NULL', $media ) );
		set_transient( 'mbcom_storage_bytes', $total, HOUR_IN_SECONDS );
		return $total;
	}

	/* ---------- Tables ---------- */

	public static function page_table( $key ) {
		$class = __NAMESPACE__ . '\\' . ucfirst( $key ) . '_Table';
		$table = new $class();
		$table->init_columns();
		$table->prepare_items();
		self::shell_open( $key, '', '', '', $table->title_html() );
		$table->render();
		self::shell_close();
	}

	/** A page's title row: the title (already escaped), a short quiet fact, and its one button. */
	public static function title_row( $title_html, $note = '', $button = '' ) {
		return '<div class="mbwa-title-row"><h1 class="mbwa-title">' . $title_html . '</h1>' . ( '' !== $note ? '<span class="mbwa-muted">' . esc_html( $note ) . '</span>' : '' ) . ( '' !== $button ? '<span class="mbcom-grow"></span>' . $button : '' ) . '</div>';
	}

	public static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return remove_query_arg( array( 'mbcom_done', 'mbcom_count', '_wpnonce' ), $uri );
	}

	public static function notice() {
		$done  = isset( $_GET['mbcom_done'] ) ? sanitize_key( wp_unslash( $_GET['mbcom_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only
		$count = isset( $_GET['mbcom_count'] ) ? absint( $_GET['mbcom_count'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $done ) {
			return;
		}
		$messages = array(
			/* translators: %d: how many */
			'hidden'   => _n( '%d post hidden from the community.', '%d posts hidden from the community.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'restored' => _n( '%d post is live again.', '%d posts are live again.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'deleted'  => _n( '%d item deleted.', '%d items deleted.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'kept'     => _n( '%d kept. Its reports are closed.', '%d kept. Their reports are closed.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'pinned'   => _n( '%d post pinned.', '%d posts pinned.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'unpinned' => _n( '%d post unpinned.', '%d posts unpinned.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'muted'    => _n( '%d member muted for 7 days.', '%d members muted for 7 days.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'banned'   => _n( '%d member banned.', '%d members banned.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'lifted'   => _n( '%d member can take part again.', '%d members can take part again.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'grouped'   => _n( '%d member added to the group.', '%d members added to the group.', $count, 'membuild-community' ),
			/* translators: %d: how many */
			'ungrouped' => _n( '%d member removed from the group.', '%d members removed from the group.', $count, 'membuild-community' ),
			'error'    => __( 'That didn’t work. Admins and moderators can’t be muted or banned, pinning allows up to 3 posts, and members already in a group aren’t added twice.', 'membuild-community' ),
		);
		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $done ? 'error' : 'success', esc_html( sprintf( $messages[ $done ], $count ) ) );
		}
	}

	/* ---------- Changes ---------- */

	/**
	 * Every change from these screens goes through the same REST endpoints the
	 * app uses, as the current admin, so points, counts and notifications stay right.
	 */
	private static function rest( $method, $path, array $body = array() ) {
		$req = new \WP_REST_Request( $method, '/' . MEMBUILD_COMMUNITY_REST_NS . $path );
		foreach ( $body as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = rest_do_request( $req );
		return $res->get_status() < 400;
	}

	private static function apply( $table, $do, $id ) {
		global $wpdb;
		$posts = Install::table( 'posts' );
		if ( 'members' === $table && preg_match( '/^group(add|remove)(\d+)$/', $do, $m ) ) {
			return 'add' === $m[1] ? Access::add( (int) $m[2], $id ) : Access::remove( (int) $m[2], $id );
		}
		switch ( $table . ':' . $do ) {
			case 'posts:hide':
				return (bool) $wpdb->update( $posts, array( 'status' => 'review' ), array( 'id' => $id, 'status' => 'published' ) );
			case 'posts:restore':
				return (bool) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'published' WHERE id = %d AND status <> 'deleted'", $posts, $id ) );
			case 'posts:delete':
				return Rest_Feed::remove_post( $id );
			case 'posts:keep':
				return self::rest( 'POST', "/moderation/reports/post/{$id}", array( 'action' => 'keep' ) );
			case 'comments:keep':
				return self::rest( 'POST', "/moderation/reports/comment/{$id}", array( 'action' => 'keep' ) );
			case 'posts:pin':
				return self::rest( 'PUT', "/posts/{$id}/pin" );
			case 'posts:unpin':
				return self::rest( 'DELETE', "/posts/{$id}/pin" );
			case 'comments:delete':
				return self::rest( 'DELETE', "/comments/{$id}" );
			case 'members:mute':
				if ( 'banned' === ( Moderation::restriction( $id )['status'] ?? '' ) ) {
					return false; // a banned member stays banned
				}
				return self::rest( 'POST', "/members/{$id}/restriction", array( 'status' => 'muted', 'days' => 7 ) );
			case 'members:ban':
				return self::rest( 'POST', "/members/{$id}/restriction", array( 'status' => 'banned' ) );
			case 'members:lift':
				return self::rest( 'POST', "/members/{$id}/restriction", array( 'status' => 'none' ) );
			case 'files:delete':
				Media::delete( $id );
				delete_transient( 'mbcom_storage_bytes' );
				return true;
		}
		return false;
	}

	public static function handle_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'membuild-community' ), 403 );
		}
		$back = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : self::url(); // phpcs:ignore WordPress.Security.NonceVerification -- checked below per action
		$back = wp_validate_redirect( rawurldecode( $back ), self::url() ); // only ever back into this site
		$done = array( 'keep' => 'kept', 'hide' => 'hidden', 'restore' => 'restored', 'delete' => 'deleted', 'pin' => 'pinned', 'unpin' => 'unpinned', 'mute' => 'muted', 'ban' => 'banned', 'lift' => 'lifted', 'groupadd' => 'grouped', 'groupremove' => 'ungrouped' );

		if ( isset( $_POST['table'] ) ) {
			// Bulk action from a table.
			$table = sanitize_key( wp_unslash( $_POST['table'] ) );
			check_admin_referer( 'mbcom_bulk_' . $table );
			$do  = sanitize_key( wp_unslash( $_POST['bulk'] ?? '' ) );
			$do  = ( '-1' === $do || '' === $do ) ? sanitize_key( wp_unslash( $_POST['bulk2'] ?? '' ) ) : $do;
			$ids = array_map( 'absint', (array) wp_unslash( $_POST['ids'] ?? array() ) );
			$ok  = 0;
			foreach ( $ids as $id ) {
				$ok += self::apply( $table, $do, $id ) ? 1 : 0;
			}
			$verb   = preg_match( '/^group(add|remove)\d+$/', $do, $m ) ? 'group' . $m[1] : $do;
			$result = $ok ? ( $done[ $verb ] ?? 'deleted' ) : 'error';
			wp_safe_redirect( add_query_arg( array( 'mbcom_done' => $result, 'mbcom_count' => $ok ), $back ) );
			exit;
		}

		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( "mbcom_admin_{$do}_{$id}" );
		list( $table, $verb ) = array_pad( explode( '_', $do, 2 ), 2, '' );
		$ok = self::apply( $table, $verb, $id );
		wp_safe_redirect( add_query_arg( array( 'mbcom_done' => $ok ? ( $done[ $verb ] ?? 'deleted' ) : 'error', 'mbcom_count' => 1 ), $back ) );
		exit;
	}

	/* ---------- Export ---------- */

	/** The downloads, in the order the Export page lists them: key => array( name, a fact to add ). */
	public static function datasets() {
		return array(
			'members'      => array( __( 'Members', 'membuild-community' ), __( 'message counts only, never message text', 'membuild-community' ) ),
			'posts'        => array( __( 'Posts and reels', 'membuild-community' ), '' ),
			'comments'     => array( __( 'Comments', 'membuild-community' ), '' ),
			'progress'     => array( __( 'Course progress', 'membuild-community' ), '' ),
			'quizzes'      => array( __( 'Quiz results', 'membuild-community' ), '' ),
			'certificates' => array( __( 'Certificates', 'membuild-community' ), '' ),
			'events'       => array( __( 'Event sign-ups', 'membuild-community' ), '' ),
			'points'       => array( __( 'Points history', 'membuild-community' ), '' ),
			'files'        => array( __( 'Files', 'membuild-community' ), '' ),
		);
	}

	public static function page_export() {
		self::shell_open( 'export', __( 'Export', 'membuild-community' ) );
		$counts = Export::counts();
		echo '<div class="mbwa-card"><div class="mbwa-card-bar"><h2 class="mbwa-card-title">' . esc_html__( 'Spreadsheets', 'membuild-community' ) . '</h2></div>';
		foreach ( self::datasets() as $key => list( $label, $note ) ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'mbcom_export', 'dataset' => $key ), admin_url( 'admin-post.php' ) ), 'mbcom_export_' . $key );
			/* translators: %s: how many rows */
			$fact = sprintf( _n( '%s row', '%s rows', $counts[ $key ] ?? 0, 'membuild-community' ), number_format_i18n( $counts[ $key ] ?? 0 ) ) . ( '' !== $note ? ' · ' . $note : '' );
			printf(
				'<div class="mbwa-setting mbcom-export-row"><div><div class="mbwa-strong">%s</div><div class="mbwa-muted">%s</div></div><a class="mbwa-btn" href="%s">%s</a></div>',
				esc_html( $label ),
				esc_html( $fact ),
				esc_url( $url ),
				esc_html__( 'Download CSV', 'membuild-community' )
			);
		}
		echo '</div>';
		echo '<div class="mbwa-card"><div class="mbwa-card-bar"><h2 class="mbwa-card-title">' . esc_html__( 'Personal data', 'membuild-community' ) . '</h2></div>';
		foreach ( array(
			array( __( 'Export their data', 'membuild-community' ), __( 'Profile, posts, comments, points and course progress', 'membuild-community' ), admin_url( 'export-personal-data.php' ), __( 'Open WordPress export', 'membuild-community' ) ),
			array( __( 'Erase their data', 'membuild-community' ), __( 'Posts, comments, messages, stories, reactions and uploads', 'membuild-community' ), admin_url( 'erase-personal-data.php' ), __( 'Open WordPress erase', 'membuild-community' ) ),
		) as list( $label, $what, $href, $link ) ) {
			printf(
				'<div class="mbwa-setting mbcom-export-row"><div><div class="mbwa-strong">%s</div><div class="mbwa-muted">%s</div></div>%s</div>',
				esc_html( $label ),
				esc_html( $what ),
				self::link( $href, $link ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
			);
		}
		echo '</div>';
		self::shell_close();
	}

	public static function handle_export() {
		$key = isset( $_GET['dataset'] ) ? sanitize_key( wp_unslash( $_GET['dataset'] ) ) : '';
		if ( ! current_user_can( self::CAP ) || ! isset( self::datasets()[ $key ] ) ) {
			wp_die( esc_html__( 'You don’t have permission to do that.', 'membuild-community' ), 403 );
		}
		check_admin_referer( 'mbcom_export_' . $key );
		Export::send( $key );
	}

	/* ---------- Look ---------- */

	/**
	 * One stylesheet for every Community screen. WordPress's own look (cards, list
	 * tables, buttons), with its admin color scheme for the accents, so it feels
	 * native whichever scheme someone picked.
	 */
	private static function css() {
		return '
/* This plugin\'s pages on the MemBuild Product System (membuild-admin.css holds the tokens and components). */
.mbcom-admin{margin-left:0}
/* A flex panel would shrink beside WordPress\'s floated Screen Options tab; sit below it instead. */
.mbcom-admin .mb-panel{clear:both;width:auto}
.mbcom-admin .mbcom-muted{color:var(--ink-mute)}
.mbcom-admin .mb-page__head .mb-tag{margin-left:8px}
.mbcom-admin .notice{border-radius:var(--radius-md);margin:0;border-left-width:1px;border-color:var(--line-100);box-shadow:none;font-family:var(--font-sans)}
.mbcom-admin .notice p{font-size:14px}
.mbcom-admin .mb-panel .notice{order:0}

/* Overview */
.mbcom-admin .mbcom-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--space-4)}
@media (max-width:1100px){.mbcom-admin .mbcom-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:600px){.mbcom-admin .mbcom-stats{grid-template-columns:1fr}}
.mbcom-admin .mbcom-strip{display:flex;flex-wrap:wrap;gap:var(--space-2);margin-top:calc(-1 * var(--space-2))}
.mbcom-admin .mbcom-strip .mb-badge{height:32px;padding:0 12px;font-weight:600}
.mbcom-admin .mbcom-strip .mb-badge--neutral{background:var(--surface-000);border:1px solid var(--line-100)}
.mbcom-admin .mbcom-urgent{margin-top:0}
.mbcom-admin .mbcom-grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:var(--space-4);align-items:start}
@media (max-width:960px){.mbcom-admin .mbcom-grid{grid-template-columns:1fr}}
.mbcom-admin .mbcom-column{display:flex;flex-direction:column;gap:var(--space-4);min-width:0}
.mbcom-admin .mbcom-chart-card .mb-card__head{align-items:flex-start}
.mbcom-admin .mbcom-chart{display:block;width:100%;height:auto;max-height:220px;overflow:visible}
.mbcom-admin .mbcom-chart .p,.mbcom-admin .mbcom-legend .p{fill:var(--brand);background:var(--brand)}
.mbcom-admin .mbcom-chart .c,.mbcom-admin .mbcom-legend .c{fill:#9CC9B2;background:#9CC9B2}
.mbcom-admin .mbcom-chart .j,.mbcom-admin .mbcom-legend .j{fill:var(--accent);background:var(--accent)}
.mbcom-admin .mbcom-chart rect{rx:2px}
.mbcom-admin .mbcom-chart .g{stroke:var(--line-100);stroke-dasharray:3 3}
.mbcom-admin .mbcom-chart .t{fill:var(--ink-mute);font-size:10px;font-family:var(--font-sans)}
.mbcom-admin .mbcom-axis{display:flex;justify-content:space-between;color:var(--ink-mute);font-size:12px;margin-top:4px}
.mbcom-admin .mbcom-legend{display:flex;flex-wrap:wrap;gap:6px;margin:-4px 0 var(--space-3)}
.mbcom-admin .mbcom-chart-wrap{position:relative}
.mbcom-admin .mbcom-chart .hit{fill:transparent}
.mbcom-admin .mbcom-chart .day{outline:none;cursor:default}
.mbcom-admin .mbcom-chart .day.is-on .hit{fill:var(--surface-200)}
.mbcom-admin .mbcom-chart .day:focus-visible .hit{fill:var(--brand-tint)}
.mbcom-admin .mbcom-tip{position:absolute;top:0;transform:translate(-50%,-100%);display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:var(--radius-md);background:var(--surface-000);border:1px solid var(--line-100);box-shadow:var(--shadow-pop);font:500 13px/18px var(--font-sans);color:var(--ink-body);white-space:nowrap;pointer-events:none;z-index:5}
.mbcom-admin .mbcom-tip[hidden]{display:none}
.mbcom-admin .mbcom-tip strong{color:var(--ink);font-weight:700;margin-bottom:2px}
.mbcom-admin .mbcom-tip span{display:flex;align-items:center;gap:8px}
.mbcom-admin .mbcom-tip b{min-width:20px;text-align:right;color:var(--ink);font-variant-numeric:tabular-nums}
.mbcom-admin .mbcom-tip i{display:inline-block;width:8px;height:8px;border-radius:50%}
.mbcom-admin .mbcom-tip .p{background:var(--brand)}.mbcom-admin .mbcom-tip .c{background:#9CC9B2}.mbcom-admin .mbcom-tip .j{background:var(--accent)}
.mbcom-admin .mbcom-pill{display:inline-flex;align-items:center;gap:6px;padding:0 9px;line-height:22px;border-radius:999px;background:var(--surface-200);color:var(--ink-body);font-size:12px;font-weight:600;white-space:nowrap}
.mbcom-admin .mbcom-pill i{display:inline-block;width:8px;height:8px;border-radius:50%}
.mbcom-admin .mbcom-activity{margin:0;list-style:none}
.mbcom-admin .mbcom-activity li{display:flex;gap:var(--space-3);align-items:flex-start;padding:12px 0;margin:0;border-top:1px solid var(--line-100);font-size:14px;line-height:20px;color:var(--ink-body)}
.mbcom-admin .mbcom-activity li:first-child{border-top:0;padding-top:4px}
.mbcom-admin .mbcom-activity li>span:last-child{min-width:0}
.mbcom-admin .mbcom-activity strong{color:var(--ink)}
.mbcom-admin .mbcom-activity .mbcom-muted{display:block;font-size:12px;line-height:16px;margin-top:2px}
.mbcom-admin .mbcom-activity a{font-weight:600}
.mbcom-admin .mbcom-checks{display:block;align-items:center}
.mbcom-admin .mbcom-checks summary{cursor:pointer;font-weight:700;display:flex;align-items:center;gap:var(--space-2);list-style:none}
.mbcom-admin .mbcom-checks summary::-webkit-details-marker{display:none}
.mbcom-admin .mbcom-checks ul{list-style:disc;margin:10px 0 0 24px;color:var(--ink-body)}
.mbcom-admin .mbcom-checks li{margin-bottom:4px}
.mbcom-admin .mbcom-checks p{margin:8px 0 0}
.mbcom-admin .mbcom-gated{filter:blur(5px);opacity:.55;pointer-events:none;user-select:none;max-height:900px;overflow:hidden;-webkit-mask-image:linear-gradient(#000 55%,transparent);mask-image:linear-gradient(#000 55%,transparent);display:flex;flex-direction:column;gap:var(--space-5)}

/* Page titles: the WordPress admin rule ".wrap h1" (thin, 23px) would win over ours. Same font as the card titles. */
.mbcom-admin .mb-page__title{font:700 24px/30px var(--font-sans);letter-spacing:-.02em;color:var(--ink);padding:0;margin:0}

/* Setup guide (setup_guide) */
.mbcom-admin .mbcom-guide{display:flex;flex-direction:column;gap:var(--space-4);padding:32px 36px}
.mbcom-admin .mbcom-guide-head{display:flex;justify-content:space-between;gap:var(--space-5);align-items:flex-start}
.mbcom-admin .mbcom-guide-head h1{margin:0;font:800 30px/38px var(--font-sans);letter-spacing:-.02em;color:var(--ink)}
.mbcom-admin .mbcom-guide-head p{margin:10px 0 0;max-width:700px;color:var(--ink-body);font-size:16px;line-height:26px}
.mbcom-admin .mbcom-guide-head p a{color:var(--ink);text-decoration:none}
.mbcom-admin .mbcom-guide-bar{display:flex;align-items:center;gap:var(--space-4);margin-top:var(--space-2)}
.mbcom-admin .mbcom-guide-bar span{flex:1;height:10px;border-radius:999px;background:var(--surface-200);overflow:hidden}
.mbcom-admin .mbcom-guide-bar i{display:block;height:100%;border-radius:inherit;background:#1f7a49}
.mbcom-admin .mbcom-guide-bar b{font-size:16px;color:var(--ink)}
.mbcom-admin .mbcom-guide-steps{list-style:none;margin:0;padding:0}
.mbcom-admin .mbcom-guide-steps li{display:flex;align-items:center;gap:var(--space-4);padding:18px 0;border-top:1px solid var(--line-100);margin:0}
.mbcom-admin .mbcom-guide-n{display:grid;place-items:center;flex:none;width:40px;height:40px;border-radius:50%;background:var(--surface-200);color:var(--ink);font:700 16px/1 var(--font-sans)}
.mbcom-admin .mbcom-guide-steps li.done .mbcom-guide-n{background:#1f7a49;color:#fff}
.mbcom-admin .mbcom-guide-ic{flex:none;color:var(--ink-body)}
.mbcom-admin .mbcom-guide-words{flex:1;min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px}
.mbcom-admin .mbcom-guide-words strong{font-size:17px;font-weight:600;color:var(--ink)}
.mbcom-admin .mbcom-guide-steps li.done .mbcom-guide-words strong{text-decoration:line-through;color:var(--ink-mute)}
.mbcom-admin .mbcom-guide-words small{flex-basis:100%;font-size:15px;color:var(--ink-body)}
.mbcom-admin .mbcom-guide-tag{padding:3px 10px;border-radius:999px;background:var(--surface-200);color:var(--ink-body);font-size:13px;font-weight:700}
.mbcom-admin .mbcom-guide-tag.warn{background:#fff3d6;color:#7a4e00}
.mbcom-admin .mbcom-guide-done{color:#1f7a49;font-size:16px;font-weight:700}
.mbcom-admin .mbcom-guide-btn{display:inline-flex;align-items:center;flex:none;min-height:52px;padding:0 24px;border-radius:999px;background:var(--surface-200);color:var(--ink);font-size:17px;font-weight:700;text-decoration:none}
.mbcom-admin .mbcom-guide-btn:hover{background:var(--line-100);color:var(--ink)}
.mbcom-admin .mbcom-guide-btn.main{background:var(--accent);color:#16181d}
.mbcom-admin .mbcom-guide-note{display:flex;align-items:center;gap:var(--space-3);margin:0;padding:16px 20px;border-radius:var(--radius-md);background:var(--surface-200);color:var(--ink-body);font-size:15px}
@media (max-width:782px){.mbcom-admin .mbcom-guide{padding:22px 18px}.mbcom-admin .mbcom-guide-head{flex-direction:column}.mbcom-admin .mbcom-guide-steps li{flex-wrap:wrap}.mbcom-admin .mbcom-guide-ic{display:none}.mbcom-admin .mbcom-guide-btn{margin-left:56px}}

/* Keep me updated (Usage::setup_card) */
.mbcom-admin .mbcom-setup{display:flex;flex-direction:column;gap:var(--space-4);border-color:var(--brand)}
.mbcom-admin .mbcom-setup h2{font:700 20px/26px var(--font-sans);letter-spacing:-.015em;margin:0}
.mbcom-admin .mbcom-setup>p{margin:-8px 0 0;max-width:720px;color:var(--ink-body)}
.mbcom-admin .mbcom-setup-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--space-4) var(--space-5);max-width:760px}
.mbcom-admin .mbcom-setup-fields label{display:flex;flex-direction:column;gap:var(--space-2);font:700 11px/16px var(--font-sans);letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);min-width:0}
.mbcom-admin .mbcom-setup-fields select,.mbcom-admin .mbcom-setup-fields input{width:100%;max-width:none;min-height:var(--control-h);border-radius:var(--radius-md);border:1px solid var(--line-200);background:var(--surface-000);color:var(--ink);font:500 15px/22px var(--font-sans);letter-spacing:0;text-transform:none;padding:0 var(--space-4);box-shadow:none}
.mbcom-admin .mbcom-setup-fields select:focus,.mbcom-admin .mbcom-setup-fields input:focus{border-color:var(--brand);box-shadow:none;outline:2px solid var(--focus);outline-offset:2px}
.mbcom-admin .mbcom-setup-fields label:first-child{grid-column:1/-1}
.mbcom-admin .mbcom-setup-fields input[readonly]{color:var(--ink-mute);background:var(--surface-200);border-color:var(--line-100)}
.mbcom-admin .mbcom-setup:not(:has(option[value="other"]:checked)) .mbcom-setup-other{display:none}
.mbcom-admin .mbcom-setup-share{display:flex;gap:var(--space-3);align-items:flex-start;max-width:760px;padding:var(--space-3) var(--space-4);border-radius:var(--radius-md);background:var(--surface-200);color:var(--ink-body);font-size:14px;line-height:20px}
.mbcom-admin .mbcom-setup-share input{margin:2px 0 0;flex:none;accent-color:var(--brand)}
.mbcom-admin .mbcom-setup-share strong{color:var(--ink)}
.mbcom-admin .mbcom-setup-actions{display:flex;align-items:center;gap:var(--space-2);margin:0}
.mbcom-admin .mbcom-setup-fine{margin:0;font-size:12px;line-height:16px;color:var(--ink-mute);max-width:760px}
@media (max-width:782px){.mbcom-admin .mbcom-setup-fields{grid-template-columns:1fr}}

/* Export */
.mbcom-admin .mbcom-exports{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--space-4)}
@media (max-width:900px){.mbcom-admin .mbcom-exports{grid-template-columns:1fr}}
.mbcom-admin .mbcom-export{display:flex;flex-direction:column;gap:var(--space-1);padding:var(--space-4) var(--space-5)}
.mbcom-admin .mbcom-export-title{font:700 15px/22px var(--font-sans);letter-spacing:-.01em;color:var(--ink)}
.mbcom-admin .mbcom-export .mb-help{margin-bottom:var(--space-3)}
.mbcom-admin .mbcom-export .mb-btn{align-self:flex-start;margin-top:auto}
.mbcom-admin .mbcom-privacy-text{color:var(--ink-body);font-size:14px;line-height:20px;max-width:760px;margin-top:var(--space-2)}
.mbcom-admin .mbcom-privacy-actions{display:flex;flex-wrap:wrap;gap:var(--space-2);margin-top:var(--space-4)}

/* Problems */
.mbcom-admin .mbcom-problems-empty{display:flex;flex-direction:column;align-items:flex-start;gap:var(--space-2);padding:var(--space-6) var(--space-5)}
.mbcom-admin .mbcom-empty-icon{width:40px;height:40px;border-radius:var(--radius-md);display:grid;place-items:center;background:var(--ok-tint);color:var(--ok);margin-bottom:var(--space-2)}
.mbcom-admin .mbcom-table-wrap{overflow-x:auto}
.mbcom-admin .mbcom-problem-table th:nth-child(2){width:46%}.mbcom-admin .mbcom-problem-table th:nth-child(3){width:22%}
.mbcom-admin .mbcom-problem-table td{vertical-align:top}
.mbcom-admin .mbcom-problem-table strong{color:var(--ink)}
.mbcom-admin .mbcom-nowrap{white-space:nowrap}
.mbcom-admin .mbcom-stack{margin-top:6px}
.mbcom-admin .mbcom-stack summary{cursor:pointer;color:var(--ink-mute);font-size:12px}
.mbcom-admin .mbcom-stack pre{margin:6px 0 0;padding:10px 12px;background:var(--surface-200);border-radius:var(--radius-sm);font:400 12px/1.5 var(--font-mono);white-space:pre-wrap;word-break:break-word;max-height:220px;overflow:auto;color:var(--ink-body)}
.mbcom-admin .mbcom-copy{margin-top:var(--space-4)}
.mbcom-admin .mbcom-copy summary{cursor:pointer;font-weight:700;color:var(--brand)}
.mbcom-admin .mbcom-copy p{margin:8px 0}
.mbcom-admin .mbcom-copy textarea{width:100%;max-width:900px;font:400 12px/1.5 var(--font-mono);border-radius:var(--radius-md);border:1px solid var(--line-200);padding:10px 12px;color:var(--ink-body)}

/* WordPress list tables, reskinned: same markup (sorting, bulk actions, screen options), the system\'s look */
.mbcom-admin .mbcom-table-card{padding:var(--space-3) var(--space-4);display:flex;flex-wrap:wrap;align-items:center;gap:var(--space-3)}
.mbcom-admin .mbcom-table-card>.subsubsub{order:1}
.mbcom-admin .mbcom-table-card>form[method=get]{order:2;margin-left:auto}
.mbcom-admin .mbcom-table-card>form[method=post]{order:3;flex:1 1 100%;min-width:0}
.mbcom-admin .mbcom-table-card>.mbcom-table-empty{order:3;flex:1 1 100%}
.mbcom-admin .subsubsub{display:inline-flex;gap:var(--space-1);background:var(--surface-200);padding:4px;border-radius:999px;margin:0;float:none;font-size:14px;width:max-content;max-width:100%;flex-wrap:wrap}
.mbcom-admin .subsubsub li{margin:0;display:inline-flex}
.mbcom-admin .subsubsub a{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 14px;border-radius:999px;color:var(--ink-body);font:600 14px/20px var(--font-sans);text-decoration:none}
.mbcom-admin .subsubsub a:hover{color:var(--ink)}
.mbcom-admin .subsubsub a.current{background:var(--surface-000);color:var(--ink);font-weight:700;box-shadow:0 1px 2px rgba(14,21,18,.08)}
.mbcom-admin .subsubsub .count{color:var(--ink-mute);font-weight:600}
.mbcom-admin .tablenav{height:auto;margin:0;padding:0;display:flex;flex-wrap:wrap;align-items:center;gap:var(--space-2);color:var(--ink-mute)}
.mbcom-admin .tablenav.top{margin-top:var(--space-1)}
.mbcom-admin .tablenav .actions{padding:0;display:flex;gap:var(--space-2);align-items:center}
.mbcom-admin .tablenav select,.mbcom-admin .search-box input[type=search]{height:40px;min-height:40px;border-radius:var(--radius-md);border:1px solid var(--line-200);background:var(--surface-000);color:var(--ink);font:500 14px/20px var(--font-sans);padding:0 14px;box-shadow:none;margin:0}
.mbcom-admin .tablenav select{padding-right:32px;background-position:right 10px center}
.mbcom-admin .tablenav select:focus,.mbcom-admin .search-box input[type=search]:focus{border-color:var(--brand);box-shadow:none;outline:2px solid var(--focus);outline-offset:2px}
.mbcom-admin .search-box{float:none;display:flex;gap:var(--space-2);align-items:center;margin:0}
.mbcom-admin .search-box input[type=search]{width:280px;max-width:100%}
.mbcom-admin .button,.mbcom-admin .button-secondary,.mbcom-admin .tablenav .button{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;min-height:36px;padding:0 14px;border-radius:999px;border:1px solid var(--line-200);background:var(--surface-000);color:var(--brand);font:600 13px/20px var(--font-sans);box-shadow:none;text-decoration:none;margin:0;vertical-align:middle}
.mbcom-admin .button:hover{border-color:var(--brand);color:var(--brand);background:var(--surface-000)}
.mbcom-admin .button-primary,.mbcom-admin .button.button-primary{background:var(--accent);border-color:transparent;color:var(--accent-on);font-weight:700}
.mbcom-admin .button-primary:hover{background:var(--accent);color:var(--accent-on);filter:brightness(1.05)}
.mbcom-admin .button-link{display:inline;height:auto;min-height:0;padding:0;border:0;background:none;border-radius:0;color:var(--brand);font-weight:600}
.mbcom-admin .tablenav-pages{float:none;margin:0 0 0 auto;display:flex;align-items:center;gap:var(--space-2);font:500 13px/18px var(--font-sans);color:var(--ink-mute)}
.mbcom-admin .tablenav-pages .displaying-num{margin:0}
.mbcom-admin .tablenav-pages .pagination-links{display:inline-flex;align-items:center;gap:var(--space-1)}
.mbcom-admin .tablenav-pages .pagination-links a,.mbcom-admin .tablenav-pages .pagination-links .tablenav-pages-navspan{min-width:36px;height:36px;padding:0 var(--space-2);border-radius:999px;border:1px solid transparent;background:transparent;color:var(--ink-body);font:600 13px/34px var(--font-sans);display:inline-flex;align-items:center;justify-content:center;box-shadow:none;margin:0}
.mbcom-admin .tablenav-pages .pagination-links a:hover{background:var(--surface-200)}
.mbcom-admin .tablenav-pages .pagination-links .tablenav-pages-navspan{opacity:.45}
.mbcom-admin .tablenav-pages .paging-input{margin:0 4px;font:500 13px/18px var(--font-sans)}
.mbcom-admin .tablenav-pages #current-page-selector{height:32px;min-height:32px;width:52px;padding:0 8px;border-radius:var(--radius-sm);border:1px solid var(--line-200);font:500 13px/18px var(--font-sans);box-shadow:none;margin:0}
.mbcom-admin .tablenav .clear{display:none}
.mbcom-admin .wp-list-table{border:0;background:transparent;box-shadow:none;margin:0;font-family:var(--font-sans)}
.mbcom-admin .wp-list-table thead th,.mbcom-admin .wp-list-table thead td{background:var(--surface-200);border:0;padding:var(--space-3) var(--space-4);font:700 11px/16px var(--font-sans);letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);white-space:nowrap}
.mbcom-admin .wp-list-table thead th:first-child{border-radius:var(--radius-md) 0 0 var(--radius-md)}
.mbcom-admin .wp-list-table thead th:last-child{border-radius:0 var(--radius-md) var(--radius-md) 0}
.mbcom-admin .wp-list-table thead th a{color:var(--ink-mute);display:inline-flex;align-items:center;gap:4px;padding:0}
.mbcom-admin .wp-list-table thead th a:hover{color:var(--ink);text-decoration:none}
.mbcom-admin .wp-list-table thead th.sorted a,.mbcom-admin .wp-list-table thead th.sortable a{padding:0}
.mbcom-admin .wp-list-table thead th .sorting-indicators{margin-left:2px}
/* WordPress sizes this column in em and clips it; the box needs its own room. */
.mbcom-admin .wp-list-table thead td.check-column,.mbcom-admin .wp-list-table tbody th.check-column,.mbcom-admin .wp-list-table tbody td.check-column{padding:var(--space-3) 0 var(--space-3) var(--space-4)!important;width:44px!important;vertical-align:middle!important;overflow:visible!important;white-space:nowrap}
.mbcom-admin .wp-list-table .check-column input[type=checkbox]{margin:0;position:static}
.mbcom-admin .wp-list-table tfoot{display:none}
.mbcom-admin .wp-list-table tbody td,.mbcom-admin .wp-list-table tbody th{padding:var(--space-3) var(--space-4);border-bottom:1px solid var(--line-100);vertical-align:middle;font:500 14px/20px var(--font-sans);color:var(--ink-body);box-shadow:none;background:transparent}
.mbcom-admin .wp-list-table tbody tr:last-child td,.mbcom-admin .wp-list-table tbody tr:last-child th{border-bottom:0}
.mbcom-admin .wp-list-table.striped>tbody>:nth-child(odd){background:transparent}
.mbcom-admin .wp-list-table tbody tr:hover td,.mbcom-admin .wp-list-table tbody tr:hover th{background:var(--surface-200)}
.mbcom-admin .wp-list-table td strong,.mbcom-admin .wp-list-table td .mbcom-name strong{color:var(--ink);font-weight:700}
.mbcom-admin .wp-list-table .row-actions{color:var(--ink-mute);font-size:12px;line-height:16px;padding-top:4px}
.mbcom-admin .wp-list-table .row-actions a{font-weight:600}
.mbcom-admin input[type=checkbox]{width:18px;height:18px;border-radius:5px;border:1px solid var(--line-200);box-shadow:none;margin:0;vertical-align:middle}
.mbcom-admin input[type=checkbox]:checked::before{width:14px;height:14px;margin:1px}
.mbcom-admin input[type=checkbox]:checked{background:var(--brand);border-color:var(--brand)}
.mbcom-admin input[type=checkbox]:checked::before{filter:brightness(10)}
.mbcom-admin .mbcom-person,.mbcom-admin .mbcom-item{display:flex;align-items:center;gap:12px}
.mbcom-admin .mbcom-item{align-items:flex-start}
.mbcom-admin .mbcom-item>div{min-width:0}
.mbcom-admin .mbcom-person img{border-radius:50%;flex:none}
.mbcom-admin .mbcom-person>span{display:flex;flex-direction:column;min-width:0}
.mbcom-admin .mbcom-person a.mbcom-muted{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none;font-weight:500;font-size:13px}
.mbcom-admin .mbcom-tag{display:inline-flex;align-items:center;gap:4px;height:22px;padding:0 8px;border-radius:var(--radius-sm);background:var(--surface-200);color:var(--ink-body);font:700 12px/16px var(--font-sans);white-space:nowrap}
.mbcom-admin .mbcom-tag .dashicons{font-size:13px;width:13px;height:13px}
.mbcom-admin .mbcom-tag.warn{background:var(--warn-tint);color:var(--warn)}.mbcom-admin .mbcom-tag.bad{background:var(--danger-tint);color:var(--danger)}.mbcom-admin .mbcom-tag.ok{background:var(--ok-tint);color:var(--ok)}
/*
 * Column widths: the first column (the post, file, member or course) takes whatever
 * room is left; the small columns keep a fixed width and never break mid-word.
 */
@media (min-width:783px){.mbcom-admin .wp-list-table td:not(.column-primary):not(.column-post):not(.column-used){white-space:nowrap;overflow:hidden;text-overflow:ellipsis}}
.mbcom-admin .column-author{width:150px}.mbcom-admin .column-author .mbcom-who{max-width:100%;overflow:hidden;text-overflow:ellipsis}
.mbcom-admin .column-type,.mbcom-admin .column-kind,.mbcom-admin .column-size,.mbcom-admin .column-lessons{width:80px}
.mbcom-admin .column-reactions,.mbcom-admin .column-comments,.mbcom-admin .column-finished{width:96px}
.mbcom-admin .column-date{width:132px}.mbcom-admin .column-status{width:132px}.mbcom-admin .column-access{width:170px}.mbcom-admin .column-started{width:140px}
.mbcom-admin .column-post{width:210px}.mbcom-admin .column-used{width:150px}
.mbcom-admin .members .column-points{width:160px}.mbcom-admin .members .column-activity,.mbcom-admin .members .column-messages{width:118px}
.mbcom-admin .members .column-last_active,.mbcom-admin .members .column-joined{width:148px}
/* The thumbnail already says "reel"; the Type column only repeats it. */
.mbcom-admin .posts .column-type{display:none}
@media (max-width:1180px) and (min-width:783px){
.mbcom-admin .members .column-joined,.mbcom-admin .posts .column-type,.mbcom-admin .files .column-author,.mbcom-admin .courses .column-finished{display:none}
}
.mbcom-admin .column-post a{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
@media (max-width:782px){
.mbcom-admin .subsubsub{width:100%}
.mbcom-admin .search-box input[type=search]{width:100%}
.mbcom-admin .wp-list-table thead th:first-child,.mbcom-admin .wp-list-table thead th:last-child{border-radius:0}
.mbcom-admin .wp-list-table tr:not(.inline-edit-row):not(.no-items) td:not(.check-column){padding:var(--space-3) var(--space-4)}
}
';
	}
}
