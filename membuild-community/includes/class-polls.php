<?php
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Polls: a Home post (its words are the question) with 2 to 6 choices. Members vote with
 * one tap and can change their vote until it closes. Nobody can see who voted, not even
 * the owner, so only totals ever leave this class. Closing is final.
 */
class Polls {

	const MIN_CHOICES = 2;
	const MAX_CHOICES = 6;

	/** A choice's longest length. */
	const MAX_LABEL = 200;

	/** "Poll closes": the choice's key => seconds from now, 0 for no end date. */
	const CLOSES = array(
		'1d'   => DAY_IN_SECONDS,
		'3d'   => 3 * DAY_IN_SECONDS,
		'1w'   => WEEK_IN_SECONDS,
		'1m'   => 30 * DAY_IN_SECONDS,
		'none' => 0,
	);

	public static function register_routes() {
		$auth = array( Rest_Feed::class, 'logged_in' );

		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/vote',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'vote' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/posts/(?P<id>\d+)/poll/close',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'close' ),
				'permission_callback' => $auth,
			)
		);
	}

	public static function register_hooks() {
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ) );
	}

	public static function on_user_deleted( $user_id ) {
		global $wpdb;
		$wpdb->delete( Install::table( 'poll_votes' ), array( 'user_id' => (int) $user_id ) );
	}

	/**
	 * Checks what the post box sent, before anything is saved.
	 *
	 * @param mixed $raw { options: string[], closes: one of CLOSES }.
	 * @return array|\WP_Error { options, closes_at }
	 */
	public static function parse( $raw ) {
		$raw     = is_array( $raw ) ? $raw : array();
		$options = array();
		$seen    = array();
		foreach ( (array) ( $raw['options'] ?? array() ) as $label ) {
			$label = is_string( $label ) ? Helpers::clean_text( $label, self::MAX_LABEL ) : '';
			if ( '' === $label ) {
				continue;
			}
			$key = mb_strtolower( $label );
			if ( isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'mbcom_poll_same', __( 'Two choices are the same. Change one of them.', 'membuild-community' ), array( 'status' => 400 ) );
			}
			$seen[ $key ] = true;
			$options[]    = $label;
		}
		if ( count( $options ) < self::MIN_CHOICES ) {
			return new \WP_Error( 'mbcom_poll_few', __( 'A poll needs at least 2 choices.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( count( $options ) > self::MAX_CHOICES ) {
			return new \WP_Error( 'mbcom_poll_many', __( '6 choices is the most.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$seconds = self::CLOSES[ $raw['closes'] ?? '1w' ] ?? WEEK_IN_SECONDS;
		return array(
			'options'   => $options,
			'closes_at' => $seconds ? gmdate( 'Y-m-d H:i:s', time() + $seconds ) : null,
		);
	}

	/** Saves a checked poll under a post that was just made. */
	public static function create( $post_id, array $poll ) {
		global $wpdb;
		$wpdb->insert( Install::table( 'polls' ), array( 'post_id' => $post_id, 'closes_at' => $poll['closes_at'], 'created_at' => Helpers::now() ) );
		foreach ( $poll['options'] as $position => $label ) {
			$wpdb->insert( Install::table( 'poll_options' ), array( 'post_id' => $post_id, 'label' => $label, 'position' => $position ) );
		}
	}

	/** Whether this post is a poll. */
	public static function exists( $post_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE post_id = %d', Install::table( 'polls' ), $post_id ) );
	}

	/** The question and choices lock once someone has voted. */
	public static function has_votes( $post_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE post_id = %d LIMIT 1', Install::table( 'poll_votes' ), $post_id ) );
	}

	/** Personal-data erasure of a post: its choices are the writer's words too. */
	public static function wipe( $post_id ) {
		global $wpdb;
		$wpdb->update( Install::table( 'poll_options' ), array( 'label' => '' ), array( 'post_id' => $post_id ) );
	}

	/**
	 * What posts that are polls carry, for a whole page of posts: three queries, and only
	 * the first when none of them is a poll. Each choice's share is only sent to someone who
	 * has voted, can't vote, or once it's closed, so it can't sway a vote before that.
	 *
	 * @param int[] $post_ids Posts of this page.
	 * @param int[] $authors  Who wrote each of them, by post id.
	 * @return array<int,array> By post id.
	 */
	public static function details( array $post_ids, array $authors ) {
		global $wpdb;
		if ( ! $post_ids ) {
			return array();
		}
		$in    = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$polls = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, closes_at, closed_at FROM %i WHERE post_id IN ($in)", array_merge( array( Install::table( 'polls' ) ), $post_ids ) ), OBJECT_K );
		if ( ! $polls ) {
			return array();
		}
		$ids     = array_map( 'intval', array_keys( $polls ) );
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$options = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id, label FROM %i WHERE post_id IN ($in) ORDER BY position", array_merge( array( Install::table( 'poll_options' ) ), $ids ) ), ARRAY_A ) as $o ) {
			$options[ (int) $o['post_id'] ][] = $o;
		}
		$me    = get_current_user_id();
		$votes = array();
		$mine  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, option_id, COUNT(*) AS n, MAX(user_id = %d) AS mine FROM %i WHERE post_id IN ($in) GROUP BY post_id, option_id", array_merge( array( $me, Install::table( 'poll_votes' ) ), $ids ) ), ARRAY_A ) as $v ) {
			$votes[ (int) $v['post_id'] ][ (int) $v['option_id'] ] = (int) $v['n'];
			if ( (int) $v['mine'] ) {
				$mine[ (int) $v['post_id'] ] = (int) $v['option_id'];
			}
		}
		$muted = (bool) Moderation::restriction( $me );
		$staff = Helpers::can_moderate();
		$now   = time();

		$out = array();
		foreach ( $polls as $id => $poll ) {
			$id         = (int) $id;
			$total      = array_sum( $votes[ $id ] ?? array() );
			$ended_at   = $poll->closed_at ?: ( $poll->closes_at && strtotime( $poll->closes_at . ' UTC' ) <= $now ? $poll->closes_at : null );
			$closed     = (bool) $ended_at;
			$can_vote   = ! $closed && ! $muted;
			$my_vote    = $mine[ $id ] ?? null;
			$results    = $closed || null !== $my_vote || ! $can_vote;
			$out[ $id ] = array(
				'options'     => array_map(
					fn( $o ) => array(
						'id'      => (int) $o['id'],
						'label'   => $o['label'],
						'percent' => $results ? ( $total ? (int) round( 100 * ( $votes[ $id ][ (int) $o['id'] ] ?? 0 ) / $total ) : 0 ) : null,
					),
					$options[ $id ] ?? array()
				),
				'total_votes' => $total,
				'my_vote'     => $my_vote,
				'closes_at'   => $poll->closes_at ? Helpers::iso( $poll->closes_at ) : null,
				'closed'      => $closed,
				'closed_at'   => $closed ? Helpers::iso( $ended_at ) : null,
				'can_vote'    => $can_vote,
				'can_close'   => ! $closed && ( (int) ( $authors[ $id ] ?? 0 ) === $me || $staff ),
				'locked'      => $total > 0,
			);
		}
		return $out;
	}

	/** One poll's state, after a vote or closing it. */
	private static function state( array $row ) {
		$id = (int) $row['id'];
		return self::details( array( $id ), array( $id => (int) $row['user_id'] ) )[ $id ] ?? null;
	}

	/** A poll this member can see, or an error saying why not. */
	private static function find( $post_id ) {
		$row  = Rest_Feed::row( $post_id );
		$poll = $row ? self::state( $row ) : null;
		if ( ! $poll ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Post not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		return array( $row, $poll );
	}

	/** Votes, or moves this member's vote to another choice. Totals only: no one is told. */
	public static function vote( \WP_REST_Request $req ) {
		global $wpdb;
		$blocked = Moderation::guard();
		if ( $blocked ) {
			return $blocked;
		}
		$found = self::find( (int) $req['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $row, $poll ) = $found;
		if ( $poll['closed'] ) {
			return new \WP_Error( 'mbcom_poll_closed', __( 'This poll is closed.', 'membuild-community' ), array( 'status' => 409 ) );
		}
		$option_id = (int) ( $req['option_id'] ?? 0 );
		if ( ! in_array( $option_id, wp_list_pluck( $poll['options'], 'id' ), true ) ) {
			return new \WP_Error( 'mbcom_poll_choice', __( 'That choice isn’t in this poll.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$slow = Helpers::throttle( 'vote', 120, 10 * MINUTE_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (post_id, option_id, user_id, created_at) VALUES (%d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)',
				Install::table( 'poll_votes' ),
				(int) $row['id'],
				$option_id,
				get_current_user_id(),
				Helpers::now()
			)
		);
		return self::state( $row );
	}

	/** The writer, moderators or the owner close it early. It can't be opened again. */
	public static function close( \WP_REST_Request $req ) {
		global $wpdb;
		$found = self::find( (int) $req['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $row, $poll ) = $found;
		if ( (int) $row['user_id'] !== get_current_user_id() && ! Helpers::can_moderate() ) {
			return new \WP_Error( 'mbcom_forbidden', __( 'You cannot close this poll.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		if ( $poll['closed'] ) {
			return $poll;
		}
		$wpdb->update( Install::table( 'polls' ), array( 'closed_at' => Helpers::now() ), array( 'post_id' => (int) $row['id'] ) );
		return self::state( $row );
	}
}
