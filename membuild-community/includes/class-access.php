<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Who can open which course.
 *
 * Each course is open to everyone, to members from a level up, or to members
 * of chosen access groups. A member is in a group when an admin added them, or
 * when they have one of the WordPress roles linked to it — checked live, so a
 * membership plugin that removes a role also removes access. Other plugins can
 * decide group membership through the membuild_community/in_access_group filter.
 * Admins and moderators open everything. Losing access never deletes progress.
 *
 * Admin REST (manage_options):
 *   GET|POST /groups · PUT|DELETE /groups/{id}
 *   GET|POST /groups/{id}/members · DELETE /groups/{id}/members/{user}
 *   GET|PUT /members/{id}/groups
 */
class Access {

	const MODES = array( 'everyone', 'level', 'groups' );

	private static $groups = null;
	private static $mine   = array();

	public static function register_hooks() {
		// A deleted WordPress account leaves its groups too.
		add_action(
			'deleted_user',
			function ( $user_id ) {
				global $wpdb;
				$wpdb->delete( Install::table( 'group_members' ), array( 'user_id' => (int) $user_id ) );
			}
		);
	}

	public static function register_routes() {
		$admin = fn() => is_user_logged_in() && Helpers::can_manage();
		$id    = '(?P<id>\d+)';
		$routes = array(
			'/groups'                                 => array( array( 'GET', 'rest_list' ), array( 'POST', 'rest_create' ) ),
			"/groups/{$id}"                           => array( array( 'PUT', 'rest_update' ), array( 'DELETE', 'rest_delete' ) ),
			"/groups/{$id}/members"                   => array( array( 'GET', 'rest_members' ), array( 'POST', 'rest_add' ) ),
			"/groups/{$id}/members/(?P<user>\\d+)"    => array( array( 'DELETE', 'rest_remove' ) ),
			"/members/{$id}/groups"                   => array( array( 'GET', 'rest_member_groups' ), array( 'PUT', 'rest_set_member_groups' ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				array_map( fn( $h ) => array( 'methods' => $h[0], 'callback' => array( __CLASS__, $h[1] ), 'permission_callback' => $admin ), $handlers )
			);
		}
	}

	/* ---------- Groups ---------- */

	/** Every access group, keyed by id: id, name, roles (WordPress role keys). */
	public static function groups() {
		global $wpdb;
		if ( null === self::$groups ) {
			self::$groups = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', Install::table( 'groups' ) ), ARRAY_A ) as $g ) {
				self::$groups[ (int) $g['id'] ] = array(
					'id'    => (int) $g['id'],
					'name'  => $g['name'],
					'roles' => array_values( array_filter( (array) json_decode( (string) $g['roles'], true ) ) ),
				);
			}
		}
		return self::$groups;
	}

	private static function forget( $user_id = null ) {
		self::$groups = null;
		if ( null === $user_id ) {
			self::$mine = array();
		} else {
			unset( self::$mine[ $user_id ] );
		}
	}

	/** Group ids an admin added this member to by hand. */
	public static function manual_groups( $user_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT group_id FROM %i WHERE user_id = %d', Install::table( 'group_members' ), $user_id ) ) );
	}

	/** Every group the member is in: added by hand, through a role, or as a filter decides. */
	public static function member_groups( $user_id ) {
		$user_id = (int) $user_id;
		if ( isset( self::$mine[ $user_id ] ) ) {
			return self::$mine[ $user_id ];
		}
		$user   = get_userdata( $user_id );
		$roles  = $user ? (array) $user->roles : array();
		$manual = array_flip( self::manual_groups( $user_id ) );
		$in     = array();
		foreach ( self::groups() as $gid => $g ) {
			$member = isset( $manual[ $gid ] ) || (bool) array_intersect( $g['roles'], $roles );
			/**
			 * Whether a member is in an access group. Lets a membership or shop plugin
			 * grant access its own way, e.g. "has an active VIP membership".
			 *
			 * @param bool $member   In the group by hand or by role.
			 * @param int  $group_id The group.
			 * @param int  $user_id  The member.
			 * @param array $group   id, name and linked roles.
			 */
			if ( apply_filters( 'membuild_community/in_access_group', $member, $gid, $user_id, $g ) ) {
				$in[] = $gid;
			}
		}
		return self::$mine[ $user_id ] = $in;
	}

	/* ---------- Courses ---------- */

	/** The groups a course is open to (only ones that still exist). */
	public static function course_groups( $course ) {
		$ids = array_map( 'intval', (array) json_decode( (string) ( $course['access_groups'] ?? '' ), true ) );
		return array_values( array_intersect( $ids, array_keys( self::groups() ) ) );
	}

	/**
	 * Why the member can't open this course, or null when they can.
	 * array( 'reason' => 'level', 'level' => 3 ) or array( 'reason' => 'groups', 'groups' => array( names ) ).
	 */
	public static function lock( $course, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( user_can( $user_id, 'manage_options' ) || Settings::is_staff( $user_id ) ) {
			return null;
		}
		$mode = $course['access'] ?? 'everyone';
		if ( 'level' === $mode ) {
			$need = max( 2, (int) $course['unlock_level'] );
			return Points::level_of( $user_id ) >= $need ? null : array( 'reason' => 'level', 'level' => $need );
		}
		if ( 'groups' === $mode ) {
			$open = self::course_groups( $course );
			if ( array_intersect( $open, self::member_groups( $user_id ) ) ) {
				return null;
			}
			$groups = self::groups();
			return array( 'reason' => 'groups', 'groups' => array_values( array_map( fn( $id ) => $groups[ $id ]['name'], $open ) ) );
		}
		return null;
	}

	/** Courses that are locked for this member and set to hide aren't shown at all. */
	public static function hidden( $course, $user_id = null ) {
		return ! empty( $course['hide_locked'] ) && null !== self::lock( $course, $user_id );
	}

	/** Reads the access fields from a course update; null when nothing about access changed. */
	public static function course_update( array $params, $course ) {
		$data = array();
		if ( isset( $params['access'] ) && in_array( $params['access'], self::MODES, true ) ) {
			$data['access'] = $params['access'];
		}
		if ( isset( $params['access_groups'] ) && is_array( $params['access_groups'] ) ) {
			$ids                   = array_values( array_intersect( array_map( 'intval', $params['access_groups'] ), array_keys( self::groups() ) ) );
			$data['access_groups'] = wp_json_encode( $ids );
		}
		if ( isset( $params['hide_locked'] ) ) {
			$data['hide_locked'] = $params['hide_locked'] ? 1 : 0;
		}
		// "How to get access": a button on the locked course, e.g. "Join the VIP plan" → a checkout page.
		if ( array_key_exists( 'access_cta_label', $params ) ) {
			$data['access_cta_label'] = mb_substr( sanitize_text_field( (string) $params['access_cta_label'] ), 0, 60 ) ?: null;
		}
		if ( array_key_exists( 'access_cta_url', $params ) ) {
			$data['access_cta_url'] = esc_url_raw( (string) $params['access_cta_url'], array( 'http', 'https' ) ) ?: null;
		}
		// The old level setting on its own still works: picking a level means "from this level up".
		if ( isset( $params['unlock_level'] ) && ! isset( $data['access'] ) ) {
			$data['access'] = (int) $params['unlock_level'] > 1 ? 'level' : ( 'level' === ( $course['access'] ?? '' ) ? 'everyone' : ( $course['access'] ?? 'everyone' ) );
		}
		return $data;
	}

	/* ---------- REST ---------- */

	private static function format_group( $g ) {
		global $wpdb;
		$names = wp_roles()->get_names();
		return $g + array(
			'role_names'   => array_values( array_map( fn( $r ) => translate_user_role( $names[ $r ] ?? $r ), $g['roles'] ) ),
			'member_count' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE group_id = %d', Install::table( 'group_members' ), $g['id'] ) ),
			'course_count' => count( array_filter( self::all_courses(), fn( $c ) => 'groups' === $c['access'] && in_array( $g['id'], self::course_groups( $c ), true ) ) ),
		);
	}

	private static function all_courses() {
		global $wpdb;
		static $rows = null;
		return $rows ??= $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, access, access_groups FROM %i', Install::table( 'courses' ) ), ARRAY_A );
	}

	/** The site's roles, for linking: key and translated name. */
	public static function roles() {
		$out = array();
		foreach ( wp_roles()->get_names() as $key => $name ) {
			$out[] = array( 'key' => $key, 'name' => translate_user_role( $name ) );
		}
		return $out;
	}

	private static function clean_roles( $roles ) {
		return array_values( array_intersect( array_map( 'sanitize_key', (array) $roles ), array_keys( wp_roles()->get_names() ) ) );
	}

	public static function rest_list() {
		return array(
			'items' => array_values( array_map( array( __CLASS__, 'format_group' ), self::groups() ) ),
			'roles' => self::roles(),
		);
	}

	public static function rest_create( \WP_REST_Request $req ) {
		global $wpdb;
		$name = mb_substr( sanitize_text_field( (string) $req['name'] ), 0, 80 );
		if ( '' === $name ) {
			return new \WP_Error( 'mbcom_group_name', __( 'Give the group a name.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->insert( Install::table( 'groups' ), array( 'name' => $name, 'roles' => wp_json_encode( self::clean_roles( $req['roles'] ) ), 'created_at' => Helpers::now() ) );
		$id = (int) $wpdb->insert_id;
		self::forget();
		$res = rest_ensure_response( self::format_group( self::groups()[ $id ] ) );
		$res->set_status( 201 );
		return $res;
	}

	public static function rest_update( \WP_REST_Request $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		if ( ! isset( self::groups()[ $id ] ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Group not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$data = array();
		if ( isset( $req['name'] ) ) {
			$name = mb_substr( sanitize_text_field( (string) $req['name'] ), 0, 80 );
			if ( '' === $name ) {
				return new \WP_Error( 'mbcom_group_name', __( 'Give the group a name.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			$data['name'] = $name;
		}
		if ( isset( $req['roles'] ) ) {
			$data['roles'] = wp_json_encode( self::clean_roles( $req['roles'] ) );
		}
		if ( $data ) {
			$wpdb->update( Install::table( 'groups' ), $data, array( 'id' => $id ) );
		}
		self::forget();
		return self::format_group( self::groups()[ $id ] );
	}

	/** Deleting a group removes it from courses too; a course left with no groups opens only for admins and moderators. */
	public static function rest_delete( \WP_REST_Request $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$wpdb->delete( Install::table( 'group_members' ), array( 'group_id' => $id ) );
		$wpdb->delete( Install::table( 'groups' ), array( 'id' => $id ) );
		self::forget();
		return array( 'deleted' => true );
	}

	public static function rest_members( \WP_REST_Request $req ) {
		global $wpdb;
		$ids = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE group_id = %d ORDER BY added_at DESC LIMIT 500', Install::table( 'group_members' ), (int) $req['id'] ) )
		);
		return array( 'items' => array_values( Helpers::users( $ids ) ) );
	}

	public static function rest_add( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! isset( self::groups()[ $id ] ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Group not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$added = 0;
		foreach ( array_slice( array_map( 'intval', (array) $req['user_ids'] ), 0, 200 ) as $user_id ) {
			$added += self::add( $id, $user_id ) ? 1 : 0;
		}
		return array( 'added' => $added ) + self::rest_members( $req );
	}

	public static function rest_remove( \WP_REST_Request $req ) {
		self::remove( (int) $req['id'], (int) $req['user'] );
		return self::rest_members( $req );
	}

	public static function add( $group_id, $user_id ) {
		global $wpdb;
		if ( ! get_userdata( $user_id ) || ! isset( self::groups()[ $group_id ] ) ) {
			return false;
		}
		$ok = (bool) $wpdb->query(
			$wpdb->prepare( 'INSERT IGNORE INTO %i (group_id, user_id, added_by, added_at) VALUES (%d, %d, %d, %s)', Install::table( 'group_members' ), $group_id, $user_id, get_current_user_id(), Helpers::now() )
		);
		self::forget( $user_id );
		if ( $ok ) {
			/**
			 * A member was added to an access group by hand (role-linked membership doesn't fire this).
			 *
			 * @param int $group_id The group.
			 * @param int $user_id  The member.
			 */
			do_action( 'membuild_community/access_group_added', $group_id, $user_id );
		}
		return $ok;
	}

	public static function remove( $group_id, $user_id ) {
		global $wpdb;
		$ok = (bool) $wpdb->delete( Install::table( 'group_members' ), array( 'group_id' => $group_id, 'user_id' => $user_id ) );
		self::forget( $user_id );
		if ( $ok ) {
			/**
			 * A member was removed from an access group they'd been added to by hand.
			 *
			 * @param int $group_id The group.
			 * @param int $user_id  The member.
			 */
			do_action( 'membuild_community/access_group_removed', $group_id, $user_id );
		}
		return $ok;
	}

	/** For the member's profile: every group, whether they're in it by hand, and whether a role puts them in it anyway. */
	public static function rest_member_groups( \WP_REST_Request $req ) {
		$user = get_userdata( (int) $req['id'] );
		if ( ! $user ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Member not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$manual = array_flip( self::manual_groups( $user->ID ) );
		$roles  = (array) $user->roles;
		$names  = wp_roles()->get_names();
		return array(
			'items' => array_values(
				array_map(
					function ( $g ) use ( $manual, $roles, $names ) {
						$via = array_values( array_intersect( $g['roles'], $roles ) );
						return array(
							'id'      => $g['id'],
							'name'    => $g['name'],
							'manual'  => isset( $manual[ $g['id'] ] ),
							'via_role' => $via ? translate_user_role( $names[ $via[0] ] ?? $via[0] ) : null,
						);
					},
					self::groups()
				)
			),
		);
	}

	public static function rest_set_member_groups( \WP_REST_Request $req ) {
		$user_id = (int) $req['id'];
		if ( ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Member not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$want = array_intersect( array_map( 'intval', (array) $req['group_ids'] ), array_keys( self::groups() ) );
		$have = self::manual_groups( $user_id );
		foreach ( array_diff( $want, $have ) as $gid ) {
			self::add( $gid, $user_id );
		}
		foreach ( array_diff( $have, $want ) as $gid ) {
			self::remove( $gid, $user_id );
		}
		return self::rest_member_groups( $req );
	}
}
