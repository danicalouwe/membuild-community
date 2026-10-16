<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Members: the directory, profiles and editing your own profile.
 *
 *   GET /members           ?search=&sort=active|newest|level&page=   the directory
 *   GET /members/{id}      one member's profile
 *   PUT /me/profile        { name, bio, avatar: { id } | null }
 *
 * A member's posts come from GET /feed?author={id}.
 * An uploaded profile photo replaces Gravatar everywhere WordPress shows avatars.
 */
class Rest_Members {

	const PAGE_SIZE = 24;
	const BIO_MAX   = 160;

	public static function register_routes() {
		$auth = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/members',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'directory' ),
				'permission_callback' => $auth,
				'args'                => array(
					'sort' => array( 'enum' => array( 'active', 'newest', 'level' ), 'default' => 'active' ),
					'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/members/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'profile' ), 'permission_callback' => $auth ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/me/profile', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'update_profile' ), 'permission_callback' => $auth ) );
	}

	/** Use the member's uploaded photo wherever WordPress asks for an avatar. */
	public static function register_hooks() {
		add_filter(
			'pre_get_avatar_data',
			function ( $args, $id_or_email ) {
				$user_id = self::user_id_of( $id_or_email );
				$url     = $user_id ? get_user_meta( $user_id, 'mbcom_avatar_url', true ) : '';
				if ( $url ) {
					$args['url']          = $url;
					$args['found_avatar'] = true;
				}
				return $args;
			},
			10,
			2
		);
	}

	private static function user_id_of( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( $id_or_email instanceof \WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof \WP_Comment ) {
			return (int) $id_or_email->user_id;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}
		return 0;
	}

	/* ---------- Directory ---------- */

	public static function directory( \WP_REST_Request $req ) {
		global $wpdb;
		$page   = (int) $req['page'];
		$search = sanitize_text_field( (string) $req['search'] );

		$args   = array( $wpdb->users, Install::table( 'member_stats' ) );
		$filter = Helpers::site_members_sql();
		if ( '' !== $search ) {
			$filter .= ' AND u.display_name LIKE %s'; // login names stay private
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		// "Active" = most recently in the community, then most points; members who never opened it come last.
		$order = array(
			'active' => 's.last_active DESC, points DESC, u.ID DESC',
			'newest' => 'u.user_registered DESC, u.ID DESC',
			'level'  => 'points DESC, u.ID ASC',
		)[ $req['sort'] ];

		$args[] = self::PAGE_SIZE + 1;
		$args[] = ( $page - 1 ) * self::PAGE_SIZE;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared below; only $filter (fixed literal) and $order (fixed whitelist) are concatenated.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.user_registered, COALESCE(s.points, 0) AS points
				FROM %i u LEFT JOIN %i s ON s.user_id = u.ID
				WHERE 1=1" . $filter . " ORDER BY {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $filter is the prepared site_members_sql() fragment plus, when searching, the fixed literal ' AND u.display_name LIKE %s'; $order comes from the fixed ORDER BY whitelist above (ORDER BY cannot take a placeholder).
				$args
			),
			ARRAY_A
		);
		$more  = count( $rows ) > self::PAGE_SIZE;
		$rows  = Blocks::filter( array_slice( $rows, 0, self::PAGE_SIZE ), 'ID' );
		$users = Helpers::users( wp_list_pluck( $rows, 'ID' ) );

		return array(
			'items'     => array_values(
				array_filter(
					array_map(
						fn( $r ) => isset( $users[ (int) $r['ID'] ] ) ? $users[ (int) $r['ID'] ] + array(
							'bio'       => (string) get_user_meta( (int) $r['ID'], 'mbcom_bio', true ),
							'points'    => (int) $r['points'],
							'joined_at' => Helpers::iso( $r['user_registered'] ),
							'role'      => self::role( (int) $r['ID'] ),
						) : null,
						$rows
					)
				)
			),
			'next_page' => $more ? $page + 1 : null,
			'total'     => Helpers::member_total(),
		);
	}

	/* ---------- Profile ---------- */

	public static function profile( \WP_REST_Request $req ) {
		global $wpdb;
		$id   = (int) $req['id'];
		$user = get_userdata( $id );
		// Someone I blocked stays reachable, so I can unblock them; someone who blocked me isn't.
		if ( ! $user || ( Blocks::hides( $id ) && ! Blocks::i_blocked( $id ) ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'This member was not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$me     = get_current_user_id();
		$stats  = Points::stats_for( array( $id ) )[ $id ] ?? array( 'points' => 0, 'level' => 1 );
		$levels = Points::thresholds();
		$level  = $stats['level'];
		$next   = $levels[ $level ] ?? null; // points needed for the next level, if any
		$floor  = $levels[ $level - 1 ] ?? 0;

		return Helpers::user( $user, $level ) + array(
			'bio'          => (string) get_user_meta( $id, 'mbcom_bio', true ),
			'avatar_large' => get_avatar_url( $id, array( 'size' => 320 ) ),
			'has_photo'    => (bool) get_user_meta( $id, 'mbcom_avatar_url', true ),
			'points'       => $stats['points'],
			'next_level'   => $next ? $level + 1 : null,
			'to_next'      => $next ? max( 0, $next - $stats['points'] ) : 0,
			'level_progress' => $next ? (int) round( 100 * ( $stats['points'] - $floor ) / max( 1, $next - $floor ) ) : 100,
			'joined_at'    => Helpers::iso( $user->user_registered ),
			'role'         => self::role( $id ),
			'posts_count'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND status = 'published' AND course_id = 0", Install::table( 'posts' ), $id ) ),
			'is_me'        => $id === $me,
			'can_message'  => $id !== $me && Settings::can_message( $me, array( $id ) ) && ! Blocks::hides( $id ),
			'blocked'      => Blocks::i_blocked( $id ),
			'can_block'    => $id !== $me && ! Settings::is_staff( $id ) && ! Settings::is_staff( $me ),
			// Staff see and change mutes and bans from the profile.
			'can_restrict' => Helpers::can_moderate() && $id !== $me && ! Settings::is_staff( $id ),
			// Staff see the details; the member only sees that they're muted and until when.
			'restriction'  => Helpers::can_moderate() ? Moderation::restriction( $id ) : ( $id === $me ? Moderation::my_restriction() : null ),
		);
	}

	/** "admin", "moderator" or null, for the badge next to a name. */
	private static function role( $user_id ) {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return 'admin';
		}
		return Settings::is_staff( $user_id ) ? 'moderator' : null;
	}

	public static function update_profile( \WP_REST_Request $req ) {
		global $wpdb;
		$me      = get_current_user_id();
		$in      = $req->get_json_params() ?: array();
		$blocked = Moderation::guard( $me );
		if ( $blocked ) {
			return $blocked;
		}

		if ( array_key_exists( 'name', $in ) ) {
			$name = mb_substr( trim( sanitize_text_field( (string) $in['name'] ) ), 0, 50 );
			if ( '' === $name ) {
				return new \WP_Error( 'mbcom_invalid', __( 'Your name can’t be empty.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			wp_update_user( array( 'ID' => $me, 'display_name' => $name ) );
		}
		if ( array_key_exists( 'bio', $in ) ) {
			update_user_meta( $me, 'mbcom_bio', Helpers::clean_text( $in['bio'], self::BIO_MAX ) );
		}
		if ( array_key_exists( 'avatar', $in ) ) {
			// The old photo is deleted, not left behind on the server.
			$old = (string) get_user_meta( $me, 'mbcom_avatar_url', true );
			if ( $old ) {
				$old_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE url = %s AND user_id = %d', Install::table( 'media' ), $old, $me ) );
			}
			if ( null === $in['avatar'] ) {
				delete_user_meta( $me, 'mbcom_avatar_url' ); // back to Gravatar
			} else {
				$media = Install::table( 'media' );
				$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, url FROM %i WHERE id = %d AND user_id = %d AND kind = 'image' AND post_id IS NULL", $media, (int) ( $in['avatar']['id'] ?? 0 ), $me ), ARRAY_A );
				if ( ! $row ) {
					return new \WP_Error( 'mbcom_bad_media', __( 'Upload the photo again, please.', 'membuild-community' ), array( 'status' => 400 ) );
				}
				$wpdb->update( $media, array( 'post_id' => 0 ), array( 'id' => $row['id'] ) ); // claimed
				update_user_meta( $me, 'mbcom_avatar_url', esc_url_raw( $row['url'] ) );
			}
			if ( ! empty( $old_id ) ) {
				Media::delete( $old_id );
			}
		}

		clean_user_cache( $me );
		$req->set_param( 'id', $me );
		return self::profile( $req );
	}
}
