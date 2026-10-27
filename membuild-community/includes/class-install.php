<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables.
 *
 * Feed content lives in its own tables (not posts/postmeta) so the feed
 * stays one indexed query with denormalized counters.
 *
 * Schema changes go in create_tables() (dbDelta adds new columns and keys).
 * Data changes go in a numbered upgrade step that runs once, when a site
 * moves past that version; the version is saved only after every step ran.
 */
class Install {

	/** Every table the plugin owns, without the "{prefix}mbcom_" part. */
	const TABLES = array( 'posts', 'media', 'comments', 'reactions', 'stories', 'story_views', 'courses', 'groups', 'group_members', 'modules', 'lessons', 'lesson_progress', 'course_starts', 'points', 'member_stats', 'threads', 'thread_members', 'messages', 'notifications', 'reports', 'blocks', 'places' );

	/** Data migrations: the version each one brings a site to => method. */
	const STEPS = array(
		15 => 'mark_private_uploads',
		16 => 'migrate_course_levels',
		17 => 'schedule_private_files',
		19 => 'schedule_message_encryption',
		20 => 'schedule_search_index',
		27 => 'backfill_course_starts',
	);

	/** Hourly maintenance (the name is kept from 0.1 so existing schedules carry on). */
	const CRON_HOOK = 'mbcom_cleanup_stories';

	public static function activate() {
		self::create_tables();
		if ( ! get_option( 'mbcom_db_version' ) ) {
			update_option( 'mbcom_db_version', MEMBUILD_COMMUNITY_DB_VERSION ); // a fresh install needs no migrations
		}
		Portal::add_rewrite_rule();
		flush_rewrite_rules();
		Search::schedule_index(); // new or reactivated: the index is added once, in the background
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'mbcom_send_emails' );
		wp_clear_scheduled_hook( Files::MIGRATE_HOOK );
		wp_clear_scheduled_hook( Vault::MIGRATE_HOOK );
		wp_clear_scheduled_hook( Usage::HOOK );
		wp_clear_scheduled_hook( Usage::HOOK . '_now' );
		wp_clear_scheduled_hook( Search::INDEX_HOOK );
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		$from = (int) get_option( 'mbcom_db_version', 0 );
		if ( $from >= (int) MEMBUILD_COMMUNITY_DB_VERSION ) {
			return;
		}
		self::create_tables();
		foreach ( self::STEPS as $version => $method ) {
			if ( $from > 0 && $from < $version ) {
				self::$method();
			}
		}
		update_option( 'mbcom_db_version', MEMBUILD_COMMUNITY_DB_VERSION );
		// Updates can change the community's address rules too; refresh them once, after init.
		add_action( 'init', fn() => flush_rewrite_rules( false ), 99 );
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'mbcom_' . $name;
	}

	/* ---------- Upgrade steps ---------- */

	/** 15: uploads from earlier versions get the private marker (see Media::register_hooks). */
	private static function mark_private_uploads() {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM %i WHERE attachment_id IS NOT NULL', self::table( 'media' ) ) ) as $att ) {
			update_post_meta( (int) $att, Media::PRIVATE_META, 1 );
		}
	}

	/** 16: courses that only had a level setting become "members from a level up". */
	private static function migrate_course_levels() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET access = 'level' WHERE unlock_level > 1 AND access = 'everyone'", self::table( 'courses' ) ) );
	}

	/**
	 * 27: lessons now open counted from the day a member starts the course, not the
	 * day they joined. Members already part-way through keep their old dates (their
	 * start is the day they joined), so nothing that was open for them closes.
	 */
	private static function backfill_course_starts() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (user_id, course_id, started_at)
				SELECT p.user_id, p.course_id, LEAST(MIN(p.completed_at), COALESCE(u.user_registered, MIN(p.completed_at)))
				FROM %i p LEFT JOIN %i u ON u.ID = p.user_id GROUP BY p.user_id, p.course_id',
				self::table( 'course_starts' ),
				self::table( 'lesson_progress' ),
				$wpdb->users
			)
		);
	}

	/** 19: messages saved before encryption existed are encrypted, in batches. */
	private static function schedule_message_encryption() {
		if ( ! wp_next_scheduled( Vault::MIGRATE_HOOK ) ) {
			wp_schedule_single_event( time(), Vault::MIGRATE_HOOK );
		}
	}

	/** 20: a FULLTEXT index for search, built in the background (see Search::add_index). */
	private static function schedule_search_index() {
		Search::schedule_index();
	}

	/** 17: existing message photos and lesson files move to the protected folder, in batches. */
	private static function schedule_private_files() {
		Files::schedule_migration();
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$posts    = self::table( 'posts' );
		$media    = self::table( 'media' );
		$comments = self::table( 'comments' );
		$reacts   = self::table( 'reactions' );
		$stories  = self::table( 'stories' );
		$views    = self::table( 'story_views' );
		$courses  = self::table( 'courses' );
		$modules  = self::table( 'modules' );
		$lessons  = self::table( 'lessons' );
		$progress = self::table( 'lesson_progress' );
		$starts   = self::table( 'course_starts' );
		$groups   = self::table( 'groups' );
		$grouped  = self::table( 'group_members' );
		$points   = self::table( 'points' );
		$stats    = self::table( 'member_stats' );
		$threads  = self::table( 'threads' );
		$members  = self::table( 'thread_members' );
		$messages = self::table( 'messages' );
		$notes    = self::table( 'notifications' );
		$reports  = self::table( 'reports' );
		$blocks   = self::table( 'blocks' );
		$places   = self::table( 'places' );

		// dbDelta needs two spaces before PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$posts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'post',
			status varchar(20) NOT NULL DEFAULT 'published',
			content text NULL,
			background varchar(20) NULL,
			feeling varchar(20) NULL,
			reactions_count int(10) unsigned NOT NULL DEFAULT 0,
			comments_count int(10) unsigned NOT NULL DEFAULT 0,
			shares_count int(10) unsigned NOT NULL DEFAULT 0,
			is_pinned tinyint(1) NOT NULL DEFAULT 0,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
			helpful_count int(10) unsigned NOT NULL DEFAULT 0,
			best_comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_title varchar(200) NULL,
			event_at datetime NULL,
			event_minutes smallint(5) unsigned NOT NULL DEFAULT 60,
			event_url varchar(500) NULL,
			event_recording varchar(500) NULL,
			event_status varchar(12) NOT NULL DEFAULT '',
			event_group bigint(20) unsigned NOT NULL DEFAULT 0,
			event_reminded tinyint(1) unsigned NOT NULL DEFAULT 0,
			edited_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_id (status, id),
			KEY type_status_id (type, status, id),
			KEY status_created (status, created_at),
			KEY pinned (is_pinned, status, id),
			KEY user_id (user_id),
			KEY user_status_created (user_id, status, created_at),
			KEY course_status_id (course_id, status, id),
			KEY lesson_status_id (lesson_id, status, id),
			KEY event_at (event_at, status)
			) {$charset};

			CREATE TABLE {$media} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NULL,
			kind varchar(10) NOT NULL,
			attachment_id bigint(20) unsigned NULL,
			url varchar(500) NOT NULL,
			poster_url varchar(500) NULL,
			mime varchar(50) NOT NULL,
			width int(10) unsigned NOT NULL DEFAULT 0,
			height int(10) unsigned NOT NULL DEFAULT 0,
			duration float NOT NULL DEFAULT 0,
			meta text NULL,
			scope varchar(10) NULL,
			scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			sort_order tinyint(3) unsigned NOT NULL DEFAULT 0,
			refreshed_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY user_id (user_id),
			KEY scope (scope, scope_id),
			KEY kind_refreshed (kind, refreshed_at),
			KEY url (url(191))
			) {$charset};

			CREATE TABLE {$comments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reply_to_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			content text NOT NULL,
			image_url varchar(500) NULL,
			image_w int(10) unsigned NOT NULL DEFAULT 0,
			image_h int(10) unsigned NOT NULL DEFAULT 0,
			image_mp4 varchar(500) NULL,
			reactions_count int(10) unsigned NOT NULL DEFAULT 0,
			replies_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id, id),
			KEY post_parent (post_id, parent_id, id),
			KEY parent_id (parent_id, id),
			KEY user_id (user_id)
			) {$charset};

			CREATE TABLE {$reacts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(10) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(32) NOT NULL DEFAULT 'like',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object_user (object_type, object_id, user_id),
			KEY user_id (user_id)
			) {$charset};

			CREATE TABLE {$stories} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			kind varchar(10) NOT NULL,
			media_url varchar(500) NULL,
			poster_url varchar(500) NULL,
			width int(10) unsigned NOT NULL DEFAULT 0,
			height int(10) unsigned NOT NULL DEFAULT 0,
			duration float NOT NULL DEFAULT 0,
			text varchar(500) NULL,
			background varchar(20) NULL,
			views_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY expires_at (expires_at),
			KEY user_created (user_id, created_at)
			) {$charset};

			CREATE TABLE {$views} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			story_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			reaction varchar(10) NULL,
			viewed_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY story_user (story_id, user_id),
			KEY user_id (user_id)
			) {$charset};

			CREATE TABLE {$courses} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(200) NOT NULL,
			description text NULL,
			cover_url varchar(500) NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			unlock_level tinyint(3) unsigned NOT NULL DEFAULT 0,
			access varchar(20) NOT NULL DEFAULT 'everyone',
			access_groups text NULL,
			hide_locked tinyint(1) NOT NULL DEFAULT 0,
			access_cta_label varchar(60) NULL,
			access_cta_url varchar(500) NULL,
			about mediumtext NULL,
			questions_off tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(10) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_order (status, sort_order)
			) {$charset};

			CREATE TABLE {$groups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(80) NOT NULL,
			roles text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
			) {$charset};

			CREATE TABLE {$grouped} (
			group_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			added_by bigint(20) unsigned NOT NULL DEFAULT 0,
			added_at datetime NOT NULL,
			PRIMARY KEY  (group_id, user_id),
			KEY user_id (user_id)
			) {$charset};

			CREATE TABLE {$modules} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			title varchar(200) NOT NULL,
			sort_order int(10) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY course_order (course_id, sort_order)
			) {$charset};

			CREATE TABLE {$lessons} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			module_id bigint(20) unsigned NOT NULL,
			title varchar(200) NOT NULL,
			content mediumtext NULL,
			media_id bigint(20) unsigned NULL,
			resources text NULL,
			drip_days int(10) unsigned NOT NULL DEFAULT 0,
			open_on date NULL,
			sort_order int(10) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY module_order (module_id, sort_order),
			KEY course_id (course_id)
			) {$charset};

			CREATE TABLE {$progress} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			completed_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_lesson (user_id, lesson_id),
			KEY user_course (user_id, course_id),
			KEY course_user (course_id, user_id),
			KEY course_done (course_id, completed_at)
			) {$charset};

			CREATE TABLE {$starts} (
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			started_at datetime NOT NULL,
			PRIMARY KEY  (user_id, course_id),
			KEY course_id (course_id)
			) {$charset};

			CREATE TABLE {$points} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			points int(11) NOT NULL,
			reason varchar(20) NOT NULL,
			object_type varchar(10) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY once (user_id, reason, object_type, object_id, actor_id),
			KEY user_created (user_id, created_at),
			KEY user_reason_created (user_id, reason, created_at),
			KEY created_at (created_at),
			KEY created_user (created_at, user_id, points)
			) {$charset};

			CREATE TABLE {$stats} (
			user_id bigint(20) unsigned NOT NULL,
			points int(11) NOT NULL DEFAULT 0,
			level tinyint(3) unsigned NOT NULL DEFAULT 1,
			streak smallint(5) unsigned NOT NULL DEFAULT 0,
			best_streak smallint(5) unsigned NOT NULL DEFAULT 0,
			last_active date NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (user_id),
			KEY points (points)
			) {$charset};

			CREATE TABLE {$threads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(10) NOT NULL DEFAULT 'direct',
			direct_key varchar(45) NULL,
			title varchar(100) NULL,
			created_by bigint(20) unsigned NOT NULL,
			last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_message_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY direct_key (direct_key),
			KEY last_message_at (last_message_at)
			) {$charset};

			CREATE TABLE {$members} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
			joined_at datetime NOT NULL,
			left_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY thread_user (thread_id, user_id),
			KEY user_id (user_id)
			) {$charset};

			CREATE TABLE {$messages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			thread_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			content text NULL,
			media text NULL,
			story text NULL,
			reply_to_id bigint(20) unsigned NULL,
			status varchar(10) NOT NULL DEFAULT 'sent',
			reactions_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY thread_id (thread_id, id),
			KEY user_created (user_id, created_at)
			) {$charset};

			CREATE TABLE {$notes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(30) NOT NULL,
			object_type varchar(10) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_ids varchar(255) NULL,
			actors_count int(10) unsigned NOT NULL DEFAULT 0,
			data text NULL,
			seen_at datetime NULL,
			read_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_updated (user_id, updated_at),
			KEY user_seen (user_id, seen_at),
			KEY updated_at (updated_at),
			KEY group_key (user_id, type, object_type, object_id)
			) {$charset};

			CREATE TABLE {$reports} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(10) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			author_id bigint(20) unsigned NOT NULL,
			reporter_id bigint(20) unsigned NOT NULL,
			reason varchar(20) NOT NULL,
			note varchar(500) NULL,
			status varchar(10) NOT NULL DEFAULT 'open',
			resolved_by bigint(20) unsigned NULL,
			resolved_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY one_per_reporter (object_type, object_id, reporter_id),
			KEY status_created (status, created_at)
			) {$charset};

			CREATE TABLE {$blocks} (
			user_id bigint(20) unsigned NOT NULL,
			blocked_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (user_id, blocked_id),
			KEY blocked_id (blocked_id)
			) {$charset};

			CREATE TABLE {$places} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(120) NOT NULL,
			category varchar(20) NOT NULL DEFAULT 'meetup',
			perk varchar(120) NOT NULL DEFAULT '',
			description text NULL,
			address varchar(255) NOT NULL DEFAULT '',
			lat decimal(9,6) NOT NULL,
			lng decimal(9,6) NOT NULL,
			website varchar(300) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			hours varchar(200) NOT NULL DEFAULT '',
			cover_url varchar(500) NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_name (status, name),
			KEY created_by (created_by)
			) {$charset};"
		);
	}
}
