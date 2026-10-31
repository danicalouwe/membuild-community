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
			'faces'        => array(
				'count'   => array( 'type' => 'number', 'default' => 6 ),
				'size'    => array( 'type' => 'number', 'default' => 44 ),
				'label'   => array( 'type' => 'boolean', 'default' => true ),
				'note'    => array( 'type' => 'string', 'default' => '' ),
				'gallery' => array( 'type' => 'boolean', 'default' => false ),
			),
			'plans'        => array(
				'free'  => array( 'type' => 'string', 'default' => "The community and chat\nStart Here course\nWeekly Q&A calls" ),
				'paid'  => array( 'type' => 'string', 'default' => "Everything in Free\nEvery course\nMonthly workshops + recordings" ),
				'trust' => array( 'type' => 'boolean', 'default' => false ),
			),
			'compare'      => array( 'rows' => array( 'type' => 'string', 'default' => "The community, posts and chat | yes\nStart Here course | yes\nWeekly Q&A calls | yes\nEvery other course | no\nMonthly workshops + recordings | no\nMembers-only chats and events | no" ) ),
			'contact-form' => array( 'note' => array( 'type' => 'boolean', 'default' => true ) ),
			'stat'         => array(
				'kind'     => array( 'type' => 'string', 'default' => 'members' ), // members | courses | active
				'format'   => array( 'type' => 'string', 'default' => '%s' ),
				'pill'     => array( 'type' => 'boolean', 'default' => false ),
				'min'      => array( 'type' => 'number', 'default' => 0 ),
				'fallback' => array( 'type' => 'string', 'default' => '' ),
			),
			'peek'         => array(
				'kind'     => array( 'type' => 'string', 'default' => 'question' ), // question | course | event | people
				'question' => array( 'type' => 'string', 'default' => 'How did you price your first tier?' ),
				'answer'   => array( 'type' => 'string', 'default' => 'Started at $19, then $29 ✨' ),
			),
			'moments'      => array(),
			'team'         => array( 'row' => array( 'type' => 'boolean', 'default' => false ) ),
		);
	}

	public static function register() {
		wp_register_script( 'mbcom-starter-blocks', MEMBUILD_COMMUNITY_URL . 'assets/starter/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ), MEMBUILD_COMMUNITY_VERSION, true );
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

	/** Members who used the community in the last day. */
	public static function active_today() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d", Online::SEEN, time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function members() {
		return (int) count_users()['total_users'];
	}

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

	public static function next_events( $limit ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, event_title, event_at, event_minutes FROM %i WHERE status = 'published' AND event_title <> '' AND event_at > %s AND event_status = '' ORDER BY event_at ASC LIMIT %d", Install::table( 'posts' ), gmdate( 'Y-m-d H:i:s' ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function going( $event_id, $limit = 3 ) {
		global $wpdb;
		$t = Install::table( 'reactions' );
		return array(
			array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE object_type = 'event' AND object_id = %d ORDER BY id DESC LIMIT %d", $t, $event_id, $limit ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'event' AND object_id = %d", $t, $event_id ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/** 'free' | 'level' | 'vip', the tag and what the button says, from who can open a course. */
	private static function access( array $c ) {
		$level  = (int) $c['unlock_level'];
		$groups = json_decode( (string) $c['access_groups'], true ) ?: array();
		if ( $groups ) {
			return array( 'vip', __( 'Members', 'membuild-community' ), $c['access_cta_label'] ?: __( 'See plans', 'membuild-community' ) );
		}
		if ( $level > 1 ) {
			/* translators: %d: a level */
			return array( 'level', sprintf( __( 'Level %d', 'membuild-community' ), $level ), sprintf( __( 'Join, then reach Level %d', 'membuild-community' ), $level ) );
		}
		return array( 'free', __( 'Free', 'membuild-community' ), __( 'Join to start', 'membuild-community' ) );
	}

	/** One list item per line of an owner's text, each with a tick. */
	private static function ticks( $text ) {
		return implode( '', array_map( fn( $line ) => '<li>' . esc_html( $line ) . '</li>', array_filter( array_map( 'trim', explode( "\n", (string) $text ) ) ) ) );
	}

	/** Each membership sold with FluentCart: [name, price, "/ month", checkout]. */
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
				$paid[] = array( preg_replace( '/\s*Members$/i', '', $name ), $item['price_text'], array( 'month' => __( '/ month', 'membuild-community' ), 'year' => __( '/ year', 'membuild-community' ) )[ $item['period'] ] ?? '', $item['checkout'] );
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
		if ( $n < (int) ( $attrs['min'] ?? 0 ) ) {
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
			$link = $more > 0 ? '<a class="mbs-more" href="' . esc_url( Portal::url() . 'members' ) . '">' . esc_html( sprintf( __( '…and %s more →', 'membuild-community' ), number_format_i18n( $more ) ) ) . '</a>' : '';
			return self::wrap( 'mbs-gallery', '<span class="mbs-gallery-grid" style="--size:' . $size . 'px">' . $grid . '</span>' . $link, $attrs );
		}
		$note  = trim( (string) ( $attrs['note'] ?? '' ) );
		/* translators: %s: number of members */
		$count = '<b>' . esc_html( sprintf( _n( '%s member', '%s members', $total, 'membuild-community' ), number_format_i18n( $total ) ) ) . '</b> ' . esc_html__( 'and growing', 'membuild-community' );
		$label = ! empty( $attrs['label'] ) ? '<span class="mbs-faces-label">' . $count . ( $note ? '<small>' . esc_html( $note ) . '</small>' : '' ) . '</span>' : '';
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
			list( $kind, $tag, $cta ) = self::access( $c );
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
				$body .= '<span class="mbs-course-meta">' . esc_html( $meta ) . '</span><span class="mbs-course-cta">' . esc_html__( 'Start', 'membuild-community' ) . ' →</span>';
			}
			$cards .= '<a class="mbs-course" data-kind="' . esc_attr( $kind ) . '" href="' . esc_url( Portal::url() . 'classroom/' . (int) $c['id'] ) . '">' . $cover . '<span class="mbs-course-body">' . $body . '</span></a>';
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

	public static function render_events( $attrs ) {
		$events = self::next_events( max( 1, min( 8, (int) ( $attrs['limit'] ?? 3 ) ) ) );
		if ( ! $events ) {
			return self::wrap( 'mbs-empty', esc_html__( 'Upcoming events show here once you add one in the community.', 'membuild-community' ), $attrs );
		}
		$rows = '';
		foreach ( $events as $e ) {
			$at            = strtotime( $e['event_at'] . ' UTC' );
			list( $going ) = self::going( (int) $e['id'] );
			/* translators: 1: a time, 2: a length in minutes */
			$when  = sprintf( __( '%1$s · %2$d min · online', 'membuild-community' ), wp_date( get_option( 'time_format' ), $at ), (int) $e['event_minutes'] );
			$rows .= '<a class="mbs-event" href="' . esc_url( Portal::url() . 'events/' . (int) $e['id'] ) . '">' . self::date_tile( $at ) . '<span class="mbs-event-text"><b>' . esc_html( $e['event_title'] ) . '</b><span>' . esc_html( $when ) . '</span></span>' . self::face_row( $going, 30 ) . '<span class="mbs-rsvp">' . esc_html__( 'RSVP', 'membuild-community' ) . '</span></a>';
		}
		return self::wrap( 'mbs-events', $rows, $attrs );
	}

	/** Free, and each membership sold with FluentCart, its price and checkout as they are now. */
	public static function render_plans( $attrs ) {
		$cards = '<div class="mbs-plan"><b class="mbs-plan-name">' . esc_html__( 'Free', 'membuild-community' ) . '</b><span class="mbs-price">$0</span><span class="mbs-plan-sum">' . esc_html__( 'Everything you need to start.', 'membuild-community' ) . '</span><ul>' . self::ticks( $attrs['free'] ?? '' ) . '</ul><a class="mbs-btn mbs-btn-light" href="' . esc_url( Login::url( 'join' ) ) . '">' . esc_html__( 'Join free', 'membuild-community' ) . '</a></div>';
		$paid  = self::paid_plans();
		foreach ( $paid as $i => list( $name, $price, $per, $url ) ) {
			$badge  = 0 === $i ? '<span class="mbs-badge">' . esc_html__( 'Most popular', 'membuild-community' ) . '</span>' : '';
			/* translators: %s: a membership's name, e.g. "VIP" */
			$cards .= '<div class="mbs-plan mbs-plan-dark">' . $badge . '<b class="mbs-plan-name">' . esc_html( $name ) . '</b><span class="mbs-price">' . esc_html( $price ) . ' <small>' . esc_html( $per ) . '</small></span><span class="mbs-plan-sum">' . esc_html__( 'For people serious about growing.', 'membuild-community' ) . '</span><ul>' . self::ticks( $attrs['paid'] ?? '' ) . '</ul><a class="mbs-btn" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Become a %s', 'membuild-community' ), $name ) ) . '</a></div>';
		}
		$trust = '';
		if ( ! empty( $attrs['trust'] ) ) {
			$trust = '<ul class="mbs-trust"><li>' . esc_html__( 'Secure checkout', 'membuild-community' ) . '</li><li>' . esc_html__( 'Cancel any time', 'membuild-community' ) . '</li><li>' . esc_html__( 'No setup fees', 'membuild-community' ) . '</li></ul>';
		}
		// Phones show the paid plan first, with the free way in as a link under it.
		$start = $paid ? '<a class="mbs-phone-only mbs-start-free" href="' . esc_url( Login::url( 'join' ) ) . '">' . esc_html__( 'Or start free →', 'membuild-community' ) . '</a>' : '';
		return self::wrap( 'mbs-plans-wrap', '<div class="mbs-plans' . ( $paid ? '' : ' mbs-plans-one' ) . '">' . $cards . '</div>' . $start . $trust, $attrs );
	}

	/** Free beside the first paid membership, a line per thing: "The community | yes". */
	public static function render_compare( $attrs ) {
		$plan = self::paid_plans()[0] ?? null;
		$paid = $plan ?: array( __( 'Members', 'membuild-community' ), '', '', Login::url( 'join' ) ); // no paid plan yet: a plain Members column
		$yes  = '<span class="mbs-yes" role="img" aria-label="' . esc_attr__( 'Included', 'membuild-community' ) . '"></span>';
		$no   = '<span class="mbs-no" role="img" aria-label="' . esc_attr__( 'Not included', 'membuild-community' ) . '">—</span>';
		$rows = '';
		foreach ( array_filter( array_map( 'trim', explode( "\n", (string) ( $attrs['rows'] ?? '' ) ) ) ) as $line ) {
			$bits  = array_map( 'trim', explode( '|', $line ) );
			$free  = in_array( strtolower( $bits[1] ?? 'no' ), array( 'yes', 'y', '✓', 'true' ), true );
			$rows .= '<tr><th scope="row">' . esc_html( $bits[0] ) . '</th><td>' . ( $free ? $yes : $no ) . '</td><td class="mbs-paid">' . $yes . '</td></tr>';
		}
		/* translators: %s: a membership's name */
		$buy  = '<tr class="mbs-compare-buy"><td></td><td><a class="mbs-btn mbs-btn-light" href="' . esc_url( Login::url( 'join' ) ) . '">' . esc_html__( 'Join free', 'membuild-community' ) . '</a></td><td class="mbs-paid">' . ( $plan ? '<a class="mbs-btn" href="' . esc_url( $paid[3] ) . '">' . esc_html( sprintf( __( 'Become a %s', 'membuild-community' ), $paid[0] ) ) . '</a>' : '' ) . '</td></tr>';
		$head = '<thead><tr><th scope="col">' . esc_html__( 'Included', 'membuild-community' ) . '</th><th scope="col">' . esc_html__( 'Free', 'membuild-community' ) . '</th><th scope="col" class="mbs-paid">' . esc_html( $paid[0] ) . '</th></tr></thead>';
		return self::wrap( 'mbs-compare', '<table>' . $head . '<tbody>' . $rows . $buy . '</tbody></table>', $attrs );
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
		$chips[] = '<span class="mbs-moment mbs-moment-1"><span class="mbs-moment-icon" aria-hidden="true">🎉</span><span><b>' . esc_html__( 'Lesson done!', 'membuild-community' ) . '</b><br><span>' . esc_html( sprintf( __( '+%d points', 'membuild-community' ), (int) ( Points::rules()['lesson'][0] ?? 5 ) ) ) . '</span></span></span>';
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
			return self::wrap( 'mbs-team-row', self::face_row( $mods ?: $team, 52 ) . '<span class="mbs-team-words"><b>' . esc_html__( 'Questions about a rule?', 'membuild-community' ) . '</b><span>' . esc_html( $line ) . '</span></span><a class="mbs-btn mbs-btn-light" href="' . esc_url( Portal::url() . 'messages' ) . '">' . esc_html__( 'Message a moderator', 'membuild-community' ) . '</a>', $attrs );
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
		$form  = '<form class="mbs-contact" data-endpoint="' . esc_url( rest_url( MEMBUILD_COMMUNITY_REST_NS . '/contact' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">'
			. $field( 'name', __( 'Your name', 'membuild-community' ), __( 'Grace Kim', 'membuild-community' ) )
			. $field( 'email', __( 'Your email', 'membuild-community' ), __( 'grace@example.com', 'membuild-community' ), 'email' )
			. $field( 'message', __( 'How can we help?', 'membuild-community' ), __( 'Tell us a little about it…', 'membuild-community' ), 'textarea' )
			. '<input class="mbs-trap" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">'
			. '<button type="submit" class="mbs-btn mbs-btn-wide">' . esc_html__( 'Send message', 'membuild-community' ) . '</button>'
			. '<p class="mbs-form-said" role="status"></p>' . $note . '</form>';
		$js = '<script>(function(f){f.addEventListener("submit",function(e){e.preventDefault();var b=f.querySelector("button"),s=f.querySelector(".mbs-form-said"),d=Object.fromEntries(new FormData(f));b.disabled=true;fetch(f.dataset.endpoint,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":f.dataset.nonce},body:JSON.stringify(d)}).then(function(r){return r.json().then(function(j){return[r.ok,j]})}).then(function(x){s.textContent=x[0]?' . wp_json_encode( __( 'Thanks! We’ll reply by email soon.', 'membuild-community' ) ) . ':(x[1].message||' . wp_json_encode( __( 'That didn’t send. Please try again.', 'membuild-community' ) ) . ');if(x[0])f.reset();b.disabled=false}).catch(function(){s.textContent=' . wp_json_encode( __( 'That didn’t send. Please try again.', 'membuild-community' ) ) . ';b.disabled=false})})})(document.currentScript.previousElementSibling)</script>';
		return self::wrap( 'mbs-form', $form . $js, $attrs );
	}
}
