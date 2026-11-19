<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The wp-admin lists, drawn in the MemBuild WP Admin look: filters with counts
 * and search above, a blue bar for the rows you tick, one link and a ··· menu
 * per row, and a pager. WP_List_Table still does the paging and Screen Options.
 */
abstract class Base_Table extends \WP_List_Table {

	protected $key           = '';
	protected $sortable      = array(); // column => SQL expression
	protected $default_order = array( 'id', 'desc' );
	protected $filter_param  = 'status';
	public $users            = array();

	const DOTS = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>';

	public function __construct() {
		parent::__construct( array( 'plural' => $this->key, 'singular' => rtrim( $this->key, 's' ), 'ajax' => false ) );
	}

	abstract public function title();

	/**
	 * The columns' widths, and where each one shows:
	 * key => array( width, '' | 'medium' (hidden below 960px) | 'keep' (shown on phones too) ).
	 */
	abstract protected function layout();

	/**
	 * WordPress asks for a screen's columns while drawing Screen Options, before
	 * this table exists, and caches the empty answer; so set them here directly.
	 */
	public function init_columns() {
		$columns               = $this->get_columns();
		$this->_column_headers = array( $columns, array(), $this->get_sortable_columns(), (string) array_key_first( array_diff_key( $columns, array( 'cb' => 1 ) ) ) );
	}

	/** A short fact next to the title, like "33 MB in all". */
	public function title_note() {
		return '';
	}

	/** The page's one button, at the end of the title row. */
	public function title_button() {
		return '';
	}

	public function title_html() {
		return Admin::title_row( esc_html( $this->title() ), $this->title_note(), $this->title_button() );
	}

	public function searchable() {
		return true;
	}

	public function search_label() {
		return __( 'Search', 'membuild-community' );
	}

	/** Filter pills: value => array( label, count ). The first is the default. */
	protected function filters() {
		return array();
	}

	/** Something else for the toolbar, between the filters and search. */
	protected function toolbar_extra() {
		return '';
	}

	/**
	 * The blue bar's buttons: value => array( label, who it fits ('*' or kinds, comma separated), confirm or null ).
	 * A confirm is array( title for one, title for many, text for one, text for many, button ), with {n} for how many.
	 */
	protected function bulk() {
		return array();
	}

	/** More controls for the blue bar (the member groups). */
	protected function bulk_extra() {
		return '';
	}

	/** Can this row be ticked, and what kind of row it is for the blue bar's buttons. */
	protected function tick( $item ) {
		return array( true, 'any' );
	}

	public function no_items() {
		esc_html_e( 'Nothing here yet.', 'membuild-community' );
	}

	protected function per_page() {
		return $this->get_items_per_page( "mbcom_{$this->key}_per_page", 20 );
	}

	protected function search() {
		return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only list filter
	}

	protected function arg( $name ) {
		return isset( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only list filter
	}

	/** The filter that's on: one of filters(), else the first. */
	protected function current_filter() {
		$f = $this->arg( $this->filter_param );
		return isset( $this->filters()[ $f ] ) ? $f : (string) array_key_first( $this->filters() );
	}

	/** ORDER BY from the whitelist only. */
	protected function order_sql() {
		$col   = $this->arg( 'orderby' );
		$col   = isset( $this->sortable[ $col ] ) ? $col : $this->default_order[0];
		$order = 'asc' === $this->arg( 'order' ) ? 'ASC' : ( $this->arg( 'order' ) ? 'DESC' : strtoupper( $this->default_order[1] ) );
		return ( $this->sortable[ $col ] ?? 'id' ) . ' ' . $order;
	}

	protected function get_sortable_columns() {
		$out = array();
		foreach ( array_keys( $this->sortable ) as $col ) {
			$out[ $col ] = array( $col, $col === $this->default_order[0] );
		}
		return $out;
	}

	protected function paged() {
		return max( 1, $this->get_pagenum() );
	}

	protected function back() {
		return Admin::current_url();
	}

	/** The arguments that make up this view, kept when searching, sorting or paging. */
	protected function view_args() {
		$out = array();
		foreach ( array_unique( array( $this->filter_param, 'type', 'course', 'author', 'post' ) ) as $k ) {
			if ( '' !== $this->arg( $k ) ) {
				$out[ $k ] = $this->arg( $k );
			}
		}
		if ( '' !== $this->search() ) {
			$out['s'] = $this->search();
		}
		return $out;
	}

	/* ---------- Drawing ---------- */

	public function render() {
		$layout = $this->layout();
		$heads  = $this->get_columns();
		$ticks  = isset( $layout['cb'] );
		$wide   = array();
		$medium = array();
		foreach ( $layout as list( $width, $where ) ) {
			$wide[] = $width;
			if ( 'medium' !== $where ) {
				$medium[] = $width;
			}
		}
		$grid = ' style="' . esc_attr( '--mbwa-cols:' . implode( ' ', $wide ) . ';--mbwa-cols-medium:' . implode( ' ', $medium ) ) . '"';

		echo '<div class="mbwa-card mbcom-list mbcom-list-' . esc_attr( $this->key ) . '">';
		$this->toolbar();
		if ( $ticks ) {
			echo '<form method="post" class="mbcom-bulk" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mbcom_bulk_' . $this->key );
			printf( '<input type="hidden" name="action" value="mbcom_admin"><input type="hidden" name="table" value="%s"><input type="hidden" name="back" value="%s">', esc_attr( $this->key ), esc_attr( rawurlencode( $this->back() ) ) );
			$this->selection_bar();
		}

		if ( $this->items ) {
			echo '<div class="mbwa-thead"' . $grid . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			foreach ( $layout as $col => list( , $where ) ) {
				echo '<span class="' . esc_attr( $this->cell_class( $col, $where ) ) . '">' . $this->head( $col, $heads[ $col ] ?? '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts
			}
			echo '</div>';
			foreach ( $this->items as $item ) {
				echo '<div class="mbwa-trow"' . $grid . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
				foreach ( $layout as $col => list( , $where ) ) {
					$method = 'column_' . $col;
					$html   = 'cb' === $col ? $this->tick_box( $item ) : ( method_exists( $this, $method ) ? $this->$method( $item ) : $this->column_default( $item, $col ) );
					echo '<div class="' . esc_attr( $this->cell_class( $col, $where ) ) . '">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each column escapes its own output
				}
				echo '</div>';
			}
		} else {
			echo '<div class="mbcom-empty">';
			$this->no_items();
			echo '</div>';
		}
		if ( $ticks ) {
			echo '</form>';
		}
		$this->pager();
		echo '</div>';
	}

	private function cell_class( $col, $where ) {
		return trim( 'mbcom-c-' . $col . ( 'medium' === $where ? ' mbwa-hide-medium' : '' ) . ( 'keep' === $where ? ' mbcom-keep' : '' ) );
	}

	/** A column heading; a sortable one is a link that flips the order. */
	private function head( $col, $label ) {
		if ( 'cb' === $col ) {
			return '<input type="checkbox" class="mbcom-tick-all" aria-label="' . esc_attr__( 'Select all on this page', 'membuild-community' ) . '">';
		}
		if ( '' === $label ) {
			return '<span class="screen-reader-text">' . esc_html__( 'Actions', 'membuild-community' ) . '</span>';
		}
		if ( ! isset( $this->sortable[ $col ] ) ) {
			return esc_html( $label );
		}
		$now   = $this->arg( 'orderby' ) ?: $this->default_order[0];
		$order = $this->arg( 'order' ) ?: $this->default_order[1];
		$on    = $now === $col;
		$next  = $on && 'asc' === $order ? 'desc' : ( $on ? 'asc' : $this->default_order[1] );
		$url   = Admin::url( $this->key, array_merge( $this->view_args(), array( 'orderby' => $col, 'order' => $next ) ) );
		$arrow = $on ? '<svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="' . ( 'asc' === $order ? 'M2 6.5l3-3 3 3' : 'M2 3.5l3 3 3-3' ) . '"/></svg>' : '';
		return '<a class="mbcom-sort" href="' . esc_url( $url ) . '"' . ( $on ? ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"' : '' ) . '>' . esc_html( $label ) . $arrow . '</a>';
	}

	private function tick_box( $item ) {
		list( $can, $kind ) = $this->tick( $item );
		if ( ! $can ) {
			return '<input type="checkbox" disabled aria-label="' . esc_attr__( 'You can’t select your own account', 'membuild-community' ) . '">';
		}
		return '<input type="checkbox" class="mbcom-tick" name="ids[]" value="' . (int) $item['id'] . '" data-kind="' . esc_attr( $kind ) . '" aria-label="' . esc_attr__( 'Select', 'membuild-community' ) . '">';
	}

	private function toolbar() {
		$filters = $this->filters();
		$extra   = $this->toolbar_extra();
		if ( ! $filters && ! $extra && ! $this->searchable() ) {
			return;
		}
		echo '<form class="mbcom-toolbar" method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( 'membuild-community-' . $this->key ) );
		foreach ( $this->view_args() as $k => $v ) {
			if ( 's' !== $k && ( 'type' !== $k || ! $extra ) ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
			}
		}
		if ( $filters ) {
			$on   = $this->current_filter();
			$keep = array_diff_key( $this->view_args(), array( $this->filter_param => 1, 's' => 1 ) );
			echo '<nav class="mbwa-filters" aria-label="' . esc_attr__( 'Show', 'membuild-community' ) . '">';
			foreach ( $filters as $value => list( $label, $count ) ) {
				$args = array_key_first( $filters ) === $value ? $keep : array_merge( $keep, array( $this->filter_param => $value ) );
				printf(
					'<a class="mbwa-filter" href="%s"%s>%s <span class="mbcom-count">%s</span></a>',
					esc_url( Admin::url( $this->key, $args ) ),
					$on === $value ? ' aria-current="page"' : '',
					esc_html( $label ),
					esc_html( number_format_i18n( $count ) )
				);
			}
			echo '</nav>';
		}
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the table
		if ( $this->searchable() ) {
			printf(
				'<label class="mbcom-search"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><span class="screen-reader-text">%1$s</span><input type="search" name="s" value="%2$s" placeholder="%1$s"></label>',
				esc_attr( $this->search_label() ),
				esc_attr( $this->search() )
			);
		}
		echo '</form>';
	}

	private function selection_bar() {
		echo '<div class="mbcom-selbar" hidden><span class="mbwa-strong" data-mbcom-count></span>';
		foreach ( $this->bulk() as $value => list( $label, $for, $confirm ) ) {
			printf(
				'<button type="submit" class="mbwa-btn" name="bulk" value="%s" data-for="%s"%s>%s</button>',
				esc_attr( $value ),
				esc_attr( $for ),
				$confirm ? ' data-confirm="' . esc_attr( wp_json_encode( array_combine( array( 'title', 'titles', 'text', 'texts', 'go' ), $confirm ) ) ) . '"' : '',
				esc_html( $label )
			);
		}
		echo $this->bulk_extra(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the table
		echo '<span class="mbcom-grow"></span><button type="button" class="mbcom-text-btn" data-mbcom-clear>' . esc_html__( 'Clear', 'membuild-community' ) . '</button></div>';
	}

	private function pager() {
		$total = (int) $this->get_pagination_arg( 'total_items' );
		$per   = max( 1, (int) $this->get_pagination_arg( 'per_page' ) );
		$pages = (int) ceil( $total / $per );
		if ( $pages < 2 ) {
			return; // one page: the filters already say how many
		}
		$page = $this->paged();
		$args = $this->view_args();
		foreach ( array( 'orderby', 'order' ) as $k ) {
			if ( '' !== $this->arg( $k ) ) {
				$args[ $k ] = $this->arg( $k );
			}
		}
		echo '<div class="mbcom-pager"><span class="mbwa-muted">';
		/* translators: 1: first row shown, 2: last row shown, 3: how many in all */
		echo esc_html( sprintf( __( '%1$s–%2$s of %3$s', 'membuild-community' ), number_format_i18n( ( $page - 1 ) * $per + 1 ), number_format_i18n( min( $total, $page * $per ) ), number_format_i18n( $total ) ) );
		echo '</span><span class="mbcom-pager-btns">';
		foreach ( array( array( $page - 1, __( 'Previous', 'membuild-community' ), $page > 1 ), array( $page + 1, __( 'Next', 'membuild-community' ), $page < $pages ) ) as list( $to, $label, $on ) ) {
			echo $on
				? '<a class="mbwa-btn" href="' . esc_url( Admin::url( $this->key, array_merge( $args, array( 'paged' => $to ) ) ) ) . '">' . esc_html( $label ) . '</a>'
				: '<span class="mbwa-btn" aria-disabled="true">' . esc_html( $label ) . '</span>';
		}
		echo '</span></div>';
	}

	/* ---------- Pieces the columns share ---------- */

	protected function column_default( $item, $column ) {
		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/** The row's one link and its ··· menu. */
	protected function acts( $link, array $menu, $name ) {
		$out = '<div class="mbcom-acts">' . $link;
		if ( $menu ) {
			/* translators: %s: the member, post, comment or file the menu is for */
			$label = sprintf( __( 'More for %s', 'membuild-community' ), $name );
			$out  .= '<details class="mbcom-more"><summary aria-label="' . esc_attr( $label ) . '" title="' . esc_attr__( 'More', 'membuild-community' ) . '">' . self::DOTS . '</summary><div class="mbcom-menu">' . implode( '', $menu ) . '</div></details>';
		}
		return $out . '</div>';
	}

	/**
	 * A menu entry that changes something, with a nonce. $confirm opens the pop-up first:
	 * array( title, text, button[, other button's label, its do] ).
	 */
	protected function menu_item( $label, $do, $id, array $confirm = array(), $last = false ) {
		$data = '';
		if ( $confirm ) {
			$c = array( 'title' => $confirm[0], 'text' => $confirm[1], 'go' => $confirm[2] );
			if ( isset( $confirm[3] ) ) {
				$c['alt']     = $confirm[3];
				$c['altHref'] = Admin::action_url( $confirm[4], $id, $this->back() );
			}
			$data = ' data-confirm="' . esc_attr( wp_json_encode( $c ) ) . '"';
		}
		return '<a class="mbcom-menu-item' . ( $last ? ' mbcom-menu-last' : '' ) . '" href="' . esc_url( Admin::action_url( $do, $id, $this->back() ) ) . '"' . $data . '>' . esc_html( $label ) . '</a>';
	}

	/** A plain link inside the menu (Edit account). */
	protected function menu_link( $label, $href ) {
		return '<a class="mbcom-menu-item" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
	}

	protected function view_link( $label, $href, $new_tab = false ) {
		return '<a class="mbcom-lnk" href="' . esc_url( $href ) . '"' . ( $new_tab ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}

	/** Two lines: a strong one and a quiet one, each cut to fit. */
	protected function two( $top, $under = '' ) {
		return '<div class="mbwa-strong mbcom-one">' . $top . '</div>' . ( '' !== $under ? '<div class="mbwa-muted mbcom-one">' . $under . '</div>' : '' );
	}

	/** A day as people say it: Today, Yesterday, 17 Nov (with the year when it isn't this one). */
	protected function day( $mysql, $utc = true ) {
		if ( ! $mysql ) {
			return '<span class="mbwa-muted">—</span>';
		}
		$ts    = $utc ? strtotime( $mysql . ' UTC' ) : strtotime( $mysql . ' 12:00 ' . wp_timezone_string() );
		$day   = wp_date( 'Y-m-d', $ts );
		$label = wp_date( 'Y-m-d' ) === $day ? __( 'Today', 'membuild-community' )
			: ( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) === $day ? __( 'Yesterday', 'membuild-community' )
			: wp_date( wp_date( 'Y' ) === wp_date( 'Y', $ts ) ? 'j M' : 'j M Y', $ts ) );
		$full  = $utc ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : wp_date( get_option( 'date_format' ), $ts );
		return '<span title="' . esc_attr( $full ) . '">' . esc_html( $label ) . '</span>';
	}

	/** Their photo and name, with an optional quiet line under it. */
	protected function who( $user, $under = '', $pill = '' ) {
		if ( ! $user ) {
			return '<span class="mbwa-muted">' . esc_html__( 'Deleted account', 'membuild-community' ) . '</span>';
		}
		$name = '<span class="mbwa-strong mbcom-one">' . esc_html( $user['name'] ) . '</span>' . $pill;
		return '<div class="mbcom-who"><img src="' . esc_url( $user['avatar'] ) . '" width="32" height="32" alt="" loading="lazy"><div><div class="mbcom-name">' . $name . '</div>' . ( '' !== $under ? '<div class="mbwa-muted mbcom-one">' . $under . '</div>' : '' ) . '</div></div>';
	}

	/** Open reports on these posts or comments: id => how many members reported it. */
	protected function reported( $type, array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, COUNT(DISTINCT reporter_id) n FROM %i WHERE object_type = %s AND status = %s AND object_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY object_id', array_merge( array( Install::table( 'reports' ), $type, 'open' ), $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder list
		return array_map( 'intval', array_column( $rows, 'n', 'object_id' ) );
	}

	protected function reported_line( $n ) {
		/* translators: %s: how many members reported it */
		return sprintf( _n( 'reported by %s member', 'reported by %s members', $n, 'membuild-community' ), number_format_i18n( $n ) );
	}
}

/* ================================================================== */

class Members_Table extends Base_Table {

	protected $key           = 'members';
	protected $sortable      = array(
		'name'        => 'u.display_name',
		'joined'      => 'u.user_registered',
		'last_active' => 's.last_active',
		'points'      => 'points',
	);
	protected $default_order = array( 'joined', 'desc' );
	private $courses_total   = 0;

	public function title() {
		return __( 'Members', 'membuild-community' );
	}

	public function search_label() {
		return __( 'Search members', 'membuild-community' );
	}

	public function get_columns() {
		return array(
			'cb'          => '',
			'name'        => __( 'Member', 'membuild-community' ),
			'points'      => __( 'Level', 'membuild-community' ),
			'activity'    => __( 'Activity', 'membuild-community' ),
			'courses'     => __( 'Courses', 'membuild-community' ),
			'last_active' => __( 'Last active', 'membuild-community' ),
			'joined'      => __( 'Joined', 'membuild-community' ),
			'acts'        => '',
		);
	}

	protected function layout() {
		return array(
			'cb'          => array( '28px', 'medium' ),
			'name'        => array( 'minmax(0,1fr)', 'keep' ),
			'points'      => array( '150px', '' ),
			'activity'    => array( '110px', 'medium' ),
			'courses'     => array( '140px', 'medium' ),
			'last_active' => array( '96px', '' ),
			'joined'      => array( '84px', 'medium' ),
			'acts'        => array( '140px', 'keep' ),
		);
	}

	/** Muted and banned people, read once. */
	private function restricted() {
		static $out = null;
		if ( null === $out ) {
			$out = array();
			foreach ( get_users( array( 'meta_key' => Moderation::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 1000 ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery -- small set
				$r = Moderation::restriction( (int) $id );
				if ( $r ) {
					$out[ (int) $id ] = $r['status'];
				}
			}
		}
		return $out;
	}

	/** Not seen in the community for 30 days (the Overview's Quiet members check). */
	private function quiet_since() {
		return gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
	}

	protected function filters() {
		global $wpdb;
		$r     = $this->restricted();
		$quiet = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i u JOIN %i s ON s.user_id = u.ID WHERE s.last_active < %s' . Helpers::site_members_sql(), $wpdb->users, Install::table( 'member_stats' ), $this->quiet_since() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a fixed, prepared fragment
		return array(
			''       => array( __( 'All', 'membuild-community' ), (int) Helpers::member_total() ),
			'quiet'  => array( __( 'Quiet', 'membuild-community' ), $quiet ),
			'muted'  => array( __( 'Muted', 'membuild-community' ), count( array_keys( $r, 'muted', true ) ) ),
			'banned' => array( __( 'Banned', 'membuild-community' ), count( array_keys( $r, 'banned', true ) ) ),
		);
	}

	protected function bulk() {
		return array(
			'mute' => array( __( 'Mute for 7 days', 'membuild-community' ), 'free', null ),
			'ban'  => array(
				__( 'Ban', 'membuild-community' ),
				'free',
				array(
					__( 'Ban 1 member?', 'membuild-community' ),
					/* translators: {n} stays as it is: it becomes how many */
					__( 'Ban {n} members?', 'membuild-community' ),
					__( 'They can’t open the community until you lift the ban.', 'membuild-community' ),
					__( 'They can’t open the community until you lift the ban.', 'membuild-community' ),
					__( 'Ban', 'membuild-community' ),
				),
			),
			'lift' => array( __( 'Lift mute or ban', 'membuild-community' ), 'restricted', null ),
		);
	}

	/** Member groups (Settings › Access): add or remove the ticked people. */
	protected function bulk_extra() {
		$groups = Access::groups();
		if ( ! $groups ) {
			return '';
		}
		$out = '<span class="mbwa-select" data-for="*"><select name="bulk2" data-mbcom-submit aria-label="' . esc_attr__( 'Member group', 'membuild-community' ) . '"><option value="">' . esc_html__( 'Member group…', 'membuild-community' ) . '</option>';
		foreach ( $groups as $id => $group ) {
			/* translators: %s: member group name */
			$out .= '<option value="' . esc_attr( 'groupadd' . $id ) . '">' . esc_html( sprintf( __( 'Add to %s', 'membuild-community' ), $group['name'] ) ) . '</option>';
		}
		foreach ( $groups as $id => $group ) {
			/* translators: %s: member group name */
			$out .= '<option value="' . esc_attr( 'groupremove' . $id ) . '">' . esc_html( sprintf( __( 'Remove from %s', 'membuild-community' ), $group['name'] ) ) . '</option>';
		}
		return $out . '</select><svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke-width="1.5" aria-hidden="true"><path d="M3 4.5l3 3 3-3"/></svg></span>';
	}

	protected function tick( $item ) {
		$id = (int) $item['id'];
		if ( get_current_user_id() === $id ) {
			return array( false, '' );
		}
		return array( true, Settings::is_staff( $id ) ? 'staff' : ( isset( $this->restricted()[ $id ] ) ? 'restricted' : 'free' ) );
	}

	public function no_items() {
		esc_html_e( 'No members match.', 'membuild-community' );
	}

	public function prepare_items() {
		global $wpdb;
		$per    = $this->per_page();
		$status = $this->current_filter();
		$where  = '1=1' . Helpers::site_members_sql();
		$args   = array( $wpdb->users, Install::table( 'member_stats' ) );
		if ( $this->search() ) {
			$like   = '%' . $wpdb->esc_like( $this->search() ) . '%';
			$where .= ' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		if ( 'quiet' === $status ) {
			$where .= ' AND s.last_active < %s';
			$args[] = $this->quiet_since();
		} elseif ( in_array( $status, array( 'muted', 'banned' ), true ) ) {
			$ids    = array_keys( $this->restricted(), $status, true ) ?: array( 0 );
			$where .= ' AND u.ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$args   = array_merge( $args, $ids );
		}
		$count_args = $args;
		$args[]     = $per;
		$args[]     = ( $this->paged() - 1 ) * $per;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where holds only fixed fragments with placeholders; ORDER BY comes from the whitelist in $sortable.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT u.ID AS id, u.display_name, u.user_email, u.user_registered, s.last_active, COALESCE(s.points, 0) AS points, COALESCE(s.level, 1) AS level FROM %i u LEFT JOIN %i s ON s.user_id = u.ID WHERE {$where} ORDER BY " . $this->order_sql() . ' LIMIT %d OFFSET %d', $args ), ARRAY_A );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i u LEFT JOIN %i s ON s.user_id = u.ID WHERE {$where}", $count_args ) );
		// phpcs:enable

		$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$cnt = $this->counts( $ids );
		foreach ( $rows as &$r ) {
			$r += $cnt[ (int) $r['id'] ] ?? array();
		}
		$this->items         = $rows;
		$this->courses_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published'", Install::table( 'courses' ) ) );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	/** Posts, comments, and courses started and finished per member, in a few small queries. */
	private function counts( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array_fill_keys( $ids, array( 'posts' => 0, 'comments' => 0, 'started' => 0, 'finished' => 0 ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL with an id placeholder list
		$q = array(
			'posts'    => array( "SELECT user_id, COUNT(*) n FROM %i WHERE status = 'published' AND user_id IN ({$in}) GROUP BY user_id", array( Install::table( 'posts' ) ), 1 ),
			'comments' => array( "SELECT user_id, COUNT(*) n FROM %i WHERE user_id IN ({$in}) GROUP BY user_id", array( Install::table( 'comments' ) ), 1 ),
			'started'  => array( "SELECT user_id, COUNT(DISTINCT course_id) n FROM (SELECT user_id, course_id FROM %i WHERE user_id IN ({$in}) UNION SELECT user_id, course_id FROM %i WHERE user_id IN ({$in})) x GROUP BY user_id", array( Install::table( 'course_starts' ) ), 2 ),
			'finished' => array( "SELECT d.user_id, COUNT(*) n FROM (SELECT user_id, course_id, COUNT(*) done FROM %i WHERE user_id IN ({$in}) GROUP BY user_id, course_id) d JOIN (SELECT course_id, COUNT(*) n FROM %i GROUP BY course_id) l ON l.course_id = d.course_id WHERE d.done >= l.n GROUP BY d.user_id", array( Install::table( 'lesson_progress' ) ), 1 ),
		);
		foreach ( $q as $field => list( $sql, $tables, $lists ) ) {
			if ( 'started' === $field ) {
				$params = array_merge( array( Install::table( 'course_starts' ) ), $ids, array( Install::table( 'lesson_progress' ) ), $ids );
			} elseif ( 'finished' === $field ) {
				$params = array_merge( array( Install::table( 'lesson_progress' ) ), $ids, array( Install::table( 'lessons' ) ) );
			} else {
				$params = array_merge( $tables, $ids );
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) as $row ) {
				$out[ (int) $row['user_id'] ][ $field ] = (int) $row['n'];
			}
		}
		// phpcs:enable
		return $out;
	}

	private function status_pill( $id ) {
		$r = $this->restricted()[ $id ] ?? '';
		return 'banned' === $r ? Admin::pill( 'action', __( 'Banned', 'membuild-community' ) ) : ( 'muted' === $r ? Admin::pill( 'watch', __( 'Muted', 'membuild-community' ) ) : '' );
	}

	protected function column_name( $item ) {
		$id    = (int) $item['id'];
		$user  = array( 'name' => $item['display_name'], 'avatar' => get_avatar_url( $id, array( 'size' => 64 ) ) );
		$role  = user_can( $id, 'manage_options' ) ? __( 'Admin', 'membuild-community' ) : ( Settings::is_staff( $id ) ? __( 'Moderator', 'membuild-community' ) : '' );
		$under = get_current_user_id() === $id ? __( 'you', 'membuild-community' ) : $item['user_email'];
		if ( '' !== $role ) {
			$under .= ' · ' . $role;
		}
		$r = Moderation::restriction( $id );
		if ( $r && 'muted' === $r['status'] && $r['until'] ) {
			/* translators: %s: the day a mute ends, e.g. 26 Nov */
			$under .= ' · ' . sprintf( __( 'until %s', 'membuild-community' ), wp_date( 'j M', strtotime( $r['until'] ) ) );
		}
		return $this->who( $user, esc_html( $under ), $this->status_pill( $id ) );
	}

	protected function column_points( $item ) {
		$name = Settings::get( 'level_names' )[ (int) $item['level'] - 1 ] ?? '';
		/* translators: %d: level number */
		$level = sprintf( __( 'Level %d', 'membuild-community' ), $item['level'] ) . ( '' !== $name ? ' · ' . $name : '' );
		/* translators: %s: number of points */
		return $this->two( esc_html( $level ), esc_html( sprintf( _n( '%s point', '%s points', (int) $item['points'], 'membuild-community' ), number_format_i18n( $item['points'] ) ) ) );
	}

	protected function column_activity( $item ) {
		$posts    = (int) ( $item['posts'] ?? 0 );
		$comments = (int) ( $item['comments'] ?? 0 );
		/* translators: %s: number of posts */
		$posts_label = esc_html( sprintf( _n( '%s post', '%s posts', $posts, 'membuild-community' ), number_format_i18n( $posts ) ) );
		/* translators: %s: number of comments */
		$comments_label = esc_html( sprintf( _n( '%s comment', '%s comments', $comments, 'membuild-community' ), number_format_i18n( $comments ) ) );
		return '<div>' . ( $posts ? '<a class="mbcom-quiet-link" href="' . esc_url( Admin::url( 'posts', array( 'author' => (int) $item['id'] ) ) ) . '">' . $posts_label . '</a>' : $posts_label ) . '</div><div class="mbwa-muted">' . $comments_label . '</div>';
	}

	protected function column_courses( $item ) {
		$started  = (int) ( $item['started'] ?? 0 );
		$finished = (int) ( $item['finished'] ?? 0 );
		if ( ! $started && ! $finished ) {
			return '<span class="mbwa-muted">' . esc_html__( 'None yet', 'membuild-community' ) . '</span>';
		}
		if ( $finished && $this->courses_total && $finished >= $this->courses_total ) {
			/* translators: %s: how many courses there are */
			return esc_html( sprintf( _n( 'Finished the course', 'All %s finished', $finished, 'membuild-community' ), number_format_i18n( $finished ) ) );
		}
		/* translators: %s: how many courses */
		$out = sprintf( _n( '%s started', '%s started', max( $started, $finished ), 'membuild-community' ), number_format_i18n( max( $started, $finished ) ) );
		if ( $finished ) {
			/* translators: %s: how many courses */
			$out .= ', ' . sprintf( _n( '%s finished', '%s finished', $finished, 'membuild-community' ), number_format_i18n( $finished ) );
		}
		return esc_html( $out );
	}

	protected function column_joined( $item ) {
		return $this->day( $item['user_registered'] );
	}

	/** The day they last opened the community (it's kept as a day, not a time). */
	protected function column_last_active( $item ) {
		return $item['last_active'] ? $this->day( $item['last_active'], false ) : '<span class="mbwa-muted">' . esc_html__( 'Not yet', 'membuild-community' ) . '</span>';
	}

	protected function column_acts( $item ) {
		$id   = (int) $item['id'];
		$name = $item['display_name'];
		$menu = array();
		if ( get_current_user_id() !== $id && ! Settings::is_staff( $id ) ) {
			$r = $this->restricted()[ $id ] ?? '';
			if ( 'muted' === $r ) {
				$menu[] = $this->menu_item( __( 'Lift mute', 'membuild-community' ), 'members_lift', $id );
			} elseif ( 'banned' === $r ) {
				$menu[] = $this->menu_item( __( 'Lift ban', 'membuild-community' ), 'members_lift', $id );
			} else {
				$menu[] = $this->menu_item( __( 'Mute for 7 days', 'membuild-community' ), 'members_mute', $id );
			}
		}
		$menu[] = $this->menu_link( __( 'Edit account', 'membuild-community' ), get_edit_user_link( $id ) );
		if ( get_current_user_id() !== $id && ! Settings::is_staff( $id ) && 'banned' !== ( $this->restricted()[ $id ] ?? '' ) ) {
			$menu[] = $this->menu_item(
				__( 'Ban', 'membuild-community' ),
				'members_ban',
				$id,
				/* translators: %s: member name */
				array( sprintf( __( 'Ban %s?', 'membuild-community' ), $name ), __( 'They can’t open the community until you lift the ban.', 'membuild-community' ), __( 'Ban', 'membuild-community' ) ),
				true
			);
		}
		return $this->acts( $this->view_link( __( 'View profile', 'membuild-community' ), Admin::app_url( 'members/' . $id ) ), $menu, $name );
	}
}

/* ================================================================== */

class Posts_Table extends Base_Table {

	protected $key           = 'posts';
	protected $sortable      = array(
		'date'      => 'p.id',
		'reactions' => 'p.reactions_count',
		'comments'  => 'p.comments_count',
	);
	protected $default_order = array( 'date', 'desc' );
	private $reports         = array();
	private $facts           = array();

	public function title() {
		return __( 'Posts', 'membuild-community' );
	}

	public function search_label() {
		return __( 'Search posts', 'membuild-community' );
	}

	public function get_columns() {
		return array(
			'cb'        => '',
			'content'   => __( 'Post', 'membuild-community' ),
			'author'    => __( 'Author', 'membuild-community' ),
			'reactions' => __( 'Reactions', 'membuild-community' ),
			'comments'  => __( 'Comments', 'membuild-community' ),
			'date'      => __( 'Posted', 'membuild-community' ),
			'acts'      => '',
		);
	}

	protected function layout() {
		$cols = array(
			'cb'        => array( '28px', 'medium' ),
			'content'   => array( 'minmax(0,1fr)', 'keep' ),
			'author'    => array( '180px', '' ),
			'reactions' => array( '90px', 'medium' ),
			'comments'  => array( '90px', 'medium' ),
			'date'      => array( '110px', '' ),
			'acts'      => array( '150px', 'keep' ),
		);
		if ( 'deleted' === $this->current_filter() ) {
			unset( $cols['cb'] ); // nothing more can be done to a deleted post
		}
		return $cols;
	}

	protected function filters() {
		global $wpdb;
		$counts = array_column( $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM %i GROUP BY status', Install::table( 'posts' ) ), ARRAY_A ), 'n', 'status' );
		$open   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT r.object_id) FROM %i r JOIN %i p ON p.id = r.object_id WHERE r.object_type = 'post' AND r.status = 'open' AND p.status <> 'deleted'", Install::table( 'reports' ), Install::table( 'posts' ) ) );
		return array(
			''         => array( __( 'Live', 'membuild-community' ), (int) ( $counts['published'] ?? 0 ) ),
			'reported' => array( __( 'Reported', 'membuild-community' ), $open ),
			'review'   => array( __( 'Hidden', 'membuild-community' ), (int) ( $counts['review'] ?? 0 ) ),
			'deleted'  => array( __( 'Deleted', 'membuild-community' ), (int) ( $counts['deleted'] ?? 0 ) ),
		);
	}

	protected function toolbar_extra() {
		$type = $this->arg( 'type' );
		$out  = '<span class="mbwa-select"><select name="type" data-mbcom-submit aria-label="' . esc_attr__( 'Kind of post', 'membuild-community' ) . '">';
		foreach ( array( '' => __( 'Posts and reels', 'membuild-community' ), 'post' => __( 'Posts only', 'membuild-community' ), 'reel' => __( 'Reels only', 'membuild-community' ) ) as $value => $label ) {
			$out .= '<option value="' . esc_attr( $value ) . '"' . selected( $type, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $out . '</select><svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke-width="1.5" aria-hidden="true"><path d="M3 4.5l3 3 3-3"/></svg></span>';
	}

	protected function bulk() {
		$delete = array(
			__( 'Delete', 'membuild-community' ),
			'*',
			array(
				__( 'Delete 1 post?', 'membuild-community' ),
				/* translators: {n} stays as it is: it becomes how many */
				__( 'Delete {n} posts?', 'membuild-community' ),
				__( 'It goes for everyone, with its comments, and can’t come back.', 'membuild-community' ),
				__( 'They go for everyone, with their comments, and can’t come back.', 'membuild-community' ),
				__( 'Delete', 'membuild-community' ),
			),
		);
		switch ( $this->current_filter() ) {
			case 'reported':
				return array(
					'keep'   => array( __( 'Keep', 'membuild-community' ), '*', null ),
					'hide'   => array( __( 'Hide', 'membuild-community' ), '*', null ),
					'delete' => $delete,
				);
			case 'review':
				return array(
					'restore' => array( __( 'Show again', 'membuild-community' ), '*', null ),
					'delete'  => $delete,
				);
		}
		return array(
			'hide'   => array( __( 'Hide', 'membuild-community' ), '*', null ),
			'delete' => $delete,
		);
	}

	public function no_items() {
		echo esc_html( 'reported' === $this->current_filter() ? __( 'No reported posts. All clear.', 'membuild-community' ) : __( 'No posts match.', 'membuild-community' ) );
	}

	public function prepare_items() {
		global $wpdb;
		$per    = $this->per_page();
		$filter = $this->current_filter();
		$args   = array( Install::table( 'posts' ) );
		if ( 'reported' === $filter ) {
			$where  = "p.status <> 'deleted' AND p.id IN (SELECT object_id FROM %i WHERE object_type = 'post' AND status = 'open')";
			$args[] = Install::table( 'reports' );
		} else {
			$where  = 'p.status = %s';
			$args[] = '' === $filter ? 'published' : $filter;
		}
		if ( in_array( $this->arg( 'type' ), array( 'post', 'reel' ), true ) ) {
			$where .= ' AND p.type = %s';
			$args[] = $this->arg( 'type' );
		}
		if ( ! empty( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- list filter
			$where .= ' AND p.user_id = %d';
			$args[] = absint( $_GET['author'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $this->search() ) {
			$where .= ' AND p.content LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $this->search() ) . '%';
		}
		$count_args = $args;
		array_push( $args, $per, ( $this->paged() - 1 ) * $per );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where holds only fixed fragments with placeholders; ORDER BY comes from the whitelist in $sortable.
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT p.* FROM %i p WHERE {$where} ORDER BY " . $this->order_sql() . ' LIMIT %d OFFSET %d', $args ), ARRAY_A );
		$total       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i p WHERE {$where}", $count_args ) );
		// phpcs:enable
		$ids           = array_map( 'intval', wp_list_pluck( $this->items, 'id' ) );
		$this->users   = Helpers::users( wp_list_pluck( $this->items, 'user_id' ) );
		$this->reports = $this->reported( 'post', $ids );
		$this->facts   = $this->facts( $ids );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	/** Reel lengths, poll votes and whether a post has a photo or video: id => array. */
	private function facts( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL with an id placeholder list
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, MAX(CASE WHEN kind = 'video' THEN duration ELSE 0 END) secs, COUNT(*) n FROM %i WHERE post_id IN ({$in}) GROUP BY post_id", array_merge( array( Install::table( 'media' ) ), $ids ) ), ARRAY_A ) as $r ) {
			$out[ (int) $r['post_id'] ]['secs']  = (float) $r['secs'];
			$out[ (int) $r['post_id'] ]['media'] = (int) $r['n'];
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT p.post_id, (SELECT COUNT(*) FROM %i v WHERE v.post_id = p.post_id) n FROM %i p WHERE p.post_id IN ({$in})", array_merge( array( Install::table( 'poll_votes' ), Install::table( 'polls' ) ), $ids ) ), ARRAY_A ) as $r ) {
			$out[ (int) $r['post_id'] ]['votes'] = (int) $r['n'];
		}
		// phpcs:enable
		return $out;
	}

	/** One fact under the post's words: what kind of post it is, and anything to know. */
	private function fact( $item ) {
		$id    = (int) $item['id'];
		$facts = $this->facts[ $id ] ?? array();
		$secs  = (int) round( $facts['secs'] ?? 0 );
		$parts = array();
		if ( isset( $facts['votes'] ) ) {
			/* translators: %s: number of votes */
			$parts[] = sprintf( _n( 'Poll, %s vote', 'Poll, %s votes', $facts['votes'], 'membuild-community' ), number_format_i18n( $facts['votes'] ) );
		} elseif ( 'reel' === $item['type'] ) {
			/* translators: %s: how long the reel is, e.g. 0:42 */
			$parts[] = $secs ? sprintf( __( 'Reel, %s', 'membuild-community' ), floor( $secs / 60 ) . ':' . str_pad( (string) ( $secs % 60 ), 2, '0', STR_PAD_LEFT ) ) : __( 'Reel', 'membuild-community' );
		} elseif ( ! empty( $item['event_at'] ) ) {
			/* translators: %s: the event's day, e.g. 3 Dec */
			$parts[] = sprintf( __( 'Event, %s', 'membuild-community' ), wp_date( 'j M', strtotime( $item['event_at'] . ' UTC' ) ) );
		} elseif ( $item['is_pinned'] ) {
			$parts[] = __( 'Pinned to the top', 'membuild-community' );
		} else {
			$parts[] = __( 'Post', 'membuild-community' );
		}
		if ( 'reported' === $this->current_filter() && 'review' === $item['status'] ) {
			$parts[] = __( 'hidden', 'membuild-community' );
		}
		if ( ! empty( $this->reports[ $id ] ) ) {
			$parts[] = $this->reported_line( $this->reports[ $id ] );
		}
		return implode( ' · ', $parts );
	}

	private function words( $item ) {
		$text = Helpers::words( wp_strip_all_tags( (string) $item['content'] ), 14, '…' );
		if ( '' !== trim( $text ) ) {
			return $text;
		}
		return ! empty( $this->facts[ (int) $item['id'] ]['media'] ) ? __( '(photo or video)', 'membuild-community' ) : __( '(no words)', 'membuild-community' );
	}

	protected function column_content( $item ) {
		$pill = ! empty( $this->reports[ (int) $item['id'] ] ) ? ' ' . Admin::pill( 'action', __( 'Reported', 'membuild-community' ) ) : '';
		return '<div class="mbcom-name"><span class="mbwa-strong mbcom-one">' . esc_html( $this->words( $item ) ) . '</span>' . $pill . '</div><div class="mbwa-muted mbcom-one">' . esc_html( $this->fact( $item ) ) . '</div>';
	}

	protected function column_author( $item ) {
		return $this->who( $this->users[ (int) $item['user_id'] ] ?? null );
	}

	protected function column_reactions( $item ) {
		return esc_html( number_format_i18n( $item['reactions_count'] ) );
	}

	protected function column_comments( $item ) {
		$n = (int) $item['comments_count'];
		return $n ? '<a class="mbcom-quiet-link" href="' . esc_url( Admin::url( 'comments', array( 'post' => (int) $item['id'] ) ) ) . '">' . esc_html( number_format_i18n( $n ) ) . '</a>' : '0';
	}

	protected function column_date( $item ) {
		return $this->day( $item['created_at'] );
	}

	protected function column_acts( $item ) {
		$id     = (int) $item['id'];
		$status = $item['status'];
		if ( 'deleted' === $status ) {
			return '';
		}
		$menu = array();
		if ( ! empty( $this->reports[ $id ] ) ) {
			$menu[] = $this->menu_item( __( 'Keep, close the reports', 'membuild-community' ), 'posts_keep', $id );
		}
		if ( 'published' === $status ) {
			$menu[] = $item['is_pinned'] ? $this->menu_item( __( 'Unpin', 'membuild-community' ), 'posts_unpin', $id ) : $this->menu_item( __( 'Pin to top', 'membuild-community' ), 'posts_pin', $id );
			$menu[] = $this->menu_item( __( 'Hide', 'membuild-community' ), 'posts_hide', $id );
		} else {
			$menu[] = $this->menu_item( __( 'Show again', 'membuild-community' ), 'posts_restore', $id );
		}
		$n       = (int) $item['comments_count'];
		$text    = $n
			/* translators: %s: number of comments */
			? sprintf( _n( 'It goes for everyone, with its %s comment, and can’t come back.', 'It goes for everyone, with its %s comments, and can’t come back.', $n, 'membuild-community' ), number_format_i18n( $n ) )
			: __( 'It goes for everyone and can’t come back.', 'membuild-community' );
		$confirm = array( __( 'Delete this post?', 'membuild-community' ), $text, __( 'Delete post', 'membuild-community' ) );
		if ( 'published' === $status ) {
			array_push( $confirm, __( 'Hide instead', 'membuild-community' ), 'posts_hide' );
		}
		$menu[] = $this->menu_item( __( 'Delete', 'membuild-community' ), 'posts_delete', $id, $confirm, true );
		$link   = 'published' === $status ? $this->view_link( __( 'View post', 'membuild-community' ), Admin::app_url( '?post=' . $id ) ) : '';
		return $this->acts( $link, $menu, $this->words( $item ) );
	}
}

/* ================================================================== */

class Comments_Table extends Base_Table {

	protected $key           = 'comments';
	protected $sortable      = array(
		'date'      => 'c.id',
		'reactions' => 'c.reactions_count',
	);
	protected $default_order = array( 'date', 'desc' );
	private $reports         = array();

	public function title() {
		return __( 'Comments', 'membuild-community' );
	}

	public function search_label() {
		return __( 'Search comments', 'membuild-community' );
	}

	public function get_columns() {
		return array(
			'cb'        => '',
			'content'   => __( 'Comment', 'membuild-community' ),
			'author'    => __( 'Author', 'membuild-community' ),
			'post'      => __( 'On post', 'membuild-community' ),
			'reactions' => __( 'Reactions', 'membuild-community' ),
			'date'      => __( 'Written', 'membuild-community' ),
			'acts'      => '',
		);
	}

	protected function layout() {
		return array(
			'cb'        => array( '28px', 'medium' ),
			'content'   => array( 'minmax(0,1fr)', 'keep' ),
			'author'    => array( '180px', '' ),
			'post'      => array( '240px', 'medium' ),
			'reactions' => array( '90px', 'medium' ),
			'date'      => array( '110px', '' ),
			'acts'      => array( '170px', 'keep' ),
		);
	}

	protected function filters() {
		global $wpdb;
		return array(
			''         => array( __( 'All', 'membuild-community' ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'comments' ) ) ) ),
			'reported' => array( __( 'Reported', 'membuild-community' ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT r.object_id) FROM %i r JOIN %i c ON c.id = r.object_id WHERE r.object_type = 'comment' AND r.status = 'open'", Install::table( 'reports' ), Install::table( 'comments' ) ) ) ),
		);
	}

	protected function bulk() {
		$delete = array(
			__( 'Delete', 'membuild-community' ),
			'*',
			array(
				__( 'Delete 1 comment?', 'membuild-community' ),
				/* translators: {n} stays as it is: it becomes how many */
				__( 'Delete {n} comments?', 'membuild-community' ),
				__( 'Its replies go too. This can’t be undone.', 'membuild-community' ),
				__( 'Their replies go too. This can’t be undone.', 'membuild-community' ),
				__( 'Delete', 'membuild-community' ),
			),
		);
		return 'reported' === $this->current_filter()
			? array( 'keep' => array( __( 'Keep', 'membuild-community' ), '*', null ), 'delete' => $delete )
			: array( 'delete' => $delete );
	}

	public function no_items() {
		echo esc_html( 'reported' === $this->current_filter() ? __( 'No reported comments. All clear.', 'membuild-community' ) : __( 'No comments match.', 'membuild-community' ) );
	}

	public function prepare_items() {
		global $wpdb;
		$per   = $this->per_page();
		$where = '1=1';
		$args  = array( Install::table( 'comments' ), Install::table( 'posts' ) );
		if ( 'reported' === $this->current_filter() ) {
			$where .= " AND c.id IN (SELECT object_id FROM %i WHERE object_type = 'comment' AND status = 'open')";
			$args[] = Install::table( 'reports' );
		}
		if ( ! empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- list filter
			$where .= ' AND c.post_id = %d';
			$args[] = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $this->search() ) {
			$where .= ' AND c.content LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $this->search() ) . '%';
		}
		$count_args = $args;
		array_push( $args, $per, ( $this->paged() - 1 ) * $per );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where holds only fixed fragments with placeholders; ORDER BY comes from the whitelist in $sortable.
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, p.content AS post_content, p.status AS post_status, p.type AS post_type FROM %i c LEFT JOIN %i p ON p.id = c.post_id WHERE {$where} ORDER BY " . $this->order_sql() . ' LIMIT %d OFFSET %d', $args ), ARRAY_A );
		$total       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.id = c.post_id WHERE {$where}", $count_args ) );
		// phpcs:enable
		$this->users   = Helpers::users( wp_list_pluck( $this->items, 'user_id' ) );
		$this->reports = $this->reported( 'comment', wp_list_pluck( $this->items, 'id' ) );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	private function words( $item ) {
		$text = Helpers::words( wp_strip_all_tags( (string) $item['content'] ), 20, '…' );
		return '' !== trim( $text ) ? $text : __( '(a photo)', 'membuild-community' );
	}

	protected function column_content( $item ) {
		$n    = $this->reports[ (int) $item['id'] ] ?? 0;
		$pill = $n ? ' ' . Admin::pill( 'action', __( 'Reported', 'membuild-community' ) ) : '';
		$out  = '<div class="mbcom-name"><span class="mbcom-one">' . esc_html( $this->words( $item ) ) . '</span>' . $pill . '</div>';
		return $n ? $out . '<div class="mbwa-muted mbcom-one">' . esc_html( $this->reported_line( $n ) ) . '</div>' : $out;
	}

	protected function column_author( $item ) {
		return $this->who( $this->users[ (int) $item['user_id'] ] ?? null );
	}

	protected function column_post( $item ) {
		$text = Helpers::words( wp_strip_all_tags( (string) $item['post_content'] ), 8, '…' );
		$text = '' !== trim( $text ) ? $text : ( 'reel' === $item['post_type'] ? __( 'A reel', 'membuild-community' ) : __( '(photo or video)', 'membuild-community' ) );
		if ( 'published' !== $item['post_status'] ) {
			return '<div class="mbcom-one">' . esc_html( $text ) . '</div><div class="mbwa-muted">' . esc_html( 'deleted' === $item['post_status'] ? __( 'Post deleted', 'membuild-community' ) : __( 'Post hidden', 'membuild-community' ) ) . '</div>';
		}
		return '<a class="mbcom-quiet-link mbcom-one" href="' . esc_url( Admin::app_url( '?post=' . (int) $item['post_id'] ) ) . '">' . esc_html( $text ) . '</a>';
	}

	protected function column_reactions( $item ) {
		return esc_html( number_format_i18n( $item['reactions_count'] ) );
	}

	protected function column_date( $item ) {
		return $this->day( $item['created_at'] );
	}

	protected function column_acts( $item ) {
		$id   = (int) $item['id'];
		$menu = array();
		if ( ! empty( $this->reports[ $id ] ) ) {
			$menu[] = $this->menu_item( __( 'Keep, close the reports', 'membuild-community' ), 'comments_keep', $id );
		}
		$menu[] = $this->menu_item( __( 'Delete', 'membuild-community' ), 'comments_delete', $id, array( __( 'Delete this comment?', 'membuild-community' ), __( 'Its replies go too. This can’t be undone.', 'membuild-community' ), __( 'Delete comment', 'membuild-community' ) ), true );
		$link   = 'published' === $item['post_status'] ? $this->view_link( __( 'View comment', 'membuild-community' ), Admin::app_url( '?post=' . (int) $item['post_id'] ) ) : '';
		return $this->acts( $link, $menu, $this->words( $item ) );
	}
}

/* ================================================================== */

class Files_Table extends Base_Table {

	protected $key           = 'files';
	protected $sortable      = array(
		'date' => 'm.id',
		'size' => 'm.bytes',
	);
	protected $default_order = array( 'date', 'desc' );
	protected $filter_param  = 'type';
	public $uses             = array(); // media ID => where it's used, for uploads not in a post
	private $posts           = array(); // post ID => array( type, words )

	public function title() {
		return __( 'Files', 'membuild-community' );
	}

	public function title_note() {
		/* translators: %s: total size, e.g. "33 MB" */
		return sprintf( __( '%s in all', 'membuild-community' ), size_format( Admin::storage_bytes() ) );
	}

	public function search_label() {
		return __( 'Search files', 'membuild-community' );
	}

	public function get_columns() {
		return array(
			'cb'     => '',
			'file'   => __( 'File', 'membuild-community' ),
			'size'   => __( 'Size', 'membuild-community' ),
			'author' => __( 'Uploaded by', 'membuild-community' ),
			'used'   => __( 'Used in', 'membuild-community' ),
			'date'   => __( 'Uploaded', 'membuild-community' ),
			'acts'   => '',
		);
	}

	protected function layout() {
		return array(
			'cb'     => array( '28px', 'medium' ),
			'file'   => array( 'minmax(0,1fr)', 'keep' ),
			'size'   => array( '90px', '' ),
			'author' => array( '180px', 'medium' ),
			'used'   => array( '240px', 'medium' ),
			'date'   => array( '100px', '' ),
			'acts'   => array( '150px', 'keep' ),
		);
	}

	protected function filters() {
		global $wpdb;
		$counts = array_column( $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) n FROM %i WHERE kind <> 'poster' AND ( scope IS NULL OR scope <> 'message' ) GROUP BY kind", Install::table( 'media' ) ), ARRAY_A ), 'n', 'kind' );
		return array(
			''      => array( __( 'All', 'membuild-community' ), array_sum( array_map( 'intval', $counts ) ) ),
			'image' => array( __( 'Photos', 'membuild-community' ), (int) ( $counts['image'] ?? 0 ) ),
			'video' => array( __( 'Videos', 'membuild-community' ), (int) ( $counts['video'] ?? 0 ) ),
			'file'  => array( __( 'Documents', 'membuild-community' ), (int) ( $counts['file'] ?? 0 ) ),
			'embed' => array( __( 'Video links', 'membuild-community' ), (int) ( $counts['embed'] ?? 0 ) ),
		);
	}

	protected function bulk() {
		return array(
			'delete' => array(
				__( 'Delete', 'membuild-community' ),
				'*',
				array(
					__( 'Delete 1 file?', 'membuild-community' ),
					/* translators: {n} stays as it is: it becomes how many */
					__( 'Delete {n} files?', 'membuild-community' ),
					__( 'It disappears from wherever it’s used. This can’t be undone.', 'membuild-community' ),
					__( 'They disappear from wherever they’re used. This can’t be undone.', 'membuild-community' ),
					__( 'Delete', 'membuild-community' ),
				),
			),
		);
	}

	public function no_items() {
		esc_html_e( 'No files match.', 'membuild-community' );
	}

	public function prepare_items() {
		global $wpdb;
		$per = $this->per_page();
		// Photos sent in private messages are never listed: admins only ever see message counts.
		$where = "m.kind <> 'poster' AND ( m.scope IS NULL OR m.scope <> 'message' )";
		$args  = array( Install::table( 'media' ), $wpdb->posts );
		if ( '' !== $this->current_filter() ) {
			$where .= ' AND m.kind = %s';
			$args[] = $this->current_filter();
		}
		if ( $this->search() ) {
			$like   = '%' . $wpdb->esc_like( $this->search() ) . '%';
			$where .= ' AND ( a.post_title LIKE %s OR m.url LIKE %s )';
			array_push( $args, $like, $like );
		}
		$count_args = $args;
		array_push( $args, $per, ( $this->paged() - 1 ) * $per );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where holds only fixed fragments with placeholders; ORDER BY comes from the whitelist in $sortable.
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT m.*, a.post_title AS file_title FROM %i m LEFT JOIN %i a ON a.ID = m.attachment_id WHERE {$where} ORDER BY " . $this->order_sql() . ' LIMIT %d OFFSET %d', $args ), ARRAY_A );
		$total       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i m LEFT JOIN %i a ON a.ID = m.attachment_id WHERE {$where}", $count_args ) );
		// phpcs:enable
		$this->users = Helpers::users( wp_list_pluck( $this->items, 'user_id' ) );
		$this->uses  = $this->uses( $this->items );
		$post_ids    = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $this->items, 'post_id' ) ) ) ) );
		if ( $post_ids ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, content, status FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'posts' ) ), $post_ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder list
			foreach ( $rows as $p ) {
				$this->posts[ (int) $p['id'] ] = $p;
			}
		}
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	/**
	 * Where each upload on this page that isn't in a post is used: a profile
	 * photo, the logo, a comment, a story, a lesson or a course cover.
	 * A handful of lookups for the whole page, not one per row.
	 */
	private function uses( array $items ) {
		global $wpdb;
		$rows = array_filter( $items, fn( $m ) => null !== $m['post_id'] && 0 === (int) $m['post_id'] );
		if ( ! $rows ) {
			return array();
		}
		$urls  = array_values( array_unique( wp_list_pluck( $rows, 'url' ) ) );
		$in    = implode( ',', array_fill( 0, count( $urls ), '%s' ) );
		$found = array();
		$look  = array(
			'avatar'  => array( "SELECT meta_value FROM %i WHERE meta_key = 'mbcom_avatar_url' AND meta_value IN ({$in})", $wpdb->usermeta, __( 'A profile photo', 'membuild-community' ) ),
			'comment' => array( "SELECT image_url FROM %i WHERE image_url IN ({$in})", Install::table( 'comments' ), __( 'A comment', 'membuild-community' ) ),
			'story'   => array( "SELECT media_url FROM %i WHERE media_url IN ({$in})", Install::table( 'stories' ), __( 'A story', 'membuild-community' ) ),
			'cover'   => array( "SELECT cover_url FROM %i WHERE cover_url IN ({$in})", Install::table( 'courses' ), __( 'A course cover', 'membuild-community' ) ),
		);
		foreach ( $look as list( $sql, $table, $label ) ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $table ), $urls ) ) ) as $url ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL with a placeholder list
				$found[ $url ] = $label;
			}
		}
		$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$lessons = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT media_id, title FROM %i WHERE media_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'lessons' ) ), $ids ) ), ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder list
			/* translators: %s: lesson title */
			$lessons[ (int) $l['media_id'] ] = sprintf( __( 'Lesson: %s', 'membuild-community' ), $l['title'] );
		}
		$named = array(
			(int) ( Settings::get( 'logo' )['id'] ?? 0 )     => __( 'The community logo', 'membuild-community' ),
			(int) ( Settings::get( 'app_icon' )['id'] ?? 0 ) => __( 'The app icon', 'membuild-community' ),
		);
		$out = array();
		foreach ( $rows as $m ) {
			$id         = (int) $m['id'];
			$out[ $id ] = $named[ $id ] ?? $lessons[ $id ] ?? ( 'lesson' === $m['scope'] ? __( 'A lesson', 'membuild-community' ) : ( $found[ $m['url'] ] ?? null ) );
		}
		return $out;
	}

	/** The name it was uploaded with (files are saved under random names, so nobody can guess another member's file). */
	private function name( $item ) {
		$stored = $item['attachment_id'] ? basename( (string) get_attached_file( (int) $item['attachment_id'] ) ) : basename( wp_parse_url( $item['url'], PHP_URL_PATH ) ?: '' );
		$title  = (string) ( $item['file_title'] ?? '' );
		$ext    = pathinfo( $stored, PATHINFO_EXTENSION );
		$name   = '' !== $title ? $title . ( $ext ? '.' . $ext : '' ) : $stored;
		return '' !== $name ? $name : __( '(file)', 'membuild-community' );
	}

	protected function column_file( $item ) {
		$icons = array(
			'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 17-5-5-9 8"/>',
			'video' => '<rect x="3" y="5" width="14" height="14" rx="2"/><path d="m17 10 4-2v8l-4-2"/>',
			'embed' => '<rect x="3" y="5" width="14" height="14" rx="2"/><path d="m17 10 4-2v8l-4-2"/>',
			'file'  => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/>',
		);
		$secs  = (int) round( (float) $item['duration'] );
		$len   = $secs ? floor( $secs / 60 ) . ':' . str_pad( (string) ( $secs % 60 ), 2, '0', STR_PAD_LEFT ) : '';
		$kinds = array(
			'image' => __( 'Photo', 'membuild-community' ),
			/* translators: %s: how long the video is, e.g. 0:42 */
			'video' => 'video/bunny' === $item['mime'] ? __( 'Video on Bunny Stream', 'membuild-community' ) : ( $len ? sprintf( __( 'Video, %s', 'membuild-community' ), $len ) : __( 'Video', 'membuild-community' ) ),
			'file'  => __( 'Document', 'membuild-community' ),
			'embed' => __( 'Video link', 'membuild-community' ),
		);
		$icon = '<span class="mbcom-thumb" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ( $icons[ $item['kind'] ] ?? $icons['file'] ) . '</svg></span>';
		return '<div class="mbcom-who">' . $icon . '<div>' . $this->two( esc_html( $this->name( $item ) ), esc_html( $kinds[ $item['kind'] ] ?? $item['kind'] ) ) . '</div></div>';
	}

	protected function column_size( $item ) {
		return (int) $item['bytes'] > 1 ? esc_html( size_format( (int) $item['bytes'] ) ) : '<span class="mbwa-muted">—</span>';
	}

	protected function column_author( $item ) {
		return $this->who( $this->users[ (int) $item['user_id'] ] ?? null );
	}

	protected function column_used( $item ) {
		if ( null === $item['post_id'] ) {
			// Uploads start as soon as a file is picked; one that never gets posted is removed by the hourly cleanup within a day.
			return Admin::pill( 'watch', __( 'Not posted', 'membuild-community' ) );
		}
		$post_id = (int) $item['post_id'];
		if ( $post_id > 0 ) {
			$p     = $this->posts[ $post_id ] ?? null;
			$words = $p ? Helpers::words( wp_strip_all_tags( (string) $p['content'] ), 6, '…' ) : '';
			/* translators: 1: Post or Reel, 2: its first words */
			$label = sprintf( __( '%1$s: %2$s', 'membuild-community' ), $p && 'reel' === $p['type'] ? __( 'Reel', 'membuild-community' ) : __( 'Post', 'membuild-community' ), '' !== trim( $words ) ? $words : '#' . $post_id );
			return $p && 'published' === $p['status']
				? '<a class="mbcom-quiet-link mbcom-one" href="' . esc_url( Admin::app_url( '?post=' . $post_id ) ) . '">' . esc_html( $label ) . '</a>'
				: '<span class="mbcom-one">' . esc_html( $label ) . '</span>';
		}
		$use = $this->uses[ (int) $item['id'] ] ?? null;
		// Left over from before replaced photos were deleted automatically: nothing shows it any more.
		return $use ? '<span class="mbcom-one">' . esc_html( $use ) . '</span>' : '<span class="mbwa-muted">' . esc_html__( 'Not in use', 'membuild-community' ) . '</span>';
	}

	protected function column_date( $item ) {
		return $this->day( $item['created_at'] );
	}

	protected function column_acts( $item ) {
		$menu = array( $this->menu_item( __( 'Delete', 'membuild-community' ), 'files_delete', (int) $item['id'], array( __( 'Delete this file?', 'membuild-community' ), __( 'It disappears from wherever it’s used. This can’t be undone.', 'membuild-community' ), __( 'Delete file', 'membuild-community' ) ), true ) );
		return $this->acts( $this->view_link( __( 'Open file', 'membuild-community' ), $item['url'], true ), $menu, $this->name( $item ) );
	}
}

/* ================================================================== */

class Courses_Table extends Base_Table {

	protected $key           = 'courses';
	protected $sortable      = array(
		'title'   => 'c.title',
		'started' => 'started',
	);
	protected $default_order = array( 'title', 'asc' );
	protected $filter_param  = 'show';
	private $course          = false;
	private $all_rows        = array(); // one course's members, before the filter and the page
	private $headline        = array( 0, 0 ); // started, finished: for the title, whatever the search

	public function title() {
		return __( 'Courses', 'membuild-community' );
	}

	private function course() {
		global $wpdb;
		if ( false === $this->course ) {
			$id           = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- list filter
			$this->course = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'courses' ), $id ), ARRAY_A ) : null;
		}
		return $this->course;
	}

	public function title_html() {
		$course = $this->course();
		if ( ! $course ) {
			return Admin::title_row( esc_html( $this->title() ), '', '<a class="mbwa-btn" href="' . esc_url( Admin::app_url( 'classroom' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit courses in the community', 'membuild-community' ) . Admin::EXT . '</a>' );
		}
		/* translators: 1: how many members started, 2: how many finished */
		$note = sprintf( __( '%1$s started, %2$s finished', 'membuild-community' ), number_format_i18n( $this->headline[0] ), number_format_i18n( $this->headline[1] ) );
		$h    = '<a class="mbcom-lnk" href="' . esc_url( Admin::url( 'courses' ) ) . '">' . esc_html( $this->title() ) . '</a> <span class="mbwa-muted mbcom-crumb" aria-hidden="true">›</span> ' . esc_html( $course['title'] );
		return Admin::title_row( $h, $note );
	}

	public function searchable() {
		return (bool) $this->course();
	}

	public function search_label() {
		return __( 'Search members', 'membuild-community' );
	}

	public function get_columns() {
		if ( $this->course() ) {
			return array(
				'member'   => __( 'Member', 'membuild-community' ),
				'progress' => __( 'Progress', 'membuild-community' ),
				'last'     => __( 'Last lesson finished', 'membuild-community' ),
				'quiz'     => __( 'Quiz', 'membuild-community' ),
				'when'     => __( 'When', 'membuild-community' ),
			);
		}
		return array(
			'title'    => __( 'Course', 'membuild-community' ),
			'status'   => __( 'Status', 'membuild-community' ),
			'access'   => __( 'Who can open it', 'membuild-community' ),
			'lessons'  => __( 'Lessons', 'membuild-community' ),
			'started'  => __( 'Started', 'membuild-community' ),
			'finished' => __( 'Finished', 'membuild-community' ),
			'certs'    => __( 'Certificates', 'membuild-community' ),
			'acts'     => '',
		);
	}

	protected function layout() {
		if ( $this->course() ) {
			return array(
				'member'   => array( 'minmax(0,1fr)', 'keep' ),
				'progress' => array( '260px', 'keep' ),
				'last'     => array( '220px', 'medium' ),
				'quiz'     => array( '130px', 'medium' ),
				'when'     => array( '110px', '' ),
			);
		}
		return array(
			'title'    => array( 'minmax(0,1fr)', 'keep' ),
			'status'   => array( '110px', '' ),
			'access'   => array( '130px', 'medium' ),
			'lessons'  => array( '70px', 'medium' ),
			'started'  => array( '80px', '' ),
			'finished' => array( '100px', '' ),
			'certs'    => array( '110px', 'medium' ),
			'acts'     => array( '130px', 'keep' ),
		);
	}

	/** Stuck: started, not finished, and no lesson finished in 14 days. */
	private function stuck( $r ) {
		return $r['done'] < $r['total'] && strtotime( ( $r['last'] ?: $r['started_at'] ) . ' UTC' ) < time() - 14 * DAY_IN_SECONDS;
	}

	protected function filters() {
		if ( ! $this->course() ) {
			return array();
		}
		return array(
			''         => array( __( 'All', 'membuild-community' ), count( $this->all_rows ) ),
			'stuck'    => array( __( 'Stuck', 'membuild-community' ), count( array_filter( $this->all_rows, array( $this, 'stuck' ) ) ) ),
			'finished' => array( __( 'Finished', 'membuild-community' ), count( array_filter( $this->all_rows, fn( $r ) => $r['total'] && $r['done'] >= $r['total'] ) ) ),
		);
	}

	public function no_items() {
		if ( ! $this->course() ) {
			esc_html_e( 'No courses yet. Make one in the community’s Classroom.', 'membuild-community' );
		} elseif ( ! $this->headline[0] ) {
			esc_html_e( 'Nobody has started this course yet.', 'membuild-community' );
		} else {
			esc_html_e( 'No members match.', 'membuild-community' );
		}
	}

	public function prepare_items() {
		global $wpdb;
		$lessons  = Install::table( 'lessons' );
		$progress = Install::table( 'lesson_progress' );
		$course   = $this->course();
		if ( $course ) {
			$this->prepare_course( $course );
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY comes from the whitelist in $sortable.
		$this->items = $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, (SELECT COUNT(*) FROM %i l WHERE l.course_id = c.id) AS lessons, (SELECT COUNT(*) FROM (SELECT user_id, course_id FROM %i UNION SELECT user_id, course_id FROM %i) s WHERE s.course_id = c.id) AS started, (SELECT COUNT(*) FROM %i k WHERE k.course_id = c.id AND k.status = %s) AS certs FROM %i c ORDER BY ' . $this->order_sql(), $lessons, $progress, Install::table( 'course_starts' ), Install::table( 'certificates' ), 'valid', Install::table( 'courses' ) ), ARRAY_A );
		foreach ( $this->items as &$c ) {
			$c['finished'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT user_id FROM %i WHERE course_id = %d GROUP BY user_id HAVING COUNT(*) >= %d) f', $progress, $c['id'], max( 1, (int) $c['lessons'] ) ) );
		}
		unset( $c );
		$this->set_pagination_args( array( 'total_items' => count( $this->items ), 'per_page' => max( 1, count( $this->items ) ) ) );
	}

	/** One course's members: how far they got, the last lesson they finished and their latest quiz. */
	private function prepare_course( array $course ) {
		global $wpdb;
		$cid      = (int) $course['id'];
		$progress = Install::table( 'lesson_progress' );
		$total    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $cid ) );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT m.user_id, COALESCE(p.done, 0) done, p.last, m.started_at FROM (SELECT user_id, MIN(at) started_at FROM (SELECT user_id, started_at at FROM %i WHERE course_id = %d UNION ALL SELECT user_id, completed_at at FROM %i WHERE course_id = %d) a GROUP BY user_id) m LEFT JOIN (SELECT user_id, COUNT(*) done, MAX(completed_at) last FROM %i WHERE course_id = %d GROUP BY user_id) p ON p.user_id = m.user_id ORDER BY done DESC, p.last DESC LIMIT 2000',
				Install::table( 'course_starts' ),
				$cid,
				$progress,
				$cid,
				$progress,
				$cid
			),
			ARRAY_A
		);
		$users          = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		$this->headline = array( count( $rows ), count( array_filter( $rows, fn( $r ) => $total && (int) $r['done'] >= $total ) ) );
		$s              = mb_strtolower( $this->search() );
		$out   = array();
		foreach ( $rows as $r ) {
			$user = $users[ (int) $r['user_id'] ] ?? null;
			$mail = $user ? (string) get_userdata( (int) $r['user_id'] )->user_email : '';
			if ( '' !== $s && ( ! $user || ( false === mb_strpos( mb_strtolower( $user['name'] ), $s ) && false === mb_strpos( mb_strtolower( $mail ), $s ) ) ) ) {
				continue;
			}
			$out[] = array(
				'id'         => (int) $r['user_id'],
				'user'       => $user,
				'email'      => $mail,
				'done'       => (int) $r['done'],
				'total'      => $total,
				'last'       => $r['last'],
				'started_at' => $r['started_at'],
			);
		}
		$this->all_rows = $out;
		$filter         = $this->current_filter();
		if ( 'stuck' === $filter ) {
			$out = array_values( array_filter( $out, array( $this, 'stuck' ) ) );
		} elseif ( 'finished' === $filter ) {
			$out = array_values( array_filter( $out, fn( $r ) => $r['total'] && $r['done'] >= $r['total'] ) );
		}
		$per         = $this->per_page();
		$this->items = array_slice( $out, ( $this->paged() - 1 ) * $per, $per );
		$this->add_details( $cid );
		$this->set_pagination_args( array( 'total_items' => count( $out ), 'per_page' => $per ) );
	}

	/** The last lesson finished and the latest quiz result, for the rows on this page only. */
	private function add_details( $cid ) {
		global $wpdb;
		$ids = wp_list_pluck( $this->items, 'id' );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL with an id placeholder list
		$last  = $wpdb->get_results( $wpdb->prepare( "SELECT p.user_id, l.title FROM %i p JOIN %i l ON l.id = p.lesson_id WHERE p.course_id = %d AND p.user_id IN ({$in}) ORDER BY p.completed_at ASC, p.id ASC", array_merge( array( Install::table( 'lesson_progress' ), Install::table( 'lessons' ), $cid ), $ids ) ), ARRAY_A );
		$tries = $wpdb->get_results( $wpdb->prepare( "SELECT t.user_id, t.score, t.total, t.passed FROM %i t JOIN %i q ON q.id = t.quiz_id WHERE q.course_id = %d AND t.preview = 0 AND t.finished_at IS NOT NULL AND t.user_id IN ({$in}) ORDER BY t.finished_at ASC, t.id ASC", array_merge( array( Install::table( 'quiz_attempts' ), Install::table( 'quizzes' ), $cid ), $ids ) ), ARRAY_A );
		// phpcs:enable
		$titles = array_column( $last, 'title', 'user_id' ); // the latest wins, as they're in order
		$quiz   = array();
		foreach ( $tries as $t ) {
			$quiz[ (int) $t['user_id'] ] = $t;
		}
		foreach ( $this->items as &$r ) {
			$r['lesson'] = $titles[ $r['id'] ] ?? '';
			$r['quiz']   = $quiz[ $r['id'] ] ?? null;
		}
	}

	private function starts( $item ) {
		if ( 'date' !== $item['open_mode'] || empty( $item['starts_on'] ) ) {
			return __( 'Members start any time', 'membuild-community' );
		}
		$ts = strtotime( $item['starts_on'] . ' 12:00 ' . wp_timezone_string() );
		/* translators: %s: a day, e.g. 1 Dec */
		return sprintf( $ts > time() ? __( 'Starts %s', 'membuild-community' ) : __( 'Started %s', 'membuild-community' ), wp_date( 'j M', $ts ) );
	}

	protected function column_title( $item ) {
		return $this->two( esc_html( $item['title'] ), esc_html( $this->starts( $item ) ) );
	}

	protected function column_status( $item ) {
		return 'published' === $item['status'] ? Admin::pill( 'fine', __( 'Published', 'membuild-community' ) ) : Admin::pill( 'note', __( 'Draft', 'membuild-community' ) );
	}

	protected function column_access( $item ) {
		if ( 'level' === $item['access'] ) {
			/* translators: %d: level number */
			return esc_html( sprintf( __( 'Level %d and up', 'membuild-community' ), $item['unlock_level'] ) );
		}
		if ( 'groups' === $item['access'] ) {
			$groups = Access::groups();
			$names  = array_filter( array_map( fn( $id ) => $groups[ $id ]['name'] ?? '', Access::course_groups( $item ) ) );
			return '<span class="mbcom-one">' . esc_html( $names ? wp_sprintf( '%l', $names ) : __( 'Member groups', 'membuild-community' ) ) . '</span>';
		}
		return esc_html__( 'Everyone', 'membuild-community' );
	}

	protected function column_lessons( $item ) {
		return esc_html( number_format_i18n( $item['lessons'] ) );
	}

	protected function column_started( $item ) {
		return esc_html( number_format_i18n( $item['started'] ) );
	}

	protected function column_finished( $item ) {
		$n = (int) $item['finished'];
		return $n && $item['started'] ? esc_html( number_format_i18n( $n ) . ' · ' . round( 100 * $n / $item['started'] ) . '%' ) : esc_html( number_format_i18n( $n ) );
	}

	protected function column_certs( $item ) {
		return Certificates::gives( (int) $item['id'] ) ? esc_html( number_format_i18n( $item['certs'] ) ) : '<span class="mbwa-muted">' . esc_html__( 'Off', 'membuild-community' ) . '</span>';
	}

	protected function column_acts( $item ) {
		return $item['started'] || $item['lessons'] ? $this->acts( $this->view_link( __( 'View progress', 'membuild-community' ), Admin::url( 'courses', array( 'course' => (int) $item['id'] ) ) ), array(), $item['title'] ) : '';
	}

	protected function column_member( $item ) {
		$pill = $this->stuck( $item ) ? Admin::pill( 'watch', __( 'Stuck', 'membuild-community' ) ) : '';
		return $this->who( $item['user'], esc_html( $item['email'] ), $pill );
	}

	protected function column_progress( $item ) {
		$pct = $item['total'] ? min( 100, round( 100 * $item['done'] / $item['total'] ) ) : 0;
		/* translators: 1: lessons finished, 2: lessons in the course */
		$n = sprintf( __( '%1$s of %2$s', 'membuild-community' ), number_format_i18n( $item['done'] ), number_format_i18n( $item['total'] ) );
		return '<div class="mbcom-prog"><div class="mbwa-bar" role="presentation"><span style="width:' . (int) $pct . '%"></span></div><span class="mbcom-prog-n">' . esc_html( $n ) . '</span></div>';
	}

	protected function column_last( $item ) {
		return '' !== $item['lesson'] ? '<span class="mbcom-one">' . esc_html( $item['lesson'] ) . '</span>' : '<span class="mbwa-muted">' . esc_html__( 'None yet', 'membuild-community' ) . '</span>';
	}

	protected function column_quiz( $item ) {
		$t = $item['quiz'];
		if ( ! $t ) {
			return '<span class="mbwa-muted">' . esc_html__( 'Not yet', 'membuild-community' ) . '</span>';
		}
		$pct = $t['total'] ? round( 100 * $t['score'] / $t['total'] ) : 0;
		/* translators: %s: the score, e.g. 90 */
		return esc_html( sprintf( $t['passed'] ? __( 'Passed, %s%%', 'membuild-community' ) : __( 'Not passed, %s%%', 'membuild-community' ), $pct ) );
	}

	protected function column_when( $item ) {
		return $this->day( $item['last'] ?: $item['started_at'] );
	}
}
