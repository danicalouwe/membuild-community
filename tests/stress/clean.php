<?php
/**
 * Removes everything tests/stress/seed.php added to the tests site. Every
 * seeded row belongs to a stress member, so each table is cleared with a join
 * on those members (indexed), never a nested IN (...) over millions of rows.
 */
use MemBuild\Community\Install;

global $wpdb;
$t     = fn( $name ) => Install::table( $name );
$range = $wpdb->get_row( "SELECT MIN(ID) lo, MAX(ID) hi FROM {$wpdb->users} WHERE user_login LIKE 'stress\\_%'" );
if ( ! $range->lo ) {
	exit( "Nothing to clean.\n" );
}

$wpdb->query( "DELETE m FROM {$t('messages')} m JOIN {$t('threads')} th ON th.id = m.thread_id WHERE th.direct_key LIKE 'stress-%'" );
$wpdb->query( "DELETE tm FROM {$t('thread_members')} tm JOIN {$t('threads')} th ON th.id = tm.thread_id WHERE th.direct_key LIKE 'stress-%'" );
$wpdb->query( "DELETE FROM {$t('threads')} WHERE direct_key LIKE 'stress-%'" );
foreach ( array( 'reactions', 'comments', 'posts', 'points', 'member_stats', 'notifications', 'thread_members' ) as $table ) {
	// A thousand members at a time, so no single statement holds locks for minutes.
	for ( $lo = (int) $range->lo; $lo <= (int) $range->hi; $lo += 1000 ) {
		$wpdb->query( $wpdb->prepare( "DELETE x FROM %i x JOIN {$wpdb->users} u ON u.ID = x.user_id WHERE x.user_id BETWEEN %d AND %d AND u.user_login LIKE 'stress\\_%%'", $t( $table ), $lo, $lo + 999 ) );
	}
}
$wpdb->query( "DELETE um FROM {$wpdb->usermeta} um JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.user_login LIKE 'stress\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->users} WHERE user_login LIKE 'stress\\_%'" );
delete_option( 'mbcom_stress_busy_user' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%mbcom\\_%'" );
wp_cache_flush();
echo "Stress data removed.\n";
