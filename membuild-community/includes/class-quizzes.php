<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Quizzes: one per lesson, at its end. Three kinds of question (one right answer, more than
 * one right answer, true or false), each with an optional picture and "Why this is the answer".
 *
 * The rules agreed on 12 Nov 2026 (the canvas note "proposals-quizcert-ask"):
 * - A quiz lesson is done once passed; with "They can move on anyway", finishing is enough.
 *   Done is the usual lesson_progress row, so lesson order, drip, Classroom progress and the
 *   course's finish (and its certificate) all follow without knowing about quizzes.
 * - A quiz only locks the next lesson in courses set to go in order (that's order_block).
 * - Editing or deleting a question keeps past passes; that question's totals start fresh.
 * - Leaving mid-quiz keeps the answers (the open attempt).
 * - The Why shows right after each answer; after a fail the right answers stay hidden.
 * - The pass mark is a count, never more than the number of questions.
 *
 * The right answers never leave the server before a member's own answer to that question is
 * checked here, and only after a pass in "See your answers".
 *
 * Members (who can open the lesson):
 *   GET  /lessons/{id}/quiz            the questions (no right answers) and where they are
 *   POST /lessons/{id}/quiz/answer     check one answer {question_id, picked: [answer ids]}
 *   GET  /lessons/{id}/quiz/answers    "See your answers" for their latest finished try
 *
 * Owners (manage_options):
 *   GET|POST|PUT|DELETE /lessons/{id}/quiz/edit   the quiz with its right answers, and how members are doing
 *   POST /quizzes/{id}/questions · PUT /quizzes/{id}/order · PUT|DELETE /quiz-questions/{id}
 *
 * Tries made while an owner uses "See it as a member" are kept apart (preview = 1): they work
 * the same for the owner but never count in "How are members doing?" or a question's totals.
 */
class Quizzes {

	const TYPES       = array( 'one', 'many', 'truefalse' );
	const MAX_ANSWERS = 10;
	const MAX_QUESTIONS = 50;

	public static function register_hooks() {
		add_action(
			'deleted_user',
			function ( $user_id ) {
				global $wpdb;
				$wpdb->delete( Install::table( 'quiz_attempts' ), array( 'user_id' => (int) $user_id ) );
			}
		);
	}

	public static function register_routes() {
		$member = Rest_Feed::feature( 'classroom' );
		$admin  = fn() => is_user_logged_in() && Helpers::can_manage();
		$id     = '(?P<id>\d+)';
		$routes = array(
			"/lessons/{$id}/quiz"         => array( array( 'GET', 'take', $member ) ),
			"/lessons/{$id}/quiz/answer"  => array( array( 'POST', 'answer', $member ) ),
			"/lessons/{$id}/quiz/answers" => array( array( 'GET', 'your_answers', $member ) ),
			"/lessons/{$id}/quiz/edit"    => array( array( 'GET', 'get_edit', $admin ), array( 'POST', 'create', $admin ), array( 'PUT', 'update', $admin ), array( 'DELETE', 'remove', $admin ) ),
			"/quizzes/{$id}/questions"    => array( array( 'POST', 'add_question', $admin ) ),
			"/quizzes/{$id}/order"        => array( array( 'PUT', 'order', $admin ) ),
			"/quiz-questions/{$id}"       => array( array( 'PUT', 'edit_question', $admin ), array( 'DELETE', 'delete_question', $admin ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				array_map( fn( $h ) => array( 'methods' => $h[0], 'callback' => array( __CLASS__, $h[1] ), 'permission_callback' => $h[2] ), $handlers )
			);
		}
	}

	/* ---------- Reading ---------- */

	private static function quiz_row( $lesson_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE lesson_id = %d', Install::table( 'quizzes' ), $lesson_id ), ARRAY_A );
	}

	private static function questions( $quiz_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE quiz_id = %d ORDER BY sort_order ASC, id ASC', Install::table( 'quiz_questions' ), $quiz_id ), ARRAY_A );
	}

	/** The pass mark in use: the owner's count, or about 80% of the questions until they pick one. Never more than the questions. */
	public static function pass_mark( array $quiz, $count ) {
		$mark = null === $quiz['pass_mark'] || '' === $quiz['pass_mark'] ? (int) ceil( 0.8 * $count ) : (int) $quiz['pass_mark'];
		return max( $count ? 1 : 0, min( $count, $mark ) );
	}

	/**
	 * A lesson's quiz when members see it (it has at least one question): {id, on_fail, questions},
	 * or null. Members only see a quiz once it has a question, so an empty one changes nothing.
	 */
	public static function active_for( $lesson_id ) {
		return self::for_lessons( array( (int) $lesson_id ) )[ (int) $lesson_id ] ?? null;
	}

	/** active_for() for many lessons in one query: lesson id => {id, on_fail, questions}. */
	public static function for_lessons( array $lesson_ids ) {
		global $wpdb;
		$lesson_ids = array_values( array_filter( array_map( 'intval', $lesson_ids ) ) );
		if ( ! $lesson_ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a list of %d placeholders; values are bound.
			$wpdb->prepare( 'SELECT q.id, q.lesson_id, q.on_fail, COUNT(a.id) AS questions FROM %i q JOIN %i a ON a.quiz_id = q.id WHERE q.lesson_id IN (' . implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) ) . ') GROUP BY q.id', array_merge( array( Install::table( 'quizzes' ), Install::table( 'quiz_questions' ) ), $lesson_ids ) ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['lesson_id'] ] = array( 'id' => (int) $r['id'], 'on_fail' => 'move_on' === $r['on_fail'] ? 'move_on' : 'retry', 'questions' => (int) $r['questions'] );
		}
		return $out;
	}

	/** The current member's open try (answers kept), or null. */
	private static function open_attempt( $quiz_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE quiz_id = %d AND user_id = %d AND finished_at IS NULL ORDER BY id DESC LIMIT 1', Install::table( 'quiz_attempts' ), $quiz_id, get_current_user_id() ), ARRAY_A );
	}

	/** The current member's latest finished try, or null. */
	private static function last_attempt( $quiz_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE quiz_id = %d AND user_id = %d AND finished_at IS NOT NULL ORDER BY id DESC LIMIT 1', Install::table( 'quiz_attempts' ), $quiz_id, get_current_user_id() ), ARRAY_A );
	}

	private static function attempt_answers( $attempt ) {
		$answers = $attempt ? json_decode( (string) $attempt['answers'], true ) : array();
		return is_array( $answers ) ? $answers : array();
	}

	/**
	 * The quiz at the end of a lesson, as the current member stands with it, for the lesson page:
	 * {questions, pass_mark, on_fail, state: new | open | passed | failed | done, at, answered, last}.
	 * at: the question to carry on from (1-based) while a try is open. Null when there's no quiz.
	 */
	public static function summary( $lesson_id ) {
		$quiz = self::quiz_row( $lesson_id );
		if ( ! $quiz ) {
			return null;
		}
		$questions = self::questions( (int) $quiz['id'] );
		if ( ! $questions ) {
			return Helpers::can_manage() ? array( 'id' => (int) $quiz['id'], 'questions' => 0, 'pass_mark' => 0, 'on_fail' => $quiz['on_fail'], 'state' => 'new' ) : null;
		}
		$count = count( $questions );
		$open  = self::open_attempt( (int) $quiz['id'] );
		$last  = self::last_attempt( (int) $quiz['id'] );
		$out   = array(
			'id'        => (int) $quiz['id'],
			'questions' => $count,
			'pass_mark' => self::pass_mark( $quiz, $count ),
			'on_fail'   => 'move_on' === $quiz['on_fail'] ? 'move_on' : 'retry',
			'state'     => 'new',
			'last'      => $last ? array( 'score' => (int) $last['score'], 'total' => (int) $last['total'], 'passed' => (bool) $last['passed'] ) : null,
			'passed'    => self::ever_passed( (int) $quiz['id'] ), // a pass stays a pass, whatever a later try scores
		);
		if ( $open ) {
			$given = self::attempt_answers( $open );
			$ids   = array_map( 'intval', array_column( $questions, 'id' ) );
			$n     = count( array_intersect( $ids, array_map( 'intval', array_keys( $given ) ) ) );
			if ( $n > 0 ) {
				$out['state']    = 'open';
				$out['answered'] = $n;
				$first           = array_search( true, array_map( fn( $id ) => ! isset( $given[ $id ] ), $ids ), true );
				$out['at']       = false === $first ? $count : $first + 1;
				return $out;
			}
		}
		if ( $out['passed'] ) {
			$out['state'] = 'passed';
		} elseif ( $last ) {
			$out['state'] = 'move_on' === $out['on_fail'] ? 'done' : 'failed';
		}
		return $out;
	}

	private static function ever_passed( $quiz_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE quiz_id = %d AND user_id = %d AND passed = 1 LIMIT 1', Install::table( 'quiz_attempts' ), $quiz_id, get_current_user_id() ) );
	}

	/**
	 * Whether the current member has earned this lesson's quiz: passed it once, or finished a try
	 * when members may move on anyway. A lesson un-marked after that can be marked done again.
	 */
	public static function earned( $lesson_id ) {
		global $wpdb;
		$quiz = self::quiz_row( (int) $lesson_id );
		if ( ! $quiz ) {
			return false;
		}
		if ( self::ever_passed( (int) $quiz['id'] ) ) {
			return true;
		}
		return 'move_on' === $quiz['on_fail'] && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE quiz_id = %d AND user_id = %d AND finished_at IS NOT NULL LIMIT 1', Install::table( 'quiz_attempts' ), $quiz['id'], get_current_user_id() ) );
	}

	/** The lesson, its quiz and its questions when the current member may take it now; else a WP_Error. */
	private static function for_member( $lesson_id ) {
		$lesson = Rest_Courses::lesson( $lesson_id );
		if ( ! $lesson || ! Rest_Courses::can_open_lesson( $lesson_id ) ) {
			return new \WP_Error( 'mbcom_not_available', __( 'This lesson is not available.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$quiz      = self::quiz_row( $lesson_id );
		$questions = $quiz ? self::questions( (int) $quiz['id'] ) : array();
		if ( ! $questions ) {
			return new \WP_Error( 'mbcom_no_quiz', __( 'This lesson has no quiz.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		return array( $lesson, $quiz, $questions );
	}

	/** A question as members get it: never which answers are right. */
	public static function public_question( array $q ) {
		return array(
			'id'       => (int) $q['id'],
			'type'     => $q['type'],
			'question' => $q['question'],
			'image'    => self::image( $q ),
			'answers'  => array_map( fn( $a ) => array( 'id' => (int) $a['id'], 'text' => $a['text'] ), self::answers_of( $q ) ),
		);
	}

	private static function answers_of( array $q ) {
		$answers = json_decode( (string) $q['answers'], true );
		return is_array( $answers ) ? $answers : array();
	}

	private static function right_ids( array $q ) {
		$ids = array();
		foreach ( self::answers_of( $q ) as $a ) {
			if ( ! empty( $a['right'] ) ) {
				$ids[] = (int) $a['id'];
			}
		}
		sort( $ids );
		return $ids;
	}

	private static function image( array $q ) {
		if ( empty( $q['media_id'] ) ) {
			return null;
		}
		$m = Media::get( (int) $q['media_id'] );
		return $m ? array( 'id' => (int) $m['id'], 'url' => $m['url'], 'width' => (int) ( $m['width'] ?? 0 ), 'height' => (int) ( $m['height'] ?? 0 ) ) : null;
	}

	/** What a checked answer tells the member: right or wrong, their own pick, and the Why. Never the right answer. */
	private static function checked( array $q, array $given ) {
		return array(
			'picked' => array_map( 'intval', (array) ( $given['picked'] ?? array() ) ),
			'right'  => ! empty( $given['right'] ),
			'why'    => (string) $q['why'],
		);
	}

	/** GET /lessons/{id}/quiz: the questions and the member's answers so far (each one already checked). */
	public static function take( \WP_REST_Request $req ) {
		$found = self::for_member( (int) $req['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $lesson, $quiz, $questions ) = $found;
		$given   = self::attempt_answers( self::open_attempt( (int) $quiz['id'] ) );
		$answers = array();
		foreach ( $questions as $q ) {
			if ( isset( $given[ (int) $q['id'] ] ) ) {
				$answers[ (int) $q['id'] ] = self::checked( $q, $given[ (int) $q['id'] ] );
			}
		}
		return array(
			'lesson_id' => (int) $lesson['id'],
			'quiz'      => self::summary( (int) $lesson['id'] ),
			'questions' => array_map( array( __CLASS__, 'public_question' ), $questions ),
			'answers'   => (object) $answers, // question id => {picked, right, why}
		);
	}

	/**
	 * POST /lessons/{id}/quiz/answer: checks one answer here, on the server, and keeps it in the
	 * member's open try (a new one starts with the first answer). A question answered already in
	 * this try keeps its first answer. The last answer finishes the try: the score, pass or not,
	 * and the lesson done when passed (or finished, for "They can move on anyway").
	 */
	public static function answer( \WP_REST_Request $req ) {
		global $wpdb;
		$found = self::for_member( (int) $req['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $lesson, $quiz, $questions ) = $found;
		$qid      = (int) $req['question_id'];
		$question = null;
		foreach ( $questions as $q ) {
			if ( (int) $q['id'] === $qid ) {
				$question = $q;
			}
		}
		if ( ! $question ) {
			return new \WP_Error( 'mbcom_no_question', __( 'This question was changed. Open the quiz again, please.', 'membuild-community' ), array( 'status' => 409 ) );
		}
		$valid  = array_map( 'intval', array_column( self::answers_of( $question ), 'id' ) );
		$picked = array_values( array_unique( array_map( 'intval', (array) $req['picked'] ) ) );
		sort( $picked );
		$single = 'many' !== $question['type'];
		if ( ! $picked || array_diff( $picked, $valid ) || ( $single && count( $picked ) > 1 ) ) {
			return new \WP_Error( 'mbcom_bad_answer', $single ? __( 'Pick one answer.', 'membuild-community' ) : __( 'Tick at least one answer.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( ! Helpers::rate_ok( 'quiz', 120, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'mbcom_rate_limited', __( 'That’s a lot of answers in a short time. Take a break and try again soon.', 'membuild-community' ), array( 'status' => 429 ) );
		}

		// One answer at a time per member and quiz: two sent at once (a double tap, two tabs) would
		// otherwise both read the try, and one answer would be lost or a second try opened.
		$lock = 'mbcom_quiz_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 10 ) . '_' . (int) $quiz['id'] . '_' . get_current_user_id();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new \WP_Error( 'mbcom_busy', __( 'Your last answer is still being checked. Try again in a moment.', 'membuild-community' ), array( 'status' => 409 ) );
		}
		try {
			return self::answer_locked( $lesson, $quiz, $questions, $question, $picked );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** answer(), holding the member's lock on this quiz: keeps the answer in their one open try. */
	private static function answer_locked( array $lesson, array $quiz, array $questions, array $question, array $picked ) {
		global $wpdb;
		$qid     = (int) $question['id'];
		$table   = Install::table( 'quiz_attempts' );
		$attempt = self::open_attempt( (int) $quiz['id'] );
		if ( ! $attempt ) {
			// preview 1: See it as a member (never counts, never gives a certificate); 2: an owner or
			// moderator trying it themselves (works as usual, but isn't counted in How are members doing).
			$preview = Helpers::as_member() ? 1 : ( Helpers::can_moderate() ? 2 : 0 );
			$wpdb->insert( $table, array( 'quiz_id' => (int) $quiz['id'], 'user_id' => get_current_user_id(), 'answers' => '{}', 'preview' => $preview, 'started_at' => Helpers::now() ) );
			$attempt = self::open_attempt( (int) $quiz['id'] );
		}
		$given = self::attempt_answers( $attempt );
		if ( ! isset( $given[ $qid ] ) ) {
			// Right means every right answer and no wrong one (for one right answer and true or false: the one).
			$right          = $picked === self::right_ids( $question );
			$given[ $qid ]  = array( 'picked' => $picked, 'right' => $right );
			$wpdb->update( $table, array( 'answers' => wp_json_encode( $given ) ), array( 'id' => (int) $attempt['id'] ) );
			if ( empty( $attempt['preview'] ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET answered = answered + 1, answered_right = answered_right + %d WHERE id = %d', Install::table( 'quiz_questions' ), $right ? 1 : 0, $qid ) );
			}
		}
		$out = self::checked( $question, $given[ $qid ] ) + array( 'question_id' => $qid, 'finished' => false );

		$ids = array_map( 'intval', array_column( $questions, 'id' ) );
		if ( ! array_diff( $ids, array_map( 'intval', array_keys( $given ) ) ) ) {
			$out = array_merge( $out, self::finish( $lesson, $quiz, $questions, $attempt, $given ) );
		}
		return $out;
	}

	/** Every question answered: the score, pass or not, and the lesson done when it counts. */
	private static function finish( array $lesson, array $quiz, array $questions, array $attempt, array $given ) {
		global $wpdb;
		$total  = count( $questions );
		$score  = count( array_filter( $questions, fn( $q ) => ! empty( $given[ (int) $q['id'] ]['right'] ) ) );
		$mark   = self::pass_mark( $quiz, $total );
		$passed = $score >= $mark;
		$wpdb->update(
			Install::table( 'quiz_attempts' ),
			array( 'score' => $score, 'total' => $total, 'passed' => $passed ? 1 : 0, 'finished_at' => Helpers::now() ),
			array( 'id' => (int) $attempt['id'] )
		);
		/**
		 * A member finished a quiz (every question answered).
		 *
		 * @param int  $lesson_id The quiz's lesson.
		 * @param int  $user_id   The member.
		 * @param int  $score     How many they got right.
		 * @param int  $total     How many questions it had.
		 * @param bool $passed    Whether they reached the pass mark.
		 */
		do_action( 'membuild_community/quiz_finished', (int) $lesson['id'], get_current_user_id(), $score, $total, $passed );
		$out = array(
			'finished' => true,
			'result'   => array( 'score' => $score, 'total' => $total, 'pass_mark' => $mark, 'passed' => $passed, 'on_fail' => 'move_on' === $quiz['on_fail'] ? 'move_on' : 'retry' ),
		);
		// Done: passed, or finished when they may move on anyway. A later lower score never undoes it.
		if ( $passed || 'move_on' === $quiz['on_fail'] || Rest_Courses::is_done( (int) $lesson['id'] ) || self::earned( (int) $lesson['id'] ) ) {
			$out['progress'] = Rest_Courses::record_done( $lesson );
		}
		$out['quiz'] = self::summary( (int) $lesson['id'] );
		return $out;
	}

	/**
	 * GET /lessons/{id}/quiz/answers: the latest finished try, question by question: right or wrong,
	 * the member's own answer and the Why. The right answers only once they've passed.
	 */
	public static function your_answers( \WP_REST_Request $req ) {
		$found = self::for_member( (int) $req['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $lesson, $quiz, $questions ) = $found;
		$last = self::last_attempt( (int) $quiz['id'] );
		if ( ! $last ) {
			return new \WP_Error( 'mbcom_no_try', __( 'Finish the quiz first, then your answers show here.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$given  = self::attempt_answers( $last );
		$reveal = (bool) $last['passed'];
		$items  = array();
		foreach ( $questions as $q ) {
			$mine  = $given[ (int) $q['id'] ] ?? null;
			$texts = array();
			foreach ( self::answers_of( $q ) as $a ) {
				$texts[ (int) $a['id'] ] = $a['text'];
			}
			$item = array(
				'id'       => (int) $q['id'],
				'question' => $q['question'],
				'type'     => $q['type'],
				'answered' => null !== $mine,
				'right'    => ! empty( $mine['right'] ),
				'picked'   => array_values( array_filter( array_map( fn( $id ) => $texts[ (int) $id ] ?? null, (array) ( $mine['picked'] ?? array() ) ) ) ),
				'why'      => (string) $q['why'],
			);
			// A question added or changed since that pass wasn't part of it: its right answer stays hidden.
			if ( $reveal && strtotime( (string) $q['updated_at'] ) <= strtotime( (string) $last['finished_at'] ) ) {
				$item['right_answers'] = array_values( array_map( fn( $id ) => $texts[ $id ], self::right_ids( $q ) ) );
			}
			$items[] = $item;
		}
		return array(
			'lesson_id' => (int) $lesson['id'],
			'score'     => (int) $last['score'],
			'total'     => (int) $last['total'],
			'pass_mark' => self::pass_mark( $quiz, count( $questions ) ),
			'passed'    => $reveal,
			'on_fail'   => 'move_on' === $quiz['on_fail'] ? 'move_on' : 'retry',
			'items'     => $items,
		);
	}

	/* ---------- Owners ---------- */

	private static function admin_lesson( $lesson_id ) {
		$lesson = Rest_Courses::lesson( $lesson_id );
		return $lesson ?: new \WP_Error( 'mbcom_not_found', __( 'Lesson not found.', 'membuild-community' ), array( 'status' => 404 ) );
	}

	/** The quiz for its editor: questions with their right answers, the pass rules, and how members are doing. */
	private static function edit_view( array $lesson ) {
		global $wpdb;
		$quiz = self::quiz_row( (int) $lesson['id'] );
		if ( ! $quiz ) {
			return array( 'lesson_id' => (int) $lesson['id'], 'lesson_title' => $lesson['title'], 'course_id' => (int) $lesson['course_id'], 'quiz' => null );
		}
		$questions = self::questions( (int) $quiz['id'] );
		$tries     = Install::table( 'quiz_attempts' );
		$tried     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM %i WHERE quiz_id = %d AND preview = 0 AND finished_at IS NOT NULL', $tries, $quiz['id'] ) );
		$passed    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT user_id) FROM %i WHERE quiz_id = %d AND preview = 0 AND passed = 1', $tries, $quiz['id'] ) );
		$hardest   = null;
		foreach ( $questions as $q ) {
			if ( (int) $q['answered'] > 0 ) {
				$pct = (int) round( 100 * (int) $q['answered_right'] / (int) $q['answered'] );
				if ( ! $hardest || $pct < $hardest['pct'] ) {
					$hardest = array( 'id' => (int) $q['id'], 'question' => $q['question'], 'pct' => $pct );
				}
			}
		}
		return array(
			'lesson_id'    => (int) $lesson['id'],
			'lesson_title' => $lesson['title'],
			'course_id'    => (int) $lesson['course_id'],
			'quiz'         => array(
				'id'          => (int) $quiz['id'],
				'pass_mark'   => self::pass_mark( $quiz, count( $questions ) ),
				'pass_chosen' => null !== $quiz['pass_mark'],
				'on_fail'     => 'move_on' === $quiz['on_fail'] ? 'move_on' : 'retry',
				'questions'   => array_map(
					fn( $q ) => array(
						'id'       => (int) $q['id'],
						'type'     => $q['type'],
						'question' => $q['question'],
						'answers'  => array_map( fn( $a ) => array( 'id' => (int) $a['id'], 'text' => $a['text'], 'right' => ! empty( $a['right'] ) ), self::answers_of( $q ) ),
						'why'      => (string) $q['why'],
						'image'    => self::image( $q ),
					),
					$questions
				),
				'stats'       => array( 'tried' => $tried, 'passed' => $passed, 'hardest' => $tried ? $hardest : null ),
			),
		);
	}

	public static function get_edit( \WP_REST_Request $req ) {
		$lesson = self::admin_lesson( (int) $req['id'] );
		return is_wp_error( $lesson ) ? $lesson : self::edit_view( $lesson );
	}

	/** POST: Add a quiz (one per lesson; a second Add gives back the one there is). */
	public static function create( \WP_REST_Request $req ) {
		global $wpdb;
		$lesson = self::admin_lesson( (int) $req['id'] );
		if ( is_wp_error( $lesson ) ) {
			return $lesson;
		}
		if ( ! self::quiz_row( (int) $lesson['id'] ) ) {
			$now = Helpers::now();
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (lesson_id, course_id, on_fail, created_at, updated_at) VALUES (%d, %d, %s, %s, %s)', Install::table( 'quizzes' ), $lesson['id'], $lesson['course_id'], 'retry', $now, $now ) );
		}
		$res = rest_ensure_response( self::edit_view( $lesson ) );
		$res->set_status( 201 );
		return $res;
	}

	/** PUT: the pass mark (a count, 1 to the number of questions) and what happens if they don't pass. */
	public static function update( \WP_REST_Request $req ) {
		global $wpdb;
		$lesson = self::admin_lesson( (int) $req['id'] );
		$quiz   = is_wp_error( $lesson ) ? null : self::quiz_row( (int) $lesson['id'] );
		if ( ! $quiz ) {
			return is_wp_error( $lesson ) ? $lesson : new \WP_Error( 'mbcom_no_quiz', __( 'This lesson has no quiz.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$data = array( 'updated_at' => Helpers::now() );
		if ( null !== $req['pass_mark'] ) {
			$count = count( self::questions( (int) $quiz['id'] ) );
			$mark  = (int) $req['pass_mark'];
			if ( $mark > $count ) {
				/* translators: %d: the number of questions */
				return new \WP_Error( 'mbcom_pass_mark', sprintf( _n( 'Can’t be more than %d, the number of questions.', 'Can’t be more than %d, the number of questions.', $count, 'membuild-community' ), $count ), array( 'status' => 400, 'field' => 'pass_mark' ) );
			}
			if ( $mark < 1 ) {
				return new \WP_Error( 'mbcom_pass_mark', __( 'Type a number of 1 or more.', 'membuild-community' ), array( 'status' => 400, 'field' => 'pass_mark' ) );
			}
			$data['pass_mark'] = $mark;
		}
		if ( null !== $req['on_fail'] ) {
			$data['on_fail'] = 'move_on' === $req['on_fail'] ? 'move_on' : 'retry';
		}
		$wpdb->update( Install::table( 'quizzes' ), $data, array( 'id' => (int) $quiz['id'] ) );
		return self::edit_view( $lesson );
	}

	/** DELETE: Remove quiz. Its questions and results go; members who finished the lesson keep it done. */
	public static function remove( \WP_REST_Request $req ) {
		$lesson = self::admin_lesson( (int) $req['id'] );
		if ( is_wp_error( $lesson ) ) {
			return $lesson;
		}
		self::remove_for( array( (int) $lesson['id'] ) );
		return self::edit_view( $lesson );
	}

	/** Removes the quizzes of these lessons: questions, their pictures and every try. Lesson progress stays. */
	public static function remove_for( array $lesson_ids ) {
		global $wpdb;
		foreach ( array_filter( array_map( 'intval', $lesson_ids ) ) as $lesson_id ) {
			$quiz = self::quiz_row( $lesson_id );
			if ( ! $quiz ) {
				continue;
			}
			foreach ( self::questions( (int) $quiz['id'] ) as $q ) {
				self::drop_picture( (int) $q['media_id'] );
			}
			$wpdb->delete( Install::table( 'quiz_questions' ), array( 'quiz_id' => (int) $quiz['id'] ) );
			$wpdb->delete( Install::table( 'quiz_attempts' ), array( 'quiz_id' => (int) $quiz['id'] ) );
			$wpdb->delete( Install::table( 'quizzes' ), array( 'id' => (int) $quiz['id'] ) );
		}
	}

	private static function drop_picture( $media_id ) {
		$row = $media_id ? Media::get( $media_id ) : null;
		if ( $row && null !== $row['post_id'] && 0 === (int) $row['post_id'] && 'message' !== $row['scope'] ) {
			Media::delete( $media_id );
		}
	}

	/**
	 * A question from the editor, checked: {type, question, answers: [{id?, text, right}], why, media_id}.
	 * Errors name the field they belong to, as the editor shows them under it.
	 */
	private static function clean_question( \WP_REST_Request $req, $old = null ) {
		$type = in_array( $req['type'], self::TYPES, true ) ? $req['type'] : ( $old['type'] ?? 'one' );
		$text = mb_substr( trim( sanitize_text_field( (string) $req['question'] ) ), 0, 500 );
		$why  = Helpers::clean_text( (string) $req['why'], 1000 );
		$in   = 'truefalse' === $type
			? array( array( 'id' => 1, 'text' => __( 'True', 'membuild-community' ) ), array( 'id' => 2, 'text' => __( 'False', 'membuild-community' ) ) )
			: array_slice( array_values( array_filter( (array) $req['answers'], 'is_array' ) ), 0, self::MAX_ANSWERS );
		// True or false: the ids marked right. The others carry their own "right" in $in, so it stays
		// with its answer however the list was cleaned (anything that isn't an answer is dropped first).
		$right_in = array();
		if ( 'truefalse' === $type ) {
			foreach ( array_values( array_filter( (array) $req['answers'], 'is_array' ) ) as $i => $a ) {
				if ( ! empty( $a['right'] ) ) {
					$right_in[] = (int) ( $a['id'] ?? 0 ) ?: $i + 1;
				}
			}
		}
		$keep    = array_map( 'intval', array_column( $old ? self::answers_of( $old ) : array(), 'id' ) );
		$next    = $keep ? max( $keep ) + 1 : 1;
		$answers = array();
		foreach ( $in as $i => $a ) {
			$label = mb_substr( trim( sanitize_text_field( (string) ( $a['text'] ?? '' ) ) ), 0, 200 );
			if ( '' === $label ) {
				continue;
			}
			$id = 'truefalse' === $type ? (int) $a['id'] : ( in_array( (int) ( $a['id'] ?? 0 ), $keep, true ) ? (int) $a['id'] : $next++ );
			$answers[] = array(
				'id'    => $id,
				'text'  => $label,
				'right' => 'truefalse' === $type ? in_array( $id, $right_in, true ) : ! empty( $a['right'] ),
			);
		}
		$fields = array();
		if ( '' === $text ) {
			$fields['question'] = __( 'Write the question', 'membuild-community' );
		}
		if ( count( $answers ) < 2 ) {
			$fields['answers'] = __( 'Add at least two answers', 'membuild-community' );
		}
		$rights = count( array_filter( $answers, fn( $a ) => $a['right'] ) );
		if ( 'many' === $type && ! $rights ) {
			$fields['right'] = __( 'Tick at least one right answer', 'membuild-community' );
		} elseif ( 'many' !== $type && 1 !== $rights ) {
			$fields['right'] = __( 'Pick the right answer', 'membuild-community' );
		}
		if ( $fields ) {
			return new \WP_Error(
				'mbcom_question',
				/* translators: %d: how many things to fix */
				sprintf( _n( 'One thing to fix before saving. It is marked below.', '%d things to fix before saving. They are marked below.', count( $fields ), 'membuild-community' ), count( $fields ) ),
				array( 'status' => 400, 'fields' => $fields )
			);
		}
		$media = $old['media_id'] ?? null;
		if ( $req->has_param( 'media_id' ) ) {
			$want = (int) $req['media_id'];
			if ( ! $want ) {
				$media = null;
			} elseif ( (int) $media !== $want ) {
				$claimed = Rest_Courses::claim_media( $want, array( 'image' ) );
				if ( ! $claimed ) {
					return new \WP_Error( 'mbcom_question', __( 'Upload the picture again, please.', 'membuild-community' ), array( 'status' => 400, 'fields' => array( 'image' => __( 'Upload the picture again, please.', 'membuild-community' ) ) ) );
				}
				$media = (int) $claimed['id'];
			}
		}
		return array( 'type' => $type, 'question' => $text, 'answers' => wp_json_encode( $answers ), 'why' => $why, 'media_id' => $media );
	}

	private static function quiz_by_id( $quiz_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'quizzes' ), $quiz_id ), ARRAY_A );
	}

	/** Pictures are protected like the lesson's files: only members who can open the lesson see them. */
	private static function protect_picture( $media_id, array $quiz ) {
		if ( $media_id ) {
			Files::protect( (int) $media_id, 'lesson', (int) $quiz['lesson_id'] );
		}
	}

	public static function add_question( \WP_REST_Request $req ) {
		global $wpdb;
		$quiz = self::quiz_by_id( (int) $req['id'] );
		if ( ! $quiz ) {
			return new \WP_Error( 'mbcom_no_quiz', __( 'This lesson has no quiz.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$count = count( self::questions( (int) $quiz['id'] ) );
		if ( $count >= self::MAX_QUESTIONS ) {
			/* translators: %d: the most questions a quiz can have */
			return new \WP_Error( 'mbcom_question', sprintf( __( 'A quiz can have up to %d questions.', 'membuild-community' ), self::MAX_QUESTIONS ), array( 'status' => 400 ) );
		}
		$data = self::clean_question( $req );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$now = Helpers::now();
		$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort_order) FROM %i WHERE quiz_id = %d', Install::table( 'quiz_questions' ), $quiz['id'] ) );
		$wpdb->insert( Install::table( 'quiz_questions' ), $data + array( 'quiz_id' => (int) $quiz['id'], 'sort_order' => null === $max ? 0 : (int) $max + 1, 'created_at' => $now, 'updated_at' => $now ) );
		self::protect_picture( $data['media_id'], $quiz );
		$res = rest_ensure_response( self::edit_view( Rest_Courses::lesson( (int) $quiz['lesson_id'] ) ) + array( 'saved_id' => (int) $wpdb->insert_id ) );
		$res->set_status( 201 );
		return $res;
	}

	private static function question_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'quiz_questions' ), $id ), ARRAY_A );
	}

	/** Editing a question keeps everyone's past passes; its own totals start fresh (rule 5). */
	public static function edit_question( \WP_REST_Request $req ) {
		global $wpdb;
		$q    = self::question_row( (int) $req['id'] );
		$quiz = $q ? self::quiz_by_id( (int) $q['quiz_id'] ) : null;
		if ( ! $quiz ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Question not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$data = self::clean_question( $req, $q );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$wpdb->update( Install::table( 'quiz_questions' ), $data + array( 'answered' => 0, 'answered_right' => 0, 'updated_at' => Helpers::now() ), array( 'id' => (int) $q['id'] ) );
		if ( (int) $q['media_id'] && (int) $q['media_id'] !== (int) $data['media_id'] ) {
			self::drop_picture( (int) $q['media_id'] );
		}
		self::protect_picture( $data['media_id'], $quiz );
		return self::edit_view( Rest_Courses::lesson( (int) $quiz['lesson_id'] ) ) + array( 'saved_id' => (int) $q['id'] );
	}

	/** Deleting a question keeps past passes; a pass mark above the new count comes down to it (rule 12). */
	public static function delete_question( \WP_REST_Request $req ) {
		global $wpdb;
		$q    = self::question_row( (int) $req['id'] );
		$quiz = $q ? self::quiz_by_id( (int) $q['quiz_id'] ) : null;
		if ( ! $quiz ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Question not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$wpdb->delete( Install::table( 'quiz_questions' ), array( 'id' => (int) $q['id'] ) );
		self::drop_picture( (int) $q['media_id'] );
		$count = count( self::questions( (int) $quiz['id'] ) );
		if ( null !== $quiz['pass_mark'] && (int) $quiz['pass_mark'] > $count ) {
			$wpdb->update( Install::table( 'quizzes' ), array( 'pass_mark' => $count ?: null ), array( 'id' => (int) $quiz['id'] ) );
		}
		return self::edit_view( Rest_Courses::lesson( (int) $quiz['lesson_id'] ) );
	}

	/** PUT /quizzes/{id}/order {ids}: the questions' new order (dragged by their handle). */
	public static function order( \WP_REST_Request $req ) {
		global $wpdb;
		$quiz = self::quiz_by_id( (int) $req['id'] );
		if ( ! $quiz ) {
			return new \WP_Error( 'mbcom_no_quiz', __( 'This lesson has no quiz.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$mine = array_map( 'intval', array_column( self::questions( (int) $quiz['id'] ), 'id' ) );
		$pos  = 0;
		foreach ( array_map( 'intval', (array) $req['ids'] ) as $qid ) {
			if ( in_array( $qid, $mine, true ) ) {
				$wpdb->update( Install::table( 'quiz_questions' ), array( 'sort_order' => $pos++ ), array( 'id' => $qid ) );
			}
		}
		return self::edit_view( Rest_Courses::lesson( (int) $quiz['lesson_id'] ) );
	}
}
