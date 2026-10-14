<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 *   GET /me/stats                         level, points, streak, rank (also records today's visit)
 *   GET /leaderboard?period=7|30|all      top members, my rank, members per level
 */
class Rest_Gamification {

	/** How long shared leaderboard numbers are reused before they're worked out again. */
	const STANDINGS_TTL = 10 * MINUTE_IN_SECONDS;

	/** What earned points, as shown on profiles, translated. */
	public static function reason_labels() {
		return array(
			'reaction_received' => __( 'Someone reacted to your post or comment', 'membuild-community' ),
			'post'              => __( 'You posted', 'membuild-community' ),
			'comment'           => __( 'You commented', 'membuild-community' ),
			'story'             => __( 'You shared a story', 'membuild-community' ),
			'lesson'            => __( 'You finished a lesson', 'membuild-community' ),
			'course'            => __( 'You finished a course', 'membuild-community' ),
			'daily_visit'       => __( 'Daily visit', 'membuild-community' ),
			'streak_7'          => __( '7-day streak bonus', 'membuild-community' ),
			'streak_30'         => __( '30-day streak bonus', 'membuild-community' ),
		);
	}

	public static function register_routes() {
		$member = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/me/stats',
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => $member )
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/leaderboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'leaderboard' ),
				'permission_callback' => Rest_Feed::feature( 'leaderboard' ),
				'args'                => array( 'period' => array( 'enum' => array( '7', '30', 'all' ), 'default' => '30' ) ),
			)
		);
	}

	public static function me() {
		global $wpdb;
		$me = get_current_user_id();
		Points::check_in( $me );

		$row        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', Install::table( 'member_stats' ), $me ), ARRAY_A );
		$points     = (int) ( $row['points'] ?? 0 );
		$level      = (int) ( $row['level'] ?? 1 );
		$thresholds = Points::thresholds();
		$floor      = $thresholds[ $level - 1 ] ?? 0;
		$next       = $thresholds[ $level ] ?? null;

		$recent = $wpdb->get_results(
			$wpdb->prepare( 'SELECT points, reason, created_at FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 12', Install::table( 'points' ), $me ),
			ARRAY_A
		);

		return array(
			'points'          => $points,
			'level'           => $level,
			'level_floor'     => $floor,
			'next_level'      => $next ? $level + 1 : null,
			'next_level_at'   => $next,
			'to_next'         => $next ? max( 0, $next - $points ) : 0,
			'level_progress'  => $next ? (int) floor( 100 * ( $points - $floor ) / max( 1, $next - $floor ) ) : 100,
			'streak'          => Points::live_streak( $row ),
			'best_streak'     => (int) ( $row['best_streak'] ?? 0 ),
			'rank_30'         => self::rank( $me, 30 ),
			'thresholds'      => $thresholds,
			'rules'           => array_map(
				fn( $reason, $rule ) => array( 'reason' => $reason, 'label' => self::reason_labels()[ $reason ] ?? $reason, 'points' => $rule[0], 'daily_cap' => $rule[1] ),
				array_keys( Points::rules() ),
				Points::rules()
			),
			'recent'          => array_map(
				fn( $r ) => array( 'points' => (int) $r['points'], 'label' => self::reason_labels()[ $r['reason'] ] ?? $r['reason'], 'at' => Helpers::iso( $r['created_at'] ) ),
				$recent
			),
			'unlocks'         => self::course_unlocks(),
		);
	}

	public static function leaderboard( \WP_REST_Request $req ) {
		global $wpdb;
		$period = (string) $req['period'];
		$me     = get_current_user_id();

		if ( 'all' === $period ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, points FROM %i WHERE points > 0 ORDER BY points DESC, user_id ASC LIMIT 50', Install::table( 'member_stats' ) ), ARRAY_A );
		} else {
			$rows = array();
			foreach ( self::standings( (int) $period )['top'] as $user_id => $points ) {
				$rows[] = array( 'user_id' => $user_id, 'points' => $points );
			}
		}

		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		$items = array();
		foreach ( $rows as $i => $r ) {
			if ( isset( $users[ (int) $r['user_id'] ] ) ) {
				$items[] = array( 'rank' => $i + 1, 'user' => $users[ (int) $r['user_id'] ], 'points' => (int) $r['points'] );
			}
		}

		return array(
			'period' => $period,
			'items'  => $items,
			'me'     => array( 'rank' => self::rank( $me, 'all' === $period ? null : (int) $period ), 'points' => self::period_points( $me, 'all' === $period ? null : (int) $period ) ),
			'levels' => self::distribution(),
		);
	}

	/* ---------- Helpers ---------- */

	/**
	 * The standings over the last $days: the top 50 (user ID => points) and how
	 * many members have each total (points => members), enough to rank anyone.
	 * The same for everyone, so it's worked out once every few minutes and shared:
	 * grouping the whole points ledger on every visit is the costliest query on
	 * small hosts. Only the counts are kept, not every member's total, so a big
	 * community's standings stay a few kilobytes.
	 */
	private static function standings( $days ) {
		global $wpdb;
		$key       = 'mbcom_standings_' . $days;
		$standings = get_transient( $key );
		if ( ! is_array( $standings ) || ! isset( $standings['counts'] ) ) {
			$totals    = $wpdb->prepare( 'SELECT user_id, SUM(points) AS points FROM %i WHERE created_at >= UTC_TIMESTAMP() - INTERVAL %d DAY GROUP BY user_id HAVING points > 0', Install::table( 'points' ), $days );
			$standings = array(
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $totals is prepared above
				'top'    => array_map( 'intval', wp_list_pluck( $wpdb->get_results( "SELECT user_id, points FROM ({$totals}) t ORDER BY points DESC, user_id ASC LIMIT 50", OBJECT_K ), 'points' ) ),
				'counts' => array_map( 'intval', wp_list_pluck( $wpdb->get_results( "SELECT points, COUNT(*) AS members FROM ({$totals}) t GROUP BY points", OBJECT_K ), 'members' ) ),
				// phpcs:enable
			);
			set_transient( $key, $standings, self::STANDINGS_TTL );
		}
		return $standings;
	}

	private static function period_points( $user_id, $days ) {
		global $wpdb;
		if ( null === $days ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT points FROM %i WHERE user_id = %d', Install::table( 'member_stats' ), $user_id ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(points) FROM %i WHERE user_id = %d AND created_at >= UTC_TIMESTAMP() - INTERVAL %d DAY', Install::table( 'points' ), $user_id, $days ) );
	}

	/** 1-based rank for a period (null = all time), or null with no points yet. */
	private static function rank( $user_id, $days ) {
		global $wpdb;
		$mine = self::period_points( $user_id, $days );
		if ( $mine <= 0 ) {
			return null;
		}
		if ( null === $days ) {
			$ahead = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE points > %d', Install::table( 'member_stats' ), $mine ) );
		} else {
			$ahead = array_sum( array_filter( self::standings( $days )['counts'], fn( $points ) => $points > $mine, ARRAY_FILTER_USE_KEY ) );
		}
		return $ahead + 1;
	}

	/** Share of members at each level, for the leaderboard's level list (cached like the standings). */
	private static function distribution() {
		global $wpdb;
		$cached = get_transient( 'mbcom_level_distribution' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$total  = max( 1, Helpers::member_total() );
		$titles = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT unlock_level, title FROM %i WHERE status = 'published' AND access = 'level' ORDER BY sort_order", Install::table( 'courses' ) ), ARRAY_A ) as $c ) {
			$titles[ (int) $c['unlock_level'] ][] = $c['title'];
		}
		$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT level, COUNT(*) n FROM %i GROUP BY level', Install::table( 'member_stats' ) ), OBJECT_K );
		$ranked = array_sum( array_map( fn( $c ) => (int) $c->n, $counts ) );
		$out    = array();
		foreach ( Points::thresholds() as $i => $min ) {
			$level = $i + 1;
			$n     = (int) ( $counts[ $level ]->n ?? 0 ) + ( 1 === $level ? max( 0, $total - $ranked ) : 0 ); // members with no points yet are Level 1
			$out[] = array(
				'level'   => $level,
				'min'     => $min,
				'members' => $n,
				'percent' => (int) round( 100 * $n / $total ),
				'courses' => $titles[ $level ] ?? array(),
			);
		}
		set_transient( 'mbcom_level_distribution', $out, self::STANDINGS_TTL );
		return $out;
	}

	/** Published courses that unlock at a level, so a level-up can say what opened. */
	private static function course_unlocks() {
		global $wpdb;
		return array_map(
			fn( $r ) => array( 'level' => (int) $r['unlock_level'], 'title' => $r['title'], 'id' => (int) $r['id'] ),
			$wpdb->get_results( $wpdb->prepare( "SELECT id, title, unlock_level FROM %i WHERE status = 'published' AND access = 'level' AND unlock_level > 1 ORDER BY unlock_level", Install::table( 'courses' ) ), ARRAY_A )
		);
	}
}
