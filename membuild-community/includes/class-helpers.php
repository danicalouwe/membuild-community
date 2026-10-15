<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Helpers {

	const REACTIONS = array( 'like', 'love', 'care', 'haha', 'wow', 'sad', 'angry' );

	/** Colored backgrounds for short text posts (the app holds the actual gradients). */
	const BACKGROUNDS = array( 'sunset', 'ocean', 'forest', 'grape', 'peach', 'night', 'candy', 'lime' );

	/** "is feeling …" options (the app holds the labels and emoji). */
	const FEELINGS = array( 'happy', 'blessed', 'loved', 'excited', 'grateful', 'motivated', 'proud', 'relaxed', 'inspired', 'determined', 'curious', 'tired', 'sad', 'celebrating' );

	public static function now() {
		return current_time( 'mysql', true );
	}

	/** ISO 8601 in UTC, so the app can format relative times. */
	public static function iso( $mysql_utc ) {
		return gmdate( 'c', strtotime( $mysql_utc . ' UTC' ) );
	}

	/**
	 * Public author summaries for a set of user IDs, fetched in one query.
	 *
	 * @param int[] $ids
	 * @return array<int, array>
	 */
	public static function users( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out    = array();
		$levels = Points::stats_for( $ids );
		foreach ( get_users( array( 'include' => $ids ) ) as $user ) {
			$out[ $user->ID ] = self::user( $user, $levels[ $user->ID ]['level'] ?? 1 );
		}
		return $out;
	}

	public static function user( \WP_User $user, $level = null ) {
		return array(
			'id'     => (int) $user->ID,
			'name'   => $user->display_name,
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'level'  => $level ?? Points::level_of( $user->ID ),
		);
	}

	/** Who can create and edit courses. */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/** Admins, editors and the moderators picked in settings. */
	public static function can_moderate() {
		return is_user_logged_in() && Settings::is_staff( get_current_user_id() );
	}

	/**
	 * A simple per-member rate limit: true while under $max actions per $window seconds.
	 * Admins are never limited.
	 */
	public static function rate_ok( $action, $max, $window ) {
		if ( self::can_manage() ) {
			return true;
		}
		$key   = 'mbcom_rl_' . $action . '_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * The standard "slow down" answer when rate_ok() says no.
	 *
	 * @param string $action Same key as rate_ok().
	 * @param int    $max    Allowed per window.
	 * @param int    $window Seconds.
	 * @return \WP_Error|null Null while the member is under the limit.
	 */
	public static function throttle( $action, $max, $window ) {
		return self::rate_ok( $action, $max, $window ) ? null : new \WP_Error( 'mbcom_slow_down', __( 'You’re doing that a lot. Please wait a few minutes and try again.', 'membuild-community' ), array( 'status' => 429 ) );
	}

	/** The request's HTTP method, upper case ("GET", "POST", "HEAD"…). */
	public static function method() {
		return strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
	}

	/**
	 * SQL limiting the users table, aliased "u", to this site's members. On a
	 * multisite network wp_users holds everyone on every site; a member of this
	 * site has capabilities here. A complete, prepared fragment ('' on a single site).
	 */
	public static function site_members_sql() {
		global $wpdb;
		if ( ! is_multisite() ) {
			return '';
		}
		return $wpdb->prepare( ' AND EXISTS (SELECT 1 FROM %i um WHERE um.user_id = u.ID AND um.meta_key = %s)', $wpdb->usermeta, $wpdb->get_blog_prefix() . 'capabilities' );
	}

	/**
	 * Keeps a page out of every page cache: the headers for browsers and CDNs,
	 * and DONOTCACHEPAGE, which WP Super Cache, W3 Total Cache, WP Rocket and
	 * LiteSpeed Cache honour even when they ignore headers. Login forms carry a
	 * nonce and the app is personal, so a cached copy would break both.
	 */
	public static function no_page_cache() {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the shared constant cache plugins read
		}
	}

	/** How many members the site has, cached for an hour: count_users() is slow on big sites. */
	public static function member_total() {
		$total = get_transient( 'mbcom_member_total' );
		if ( false === $total ) {
			$total = (int) count_users()['total_users'];
			set_transient( 'mbcom_member_total', $total, HOUR_IN_SECONDS );
		}
		return (int) $total;
	}

	/** Plain text only: tags are stripped, line breaks kept. */
	public static function clean_text( $text, $max = 5000 ) {
		$text = sanitize_textarea_field( (string) $text );
		return mb_substr( trim( $text ), 0, $max );
	}
}
