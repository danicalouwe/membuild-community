<?php
/**
 * Import from Tutor LMS (free and Pro), read from its posts, post meta, user meta,
 * comments and Pro's subscription tables, so it works with Tutor switched off.
 *
 * Tutor's topics become sections and its lessons stay lessons, in Tutor's order, with
 * their words, video and files. Students come from enrollments that went through
 * (unpaid and cancelled ones are left out); finished lessons from each member's meta;
 * finished courses from Tutor's course_completed comments.
 *
 * Who can open a course: free and public courses stay open to everyone. A paid or
 * subscription course becomes a membership with the people enrolled in it now. A Tutor
 * Pro plan for the whole site, or for categories, becomes a membership with its active
 * subscribers, opening every paid course it covers (an "All courses" plan opens them
 * all, even ones a subscriber never opened in Tutor). Payments stay with Tutor.
 *
 * Left out: quizzes, assignments, live sessions (Zoom, Google Meet), certificates,
 * bundles, and lessons that open one after another or after other lessons.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import_Tutor extends Import_Source {

	const SKIP = "('tutor_quiz', 'tutor_assignments', 'tutor_zoom_meeting', 'tutor-google-meet')";

	public function label() {
		return 'Tutor LMS';
	}

	public function kind() {
		return 'courses';
	}

	public function detect() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE post_type = 'courses' AND post_status IN ('publish', 'draft', 'private') LIMIT 1", $wpdb->posts ) )
			&& (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE post_type IN ('topics', 'tutor_enrolled') LIMIT 1", $wpdb->posts ) );
	}

	public function active() {
		return defined( 'TUTOR_VERSION' );
	}

	public function last_activity() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_date_gmt) FROM %i WHERE post_type IN ('tutor_enrolled', 'courses', 'lesson')", $wpdb->posts ) );
	}

	/* ---------- Reading ---------- */

	private static function course_ids() {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'courses' AND post_status IN ('publish', 'draft', 'private') ORDER BY menu_order, ID", $wpdb->posts ) ) );
	}

	private static function meta( $post_id, $key ) {
		$v = get_post_meta( $post_id, $key, true );
		$v = is_string( $v ) && is_serialized( $v ) ? @unserialize( $v, array( 'allowed_classes' => false ) ) : $v; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are refused
		return $v;
	}

	/** [ [topic post, [lesson posts]] … ] in Tutor's order. */
	private static function outline( $cid ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM %i WHERE post_type = 'topics' AND post_parent = %d AND post_status <> 'trash' ORDER BY menu_order, ID", $wpdb->posts, $cid ) ) as $topic ) {
			$out[] = array( $topic, $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE post_type = 'lesson' AND post_parent = %d AND post_status IN ('publish', 'draft') ORDER BY menu_order, ID", $wpdb->posts, $topic->ID ) ) );
		}
		return $out;
	}

	/** When each enrolled student started, so lessons that open days after enrolling keep their real date. */
	private static function started( $cid ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_author, MIN(post_date_gmt) AS at FROM %i WHERE post_type = 'tutor_enrolled' AND post_parent = %d AND post_status = 'completed' AND " . self::site_user( 'post_author' ) . ' GROUP BY post_author', $wpdb->posts, $cid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
		return array_column( $rows, 'at', 'post_author' );
	}

	private static function students( $cid ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM %i WHERE post_type = 'tutor_enrolled' AND post_parent = %d AND post_status = 'completed' AND " . self::site_user( 'post_author' ), $wpdb->posts, $cid ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
	}

	private static function price_type( $cid ) {
		if ( 'yes' === get_post_meta( $cid, '_tutor_is_public_course', true ) ) {
			return 'free';
		}
		$t = (string) get_post_meta( $cid, '_tutor_course_price_type', true );
		return in_array( $t, array( 'paid', 'subscription' ), true ) ? $t : 'free';
	}

	private static function has_plans() {
		global $wpdb;
		return Import::has_table( $wpdb->prefix . 'tutor_subscription_plans' ) && Import::has_table( $wpdb->prefix . 'tutor_subscriptions' );
	}

	/** Tutor Pro plans for the whole site or for categories: [id => [name, price, period, members, courses (null = all)]]. */
	private static function plans() {
		global $wpdb;
		static $plans = null;
		if ( null !== $plans ) {
			return $plans;
		}
		$plans = array();
		if ( ! self::has_plans() ) {
			return $plans;
		}
		$p = $wpdb->prefix;
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE plan_type IN ('full_site', 'category')", $p . 'tutor_subscription_plans' ), ARRAY_A ) as $plan ) {
			$courses = null;
			$cats    = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM %i WHERE plan_id = %d AND object_name = 'category'", $p . 'tutor_subscription_plan_items', $plan['id'] ) ) );
			if ( 'category' === $plan['plan_type'] || ( $cats && in_array( $plan['restriction_mode'], array( 'include', 'exclude' ), true ) ) ) {
				// Read straight from the tables: with Tutor switched off its taxonomy isn't registered.
				$in = $cats ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT r.object_id FROM %i r JOIN %i t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE t.taxonomy = 'course-category' AND t.term_id IN (" . implode( ',', $cats ) . ')', $wpdb->term_relationships, $wpdb->term_taxonomy ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integers
				$courses = 'exclude' === $plan['restriction_mode'] ? array_values( array_diff( self::course_ids(), $in ) ) : $in;
			}
			$plans[ (int) $plan['id'] ] = array(
				'name'    => html_entity_decode( (string) $plan['plan_name'], ENT_QUOTES, 'UTF-8' ),
				'price'   => self::money( $plan['sale_price'] && (float) $plan['sale_price'] > 0 ? $plan['sale_price'] : $plan['regular_price'] ),
				'period'  => self::period( $plan ),
				'members' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE plan_id = %d AND status = 'active' AND " . self::site_user( 'user_id' ), $p . 'tutor_subscriptions', $plan['id'] ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
				'courses' => $courses,
			);
		}
		return $plans;
	}

	private static function money( $v ) {
		$v = (float) $v;
		return $v > 0 ? rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' ) : null;
	}

	private static function period( array $plan ) {
		if ( 'recurring' !== ( $plan['payment_type'] ?? 'recurring' ) ) {
			return 'once';
		}
		$every = (int) ( $plan['recurring_value'] ?? 1 );
		$unit  = (string) ( $plan['recurring_interval'] ?? 'month' );
		return 1 === $every && in_array( $unit, array( 'month', 'year' ), true ) ? $unit : ( 12 === $every && 'month' === $unit ? 'year' : 'once' );
	}

	/** A course's own price, from Tutor's checkout or the shop product it sells through. */
	private static function course_price( $cid, $type ) {
		global $wpdb;
		if ( 'subscription' === $type && self::has_plans() ) {
			$plan = $wpdb->get_row( $wpdb->prepare( "SELECT p.* FROM %i p JOIN %i i ON i.plan_id = p.id AND i.object_name = 'course' AND i.object_id = %d ORDER BY p.regular_price LIMIT 1", $wpdb->prefix . 'tutor_subscription_plans', $wpdb->prefix . 'tutor_subscription_plan_items', $cid ), ARRAY_A );
			if ( $plan ) {
				return array( self::money( $plan['sale_price'] && (float) $plan['sale_price'] > 0 ? $plan['sale_price'] : $plan['regular_price'] ), self::period( $plan ) );
			}
		}
		$product = (int) get_post_meta( $cid, '_tutor_course_product_id', true );
		$sale    = get_post_meta( $cid, 'tutor_course_sale_price', true );
		$price   = $product ? get_post_meta( $product, '_price', true ) : ( (float) $sale > 0 ? $sale : get_post_meta( $cid, 'tutor_course_price', true ) );
		return array( self::money( $price ), 'once' );
	}

	public function summary() {
		global $wpdb;
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$ids      = self::course_ids();
		$in       = $ids ? implode( ',', $ids ) : '0';
		$topics   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'topics' AND post_parent IN ({$in})", $wpdb->posts ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
		$lessons  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i l JOIN %i t ON t.ID = l.post_parent AND t.post_type = 'topics' WHERE l.post_type = 'lesson' AND l.post_status IN ('publish', 'draft') AND t.post_parent IN ({$in})", $wpdb->posts, $wpdb->posts ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
		$students = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_author) FROM %i WHERE post_type = 'tutor_enrolled' AND post_status = 'completed'", $wpdb->posts ) );
		$done     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpdb->esc_like( '_tutor_completed_lesson_id_' ) . '%' ) );
		$memo     = array(
			'members'  => 0,
			'courses'  => count( $ids ),
			'sections' => $topics,
			'lessons'  => $lessons,
			'students' => $students,
			'done'     => $done,
			'plans'    => count( self::plans() ),
			'paid'     => count( array_filter( $ids, fn( $cid ) => 'free' !== self::price_type( $cid ) ) ),
			'open'     => count( array_filter( $ids, fn( $cid ) => 'free' === self::price_type( $cid ) ) ),
			'pro'      => defined( 'TUTOR_PRO_VERSION' ) || self::has_plans(),
		);
		return $memo;
	}

	public function totals( array $job ) {
		return array( 'courses' => count( self::course_ids() ) );
	}

	public function batch( $step, $after, $limit, array $job ) {
		if ( 'courses' !== $step ) {
			return array( array(), null );
		}
		foreach ( self::course_ids() as $cid ) {
			if ( $cid > (int) $after && ! Import::mapped( 'tutor', 'course', $cid ) ) {
				return array( array( $this->course( $cid ) ), $cid );
			}
		}
		return array( array(), null );
	}

	private function course( $cid ) {
		global $wpdb;
		$post     = get_post( $cid );
		$settings = (array) self::meta( $cid, '_tutor_course_settings' );
		$drip     = ! empty( $settings['enable_content_drip'] ) ? (string) ( $settings['content_drip_type'] ?? '' ) : '';
		$modules  = array();
		$lessons  = array();
		foreach ( self::outline( $cid ) as $sec ) {
			list( $topic, $items ) = $sec;
			$modules[] = array( 'source_id' => (int) $topic->ID, 'title' => html_entity_decode( $topic->post_title, ENT_QUOTES, 'UTF-8' ) );
			foreach ( $items as $l ) {
				$lessons[] = self::lesson( $l, (int) $topic->ID, $drip );
			}
		}
		$ids      = array_column( $lessons, 'source_id' );
		$offset   = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$done     = array();
		$keys     = array_map( fn( $id ) => '_tutor_completed_lesson_id_' . $id, $ids );
		foreach ( array_chunk( $keys, 200 ) as $chunk ) {
			$marks = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM %i WHERE meta_key IN ({$marks}) AND " . self::site_user( 'user_id' ), $wpdb->usermeta, ...$chunk ), ARRAY_A );
			foreach ( $rows as $r ) {
				$ts     = (int) $r['meta_value'] - $offset; // Tutor writes the site's local time as a timestamp
				$done[] = array( 'user_id' => (int) $r['user_id'], 'lesson' => (int) substr( $r['meta_key'], 27 ), 'at' => $ts > 0 ? gmdate( 'Y-m-d H:i:s', $ts ) : null );
			}
		}
		$finished = array_map(
			fn( $r ) => array( 'user_id' => (int) $r['user_id'], 'at' => $r['comment_date_gmt'] ),
			$wpdb->get_results( $wpdb->prepare( "SELECT user_id, comment_date_gmt FROM %i WHERE comment_type = 'course_completed' AND comment_post_ID = %d AND " . self::site_user( 'user_id' ), $wpdb->comments, $cid ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
		);
		$enrolled = self::students( $cid );
		$type     = self::price_type( $cid );
		$memberships = array();
		if ( 'free' !== $type ) {
			list( $price, $period ) = self::course_price( $cid, $type );
			$memberships[]          = array( 'key' => 'course:' . $cid, 'name' => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ), 'members' => $enrolled, 'price' => $price, 'period' => $period, 'url' => get_permalink( $cid ) );
			foreach ( self::plans() as $pid => $plan ) {
				if ( null === $plan['courses'] || in_array( $cid, $plan['courses'], true ) ) {
					$memberships[] = array( 'key' => 'plan:' . $pid, 'name' => $plan['name'], 'members' => $plan['members'], 'price' => $plan['price'], 'period' => $plan['period'], 'url' => '' );
				}
			}
		}
		$thumb = (int) get_post_thumbnail_id( $cid );
		return array(
			'source_id'   => $cid,
			'title'       => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
			'description' => Import::text( Import::tidy( $post->post_excerpt ?: $post->post_content ) ),
			'cover_url'   => $thumb ? (string) wp_get_attachment_url( $thumb ) : '',
			'published'   => 'publish' === $post->post_status,
			'private'     => false,
			'everyone'    => 'free' === $type,
			'memberships' => $memberships,
			'created_by'  => (int) $post->post_author,
			'created_at'  => $post->post_date_gmt,
			'modules'     => $modules,
			'lessons'     => $lessons,
			'students'    => array_values( array_unique( array_merge( $enrolled, array_column( $done, 'user_id' ) ) ) ),
			'started'     => self::started( $cid ),
			'done'        => $done,
			'finished'    => $finished,
		);
	}

	/** Tutor's video box: [source, source_youtube, source_vimeo, source_external_url, source_embedded, source_video_id, …]. */
	public static function video( $lesson_id ) {
		$v   = self::meta( $lesson_id, '_video' );
		$v   = is_array( $v ) ? $v : array();
		$src = (string) ( $v['source'] ?? '' );
		switch ( $src ) {
			case 'html5':
				$att = (int) ( $v['source_video_id'] ?? 0 );
				$url = $att ? (string) wp_get_attachment_url( $att ) : '';
				return array( 'url' => $url, 'file' => $url ? array( 'attachment_id' => $att, 'mime' => (string) get_post_mime_type( $att ) ) : null );
			case 'youtube':
			case 'vimeo':
			case 'external_url':
			case 'embedded':
				$given = (string) ( $v[ 'source_' . $src ] ?? '' );
				return array( 'url' => Import::video_url( $given ) ?: Import::first_url( $given ), 'file' => null ); // a link that can't play here still stays in the lesson
			case 'shortcode':
				return array( 'url' => '', 'file' => null, 'shortcode' => true );
		}
		return array( 'url' => '', 'file' => null );
	}

	private static function lesson( $l, $module, $drip ) {
		$v     = self::video( $l->ID );
		$url   = $v['url'] ?: Import::video_url( (string) $l->post_content );
		$d     = (array) self::meta( $l->ID, '_content_drip_settings' );
		$files = array();
		foreach ( array_slice( array_filter( array_map( 'intval', (array) self::meta( $l->ID, '_tutor_attachments' ) ) ), 0, 20 ) as $att ) {
			$link = wp_get_attachment_url( $att );
			if ( $link ) {
				$files[] = array( 'url' => $link, 'attachment_id' => $att, 'mime' => (string) get_post_mime_type( $att ), 'name' => get_the_title( $att ) );
			}
		}
		$open_on = null;
		if ( 'unlock_by_date' === $drip && ! empty( $d['unlock_date'] ) ) {
			$ts      = strtotime( (string) $d['unlock_date'] );
			$open_on = $ts ? gmdate( 'Y-m-d', $ts ) : null;
		}
		return array(
			'source_id'  => (int) $l->ID,
			'module'     => $module,
			'title'      => html_entity_decode( $l->post_title, ENT_QUOTES, 'UTF-8' ),
			'content'    => Import::tidy( (string) $l->post_content, $url ),
			'video'      => $url,
			'video_file' => $v['file'],
			'files'      => $files,
			'drip_days'  => 'specific_days' === $drip ? max( 0, (int) ( $d['after_xdays_of_enroll'] ?? 0 ) ) : 0,
			'open_on'    => $open_on,
			'created_at' => $l->post_date_gmt,
		);
	}

	/* ---------- The dry run ---------- */

	public function check( array $job ) {
		global $wpdb;
		$f      = $this->summary();
		$b      = $job['bring'];
		$items  = array();
		$notes  = array();
		$every  = self::course_ids();
		$ids    = array_values( array_filter( $every, fn( $cid ) => ! Import::mapped( 'tutor', 'course', $cid ) ) ); // only what this run brings
		if ( $every && ! $ids ) {
			return Import_Check::nothing_new( count( $every ), 'Tutor LMS' );
		}
		$again  = count( $ids ) < count( $every );
		$secs   = 0;
		$steps  = 0;
		$free   = 0;
		$paid   = 0;
		$timed  = 0;
		$order  = 0;
		$codes  = 0;
		$other  = 0;
		$drafts = 0;
		foreach ( $ids as $cid ) {
			'free' === self::price_type( $cid ) ? ++$free : ++$paid;
			$drafts += 'publish' === get_post_status( $cid ) ? 0 : 1;
			$s     = (array) self::meta( $cid, '_tutor_course_settings' );
			$drip  = ! empty( $s['enable_content_drip'] ) ? (string) ( $s['content_drip_type'] ?? '' ) : '';
			$order += in_array( $drip, array( 'unlock_sequentially', 'after_finishing_prerequisites' ), true ) ? 1 : 0;
			foreach ( self::outline( $cid ) as $sec ) {
				++$secs;
				$steps += count( $sec[1] );
				foreach ( $sec[1] as $l ) {
					$d      = (array) self::meta( $l->ID, '_content_drip_settings' );
					$timed += ( 'specific_days' === $drip && (int) ( $d['after_xdays_of_enroll'] ?? 0 ) > 0 ) || ( 'unlock_by_date' === $drip && ! empty( $d['unlock_date'] ) ) ? 1 : 0;
					$vid    = self::video( $l->ID );
					$codes += ! empty( $vid['shortcode'] ) ? 1 : 0;
					$other += '' !== $vid['url'] && ! $vid['file'] && ! Import::plays( $vid['url'] ) ? 1 : 0;
				}
			}
		}
		$plans   = self::plans();
		$all     = array_filter( $plans, fn( $p ) => null === $p['courses'] );
		$count   = fn( $type ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = %s AND post_status = 'publish'", $wpdb->posts, $type ) );
		$skipped = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'tutor_enrolled' AND post_status <> 'completed'", $wpdb->posts ) );

		if ( in_array( 'courses', $b, true ) ) {
			$items[] = array(
				'title' => Import_Check::courses_line( count( $ids ), $again ),
				'sub'   => sprintf(
					/* translators: %s: e.g. "5 sections and 12 lessons" */
					__( '%s, in Tutor’s order.', 'membuild-community' ),
					Import_Check::join(
						array(
							/* translators: %s: number */
							sprintf( _n( '%s section', '%s sections', $secs, 'membuild-community' ), Import_Check::n( $secs ) ),
							/* translators: %s: number */
							sprintf( _n( '%s lesson', '%s lessons', $steps, 'membuild-community' ), Import_Check::n( $steps ) ),
						)
					)
				) . ( $drafts ? ' ' . sprintf( _n( '%s is a draft and stays a draft.', '%s are drafts and stay drafts.', $drafts, 'membuild-community' ), Import_Check::n( $drafts ) ) : '' ),
				'tag'   => 'ready',
			);
		}
		if ( in_array( 'progress', $b, true ) ) {
			$items[] = array(
				/* translators: 1: students, 2: finished lessons */
				'title' => $again ? __( 'Students and their progress in the new courses', 'membuild-community' ) : sprintf( __( '%1$s students and %2$s finished lessons', 'membuild-community' ), Import_Check::n( $f['students'] ), Import_Check::n( $f['done'] ) ),
				/* translators: %s: number of enrollments */
				'sub'   => $skipped ? sprintf( _n( '%s enrollment waiting for payment or cancelled is left out.', '%s enrollments waiting for payment or cancelled are left out.', $skipped, 'membuild-community' ), Import_Check::n( $skipped ) ) : __( 'Matched by their account, so nothing is doubled.', 'membuild-community' ),
				'tag'   => 'ready',
			);
		}
		if ( in_array( 'access', $b, true ) ) {
			if ( $free ) {
				/* translators: %s: number of courses */
				$items[] = array( 'title' => sprintf( _n( '%s free course stays open to everyone', '%s free courses stay open to everyone', $free, 'membuild-community' ), Import_Check::n( $free ) ), 'sub' => '', 'tag' => 'ready' );
			}
			if ( $paid ) {
				$items[] = array(
					'title' => $plans
						/* translators: %s: e.g. "5 paid courses and 2 Tutor Pro plans" */
						? sprintf( __( '%s become memberships', 'membuild-community' ), Import_Check::join( array( sprintf( _n( '%s paid course', '%s paid courses', $paid, 'membuild-community' ), Import_Check::n( $paid ) ), sprintf( _n( '%s Tutor Pro plan', '%s Tutor Pro plans', count( $plans ), 'membuild-community' ), Import_Check::n( count( $plans ) ) ) ) ) )
						/* translators: %s: number of courses */
						: sprintf( _n( '%s paid course becomes a membership', '%s paid courses become memberships', $paid, 'membuild-community' ), Import_Check::n( $paid ) ),
					/* translators: %s: plan names */
					'sub'   => $all ? sprintf( __( 'Subscribers to %s get every paid course, even ones they never opened in Tutor.', 'membuild-community' ), implode( ', ', array_map( fn( $p ) => '“' . $p['name'] . '”', $all ) ) ) : __( 'Each with the people who have it now.', 'membuild-community' ),
					'tag'   => 'ready',
				);
			}
		} elseif ( $paid ) {
			/* translators: %s: number of courses */
			$notes[] = array( 'title' => sprintf( _n( '%s paid course opens only for admins for now', '%s paid courses open only for admins for now', $paid, 'membuild-community' ), Import_Check::n( $paid ) ), 'sub' => __( 'You didn’t tick who can open each course. Choose in each course’s settings.', 'membuild-community' ), 'tag' => 'your_turn' );
		}
		if ( in_array( 'timing', $b, true ) && $timed ) {
			/* translators: %s: number of lessons */
			$items[] = array( 'title' => sprintf( _n( '%s lesson keeps its timing', '%s lessons keep their timing', $timed, 'membuild-community' ), Import_Check::n( $timed ) ), 'sub' => '', 'tag' => 'ready' );
		}

		if ( in_array( 'access', $b, true ) && $paid ) {
			$notes[] = array( 'title' => __( 'Payments don’t move', 'membuild-community' ), 'sub' => __( 'Tutor’s checkout keeps charging subscribers. Choose how people buy each new membership in Memberships before you switch Tutor off.', 'membuild-community' ), 'tag' => 'your_turn' );
		}
		$quiz = $count( 'tutor_quiz' );
		$task = $count( 'tutor_assignments' );
		if ( $quiz || $task ) {
			$what    = Import_Check::join(
				array(
					/* translators: %s: number */
					$quiz ? sprintf( _n( '%s quiz', '%s quizzes', $quiz, 'membuild-community' ), Import_Check::n( $quiz ) ) : '',
					/* translators: %s: number */
					$task ? sprintf( _n( '%s assignment', '%s assignments', $task, 'membuild-community' ), Import_Check::n( $task ) ) : '',
				)
			);
			/* translators: %s: e.g. "14 quizzes and 5 assignments" */
			$notes[] = array( 'title' => sprintf( _n( '%s stays in Tutor', '%s stay in Tutor', $quiz + $task, 'membuild-community' ), $what ), 'sub' => __( 'The Classroom has no quizzes. Finished courses still show as finished.', 'membuild-community' ), 'tag' => 'left_out' );
		}
		$live = $count( 'tutor_zoom_meeting' ) + $count( 'tutor-google-meet' );
		if ( $live ) {
			/* translators: %s: number of sessions */
			$notes[] = array( 'title' => sprintf( _n( '%s live session (Zoom or Google Meet)', '%s live sessions (Zoom and Google Meet)', $live, 'membuild-community' ), Import_Check::n( $live ) ), 'sub' => __( 'Past ones are left out. Make upcoming ones an event in Events, so members get reminders.', 'membuild-community' ), 'tag' => 'left_out' );
		}
		if ( $order ) {
			/* translators: %s: number of courses */
			$notes[] = array( 'title' => sprintf( _n( '%s course opens lessons one after another', '%s courses open lessons one after another', $order, 'membuild-community' ), Import_Check::n( $order ) ), 'sub' => __( 'The Classroom opens them all at once, or on days you set. Add days per lesson in the course after the import if you want them spaced out.', 'membuild-community' ), 'tag' => 'changes' );
		}
		if ( $other ) {
			/* translators: %s: number of videos */
			$notes[] = array( 'title' => sprintf( _n( '%s video from somewhere else', '%s videos from somewhere else', $other, 'membuild-community' ), Import_Check::n( $other ) ), 'sub' => __( 'Only YouTube, Vimeo and your own Bunny videos play as a lesson’s video. Their links stay at the end of each lesson, so you can add them again.', 'membuild-community' ), 'tag' => 'your_turn' );
		}
		if ( $codes ) {
			/* translators: %s: number of lessons */
			$notes[] = array( 'title' => sprintf( _n( '%s lesson plays a video from a shortcode', '%s lessons play a video from a shortcode', $codes, 'membuild-community' ), Import_Check::n( $codes ) ), 'sub' => __( 'Shortcodes only work with their plugin, so these show the lesson text without the video. Add the video link to each one.', 'membuild-community' ), 'tag' => 'your_turn' );
		}
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = 'tutor' AND kind = 'course'", Install::table( 'import_map' ) ) );
		if ( $before ) {
			/* translators: %s: number of courses */
			$notes[] = array( 'title' => __( 'Imported before', 'membuild-community' ), 'sub' => sprintf( _n( '%s course is already here from last time. Only new courses come over.', '%s courses are already here from last time. Only new courses come over.', $before, 'membuild-community' ), Import_Check::n( $before ) ), 'tag' => 'merged' );
		}
		return array( 'items' => $items, 'notes' => $notes );
	}
}
