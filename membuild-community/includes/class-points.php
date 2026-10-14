<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Points, levels and streaks.
 *
 * Every award is a row in a ledger with a unique key per (member, reason,
 * object, actor), so the same like or lesson can never count twice, and
 * undoing it (un-like, un-complete, delete) removes exactly that row.
 * Totals and levels live in member_stats and can be rebuilt from the ledger.
 */
class Points {

	/** Points per action and how many times a day they count (0 = no cap). */
	const RULES = array(
		'reaction_received' => array( 1, 25 ), // capped, so throwaway accounts can't farm levels
		'post'              => array( 2, 5 ),
		'comment'           => array( 1, 10 ),
		'story'             => array( 1, 3 ),
		'lesson'            => array( 5, 0 ),
		'course'            => array( 20, 0 ),
		'daily_visit'       => array( 1, 0 ),
		'streak_7'          => array( 10, 0 ),
		'streak_30'         => array( 50, 0 ),
	);

	/** Points needed for levels 1–9: steeper each level, Skool-style. */
	const LEVELS = array( 0, 5, 20, 65, 155, 515, 2015, 8015, 33015 );

	public static function rules() {
		/**
		 * Points per action and their daily caps, after the community's own settings.
		 *
		 * @param array $rules reason => array( points, daily cap ), 0 = no cap.
		 */
		return apply_filters( 'membuild_community/points_rules', Settings::point_rules( self::RULES ) );
	}

	public static function thresholds() {
		/**
		 * Points needed for each level, starting with level 1.
		 *
		 * @param int[] $thresholds Nine ascending point totals.
		 */
		return apply_filters( 'membuild_community/level_thresholds', self::LEVELS );
	}

	public static function level_for( $points ) {
		$level = 1;
		foreach ( self::thresholds() as $i => $min ) {
			if ( $points >= $min ) {
				$level = $i + 1;
			}
		}
		return $level;
	}

	/* ---------- Wiring ---------- */

	public static function register_hooks() {
		add_action( 'membuild_community/post_created', fn( $post_id, $user_id ) => self::award( $user_id, 'post', 'post', $post_id ), 10, 2 );
		add_action( 'membuild_community/post_deleted', array( __CLASS__, 'on_post_deleted' ), 10, 2 );
		add_action( 'membuild_community/comment_created', fn( $comment_id, $post_id, $user_id ) => self::award( $user_id, 'comment', 'comment', $comment_id ), 10, 3 );
		add_action( 'membuild_community/comment_deleted', array( __CLASS__, 'on_comment_deleted' ), 10, 2 );
		add_action( 'membuild_community/reaction_changed', array( __CLASS__, 'on_reaction' ), 10, 4 );
		add_action( 'membuild_community/story_created', fn( $story_id, $user_id ) => self::award( $user_id, 'story', 'story', $story_id ), 10, 2 );
		add_action( 'membuild_community/lesson_completed', fn( $lesson_id, $user_id ) => self::award( $user_id, 'lesson', 'lesson', $lesson_id ), 10, 2 );
		add_action( 'membuild_community/lesson_uncompleted', fn( $lesson_id, $user_id ) => self::revoke( $user_id, 'lesson', 'lesson', $lesson_id ), 10, 2 );
		add_action( 'membuild_community/course_completed', fn( $course_id, $user_id ) => self::award( $user_id, 'course', 'course', $course_id ), 10, 2 );
	}

	/** A reaction gives the author a point; changing its type keeps it, removing it takes it back. */
	public static function on_reaction( $object_type, $object_id, $actor_id, $type ) {
		global $wpdb;
		$table  = Install::table( 'post' === $object_type ? 'posts' : 'comments' );
		$author = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE id = %d', $table, $object_id ) );
		if ( ! $author || $author === (int) $actor_id ) {
			return; // no points for reacting to yourself
		}
		if ( $type && 'down' !== $type ) {
			self::award( $author, 'reaction_received', $object_type, $object_id, $actor_id );
		} else {
			self::revoke( $author, 'reaction_received', $object_type, $object_id, $actor_id );
		}
	}

	/** Deleting a post takes back what it earned, so posting and deleting can't farm points. */
	public static function on_post_deleted( $post_id, $user_id ) {
		self::revoke( $user_id, 'post', 'post', $post_id );
		self::revoke_all( $user_id, 'reaction_received', 'post', $post_id );
	}

	public static function on_comment_deleted( $comment_id, $user_id ) {
		self::revoke( $user_id, 'comment', 'comment', $comment_id );
		self::revoke_all( $user_id, 'reaction_received', 'comment', $comment_id );
	}

	/* ---------- Ledger ---------- */

	/**
	 * Records points once per (member, reason, object, actor), respecting the
	 * reason's daily cap. Returns the points awarded (0 when skipped).
	 */
	public static function award( $user_id, $reason, $object_type = '', $object_id = 0, $actor_id = 0, $at = null ) {
		global $wpdb;
		$rules = self::rules();
		if ( ! $user_id || ! isset( $rules[ $reason ] ) ) {
			return 0;
		}
		list( $points, $cap ) = $rules[ $reason ];
		$at                   = $at ?: Helpers::now();
		$ledger               = Install::table( 'points' );

		if ( $cap > 0 ) {
			$day   = substr( get_date_from_gmt( $at ), 0, 10 ); // the member's "today" is the site's day
			$start = get_gmt_from_date( "{$day} 00:00:00" );
			$used  = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND reason = %s AND created_at >= %s AND created_at < %s + INTERVAL 1 DAY', $ledger, $user_id, $reason, $start, $start )
			);
			if ( $used >= $cap ) {
				return 0;
			}
		}

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (user_id, points, reason, object_type, object_id, actor_id, created_at) VALUES (%d, %d, %s, %s, %d, %d, %s)',
				$ledger,
				$user_id,
				$points,
				$reason,
				$object_type,
				$object_id,
				$actor_id,
				$at
			)
		);
		if ( ! $inserted ) {
			return 0;
		}
		self::adjust( $user_id, $points );
		return $points;
	}

	public static function revoke( $user_id, $reason, $object_type, $object_id, $actor_id = 0 ) {
		global $wpdb;
		$ledger = Install::table( 'points' );
		$row    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, points FROM %i WHERE user_id = %d AND reason = %s AND object_type = %s AND object_id = %d AND actor_id = %d', $ledger, $user_id, $reason, $object_type, $object_id, $actor_id ),
			ARRAY_A
		);
		if ( $row ) {
			$wpdb->delete( $ledger, array( 'id' => $row['id'] ) );
			self::adjust( $user_id, -(int) $row['points'] );
		}
	}

	private static function revoke_all( $user_id, $reason, $object_type, $object_id ) {
		global $wpdb;
		$ledger = Install::table( 'points' );
		$sum    = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(points) FROM %i WHERE user_id = %d AND reason = %s AND object_type = %s AND object_id = %d', $ledger, $user_id, $reason, $object_type, $object_id )
		);
		if ( $sum ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND reason = %s AND object_type = %s AND object_id = %d', $ledger, $user_id, $reason, $object_type, $object_id ) );
			self::adjust( $user_id, -$sum );
		}
	}

	/**
	 * Applies a change to a member's running total and level in member_stats.
	 * Adding the difference keeps a like cheap however long a member's history
	 * is; rebuild() recomputes totals from the ledger if they ever drift.
	 */
	private static function adjust( $user_id, $delta ) {
		global $wpdb;
		$stats = Install::table( 'member_stats' );
		$now   = Helpers::now();
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id, points, level, updated_at) VALUES (%d, 0, 1, %s)', $stats, $user_id, $now ) );
		$old   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT level FROM %i WHERE user_id = %d', $stats, $user_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET points = GREATEST(0, points + %d), updated_at = %s WHERE user_id = %d', $stats, (int) $delta, $now, $user_id ) );
		$level = self::level_for( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT points FROM %i WHERE user_id = %d', $stats, $user_id ) ) );
		if ( $level !== $old ) {
			$wpdb->update( $stats, array( 'level' => $level ), array( 'user_id' => $user_id ) );
		}
		if ( $level > $old ) {
			/**
			 * A member reached a higher level.
			 *
			 * @param int $user_id The member.
			 * @param int $level   Their new level.
			 * @param int $old     Their previous level.
			 */
			do_action( 'membuild_community/level_up', $user_id, $level, $old );
		}
	}


	/* ---------- Streaks ---------- */

	/**
	 * Called once per app load. The first visit of the day gives +1 and
	 * extends the streak if yesterday was active; 7 and 30 days earn a bonus.
	 */
	public static function check_in( $user_id ) {
		global $wpdb;
		$stats = Install::table( 'member_stats' );
		$today = current_time( 'Y-m-d' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $stats, $user_id ), ARRAY_A );
		if ( $row && $row['last_active'] === $today ) {
			return;
		}
		$yesterday = gmdate( 'Y-m-d', strtotime( "{$today} -1 day" ) );
		$streak    = $row && $row['last_active'] === $yesterday ? (int) $row['streak'] + 1 : 1;

		$day_id = (int) str_replace( '-', '', $today );
		self::award( $user_id, 'daily_visit', 'day', $day_id );
		if ( 0 === $streak % 7 ) {
			self::award( $user_id, 'streak_7', 'day', $day_id );
		}
		if ( 0 === $streak % 30 ) {
			self::award( $user_id, 'streak_30', 'day', $day_id );
		}
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET streak = %d, best_streak = GREATEST(best_streak, %d), last_active = %s WHERE user_id = %d',
				$stats,
				$streak,
				$streak,
				$today,
				$user_id
			)
		);
	}

	/** A member's streak only counts if they were active today or yesterday. */
	public static function live_streak( $row ) {
		if ( ! $row || ! $row['last_active'] ) {
			return 0;
		}
		$today     = current_time( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( "{$today} -1 day" ) );
		return in_array( $row['last_active'], array( $today, $yesterday ), true ) ? (int) $row['streak'] : 0;
	}

	/* ---------- Lookups ---------- */

	/** @return array<int, array{points:int, level:int}> */
	public static function stats_for( array $user_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, points, level FROM %i WHERE user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'member_stats' ) ), $ids ) ), ARRAY_A ) as $r ) {
			$out[ (int) $r['user_id'] ] = array( 'points' => (int) $r['points'], 'level' => (int) $r['level'] );
		}
		return $out;
	}

	public static function level_of( $user_id ) {
		return self::stats_for( array( $user_id ) )[ (int) $user_id ]['level'] ?? 1;
	}

	/* ---------- Rebuild ---------- */

	/**
	 * Recreates the ledger from existing activity (posts, comments, reactions,
	 * finished lessons and courses), oldest first, applying the daily caps.
	 * Streaks are not reconstructed. Used after installing on an existing site.
	 */
	/**
	 * Recomputes every member's total and level from the ledger in two queries.
	 *
	 * @return int Members updated.
	 */
	public static function recount() {
		global $wpdb;
		$stats  = Install::table( 'member_stats' );
		$ledger = Install::table( 'points' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i s LEFT JOIN (SELECT user_id, SUM(points) total FROM %i GROUP BY user_id) l ON l.user_id = s.user_id SET s.points = GREATEST(0, COALESCE(l.total, 0))', $stats, $ledger ) );
		$count = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, points, level FROM %i', $stats ), ARRAY_A ) as $row ) {
			$level = self::level_for( (int) $row['points'] );
			if ( (int) $row['level'] !== $level ) {
				$wpdb->update( $stats, array( 'level' => $level ), array( 'user_id' => $row['user_id'] ) );
			}
			++$count;
		}
		return $count;
	}

	public static function rebuild() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Install::table( 'points' ) ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET points = 0, level = 1', Install::table( 'member_stats' ) ) );

		$posts     = Install::table( 'posts' );
		$comments  = Install::table( 'comments' );
		$reactions = Install::table( 'reactions' );
		$events    = array();

		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, created_at FROM %i WHERE status = %s', $posts, 'published' ), ARRAY_A ) as $r ) {
			$events[] = array( $r['created_at'], (int) $r['user_id'], 'post', 'post', (int) $r['id'], 0 );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, created_at FROM %i', $comments ), ARRAY_A ) as $r ) {
			$events[] = array( $r['created_at'], (int) $r['user_id'], 'comment', 'comment', (int) $r['id'], 0 );
		}
		$reacted = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.object_type, r.object_id, r.user_id AS actor, r.created_at, COALESCE(p.user_id, c.user_id) AS author
				FROM %i r
				LEFT JOIN %i p ON r.object_type = 'post' AND p.id = r.object_id AND p.status = 'published'
				LEFT JOIN %i c ON r.object_type = 'comment' AND c.id = r.object_id
				WHERE r.type <> 'down'",
				$reactions,
				$posts,
				$comments
			),
			ARRAY_A
		);
		foreach ( $reacted as $r ) {
			if ( $r['author'] && (int) $r['author'] !== (int) $r['actor'] ) {
				$events[] = array( $r['created_at'], (int) $r['author'], 'reaction_received', $r['object_type'], (int) $r['object_id'], (int) $r['actor'] );
			}
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, lesson_id, completed_at FROM %i', Install::table( 'lesson_progress' ) ), ARRAY_A ) as $r ) {
			$events[] = array( $r['completed_at'], (int) $r['user_id'], 'lesson', 'lesson', (int) $r['lesson_id'], 0 );
		}

		usort( $events, fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		$total = 0;
		foreach ( $events as list( $at, $user, $reason, $type, $id, $actor ) ) {
			$total += self::award( $user, $reason, $type, $id, $actor, $at );
		}
		return array( 'events' => count( $events ), 'points' => $total );
	}
}
