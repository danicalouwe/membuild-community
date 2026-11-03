<?php
/**
 * The starter pages' live blocks: the parts of the website pages that come from the
 * community itself (courses, events, members, plans, the team), always up to date.
 * Each is a server-rendered block the owner can move, remove or set up in the editor.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/** Server-rendered blocks for the main website pages (Starter_Pages). */
class Starter_Blocks {

	/** name => its settings (attributes). */
	private static function blocks() {
		return array(
			'courses'      => array(
				'limit'   => array( 'type' => 'number', 'default' => 3 ),
				'details' => array( 'type' => 'boolean', 'default' => false ), // the Courses page: what it's about, who's taking it, what it needs
				'filters' => array( 'type' => 'boolean', 'default' => false ),
				'more'    => array( 'type' => 'boolean', 'default' => false ),
			),
			'events'       => array( 'limit' => array( 'type' => 'number', 'default' => 3 ) ),
			'upcoming-events' => array( 'count' => array( 'type' => 'number', 'default' => 6 ) ), // the Events page
			'faces'        => array(
				'count'   => array( 'type' => 'number', 'default' => 6 ),
				'size'    => array( 'type' => 'number', 'default' => 44 ),
				'label'   => array( 'type' => 'boolean', 'default' => true ),
				'note'    => array( 'type' => 'string', 'default' => '' ),
				'gallery' => array( 'type' => 'boolean', 'default' => false ),
			),
			'plans'        => array(
				'free'       => array( 'type' => 'string', 'default' => '' ), // what Free includes, a line each (paid plans' lines come from Memberships)
				'paid'       => array( 'type' => 'string', 'default' => '' ), // pages made before 1.17: the paid plans' lines, until Memberships has them
				'trust'      => array( 'type' => 'boolean', 'default' => false ),
				'showFree'   => array( 'type' => array( 'boolean', 'null', 'string' ), 'default' => null ), // null (or '' from the editor's preview): when people can join for free
				'badge'      => array( 'type' => 'string', 'default' => 'Most popular' ),
				'mainLine'   => array( 'type' => 'string', 'default' => '' ),
				'mainButton' => array( 'type' => 'string', 'default' => '' ),
				'freeLine'   => array( 'type' => 'string', 'default' => '' ),
				'freeButton' => array( 'type' => 'string', 'default' => '' ),
			),
			'compare'      => array(
				'rows'     => array( 'type' => 'string', 'default' => '' ), // pages made before 1.17: "words | yes" lines
				'items'    => array( 'type' => 'array', 'default' => array() ), // [ { label, in: [ 'free' | membership id ] } ]
				'showFree' => array( 'type' => array( 'boolean', 'null', 'string' ), 'default' => null ),
			),
			'contact-form' => array( 'note' => array( 'type' => 'boolean', 'default' => true ) ),
			'waitlist'     => array( 'button' => array( 'type' => 'string', 'default' => 'Keep me posted' ) ), // Coming soon: the email box
			'opening'      => array( 'fallback' => array( 'type' => 'string', 'default' => 'Opening soon' ) ), // Coming soon: "Opening Mon, Dec 1"
			'stat'         => array(
				'kind'     => array( 'type' => 'string', 'default' => 'members' ), // members | courses | active
				'format'   => array( 'type' => 'string', 'default' => '%s' ),
				'pill'     => array( 'type' => 'boolean', 'default' => false ),
				'min'      => array( 'type' => 'number', 'default' => -1 ), // -1: from 20 active today, 50 members (SHOW_FROM)
				'fallback' => array( 'type' => 'string', 'default' => '' ),
			),
			'peek'         => array(
				'kind'     => array( 'type' => 'string', 'default' => 'question' ), // question | course | event | people
				'question' => array( 'type' => 'string', 'default' => 'Where should a beginner start?' ),
				'answer'   => array( 'type' => 'string', 'default' => 'Start Here, then ask away. We all did!' ),
			),
			'moments'      => array(),
			'team'         => array( 'row' => array( 'type' => 'boolean', 'default' => false ) ),
		);
	}

	public static function register() {
		wp_register_script( 'mbcom-starter-blocks', MEMBUILD_COMMUNITY_URL . 'assets/starter/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ), MEMBUILD_COMMUNITY_VERSION, true );
		// The Compare rows need the plans' names for their tick columns.
		if ( is_admin() ) {
			add_action( 'enqueue_block_editor_assets', fn() => wp_add_inline_script( 'mbcom-starter-blocks', 'window.mbcomStarter=' . wp_json_encode( array( 'columns' => self::compare_columns() ) ) . ';', 'before' ) );
		}
		// The live blocks have their own group in the inserter, not mixed into Widgets.
		add_filter( 'block_categories_all', fn( $cats ) => array_merge( array( array( 'slug' => 'membuild-community', 'title' => __( 'MemBuild Community', 'membuild-community' ), 'icon' => null ) ), array_values( array_filter( $cats, fn( $c ) => 'membuild-community' !== $c['slug'] ) ) ) );
		foreach ( self::blocks() as $name => $attributes ) {
			register_block_type(
				'membuild-community/' . $name,
				array(
					'api_version'     => 3,
					'attributes'      => $attributes + array( 'className' => array( 'type' => 'string' ) ),
					'editor_script'   => 'mbcom-starter-blocks',
					'render_callback' => array( __CLASS__, 'render_' . str_replace( '-', '_', $name ) ),
					'supports'        => array( 'html' => false ),
				)
			);
		}
	}

	/* ---------- Shared bits ---------- */

	private static function wrap( $class, $inner, $attrs, $tag = 'div' ) {
		$extra = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', trim( (string) ( $attrs['className'] ?? '' ) ) ) ) );
		return '<' . $tag . ' class="mbs-live ' . esc_attr( trim( $class . ' ' . $extra ) ) . '">' . $inner . '</' . $tag . '>';
	}

	private static function photo( $user_id, $size = 96 ) {
		return (string) get_user_meta( $user_id, 'mbcom_avatar_url', true ) ?: (string) get_avatar_url( $user_id, array( 'size' => $size * 2 ) );
	}

	private static function face( $user_id, $size ) {
		return '<img class="mbs-face" src="' . esc_url( self::photo( $user_id, $size ) ) . '" alt="" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy">';
	}

	private static function face_row( array $ids, $size ) {
		return $ids ? '<span class="mbs-face-row" style="--size:' . (int) $size . 'px">' . implode( '', array_map( fn( $id ) => self::face( $id, $size ), $ids ) ) . '</span>' : '';
	}

	/** Members with a photo: the most recently active first. */
	public static function faces( $count ) {
		global $wpdb;
		$count = max( 1, min( 48, (int) $count ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT a.user_id FROM {$wpdb->usermeta} a LEFT JOIN {$wpdb->usermeta} s ON s.user_id = a.user_id AND s.meta_key = %s WHERE a.meta_key = 'mbcom_avatar_url' AND a.meta_value <> '' ORDER BY CAST(s.meta_value AS UNSIGNED) DESC, a.user_id ASC LIMIT %d", Online::SEEN, $count ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $ids );
	}

	/**
	 * The people a live number counts: not admins, and not accounts marked as test accounts
	 * (user meta mbcom_test_account). SQL for "u.ID is counted".
	 */
	private static function counted_sql() {
		global $wpdb;
		return $wpdb->prepare(
			"NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} c WHERE c.user_id = u.ID AND ( ( c.meta_key = %s AND c.meta_value LIKE %s ) OR ( c.meta_key = 'mbcom_test_account' AND c.meta_value <> '' ) ))",
			$wpdb->prefix . 'capabilities',
			'%"administrator"%'
		);
	}

	/** Members who used the community in the last day (admins and test accounts aren't counted). */
	public static function active_today() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} s JOIN {$wpdb->users} u ON u.ID = s.user_id WHERE s.meta_key = %s AND CAST(s.meta_value AS UNSIGNED) > %d AND ", Online::SEEN, time() - DAY_IN_SECONDS ) . self::counted_sql() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- counted_sql() is prepared
	}

	/** Everyone who has joined (admins and test accounts aren't counted). */
	public static function members() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE " . self::counted_sql() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- counted_sql() is prepared
	}

	/** "Only show from": a live number stays hidden until it helps (20 active today, 50 members). */
	const SHOW_FROM = array( 'active' => 20, 'members' => 50, 'courses' => 0, 'waiting' => 20 );

	private static function course_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published'", Install::table( 'courses' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The people who look after the community: the host (first admin), then the moderators. */
	private static function team() {
		$host = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
		$ids  = array_merge( array_map( 'intval', $host ), array_map( 'intval', (array) Settings::get( 'moderators' ) ) );
		return array_values( array_unique( array_filter( $ids, fn( $id ) => (bool) get_userdata( $id ) ) ) );
	}

	/** The little calendar page: NOV over 5. */
	private static function date_tile( $at ) {
		return '<span class="mbs-date"><small>' . esc_html( strtoupper( wp_date( 'M', $at ) ) ) . '</small><b>' . esc_html( wp_date( 'j', $at ) ) . '</b></span>';
	}

	/**
	 * The next events this visitor may know about, soonest first: the community's own rule
	 * (Events::visible_sql), so a members-only event never reaches a visitor, and nothing
	 * from someone they blocked.
	 */
	public static function next_events( $limit ) {
		global $wpdb;
		list( $seen, $vals ) = Events::visible_sql();
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $seen is fixed SQL with %d placeholders; values are bound.
			$wpdb->prepare( "SELECT id, user_id, event_title, event_at, event_minutes, event_where, event_place_id, event_address FROM %i WHERE status = 'published' AND event_title <> '' AND event_at > %s AND event_status = '' AND {$seen} ORDER BY event_at ASC LIMIT %d", array_merge( array( Install::table( 'posts' ), gmdate( 'Y-m-d H:i:s' ) ), $vals, array( (int) $limit ) ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return Blocks::filter( (array) $rows );
	}

	/**
	 * Only the town from an event's address, never the street or the place:
	 * "Zilker Park, Austin, TX" → "Austin", "12 Main St, London SW1A 2AA, UK" → "London".
	 * Addresses read from the end: the country, a postcode or a state code is skipped, then
	 * the first part left is the town. '' when there's only one part (it may be a venue).
	 */
	public static function city( $address ) {
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', (string) $address ) ), 'strlen' ) );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$countries = array( 'usa', 'us', 'u.s.', 'u.s.a.', 'united states', 'united states of america', 'uk', 'u.k.', 'united kingdom', 'england', 'scotland', 'wales', 'northern ireland', 'australia', 'canada', 'new zealand', 'ireland', 'philippines', 'south africa' );
		if ( in_array( mb_strtolower( end( $parts ) ), $countries, true ) ) {
			array_pop( $parts );
		}
		foreach ( array_reverse( array_slice( $parts, 1 ) ) as $part ) {
			if ( preg_match( '/^\d/', $part ) ) {
				continue; // a street number
			}
			// Drop postcodes ("78701", "SW1A") and state codes ("TX", "NSW").
			$words = array_filter( preg_split( '/\s+/u', $part ), fn( $w ) => ! preg_match( '/\d/', $w ) && ! preg_match( '/^[A-Z]{2,3}$/', $w ) );
			$town  = trim( implode( ' ', $words ) );
			if ( '' !== $town ) {
				return mb_substr( $town, 0, 40 );
			}
		}
		return '';
	}

	private static function going( $event_id, $limit = 3 ) {
		global $wpdb;
		$t = Install::table( 'reactions' );
		return array(
			array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE object_type = 'event' AND object_id = %d ORDER BY id DESC LIMIT %d", $t, $event_id, $limit ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'event' AND object_id = %d", $t, $event_id ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Who can open a course, and where its card goes for whoever is looking:
	 * [ 'free' | 'level' | 'vip', the tag, what the button says, the link ].
	 * Visitors join first and then go straight to the course; a members-only course takes them
	 * to Pricing with its plan picked. Members open the course.
	 */
	private static function access( array $c ) {
		$level  = (int) $c['unlock_level'];
		$groups = Access::course_groups( $c );
		$open   = Portal::url() . 'classroom/' . (int) $c['id'];
		$in     = is_user_logged_in();
		if ( $groups ) {
			$plan = '';
			foreach ( self::paid_plans() as $p ) {
				if ( in_array( (int) $p[4], $groups, true ) ) {
					$plan = $p[0];
					break;
				}
			}
			$mine = $in && array_intersect( $groups, Access::member_groups( get_current_user_id() ) );
			/* translators: %s: a membership's name, e.g. "VIP" */
			$cta  = $mine ? __( 'Open', 'membuild-community' ) : ( $plan ? sprintf( __( 'See the %s plan', 'membuild-community' ), $plan ) : ( $c['access_cta_label'] ?: __( 'See plans', 'membuild-community' ) ) );
			$url  = $mine ? $open : add_query_arg( 'course', (int) $c['id'], Starter_Site::pricing_for( $groups[0] ) );
			return array( 'vip', $plan ?: __( 'Members', 'membuild-community' ), $cta, $url );
		}
		if ( $level > 1 ) {
			/* translators: %d: a level */
			return array( 'level', sprintf( __( 'Level %d', 'membuild-community' ), $level ), $in ? __( 'Open', 'membuild-community' ) : sprintf( __( 'Join, then reach Level %d', 'membuild-community' ), $level ), Starter_Site::into( $open ) );
		}
		return array( 'free', __( 'Free', 'membuild-community' ), $in ? __( 'Start', 'membuild-community' ) : __( 'Start free', 'membuild-community' ), Starter_Site::into( $open ) );
	}

	/** One list item per line of an owner's text, each with a tick. */
	private static function ticks( $text ) {
		return implode( '', array_map( fn( $line ) => '<li>' . esc_html( $line ) . '</li>', array_filter( array_map( 'trim', explode( "\n", (string) $text ) ) ) ) );
	}

	/** Each membership sold with FluentCart: [name, price, "/ month", checkout, membership id]. */
	public static function paid_plans() {
		$paid = array();
		if ( ! Fluent_Cart::active() ) {
			return $paid;
		}
		global $wpdb;
		foreach ( Fluent_Cart::links() as $group_id => $variation ) {
			$item = Fluent_Cart::product( $variation );
			$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', Install::table( 'groups' ), $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $item && $name ) {
				// The membership's own name, the same as at checkout. [name, price, "/ month", checkout, membership id]
				$paid[] = array( $name, $item['price_text'], array( 'month' => __( '/ month', 'membuild-community' ), 'year' => __( '/ year', 'membuild-community' ) )[ $item['period'] ] ?? '', $item['checkout'], (int) $group_id );
			}
		}
		return array_slice( $paid, 0, 2 );
	}

	/* ---------- The blocks ---------- */

	/** A number from the community: members, courses, or members active today. */
	public static function render_stat( $attrs ) {
		$kind = (string) ( $attrs['kind'] ?? 'members' );
		$n    = 'courses' === $kind ? self::course_count() : ( 'active' === $kind ? self::active_today() : self::members() );
		$pill = ! empty( $attrs['pill'] );
		$min  = (int) ( $attrs['min'] ?? -1 );
		if ( $n < ( $min < 0 ? ( self::SHOW_FROM[ $kind ] ?? 0 ) : $min ) ) {
			$text = (string) ( $attrs['fallback'] ?? '' );
			return '' === $text ? '' : self::wrap( $pill ? 'mbs-pill' : 'mbs-stat', esc_html( $text ), $attrs, 'p' );
		}
		$format = (string) ( $attrs['format'] ?? '%s' );
		$text   = str_replace( '%s', number_format_i18n( $n ), false === strpos( $format, '%s' ) ? '%s' : $format );
		return self::wrap( $pill ? 'mbs-pill mbs-pill-live' : 'mbs-stat', esc_html( $text ), $attrs, 'p' );
	}

	public static function render_faces( $attrs ) {
		$size  = max( 24, min( 96, (int) ( $attrs['size'] ?? 44 ) ) );
		$ids   = self::faces( (int) ( $attrs['count'] ?? 6 ) );
		$total = self::members();
		if ( ! empty( $attrs['gallery'] ) ) {
			$grid = implode( '', array_map( fn( $id ) => self::face( $id, $size ), $ids ) );
			$more = $total - count( $ids );
			/* translators: %s: number of other members */
			$link = $more > 0 && $total >= self::SHOW_FROM['members'] ? '<a class="mbs-more" href="' . esc_url( Starter_Site::into( Portal::url() . 'members' ) ) . '">' . esc_html( sprintf( __( '…and %s more →', 'membuild-community' ), number_format_i18n( $more ) ) ) . '</a>' : '';
			return self::wrap( 'mbs-gallery', '<span class="mbs-gallery-grid" style="--size:' . $size . 'px">' . $grid . '</span>' . $link, $attrs );
		}
		$note  = trim( (string) ( $attrs['note'] ?? '' ) );
		/* translators: %s: number of members */
		$count = '<b>' . esc_html( sprintf( _n( '%s member', '%s members', $total, 'membuild-community' ), number_format_i18n( $total ) ) ) . '</b> ' . esc_html__( 'and growing', 'membuild-community' );
		$count = $total >= self::SHOW_FROM['members'] ? $count : ''; // a small number makes a new community look empty
		$label = ! empty( $attrs['label'] ) && ( $count || $note ) ? '<span class="mbs-faces-label">' . $count . ( $note ? '<small>' . esc_html( $note ) . '</small>' : '' ) . '</span>' : '';
		return self::wrap( 'mbs-faces', self::face_row( $ids, $size ) . $label, $attrs );
	}

	public static function render_courses( $attrs ) {
		global $wpdb;
		$limit   = max( 1, min( 12, (int) ( $attrs['limit'] ?? 3 ) ) );
		$details = ! empty( $attrs['details'] );
		$courses = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' ORDER BY sort_order ASC, id ASC LIMIT %d", Install::table( 'courses' ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $courses ) {
			return self::wrap( 'mbs-empty', esc_html__( 'Your courses show here once you publish one in the Classroom.', 'membuild-community' ), $attrs );
		}
		$cards = '';
		$kinds = array();
		foreach ( $courses as $c ) {
			list( $kind, $tag, $cta, $url ) = self::access( $c );
			$kinds[ $kind ]           = true;
			$lessons = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $c['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			/* translators: %d: number of lessons */
			$meta  = sprintf( _n( '%d lesson', '%d lessons', $lessons, 'membuild-community' ), $lessons );
			$cover = $c['cover_url'] ? '<img class="mbs-cover" src="' . esc_url( $c['cover_url'] ) . '" alt="" loading="lazy">' : '<span class="mbs-cover mbs-cover-blank"></span>';
			$body  = '<span class="mbs-tag mbs-tag-' . esc_attr( $kind ) . '">' . esc_html( $tag ) . '</span><b>' . esc_html( $c['title'] ) . '</b>';
			if ( $details ) {
				$takers = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE course_id = %d ORDER BY started_at DESC LIMIT 3', Install::table( 'course_starts' ), $c['id'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$taking = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'course_starts' ), $c['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$body  .= '<span class="mbs-course-sum">' . esc_html( wp_trim_words( (string) $c['description'], 12 ) ) . '</span><span class="mbs-course-meta">' . esc_html( $meta ) . '</span>';
				/* translators: %d: number of members */
				$body  .= $taking ? '<span class="mbs-taking">' . self::face_row( $takers, 24 ) . esc_html( sprintf( _n( '%d member taking it', '%d members taking it', $taking, 'membuild-community' ), $taking ) ) . '</span>' : '';
				$body  .= '<span class="mbs-course-cta">' . esc_html( $cta ) . ' →</span>';
			} else {
				$body .= '<span class="mbs-course-meta">' . esc_html( $meta ) . '</span><span class="mbs-course-cta">' . esc_html( $cta ) . ' →</span>';
			}
			$cards .= '<a class="mbs-course" data-kind="' . esc_attr( $kind ) . '" href="' . esc_url( $url ) . '">' . $cover . '<span class="mbs-course-body">' . $body . '</span></a>';
		}
		if ( ! empty( $attrs['more'] ) ) {
			$cards .= '<span class="mbs-course mbs-course-more"><b>' . esc_html__( 'More coming', 'membuild-community' ) . '</b><span>' . esc_html__( 'New courses are added as the community asks.', 'membuild-community' ) . '</span></span>';
		}
		$pills = '';
		if ( ! empty( $attrs['filters'] ) && count( $kinds ) > 1 ) {
			$names = array( 'free' => __( 'Free', 'membuild-community' ), 'level' => __( 'Unlocks with levels', 'membuild-community' ), 'vip' => __( 'Members only', 'membuild-community' ) );
			$pills = '<div class="mbs-filters" role="group" aria-label="' . esc_attr__( 'Show courses', 'membuild-community' ) . '"><button type="button" class="on" aria-pressed="true" data-kind="">' . esc_html__( 'All', 'membuild-community' ) . '</button>';
			foreach ( array_intersect_key( $names, $kinds ) as $k => $label ) {
				$pills .= '<button type="button" aria-pressed="false" data-kind="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</button>';
			}
			$pills .= '</div><script>(function(s){var w=s.previousElementSibling.parentNode;w.querySelectorAll(".mbs-filters button").forEach(function(b){b.addEventListener("click",function(){w.querySelectorAll(".mbs-filters button").forEach(function(x){x.classList.toggle("on",x===b);x.setAttribute("aria-pressed",x===b)});w.querySelectorAll(".mbs-course[data-kind]").forEach(function(c){c.hidden=!!b.dataset.kind&&c.dataset.kind!==b.dataset.kind})})})})(document.currentScript)</script>';
		}
		return self::wrap( 'mbs-courses-wrap', $pills . '<div class="mbs-courses' . ( $details ? ' mbs-courses-full' : '' ) . '">' . $cards . '</div>', $attrs );
	}

	/**
	 * The next events, a row each. Visitors' rows go to Join first and then straight to the
	 * event ("Save my seat"); members open the event. Times show in the visitor's own zone.
	 */
	public static function render_events( $attrs ) {
		$events = self::next_events( max( 1, min( 8, (int) ( $attrs['limit'] ?? 3 ) ) ) );
		if ( ! $events ) {
			return self::wrap( 'mbs-empty', esc_html__( 'Upcoming events show here once you add one in the community.', 'membuild-community' ), $attrs );
		}
		$in   = is_user_logged_in();
		$rows = '';
		foreach ( $events as $e ) {
			$at            = strtotime( $e['event_at'] . ' UTC' );
			list( $going ) = self::going( (int) $e['id'] );
			$where = in_array( $e['event_where'], array( 'person', 'both' ), true ) ? __( 'in person', 'membuild-community' ) : __( 'online', 'membuild-community' );
			/* translators: 1: a time with its zone, 2: a length in minutes, 3: online or in person */
			$when  = sprintf( __( '%1$s · %2$d min · %3$s', 'membuild-community' ), wp_date( get_option( 'time_format' ), $at ) . ' ' . self::zone(), (int) $e['event_minutes'], $where );
			$rows .= '<a class="mbs-event" data-at="' . esc_attr( (string) $at ) . '" href="' . esc_url( Starter_Site::into( Events::url( (int) $e['id'] ) ) ) . '">' . self::date_tile( $at ) . '<span class="mbs-event-text"><b>' . esc_html( $e['event_title'] ) . '</b><span><time datetime="' . esc_attr( gmdate( 'c', $at ) ) . '">' . esc_html( $when ) . '</time></span></span>' . self::face_row( $going, 30 ) . '<span class="mbs-rsvp">' . ( $in ? esc_html__( 'RSVP', 'membuild-community' ) : esc_html__( 'Save my seat', 'membuild-community' ) . ' →' ) . '</span></a>';
		}
		// The time in the visitor's own zone, keeping "· 60 min · online".
		$js = '<script>(function(l){if(!window.Intl)return;var o=Intl.DateTimeFormat().resolvedOptions().locale;l.querySelectorAll("[data-at]").forEach(function(e){var d=new Date(1e3*e.dataset.at),t=e.querySelector("time"),m=e.querySelector(".mbs-date small"),n=e.querySelector(".mbs-date b");try{var r=t.textContent.split(" · ");r[0]=d.toLocaleTimeString(o,{hour:"numeric",minute:"2-digit",timeZoneName:"short"});t.textContent=r.join(" · ");m.textContent=d.toLocaleDateString(o,{month:"short"}).toUpperCase();n.textContent=d.getDate()}catch(x){}})})(document.currentScript.previousElementSibling)</script>';
		return self::wrap( 'mbs-events', $rows, $attrs ) . $js;
	}

	/** The site's time zone, short ("EST", or "UTC+8" where there's no short name). */
	private static function zone() {
		$zone = wp_date( 'T' );
		return preg_match( '/^[+-]/', $zone ) ? ( preg_match( '/^[+-]0+:?0*$/', $zone ) ? 'UTC' : 'UTC' . $zone ) : $zone;
	}

	/**
	 * The Events page's list: the next few events, each with its day, its time, online or in
	 * person (the town only), and one button. Visitors see "Join to attend"; members open the
	 * event in the community. Never the meeting link, the address, the place or who's going:
	 * those stay inside the community.
	 */
	public static function render_upcoming_events( $attrs ) {
		$count  = max( 1, min( 12, (int) ( $attrs['count'] ?? 6 ) ) );
		$member = is_user_logged_in();
		$join   = Login::url( 'join' );
		$events = self::next_events( $count );
		if ( ! $events ) {
			$hint = Settings::is_staff( get_current_user_id() ) ? '<small>' . esc_html__( 'Only you see this: add an event from Events in the community.', 'membuild-community' ) . '</small>' : '';
			$btn  = $member
				? '<a class="mbs-btn mbs-btn-light" href="' . esc_url( Portal::url() . 'events' ) . '">' . esc_html__( 'Open the community', 'membuild-community' ) . '</a>'
				: '<a class="mbs-btn" href="' . esc_url( $join ) . '">' . esc_html__( 'Join free', 'membuild-community' ) . '</a>';
			$line = $member ? __( 'We’ll tell you in the community when the next one is on.', 'membuild-community' ) : __( 'Join free and we’ll tell you when the next one is on.', 'membuild-community' );
			return self::wrap( 'mbs-up-empty', '<span class="mbs-up-empty-icon" aria-hidden="true"></span><b>' . esc_html__( 'New dates coming soon', 'membuild-community' ) . '</b><span>' . esc_html( $line ) . '</span>' . $btn . $hint, $attrs );
		}
		$places = array_flip( Rest_Places::hidden_ids( array_column( $events, 'event_place_id' ) ) ); // a members-only place: not even its town
		$zone   = self::zone();
		$rows   = '';
		foreach ( $events as $e ) {
			$at    = strtotime( $e['event_at'] . ' UTC' );
			$where = in_array( $e['event_where'], array( 'person', 'both' ), true ) ? $e['event_where'] : 'online';
			$town  = 'online' === $where || isset( $places[ (int) $e['event_place_id'] ] ) ? '' : self::city( $e['event_address'] );
			$label = array(
				'online' => __( 'Online', 'membuild-community' ),
				'person' => __( 'In person', 'membuild-community' ),
				'both'   => __( 'Online and in person', 'membuild-community' ),
			)[ $where ];
			$when  = wp_date( 'D, M j · ' . get_option( 'time_format' ), $at ) . ' ' . $zone; // the site's time zone; the visitor's own once the page loads
			/* translators: %d: a length in minutes */
			$long  = sprintf( __( '%d min', 'membuild-community' ), (int) $e['event_minutes'] );
			$title = (string) $e['event_title'];
			$btn   = $member
				/* translators: %s: an event's title */
				? '<a class="mbs-btn mbs-btn-light" href="' . esc_url( Events::url( (int) $e['id'] ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Open in the community: %s', 'membuild-community' ), $title ) ) . '">' . esc_html__( 'Open in the community', 'membuild-community' ) . '</a>'
				/* translators: %s: an event's title */
				: '<a class="mbs-btn" href="' . esc_url( $join ) . '" aria-label="' . esc_attr( sprintf( __( 'Join to attend: %s', 'membuild-community' ), $title ) ) . '">' . esc_html__( 'Join to attend', 'membuild-community' ) . '</a>';
			$rows .= '<li class="mbs-up-event" data-at="' . esc_attr( (string) $at ) . '">' . self::date_tile( $at )
				. '<span class="mbs-up-text"><b>' . esc_html( $title ) . '</b>'
				. '<span class="mbs-up-when"><time datetime="' . esc_attr( gmdate( 'c', $at ) ) . '">' . esc_html( $when ) . '</time> · <span class="mbs-nowrap">' . esc_html( $long ) . '</span></span>'
				. '<span class="mbs-up-where mbs-up-' . esc_attr( $where ) . '">' . esc_html( $label . ( '' !== $town ? ' · ' . $town : '' ) ) . '</span></span>'
				. $btn . '</li>';
		}
		// The day and time in the visitor's own time zone, when their browser says which it is.
		$js = '<script>(function(l){if(!window.Intl)return;var o=Intl.DateTimeFormat().resolvedOptions().locale;l.querySelectorAll("li[data-at]").forEach(function(e){var d=new Date(1e3*e.dataset.at),t=e.querySelector("time"),m=e.querySelector(".mbs-date small"),n=e.querySelector(".mbs-date b");try{t.textContent=d.toLocaleDateString(o,{weekday:"short",month:"short",day:"numeric"})+" · "+d.toLocaleTimeString(o,{hour:"numeric",minute:"2-digit",timeZoneName:"short"});m.textContent=d.toLocaleDateString(o,{month:"short"}).toUpperCase();n.textContent=d.getDate()}catch(x){}})})(document.currentScript.previousElementSibling)</script>';
		return self::wrap( 'mbs-up', '<ul class="mbs-up-list">' . $rows . '</ul>' . $js, $attrs );
	}

	/** Whether the plans show Free: the block's switch, else whether people can join for free. */
	private static function show_free( $attrs ) {
		$on = $attrs['showFree'] ?? null;
		return null === $on || '' === $on ? Login::registration_open() : filter_var( $on, FILTER_VALIDATE_BOOLEAN );
	}

	/** The paid plans, cheapest first, so the dearest (the main plan, with the badge) comes last. */
	private static function plans_by_price() {
		$plans = self::paid_plans();
		usort( $plans, fn( $a, $b ) => (float) preg_replace( '/[^\d.]/', '', $a[1] ) <=> (float) preg_replace( '/[^\d.]/', '', $b[1] ) );
		return $plans;
	}

	/** Grey placeholder lines: they show the owner a short checklist goes there. */
	private static function placeholder_ticks( $first ) {
		$lines = array( $first, __( 'A second thing, like the starter course', 'membuild-community' ), __( 'Keep it to 3 to 5 short lines', 'membuild-community' ) );
		return implode( '', array_map( fn( $l ) => '<li class="mbs-tick-ph">' . esc_html( $l ) . '</li>', $lines ) );
	}

	/** A plan's lines: from Memberships, else the block's own (pages made before 1.17), else placeholders. */
	private static function plan_ticks( $group_id, $legacy, $first ) {
		$lines = $group_id ? Starter_Site::features( $group_id ) : array();
		if ( $lines ) {
			return implode( '', array_map( fn( $l ) => '<li>' . esc_html( $l ) . '</li>', $lines ) );
		}
		return '' !== trim( (string) $legacy ) ? self::ticks( $legacy ) : self::placeholder_ticks( $first );
	}

	/**
	 * Free (when it's on), and each membership sold with FluentCart, with its price, checkout and
	 * what it includes as they are now. Signed-in members see "✓ Your plan" on their own plan,
	 * with a button into the community. ?plan=<membership> (from a course card) picks a plan.
	 */
	public static function render_plans( $attrs ) {
		$in     = is_user_logged_in();
		$mine   = $in ? Access::member_groups( get_current_user_id() ) : array();
		$paid   = self::plans_by_price();
		$free   = self::show_free( $attrs ) || ! $paid;
		$picked = isset( $_GET['plan'] ) ? (int) $_GET['plan'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- which card to point at, nothing more
		$course = isset( $_GET['course'] ) ? (int) $_GET['course'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$open   = '<a class="mbs-btn" href="' . esc_url( Portal::url() ) . '">' . esc_html__( 'Go to the community →', 'membuild-community' ) . '</a>';
		$yours  = '<span class="mbs-badge mbs-badge-mine">✓ ' . esc_html__( 'Your plan', 'membuild-community' ) . '</span>';
		$ownpaid = (bool) array_intersect( array_map( fn( $p ) => (int) $p[4], $paid ), $mine );
		$cards  = '';
		if ( $free ) {
			$is_mine = $in && ! $ownpaid;
			$button  = trim( (string) ( $attrs['freeButton'] ?? '' ) ) ?: __( 'Join free', 'membuild-community' );
			$cards  .= '<div class="mbs-plan' . ( $is_mine ? ' mbs-plan-mine' : '' ) . '">' . ( $is_mine ? $yours : '' ) . '<b class="mbs-plan-name">' . esc_html__( 'Free', 'membuild-community' ) . '</b><span class="mbs-price">$0</span><span class="mbs-plan-sum">' . esc_html( trim( (string) ( $attrs['freeLine'] ?? '' ) ) ?: __( 'Everything you need to start.', 'membuild-community' ) ) . '</span><ul>' . self::plan_ticks( 0, $attrs['free'] ?? '', __( 'What everyone gets, in a few words', 'membuild-community' ) ) . '</ul>'
				. ( $is_mine ? str_replace( 'class="mbs-btn"', 'class="mbs-btn mbs-btn-light"', $open ) : '<a class="mbs-btn mbs-btn-light" href="' . esc_url( Login::url( 'join' ) ) . '">' . esc_html( $button ) . '</a>' ) . '</div>';
		}
		$main = count( $paid ) - 1;
		foreach ( $paid as $i => list( $name, $price, $per, $url, $gid ) ) {
			$is_mine = in_array( (int) $gid, $mine, true );
			$badge   = $is_mine ? $yours : ( $i === $main ? '<span class="mbs-badge">' . esc_html( trim( (string) ( $attrs['badge'] ?? __( 'Most popular', 'membuild-community' ) ) ) ) . '</span>' : '' );
			$badge   = '<span class="mbs-badge"></span>' === $badge ? '' : $badge;
			$label   = trim( (string) ( $attrs['mainButton'] ?? '' ) );
			/* translators: %s: a membership's name, e.g. "VIP" */
			$label   = $i === $main && '' !== $label ? $label : sprintf( __( 'Become a %s', 'membuild-community' ), $name );
			$first   = $free ? __( 'Everything in Free', 'membuild-community' ) : __( 'The main reason to join', 'membuild-community' );
			$note    = $picked === (int) $gid && $course ? '<span class="mbs-plan-picked-note">' . esc_html__( 'Includes this course', 'membuild-community' ) . '</span>' : '';
			$cards  .= '<div class="mbs-plan mbs-plan-dark' . ( $picked === (int) $gid ? ' mbs-plan-picked' : '' ) . ( $is_mine ? ' mbs-plan-mine' : '' ) . '" id="plan-' . (int) $gid . '">' . $badge . $note . '<b class="mbs-plan-name">' . esc_html( $name ) . '</b><span class="mbs-price">' . esc_html( $price ) . ' <small>' . esc_html( $per ) . '</small></span><span class="mbs-plan-sum">' . esc_html( $i === $main ? ( trim( (string) ( $attrs['mainLine'] ?? '' ) ) ?: __( 'For people serious about growing.', 'membuild-community' ) ) : __( 'A good place to start.', 'membuild-community' ) ) . '</span><ul>' . self::plan_ticks( $gid, $attrs['paid'] ?? '', $first ) . '</ul>'
				. ( $is_mine ? $open : '<a class="mbs-btn" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' ) . '</div>';
		}
		$trust = '';
		$back  = Starter_Site::money_back();
		if ( ! empty( $attrs['trust'] ) ) {
			$trust = '<ul class="mbs-trust">' . ( $paid && $back ? '<li>' . esc_html( $back ) . '</li>' : '' ) . '<li>' . esc_html__( 'Cancel any time', 'membuild-community' ) . '</li><li>' . esc_html__( 'Secure checkout', 'membuild-community' ) . '</li></ul>';
			if ( $paid && $back ) {
				$made    = (array) ( get_option( Starter_Pages::OPTION, array() )['pages'] ?? array() );
				$refunds = ! empty( $made['refunds'] ) && get_post( (int) $made['refunds'] ) ? ' <a href="' . esc_url( get_permalink( (int) $made['refunds'] ) ) . '">' . esc_html__( 'Refunds & cancelling', 'membuild-community' ) . ' →</a>' : '';
				/* translators: %d: number of days */
				$trust  .= '<p class="mbs-money-back"><b>' . esc_html__( 'Not for you?', 'membuild-community' ) . '</b> ' . esc_html( sprintf( __( 'Write to us within %d days of your first payment for a full refund.', 'membuild-community' ), Starter_Site::refund_days() ) ) . $refunds . '</p>';
			}
		}
		// Phones show the paid plans first, with the free way in as a link under them.
		$start = $paid && $free && ! $in ? '<a class="mbs-phone-only mbs-start-free" href="' . esc_url( Login::url( 'join' ) ) . '">' . esc_html( sprintf( /* translators: %s: the free plan's button, e.g. "Join free" */ __( 'Or %s →', 'membuild-community' ), mb_strtolower( trim( (string) ( $attrs['freeButton'] ?? '' ) ) ?: __( 'Join free', 'membuild-community' ) ) ) ) . '</a>' : '';
		$count = (int) $free + count( $paid );
		return self::wrap( 'mbs-plans-wrap', '<div class="mbs-plans mbs-plans-' . $count . ( 1 === $count ? ' mbs-plans-one' : '' ) . ( $free ? '' : ' mbs-plans-paid' ) . '">' . $cards . '</div>' . $start . $trust, $attrs );
	}

	/** The Compare table's rows: [ label, [ 'free' | membership id, … ] ]. From the row list, else the old "words | yes" lines. */
	private static function compare_rows( $attrs, array $columns ) {
		$rows = array();
		$paid = array_values( array_filter( array_map( 'strval', $columns ), fn( $k ) => 'free' !== $k ) );
		if ( ! empty( $attrs['items'] ) && is_array( $attrs['items'] ) ) {
			foreach ( $attrs['items'] as $item ) {
				$label = trim( (string) ( $item['label'] ?? '' ) );
				$in    = array_map( 'strval', (array) ( $item['in'] ?? array() ) );
				if ( '' !== $label ) {
					$rows[] = array( $label, in_array( 'paid', $in, true ) ? array_merge( $in, $paid ) : $in ); // "paid": every paid plan
				}
			}
			return $rows;
		}
		foreach ( array_filter( array_map( 'trim', explode( "\n", (string) ( $attrs['rows'] ?? '' ) ) ) ) as $line ) {
			$bits   = array_map( 'trim', explode( '|', $line ) );
			$free   = in_array( strtolower( $bits[1] ?? 'no' ), array( 'yes', 'y', '✓', 'true' ), true );
			$rows[] = array( $bits[0], array_merge( $free ? array( 'free' ) : array(), $paid ) );
		}
		return $rows;
	}

	/** A column per plan (Free when it's on, then each paid plan), and a tick where a row is included. */
	public static function render_compare( $attrs ) {
		$paid    = self::plans_by_price();
		$columns = array();
		if ( self::show_free( $attrs ) || ! $paid ) {
			$columns['free'] = array( __( 'Free', 'membuild-community' ), '<a class="mbs-btn mbs-btn-light" href="' . esc_url( Login::url( 'join' ) ) . '">' . esc_html__( 'Join free', 'membuild-community' ) . '</a>' );
		}
		foreach ( $paid as $p ) {
			/* translators: %s: a membership's name */
			$columns[ (string) $p[4] ] = array( $p[0], '<a class="mbs-btn" href="' . esc_url( $p[3] ) . '">' . esc_html( sprintf( __( 'Become a %s', 'membuild-community' ), $p[0] ) ) . '</a>' );
		}
		if ( ! $paid ) {
			$columns['members'] = array( __( 'Members', 'membuild-community' ), '' ); // no paid plan yet: a plain Members column
		}
		$keys = array_keys( $columns );
		$yes  = '<span class="mbs-yes" role="img" aria-label="' . esc_attr__( 'Included', 'membuild-community' ) . '"></span>';
		$no   = '<span class="mbs-no" role="img" aria-label="' . esc_attr__( 'Not included', 'membuild-community' ) . '">—</span>';
		$last = end( $keys );
		$body = '';
		foreach ( self::compare_rows( $attrs, $keys ) as list( $label, $in ) ) {
			$body .= '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $keys as $k ) {
				$on    = in_array( (string) $k, $in, true );
				$body .= '<td' . ( $k === $last && 'free' !== $k ? ' class="mbs-paid"' : '' ) . '>' . ( $on ? $yes : $no ) . '</td>';
			}
			$body .= '</tr>';
		}
		$head = '<thead><tr><th scope="col">' . esc_html__( 'Included', 'membuild-community' ) . '</th>';
		$buy  = '<tr class="mbs-compare-buy"><td></td>';
		foreach ( $columns as $k => list( $name, $button ) ) {
			$cls   = $k === $last && 'free' !== $k ? ' class="mbs-paid"' : '';
			$head .= '<th scope="col"' . $cls . '>' . esc_html( $name ) . '</th>';
			$buy  .= '<td' . $cls . '>' . $button . '</td>';
		}
		return self::wrap( 'mbs-compare mbs-compare-' . count( $columns ), '<table>' . $head . '</tr></thead><tbody>' . $body . ( is_user_logged_in() ? '' : $buy . '</tr>' ) . '</tbody></table>', $attrs );
	}

	/** For the editor's Compare rows: a column per plan. */
	public static function compare_columns() {
		$out = array();
		if ( Login::registration_open() || ! self::paid_plans() ) {
			$out[] = array( 'key' => 'free', 'name' => __( 'Free', 'membuild-community' ) );
		}
		foreach ( self::plans_by_price() as $p ) {
			$out[] = array( 'key' => (string) $p[4], 'name' => $p[0] );
		}
		return $out;
	}

	/** The small live previews at the foot of the "What members get" cards. */
	public static function render_peek( $attrs ) {
		global $wpdb;
		$kind = (string) ( $attrs['kind'] ?? 'question' );
		$out  = '';
		if ( 'question' === $kind ) {
			$who = self::faces( 1 );
			$out = '<span class="mbs-peek-q">' . self::face_row( $who, 28 ) . '<span class="mbs-bubble">' . esc_html( (string) ( $attrs['question'] ?? '' ) ) . '</span></span><span class="mbs-bubble mbs-bubble-me">' . esc_html( (string) ( $attrs['answer'] ?? '' ) ) . '</span>';
		} elseif ( 'course' === $kind ) {
			$c = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, cover_url FROM %i WHERE status = 'published' ORDER BY sort_order ASC, id ASC LIMIT 1", Install::table( 'courses' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $c ) {
				$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $c['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				// The furthest a member has got that hasn't finished it: real progress, not a made-up one.
				$done  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM %i WHERE course_id = %d GROUP BY user_id) t WHERE n < %d', Install::table( 'lesson_progress' ), $c['id'], max( 1, $total ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$done  = $done ?: min( 1, $total );
				$cover = $c['cover_url'] ? '<img src="' . esc_url( $c['cover_url'] ) . '" alt="" loading="lazy">' : '';
				/* translators: 1: lessons done, 2: lessons in the course */
				$out = $cover . '<span class="mbs-peek-text"><b>' . esc_html( $c['title'] ) . '</b><span class="mbs-bar"><i style="width:' . ( $total ? round( 100 * $done / $total ) : 0 ) . '%"></i></span><small>' . esc_html( sprintf( __( '%1$d of %2$d lessons', 'membuild-community' ), $done, $total ) ) . '</small></span>';
			}
		} elseif ( 'event' === $kind ) {
			$e = self::next_events( 1 )[0] ?? null;
			if ( $e ) {
				list( , $n ) = self::going( (int) $e['id'] );
				/* translators: %d: people going */
				$out = self::date_tile( strtotime( $e['event_at'] . ' UTC' ) ) . '<span class="mbs-peek-text"><b>' . esc_html( $e['event_title'] ) . '</b>' . ( $n ? '<small>' . esc_html( sprintf( _n( '%d going', '%d going', $n, 'membuild-community' ), $n ) ) . '</small>' : '' ) . '</span>';
			}
		} else {
			$n = self::active_today();
			/* translators: %s: number of members */
			$out = self::face_row( self::faces( 4 ), 30 ) . '<span class="mbs-peek-text"><b>' . esc_html__( 'Active today', 'membuild-community' ) . '</b>' . ( $n ? '<small>' . esc_html( sprintf( _n( '%s member', '%s members', $n, 'membuild-community' ), number_format_i18n( $n ) ) ) . '</small>' : '' ) . '</span>';
		}
		return '' === $out ? '' : self::wrap( 'mbs-peek mbs-peek-' . sanitize_html_class( $kind ), $out, $attrs );
	}

	/** The moments floating around the phone in the Home hero, from the community's own activity. */
	public static function render_moments( $attrs ) {
		global $wpdb;
		$chips = array();
		/* translators: %d: points */
		$chips[] = '<span class="mbs-moment mbs-moment-1"><span class="mbs-moment-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span><span><b>' . esc_html__( 'Lesson done!', 'membuild-community' ) . '</b><br><span>' . esc_html( sprintf( __( '+%d points', 'membuild-community' ), (int) ( Points::rules()['lesson'][0] ?? 5 ) ) ) . '</span></span></span>';
		$like    = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM %i WHERE object_type = 'post' AND user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'mbcom_avatar_url' AND meta_value <> '') ORDER BY id DESC LIMIT 1", Install::table( 'reactions' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$user    = $like ? get_userdata( (int) $like ) : null;
		if ( $user ) {
			$first   = (string) ( $user->first_name ?: strtok( $user->display_name, ' ' ) );
			/* translators: %s: a member's first name */
			$chips[] = '<span class="mbs-moment mbs-moment-2">' . self::face_row( array( $user->ID ), 34 ) . '<span>' . wp_kses( sprintf( __( '%s loved your post', 'membuild-community' ), '<b>' . esc_html( $first ) . '</b>' ), array( 'b' => array() ) ) . '</span></span>';
		}
		$e = self::next_events( 1 )[0] ?? null;
		if ( $e ) {
			$at          = strtotime( $e['event_at'] . ' UTC' );
			list( , $n ) = self::going( (int) $e['id'] );
			/* translators: %d: people going */
			$chips[] = '<span class="mbs-moment mbs-moment-3">' . self::date_tile( $at ) . '<span><b>' . esc_html( $e['event_title'] ) . '</b><br><span>' . esc_html( wp_date( get_option( 'time_format' ), $at ) . ( $n ? ' · ' . sprintf( _n( '%d going', '%d going', $n, 'membuild-community' ), $n ) : '' ) ) . '</span></span></span>';
		}
		return self::wrap( 'mbs-moments', implode( '', $chips ), $attrs );
	}

	/** The host and moderators: as cards (About), or as one row with a button (Guidelines). */
	public static function render_team( $attrs ) {
		$team = self::team();
		if ( ! $team ) {
			return '';
		}
		$mods = array_slice( $team, 1 );
		if ( ! empty( $attrs['row'] ) ) {
			$names = array_map( fn( $id ) => (string) ( get_userdata( $id )->first_name ?: strtok( get_userdata( $id )->display_name, ' ' ) ), $mods ?: $team );
			$last  = array_pop( $names );
			/* translators: 1: names, 2: the last name ("Tasha or Marcus") */
			$who   = $names ? sprintf( __( '%1$s or %2$s', 'membuild-community' ), implode( ', ', $names ), $last ) : $last;
			/* translators: %s: names of the moderators */
			$line  = $mods ? sprintf( __( 'Message %s, our moderators. They’re friendly, promise.', 'membuild-community' ), $who ) : sprintf( __( 'Message %s. They’re friendly, promise.', 'membuild-community' ), $who );
			// Visitors can't message anyone yet: they get the contact page.
			$made   = (array) ( get_option( Starter_Pages::OPTION, array() )['pages'] ?? array() );
			$button = is_user_logged_in() || empty( $made['contact'] )
				? '<a class="mbs-btn mbs-btn-light" href="' . esc_url( Starter_Site::into( Portal::url() . 'messages' ) ) . '">' . esc_html__( 'Message a moderator', 'membuild-community' ) . '</a>'
				: '<a class="mbs-btn mbs-btn-light" href="' . esc_url( get_permalink( (int) $made['contact'] ) ) . '">' . esc_html__( 'Questions? Contact us', 'membuild-community' ) . '</a>';
			return self::wrap( 'mbs-team-row', self::face_row( $mods ?: $team, 52 ) . '<span class="mbs-team-words"><b>' . esc_html__( 'Questions about a rule?', 'membuild-community' ) . '</b><span>' . esc_html( $line ) . '</span></span>' . $button, $attrs );
		}
		$cards = '';
		foreach ( array_slice( $team, 0, 3 ) as $i => $id ) {
			$u      = get_userdata( $id );
			$bio    = wp_trim_words( (string) get_user_meta( $id, 'mbcom_bio', true ), 10 );
			$cards .= '<div class="mbs-person"><img src="' . esc_url( self::photo( $id, 96 ) ) . '" alt="" width="96" height="96" loading="lazy"><b>' . esc_html( $u->display_name ) . '</b><span class="mbs-tag mbs-tag-role">' . esc_html( 0 === $i ? __( 'Host', 'membuild-community' ) : __( 'Moderator', 'membuild-community' ) ) . '</span>' . ( $bio ? '<span class="mbs-person-bio">' . esc_html( $bio ) . '</span>' : '' ) . '</div>';
		}
		return self::wrap( 'mbs-team', $cards, $attrs );
	}

	public static function render_contact_form( $attrs ) {
		$field = fn( $name, $label, $hint, $type = 'text' ) => '<label><b>' . esc_html( $label ) . '</b>' . ( 'textarea' === $type ? '<textarea name="' . $name . '" rows="5" placeholder="' . esc_attr( $hint ) . '" required></textarea>' : '<input name="' . $name . '" type="' . $type . '" placeholder="' . esc_attr( $hint ) . '" autocomplete="' . ( 'email' === $type ? 'email' : 'name' ) . '" required>' ) . '</label>';
		$host  = self::team()[0] ?? 0;
		/* translators: %s: the host's first name */
		$note  = ! empty( $attrs['note'] ) && $host ? '<span class="mbs-form-note">' . self::face_row( array( $host ), 34 ) . esc_html( sprintf( __( '%s usually replies within a day.', 'membuild-community' ), (string) ( get_userdata( $host )->first_name ?: strtok( get_userdata( $host )->display_name, ' ' ) ) ) ) . '</span>' : '';
		// No WordPress safety code for visitors: on a cached page it runs out after a day and every
		// message would fail. The hidden spam trap and the send limit protect the form.
		$nonce = is_user_logged_in() ? ' data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"' : '';
		$form  = '<form class="mbs-contact" data-endpoint="' . esc_url( rest_url( MEMBUILD_COMMUNITY_REST_NS . '/contact' ) ) . '"' . $nonce . '>'
			. $field( 'name', __( 'Your name', 'membuild-community' ), __( 'Grace Kim', 'membuild-community' ) )
			. $field( 'email', __( 'Your email', 'membuild-community' ), __( 'grace@example.com', 'membuild-community' ), 'email' )
			. $field( 'message', __( 'How can we help?', 'membuild-community' ), __( 'Tell us a little about it…', 'membuild-community' ), 'textarea' )
			. '<input class="mbs-trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'
			. '<button type="submit" class="mbs-btn mbs-btn-wide">' . esc_html__( 'Send message', 'membuild-community' ) . '</button>'
			. '<p class="mbs-form-said" role="status"></p>' . $note . '</form>';
		$js = '<script>(function(f){f.addEventListener("submit",function(e){e.preventDefault();var b=f.querySelector("button"),s=f.querySelector(".mbs-form-said"),d=Object.fromEntries(new FormData(f));b.disabled=true;fetch(f.dataset.endpoint,{method:"POST",headers:Object.assign({"Content-Type":"application/json"},f.dataset.nonce?{"X-WP-Nonce":f.dataset.nonce}:{}),body:JSON.stringify(d)}).then(function(r){return r.json().then(function(j){return[r.ok,j]})}).then(function(x){s.textContent=x[0]?' . wp_json_encode( __( 'Thanks! We’ll reply by email soon.', 'membuild-community' ) ) . ':(x[1].message||' . wp_json_encode( __( 'That didn’t send. Please try again.', 'membuild-community' ) ) . ');if(x[0])f.reset();b.disabled=false}).catch(function(){s.textContent=' . wp_json_encode( __( 'That didn’t send. Please try again.', 'membuild-community' ) ) . ';b.disabled=false})})})(document.currentScript.previousElementSibling)</script>';
		return self::wrap( 'mbs-form', $form . $js, $attrs );
	}

	/**
	 * Coming soon: one email box. After "Keep me posted" it says "You're on the list". The
	 * "N people are waiting" line shows from 20, like the other live numbers.
	 */
	public static function render_waitlist( $attrs ) {
		$n      = Coming_Soon::count();
		$opens  = Coming_Soon::opens_text();
		$button = trim( (string) ( $attrs['button'] ?? '' ) ) ?: __( 'Keep me posted', 'membuild-community' );
		/* translators: %s: number of people */
		$wait   = $n >= self::SHOW_FROM['waiting'] ? '<p class="mbs-wait-count">' . self::face_row( self::faces( 4 ), 30 ) . '<span>' . esc_html( sprintf( _n( '%s person is waiting', '%s people are waiting', $n, 'membuild-community' ), number_format_i18n( $n ) ) ) . '</span></p>' : '';
		/* translators: %s: a date, e.g. "Mon, Dec 1" */
		$when   = $opens ? sprintf( __( 'We’ll email %1$s on %2$s, when the doors open.', 'membuild-community' ), '{email}', $opens ) : __( 'We’ll email {email} when the doors open.', 'membuild-community' );
		$form   = '<form class="mbs-wait-form" data-endpoint="' . esc_url( rest_url( MEMBUILD_COMMUNITY_REST_NS . '/waitlist' ) ) . '">'
			. '<label class="mbs-sr" for="mbs-wait-email">' . esc_html__( 'Your email', 'membuild-community' ) . '</label>'
			. '<input id="mbs-wait-email" name="email" type="email" autocomplete="email" placeholder="' . esc_attr__( 'Your email', 'membuild-community' ) . '" required>'
			. '<input class="mbs-trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'
			. '<button type="submit" class="mbs-btn">' . esc_html( $button ) . '</button>'
			. '<p class="mbs-form-said" role="status"></p></form>';
		$done   = '<div class="mbs-wait-done" hidden tabindex="-1"><span class="mbs-wait-tick" aria-hidden="true"></span><b>' . esc_html__( 'You’re on the list', 'membuild-community' ) . '</b><span class="mbs-wait-when" data-when="' . esc_attr( $when ) . '"></span>'
			. '<button type="button" class="mbs-btn mbs-btn-light mbs-wait-share" data-url="' . esc_url( home_url( '/' ) ) . '" data-done="' . esc_attr__( 'Link copied', 'membuild-community' ) . '">' . esc_html__( 'Tell a friend', 'membuild-community' ) . '</button>'
			. '<small>' . esc_html__( 'Changed your mind? One click in any email takes you off.', 'membuild-community' ) . '</small></div>';
		$js     = '<script>(function(w){var f=w.querySelector("form"),d=w.querySelector(".mbs-wait-done"),s=f.querySelector(".mbs-form-said");f.addEventListener("submit",function(e){e.preventDefault();var b=f.querySelector("button"),x=Object.fromEntries(new FormData(f));b.disabled=true;fetch(f.dataset.endpoint,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(x)}).then(function(r){return r.json().then(function(j){return[r.ok,j]})}).then(function(r){b.disabled=false;if(!r[0]){s.textContent=r[1].message||' . wp_json_encode( __( 'That didn’t work. Please try again.', 'membuild-community' ) ) . ';return}var t=d.querySelector(".mbs-wait-when");t.textContent=t.dataset.when.replace("{email}",x.email);w.classList.add("is-done");f.hidden=true;d.hidden=false;d.focus()}).catch(function(){b.disabled=false;s.textContent=' . wp_json_encode( __( 'That didn’t work. Please try again.', 'membuild-community' ) ) . '})});w.querySelector(".mbs-wait-share").addEventListener("click",function(){var u=this.dataset.url,b=this;if(navigator.share){navigator.share({url:u}).catch(function(){})}else if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){b.textContent=b.dataset.done})}})})(document.currentScript.previousElementSibling)</script>';
		return self::wrap( 'mbs-wait', $form . $done . $wait, $attrs ) . $js;
	}

	/** Coming soon: "Opening Mon, Dec 1" from Settings, or "Opening soon" with no date. */
	public static function render_opening( $attrs ) {
		$day = Coming_Soon::opens_text();
		/* translators: %s: a date, e.g. "Mon, Dec 1" */
		$text = $day ? sprintf( __( 'Opening %s', 'membuild-community' ), $day ) : ( trim( (string) ( $attrs['fallback'] ?? '' ) ) ?: __( 'Opening soon', 'membuild-community' ) );
		return self::wrap( 'mbs-pill mbs-pill-live', esc_html( $text ), $attrs, 'p' );
	}
}
