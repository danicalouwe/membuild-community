<?php
/**
 * Import: brings a community over from BuddyBoss, BuddyPress, PeepSo or FluentCommunity
 * (read straight from their tables, so it works with the old plugin switched off), or
 * members from a spreadsheet (Kajabi, Mighty Networks, Circle, Skool, any CSV).
 *
 * Choose → Check (a dry run: counts and anything worth knowing) → Import (in the
 * background, a batch at a time, with Pause) → Done (Undo, Email everyone).
 *
 * Every row it makes is written down in the import_map table (source, kind, source id →
 * our id), so importing again only brings what's new, and Undo removes exactly what an
 * import brought. Profiles that already had a photo, bio or location keep them.
 *
 * Groups and spaces become topics; a private or hidden one becomes a members-only topic
 * with a membership for the same people. An add-on can take the groups instead
 * (MemBuild Community Pro: spaces) through the membuild_community/import_groups filter.
 *
 *   GET  /import                    what's on this site, and the import in progress or last done
 *   POST /import/check              {source, bring[]} the dry run
 *   POST /import/start              {source, bring[]} or, for a spreadsheet, {source: 'csv', file, map, plans, welcome}
 *   POST /import/pause | /resume    the import in progress
 *   POST /import/undo               the last import
 *   POST /import/dismiss            back to the first screen after Done
 *   POST /import/email              email everyone the last import brought (slowly)
 *   POST /import/file               a spreadsheet (.csv or .xlsx): its columns, first row and our guesses
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

class Import {

	const OPTION   = 'mbcom_import_job';
	const HISTORY  = 'mbcom_imports'; // every finished import, newest last: id, source, label, name, finished, counts, status, undone_at
	const HOOK     = 'mbcom_import_tick';
	const MAIL     = 'mbcom_import_mail';
	const LOCK     = 'mbcom_import_lock';
	const SECONDS  = 20;   // one batch run, well inside most hosts' limits
	const BATCH    = 200;  // rows per read
	const SOURCES  = array(
		'buddyboss'       => Import_Buddypress::class,
		'buddypress'      => Import_Buddypress::class,
		'peepso'          => Import_Peepso::class,
		'fluentcommunity' => Import_Fluent::class,
	);
	const STEPS    = array( 'members', 'topics', 'posts', 'comments', 'reactions', 'courses', 'messages', 'points', 'finish' );
	const BRINGS   = array( 'profiles', 'posts', 'reactions', 'courses', 'messages', 'points' );
	const MAIL_PER = 8;    // welcome or "we've moved" emails per run, every few minutes: about 200 an hour

	public static function register_hooks() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( self::MAIL, array( __CLASS__, 'mail_tick' ) );
	}

	public static function register_routes() {
		$admin = array( Helpers::class, 'can_manage' );
		$ns    = 'membuild-community/v1';
		register_rest_route( $ns, '/import', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_state' ), 'permission_callback' => $admin ) );
		register_rest_route( $ns, '/import/impact', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_impact' ), 'permission_callback' => $admin ) );
		foreach ( array( 'check', 'start', 'pause', 'resume', 'undo', 'dismiss', 'email', 'file' ) as $action ) {
			register_rest_route( $ns, '/import/' . $action, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_' . $action ), 'permission_callback' => $admin ) );
		}
	}

	/* ---------- Sources ---------- */

	/** @return Import_Source|null */
	public static function source( $key ) {
		if ( 'csv' === $key ) {
			return new Import_Csv();
		}
		$class = self::SOURCES[ $key ] ?? null;
		return $class ? new $class( $key ) : null;
	}

	/** The community plugins whose data is on this site, switched on or off. */
	public static function found() {
		$out = array();
		foreach ( array_keys( self::SOURCES ) as $key ) {
			$src = self::source( $key );
			if ( $src->detect() ) {
				$out[] = array( 'key' => $key, 'label' => $src->label(), 'active' => $src->active(), 'last' => $src->last_activity() ? Helpers::iso( $src->last_activity() ) : null ) + $src->summary();
			}
		}
		return $out;
	}

	/* ---------- REST ---------- */

	public static function rest_state() {
		return array(
			'found'  => self::found(),
			'job'    => self::public_job( self::maybe_nudge() ),
			'vault'  => Vault::ready(),
			'pro'    => (bool) self::groups_handler( 'buddyboss', array() ),
			'admin'  => wp_get_current_user()->user_email,
			'plugins' => admin_url( 'plugins.php' ),
			'past'    => self::past(),
		);
	}

	public static function rest_check( \WP_REST_Request $req ) {
		$job = self::new_job( $req );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$started = microtime( true );
		$report  = self::source( $job['source'] )->check( $job );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		return $report + array( 'seconds' => (int) ceil( microtime( true ) - $started ) );
	}

	public static function rest_start( \WP_REST_Request $req ) {
		$job = self::job();
		if ( $job && in_array( $job['status'], array( 'running', 'paused' ), true ) ) {
			return new \WP_Error( 'mbcom_busy', __( 'An import is already running. Pause or finish it first.', 'membuild-community' ), array( 'status' => 409 ) );
		}
		$job = self::new_job( $req );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$job['id']      = (int) get_option( 'mbcom_import_seq', 0 ) + 1;
		$job['status']  = 'running';
		$job['started'] = Helpers::now();
		$job['by']      = get_current_user_id();
		$job['total']   = self::source( $job['source'] )->totals( $job );
		update_option( 'mbcom_import_seq', $job['id'], false );
		self::save( $job );
		self::schedule();
		return self::public_job( $job );
	}

	public static function rest_pause() {
		$job = self::job();
		if ( $job && 'running' === $job['status'] ) {
			$job['status'] = 'paused';
			self::save( $job );
		}
		return self::public_job( $job );
	}

	public static function rest_resume() {
		$job = self::job();
		if ( $job && 'paused' === $job['status'] ) {
			$job['status'] = 'running';
			self::save( $job );
			self::schedule();
		}
		return self::public_job( $job );
	}

	public static function rest_dismiss() {
		$job = self::job();
		if ( $job && in_array( $job['status'], array( 'done', 'undone', 'failed' ), true ) ) {
			$job['dismissed'] = true;
			self::save( $job );
		}
		return self::rest_state();
	}

	/**
	 * Undo the import in progress or just done, or with {id} any past one, newest or not.
	 * Nothing is undone while another import is running.
	 */
	public static function rest_undo( \WP_REST_Request $req ) {
		$job = self::job();
		$id  = (int) $req['id'] ?: (int) ( $job['id'] ?? 0 );
		$is_current = $job && (int) $job['id'] === $id;
		if ( $job && ! $is_current && in_array( $job['status'], array( 'running', 'paused' ), true ) ) {
			return new \WP_Error( 'mbcom_busy', __( 'Finish or undo the import in progress first.', 'membuild-community' ), array( 'status' => 409 ) );
		}
		$allowed = $is_current ? in_array( $job['status'], array( 'done', 'paused', 'failed' ), true ) : 'done' === ( self::past_one( $id )['status'] ?? '' );
		if ( ! $allowed ) {
			return new \WP_Error( 'mbcom_invalid', __( 'There is no import to undo.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$removed = self::undo( $id );
		self::remember_past( $id, array( 'status' => 'undone', 'undone_at' => Helpers::now() ) );
		if ( $is_current ) {
			$job['status'] = 'undone';
			$job['undone'] = $removed;
			self::save( $job );
			return self::public_job( $job );
		}
		return array( 'undone' => $removed, 'past' => self::past() );
	}

	/** What undoing an import would take: what it brought, and what members added to it since. */
	public static function rest_impact( \WP_REST_Request $req ) {
		global $wpdb;
		$id    = (int) $req['id'];
		$map   = Install::table( 'import_map' );
		$count = fn( $kind ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE import_id = %d AND kind = %s', $map, $id, $kind ) );
		$posts = "SELECT local_id FROM {$map} WHERE import_id = " . $id . " AND kind = 'post'";
		$since = array(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table names and an integer
			'comments'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Install::table( 'comments' ) . " c WHERE c.post_id IN ({$posts}) AND c.id NOT IN (SELECT local_id FROM {$map} WHERE import_id = {$id} AND kind = 'comment')" ),
			'reactions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Install::table( 'reactions' ) . " r WHERE ((r.object_type = 'post' AND r.object_id IN ({$posts})) OR (r.object_type = 'comment' AND r.object_id IN (SELECT id FROM " . Install::table( 'comments' ) . " WHERE post_id IN ({$posts})))) AND r.id NOT IN (SELECT local_id FROM {$map} WHERE import_id = {$id} AND kind = 'reaction')" ),
			'messages'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Install::table( 'messages' ) . " m WHERE m.thread_id IN (SELECT local_id FROM {$map} WHERE import_id = {$id} AND kind = 'thread') AND m.id NOT IN (SELECT local_id FROM {$map} WHERE import_id = {$id} AND kind = 'message')" ),
			// phpcs:enable
		);
		return array(
			'brought' => array( 'posts' => $count( 'post' ), 'comments' => $count( 'comment' ), 'reactions' => $count( 'reaction' ), 'chats' => $count( 'thread' ), 'topics' => $count( 'topic' ), 'courses' => $count( 'course' ), 'memberships' => $count( 'membership' ) + $count( 'grant' ) ),
			'since'   => $since,
		);
	}

	/* ---------- Past imports ---------- */

	/** The finished imports, newest first, for the list under Import. */
	public static function past() {
		$out = array();
		foreach ( array_reverse( (array) get_option( self::HISTORY, array() ) ) as $p ) {
			$p['finished']  = ! empty( $p['finished'] ) ? Helpers::iso( $p['finished'] ) : null;
			$p['undone_at'] = ! empty( $p['undone_at'] ) ? Helpers::iso( $p['undone_at'] ) : null;
			$out[]          = $p;
		}
		return array_slice( $out, 0, 20 );
	}

	private static function past_one( $id ) {
		foreach ( (array) get_option( self::HISTORY, array() ) as $p ) {
			if ( (int) $p['id'] === (int) $id ) {
				return $p;
			}
		}
		return null;
	}

	/** Adds or updates one import in the history. */
	private static function remember_past( $id, array $fields ) {
		$all   = (array) get_option( self::HISTORY, array() );
		$found = false;
		foreach ( $all as &$p ) {
			if ( (int) $p['id'] === (int) $id ) {
				$p     = array_merge( $p, $fields );
				$found = true;
			}
		}
		unset( $p );
		if ( ! $found ) {
			$all[] = array( 'id' => (int) $id ) + $fields;
		}
		update_option( self::HISTORY, array_slice( $all, -50 ), false );
	}

	/** Email everyone the import brought: "we've moved", or for new accounts a link to set a password. */
	public static function rest_email() {
		$job = self::job();
		if ( ! $job || 'done' !== $job['status'] ) {
			return new \WP_Error( 'mbcom_invalid', __( 'There is no finished import to email about.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( empty( $job['emailing'] ) ) {
			$job['emailing'] = array( 'after' => 0, 'sent' => 0, 'total' => self::member_count( (int) $job['id'] ) );
			self::save( $job );
			self::schedule_mail();
		}
		return self::public_job( $job );
	}

	/** The spreadsheet's bytes as the body, its name as ?name= (the way the app sends files). */
	public static function rest_file( \WP_REST_Request $req ) {
		$bytes = (string) $req->get_body();
		if ( '' === $bytes ) {
			return new \WP_Error( 'mbcom_empty', __( 'Choose a spreadsheet to upload.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		return Import_Csv::receive( (string) $req['name'], $bytes );
	}

	/** Reads and checks {source, bring, …} into a job that hasn't started. */
	private static function new_job( \WP_REST_Request $req ) {
		$key = (string) $req['source'];
		$src = self::source( $key );
		if ( ! $src || ( 'csv' !== $key && ! $src->detect() ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'That community isn’t on this site.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		$bring = array_values( array_intersect( self::BRINGS, (array) $req['bring'] ) );
		$job   = array(
			'source' => $key,
			'label'  => $src->label(),
			'bring'  => 'csv' === $key ? array( 'profiles' ) : $bring,
			'step'   => 'members',
			'after'  => 0,
			'done'   => array(),
			'counts' => array(),
		);
		if ( ! $job['bring'] ) {
			return new \WP_Error( 'mbcom_empty', __( 'Tick at least one thing to bring.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$job['groups_to'] = 'spaces' === $req['groups'] && self::groups_handler( $key, array() ) ? 'spaces' : 'topics';
		if ( 'csv' === $key ) {
			$extra = Import_Csv::options( $req );
			if ( is_wp_error( $extra ) ) {
				return $extra;
			}
			$job += $extra;
		}
		return $job;
	}

	/* ---------- The job ---------- */

	public static function job() {
		$job = get_option( self::OPTION );
		return is_array( $job ) ? $job : null;
	}

	private static function save( $job ) {
		update_option( self::OPTION, $job, false );
	}

	private static function public_job( $job ) {
		if ( ! $job || ! empty( $job['dismissed'] ) ) {
			return null;
		}
		$keep = array( 'id', 'source', 'label', 'bring', 'status', 'step', 'counts', 'total', 'started', 'finished', 'error', 'undone', 'emailing', 'notes', 'groups_to', 'welcome' );
		$out  = array_intersect_key( $job, array_flip( $keep ) );
		foreach ( array( 'started', 'finished' ) as $k ) {
			if ( ! empty( $out[ $k ] ) ) {
				$out[ $k ] = Helpers::iso( $out[ $k ] );
			}
		}
		$out['steps'] = self::steps_for( $job );
		return $out;
	}

	/** The steps this job runs, in order. */
	public static function steps_for( $job ) {
		$b    = $job['bring'];
		$want = array(
			'members'   => true, // profiles, or for a spreadsheet the accounts themselves
			'topics'    => in_array( 'posts', $b, true ),
			'posts'     => in_array( 'posts', $b, true ),
			'comments'  => in_array( 'posts', $b, true ),
			'reactions' => in_array( 'reactions', $b, true ),
			'courses'   => in_array( 'courses', $b, true ),
			'messages'  => in_array( 'messages', $b, true ),
			'points'    => in_array( 'points', $b, true ),
			'finish'    => true,
		);
		return array_keys( array_filter( $want ) );
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		spawn_cron();
	}

	/**
	 * While the owner watches, a status check also moves the import along when WordPress's
	 * scheduler hasn't (it only runs on visits, and some hosts switch it off).
	 */
	private static function maybe_nudge() {
		$job = self::job();
		if ( $job && 'running' === $job['status'] && time() - (int) ( $job['ticked'] ?? 0 ) > 30 ) {
			self::tick( 6 );
			$job = self::job();
		}
		return $job;
	}

	/** One run: as many batches as fit in a few seconds, then it schedules the next. */
	public static function tick( $seconds = self::SECONDS ) {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, self::SECONDS + 40 );
		$until = microtime( true ) + (float) $seconds;
		try {
			$job = self::job();
			while ( $job && 'running' === $job['status'] && microtime( true ) < $until ) {
				$job = self::step( $job );
				$job['ticked'] = time();
				self::save( $job );
				$job = self::job(); // a Pause pressed meanwhile wins
			}
		} catch ( \Throwable $e ) {
			$job           = self::job();
			$job['status'] = 'paused';
			$job['error']  = $e->getMessage();
			self::save( $job );
		} finally {
			delete_transient( self::LOCK );
		}
		$job = self::job();
		if ( $job && 'running' === $job['status'] ) {
			wp_schedule_single_event( time() + 2, self::HOOK );
		}
	}

	/** One batch of the current step. */
	private static function step( $job ) {
		$steps = self::steps_for( $job );
		if ( ! in_array( $job['step'], $steps, true ) ) {
			$job['step'] = $steps[0];
		}
		$src  = self::source( $job['source'] );
		$step = $job['step'];
		$next = null;
		switch ( $step ) {
			case 'members':
				list( $rows, $next ) = $src->batch( 'members', $job['after'], self::BATCH, $job );
				foreach ( $rows as $row ) {
					self::count( $job, 'members', self::write_member( $job, $row ) );
				}
				break;
			case 'topics':
				self::write_groups( $job, $src->groups() );
				break;
			case 'posts':
				list( $rows, $next ) = $src->batch( 'posts', $job['after'], self::BATCH, $job );
				foreach ( $rows as $row ) {
					self::count( $job, 'posts', self::write_post( $job, $row ) );
				}
				break;
			case 'comments':
				list( $rows, $next ) = $src->batch( 'comments', $job['after'], self::BATCH, $job );
				foreach ( $rows as $row ) {
					self::count( $job, 'comments', self::write_comment( $job, $row ) );
				}
				break;
			case 'reactions':
				list( $rows, $next ) = $src->batch( 'reactions', $job['after'], self::BATCH * 5, $job );
				foreach ( $rows as $row ) {
					self::count( $job, 'reactions', self::write_reaction( $job, $row ) );
				}
				break;
			case 'courses':
				list( $rows, $next ) = $src->batch( 'courses', $job['after'], 1, $job );
				foreach ( $rows as $row ) {
					self::count( $job, 'courses', self::write_course( $job, $row ) );
				}
				break;
			case 'messages':
				if ( Vault::ready() ) {
					list( $rows, $next ) = $src->batch( 'threads', $job['after'], 25, $job );
					foreach ( $rows as $row ) {
						self::count( $job, 'messages', self::write_thread( $job, $row ) );
					}
				}
				break;
			case 'points':
				$next = self::write_points( $job, $job['after'] );
				break;
			case 'finish':
				self::finish( $job );
				return $job;
		}
		if ( null === $next ) {
			$job['done'][] = $step;
			$at            = array_search( $step, $steps, true );
			$job['step']   = $steps[ $at + 1 ] ?? 'finish';
			$job['after']  = 0;
		} else {
			$job['after'] = $next;
		}
		return $job;
	}

	private static function count( &$job, $kind, $added ) {
		$job['counts'][ $kind ] = (int) ( $job['counts'][ $kind ] ?? 0 ) + (int) $added;
	}

	private static function finish( &$job ) {
		global $wpdb;
		$id    = (int) $job['id'];
		$map   = Install::table( 'import_map' );
		$posts = Install::table( 'posts' );
		$comms = Install::table( 'comments' );
		$reac  = Install::table( 'reactions' );
		// Counts on what this import touched, worked out once at the end.
		$wpdb->query( $wpdb->prepare( "UPDATE %i p JOIN %i m ON m.import_id = %d AND m.kind = 'post' AND m.local_id = p.id SET p.comments_count = (SELECT COUNT(*) FROM %i c WHERE c.post_id = p.id), p.reactions_count = (SELECT COUNT(*) FROM %i r WHERE r.object_type = 'post' AND r.object_id = p.id)", $posts, $map, $id, $comms, $reac ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i c JOIN %i m ON m.import_id = %d AND m.kind = 'comment' AND m.local_id = c.id SET c.replies_count = (SELECT COUNT(*) FROM (SELECT parent_id FROM %i) x WHERE x.parent_id = c.id), c.reactions_count = (SELECT COUNT(*) FROM %i r WHERE r.object_type = 'comment' AND r.object_id = c.id)", $comms, $map, $id, $comms, $reac ) );
		if ( 'csv' !== $job['source'] && in_array( 'profiles', $job['bring'], true ) ) {
			// Everyone came along (they're the same WordPress accounts), not only those whose profile changed.
			$job['counts']['members'] = max( (int) ( $job['counts']['members'] ?? 0 ), (int) ( $job['total']['members'] ?? 0 ) );
		}
		Search::schedule_index();
		delete_transient( 'mbcom_member_total' );
		$job['status']   = 'done';
		$job['finished'] = Helpers::now();
		$job['done'][]   = 'finish';
		self::remember_past( $id, array( 'source' => $job['source'], 'label' => $job['label'], 'name' => $job['name'] ?? '', 'finished' => $job['finished'], 'counts' => $job['counts'], 'status' => 'done' ) );
		self::tell_owner( $job );
		if ( 'csv' === $job['source'] && ! empty( $job['welcome'] ) ) {
			// "Send each new member a welcome email": new accounts only, slowly.
			$job['emailing'] = array( 'after' => 0, 'sent' => 0, 'total' => (int) ( $job['counts']['members'] ?? 0 ), 'kinds' => array( 'account' ) );
			self::schedule_mail();
		}
		/**
		 * An import finished.
		 *
		 * @param int    $import_id The import.
		 * @param string $source    'buddyboss', 'csv', …
		 */
		do_action( 'membuild_community/import_done', $id, $job['source'] );
	}

	/* ---------- The map: what each import made ---------- */

	public static function mapped( $source, $kind, $source_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT local_id FROM %i WHERE source = %s AND kind = %s AND source_id = %s', Install::table( 'import_map' ), self::family( $source ), $kind, (string) $source_id ) );
	}

	private static function remember( $job, $kind, $source_id, $local_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (import_id, source, kind, source_id, local_id) VALUES (%d, %s, %s, %s, %d)',
				Install::table( 'import_map' ),
				(int) $job['id'],
				self::family( $job['source'] ),
				$kind,
				(string) $source_id,
				(int) $local_id
			)
		);
	}

	/** A membership a spreadsheet gave someone, written down for Undo. */
	public static function granted( $job, $group_id, $user_id ) {
		self::remember( $job, 'grant', $group_id . ':' . $user_id, $user_id );
	}

	/** BuddyBoss and BuddyPress share their tables, so they share the map too. */
	private static function family( $source ) {
		return 'buddyboss' === $source ? 'buddypress' : $source;
	}

	private static function member_count( $import_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE import_id = %d AND kind IN ('member', 'account')", Install::table( 'import_map' ), $import_id ) );
	}

	/* ---------- Writing ---------- */

	/**
	 * A member's profile: photo, cover, bio and location, only where ours is still empty.
	 * A spreadsheet row instead brings the account itself (see Import_Csv::write).
	 */
	private static function write_member( &$job, $row ) {
		if ( 'csv' === $job['source'] ) {
			list( $user_id, $is_new ) = Import_Csv::write( $job, $row );
			if ( $user_id ) {
				self::remember( $job, $is_new ? 'account' : 'member', strtolower( $row['email'] ), $user_id );
				$is_new || self::count( $job, 'updated', 1 );
			}
			return $is_new ? 1 : 0;
		}
		$user_id = (int) $row['user_id'];
		if ( ! in_array( 'profiles', $job['bring'], true ) || ! get_userdata( $user_id ) ) {
			return 0;
		}
		$fields = array(
			'mbcom_avatar_url' => $row['avatar'] ?? '',
			Rest_Members::COVER    => $row['cover'] ?? '',
			'mbcom_bio'        => isset( $row['bio'] ) ? mb_substr( Helpers::clean_text( self::text( $row['bio'] ), 500 ), 0, 500 ) : '',
			Rest_Members::LOCATION => isset( $row['location'] ) ? mb_substr( sanitize_text_field( $row['location'] ), 0, 80 ) : '',
		);
		foreach ( $fields as $meta => $value ) {
			if ( '' !== $value && '' === (string) get_user_meta( $user_id, $meta, true ) ) {
				update_user_meta( $user_id, $meta, $value );
			}
		}
		self::remember( $job, 'member', $user_id, $user_id );
		return 1;
	}

	/** Groups or spaces become topics (or, with Pro, its spaces). */
	private static function write_groups( &$job, array $groups ) {
		$handler = 'spaces' === ( $job['groups_to'] ?? 'topics' ) ? self::groups_handler( $job['source'], $groups ) : null;
		if ( $handler ) {
			foreach ( (array) call_user_func( $handler, $groups ) as $source_id => $space_id ) {
				self::remember( $job, 'space', $source_id, (int) $space_id );
			}
			$job['counts']['topics'] = count( $groups );
			return;
		}
		$room  = max( 0, Topics::MAX - count( Topics::all() ) );
		$added = 0;
		// The busiest first, so the topics left out (past the limit) are the quiet ones.
		usort( $groups, fn( $a, $b ) => ( $b['posts'] ?? 0 ) <=> ( $a['posts'] ?? 0 ) );
		foreach ( $groups as $g ) {
			if ( self::mapped( $job['source'], 'topic', $g['source_id'] ) ) {
				continue; // brought last time
			}
			$existing = Topics::by_name( $g['name'] );
			if ( ! $existing && $room <= 0 ) {
				continue;
			}
			$group_id = 0;
			if ( 'public' !== $g['privacy'] ) {
				$group_id = Access::create( $g['name'] );
				foreach ( $g['member_ids'] as $uid ) {
					Access::add( $group_id, (int) $uid );
				}
				self::remember( $job, 'membership', $g['source_id'], $group_id );
			}
			if ( $existing ) {
				$topic_id = (int) $existing['id'];
				self::remember( $job, 'topic_seen', $g['source_id'], $topic_id ); // matched by name, not ours to undo
			} else {
				$topic_id = Topics::add(
					array(
						'name'        => $g['name'],
						'description' => $g['description'] ?? '',
						'color'       => Topics::COLORS[ count( Topics::all() ) % count( Topics::COLORS ) ],
						'group_id'    => $group_id,
					)
				);
				--$room;
				++$added;
				self::remember( $job, 'topic', $g['source_id'], $topic_id );
			}
		}
		$job['counts']['topics'] = $added;
	}

	/** The topic a source group's posts land in, if any. */
	private static function topic_for( $job, $group_source_id ) {
		if ( ! $group_source_id ) {
			return 0;
		}
		return self::mapped( $job['source'], 'topic', $group_source_id ) ?: self::mapped( $job['source'], 'topic_seen', $group_source_id );
	}

	private static function write_post( $job, $row ) {
		global $wpdb;
		if ( self::mapped( $job['source'], 'post', $row['source_id'] ) ) {
			return 0;
		}
		$content = Helpers::clean_text( self::text( $row['content'] ?? '' ), 20000 );
		$media   = array_slice( array_filter( (array) ( $row['media'] ?? array() ), fn( $m ) => ! empty( $m['url'] ) ), 0, 20 );
		if ( '' === trim( $content ) && ! $media ) {
			return 0; // nothing left to show (a photo that no longer exists)
		}
		$at = $row['created_at'] ?: Helpers::now();
		$wpdb->insert(
			Install::table( 'posts' ),
			array(
				'user_id'    => (int) $row['user_id'],
				'type'       => 'post',
				'status'     => 'published',
				'content'    => $content,
				'topic_id'   => self::topic_for( $job, $row['group'] ?? 0 ),
				'created_at' => $at,
				'updated_at' => $at,
			)
		);
		$post_id = (int) $wpdb->insert_id;
		self::remember( $job, 'post', $row['source_id'], $post_id );
		foreach ( array_values( $media ) as $i => $m ) {
			$wpdb->insert(
				Install::table( 'media' ),
				array(
					'user_id'       => (int) $row['user_id'],
					'post_id'       => $post_id,
					'kind'          => 'video' === ( $m['kind'] ?? '' ) ? 'video' : 'image',
					'attachment_id' => (int) ( $m['attachment_id'] ?? 0 ) ?: null,
					'url'           => esc_url_raw( $m['url'] ),
					'mime'          => $m['mime'] ?? ( 'video' === ( $m['kind'] ?? '' ) ? 'video/mp4' : 'image/jpeg' ),
					'width'         => (int) ( $m['width'] ?? 0 ),
					'height'        => (int) ( $m['height'] ?? 0 ),
					'sort_order'    => $i,
					'created_at'    => $at,
				)
			);
			self::remember( $job, 'media', $row['source_id'] . ':' . $i, (int) $wpdb->insert_id );
		}
		/** @see docs/from-core-2026-10-30.md: Pro places a post in its space. */
		do_action( 'membuild_community/import_post', $post_id, $row['group'] ?? 0, $job['source'] );
		return 1;
	}

	/**
	 * Comments keep one level of replies, as here: a reply to a reply joins the same
	 * top comment and says who it answers.
	 */
	private static function write_comment( $job, $row ) {
		global $wpdb;
		if ( self::mapped( $job['source'], 'comment', $row['source_id'] ) ) {
			return 0;
		}
		$post_id = self::mapped( $job['source'], 'post', $row['post'] );
		$content = Helpers::clean_text( self::text( $row['content'] ?? '' ), 5000 );
		$image   = $row['image'] ?? '';
		if ( ! $post_id || ( '' === trim( $content ) && ! $image ) ) {
			return 0;
		}
		$parent = 0;
		$to     = 0;
		if ( ! empty( $row['parent'] ) ) {
			$above = $wpdb->get_row( $wpdb->prepare( 'SELECT id, parent_id, user_id FROM %i WHERE id = %d', Install::table( 'comments' ), self::mapped( $job['source'], 'comment', $row['parent'] ) ), ARRAY_A );
			if ( $above ) {
				$parent = (int) $above['parent_id'] ?: (int) $above['id'];
				$to     = (int) $above['parent_id'] ? (int) $above['user_id'] : 0;
			}
		}
		$wpdb->insert(
			Install::table( 'comments' ),
			array(
				'post_id'          => $post_id,
				'user_id'          => (int) $row['user_id'],
				'parent_id'        => $parent,
				'reply_to_user_id' => $to,
				'content'          => $content,
				'image_url'        => $image ? esc_url_raw( $image ) : null,
				'created_at'       => $row['created_at'] ?: Helpers::now(),
			)
		);
		self::remember( $job, 'comment', $row['source_id'], (int) $wpdb->insert_id );
		return 1;
	}

	private static function write_reaction( $job, $row ) {
		global $wpdb;
		$kind = 'comment' === $row['object'] ? 'comment' : 'post';
		$id   = self::mapped( $job['source'], $kind, $row['object_id'] );
		if ( ! $id || ! $row['user_id'] ) {
			return 0;
		}
		$type = in_array( $row['type'] ?? '', Helpers::REACTIONS, true ) ? $row['type'] : 'like';
		$done = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (object_type, object_id, user_id, type, created_at) VALUES (%s, %d, %d, %s, %s)',
				Install::table( 'reactions' ),
				$kind,
				$id,
				(int) $row['user_id'],
				$type,
				$row['created_at'] ?: Helpers::now()
			)
		);
		if ( $done ) {
			self::remember( $job, 'reaction', $kind . ':' . $row['object_id'] . ':' . $row['user_id'], (int) $wpdb->insert_id );
		}
		return $done ? 1 : 0;
	}

	/**
	 * A course into the Classroom: sections become modules, lessons keep their order and
	 * video, and members keep their progress. A private course gets a membership with the
	 * same students.
	 */
	private static function write_course( $job, $c ) {
		global $wpdb;
		if ( self::mapped( $job['source'], 'course', $c['source_id'] ) ) {
			return 0;
		}
		$at       = $c['created_at'] ?: Helpers::now();
		$group_id = 0;
		if ( $c['private'] ) {
			$group_id = Access::create( $c['title'] );
			foreach ( $c['students'] as $uid ) {
				Access::add( $group_id, (int) $uid );
			}
			self::remember( $job, 'membership', 'course:' . $c['source_id'], $group_id );
		}
		$wpdb->insert(
			Install::table( 'courses' ),
			array(
				'title'         => mb_substr( sanitize_text_field( $c['title'] ), 0, 200 ),
				'description'   => Helpers::clean_text( $c['description'], 2000 ),
				'cover_url'     => $c['cover_url'] ? esc_url_raw( $c['cover_url'] ) : null,
				'status'        => $c['published'] ? 'published' : 'draft',
				'access'        => $group_id ? 'groups' : 'everyone',
				'access_groups' => $group_id ? wp_json_encode( array( $group_id ) ) : null,
				'sort_order'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'courses' ) ) ),
				'created_by'    => (int) $c['created_by'] ?: (int) ( $job['by'] ?? 0 ),
				'created_at'    => $at,
				'updated_at'    => $at,
			)
		);
		$course_id = (int) $wpdb->insert_id;
		self::remember( $job, 'course', $c['source_id'], $course_id );
		$modules = array();
		foreach ( $c['modules'] as $i => $m ) {
			$wpdb->insert( Install::table( 'modules' ), array( 'course_id' => $course_id, 'title' => mb_substr( sanitize_text_field( $m['title'] ), 0, 200 ), 'sort_order' => $i ) );
			$modules[ $m['source_id'] ] = (int) $wpdb->insert_id;
		}
		$lessons = array();
		$order   = array();
		foreach ( $c['lessons'] as $l ) {
			$module = $modules[ $l['module'] ] ?? 0;
			if ( ! $module ) {
				// A lesson outside any section gets one, so it still shows.
				$wpdb->insert( Install::table( 'modules' ), array( 'course_id' => $course_id, 'title' => __( 'Lessons', 'membuild-community' ), 'sort_order' => count( $modules ) ) );
				$module                  = (int) $wpdb->insert_id;
				$modules[ $l['module'] ] = $module;
			}
			$media_id = null;
			if ( $l['video'] && ( Embeds::youtube_id( $l['video'] ) || Embeds::vimeo_id( $l['video'] ) ) ) {
				$embed    = Embeds::create( (int) ( $job['by'] ?? 0 ), $l['video'] );
				$media_id = is_array( $embed ) && ! empty( $embed['id'] ) ? (int) $embed['id'] : null;
			}
			$order[ $module ] = ( $order[ $module ] ?? -1 ) + 1;
			$wpdb->insert(
				Install::table( 'lessons' ),
				array(
					'course_id'  => $course_id,
					'module_id'  => $module,
					'title'      => mb_substr( sanitize_text_field( $l['title'] ), 0, 200 ),
					'content'    => Helpers::clean_text( self::text( $l['content'] ), 50000 ),
					'media_id'   => $media_id,
					'sort_order' => $order[ $module ],
					'created_at' => $l['created_at'] ?: $at,
					'updated_at' => $l['created_at'] ?: $at,
				)
			);
			$lessons[ $l['source_id'] ] = (int) $wpdb->insert_id;
		}
		foreach ( $c['done'] as $d ) {
			if ( isset( $lessons[ $d['lesson'] ] ) ) {
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id, lesson_id, course_id, completed_at) VALUES (%d, %d, %d, %s)', Install::table( 'lesson_progress' ), $d['user_id'], $lessons[ $d['lesson'] ], $course_id, $d['at'] ?: $at ) );
			}
		}
		foreach ( array_unique( array_merge( $c['students'], array_column( $c['done'], 'user_id' ) ) ) as $uid ) {
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id, course_id, started_at) VALUES (%d, %d, %s)', Install::table( 'course_starts' ), (int) $uid, $course_id, $at ) );
		}
		return 1;
	}

	/** A chat with its messages, encrypted as they come in. Members see it as read. */
	private static function write_thread( $job, $row ) {
		global $wpdb;
		if ( self::mapped( $job['source'], 'thread', $row['source_id'] ) ) {
			return 0;
		}
		$people = array_values( array_unique( array_filter( array_map( 'intval', $row['user_ids'] ), fn( $u ) => $u && get_userdata( $u ) ) ) );
		$msgs   = array_values( array_filter( (array) $row['messages'], fn( $m ) => '' !== trim( self::text( $m['content'] ?? '' ) ) ) );
		if ( count( $people ) < 2 || ! $msgs ) {
			return 0;
		}
		$threads = Install::table( 'threads' );
		$direct  = 2 === count( $people );
		$key     = $direct ? min( $people ) . ':' . max( $people ) : null;
		$first   = $msgs[0]['created_at'] ?: Helpers::now();
		$thread  = $key ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE direct_key = %s', $threads, $key ) ) : 0;
		$ours    = ! $thread;
		if ( $ours ) {
			$wpdb->insert( $threads, array( 'type' => $direct ? 'direct' : 'group', 'direct_key' => $key, 'title' => $direct ? null : ( mb_substr( sanitize_text_field( $row['title'] ?? '' ), 0, 100 ) ?: null ), 'created_by' => (int) ( $row['created_by'] ?? $people[0] ), 'created_at' => $first ) );
			$thread = (int) $wpdb->insert_id;
			foreach ( $people as $uid ) {
				$wpdb->insert( Install::table( 'thread_members' ), array( 'thread_id' => $thread, 'user_id' => $uid, 'joined_at' => $first ) );
			}
		}
		self::remember( $job, $ours ? 'thread' : 'thread_seen', $row['source_id'], $thread );
		$last = 0;
		$when = $first;
		foreach ( $msgs as $i => $m ) {
			$uid = (int) $m['user_id'];
			if ( ! in_array( $uid, $people, true ) ) {
				continue;
			}
			$wpdb->insert(
				Install::table( 'messages' ),
				array(
					'thread_id'  => $thread,
					'user_id'    => $uid,
					'content'    => Vault::seal( Helpers::clean_text( self::text( $m['content'] ), 5000 ), 'message:' . $thread ),
					'created_at' => $m['created_at'] ?: $when,
				)
			);
			$last = (int) $wpdb->insert_id;
			$when = $m['created_at'] ?: $when;
			self::remember( $job, 'message', $row['source_id'] . ':' . $i, $last );
		}
		if ( $last ) {
			$newest = $wpdb->get_row( $wpdb->prepare( 'SELECT id, created_at FROM %i WHERE thread_id = %d ORDER BY created_at DESC, id DESC LIMIT 1', Install::table( 'messages' ), $thread ), ARRAY_A );
			$wpdb->update( $threads, array( 'last_message_id' => (int) $newest['id'], 'last_message_at' => $newest['created_at'] ), array( 'id' => $thread ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_read_id = GREATEST(last_read_id, %d) WHERE thread_id = %d', Install::table( 'thread_members' ), (int) $newest['id'], $thread ) );
		}
		return 1;
	}

	/**
	 * Points worked out from what came over, on the days it happened (the usual daily caps
	 * apply), so long-time members keep their place instead of starting at Level 1.
	 */
	private static function write_points( $job, $after ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, kind, local_id FROM %i WHERE import_id = %d AND kind IN ('post', 'comment', 'reaction') AND id > %d ORDER BY id LIMIT %d", Install::table( 'import_map' ), (int) $job['id'], (int) $after, self::BATCH * 2 ),
			ARRAY_A
		);
		foreach ( $rows as $r ) {
			$lid = (int) $r['local_id'];
			if ( 'post' === $r['kind'] ) {
				$p = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, created_at FROM %i WHERE id = %d', Install::table( 'posts' ), $lid ), ARRAY_A );
				$p && Points::award( (int) $p['user_id'], 'post', 'post', $lid, 0, $p['created_at'] );
			} elseif ( 'comment' === $r['kind'] ) {
				$c = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, created_at FROM %i WHERE id = %d', Install::table( 'comments' ), $lid ), ARRAY_A );
				$c && Points::award( (int) $c['user_id'], 'comment', 'comment', $lid, 0, $c['created_at'] );
			} else {
				$x = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT r.object_type, r.object_id, r.user_id, r.created_at, COALESCE(p.user_id, c.user_id) AS author FROM %i r LEFT JOIN %i p ON r.object_type = 'post' AND p.id = r.object_id LEFT JOIN %i c ON r.object_type = 'comment' AND c.id = r.object_id WHERE r.id = %d",
						Install::table( 'reactions' ),
						Install::table( 'posts' ),
						Install::table( 'comments' ),
						$lid
					),
					ARRAY_A
				);
				if ( $x && (int) $x['author'] && (int) $x['author'] !== (int) $x['user_id'] ) {
					Points::award( (int) $x['author'], 'reaction_received', $x['object_type'], (int) $x['object_id'], (int) $x['user_id'], $x['created_at'] );
				}
			}
		}
		return count( $rows ) < self::BATCH * 2 ? null : (int) end( $rows )['id'];
	}

	/* ---------- Undo ---------- */

	/** Removes what one import made: posts, comments, reactions, chats, topics and memberships. Profiles and accounts stay. */
	public static function undo( $import_id ) {
		global $wpdb;
		$map     = Install::table( 'import_map' );
		$removed = array();
		$tables  = array(
			'reaction'   => 'reactions',
			'media'      => 'media',
			'comment'    => 'comments',
			'post'       => 'posts',
			'message'    => 'messages',
			'thread'     => 'threads',
			'topic'      => 'topics',
			'course'     => 'courses',
			'membership' => 'groups',
		);
		foreach ( $tables as $kind => $table ) {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT local_id FROM %i WHERE import_id = %d AND kind = %s', $map, $import_id, $kind ) ) );
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$in = implode( ',', $chunk );
				if ( 'post' === $kind ) {
					// Comments and reactions members added here since go with the posts (they'd be left pointing at nothing).
					$later = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE post_id IN ({$in})", Install::table( 'comments' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					foreach ( array_chunk( $later, 500 ) as $cc ) {
						$cin = implode( ',', $cc );
						$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'comment' AND object_id IN ({$cin})", Install::table( 'reactions' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
						$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'comment' AND object_id IN ({$cin})", Install::table( 'points' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					}
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN ({$in})", Install::table( 'comments' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'post' AND object_id IN ({$in})", Install::table( 'reactions' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN ({$in})", Install::table( 'media' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN ({$in})", Install::table( 'notifications' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'post' AND object_id IN ({$in})", Install::table( 'points' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE reason = 'post' AND object_id IN ({$in})", Install::table( 'points' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
				}
				if ( 'comment' === $kind ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE object_type = 'comment' AND object_id IN ({$in})", Install::table( 'points' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
				}
				if ( 'thread' === $kind ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE thread_id IN ({$in})", Install::table( 'messages' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers, and messages written since
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE thread_id IN ({$in})", Install::table( 'thread_members' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
				}
				if ( 'course' === $kind ) {
					foreach ( array( 'lesson_progress', 'course_starts', 'lessons', 'modules' ) as $part ) {
						$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE course_id IN ({$in})", Install::table( $part ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					}
				}
				if ( 'topic' === $kind ) {
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET topic_id = 0 WHERE topic_id IN ({$in})", Install::table( 'posts' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
				}
				if ( 'membership' === $kind ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE group_id IN ({$in})", Install::table( 'group_members' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET group_id = 0 WHERE group_id IN ({$in})", Install::table( 'topics' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$in})", Install::table( $table ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers
			}
			$removed[ $kind ] = count( $ids );
		}
		// Memberships a spreadsheet gave: taken back (the accounts stay, so nobody is locked out of their login).
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT source_id FROM %i WHERE import_id = %d AND kind = 'grant'", $map, $import_id ) ) as $grant ) {
			list( $group, $user ) = array_map( 'intval', explode( ':', $grant ) );
			Access::remove( $group, $user );
		}
		// Messages added to chats that were already here.
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT local_id FROM %i WHERE import_id = %d AND kind = 'thread_seen'", $map, $import_id ) ) as $thread ) {
			$newest = $wpdb->get_row( $wpdb->prepare( 'SELECT id, created_at FROM %i WHERE thread_id = %d ORDER BY id DESC LIMIT 1', Install::table( 'messages' ), $thread ), ARRAY_A );
			$wpdb->update( Install::table( 'threads' ), array( 'last_message_id' => $newest ? (int) $newest['id'] : 0, 'last_message_at' => $newest['created_at'] ?? null ), array( 'id' => (int) $thread ) );
		}
		// Reactions on posts that stay (they were here before) need their counts again.
		$wpdb->query( $wpdb->prepare( "UPDATE %i p SET p.reactions_count = (SELECT COUNT(*) FROM %i r WHERE r.object_type = 'post' AND r.object_id = p.id), p.comments_count = (SELECT COUNT(*) FROM %i c WHERE c.post_id = p.id)", Install::table( 'posts' ), Install::table( 'reactions' ), Install::table( 'comments' ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE reason = 'reaction_received' AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.object_type = %i.object_type AND r.object_id = %i.object_id AND r.user_id = %i.actor_id)", Install::table( 'points' ), Install::table( 'reactions' ), Install::table( 'points' ), Install::table( 'points' ), Install::table( 'points' ) ) );
		Points::recount();
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE import_id = %d AND kind NOT IN ('member', 'account')", $map, $import_id ) );
		Topics::forget();
		Access::forget_all();
		/** @param int $import_id The import that was undone. */
		do_action( 'membuild_community/import_undone', $import_id );
		return $removed;
	}

	/* ---------- Email ---------- */

	private static function tell_owner( $job ) {
		$owner = get_userdata( (int) ( $job['by'] ?? 0 ) );
		if ( ! $owner ) {
			return;
		}
		$c     = $job['counts'];
		$parts = array_filter(
			array(
				/* translators: %s: a number */
				! empty( $c['members'] ) ? sprintf( _n( '%s member', '%s members', $c['members'], 'membuild-community' ), number_format_i18n( $c['members'] ) ) : '',
				/* translators: %s: a number */
				! empty( $c['posts'] ) ? sprintf( _n( '%s post', '%s posts', $c['posts'], 'membuild-community' ), number_format_i18n( $c['posts'] ) ) : '',
				/* translators: %s: a number */
				! empty( $c['comments'] ) ? sprintf( _n( '%s comment', '%s comments', $c['comments'], 'membuild-community' ), number_format_i18n( $c['comments'] ) ) : '',
				/* translators: %s: a number */
				! empty( $c['messages'] ) ? sprintf( _n( '%s chat', '%s chats', $c['messages'], 'membuild-community' ), number_format_i18n( $c['messages'] ) ) : '',
			)
		);
		/* translators: %s: where it came from, e.g. BuddyBoss */
		$title = sprintf( __( 'Your import from %s is done', 'membuild-community' ), $job['label'] );
		$line  = $parts ? implode( ', ', $parts ) . '.' : __( 'Everything was already here.', 'membuild-community' );
		$url   = Portal::url() . '/settings?s=import#s-import';
		$html  = Email::layout( $title, Email::para( esc_html( $line ) ), $url, __( 'See what came over', 'membuild-community' ), '', __( 'You’re getting this because you started an import.', 'membuild-community' ) );
		Email::send( $owner->user_email, $title, $html, $title . "\n\n" . $line . "\n\n" . $url );
	}

	private static function schedule_mail() {
		if ( ! wp_next_scheduled( self::MAIL ) ) {
			wp_schedule_single_event( time() + 5, self::MAIL );
		}
	}

	/** A few emails at a time, a few minutes apart, so the host's mail server doesn't flag them. */
	public static function mail_tick() {
		global $wpdb;
		$job = self::job();
		if ( ! $job || empty( $job['emailing'] ) ) {
			return;
		}
		$e     = $job['emailing'];
		$kinds = "'" . implode( "', '", array_intersect( array( 'member', 'account' ), $e['kinds'] ?? array( 'member', 'account' ) ) ) . "'";
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, kind, local_id FROM %i WHERE import_id = %d AND kind IN ({$kinds}) AND id > %d ORDER BY id LIMIT %d", Install::table( 'import_map' ), (int) $job['id'], (int) $e['after'], self::MAIL_PER ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed values
		foreach ( $rows as $r ) {
			if ( self::email_member( (int) $r['local_id'], 'account' === $r['kind'] ) ) {
				++$e['sent'];
			}
			$e['after'] = (int) $r['id'];
		}
		$e['finished']   = count( $rows ) < self::MAIL_PER;
		$job['emailing'] = $e;
		self::save( $job );
		if ( ! $e['finished'] ) {
			wp_schedule_single_event( time() + 150, self::MAIL );
		}
	}

	/** "We've moved" with a link to log in; a new account gets a link to set its password instead. */
	public static function email_member( $user_id, $new_account ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		$name  = Settings::name();
		$first = $user->first_name ?: $user->display_name;
		if ( $new_account ) {
			$key = get_password_reset_key( $user );
			if ( is_wp_error( $key ) ) {
				return false;
			}
			$url    = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
			$button = __( 'Set my password', 'membuild-community' );
			/* translators: %s: community name */
			$body = sprintf( __( '%s has a new home, and you have an account there. Set a password to log in.', 'membuild-community' ), $name );
		} else {
			$url    = Portal::url();
			$button = __( 'Open the community', 'membuild-community' );
			/* translators: %s: community name */
			$body = sprintf( __( '%s has a new look. Your posts, comments and chats came along, and your password hasn’t changed.', 'membuild-community' ), $name );
		}
		/* translators: %s: member's first name */
		$title = sprintf( __( 'Hi %s, we’ve moved in', 'membuild-community' ), $first );
		/* translators: %s: community name */
		$why  = sprintf( __( 'You’re getting this because you’re a member of %s.', 'membuild-community' ), $name );
		$html = Email::layout( $title, Email::para( esc_html( $body ) ), $url, $button, '', $why );
		return true === Email::send( $user->user_email, $title, $html, $title . "\n\n" . $body . "\n\n" . $url );
	}

	/* ---------- Helpers ---------- */

	/**
	 * Lets an add-on import groups as something else (Pro: spaces).
	 * Return a callable to take them, or null to leave them to core (topics).
	 *
	 * @param null   $handler
	 * @param string $source  'buddyboss' | 'buddypress' | 'peepso' | 'fluentcommunity'
	 * @param array  $groups  each: source_id, name, description, privacy ('public'|'private'|'hidden'),
	 *                        avatar_url, cover_url, member_ids (WP user ids), admin_ids
	 */
	public static function groups_handler( $source, array $groups ) {
		$handler = apply_filters( 'membuild_community/import_groups', null, $source, $groups );
		return is_callable( $handler ) ? $handler : null;
	}

	/** HTML from another plugin as the plain text posts here are written in: line breaks kept, links written out. */
	public static function text( $html ) {
		$html = (string) $html;
		if ( '' === $html || false === strpbrk( $html, '<&' ) ) {
			return $html;
		}
		$html = preg_replace_callback(
			'#<a(\s[^>]*)>(.*?)</a>#is',
			function ( $m ) {
				$label = trim( wp_strip_all_tags( $m[2] ) );
				$href  = preg_match( '#href=["\']([^"\']+)["\']#i', $m[1], $h ) ? html_entity_decode( $h[1] ) : '';
				if ( preg_match( '#mention|data-user_name|data-bb-hp-profile#i', $m[1] ) || 0 === strpos( $label, '@' ) ) {
					return '@' . ltrim( $label, '@' ); // a mention: the name is enough
				}
				if ( ! preg_match( '#^https?://#i', $href ) || '' === $label || $label === $href ) {
					return '' !== $label ? $label : $href; // a link back into the old site, or a bare address
				}
				return $label . ' (' . $href . ')';
			},
			$html
		);
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|li|h[1-6]|blockquote)>#i', "\n", $html );
		$html = preg_replace( '#<li[^>]*>#i', '• ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( "/[ \t]+\n/", "\n", $text ) ) );
	}

	/** Whether a table exists, so a source can be found with its plugin switched off. */
	public static function has_table( $name ) {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}
}
