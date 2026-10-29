<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The first days of a community.
 *
 * Owner: six steps to get ready (look, email, safety key, first course, welcome post,
 * invite), shown as the setup guide in wp-admin and as "Finish setting up" on Home.
 * Each step is done when the thing itself is done, so there's nothing to tick by hand;
 * the list hides itself once every step is done (the optional course doesn't hold it up).
 *
 * New member: "Welcome, Olivia!" with four ways to start (joined, say hello, start a
 * course, add a photo), for their first 30 days, until done or hidden.
 *
 *   POST /first-run/invited   the owner copied the join link or the message
 *   POST /first-run/hide      { list: member }
 */
class First_Run {

	const INVITED  = 'mbcom_invited';       // option: when the owner first copied the join link
	const WELCOMED = 'mbcom_welcome_hidden'; // user meta: the member hid their welcome list
	const NEW_FOR  = 30 * DAY_IN_SECONDS;

	public static function register_routes() {
		$admin  = fn() => is_user_logged_in() && Helpers::can_manage();
		$member = array( 'MemBuild\Community\Rest_Feed', 'logged_in' );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/first-run/invited', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'invited' ), 'permission_callback' => $admin ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/first-run/hide', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'hide' ), 'permission_callback' => $member ) );
	}

	/** The owner's six steps: key => done. */
	public static function owner_steps() {
		global $wpdb;
		$posts = Install::table( 'posts' );
		$email = Email::settings();
		$staff = array_map( 'intval', get_users( array( 'capability' => 'moderate_comments', 'fields' => 'ID', 'number' => 50 ) ) );
		$by    = implode( ',', $staff ?: array( 0 ) );
		return array(
			'look'   => (bool) get_option( Settings::OPTION ), // Settings saved once
			'email'  => 'default' !== $email['mode'] || (bool) Email::smtp_plugin() || (bool) get_option( 'mbcom_test_email_ok' ),
			'key'    => ! Vault::ready() || 'constant' === Vault::key_source() || (bool) get_option( Vault::SAVED_OPTION ), // same rule as the old reminder card
			'course' => (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', Install::table( 'courses' ) ) ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $by is a list of integers.
			'post'   => (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'published' AND course_id = 0 AND event_at IS NULL AND user_id IN ({$by}) LIMIT 1", $posts ) ),
			'invite' => (bool) get_option( self::INVITED ) || Helpers::member_total() > count( $staff ),
		);
	}

	/** Every step but the optional course is done. */
	public static function owner_done() {
		$steps = self::owner_steps();
		unset( $steps['course'] );
		return ! in_array( false, $steps, true );
	}

	/** A new member's four ways to start: key => done. Null when the list doesn't apply. */
	public static function member_steps( $user_id ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		if ( ! $user || Settings::is_staff( $user_id ) || get_user_meta( $user_id, self::WELCOMED, true ) || time() - strtotime( $user->user_registered . ' UTC' ) > self::NEW_FOR ) {
			return null;
		}
		$steps = array(
			'joined' => true,
			'hello'  => (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE user_id = %d AND status = 'published' LIMIT 1", Install::table( 'posts' ), $user_id ) )
				|| (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d LIMIT 1', Install::table( 'comments' ), $user_id ) ),
		);
		if ( Settings::feature( 'classroom' ) ) {
			$steps['course'] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE user_id = %d LIMIT 1', Install::table( 'lesson_progress' ), $user_id ) );
		}
		$steps['photo'] = (bool) get_user_meta( $user_id, 'mbcom_avatar_url', true );
		return in_array( false, $steps, true ) ? $steps : null; // all done: nothing to show
	}

	/** For the app: what Home shows on day one. */
	public static function config() {
		$me = get_current_user_id();
		return array(
			'owner'  => Helpers::can_manage() && ! self::owner_done() ? self::owner_steps() : null,
			'member' => self::member_steps( $me ),
			'joinUrl' => Login::registration_open() ? Login::url( 'join' ) : ( Settings::get( 'signup_url' ) ?: '' ),
		);
	}

	public static function invited() {
		if ( ! get_option( self::INVITED ) ) {
			update_option( self::INVITED, time(), false );
		}
		return self::config();
	}

	public static function hide() {
		update_user_meta( get_current_user_id(), self::WELCOMED, time() );
		return self::config();
	}
}
