<?php
/**
 * Course discussions.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Posts inside a course: under a lesson, or in the course's Discussion tab.
 *
 * They are ordinary community posts (same composer, reactions, replies,
 * reports and edits) with a course_id, and a lesson_id when posted under a
 * lesson. Only members who can open that course (and that lesson, while it
 * drips) can read them; they never show in the main feed or on profiles.
 *
 *   GET        /lessons/{id}/posts ?sort=top|newest&page=        a lesson's posts
 *   GET        /courses/{id}/posts ?sort=newest|top&q=&page=     the whole course, searchable
 *   PUT|DELETE /posts/{id}/helpful                               "Helpful" vote (not on your own post)
 *   PUT        /posts/{id}/best {comment_id}   DELETE            Best answer (staff or the post's author)
 *
 * Pins (PUT /posts/{id}/pin {scope: lesson|course}, staff) are stored in
 * posts.is_pinned: 1 = top of its lesson, 2 = top of the course's Discussion
 * tab (and of its lesson). Up to PINS of each.
 */
class Discussions {

	/** Pins per lesson, and per course. */
	const PINS = 3;

	public static function register_routes() {
		$member = Rest_Feed::feature( 'classroom' );
		$id     = '(?P<id>\d+)';
		$list   = array(
			'sort' => array( 'enum' => array( 'top', 'newest' ), 'default' => 'top' ),
			'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'q'    => array( 'type' => 'string', 'default' => '' ),
		);
		// A lesson opens on its most helpful posts; the course's Discussion tab on its newest.
		$routes = array(
			"/lessons/{$id}/posts" => array( array( 'GET', 'lesson_posts', $member, $list ) ),
			"/courses/{$id}/posts" => array( array( 'GET', 'course_posts', $member, array( 'sort' => array( 'enum' => array( 'top', 'newest' ), 'default' => 'newest' ) ) + $list ) ),
			"/posts/{$id}/helpful" => array( array( 'PUT', 'vote', $member ), array( 'DELETE', 'unvote', $member ) ),
			"/posts/{$id}/best"    => array( array( 'PUT', 'choose_best', $member ), array( 'DELETE', 'clear_best', $member ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				array_map(
					fn( $h ) => array(
						'methods'             => $h[0],
						'callback'            => array( __CLASS__, $h[1] ),
						'permission_callback' => $h[2],
						'args'                => $h[3] ?? array(),
					),
					$handlers
				)
			);
		}
	}

	/* ---------- Who can read what ---------- */

	/** May the current member read this course post (a posts row with a course_id)? */
	public static function can_read( array $row ) {
		if ( ! Settings::feature( 'classroom' ) ) {
			return false;
		}
		return (int) $row['lesson_id']
			? Rest_Courses::can_open_lesson( (int) $row['lesson_id'] )
			: Rest_Courses::can_open_course( (int) $row['course_id'] );
	}

	/**
	 * The courses and lessons whose posts the current member can read:
	 * courses they can open, and those courses' lessons that aren't dripping.
	 *
	 * @return array{courses:int[],lessons:int[]}
	 */
	public static function open() {
		global $wpdb;
		$out = array( 'courses' => array(), 'lessons' => array() );
		if ( ! Settings::feature( 'classroom' ) ) {
			return $out;
		}
		$rows = Helpers::can_manage()
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', Install::table( 'courses' ) ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'published'", Install::table( 'courses' ) ), ARRAY_A );
		foreach ( $rows as $c ) {
			if ( ! Access::hidden( $c ) && ! Access::lock( $c ) ) {
				$out['courses'][] = (int) $c['id'];
			}
		}
		if ( $out['courses'] ) {
			$lessons = $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, drip_days FROM %i WHERE course_id IN (' . implode( ',', array_fill( 0, count( $out['courses'] ), '%d' ) ) . ')', array_merge( array( Install::table( 'lessons' ) ), $out['courses'] ) ),
				ARRAY_A
			);
			foreach ( $lessons as $l ) {
				if ( ! Rest_Courses::unlock_at( (int) $l['drip_days'] ) ) {
					$out['lessons'][] = (int) $l['id'];
				}
			}
		}
		return $out;
	}

	/**
	 * SQL limiting posts to ones the current member may read: community posts,
	 * plus course posts in courses and lessons they can open. For search.
	 *
	 * @param string $alias The posts table's alias in the query, if any.
	 * @return array{0:string,1:array} Fixed SQL with %d placeholders, and its values.
	 */
	public static function visible_sql( $alias = '' ) {
		$p    = $alias ? "{$alias}." : '';
		$open = self::open();
		if ( ! $open['courses'] ) {
			return array( "{$p}course_id = 0", array() );
		}
		$courses = implode( ',', array_fill( 0, count( $open['courses'] ), '%d' ) );
		$lessons = $open['lessons'] ? implode( ',', array_fill( 0, count( $open['lessons'] ), '%d' ) ) : '0';
		return array(
			"({$p}course_id = 0 OR ({$p}course_id IN ({$courses}) AND ({$p}lesson_id = 0 OR {$p}lesson_id IN ({$lessons}))))",
			array_merge( $open['courses'], $open['lessons'] ),
		);
	}

	/**
	 * SQL for the Home feed: community posts, plus questions from courses the
	 * member has started (completed a lesson in, or posted in). A lesson question
	 * is the highest-intent post a member makes; in Home the whole class sees it
	 * and can answer, instead of only whoever opens that lesson. Admins see every
	 * course they can open. Course posts never show on profiles (see Rest_Feed).
	 *
	 * @return array{0:string,1:array} Fixed SQL with %d placeholders, and its values.
	 */
	public static function home_sql() {
		global $wpdb;
		list( $vis, $vals ) = self::visible_sql();
		if ( ! $vals || Helpers::can_manage() ) {
			return array( $vis, $vals );
		}
		$me      = get_current_user_id();
		$started = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT course_id FROM %i WHERE user_id = %d UNION SELECT DISTINCT course_id FROM %i WHERE user_id = %d AND course_id <> 0',
					Install::table( 'lesson_progress' ),
					$me,
					Install::table( 'posts' ),
					$me
				)
			)
		);
		if ( ! $started ) {
			return array( 'course_id = 0', array() );
		}
		$in = implode( ',', array_fill( 0, count( $started ), '%d' ) );
		return array( "(course_id = 0 OR (course_id IN ({$in}) AND {$vis}))", array_merge( $started, $vals ) );
	}

	/**
	 * Where a new post goes when the composer names a lesson or a course:
	 * array( course_id, lesson_id ), or an error when the member can't post there.
	 */
	public static function place( $lesson_id, $course_id ) {
		global $wpdb;
		if ( ! Settings::feature( 'classroom' ) ) {
			return new \WP_Error( 'mbcom_feature_off', __( 'This part of the community is turned off.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		if ( $lesson_id ) {
			$course_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT course_id FROM %i WHERE id = %d', Install::table( 'lessons' ), $lesson_id ) );
			$ok        = $course_id && Rest_Courses::can_open_lesson( $lesson_id );
		} else {
			$ok = Rest_Courses::can_open_course( $course_id );
		}
		if ( ! $ok ) {
			return new \WP_Error( 'mbcom_not_available', __( 'You can post here once you can open this lesson.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		return array( (int) $course_id, (int) $lesson_id );
	}

	/* ---------- Lists ---------- */

	public static function lesson_posts( \WP_REST_Request $req ) {
		$lesson_id = (int) $req['id'];
		if ( ! Rest_Courses::can_open_lesson( $lesson_id ) ) {
			return new \WP_Error( 'mbcom_not_available', __( 'This lesson is not available.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		return self::page( 'lesson_id = %d', array( $lesson_id ), $req, 'is_pinned > 0' );
	}

	/** The course's Discussion tab: posts from every lesson the member can open, and posts made in the tab. */
	public static function course_posts( \WP_REST_Request $req ) {
		global $wpdb;
		$course_id = (int) $req['id'];
		if ( ! Rest_Courses::can_open_course( $course_id ) ) {
			return new \WP_Error( 'mbcom_not_available', __( 'This course is not available.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$open    = self::open();
		$lessons = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $course_id ) ) );
		$lessons = array_values( array_intersect( $lessons, $open['lessons'] ) );
		$where   = 'course_id = %d AND (lesson_id = 0' . ( $lessons ? ' OR lesson_id IN (' . implode( ',', array_fill( 0, count( $lessons ), '%d' ) ) . ')' : '' ) . ')';
		$args    = array_merge( array( $course_id ), $lessons );

		$words = Search::words( Search::clean_query( (string) $req['q'] ) );
		if ( $words ) {
			// The post's words, or the words of a reply to it.
			list( $in_post, $post_vals )   = Search::match( 'posts', 'content', $words );
			list( $in_reply, $reply_vals ) = Search::match( 'comments', 'content', $words );
			$where .= " AND ({$in_post} OR id IN (SELECT post_id FROM " . esc_sql( Install::table( 'comments' ) ) . " WHERE {$in_reply}))";
			$args   = array_merge( $args, $post_vals, $reply_vals );
			return self::page( $where, $args, $req, null );
		}
		return self::page( $where, $args, $req, 'is_pinned = 2' );
	}

	/**
	 * One page of course posts, pinned ones first (page 1, in their own list).
	 *
	 * @param string      $where   Fixed SQL with placeholders.
	 * @param array       $args    Its values.
	 * @param string|null $pin_sql Which posts count as pinned here (null: no pinned list).
	 */
	private static function page( $where, array $args, \WP_REST_Request $req, $pin_sql ) {
		global $wpdb;
		$posts  = Install::table( 'posts' );
		$sort   = 'newest' === $req['sort'] ? 'newest' : 'top';
		$page   = max( 1, (int) $req['page'] );
		$per    = Rest_Feed::PAGE_SIZE;
		$order  = 'top' === $sort ? 'helpful_count DESC, (reactions_count + 2 * comments_count) DESC, id DESC' : 'id DESC';
		$pinned = array();
		$rest   = $where;
		if ( $pin_sql ) {
			if ( 1 === $page ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where and $pin_sql are fixed SQL with placeholders; values are bound.
				$pinned = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' AND {$where} AND {$pin_sql} ORDER BY is_pinned DESC, id DESC LIMIT %d", array_merge( array( $posts ), $args, array( 2 * self::PINS ) ) ), ARRAY_A );
			}
			$rest .= " AND NOT ({$pin_sql})";
		}
		$rows     = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $rest and $order are fixed SQL with placeholders; values are bound.
			$wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' AND {$rest} ORDER BY {$order} LIMIT %d, %d", array_merge( array( $posts ), $args, array( ( $page - 1 ) * $per, $per + 1 ) ) ),
			ARRAY_A
		);
		$has_more = count( $rows ) > $per;
		$rows     = Blocks::filter( array_slice( $rows, 0, $per ) );
		$pinned   = Blocks::filter( $pinned );
		return array(
			'items'     => Rest_Feed::format_posts( $rows ),
			'pinned'    => Rest_Feed::format_posts( $pinned ),
			'sort'      => $sort,
			'next_page' => $has_more ? $page + 1 : null,
		);
	}

	/* ---------- Helpful ---------- */

	public static function vote( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::course_post( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( (int) $row['user_id'] === get_current_user_id() ) {
			return new \WP_Error( 'mbcom_own_post', __( 'You can’t vote for your own post.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (object_type, object_id, user_id, type, created_at) VALUES ('helpful', %d, %d, 'up', %s)",
				Install::table( 'reactions' ),
				$row['id'],
				get_current_user_id(),
				Helpers::now()
			)
		);
		/**
		 * A member marked a course post as helpful, or took the vote back.
		 *
		 * @param int  $post_id The post.
		 * @param int  $user_id The member who voted.
		 * @param bool $helpful True for a vote, false when taken back.
		 */
		do_action( 'membuild_community/helpful_changed', (int) $row['id'], get_current_user_id(), true );
		return self::votes( (int) $row['id'] );
	}

	public static function unvote( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::course_post( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wpdb->delete( Install::table( 'reactions' ), array( 'object_type' => 'helpful', 'object_id' => $row['id'], 'user_id' => get_current_user_id() ) );
		/** This action is documented in includes/class-discussions.php */
		do_action( 'membuild_community/helpful_changed', (int) $row['id'], get_current_user_id(), false );
		return self::votes( (int) $row['id'] );
	}

	/** Recounts instead of incrementing, so the stored counter can never drift. */
	private static function votes( $post_id ) {
		global $wpdb;
		$reacts = Install::table( 'reactions' );
		$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'helpful' AND object_id = %d", $reacts, $post_id ) );
		$wpdb->update( Install::table( 'posts' ), array( 'helpful_count' => $count ), array( 'id' => $post_id ) );
		return array(
			'helpful_count' => $count,
			'my_helpful'    => (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE object_type = 'helpful' AND object_id = %d AND user_id = %d", $reacts, $post_id, get_current_user_id() ) ),
		);
	}

	/* ---------- Best answer ---------- */

	public static function choose_best( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::course_post( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! self::can_choose( $row ) ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'Only admins, moderators and the person who posted can choose the best answer.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$comment = Rest_Comments::get( (int) $req['comment_id'] );
		if ( ! $comment || (int) $comment['post_id'] !== (int) $row['id'] || (int) $comment['parent_id'] ) {
			return new \WP_Error( 'mbcom_bad_comment', __( 'Choose one of the answers to this post.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->update( Install::table( 'posts' ), array( 'best_comment_id' => $comment['id'] ), array( 'id' => $row['id'] ) );
		/**
		 * A course post's best answer was chosen.
		 *
		 * @param int $post_id    The post.
		 * @param int $comment_id The answer.
		 * @param int $user_id    Who chose it.
		 */
		do_action( 'membuild_community/best_answer', (int) $row['id'], (int) $comment['id'], get_current_user_id() );
		return array( 'best_comment_id' => (int) $comment['id'] );
	}

	public static function clear_best( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::course_post( (int) $req['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! self::can_choose( $row ) ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'Only admins, moderators and the person who posted can choose the best answer.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$wpdb->update( Install::table( 'posts' ), array( 'best_comment_id' => 0 ), array( 'id' => $row['id'] ) );
		return array( 'best_comment_id' => 0 );
	}

	/** Staff, or the member who asked. */
	public static function can_choose( array $row ) {
		return Helpers::can_moderate() || (int) $row['user_id'] === get_current_user_id();
	}

	/* ---------- Pins ---------- */

	/**
	 * Pins a course post to the top of its lesson, or of the course's
	 * Discussion tab. Posts made in the tab (no lesson) always pin there.
	 */
	public static function pin( array $row, $scope ) {
		global $wpdb;
		$posts  = Install::table( 'posts' );
		$course = 'course' === $scope || ! (int) $row['lesson_id'];
		$value  = $course ? 2 : 1;
		if ( (int) $row['is_pinned'] !== $value ) {
			$count = $course
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE course_id = %d AND is_pinned = 2 AND status = 'published'", $posts, $row['course_id'] ) )
				: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE lesson_id = %d AND is_pinned = 1 AND status = 'published'", $posts, $row['lesson_id'] ) );
			if ( $count >= self::PINS ) {
				$message = $course
					/* translators: %d: how many posts can be pinned */
					? sprintf( __( 'Up to %d posts can be pinned to a course. Unpin one first.', 'membuild-community' ), self::PINS )
					/* translators: %d: how many posts can be pinned */
					: sprintf( __( 'Up to %d posts can be pinned to a lesson. Unpin one first.', 'membuild-community' ), self::PINS );
				return new \WP_Error( 'mbcom_pin_limit', $message, array( 'status' => 400 ) );
			}
			$wpdb->update( $posts, array( 'is_pinned' => $value ), array( 'id' => $row['id'] ) );
		}
		return array( 'pinned' => true, 'pin' => $course ? 'course' : 'lesson' );
	}

	/* ---------- Clean-up ---------- */

	/**
	 * Deletes the posts of lessons or courses that are being deleted.
	 *
	 * @param string $column 'lesson_id' or 'course_id'.
	 * @param int[]  $ids    Their IDs.
	 */
	public static function remove_for( $column, array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids || ! in_array( $column, array( 'lesson_id', 'course_id' ), true ) ) {
			return;
		}
		$post_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is one of two fixed names; values are bound.
			$wpdb->prepare( "SELECT id FROM %i WHERE {$column} IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ") AND status <> 'deleted'", array_merge( array( Install::table( 'posts' ) ), $ids ) )
		);
		foreach ( $post_ids as $post_id ) {
			Rest_Feed::remove_post( (int) $post_id );
		}
	}

	/** A published course post the member can read, or an error. */
	private static function course_post( $id ) {
		$row = Rest_Feed::row( $id );
		if ( ! $row || ! (int) $row['course_id'] ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		return $row;
	}
}
