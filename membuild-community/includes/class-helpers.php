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
			// When they were last really here, if the viewer may see it (the green dot, "Active now").
			'active_at' => Online::active_at( $user->ID ),
		);
	}

	/** Who can create and edit courses. */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/** Admins, editors and the moderators picked in settings. */
	/** 'instructor' for admins, 'moderator' for moderators, null for members. Remembered per request. */
	public static function staff_role( $user_id ) {
		static $roles = array();
		if ( ! array_key_exists( $user_id, $roles ) ) {
			$roles[ $user_id ] = user_can( $user_id, 'manage_options' ) ? 'instructor' : ( Settings::is_staff( $user_id ) ? 'moderator' : null );
		}
		return $roles[ $user_id ];
	}

	public static function can_moderate() {
		return is_user_logged_in() && Settings::is_staff( get_current_user_id() );
	}

	/**
	 * A single emoji (with any skin tone, gender or flag joins), for reacting to a
	 * message with any emoji, like Messenger's "+". Null for anything else: no
	 * letters, digits, punctuation, spaces or markup, and at most 8 characters.
	 */
	public static function emoji( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || mb_strlen( $text ) > 8 || strlen( $text ) > 32 || preg_match( '/[\p{L}\p{N}\p{P}\p{Z}\p{Cc}<>=+`^|~$]/u', $text ) ) {
			return null;
		}
		// At least one real pictograph (emoji live above U+2190), not just joiners or variation marks.
		return preg_match( '/[\x{2190}-\x{2BFF}\x{1F000}-\x{1FAFF}]/u', $text ) ? $text : null;
	}

	/**
	 * A per-member rate limit: true while under $max actions per $window seconds.
	 * Admins are never limited.
	 *
	 * The count goes up in one database statement (insert, or add one), so many
	 * requests at the same moment can't all read the same count and slip past
	 * the limit, as a read-then-save would let them. Each window has its own
	 * row, named with the time it ends; cleanup_rate_limits() clears old ones.
	 */
	public static function rate_ok( $action, $max, $window ) {
		global $wpdb;
		if ( self::can_manage() ) {
			return true;
		}
		$window = max( 1, (int) $window );
		$ends   = ( (int) floor( time() / $window ) + 1 ) * $window;
		$name   = 'mbcom_rl_' . $action . '_' . get_current_user_id() . '_' . $ends;
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $wpdb->options, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $count <= (int) $max;
	}

	/** Hourly: rate-limit counts whose window has ended. */
	public static function cleanup_rate_limits() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_name, '_', -1) AS UNSIGNED) < %d LIMIT 5000", $wpdb->options, $wpdb->esc_like( 'mbcom_rl_' ) . '%', time() ) );
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
