<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The "For you" column beside the Home feed on computers: the next event,
 * the course you're in the middle of with its next lesson, and how many
 * people joined this week. Each part is left out when there's nothing to say.
 *
 *   GET /home/for-you
 */
class For_You {

	const NEW_WINDOW = 7 * DAY_IN_SECONDS;

	public static function register_routes() {
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/home/for-you', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get' ), 'permission_callback' => array( 'MemBuild\Community\Rest_Feed', 'logged_in' ) ) );
	}

	public static function get() {
		return array(
			'event'   => self::event(),
			'course'  => self::course(),
			'members' => self::members(),
		);
	}

	/** The soonest event that hasn't ended and isn't cancelled, that I can see. */
	private static function event() {
		global $wpdb;
		if ( ! Settings::feature( 'events' ) ) {
			return null;
		}
		list( $seen, $vals ) = Events::visible_sql();
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $seen is fixed SQL with placeholders; values are bound.
			$wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' AND event_at IS NOT NULL AND event_at + INTERVAL event_minutes MINUTE > %s AND (event_status IS NULL OR event_status <> 'cancelled') AND {$seen} ORDER BY event_at ASC LIMIT 1", array_merge( array( Install::table( 'posts' ), Helpers::now() ), $vals ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$ev = Events::details( array( $row ) )[ (int) $row['id'] ] ?? null;
		return $ev ? array(
			'id'      => (int) $row['id'],
			'title'   => $ev['title'],
			'at'      => $ev['at'],
			'minutes' => $ev['minutes'],
			'tz'      => $ev['tz'],
		) : null;
	}

	/** The course I touched last that still has a lesson to do. */
	private static function course() {
		if ( ! Settings::feature( 'classroom' ) ) {
			return null;
		}
		$list = Rest_Courses::list_courses();
		$best = null;
		foreach ( $list['items'] as $c ) {
			if ( empty( $c['next_lesson'] ) || ! $c['completed_count'] || 'published' !== $c['status'] ) {
				continue;
			}
			if ( ! $best || (string) $c['last_activity'] > (string) $best['last_activity'] ) {
				$best = $c;
			}
		}
		return $best ? array(
			'id'          => $best['id'],
			'title'       => $best['title'],
			'cover'       => $best['cover'],
			'progress'    => $best['progress'],
			'next_lesson' => $best['next_lesson'],
		) : null;
	}

	/** How many people joined in the last week, and a few of their faces. */
	private static function members() {
		global $wpdb;
		$since  = gmdate( 'Y-m-d H:i:s', time() - self::NEW_WINDOW );
		$where  = $wpdb->prepare( 'u.user_registered > %s AND u.ID <> %d', $since, get_current_user_id() ) . Helpers::site_members_sql();
		$hidden = array_keys( Blocks::hidden() ); // people you blocked, or who blocked you
		if ( $hidden ) {
			$where .= ' AND u.ID NOT IN (' . implode( ',', array_map( 'intval', $hidden ) ) . ')';
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above; the ids are ints.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i u WHERE {$where}", $wpdb->users ) );
		if ( ! $count ) {
			return null;
		}
		$faces = $wpdb->get_col( $wpdb->prepare( "SELECT u.ID FROM %i u WHERE {$where} ORDER BY u.user_registered DESC, u.ID DESC LIMIT 3", $wpdb->users ) );
		// phpcs:enable
		return array(
			'count' => $count,
			'faces' => array_values( Helpers::users( $faces ) ),
		);
	}
}
