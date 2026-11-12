<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Certificates: one clean certificate per member per finished course, with the member's name,
 * the course, the day they finished, the owner's logo and main color, and who signed it.
 * Settings › Certificates turns them on, for all courses or chosen ones.
 *
 * The rules agreed on 12 Nov 2026 (canvas note "proposals-quizcert-ask"):
 * - A certificate needs every lesson done, and a quiz lesson is done once passed (or finished,
 *   for "They can move on anyway"); so it comes with the course's finish (course_completed).
 * - Members who finished before certificates were on get one, dated the day they really finished.
 * - Un-marking a lesson keeps an earned certificate. A name change shows on it; the date never changes.
 * - Turning certificates off, or unticking a course, keeps earned ones and gives no new ones.
 * - An owner or moderator can remove a member's certificate. The member gets no alert, its check
 *   link then says "Not found or removed", and it never comes back on its own (the row stays).
 *
 * Members:
 *   GET    /members/{id}/certificates     the profile's list (others only when Show on profiles is on)
 *   GET    /certificates/check/{code}     one opened certificate
 *   GET    /courses/{id}/certificate      mine for a course (the Course finished window)
 * Owners and moderators:
 *   DELETE /certificates/{id}             remove it
 * Anyone, when "Let anyone check a certificate" is on:
 *   /community/certificate/{code}          the public check page (templates/certificate.php)
 */
class Certificates {

	/** Letters and digits that can't be mistaken for each other (no 0/O, 1/I/L). */
	const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

	public static function defaults() {
		return array(
			'on'          => false, // Give certificates: off until the owner turns it on (rule 4 then gives past finishers theirs)
			'which'       => 'all', // all | chosen
			'courses'     => array(), // the chosen courses' ids
			'title'       => '', // empty: "Certificate of completion"
			'signed_by'   => '', // empty: the community's host (Settings › Joining's host, else the first admin)
			'role'        => '', // empty: "Host"
			'signature'   => null, // array( 'id', 'url' ): an uploaded signature picture
			'on_profiles' => true, // Show certificates on profiles
			'checkable'   => true, // Let anyone check a certificate (the private link)
		);
	}

	public static function settings() {
		return array_merge( self::defaults(), (array) Settings::get( 'certificates' ) );
	}

	/** The cron event that gives past finishers their certificates, a batch at a time. */
	const CATCH_UP = 'mbcom_certificates_catch_up';
	/** Certificates given per batch (in the request that changed the settings, then by cron). */
	public static $batch = 200;
	/** Where the catch-up has got to in each course (course id => the last member id looked at). */
	const CATCH_UP_AFTER = 'mbcom_certificates_catch_up_after';

	public static function register_hooks() {
		add_action( 'membuild_community/course_completed', array( __CLASS__, 'give' ), 10, 2 );
		add_action( self::CATCH_UP, array( __CLASS__, 'catch_up' ) );
		// An import brings finished students: they get their certificates too.
		add_action( 'membuild_community/import_done', array( __CLASS__, 'start_catch_up' ), 10, 0 );
		add_action(
			'deleted_user',
			function ( $user_id ) {
				global $wpdb;
				$wpdb->delete( Install::table( 'certificates' ), array( 'user_id' => (int) $user_id ) );
			}
		);
	}

	public static function register_routes() {
		$member = fn() => Rest_Feed::logged_in();
		$staff  = fn() => Rest_Feed::logged_in() && Helpers::can_moderate();
		$routes = array(
			'/members/(?P<id>\d+)/certificates'   => array( array( 'GET', 'for_member', $member ) ),
			'/certificates/check/(?P<code>[A-Za-z0-9-]{8,12})' => array( array( 'GET', 'open', $member ) ),
			'/certificates/(?P<id>\d+)'            => array( array( 'DELETE', 'remove', $staff ) ),
			'/courses/(?P<id>\d+)/certificate'     => array( array( 'GET', 'for_course', $member ) ),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route(
				MEMBUILD_COMMUNITY_REST_NS,
				$path,
				array_map( fn( $h ) => array( 'methods' => $h[0], 'callback' => array( __CLASS__, $h[1] ), 'permission_callback' => $h[2] ), $handlers )
			);
		}
	}

	/* ---------- Giving ---------- */

	/** Whether a course gives certificates now: they're on, and it's all courses or one of the chosen. */
	public static function gives( $course_id ) {
		$s = self::settings();
		if ( empty( $s['on'] ) ) {
			return false;
		}
		return 'chosen' !== $s['which'] || in_array( (int) $course_id, array_map( 'intval', (array) $s['courses'] ), true );
	}

	/** Wrong codes one address may try in an hour, on the check page and the check route together. */
	const MISSES = 30;

	/** A new check code, like 7KQ29XMB4T (shown as 7KQ29-XMB4T). Codes given before were 8 long and still work. */
	private static function new_code() {
		$code = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
		}
		return $code;
	}

	public static function pretty( $code ) {
		$half = (int) ceil( strlen( $code ) / 2 );
		return substr( $code, 0, $half ) . '-' . substr( $code, $half );
	}

	/* ---------- Guessing ---------- */

	/**
	 * Who is guessing, for the wrong-code count. By default the address the request came from
	 * (REMOTE_ADDR), never a header a visitor can type in. Behind Cloudflare or another proxy every
	 * visitor shares the proxy's address; a site there can hand over the real one with the
	 * membuild_community/visitor_ip filter. IPv6 addresses count by their /64 (one home or server).
	 */
	public static function visitor_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The visitor's real address, for limits on guessing. Return it from your proxy's own
		 * header only when every request really comes through that proxy.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip     = trim( (string) apply_filters( 'membuild_community/visitor_ip', $ip ) );
		$packed = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? inet_pton( $ip ) : false;
		if ( false !== $packed && 16 === strlen( $packed ) ) {
			return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
		}
		return $ip;
	}

	private static function miss_key() {
		return 'mbcom_certmiss_' . md5( self::visitor_ip() );
	}

	/** Whether this address has used up its wrong codes for the hour. Then wrong codes say so; real ones still open. */
	public static function limited() {
		return (int) get_transient( self::miss_key() ) >= self::MISSES;
	}

	/** One more wrong code from this address. The hour starts at the first one. */
	private static function missed() {
		$key  = self::miss_key();
		$n    = (int) get_transient( $key );
		$ends = (int) get_option( '_transient_timeout_' . $key ) ?: time() + HOUR_IN_SECONDS;
		set_transient( $key, $n + 1, max( 60, $ends - time() ) );
	}

	/** "7kq2 9xmb", "7KQ2-9XMB" → "7KQ29XMB". */
	public static function normalize( $code ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
	}

	/**
	 * Gives a member their certificate for a course they finished, once. Nothing happens when the
	 * course doesn't give certificates now, or there's a row already (one that was removed stays removed).
	 *
	 * @param int         $course_id   The course.
	 * @param int         $user_id     The member.
	 * @param string|null $finished_at When they really finished (UTC, MySQL); now when null.
	 */
	public static function give( $course_id, $user_id, $finished_at = null ) {
		global $wpdb;
		$course_id = (int) $course_id;
		$user_id   = (int) $user_id;
		if ( ! $user_id || ! self::gives( $course_id ) ) {
			return false;
		}
		// An owner finishing a course in "See it as a member" (a quiz passed, or Mark Complete) is only trying it out.
		if ( $user_id === get_current_user_id() && Helpers::as_member() ) {
			return false;
		}
		$course = $wpdb->get_row( $wpdb->prepare( 'SELECT id, title, status FROM %i WHERE id = %d', Install::table( 'courses' ), $course_id ), ARRAY_A );
		if ( ! $course || 'published' !== $course['status'] ) {
			return false;
		}
		$table   = Install::table( 'certificates' );
		$lessons = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $course_id ) );
		for ( $try = 0; $try < 5; $try++ ) {
			$added = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (user_id, course_id, code, course_title, lessons, finished_at, status, created_at) VALUES (%d, %d, %s, %s, %d, %s, %s, %s)',
					$table,
					$user_id,
					$course_id,
					self::new_code(),
					$course['title'],
					$lessons,
					$finished_at ?: Helpers::now(),
					'valid',
					Helpers::now()
				)
			);
			if ( $added ) {
				/**
				 * A member was given a certificate.
				 *
				 * @param int $course_id The course.
				 * @param int $user_id   The member.
				 */
				do_action( 'membuild_community/certificate_given', $course_id, $user_id );
				return true;
			}
			// Ignored: they have one for this course already (nothing to do), or the code was taken (try another).
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d AND course_id = %d', $table, $user_id, $course_id ) ) ) {
				return false;
			}
		}
		return false;
	}

	/** What decides who gets certificates: on, all or chosen, and which courses. Other changes need no catch-up. */
	public static function giving_key( array $c ) {
		$courses = array_map( 'intval', (array) ( $c['courses'] ?? array() ) );
		sort( $courses );
		return wp_json_encode( array( ! empty( $c['on'] ), 'chosen' === ( $c['which'] ?? 'all' ) ? 'chosen' : 'all', 'chosen' === ( $c['which'] ?? 'all' ) ? $courses : array() ) );
	}

	/** A new catch-up from the start of every course (who gets certificates changed, or an import). */
	public static function start_catch_up() {
		delete_option( self::CATCH_UP_AFTER );
		return self::catch_up();
	}

	/**
	 * Rule 4: members who finished a course before it gave certificates get theirs, dated the day
	 * they really finished (their last lesson). Runs when who gets certificates changes and after an
	 * import: one batch ($batch) now, and the rest by cron, a batch at a time, until none are left.
	 * Finished: every lesson the course has now is done. Removed certificates stay removed.
	 *
	 * Each batch goes on from the last member looked at in each course, so a finisher who can't be
	 * given one (an owner in See it as a member, say) is passed over, not picked again every batch.
	 * Every full batch moves on by $batch members, so it always comes to an end.
	 * Returns how many this batch gave.
	 */
	public static function catch_up() {
		global $wpdb;
		if ( empty( self::settings()['on'] ) ) {
			delete_option( self::CATCH_UP_AFTER );
			return 0;
		}
		$given   = 0;
		$left    = max( 1, (int) self::$batch );
		$after   = (array) get_option( self::CATCH_UP_AFTER, array() );
		$courses = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'published' ORDER BY id", Install::table( 'courses' ) ) );
		foreach ( array_map( 'intval', $courses ) as $course_id ) {
			if ( $left <= 0 ) {
				break;
			}
			if ( ! self::gives( $course_id ) ) {
				continue;
			}
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', Install::table( 'lessons' ), $course_id ) );
			if ( ! $total ) {
				continue;
			}
			// Finishers without a certificate row (given or removed), after the last member looked at.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT p.user_id, MAX(p.completed_at) AS finished FROM %i p JOIN %i l ON l.id = p.lesson_id JOIN %i u ON u.ID = p.user_id
					LEFT JOIN %i c ON c.user_id = p.user_id AND c.course_id = p.course_id
					WHERE p.course_id = %d AND p.user_id > %d AND c.id IS NULL GROUP BY p.user_id HAVING COUNT(DISTINCT p.lesson_id) >= %d ORDER BY p.user_id LIMIT %d',
					Install::table( 'lesson_progress' ),
					Install::table( 'lessons' ),
					$wpdb->users,
					Install::table( 'certificates' ),
					$course_id,
					(int) ( $after[ $course_id ] ?? 0 ),
					$total,
					$left
				),
				ARRAY_A
			);
			$left -= count( $rows );
			foreach ( $rows as $r ) {
				$given              += self::give( $course_id, (int) $r['user_id'], $r['finished'] ) ? 1 : 0;
				$after[ $course_id ] = (int) $r['user_id'];
			}
		}
		// A full batch: there may be more. Carry on by cron from where this one stopped.
		if ( $left <= 0 ) {
			update_option( self::CATCH_UP_AFTER, $after, false );
			if ( ! wp_next_scheduled( self::CATCH_UP ) ) {
				wp_schedule_single_event( time() + 10, self::CATCH_UP );
			}
		} else {
			delete_option( self::CATCH_UP_AFTER ); // every course looked at to the end
		}
		return $given;
	}

	/* ---------- Reading ---------- */

	private static function row_by_code( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE code = %s', Install::table( 'certificates' ), self::normalize( $code ) ), ARRAY_A );
	}

	/** The certificate as the app shows it: what's on it, and what this viewer may do with it. */
	public static function format( array $row ) {
		global $wpdb;
		$s      = self::settings();
		$user   = get_userdata( (int) $row['user_id'] );
		$course = $wpdb->get_row( $wpdb->prepare( 'SELECT title FROM %i WHERE id = %d', Install::table( 'courses' ), $row['course_id'] ), ARRAY_A );
		$mine   = (int) $row['user_id'] === get_current_user_id();
		$out    = array(
			'id'          => (int) $row['id'],
			'course_id'   => (int) $row['course_id'],
			'course'      => $course['title'] ?? $row['course_title'], // the course's name today, or as it was if it was deleted
			'lessons'     => (int) $row['lessons'],
			'name'        => $user ? $user->display_name : '', // rule 7: the name as it is today
			'member_id'   => (int) $row['user_id'],
			'finished_at' => Helpers::iso( $row['finished_at'] ),
			'finished_on' => wp_date( 'Y-m-d', strtotime( $row['finished_at'] . ' UTC' ) ), // the site's calendar day, the same everywhere
			'mine'        => $mine,
			'can_remove'  => Helpers::can_moderate(),
		) + self::look();
		// The check link: for the member's own certificate, when anyone may check them.
		if ( $mine && ! empty( $s['checkable'] ) ) {
			$out['code']      = self::pretty( $row['code'] );
			$out['check_url'] = self::check_url( $row['code'] );
		}
		/**
		 * The certificate as the app (and its PDF) shows it.
		 *
		 * @param array $out The certificate.
		 * @param array $row Its database row.
		 */
		return apply_filters( 'membuild_community/certificate', $out, $row );
	}

	/** What every certificate shares: the community, its logo and color, the title and who signed it. */
	public static function look() {
		$s    = self::settings();
		$host = Login::host();
		return array(
			'community' => Settings::name(),
			'logo'      => Settings::get( 'logo' )['url'] ?? null,
			'initials'  => Settings::initials(),
			'accent'    => Settings::get( 'accent' ) ?: '',
			'title'     => trim( (string) $s['title'] ) ?: __( 'Certificate of completion', 'membuild-community' ),
			'signed_by' => trim( (string) $s['signed_by'] ) ?: (string) ( $host['name'] ?? '' ),
			'role'      => trim( (string) $s['role'] ) ?: __( 'Host', 'membuild-community' ),
			'signature' => $s['signature']['url'] ?? null,
		);
	}

	public static function check_url( $code ) {
		return home_url( '/' . Settings::slug() . '/certificate/' . self::pretty( self::normalize( $code ) ) );
	}

	/** Whether the current member may see this member's certificates: their own, staff, or Show on profiles. */
	private static function can_see( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id === get_current_user_id() ) {
			return true;
		}
		// As for their profile (Rest_Members): a member of this site, and not hidden by a block either way.
		if ( ! self::on_site( $user_id ) || ( Blocks::hides( $user_id ) && ! Blocks::i_blocked( $user_id ) ) ) {
			return false;
		}
		return Helpers::can_moderate() || ! empty( self::settings()['on_profiles'] );
	}

	/** The account exists and, on a multisite network, belongs to this site. */
	private static function on_site( $user_id ) {
		return get_userdata( (int) $user_id ) && ( ! is_multisite() || is_user_member_of_blog( (int) $user_id ) );
	}

	public static function for_member( \WP_REST_Request $req ) {
		global $wpdb;
		$user_id = (int) $req['id'];
		if ( ! self::on_site( $user_id ) || ! self::can_see( $user_id ) ) {
			return array( 'items' => array() );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND status = 'valid' ORDER BY finished_at DESC, id DESC", Install::table( 'certificates' ), $user_id ), ARRAY_A );
		return array( 'items' => array_map( array( __CLASS__, 'format' ), $rows ) );
	}

	public static function open( \WP_REST_Request $req ) {
		$row = self::row_by_code( (string) $req['code'] );
		// A code that exists is always answered; only wrong ones count, and are refused after 30 in an hour.
		if ( ! $row ) {
			if ( self::limited() && ! Helpers::can_moderate() ) {
				return new \WP_Error( 'mbcom_rate_limited', __( 'Too many tries. Try again in an hour.', 'membuild-community' ), array( 'status' => 429 ) );
			}
			self::missed();
		}
		if ( ! $row || 'valid' !== $row['status'] || ! self::can_see( (int) $row['user_id'] ) ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Certificate not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		return self::format( $row );
	}

	/** The current member's valid certificate for a course, formatted, or null. */
	public static function mine( $course_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND course_id = %d AND status = 'valid'", Install::table( 'certificates' ), get_current_user_id(), $course_id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	public static function for_course( \WP_REST_Request $req ) {
		return array( 'certificate' => self::mine( (int) $req['id'] ) );
	}

	/** An owner or moderator removes a member's certificate. No alert to the member; it stays removed. */
	public static function remove( \WP_REST_Request $req ) {
		global $wpdb;
		$table = Install::table( 'certificates' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $req['id'] ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'mbcom_not_found', __( 'Certificate not found.', 'membuild-community' ), array( 'status' => 404 ) );
		}
		if ( 'valid' === $row['status'] ) {
			$wpdb->update( $table, array( 'status' => 'removed', 'removed_at' => Helpers::now(), 'removed_by' => get_current_user_id() ), array( 'id' => (int) $row['id'] ) );
			/**
			 * An owner or moderator removed a member's certificate.
			 *
			 * @param int $course_id The course.
			 * @param int $user_id   The member.
			 * @param int $by        Who removed it.
			 */
			do_action( 'membuild_community/certificate_removed', (int) $row['course_id'], (int) $row['user_id'], get_current_user_id() );
		}
		return array( 'removed' => true );
	}

	/* ---------- The public check page ---------- */

	/**
	 * /community/certificate/{code}, for anyone with the link (no login, no community menu): the
	 * name, course, day and who gave it when it's valid, else "Not found or removed" with nothing
	 * else. Off ("Let anyone check a certificate"): every link says not found.
	 */
	public static function maybe_check_page( $path ) {
		if ( ! preg_match( '#^certificate/([A-Za-z0-9-]{1,20})$#', $path, $m ) ) {
			return;
		}
		Helpers::no_page_cache();
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex' );
		list( $status, $html ) = self::check_page( $m[1] );
		status_header( $status );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes everything it prints
		exit;
	}

	/** The check page for a code: array( HTTP status, the page's HTML ). */
	public static function check_page( $raw_code ) {
		// A code that exists is always answered. One that matches nothing (guessing, or copied
		// wrong) counts as a miss, and after 30 in an hour from this address it says Too many tries.
		$row     = self::row_by_code( $raw_code );
		$limited = ! $row && self::limited();
		if ( ! $row && ! $limited ) {
			self::missed();
		}
		$ok   = $row && 'valid' === $row['status'] && ! empty( self::settings()['checkable'] ) && self::on_site( (int) $row['user_id'] );
		$cert = $ok ? self::format( $row ) : null;
		$look   = self::look();
		$scheme = Login::scheme();
		$code   = $ok ? self::pretty( $row['code'] ) : '';
		$tokens = Color::tokens( Settings::get( 'accent' ) );
		ob_start();
		include MEMBUILD_COMMUNITY_DIR . 'templates/certificate.php';
		return array( $ok ? 200 : ( $limited ? 429 : 404 ), (string) ob_get_clean() );
	}
}
