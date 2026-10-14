<?php
/**
 * Fills the tests site with a large community: run with
 *   wp eval-file tests/stress/seed.php [members] [posts]
 * Rows are bulk-inserted (no hooks, no password hashing), and every stress
 * member's login starts with "stress_" so tests/stress/clean.php can remove them.
 */
use MemBuild\Community\Install;

global $wpdb;
$members  = (int) ( $args[0] ?? 5000 );
$posts    = (int) ( $args[1] ?? 50000 );
$comments = $posts * 4;
$reacts   = $posts * 10;
$threads  = 3000;
$messages = 100000;

$t0  = microtime( true );
mt_srand( 42 );
$now  = time();
$date = fn( $max_days ) => gmdate( 'Y-m-d H:i:s', $now - mt_rand( 0, $max_days * DAY_IN_SECONDS ) );
$bulk = function ( $table, array $cols, callable $row, $count, $batch = 2000 ) use ( $wpdb ) {
	for ( $done = 0; $done < $count; $done += $batch ) {
		$values = array();
		for ( $i = $done; $i < min( $count, $done + $batch ); $i++ ) {
			$values[] = '(' . implode( ',', array_map( fn( $v ) => null === $v ? 'NULL' : ( is_int( $v ) ? $v : "'" . esc_sql( $v ) . "'" ), $row( $i ) ) ) . ')';
		}
		$wpdb->query( "INSERT IGNORE INTO {$table} (" . implode( ',', $cols ) . ') VALUES ' . implode( ',', $values ) );
	}
};
$words = explode( ' ', 'membership pricing churn launch course community retention onboarding funnel stripe webinar coaching cohort content calendar upsell trial annual monthly members engagement growth wordpress plugin skool circle kajabi email newsletter podcast workshop' );
$text  = fn( $n ) => ucfirst( implode( ' ', array_map( fn() => $words[ mt_rand( 0, count( $words ) - 1 ) ], range( 1, $n ) ) ) ) . '.';

// Members.
$first_user = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ) + 1;
$bulk(
	$wpdb->users,
	array( 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_registered', 'display_name' ),
	fn( $i ) => array( "stress_{$first_user}_{$i}", '$P$Bstressnotarealhashxxxxxxxxxxx0', "stress-{$first_user}-{$i}", "stress{$first_user}.{$i}@example.test", $date( 700 ), "Stress Member {$i}" ),
	$members
);
$caps = $wpdb->prefix . 'capabilities';
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) SELECT ID, %s, %s FROM {$wpdb->users} WHERE user_login LIKE 'stress\\_%%' AND ID >= %d", $caps, serialize( array( 'subscriber' => true ) ), $first_user ) );
$run       = $first_user;
$last_user = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" );
$user      = fn() => mt_rand( $first_user, $last_user );

$bulk(
	Install::table( 'member_stats' ),
	array( 'user_id', 'points', 'level', 'streak', 'best_streak', 'last_active', 'updated_at' ),
	fn( $i ) => array( $first_user + $i, $p = mt_rand( 0, 9000 ), min( 9, 1 + intdiv( $p, 1000 ) ), mt_rand( 0, 30 ), mt_rand( 0, 90 ), gmdate( 'Y-m-d', $now - mt_rand( 0, 60 ) * DAY_IN_SECONDS ), $date( 60 ) ),
	$members
);

// Posts, comments, reactions.
$first_post = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . Install::table( 'posts' ) ) + 1;
$bulk(
	Install::table( 'posts' ),
	array( 'user_id', 'type', 'status', 'content', 'reactions_count', 'comments_count', 'created_at', 'updated_at' ),
	fn( $i ) => array( $user(), 'post', 'published', $text( mt_rand( 8, 60 ) ), mt_rand( 0, 40 ), mt_rand( 0, 12 ), $d = $date( 365 ), $d ),
	$posts
);
$last_post = $first_post + $posts - 1;
$bulk(
	Install::table( 'comments' ),
	array( 'post_id', 'user_id', 'content', 'created_at' ),
	fn( $i ) => array( mt_rand( $first_post, $last_post ), $user(), $text( mt_rand( 3, 25 ) ), $date( 365 ) ),
	$comments
);
$bulk(
	Install::table( 'reactions' ),
	array( 'object_type', 'object_id', 'user_id', 'type', 'created_at' ),
	fn( $i ) => array( 'post', mt_rand( $first_post, $last_post ), $user(), array( 'like', 'love', 'haha', 'wow' )[ mt_rand( 0, 3 ) ], $date( 365 ) ),
	$reacts
);
$bulk(
	Install::table( 'points' ),
	array( 'user_id', 'points', 'reason', 'object_type', 'object_id', 'created_at' ),
	fn( $i ) => array( $user(), mt_rand( 1, 5 ), 'post', 'post', $first_post + ( $i % $posts ), $date( 120 ) ),
	$posts * 3
);

// Chats: one-on-one threads, each between two stress members, with sealed messages.
$first_thread = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . Install::table( 'threads' ) ) + 1;
$pairs        = array();
for ( $i = 0; $i < $threads; $i++ ) {
	$a          = $user();
	$b          = $user();
	$pairs[ $i ] = array( min( $a, $b ), max( $a, $b ) + ( $a === $b ? 1 : 0 ) );
}
$bulk(
	Install::table( 'threads' ),
	array( 'type', 'direct_key', 'created_by', 'last_message_at', 'created_at' ),
	fn( $i ) => array( 'direct', "stress-{$run}-{$i}-" . implode( '-', $pairs[ $i ] ), $pairs[ $i ][0], $date( 30 ), $date( 200 ) ),
	$threads
);
$bulk(
	Install::table( 'thread_members' ),
	array( 'thread_id', 'user_id', 'joined_at' ),
	fn( $i ) => array( $first_thread + intdiv( $i, 2 ), $pairs[ intdiv( $i, 2 ) ][ $i % 2 ], $date( 200 ) ),
	$threads * 2
);
$bulk(
	Install::table( 'messages' ),
	array( 'thread_id', 'user_id', 'content', 'created_at' ),
	function ( $i ) use ( $first_thread, $pairs, $threads, $text, $date ) {
		$t = $i % $threads;
		return array( $first_thread + $t, $pairs[ $t ][ $i % 2 ], \MemBuild\Community\Vault::seal( $text( mt_rand( 3, 20 ) ), 'message:' . ( $first_thread + $t ) ), $date( 200 ) );
	},
	$messages
);
$wpdb->query( 'UPDATE ' . Install::table( 'threads' ) . ' t SET last_message_id = (SELECT MAX(id) FROM ' . Install::table( 'messages' ) . " m WHERE m.thread_id = t.id) WHERE t.direct_key LIKE 'stress-%'" );

// Notifications for one busy stress member (the one the timings log in as).
$busy = (int) get_option( 'mbcom_stress_busy_user' ) ?: $first_user;
$bulk(
	Install::table( 'notifications' ),
	array( 'user_id', 'type', 'object_type', 'object_id', 'post_id', 'actor_ids', 'actors_count', 'created_at', 'updated_at' ),
	fn( $i ) => array( $busy, 'reaction', 'post', $p = mt_rand( $first_post, $last_post ), $p, (string) $user(), 1, $d = $date( 90 ), $d ),
	5000
);
add_option( 'mbcom_stress_busy_user', $busy, '', false ); // the first run's member stays the one timed

wp_cache_flush();
printf( "Seeded %d members, %d posts, %d comments, %d reactions, %d threads, %d messages in %.1fs\n", $members, $posts, $comments, $reacts, $threads, $messages, microtime( true ) - $t0 );
