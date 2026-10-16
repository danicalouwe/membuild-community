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
		add_submenu_page( 'membuild-community', __( 'Community settings', 'membuild-community' ), __( 'Settings', 'membuild-community' ), self::CAP, 'membuild-community-settings', '__return_null' );
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

	public static function styles( $hook ) {
		if ( false === strpos( (string) $hook, 'membuild-community' ) ) {
			return;
		}
		wp_register_style( 'mbcom-admin', false, array(), MEMBUILD_COMMUNITY_VERSION );
		wp_enqueue_style( 'mbcom-admin' );
		wp_add_inline_style( 'mbcom-admin', self::css() );
	}

	/**
	 * Draws the menu icon in color: the community's logo, or its initials on its
	 * accent color. Done in CSS because WordPress repaints SVG menu icons gray.
	 */
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

	public static function page_overview() {
		$t       = self::totals();
		$week    = $t['week'];
		$reports = Moderation::open_count();
		/* translators: %s: a number */
		$this_week = fn( $n ) => $n ? sprintf( __( '+%s this week', 'membuild-community' ), number_format_i18n( $n ) ) : __( 'None this week', 'membuild-community' );
		$share     = $t['members'] ? (int) round( 100 * $t['active'] / $t['members'] ) : 0;
		/* translators: %s: share of all members who were active this week, e.g. "38" */
		$active_share = sprintf( __( '%s%% of all members', 'membuild-community' ), number_format_i18n( $share ) );
		/* translators: %s: number of reels */
		$reels = sprintf( _n( '%s reel', '%s reels', $t['reels'], 'membuild-community' ), number_format_i18n( $t['reels'] ) );
		$cards = array(
			// label, value, chip, note after the chip, link, icon, chip is good news
			array( __( 'Members', 'membuild-community' ), $t['members'], $this_week( $week['members'] ), '', self::url( 'members' ), 'groups', (bool) $week['members'] ),
			array( __( 'Active this week', 'membuild-community' ), $t['active'], $active_share, '', self::url( 'members', array( 'orderby' => 'last_active', 'order' => 'desc' ) ), 'chart-bar', false ),
			array( __( 'Posts', 'membuild-community' ), $t['posts'] + $t['reels'], $this_week( $week['posts'] ), $reels, self::url( 'posts' ), 'admin-post', (bool) $week['posts'] ),
			array( __( 'Comments', 'membuild-community' ), $t['comments'], $this_week( $week['comments'] ), '', self::url( 'comments' ), 'admin-comments', (bool) $week['comments'] ),
		);

		echo '<div class="wrap mbcom-admin">';
		printf( '<h1 class="wp-heading-inline">%s</h1> <a class="page-title-action" href="%s">%s</a>', esc_html( Settings::name() ), esc_url( self::app_url() ), esc_html__( 'Open community', 'membuild-community' ) );
		echo '<hr class="wp-header-end">';

		// Keep me updated: once, and once more two weeks after “Not now”. While it's
		// up, the numbers sit blurred behind it; either button clears the way. Anything
		// urgent (reports, a broken host) is shown above the blur, never behind it.
		$gated = Helpers::can_manage() && Usage::card_due();
		Usage::setup_card();
		if ( $gated ) {
			$urgent = array();
			if ( $reports ) {
				/* translators: %s: number of reports */
				$urgent[] = '<li class="mbcom-alert"><span class="dashicons dashicons-flag" aria-hidden="true"></span> ' . esc_html( sprintf( _n( '%s report waiting', '%s reports waiting', $reports, 'membuild-community' ), number_format_i18n( $reports ) ) ) . ' <a href="' . esc_url( self::app_url( 'moderation' ) ) . '">' . esc_html__( 'Review', 'membuild-community' ) . '</a></li>';
			}
			foreach ( array( 'permalinks', 'cron', 'https', 'images', 'uploads', 'email' ) as $key ) {
				if ( 'critical' === Health::check( $key )[0] ) {
					$urgent[] = '<li class="mbcom-alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__( 'Something on your hosting needs fixing', 'membuild-community' ) . ' <a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Open Site Health', 'membuild-community' ) . '</a></li>';
					break;
				}
			}
			if ( $urgent ) {
				echo '<ul class="mbcom-strip mbcom-urgent">' . implode( '', $urgent ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts
			}
			echo '<div class="mbcom-gated" inert aria-hidden="true">';
		}

		echo '<div class="mbcom-cards mbcom-stats">';
		foreach ( $cards as list( $label, $value, $chip, $note, $link, $icon, $up ) ) {
			printf(
				'<a class="mbcom-card mbcom-stat" href="%s"><span class="mbcom-stat-icon"><span class="dashicons dashicons-%s" aria-hidden="true"></span></span><span class="mbcom-card-label">%s</span><span class="mbcom-card-value">%s</span><span class="mbcom-card-foot"><span class="mbcom-card-sub%s">%s</span>%s</span></a>',
				esc_url( $link ),
				esc_attr( $icon ),
				esc_html( $label ),
				esc_html( number_format_i18n( $value ) ),
				$up ? ' is-up' : '',
				esc_html( $chip ),
				$note ? '<span class="mbcom-card-note">' . esc_html( $note ) . '</span>' : ''
			);
		}
		echo '</div>';

		// The quieter numbers on one line; reports get attention only when there are some.
		echo '<ul class="mbcom-strip">';
		printf(
			'<li class="%s"><span class="dashicons dashicons-flag" aria-hidden="true"></span> %s%s</li>',
			$reports ? 'mbcom-alert' : '',
			/* translators: %s: number of reports */
			esc_html( $reports ? sprintf( _n( '%s report waiting', '%s reports waiting', $reports, 'membuild-community' ), number_format_i18n( $reports ) ) : __( 'No reports waiting', 'membuild-community' ) ),
			$reports ? ' <a href="' . esc_url( self::app_url( 'moderation' ) ) . '">' . esc_html__( 'Review', 'membuild-community' ) . '</a>' : ''
		);
		/* translators: %s: number of private messages */
		printf( '<li><span class="dashicons dashicons-lock" aria-hidden="true"></span> %s</li>', esc_html( sprintf( _n( '%s private message', '%s private messages', $t['messages'], 'membuild-community' ), number_format_i18n( $t['messages'] ) ) ) );
		/* translators: %s: storage size, e.g. "68 MB" */
		printf( '<li><span class="dashicons dashicons-database" aria-hidden="true"></span> <a href="%s">%s</a></li>', esc_url( self::url( 'files' ) ), esc_html( sprintf( __( '%s of files', 'membuild-community' ), size_format( $t['storage'] ) ) ) );
		echo '</ul>';

		echo '<div class="mbcom-grid"><div class="mbcom-column"><section class="mbcom-panel">';
		self::activity_chart();
		echo '</section>';
		self::health_banner();
		echo '</div><section class="mbcom-panel">';
		printf( '<h2>%s</h2>', esc_html__( 'Latest activity', 'membuild-community' ) );
		self::recent_activity();
		echo '</section></div>';

		printf(
			'<p class="mbcom-note">%s</p>',
			esc_html__( 'All of this is stored in your own WordPress database, in tables that start with “mbcom_”. Private messages are counted here but never shown; reported messages and posts reach moderators in the community’s Moderation page.', 'membuild-community' )
		);
		if ( $gated ) {
			echo '</div>';
		}
		echo '</div>';
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
			'<details class="mbcom-panel mbcom-checks%s"%s><summary><span class="dashicons %s" aria-hidden="true"></span> %s</summary><ul>',
			$critical ? ' is-critical' : '',
			$critical ? ' open' : '',
			$critical ? 'dashicons-warning' : 'dashicons-info-outline',
			/* translators: %s: number of things to check */
			esc_html( sprintf( _n( '%s thing to check on your hosting', '%s things to check on your hosting', count( $issues ), 'membuild-community' ), number_format_i18n( count( $issues ) ) ) )
		);
		foreach ( $issues as list( $status, $text ) ) {
			echo '<li class="' . esc_attr( $status ) . '">' . wp_kses_post( $text ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Open Site Health', 'membuild-community' ) . '</a></p></details>';
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
			'<h2>%s <span class="mbcom-legend"><span class="mbcom-pill"><i class="p"></i>%s</span><span class="mbcom-pill"><i class="c"></i>%s</span><span class="mbcom-pill"><i class="j"></i>%s</span></span></h2>',
			esc_html__( 'Last 30 days', 'membuild-community' ),
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
			$svg .= '<g><title>' . esc_html( $tip ) . '</title>';
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
		echo '<svg class="mbcom-chart" viewBox="0 -14 ' . ( 30 * $w ) . ' ' . ( $h + 28 ) . '" role="img" aria-label="' . esc_attr__( 'Posts and comments per day for the last 30 days', 'membuild-community' ) . '">' . $grid . $svg . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numbers and escaped titles only
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
				'<li><img src="%s" width="28" height="28" alt=""><span>%s<span class="mbcom-muted">%s</span></span></li>',
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

		echo '<div class="wrap mbcom-admin">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html( $table->title() ) );
		$extra = $table->title_actions();
		if ( $extra ) {
			echo ' ' . $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in the table class
		}
		echo '<hr class="wp-header-end">';
		self::notice();
		$table->intro();
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
	}

	public static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return remove_query_arg( array( 'mbcom_done', 'mbcom_count', '_wpnonce' ), $uri );
	}

	private static function notice() {
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
		echo '<div class="wrap mbcom-admin">';
		printf( '<h1>%s</h1>', esc_html__( 'Export community data', 'membuild-community' ) );
		printf( '<p class="mbcom-intro">%s</p>', esc_html__( 'Download your community’s data as spreadsheets (CSV files open in Excel, Numbers and Google Sheets). Private message text is never exported; message counts are included with members.', 'membuild-community' ) );
		$icons = array( 'members' => 'groups', 'posts' => 'admin-post', 'comments' => 'admin-comments', 'progress' => 'welcome-learn-more', 'points' => 'star-filled', 'files' => 'media-default' );
		echo '<div class="mbcom-cards mbcom-exports">';
		foreach ( self::datasets() as $key => list( $label, $desc ) ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'mbcom_export', 'dataset' => $key ), admin_url( 'admin-post.php' ) ), 'mbcom_export_' . $key );
			printf(
				'<div class="mbcom-card mbcom-export"><span class="mbcom-stat-icon"><span class="dashicons dashicons-%s" aria-hidden="true"></span></span><strong>%s</strong><span class="mbcom-muted">%s</span><a class="button" href="%s"><span class="dashicons dashicons-download" aria-hidden="true"></span> %s</a></div>',
				esc_attr( $icons[ $key ] ?? 'media-spreadsheet' ),
				esc_html( $label ),
				esc_html( $desc ),
				esc_url( $url ),
				esc_html__( 'Download CSV', 'membuild-community' )
			);
		}
		echo '</div>';
		printf(
			'<section class="mbcom-panel mbcom-privacy"><h2>%s</h2><p>%s</p><p class="mbcom-privacy-actions"><a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a></p></section>',
			esc_html__( 'A member asked for their data?', 'membuild-community' ),
			esc_html__( 'The community is part of WordPress’s privacy tools. Exporting a member’s personal data includes their profile, posts, comments, points and course progress. Erasing it removes their posts, comments, messages, stories, reactions and uploads.', 'membuild-community' ),
			esc_url( admin_url( 'export-personal-data.php' ) ),
			esc_html__( 'Export personal data', 'membuild-community' ),
			esc_url( admin_url( 'erase-personal-data.php' ) ),
			esc_html__( 'Erase personal data', 'membuild-community' )
		);
		echo '</div>';
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
.mbcom-admin{--mbcom-accent:var(--wp-admin-theme-color,#2271b1);--mbcom-line:#dcdcde;--mbcom-soft:#f6f7f7;--mbcom-muted:#646970}
.mbcom-admin .mbcom-muted{color:var(--mbcom-muted)}
.mbcom-admin .mbcom-panel,.mbcom-admin .mbcom-card{background:#fff;border:1px solid var(--mbcom-line);border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.mbcom-admin .mbcom-panel{padding:8px 24px 20px}
.mbcom-admin .mbcom-panel h2{display:flex;flex-wrap:wrap;align-items:baseline;gap:8px;font-size:16px;letter-spacing:-.01em;margin:16px 0 14px}
.mbcom-admin h1{font-size:26px;font-weight:600;letter-spacing:-.02em}
.mbcom-admin .mbcom-intro{color:var(--mbcom-muted);font-size:14px;max-width:760px;margin:12px 0 0}
/* Buttons on our pages share one shape: rounded, a touch taller, icons centred */
.mbcom-admin .button,.mbcom-admin .button-primary{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:0 14px;border-radius:8px;font-weight:600;line-height:1}
.mbcom-admin .button .dashicons{font-size:16px;width:16px;height:16px;line-height:16px}
.mbcom-admin .button-link{display:inline;min-height:0;padding:0;border-radius:0;font-weight:400}
.mbcom-admin .page-title-action.button{min-height:0}
.mbcom-admin .page-title-action{border-radius:999px;padding:0 14px 0 12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;margin-left:8px;vertical-align:middle;top:0}
.mbcom-admin .page-title-action:after{content:"\2197";font-size:12px}
.mbcom-admin .wp-header-end{margin-bottom:8px}
.mbcom-admin .notice{border-radius:10px;margin:16px 0 0}
.mbcom-admin .mbcom-notice{display:flex;gap:16px;align-items:flex-start;padding:18px 22px;border:1px solid #f0c36d;border-left-width:1px;border-radius:14px;background:linear-gradient(135deg,#fff7e6,#fffdf8 70%);box-shadow:0 1px 2px rgba(16,24,40,.04)}
.mbcom-admin .mbcom-notice.notice-error{border-color:#f0a8a8;background:linear-gradient(135deg,#fdecec,#fffafa 70%)}
.mbcom-admin .mbcom-notice-icon{flex:none;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#f0b849;color:#fff;font-size:22px;box-shadow:0 6px 14px rgba(240,184,73,.35)}
.mbcom-admin .mbcom-notice-icon:before{font-size:22px;width:22px;height:22px}
.mbcom-admin .notice-error .mbcom-notice-icon{background:#d63638;box-shadow:0 6px 14px rgba(214,54,56,.3)}
.mbcom-admin .mbcom-notice-body{min-width:0}
.mbcom-admin .mbcom-notice-body p{margin:0;font-size:13px;line-height:1.55;max-width:760px}
.mbcom-admin .mbcom-notice-body p strong{display:block;font-size:15px;color:#1d2327;margin-bottom:3px;letter-spacing:-.01em}
.mbcom-admin .mbcom-notice-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:12px!important}
.mbcom-admin .mbcom-notice-actions .button-primary{min-height:36px;padding:0 16px;border-radius:8px;font-weight:600}
.mbcom-admin .mbcom-notice-hint{color:var(--mbcom-muted);font-size:12px}
@media (max-width:600px){.mbcom-admin .mbcom-notice-icon{display:none}}
.mbcom-admin .mbcom-panel h2 .mbcom-muted{font-size:13px;font-weight:400}

/* Overview */
.mbcom-admin .mbcom-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin:20px 0 12px}
.mbcom-admin .mbcom-stats{grid-template-columns:repeat(4,minmax(0,1fr))}
@media (max-width:1100px){.mbcom-admin .mbcom-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:600px){.mbcom-admin .mbcom-stats{grid-template-columns:1fr}}
.mbcom-admin .mbcom-card{display:flex;flex-direction:column;gap:2px;padding:18px 20px;color:#1d2327;text-decoration:none;transition:border-color .15s,box-shadow .15s,transform .15s}
.mbcom-admin a.mbcom-card:hover,.mbcom-admin a.mbcom-card:focus{border-color:color-mix(in srgb,var(--mbcom-accent) 50%,var(--mbcom-line));box-shadow:0 6px 16px rgba(16,24,40,.08);transform:translateY(-1px);color:#1d2327;outline:0}
.mbcom-admin a.mbcom-card:focus-visible{box-shadow:0 0 0 2px var(--mbcom-accent)}
.mbcom-admin .mbcom-stat{position:relative}
.mbcom-admin .mbcom-stat .mbcom-card-label{padding-right:48px}
.mbcom-admin .mbcom-stat-icon{position:absolute;top:16px;right:16px;width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:color-mix(in srgb,var(--mbcom-accent) 10%,#fff);color:var(--mbcom-accent)}
.mbcom-admin .mbcom-stat-icon .dashicons{font-size:18px;width:18px;height:18px}
.mbcom-admin .mbcom-card-label{color:var(--mbcom-muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.mbcom-admin .mbcom-card-value{font-size:34px;font-weight:700;line-height:1.2;letter-spacing:-.02em;font-variant-numeric:tabular-nums;margin:2px 0 6px}
.mbcom-admin .mbcom-card-foot{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px;font-size:12px;color:var(--mbcom-muted)}
.mbcom-admin .mbcom-card-sub{color:var(--mbcom-muted);white-space:nowrap;line-height:20px;padding:0 9px;border-radius:999px;background:var(--mbcom-soft)}
.mbcom-admin .mbcom-card-note{white-space:nowrap}
.mbcom-admin .mbcom-card-sub.is-up{color:#0f5132;background:#e3f6e8}
.mbcom-admin .mbcom-strip{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 20px;padding:0;color:var(--mbcom-muted)}
.mbcom-admin .mbcom-strip li{margin:0;display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;background:#fff;border:1px solid var(--mbcom-line);font-size:12.5px}
.mbcom-admin .mbcom-strip li a{text-decoration:none;font-weight:600}
.mbcom-admin .mbcom-strip .dashicons{font-size:15px;width:15px;height:15px;color:var(--mbcom-muted)}
.mbcom-admin .mbcom-strip .mbcom-alert{color:#8a2424;font-weight:600;background:#fbeaea;border-color:#f3c7c7}
.mbcom-admin .mbcom-strip .mbcom-alert .dashicons{color:#8a2424}
.mbcom-admin .mbcom-urgent{margin:16px 0 0}
.mbcom-admin .mbcom-gated{filter:blur(5px);opacity:.55;pointer-events:none;user-select:none;max-height:900px;overflow:hidden;-webkit-mask-image:linear-gradient(#000 55%,transparent);mask-image:linear-gradient(#000 55%,transparent)}
.mbcom-admin .mbcom-grid{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:16px;align-items:start}
@media (max-width:960px){.mbcom-admin .mbcom-grid{grid-template-columns:1fr}}
.mbcom-admin .mbcom-chart{display:block;width:100%;height:auto;max-height:220px;overflow:visible}
.mbcom-admin .mbcom-chart .p,.mbcom-legend .p{fill:var(--mbcom-accent);background:var(--mbcom-accent)}
.mbcom-admin .mbcom-chart .c,.mbcom-legend .c{fill:color-mix(in srgb,var(--mbcom-accent) 38%,#fff);background:color-mix(in srgb,var(--mbcom-accent) 38%,#fff)}
.mbcom-admin .mbcom-chart .j,.mbcom-legend .j{fill:#00a32a;background:#00a32a}
.mbcom-admin .mbcom-chart rect{rx:2px}
.mbcom-admin .mbcom-chart .g{stroke:#e0e0e0;stroke-dasharray:3 3}
.mbcom-admin .mbcom-chart .t{fill:var(--mbcom-muted);font-size:10px}
.mbcom-admin .mbcom-axis{display:flex;justify-content:space-between;color:var(--mbcom-muted);font-size:12px;margin-top:4px}
.mbcom-admin .mbcom-legend{display:inline-flex;flex-wrap:wrap;gap:6px;margin-left:4px}
.mbcom-admin .mbcom-pill{display:inline-flex;align-items:center;gap:6px;padding:0 9px;line-height:20px;border-radius:999px;background:var(--mbcom-soft);color:var(--mbcom-muted);font-size:12px;font-weight:500;white-space:nowrap}
.mbcom-admin .mbcom-pill i{display:inline-block;width:8px;height:8px;border-radius:50%}
.mbcom-admin .mbcom-activity{margin:0}
.mbcom-admin .mbcom-activity li{display:flex;gap:12px;align-items:flex-start;padding:11px 0;margin:0;border-top:1px solid #f0f0f1;line-height:1.45}
.mbcom-admin .mbcom-activity li:first-child{border-top:0;padding-top:0}
.mbcom-admin .mbcom-activity img{border-radius:50%;flex:none}
.mbcom-admin .mbcom-activity .mbcom-muted{display:block;font-size:12px}
.mbcom-admin .mbcom-activity a{text-decoration:none}
.mbcom-admin .mbcom-activity a:hover{text-decoration:underline}
.mbcom-admin .mbcom-column{display:flex;flex-direction:column;gap:16px;min-width:0}
.mbcom-admin .mbcom-checks{padding:14px 20px;background:#fffbf0;border-color:#f0dca8}
.mbcom-admin .mbcom-checks summary{cursor:pointer;font-weight:600;display:flex;align-items:center;gap:6px}
.mbcom-admin .mbcom-checks summary .dashicons{color:#996800}
.mbcom-admin .mbcom-checks.is-critical{border-color:#d63638}
.mbcom-admin .mbcom-checks.is-critical summary .dashicons{color:#d63638}
.mbcom-admin .mbcom-checks ul{list-style:disc;margin:12px 0 0 20px;max-width:820px}
.mbcom-admin .mbcom-checks li{margin-bottom:6px}
.mbcom-admin .mbcom-note{margin-top:20px;color:var(--mbcom-muted);max-width:760px}

/* Keep me updated (Usage::setup_card) */
.mbcom-admin .mbcom-setup{position:relative;margin:20px 0 0;padding:24px 28px 20px 92px;border-color:color-mix(in srgb,var(--mbcom-accent) 30%,var(--mbcom-line));background:linear-gradient(135deg,color-mix(in srgb,var(--mbcom-accent) 8%,#fff),#fff 60%)}
.mbcom-admin .mbcom-setup-icon{position:absolute;top:24px;left:28px;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--mbcom-accent);color:#fff;box-shadow:0 6px 14px color-mix(in srgb,var(--mbcom-accent) 30%,transparent)}
.mbcom-admin .mbcom-setup-icon .dashicons{font-size:22px;width:22px;height:22px}
.mbcom-admin .mbcom-setup h2{margin:0 0 4px;font-size:18px;letter-spacing:-.01em}
.mbcom-admin .mbcom-setup>p{margin:0 0 18px;max-width:720px}
.mbcom-admin .mbcom-setup-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 20px;max-width:760px}
.mbcom-admin .mbcom-setup-fields label{display:flex;flex-direction:column;gap:5px;font-weight:600;font-size:13px;min-width:0}
.mbcom-admin .mbcom-setup-fields select,.mbcom-admin .mbcom-setup-fields input{width:100%;max-width:none;min-height:38px;border-radius:8px;border-color:#c3c4c7;font-weight:400;font-size:14px;padding:0 12px}
.mbcom-admin .mbcom-setup-fields select{padding-right:32px}
.mbcom-admin .mbcom-setup-fields input[readonly]{color:var(--mbcom-muted);background:#f6f7f7;box-shadow:none}
.mbcom-admin .mbcom-setup:not(:has(option[value="other"]:checked)) .mbcom-setup-other{display:none}
.mbcom-admin .mbcom-setup-share{display:flex;gap:12px;align-items:flex-start;margin:18px 0 16px;max-width:760px;padding:12px 14px;border-radius:10px;background:#fff;border:1px solid var(--mbcom-line);color:var(--mbcom-muted);line-height:1.5}
.mbcom-admin .mbcom-setup-share input{margin:3px 0 0;flex:none}
.mbcom-admin .mbcom-setup-share strong{color:#1d2327}
.mbcom-admin .mbcom-setup-actions{display:flex;align-items:center;gap:16px;margin:0}
.mbcom-admin .mbcom-setup-actions .button-primary{min-height:38px;padding:0 18px;border-radius:8px;font-weight:600}
.mbcom-admin .mbcom-setup-actions .button-link{color:var(--mbcom-muted);text-decoration:none}
.mbcom-admin .mbcom-setup-actions .button-link:hover{color:#1d2327;text-decoration:underline}
.mbcom-admin .mbcom-setup-fine{margin:14px 0 0;font-size:12px;color:var(--mbcom-muted);max-width:760px;line-height:1.5}
@media (max-width:782px){.mbcom-admin .mbcom-setup{padding:20px 20px 18px}.mbcom-admin .mbcom-setup-icon{display:none}.mbcom-admin .mbcom-setup-fields{grid-template-columns:1fr}}

/* Export */
.mbcom-admin .mbcom-exports{grid-template-columns:repeat(3,minmax(0,1fr));max-width:1100px}
@media (max-width:900px){.mbcom-admin .mbcom-exports{grid-template-columns:1fr}}
.mbcom-admin .mbcom-export{position:relative;gap:4px;padding:18px 20px}
.mbcom-admin .mbcom-export strong{font-size:15px;letter-spacing:-.01em;padding-right:48px}
.mbcom-admin .mbcom-export .mbcom-muted{margin-bottom:14px;line-height:1.5;padding-right:24px}
.mbcom-admin .mbcom-export .button{align-self:flex-start;margin-top:auto}
.mbcom-admin .mbcom-privacy{max-width:1100px;margin-top:4px}
.mbcom-admin .mbcom-privacy p{max-width:760px;font-size:13px;line-height:1.55}
.mbcom-admin .mbcom-privacy-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}

/* Tables */
.mbcom-admin .wp-list-table thead th,.mbcom-admin .wp-list-table tfoot th{white-space:nowrap}
.mbcom-admin .wp-list-table td{vertical-align:middle}
.mbcom-admin .mbcom-person,.mbcom-admin .mbcom-item{display:flex;align-items:center;gap:12px}
.mbcom-admin .mbcom-item{align-items:flex-start}
.mbcom-admin .mbcom-item>div{min-width:0}
.mbcom-admin .mbcom-person img{border-radius:50%;flex:none}
.mbcom-admin .mbcom-person>span{display:flex;flex-direction:column;min-width:0}
.mbcom-admin .mbcom-person a.mbcom-muted{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-decoration:none}
.mbcom-admin .mbcom-who{display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
.mbcom-admin .mbcom-who img{border-radius:50%}
.mbcom-admin .mbcom-thumb{width:48px;height:48px;object-fit:cover;border-radius:8px;background:var(--mbcom-soft);display:grid;place-items:center;flex:none;color:var(--mbcom-muted);font-weight:600}
.mbcom-admin .mbcom-thumb-wide{width:72px;height:44px}
.mbcom-admin .mbcom-tag{display:inline-flex;align-items:center;gap:3px;padding:1px 8px;border-radius:10px;background:#f0f0f1;font-size:12px;white-space:nowrap}
.mbcom-admin .mbcom-tag .dashicons{font-size:13px;width:13px;height:13px}
.mbcom-admin .mbcom-tag.warn{background:#fcf0c3;color:#6b4c00}.mbcom-admin .mbcom-tag.bad{background:#facfd2;color:#8a1f1f}.mbcom-admin .mbcom-tag.ok{background:#d1f0d9;color:#0f5132}
.mbcom-admin .mbcom-name{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px}
/*
 * Column widths: the first column (the post, file, member or course) takes whatever
 * room is left; the small columns keep a fixed width and never break mid-word, so a
 * narrower screen shortens the main text instead of squeezing "Photo" into a letter.
 */
@media (min-width:783px){.mbcom-admin .wp-list-table td:not(.column-primary):not(.column-post):not(.column-used){white-space:nowrap;overflow:hidden;text-overflow:ellipsis}}
.mbcom-admin .column-author{width:150px}.mbcom-admin .column-author .mbcom-who{max-width:100%;overflow:hidden;text-overflow:ellipsis}
.mbcom-admin .column-type,.mbcom-admin .column-kind,.mbcom-admin .column-size,.mbcom-admin .column-lessons{width:80px}
.mbcom-admin .column-reactions,.mbcom-admin .column-comments,.mbcom-admin .column-finished{width:96px}
.mbcom-admin .column-date{width:112px}.mbcom-admin .column-status{width:100px}.mbcom-admin .column-access{width:170px}.mbcom-admin .column-started{width:140px}
.mbcom-admin .column-post{width:210px}.mbcom-admin .column-used{width:150px}
.mbcom-admin .members .column-points,.mbcom-admin .members .column-activity,.mbcom-admin .members .column-messages{width:118px}
.mbcom-admin .members .column-last_active,.mbcom-admin .members .column-joined{width:112px}
/* Between phone and wide screens, the least needed columns step aside. */
@media (max-width:1180px) and (min-width:783px){
.mbcom-admin .members .column-joined,.mbcom-admin .posts .column-type,.mbcom-admin .files .column-author,.mbcom-admin .courses .column-finished{display:none}
}
.mbcom-admin .column-post a{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.mbcom-admin .mbcom-bar{height:6px;background:#f0f0f1;border-radius:3px;overflow:hidden;width:120px;display:inline-block;vertical-align:middle;margin-right:6px}
.mbcom-admin .mbcom-bar i{display:block;height:100%;background:var(--mbcom-accent)}
';
	}
}
