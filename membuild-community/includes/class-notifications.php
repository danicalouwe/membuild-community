<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Facebook-style notifications.
 *
 * Similar events are grouped into one row while it is unread, for example
 * "Maya and 2 others reacted to your post". The bell count is what you haven't
 * *seen* (cleared when the panel opens); each item stays highlighted until it
 * is *read* (clicked). Messages have their own badge and never appear here.
 *
 *   GET  /notifications?filter=all|unread&page=n
 *   POST /notifications/seen            mark everything seen (clears the bell count)
 *   POST /notifications/{id}/read
 *   POST /notifications/read-all
 */
class Notifications {

	const PAGE = 20;

	public static function register_hooks() {
		add_action( 'membuild_community/reaction_changed', array( __CLASS__, 'on_reaction' ), 20, 4 );
		add_action( 'membuild_community/comment_created', array( __CLASS__, 'on_comment' ), 20, 3 );
		add_action( 'membuild_community/story_reacted', array( __CLASS__, 'on_story_reaction' ), 20, 3 );
		add_action( 'membuild_community/level_up', array( __CLASS__, 'on_level_up' ), 20, 3 );
	}

	/* ---------- Events ---------- */

	public static function on_reaction( $object_type, $object_id, $actor_id, $type ) {
		global $wpdb;
		if ( ! $type || 'down' === $type ) {
			return; // removing a reaction or a private downvote doesn't notify
		}
		if ( 'post' === $object_type ) {
			$post = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, content FROM %i WHERE id = %d', Install::table( 'posts' ), $object_id ), ARRAY_A );
			if ( $post ) {
				self::notify( (int) $post['user_id'], 'post_reaction', 'post', (int) $post['id'], (int) $post['id'], $actor_id, array( 'snippet' => self::snippet( $post['content'] ), 'reaction' => $type ) );
			}
		} elseif ( 'comment' === $object_type ) {
			$comment = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, post_id, content FROM %i WHERE id = %d', Install::table( 'comments' ), $object_id ), ARRAY_A );
			if ( $comment ) {
				self::notify( (int) $comment['user_id'], 'comment_reaction', 'comment', (int) $comment['id'], (int) $comment['post_id'], $actor_id, array( 'snippet' => self::snippet( $comment['content'] ), 'reaction' => $type ) );
			}
		}
	}

	public static function on_comment( $comment_id, $post_id, $actor_id ) {
		global $wpdb;
		$comment = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'comments' ), $comment_id ), ARRAY_A );
		$post    = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, content FROM %i WHERE id = %d', Install::table( 'posts' ), $post_id ), ARRAY_A );
		if ( ! $comment || ! $post ) {
			return;
		}
		$notified = array();
		// Whoever was answered: the person mentioned in a reply, or the top-level comment's author.
		if ( $comment['parent_id'] ) {
			$parent    = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, content FROM %i WHERE id = %d', Install::table( 'comments' ), $comment['parent_id'] ), ARRAY_A );
			$recipient = (int) $comment['reply_to_user_id'] ?: (int) ( $parent['user_id'] ?? 0 );
			if ( $parent && $recipient ) {
				self::notify( $recipient, 'comment_reply', 'comment', (int) $parent['id'], (int) $post['id'], $actor_id, array( 'snippet' => self::snippet( $comment['content'] ) ) );
				$notified[] = $recipient;
			}
		}
		// The post's author hears about every comment on their post (once, even if also answered).
		if ( ! in_array( (int) $post['user_id'], $notified, true ) ) {
			self::notify( (int) $post['user_id'], 'post_comment', 'post', (int) $post['id'], (int) $post['id'], $actor_id, array( 'snippet' => self::snippet( $post['content'] ), 'comment' => self::snippet( $comment['content'] ) ) );
		}
	}

	public static function on_story_reaction( $story_id, $actor_id, $type ) {
		global $wpdb;
		$story = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, kind, media_url, poster_url, text FROM %i WHERE id = %d', Install::table( 'stories' ), $story_id ), ARRAY_A );
		if ( $story ) {
			$thumb = 'photo' === $story['kind'] ? $story['media_url'] : $story['poster_url'];
			self::notify( (int) $story['user_id'], 'story_reaction', 'story', (int) $story['id'], 0, $actor_id, array( 'reaction' => $type, 'thumb' => $thumb, 'snippet' => self::snippet( $story['text'] ) ) );
		}
	}

	public static function on_level_up( $user_id, $level ) {
		global $wpdb;
		$courses = $wpdb->get_col( $wpdb->prepare( "SELECT title FROM %i WHERE status = 'published' AND unlock_level = %d", Install::table( 'courses' ), $level ) );
		self::notify( (int) $user_id, 'level_up', 'level', (int) $level, 0, 0, array( 'level' => (int) $level, 'courses' => $courses ) );
	}

	/* ---------- Writing ---------- */

	/**
	 * Adds a notification, or folds the actor into the matching unread one,
	 * which moves back to the top and rings the bell again.
	 */
	public static function notify( $user_id, $type, $object_type, $object_id, $post_id, $actor_id, array $data = array() ) {
		global $wpdb;
		if ( ! $user_id || ( $actor_id && (int) $actor_id === (int) $user_id ) ) {
			return; // never notify people about their own actions
		}
		if ( $actor_id && Blocks::hides( $actor_id, $user_id ) ) {
			return; // nor about anything from someone they blocked, or who blocked them
		}
		$table = Install::table( 'notifications' );
		$now   = Helpers::now();
		$open  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND type = %s AND object_type = %s AND object_id = %d AND read_at IS NULL', $table, $user_id, $type, $object_type, $object_id ),
			ARRAY_A
		);
		if ( $open && $actor_id ) {
			$actors = array_values( array_diff( array_filter( array_map( 'intval', explode( ',', (string) $open['actor_ids'] ) ) ), array( (int) $actor_id ) ) );
			array_unshift( $actors, (int) $actor_id ); // newest first
			$is_new = ! in_array( (int) $actor_id, array_map( 'intval', explode( ',', (string) $open['actor_ids'] ) ), true );
			$wpdb->update(
				$table,
				array(
					'actor_ids'    => implode( ',', array_slice( $actors, 0, 10 ) ),
					'actors_count' => (int) $open['actors_count'] + ( $is_new ? 1 : 0 ),
					'data'         => wp_json_encode( $data + ( json_decode( (string) $open['data'], true ) ?: array() ) ),
					'seen_at'      => null,
					'updated_at'   => $now,
				),
				array( 'id' => $open['id'] )
			);
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'user_id'      => $user_id,
				'type'         => $type,
				'object_type'  => $object_type,
				'object_id'    => $object_id,
				'post_id'      => $post_id,
				'actor_ids'    => $actor_id ? (string) (int) $actor_id : null,
				'actors_count' => $actor_id ? 1 : 0,
				'data'         => wp_json_encode( $data ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		/**
		 * A notification was created for a member.
		 *
		 * @param int    $notification_id The notification.
		 * @param int    $user_id         Who it is for.
		 * @param string $type            e.g. "post_comment", "comment_reply", "level_up".
		 */
		do_action( 'membuild_community/notification_created', (int) $wpdb->insert_id, $user_id, $type );
	}

	/* ---------- API ---------- */

	public static function register_routes() {
		$auth = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_items' ),
				'permission_callback' => $auth,
				'args'                => array(
					'filter' => array( 'enum' => array( 'all', 'unread' ), 'default' => 'all' ),
					'page'   => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/notifications/seen', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'mark_seen' ), 'permission_callback' => $auth ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/notifications/read-all', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'read_all' ), 'permission_callback' => $auth ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/notifications/(?P<id>\d+)/read', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'read_one' ), 'permission_callback' => $auth ) );
	}

	public static function list_items( \WP_REST_Request $req ) {
		global $wpdb;
		$table  = Install::table( 'notifications' );
		$offset = ( (int) $req['page'] - 1 ) * self::PAGE;
		if ( 'unread' === $req['filter'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d AND read_at IS NULL ORDER BY updated_at DESC, id DESC LIMIT %d, %d', $table, get_current_user_id(), $offset, self::PAGE + 1 ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d, %d', $table, get_current_user_id(), $offset, self::PAGE + 1 ),
				ARRAY_A
			);
		}
		$has_more = count( $rows ) > self::PAGE;
		$rows     = array_slice( $rows, 0, self::PAGE );
		$actor    = array();
		foreach ( $rows as $r ) {
			$actor = array_merge( $actor, array_slice( array_filter( array_map( 'intval', explode( ',', (string) $r['actor_ids'] ) ) ), 0, 2 ) );
		}
		$users = Helpers::users( $actor );
		return array(
			'items'     => array_map( fn( $r ) => self::format( $r, $users ), $rows ),
			'next_page' => $has_more ? (int) $req['page'] + 1 : null,
			'unseen'    => self::unseen_count( get_current_user_id() ),
		);
	}

	public static function mark_seen() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET seen_at = %s WHERE user_id = %d AND seen_at IS NULL', Install::table( 'notifications' ), Helpers::now(), get_current_user_id() ) );
		return array( 'unseen' => 0 );
	}

	public static function read_one( \WP_REST_Request $req ) {
		global $wpdb;
		$now = Helpers::now();
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET read_at = COALESCE(read_at, %s), seen_at = COALESCE(seen_at, %s) WHERE id = %d AND user_id = %d', Install::table( 'notifications' ), $now, $now, (int) $req['id'], get_current_user_id() )
		);
		return array( 'unseen' => self::unseen_count( get_current_user_id() ) );
	}

	public static function read_all() {
		global $wpdb;
		$now = Helpers::now();
		$wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET read_at = COALESCE(read_at, %s), seen_at = COALESCE(seen_at, %s) WHERE user_id = %d', Install::table( 'notifications' ), $now, $now, get_current_user_id() )
		);
		return array( 'unseen' => 0 );
	}

	public static function unseen_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND seen_at IS NULL', Install::table( 'notifications' ), $user_id ) );
	}

	/** Hourly: drop read notifications older than 90 days. */
	public static function cleanup() {
		global $wpdb;
		// Read ones after 90 days; unread ones after 180, so the table can't grow forever for members who never come back.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE read_at IS NOT NULL AND updated_at < UTC_TIMESTAMP() - INTERVAL %d DAY LIMIT %d', Install::table( 'notifications' ), 90, 1000 ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < UTC_TIMESTAMP() - INTERVAL %d DAY LIMIT %d', Install::table( 'notifications' ), 180, 1000 ) );
	}

	/* ---------- Formatting ---------- */

	private static function snippet( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '…' : $text;
	}

	private static function format( $r, $users ) {
		$actors = array_values( array_filter( array_map( fn( $id ) => $users[ $id ] ?? null, array_slice( array_filter( array_map( 'intval', explode( ',', (string) $r['actor_ids'] ) ) ), 0, 2 ) ) ) );
		$data   = json_decode( (string) $r['data'], true ) ?: array();
		$link   = match ( $r['type'] ) {
			'level_up'       => array( 'name' => 'me' ),
			'story_reaction' => array( 'name' => 'feed' ),
			default          => array( 'name' => 'feed', 'query' => array( 'post' => (int) $r['post_id'] ) ),
		};
		return array(
			'id'           => (int) $r['id'],
			'type'         => $r['type'],
			'actors'       => $actors,
			'actors_count' => (int) $r['actors_count'],
			'data'         => $data,
			'link'         => $link,
			'read'         => (bool) $r['read_at'],
			'seen'         => (bool) $r['seen_at'],
			'updated_at'   => Helpers::iso( $r['updated_at'] ),
		);
	}
}
