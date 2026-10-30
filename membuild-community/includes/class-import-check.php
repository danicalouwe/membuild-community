<?php
/**
 * The dry run before an import from a WordPress community plugin: what will happen, in
 * plain words, and anything worth knowing first. Nothing is written.
 *
 * Each line: title, sub (optional), tag: ready | merged | brought | linked | left_out | skipped.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import_Check {

	private static function n( $count ) {
		return number_format_i18n( (int) $count );
	}

	public static function wordpress( Import_Source $src, array $job ) {
		global $wpdb;
		$f      = $src->facts( $job );
		$b      = $job['bring'];
		$items  = array();
		$notes  = array();
		$groups = in_array( 'posts', $b, true ) ? $src->groups() : array();
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE source = %s AND kind = 'post'", Install::table( 'import_map' ), 'buddyboss' === $job['source'] ? 'buddypress' : $job['source'] ) );

		if ( in_array( 'profiles', $b, true ) ) {
			$items[] = array(
				/* translators: %s: number of members */
				'title' => sprintf( _n( '%s member gets their photo, cover, bio and location', '%s members get their photo, cover, bio and location', $f['members'], 'membuild-community' ), self::n( $f['members'] ) ),
				'sub'   => __( 'Only where their profile here is still empty. Everyone keeps their own login.', 'membuild-community' ),
				'tag'   => 'ready',
			);
		}
		if ( in_array( 'posts', $b, true ) ) {
			$posts = max( 0, $f['posts'] - $f['empty'] - $before );
			$items[] = array(
				/* translators: 1: number of posts, 2: number of comments */
				'title' => sprintf( __( '%1$s posts and %2$s comments come over', 'membuild-community' ), self::n( $posts ), self::n( $f['comments'] ) ),
				/* translators: %s: number of posts */
				'sub'   => $f['empty'] ? sprintf( _n( '%s post is empty (a photo that no longer exists) and is left out.', '%s posts are empty (a photo that no longer exists) and are left out.', $f['empty'], 'membuild-community' ), self::n( $f['empty'] ) ) : __( 'In their original order, under their original dates.', 'membuild-community' ),
				'tag'   => 'ready',
			);
		}
		if ( in_array( 'reactions', $b, true ) && $f['reactions'] ) {
			/* translators: %s: number of reactions */
			$items[] = array( 'title' => sprintf( _n( '%s reaction', '%s reactions', $f['reactions'], 'membuild-community' ), self::n( $f['reactions'] ) ), 'sub' => '', 'tag' => 'ready' );
		}
		if ( in_array( 'courses', $b, true ) && ! empty( $f['courses'] ) ) {
			$items[] = array(
				/* translators: 1: number of courses, 2: number of lessons */
				'title' => sprintf( _n( '%1$s course with %2$s lessons goes into the Classroom', '%1$s courses with %2$s lessons go into the Classroom', $f['courses'], 'membuild-community' ), self::n( $f['courses'] ), self::n( $f['lessons'] ) ),
				'sub'   => __( 'With their sections, lessons and videos, and each member’s progress.', 'membuild-community' ),
				'tag'   => 'ready',
			);
		}
		if ( in_array( 'messages', $b, true ) && $f['messages'] ) {
			$items[] = Vault::ready()
				/* translators: %s: number of chats */
				? array( 'title' => sprintf( _n( '%s chat', '%s chats', $f['messages'], 'membuild-community' ), self::n( $f['messages'] ) ), 'sub' => __( 'Encrypted as they come in.', 'membuild-community' ), 'tag' => 'ready' )
				/* translators: %s: number of chats */
				: array( 'title' => sprintf( _n( '%s chat can’t come over yet', '%s chats can’t come over yet', $f['messages'], 'membuild-community' ), self::n( $f['messages'] ) ), 'sub' => __( 'Messages here are encrypted, and the key isn’t set up. See Site Health, then check again.', 'membuild-community' ), 'tag' => 'skipped' );
		}
		if ( $groups ) {
			$room    = max( 0, Topics::MAX - count( Topics::all() ) );
			$private = count( array_filter( $groups, fn( $g ) => 'public' !== $g['privacy'] ) );
			$word    = 'fluentcommunity' === $job['source'] ? __( 'spaces', 'membuild-community' ) : __( 'groups', 'membuild-community' );
			if ( 'spaces' === ( $job['groups_to'] ?? '' ) ) {
				/* translators: 1: number, 2: "groups" or "spaces" */
				$items[] = array( 'title' => sprintf( __( '%1$s %2$s become spaces (Pro)', 'membuild-community' ), self::n( count( $groups ) ), $word ), 'sub' => '', 'tag' => 'ready' );
			} else {
				$items[] = array(
					/* translators: 1: number, 2: "groups" or "spaces", 3: number of topics */
					'title' => sprintf( __( '%1$s %2$s become %3$s topics', 'membuild-community' ), self::n( count( $groups ) ), $word, self::n( min( count( $groups ), $room ) ) ),
					/* translators: %s: number of private groups */
					'sub'   => $private ? sprintf( _n( '%s of them private: a members-only topic, with a membership for the same people.', '%s of them private: members-only topics, each with a membership for the same people.', $private, 'membuild-community' ), self::n( $private ) ) : '',
					'tag'   => 'ready',
				);
				if ( count( $groups ) > $room ) {
					$notes[] = array(
						/* translators: %d: the most topics a community can have */
						'title' => sprintf( __( 'A community has up to %d topics', 'membuild-community' ), Topics::MAX ),
						/* translators: %s: number of groups left */
						'sub'   => sprintf( __( 'The busiest come over as topics. Posts from the other %s still come over, without a topic.', 'membuild-community' ), self::n( count( $groups ) - $room ) ),
						'tag'   => 'brought',
					);
				}
			}
		}

		if ( ! empty( $f['media'] ) ) {
			/* translators: %s: number of photos and videos */
			$notes[] = array( 'title' => sprintf( _n( '%s photo or video', '%s photos and videos', $f['media'], 'membuild-community' ), self::n( $f['media'] ) ), 'sub' => _n( 'It stays where it is in your Media Library, and the post shows it from there.', 'They stay where they are in your Media Library, and posts show them from there.', $f['media'], 'membuild-community' ), 'tag' => 'linked' );
		}
		if ( ! empty( $f['private'] ) ) {
			/* translators: %s: number of posts */
			$notes[] = array( 'title' => sprintf( _n( '%s post was set to “Only me”', '%s posts were set to “Only me”', $f['private'], 'membuild-community' ), self::n( $f['private'] ) ), 'sub' => _n( 'It stays private: it doesn’t come over.', 'They stay private: they don’t come over.', $f['private'], 'membuild-community' ), 'tag' => 'left_out' );
		}
		if ( ! empty( $f['friends'] ) ) {
			/* translators: %s: number of posts */
			$notes[] = array( 'title' => sprintf( _n( '%s post was for friends only', '%s posts were for friends only', $f['friends'], 'membuild-community' ), self::n( $f['friends'] ) ), 'sub' => _n( 'There are no friend lists here, so all members will see it.', 'There are no friend lists here, so all members will see them.', $f['friends'], 'membuild-community' ), 'tag' => 'brought' );
		}
		if ( ! empty( $f['inactive'] ) && in_array( 'profiles', $b, true ) ) {
			/* translators: %s: number of members */
			$notes[] = array( 'title' => sprintf( _n( '%s member hasn’t logged in for over 2 years', '%s members haven’t logged in for over 2 years', $f['inactive'], 'membuild-community' ), self::n( $f['inactive'] ) ), 'sub' => __( 'They come over like everyone else; nobody is emailed.', 'membuild-community' ), 'tag' => 'brought' );
		}
		$here = min( (int) $f['members'], (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'member_stats' ) ) ) );
		if ( $here && in_array( 'profiles', $b, true ) ) {
			/* translators: %s: number of members */
			$notes[] = array( 'title' => sprintf( _n( 'Already in this community: %s member', 'Already in this community: %s members', $here, 'membuild-community' ), self::n( $here ) ), 'sub' => __( 'Matched by their account; nothing is doubled, and nothing they set here is overwritten.', 'membuild-community' ), 'tag' => 'merged' );
		}
		if ( $before ) {
			/* translators: %s: number of posts */
			$notes[] = array( 'title' => __( 'Imported before', 'membuild-community' ), 'sub' => sprintf( __( '%s posts are already here from last time. Only what’s new comes over.', 'membuild-community' ), self::n( $before ) ), 'tag' => 'merged' );
		}
		return array( 'items' => $items, 'notes' => $notes );
	}
}
