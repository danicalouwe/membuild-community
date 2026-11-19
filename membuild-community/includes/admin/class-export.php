<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * CSV downloads of the community's data for admins (Community → Export).
 * Rows are streamed in batches, so large communities don't run out of memory.
 * Private message text is never included.
 */
class Export {

	const BATCH = 500;

	public static function send( $key ) {
		$name = sanitize_file_name( Settings::slug() . '-' . $key . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download, not writing a file
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 marker so Excel shows accents and emoji correctly
		self::write( $key, $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Writes one dataset (a key of Admin::datasets()) as CSV rows to an open stream. */
	public static function write( $key, $out ) {
		if ( isset( Admin::datasets()[ $key ] ) ) {
			call_user_func( array( __CLASS__, $key ), $out );
		}
	}

	/** One row, with cells that could run as spreadsheet formulas neutralised. */
	private static function row( $out, array $cells ) {
		$safe = array_map(
			function ( $v ) {
				$v = (string) $v;
				return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
			},
			$cells
		);
		fputcsv( $out, $safe, ',', '"', '\\' );
	}

	/** Walks a query in batches of BATCH rows, calling $each for every row. */
	private static function walk( $sql, array $args, callable $each ) {
		global $wpdb;
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', array_merge( $args, array( self::BATCH, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL from this class, values bound here
			foreach ( $rows as $row ) {
				$each( $row );
			}
			$offset += self::BATCH;
		} while ( count( $rows ) === self::BATCH );
	}

	private static function iso( $mysql ) {
		return $mysql ? gmdate( 'c', strtotime( $mysql . ' UTC' ) ) : '';
	}

	private static function members( $out ) {
		global $wpdb;
		self::row( $out, array( 'ID', 'Name', 'Email', 'Joined', 'Last active', 'Level', 'Points', 'Streak', 'Best streak', 'Posts', 'Comments', 'Messages sent', 'Chats', 'Status' ) );
		self::walk(
			"SELECT u.ID, u.display_name, u.user_email, u.user_registered, s.last_active, COALESCE(s.level, 1) level, COALESCE(s.points, 0) points, COALESCE(s.streak, 0) streak, COALESCE(s.best_streak, 0) best_streak,
				(SELECT COUNT(*) FROM %i p WHERE p.user_id = u.ID AND p.status = 'published') posts,
				(SELECT COUNT(*) FROM %i c WHERE c.user_id = u.ID) comments,
				(SELECT COUNT(*) FROM %i m WHERE m.user_id = u.ID AND m.status = 'sent') sent,
				(SELECT COUNT(*) FROM %i t WHERE t.user_id = u.ID AND t.left_at IS NULL) chats
			FROM %i u LEFT JOIN %i s ON s.user_id = u.ID WHERE 1=1" . Helpers::site_members_sql() . ' ORDER BY u.ID',
			array( Install::table( 'posts' ), Install::table( 'comments' ), Install::table( 'messages' ), Install::table( 'thread_members' ), $wpdb->users, Install::table( 'member_stats' ) ),
			function ( $r ) use ( $out ) {
				$restriction = Moderation::restriction( (int) $r['ID'] );
				$status      = user_can( (int) $r['ID'], 'manage_options' ) ? 'admin' : ( Settings::is_staff( (int) $r['ID'] ) ? 'moderator' : ( $restriction['status'] ?? 'member' ) );
				self::row( $out, array( $r['ID'], $r['display_name'], $r['user_email'], self::iso( $r['user_registered'] ), $r['last_active'], $r['level'], $r['points'], $r['streak'], $r['best_streak'], $r['posts'], $r['comments'], $r['sent'], $r['chats'], $status ) );
			}
		);
	}

	private static function posts( $out ) {
		global $wpdb;
		self::row( $out, array( 'ID', 'Type', 'Status', 'Author ID', 'Author', 'Text', 'Reactions', 'Comments', 'Shares', 'Pinned', 'Posted', 'Link' ) );
		self::walk(
			'SELECT p.*, u.display_name FROM %i p LEFT JOIN %i u ON u.ID = p.user_id ORDER BY p.id',
			array( Install::table( 'posts' ), $wpdb->users ),
			fn( $r ) => self::row( $out, array( $r['id'], $r['type'], $r['status'], $r['user_id'], $r['display_name'], $r['content'], $r['reactions_count'], $r['comments_count'], $r['shares_count'] ?? 0, $r['is_pinned'] ? 'yes' : '', self::iso( $r['created_at'] ), 'published' === $r['status'] ? Admin::app_url( '?post=' . $r['id'] ) : '' ) )
		);
	}

	private static function comments( $out ) {
		global $wpdb;
		self::row( $out, array( 'ID', 'Post ID', 'Reply to comment', 'Author ID', 'Author', 'Text', 'Photo', 'Reactions', 'Written' ) );
		self::walk(
			'SELECT c.*, u.display_name FROM %i c LEFT JOIN %i u ON u.ID = c.user_id ORDER BY c.id',
			array( Install::table( 'comments' ), $wpdb->users ),
			fn( $r ) => self::row( $out, array( $r['id'], $r['post_id'], $r['parent_id'] ?: '', $r['user_id'], $r['display_name'], $r['content'], $r['image_url'] ?? '', $r['reactions_count'], self::iso( $r['created_at'] ) ) )
		);
	}

	private static function progress( $out ) {
		global $wpdb;
		self::row( $out, array( 'Member ID', 'Member', 'Course', 'Lesson', 'Finished' ) );
		self::walk(
			'SELECT p.user_id, u.display_name, c.title course, l.title lesson, p.completed_at FROM %i p LEFT JOIN %i u ON u.ID = p.user_id LEFT JOIN %i c ON c.id = p.course_id LEFT JOIN %i l ON l.id = p.lesson_id ORDER BY p.id',
			array( Install::table( 'lesson_progress' ), $wpdb->users, Install::table( 'courses' ), Install::table( 'lessons' ) ),
			fn( $r ) => self::row( $out, array( $r['user_id'], $r['display_name'], $r['course'], $r['lesson'], self::iso( $r['completed_at'] ) ) )
		);
	}

	private static function quizzes( $out ) {
		global $wpdb;
		self::row( $out, array( 'Member ID', 'Member', 'Course', 'Lesson', 'Score', 'Out of', 'Percent', 'Passed', 'Finished' ) );
		self::walk(
			'SELECT t.user_id, u.display_name, c.title course, l.title lesson, t.score, t.total, t.passed, t.finished_at FROM %i t JOIN %i q ON q.id = t.quiz_id LEFT JOIN %i u ON u.ID = t.user_id LEFT JOIN %i c ON c.id = q.course_id LEFT JOIN %i l ON l.id = q.lesson_id WHERE t.preview = 0 AND t.finished_at IS NOT NULL ORDER BY t.id',
			array( Install::table( 'quiz_attempts' ), Install::table( 'quizzes' ), $wpdb->users, Install::table( 'courses' ), Install::table( 'lessons' ) ),
			fn( $r ) => self::row( $out, array( $r['user_id'], $r['display_name'], $r['course'], $r['lesson'], $r['score'], $r['total'], $r['total'] ? round( 100 * $r['score'] / $r['total'] ) : '', $r['passed'] ? 'yes' : 'no', self::iso( $r['finished_at'] ) ) )
		);
	}

	private static function certificates( $out ) {
		global $wpdb;
		self::row( $out, array( 'Member ID', 'Member', 'Course', 'Code', 'Lessons', 'Finished', 'Status' ) );
		self::walk(
			'SELECT k.*, u.display_name FROM %i k LEFT JOIN %i u ON u.ID = k.user_id ORDER BY k.id',
			array( Install::table( 'certificates' ), $wpdb->users ),
			fn( $r ) => self::row( $out, array( $r['user_id'], $r['display_name'], $r['course_title'], $r['code'], $r['lessons'], self::iso( $r['finished_at'] ), $r['status'] ) )
		);
	}

	private static function events( $out ) {
		global $wpdb;
		self::row( $out, array( 'Event', 'Starts', 'Member ID', 'Member', 'Email', 'Answer', 'When' ) );
		self::walk(
			"SELECT p.event_title, p.event_at, r.user_id, r.type, r.created_at, u.display_name, u.user_email FROM %i r JOIN %i p ON p.id = r.object_id LEFT JOIN %i u ON u.ID = r.user_id WHERE r.object_type = 'event' ORDER BY p.event_at, r.id",
			array( Install::table( 'reactions' ), Install::table( 'posts' ), $wpdb->users ),
			fn( $r ) => self::row( $out, array( $r['event_title'], self::iso( $r['event_at'] ), $r['user_id'], $r['display_name'], $r['user_email'], 'going' === $r['type'] ? 'Going' : 'Interested', self::iso( $r['created_at'] ) ) )
		);
	}

	/** How many rows each download has, for the Export page. */
	public static function counts() {
		global $wpdb;
		$n = fn( $sql, ...$args ) => (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL below
		return array(
			'members'      => $n( 'SELECT COUNT(*) FROM %i u WHERE 1=1' . Helpers::site_members_sql(), $wpdb->users ),
			'posts'        => $n( 'SELECT COUNT(*) FROM %i', Install::table( 'posts' ) ),
			'comments'     => $n( 'SELECT COUNT(*) FROM %i', Install::table( 'comments' ) ),
			'progress'     => $n( 'SELECT COUNT(*) FROM %i', Install::table( 'lesson_progress' ) ),
			'quizzes'      => $n( 'SELECT COUNT(*) FROM %i WHERE preview = 0 AND finished_at IS NOT NULL', Install::table( 'quiz_attempts' ) ),
			'certificates' => $n( 'SELECT COUNT(*) FROM %i', Install::table( 'certificates' ) ),
			'events'       => $n( "SELECT COUNT(*) FROM %i WHERE object_type = 'event'", Install::table( 'reactions' ) ),
			'points'       => $n( 'SELECT COUNT(*) FROM %i', Install::table( 'points' ) ),
			'files'        => $n( "SELECT COUNT(*) FROM %i WHERE kind <> 'poster' AND ( scope IS NULL OR scope <> 'message' )", Install::table( 'media' ) ),
		);
	}

	private static function points( $out ) {
		global $wpdb;
		$labels = Rest_Gamification::reason_labels();
		self::row( $out, array( 'Member ID', 'Member', 'Points', 'Reason', 'When' ) );
		self::walk(
			'SELECT p.*, u.display_name FROM %i p LEFT JOIN %i u ON u.ID = p.user_id ORDER BY p.id',
			array( Install::table( 'points' ), $wpdb->users ),
			fn( $r ) => self::row( $out, array( $r['user_id'], $r['display_name'], $r['points'], $labels[ $r['reason'] ] ?? $r['reason'], self::iso( $r['created_at'] ) ) )
		);
	}

	private static function files( $out ) {
		global $wpdb;
		self::row( $out, array( 'ID', 'Type', 'Uploaded by', 'Size (bytes)', 'Used in post', 'Address', 'Uploaded' ) );
		self::walk(
			"SELECT m.*, u.display_name FROM %i m LEFT JOIN %i u ON u.ID = m.user_id WHERE m.kind <> 'poster' AND ( m.scope IS NULL OR m.scope <> 'message' ) ORDER BY m.id",
			array( Install::table( 'media' ), $wpdb->users ),
			function ( $r ) use ( $out ) {
				$file = $r['attachment_id'] ? get_attached_file( (int) $r['attachment_id'] ) : '';
				self::row( $out, array( $r['id'], $r['kind'], $r['display_name'], $file && file_exists( $file ) ? filesize( $file ) : '', (int) $r['post_id'] > 0 ? $r['post_id'] : '', $r['url'], self::iso( $r['created_at'] ) ) );
			}
		);
	}
}
