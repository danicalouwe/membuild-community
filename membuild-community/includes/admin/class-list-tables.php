<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Shared bits of the wp-admin tables: paging, search, sorting from a fixed
 * whitelist, and links into the community app.
 */
abstract class Base_Table extends \WP_List_Table {

	protected $key      = '';
	protected $sortable = array(); // column => SQL expression
	protected $default_order = array( 'id', 'desc' );

	public function __construct() {
		parent::__construct( array( 'plural' => $this->key, 'singular' => rtrim( $this->key, 's' ), 'ajax' => false ) );
	}

	abstract public function title();

	/**
	 * WordPress asks for a screen's columns while drawing Screen Options, before
	 * this table exists, and caches the empty answer; so set them here directly.
	 */
	public function init_columns() {
		$columns               = $this->get_columns();
		$this->_column_headers = array( $columns, get_hidden_columns( $this->screen ), $this->get_sortable_columns(), (string) array_key_first( array_diff_key( $columns, array( 'cb' => 1 ) ) ) );
	}

	public function title_actions() {
		return '';
	}

	public function intro() {}

	public function searchable() {
		return true;
	}

	public function search_label() {
		return __( 'Search', 'membuild-community' );
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

	protected function action_link( $label, $do, $id, $confirm = '', $class = '' ) {
		$url = Admin::action_url( $do, $id, $this->back() );
		$js  = $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '';
		return '<a href="' . esc_url( $url ) . '"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . $js . '>' . esc_html( $label ) . '</a>';
	}

	/** Bulk actions use their own field names, so the table and the action handler agree. */
	protected function bulk_actions( $which = '' ) {
		$actions = $this->get_bulk_actions();
		if ( ! $actions ) {
			return;
		}
		$name = 'top' === $which ? 'bulk' : 'bulk2';
		echo '<label for="mbcom-' . esc_attr( $name ) . '" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'membuild-community' ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '" id="mbcom-' . esc_attr( $name ) . '"><option value="-1">' . esc_html__( 'Bulk actions', 'membuild-community' ) . '</option>';
		foreach ( $actions as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Apply', 'membuild-community' ), 'action', '', false, array( 'id' => 'do' . $name ) );
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '">';
	}

	protected function column_default( $item, $column ) {
		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	protected function date( $mysql ) {
		if ( ! $mysql ) {
			return '—';
		}
		$ts = strtotime( $mysql . ' UTC' );
		/* translators: %s: time, e.g. "5 mins" */
		$ago = sprintf( __( '%s ago', 'membuild-community' ), human_time_diff( $ts ) );
		return '<span title="' . esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) . '">' . esc_html( $ago ) . '</span>';
	}

	protected function who( $user ) {
		if ( ! $user ) {
			return '<span class="mbcom-muted">' . esc_html__( 'Deleted user', 'membuild-community' ) . '</span>';
		}
		return '<a class="mbcom-who" href="' . esc_url( Admin::url( 'members', array( 's' => $user['name'] ) ) ) . '"><img src="' . esc_url( $user['avatar'] ) . '" width="28" height="28" alt=""> ' . esc_html( $user['name'] ) . '</a>';
	}

	protected function views_from( array $views, $current, $param = 'status' ) {
		$out = array();
		foreach ( $views as $key => list( $label, $count ) ) {
			$url         = Admin::url( $this->key, '' === $key ? array() : array( $param => $key ) );
			$out[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%s)</span></a>', esc_url( $url ), $current === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ), number_format_i18n( $count ) );
		}
		return $out;
	}
}

/* ================================================================== */

class Members_Table extends Base_Table {

	protected $key      = 'members';
	protected $sortable = array(
		'name'        => 'u.display_name',
		'joined'      => 'u.user_registered',
		'last_active' => 's.last_active',
		'points'      => 'points',
	);
	protected $default_order = array( 'joined', 'desc' );

	public function title() {
		return __( 'Community members', 'membuild-community' );
	}

	public function search_label() {
		return __( 'Search members', 'membuild-community' );
	}

	public function intro() {
		echo '<p class="mbcom-muted">' . esc_html__( 'Everyone with an account on this site is a community member. Messages are counted, never shown: admins can’t read members’ private chats.', 'membuild-community' ) . '</p>';
	}

	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'name'        => __( 'Member', 'membuild-community' ),
			'points'      => __( 'Level', 'membuild-community' ),
			'activity'    => __( 'Activity', 'membuild-community' ),
			'messages'    => __( 'Messages', 'membuild-community' ),
			'last_active' => __( 'Last active', 'membuild-community' ),
			'joined'      => __( 'Joined', 'membuild-community' ),
		);
	}

	protected function get_bulk_actions() {
		$actions = array(
			'mute' => __( 'Mute for 7 days', 'membuild-community' ),
			'ban'  => __( 'Ban from the community', 'membuild-community' ),
			'lift' => __( 'Unmute or unban', 'membuild-community' ),
		);
		// One "add to" and one "remove from" per access group (Community → Settings → Access groups).
		foreach ( Access::groups() as $id => $group ) {
			/* translators: %s: access group name */
			$actions[ 'groupadd' . $id ] = sprintf( __( 'Add to member group: %s', 'membuild-community' ), $group['name'] );
		}
		foreach ( Access::groups() as $id => $group ) {
			/* translators: %s: access group name */
			$actions[ 'groupremove' . $id ] = sprintf( __( 'Remove from member group: %s', 'membuild-community' ), $group['name'] );
		}
		return $actions;
	}

	protected function get_views() {
		$restricted = get_users( array( 'meta_key' => Moderation::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 1000 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- small set
		$muted      = 0;
		$banned     = 0;
		foreach ( $restricted as $id ) {
			$r       = Moderation::restriction( (int) $id );
			$muted  += 'muted' === ( $r['status'] ?? '' ) ? 1 : 0;
			$banned += 'banned' === ( $r['status'] ?? '' ) ? 1 : 0;
		}
		return $this->views_from(
			array(
				''       => array( __( 'All', 'membuild-community' ), (int) count_users()['total_users'] ),
				'muted'  => array( __( 'Muted', 'membuild-community' ), $muted ),
				'banned' => array( __( 'Banned', 'membuild-community' ), $banned ),
			),
			$this->arg( 'status' )
		);
	}

	public function prepare_items() {
		global $wpdb;
		$per    = $this->per_page();
		$status = $this->arg( 'status' );
		$where  = '1=1' . Helpers::site_members_sql();
		$args   = array( $wpdb->users, Install::table( 'member_stats' ) );
		if ( $this->search() ) {
			$like   = '%' . $wpdb->esc_like( $this->search() ) . '%';
			$where .= ' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		if ( in_array( $status, array( 'muted', 'banned' ), true ) ) {
			$ids    = array_map( 'intval', get_users( array( 'meta_key' => Moderation::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 1000 ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$ids    = array_values( array_filter( $ids, fn( $id ) => ( Moderation::restriction( $id )['status'] ?? '' ) === $status ) ) ?: array( 0 );
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
		$this->items = $rows;
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	/** Posts, comments, messages and chats per member, in four small queries. */
	private function counts( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array_fill_keys( $ids, array( 'posts' => 0, 'comments' => 0, 'sent' => 0, 'chats' => 0 ) );
		$q   = array(
			'posts'    => array( "SELECT user_id, COUNT(*) n FROM %i WHERE status = 'published' AND user_id IN ({$in}) GROUP BY user_id", 'posts' ),
			'comments' => array( "SELECT user_id, COUNT(*) n FROM %i WHERE user_id IN ({$in}) GROUP BY user_id", 'comments' ),
			'sent'     => array( "SELECT user_id, COUNT(*) n FROM %i WHERE status = 'sent' AND user_id IN ({$in}) GROUP BY user_id", 'messages' ),
			'chats'    => array( "SELECT user_id, COUNT(*) n FROM %i WHERE left_at IS NULL AND user_id IN ({$in}) GROUP BY user_id", 'thread_members' ),
		);
		foreach ( $q as $field => list( $sql, $table ) ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( Install::table( $table ) ), $ids ) ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL with an id placeholder list
				$out[ (int) $row['user_id'] ][ $field ] = (int) $row['n'];
			}
		}
		return $out;
	}

	protected function column_name( $item ) {
		$id      = (int) $item['id'];
		$avatar  = get_avatar_url( $id, array( 'size' => 64 ) );
		$actions = array( 'view' => '<a href="' . esc_url( Admin::app_url( 'members/' . $id ) ) . '">' . esc_html__( 'View profile', 'membuild-community' ) . '</a>' );
		if ( ! Settings::is_staff( $id ) ) {
			$r = Moderation::restriction( $id );
			if ( $r ) {
				$actions['lift'] = $this->action_link( __( 'Lift', 'membuild-community' ), 'members_lift', $id );
			} else {
				$actions['mute'] = $this->action_link( __( 'Mute 7 days', 'membuild-community' ), 'members_mute', $id );
				/* translators: %s: member name */
				$actions['ban'] = $this->action_link( __( 'Ban', 'membuild-community' ), 'members_ban', $id, sprintf( __( 'Ban %s from the community?', 'membuild-community' ), $item['display_name'] ), 'submitdelete' );
			}
		}
		$actions['edit'] = '<a href="' . esc_url( get_edit_user_link( $id ) ) . '">' . esc_html__( 'Edit account', 'membuild-community' ) . '</a>';
		// The email sits under the name, as on WordPress's own Users screen, so it never breaks across lines.
		// Admin, moderator, muted or banned shows next to the name: most members have no label, so it needs no column.
		return '<span class="mbcom-person"><img src="' . esc_url( $avatar ) . '" width="36" height="36" alt=""><span><span class="mbcom-name"><strong>' . esc_html( $item['display_name'] ) . '</strong> ' . $this->column_status( $item ) . '</span><a class="mbcom-muted" href="mailto:' . esc_attr( $item['user_email'] ) . '">' . esc_html( $item['user_email'] ) . '</a></span></span>' . $this->row_actions( $actions );
	}

	protected function column_status( $item ) {
		$id = (int) $item['id'];
		if ( user_can( $id, 'manage_options' ) ) {
			return '<span class="mbcom-tag ok">' . esc_html__( 'Admin', 'membuild-community' ) . '</span>';
		}
		if ( Settings::is_staff( $id ) ) {
			return '<span class="mbcom-tag ok">' . esc_html__( 'Moderator', 'membuild-community' ) . '</span>';
		}
		$r = Moderation::restriction( $id );
		if ( ! $r ) {
			return ''; // most people are plain members: only the exceptions get a label
		}
		$until = $r['until'] ? wp_date( get_option( 'date_format' ), strtotime( $r['until'] ) ) : null;
		$label = 'banned' === $r['status']
			? __( 'Banned', 'membuild-community' )
			/* translators: %s: date and time the mute ends */
			: ( $until ? sprintf( __( 'Muted until %s', 'membuild-community' ), $until ) : __( 'Muted', 'membuild-community' ) );
		return '<span class="mbcom-tag ' . ( 'banned' === $r['status'] ? 'bad' : 'warn' ) . '">' . esc_html( $label ) . '</span>';
	}

	protected function column_joined( $item ) {
		return $this->date( $item['user_registered'] );
	}

	/** The day they last opened the community (it's kept as a day, not a time). */
	protected function column_last_active( $item ) {
		if ( ! $item['last_active'] ) {
			return '<span class="mbcom-muted">' . esc_html__( 'Not yet', 'membuild-community' ) . '</span>';
		}
		$day   = $item['last_active'];
		$today = current_time( 'Y-m-d' );
		$label = $today === $day ? __( 'Today', 'membuild-community' )
			: ( gmdate( 'Y-m-d', strtotime( "{$today} -1 day" ) ) === $day ? __( 'Yesterday', 'membuild-community' )
			: date_i18n( substr( $day, 0, 4 ) === substr( $today, 0, 4 ) ? 'M j' : 'M j, Y', strtotime( $day ) ) );
		return '<span title="' . esc_attr( date_i18n( get_option( 'date_format' ), strtotime( $day ) ) ) . '">' . esc_html( $label ) . '</span>';
	}

	protected function column_points( $item ) {
		$name = Settings::get( 'level_names' )[ (int) $item['level'] - 1 ] ?? '';
		/* translators: %d: level number */
		$level = sprintf( __( 'Level %d', 'membuild-community' ), $item['level'] ) . ( '' !== $name ? ' · ' . $name : '' );
		/* translators: %s: number of points */
		return '<strong>' . esc_html( $level ) . '</strong><br><span class="mbcom-muted">' . esc_html( sprintf( _n( '%s point', '%s points', (int) $item['points'], 'membuild-community' ), number_format_i18n( $item['points'] ) ) ) . '</span>';
	}

	protected function column_activity( $item ) {
		$posts    = (int) ( $item['posts'] ?? 0 );
		$comments = (int) ( $item['comments'] ?? 0 );
		/* translators: %s: number of posts */
		$posts_label = esc_html( sprintf( _n( '%s post', '%s posts', $posts, 'membuild-community' ), number_format_i18n( $posts ) ) );
		/* translators: %s: number of comments, e.g. "12" or "1.2K" */
		$comments_label = esc_html( sprintf( _n( '%s comment', '%s comments', $comments, 'membuild-community' ), number_format_i18n( $comments ) ) );
		return ( $posts ? '<a href="' . esc_url( Admin::url( 'posts', array( 'author' => (int) $item['id'] ) ) ) . '">' . $posts_label . '</a>' : $posts_label ) . '<br><span class="mbcom-muted">' . $comments_label . '</span>';
	}

	/** How many messages, never what they say. */
	protected function column_messages( $item ) {
		$sent  = (int) ( $item['sent'] ?? 0 );
		$chats = (int) ( $item['chats'] ?? 0 );
		if ( ! $sent && ! $chats ) {
			return '<span class="mbcom-muted">0</span>';
		}
		/* translators: %s: number of chats */
		return esc_html( number_format_i18n( $sent ) ) . '<br><span class="mbcom-muted">' . esc_html( sprintf( _n( 'in %s chat', 'in %s chats', $chats, 'membuild-community' ), number_format_i18n( $chats ) ) ) . '</span>';
	}
}

/* ================================================================== */

class Posts_Table extends Base_Table {

	protected $key      = 'posts';
	protected $sortable = array(
		'date'      => 'p.id',
		'reactions' => 'p.reactions_count',
		'comments'  => 'p.comments_count',
	);
	protected $default_order = array( 'date', 'desc' );

	public function title() {
		return __( 'Posts and reels', 'membuild-community' );
	}

	public function search_label() {
		return __( 'Search posts', 'membuild-community' );
	}

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'content'   => __( 'Post', 'membuild-community' ),
			'author'    => __( 'Author', 'membuild-community' ),
			'type'      => __( 'Type', 'membuild-community' ),
			'reactions' => __( 'Reactions', 'membuild-community' ),
			'comments'  => __( 'Comments', 'membuild-community' ),
			'date'      => __( 'Posted', 'membuild-community' ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'hide'    => __( 'Hide from the community', 'membuild-community' ),
			'restore' => __( 'Show again', 'membuild-community' ),
			'delete'  => __( 'Delete', 'membuild-community' ),
		);
	}

	protected function get_views() {
		global $wpdb;
		$counts = array_column( $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM %i GROUP BY status', Install::table( 'posts' ) ), ARRAY_A ), 'n', 'status' );
		return $this->views_from(
			array(
				''          => array( __( 'Live', 'membuild-community' ), (int) ( $counts['published'] ?? 0 ) ),
				'review'    => array( __( 'Hidden', 'membuild-community' ), (int) ( $counts['review'] ?? 0 ) ),
				'deleted'   => array( __( 'Deleted', 'membuild-community' ), (int) ( $counts['deleted'] ?? 0 ) ),
			),
			$this->arg( 'status' )
		);
	}

	public function prepare_items() {
		global $wpdb;
		$per    = $this->per_page();
		$status = in_array( $this->arg( 'status' ), array( 'review', 'deleted' ), true ) ? $this->arg( 'status' ) : 'published';
		$where  = 'p.status = %s';
		$args   = array( Install::table( 'posts' ), $status );
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
		$this->users = Helpers::users( wp_list_pluck( $this->items, 'user_id' ) );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	public $users = array();

	protected function column_content( $item ) {
		$id      = (int) $item['id'];
		$text    = wp_trim_words( wp_strip_all_tags( (string) $item['content'] ), 24, '…' );
		$media   = Media::for_posts( array( $id ) )[ $id ] ?? array();
		$src     = $media ? ( 'image' === $media[0]['kind'] ? $media[0]['url'] : $media[0]['poster'] ) : '';
		// Every row gets a tile, so text-only posts line up with the ones that have a photo.
		$thumb = $src ? '<img class="mbcom-thumb" src="' . esc_url( $src ) . '" alt="">' : '<span class="mbcom-thumb mbcom-thumb-text" aria-hidden="true">Aa</span>';
		$actions = array();
		if ( 'published' === $item['status'] ) {
			$actions['view'] = '<a href="' . esc_url( Admin::app_url( '?post=' . $id ) ) . '">' . esc_html__( 'View', 'membuild-community' ) . '</a>';
			$actions['pin']  = $item['is_pinned'] ? $this->action_link( __( 'Unpin', 'membuild-community' ), 'posts_unpin', $id ) : $this->action_link( __( 'Pin to top', 'membuild-community' ), 'posts_pin', $id );
			$actions['hide'] = $this->action_link( __( 'Hide', 'membuild-community' ), 'posts_hide', $id );
		} elseif ( 'deleted' !== $item['status'] ) { // a deleted post's files are gone, so it can't come back
			$actions['restore'] = $this->action_link( __( 'Show again', 'membuild-community' ), 'posts_restore', $id );
		}
		if ( 'deleted' !== $item['status'] ) {
			$actions['delete'] = $this->action_link( __( 'Delete', 'membuild-community' ), 'posts_delete', $id, __( 'Delete this post for everyone?', 'membuild-community' ), 'submitdelete' );
		}
		$label = '' !== $text ? esc_html( $text ) : '<em class="mbcom-muted">' . esc_html( $media ? __( '(photo or video)', 'membuild-community' ) : __( '(no text)', 'membuild-community' ) ) . '</em>';
		$pinned = $item['is_pinned'] ? ' <span class="mbcom-tag"><span class="dashicons dashicons-sticky" aria-hidden="true"></span> ' . esc_html__( 'Pinned', 'membuild-community' ) . '</span>' : '';
		return '<div class="mbcom-item">' . $thumb . '<div>' . $label . $pinned . $this->row_actions( $actions ) . '</div></div>';
	}

	protected function column_author( $item ) {
		return $this->who( $this->users[ (int) $item['user_id'] ] ?? null );
	}

	protected function column_type( $item ) {
		return esc_html( 'reel' === $item['type'] ? __( 'Reel', 'membuild-community' ) : __( 'Post', 'membuild-community' ) );
	}

	protected function column_reactions( $item ) {
		return esc_html( number_format_i18n( $item['reactions_count'] ) );
	}

	protected function column_comments( $item ) {
		return '<a href="' . esc_url( Admin::url( 'comments', array( 'post' => (int) $item['id'] ) ) ) . '">' . esc_html( number_format_i18n( $item['comments_count'] ) ) . '</a>';
	}

	protected function column_date( $item ) {
		return $this->date( $item['created_at'] );
	}
}

/* ================================================================== */

class Comments_Table extends Base_Table {

	protected $key      = 'comments';
	protected $sortable = array(
		'date'      => 'c.id',
		'reactions' => 'c.reactions_count',
	);
	protected $default_order = array( 'date', 'desc' );
	public $users            = array();

	public function title() {
		return __( 'Comments', 'membuild-community' );
	}

	public function search_label() {
		return __( 'Search comments', 'membuild-community' );
	}

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'content'   => __( 'Comment', 'membuild-community' ),
			'author'    => __( 'Author', 'membuild-community' ),
			'post'      => __( 'On post', 'membuild-community' ),
			'reactions' => __( 'Reactions', 'membuild-community' ),
			'date'      => __( 'Written', 'membuild-community' ),
		);
	}

	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'membuild-community' ) );
	}

	public function prepare_items() {
		global $wpdb;
		$per   = $this->per_page();
		$where = '1=1';
		$args  = array( Install::table( 'comments' ), Install::table( 'posts' ) );
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
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, p.content AS post_content, p.status AS post_status FROM %i c LEFT JOIN %i p ON p.id = c.post_id WHERE {$where} ORDER BY " . $this->order_sql() . ' LIMIT %d OFFSET %d', $args ), ARRAY_A );
		$total       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.id = c.post_id WHERE {$where}", $count_args ) );
		// phpcs:enable
		$this->users = Helpers::users( wp_list_pluck( $this->items, 'user_id' ) );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	protected function column_content( $item ) {
		$text    = wp_trim_words( wp_strip_all_tags( (string) $item['content'] ), 30, '…' );
		$actions = array(
			'view'   => '<a href="' . esc_url( Admin::app_url( '?post=' . (int) $item['post_id'] ) ) . '">' . esc_html__( 'View in post', 'membuild-community' ) . '</a>',
			'delete' => $this->action_link( __( 'Delete', 'membuild-community' ), 'comments_delete', (int) $item['id'], __( 'Delete this comment and its replies?', 'membuild-community' ), 'submitdelete' ),
		);
		$reply = (int) $item['parent_id'] ? '<span class="mbcom-tag">' . esc_html__( 'Reply', 'membuild-community' ) . '</span> ' : '';
		$img   = $item['image_url'] ? ' <span class="mbcom-tag">' . esc_html__( 'Photo', 'membuild-community' ) . '</span>' : '';
		return $reply . ( '' !== $text ? esc_html( $text ) : '' ) . $img . $this->row_actions( $actions );
	}

	protected function column_author( $item ) {
		return $this->who( $this->users[ (int) $item['user_id'] ] ?? null );
	}

	protected function column_post( $item ) {
		$text = wp_trim_words( wp_strip_all_tags( (string) $item['post_content'] ), 8, '…' ) ?: __( '(photo or video)', 'membuild-community' );
		$gone = 'published' !== $item['post_status'] ? ' <span class="mbcom-tag warn">' . esc_html__( 'Post not live', 'membuild-community' ) . '</span>' : '';
		return '<a href="' . esc_url( Admin::url( 'comments', array( 'post' => (int) $item['post_id'] ) ) ) . '">' . esc_html( $text ) . '</a>' . $gone;
	}

	protected function column_reactions( $item ) {
		return esc_html( number_format_i18n( $item['reactions_count'] ) );
	}

	protected function column_date( $item ) {
		return $this->date( $item['created_at'] );
	}
}

/* ================================================================== */

class Files_Table extends Base_Table {

	protected $key      = 'files';
	protected $sortable = array( 'date' => 'm.id' );
	protected $default_order = array( 'date', 'desc' );
	public $users            = array();

	public function title() {
		return __( 'Files', 'membuild-community' );
	}

	public function title_actions() {
		/* translators: %s: total size, e.g. "120 MB" */
		return '<span class="mbcom-tag">' . esc_html( sprintf( __( '%s in total', 'membuild-community' ), size_format( Admin::storage_bytes() ) ) ) . '</span>';
	}

	public function intro() {
		echo '<p class="mbcom-muted">' . esc_html__( 'Photos, videos and documents uploaded to the community. They’re kept out of WordPress’s Media Library so members’ private photos stay private. Uploads that are never used are removed automatically after a day.', 'membuild-community' ) . '</p>';
	}

	public function searchable() {
		return false;
	}

	public function get_columns() {
		return array(
			'cb'     => '<input type="checkbox">',
			'file'   => __( 'File', 'membuild-community' ),
			'kind'   => __( 'Type', 'membuild-community' ),
			'size'   => __( 'Size', 'membuild-community' ),
			'author' => __( 'Uploaded by', 'membuild-community' ),
			'used'   => __( 'Used in', 'membuild-community' ),
			'date'   => __( 'Uploaded', 'membuild-community' ),
		);
	}

	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'membuild-community' ) );
	}

	protected function get_views() {
		global $wpdb;
		$counts = array_column( $wpdb->get_results( $wpdb->prepare( "SELECT kind, COUNT(*) n FROM %i WHERE kind <> 'poster' AND ( scope IS NULL OR scope <> 'message' ) GROUP BY kind", Install::table( 'media' ) ), ARRAY_A ), 'n', 'kind' );
		return $this->views_from(
			array(
				''      => array( __( 'All', 'membuild-community' ), array_sum( array_map( 'intval', $counts ) ) ),
				'image' => array( __( 'Photos', 'membuild-community' ), (int) ( $counts['image'] ?? 0 ) ),
				'video' => array( __( 'Videos', 'membuild-community' ), (int) ( $counts['video'] ?? 0 ) ),
				'file'  => array( __( 'Documents', 'membuild-community' ), (int) ( $counts['file'] ?? 0 ) ),
				'embed' => array( __( 'Video links', 'membuild-community' ), (int) ( $counts['embed'] ?? 0 ) ),
			),
			$this->arg( 'type' ),
			'type'
		);
	}

	public function prepare_items() {
		global $wpdb;
		$per   = $this->per_page();
		// Photos sent in private messages are never listed: admins only ever see message counts.
		$where = "m.kind <> 'poster' AND ( m.scope IS NULL OR m.scope <> 'message' )";
		$args  = array( Install::table( 'media' ) );
		if ( in_array( $this->arg( 'type' ), array( 'image', 'video', 'file', 'embed' ), true ) ) {
			$where .= ' AND m.kind = %s';
			$args[] = $this->arg( 'type' );
		}
		$count_args = $args;
		array_push( $args, $per, ( $this->paged() - 1 ) * $per );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where holds only fixed fragments with placeholders; ORDER BY comes from the whitelist in $sortable.
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT m.* FROM %i m WHERE {$where} ORDER BY " . $this->order_sql() . ' LIMIT %d OFFSET %d', $args ), ARRAY_A );
		$total       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i m WHERE {$where}", $count_args ) );
		// phpcs:enable
		$this->users = Helpers::users( wp_list_pluck( $this->items, 'user_id' ) );
		$this->uses  = $this->uses( $this->items );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per ) );
	}

	public $uses = array(); // media ID => where it's used, for uploads not in a post

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
		$lessons = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT media_id FROM %i WHERE media_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'lessons' ) ), $ids ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder list
		$named   = array(
			(int) ( Settings::get( 'logo' )['id'] ?? 0 )     => __( 'The community logo', 'membuild-community' ),
			(int) ( Settings::get( 'app_icon' )['id'] ?? 0 ) => __( 'The app icon', 'membuild-community' ),
		);
		$out = array();
		foreach ( $rows as $m ) {
			$id          = (int) $m['id'];
			$out[ $id ]  = $named[ $id ] ?? ( 'lesson' === $m['scope'] || in_array( $id, $lessons, true ) ? __( 'A lesson', 'membuild-community' ) : ( $found[ $m['url'] ] ?? null ) );
		}
		return $out;
	}

	protected function column_file( $item ) {
		$stored  = $item['attachment_id'] ? basename( (string) get_attached_file( (int) $item['attachment_id'] ) ) : basename( wp_parse_url( $item['url'], PHP_URL_PATH ) ?: '' );
		// Files are saved under random names (so nobody can guess another member's file); show the name it was uploaded with.
		$title   = $item['attachment_id'] ? get_the_title( (int) $item['attachment_id'] ) : '';
		$name    = '' !== $title ? $title . '.' . pathinfo( $stored, PATHINFO_EXTENSION ) : $stored;
		$src     = 'image' === $item['kind'] ? $item['url'] : $item['poster_url'];
		$thumb   = $src ? '<img class="mbcom-thumb" src="' . esc_url( $src ) . '" alt="">' : '<span class="mbcom-thumb mbcom-thumb-text" aria-hidden="true"><span class="dashicons dashicons-media-default"></span></span>';
		$actions = array(
			'open'   => '<a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open', 'membuild-community' ) . '</a>',
			'delete' => $this->action_link( __( 'Delete', 'membuild-community' ), 'files_delete', (int) $item['id'], __( 'Delete this file? It disappears from wherever it’s used.', 'membuild-community' ), 'submitdelete' ),
		);
		return '<div class="mbcom-item">' . $thumb . '<div><strong>' . esc_html( $name ?: __( '(file)', 'membuild-community' ) ) . '</strong>' . $this->row_actions( $actions ) . '</div></div>';
	}

	protected function column_kind( $item ) {
		$labels = array(
			'image' => __( 'Photo', 'membuild-community' ),
			'video' => 'video/bunny' === $item['mime'] ? __( 'Video (Bunny Stream)', 'membuild-community' ) : __( 'Video', 'membuild-community' ),
			'file'  => __( 'Document', 'membuild-community' ),
			'embed' => __( 'Video link', 'membuild-community' ),
		);
		return esc_html( $labels[ $item['kind'] ] ?? $item['kind'] );
	}

	protected function column_size( $item ) {
		return (int) $item['bytes'] > 1 ? esc_html( size_format( (int) $item['bytes'] ) ) : '<span class="mbcom-muted">—</span>';
	}

	protected function column_author( $item ) {
		return $this->who( $this->users[ (int) $item['user_id'] ] ?? null );
	}

	protected function column_used( $item ) {
		if ( null === $item['post_id'] ) {
			// Uploads start as soon as a file is picked; one that never gets posted is removed by the hourly cleanup.
			return '<span class="mbcom-tag warn">' . esc_html__( 'Not posted', 'membuild-community' ) . '</span><br><span class="description">' . esc_html__( 'Deleted automatically within a day', 'membuild-community' ) . '</span>';
		}
		if ( (int) $item['post_id'] > 0 ) {
			/* translators: %d: post number */
			return '<a href="' . esc_url( Admin::app_url( '?post=' . (int) $item['post_id'] ) ) . '">' . esc_html( sprintf( __( 'Post #%d', 'membuild-community' ), $item['post_id'] ) ) . '</a>';
		}
		$use = $this->uses[ (int) $item['id'] ] ?? null;
		// Left over from before replaced photos were deleted automatically: nothing shows it any more.
		return $use ? esc_html( $use ) : '<span class="mbcom-muted">' . esc_html__( 'Not found in use', 'membuild-community' ) . '</span>';
	}

	protected function column_date( $item ) {
		return $this->date( $item['created_at'] );
	}
}

/* ================================================================== */

class Courses_Table extends Base_Table {

	protected $key      = 'courses';
	protected $sortable = array( 'title' => 'c.title', 'started' => 'started' );
	protected $default_order = array( 'title', 'asc' );

	public function title() {
		$course = $this->course();
		/* translators: %s: course title */
		return $course ? sprintf( __( 'Progress in “%s”', 'membuild-community' ), $course['title'] ) : __( 'Courses', 'membuild-community' );
	}

	public function title_actions() {
		return $this->course()
			? '<a class="page-title-action" href="' . esc_url( Admin::url( 'courses' ) ) . '">' . esc_html__( 'All courses', 'membuild-community' ) . '</a>'
			: '<a class="page-title-action" href="' . esc_url( Admin::app_url( 'classroom' ) ) . '">' . esc_html__( 'Edit courses in the community', 'membuild-community' ) . '</a>';
	}

	public function searchable() {
		return false;
	}

	private function course() {
		global $wpdb;
		$id = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- list filter
		return $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'courses' ), $id ), ARRAY_A ) : null;
	}

	public function get_columns() {
		if ( $this->course() ) {
			return array(
				'member'   => __( 'Member', 'membuild-community' ),
				'progress' => __( 'Progress', 'membuild-community' ),
				'last'     => __( 'Last lesson finished', 'membuild-community' ),
			);
		}
		return array(
			'title'    => __( 'Course', 'membuild-community' ),
			'status'   => __( 'Status', 'membuild-community' ),
			'access'   => __( 'Who can open it', 'membuild-community' ),
			'lessons'  => __( 'Lessons', 'membuild-community' ),
			'started'  => __( 'Members started', 'membuild-community' ),
			'finished' => __( 'Finished', 'membuild-community' ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$lessons  = Install::table( 'lessons' );
		$progress = Install::table( 'lesson_progress' );
		$course   = $this->course();
		if ( $course ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', $lessons, $course['id'] ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, COUNT(*) done, MAX(completed_at) last FROM %i WHERE course_id = %d GROUP BY user_id ORDER BY done DESC, last DESC LIMIT 500', $progress, $course['id'] ), ARRAY_A );
			$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
			foreach ( $rows as &$r ) {
				$r['total'] = $total;
				$r['user']  = $users[ (int) $r['user_id'] ] ?? null;
			}
			$this->items = $rows;
			$this->set_pagination_args( array( 'total_items' => count( $rows ), 'per_page' => 500 ) );
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY comes from the whitelist in $sortable.
		$this->items = $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, (SELECT COUNT(*) FROM %i l WHERE l.course_id = c.id) AS lessons, (SELECT COUNT(DISTINCT user_id) FROM %i p WHERE p.course_id = c.id) AS started FROM %i c ORDER BY ' . $this->order_sql(), $lessons, $progress, Install::table( 'courses' ) ), ARRAY_A );
		foreach ( $this->items as &$c ) {
			$c['finished'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT user_id FROM %i WHERE course_id = %d GROUP BY user_id HAVING COUNT(*) >= %d) f', $progress, $c['id'], max( 1, (int) $c['lessons'] ) ) );
		}
		$this->set_pagination_args( array( 'total_items' => count( $this->items ), 'per_page' => 1000 ) );
	}

	protected function column_title( $item ) {
		$actions = array(
			'progress' => '<a href="' . esc_url( Admin::url( 'courses', array( 'course' => (int) $item['id'] ) ) ) . '">' . esc_html__( 'See progress', 'membuild-community' ) . '</a>',
			'open'     => '<a href="' . esc_url( Admin::app_url( 'classroom/' . (int) $item['id'] ) ) . '">' . esc_html__( 'Open in the community', 'membuild-community' ) . '</a>',
		);
		$cover = $item['cover_url'] ? '<img class="mbcom-thumb mbcom-thumb-wide" src="' . esc_url( $item['cover_url'] ) . '" alt="">' : '<span class="mbcom-thumb mbcom-thumb-wide mbcom-thumb-text" aria-hidden="true"><span class="dashicons dashicons-welcome-learn-more"></span></span>';
		return '<div class="mbcom-item">' . $cover . '<div><strong><a href="' . esc_url( Admin::url( 'courses', array( 'course' => (int) $item['id'] ) ) ) . '">' . esc_html( $item['title'] ) . '</a></strong>' . $this->row_actions( $actions ) . '</div></div>';
	}

	protected function column_access( $item ) {
		if ( 'level' === $item['access'] ) {
			$name = Settings::get( 'level_names' )[ (int) $item['unlock_level'] - 1 ] ?? '';
			/* translators: %d: level number */
			return esc_html( sprintf( __( 'Level %d and up', 'membuild-community' ), $item['unlock_level'] ) . ( '' !== $name ? ' · ' . $name : '' ) );
		}
		if ( 'groups' === $item['access'] ) {
			$groups = Access::groups();
			$names  = array_filter( array_map( fn( $id ) => $groups[ $id ]['name'] ?? '', Access::course_groups( $item ) ) );
			return esc_html( $names ? wp_sprintf( '%l', $names ) : __( 'Member groups', 'membuild-community' ) );
		}
		return '<span class="mbcom-muted">' . esc_html__( 'Everyone', 'membuild-community' ) . '</span>';
	}

	protected function column_status( $item ) {
		return 'published' === $item['status'] ? '<span class="mbcom-tag ok">' . esc_html__( 'Published', 'membuild-community' ) . '</span>' : '<span class="mbcom-tag">' . esc_html__( 'Draft', 'membuild-community' ) . '</span>';
	}

	protected function column_lessons( $item ) {
		return esc_html( number_format_i18n( $item['lessons'] ) );
	}

	protected function column_started( $item ) {
		return esc_html( number_format_i18n( $item['started'] ) );
	}

	protected function column_finished( $item ) {
		return esc_html( number_format_i18n( $item['finished'] ) );
	}

	protected function column_member( $item ) {
		return $this->who( $item['user'] );
	}

	protected function column_progress( $item ) {
		$pct = $item['total'] ? min( 100, round( 100 * $item['done'] / $item['total'] ) ) : 0;
		/* translators: 1: lessons done, 2: lessons in the course */
		return '<span class="mbcom-bar"><i style="width:' . (int) $pct . '%"></i></span>' . esc_html( sprintf( __( '%1$d of %2$d lessons', 'membuild-community' ), $item['done'], $item['total'] ) );
	}

	protected function column_last( $item ) {
		return $this->date( $item['last'] );
	}

	public function no_items() {
		echo esc_html( $this->course() ? __( 'Nobody has started this course yet.', 'membuild-community' ) : __( 'No courses yet. Create one in the community’s Classroom.', 'membuild-community' ) );
	}
}
