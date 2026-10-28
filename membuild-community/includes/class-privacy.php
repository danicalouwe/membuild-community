<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The community in WordPress's privacy tools (Tools → Export / Erase Personal
 * Data), and suggested text for the site's privacy policy.
 *
 * Exports include a member's profile, posts, comments, course progress and
 * points. Private message text is left out, consistent with admins never
 * seeing messages; the export says how many there are.
 * Erasing removes everything the member created in the community.
 */
class Privacy {

	const BATCH = 200;

	public static function register_hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function exporters( $list ) {
		$list['membuild-community'] = array(
			'exporter_friendly_name' => __( 'Community', 'membuild-community' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ) {
		$list['membuild-community'] = array(
			'eraser_friendly_name' => __( 'Community', 'membuild-community' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/* ---------- Export ---------- */

	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$id    = (int) $user->ID;
		$items = array();

		if ( 1 === (int) $page ) {
			$stats   = Points::stats_for( array( $id ) )[ $id ] ?? array( 'points' => 0, 'level' => 1 );
			$sent    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND status = 'sent'", Install::table( 'messages' ), $id ) );
			$items[] = array(
				'group_id'    => 'mbcom-profile',
				'group_label' => __( 'Community profile', 'membuild-community' ),
				'item_id'     => 'mbcom-profile-' . $id,
				'data'        => array(
					array( 'name' => __( 'Bio', 'membuild-community' ), 'value' => (string) get_user_meta( $id, 'mbcom_bio', true ) ),
					array( 'name' => __( 'Profile photo', 'membuild-community' ), 'value' => (string) get_user_meta( $id, 'mbcom_avatar_url', true ) ),
					array( 'name' => __( 'Cover picture', 'membuild-community' ), 'value' => (string) get_user_meta( $id, 'mbcom_cover_url', true ) ),
					array( 'name' => __( 'Where you live', 'membuild-community' ), 'value' => (string) get_user_meta( $id, 'mbcom_location', true ) ),
					array( 'name' => __( 'Shown on the map at', 'membuild-community' ), 'value' => (string) ( get_user_meta( $id, Rest_Places::MAP_META, true )['city'] ?? '' ) ),
					array( 'name' => __( 'Level', 'membuild-community' ), 'value' => (string) $stats['level'] ),
					array( 'name' => __( 'Points', 'membuild-community' ), 'value' => (string) $stats['points'] ),
					array( 'name' => __( 'Private messages sent', 'membuild-community' ), 'value' => (string) $sent ),
					array( 'name' => __( 'Email preferences', 'membuild-community' ), 'value' => wp_json_encode( Email::prefs( $id ) ) ),
					array( 'name' => __( 'Member groups', 'membuild-community' ), 'value' => implode( ', ', array_map( fn( $g ) => Access::groups()[ $g ]['name'] ?? '', Access::member_groups( $id ) ) ) ),
				),
			);
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT p.completed_at, c.title course, l.title lesson FROM %i p LEFT JOIN %i c ON c.id = p.course_id LEFT JOIN %i l ON l.id = p.lesson_id WHERE p.user_id = %d', Install::table( 'lesson_progress' ), Install::table( 'courses' ), Install::table( 'lessons' ), $id ), ARRAY_A ) as $i => $r ) {
				$items[] = array(
					'group_id'    => 'mbcom-progress',
					'group_label' => __( 'Community course progress', 'membuild-community' ),
					'item_id'     => 'mbcom-progress-' . $i,
					'data'        => array(
						array( 'name' => __( 'Course', 'membuild-community' ), 'value' => (string) $r['course'] ),
						array( 'name' => __( 'Lesson', 'membuild-community' ), 'value' => (string) $r['lesson'] ),
						array( 'name' => __( 'Finished', 'membuild-community' ), 'value' => (string) $r['completed_at'] ),
					),
				);
			}
		}

		// Posts and comments, a batch per page.
		$offset   = ( (int) $page - 1 ) * self::BATCH;
		$posts    = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, content, created_at FROM %i WHERE user_id = %d AND status <> 'deleted' ORDER BY id LIMIT %d OFFSET %d", Install::table( 'posts' ), $id, self::BATCH, $offset ), ARRAY_A );
		$comments = $wpdb->get_results( $wpdb->prepare( 'SELECT id, post_id, content, image_url, created_at FROM %i WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d', Install::table( 'comments' ), $id, self::BATCH, $offset ), ARRAY_A );
		$media    = Media::for_posts( wp_list_pluck( $posts, 'id' ) );
		foreach ( $posts as $p ) {
			$items[] = array(
				'group_id'    => 'mbcom-posts',
				'group_label' => __( 'Community posts', 'membuild-community' ),
				'item_id'     => 'mbcom-post-' . $p['id'],
				'data'        => array(
					array( 'name' => __( 'Type', 'membuild-community' ), 'value' => (string) $p['type'] ),
					array( 'name' => __( 'Text', 'membuild-community' ), 'value' => (string) $p['content'] ),
					array( 'name' => __( 'Photos and videos', 'membuild-community' ), 'value' => implode( "\n", wp_list_pluck( $media[ (int) $p['id'] ] ?? array(), 'url' ) ) ),
					array( 'name' => __( 'Posted', 'membuild-community' ), 'value' => (string) $p['created_at'] ),
				),
			);
		}
		foreach ( $comments as $c ) {
			$items[] = array(
				'group_id'    => 'mbcom-comments',
				'group_label' => __( 'Community comments', 'membuild-community' ),
				'item_id'     => 'mbcom-comment-' . $c['id'],
				'data'        => array(
					array( 'name' => __( 'On post', 'membuild-community' ), 'value' => (string) $c['post_id'] ),
					array( 'name' => __( 'Text', 'membuild-community' ), 'value' => (string) $c['content'] ),
					array( 'name' => __( 'Photo', 'membuild-community' ), 'value' => (string) $c['image_url'] ),
					array( 'name' => __( 'Written', 'membuild-community' ), 'value' => (string) $c['created_at'] ),
				),
			);
		}
		$done = count( $posts ) < self::BATCH && count( $comments ) < self::BATCH;
		return array( 'data' => $items, 'done' => $done );
	}

	/* ---------- Erase ---------- */

	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		$none = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		if ( ! $user ) {
			return $none;
		}
		$id      = (int) $user->ID;
		$removed = 0;

		// Posts first (taking their points back), then everything else in batches.
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE user_id = %d AND status <> 'deleted' LIMIT %d", Install::table( 'posts' ), $id, self::BATCH ) ) as $post_id ) {
			Rest_Feed::remove_post( (int) $post_id, true );
			++$removed;
		}
		$comments = Install::table( 'comments' );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, replies_count FROM %i WHERE user_id = %d AND user_id <> 0 LIMIT %d', $comments, $id, self::BATCH ), ARRAY_A ) as $c ) {
			if ( (int) $c['replies_count'] > 0 ) {
				// Other people replied: keep their replies, remove this member's words and name.
				$wpdb->update( $comments, array( 'content' => __( '(comment removed)', 'membuild-community' ), 'image_url' => null, 'user_id' => 0 ), array( 'id' => (int) $c['id'] ) );
			} else {
				// The normal delete keeps comment counts and points right.
				rest_do_request( new \WP_REST_Request( 'DELETE', '/' . MEMBUILD_COMMUNITY_REST_NS . '/comments/' . (int) $c['id'] ) );
			}
			++$removed;
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d LIMIT %d', Install::table( 'media' ), $id, self::BATCH ) ) as $media_id ) {
			Media::delete( (int) $media_id );
			++$removed;
		}

		$more = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE user_id = %d AND status <> 'deleted' LIMIT 1", Install::table( 'posts' ), $id ) )
			|| (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d LIMIT 1', $comments, $id ) )
			|| (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d LIMIT 1', Install::table( 'media' ), $id ) );
		if ( $more ) {
			return array( 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => array(), 'done' => false );
		}

		// Last pass: messages (text removed, the conversation's shape kept for the other people), stories, reactions and the rest.
		$removed += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET content = NULL, media = NULL, story = NULL, status = 'unsent' WHERE user_id = %d AND status <> 'unsent'", Install::table( 'messages' ), $id ) );
		$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Install::table( 'stories' ), $id ) );
		foreach ( array( 'reactions', 'story_views', 'notifications', 'lesson_progress', 'course_starts', 'points', 'member_stats', 'group_members', 'place_news' ) as $table ) {
			$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Install::table( $table ), $id ) );
		}
		$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE reporter_id = %d', Install::table( 'reports' ), $id ) );
		$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d OR blocked_id = %d', Install::table( 'blocks' ), $id, $id ) );
		foreach ( array( 'mbcom_bio', 'mbcom_avatar_url', 'mbcom_cover_url', 'mbcom_location', Rest_Places::MAP_META, 'mbcom_seen_at', 'mbcom_hide_online', 'mbcom_email_prefs', 'mbcom_email_last', 'mbcom_last_active', Moderation::META ) as $key ) {
			$removed += delete_user_meta( $id, $key ) ? 1 : 0;
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array( __( 'Community: the member’s posts, comments, uploads, stories, reactions, points and profile were removed. Their messages were replaced with “Message unsent” so the other people’s side of each chat still makes sense.', 'membuild-community' ) ),
			'done'           => true,
		);
	}

	/* ---------- Privacy policy ---------- */

	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for the community. Edit it to match how you run your community.', 'membuild-community' ) . '</p>'
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'membuild-community' ) . '</strong> '
			. esc_html__( 'When you take part in our community we store what you share there: your profile, posts, comments, reactions, stories, messages, course progress and points. Messages are private between the people in a chat and are stored encrypted; site admins can see how many messages there are, but not what they say, and message text is never included in emails. If you report a post or comment, moderators see your report and note. You can ask us for a copy of your data or ask us to erase it.', 'membuild-community' )
			. '</p><p>' . esc_html__( 'If a member posts a YouTube or Vimeo link, the site fetches the video’s public details from that service and the video plays in their player. If email notifications are on, your email address and the notification are sent through the site’s email service. If Bunny Stream is turned on, videos you upload are stored with Bunny.net. Members without their own profile photo are shown with their Gravatar, which sends a hashed form of their email address to Gravatar.com.', 'membuild-community' ) . '</p>';
		wp_add_privacy_policy_content( __( 'MemBuild - Community', 'membuild-community' ), wp_kses_post( $text ) );
	}
}
