<?php
/**
 * The Course map's live numbers (owners): who has started the course, who is
 * on each lesson right now and for how long, who is waiting for the next
 * lesson to open, and who has finished.
 *
 * A member is "on" the first lesson in the course they haven't finished. If
 * that lesson hasn't opened for them yet (it drips), they're "waiting" in the
 * gap just before it instead.
 *
 * @package MemBuild\Community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Course_Map {

	/** Faces listed per step; the count is always the full number. */
	const PEOPLE = 60;

	/** Days on one lesson after which the map says they may be stuck. */
	const STUCK_DAYS = 7;

	public static function build( array $course ) {
		global $wpdb;
		$id      = (int) $course['id'];
		$lessons = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.id, l.drip_days, l.open_on FROM %i l JOIN %i m ON m.id = l.module_id WHERE l.course_id = %d ORDER BY m.sort_order ASC, m.id ASC, l.sort_order ASC, l.id ASC',
				Install::table( 'lessons' ),
				Install::table( 'modules' ),
				$id
			),
			ARRAY_A
		);
		$starts  = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, started_at FROM %i WHERE course_id = %d', Install::table( 'course_starts' ), $id ), ARRAY_A );
		$done    = array();
		$last    = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, lesson_id, completed_at FROM %i WHERE course_id = %d', Install::table( 'lesson_progress' ), $id ), ARRAY_A ) as $p ) {
			$u                                     = (int) $p['user_id'];
			$done[ $u ][ (int) $p['lesson_id'] ] = true;
			if ( ! isset( $last[ $u ] ) || $p['completed_at'] > $last[ $u ] ) {
				$last[ $u ] = $p['completed_at'];
			}
		}

		$now      = time();
		$steps    = array(); // lesson id => { on: [user ids], waiting: [user ids] }
		$finished = array();
		$since    = array();
		foreach ( $lessons as $l ) {
			$steps[ (int) $l['id'] ] = array( 'on' => array(), 'waiting' => array() );
		}
		foreach ( $starts as $s ) {
			$u       = (int) $s['user_id'];
			$started = strtotime( $s['started_at'] . ' UTC' );
			$next    = null;
			foreach ( $lessons as $l ) {
				if ( empty( $done[ $u ][ (int) $l['id'] ] ) ) {
					$next = $l;
					break;
				}
			}
			if ( ! $next ) {
				if ( $lessons ) {
					$finished[] = $u;
				}
				continue;
			}
			$opens         = $next['open_on'] ? Rest_Courses::date_ts( $next['open_on'] ) : $started + (int) $next['drip_days'] * DAY_IN_SECONDS;
			$since[ $u ]   = max( $started, isset( $last[ $u ] ) ? strtotime( $last[ $u ] . ' UTC' ) : 0 );
			$which         = $opens > $now ? 'waiting' : 'on';
			$steps[ (int) $next['id'] ][ $which ][] = $u;
		}

		// Faces and names, for the people shown on each step.
		$show = $finished;
		foreach ( $steps as $s ) {
			$show = array_merge( $show, array_slice( $s['on'], 0, self::PEOPLE ), array_slice( $s['waiting'], 0, self::PEOPLE ) );
		}
		$users = Helpers::users( $show );
		$person = function ( $u ) use ( $users, $since, $now ) {
			if ( ! isset( $users[ $u ] ) ) {
				return null;
			}
			$seen = (int) get_user_meta( $u, Email::LAST_ACTIVE, true );
			$days = isset( $since[ $u ] ) ? (int) floor( ( $now - $since[ $u ] ) / DAY_IN_SECONDS ) : 0;
			return $users[ $u ] + array(
				'days'        => $days,
				'stuck'       => $days >= self::STUCK_DAYS,
				'last_active' => $seen ? gmdate( 'c', $seen ) : null,
			);
		};
		$list = fn( array $ids ) => array_values( array_filter( array_map( $person, array_slice( $ids, 0, self::PEOPLE ) ) ) );

		$out = array();
		foreach ( $steps as $lesson_id => $s ) {
			$out[] = array(
				'lesson_id' => $lesson_id,
				'on'        => count( $s['on'] ),
				'waiting'   => count( $s['waiting'] ),
				'people'    => $list( $s['on'] ),
				'waiters'   => $list( $s['waiting'] ),
			);
		}
		// Who is on a lesson longest comes first: they're the ones to check on.
		foreach ( $out as &$step ) {
			usort( $step['people'], fn( $a, $b ) => $b['days'] <=> $a['days'] );
		}
		unset( $step );

		return array(
			'started'  => count( $starts ),
			'finished' => count( $finished ),
			'finishers' => $list( $finished ),
			'lessons'  => $out,
		);
	}
}
