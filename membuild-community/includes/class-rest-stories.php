<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Stories ("My Day"): photos, short videos or text that disappear after 24 hours.
 *
 *   GET    /stories                   tray: active stories grouped by person
 *   POST   /stories                   {media_id?, text?, background?}
 *   POST   /stories/{id}/view         mark seen
 *   PUT    /stories/{id}/reaction     {type} quick reaction (shown to the owner)
 *   GET    /stories/{id}/viewers      owner only: who saw it and how they reacted
 *   DELETE /stories/{id}
 */
class Rest_Stories {

	const LIFETIME   = DAY_IN_SECONDS;
	const PHOTO_SECS = 5;
	const MAX_SECS   = 60;

	public static function register_routes() {
		$auth = Rest_Feed::feature( 'stories' );

		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/stories',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'tray' ),
					'permission_callback' => $auth,
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
			'/stories/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/stories/(?P<id>\d+)/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'view' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/stories/(?P<id>\d+)/reaction',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'react' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/stories/(?P<id>\d+)/viewers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'viewers' ),
				'permission_callback' => $auth,
			)
		);
	}

	/**
	 * Active stories grouped by person: you first, then people with stories
	 * you haven't seen (newest first), then everyone you've fully seen.
	 */
	public static function tray() {
		global $wpdb;
		$me      = get_current_user_id();
		$stories = Install::table( 'stories' );
		$views   = Install::table( 'story_views' );
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, v.id AS seen, v.reaction AS my_reaction FROM %i s
				LEFT JOIN %i v ON v.story_id = s.id AND v.user_id = %d
				WHERE s.expires_at > UTC_TIMESTAMP()
				ORDER BY s.created_at DESC LIMIT 2000',
				$stories,
				$views,
				$me
			),
			ARRAY_A
		);

		$rows   = array_reverse( Blocks::filter( $rows ) ); // the newest 2,000, shown oldest first
		$users  = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		$groups = array();
		foreach ( $rows as $row ) {
			$uid = (int) $row['user_id'];
			if ( ! isset( $users[ $uid ] ) ) {
				continue;
			}
			$groups[ $uid ] ??= array( 'author' => $users[ $uid ], 'is_me' => $uid === $me, 'stories' => array() );
			$groups[ $uid ]['stories'][] = self::format( $row, $uid === $me );
		}

		// Your own stories show the last few people who saw them ("Seen by 12" with faces).
		if ( isset( $groups[ $me ] ) ) {
			foreach ( $groups[ $me ]['stories'] as &$mine ) {
				$ids = array_slice( Blocks::filter( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE story_id = %d ORDER BY viewed_at DESC LIMIT 12', $views, $mine['id'] ) ) ) ), 0, 3 );
				$faces = Helpers::users( $ids );
				$mine['seen_faces'] = array_values( array_filter( array_map( fn( $id ) => $faces[ $id ] ?? null, $ids ) ) );
			}
			unset( $mine );
		}

		foreach ( $groups as &$g ) {
			$g['has_unseen'] = ! $g['is_me'] && (bool) array_filter( $g['stories'], fn( $s ) => ! $s['seen'] );
			$g['latest_at']  = end( $g['stories'] )['created_at'];
		}
		unset( $g );

		usort(
			$groups,
			fn( $a, $b ) => array( $b['is_me'], $b['has_unseen'], $b['latest_at'] ) <=> array( $a['is_me'], $a['has_unseen'], $a['latest_at'] )
		);

		return array( 'groups' => array_values( $groups ) );
	}

	public static function create( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard();
		if ( $blocked ) {
			return $blocked;
		}
		// A day's stories are few; this keeps one member from filling everyone's tray.
		$slow = Helpers::throttle( 'story', 30, DAY_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		$me         = get_current_user_id();
		$text       = Helpers::clean_text( $req['text'] ?? '', 250 );
		$background = in_array( $req['background'] ?? '', self::backgrounds(), true ) ? $req['background'] : null;
		$media      = null;

		if ( ! empty( $req['media_id'] ) ) {
			$media = Media::get( (int) $req['media_id'] );
			if ( ! $media || (int) $media['user_id'] !== $me || null !== $media['post_id'] || ! in_array( $media['kind'], array( 'image', 'video' ), true ) ) {
				return new \WP_Error( 'mbcom_bad_media', __( 'That upload is not available.', 'membuild-community' ), array( 'status' => 400 ) );
			}
		} elseif ( '' === $text ) {
			return new \WP_Error( 'mbcom_empty', __( 'Add a photo, a video or some text.', 'membuild-community' ), array( 'status' => 400 ) );
		}

		$kind     = $media ? ( 'video' === $media['kind'] ? 'video' : 'photo' ) : 'text';
		$duration = 'video' === $kind ? min( self::MAX_SECS, max( 1, (float) $media['duration'] ) ) : self::PHOTO_SECS;
		$now      = time();

		$wpdb->insert(
			Install::table( 'stories' ),
			array(
				'user_id'    => $me,
				'kind'       => $kind,
				'media_url'  => $media['url'] ?? null,
				'poster_url' => $media['poster_url'] ?? null,
				'width'      => (int) ( $media['width'] ?? 0 ),
				'height'     => (int) ( $media['height'] ?? 0 ),
				'duration'   => $duration,
				'text'       => '' === $text ? null : $text,
				'background' => 'text' === $kind ? ( $background ?: 'grape' ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::LIFETIME ),
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( $media ) {
			// post_id = 0 marks the upload as used, so it can't also be attached to a post.
			$wpdb->update( Install::table( 'media' ), array( 'post_id' => 0 ), array( 'id' => $media['id'] ) );
		}

		/**
		 * A member shared a story.
		 *
		 * @param int $story_id The story.
		 * @param int $user_id  Its author.
		 */
		do_action( 'membuild_community/story_created', $id, $me );

		$row      = self::get( $id );
		$response = rest_ensure_response( self::format( $row + array( 'seen' => null, 'my_reaction' => null ), true ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function view( \WP_REST_Request $req ) {
		global $wpdb;
		$story = self::get( (int) $req['id'], true );
		if ( ! $story || Blocks::hides( (int) $story['user_id'] ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'This story is no longer available.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$me = get_current_user_id();
		if ( (int) $story['user_id'] !== $me ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (story_id, user_id, viewed_at) VALUES (%d, %d, %s)',
					Install::table( 'story_views' ),
					$story['id'],
					$me,
					Helpers::now()
				)
			);
			if ( $inserted ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET views_count = views_count + 1 WHERE id = %d', Install::table( 'stories' ), $story['id'] ) );
			}
		}
		return array( 'seen' => true );
	}

	public static function react( \WP_REST_Request $req ) {
		global $wpdb;
		$story = self::get( (int) $req['id'], true );
		$type  = in_array( $req['type'], Helpers::REACTIONS, true ) ? $req['type'] : 'like';
		if ( ! $story || Blocks::hides( (int) $story['user_id'] ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'This story is no longer available.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $story['user_id'] === get_current_user_id() ) {
			return new \WP_Error( 'mbcom_own_story', __( 'You cannot react to your own story.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		self::view( $req ); // reacting implies seeing
		$wpdb->update(
			Install::table( 'story_views' ),
			array( 'reaction' => $type ),
			array( 'story_id' => $story['id'], 'user_id' => get_current_user_id() )
		);
		/**
		 * A member reacted to a story.
		 *
		 * @param int    $story_id The story.
		 * @param int    $user_id  Who reacted.
		 * @param string $type     The reaction.
		 */
		do_action( 'membuild_community/story_reacted', (int) $story['id'], get_current_user_id(), $type );
		return array( 'my_reaction' => $type );
	}

	public static function viewers( \WP_REST_Request $req ) {
		global $wpdb;
		$story = self::get( (int) $req['id'] );
		if ( ! $story ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Story not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $story['user_id'] !== get_current_user_id() && ! Helpers::can_moderate() ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'Only the author can see who viewed this.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT user_id, reaction, viewed_at FROM %i WHERE story_id = %d ORDER BY viewed_at DESC LIMIT 500', Install::table( 'story_views' ), $story['id'] ),
			ARRAY_A
		);
		$rows  = Blocks::filter( $rows ); // people the author blocked, or who blocked them, aren't listed
		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		return array(
			'count' => count( $rows ),
			'items' => array_values(
				array_filter(
					array_map(
						fn( $r ) => isset( $users[ (int) $r['user_id'] ] ) ? array(
							'user'      => $users[ (int) $r['user_id'] ],
							'reaction'  => $r['reaction'],
							'viewed_at' => Helpers::iso( $r['viewed_at'] ),
						) : null,
						$rows
					)
				)
			),
		);
	}

	public static function delete( \WP_REST_Request $req ) {
		global $wpdb;
		$story = self::get( (int) $req['id'] );
		if ( ! $story ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Story not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( (int) $story['user_id'] !== get_current_user_id() && ! Helpers::can_moderate() ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'You cannot delete this story.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		self::remove( array( (int) $story['id'] ) );
		return array( 'deleted' => true );
	}

	/** Hourly: removes stories that expired more than a day ago, with their files. */
	public static function cleanup() {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 500', Install::table( 'stories' ) ) ) );
		if ( $ids ) {
			self::remove( $ids );
		}
	}

	/* ---------- Helpers ---------- */

	/** Text story backgrounds: the post ones, the community's own colour and a few more (see lib/postStyle.js). */
	public static function backgrounds() {
		return array_merge( array( 'community' ), Helpers::BACKGROUNDS, array( 'sun', 'blue', 'fire', 'slate' ) );
	}

	private static function get( $id, $active_only = false ) {
		global $wpdb;
		if ( $active_only ) {
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND expires_at > UTC_TIMESTAMP()', Install::table( 'stories' ), $id ), ARRAY_A );
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'stories' ), $id ), ARRAY_A );
	}

	/** Deletes stories, their views and their uploaded files. */
	private static function remove( array $ids ) {
		global $wpdb;
		$ids = array_map( 'intval', $ids );
		if ( ! $ids ) {
			return;
		}
		$urls  = $wpdb->get_col( $wpdb->prepare( 'SELECT media_url FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND media_url IS NOT NULL', array_merge( array( Install::table( 'stories' ) ), $ids ) ) );
		$media = Install::table( 'media' );
		foreach ( $urls as $url ) {
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE url = %s AND post_id = 0', $media, $url ) );
			if ( $id ) {
				Media::delete( (int) $id );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE story_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'story_views' ) ), $ids ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( Install::table( 'stories' ) ), $ids ) ) );
	}

	private static function format( $row, $mine ) {
		return array(
			'id'          => (int) $row['id'],
			'kind'        => $row['kind'],
			'url'         => $row['media_url'],
			'poster'      => $row['poster_url'],
			'width'       => (int) $row['width'],
			'height'      => (int) $row['height'],
			'duration'    => (float) $row['duration'],
			'text'        => $row['text'],
			'background'  => $row['background'],
			'created_at'  => Helpers::iso( $row['created_at'] ),
			'expires_at'  => Helpers::iso( $row['expires_at'] ),
			'seen'        => $mine || ! empty( $row['seen'] ),
			'my_reaction' => $row['my_reaction'] ?? null,
			'views_count' => $mine ? (int) $row['views_count'] : null,
		);
	}
}
