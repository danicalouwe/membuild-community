<?php
/**
 * Search across the community.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * One search box for members, posts, comments and the Classroom.
 *
 *   GET /search ?q=&type=all|members|posts|comments|courses&page=
 *
 * type=all returns a few of each kind for the dropdown under the search box;
 * any other type returns one kind, a page at a time, for the results page.
 *
 * It shows only what the member could already open, using the same rules as
 * the rest of the community:
 * - posts and comments are published ones, never from people hidden from the
 *   member (Blocks), and never reels while reels are switched off;
 * - private messages are never searched (they're encrypted);
 * - a course set to "hide when locked" doesn't appear for members who can't
 *   open it; any other locked course, and its lessons, match by title only and
 *   come back with the lock, so no lesson text is quoted to someone who can't
 *   read it. A lesson that's still dripping matches by title only too.
 *
 * Posts and comments are matched with a FULLTEXT index when the database has
 * one (added in the background after an update, see add_index()), otherwise
 * with LIKE. Either way, every word typed must appear.
 */
class Search {

	const INDEX_HOOK   = 'mbcom_search_index';
	const INDEX_OPTION = 'mbcom_search_index'; // table => 'ready' | 'failed'
	const PER_GROUP    = 4;   // results per kind in the dropdown
	const PAGE_SIZE    = 20;  // results per page on the results page
	const SNIPPET      = 140; // characters around the first match

	public static function register_hooks() {
		add_action( self::INDEX_HOOK, array( __CLASS__, 'add_index' ) );
	}

	public static function register_routes() {
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => Rest_Feed::feature( 'search' ),
				'args'                => array(
					'q'    => array( 'type' => 'string', 'required' => true ),
					'type' => array( 'enum' => array( 'all', 'members', 'posts', 'comments', 'courses', 'places' ), 'default' => 'all' ),
					'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 50 ),
				),
			)
		);
	}

	/* ---------- The search ---------- */

	public static function search( \WP_REST_Request $req ) {
		$q     = self::clean_query( (string) $req['q'] );
		$words = self::words( $q );
		$type  = (string) $req['type'];
		$kinds = array( 'members', 'posts', 'comments', 'courses', 'places' );
		if ( ! Settings::feature( 'places' ) ) {
			$kinds = array_diff( $kinds, array( 'places' ) );
		}
		if ( ! Settings::feature( 'classroom' ) ) {
			$kinds = array_diff( $kinds, array( 'courses' ) );
		}
		if ( 'all' === $type ) {
			$groups = array();
			foreach ( $kinds as $kind ) {
				$found           = $words ? self::$kind( $words, self::PER_GROUP, 0 ) : array();
				$groups[ $kind ] = array(
					'items' => array_slice( $found, 0, self::PER_GROUP ),
					'more'  => count( $found ) > self::PER_GROUP,
				);
			}
			return array( 'query' => $q, 'groups' => $groups );
		}
		if ( ! in_array( $type, $kinds, true ) ) {
			return new \WP_Error( 'mbcom_feature_off', __( 'This part of the community is turned off.', 'membuild-community' ), array( 'status' => 403 ) );
		}
		$page  = (int) $req['page'];
		$found = $words ? self::$type( $words, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE ) : array();
		return array(
			'query'     => $q,
			'items'     => array_slice( $found, 0, self::PAGE_SIZE ),
			'next_page' => count( $found ) > self::PAGE_SIZE ? $page + 1 : null,
		);
	}

	/** Plain text, one line, at most 100 characters. */
	public static function clean_query( $q ) {
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $q ) ) ), 0, 100 );
	}

	/** The words to look for: up to 6, each at least 2 characters (a one-letter search matches everything). */
	public static function words( $q ) {
		$words = array_filter( preg_split( '/[\s,.;:!?()"\'“”‘’]+/u', mb_strtolower( $q ), -1, PREG_SPLIT_NO_EMPTY ), fn( $w ) => mb_strlen( $w ) >= 2 );
		return array_slice( array_values( array_unique( $words ) ), 0, 6 );
	}

	/**
	 * SQL that requires every word in $column, and its values: FULLTEXT when the
	 * table has the index and every word is long enough for it, LIKE otherwise.
	 *
	 * @return array{0:string,1:array}
	 */
	public static function match( $table, $column, array $words ) {
		global $wpdb;
		static $min = null;
		$min = $min ?? (int) ( $wpdb->get_var( "SHOW VARIABLES LIKE 'innodb_ft_min_token_size'", 1 ) ?: 3 ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ft  = 'ready' === ( get_option( self::INDEX_OPTION, array() )[ $table ] ?? '' ) && ! array_filter( $words, fn( $w ) => mb_strlen( $w ) < $min || preg_match( '/[^\p{L}\p{N}]/u', $w ) );
		if ( $ft ) {
			return array( "MATCH({$column}) AGAINST (%s IN BOOLEAN MODE)", array( implode( ' ', array_map( fn( $w ) => '+' . $w . '*', $words ) ) ) );
		}
		return array( implode( ' AND ', array_fill( 0, count( $words ), "{$column} LIKE %s" ) ), array_map( fn( $w ) => '%' . $wpdb->esc_like( $w ) . '%', $words ) );
	}

	/** The text around the first word found, with "…" where it was cut. */
	public static function snippet( $text, array $words ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		if ( mb_strlen( $text ) <= self::SNIPPET ) {
			return $text;
		}
		$at = false;
		foreach ( $words as $w ) {
			$pos = mb_stripos( $text, $w );
			if ( false !== $pos && ( false === $at || $pos < $at ) ) {
				$at = $pos;
			}
		}
		$start = max( 0, (int) $at - 40 );
		$cut   = mb_substr( $text, $start, self::SNIPPET );
		return ( $start ? '…' : '' ) . trim( $cut ) . ( $start + self::SNIPPET < mb_strlen( $text ) ? '…' : '' );
	}

	/* ---------- Kinds ---------- */

	/** Members by name, then by bio. Login names and emails are never searched. */
	private static function members( array $words, $limit, $offset ) {
		global $wpdb;
		$name_sql = implode( ' AND ', array_fill( 0, count( $words ), 'u.display_name LIKE %s' ) );
		$bio_sql  = implode( ' AND ', array_fill( 0, count( $words ), 'b.meta_value LIKE %s' ) );
		$likes    = array_map( fn( $w ) => '%' . $wpdb->esc_like( $w ) . '%', $words );
		// In the order the placeholders appear: the SELECT's name test, the tables, then WHERE.
		$args     = array_merge( $likes, array( $wpdb->users, $wpdb->usermeta, 'mbcom_bio' ), $likes, $likes, array( $limit + 1, $offset ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $name_sql/$bio_sql are fixed fragments with %s placeholders, one per word; site_members_sql() is a prepared fragment; every value is bound in $args.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, b.meta_value AS bio, ({$name_sql}) AS by_name
				FROM %i u LEFT JOIN %i b ON b.user_id = u.ID AND b.meta_key = %s
				WHERE (({$name_sql}) OR ({$bio_sql}))" . Helpers::site_members_sql() . '
				ORDER BY by_name DESC, u.display_name ASC, u.ID ASC LIMIT %d OFFSET %d',
				$args
			),
			ARRAY_A
		);
		// phpcs:enable
		$rows  = Blocks::filter( $rows, 'ID' );
		$users = Helpers::users( wp_list_pluck( $rows, 'ID' ) );
		$out   = array();
		foreach ( $rows as $r ) {
			if ( isset( $users[ (int) $r['ID'] ] ) && ! Moderation::is_banned( (int) $r['ID'] ) ) {
				$out[] = array(
					'kind'    => 'member',
					'id'      => (int) $r['ID'],
					'user'    => $users[ (int) $r['ID'] ],
					'snippet' => $r['by_name'] ? '' : self::snippet( $r['bio'], $words ),
					'link'    => array( 'name' => 'member', 'params' => array( 'id' => (int) $r['ID'] ) ),
				);
			}
		}
		return $out;
	}

	/** Published posts (and reels, while they're on), newest first. */
	private static function posts( array $words, $limit, $offset ) {
		global $wpdb;
		$posts           = Install::table( 'posts' );
		list( $m, $vals ) = self::match( 'posts', 'content', $words );
		$reels           = Settings::feature( 'reels' ) ? '' : " AND type = 'post'";
		// Posts in courses show only to members who can open that course and lesson.
		list( $seen, $seen_vals ) = Discussions::visible_sql();
		list( $ev, $ev_vals )     = Events::visible_sql();
		$rows            = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $m comes from match(), $seen and $ev from visible_sql(): fixed SQL with placeholders; $reels is a fixed literal; values are bound.
			$wpdb->prepare( "SELECT id, user_id, type, content, course_id, lesson_id, event_at, event_title, reactions_count, comments_count, created_at FROM %i WHERE status = 'published'{$reels} AND {$seen} AND {$ev} AND ({$m} OR event_title LIKE %s) ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( array( $posts ), $seen_vals, $ev_vals, $vals, array( '%' . $wpdb->esc_like( $words[0] ) . '%', $limit + 1, $offset ) ) ),
			ARRAY_A
		);
		$rows  = Blocks::filter( $rows );
		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		// The first attachment gives the row a picture: a photo, a video's poster or an embed's thumbnail.
		$media = Media::for_posts( wp_list_pluck( $rows, 'id' ) );
		$first = function ( $id ) use ( $media ) {
			$m = $media[ (int) $id ][0] ?? null;
			if ( ! $m ) {
				return array( null, false );
			}
			$video = in_array( $m['kind'], array( 'video', 'embed' ), true ) || ! empty( $m['stream'] ); // embeds are always videos (YouTube, Vimeo)
			return array( $m['poster'] ?: ( 'image' === $m['kind'] ? $m['url'] : null ), $video );
		};
		return array_values(
			array_filter(
				array_map(
					fn( $r ) => isset( $users[ (int) $r['user_id'] ] ) ? array(
						'kind'       => 'reel' === $r['type'] ? 'reel' : 'post',
						'id'         => (int) $r['id'],
						'user'       => $users[ (int) $r['user_id'] ],
						'thumb'      => $first( $r['id'] )[0],
						'video'      => 'reel' === $r['type'] || $first( $r['id'] )[1],
						'snippet'    => self::snippet( $r['content'], $words ),
						'reactions'  => (int) $r['reactions_count'],
						'comments'   => (int) $r['comments_count'],
						'created_at' => Helpers::iso( $r['created_at'] ),
						'in_course'  => (bool) (int) $r['course_id'],
						'event'      => $r['event_at'] ? (string) $r['event_title'] : null,
						'link'       => $r['event_at'] ? array( 'name' => 'event', 'params' => array( 'id' => (int) $r['id'] ) ) : array( 'query' => array( 'post' => (int) $r['id'] ) ),
					) : null,
					$rows
				)
			)
		);
	}

	/** Comments on published posts, newest first, with the post they're on. */
	private static function comments( array $words, $limit, $offset ) {
		global $wpdb;
		list( $m, $vals ) = self::match( 'comments', 'c.content', $words );
		$reels           = Settings::feature( 'reels' ) ? '' : " AND p.type = 'post'";
		list( $seen, $seen_vals ) = Discussions::visible_sql( 'p' );
		$rows            = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $m comes from match() and $seen from visible_sql(): fixed SQL with placeholders; $reels is a fixed literal; values are bound.
			$wpdb->prepare( "SELECT c.id, c.post_id, c.user_id, c.content, c.created_at, p.user_id AS post_user, p.content AS post_content FROM %i c INNER JOIN %i p ON p.id = c.post_id AND p.status = 'published'{$reels} AND {$seen} WHERE {$m} ORDER BY c.id DESC LIMIT %d OFFSET %d", array_merge( array( Install::table( 'comments' ), Install::table( 'posts' ) ), $seen_vals, $vals, array( $limit + 1, $offset ) ) ),
			ARRAY_A
		);
		$rows  = Blocks::filter( Blocks::filter( $rows ), 'post_user' ); // neither the comment's author nor the post's
		$users = Helpers::users( wp_list_pluck( $rows, 'user_id' ) );
		return array_values(
			array_filter(
				array_map(
					fn( $r ) => isset( $users[ (int) $r['user_id'] ] ) ? array(
						'kind'       => 'comment',
						'id'         => (int) $r['id'],
						'post_id'    => (int) $r['post_id'],
						'user'       => $users[ (int) $r['user_id'] ],
						'snippet'    => self::snippet( $r['content'], $words ),
						'on'         => mb_substr( self::snippet( $r['post_content'], array() ), 0, 60 ),
						'created_at' => Helpers::iso( $r['created_at'] ),
						'link'       => array( 'query' => array( 'post' => (int) $r['post_id'] ) ),
					) : null,
					$rows
				)
			)
		);
	}

	/**
	 * Courses, then lessons. A locked course and its lessons match by title only;
	 * an open course's lessons match by their text too, unless still dripping.
	 */
	private static function courses( array $words, $limit, $offset ) {
		global $wpdb;
		$rows = Helpers::can_manage()
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', Install::table( 'courses' ) ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'published' ORDER BY sort_order ASC, id ASC", Install::table( 'courses' ) ), ARRAY_A );
		$courses = array();
		foreach ( $rows as $c ) {
			if ( ! Access::hidden( $c ) ) {
				$c['lock']                  = Access::lock( $c );
				$courses[ (int) $c['id'] ] = $c;
			}
		}
		if ( ! $courses ) {
			return array();
		}
		$has_all = fn( $text ) => ! array_filter( $words, fn( $w ) => false === mb_stripos( wp_strip_all_tags( (string) $text ), $w ) );
		$out     = array();
		foreach ( $courses as $c ) {
			$by_title = $has_all( $c['title'] );
			if ( $by_title || ( ! $c['lock'] && $has_all( $c['description'] ) ) ) {
				$out[] = self::course_item( $c, $by_title ? '' : self::snippet( $c['description'], $words ) );
			}
		}

		// Lessons: by title in any listed course; by text only where the course is open.
		$ids       = array_keys( $courses );
		$open      = array_keys( array_filter( $courses, fn( $c ) => ! $c['lock'] ) );
		$likes     = array_map( fn( $w ) => '%' . $wpdb->esc_like( $w ) . '%', $words );
		$title_sql = implode( ' AND ', array_fill( 0, count( $words ), 'title LIKE %s' ) );
		$text_sql  = implode( ' AND ', array_fill( 0, count( $words ), 'content LIKE %s' ) );
		$in        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$in_open   = $open ? implode( ',', array_fill( 0, count( $open ), '%d' ) ) : '0';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed fragments with one placeholder per word or ID; every value is bound.
		$lessons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, course_id, title, content, drip_days, open_on, ({$title_sql}) AS by_title FROM %i
				WHERE course_id IN ({$in}) AND (({$title_sql}) OR (course_id IN ({$in_open}) AND {$text_sql}))
				ORDER BY by_title DESC, course_id ASC, sort_order ASC, id ASC LIMIT 200",
				array_merge( $likes, array( Install::table( 'lessons' ) ), $ids, $likes, $open, $likes )
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $lessons as $l ) {
			$course = $courses[ (int) $l['course_id'] ];
			$unlock = $course['lock'] ? null : Rest_Courses::unlock_at( (int) $l['drip_days'], (int) $l['course_id'], $l['open_on'] );
			if ( ! $l['by_title'] && $unlock ) {
				continue; // matched only by text the member can't read yet
			}
			$out[] = array(
				'kind'      => 'lesson',
				'id'        => (int) $l['id'],
				'course_id' => (int) $course['id'],
				'title'     => $l['title'],
				'course'    => $course['title'],
				'snippet'   => $l['by_title'] || $course['lock'] ? '' : self::snippet( $l['content'], $words ),
				'lock'      => $course['lock'],
				'unlock_at' => $unlock ? gmdate( 'c', $unlock ) : null,
				'link'      => array( 'name' => 'course', 'params' => array( 'courseId' => (int) $course['id'], 'lessonId' => (int) $l['id'] ) ),
			);
		}
		return array_slice( $out, $offset, $limit + 1 );
	}

	/** Published places by name, address or perk. */
	private static function places( array $words, $limit, $offset ) {
		global $wpdb;
		$fields = array();
		$args   = array( Install::table( 'places' ) );
		foreach ( $words as $w ) {
			$fields[] = '(name LIKE %s OR address LIKE %s OR perk LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $w ) . '%';
			array_push( $args, $like, $like, $like );
		}
		array_push( $args, $limit + 1, $offset );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- one fixed fragment with placeholders per word; every value is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, category, perk, address, cover_url FROM %i WHERE status = 'published' AND " . implode( ' AND ', $fields ) . ' ORDER BY name ASC LIMIT %d OFFSET %d', $args ), ARRAY_A );
		return array_map(
			fn( $r ) => array(
				'kind'     => 'place',
				'id'       => (int) $r['id'],
				'title'    => $r['name'],
				'category' => $r['category'],
				'perk'     => $r['perk'],
				'address'  => $r['address'],
				'cover'    => $r['cover_url'] ?: null,
				'link'     => array( 'name' => 'places', 'params' => array( 'placeId' => (int) $r['id'] ) ),
			),
			$rows
		);
	}

	private static function course_item( array $c, $snippet ) {
		global $wpdb;
		return array(
			'kind'    => 'course',
			'id'      => (int) $c['id'],
			'title'   => $c['title'],
			'cover'   => $c['cover_url'] ?: null,
			'lessons' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $c['id'] ) ),
			'snippet' => $snippet,
			'lock'    => $c['lock'],
			'draft'   => 'published' !== $c['status'],
			'link'    => array( 'name' => 'course', 'params' => array( 'courseId' => (int) $c['id'] ) ),
		);
	}

	/* ---------- The index ---------- */

	/** Schedules add_index() in the background: on a big site, building it takes a while. */
	public static function schedule_index() {
		if ( ! wp_next_scheduled( self::INDEX_HOOK ) ) {
			wp_schedule_single_event( time(), self::INDEX_HOOK );
		}
	}

	/**
	 * Adds a FULLTEXT index on post and comment text, once. Search works without
	 * it (with LIKE); a database that can't build one is remembered and not asked again.
	 */
	public static function add_index() {
		global $wpdb;
		$state = get_option( self::INDEX_OPTION, array() );
		foreach ( array( 'posts', 'comments' ) as $name ) {
			if ( isset( $state[ $name ] ) ) {
				continue;
			}
			$table  = Install::table( $name );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'mbcom_search' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table: a search index, added once.
			$state[ $name ] = $exists || false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD FULLTEXT KEY mbcom_search (content)', $table ) ) ? 'ready' : 'failed';
		}
		update_option( self::INDEX_OPTION, $state, false );
	}
}
