<?php
/**
 * On a multisite network: a member of another site must not show up in this
 * site's member directory or admin screens. Run after `wp core multisite-convert`.
 */
use MemBuild\Community\Admin;

global $wpdb;
$check = function ( $label, $ok ) {
	echo ( $ok ? '  ✓ ' : '  ✗ ' ), $label, "\n";
};
$here  = (int) wp_insert_user( array( 'user_login' => 'ms_here', 'user_pass' => wp_generate_password(), 'user_email' => 'ms_here@example.test', 'display_name' => 'Here Member', 'role' => 'subscriber' ) );
$other = wpmu_create_blog( 'localhost', '/othersite/', 'Other site', 1 );
$there = (int) wp_insert_user( array( 'user_login' => 'ms_there', 'user_pass' => wp_generate_password(), 'user_email' => 'ms_there@example.test', 'display_name' => 'Elsewhere Member', 'role' => '' ) );
remove_user_from_blog( $there, get_current_blog_id() );
add_user_to_blog( $other, $there, 'subscriber' );

wp_set_current_user( $here );
$names = function ( $params ) {
	$req = new WP_REST_Request( 'GET', '/' . MEMBUILD_COMMUNITY_REST_NS . '/members' );
	$req->set_query_params( $params );
	return wp_list_pluck( rest_do_request( $req )->get_data()['items'] ?? array(), 'name' );
};
foreach ( array( 'active', 'newest', 'level' ) as $sort ) {
	$list = $names( array( 'sort' => $sort ) );
	$check( "directory ({$sort}) lists this site's member", in_array( 'Here Member', $list, true ) );
	$check( "…but not another site's member", ! in_array( 'Elsewhere Member', $list, true ) );
}
$check( 'search doesn’t find another site’s member', ! in_array( 'Elsewhere Member', $names( array( 'search' => 'Elsewhere' ) ), true ) );

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once WP_PLUGIN_DIR . '/membuild-community/includes/admin/class-admin.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
wp_set_current_user( 1 );
set_current_screen( 'dashboard' );
ob_start();
Admin::page_table( 'members' );
$html = ob_get_clean();
$check( 'admin Members screen shows this site’s member', false !== strpos( $html, 'Here Member' ) );
$check( '…but not another site’s member', false === strpos( $html, 'Elsewhere Member' ) );
