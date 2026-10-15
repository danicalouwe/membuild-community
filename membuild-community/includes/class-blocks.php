<?php
/**
 * Members blocking each other.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Blocking works both ways, like Facebook: once either member blocks the
 * other, neither sees the other's posts, comments, stories or profile,
 * neither finds the other in search, and they can't message each other.
 *
 * Admins and moderators can't be blocked (they must be able to reach every
 * member) and aren't affected by blocks, so moderation always sees everything.
 *
 *   GET /blocks · PUT|DELETE /members/{id}/block
 */
class Blocks {

	private static $hidden = array(); // per request: viewer ID => [ user ID => true ]

	public static function register_routes() {
		$auth = array( Rest_Feed::class, 'logged_in' );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/blocks', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $auth ) );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/members/(?P<id>\d+)/block',
			array(
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_block' ), 'permission_callback' => $auth ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_unblock' ), 'permission_callback' => $auth ),
			)
		);
	}

	public static function register_hooks() {
		// A deleted account leaves no blocks behind.
		add_action(
			'deleted_user',
			function ( $user_id ) {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d OR blocked_id = %d', Install::table( 'blocks' ), $user_id, $user_id ) );
			}
		);
	}

	/**
	 * Everyone hidden from $viewer: people they blocked and people who blocked them.
	 * Staff see everyone.
	 *
	 * @return array<int, true> User ID => true.
	 */
	public static function hidden( $viewer = null ) {
		global $wpdb;
		$viewer = null === $viewer ? get_current_user_id() : (int) $viewer;
		if ( ! $viewer || Settings::is_staff( $viewer ) ) {
			return array();
		}
		if ( ! isset( self::$hidden[ $viewer ] ) ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT blocked_id FROM %i WHERE user_id = %d UNION SELECT user_id FROM %i WHERE blocked_id = %d',
					Install::table( 'blocks' ),
					$viewer,
					Install::table( 'blocks' ),
					$viewer
				)
			);
			self::$hidden[ $viewer ] = array_fill_keys( array_map( 'intval', $ids ), true );
		}
		return self::$hidden[ $viewer ];
	}

	/** Whether $user is hidden from $viewer (the current member by default). */
	public static function hides( $user, $viewer = null ) {
		return isset( self::hidden( $viewer )[ (int) $user ] );
	}

	/** Rows without the ones written by people hidden from the current member. */
	public static function filter( array $rows, $column = 'user_id' ) {
		$hidden = self::hidden();
		return $hidden ? array_values( array_filter( $rows, fn( $row ) => ! isset( $hidden[ (int) ( is_array( $row ) ? $row[ $column ] : $row ) ] ) ) ) : $rows;
	}

	/** Why $me can't block $target, or null when they can. */
	private static function cannot_block( $me, $target ) {
		if ( $target === $me || ! get_userdata( $target ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Member not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( Settings::is_staff( $target ) ) {
			return new \WP_Error( 'mbcom_cannot_block', __( 'Admins and moderators can’t be blocked, because they need to reach every member. If one of them is bothering you, report it to the site owner.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		if ( Settings::is_staff( $me ) ) {
			return new \WP_Error( 'mbcom_cannot_block', __( 'Admins and moderators mute or ban members instead of blocking them.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		return null;
	}

	public static function rest_block( \WP_REST_Request $req ) {
		global $wpdb;
		$me     = get_current_user_id();
		$target = (int) $req['id'];
		$error  = self::cannot_block( $me, $target );
		if ( $error ) {
			return $error;
		}
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id, blocked_id, created_at) VALUES (%d, %d, %s)', Install::table( 'blocks' ), $me, $target, Helpers::now() ) );
		self::$hidden = array();
		/**
		 * A member blocked another member.
		 *
		 * @param int $user_id    Who blocked.
		 * @param int $blocked_id Who was blocked.
		 */
		do_action( 'membuild_community/member_blocked', $me, $target );
		return array( 'blocked' => true );
	}

	public static function rest_unblock( \WP_REST_Request $req ) {
		global $wpdb;
		$wpdb->delete( Install::table( 'blocks' ), array( 'user_id' => get_current_user_id(), 'blocked_id' => (int) $req['id'] ) );
		self::$hidden = array();
		return array( 'blocked' => false );
	}

	/** The people I blocked, newest first, so I can unblock them. */
	public static function rest_list() {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT blocked_id FROM %i WHERE user_id = %d ORDER BY created_at DESC', Install::table( 'blocks' ), get_current_user_id() ) ) );
		return array( 'items' => array_values( Helpers::users( $ids ) ) );
	}

	/** Whether I blocked this member (my own choice, as opposed to being blocked). */
	public static function i_blocked( $target ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d AND blocked_id = %d', Install::table( 'blocks' ), get_current_user_id(), $target ) );
	}
}
