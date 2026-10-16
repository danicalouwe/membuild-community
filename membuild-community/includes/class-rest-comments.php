<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Comments, one level of replies, and reactions on comments.
 *
 *   GET    /posts/{id}/comments?sort=relevant|newest&page=n   top-level comments
 *   POST   /posts/{id}/comments   {content, parent_id?, reply_to_user_id?}
 *   GET    /comments/{id}/replies
 *   DELETE /comments/{id}
 *   PUT    /comments/{id}/reaction  {type}   like|love|care|haha|wow|sad|angry|down
 *   DELETE /comments/{id}/reaction
 *
 * Replies are kept one level deep, like Facebook: replying to a reply attaches
 * to the same top-level comment and mentions the person being answered.
 * "down" is a private downvote. It is stored like a reaction but never counted or shown.
 */
class Rest_Comments {

	const PAGE_SIZE = 20;

	public static function register_routes() {
		$auth = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );

		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/comments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_comments' ),
					'permission_callback' => $auth,
					'args'                => array(
						'sort' => array( 'enum' => array( 'relevant', 'newest' ), 'default' => 'relevant' ),
						'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => $auth,
				),
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/comments/(?P<id>\d+)/replies',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'replies' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/comments/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/comments/(?P<id>\d+)/reaction',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'react' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'unreact' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	/* ---------- Reading ---------- */

	public static function list_comments( \WP_REST_Request $req ) {
		global $wpdb;
		$post = Rest_Feed::row( (int) $req['id'] );
		if ( ! $post ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$table  = Install::table( 'comments' );
		$offset = ( (int) $req['page'] - 1 ) * self::PAGE_SIZE;
		$limit  = self::PAGE_SIZE + 1;
		if ( 'newest' === $req['sort'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d AND parent_id = 0 ORDER BY id DESC LIMIT %d, %d', $table, $post['id'], $offset, $limit ),
				ARRAY_A
			);
		} else {
			// "Most relevant": comments people reacted to and replied to rise; ties keep conversation order.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d AND parent_id = 0 ORDER BY (reactions_count + 2 * replies_count) DESC, id ASC LIMIT %d, %d', $table, $post['id'], $offset, $limit ),
				ARRAY_A
			);
		}
		$has_more = count( $rows ) > self::PAGE_SIZE;
		$rows     = Blocks::filter( array_slice( $rows, 0, self::PAGE_SIZE ) );

		return array(
			'items'     => self::format( $rows, (int) $post['user_id'] ),
			'next_page' => $has_more ? (int) $req['page'] + 1 : null,
		);
	}

	public static function replies( \WP_REST_Request $req ) {
		global $wpdb;
		$parent = self::get( (int) $req['id'] );
		if ( ! $parent ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Comment not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$post = Rest_Feed::row( (int) $parent['post_id'] );
		if ( ! $post ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Comment not found.', 'membuild-community' ), array( 'status' => 404 ) ); // its post was removed or is under review
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE parent_id = %d ORDER BY id ASC LIMIT 200', Install::table( 'comments' ), $parent['id'] ),
			ARRAY_A
		);
		return array( 'items' => self::format( Blocks::filter( $rows ), (int) ( $post['user_id'] ?? 0 ) ) );
	}

	/* ---------- Writing ---------- */

	public static function create( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard() ?? Helpers::throttle( 'comment', 30, 10 * MINUTE_IN_SECONDS );
		if ( $blocked ) {
			return $blocked;
		}
		$post    = Rest_Feed::row( (int) $req['id'] );
		$content = Helpers::clean_text( $req['content'] ?? '', 2000 );
		if ( ! $post ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		// One photo per comment, uploaded first through /media/uploads.
		$image = null;
		if ( ! empty( $req['media_id'] ) ) {
			$media = Install::table( 'media' );
			$image = $wpdb->get_row( $wpdb->prepare( "SELECT id, url, width, height, meta FROM %i WHERE id = %d AND user_id = %d AND kind = 'image' AND post_id IS NULL", $media, (int) $req['media_id'], get_current_user_id() ), ARRAY_A );
			if ( ! $image ) {
				return new \WP_Error( 'mbcom_bad_media', __( 'Add the photo again, please.', 'membuild-community' ), array( 'status' => 400 ) );
			}
		}
		if ( '' === $content && ! $image ) {
			return new \WP_Error( 'mbcom_empty', __( 'Write a comment first.', 'membuild-community' ), array( 'status' => 400 ) );
		}

		$parent_id = 0;
		$reply_to  = 0;
		if ( ! empty( $req['parent_id'] ) ) {
			$parent = self::get( (int) $req['parent_id'] );
			if ( ! $parent || (int) $parent['post_id'] !== (int) $post['id'] ) {
				return new \WP_Error( 'mbcom_bad_parent', __( 'That comment no longer exists.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			// Keep threads one level deep: a reply to a reply joins the same top-level comment.
			$parent_id = (int) $parent['parent_id'] ?: (int) $parent['id'];
			$reply_to  = (int) $parent['user_id'];
			// "Replying to Sam" may name anyone already in this thread, nobody else (it notifies them).
			$asked = (int) ( $req['reply_to_user_id'] ?? 0 );
			if ( $asked && $asked !== $reply_to ) {
				$in_thread = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND ( id = %d OR parent_id = %d )', Install::table( 'comments' ), $asked, $parent_id, $parent_id ) );
				$reply_to  = $in_thread ? $asked : $reply_to;
			}
		}

		$table = Install::table( 'comments' );
		$wpdb->insert(
			$table,
			array(
				'post_id'          => $post['id'],
				'user_id'          => get_current_user_id(),
				'parent_id'        => $parent_id,
				'reply_to_user_id' => $reply_to,
				'content'          => $content,
				'image_url'        => $image['url'] ?? null,
				'image_w'          => (int) ( $image['width'] ?? 0 ),
				'image_h'          => (int) ( $image['height'] ?? 0 ),
				'image_mp4'        => $image ? Giphy::mp4( $image ) : null,
				'created_at'       => Helpers::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $image ) {
			$wpdb->update( Install::table( 'media' ), array( 'post_id' => 0 ), array( 'id' => $image['id'] ) ); // claimed
		}
		self::recount( (int) $post['id'], $parent_id );

		/**
		 * A comment or reply was posted. Points and notifications are given on this hook.
		 *
		 * @param int $comment_id The new comment.
		 * @param int $post_id    Its post.
		 * @param int $user_id    Who wrote it.
		 */
		do_action( 'membuild_community/comment_created', $id, (int) $post['id'], get_current_user_id() );

		$response = rest_ensure_response( self::format( array( self::get( $id ) ), (int) $post['user_id'] )[0] );
		$response->set_status( 201 );
		return $response;
	}

	public static function delete( \WP_REST_Request $req ) {
		global $wpdb;
		$row = self::get( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Comment not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $row['user_id'] !== get_current_user_id() && ! Helpers::can_moderate() ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'You cannot delete this comment.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$table = Install::table( 'comments' );
		$ids   = array_merge( array( (int) $row['id'] ), array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE parent_id = %d', $table, $row['id'] ) ) ) );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $table ), $ids ) ), ARRAY_A ) as $gone ) {
			/**
			 * A comment was deleted (replies to it are deleted with it, each firing this). Points it earned are taken back on this hook.
			 *
			 * @param int $comment_id The comment.
			 * @param int $user_id    Who wrote it.
			 */
			do_action( 'membuild_community/comment_deleted', (int) $gone['id'], (int) $gone['user_id'] );
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $table ), $ids ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'comment' AND object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'reactions' ) ), $ids ) ) );
		self::recount( (int) $row['post_id'], (int) $row['parent_id'] );
		return array( 'deleted' => true );
	}

	public static function react( \WP_REST_Request $req ) {
		global $wpdb;
		$id   = (int) $req['id'];
		$type = (string) $req['type'];
		if ( 'down' !== $type && ! in_array( $type, Helpers::REACTIONS, true ) ) {
			$type = 'like';
		}
		$comment = self::get( $id );
		if ( ! $comment || ! Rest_Feed::row( (int) $comment['post_id'] ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Comment not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (object_type, object_id, user_id, type, created_at) VALUES ('comment', %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE type = VALUES(type)",
				Install::table( 'reactions' ),
				$id,
				get_current_user_id(),
				$type,
				Helpers::now()
			)
		);
		/**
		 * A member reacted, changed their reaction or took it back.
		 *
		 * @param string      $object_type "post" or "comment".
		 * @param int         $object_id   What was reacted to.
		 * @param int         $user_id     Who reacted.
		 * @param string|null $type        The reaction, e.g. "like", or null when removed.
		 */
		do_action( 'membuild_community/reaction_changed', 'comment', $id, get_current_user_id(), $type );
		return self::reaction_state( $id );
	}

	public static function unreact( \WP_REST_Request $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$wpdb->delete( Install::table( 'reactions' ), array( 'object_type' => 'comment', 'object_id' => $id, 'user_id' => get_current_user_id() ) );
		/** This action is documented above. */
		do_action( 'membuild_community/reaction_changed', 'comment', $id, get_current_user_id(), null );
		return self::reaction_state( $id );
	}

	/* ---------- Helpers ---------- */

	private static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'comments' ), $id ), ARRAY_A );
	}

	/** Recounts the stored counters instead of incrementing, so they never drift. */
	private static function recount( $post_id, $parent_id ) {
		global $wpdb;
		$table = Install::table( 'comments' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET comments_count = (SELECT COUNT(*) FROM %i WHERE post_id = %d) WHERE id = %d',
				Install::table( 'posts' ),
				$table,
				$post_id,
				$post_id
			)
		);
		if ( $parent_id ) {
			$wpdb->query(
				$wpdb->prepare( 'UPDATE %i SET replies_count = (SELECT COUNT(*) FROM (SELECT id FROM %i WHERE parent_id = %d) r) WHERE id = %d', $table, $table, $parent_id, $parent_id )
			);
		}
	}

	private static function reaction_state( $id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'comment' AND object_id = %d AND type <> 'down'", Install::table( 'reactions' ), $id )
		);
		$wpdb->update( Install::table( 'comments' ), array( 'reactions_count' => $count ), array( 'id' => $id ) );
		$row  = self::get( $id );
		$post = Rest_Feed::row( (int) $row['post_id'] );
		$full = self::format( array( $row ), (int) ( $post['user_id'] ?? 0 ) )[0];
		return array_intersect_key( $full, array_flip( array( 'reactions_count', 'my_reaction', 'top_reactions', 'liked_by_author' ) ) );
	}

	/**
	 * Formats comments with a fixed number of queries per page: authors,
	 * mentioned people, my reactions and the reaction breakdown are each fetched once.
	 */
	/**
	 * The comment preview shown under each post in the feed: the most relevant
	 * top-level comment plus its first reply. A fixed handful of queries for the whole page, however many posts it has.
	 *
	 * @param array[] $posts Post rows (need id and user_id).
	 * @return array<int, array> post ID => formatted comment with a 'replies' list
	 */
	public static function previews( array $posts ) {
		global $wpdb;
		$posts = array_filter( $posts, fn( $p ) => (int) $p['comments_count'] > 0 );
		if ( ! $posts ) {
			return array();
		}
		$table = Install::table( 'comments' );
		$pids  = array_map( 'intval', wp_list_pluck( $posts, 'id' ) );
		$top   = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id IN (' . implode( ',', array_fill( 0, count( $pids ), '%d' ) ) . ') AND parent_id = 0 ORDER BY (reactions_count + 2 * replies_count) DESC, id ASC LIMIT 1000', array_merge( array( $table ), $pids ) ), ARRAY_A ) as $row ) {
			if ( ! Blocks::hides( $row['user_id'] ) ) {
				$top[ (int) $row['post_id'] ] ??= $row;
			}
		}
		if ( ! $top ) {
			return array();
		}
		$parent_ids = array_values( array_map( fn( $r ) => (int) $r['id'], $top ) );
		$first      = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE parent_id IN (' . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') ORDER BY id ASC LIMIT 1000', array_merge( array( $table ), $parent_ids ) ), ARRAY_A ) as $row ) {
			if ( ! Blocks::hides( $row['user_id'] ) ) {
				$first[ (int) $row['parent_id'] ] ??= $row;
			}
		}

		// Every preview comment and reply on the page is formatted together: a fixed number of queries per page.
		$authors   = array_map( 'intval', array_column( $posts, 'user_id', 'id' ) );
		$formatted = array_column( self::format( array_merge( array_values( $top ), array_values( $first ) ), $authors ), null, 'id' );
		$out       = array();
		foreach ( $top as $post_id => $row ) {
			$comment            = $formatted[ (int) $row['id'] ];
			$reply              = $first[ (int) $row['id'] ] ?? null;
			$comment['replies'] = $reply ? array( $formatted[ (int) $reply['id'] ] ) : array();
			$out[ $post_id ]    = $comment;
		}
		return $out;
	}

	/**
	 * Comments as the app shows them, with authors, reactions and "liked by author".
	 *
	 * @param array[]   $rows        Comment rows.
	 * @param int|int[] $post_author The post's author, or post ID => author when rows span posts.
	 */
	private static function format( array $rows, $post_author ) {
		global $wpdb;
		$rows = array_filter( $rows );
		if ( ! $rows ) {
			return array();
		}
		$id_list = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$reacts = Install::table( 'reactions' );
		$me     = get_current_user_id();
		$users  = Helpers::users( array_merge( wp_list_pluck( $rows, 'user_id' ), wp_list_pluck( $rows, 'reply_to_user_id' ) ) );

		$mine = $wpdb->get_results(
			$wpdb->prepare( "SELECT object_id, type FROM %i WHERE object_type = 'comment' AND user_id = %d AND object_id IN (" . implode( ',', array_fill( 0, count( $id_list ), '%d' ) ) . ')', array_merge( array( $reacts, $me ), $id_list ) ),
			OBJECT_K
		);
		$summary   = array();
		$by_author = array();
		$author_of = array(); // comment ID => author of its post
		foreach ( $rows as $row ) {
			$author_of[ (int) $row['id'] ] = is_array( $post_author ) ? (int) ( $post_author[ (int) $row['post_id'] ] ?? 0 ) : (int) $post_author;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT object_id, user_id, type FROM %i WHERE object_type = 'comment' AND type <> 'down' AND object_id IN (" . implode( ',', array_fill( 0, count( $id_list ), '%d' ) ) . ')', array_merge( array( $reacts ), $id_list ) ), ARRAY_A ) as $r ) {
			$oid                           = (int) $r['object_id'];
			$summary[ $oid ][ $r['type'] ] = ( $summary[ $oid ][ $r['type'] ] ?? 0 ) + 1;
			if ( (int) $r['user_id'] === $author_of[ $oid ] ) {
				$by_author[ $oid ] = $r['type'];
			}
		}

		return array_values(
			array_map(
				function ( $row ) use ( $users, $mine, $summary, $by_author, $me ) {
					$id   = (int) $row['id'];
					$tops = $summary[ $id ] ?? array();
					arsort( $tops );
					return array(
						'id'              => $id,
						'post_id'         => (int) $row['post_id'],
						'parent_id'       => (int) $row['parent_id'],
						'content'         => $row['content'],
						'image'           => $row['image_url'] ? array_filter( array( 'url' => $row['image_url'], 'width' => (int) $row['image_w'], 'height' => (int) $row['image_h'], 'mp4' => $row['image_mp4'] ?? null ) ) : null,
						'created_at'      => Helpers::iso( $row['created_at'] ),
						'author'          => $users[ (int) $row['user_id'] ] ?? null,
						'reply_to'        => $users[ (int) $row['reply_to_user_id'] ] ?? null,
						'reactions_count' => (int) $row['reactions_count'],
						'replies_count'   => (int) $row['replies_count'],
						'my_reaction'     => isset( $mine[ $id ] ) ? $mine[ $id ]->type : null,
						'top_reactions'   => array_slice( array_keys( $tops ), 0, 3 ),
						'liked_by_author' => $by_author[ $id ] ?? null,
						'can_delete'      => (int) $row['user_id'] === $me || Helpers::can_moderate(),
					);
				},
				$rows
			)
		);
	}
}
