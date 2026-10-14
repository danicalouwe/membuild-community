<?php
/**
 * Times the busiest endpoints as a member of a large community (after seed.php):
 * server time, database queries and their total time, and peak memory, each the
 * median of a few runs with the object cache flushed (a host without Redis).
 */
use MemBuild\Community\Install;

global $wpdb;
define( 'SAVEQUERIES', true );
$cold = in_array( 'cold', $args ?? array(), true ); // also drop saved results, as on the first visit after they expire
$busy = (int) get_option( 'mbcom_stress_busy_user' );
wp_set_current_user( $busy );

$post   = (int) $wpdb->get_var( 'SELECT post_id FROM ' . Install::table( 'comments' ) . ' GROUP BY post_id ORDER BY COUNT(*) DESC LIMIT 1' );
$thread = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT thread_id FROM %i WHERE user_id = %d LIMIT 1', Install::table( 'thread_members' ), $busy ) );
if ( ! $thread ) {
	$thread = (int) $wpdb->get_var( 'SELECT thread_id FROM ' . Install::table( 'messages' ) . ' GROUP BY thread_id ORDER BY COUNT(*) DESC LIMIT 1' );
	$wpdb->insert( Install::table( 'thread_members' ), array( 'thread_id' => $thread, 'user_id' => $busy, 'joined_at' => current_time( 'mysql', true ) ) );
}
$deep = (int) $wpdb->get_var( 'SELECT id FROM ' . Install::table( 'posts' ) . ' ORDER BY created_at ASC, id ASC LIMIT 1' );

$cases = array(
	'feed (latest)'          => array( '/feed', array() ),
	'feed (hot)'             => array( '/feed', array( 'sort' => 'hot' ) ),
	'feed page 50'           => array( '/feed', array( 'page' => 50 ) ),
	'feed by author'         => array( '/feed', array( 'author' => $busy + 7 ) ),
	'new posts pulse'        => array( '/feed/new', array( 'since' => (int) $wpdb->get_var( 'SELECT MAX(id) - 50 FROM ' . Install::table( 'posts' ) ) ) ),
	'post'                   => array( "/posts/{$post}", array() ),
	'post comments'          => array( "/posts/{$post}/comments", array() ),
	'post reactions'         => array( "/posts/{$post}/reactions", array() ),
	'stories'                => array( '/stories', array() ),
	'members (active)'       => array( '/members', array() ),
	'members (level)'        => array( '/members', array( 'sort' => 'level' ) ),
	'members search'         => array( '/members', array( 'search' => 'Member 42' ) ),
	'member profile'         => array( '/members/' . ( $busy + 7 ), array() ),
	'leaderboard 7 days'     => array( '/leaderboard', array( 'period' => '7' ) ),
	'leaderboard 30 days'    => array( '/leaderboard', array( 'period' => '30' ) ),
	'leaderboard all time'   => array( '/leaderboard', array( 'period' => 'all' ) ),
	'my stats'               => array( '/me/stats', array() ),
	'chats list'             => array( '/messages/threads', array() ),
	'chat messages'          => array( "/messages/threads/{$thread}/messages", array() ),
	'chat pulse'             => array( '/messages/pulse', array() ),
	'notifications'          => array( '/notifications', array() ),
	'notifications (unread)' => array( '/notifications', array( 'filter' => 'unread' ) ),
	'courses'                => array( '/courses', array() ),
);

$median = function ( array $v ) {
	sort( $v );
	return $v[ intdiv( count( $v ), 2 ) ];
};
printf( "%-24s %8s %8s %8s %8s %7s\n", 'endpoint', 'ms', 'queries', 'db ms', 'slowest', 'status' );
foreach ( $cases as $label => list( $route, $params ) ) {
	$ms = $q = $dbms = $slow = array();
	$status = 0;
	$worst  = '';
	for ( $run = 0; $run < 5; $run++ ) {
		wp_cache_flush();
		if ( $cold ) {
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mbcom\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mbcom\\_%'" );
		}
		$wpdb->queries = array();
		$req = new WP_REST_Request( 'GET', '/' . MEMBUILD_COMMUNITY_REST_NS . $route );
		$req->set_query_params( $params );
		$t        = microtime( true );
		$res      = rest_do_request( $req );
		$ms[]     = ( microtime( true ) - $t ) * 1000;
		$status   = $res->get_status();
		$q[]      = count( $wpdb->queries );
		$times    = array_column( $wpdb->queries, 1 );
		$dbms[]   = array_sum( $times ) * 1000;
		$slow[]   = $times ? max( $times ) * 1000 : 0;
		if ( $times && max( $times ) * 1000 > 50 ) {
			$worst = $wpdb->queries[ array_search( max( $times ), $times, true ) ][0];
		}
	}
	printf( "%-24s %8.1f %8d %8.1f %8.1f %7d\n", $label, $median( $ms ), $median( $q ), $median( $dbms ), $median( $slow ), $status );
	if ( $worst ) {
		echo '    slow query: ', preg_replace( '/\s+/', ' ', substr( $worst, 0, 400 ) ), "\n";
	}
}
printf( "peak memory %.1f MB\n", memory_get_peak_usage() / MB_IN_BYTES );
