<?php
/**
 * Import from PeepSo, read from its tables (works with it switched off). Posts and comments
 * are WordPress posts (peepso-post, peepso-comment) tied to rows in peepso_activities.
 * Likes and reactions are in two tables. Groups, photos and chat come from PeepSo's paid
 * add-ons; when their tables are here, they come along too.
 *
 * Skipped: posts set to "Only me", friends and follows, notifications.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import_Peepso extends Import_Source {

	const ONLY_ME = 40;

	/** Its reaction numbers to ours; the ones we don't have become the closest. */
	const REACTIONS = array( 0 => 'like', 1 => 'love', 2 => 'haha', 3 => 'haha', 4 => 'wow', 5 => 'sad', 6 => 'angry', 7 => 'haha', 8 => 'wow', 9 => 'care', 10 => 'love' );

	public function label() {
		return 'PeepSo';
	}

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'peepso_' . $name;
	}

	public function detect() {
		return Import::has_table( self::t( 'activities' ) ) && Import::has_table( self::t( 'users' ) );
	}

	public function active() {
		return class_exists( 'PeepSo' );
	}

	public function last_activity() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT MAX(post_date_gmt) FROM {$wpdb->posts} WHERE post_type IN ('peepso-post', 'peepso-comment')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare -- core table
	}

	/** Posts: published, from the activity stream (not a comment), not "Only me". */
	private static function posts_sql( $select, $extra = '' ) {
		global $wpdb;
		return "SELECT {$select} FROM {$wpdb->posts} p JOIN " . self::t( 'activities' ) . " a ON a.act_external_id = p.ID AND a.act_comment_object_id = 0 WHERE p.post_type = 'peepso-post' AND p.post_status = 'publish' AND a.act_access <> " . self::ONLY_ME . ' ' . $extra;
	}

	public function summary() {
		global $wpdb;
		$groups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-group' AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- core table
		return array(
			'members'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . self::site_user( 'usr_id' ), self::t( 'users' ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			'posts'     => (int) $wpdb->get_var( self::posts_sql( 'COUNT(*)' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			'comments'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-comment' AND post_status = 'publish'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
			'reactions' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::t( 'likes' ) ) ) + ( Import::has_table( self::t( 'reactions' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::t( 'reactions' ) ) ) : 0 ),
			'messages'  => Import::has_table( self::t( 'message_participants' ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'peepso-message' AND post_parent = 0" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
			'groups'    => $groups,
		);
	}

	/** Groups (the Groups add-on): open ones are public; closed and secret ones private. */
	public function groups() {
		global $wpdb;
		if ( ! Import::has_table( self::t( 'group_members' ) ) ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'peepso-group' AND post_status = 'publish' ORDER BY ID", ARRAY_A ) as $g ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
			$privacy = (int) get_post_meta( (int) $g['ID'], 'peepso_group_privacy', true );
			$members = $wpdb->get_results( $wpdb->prepare( "SELECT gm_user_id, gm_user_status FROM %i WHERE gm_group_id = %d AND gm_user_status LIKE 'member%%'", self::t( 'group_members' ), $g['ID'] ), ARRAY_A );
			$out[]   = array(
				'source_id'   => (int) $g['ID'],
				'name'        => html_entity_decode( $g['post_title'], ENT_QUOTES, 'UTF-8' ),
				'description' => wp_trim_words( Import::text( $g['post_content'] ), 20, '…' ),
				'privacy'     => 0 === $privacy ? 'public' : ( 2 === $privacy ? 'hidden' : 'private' ),
				'member_ids'  => array_map( 'intval', array_column( $members, 'gm_user_id' ) ),
				'admin_ids'   => array_map( 'intval', array_column( array_filter( $members, fn( $m ) => in_array( $m['gm_user_status'], array( 'member_owner', 'member_manager' ), true ) ), 'gm_user_id' ) ),
				'avatar_url'  => '',
				'cover_url'   => '',
				'posts'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'peepso_group_id' AND meta_value = %s", (string) $g['ID'] ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
			);
		}
		return $out;
	}

	public function batch( $step, $after, $limit, array $job ) {
		switch ( $step ) {
			case 'members':
				return $this->members( (int) $after, $limit );
			case 'posts':
				return $this->posts( (int) $after, $limit );
			case 'comments':
				return $this->comments( (int) $after, $limit );
			case 'reactions':
				return $this->reactions( (string) $after, $limit );
			case 'threads':
				return $this->threads( (int) $after, $limit );
		}
		return array( array(), null );
	}

	/** PeepSo keeps its files in wp-content/peepso unless moved. */
	private static function dir() {
		$cfg = get_option( 'peepso_config' );
		$dir = is_array( $cfg ) && ! empty( $cfg['site_peepso_dir'] ) ? untrailingslashit( $cfg['site_peepso_dir'] ) : WP_CONTENT_DIR . '/peepso';
		$url = 0 === strpos( $dir, WP_CONTENT_DIR ) ? WP_CONTENT_URL . substr( $dir, strlen( WP_CONTENT_DIR ) ) : '';
		return array( $dir, $url );
	}

	private static function user_file( $user_id, $names ) {
		list( $dir, $url ) = self::dir();
		foreach ( $names as $name ) {
			if ( $url && is_file( "{$dir}/users/{$user_id}/{$name}" ) ) {
				return "{$url}/users/{$user_id}/" . rawurlencode( $name );
			}
		}
		return '';
	}

	private function members( $after, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT usr_id, usr_cover_photo FROM %i WHERE usr_id > %d ORDER BY usr_id LIMIT %d', self::t( 'users' ), $after, $limit ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$id    = (int) $r['usr_id'];
			$ah    = (string) get_user_meta( $id, 'peepso_avatar_hash', true );
			$ch    = (string) get_user_meta( $id, 'peepso_cover_hash', true );
			$loc   = get_user_meta( $id, 'peepso_user_field_location', true );
			$out[] = array(
				'user_id'  => $id,
				'avatar'   => get_user_meta( $id, 'peepso_use_gravatar', true ) ? '' : self::user_file( $id, array_filter( array( $ah ? "{$ah}-avatar-full.jpg" : '', 'avatar-full.jpg' ) ) ),
				'cover'    => $r['usr_cover_photo'] ?: self::user_file( $id, array_filter( array( $ch ? "{$ch}-cover.jpg" : '', 'cover.jpg' ) ) ),
				'bio'      => (string) get_user_meta( $id, 'description', true ),
				'location' => is_array( $loc ) ? (string) ( $loc['name'] ?? '' ) : (string) $loc,
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['usr_id'] );
	}

	/** Mentions are saved as @peepso_user_12(Ana Ruiz): keep the name. */
	private static function clean( $content ) {
		return preg_replace( '/@peepso_user_\d+\(([^)]*)\)/', '@$1', (string) $content );
	}

	/** Photos (the Photos add-on), from the poster's folder. */
	private static function photos( $post_id, $owner ) {
		global $wpdb;
		if ( ! Import::has_table( self::t( 'photos' ) ) ) {
			return array();
		}
		list( $dir, $url ) = self::dir();
		$out               = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT pho_file_name FROM %i WHERE pho_post_id = %d ORDER BY pho_id', self::t( 'photos' ), $post_id ) ) as $file ) {
			if ( $url && is_file( "{$dir}/users/{$owner}/photos/{$file}" ) ) {
				$out[] = array( 'url' => "{$url}/users/{$owner}/photos/" . rawurlencode( $file ), 'kind' => 'image' );
			}
		}
		return $out;
	}

	private function posts( $after, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( self::posts_sql( 'p.ID, p.post_author, p.post_content, p.post_date_gmt', 'AND p.ID > %d ORDER BY p.ID LIMIT %d' ), $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'source_id'  => (int) $r['ID'],
				'user_id'    => (int) $r['post_author'],
				'content'    => self::clean( $r['post_content'] ),
				'created_at' => $r['post_date_gmt'],
				'group'      => (int) get_post_meta( (int) $r['ID'], 'peepso_group_id', true ),
				'media'      => self::photos( (int) $r['ID'], (int) $r['post_author'] ),
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['ID'] );
	}

	/** A comment points at a post, or at another comment (a reply); a reply's post is its comment's post. */
	private function comments( $after, $limit ) {
		global $wpdb;
		$act  = self::t( 'activities' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.ID, c.post_author, c.post_content, c.post_date_gmt, a.act_comment_object_id AS above, t.post_type AS above_type, a2.act_comment_object_id AS above_post
				FROM {$wpdb->posts} c
				JOIN %i a ON a.act_external_id = c.ID
				LEFT JOIN {$wpdb->posts} t ON t.ID = a.act_comment_object_id
				LEFT JOIN %i a2 ON a2.act_external_id = a.act_comment_object_id
				WHERE c.post_type = 'peepso-comment' AND c.post_status = 'publish' AND c.ID > %d ORDER BY c.ID LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
				$act,
				$act,
				$after,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$reply = 'peepso-comment' === $r['above_type'];
			$out[] = array(
				'source_id'  => (int) $r['ID'],
				'post'       => $reply ? (int) $r['above_post'] : (int) $r['above'],
				'parent'     => $reply ? (int) $r['above'] : 0,
				'user_id'    => (int) $r['post_author'],
				'content'    => self::clean( $r['post_content'] ),
				'image'      => '',
				'created_at' => $r['post_date_gmt'],
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['ID'] );
	}

	/** Likes first (peepso_likes), then the other reactions (peepso_reactions): the cursor says which. */
	private function reactions( $after, $limit ) {
		global $wpdb;
		$in_reactions = 0 === strpos( $after, 'r' );
		$from         = (int) ltrim( $after, 'r' );
		$out          = array();
		if ( ! $in_reactions ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT l.like_id AS id, l.like_user_id AS user_id, l.like_external_id AS object_id, l.like_timestamp AS at, p.post_type FROM %i l JOIN {$wpdb->posts} p ON p.ID = l.like_external_id WHERE l.like_id > %d ORDER BY l.like_id LIMIT %d", self::t( 'likes' ), $from, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$out[] = array( 'object' => 'peepso-comment' === $r['post_type'] ? 'comment' : 'post', 'object_id' => (int) $r['object_id'], 'user_id' => (int) $r['user_id'], 'type' => 'like', 'created_at' => get_gmt_from_date( $r['at'] ) );
			}
			if ( count( $rows ) === $limit ) {
				return array( $out, (string) end( $rows )['id'] );
			}
			if ( ! Import::has_table( self::t( 'reactions' ) ) ) {
				return array( $out, null );
			}
			return array( $out, 'r0' );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT r.reaction_id AS id, r.reaction_user_id AS user_id, r.reaction_type AS type, r.reaction_timestamp AS at, a.act_external_id AS object_id, p.post_type FROM %i r JOIN %i a ON a.act_id = r.reaction_act_id JOIN {$wpdb->posts} p ON p.ID = a.act_external_id WHERE r.reaction_id > %d ORDER BY r.reaction_id LIMIT %d", self::t( 'reactions' ), self::t( 'activities' ), $from, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$out[] = array( 'object' => 'peepso-comment' === $r['post_type'] ? 'comment' : 'post', 'object_id' => (int) $r['object_id'], 'user_id' => (int) $r['user_id'], 'type' => self::REACTIONS[ (int) $r['type'] ] ?? 'like', 'created_at' => get_gmt_from_date( $r['at'] ) );
		}
		return array( $out, count( $rows ) < $limit ? null : 'r' . end( $rows )['id'] );
	}

	/** Chats (the Chat add-on): a first message with the replies under it, and who's in it. */
	private function threads( $after, $limit ) {
		global $wpdb;
		if ( ! Import::has_table( self::t( 'message_participants' ) ) ) {
			return array( array(), null );
		}
		$roots = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_author, post_title, post_content, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'peepso-message' AND post_parent = 0 AND ID > %d ORDER BY ID LIMIT %d", $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
		$out   = array();
		foreach ( $roots as $root ) {
			$rest  = $wpdb->get_results( $wpdb->prepare( "SELECT post_author, post_content, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'peepso-message' AND post_parent = %d ORDER BY post_date_gmt, ID", $root['ID'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
			$all   = array_merge( array( $root ), $rest );
			$users = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT mpart_user_id FROM %i WHERE mpart_msg_id = %d', self::t( 'message_participants' ), $root['ID'] ) ) );
			$out[] = array(
				'source_id'  => (int) $root['ID'],
				'user_ids'   => array_values( array_unique( array_merge( $users, array_map( 'intval', array_column( $all, 'post_author' ) ) ) ) ),
				'title'      => (string) $root['post_title'],
				'created_by' => (int) $root['post_author'],
				'messages'   => array_map( fn( $m ) => array( 'user_id' => (int) $m['post_author'], 'content' => self::clean( $m['post_content'] ), 'created_at' => $m['post_date_gmt'] ), $all ),
			);
		}
		return array( $out, count( $roots ) < $limit ? null : (int) end( $roots )['ID'] );
	}

	public function facts( array $job ) {
		global $wpdb;
		$f             = $this->summary();
		$f['inactive'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE usr_last_activity IS NOT NULL AND usr_last_activity < %s', self::t( 'users' ), gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) ) ) );
		$f['empty']    = (int) $wpdb->get_var( self::posts_sql( 'COUNT(*)', "AND TRIM(p.post_content) = ''" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
		$f['private']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN " . self::t( 'activities' ) . " a ON a.act_external_id = p.ID WHERE p.post_type = 'peepso-post' AND a.act_access = " . self::ONLY_ME ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL
		$f['friends']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN " . self::t( 'activities' ) . " a ON a.act_external_id = p.ID WHERE p.post_type = 'peepso-post' AND a.act_access = 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL
		$f['media']    = Import::has_table( self::t( 'photos' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::t( 'photos' ) ) ) : 0;
		return $f;
	}
}
