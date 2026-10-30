<?php
/**
 * The shape every import source (BuddyBoss, PeepSo, a spreadsheet…) reads into.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * One place an import reads from. Rows come back in the shapes Import writes:
 *   members:   user_id, avatar, cover, bio, location
 *   posts:     source_id, user_id, content (HTML is fine), created_at (UTC), group (source group id), media[{url, kind, attachment_id, mime, width, height}]
 *   comments:  source_id, post (source post id), parent (source comment id or 0), user_id, content, image, created_at
 *   reactions: object ('post'|'comment'), object_id (source id), user_id, type, created_at
 *   threads:   source_id, user_ids, title, created_by, messages[{user_id, content, created_at}]
 * batch() returns [rows, the cursor to continue after, or null when that step is done].
 */
abstract class Import_Source {

	protected $key;

	public function __construct( $key = '' ) {
		$this->key = $key;
	}

	abstract public function label();

	abstract public function detect();

	public function active() {
		return false;
	}

	public function last_activity() {
		return null;
	}

	/** Counts for the first screen: members, posts, comments, messages, groups. */
	abstract public function summary();

	/** Groups or spaces, each: source_id, name, description, privacy, member_ids, admin_ids, posts. */
	public function groups() {
		return array();
	}

	abstract public function batch( $step, $after, $limit, array $job );

	/** Totals for the progress bars. */
	public function totals( array $job ) {
		return $this->summary();
	}

	/** Numbers for the dry run: summary() plus inactive, empty, private, friends, media. */
	public function facts( array $job ) {
		return $this->summary() + array( 'inactive' => 0, 'empty' => 0, 'private' => 0, 'friends' => 0, 'media' => 0 );
	}

	/** The dry run: what will happen, and anything worth knowing. */
	public function check( array $job ) {
		return Import_Check::wordpress( $this, $job );
	}

	/** SQL fragment for "users on this site" (multisite keeps each site's own). */
	protected static function site_user( $column ) {
		global $wpdb;
		return $column . ' IN (SELECT ID FROM ' . $wpdb->users . ')';
	}
}
