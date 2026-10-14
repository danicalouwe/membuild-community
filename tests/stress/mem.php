<?php
/** One request in a fresh process: prints its peak memory. Args: route [key=value ...] */
global $wpdb;
wp_set_current_user( (int) get_option( 'mbcom_stress_busy_user' ) ?: 1 );
$route  = array_shift( $args );
$params = array();
foreach ( $args as $pair ) {
	list( $k, $v ) = explode( '=', $pair, 2 );
	$params[ $k ]  = $v;
}
$before = memory_get_usage();
$req    = new WP_REST_Request( 'GET', '/' . MEMBUILD_COMMUNITY_REST_NS . $route );
$req->set_query_params( $params );
$res = rest_do_request( $req );
printf( "%-40s %d  request %.1f MB, process peak %.1f MB (limit %s)\n", $route . ' ' . implode( ' ', $args ), $res->get_status(), ( memory_get_peak_usage() - $before ) / MB_IN_BYTES, memory_get_peak_usage() / MB_IN_BYTES, ini_get( 'memory_limit' ) );
