<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Direct messages and small group chats.
 *
 *   GET    /messages/threads                     inbox (newest first, unread counts)
 *   POST   /messages/threads                     {user_ids, title?} find or create a chat
 *   GET    /messages/threads/{id}                chat details and members
 *   PUT    /messages/threads/{id}                {title} rename a group
 *   POST   /messages/threads/{id}/leave          leave a group
 *   GET    /messages/threads/{id}/messages       ?before= older page
 *   POST   /messages/threads/{id}/messages       {content, media_ids?, reply_to_id?}
 *   POST   /messages/threads/{id}/read           {message_id}
 *   POST   /messages/threads/{id}/typing
 *   DELETE /messages/{id}                        unsend
 *   PUT|DELETE /messages/{id}/reaction
 *   GET    /messages/pulse                       ?threads=id:after,... one cheap poll for everything live
 *   GET    /messages/people                      ?search= who you can message
 *   POST   /stories/{id}/reply                   {content} private reply to a story
 *
 * Only members of a chat can read or post in it.
 */
class Rest_Messages {

	const PAGE        = 40;
	const MAX_GROUP   = 50;
	const RATE_LIMIT  = 30; // messages per minute, per member
	const TYPING_SECS = 6;

	public static function register_routes() {
		$auth = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		$chat = Rest_Feed::feature( 'messages' );
		$id   = '(?P<id>\d+)';
		$map  = array(
			'/messages/threads'                 => array( array( 'GET', 'inbox' ), array( 'POST', 'start' ) ),
			"/messages/threads/{$id}"           => array( array( 'GET', 'thread' ), array( 'PUT', 'rename' ) ),
			"/messages/threads/{$id}/leave"     => array( array( 'POST', 'leave' ) ),
			"/messages/threads/{$id}/messages"  => array( array( 'GET', 'history' ), array( 'POST', 'send' ) ),
			"/messages/threads/{$id}/read"      => array( array( 'POST', 'read' ) ),
			"/messages/threads/{$id}/typing"    => array( array( 'POST', 'typing' ) ),
			"/messages/{$id}"                   => array( array( 'DELETE', 'unsend' ) ),
			"/messages/{$id}/reaction"          => array( array( 'PUT', 'react' ), array( 'DELETE', 'unreact' ) ),
			'/messages/pulse'                   => array( array( 'GET', 'pulse' ) ),
			'/messages/people'                  => array( array( 'GET', 'people' ) ),
			"/stories/{$id}/reply"              => array( array( 'POST', 'story_reply' ) ),
		);
		foreach ( $map as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				// The pulse also carries the bell count, and people search also picks moderators, so they work with messages off.
				array_map( fn( $h ) => array( 'methods' => $h[0], 'callback' => array( __CLASS__, $h[1] ), 'permission_callback' => in_array( $path, array( '/messages/pulse', '/messages/people' ), true ) ? $auth : $chat ), $handlers )
			);
		}
	}

	/* ---------- Chats ---------- */

	public static function inbox( \WP_REST_Request $req ) {
		global $wpdb;
		$me      = get_current_user_id();
		$threads = Install::table( 'threads' );
		$members = Install::table( 'thread_members' );
		if ( $req['before'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.*, m.last_read_id FROM %i t JOIN %i m ON m.thread_id = t.id AND m.user_id = %d AND m.left_at IS NULL
					WHERE t.last_message_id > 0 AND t.last_message_at < %s ORDER BY t.last_message_at DESC LIMIT 31',
					$threads,
					$members,
					$me,
					gmdate( 'Y-m-d H:i:s', strtotime( (string) $req['before'] ) )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.*, m.last_read_id FROM %i t JOIN %i m ON m.thread_id = t.id AND m.user_id = %d AND m.left_at IS NULL
					WHERE t.last_message_id > 0 ORDER BY t.last_message_at DESC LIMIT 31',
					$threads,
					$members,
					$me
				),
				ARRAY_A
			);
		}
		$has_more = count( $rows ) > 30;
		$rows     = array_slice( $rows, 0, 30 );
		return array(
			'items'    => self::format_threads( $rows ),
			'has_more' => $has_more,
			'unread'   => self::unread_total(),
		);
	}

	/** Opens the existing direct chat with someone, or creates a chat (direct or group). */
	public static function start( \WP_REST_Request $req ) {
		global $wpdb;
		$me     = get_current_user_id();
		$others = array_values( array_diff( array_unique( array_map( 'intval', (array) $req['user_ids'] ) ), array( $me ) ) );
		$others = array_values( array_filter( $others, fn( $id ) => (bool) get_userdata( $id ) ) );
		if ( ! $others ) {
			return new \WP_Error( 'mbcom_no_people', __( 'Choose who to message.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$slow = Helpers::throttle( 'chat', 20, HOUR_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		if ( count( $others ) >= self::MAX_GROUP ) {
			/* translators: %d: maximum group size */
			return new \WP_Error( 'mbcom_group_size', sprintf( __( 'Groups can have up to %d people.', 'membuild-community' ), self::MAX_GROUP ), array( 'status' => 400 ) );
		}
		$blocked = Moderation::guard( $me );
		if ( $blocked ) {
			return $blocked;
		}
		if ( ! Settings::can_message( $me, $others ) ) {
			return new \WP_Error( 'mbcom_messaging_staff', __( 'You can message admins and moderators here.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		if ( Blocks::filter( $others ) !== $others ) {
			return new \WP_Error( 'mbcom_blocked', __( 'You can’t message this person.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$thread_id = 1 === count( $others ) ? self::direct_thread( $me, $others[0] ) : self::create_thread( 'group', array_merge( array( $me ), $others ), sanitize_text_field( $req['title'] ?? '' ) );
		return self::thread_payload( $thread_id );
	}

	public static function thread( \WP_REST_Request $req ) {
		$thread = self::member_thread( (int) $req['id'] );
		return is_wp_error( $thread ) ? $thread : self::thread_payload( (int) $thread['id'] );
	}

	public static function rename( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard();
		if ( $blocked ) {
			return $blocked;
		}
		$thread = self::member_thread( (int) $req['id'] );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}
		if ( 'group' !== $thread['type'] ) {
			return new \WP_Error( 'mbcom_not_group', __( 'Only group chats have a name.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->update( Install::table( 'threads' ), array( 'title' => sanitize_text_field( $req['title'] ?? '' ) ?: null ), array( 'id' => $thread['id'] ) );
		return self::thread_payload( (int) $thread['id'] );
	}

	public static function leave( \WP_REST_Request $req ) {
		global $wpdb;
		$thread = self::member_thread( (int) $req['id'] );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}
		if ( 'group' !== $thread['type'] ) {
			return new \WP_Error( 'mbcom_not_group', __( 'You can only leave group chats.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$wpdb->update( Install::table( 'thread_members' ), array( 'left_at' => Helpers::now() ), array( 'thread_id' => $thread['id'], 'user_id' => get_current_user_id() ) );
		return array( 'left' => true );
	}

	/* ---------- Messages ---------- */

	public static function history( \WP_REST_Request $req ) {
		global $wpdb;
		$thread = self::member_thread( (int) $req['id'] );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}
		$before = (int) $req['before'];
		$rows   = $before
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE thread_id = %d AND id < %d ORDER BY id DESC LIMIT %d', Install::table( 'messages' ), $thread['id'], $before, self::PAGE + 1 ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE thread_id = %d ORDER BY id DESC LIMIT %d', Install::table( 'messages' ), $thread['id'], self::PAGE + 1 ), ARRAY_A );
		$has_more = count( $rows ) > self::PAGE;
		$rows     = array_reverse( array_slice( $rows, 0, self::PAGE ) );
		return array( 'items' => self::format_messages( $rows ), 'has_more' => $has_more );
	}

	public static function send( \WP_REST_Request $req ) {
		$blocked = Moderation::guard();
		if ( $blocked ) {
			return $blocked;
		}
		$thread = self::member_thread( (int) $req['id'] );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}
		// "Members can only message staff" also covers chats that already exist.
		$others = array_values( array_diff( array_column( self::members( (int) $thread['id'] ), 'id' ), array( get_current_user_id() ) ) );
		if ( 'direct' === $thread['type'] && ! Settings::can_message( get_current_user_id(), $others ) ) {
			return new \WP_Error( 'mbcom_messaging_staff', __( 'You can message admins and moderators here.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$message = self::post_message( (int) $thread['id'], get_current_user_id(), (string) ( $req['content'] ?? '' ), (array) ( $req['media_ids'] ?? array() ), (int) ( $req['reply_to_id'] ?? 0 ) );
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		$response = rest_ensure_response( $message );
		$response->set_status( 201 );
		return $response;
	}

	public static function unsend( \WP_REST_Request $req ) {
		global $wpdb;
		$table = Install::table( 'messages' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $req['id'] ), ARRAY_A );
		if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'You can only unsend your own messages.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		// Keep the row so the conversation's shape stays intact; drop what was said, photos included.
		foreach ( json_decode( (string) $row['media'], true ) ?: array() as $m ) {
			Media::delete( (int) ( $m['id'] ?? 0 ) );
		}
		$wpdb->update( $table, array( 'status' => 'unsent', 'content' => null, 'media' => null, 'story' => null ), array( 'id' => $row['id'] ) );
		$wpdb->delete( Install::table( 'reactions' ), array( 'object_type' => 'message', 'object_id' => $row['id'] ) );
		return self::format_messages( array( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $row['id'] ), ARRAY_A ) ) )[0];
	}

	public static function react( \WP_REST_Request $req ) {
		global $wpdb;
		$message = self::member_message( (int) $req['id'] );
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		// The usual reactions, or any one emoji (Messenger's "+").
		$type = in_array( $req['type'], Helpers::REACTIONS, true ) ? $req['type'] : ( Helpers::emoji( $req['type'] ) ?? 'love' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (object_type, object_id, user_id, type, created_at) VALUES ('message', %d, %d, %s, %s) ON DUPLICATE KEY UPDATE type = VALUES(type)",
				Install::table( 'reactions' ),
				$message['id'],
				get_current_user_id(),
				$type,
				Helpers::now()
			)
		);
		return self::format_messages( array( $message ) )[0];
	}

	public static function unreact( \WP_REST_Request $req ) {
		global $wpdb;
		$message = self::member_message( (int) $req['id'] );
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		$wpdb->delete( Install::table( 'reactions' ), array( 'object_type' => 'message', 'object_id' => $message['id'], 'user_id' => get_current_user_id() ) );
		return self::format_messages( array( $message ) )[0];
	}

	public static function read( \WP_REST_Request $req ) {
		global $wpdb;
		$thread = self::member_thread( (int) $req['id'] );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}
		$upto = min( (int) $req['message_id'], (int) $thread['last_message_id'] ) ?: (int) $thread['last_message_id'];
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_read_id = GREATEST(last_read_id, %d) WHERE thread_id = %d AND user_id = %d',
				Install::table( 'thread_members' ),
				$upto,
				$thread['id'],
				get_current_user_id()
			)
		);
		return array( 'unread' => self::unread_total() );
	}

	public static function typing( \WP_REST_Request $req ) {
		$thread = self::member_thread( (int) $req['id'] );
		if ( is_wp_error( $thread ) ) {
			return $thread;
		}
		self::set_typing( (int) $thread['id'], get_current_user_id(), true );
		return array( 'ok' => true );
	}

	/**
	 * One small request for everything live: total unread, a marker that changes
	 * when any of my chats gets a message, and, for the open chat, new messages,
	 * who is typing and how far each member has read.
	 */
	public static function pulse( \WP_REST_Request $req ) {
		global $wpdb;
		$me   = get_current_user_id();
		Email::touch( $me ); // members active right now don't get emailed about what they've seen
		if ( rest_sanitize_boolean( $req['active'] ) ) {
			Online::touch( $me ); // a real click, key or scroll lately: the green dot
		}
		$out  = array(
			'unread'        => self::unread_total(),
			'inbox_version' => (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(MAX(t.last_message_id), 0) FROM %i t JOIN %i m ON m.thread_id = t.id AND m.user_id = %d AND m.left_at IS NULL',
					Install::table( 'threads' ),
					Install::table( 'thread_members' ),
					$me
				)
			),
			'threads'       => array(),
			// The bell count rides along, so the app needs only this one poll.
			'notifications' => Notifications::unseen_count( $me ),
			// Staff see how many reports are waiting.
			'reports'       => Helpers::can_moderate() ? Moderation::open_count() : 0,
		);
		if ( ! Settings::feature( 'messages' ) ) {
			$req['threads'] = '';
		}
		// Every open chat (the Messages page or up to 4 docked windows) as "id:after_id,id:after_id".
		foreach ( array_slice( array_filter( explode( ',', (string) $req['threads'] ) ), 0, 4 ) as $pair ) {
			list( $thread_id, $after ) = array_map( 'intval', array_pad( explode( ':', $pair ), 2, 0 ) );
			$thread = $thread_id ? self::pulse_thread( $thread_id, $after ) : null;
			if ( $thread ) {
				$out['threads'][] = $thread;
			}
		}
		// The feed's "new posts" pill rides along too, so the feed needs no poll of its own.
		$since = (int) $req['feed_since'];
		if ( $since > 0 ) {
			$out['new_posts'] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND course_id = 0 AND id > %d AND user_id <> %d", Install::table( 'posts' ), $since, $me )
			);
		}
		return $out;
	}

	/** New messages after $after, who is typing and read positions for one chat I belong to. */
	private static function pulse_thread( $thread_id, $after ) {
		global $wpdb;
		$me = get_current_user_id();
		if ( is_wp_error( self::member_thread( $thread_id ) ) ) {
			return null;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE thread_id = %d AND id > %d ORDER BY id ASC LIMIT 100', Install::table( 'messages' ), $thread_id, $after ),
			ARRAY_A
		);
		// Changes to messages already on screen (unsent, reactions) since the last pulse are not tracked here; the app refreshes those on focus.
		$members = self::members( $thread_id );
		$typing  = array();
		$now_typing = self::typing_now( $thread_id );
		foreach ( $members as $m ) {
			if ( $m['id'] !== $me && isset( $now_typing[ $m['id'] ] ) ) {
				$typing[] = $m['user'];
			}
		}
		return array(
			'id'       => $thread_id,
			'messages' => self::format_messages( $rows ),
			'typing'   => $typing,
			'reads'    => array_map( fn( $m ) => array( 'user_id' => $m['id'], 'last_read_id' => $m['last_read_id'] ), $members ),
		);
	}

	/**
	 * Who is typing in a chat: one small transient per chat (user ID => until),
	 * so a pulse costs one lookup however many people are in the chat.
	 */
	private static function typing_now( $thread_id ) {
		$now = time();
		return array_filter( (array) get_transient( 'mbcom_typing_' . $thread_id ), fn( $until ) => $until > $now );
	}

	private static function set_typing( $thread_id, $user_id, $on ) {
		$typing = self::typing_now( $thread_id );
		if ( $on ) {
			$typing[ $user_id ] = time() + self::TYPING_SECS;
		} elseif ( isset( $typing[ $user_id ] ) ) {
			unset( $typing[ $user_id ] );
		} else {
			return; // nothing to change, so no write
		}
		set_transient( 'mbcom_typing_' . $thread_id, $typing, self::TYPING_SECS );
	}

	public static function people( \WP_REST_Request $req ) {
		$search = sanitize_text_field( (string) $req['search'] );
		$args   = array(
			'number'  => 20,
			'exclude' => array( get_current_user_id() ),
			'orderby' => 'display_name',
			'fields'  => 'ID',
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'display_name' ); // login names stay private
		}
		$ids = Blocks::filter( array_map( 'intval', get_users( $args ) ) );
		return array( 'items' => array_values( Helpers::users( $ids ) ) );
	}

	/** Replying to a story sends the owner a private message that shows the story it answers. */
	public static function story_reply( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard();
		if ( $blocked ) {
			return $blocked;
		}
		$story = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND expires_at > UTC_TIMESTAMP()', Install::table( 'stories' ), (int) $req['id'] ),
			ARRAY_A
		);
		$me = get_current_user_id();
		if ( ! $story ) {
			return new \WP_Error( 'mbcom_not_found', __( 'This story is no longer available.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $story['user_id'] === $me ) {
			return new \WP_Error( 'mbcom_own_story', __( 'You cannot reply to your own story.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( ! Settings::can_message( $me, array( (int) $story['user_id'] ) ) ) {
			return new \WP_Error( 'mbcom_messaging_staff', __( 'You can message admins and moderators here.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$thread_id = self::direct_thread( $me, (int) $story['user_id'] );
		$preview   = array(
			'id'         => (int) $story['id'],
			'kind'       => $story['kind'],
			'thumb'      => 'photo' === $story['kind'] ? $story['media_url'] : $story['poster_url'],
			'text'       => $story['text'],
			'background' => $story['background'],
		);
		$message = self::post_message( $thread_id, $me, (string) ( $req['content'] ?? '' ), array(), 0, $preview );
		if ( is_wp_error( $message ) ) {
			return $message;
		}
		return array( 'thread_id' => $thread_id, 'message' => $message );
	}

	/* ---------- Internals ---------- */

	/** @return array|\WP_Error the formatted message */
	private static function post_message( $thread_id, $user_id, $content, array $media_ids, $reply_to = 0, $story = null ) {
		global $wpdb;
		$content = Helpers::clean_text( $content, 4000 );
		$table   = Install::table( 'messages' );
		$recent  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE', $table, $user_id ) );
		if ( $recent >= self::RATE_LIMIT ) {
			return new \WP_Error( 'mbcom_slow_down', __( "You're sending messages very quickly. Please wait a moment.", 'membuild-community' ), array( 'status' => 429 ) );
		}
		if ( self::blocked_in_direct( $thread_id, $user_id ) ) {
			return new \WP_Error( 'mbcom_blocked', __( 'You can’t message this person.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		if ( '' === $content && ! $media_ids ) {
			return new \WP_Error( 'mbcom_empty', __( 'Write a message first.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$paused = Vault::paused();
		if ( $paused ) {
			return $paused;
		}
		// Photos are claimed only once the message will be sent, so a refused message leaves them free.
		$media = self::claim_images( $user_id, $media_ids, $thread_id );
		if ( '' === $content && ! $media ) {
			return new \WP_Error( 'mbcom_empty', __( 'Write a message first.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( $reply_to && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND thread_id = %d', $table, $reply_to, $thread_id ) ) ) {
			$reply_to = 0;
		}
		$now = Helpers::now();
		$wpdb->insert(
			$table,
			array(
				'thread_id'   => $thread_id,
				'user_id'     => $user_id,
				// Encrypted at rest (see Vault), tied to this chat.
				'content'     => '' === $content ? null : Vault::seal( $content, self::context( $thread_id ) ),
				'media'       => $media ? wp_json_encode( $media ) : null,
				'story'       => $story ? Vault::seal( wp_json_encode( $story ), self::context( $thread_id ) ) : null,
				'reply_to_id' => $reply_to ?: null,
				'created_at'  => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		$wpdb->update( Install::table( 'threads' ), array( 'last_message_id' => $id, 'last_message_at' => $now ), array( 'id' => $thread_id ) );
		// Sending counts as reading, and re-opens the chat for anyone who had left a direct chat.
		$wpdb->update( Install::table( 'thread_members' ), array( 'last_read_id' => $id ), array( 'thread_id' => $thread_id, 'user_id' => $user_id ) );
		self::set_typing( $thread_id, $user_id, false );

		/**
		 * A private message was sent. The message text is not passed on purpose.
		 *
		 * @param int $message_id The message.
		 * @param int $thread_id  Its chat.
		 * @param int $user_id    Who sent it.
		 */
		do_action( 'membuild_community/message_sent', $id, $thread_id, $user_id );
		return self::format_messages( array( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ) ) )[0];
	}

	/** Photos the sender uploaded and hasn't used elsewhere, moved into the protected folder for this chat. */
	private static function claim_images( $user_id, array $media_ids, $thread_id ) {
		global $wpdb;
		$out = array();
		foreach ( array_slice( array_map( 'intval', $media_ids ), 0, 10 ) as $mid ) {
			$row = Media::get( $mid );
			if ( $row && (int) $row['user_id'] === (int) $user_id && null === $row['post_id'] && 'image' === $row['kind'] ) {
				$wpdb->update( Install::table( 'media' ), array( 'post_id' => 0 ), array( 'id' => $mid ) );
				$out[] = array_filter( array( 'id' => $mid, 'url' => Files::protect( $mid, 'message', $thread_id ), 'width' => (int) $row['width'], 'height' => (int) $row['height'], 'mp4' => Giphy::mp4( $row ) ) );
			}
		}
		return $out;
	}

	/** One-on-one chats stay readable after a block, but nobody can add to them. */
	private static function blocked_in_direct( $thread_id, $user_id ) {
		global $wpdb;
		$other = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT m.user_id FROM %i t JOIN %i m ON m.thread_id = t.id AND m.user_id <> %d WHERE t.id = %d AND t.type = 'direct' LIMIT 1",
				Install::table( 'threads' ),
				Install::table( 'thread_members' ),
				$user_id,
				$thread_id
			)
		);
		return $other && Blocks::hides( (int) $other, $user_id );
	}

	private static function direct_thread( $a, $b ) {
		global $wpdb;
		$key      = min( $a, $b ) . ':' . max( $a, $b );
		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE direct_key = %s', Install::table( 'threads' ), $key ) );
		if ( $existing ) {
			// Messaging someone again brings the chat back if either person had left it.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET left_at = NULL WHERE thread_id = %d', Install::table( 'thread_members' ), $existing ) );
			return $existing;
		}
		return self::create_thread( 'direct', array( $a, $b ), null, $key );
	}

	private static function create_thread( $type, array $user_ids, $title = null, $direct_key = null ) {
		global $wpdb;
		$now = Helpers::now();
		$wpdb->insert(
			Install::table( 'threads' ),
			array( 'type' => $type, 'direct_key' => $direct_key, 'title' => $title ?: null, 'created_by' => get_current_user_id(), 'created_at' => $now )
		);
		$thread_id = (int) $wpdb->insert_id;
		foreach ( array_unique( $user_ids ) as $uid ) {
			$wpdb->insert( Install::table( 'thread_members' ), array( 'thread_id' => $thread_id, 'user_id' => $uid, 'joined_at' => $now ) );
		}
		return $thread_id;
	}

	/** The chat, if the current member belongs to it. */
	private static function member_thread( $thread_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT t.*, m.last_read_id FROM %i t JOIN %i m ON m.thread_id = t.id AND m.user_id = %d AND m.left_at IS NULL WHERE t.id = %d',
				Install::table( 'threads' ),
				Install::table( 'thread_members' ),
				get_current_user_id(),
				$thread_id
			),
			ARRAY_A
		);
		return $row ?: new \WP_Error( 'mbcom_not_found', __( 'Conversation not found.', 'membuild-community' ), array( 'status' => 404 ) );
	}

	private static function member_message( $message_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND status = 'sent'", Install::table( 'messages' ), $message_id ), ARRAY_A );
		if ( ! $row || is_wp_error( self::member_thread( (int) $row['thread_id'] ) ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Message not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		return $row;
	}

	private static function unread_total() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i msg JOIN %i m ON m.thread_id = msg.thread_id AND m.user_id = %d AND m.left_at IS NULL
				WHERE msg.id > m.last_read_id AND msg.user_id <> %d AND msg.status = 'sent'",
				Install::table( 'messages' ),
				Install::table( 'thread_members' ),
				get_current_user_id(),
				get_current_user_id()
			)
		);
	}

	/** @return array[] members of a chat (including people who left), with read positions */
	private static function members( $thread_id ) {
		global $wpdb;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, last_read_id, left_at FROM %i WHERE thread_id = %d', Install::table( 'thread_members' ), $thread_id ), ARRAY_A );
		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		$out   = array();
		foreach ( $rows as $r ) {
			if ( isset( $users[ (int) $r['user_id'] ] ) ) {
				$out[] = array( 'id' => (int) $r['user_id'], 'user' => $users[ (int) $r['user_id'] ], 'last_read_id' => (int) $r['last_read_id'], 'left' => (bool) $r['left_at'] );
			}
		}
		return $out;
	}

	private static function thread_payload( $thread_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT t.*, m.last_read_id FROM %i t JOIN %i m ON m.thread_id = t.id AND m.user_id = %d WHERE t.id = %d',
				Install::table( 'threads' ),
				Install::table( 'thread_members' ),
				get_current_user_id(),
				$thread_id
			),
			ARRAY_A
		);
		$out            = self::format_threads( array( $row ) )[0];
		$out['members'] = array_map( fn( $m ) => $m['user'] + array( 'last_read_id' => $m['last_read_id'], 'left' => $m['left'] ), self::members( $thread_id ) );
		return $out;
	}

	/** Inbox rows: everyone else in the chat, the last message preview and my unread count. */
	private static function format_threads( array $rows ) {
		global $wpdb;
		$rows = array_filter( $rows );
		if ( ! $rows ) {
			return array();
		}
		$me      = get_current_user_id();
		$ids     = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$members = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT thread_id, user_id FROM %i WHERE thread_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND left_at IS NULL', array_merge( array( Install::table( 'thread_members' ) ), $ids ) ), ARRAY_A ) as $m ) {
			$members[ (int) $m['thread_id'] ][] = (int) $m['user_id'];
		}
		$users = Helpers::users( array_merge( array( $me ), ...array_values( $members ) ) );
		$last  = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'last_message_id' ) ) );
		$lasts = $last ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $last ), '%d' ) ) . ')', array_merge( array( Install::table( 'messages' ) ), $last ) ), OBJECT_K ) : array();

		$unread = array();
		foreach ( $wpdb->get_results(
			$wpdb->prepare(
				'SELECT msg.thread_id, COUNT(*) n FROM %i msg JOIN %i m ON m.thread_id = msg.thread_id AND m.user_id = %d
				WHERE msg.thread_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND msg.id > m.last_read_id AND msg.user_id <> %d AND msg.status = \'sent\' GROUP BY msg.thread_id',
				array_merge( array( Install::table( 'messages' ), Install::table( 'thread_members' ), $me ), $ids, array( $me ) )
			),
			ARRAY_A
		) as $u ) {
			$unread[ (int) $u['thread_id'] ] = (int) $u['n'];
		}

		return array_values(
			array_map(
				function ( $t ) use ( $members, $users, $lasts, $unread, $me ) {
					$id     = (int) $t['id'];
					$others = array_values( array_filter( array_map( fn( $uid ) => $uid !== $me ? ( $users[ $uid ] ?? null ) : null, $members[ $id ] ?? array() ) ) );
					$lm     = $lasts[ (int) $t['last_message_id'] ] ?? null;
					return array(
						'id'           => $id,
						'type'         => $t['type'],
						'title'        => $t['title'],
						'others'       => $others,
						'last_message' => $lm ? array(
							'id'         => (int) $lm->id,
							'user_id'    => (int) $lm->user_id,
							'preview'    => self::preview( $lm ),
							'created_at' => Helpers::iso( $lm->created_at ),
						) : null,
						'unread'       => $unread[ $id ] ?? 0,
					);
				},
				$rows
			)
		);
	}

	/* ---------- Encryption at rest ---------- */

	/** What a message's ciphertext is tied to, so it can't be moved into another chat. */
	private static function context( $thread_id ) {
		return 'message:' . (int) $thread_id;
	}

	/** A message's stored text (or story preview), decrypted for display. */
	private static function plaintext( $stored, $thread_id ) {
		if ( null === $stored || '' === $stored ) {
			return $stored;
		}
		$plain = Vault::open( $stored, self::context( $thread_id ) );
		return null === $plain ? __( 'This message can’t be shown.', 'membuild-community' ) : $plain;
	}

	/**
	 * Encrypts messages saved before encryption existed, a batch per run,
	 * rescheduling itself until none are left.
	 */
	public static function encrypt_existing() {
		global $wpdb;
		if ( ! Vault::ready() ) {
			return; // Vault::restore() and start_over() schedule this again once there's a key
		}
		$table = Install::table( 'messages' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, thread_id, content, story FROM %i WHERE ( content IS NOT NULL AND content <> '' AND content NOT LIKE %s ) OR ( story IS NOT NULL AND story NOT LIKE %s ) LIMIT 500",
				$table,
				$wpdb->esc_like( Vault::PREFIX ) . '%',
				$wpdb->esc_like( Vault::PREFIX ) . '%'
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$ctx = self::context( (int) $row['thread_id'] );
			$wpdb->update(
				$table,
				array(
					'content' => Vault::is_sealed( $row['content'] ) || null === $row['content'] || '' === $row['content'] ? $row['content'] : Vault::seal( $row['content'], $ctx ),
					'story'   => Vault::is_sealed( $row['story'] ) || null === $row['story'] ? $row['story'] : Vault::seal( $row['story'], $ctx ),
				),
				array( 'id' => $row['id'] )
			);
		}
		if ( 500 === count( $rows ) ) {
			wp_schedule_single_event( time() + 30, Vault::MIGRATE_HOOK );
		}
	}

	private static function preview( $m ) {
		if ( 'unsent' === $m->status ) {
			return __( 'Message unsent', 'membuild-community' );
		}
		if ( $m->content ) {
			return mb_substr( (string) self::plaintext( $m->content, (int) $m->thread_id ), 0, 120 );
		}
		if ( $m->story ) {
			return __( 'Replied to a story', 'membuild-community' );
		}
		return __( 'Sent a photo', 'membuild-community' );
	}

	private static function format_messages( array $rows ) {
		global $wpdb;
		$rows = array_filter( $rows );
		if ( ! $rows ) {
			return array();
		}
		$ids    = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$me     = get_current_user_id();
		$reacts = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, user_id, type FROM %i WHERE object_type = \'message\' AND object_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'reactions' ) ), $ids ) ), ARRAY_A ) as $r ) {
			$reacts[ (int) $r['object_id'] ][] = array( 'user_id' => (int) $r['user_id'], 'type' => $r['type'] );
		}
		$reply_ids = array_filter( array_map( 'intval', wp_list_pluck( $rows, 'reply_to_id' ) ) );
		$replies   = $reply_ids ? $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, content, status FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $reply_ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'messages' ) ), $reply_ids ) ), OBJECT_K ) : array();

		return array_values(
			array_map(
				function ( $m ) use ( $reacts, $replies, $me ) {
					$id    = (int) $m['id'];
					$list  = $reacts[ $id ] ?? array();
					$to    = $replies[ (int) $m['reply_to_id'] ] ?? null;
					$story = self::plaintext( $m['story'], (int) $m['thread_id'] );
					return array(
						'id'          => $id,
						'thread_id'   => (int) $m['thread_id'],
						'user_id'     => (int) $m['user_id'],
						'mine'        => (int) $m['user_id'] === $me,
						'content'     => self::plaintext( $m['content'], (int) $m['thread_id'] ),
						'media'       => $m['media'] ? json_decode( $m['media'], true ) : array(),
						'story'       => $story ? json_decode( $story, true ) : null,
						'reply_to'    => $to ? array( 'id' => (int) $to->id, 'user_id' => (int) $to->user_id, 'content' => 'unsent' === $to->status ? null : mb_substr( (string) self::plaintext( $to->content, (int) $m['thread_id'] ), 0, 140 ) ) : null,
						'unsent'      => 'unsent' === $m['status'],
						'reactions'   => $list,
						'my_reaction' => current( array_column( array_filter( $list, fn( $r ) => $r['user_id'] === $me ), 'type' ) ) ?: null,
						'created_at'  => Helpers::iso( $m['created_at'] ),
					);
				},
				$rows
			)
		);
	}
}
