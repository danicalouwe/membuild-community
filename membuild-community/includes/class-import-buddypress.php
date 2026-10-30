<?php
/**
 * Import from BuddyBoss or BuddyPress, read from their tables (they share them; BuddyBoss
 * adds media, reactions and a few columns). Works with the plugin switched off.
 *
 * Skipped on purpose: friends and follows, notifications, forums, and posts set to
 * "Only me" (BuddyBoss). "Friends" posts come over for all members (there are no friends here).
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import_Buddypress extends Import_Source {

	private $fields = null;

	public function label() {
		return 'buddyboss' === $this->key ? 'BuddyBoss' : 'BuddyPress';
	}

	private static function t( $name ) {
		global $wpdb;
		return $wpdb->base_prefix . $name;
	}

	/** BuddyBoss leaves its own tables beside BuddyPress's. */
	public static function is_buddyboss() {
		return Import::has_table( self::t( 'bb_user_reactions' ) ) || Import::has_table( self::t( 'bp_media' ) ) || false !== get_option( 'bb_plugin_version_history', false );
	}

	public function detect() {
		return Import::has_table( self::t( 'bp_activity' ) ) && ( 'buddyboss' === $this->key ) === self::is_buddyboss();
	}

	public function active() {
		return 'buddyboss' === $this->key ? defined( 'BP_PLATFORM_VERSION' ) : ( defined( 'BP_VERSION' ) && ! defined( 'BP_PLATFORM_VERSION' ) );
	}

	public function last_activity() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(date_recorded) FROM %i', self::t( 'bp_activity' ) ) );
	}

	private function has_column( $column ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::t( 'bp_activity' ), $column ) );
	}

	/** Posts worth bringing: not spam, not scheduled, not "Only me". */
	private function post_where( $alias = '' ) {
		$a     = $alias ? $alias . '.' : '';
		$where = "{$a}is_spam = 0";
		if ( $this->has_column( 'status' ) ) {
			$where .= " AND {$a}status = 'published'";
		}
		if ( $this->has_column( 'privacy' ) ) {
			$where .= " AND {$a}privacy NOT IN ('onlyme', 'media', 'video', 'document')";
		}
		return $where;
	}

	public function summary() {
		global $wpdb;
		$act = self::t( 'bp_activity' );
		return array(
			'members'   => $this->member_total(),
			'posts'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'activity_update' AND " . $this->post_where(), $act ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			'comments'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'activity_comment' AND is_spam = 0", $act ) ),
			'reactions' => $this->reaction_total(),
			'messages'  => Import::has_table( self::t( 'bp_messages_messages' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT thread_id) FROM %i', self::t( 'bp_messages_messages' ) ) ) : 0,
			'groups'    => Import::has_table( self::t( 'bp_groups' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::t( 'bp_groups' ) ) ) : 0,
		);
	}

	/** Everyone BuddyPress knew: anyone with a "last active" row or a profile field. */
	private function member_total() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE type = 'last_activity' AND " . self::site_user( 'user_id' ), self::t( 'bp_activity' ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			?: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
	}

	private function reaction_total() {
		global $wpdb;
		if ( Import::has_table( self::t( 'bb_user_reactions' ) ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE item_type IN ('activity', 'activity_comment')", self::t( 'bb_user_reactions' ) ) );
		}
		$total = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", 'bp_favorite_activities' ) ) as $v ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
			$total += count( (array) maybe_unserialize( $v ) );
		}
		return $total;
	}

	public function groups() {
		global $wpdb;
		if ( ! Import::has_table( self::t( 'bp_groups' ) ) ) {
			return array();
		}
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, description, status FROM %i ORDER BY id', self::t( 'bp_groups' ) ), ARRAY_A ) as $g ) {
			$members = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, is_admin FROM %i WHERE group_id = %d AND is_confirmed = 1 AND is_banned = 0', self::t( 'bp_groups_members' ), $g['id'] ), ARRAY_A );
			$out[]   = array(
				'source_id'   => (int) $g['id'],
				'name'        => html_entity_decode( $g['name'], ENT_QUOTES, 'UTF-8' ),
				'description' => wp_trim_words( Import::text( $g['description'] ), 20, '…' ),
				'privacy'     => in_array( $g['status'], array( 'private', 'hidden' ), true ) ? $g['status'] : 'public',
				'member_ids'  => array_map( 'intval', array_column( $members, 'user_id' ) ),
				'admin_ids'   => array_map( 'intval', array_column( array_filter( $members, fn( $m ) => (int) $m['is_admin'] ), 'user_id' ) ),
				'avatar_url'  => self::first_file( 'group-avatars/' . (int) $g['id'], '-bpfull.' ),
				'cover_url'   => self::first_file( 'buddypress/groups/' . (int) $g['id'] . '/cover-image', '' ),
				'posts'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE component = 'groups' AND type = 'activity_update' AND item_id = %d", self::t( 'bp_activity' ), $g['id'] ) ),
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
			case 'threads':
				return $this->threads( (int) $after, $limit );
		}
		return array( array(), null );
	}

	private function members( $after, $limit ) {
		global $wpdb;
		$ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT %d", $after, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
		$rows = array();
		$f    = $this->profile_fields();
		foreach ( $ids as $id ) {
			$row = array(
				'user_id'  => $id,
				'avatar'   => self::first_file( 'avatars/' . $id, '-bpfull.' ),
				'cover'    => self::first_file( 'buddypress/members/' . $id . '/cover-image', '' ),
				'bio'      => $f['bio'] ? (string) $this->field_value( $f['bio'], $id ) : '',
				'location' => $f['location'] ? (string) $this->field_value( $f['location'], $id ) : '',
			);
			if ( $row['avatar'] || $row['cover'] || $row['bio'] || $row['location'] ) {
				$rows[] = $row;
			}
		}
		return array( $rows, count( $ids ) < $limit ? null : end( $ids ) );
	}

	/** The profile fields that hold a bio and a location, found by their names. */
	private function profile_fields() {
		global $wpdb;
		if ( null !== $this->fields ) {
			return $this->fields;
		}
		$this->fields = array( 'bio' => array(), 'location' => array() );
		if ( ! Import::has_table( self::t( 'bp_xprofile_fields' ) ) ) {
			return $this->fields;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM %i WHERE parent_id = 0', self::t( 'bp_xprofile_fields' ) ), ARRAY_A ) as $field ) {
			$n = strtolower( $field['name'] );
			if ( preg_match( '/\b(bio|about|biography|about me|description)\b/', $n ) ) {
				$this->fields['bio'][] = (int) $field['id'];
			} elseif ( preg_match( '/\b(location|city|town|country|where)\b/', $n ) ) {
				$this->fields['location'][] = (int) $field['id'];
			}
		}
		return $this->fields;
	}

	private function field_value( array $field_ids, $user_id ) {
		global $wpdb;
		foreach ( $field_ids as $fid ) {
			$v = $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i WHERE field_id = %d AND user_id = %d', self::t( 'bp_xprofile_data' ), $fid, $user_id ) );
			$v = is_serialized( (string) $v ) ? implode( ', ', array_filter( (array) maybe_unserialize( $v, array( 'allowed_classes' => false ) ), 'is_scalar' ) ) : (string) $v;
			if ( '' !== trim( $v ) ) {
				return $v;
			}
		}
		return '';
	}

	/** A file BuddyPress saved under uploads, found by listing its folder (names are random). */
	private static function first_file( $dir, $contains ) {
		$up   = wp_get_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . $dir;
		if ( ! is_dir( $path ) ) {
			return '';
		}
		foreach ( (array) scandir( $path ) as $file ) {
			if ( preg_match( '/\.(jpe?g|png|gif|webp)$/i', $file ) && ( '' === $contains || false !== strpos( $file, $contains ) ) ) {
				return trailingslashit( $up['baseurl'] ) . $dir . '/' . rawurlencode( $file );
			}
		}
		return '';
	}

	private function posts( $after, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, user_id, component, item_id, content, date_recorded FROM %i WHERE id > %d AND type = 'activity_update' AND " . $this->post_where() . ' ORDER BY id LIMIT %d', self::t( 'bp_activity' ), $after, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'source_id'  => (int) $r['id'],
				'user_id'    => (int) $r['user_id'],
				'content'    => $r['content'],
				'created_at' => $r['date_recorded'],
				'group'      => 'groups' === $r['component'] ? (int) $r['item_id'] : 0,
				'media'      => $this->media( (int) $r['id'] ),
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['id'] );
	}

	/** BuddyBoss photos and videos on a post (the files stay where they are), and a GIF. */
	private function media( $activity_id ) {
		global $wpdb;
		$out = array();
		if ( Import::has_table( self::t( 'bp_media' ) ) ) {
			$ids = array();
			foreach ( array( 'bp_media_ids', 'bp_video_ids' ) as $key ) {
				$v   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE activity_id = %d AND meta_key = %s', self::t( 'bp_activity_meta' ), $activity_id, $key ) );
				$ids = array_merge( $ids, array_filter( array_map( 'intval', explode( ',', $v ) ) ) );
			}
			if ( $ids ) {
				$in = implode( ',', $ids );
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id, type FROM %i WHERE id IN ({$in}) ORDER BY menu_order, id", self::t( 'bp_media' ) ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					$item = self::attachment( (int) $m['attachment_id'], 'video' === $m['type'] ? 'video' : 'image' );
					$item && ( $out[] = $item );
				}
			}
		}
		$gif = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE activity_id = %d AND meta_key = %s', self::t( 'bp_activity_meta' ), $activity_id, '_gif_data' ) ), array( 'allowed_classes' => false ) );
		if ( is_array( $gif ) && ! empty( $gif['still'] ) ) {
			$still = is_numeric( $gif['still'] ) ? wp_get_attachment_url( (int) $gif['still'] ) : $gif['still'];
			$still && ( $out[] = array( 'url' => $still, 'kind' => 'image', 'mime' => 'image/gif' ) );
		}
		return $out;
	}

	/** A WordPress attachment as a media item: its address, type and size. */
	public static function attachment( $id, $kind ) {
		$url = $id ? wp_get_attachment_url( $id ) : '';
		if ( ! $url ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id ) ?: array();
		return array(
			'url'           => $url,
			'kind'          => $kind,
			'attachment_id' => $id,
			'mime'          => get_post_mime_type( $id ) ?: ( 'video' === $kind ? 'video/mp4' : 'image/jpeg' ),
			'width'         => (int) ( $meta['width'] ?? 0 ),
			'height'        => (int) ( $meta['height'] ?? 0 ),
		);
	}

	private function comments( $after, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, user_id, item_id, secondary_item_id, content, date_recorded FROM %i WHERE id > %d AND type = 'activity_comment' AND is_spam = 0 ORDER BY id LIMIT %d", self::t( 'bp_activity' ), $after, $limit ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$media = $this->media( (int) $r['id'] );
			$out[] = array(
				'source_id'  => (int) $r['id'],
				'post'       => (int) $r['item_id'],
				'parent'     => (int) $r['secondary_item_id'] === (int) $r['item_id'] ? 0 : (int) $r['secondary_item_id'],
				'user_id'    => (int) $r['user_id'],
				'content'    => $r['content'],
				'image'      => $media[0]['url'] ?? '',
				'created_at' => $r['date_recorded'],
			);
		}
		return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['id'] );
	}

	/** BuddyBoss reactions by name, or BuddyPress favorites as likes. */
	private function reactions( $after, $limit ) {
		global $wpdb;
		$act = self::t( 'bp_activity' );
		if ( Import::has_table( self::t( 'bb_user_reactions' ) ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.id, r.user_id, r.item_type, r.item_id, r.date_created, p.post_title FROM %i r LEFT JOIN {$wpdb->posts} p ON p.ID = r.reaction_id WHERE r.id > %d AND r.item_type IN ('activity', 'activity_comment') ORDER BY r.id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
					self::t( 'bb_user_reactions' ),
					$after,
					$limit
				),
				ARRAY_A
			);
			$out = array();
			foreach ( $rows as $r ) {
				$out[] = array(
					'object'     => 'activity_comment' === $r['item_type'] ? 'comment' : 'post',
					'object_id'  => (int) $r['item_id'],
					'user_id'    => (int) $r['user_id'],
					'type'       => self::reaction_name( (string) $r['post_title'] ),
					'created_at' => $r['date_created'],
				);
			}
			return array( $out, count( $rows ) < $limit ? null : (int) end( $rows )['id'] );
		}
		// BuddyPress: each member's favorites are one list in their user meta.
		$metas = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND umeta_id > %d ORDER BY umeta_id LIMIT %d", 'bp_favorite_activities', $after, max( 1, (int) ( $limit / 20 ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table
		$out   = array();
		foreach ( $metas as $m ) {
			foreach ( array_map( 'intval', (array) maybe_unserialize( $m['meta_value'], array( 'allowed_classes' => false ) ) ) as $aid ) {
				$a = $wpdb->get_row( $wpdb->prepare( 'SELECT type, date_recorded FROM %i WHERE id = %d', $act, $aid ), ARRAY_A );
				if ( $a ) {
					$out[] = array( 'object' => 'activity_comment' === $a['type'] ? 'comment' : 'post', 'object_id' => $aid, 'user_id' => (int) $m['user_id'], 'type' => 'like', 'created_at' => $a['date_recorded'] );
				}
			}
		}
		return array( $out, count( $metas ) < max( 1, (int) ( $limit / 20 ) ) ? null : (int) end( $metas )['umeta_id'] );
	}

	/** "Likes", "Love", "Haha"… to ours; anything else becomes Like. */
	public static function reaction_name( $title ) {
		$t   = strtolower( trim( $title ) );
		$map = array( 'likes' => 'like', 'like' => 'like', 'love' => 'love', 'heart' => 'love', 'care' => 'care', 'haha' => 'haha', 'laugh' => 'haha', 'wow' => 'wow', 'sad' => 'sad', 'angry' => 'angry' );
		return $map[ $t ] ?? 'like';
	}

	private function threads( $after, $limit ) {
		global $wpdb;
		$msgs = self::t( 'bp_messages_messages' );
		if ( ! Import::has_table( $msgs ) ) {
			return array( array(), null );
		}
		$ids     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT thread_id FROM %i WHERE thread_id > %d ORDER BY thread_id LIMIT %d', $msgs, $after, $limit ) ) );
		$deleted = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $msgs, 'is_deleted' ) );
		$out     = array();
		foreach ( $ids as $tid ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT sender_id, subject, message, date_sent' . ( $deleted ? ', is_deleted' : '' ) . ' FROM %i WHERE thread_id = %d ORDER BY date_sent, id', $msgs, $tid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
			$rows  = array_values( array_filter( $rows, fn( $r ) => empty( $r['is_deleted'] ) ) );
			$users = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i WHERE thread_id = %d', self::t( 'bp_messages_recipients' ), $tid ) ) );
			if ( ! $rows ) {
				continue;
			}
			$out[] = array(
				'source_id'  => $tid,
				'user_ids'   => array_values( array_unique( array_merge( $users, array_map( 'intval', array_column( $rows, 'sender_id' ) ) ) ) ),
				'title'      => html_entity_decode( (string) $rows[0]['subject'], ENT_QUOTES, 'UTF-8' ),
				'created_by' => (int) $rows[0]['sender_id'],
				'messages'   => array_map( fn( $r ) => array( 'user_id' => (int) $r['sender_id'], 'content' => $r['message'], 'created_at' => $r['date_sent'] ), $rows ),
			);
		}
		return array( $out, count( $ids ) < $limit ? null : end( $ids ) );
	}

	/** Numbers for the dry run. */
	public function facts( array $job ) {
		global $wpdb;
		$act   = self::t( 'bp_activity' );
		$facts = $this->summary();
		$facts['inactive'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE type = 'last_activity' AND date_recorded < %s", $act, gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) ) ) );
		$facts['empty']    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i a WHERE a.type = 'activity_update' AND TRIM(a.content) = '' AND " . $this->post_where( 'a' ) . " AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.activity_id = a.id AND m.meta_key IN ('bp_media_ids', 'bp_video_ids', '_gif_data'))", $act, self::t( 'bp_activity_meta' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL
		$facts['private']  = $this->has_column( 'privacy' ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'activity_update' AND privacy = 'onlyme'", $act ) ) : 0;
		$facts['friends']  = $this->has_column( 'privacy' ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'activity_update' AND privacy = 'friends' AND is_spam = 0", $act ) ) : 0;
		$facts['media']    = Import::has_table( self::t( 'bp_media' ) ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE activity_id > 0', self::t( 'bp_media' ) ) ) : 0;
		return $facts;
	}
}
