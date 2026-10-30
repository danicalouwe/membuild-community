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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'menu_icon' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'menu_links' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer' ) );
		add_filter( 'set-screen-option', fn( $keep, $option, $value ) => str_starts_with( $option, 'mbcom_' ) ? (int) $value : $keep, 10, 3 );
	}

	/**
	 * The "Community" menu, last in the sidebar (after the final separator) with
	 * the community's own colored icon. "Open community" and "Settings" go to the app.
	 */
	public static function menu() {
		add_menu_page( __( 'Community', 'membuild-community' ), __( 'Community', 'membuild-community' ), self::CAP, 'membuild-community', array( __CLASS__, 'page_overview' ), 'none', 1000 );
		add_submenu_page( 'membuild-community', __( 'Community overview', 'membuild-community' ), __( 'Overview', 'membuild-community' ), self::CAP, 'membuild-community', array( __CLASS__, 'page_overview' ) );
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
		add_submenu_page( 'membuild-community', __( 'Export community data', 'membuild-community' ), __( 'Export', 'membuild-community' ), self::CAP, 'membuild-community-export', array( __CLASS__, 'page_export' ) );
		add_submenu_page( 'membuild-community', __( 'Problems', 'membuild-community' ), __( 'Problems', 'membuild-community' ), self::CAP, 'membuild-community-problems', array( Problems::class, 'page' ) );
		add_submenu_page( 'membuild-community', __( 'Community settings', 'membuild-community' ), __( 'Settings ↗', 'membuild-community' ), self::CAP, 'membuild-community-settings', '__return_null' );
		add_submenu_page( 'membuild-community', __( 'Open community', 'membuild-community' ), __( 'Open community ↗', 'membuild-community' ), self::CAP, 'membuild-community-open', '__return_null' );
	}

	/** "Built by MemBuild" at the foot of the Community pages, in place of "Thank you for creating with WordPress". */
	public static function footer( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'membuild-community' ) ) {
			return $text;
		}
		return sprintf(
			/* translators: 1: plugin name and version, e.g. "MemBuild - Community 1.2.0", 2: "MemBuild", linked */
			esc_html__( '%1$s · Built by %2$s', 'membuild-community' ),
			esc_html( 'MemBuild - Community ' . MEMBUILD_COMMUNITY_VERSION ),
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
		// site running two of them loads it once, and the newest copy wins.
		if ( ! wp_style_is( 'membuild-admin', 'registered' ) || version_compare( (string) wp_styles()->registered['membuild-admin']->ver, self::SYSTEM_VERSION, '<' ) ) {
			wp_deregister_style( 'membuild-admin' );
			wp_register_style( 'membuild-admin', MEMBUILD_COMMUNITY_URL . 'assets/admin/membuild-admin.css', array(), self::SYSTEM_VERSION );
		}
		wp_enqueue_style( 'membuild-admin' );
		wp_register_style( 'mbcom-admin', false, array( 'membuild-admin' ), MEMBUILD_COMMUNITY_VERSION );
		wp_enqueue_style( 'mbcom-admin' );
		wp_add_inline_style( 'mbcom-admin', self::css() );
		wp_register_script( 'mbcom-admin', false, array(), MEMBUILD_COMMUNITY_VERSION, true );
		wp_enqueue_script( 'mbcom-admin' );
		wp_add_inline_script( 'mbcom-admin', self::js() );
	}

	/** The chart's hover card: one for the page, moved to whichever day is under the pointer or focused. */
	private static function js() {
		return <<<'JS'
(function () {
	document.querySelectorAll('.mbcom-chart-wrap').forEach(function (wrap) {
		var tip = wrap.querySelector('.mbcom-tip');
		var svg = wrap.querySelector('svg');
		if (!tip || !svg) return;
		function show(day) {
			['date', 'posts', 'comments', 'joined'].forEach(function (k) {
				tip.querySelector('[data-tip="' + k + '"]').textContent = day.getAttribute('data-' + k);
			});
			tip.hidden = false;
			var box = day.querySelector('.hit').getBoundingClientRect();
			var host = wrap.getBoundingClientRect();
			// Just above the day's bar (its tallest piece), never off the card's sides.
			var top = box.bottom;
			day.querySelectorAll('rect.p, rect.c').forEach(function (r) { if (r.getBBox().height > 0) top = Math.min(top, r.getBoundingClientRect().top); });
			var left = box.left - host.left + box.width / 2;
			left = Math.max(tip.offsetWidth / 2 + 4, Math.min(left, host.width - tip.offsetWidth / 2 - 4));
			tip.style.left = left + 'px';
			tip.style.top = Math.max(0, top - host.top - 8) + 'px';
			svg.querySelectorAll('.day.is-on').forEach(function (d) { d.classList.remove('is-on'); });
			day.classList.add('is-on');
		}
		function hide() {
			tip.hidden = true;
			svg.querySelectorAll('.day.is-on').forEach(function (d) { d.classList.remove('is-on'); });
		}
		svg.addEventListener('pointerover', function (e) { var day = e.target.closest('.day'); if (day) show(day); });
		svg.addEventListener('pointerleave', hide);
		svg.addEventListener('focusin', function (e) { var day = e.target.closest('.day'); if (day) show(day); });
		svg.addEventListener('focusout', hide);
	});
})();
JS;
	}

	/**
	 * Draws the menu icon in color: the community's logo, or its initials on its
	 * accent color. Done in CSS because WordPress repaints SVG menu icons gray.
	 */
	/** "Settings" and "Open community ↗" in the WP submenu lead to the community itself, so they open a new tab. */
	public static function menu_links() {
		wp_add_inline_script(
			'common',
			'document.querySelectorAll(\'#toplevel_page_membuild-community a[href$="page=membuild-community-settings"], #toplevel_page_membuild-community a[href$="page=membuild-community-open"]\').forEach(function (a) { a.target = "_blank"; a.rel = "noopener"; a.href = a.href.indexOf("-settings") > -1 ? ' . wp_json_encode( self::app_url( 'settings' ) ) . ' : ' . wp_json_encode( self::app_url() ) . '; });',
			'after'
		);
	}

	public static function menu_icon() {
		$logo = Settings::get( 'logo' )['url'] ?? '';
		if ( $logo ) {
			$image = 'url("' . esc_url_raw( $logo ) . '")';
		} else {
			$accent = Settings::get( 'accent' ) ?: '#3b5bfd';
			$ink    = Color::ink( $accent );
			$svg    = sprintf(
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" rx="10" fill="%1$s"/><text x="20" y="26.5" text-anchor="middle" font-family="-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif" font-size="17" font-weight="800" fill="%2$s">%3$s</text></svg>',
				esc_attr( $accent ),
				esc_attr( $ink ),
				esc_html( Settings::initials() )
			);
			$image = 'url("data:image/svg+xml,' . rawurlencode( $svg ) . '")';
		}
		wp_add_inline_style(
			'admin-menu',
			"#adminmenu #toplevel_page_membuild-community .wp-menu-image{background:{$image} center/20px 20px no-repeat;border-radius:5px}"
			. '#adminmenu #toplevel_page_membuild-community .wp-menu-image::before{content:none}'
			. '.folded #adminmenu #toplevel_page_membuild-community .wp-menu-image{background-size:22px 22px}'
		);
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

	/* ---------- The panel every page sits in ---------- */

	/** The tabs across the top of the panel: what members make, then admin. */
	private static function tabs() {
		return array(
			array( '', __( 'Overview', 'membuild-community' ) ),
			array( 'members', __( 'Members', 'membuild-community' ) ),
			array( 'posts', __( 'Posts', 'membuild-community' ) ),
			array( 'comments', __( 'Comments', 'membuild-community' ) ),
			array( 'files', __( 'Files', 'membuild-community' ) ),
			array( 'courses', __( 'Courses', 'membuild-community' ) ),
			array( '|', '' ),
			array( 'export', __( 'Export', 'membuild-community' ) ),
			array( 'problems', __( 'Problems', 'membuild-community' ) ),
			array( 'settings', __( 'Settings', 'membuild-community' ) ),
		);
	}

	/**
	 * "v1.8.3 · up to date", or the newer version on offer. Reads only what the
	 * update check already cached: a page never waits on GitHub.
	 */
	private static function version_badge() {
		$latest = get_site_transient( Updater::CACHE );
		$newer  = is_array( $latest ) && ! empty( $latest['version'] ) && version_compare( $latest['version'], MEMBUILD_COMMUNITY_VERSION, '>' );
		if ( $newer ) {
			/* translators: 1: installed version, 2: newer version */
			return '<a class="mb-badge mb-badge--warn" href="' . esc_url( admin_url( 'plugins.php' ) ) . '"><span class="mb-dot"></span>' . esc_html( sprintf( __( 'v%1$s · %2$s available', 'membuild-community' ), MEMBUILD_COMMUNITY_VERSION, $latest['version'] ) ) . '</a>';
		}
		/* translators: %s: the installed version */
		return '<span class="mb-badge mb-badge--ok"><span class="mb-dot"></span>' . esc_html( sprintf( __( 'v%s · up to date', 'membuild-community' ), MEMBUILD_COMMUNITY_VERSION ) ) . '</span>';
	}

	/**
	 * Opens the panel: tile and name, version, the tabs, then the page's title,
	 * its one line and its actions. $current is the tab key ('' = overview).
	 */
	public static function shell_open( $current, $title, $desc = '', $actions = '', $before_head = '' ) {
		$ext = '<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3H3v10h10v-3M9 3h4v4M13 3L7 9"/></svg>';
		echo '<div class="wrap mbcom-admin ' . esc_attr( $current ?: 'overview' ) . '"><div class="mb-panel">';
		printf(
			'<div class="mb-head"><div class="mb-brand"><span class="mb-tile" aria-hidden="true">MB</span><span class="mb-brand__name">%s</span></div><div>%s</div></div>',
			esc_html__( 'MemBuild Community', 'membuild-community' ),
			self::version_badge() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
		);
		echo '<nav class="mb-tabs" aria-label="' . esc_attr__( 'Community pages', 'membuild-community' ) . '">';
		foreach ( self::tabs() as list( $key, $label ) ) {
			if ( '|' === $key ) {
				echo '<span class="mb-tabs__sep" aria-hidden="true"></span>';
				continue;
			}
			if ( 'settings' === $key ) {
				printf( '<a class="mb-tab" href="%s" target="_blank" rel="noopener">%s %s</a>', esc_url( self::app_url( 'settings' ) ), esc_html( $label ), $ext ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg
				continue;
			}
			printf( '<a class="mb-tab" href="%s"%s>%s</a>', esc_url( self::url( $key ) ), $key === $current ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';
		echo $before_head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller
		if ( '' !== $title ) { // the setup guide brings its own heading
			printf(
				'<div class="mb-page__head"><div><h1 class="mb-page__title">%s</h1>%s</div>%s</div>',
				esc_html( $title ),
				$desc ? '<p class="mb-page__desc">' . wp_kses( $desc, array( 'a' => array( 'href' => array() ), 'strong' => array() ) ) . '</p>' : '',
				$actions ? '<div class="mb-page__actions">' . $actions . '</div>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller
			);
		}
		self::notice();
	}

	/** Closes the panel with the one-line footer. */
	public static function shell_close() {
		printf(
			'<div class="mb-foot"><span>%s</span><span>%s</span></div></div></div>',
			sprintf(
				/* translators: %s: "MemBuild", linked */
				esc_html__( 'Made by %s · the team that runs membership sites.', 'membuild-community' ),
				'<a href="https://membuild.com" target="_blank" rel="noopener">MemBuild</a>'
			),
			sprintf(
				/* translators: %s: a link to membuild.com */
				esc_html__( 'Need a hand? %s', 'membuild-community' ),
				'<a href="https://membuild.com/community" target="_blank" rel="noopener">membuild.com</a>'
			)
		);
	}

	/** A quiet "Open community ↗" button for the page's actions. */
	private static function open_button() {
		return '<a class="mb-btn mb-btn--quiet" href="' . esc_url( self::app_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open community', 'membuild-community' ) . ' <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3H3v10h10v-3M9 3h4v4M13 3L7 9"/></svg></a>';
	}

	/* ---------- Overview ---------- */

	/**
	 * The overview's totals. Counting big tables is slow on small hosts, so they're
	 * reused for a few minutes; reports waiting are always live.
	 */
	private static function totals() {
		global $wpdb;
		$totals = get_transient( 'mbcom_admin_totals' );
		if ( is_array( $totals ) && isset( $totals['week'] ) ) { // saved by this version (older ones had no weekly counts)
			return $totals;
		}
		$posts  = Install::table( 'posts' );
		$totals = array(
			'members'  => Helpers::member_total(),
			'active'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_active >= %s', Install::table( 'member_stats' ), gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) ) ),
			'posts'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND type = 'post'", $posts ) ),
			'reels'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND type = 'reel'", $posts ) ),
			'comments' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'comments' ) ) ),
			'messages' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'sent'", Install::table( 'messages' ) ) ),
			'storage'  => self::storage_bytes(),
		);
		// The same counts for the last seven days, shown under each number.
		$week            = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$totals['week']  = array(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- site_members_sql() returns a prepared fragment.
			'members'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i u WHERE u.user_registered >= %s' . Helpers::site_members_sql(), $wpdb->users, $week ) ),
			'posts'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND created_at >= %s", $posts, $week ) ),
			'comments' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', Install::table( 'comments' ), $week ) ),
		);
		set_transient( 'mbcom_admin_totals', $totals, 5 * MINUTE_IN_SECONDS );
		return $totals;
	}

	/** The six steps, one main button on the next one, a thin progress bar (see First_Run). */
	private static function setup_guide() {
		$done  = First_Run::owner_steps();
		$steps = array(
			'look'   => array( __( 'Name, logo and color', 'membuild-community' ), __( 'How your community looks to members.', 'membuild-community' ), __( 'Choose', 'membuild-community' ), self::app_url( 'settings#s-general' ), 'M4 20l4-1 10-10-3-3L5 16zM14 7l3 3' ),
			'email'  => array( __( 'Choose how emails are sent', 'membuild-community' ), __( 'So sign-up and password emails arrive, not land in spam.', 'membuild-community' ), __( 'Choose', 'membuild-community' ), self::app_url( 'settings#s-email' ), 'M3 6h18v12H3zM3 7l9 7 9-7' ),
			'key'    => array( __( 'Save your safety key', 'membuild-community' ), __( 'Keeps private messages readable if your site ever moves to a new host.', 'membuild-community' ), __( 'Save the key', 'membuild-community' ), self::app_url( 'settings?s=advanced#s-key' ), 'M15 7a4 4 0 1 1-3.9 5H7v3H4v-3H3v-3h8.1A4 4 0 0 1 15 7z' ),
			'course' => array( __( 'Add your first course', 'membuild-community' ), __( 'Skip this if you only want a community for now.', 'membuild-community' ), __( 'Create a course', 'membuild-community' ), self::app_url( 'classroom' ), 'M2 9l10-5 10 5-10 5zM6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5' ),
			'post'   => array( __( 'Write a welcome post', 'membuild-community' ), __( 'The first thing new members see. Say hello and what to expect.', 'membuild-community' ), __( 'Write it', 'membuild-community' ), self::app_url( '?write=1' ), 'M4 20l4-1 10-10-3-3L5 16z' ),
			'invite' => array( __( 'Invite your members', 'membuild-community' ), __( 'Share your join link. People with an account on your site can already log in.', 'membuild-community' ), __( 'Invite', 'membuild-community' ), self::app_url( '?invite=1' ), 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 7.5M18 14.5a7 7 0 0 1 4 6.5' ),
		);
		$count = count( array_filter( $done ) );
		$next  = '';
		foreach ( $steps as $key => $st ) {
			if ( empty( $done[ $key ] ) && 'course' !== $key ) {
				$next = $key;
				break;
			}
		}
		echo '<section class="mb-card mbcom-guide">';
		printf(
			'<div class="mbcom-guide-head"><div><h1>%s</h1><p>%s</p></div>%s</div>',
			esc_html__( 'Welcome! Let’s get your community ready', 'membuild-community' ),
			wp_kses(
				sprintf(
					/* translators: %s: the community's address */
					__( 'Your community is already live at %s. These six steps take about 10 minutes. You can stop and come back any time.', 'membuild-community' ),
					'<a href="' . esc_url( self::app_url() ) . '"><strong>' . esc_html( wp_parse_url( self::app_url(), PHP_URL_HOST ) . '/' . Settings::slug() ) . '</strong></a>'
				),
				array( 'a' => array( 'href' => true ), 'strong' => array() )
			),
			self::open_button() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
		);
		/* translators: 1: steps done, 2: all steps */
		printf( '<div class="mbcom-guide-bar"><span><i style="width:%d%%"></i></span><b>%s</b></div>', (int) round( 100 * $count / count( $steps ) ), esc_html( sprintf( __( '%1$d of %2$d done', 'membuild-community' ), $count, count( $steps ) ) ) );
		echo '<ol class="mbcom-guide-steps">';
		$n = 0;
		foreach ( $steps as $key => $st ) {
			++$n;
			$is_done = ! empty( $done[ $key ] );
			$tag     = 'email' === $key && ! $is_done ? '<span class="mbcom-guide-tag warn">' . esc_html__( 'Do this before inviting anyone', 'membuild-community' ) . '</span>' : ( 'course' === $key ? '<span class="mbcom-guide-tag">' . esc_html__( 'Optional', 'membuild-community' ) . '</span>' : '' );
			printf(
				'<li class="%s"><span class="mbcom-guide-n">%s</span><svg class="mbcom-guide-ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="%s"/></svg><span class="mbcom-guide-words"><strong>%s</strong>%s<small>%s</small></span>%s</li>',
				$is_done ? 'done' : ( $key === $next ? 'next' : '' ),
				$is_done ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>' : (int) $n,
				esc_attr( $st[4] ),
				esc_html( $st[0] ),
				$tag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
				esc_html( $st[1] ),
				$is_done ? '<span class="mbcom-guide-done">' . esc_html__( 'Done', 'membuild-community' ) . '</span>' : '<a class="mbcom-guide-btn' . ( $key === $next ? ' main' : '' ) . '" href="' . esc_url( $st[3] ) . '">' . esc_html( $st[2] ) . '</a>'
			);
		}
		echo '</ol></section>';
	}

	public static function page_overview() {
		$t       = self::totals();
		$week    = $t['week'];
		$reports = Moderation::open_count();
		/* translators: %s: a number */
		$this_week = fn( $n ) => $n ? sprintf( __( '+%s this week', 'membuild-community' ), number_format_i18n( $n ) ) : __( 'None this week', 'membuild-community' );
		$share     = $t['members'] ? (int) round( 100 * $t['active'] / $t['members'] ) : 0;
		/* translators: %s: share of all members who were active this week, e.g. "38" */
		$active_share = sprintf( __( '%s%% of members', 'membuild-community' ), number_format_i18n( $share ) );
		/* translators: %s: number of reels */
		$reels = $t['reels'] ? sprintf( _n( '%s is a reel', '%s are reels', $t['reels'], 'membuild-community' ), number_format_i18n( $t['reels'] ) ) : '';
		$cards = array(
			// label, value, chip, note after the chip, link, chip is good news
			array( __( 'Members', 'membuild-community' ), $t['members'], $this_week( $week['members'] ), '', self::url( 'members' ), (bool) $week['members'] ),
			array( __( 'Active this week', 'membuild-community' ), $t['active'], $active_share, '', self::url( 'members', array( 'orderby' => 'last_active', 'order' => 'desc' ) ), $t['active'] > 0 ),
			array( __( 'Posts', 'membuild-community' ), $t['posts'] + $t['reels'], $this_week( $week['posts'] ), $reels, self::url( 'posts' ), (bool) $week['posts'] ),
			array( __( 'Comments', 'membuild-community' ), $t['comments'], $this_week( $week['comments'] ), '', self::url( 'comments' ), (bool) $week['comments'] ),
		);

		// A new community: the six-step setup guide comes first, then Keep me updated.
		// The numbers show once people have joined; nothing is blurred.
		$guide = Helpers::can_manage() && ! First_Run::owner_done();
		$staff = count( get_users( array( 'capability' => 'moderate_comments', 'fields' => 'ID', 'number' => 50 ) ) );
		$quiet = $guide && $t['members'] <= $staff; // nobody but the team yet
		$gated = false;
		if ( $guide ) {
			self::shell_open( '', '', '', '' );
			self::setup_guide();
		} else {
			self::shell_open(
				'',
				Settings::name(),
				sprintf(
					/* translators: %s: the community's address, linked */
					__( 'Your community page is live at %s. Here is what happened in it.', 'membuild-community' ),
					'<a href="' . esc_url( self::app_url() ) . '">/' . esc_html( Settings::slug() ) . '/</a>'
				),
				self::open_button(),
				Health::key_card()
			);
		}
		Usage::setup_card();
		if ( $quiet ) {
			printf( '<p class="mbcom-guide-note"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> %s</p>', esc_html__( 'Your numbers (members, posts, activity) show here once people join. This guide hides itself when all six steps are done.', 'membuild-community' ) );
			self::shell_close();
			return;
		}
		if ( $gated ) {
			$urgent = array();
			if ( Problems::strip_item() ) {
				$urgent[] = Problems::strip_item();
			}
			if ( $reports ) {
				/* translators: %s: number of reports */
				$urgent[] = '<a class="mb-badge mb-badge--danger" href="' . esc_url( self::app_url( 'moderation' ) ) . '">' . esc_html( sprintf( _n( '%s report waiting', '%s reports waiting', $reports, 'membuild-community' ), number_format_i18n( $reports ) ) ) . ' · ' . esc_html__( 'Review', 'membuild-community' ) . '</a>';
			}
			foreach ( array( 'permalinks', 'cron', 'https', 'images', 'uploads', 'email' ) as $key ) {
				if ( 'critical' === Health::check( $key )[0] ) {
					$urgent[] = '<a class="mb-badge mb-badge--danger" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Something on your hosting needs fixing', 'membuild-community' ) . ' · ' . esc_html__( 'Open Site Health', 'membuild-community' ) . '</a>';
					break;
				}
			}
			if ( $urgent ) {
				echo '<div class="mbcom-strip mbcom-urgent">' . implode( '', $urgent ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts
			}
			echo '<div class="mbcom-gated" inert aria-hidden="true">';
		}

		echo '<div class="mbcom-stats">';
		foreach ( $cards as list( $label, $value, $chip, $note, $link, $up ) ) {
			printf(
				'<a class="mb-stat" href="%s"><span class="mb-label">%s</span><span class="mb-stat__n">%s</span><span class="mb-stat__l"><span class="mb-badge %s">%s</span>%s</span></a>',
				esc_url( $link ),
				esc_html( $label ),
				esc_html( number_format_i18n( $value ) ),
				$up ? 'mb-badge--ok' : 'mb-badge--neutral',
				esc_html( $chip ),
				$note ? '<span>' . esc_html( $note ) . '</span>' : ''
			);
		}
		echo '</div>';

		// The quieter numbers as pills; reports get attention only when there are some.
		echo '<div class="mbcom-strip">';
		echo Problems::strip_item(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped there
		if ( $reports ) {
			/* translators: %s: number of reports */
			printf( '<a class="mb-badge mb-badge--danger" href="%s">%s · %s</a>', esc_url( self::app_url( 'moderation' ) ), esc_html( sprintf( _n( '%s report waiting', '%s reports waiting', $reports, 'membuild-community' ), number_format_i18n( $reports ) ) ), esc_html__( 'Review', 'membuild-community' ) );
		} else {
			printf( '<span class="mb-badge mb-badge--neutral"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8.5l3 3 7-7"/></svg> %s</span>', esc_html__( 'No reports waiting', 'membuild-community' ) );
		}
		/* translators: %s: number of private messages */
		printf( '<span class="mb-badge mb-badge--neutral">%s</span>', esc_html( sprintf( _n( '%s private message, never shown', '%s private messages, never shown', $t['messages'], 'membuild-community' ), number_format_i18n( $t['messages'] ) ) ) );
		/* translators: %s: storage size, e.g. "68 MB" */
		printf( '<a class="mb-badge mb-badge--neutral" href="%s">%s</a>', esc_url( self::url( 'files' ) ), esc_html( sprintf( __( '%s of files', 'membuild-community' ), size_format( $t['storage'] ) ) ) );
		echo '</div>';

		echo '<div class="mbcom-grid"><div class="mbcom-column"><section class="mb-card mbcom-chart-card">';
		self::activity_chart();
		echo '</section>';
		self::health_banner();
		echo '</div><section class="mb-card mbcom-activity-card">';
		printf( '<div class="mb-card__head"><h2 class="mb-card__title">%s</h2><a class="mb-link" href="%s">%s</a></div>', esc_html__( 'Latest activity', 'membuild-community' ), esc_url( self::url( 'posts' ) ), esc_html__( 'All posts', 'membuild-community' ) );
		self::recent_activity();
		echo '</section></div>';

		printf(
			'<div class="mb-inset">%s</div>',
			sprintf(
				/* translators: %s: "mbcom_" in a code chip */
				esc_html__( 'All of this is stored in your own WordPress database, in tables that start with %s. Private messages are counted here but never shown; reported messages and posts reach moderators in the community’s Moderation page.', 'membuild-community' ),
				'<span class="mb-code">mbcom_</span>'
			)
		);
		if ( $gated ) {
			echo '</div>';
		}
		self::shell_close();
	}

	/** Problems Site Health found, with a link there. */
	private static function health_banner() {
		$issues = array();
		foreach ( array( 'permalinks', 'cron', 'https', 'images', 'uploads', 'email' ) as $key ) {
			list( $status, $text ) = Health::check( $key );
			if ( 'good' !== $status ) {
				$issues[] = array( $status, $text );
			}
		}
		if ( ! $issues ) {
			return;
		}
		// Folded into one line unless something is broken: most of these are worth fixing, not urgent.
		$critical = (bool) array_filter( $issues, fn( $i ) => 'critical' === $i[0] );
		printf(
			'<details class="mb-notice %s mbcom-checks"%s><summary><svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M8 7v4M8 5v.01"/></svg> %s</summary><ul>',
			$critical ? 'mb-notice--danger' : 'mb-notice--warn',
			$critical ? ' open' : '',
			/* translators: %s: number of things to check */
			esc_html( sprintf( _n( '%s thing to check on your hosting', '%s things to check on your hosting', count( $issues ), 'membuild-community' ), number_format_i18n( count( $issues ) ) ) )
		);
		foreach ( $issues as list( $status, $text ) ) {
			echo '<li class="' . esc_attr( $status ) . '">' . wp_kses_post( $text ) . '</li>';
		}
		echo '</ul><p><a class="mb-link" href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Open Site Health', 'membuild-community' ) . '</a></p></details>';
	}

	/** Daily posts, comments and new members, as a simple bar chart (no scripts, no outside services). */
	private static function activity_chart() {
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$days  = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$days[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = array( 0, 0, 0 );
		}
		$series = array(
			0 => $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, COUNT(*) n FROM %i WHERE status = 'published' AND created_at >= %s GROUP BY d", Install::table( 'posts' ), $since ), ARRAY_A ),
			1 => $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(created_at) d, COUNT(*) n FROM %i WHERE created_at >= %s GROUP BY d', Install::table( 'comments' ), $since ), ARRAY_A ),
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- site_members_sql() returns a prepared fragment.
			2 => $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(u.user_registered) d, COUNT(*) n FROM %i u WHERE u.user_registered >= %s' . Helpers::site_members_sql() . ' GROUP BY d', $wpdb->users, $since ), ARRAY_A ),
		);
		foreach ( $series as $k => $rows ) {
			foreach ( $rows as $r ) {
				if ( isset( $days[ $r['d'] ] ) ) {
					$days[ $r['d'] ][ $k ] = (int) $r['n'];
				}
			}
		}
		$sum = fn( $k ) => array_sum( array_column( $days, $k ) );
		// The totals double as the legend: each pill carries its series' colour.
		printf(
			'<div class="mb-card__head"><div><h2 class="mb-card__title">%s</h2><p class="mb-card__sub">%s</p></div></div><div class="mbcom-legend"><span class="mbcom-pill"><i class="p"></i>%s</span><span class="mbcom-pill"><i class="c"></i>%s</span><span class="mbcom-pill"><i class="j"></i>%s</span></div>',
			esc_html__( 'Last 30 days', 'membuild-community' ),
			esc_html__( 'Posts and comments per day; a dot is a day someone joined.', 'membuild-community' ),
			/* translators: %s: number of posts */
			esc_html( sprintf( _n( '%s post', '%s posts', $sum( 0 ), 'membuild-community' ), number_format_i18n( $sum( 0 ) ) ) ),
			/* translators: %s: number of comments, e.g. "12" or "1.2K" */
			esc_html( sprintf( _n( '%s comment', '%s comments', $sum( 1 ), 'membuild-community' ), number_format_i18n( $sum( 1 ) ) ) ),
			/* translators: %s: number of new members */
			esc_html( sprintf( _n( '%s new member', '%s new members', $sum( 2 ), 'membuild-community' ), number_format_i18n( $sum( 2 ) ) ) )
		);
		$max = max( 1, max( array_map( fn( $v ) => $v[0] + $v[1], $days ) ) );
		$w   = 18;
		$h   = 140;
		$svg = '';
		$x   = 0;
		foreach ( $days as $day => list( $posts, $comments, $joined ) ) {
			$hp   = round( $posts / $max * $h );
			$hc   = round( $comments / $max * $h );
			/* translators: 1: date, 2: posts, 3: comments, 4: new members */
			$tip  = sprintf( __( '%1$s: %2$d posts, %3$d comments, %4$d new members', 'membuild-community' ), date_i18n( get_option( 'date_format' ), strtotime( $day ) ), $posts, $comments, $joined );
			$svg .= '<g class="day" tabindex="0" role="img" aria-label="' . esc_attr( $tip ) . '" data-date="' . esc_attr( date_i18n( 'D, M j', strtotime( $day ) ) ) . '" data-posts="' . (int) $posts . '" data-comments="' . (int) $comments . '" data-joined="' . (int) $joined . '">';
			$svg .= '<rect x="' . $x . '" y="-14" width="' . $w . '" height="' . ( $h + 28 ) . '" class="hit"/>';
			$svg .= '<rect x="' . $x . '" y="' . ( $h - $hp - $hc ) . '" width="' . ( $w - 4 ) . '" height="' . $hc . '" class="c"/>';
			$svg .= '<rect x="' . $x . '" y="' . ( $h - $hp ) . '" width="' . ( $w - 4 ) . '" height="' . $hp . '" class="p"/>';
			if ( $joined ) {
				$svg .= '<circle cx="' . ( $x + ( $w - 4 ) / 2 ) . '" cy="' . ( $h + 8 ) . '" r="3" class="j"/>';
			}
			$svg .= '</g>';
			$x   += $w;
		}
		// A faint line at the busiest day, labelled, so the bars have a scale.
		$grid = '<line x1="0" x2="' . ( 30 * $w ) . '" y1="0.5" y2="0.5" class="g"/><text x="' . ( 30 * $w ) . '" y="-4" class="t" text-anchor="end">' . (int) $max . '</text>';
		echo '<div class="mbcom-chart-wrap"><svg class="mbcom-chart" viewBox="0 -14 ' . ( 30 * $w ) . ' ' . ( $h + 28 ) . '" role="group" aria-label="' . esc_attr__( 'Posts and comments per day for the last 30 days', 'membuild-community' ) . '">' . $grid . $svg . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numbers and escaped titles only
		printf(
			'<div class="mbcom-tip" role="tooltip" hidden><strong data-tip="date"></strong><span><i class="p"></i><b data-tip="posts"></b> %s</span><span><i class="c"></i><b data-tip="comments"></b> %s</span><span><i class="j"></i><b data-tip="joined"></b> %s</span></div></div>',
			esc_html__( 'posts', 'membuild-community' ),
			esc_html__( 'comments', 'membuild-community' ),
			esc_html__( 'new members', 'membuild-community' )
		);
		printf(
			'<div class="mbcom-axis"><span>%s</span><span>%s</span></div>',
			esc_html( date_i18n( 'M j', strtotime( array_key_first( $days ) ) ) ),
			esc_html__( 'Today', 'membuild-community' )
		);
	}

	private static function recent_activity() {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- site_members_sql() returns a prepared fragment.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"(SELECT 'post' AS kind, id, user_id, content, created_at, id AS post_id FROM %i WHERE status = 'published' ORDER BY id DESC LIMIT 8)
				UNION ALL (SELECT 'comment', id, user_id, content, created_at, post_id FROM %i ORDER BY id DESC LIMIT 8)
				UNION ALL (SELECT 'join', u.ID, u.ID, '', u.user_registered, 0 FROM %i u WHERE 1=1" . Helpers::site_members_sql() . " ORDER BY u.ID DESC LIMIT 4)
				ORDER BY created_at DESC LIMIT 8",
				Install::table( 'posts' ),
				Install::table( 'comments' ),
				$wpdb->users
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing yet.', 'membuild-community' ) . '</p>';
			return;
		}
		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		echo '<ul class="mbcom-activity">';
		foreach ( $rows as $r ) {
			$user = $users[ (int) $r['user_id'] ] ?? null;
			$name = '<strong>' . esc_html( $user['name'] ?? __( 'Someone', 'membuild-community' ) ) . '</strong>';
			if ( 'join' === $r['kind'] ) {
				/* translators: %s: member name */
				$text = sprintf( esc_html__( '%s joined', 'membuild-community' ), $name );
			} else {
				$quote = '<a href="' . esc_url( self::app_url( '?post=' . (int) $r['post_id'] ) ) . '">' . esc_html( wp_trim_words( wp_strip_all_tags( (string) $r['content'] ), 10, '…' ) ?: __( 'a photo or video', 'membuild-community' ) ) . '</a>';
				$text  = sprintf(
					/* translators: 1: member name, 2: start of what they wrote, as a link */
					'post' === $r['kind'] ? esc_html__( '%1$s posted %2$s', 'membuild-community' ) : esc_html__( '%1$s commented %2$s', 'membuild-community' ),
					$name,
					$quote
				);
			}
			printf(
				'<li><span class="mb-av"><img src="%s" width="32" height="32" alt=""></span><span>%s<span class="mbcom-muted">%s</span></span></li>',
				esc_url( $user['avatar'] ?? get_avatar_url( 0, array( 'size' => 56 ) ) ),
				$text, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above
				/* translators: %s: time, e.g. "5 mins" */
				esc_html( sprintf( __( '%s ago', 'membuild-community' ), human_time_diff( strtotime( $r['created_at'] . ' UTC' ) ) ) )
			);
		}
		echo '</ul>';
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

		ob_start();
		$table->intro();
		$intro = trim( wp_strip_all_tags( ob_get_clean() ) );
		self::shell_open( $key, $table->title(), $intro, $table->title_actions() );
		echo '<div class="mb-card mbcom-table-card">';
		$table->views();
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'membuild-community-' . $key ) );
		foreach ( array( 'status', 'type', 'course' ) as $keep ) {
			if ( isset( $_GET[ $keep ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- keeps the current filter in the search form
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $keep ), esc_attr( sanitize_key( wp_unslash( $_GET[ $keep ] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}
		if ( $table->searchable() ) {
			$table->search_box( $table->search_label(), 'mbcom-search' );
		}
		echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mbcom_bulk_' . $key );
		printf( '<input type="hidden" name="action" value="mbcom_admin"><input type="hidden" name="table" value="%s"><input type="hidden" name="back" value="%s">', esc_attr( $key ), esc_attr( self::current_url() ) );
		$table->display();
		echo '</form></div>';
		self::shell_close();
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
			case 'posts:pin':
				return self::rest( 'PUT', "/posts/{$id}/pin" );
			case 'posts:unpin':
				return self::rest( 'DELETE', "/posts/{$id}/pin" );
			case 'comments:delete':
				return self::rest( 'DELETE', "/comments/{$id}" );
			case 'members:mute':
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
		$done = array( 'hide' => 'hidden', 'restore' => 'restored', 'delete' => 'deleted', 'pin' => 'pinned', 'unpin' => 'unpinned', 'mute' => 'muted', 'ban' => 'banned', 'lift' => 'lifted', 'groupadd' => 'grouped', 'groupremove' => 'ungrouped' );

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

	public static function datasets() {
		return array(
			'members'  => array( __( 'Members', 'membuild-community' ), __( 'Name, email, join date, level, points, streak, posts, comments, message and chat counts, and status.', 'membuild-community' ) ),
			'posts'    => array( __( 'Posts and reels', 'membuild-community' ), __( 'Every post with its author, text, reactions, comments, status and date.', 'membuild-community' ) ),
			'comments' => array( __( 'Comments', 'membuild-community' ), __( 'Every comment with its author, the post it’s on, and date.', 'membuild-community' ) ),
			'progress' => array( __( 'Course progress', 'membuild-community' ), __( 'Which member finished which lesson, and when.', 'membuild-community' ) ),
			'points'   => array( __( 'Points history', 'membuild-community' ), __( 'Every point earned or taken back, and why.', 'membuild-community' ) ),
			'files'    => array( __( 'Files', 'membuild-community' ), __( 'Every uploaded file, its size, who uploaded it and its address.', 'membuild-community' ) ),
		);
	}

	public static function page_export() {
		self::shell_open( 'export', __( 'Export community data', 'membuild-community' ), __( 'Download your community’s data as spreadsheets (CSV files open in Excel, Numbers and Google Sheets). Private message text is never exported; message counts are included with members.', 'membuild-community' ) );
		echo '<div class="mbcom-exports">';
		foreach ( self::datasets() as $key => list( $label, $desc ) ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'mbcom_export', 'dataset' => $key ), admin_url( 'admin-post.php' ) ), 'mbcom_export_' . $key );
			printf(
				'<div class="mb-card mbcom-export"><strong class="mbcom-export-title">%s</strong><span class="mb-help">%s</span><a class="mb-btn mb-btn--quiet mb-btn--sm" href="%s"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v8M4.5 6.5L8 10l3.5-3.5M3 13h10"/></svg> %s</a></div>',
				esc_html( $label ),
				esc_html( $desc ),
				esc_url( $url ),
				esc_html__( 'Download CSV', 'membuild-community' )
			);
		}
		echo '</div>';
		printf(
			'<section class="mb-card mbcom-privacy"><h2 class="mb-card__title">%s</h2><p class="mbcom-privacy-text">%s</p><p class="mbcom-privacy-actions"><a class="mb-btn mb-btn--quiet mb-btn--sm" href="%s">%s</a> <a class="mb-btn mb-btn--quiet mb-btn--sm" href="%s">%s</a></p></section>',
			esc_html__( 'A member asked for their data?', 'membuild-community' ),
			esc_html__( 'The community is part of WordPress’s privacy tools. Exporting a member’s personal data includes their profile, posts, comments, points and course progress. Erasing it removes their posts, comments, messages, stories, reactions and uploads.', 'membuild-community' ),
			esc_url( admin_url( 'export-personal-data.php' ) ),
			esc_html__( 'Export personal data', 'membuild-community' ),
			esc_url( admin_url( 'erase-personal-data.php' ) ),
			esc_html__( 'Erase personal data', 'membuild-community' )
		);
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
.mbcom-admin .mbcom-who{display:inline-flex;align-items:center;gap:8px;white-space:nowrap;font-weight:600}
.mbcom-admin .mbcom-who img{border-radius:50%}
.mbcom-admin .mbcom-thumb{width:44px;height:44px;object-fit:cover;border-radius:var(--radius-sm);background:var(--surface-200);border:1px solid var(--line-100);display:grid;place-items:center;flex:none;color:var(--ink-mute);font-weight:700;font-size:12px}
.mbcom-admin .mbcom-thumb-wide{width:72px;height:44px}
.mbcom-admin .mbcom-tag{display:inline-flex;align-items:center;gap:4px;height:22px;padding:0 8px;border-radius:var(--radius-sm);background:var(--surface-200);color:var(--ink-body);font:700 12px/16px var(--font-sans);white-space:nowrap}
.mbcom-admin .mbcom-tag .dashicons{font-size:13px;width:13px;height:13px}
.mbcom-admin .mbcom-tag.warn{background:var(--warn-tint);color:var(--warn)}.mbcom-admin .mbcom-tag.bad{background:var(--danger-tint);color:var(--danger)}.mbcom-admin .mbcom-tag.ok{background:var(--ok-tint);color:var(--ok)}
.mbcom-admin .mbcom-name{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px}
.mbcom-admin .mbcom-bar{height:6px;background:var(--surface-200);border-radius:3px;overflow:hidden;width:120px;display:inline-block;vertical-align:middle;margin-right:6px}
.mbcom-admin .mbcom-bar i{display:block;height:100%;background:var(--brand)}
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
.mbcom-admin .members .column-last_active,.mbcom-admin .members .column-joined{width:124px}
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
