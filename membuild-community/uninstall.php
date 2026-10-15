<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Community data (posts, members' messages, courses…) is only removed when an
 * admin turned on "Delete all community data when the plugin is deleted" in
 * Settings, so deleting the plugin by mistake never wipes a community.
 * On a multisite network each site follows its own setting.
 * Videos stored on Bunny Stream stay in the Bunny account.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-install.php';

/** Cleans up the current site. */
function mbcom_uninstall_site() {
	global $wpdb;

	foreach ( array( MemBuild\Community\Install::CRON_HOOK, 'mbcom_send_emails', 'mbcom_migrate_private_files', 'mbcom_encrypt_messages', 'mbcom_usage_report', 'mbcom_search_index' ) as $mbcom_hook ) {
		wp_clear_scheduled_hook( $mbcom_hook );
	}

	delete_option( 'mbcom_usage' ); // the anonymous report's ID and answers; a reinstall starts fresh

	$mbcom_settings = get_option( 'mbcom_settings', array() );
	if ( empty( $mbcom_settings['delete_data'] ) ) {
		return;
	}

	// Uploaded photos, videos and files are WordPress attachments; delete them with their files.
	$mbcom_media = $wpdb->prefix . 'mbcom_media';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mbcom_media ) ) === $mbcom_media ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM %i WHERE attachment_id IS NOT NULL', $mbcom_media ) ) as $mbcom_attachment ) {
			wp_delete_attachment( (int) $mbcom_attachment, true );
		}
	}
	delete_post_meta_by_key( '_mbcom_private' ); // anything the media table didn't track (seeded demo files, icons)

	foreach ( MemBuild\Community\Install::TABLES as $mbcom_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'mbcom_' . $mbcom_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall, only when the admin opted in.
	}

	foreach ( array( 'mbcom_bio', 'mbcom_avatar_url', 'mbcom_restriction', 'mbcom_email_prefs', 'mbcom_email_last', 'mbcom_last_active' ) as $mbcom_meta ) {
		delete_metadata( 'user', 0, $mbcom_meta, '', true );
	}

	// The encryption key goes too: with the messages deleted it protects nothing.
	// On a network it's shared by every site, so it stays.
	$mbcom_key = trailingslashit( WP_CONTENT_DIR ) . 'mbcom-key.php';
	if ( ! is_multisite() && file_exists( $mbcom_key ) ) {
		wp_delete_file( $mbcom_key );
	}

	foreach ( array( 'mbcom_settings', 'mbcom_db_version', 'mbcom_files_migration', 'mbcom_key', 'mbcom_key_check', 'mbcom_key_saved', 'mbcom_search_index' ) as $mbcom_option ) {
		delete_option( $mbcom_option );
	}
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_mbcom_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mbcom_' ) . '%'
		)
	);

	// Generated app icons, protected files and leftover upload pieces.
	$mbcom_uploads = wp_upload_dir();
	foreach ( array( 'mbcom-icons', 'mbcom-private', 'mbcom-tmp' ) as $mbcom_dir ) {
		$mbcom_path = trailingslashit( $mbcom_uploads['basedir'] ) . $mbcom_dir;
		if ( is_dir( $mbcom_path ) ) {
			foreach ( array_merge( glob( $mbcom_path . '/*' ) ?: array(), glob( $mbcom_path . '/.htaccess' ) ?: array() ) as $mbcom_file ) {
				wp_delete_file( $mbcom_file );
			}
			rmdir( $mbcom_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	flush_rewrite_rules( false );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mbcom_site ) {
		switch_to_blog( $mbcom_site );
		mbcom_uninstall_site();
		restore_current_blog();
	}
} else {
	mbcom_uninstall_site();
}
