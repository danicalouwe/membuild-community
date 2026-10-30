<?php
/**
 * Import from FluentCommunity, read from its fcom_* tables (works with it switched off).
 * Spaces become topics; courses go into the Classroom with their sections, lessons,
 * videos and each member's progress. FluentCommunity free has no chat.
 *
 * Two things differ from the other plugins: its dates are in the site's time zone (not
 * UTC), and its meta and settings columns are PHP-serialized.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import_Fluent extends Import_Source {

	const POST_TYPES = "('text', 'image', 'article', 'feed')";

	public function label() {
		return 'FluentCommunity';
	}

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'fcom_' . $name;
	}

	public function detect() {
		return Import::has_table( self::t( 'posts' ) ) && Import::has_table( self::t( 'spaces' ) );
	}

	public function active() {
		return defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' );
	}

	public function last_activity() {
		global $wpdb;
		$at = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM %i', self::t( 'posts' ) ) );
		return $at ? self::utc( $at ) : null;
	}

	/** Its times are the site's local time. */
	private static function utc( $local ) {
		return $local ? get_gmt_from_date( $local ) : null;
	}

	/** Serialized meta, read without ever making objects. */
	private static function meta( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$v = is_serialized( $value ) ? @unserialize( $value, array( 'allowed_classes' => false ) ) : json_decode( $value, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are refused
		return is_array( $v ) ? $v : array();
	}

	public function summary() {
		global $wpdb;
		$posts = self::t( 'posts' );
		return array(
			'members'   => Import::has_table( self::t( 'xprofile' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . self::site_user( 'user_id' ), self::t( 'xprofile' ) ) ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			'posts'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type IN " . self::POST_TYPES . " AND status = 'published'", $posts ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			'comments'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published'", self::t( 'post_comments' ) ) ),
			'reactions' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'like' AND object_type IN ('feed', 'comment')", self::t( 'post_reactions' ) ) ),
			'messages'  => 0,
			'groups'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'community'", self::t( 'spaces' ) ) ),
			'courses'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'course'", self::t( 'spaces' ) ) ),
			'lessons'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'course_lesson'", $posts ) ),
		);
	}

	public function groups() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, title, description, privacy, logo, cover_photo FROM %i WHERE type = 'community' ORDER BY serial, id", self::t( 'spaces' ) ), ARRAY_A ) as $s ) {
			$members = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, role FROM %i WHERE space_id = %d AND status = 'active'", self::t( 'space_user' ), $s['id'] ), ARRAY_A );
			$out[]   = array(
				'source_id'   => (int) $s['id'],
				'name'        => html_entity_decode( $s['title'], ENT_QUOTES, 'UTF-8' ),
				'description' => wp_trim_words( Import::text( (string) $s['description'] ), 20, '…' ),
				'privacy'     => 'secret' === $s['privacy'] ? 'hidden' : ( 'private' === $s['privacy'] ? 'private' : 'public' ),
				'member_ids'  => array_map( 'intval', array_column( $members, 'user_id' ) ),
				'admin_ids'   => array_map( 'intval', array_column( array_filter( $members, fn( $m ) => 'admin' === $m['role'] ), 'user_id' ) ),
				'avatar_url'  => (string) $s['logo'],
				'cover_url'   => (string) $s['cover_photo'],
				'posts'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE space_id = %d AND type IN ' . self::POST_TYPES, self::t( 'posts' ), $s['id'] ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
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
				return $this->reactions( (int) $after, $limit );
			case 'courses':
				return $this->courses( (int) $after );
		}
		return array( array(), null );
	}

	private function members( $after, $limit ) {
		global $wpdb;
		if ( ! Import::has_table( self::t( 'xprofile' ) ) ) {
			return array( array(), null );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, avatar, short_description, meta FROM %i WHERE id > %d ORDER BY id LIMIT %d', self::t( 'xprofile' ), $after, $limit ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$meta  = self::meta( $r['meta'] );
			$out[] = array(
				'user_id'  => (int) $r['user_id'],
				'avatar'   => (string) $r['avatar'],
				'cover'    => (string) ( $meta['cover_photo'] ?? '' ),
				'bio'      => (string) $r['short_description'],
				'location' => '',
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['id'] );
	}

	/** Photos from meta: several (media_items) or one (media_preview); embeds and GIFs stay links. */
	private static function media( array $meta ) {
		$out = array();
		foreach ( (array) ( $meta['media_items'] ?? array() ) as $m ) {
			if ( ! empty( $m['url'] ) && 'image' === ( $m['type'] ?? 'image' ) ) {
				$out[] = array( 'url' => $m['url'], 'kind' => 'image', 'width' => (int) ( $m['width'] ?? 0 ), 'height' => (int) ( $m['height'] ?? 0 ) );
			}
		}
		$p = $meta['media_preview'] ?? null;
		if ( ! $out && is_array( $p ) && ! empty( $p['image'] ) && in_array( $p['provider'] ?? '', array( 'uploader', 'giphy' ), true ) ) {
			$out[] = array( 'url' => $p['image'], 'kind' => 'image', 'mime' => 'giphy' === $p['provider'] ? 'image/gif' : 'image/jpeg', 'width' => (int) ( $p['width'] ?? 0 ), 'height' => (int) ( $p['height'] ?? 0 ) );
		}
		return $out;
	}

	private function posts( $after, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, user_id, title, message, message_rendered, type, space_id, meta, created_at FROM %i WHERE id > %d AND type IN " . self::POST_TYPES . " AND status = 'published' ORDER BY id LIMIT %d", self::t( 'posts' ), $after, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$meta  = self::meta( $r['meta'] );
			$body  = $r['message_rendered'] ?: $r['message'];
			$out[] = array(
				'source_id'  => (int) $r['id'],
				'user_id'    => (int) $r['user_id'],
				'content'    => ( $r['title'] ? '<p>' . $r['title'] . '</p>' : '' ) . $body,
				'created_at' => self::utc( $r['created_at'] ),
				'group'      => (int) $r['space_id'],
				'media'      => self::media( $meta ),
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['id'] );
	}

	private function comments( $after, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT c.id, c.user_id, c.post_id, c.parent_id, c.message, c.message_rendered, c.meta, c.created_at FROM %i c WHERE c.id > %d AND c.status = 'published' ORDER BY c.id LIMIT %d", self::t( 'post_comments' ), $after, $limit ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$media = self::media( self::meta( $r['meta'] ) );
			$out[] = array(
				'source_id'  => (int) $r['id'],
				'post'       => (int) $r['post_id'],
				'parent'     => (int) $r['parent_id'],
				'user_id'    => (int) $r['user_id'],
				'content'    => $r['message_rendered'] ?: $r['message'],
				'image'      => $media[0]['url'] ?? '',
				'created_at' => self::utc( $r['created_at'] ),
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['id'] );
	}

	private function reactions( $after, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, user_id, object_id, object_type, created_at FROM %i WHERE id > %d AND type = 'like' AND object_type IN ('feed', 'comment') ORDER BY id LIMIT %d", self::t( 'post_reactions' ), $after, $limit ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array( 'object' => 'comment' === $r['object_type'] ? 'comment' : 'post', 'object_id' => (int) $r['object_id'], 'user_id' => (int) $r['user_id'], 'type' => 'like', 'created_at' => self::utc( $r['created_at'] ) );
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['id'] );
	}

	/**
	 * One course at a time: the course, its sections as modules, its lessons (with a
	 * YouTube or Vimeo video when it has one), the students and their finished lessons.
	 * A private course becomes a membership with the same students.
	 */
	private function courses( $after ) {
		global $wpdb;
		$c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE type = 'course' AND id > %d ORDER BY id LIMIT 1", self::t( 'spaces' ), $after ), ARRAY_A );
		if ( ! $c ) {
			return array( array(), null );
		}
		$posts    = self::t( 'posts' );
		$sections = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM %i WHERE space_id = %d AND type = 'course_section' ORDER BY priority, id", $posts, $c['id'] ), ARRAY_A );
		$lessons  = $wpdb->get_results( $wpdb->prepare( "SELECT id, parent_id, title, message, message_rendered, meta, status, created_at FROM %i WHERE space_id = %d AND type = 'course_lesson' ORDER BY priority, id", $posts, $c['id'] ), ARRAY_A );
		$students = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE space_id = %d AND status = 'active'", self::t( 'space_user' ), $c['id'] ) ) );
		$done     = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, object_id, created_at FROM %i WHERE object_type = 'lesson_completed' AND parent_id = %d", self::t( 'post_reactions' ), $c['id'] ), ARRAY_A );
		$course   = array(
			'source_id'   => (int) $c['id'],
			'title'       => html_entity_decode( $c['title'], ENT_QUOTES, 'UTF-8' ),
			'description' => Import::text( (string) $c['description'] ),
			'cover_url'   => (string) $c['cover_photo'],
			'published'   => 'published' === $c['status'] || '' === (string) $c['status'],
			'private'     => 'public' !== $c['privacy'],
			'created_by'  => (int) $c['created_by'],
			'created_at'  => self::utc( $c['created_at'] ),
			'modules'     => array_map( fn( $s ) => array( 'source_id' => (int) $s['id'], 'title' => html_entity_decode( $s['title'], ENT_QUOTES, 'UTF-8' ) ), $sections ),
			'lessons'     => array(),
			'students'    => $students,
			'done'        => array_map( fn( $d ) => array( 'user_id' => (int) $d['user_id'], 'lesson' => (int) $d['object_id'], 'at' => self::utc( $d['created_at'] ) ), $done ),
		);
		foreach ( $lessons as $l ) {
			$meta                = self::meta( $l['meta'] );
			$course['lessons'][] = array(
				'source_id'  => (int) $l['id'],
				'module'     => (int) $l['parent_id'],
				'title'      => html_entity_decode( $l['title'], ENT_QUOTES, 'UTF-8' ),
				'content'    => $l['message_rendered'] ?: $l['message'],
				'video'      => (string) ( $meta['media']['url'] ?? '' ),
				'created_at' => self::utc( $l['created_at'] ),
			);
		}
		return array( array( $course ), (int) $c['id'] );
	}

	public function facts( array $job ) {
		global $wpdb;
		$f             = $this->summary();
		$f['inactive'] = Import::has_table( self::t( 'xprofile' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_activity IS NOT NULL AND last_activity < %s', self::t( 'xprofile' ), wp_date( 'Y-m-d H:i:s', strtotime( '-2 years' ) ) ) ) : 0;
		$f['empty']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type IN " . self::POST_TYPES . " AND status = 'published' AND TRIM(COALESCE(message, '')) = '' AND TRIM(COALESCE(title, '')) = '' AND (meta IS NULL OR meta NOT LIKE %s)", self::t( 'posts' ), '%media_%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
		$f['private']  = 0;
		$f['friends']  = 0;
		$f['media']    = Import::has_table( self::t( 'media_archive' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_source IN ('feed', 'comment')", self::t( 'media_archive' ) ) ) : 0;
		return $f;
	}
}
