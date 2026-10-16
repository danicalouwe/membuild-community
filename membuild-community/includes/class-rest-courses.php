<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Classroom: courses → modules → lessons, with progress and drip unlocks.
 * Who can open a course (everyone, a level, or access groups) is decided by Access.
 *
 * Members:
 *   GET    /courses                     grid with my progress
 *   GET    /courses/{id}                outline (modules, lessons, done / locked)
 *   GET    /courses/{id}/resources      every lesson's files in one list
 *   GET    /lessons/{id}                full lesson (403 + unlock_at while dripped)
 *   POST   /lessons/{id}/complete       mark done   (DELETE to undo)
 *
 * Admins (manage_options):
 *   POST /courses · PUT|DELETE /courses/{id} · PUT /courses/{id}/structure
 *   POST /courses/{id}/modules · PUT|DELETE /modules/{id}
 *   POST /modules/{id}/lessons · PUT|DELETE /lessons/{id}
 *
 * Fires membuild_community/lesson_completed and membuild_community/course_completed for gamification.
 */
class Rest_Courses {

	public static function register_routes() {
		$member = Rest_Feed::feature( 'classroom' );
		$admin  = fn() => is_user_logged_in() && Helpers::can_manage();
		$id     = '(?P<id>\d+)';

		$routes = array(
			'/courses'                     => array( array( 'GET', 'list_courses', $member ), array( 'POST', 'create_course', $admin ) ),
			"/courses/{$id}"               => array( array( 'GET', 'get_course', $member ), array( 'PUT', 'update_course', $admin ), array( 'DELETE', 'delete_course', $admin ) ),
			"/courses/{$id}/resources"     => array( array( 'GET', 'resources', $member ) ),
			"/courses/{$id}/structure"     => array( array( 'PUT', 'update_structure', $admin ) ),
			"/courses/{$id}/modules"       => array( array( 'POST', 'create_module', $admin ) ),
			"/modules/{$id}"               => array( array( 'PUT', 'update_module', $admin ), array( 'DELETE', 'delete_module', $admin ) ),
			"/modules/{$id}/lessons"       => array( array( 'POST', 'create_lesson', $admin ) ),
			"/lessons/{$id}"               => array( array( 'GET', 'get_lesson', $member ), array( 'PUT', 'update_lesson', $admin ), array( 'DELETE', 'delete_lesson', $admin ) ),
			"/lessons/{$id}/complete"      => array( array( 'POST', 'complete', $member ), array( 'DELETE', 'uncomplete', $member ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				array_map(
					fn( $h ) => array( 'methods' => $h[0], 'callback' => array( __CLASS__, $h[1] ), 'permission_callback' => $h[2] ),
					$handlers
				)
			);
		}
	}

	/* ---------- Reading ---------- */

	public static function list_courses() {
		global $wpdb;
		$courses = Install::table( 'courses' );
		$rows    = Helpers::can_manage()
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', $courses ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' ORDER BY sort_order ASC, id ASC", $courses ), ARRAY_A );
		// Courses set to "hide when locked" aren't listed for members who can't open them.
		$rows = array_values( array_filter( $rows, fn( $row ) => ! Access::hidden( $row ) ) );
		if ( ! $rows ) {
			return array( 'items' => array(), 'can_manage' => Helpers::can_manage() );
		}
		$ids    = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$totals = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT course_id, COUNT(*) n FROM %i WHERE course_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY course_id',
				array_merge( array( Install::table( 'lessons' ) ), $ids )
			),
			OBJECT_K
		);
		$done   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT course_id, COUNT(*) n FROM %i WHERE user_id = %d AND course_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY course_id',
				array_merge( array( Install::table( 'lesson_progress' ), get_current_user_id() ), $ids )
			),
			OBJECT_K
		);
		$journey = self::journeys( $ids );
		$life    = self::activity( $ids );
		return array(
			'items'      => array_map(
				function ( $row ) use ( $totals, $done, $journey, $life ) {
					$total     = (int) ( $totals[ $row['id'] ]->n ?? 0 );
					$completed = min( $total, (int) ( $done[ $row['id'] ]->n ?? 0 ) );
					$course    = self::format_course( $row );
					$mine      = $journey[ (int) $row['id'] ] ?? array();
					return $course + array(
						'lessons_count'   => $total,
						'completed_count' => $completed,
						'progress'        => $total ? (int) round( 100 * $completed / $total ) : 0,
						// Where "Continue" goes: the first lesson not done yet (not while the course is locked).
						'next_lesson'     => $course['lock'] ? null : ( $mine['next'] ?? null ),
						'last_activity'   => $mine['last'] ?? null,
					) + ( $life[ (int) $row['id'] ] ?? array() );
				},
				$rows
			),
			'can_manage' => Helpers::can_manage(),
		);
	}

	public static function get_course( \WP_REST_Request $req ) {
		$course = self::course_row( (int) $req['id'] );
		if ( ! $course || Access::hidden( $course ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Course not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		return self::outline( $course );
	}

	public static function get_lesson( \WP_REST_Request $req ) {
		$lesson = self::lesson_row( (int) $req['id'] );
		$course = $lesson ? self::course_row( (int) $lesson['course_id'] ) : null;
		if ( ! $lesson || ! $course || Access::hidden( $course ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Lesson not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$lock = Access::lock( $course );
		if ( $lock && 'level' === $lock['reason'] ) {
			/* translators: %d: level number */
			return new \WP_Error( 'mbcom_level_locked', sprintf( __( 'Reach Level %d to unlock this course.', 'membuild-community' ), $lock['level'] ), array( 'status' => 403, 'level' => $lock['level'] ) );
		}
		if ( $lock ) {
			return new \WP_Error( 'mbcom_group_locked', __( 'This course is for members of a group.', 'membuild-community' ), array( 'status' => 403, 'groups' => $lock['groups'] ) );
		}
		$unlock = self::unlock_at( (int) $lesson['drip_days'] );
		if ( $unlock ) {
			return new \WP_Error(
				'mbcom_locked',
				/* translators: %s: date the lesson unlocks */
				sprintf( __( 'This lesson unlocks on %s.', 'membuild-community' ), wp_date( get_option( 'date_format' ), $unlock ) ),
				array( 'status' => 403, 'unlock_at' => gmdate( 'c', $unlock ) )
			);
		}
		return self::format_lesson( $lesson, $course );
	}

	/* ---------- Progress ---------- */

	public static function complete( \WP_REST_Request $req ) {
		global $wpdb;
		$lesson = self::lesson_row( (int) $req['id'] );
		$course = $lesson ? self::course_row( (int) $lesson['course_id'] ) : null;
		if ( ! $lesson || ! $course || Access::lock( $course ) || self::unlock_at( (int) $lesson['drip_days'] ) ) {
			return new \WP_Error( 'mbcom_not_available', __( 'This lesson is not available.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$me       = get_current_user_id();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (user_id, lesson_id, course_id, completed_at) VALUES (%d, %d, %d, %s)',
				Install::table( 'lesson_progress' ),
				$me,
				$lesson['id'],
				$lesson['course_id'],
				Helpers::now()
			)
		);
		$progress = self::progress( (int) $lesson['course_id'] );
		if ( $inserted ) {
			/**
			 * A member finished a lesson for the first time.
			 *
			 * @param int $lesson_id The lesson.
			 * @param int $user_id   The member.
			 * @param int $course_id Its course.
			 */
			do_action( 'membuild_community/lesson_completed', (int) $lesson['id'], $me, (int) $lesson['course_id'] );
			if ( 100 === $progress['progress'] ) {
				/**
				 * A member finished every lesson in a course.
				 *
				 * @param int $course_id The course.
				 * @param int $user_id   The member.
				 */
				do_action( 'membuild_community/course_completed', (int) $lesson['course_id'], $me );
			}
		}
		return $progress;
	}

	public static function uncomplete( \WP_REST_Request $req ) {
		global $wpdb;
		$lesson = self::lesson_row( (int) $req['id'] );
		if ( ! $lesson ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Lesson not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$removed = $wpdb->delete( Install::table( 'lesson_progress' ), array( 'user_id' => get_current_user_id(), 'lesson_id' => $lesson['id'] ) );
		if ( $removed ) {
			/**
			 * A member marked a lesson as not done.
			 *
			 * @param int $lesson_id The lesson.
			 * @param int $user_id   The member.
			 * @param int $course_id Its course.
			 */
			do_action( 'membuild_community/lesson_uncompleted', (int) $lesson['id'], get_current_user_id(), (int) $lesson['course_id'] );
		}
		return self::progress( (int) $lesson['course_id'] );
	}

	/* ---------- Admin: courses ---------- */

	public static function create_course( \WP_REST_Request $req ) {
		global $wpdb;
		$title = sanitize_text_field( $req['title'] ?? '' ) ?: __( 'Untitled course', 'membuild-community' );
		$now   = Helpers::now();
		$max   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort_order) FROM %i', Install::table( 'courses' ) ) );
		$wpdb->insert(
			Install::table( 'courses' ),
			array(
				'title'       => $title,
				'description' => Helpers::clean_text( $req['description'] ?? '', 1000 ),
				'status'      => 'draft',
				'sort_order'  => $max + 1,
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$course_id = (int) $wpdb->insert_id;
		// Every course starts with one module, so a first lesson can be added right away.
		$wpdb->insert( Install::table( 'modules' ), array( 'course_id' => $course_id, 'title' => __( 'Module 1', 'membuild-community' ), 'sort_order' => 0 ) );
		$response = rest_ensure_response( self::outline( self::course_row( $course_id ) ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function update_course( \WP_REST_Request $req ) {
		global $wpdb;
		$course = self::course_row( (int) $req['id'] );
		if ( ! $course ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Course not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$data = array( 'updated_at' => Helpers::now() );
		if ( isset( $req['title'] ) ) {
			$data['title'] = sanitize_text_field( $req['title'] ) ?: $course['title'];
		}
		if ( isset( $req['description'] ) ) {
			$data['description'] = Helpers::clean_text( $req['description'], 1000 );
		}
		if ( isset( $req['unlock_level'] ) ) {
			$data['unlock_level'] = max( 0, min( count( Points::thresholds() ), (int) $req['unlock_level'] ) );
		}
		$data = array_merge( $data, Access::course_update( $req->get_params(), $course ) );
		if ( isset( $req['status'] ) && in_array( $req['status'], array( 'draft', 'published' ), true ) ) {
			$data['status'] = $req['status'];
		}
		if ( array_key_exists( 'cover_media_id', $req->get_params() ) ) {
			$media             = $req['cover_media_id'] ? self::claim_media( (int) $req['cover_media_id'], array( 'image' ) ) : null;
			$data['cover_url'] = $media['url'] ?? null;
		}
		$wpdb->update( Install::table( 'courses' ), $data, array( 'id' => $course['id'] ) );
		return self::outline( self::course_row( (int) $course['id'] ) );
	}

	public static function delete_course( \WP_REST_Request $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		Discussions::remove_for( 'course_id', array( $id ) );
		foreach ( array( 'lesson_progress' => 'course_id', 'lessons' => 'course_id', 'modules' => 'course_id', 'courses' => 'id' ) as $table => $col ) {
			$wpdb->delete( Install::table( $table ), array( $col => $id ) );
		}
		return array( 'deleted' => true );
	}

	/** Reorders modules and lessons (and moves lessons between modules) in one call. */
	public static function update_structure( \WP_REST_Request $req ) {
		global $wpdb;
		$course = self::course_row( (int) $req['id'] );
		if ( ! $course ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Course not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$module_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE course_id = %d', Install::table( 'modules' ), $course['id'] ) ) );
		foreach ( array_values( (array) $req['modules'] ) as $m_order => $module ) {
			$mid = (int) ( $module['id'] ?? 0 );
			if ( ! in_array( $mid, $module_ids, true ) ) {
				continue;
			}
			$wpdb->update( Install::table( 'modules' ), array( 'sort_order' => $m_order ), array( 'id' => $mid ) );
			foreach ( array_values( (array) ( $module['lessons'] ?? array() ) ) as $l_order => $lesson_id ) {
				$wpdb->update(
					Install::table( 'lessons' ),
					array( 'module_id' => $mid, 'sort_order' => $l_order ),
					array( 'id' => (int) $lesson_id, 'course_id' => $course['id'] )
				);
			}
		}
		return self::outline( $course );
	}

	/* ---------- Admin: modules ---------- */

	public static function create_module( \WP_REST_Request $req ) {
		global $wpdb;
		$course = self::course_row( (int) $req['id'] );
		if ( ! $course ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Course not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort_order) FROM %i WHERE course_id = %d', Install::table( 'modules' ), $course['id'] ) );
		$wpdb->insert(
			Install::table( 'modules' ),
			array( 'course_id' => $course['id'], 'title' => sanitize_text_field( $req['title'] ?? '' ) ?: __( 'New module', 'membuild-community' ), 'sort_order' => $max + 1 )
		);
		return self::outline( $course );
	}

	public static function update_module( \WP_REST_Request $req ) {
		global $wpdb;
		$module = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'modules' ), (int) $req['id'] ), ARRAY_A );
		if ( ! $module ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Module not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$wpdb->update( Install::table( 'modules' ), array( 'title' => sanitize_text_field( $req['title'] ?? '' ) ?: $module['title'] ), array( 'id' => $module['id'] ) );
		return self::outline( self::course_row( (int) $module['course_id'] ) );
	}

	public static function delete_module( \WP_REST_Request $req ) {
		global $wpdb;
		$module = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'modules' ), (int) $req['id'] ), ARRAY_A );
		if ( ! $module ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Module not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$lesson_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE module_id = %d', Install::table( 'lessons' ), $module['id'] ) ) );
		if ( $lesson_ids ) {
			Discussions::remove_for( 'lesson_id', $lesson_ids );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE lesson_id IN (' . implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'lesson_progress' ) ), $lesson_ids ) ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'lessons' ) ), $lesson_ids ) ) );
		}
		$wpdb->delete( Install::table( 'modules' ), array( 'id' => $module['id'] ) );
		return self::outline( self::course_row( (int) $module['course_id'] ) );
	}

	/* ---------- Admin: lessons ---------- */

	public static function create_lesson( \WP_REST_Request $req ) {
		global $wpdb;
		$module = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'modules' ), (int) $req['id'] ), ARRAY_A );
		if ( ! $module ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Module not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sort_order) FROM %i WHERE module_id = %d', Install::table( 'lessons' ), $module['id'] ) );
		$now = Helpers::now();
		$wpdb->insert(
			Install::table( 'lessons' ),
			array(
				'course_id'  => $module['course_id'],
				'module_id'  => $module['id'],
				'title'      => sanitize_text_field( $req['title'] ?? '' ) ?: __( 'New lesson', 'membuild-community' ),
				'sort_order' => null === $max ? 0 : (int) $max + 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$lesson   = self::lesson_row( (int) $wpdb->insert_id );
		$response = rest_ensure_response( self::format_lesson( $lesson, self::course_row( (int) $module['course_id'] ) ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function update_lesson( \WP_REST_Request $req ) {
		global $wpdb;
		$lesson = self::lesson_row( (int) $req['id'] );
		if ( ! $lesson ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Lesson not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$params = $req->get_params();
		$data   = array( 'updated_at' => Helpers::now() );
		if ( isset( $params['title'] ) ) {
			$data['title'] = sanitize_text_field( $params['title'] ) ?: $lesson['title'];
		}
		if ( isset( $params['content'] ) ) {
			// Stored as plain text with light markdown; rendered safely in the app.
			$data['content'] = Helpers::clean_text( $params['content'], 50000 );
		}
		if ( isset( $params['drip_days'] ) ) {
			$data['drip_days'] = max( 0, min( 3650, (int) $params['drip_days'] ) );
		}
		if ( array_key_exists( 'media_id', $params ) ) {
			$media            = $params['media_id'] ? self::claim_media( (int) $params['media_id'], array( 'video', 'embed' ) ) : null;
			$data['media_id'] = $media['id'] ?? null;
			if ( $media ) {
				Files::protect( (int) $media['id'], 'lesson', (int) $lesson['id'] ); // only members who can open the lesson can play it
			}
		}
		if ( isset( $params['resources'] ) ) {
			$resources = array();
			foreach ( array_slice( (array) $params['resources'], 0, 20 ) as $res ) {
				$media = self::claim_media( (int) ( $res['media_id'] ?? 0 ), array( 'file', 'image' ) );
				if ( $media ) {
					$name        = sanitize_file_name( $res['name'] ?? '' ) ?: basename( wp_parse_url( $media['url'], PHP_URL_PATH ) );
					$resources[] = array( 'media_id' => (int) $media['id'], 'name' => $name, 'url' => Files::protect( (int) $media['id'], 'lesson', (int) $lesson['id'] ), 'mime' => $media['mime'] );
				}
			}
			$data['resources'] = wp_json_encode( $resources );
		}
		$wpdb->update( Install::table( 'lessons' ), $data, array( 'id' => $lesson['id'] ) );
		return self::format_lesson( self::lesson_row( (int) $lesson['id'] ), self::course_row( (int) $lesson['course_id'] ) );
	}

	public static function delete_lesson( \WP_REST_Request $req ) {
		global $wpdb;
		$lesson = self::lesson_row( (int) $req['id'] );
		if ( ! $lesson ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Lesson not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		Discussions::remove_for( 'lesson_id', array( (int) $lesson['id'] ) );
		$wpdb->delete( Install::table( 'lesson_progress' ), array( 'lesson_id' => $lesson['id'] ) );
		$wpdb->delete( Install::table( 'lessons' ), array( 'id' => $lesson['id'] ) );
		return self::outline( self::course_row( (int) $lesson['course_id'] ) );
	}

	/* ---------- Helpers ---------- */

	/**
	 * Whether the current member may open a lesson right now: the course is
	 * visible to them, not locked (level or group) and the lesson isn't dripped.
	 * Shared with Files, so lesson videos and downloads follow the same rule.
	 */
	public static function can_open_lesson( $lesson_id ) {
		$lesson = self::lesson_row( (int) $lesson_id );
		$course = $lesson ? self::course_row( (int) $lesson['course_id'] ) : null;
		return $lesson && $course && ! Access::hidden( $course ) && ! Access::lock( $course ) && ! self::unlock_at( (int) $lesson['drip_days'] );
	}


	/** Whether the current member may open a course: visible to them and not locked. */
	public static function can_open_course( $course_id ) {
		$course = self::course_row( (int) $course_id );
		return $course && ! Access::hidden( $course ) && ! Access::lock( $course );
	}

	/** Published courses for everyone; drafts only for admins. */
	private static function course_row( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'courses' ), $id ), ARRAY_A );
		return $row && ( 'published' === $row['status'] || Helpers::can_manage() ) ? $row : null;
	}

	private static function lesson_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'lessons' ), $id ), ARRAY_A );
	}

	/**
	 * When a dripped lesson unlocks for the current member, or null if it is
	 * already open. Counted from the day they joined; admins see everything.
	 */
	public static function unlock_at( $drip_days ) {
		if ( $drip_days <= 0 || Helpers::can_manage() ) {
			return null;
		}
		$joined = strtotime( wp_get_current_user()->user_registered . ' UTC' );
		$unlock = $joined + $drip_days * DAY_IN_SECONDS;
		return $unlock > time() ? $unlock : null;
	}

	/** Uses an upload the admin made (image, video, embed or file) and marks it as taken. */
	private static function claim_media( $media_id, array $kinds ) {
		global $wpdb;
		$media = Media::get( $media_id );
		// Never another chat's photo: those stay with the people in the chat.
		if ( ! $media || 'message' === $media['scope'] || ! in_array( $media['kind'], $kinds, true ) || ( null !== $media['post_id'] && 0 !== (int) $media['post_id'] ) ) {
			return null;
		}
		$wpdb->update( Install::table( 'media' ), array( 'post_id' => 0 ), array( 'id' => $media['id'] ) );
		return $media;
	}

	private static function progress( $course_id ) {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $course_id ) );
		$done  = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d AND user_id = %d', Install::table( 'lesson_progress' ), $course_id, get_current_user_id() )
		);
		$done  = min( $total, $done );
		return array(
			'course_id'       => $course_id,
			'lessons_count'   => $total,
			'completed_count' => $done,
			'progress'        => $total ? (int) round( 100 * $done / $total ) : 0,
		);
	}

	private static function format_course( $row ) {
		$lock = Access::lock( $row );
		if ( $lock && 'level' === $lock['reason'] ) {
			$lock['points_to_go'] = max( 0, ( Points::thresholds()[ $lock['level'] - 1 ] ?? 0 ) - (int) ( Points::stats_for( array( get_current_user_id() ) )[ get_current_user_id() ]['points'] ?? 0 ) );
		}
		if ( $lock && ! empty( $row['access_cta_url'] ) ) {
			$lock['cta'] = array( 'label' => $row['access_cta_label'] ?: __( 'Get access', 'membuild-community' ), 'url' => $row['access_cta_url'] );
		}
		$out  = array(
			'id'           => (int) $row['id'],
			'title'        => $row['title'],
			'description'  => $row['description'],
			'cover'        => $row['cover_url'],
			'status'       => $row['status'],
			'access'       => $row['access'] ?? 'everyone',
			'unlock_level' => (int) ( $row['unlock_level'] ?? 0 ),
			'lock'         => $lock,
			'locked_level' => $lock && 'level' === $lock['reason'] ? $lock['level'] : 0,
		);
		if ( Helpers::can_manage() ) {
			$groups               = Access::groups();
			$out['access_groups'] = Access::course_groups( $row );
			$out['group_names']   = array_map( fn( $id ) => $groups[ $id ]['name'], $out['access_groups'] );
			$out['hide_locked']   = ! empty( $row['hide_locked'] );
			$out['access_cta']    = array( 'label' => (string) $row['access_cta_label'], 'url' => (string) $row['access_cta_url'] );
		}
		return $out;
	}

	/** Course with its modules and lessons: titles, done and locked state. Three queries. */
	private static function outline( $course ) {
		global $wpdb;
		$modules = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE course_id = %d ORDER BY sort_order ASC, id ASC', Install::table( 'modules' ), $course['id'] ), ARRAY_A );
		$lessons = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.module_id, l.title, l.media_id, l.drip_days, l.resources, CHAR_LENGTH(l.content) AS chars, md.duration, md.meta
				FROM %i l LEFT JOIN %i md ON md.id = l.media_id WHERE l.course_id = %d ORDER BY l.sort_order ASC, l.id ASC',
				Install::table( 'lessons' ),
				Install::table( 'media' ),
				$course['id']
			),
			ARRAY_A
		);
		$talk    = $wpdb->get_results( $wpdb->prepare( "SELECT lesson_id, COUNT(*) n FROM %i WHERE course_id = %d AND status = 'published' GROUP BY lesson_id", Install::table( 'posts' ), $course['id'] ), OBJECT_K );
		$done    = array_flip(
			array_map(
				'intval',
				$wpdb->get_col( $wpdb->prepare( 'SELECT lesson_id FROM %i WHERE user_id = %d AND course_id = %d', Install::table( 'lesson_progress' ), get_current_user_id(), $course['id'] ) )
			)
		);
		$by_module    = array();
		$level_locked = (bool) Access::lock( $course );
		$files_total  = 0;
		foreach ( $lessons as $l ) {
			$unlock                               = self::unlock_at( (int) $l['drip_days'] );
			$files                                = count( json_decode( (string) $l['resources'], true ) ?: array() );
			$files_total                         += $files;
			$by_module[ (int) $l['module_id'] ][] = array(
				'id'        => (int) $l['id'],
				'title'     => $l['title'],
				'completed' => isset( $done[ (int) $l['id'] ] ),
				'locked'    => $level_locked || (bool) $unlock,
				'unlock_at' => $unlock ? gmdate( 'c', $unlock ) : null,
				'has_video' => (bool) $l['media_id'],
				'drip_days' => (int) $l['drip_days'],
				'minutes'   => self::minutes( (int) $l['chars'], $l['media_id'] ? $l : null ),
				'files'     => $files,
				'posts'     => (int) ( $talk[ $l['id'] ]->n ?? 0 ),
			);
		}
		$life  = self::activity( array( (int) $course['id'] ) )[ (int) $course['id'] ];
		$total = count( $lessons );
		$count = min( $total, count( array_intersect_key( $done, array_flip( array_map( 'intval', wp_list_pluck( $lessons, 'id' ) ) ) ) ) );
		return self::format_course( $course ) + array(
			'lessons_count'   => $total,
			'completed_count' => $count,
			'progress'        => $total ? (int) round( 100 * $count / $total ) : 0,
			'posts_count'     => array_sum( array_map( fn( $r ) => (int) $r->n, $talk ) ),
			'files_count'     => $files_total,
			// For the course overview: who teaches it and who's taking it.
			'instructor'      => Helpers::users( array( (int) $course['created_by'] ) )[ (int) $course['created_by'] ] ?? null,
			'learners'        => $life['learners'],
			'faces'           => $life['faces'],
			'can_manage'      => Helpers::can_manage(),
			'modules'         => array_map(
				fn( $m ) => array( 'id' => (int) $m['id'], 'title' => $m['title'], 'lessons' => $by_module[ (int) $m['id'] ] ?? array() ),
				$modules
			),
		);
	}

	private static function format_lesson( $lesson, $course ) {
		global $wpdb;
		// Previous / next follow the outline order across modules.
		$order = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT l.id FROM %i l JOIN %i m ON m.id = l.module_id
				WHERE l.course_id = %d ORDER BY m.sort_order ASC, m.id ASC, l.sort_order ASC, l.id ASC',
				Install::table( 'lessons' ),
				Install::table( 'modules' ),
				$course['id']
			)
		);
		$pos       = array_search( (string) $lesson['id'], $order, true );
		$completed = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d AND lesson_id = %d', Install::table( 'lesson_progress' ), get_current_user_id(), $lesson['id'] )
		);
		$media     = $lesson['media_id'] ? Media::get( (int) $lesson['media_id'] ) : null;
		return array(
			'id'           => (int) $lesson['id'],
			'course_id'    => (int) $lesson['course_id'],
			'module_id'    => (int) $lesson['module_id'],
			'module_title' => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM %i WHERE id = %d', Install::table( 'modules' ), $lesson['module_id'] ) ),
			'number'       => false !== $pos ? $pos + 1 : null, // "Lesson 3 of 12"
			'total'        => count( $order ),
			'minutes'      => self::minutes( mb_strlen( (string) $lesson['content'] ), $media ),
			'title'        => $lesson['title'],
			'content'      => (string) $lesson['content'],
			'media'        => $media ? Media::format( $media ) : null,
			'resources'    => self::files( json_decode( (string) $lesson['resources'], true ) ?: array(), true ),
			'drip_days'    => (int) $lesson['drip_days'],
			'completed'    => $completed,
			'prev_id'   => false !== $pos && $pos > 0 ? (int) $order[ $pos - 1 ] : null,
			'next_id'      => false !== $pos && $pos < count( $order ) - 1 ? (int) $order[ $pos + 1 ] : null,
		);
	}

	/**
	 * About how long a lesson takes, in minutes: its video plus reading at
	 * roughly 200 words a minute. Null when it can't be told (a video whose
	 * length isn't known, such as some embeds) or the lesson is empty.
	 *
	 * @param int        $chars Characters of lesson text.
	 * @param array|null $media The lesson's media row (duration, meta), if it has one.
	 */
	private static function minutes( $chars, $media ) {
		$seconds = 0.0;
		if ( $media ) {
			$seconds = (float) ( $media['duration'] ?? 0 ) ?: (float) ( json_decode( (string) ( $media['meta'] ?? '' ), true )['duration'] ?? 0 );
			if ( $seconds <= 0 ) {
				return null;
			}
		}
		$total = $seconds + $chars / 6 / 200 * 60; // about six characters a word
		return $total > 0 ? max( 1, (int) round( $total / 60 ) ) : null;
	}

	/**
	 * A lesson's files as the app shows them: name, type, size and the link
	 * (left out when the member can't open the lesson).
	 */
	private static function files( array $resources, $open, $bytes = null ) {
		$bytes = $bytes ?? self::sizes( $resources );
		return array_map(
			fn( $r ) => array(
				'media_id' => (int) $r['media_id'],
				'name'     => (string) $r['name'],
				'url'      => $open ? (string) $r['url'] : null,
				'mime'     => (string) ( $r['mime'] ?? '' ),
				'ext'      => strtoupper( (string) pathinfo( (string) $r['name'], PATHINFO_EXTENSION ) ),
				'size'     => (int) ( $bytes[ (int) $r['media_id'] ] ?? 0 ) ?: null,
			),
			$resources
		);
	}

	/** File sizes by media ID, in one query. */
	private static function sizes( array $resources ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( fn( $r ) => (int) ( $r['media_id'] ?? 0 ), $resources ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		return array_map( 'intval', wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'SELECT id, bytes FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'media' ) ), $ids ) ), OBJECT_K ), 'bytes' ) );
	}

	/**
	 * Each course's journey for the current member: the next lesson to take
	 * (the first not done and not dripping, in outline order) and when they
	 * last finished one. Two queries for the whole grid.
	 *
	 * @param int[] $ids Course IDs.
	 * @return array<int,array{next?:array,last?:string}>
	 */
	private static function journeys( array $ids ) {
		global $wpdb;
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$lessons = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %d placeholders; values are bound.
			$wpdb->prepare( "SELECT l.id, l.course_id, l.title, l.drip_days FROM %i l JOIN %i m ON m.id = l.module_id WHERE l.course_id IN ({$in}) ORDER BY l.course_id ASC, m.sort_order ASC, m.id ASC, l.sort_order ASC, l.id ASC", array_merge( array( Install::table( 'lessons' ), Install::table( 'modules' ) ), $ids ) ),
			ARRAY_A
		);
		$done    = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %d placeholders; values are bound.
			$wpdb->prepare( "SELECT lesson_id, course_id, completed_at FROM %i WHERE user_id = %d AND course_id IN ({$in})", array_merge( array( Install::table( 'lesson_progress' ), get_current_user_id() ), $ids ) ),
			ARRAY_A
		);
		$out      = array();
		$finished = array();
		foreach ( $done as $d ) {
			$finished[ (int) $d['lesson_id'] ] = true;
			$c                                 = (int) $d['course_id'];
			if ( ! isset( $out[ $c ]['last'] ) || $d['completed_at'] > $out[ $c ]['last'] ) {
				$out[ $c ]['last'] = $d['completed_at'];
			}
		}
		foreach ( $out as $c => $o ) {
			$out[ $c ]['last'] = Helpers::iso( $o['last'] );
		}
		$number = array();
		foreach ( $lessons as $l ) {
			$c              = (int) $l['course_id'];
			$number[ $c ]   = ( $number[ $c ] ?? 0 ) + 1;
			if ( ! isset( $out[ $c ]['next'] ) && ! isset( $finished[ (int) $l['id'] ] ) && ! self::unlock_at( (int) $l['drip_days'] ) ) {
				$out[ $c ]['next'] = array( 'id' => (int) $l['id'], 'title' => $l['title'], 'number' => $number[ $c ] );
			}
		}
		return $out;
	}

	/**
	 * Signs of life on each course card: how many members are taking it, a few
	 * of the latest, and how many posts its discussion has. Counts only, so a
	 * locked course can show them too. Three queries for the whole grid.
	 *
	 * @param int[] $ids Course IDs.
	 * @return array<int,array>
	 */
	private static function activity( array $ids ) {
		global $wpdb;
		$in       = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$progress = Install::table( 'lesson_progress' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %d placeholders; values are bound.
		// Both read the course_user and course_done indexes, so they stay quick with many members.
		$learners = array_map( 'intval', wp_list_pluck( $wpdb->get_results( $wpdb->prepare( "SELECT course_id, COUNT(DISTINCT user_id) n FROM %i WHERE course_id IN ({$in}) GROUP BY course_id", array_merge( array( $progress ), $ids ) ), OBJECT_K ), 'n' ) );
		$talk = $wpdb->get_results( $wpdb->prepare( "SELECT course_id, COUNT(*) n, SUM(created_at >= %s) w FROM %i WHERE status = 'published' AND course_id IN ({$in}) GROUP BY course_id", array_merge( array( gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ), Install::table( 'posts' ) ), $ids ) ), OBJECT_K );
		// phpcs:enable
		// The faces: the members who finished a lesson most recently (not me, not anyone I blocked).
		$faces = array();
		foreach ( $ids as $id ) {
			$recent = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE course_id = %d AND user_id <> %d ORDER BY completed_at DESC LIMIT 40', $progress, $id, get_current_user_id() ) );
			foreach ( array_unique( array_map( 'intval', $recent ) ) as $u ) {
				if ( count( $faces[ $id ] ?? array() ) < 3 && ! Blocks::hides( $u ) ) {
					$faces[ $id ][] = $u;
				}
			}
		}
		$users = Helpers::users( array_merge( array(), ...array_values( $faces ) ) );
		$out   = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'learners'    => (int) ( $learners[ $id ] ?? 0 ),
				'faces'       => array_values( array_filter( array_map( fn( $u ) => $users[ $u ] ?? null, $faces[ $id ] ?? array() ) ) ),
				'posts_count' => (int) ( $talk[ $id ]->n ?? 0 ),
				'posts_week'  => (int) ( $talk[ $id ]->w ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * The Resources tab: every lesson's files, in outline order. A member who
	 * can't open a lesson yet (locked course, or dripping) sees the file names
	 * with a lock, without links.
	 */
	public static function resources( \WP_REST_Request $req ) {
		global $wpdb;
		$course = self::course_row( (int) $req['id'] );
		if ( ! $course || Access::hidden( $course ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Course not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$locked  = (bool) Access::lock( $course );
		$lessons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.title, l.drip_days, l.resources, m.title AS module_title FROM %i l JOIN %i m ON m.id = l.module_id
				WHERE l.course_id = %d AND l.resources IS NOT NULL AND l.resources NOT IN ('', '[]')
				ORDER BY m.sort_order ASC, m.id ASC, l.sort_order ASC, l.id ASC",
				Install::table( 'lessons' ),
				Install::table( 'modules' ),
				$course['id']
			),
			ARRAY_A
		);
		$items = array();
		$all   = array_merge( array(), ...array_map( fn( $l ) => json_decode( (string) $l['resources'], true ) ?: array(), $lessons ) );
		$bytes = self::sizes( $all );
		foreach ( $lessons as $l ) {
			$files = json_decode( (string) $l['resources'], true ) ?: array();
			if ( ! $files ) {
				continue;
			}
			$unlock  = $locked ? null : self::unlock_at( (int) $l['drip_days'] );
			$items[] = array(
				'lesson_id'    => (int) $l['id'],
				'lesson_title' => $l['title'],
				'module_title' => $l['module_title'],
				'locked'       => $locked || (bool) $unlock,
				'unlock_at'    => $unlock ? gmdate( 'c', $unlock ) : null,
				'files'        => self::files( $files, ! $locked && ! $unlock, $bytes ),
			);
		}
		return array( 'items' => $items );
	}
}
