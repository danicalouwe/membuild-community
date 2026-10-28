<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Who's around right now: the green dot and "Active now".
 *
 * Only real use counts: the app reports a click, key or scroll in the last couple
 * of minutes with its pulse, so a tab left open doesn't keep anyone online.
 * "Active now" is the last 5 minutes; older than a day, nothing is shown.
 * A member can hide it (Privacy › Show when I'm online). That is fair both ways:
 * nobody sees them, and they see nobody. Owners switch the whole thing off in
 * Settings › Features (it needs Messages). Admins see the same as everyone else.
 *
 *   GET|PUT /me/privacy   { show_online }
 */
class Online {

	const SEEN   = 'mbcom_seen_at';     // when they last really used the community (timestamp)
	const HIDE   = 'mbcom_hide_online'; // '1' when they chose not to show it
	const NOW    = 5 * MINUTE_IN_SECONDS;
	const SHOWN  = DAY_IN_SECONDS;      // "Active 3 h ago" up to a day, then nothing

	public static function register_routes() {
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/me/privacy',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get' ), 'permission_callback' => 'is_user_logged_in' ),
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => 'is_user_logged_in' ),
			)
		);
	}

	/** The owner's switch: on, and Messages on too. */
	public static function enabled() {
		return Settings::feature( 'messages' ) && Settings::feature( 'online' );
	}

	public static function hides( $user_id ) {
		return '1' === (string) get_user_meta( (int) $user_id, self::HIDE, true );
	}

	/** Whether the current member sees anyone's status (not when they hide their own). */
	public static function viewer_sees() {
		return self::enabled() && is_user_logged_in() && ! self::hides( get_current_user_id() );
	}

	/** Called by the pulse when the member really did something lately (at most one write a minute). */
	public static function touch( $user_id ) {
		if ( ! $user_id ) {
			return;
		}
		if ( time() - (int) get_user_meta( $user_id, self::SEEN, true ) > MINUTE_IN_SECONDS ) {
			update_user_meta( $user_id, self::SEEN, time() );
		}
	}

	/** When the current member may see this person was last active (ISO), or null. */
	public static function active_at( $user_id ) {
		if ( ! self::viewer_sees() || self::hides( $user_id ) ) {
			return null;
		}
		$at = (int) get_user_meta( (int) $user_id, self::SEEN, true );
		return $at && time() - $at < self::SHOWN ? gmdate( 'c', $at ) : null;
	}

	/** SQL for "Online now": seen in the last 5 minutes and not hiding. */
	public static function online_sql() {
		global $wpdb;
		return $wpdb->prepare(
			" AND u.ID IN (SELECT user_id FROM %i WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d) AND u.ID NOT IN (SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = '1')",
			$wpdb->usermeta,
			self::SEEN,
			time() - self::NOW,
			$wpdb->usermeta,
			self::HIDE
		);
	}

	public static function rest_get() {
		return array(
			'show_online' => ! self::hides( get_current_user_id() ),
			'available'   => self::enabled(),
		);
	}

	public static function rest_save( \WP_REST_Request $req ) {
		if ( isset( $req['show_online'] ) ) {
			if ( rest_sanitize_boolean( $req['show_online'] ) ) {
				delete_user_meta( get_current_user_id(), self::HIDE );
			} else {
				update_user_meta( get_current_user_id(), self::HIDE, '1' );
			}
		}
		return self::rest_get();
	}
}
