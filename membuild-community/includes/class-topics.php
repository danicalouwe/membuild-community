<?php
/**
 * Topics: labels on posts that members filter Home by (Introductions, Wins, Pricing club…).
 * A post has one topic or none. Owners make them in Settings › Topics.
 *
 * A topic can be tied to one membership (access group): then only its members (and staff)
 * see the topic and its posts, enforced here in SQL like Events::visible_sql(), so nothing
 * depends on the app hiding them. A topic can also be "only admins and moderators post"
 * (an Announcements topic): members read and comment.
 *
 * Kept simple on purpose: a topic has no members, feed or settings of its own. Those are
 * Spaces, in MemBuild Community Pro.
 *
 *   GET    /topics                         the topics this member can see, with post counts
 *   POST   /topics                         (admins) {name, color, description, group_id, staff_only}
 *   PUT    /topics/{id}                    (admins) the same fields
 *   DELETE /topics/{id}                    (admins) {move_to}: its posts move there, or lose their topic
 *   PUT    /topics/order                   (admins) {ids} in their new order
 *   PUT    /posts/{id}/topic               (admins, moderators) {topic_id}: move a post; its author is told
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Topics {

	/** The colours a topic can have: each reads on white and on the grey fills. */
	const COLORS = array( '#c92a2a', '#2f6be0', '#087a66', '#c2410c', '#b4338a', '#6c47ff', '#646973' );

	const MAX = 30;

	private static $rows = null;

	public static function register_routes() {
		$member = array( Rest_Feed::class, 'logged_in' );
		$admin  = fn() => Rest_Feed::logged_in() && Helpers::can_manage();
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/topics', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $member ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $admin ),
		) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/topics/order', array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_order' ), 'permission_callback' => $admin ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/topics/(?P<id>\d+)', array(
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_update' ), 'permission_callback' => $admin ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_delete' ), 'permission_callback' => $admin ),
		) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/posts/(?P<id>\d+)/topic', array(
			'methods'             => 'PUT',
			'callback'            => array( __CLASS__, 'rest_move_post' ),
			'permission_callback' => fn() => Rest_Feed::logged_in() && Helpers::can_moderate(),
		) );
	}

	/* ---------- Reading ---------- */

	/** Every topic, in order (cached for the request). */
	public static function all() {
		global $wpdb;
		if ( null === self::$rows ) {
			self::$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', Install::table( 'topics' ) ), ARRAY_A ) ?: array();
		}
		return self::$rows;
	}

	public static function get( $id ) {
		foreach ( self::all() as $t ) {
			if ( (int) $t['id'] === (int) $id ) {
				return $t;
			}
		}
		return null;
	}

	/** Can $user_id (default: the current member) see this topic and its posts? */
	public static function can_see( $topic, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		$group   = (int) ( $topic['group_id'] ?? 0 );
		return ! $group || Settings::is_staff( $user_id ) || in_array( $group, Access::member_groups( $user_id ), true );
	}

	/** Can they file a post in it? */
	public static function can_post_in( $topic, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		return self::can_see( $topic, $user_id ) && ( empty( $topic['staff_only'] ) || Settings::is_staff( $user_id ) );
	}

	/**
	 * SQL keeping posts to ones whose topic (if any) the member may see: array( sql, values ).
	 * Topics are few, so the hidden ones are worked out here and left out by id.
	 */
	public static function visible_sql( $alias = '' ) {
		$p      = $alias ? "{$alias}." : '';
		$hidden = array();
		foreach ( self::all() as $t ) {
			if ( ! self::can_see( $t ) ) {
				$hidden[] = (int) $t['id'];
			}
		}
		if ( ! $hidden ) {
			return array( '1 = 1', array() );
		}
		return array( "{$p}topic_id NOT IN (" . implode( ',', array_fill( 0, count( $hidden ), '%d' ) ) . ')', $hidden );
	}

	/** What a post carries about its topic: { id, name, color } or null. */
	public static function brief( $topic_id ) {
		$t = $topic_id ? self::get( $topic_id ) : null;
		return $t ? array( 'id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'] ) : null;
	}

	/** The topics this member sees, for the app: on Home, in Create post and in Settings. */
	public static function for_member() {
		global $wpdb;
		$counts = array();
		if ( self::all() ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT topic_id, COUNT(*) AS n FROM %i WHERE status = 'published' AND topic_id > 0 GROUP BY topic_id", Install::table( 'posts' ) ), ARRAY_A ) as $r ) {
				$counts[ (int) $r['topic_id'] ] = (int) $r['n'];
			}
		}
		$out = array();
		foreach ( self::all() as $t ) {
			if ( ! self::can_see( $t ) ) {
				continue;
			}
			$out[] = array(
				'id'          => (int) $t['id'],
				'name'        => $t['name'],
				'color'       => $t['color'],
				'description' => (string) $t['description'],
				'group_id'    => (int) $t['group_id'],
				'members_only' => (bool) (int) $t['group_id'],
				'staff_only'  => (bool) (int) $t['staff_only'],
				'can_post'    => self::can_post_in( $t ),
				'posts'       => $counts[ (int) $t['id'] ] ?? 0,
			);
		}
		return $out;
	}

	public static function rest_list() {
		return array( 'items' => self::for_member(), 'on_home' => (bool) Settings::get( 'topics_on_home' ), 'colors' => self::COLORS );
	}

	/* ---------- Owners ---------- */

	private static function clean( \WP_REST_Request $req, $current = array() ) {
		$name = trim( Helpers::clean_text( $req['name'] ?? ( $current['name'] ?? '' ), 40 ) );
		if ( '' === $name ) {
			return new \WP_Error( 'mbcom_invalid', __( 'Give the topic a name.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		foreach ( self::all() as $t ) {
			if ( (int) $t['id'] !== (int) ( $current['id'] ?? 0 ) && 0 === strcasecmp( $t['name'], $name ) ) {
				return new \WP_Error( 'mbcom_invalid', __( 'There’s already a topic with that name.', 'membuild-community' ), array( 'status' => 400 ) );
			}
		}
		$color = strtolower( (string) ( $req['color'] ?? ( $current['color'] ?? '' ) ) );
		$group = isset( $req['group_id'] ) ? (int) $req['group_id'] : (int) ( $current['group_id'] ?? 0 );
		if ( $group && ! in_array( $group, array_map( fn( $g ) => (int) $g['id'], Access::groups() ), true ) ) {
			return new \WP_Error( 'mbcom_invalid', __( 'That membership doesn’t exist any more.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		return array(
			'name'        => $name,
			'color'       => in_array( $color, self::COLORS, true ) ? $color : self::COLORS[ count( self::all() ) % count( self::COLORS ) ],
			'description' => Helpers::clean_text( $req['description'] ?? ( $current['description'] ?? '' ), 120 ),
			'group_id'    => $group,
			'staff_only'  => isset( $req['staff_only'] ) ? ( $req['staff_only'] ? 1 : 0 ) : (int) ( $current['staff_only'] ?? 0 ),
		);
	}

	/** A topic from Import: name, colour, description and membership, already decided. */
	public static function add( array $data ) {
		global $wpdb;
		$wpdb->insert(
			Install::table( 'topics' ),
			array(
				'name'        => mb_substr( sanitize_text_field( $data['name'] ), 0, 40 ),
				'color'       => in_array( $data['color'] ?? '', self::COLORS, true ) ? $data['color'] : self::COLORS[0],
				'description' => mb_substr( sanitize_text_field( Import::text( $data['description'] ?? '' ) ), 0, 120 ),
				'group_id'    => (int) ( $data['group_id'] ?? 0 ),
				'sort_order'  => count( self::all() ),
				'created_at'  => Helpers::now(),
			)
		);
		self::$rows = null;
		return (int) $wpdb->insert_id;
	}

	/** The topic with this name, any case, if there is one. */
	public static function by_name( $name ) {
		$name = mb_strtolower( trim( mb_substr( sanitize_text_field( $name ), 0, 40 ) ) );
		foreach ( self::all() as $t ) {
			if ( mb_strtolower( $t['name'] ) === $name ) {
				return $t;
			}
		}
		return null;
	}

	public static function forget() {
		self::$rows = null;
	}

	public static function rest_create( \WP_REST_Request $req ) {
		global $wpdb;
		if ( count( self::all() ) >= self::MAX ) {
			/* translators: %d: the most topics a community can have */
			return new \WP_Error( 'mbcom_invalid', sprintf( __( 'A community can have up to %d topics.', 'membuild-community' ), self::MAX ), array( 'status' => 400 ) );
		}
		$data = self::clean( $req );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['sort_order'] = count( self::all() );
		$data['created_at'] = Helpers::now();
		$wpdb->insert( Install::table( 'topics' ), $data );
		self::$rows = null;
		$response = rest_ensure_response( self::rest_list() );
		$response->set_status( 201 );
		return $response;
	}

	public static function rest_update( \WP_REST_Request $req ) {
		global $wpdb;
		$topic = self::get( (int) $req['id'] );
		if ( ! $topic ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Topic not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$data = self::clean( $req, $topic );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$wpdb->update( Install::table( 'topics' ), $data, array( 'id' => $topic['id'] ) );
		self::$rows = null;
		return self::rest_list();
	}

	/** Deleting a topic never deletes posts: they move to another topic, or keep going without one. */
	public static function rest_delete( \WP_REST_Request $req ) {
		global $wpdb;
		$topic = self::get( (int) $req['id'] );
		if ( ! $topic ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Topic not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$to = self::get( (int) $req['move_to'] );
		$to = $to && (int) $to['id'] !== (int) $topic['id'] ? (int) $to['id'] : 0;
		$wpdb->update( Install::table( 'posts' ), array( 'topic_id' => $to ), array( 'topic_id' => $topic['id'] ) );
		$wpdb->delete( Install::table( 'topics' ), array( 'id' => $topic['id'] ) );
		self::$rows = null;
		return self::rest_list();
	}

	public static function rest_order( \WP_REST_Request $req ) {
		global $wpdb;
		$ids   = array_values( array_unique( array_map( 'intval', (array) $req['ids'] ) ) );
		$known = array_map( fn( $t ) => (int) $t['id'], self::all() );
		$ids   = array_merge( array_values( array_intersect( $ids, $known ) ), array_values( array_diff( $known, $ids ) ) ); // anything left out keeps its place at the end
		foreach ( $ids as $i => $id ) {
			$wpdb->update( Install::table( 'topics' ), array( 'sort_order' => $i ), array( 'id' => $id ) );
		}
		self::$rows = null;
		return self::rest_list();
	}

	/* ---------- Moving a post ---------- */

	/** A moderator puts a post in the right topic (or none); its author gets a notification. */
	public static function rest_move_post( \WP_REST_Request $req ) {
		global $wpdb;
		$post = Rest_Feed::row( (int) $req['id'] );
		if ( ! $post || (int) $post['course_id'] || $post['event_at'] ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$topic_id = (int) $req['topic_id'];
		$topic    = $topic_id ? self::get( $topic_id ) : null;
		if ( $topic_id && ! $topic ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Topic not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $post['topic_id'] !== $topic_id ) {
			$wpdb->update( Install::table( 'posts' ), array( 'topic_id' => $topic_id ), array( 'id' => $post['id'] ) );
			/**
			 * A moderator moved a post to another topic (0: no topic).
			 *
			 * @param int $post_id  The post.
			 * @param int $topic_id Its new topic.
			 * @param int $by       Who moved it.
			 */
			do_action( 'membuild_community/post_topic_changed', (int) $post['id'], $topic_id, get_current_user_id() );
			if ( (int) $post['user_id'] !== get_current_user_id() ) {
				Notifications::notify( (int) $post['user_id'], 'post_moved', 'post', (int) $post['id'], (int) $post['id'], get_current_user_id(), array( 'topic' => $topic ? $topic['name'] : '' ) );
			}
		}
		return Rest_Feed::format_posts( array( Rest_Feed::row( (int) $post['id'] ) ) )[0];
	}

	/**
	 * The topic a new or edited post goes in: a topic the author may post in, else 0.
	 * Course and event posts have no topic.
	 *
	 * @return int|\WP_Error
	 */
	public static function for_post( $topic_id, $user_id ) {
		$topic_id = (int) $topic_id;
		if ( ! $topic_id ) {
			return 0;
		}
		$topic = self::get( $topic_id );
		if ( ! $topic || ! self::can_see( $topic, $user_id ) ) {
			return new \WP_Error( 'mbcom_invalid', __( 'That topic isn’t available.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_post_in( $topic, $user_id ) ) {
			return new \WP_Error( 'mbcom_posting_staff', __( 'Only admins and moderators post in this topic.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		return $topic_id;
	}
}
