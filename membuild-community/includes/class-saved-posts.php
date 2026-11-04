<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Saved posts: a private list a member keeps for themself. The writer is never told and
 * no points move. A saved post that later goes away stays in the list as a quiet row
 * (without its words or pictures), so the member can see why it's missing.
 */
class Saved_Posts {

	/** Rows per page of the Saved posts page. */
	const PER_PAGE = 20;

	public static function register_routes() {
		$auth = array( Rest_Feed::class, 'logged_in' );

		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/saved',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => $auth,
				'args'                => array(
					'cursor' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/save',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'save' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'unsave' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	public static function register_hooks() {
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
	}

	public static function on_user_deleted( $user_id ) {
		global $wpdb;
		$wpdb->delete( Install::table( 'saved_posts' ), array( 'user_id' => (int) $user_id ) );
	}

	/** Which of these posts this member saved, as a set (post id => true). */
	public static function mine( array $post_ids ) {
		global $wpdb;
		if ( ! $post_ids ) {
			return array();
		}
		return array_flip(
			array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						'SELECT post_id FROM %i WHERE user_id = %d AND post_id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')',
						array_merge( array( Install::table( 'saved_posts' ), get_current_user_id() ), $post_ids )
					)
				)
			)
		);
	}

	public static function save( \WP_REST_Request $req ) {
		global $wpdb;
		$post_id = (int) $req['id'];
		if ( ! Rest_Feed::row( $post_id ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$slow = Helpers::throttle( 'save', 120, 10 * MINUTE_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (user_id, post_id, created_at) VALUES (%d, %d, %s)',
				Install::table( 'saved_posts' ),
				get_current_user_id(),
				$post_id,
				Helpers::now()
			)
		);
		return array( 'saved' => true );
	}

	/** Works on a post that's gone too: that's how a quiet row gets removed. */
	public static function unsave( \WP_REST_Request $req ) {
		global $wpdb;
		$wpdb->delete( Install::table( 'saved_posts' ), array( 'user_id' => get_current_user_id(), 'post_id' => (int) $req['id'] ) );
		return array( 'saved' => false );
	}

	/**
	 * One page of this member's saved posts, newest saved first. A post they can no longer
	 * read comes back as { id, gone, saved_id } with none of its content.
	 */
	public static function index( \WP_REST_Request $req ) {
		global $wpdb;
		$cursor = (int) $req['cursor'];
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, post_id FROM %i WHERE user_id = %d' . ( $cursor ? ' AND id < %d' : '' ) . ' ORDER BY id DESC LIMIT %d',
				array_merge( array( Install::table( 'saved_posts' ), get_current_user_id() ), $cursor ? array( $cursor ) : array(), array( self::PER_PAGE + 1 ) )
			),
			ARRAY_A
		);
		$next = null;
		if ( count( $rows ) > self::PER_PAGE ) {
			array_pop( $rows );
			$next = (int) end( $rows )['id'];
		}

		$open = array();
		$gone = array();
		foreach ( $rows as $saved ) {
			$post_id = (int) $saved['post_id'];
			$row     = Rest_Feed::row( $post_id );
			if ( $row ) {
				$open[ $post_id ] = $row;
				continue;
			}
			$status           = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Install::table( 'posts' ), $post_id ) );
			$gone[ $post_id ] = ( null === $status || 'deleted' === $status ) ? 'deleted' : 'locked';
		}
		$posts = array();
		foreach ( Rest_Feed::format_posts( array_values( $open ) ) as $post ) {
			$posts[ $post['id'] ] = $post;
		}

		$items = array();
		foreach ( $rows as $saved ) {
			$post_id = (int) $saved['post_id'];
			$items[] = isset( $posts[ $post_id ] )
				? $posts[ $post_id ] + array( 'saved_id' => (int) $saved['id'] )
				: array( 'id' => $post_id, 'gone' => $gone[ $post_id ], 'saved_id' => (int) $saved['id'] );
		}
		return array( 'items' => $items, 'next_cursor' => $next );
	}
}
