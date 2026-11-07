<?php
/**
 * Import from LearnDash, read from its posts, post meta, user meta and activity table,
 * so it works with LearnDash switched off. Courses go into the Classroom; nothing else
 * (LearnDash has no posts or chats).
 *
 * LearnDash has three levels (section heading, lesson, topic) and the Classroom two
 * (section, lesson), so the owner picks where topics go (job 'shape'):
 *   sections  a lesson with topics becomes a section: the lesson itself first (when it has
 *             words or a video), then its topics. Lessons without topics stay together
 *             under their section heading.
 *   follow    section headings stay sections; each lesson is followed by its topics.
 *
 * Who can open a course: open and free courses stay open to everyone. A paid, subscribed
 * or closed course becomes a membership with the people enrolled in it directly, and each
 * LearnDash group becomes a membership with its people, opening its courses. Prices come
 * over as the membership's price text; payments stay with LearnDash.
 *
 * Left out: quizzes, assignments, certificates, prerequisites, seat limits and access that
 * runs out (Import_Check says so, with counts).
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import_Learndash extends Import_Source {

	public function label() {
		return 'LearnDash';
	}

	public function kind() {
		return 'courses';
	}

	public function detect() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE post_type = 'sfwd-courses' AND post_status IN ('publish', 'draft', 'private') LIMIT 1", $wpdb->posts ) );
	}

	public function active() {
		return defined( 'LEARNDASH_VERSION' );
	}

	public function last_activity() {
		global $wpdb;
		$t = $wpdb->prefix . 'learndash_user_activity';
		if ( Import::has_table( $t ) ) {
			$at = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(activity_updated) FROM %i', $t ) );
			if ( $at ) {
				return gmdate( 'Y-m-d H:i:s', $at );
			}
		}
		return $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_modified_gmt) FROM %i WHERE post_type IN ('sfwd-courses', 'sfwd-lessons', 'sfwd-topic')", $wpdb->posts ) );
	}

	/* ---------- Reading ---------- */

	private static function course_ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'sfwd-courses' AND post_status IN ('publish', 'draft', 'private') ORDER BY menu_order, ID", $wpdb->posts ) ) );
	}

	/** A course's settings without the "sfwd-courses_" in front. */
	private static function settings( $post_id, $type = 'sfwd-courses' ) {
		$raw = get_post_meta( $post_id, '_' . $type, true );
		$raw = is_string( $raw ) && is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : $raw; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are refused
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $k => $v ) {
			$out[ preg_replace( '/^' . preg_quote( $type, '/' ) . '_/', '', (string) $k ) ] = $v;
		}
		return $out;
	}

	/**
	 * The course outline: [ [heading or null, [ [lesson_id, [topic ids]] … ]] … ].
	 * From the course builder's saved tree when there is one, else from each lesson's course.
	 */
	public static function outline( $course_id ) {
		global $wpdb;
		$meta    = get_post_meta( $course_id, 'ld_course_steps', true );
		$meta    = is_array( $meta ) ? $meta : array();
		$tree    = $meta['steps']['h'] ?? ( $meta['h'] ?? null );
		$trusted = ! isset( $meta['course_id'] ) || (int) $meta['course_id'] === (int) $course_id; // copied by a clone plugin: not this course's
		$lessons = array();
		if ( is_array( $tree ) && $trusted && ! empty( $tree['sfwd-lessons'] ) ) {
			// The builder's list can still name lessons and topics that were trashed or deleted since.
			$live = fn( $id ) => in_array( get_post_status( (int) $id ), array( 'publish', 'draft' ), true );
			foreach ( (array) $tree['sfwd-lessons'] as $lid => $kids ) {
				if ( $live( $lid ) ) {
					$lessons[] = array( (int) $lid, array_values( array_filter( array_map( 'intval', array_keys( (array) ( $kids['sfwd-topic'] ?? array() ) ) ), $live ) ) );
				}
			}
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = 'course_id' AND m.meta_value = %d WHERE p.post_type = 'sfwd-lessons' AND p.post_status IN ('publish', 'draft') ORDER BY p.menu_order, p.ID", $wpdb->posts, $wpdb->postmeta, $course_id ) );
			foreach ( $ids as $lid ) {
				$topics    = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = 'lesson_id' AND m.meta_value = %d WHERE p.post_type = 'sfwd-topic' AND p.post_status IN ('publish', 'draft') ORDER BY p.menu_order, p.ID", $wpdb->posts, $wpdb->postmeta, $lid ) );
				$lessons[] = array( (int) $lid, array_map( 'intval', $topics ) );
			}
		}
		// Section headings sit between lessons, at their place in the list of lessons.
		$heads = json_decode( (string) get_post_meta( $course_id, 'course_sections', true ), true );
		$heads = array_values( array_filter( is_array( $heads ) ? $heads : array(), fn( $h ) => is_array( $h ) && isset( $h['order'] ) && '' !== trim( (string) ( $h['post_title'] ?? '' ) ) ) );
		usort( $heads, fn( $a, $b ) => (int) $a['order'] <=> (int) $b['order'] );
		$items = $lessons;
		foreach ( $heads as $h ) {
			array_splice( $items, max( 0, (int) $h['order'] ), 0, array( array( 'head' => wp_strip_all_tags( $h['post_title'] ) ) ) );
		}
		$out = array( array( null, array() ) );
		foreach ( $items as $it ) {
			if ( isset( $it['head'] ) ) {
				$out[] = array( $it['head'], array() );
			} else {
				$out[ count( $out ) - 1 ][1][] = $it;
			}
		}
		return array_values( array_filter( $out, fn( $s ) => $s[1] || null !== $s[0] ) );
	}

	/** Whether most of a course's lessons have topics. */
	private static function mostly_topics( array $outline ) {
		$all = array_merge( ...array_map( fn( $s ) => $s[1], $outline ?: array( array( null, array() ) ) ) );
		$with = count( array_filter( $all, fn( $l ) => $l[1] ) );
		return $all && $with * 2 >= count( $all );
	}

	/** The groups and which courses each opens: [gid => ['title', 'courses' => [], 'members' => []]]. */
	private static function groups_map() {
		global $wpdb;
		if ( null !== self::$groups ) {
			return self::$groups;
		}
		$map = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM %i WHERE post_type = 'groups' AND post_status = 'publish' ORDER BY ID", $wpdb->posts ), ARRAY_A ) as $g ) {
			$gid       = (int) $g['ID'];
			$map[ $gid ] = array(
				'title'   => html_entity_decode( $g['post_title'], ENT_QUOTES, 'UTF-8' ),
				'courses' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, 'learndash_group_enrolled_' . $gid ) ) ),
				'members' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE meta_key = %s AND ' . self::site_user( 'user_id' ), $wpdb->usermeta, 'learndash_group_users_' . $gid ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			);
		}
		self::$groups = $map;
		return $map;
	}

	private static function paid( array $s ) {
		$type = (string) ( $s['course_price_type'] ?? '' );
		if ( 'paynow' === $type && '' === trim( (string) ( $s['course_price'] ?? '' ) ) ) {
			return false; // "Buy now" with no price works as open
		}
		return in_array( $type, array( 'paynow', 'subscribe', 'closed' ), true );
	}

	/** When each student was given the course (LearnDash's access_from), so drip keeps their real start. */
	private static function started( $course_id ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, meta_value FROM %i WHERE meta_key = %s AND ' . self::site_user( 'user_id' ), $wpdb->usermeta, 'course_' . (int) $course_id . '_access_from' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			if ( (int) $r['meta_value'] > 0 ) {
				$out[ (int) $r['user_id'] ] = gmdate( 'Y-m-d H:i:s', (int) $r['meta_value'] );
			}
		}
		return $out;
	}

	private static function direct_students( $course_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE meta_key = %s AND ' . self::site_user( 'user_id' ), $wpdb->usermeta, 'course_' . (int) $course_id . '_access_from' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
	}

	/** Read once per request: the groups and the summary. */
	private static $groups = null;
	private static $memo   = null;

	/** Read LearnDash again (Import::forget_found). */
	public static function forget() {
		self::$groups = null;
		self::$memo   = null;
	}

	public function summary() {
		global $wpdb;
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$ids      = self::course_ids();
		$lessons  = 0;
		$topics   = 0;
		$mostly   = 0;
		$sample   = null;
		$paid     = 0;
		foreach ( $ids as $cid ) {
			$paid += self::paid( self::settings( $cid ) ) ? 1 : 0;
			$o     = self::outline( $cid );
			foreach ( $o as $s ) {
				$lessons += count( $s[1] );
				foreach ( $s[1] as $l ) {
					$topics += count( $l[1] );
				}
			}
			$mostly += self::mostly_topics( $o ) ? 1 : 0;
			if ( ! $sample && self::mostly_topics( $o ) ) {
				$sample = self::sample( $cid, $o );
			}
		}
		$t = $wpdb->prefix . 'learndash_user_activity';
		$done = Import::has_table( $t ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE activity_type IN ('lesson', 'topic') AND activity_status = 1", $t ) ) : 0;
		if ( ! $done ) {
			// Older sites keep progress only on each member: count it there (a few hundred rows at a time).
			$after = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, meta_value FROM %i WHERE meta_key = '_sfwd-course_progress' AND umeta_id > %d ORDER BY umeta_id LIMIT 500", $wpdb->usermeta, $after ), ARRAY_A );
				foreach ( $rows as $r ) {
					$after = (int) $r['umeta_id'];
					$p     = @unserialize( $r['meta_value'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are refused
					foreach ( is_array( $p ) ? $p : array() as $c ) {
						$done += count( array_filter( (array) ( $c['lessons'] ?? array() ) ) );
						foreach ( (array) ( $c['topics'] ?? array() ) as $tp ) {
							$done += count( array_filter( (array) $tp ) );
						}
					}
				}
			} while ( count( $rows ) === 500 );
		}
		$who  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE (meta_key LIKE %s OR meta_key LIKE %s OR meta_key = '_sfwd-course_progress') AND " . self::site_user( 'user_id' ), $wpdb->usermeta, 'course\_%\_access\_from', 'learndash\_group\_users\_%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
		self::$memo = array(
			'members'  => 0,
			'courses'  => count( $ids ),
			'lessons'  => $lessons + $topics,
			'sections' => $lessons,
			'topics'   => $topics,
			'students' => $who,
			'done'     => $done,
			'groups'   => count( self::groups_map() ),
			'paid'     => $paid,
			'open'     => count( $ids ) - $paid,
			'mostly'   => $mostly,
			'shape'    => $topics ? ( $mostly * 2 >= count( $ids ) ? 'sections' : 'follow' ) : '',
			'sample'   => $sample,
		);
		return self::$memo;
	}

	/** One course's first lesson with topics, for the "where should topics go" preview. */
	private static function sample( $cid, array $outline ) {
		foreach ( $outline as $s ) {
			foreach ( $s[1] as $l ) {
				if ( $l[1] ) {
					return array(
						'course'  => html_entity_decode( get_the_title( $cid ), ENT_QUOTES, 'UTF-8' ),
						'heading' => $s[0],
						'lesson'  => html_entity_decode( get_the_title( $l[0] ), ENT_QUOTES, 'UTF-8' ),
						'topics'  => array_map( fn( $t ) => html_entity_decode( get_the_title( $t ), ENT_QUOTES, 'UTF-8' ), array_slice( $l[1], 0, 3 ) ),
					);
				}
			}
		}
		return null;
	}

	public function totals( array $job ) {
		return array( 'courses' => count( self::course_ids() ) );
	}

	public function batch( $step, $after, $limit, array $job ) {
		if ( 'courses' !== $step ) {
			return array( array(), null );
		}
		foreach ( self::course_ids() as $cid ) {
			if ( $cid > (int) $after && ! Import::mapped( 'learndash', 'course', $cid ) ) {
				return array( array( $this->course( $cid, $job ) ), $cid );
			}
		}
		return array( array(), null );
	}

	/** One course in the shape Import::write_course takes. */
	private function course( $cid, array $job ) {
		global $wpdb;
		$post    = get_post( $cid );
		$s       = self::settings( $cid );
		$outline = self::outline( $cid );
		$shape   = in_array( $job['shape'] ?? '', array( 'sections', 'follow' ), true ) ? $job['shape'] : ( self::mostly_topics( $outline ) ? 'sections' : 'follow' );

		// Sections and lessons, in the order the owner chose.
		$modules = array();
		$lessons = array();
		$add_mod = function ( $title ) use ( &$modules ) {
			$modules[] = array( 'source_id' => 'm' . count( $modules ), 'title' => $title );
			return 'm' . ( count( $modules ) - 1 );
		};
		$fallback = __( 'Lessons', 'membuild-community' );
		foreach ( $outline as $sec ) {
			list( $head, $items ) = $sec;
			$current = null;
			foreach ( $items as $l ) {
				list( $lid, $topics ) = $l;
				if ( 'sections' === $shape && $topics ) {
					$m    = $add_mod( get_the_title( $lid ) );
					$self = self::lesson( $lid, $m, 'sfwd-lessons' );
					if ( '' !== trim( Import::text( $self['content'] ) ) || $self['video'] ) {
						$lessons[] = $self;
					}
					foreach ( $topics as $tid ) {
						$lessons[] = self::lesson( $tid, $m, 'sfwd-topic' );
					}
					$current = null; // the next lesson without topics starts a section of its own
					continue;
				}
				$current   = $current ?? $add_mod( $head ?? $fallback );
				$lessons[] = self::lesson( $lid, $current, 'sfwd-lessons' );
				foreach ( $topics as $tid ) {
					$lessons[] = self::lesson( $tid, $current, 'sfwd-topic' );
				}
			}
		}
		foreach ( $modules as &$m ) {
			$m['title'] = html_entity_decode( (string) $m['title'], ENT_QUOTES, 'UTF-8' );
		}
		unset( $m );

		// Who's in it, what they finished, and who finished it all.
		$ids      = array_column( $lessons, 'source_id' );
		$direct   = self::direct_students( $cid );
		$groups   = array_filter( self::groups_map(), fn( $g ) => in_array( $cid, $g['courses'], true ) );
		$done     = self::finished_steps( $cid, $ids );
		$students = $direct;
		foreach ( $groups as $g ) {
			$students = array_merge( $students, $g['members'] );
		}
		$students = array_values( array_unique( array_merge( $students, array_column( $done, 'user_id' ) ) ) );
		$finished = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, meta_value FROM %i WHERE meta_key = %s AND ' . self::site_user( 'user_id' ), $wpdb->usermeta, 'course_completed_' . $cid ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			$finished[] = array( 'user_id' => (int) $r['user_id'], 'at' => (int) $r['meta_value'] ? gmdate( 'Y-m-d H:i:s', (int) $r['meta_value'] ) : null );
		}

		// Who can open it: memberships for a paid course and for each group that opens it.
		$memberships = array();
		$paid        = self::paid( $s );
		if ( $paid && ( $direct || ! $groups ) ) {
			list( $price, $period ) = self::price( $s, 'course' );
			$memberships[]          = array(
				'key'     => 'course:' . $cid,
				'name'    => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
				'members' => $direct,
				'price'   => $price,
				'period'  => $period,
				'url'     => (string) ( $s['custom_button_url'] ?? '' ) ?: get_permalink( $cid ),
			);
		}
		foreach ( $groups as $gid => $g ) {
			list( $price, $period ) = self::price( self::settings( $gid, 'groups' ), 'group' );
			$memberships[]          = array( 'key' => 'group:' . $gid, 'name' => $g['title'], 'members' => $g['members'], 'price' => $price, 'period' => $period, 'url' => get_permalink( $gid ) );
		}

		$thumb = (int) get_post_thumbnail_id( $cid );
		return array(
			'source_id'   => $cid,
			'title'       => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'description' => Import::text( Import::tidy( $post->post_excerpt ?: $post->post_content ) ),
			'cover_url'   => $thumb ? (string) wp_get_attachment_url( $thumb ) : '',
			'published'   => 'publish' === $post->post_status,
			'private'     => false,
			'everyone'    => ! $paid,
			'memberships' => $memberships,
			'created_by'  => (int) $post->post_author,
			'created_at'  => $post->post_date_gmt,
			'modules'     => $modules,
			'lessons'     => $lessons,
			'students'    => $students,
			'started'     => self::started( $cid ),
			'done'        => $done,
			'finished'    => $finished,
		);
	}

	/** A price as the membership shows it ("49", "19.99") and how often. */
	private static function price( array $s, $prefix ) {
		$type  = (string) ( $s[ $prefix . '_price_type' ] ?? '' );
		$price = trim( (string) ( $s[ $prefix . '_price' ] ?? '' ) );
		if ( 'closed' === $type || '' === $price ) {
			return array( null, 'once' );
		}
		$period = 'once';
		if ( 'subscribe' === $type ) {
			$unit   = strtoupper( (string) ( $s[ $prefix . '_price_billing_t3' ] ?? '' ) );
			$every  = (int) ( $s[ $prefix . '_price_billing_p3' ] ?? 1 );
			$period = 1 === $every && 'M' === $unit ? 'month' : ( 1 === $every && 'Y' === $unit ? 'year' : ( 12 === $every && 'M' === $unit ? 'year' : 'once' ) );
		}
		return array( mb_substr( preg_replace( '/[^\d.,]/', '', $price ), 0, 20 ) ?: null, $period );
	}

	/** A lesson or topic: its words, its video and when it opens. */
	private static function lesson( $id, $module, $type ) {
		$p   = get_post( $id );
		$s   = self::settings( $id, $type );
		$box = 'on' === ( $s['lesson_video_enabled'] ?? '' ) ? (string) ( $s['lesson_video_url'] ?? '' ) : '';
		$url = Import::video_url( $box );
		$raw = $p ? (string) $p->post_content : '';
		$tidy = $url;
		if ( ! $url ) {
			$tidy = Import::video_url( $raw ); // a video put in the lesson's text
			// A video box that can't play here (a file, another host) still keeps its link: it goes at the end of the lesson.
			$url  = $tidy ?: Import::first_url( $box );
		}
		$open_on = null;
		$at      = $s['visible_after_specific_date'] ?? '';
		if ( $at ) {
			$ts      = is_numeric( $at ) ? (int) $at : strtotime( (string) $at );
			$open_on = $ts ? gmdate( 'Y-m-d', $ts ) : null;
		}
		return array(
			'source_id'  => (int) $id,
			'module'     => $module,
			'title'      => $p ? html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ) : '',
			'content'    => Import::tidy( $raw, $tidy ),
			'video'      => $url,
			'drip_days'  => $open_on ? 0 : max( 0, (int) ( $s['visible_after'] ?? 0 ) ),
			'open_on'    => $open_on,
			'created_at' => $p ? $p->post_date_gmt : null,
		);
	}

	/**
	 * Lessons and topics members finished in this course, with the day they finished
	 * them: from the activity table, and from the progress LearnDash keeps on each member
	 * (older sites) for any the table doesn't have.
	 */
	private static function finished_steps( $cid, array $ids ) {
		global $wpdb;
		$out  = array();
		$seen = array();
		$t    = $wpdb->prefix . 'learndash_user_activity';
		if ( Import::has_table( $t ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, post_id, activity_completed FROM %i WHERE course_id = %d AND activity_type IN ('lesson', 'topic') AND activity_status = 1 AND " . self::site_user( 'user_id' ), $t, $cid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			foreach ( $rows as $r ) {
				$k = $r['user_id'] . ':' . $r['post_id'];
				if ( in_array( (int) $r['post_id'], $ids, true ) && ! isset( $seen[ $k ] ) ) {
					$seen[ $k ] = 1;
					$out[]      = array( 'user_id' => (int) $r['user_id'], 'lesson' => (int) $r['post_id'], 'at' => (int) $r['activity_completed'] ? gmdate( 'Y-m-d H:i:s', (int) $r['activity_completed'] ) : null );
				}
			}
		}
		$like = '%' . $wpdb->esc_like( 'i:' . (int) $cid . ';' ) . '%';
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM %i WHERE meta_key = '_sfwd-course_progress' AND meta_value LIKE %s AND " . self::site_user( 'user_id' ), $wpdb->usermeta, $like ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			$p = @unserialize( $r['meta_value'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are refused
			$c = is_array( $p ) ? ( $p[ $cid ] ?? null ) : null;
			if ( ! is_array( $c ) ) {
				continue;
			}
			$steps = array_keys( array_filter( (array) ( $c['lessons'] ?? array() ) ) );
			foreach ( (array) ( $c['topics'] ?? array() ) as $topics ) {
				$steps = array_merge( $steps, array_keys( array_filter( (array) $topics ) ) );
			}
			foreach ( $steps as $sid ) {
				$k = $r['user_id'] . ':' . $sid;
				if ( in_array( (int) $sid, $ids, true ) && ! isset( $seen[ $k ] ) ) {
					$seen[ $k ] = 1;
					$out[]      = array( 'user_id' => (int) $r['user_id'], 'lesson' => (int) $sid, 'at' => null );
				}
			}
		}
		return $out;
	}

	/* ---------- The dry run ---------- */

	public function check( array $job ) {
		global $wpdb;
		$f     = $this->summary();
		$b     = $job['bring'];
		$items = array();
		$notes = array();
		$every = self::course_ids();
		$ids   = array_values( array_filter( $every, fn( $cid ) => ! Import::mapped( 'learndash', 'course', $cid ) ) ); // only what this run brings
		if ( $every && ! $ids ) {
			return Import_Check::nothing_new( count( $every ), 'LearnDash' );
		}
		$again = count( $ids ) < count( $every );
		$steps = 0;
		$paid  = 0;
		$drip  = array( 'days' => 0, 'date' => 0 );
		$video = array( 'text' => 0, 'other' => 0 );
		$codes = 0;
		$seen  = array();
		$share = 0;
		$open  = 0;
		$limit = 0;
		$drafts = 0;
		foreach ( $ids as $cid ) {
			$s = self::settings( $cid );
			self::paid( $s ) ? ++$paid : ++$open;
			$limit  += ( ! empty( $s['course_prerequisite_enabled'] ) && 'on' === $s['course_prerequisite_enabled'] ) || ! empty( $s['expire_access'] ) || ! empty( $s['course_seats_limit'] ) ? 1 : 0;
			$drafts += 'publish' === get_post_status( $cid ) ? 0 : 1;
			foreach ( self::outline( $cid ) as $sec ) {
				foreach ( $sec[1] as $l ) {
					foreach ( array_merge( array( $l[0] ), $l[1] ) as $i => $sid ) {
						$type = 0 === $i ? 'sfwd-lessons' : 'sfwd-topic';
						++$steps;
						if ( isset( $seen[ $sid ] ) ) {
							++$share;
						}
						$seen[ $sid ] = 1;
						$st           = self::settings( $sid, $type );
						$drip['date'] += ! empty( $st['visible_after_specific_date'] ) ? 1 : 0;
						$drip['days'] += empty( $st['visible_after_specific_date'] ) && (int) ( $st['visible_after'] ?? 0 ) > 0 ? 1 : 0;
						$raw           = (string) get_post_field( 'post_content', $sid );
						$boxed         = 'on' === ( $st['lesson_video_enabled'] ?? '' ) ? trim( (string) ( $st['lesson_video_url'] ?? '' ) ) : '';
						$intext        = '' === $boxed ? Import::video_url( $raw ) : '';
						if ( '' !== $boxed ) {
							$video['other'] += Import::plays( Import::video_url( $boxed ) ) ? 0 : 1; // a file, another host, or Bunny not set up here
						} elseif ( $intext ) {
							Import::plays( $intext ) ? ++$video['text'] : ++$video['other'];
						} elseif ( preg_match( '#<iframe|\[video|\[embed#i', $raw ) ) {
							++$video['other'];
						}
						$codes += preg_match( Import::SHORTCODES, $raw ) ? 1 : 0;
					}
				}
			}
		}
		$groups = self::groups_map();
		$count  = fn( $type ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = 'publish'", $wpdb->posts, $type ) );

		if ( in_array( 'courses', $b, true ) ) {
			$items[] = array(
				'title' => Import_Check::courses_line( count( $ids ), $again ),
				/* translators: %s: number of lessons and topics */
				'sub'   => sprintf( _n( '%s lesson or topic, with its words, video and cover picture.', '%s lessons and topics, with their words, videos and cover pictures.', $steps, 'membuild-community' ), Import_Check::n( $steps ) ) . ( $drafts ? ' ' . sprintf( _n( '%s is a draft and stays a draft.', '%s are drafts and stay drafts.', $drafts, 'membuild-community' ), Import_Check::n( $drafts ) ) : '' ),
				'tag'   => 'ready',
			);
		}
		if ( in_array( 'progress', $b, true ) ) {
			$items[] = array(
				/* translators: 1: students, 2: finished lessons */
				'title' => $again ? __( 'Students and their progress in the new courses', 'membuild-community' ) : sprintf( __( '%1$s students and %2$s finished lessons', 'membuild-community' ), Import_Check::n( $f['students'] ), Import_Check::n( $f['done'] ) ),
				'sub'   => __( 'Matched by their account, so nothing is doubled. Who finished a whole course stays finished.', 'membuild-community' ),
				'tag'   => 'ready',
			);
		}
		if ( in_array( 'access', $b, true ) ) {
			if ( $open ) {
				/* translators: %s: number of courses */
				$items[] = array( 'title' => sprintf( _n( '%s open or free course stays open to everyone', '%s open and free courses stay open to everyone', $open, 'membuild-community' ), Import_Check::n( $open ) ), 'sub' => __( 'Free courses in LearnDash still asked students to enroll. Here, every member can open them.', 'membuild-community' ), 'tag' => 'ready' );
			}
			if ( $paid || $groups ) {
				$what    = Import_Check::join(
					array(
						/* translators: %s: number of courses */
						$paid ? sprintf( _n( '%s paid course', '%s paid courses', $paid, 'membuild-community' ), Import_Check::n( $paid ) ) : '',
						/* translators: %s: number of LearnDash groups */
						$groups ? sprintf( _n( '%s group', '%s groups', count( $groups ), 'membuild-community' ), Import_Check::n( count( $groups ) ) ) : '',
					)
				);
				/* translators: %s: e.g. "5 paid courses and 3 groups" */
				$items[] = array( 'title' => sprintf( __( '%s become memberships', 'membuild-community' ), $what ), 'sub' => __( 'Each with the same people in it, and named after the course or group. Prices come over as text on the Get access button.', 'membuild-community' ), 'tag' => 'ready' );
			}
		} elseif ( $paid ) {
			/* translators: %s: number of courses */
			$notes[] = array( 'title' => sprintf( _n( '%s paid course opens only for admins for now', '%s paid courses open only for admins for now', $paid, 'membuild-community' ), Import_Check::n( $paid ) ), 'sub' => __( 'You didn’t tick who can open each course. Choose in each course’s settings.', 'membuild-community' ), 'tag' => 'your_turn' );
		}
		if ( in_array( 'timing', $b, true ) && array_sum( $drip ) ) {
			/* translators: %s: number of lessons */
			$when    = array(
				/* translators: %s: number of lessons */
				$drip['days'] ? sprintf( _n( '%s opens some days after a student starts', '%s open some days after a student starts', $drip['days'], 'membuild-community' ), Import_Check::n( $drip['days'] ) ) : '',
				/* translators: %s: number of lessons */
				$drip['date'] ? sprintf( _n( '%s opens on a set date', '%s open on a set date', $drip['date'], 'membuild-community' ), Import_Check::n( $drip['date'] ) ) : '',
			);
			$items[] = array( 'title' => sprintf( _n( '%s lesson keeps its timing', '%s lessons keep their timing', array_sum( $drip ), 'membuild-community' ), Import_Check::n( array_sum( $drip ) ) ), 'sub' => ucfirst( implode( '; ', array_filter( $when ) ) ) . '.', 'tag' => 'ready' );
		}

		if ( in_array( 'access', $b, true ) && ( $paid || $groups ) ) {
			$notes[] = array( 'title' => __( 'Payments don’t move', 'membuild-community' ), 'sub' => __( 'Students who pay LearnDash today keep paying it. Choose how people buy each new membership in Memberships before you switch LearnDash off.', 'membuild-community' ), 'tag' => 'your_turn' );
		}
		$left = array_filter( array( 'quizzes' => $count( 'sfwd-quiz' ), 'assignments' => $count( 'sfwd-assignment' ), 'certificates' => $count( 'sfwd-certificates' ) ) );
		if ( $left ) {
			$what    = Import_Check::join(
				array(
					/* translators: %s: number */
					isset( $left['quizzes'] ) ? sprintf( _n( '%s quiz', '%s quizzes', $left['quizzes'], 'membuild-community' ), Import_Check::n( $left['quizzes'] ) ) : '',
					/* translators: %s: number */
					isset( $left['assignments'] ) ? sprintf( _n( '%s assignment', '%s assignments', $left['assignments'], 'membuild-community' ), Import_Check::n( $left['assignments'] ) ) : '',
					/* translators: %s: number */
					isset( $left['certificates'] ) ? sprintf( _n( '%s certificate', '%s certificates', $left['certificates'], 'membuild-community' ), Import_Check::n( $left['certificates'] ) ) : '',
				)
			);
			$notes[] = array(
				/* translators: %s: e.g. "23 quizzes, 6 assignments and 2 certificates" */
				'title' => sprintf( _n( '%s stays in LearnDash', '%s stay in LearnDash', array_sum( $left ), 'membuild-community' ), $what ),
				'sub'   => __( 'The Classroom has no quizzes. Students who finished a course still show it as finished, even when a quiz was the last step.', 'membuild-community' ),
				'tag'   => 'left_out',
			);
		}
		if ( $video['text'] ) {
			/* translators: %s: number of videos */
			$notes[] = array( 'title' => sprintf( _n( '%s video is in the lesson text, not the video box', '%s videos are in the lesson text, not the video box', $video['text'], 'membuild-community' ), Import_Check::n( $video['text'] ) ), 'sub' => __( 'YouTube, Vimeo and your own Bunny links are found and play as the lesson’s video.', 'membuild-community' ), 'tag' => 'linked' );
		}
		if ( $video['other'] ) {
			/* translators: %s: number of videos */
			$notes[] = array( 'title' => sprintf( _n( '%s video from somewhere else', '%s videos from somewhere else', $video['other'], 'membuild-community' ), Import_Check::n( $video['other'] ) ), 'sub' => __( 'Only YouTube, Vimeo and your own Bunny videos play as a lesson’s video. Their links stay at the end of each lesson, so you can add them again.', 'membuild-community' ), 'tag' => 'your_turn' );
		}
		if ( $codes ) {
			/* translators: %s: number of lessons */
			$notes[] = array( 'title' => sprintf( _n( 'LearnDash shortcodes in %s lesson', 'LearnDash shortcodes in %s lessons', $codes, 'membuild-community' ), Import_Check::n( $codes ) ), 'sub' => __( 'Things like [ld_video] only work with LearnDash, so they’re taken out. The words around them stay.', 'membuild-community' ), 'tag' => 'tidied' );
		}
		if ( $share ) {
			/* translators: %s: number of lessons */
			$notes[] = array( 'title' => sprintf( _n( '%s lesson is in more than one course', '%s lessons are in more than one course', $share, 'membuild-community' ), Import_Check::n( $share ) ), 'sub' => __( 'Each course gets its own copy, so you can change one without the other.', 'membuild-community' ), 'tag' => 'copied' );
		}
		if ( $limit ) {
			$notes[] = array( 'title' => __( 'Prerequisites, seat limits and access that runs out', 'membuild-community' ), 'sub' => __( 'There’s no match in the Classroom yet. Students keep access to what they have.', 'membuild-community' ), 'tag' => 'left_out' );
		}
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = 'learndash' AND kind = 'course'", Install::table( 'import_map' ) ) );
		if ( $before ) {
			/* translators: %s: number of courses */
			$notes[] = array( 'title' => __( 'Imported before', 'membuild-community' ), 'sub' => sprintf( _n( '%s course is already here from last time. Only new courses come over.', '%s courses are already here from last time. Only new courses come over.', $before, 'membuild-community' ), Import_Check::n( $before ) ), 'tag' => 'merged' );
		}
		return array( 'items' => $items, 'notes' => $notes );
	}
}
