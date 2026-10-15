<?php
/**
 * End-to-end checks of the plugin's REST API, run inside WordPress on the
 * wp-env *tests* site (port 8892), so the demo site's data is never touched.
 *
 *   npm test
 *
 * Each check calls the real endpoints as real users. Outside services are
 * stubbed: emails are captured instead of sent, and Bunny Stream's API is faked.
 */

// phpcs:ignoreFile -- test script.

// WP-CLI runs this inside a function, so shared values live in $GLOBALS explicitly.
global $wpdb;
$mbcom_ns                 = '/membuild-community/v1';
$GLOBALS['mbcom_ns']      = $mbcom_ns;
$GLOBALS['mbcom_failed']  = 0;
$GLOBALS['mbcom_passed']  = 0;

function mbcom_check( $label, $ok, $detail = '' ) {
	if ( $ok ) {
		++$GLOBALS['mbcom_passed'];
		echo "  ✓ {$label}\n";
	} else {
		++$GLOBALS['mbcom_failed'];
		echo "  ✗ {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}
}

function mbcom_call( $user, $method, $path, $body = array() ) {
	wp_set_current_user( $user );
	$r = new WP_REST_Request( $method, $GLOBALS['mbcom_ns'] . $path );
	if ( 'GET' === $method ) {
		foreach ( $body as $k => $v ) {
			$r->set_param( $k, $v );
		}
	} elseif ( $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$x = rest_do_request( $r );
	return array( $x->get_status(), $x->get_data() );
}

function mbcom_section( $name ) {
	echo "\n{$name}\n";
}

function mbcom_user( $login, $role = 'subscriber' ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		return (int) $u->ID;
	}
	return (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => "{$login}@example.test", 'display_name' => ucfirst( str_replace( 'mbct_', '', $login ) ), 'role' => $role ) );
}

/** Uploads $bytes through the chunked upload API as $user; returns the media row or the error. */
function mbcom_upload( $user, $mime, $bytes, $name ) {
	list( , $start ) = mbcom_call( $user, 'POST', '/media/uploads', array( 'mime' => $mime, 'size' => strlen( $bytes ), 'name' => $name ) );
	if ( empty( $start['upload_id'] ) ) {
		return $start;
	}
	$r = new WP_REST_Request( 'POST', $GLOBALS['mbcom_ns'] . "/media/uploads/{$start['upload_id']}/chunks/0" );
	$r->set_header( 'content-type', 'application/octet-stream' );
	$r->set_body( $bytes );
	rest_do_request( $r );
	list( , $media ) = mbcom_call( $user, 'POST', "/media/uploads/{$start['upload_id']}/complete", array( 'width' => 1, 'height' => 1 ) );
	return $media;
}

// A clean slate: start from default settings with known test members, and no rate limits left over from an earlier run.
delete_option( 'mbcom_settings' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", '_transient_mbcom_rl_%', '_transient_timeout_mbcom_rl_%', '_transient%mbcom_shared_%' ) );
\MemBuild\Community\Settings::flush();
$admin = mbcom_user( 'mbct_admin', 'administrator' );
$ana   = mbcom_user( 'mbct_ana' );
$ben   = mbcom_user( 'mbct_ben' );
$cy    = mbcom_user( 'mbct_cy' );
$dee   = mbcom_user( 'mbct_dee' );
foreach ( array( $ana, $ben, $cy, $dee ) as $u ) {
	delete_user_meta( $u, 'mbcom_restriction' );
}

/* ---------------------------------------------------------------- */
mbcom_section( 'Feed and posts' );
list( $s, $post ) = mbcom_call( $ana, 'POST', '/posts', array( 'content' => 'Hello from the tests' ) );
mbcom_check( 'a member can post', 201 === $s, (string) $s );
$pid = (int) ( $post['id'] ?? 0 );
list( $s, $feed ) = mbcom_call( $ben, 'GET', '/feed' );
mbcom_check( 'the post shows in the feed', 200 === $s && in_array( $pid, array_column( $feed['items'], 'id' ), true ) );
list( $s ) = mbcom_call( $ben, 'PUT', "/posts/{$pid}", array( 'content' => 'hijacked' ) );
mbcom_check( "others can't edit it", 403 === $s, (string) $s );
list( $s ) = mbcom_call( $ben, 'DELETE', "/posts/{$pid}" );
mbcom_check( "others can't delete it", 403 === $s, (string) $s );
list( $s, $edited ) = mbcom_call( $ana, 'PUT', "/posts/{$pid}", array( 'content' => 'Hello, edited' ) );
mbcom_check( 'the author can edit it, marked edited', 200 === $s && ! empty( $edited['edited'] ) );
list( $s ) = mbcom_call( 0, 'GET', '/feed' );
mbcom_check( 'signed-out visitors get nothing', in_array( $s, array( 401, 403 ), true ), (string) $s );

/* ---------------------------------------------------------------- */
mbcom_section( 'Comments, reactions, points and notifications' );
$before = \MemBuild\Community\Points::stats_for( array( $ana ) )[ $ana ]['points'] ?? 0;
list( $s ) = mbcom_call( $ben, 'PUT', "/posts/{$pid}/reaction", array( 'type' => 'love' ) );
mbcom_check( 'a member can react', 200 === $s, (string) $s );
$after = \MemBuild\Community\Points::stats_for( array( $ana ) )[ $ana ]['points'] ?? 0;
mbcom_check( 'the author earns a point', $after === $before + 1, "{$before} → {$after}" );
mbcom_call( $ben, 'DELETE', "/posts/{$pid}/reaction" );
mbcom_check( 'removing the reaction takes it back', ( \MemBuild\Community\Points::stats_for( array( $ana ) )[ $ana ]['points'] ?? 0 ) === $before );
list( $s, $c ) = mbcom_call( $ben, 'POST', "/posts/{$pid}/comments", array( 'content' => 'Nice one' ) );
mbcom_check( 'a member can comment', 201 === $s, (string) $s );
list( $s, $reply ) = mbcom_call( $ana, 'POST', "/posts/{$pid}/comments", array( 'content' => 'Thanks!', 'parent_id' => $c['id'] ) );
mbcom_check( 'replies thread under the comment', 201 === $s && (int) $reply['parent_id'] === (int) $c['id'] );
list( , $n_ana ) = mbcom_call( $ana, 'GET', '/notifications' );
mbcom_check( 'the author is notified of the comment', in_array( 'post_comment', array_column( $n_ana['items'], 'type' ), true ) );
list( , $n_ben ) = mbcom_call( $ben, 'GET', '/notifications' );
mbcom_check( 'the commenter is notified of the reply', in_array( 'comment_reply', array_column( $n_ben['items'], 'type' ), true ) );
mbcom_check( 'nobody is notified about their own actions', ! in_array( 'post_comment', array_column( $n_ben['items'], 'type' ), true ) );

/* ---------------------------------------------------------------- */
mbcom_section( 'Settings and permissions' );
list( $s ) = mbcom_call( $ana, 'GET', '/settings' );
mbcom_check( "members can't read settings", 403 === $s, (string) $s );
list( $s ) = mbcom_call( $ana, 'PUT', '/settings', array( 'name' => 'Pwned' ) );
mbcom_check( "members can't change settings", 403 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'PUT', '/settings', array( 'posting' => 'staff', 'features' => array( 'reels' => false ) ) );
mbcom_check( 'admins can change settings', 200 === $s, (string) $s );
list( $s ) = mbcom_call( $ana, 'POST', '/posts', array( 'content' => 'members only now?' ) );
mbcom_check( '"only staff can post" blocks members', 403 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'POST', '/posts', array( 'content' => 'admin announcement' ) );
mbcom_check( '…but not admins', 201 === $s, (string) $s );
list( $s, $d ) = mbcom_call( $admin, 'PUT', '/settings', array( 'email' => array( 'mode' => 'smtp', 'host' => 'smtp.example.test', 'password' => 'hunter2' ) ) );
mbcom_check( 'the SMTP password is never sent back', 200 === $s && '' === $d['settings']['email']['password'] && $d['settings']['email']['has_password'] );
$raw = get_option( 'mbcom_settings' )['email']['password'] ?? '';
mbcom_check( '…and is stored encrypted', '' !== $raw && false === strpos( $raw, 'hunter2' ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'posting' => 'everyone', 'features' => array( 'reels' => true ), 'email' => array( 'mode' => 'default', 'clear_password' => true ) ) );

/* ---------------------------------------------------------------- */
mbcom_section( 'Messages' );
list( $s, $chat ) = mbcom_call( $ana, 'POST', '/messages/threads', array( 'user_ids' => array( $ben ) ) );
mbcom_check( 'a member can start a chat', 200 === $s, (string) $s );
$tid = (int) ( $chat['id'] ?? 0 );
list( $s ) = mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => 'Hi Ben' ) );
mbcom_check( 'and send a message', in_array( $s, array( 200, 201 ), true ), (string) $s );
list( $s, $hist ) = mbcom_call( $ben, 'GET', "/messages/threads/{$tid}/messages" );
mbcom_check( 'the other person can read it', 200 === $s && 'Hi Ben' === end( $hist['items'] )['content'] );
list( $s ) = mbcom_call( $cy, 'GET', "/messages/threads/{$tid}/messages" );
mbcom_check( "outsiders can't read the chat", in_array( $s, array( 403, 404 ), true ), (string) $s );
list( $s ) = mbcom_call( $cy, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => 'butting in' ) );
mbcom_check( "…or post in it", in_array( $s, array( 403, 404 ), true ), (string) $s );
list( , $pulse ) = mbcom_call( $ben, 'GET', '/messages/pulse' );
mbcom_check( 'the pulse counts it as unread', (int) $pulse['unread'] >= 1 );

/* ---------------------------------------------------------------- */
mbcom_section( 'Moderation' );
list( , $p2 ) = mbcom_call( $dee, 'POST', '/posts', array( 'content' => 'Buy followers now!!!' ) );
$p2 = (int) $p2['id'];
foreach ( array( $ana, $ben, $cy ) as $u ) {
	list( , $rep ) = mbcom_call( $u, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => $p2, 'reason' => 'spam' ) );
}
mbcom_check( 'three reports hide a post', ! empty( $rep['hidden'] ) );
list( $s ) = mbcom_call( $dee, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => $p2, 'reason' => 'spam' ) );
mbcom_check( "you can't report your own post", 400 === $s, (string) $s );
list( $s ) = mbcom_call( $ana, 'GET', '/moderation/reports' );
mbcom_check( "members can't open the review queue", 403 === $s, (string) $s );
list( $s, $q ) = mbcom_call( $admin, 'GET', '/moderation/reports' );
mbcom_check( 'staff see it in the queue', 200 === $s && in_array( $p2, array_column( $q['items'], 'object_id' ), true ) );
mbcom_call( $admin, 'POST', "/moderation/reports/post/{$p2}", array( 'action' => 'remove' ) );
mbcom_check( 'removing it deletes the post', 'deleted' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}mbcom_posts WHERE id = %d", $p2 ) ) );
list( $s ) = mbcom_call( $ana, 'POST', "/members/{$dee}/restriction", array( 'status' => 'banned' ) );
mbcom_check( "members can't ban anyone", 403 === $s, (string) $s );
mbcom_call( $admin, 'POST', "/members/{$dee}/restriction", array( 'status' => 'muted', 'days' => 1 ) );
list( $s ) = mbcom_call( $dee, 'POST', '/posts', array( 'content' => 'still here' ) );
mbcom_check( "muted members can't post", 403 === $s, (string) $s );
list( $s ) = mbcom_call( $dee, 'POST', "/posts/{$pid}/comments", array( 'content' => 'or comment' ) );
mbcom_check( "…or comment", 403 === $s, (string) $s );
list( $s ) = mbcom_call( $dee, 'GET', '/feed' );
mbcom_check( '…but can still read', 200 === $s, (string) $s );
mbcom_call( $admin, 'POST', "/members/{$dee}/restriction", array( 'status' => 'banned' ) );
list( $s ) = mbcom_call( $dee, 'GET', '/feed' );
mbcom_check( 'banned members are locked out', 403 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'POST', "/members/{$admin}/restriction", array( 'status' => 'banned' ) );
mbcom_check( "admins can't be banned", 403 === $s, (string) $s );
mbcom_call( $admin, 'POST', "/members/{$dee}/restriction", array( 'status' => 'none' ) );
list( $s ) = mbcom_call( $ana, 'PUT', "/posts/{$pid}/pin" );
mbcom_check( "members can't pin posts", 403 === $s, (string) $s );

/* ---------------------------------------------------------------- */
mbcom_section( 'Members' );
list( $s, $dir ) = mbcom_call( $ana, 'GET', '/members', array( 'search' => 'Ben' ) );
mbcom_check( 'the directory finds members by name', 200 === $s && in_array( $ben, array_column( $dir['items'], 'id' ), true ) );
mbcom_check( "it doesn't expose email addresses", false === strpos( wp_json_encode( $dir ), '@example.test' ) );
list( $s ) = mbcom_call( $ana, 'PUT', '/me/profile', array( 'name' => '   ' ) );
mbcom_check( "an empty name isn't allowed", 400 === $s, (string) $s );
list( $s, $prof ) = mbcom_call( $ana, 'PUT', '/me/profile', array( 'bio' => 'Testing things' ) );
mbcom_check( 'members can update their bio', 200 === $s && 'Testing things' === $prof['bio'] );

/* ---------------------------------------------------------------- */
mbcom_section( 'Email notifications' );
$mbcom_mails = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$mbcom_mails ) {
		$mbcom_mails[] = $atts;
		return true;
	},
	10,
	2
);
list( , $p3 ) = mbcom_call( $ana, 'POST', '/posts', array( 'content' => 'Email me' ) );
mbcom_call( $ben, 'POST', "/posts/{$p3['id']}/comments", array( 'content' => 'Commenting for the email test' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mbcom_notifications SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) WHERE user_id = %d", $ana ) );
delete_user_meta( $ana, 'mbcom_email_last' );
delete_user_meta( $ana, 'mbcom_last_active' );
\MemBuild\Community\Email::run();
$to_ana = array_values( array_filter( $mbcom_mails, fn( $m ) => 'mbct_ana@example.test' === $m['to'] ) );
mbcom_check( 'a summary email goes out', 1 === count( $to_ana ), count( $to_ana ) . ' emails' );
mbcom_check( 'it has an unsubscribe link', $to_ana && false !== strpos( $to_ana[0]['message'], 'mbcom_unsubscribe' ) );
$mbcom_mails = array();
\MemBuild\Community\Email::run();
mbcom_check( 'the same news is never emailed twice', ! array_filter( $mbcom_mails, fn( $m ) => 'mbct_ana@example.test' === $m['to'] ) );

/* ---------------------------------------------------------------- */
mbcom_section( 'Bunny Stream (with a stand-in for Bunny)' );
$mbcom_bunny = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$mbcom_bunny ) {
		if ( false === strpos( $url, 'video.bunnycdn.com' ) ) {
			return $pre;
		}
		$mbcom_bunny[] = $args['method'] . ' ' . wp_parse_url( $url, PHP_URL_PATH );
		$body        = array( 'guid' => 'test-guid', 'status' => 4, 'width' => 720, 'height' => 1280, 'length' => 9, 'totalItems' => 0 );
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);
list( $s ) = mbcom_call( $admin, 'PUT', '/settings', array( 'video' => array( 'provider' => 'bunny', 'library_id' => '1', 'cdn_host' => 'vz-x.b-cdn.net', 'api_key' => 'k' ) ) );
mbcom_check( 'Bunny settings save', 200 === $s, (string) $s );
list( $s, $start ) = mbcom_call( $ana, 'POST', '/media/bunny', array( 'name' => 'v.mp4', 'size' => 1000 ) );
mbcom_check( 'a member gets a signed upload for one video', 200 === $s && ! empty( $start['tus']['headers']['AuthorizationSignature'] ) );
list( $s ) = mbcom_call( $ben, 'POST', "/media/bunny/{$start['media']['id']}/complete" );
mbcom_check( "others can't complete someone else's upload", 404 === $s, (string) $s );
list( $s, $done ) = mbcom_call( $ana, 'POST', "/media/bunny/{$start['media']['id']}/complete" );
mbcom_check( 'completing it checks Bunny and marks it ready', 200 === $s && 'ready' === $done['stream']['status'], $done['stream']['status'] ?? '' );
$w = new WP_REST_Request( 'POST', $mbcom_ns . '/bunny/webhook' );
$w->set_param( 'token', 'wrong' );
wp_set_current_user( 0 );
mbcom_check( 'the webhook rejects a wrong token', 403 === rest_do_request( $w )->get_status() );
list( , $reel ) = mbcom_call( $ana, 'POST', '/posts', array( 'type' => 'reel', 'content' => 'bunny reel', 'media_ids' => array( $start['media']['id'] ) ) );
$mbcom_bunny = array();
mbcom_call( $ana, 'DELETE', "/posts/{$reel['id']}" );
mbcom_check( "deleting the post deletes the video from Bunny", in_array( 'DELETE /library/1/videos/test-guid', $mbcom_bunny, true ), implode( ', ', $mbcom_bunny ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'video' => array( 'provider' => 'local' ) ) );

/* ---------------------------------------------------------------- */
mbcom_section( 'Security fixes' );
// Uploads stay out of WordPress's public media listing.
$att = wp_insert_attachment( array( 'post_title' => 'dm-photo', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ) );
update_post_meta( $att, '_mbcom_private', 1 );
wp_set_current_user( 0 );
$media_list = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media' ) )->get_data();
mbcom_check( 'community uploads are hidden from /wp/v2/media', ! in_array( $att, array_column( (array) $media_list, 'id' ), true ) );
$one = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/media/{$att}" ) );
mbcom_check( '…including by ID', 404 === $one->get_status(), (string) $one->get_status() );
wp_delete_attachment( $att, true );

list( $s ) = mbcom_call( $ana, 'POST', '/media/uploads', array( 'mime' => 'application/pdf', 'size' => 1000, 'name' => 'x.pdf' ) );
mbcom_check( "members can't upload documents (lesson files are admin-only)", 415 === $s, (string) $s );

// Messaging set to "staff only" can't be bypassed through an existing chat or a story reply.
mbcom_call( $admin, 'PUT', '/settings', array( 'messaging' => 'staff' ) );
list( $s ) = mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => 'still talking?' ) );
mbcom_check( 'the staff-only rule covers existing chats', 403 === $s, (string) $s );
$wpdb->insert( "{$wpdb->prefix}mbcom_stories", array( 'user_id' => $ben, 'kind' => 'text', 'text' => 'hi', 'created_at' => current_time( 'mysql', true ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
$sid = (int) $wpdb->insert_id;
list( $s ) = mbcom_call( $ana, 'POST', "/stories/{$sid}/reply", array( 'content' => 'sneaky' ) );
mbcom_check( '…and story replies', 403 === $s, (string) $s );
$wpdb->delete( "{$wpdb->prefix}mbcom_stories", array( 'id' => $sid ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'messaging' => 'everyone' ) );

// A reply can only "reply to" someone already in that thread.
list( , $c2 ) = mbcom_call( $ben, 'POST', "/posts/{$pid}/comments", array( 'content' => 'top comment' ) );
list( , $r2 ) = mbcom_call( $ana, 'POST', "/posts/{$pid}/comments", array( 'content' => 'spam ping', 'parent_id' => $c2['id'], 'reply_to_user_id' => $dee ) );
mbcom_check( "replies can't notify people outside the thread", (int) ( $r2['reply_to']['id'] ?? 0 ) === $ben, 'got ' . ( $r2['reply_to']['id'] ?? 'none' ) );

// Comments on a removed post can't be read or reacted to.
list( , $gone ) = mbcom_call( $ana, 'POST', '/posts', array( 'content' => 'to be removed' ) );
list( , $gc ) = mbcom_call( $ben, 'POST', "/posts/{$gone['id']}/comments", array( 'content' => 'on a removed post' ) );
mbcom_call( $ana, 'DELETE', "/posts/{$gone['id']}" );
list( $s ) = mbcom_call( $ben, 'GET', "/comments/{$gc['id']}/replies" );
mbcom_check( "replies on a removed post can't be read", 404 === $s, (string) $s );
list( $s ) = mbcom_call( $ben, 'PUT', "/comments/{$gc['id']}/reaction", array( 'type' => 'like' ) );
mbcom_check( "…or reacted to", 404 === $s, (string) $s );

// Staff posts can't be hidden by a few reports.
list( , $sp ) = mbcom_call( $admin, 'POST', '/posts', array( 'content' => 'house rules' ) );
foreach ( array( $ana, $ben, $cy ) as $u ) {
	list( , $rep2 ) = mbcom_call( $u, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => $sp['id'], 'reason' => 'spam' ) );
}
mbcom_check( 'reports never auto-hide a staff post', empty( $rep2['hidden'] ) );

// Restricted members see that they're muted, not who did it or why.
mbcom_call( $admin, 'POST', "/members/{$dee}/restriction", array( 'status' => 'muted', 'days' => 1, 'reason' => 'internal note' ) );
list( , $me_prof ) = mbcom_call( $dee, 'GET', "/members/{$dee}" );
mbcom_check( "muted members don't see the moderator or reason", ! isset( $me_prof['restriction']['by'] ) && ! isset( $me_prof['restriction']['reason'] ) && 'muted' === $me_prof['restriction']['status'] );
list( $s ) = mbcom_call( $dee, 'PUT', '/me/profile', array( 'bio' => 'changed while muted' ) );
mbcom_check( "muted members can't edit their profile", 403 === $s, (string) $s );
mbcom_call( $admin, 'POST', "/members/{$dee}/restriction", array( 'status' => 'none' ) );

// Login names aren't searchable.
list( , $byLogin ) = mbcom_call( $ben, 'GET', '/members', array( 'search' => 'mbct_' ) );
mbcom_check( 'member search ignores login names', ! $byLogin['items'] );

/* ---------------------------------------------------------------- */
mbcom_section( 'Admin screens and privacy tools' );
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once WP_PLUGIN_DIR . '/membuild-community/includes/admin/class-admin.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
wp_set_current_user( $admin );
set_current_screen( 'dashboard' );
$secret = 'Top secret message ' . wp_generate_password( 6, false );
mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => $secret ) );
wp_set_current_user( $admin );
ob_start();
\MemBuild\Community\Admin::page_table( 'members' );
$html = ob_get_clean();
mbcom_check( 'the Members screen lists members with message counts', false !== strpos( $html, 'Ana' ) && (bool) preg_match( '/in \d+ chats?/', $html ) );
mbcom_check( '…but never message text', false === strpos( $html, $secret ) );
foreach ( array( 'posts', 'comments', 'files', 'courses' ) as $screen ) {
	ob_start();
	\MemBuild\Community\Admin::page_table( $screen );
	$html = ob_get_clean();
	mbcom_check( "the {$screen} screen renders", strlen( $html ) > 500 && false === stripos( $html, 'fatal' ) );
}
ob_start();
\MemBuild\Community\Admin::page_overview();
$html = ob_get_clean();
mbcom_check( 'the overview renders its numbers and chart', false !== strpos( $html, 'mbcom-card-value' ) && false !== strpos( $html, '<svg' ) );

$export = \MemBuild\Community\Privacy::export( 'mbct_ana@example.test', 1 );
$groups = array_unique( array_column( $export['data'], 'group_id' ) );
mbcom_check( 'personal-data export includes profile and posts', in_array( 'mbcom-profile', $groups, true ) && in_array( 'mbcom-posts', $groups, true ) );
mbcom_check( '…counts messages without their text', false === strpos( wp_json_encode( $export ), $secret ) );

// Erasing a member: their posts go, others' replies to their comments stay.
$victim = mbcom_user( 'mbct_erase' );
list( , $vp ) = mbcom_call( $victim, 'POST', '/posts', array( 'content' => 'Erase me please' ) );
list( , $vc ) = mbcom_call( $victim, 'POST', "/posts/{$pid}/comments", array( 'content' => 'my comment' ) );
list( , $other_reply ) = mbcom_call( $ben, 'POST', "/posts/{$pid}/comments", array( 'content' => 'a reply to it', 'parent_id' => $vc['id'] ) );
wp_set_current_user( $admin );
do {
	$res = \MemBuild\Community\Privacy::erase( 'mbct_erase@example.test', 1 );
} while ( ! $res['done'] );
mbcom_check( "erasing removes the member's posts", 'deleted' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}mbcom_posts WHERE id = %d", $vp['id'] ) ) );
mbcom_check( "…removes their words from comments others replied to", '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}mbcom_comments WHERE id = %d", $vc['id'] ) ) );
mbcom_check( "…and keeps other people's replies", (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mbcom_comments WHERE id = %d", $other_reply['id'] ) ) );

/* ---------------------------------------------------------------- */
echo "\nEncryption at rest\n";
$secret_text = 'Meet me at the secret spot ' . wp_generate_password( 6, false );
list( , $sealed_msg ) = mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => $secret_text ) );
$raw_row = $wpdb->get_row( $wpdb->prepare( "SELECT thread_id, content FROM {$wpdb->prefix}mbcom_messages WHERE id = %d", $sealed_msg['id'] ), ARRAY_A );
mbcom_check( 'message text is stored encrypted', str_starts_with( $raw_row['content'], 'e1:' ) && false === strpos( $raw_row['content'], 'secret spot' ) );
list( , $hist ) = mbcom_call( $ben, 'GET', "/messages/threads/{$tid}/messages" );
mbcom_check( '…and reads normally for the people in the chat', in_array( $secret_text, wp_list_pluck( $hist['items'], 'content' ), true ) );
mbcom_check( 'encrypted text copied into another chat can’t be opened', null === \MemBuild\Community\Vault::open( $raw_row['content'], 'message:' . ( (int) $raw_row['thread_id'] + 1 ) ) );
$legacy_key = hash( 'sha256', wp_salt( 'auth' ) . 'mbcom-email', true );
$legacy_iv  = random_bytes( 16 );
$legacy     = 'v1:' . base64_encode( $legacy_iv . openssl_encrypt( 'old-smtp-pass', 'aes-256-cbc', $legacy_key, OPENSSL_RAW_DATA, $legacy_iv ) );
mbcom_check( 'secrets saved by 0.1 still read', 'old-smtp-pass' === \MemBuild\Community\Vault::reveal( $legacy ) );
mbcom_check( '…and new ones are sealed', str_starts_with( \MemBuild\Community\Vault::secret( 'x' ), 'e1:' ) && 'x' === \MemBuild\Community\Vault::reveal( \MemBuild\Community\Vault::secret( 'x' ) ) );
mbcom_check( 'the key is kept outside the database', 'database' !== \MemBuild\Community\Vault::key_source() );

echo "\nA lost or wrong encryption key\n";
$vault      = '\MemBuild\Community\Vault';
$key_file   = $vault::key_path();
$orig_key   = (string) include $key_file;
$orig_check = get_option( $vault::CHECK_OPTION );
$put_back   = function () use ( $vault, $key_file, $orig_key, $orig_check ) {
	file_put_contents( $key_file, "<?php\nreturn '{$orig_key}';\n" );
	update_option( $vault::CHECK_OPTION, $orig_check, false );
	foreach ( glob( WP_CONTENT_DIR . '/mbcom-key-replaced-*.php' ) as $old ) {
		unlink( $old );
	}
	$vault::flush();
};
$vault::flush();
mbcom_check( 'the key can be copied by an admin', 'ok' === $vault::state() && $orig_key === ( mbcom_call( $admin, 'GET', '/settings/encryption/key' )[1]['key'] ?? '' ) );
list( $s ) = mbcom_call( $ana, 'GET', '/settings/encryption/key' );
mbcom_check( '…and by nobody else', in_array( $s, array( 401, 403 ), true ), (string) $s );

rename( $key_file, $key_file . '.away' );
$vault::flush();
mbcom_check( 'a missing key file is noticed, not quietly replaced', 'missing' === $vault::state() && ! file_exists( $key_file ) );
list( $s ) = mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => 'While the key is away' ) );
mbcom_check( '…messages pause instead of using a new key', 503 === $s, (string) $s );
list( , $hist ) = mbcom_call( $ben, 'GET', "/messages/threads/{$tid}/messages" );
mbcom_check( '…and earlier ones show as unreadable, not as gibberish', in_array( 'This message can’t be shown.', wp_list_pluck( $hist['items'], 'content' ), true ) );
list( $s ) = mbcom_call( $admin, 'POST', '/settings/encryption', array( 'action' => 'restore', 'key' => bin2hex( random_bytes( 32 ) ) ) );
mbcom_check( 'a different site’s key is refused', 400 === $s, (string) $s );
list( $s, $restored ) = mbcom_call( $admin, 'POST', '/settings/encryption', array( 'action' => 'restore', 'key' => strtoupper( $orig_key ) ) );
list( , $hist ) = mbcom_call( $ben, 'GET', "/messages/threads/{$tid}/messages" );
mbcom_check( 'pasting the saved key brings every message back', 200 === $s && 'ok' === $restored['state'] && in_array( $secret_text, wp_list_pluck( $hist['items'], 'content' ), true ) );
mbcom_check( '…and counts as having a copy', ! empty( $restored['saved_at'] ) );
rename( $key_file . '.away', $key_file . '.spare' ); // the restore wrote a fresh key file
unlink( $key_file . '.spare' );

file_put_contents( $key_file, "<?php\nreturn '" . bin2hex( random_bytes( 32 ) ) . "';\n" );
$vault::flush();
mbcom_check( 'a key file from another site is noticed', 'wrong' === $vault::state() );
list( $s, $fresh ) = mbcom_call( $admin, 'POST', '/settings/encryption', array( 'action' => 'start_over' ) );
list( $s2 ) = mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => 'After starting over' ) );
list( , $hist ) = mbcom_call( $ben, 'GET', "/messages/threads/{$tid}/messages" );
$texts = wp_list_pluck( $hist['items'], 'content' );
mbcom_check( 'starting over gives a new key that works', 200 === $s && 'ok' === $fresh['state'] && 201 === $s2 && in_array( 'After starting over', $texts, true ) );
mbcom_check( '…while earlier messages stay unreadable', ! in_array( $secret_text, $texts, true ) );
mbcom_check( '…and the replaced key file is kept, not deleted', (bool) glob( WP_CONTENT_DIR . '/mbcom-key-replaced-*.php' ) );
mbcom_check( '…and asks for a copy of the new key', null === $fresh['saved_at'] );
list( $s ) = mbcom_call( $admin, 'POST', '/settings/encryption', array( 'action' => 'start_over' ) );
mbcom_check( 'a working key can’t be thrown away', 400 === $s, (string) $s );
$put_back();
mbcom_check( '(the original key is back for the rest of the tests)', 'ok' === $vault::state() );

/* ---------------------------------------------------------------- */
echo "\nProtected files\n";
$png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
$photo = mbcom_upload( $ana, 'image/png', $png, 'IMG_1234.png' );
mbcom_check( 'uploads get a random file name, not the original', isset( $photo['url'] ) && false === strpos( $photo['url'], 'IMG_1234' ), wp_json_encode( $photo ) );
list( , $sent ) = mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => '', 'media_ids' => array( $photo['id'] ) ) );
$row = \MemBuild\Community\Media::get( $photo['id'] );
mbcom_check( 'a chat photo moves to the protected folder', 'message' === $row['scope'] && str_contains( get_attached_file( (int) $row['attachment_id'] ), '/mbcom-private/' ) );
mbcom_check( '…and the message points at the protected address', \MemBuild\Community\Files::url( $photo['id'] ) === ( $sent['media'][0]['url'] ?? '' ) );
wp_set_current_user( $ben );
mbcom_check( 'people in the chat can open it', \MemBuild\Community\Files::can_view( $row ) );
wp_set_current_user( $cy );
mbcom_check( 'others can’t', ! \MemBuild\Community\Files::can_view( $row ) );
wp_set_current_user( $admin );
mbcom_check( '…not even admins', ! \MemBuild\Community\Files::can_view( $row ) );
wp_set_current_user( 0 );
mbcom_check( '…nor visitors who aren’t logged in', ! \MemBuild\Community\Files::can_view( $row ) );
$dir = wp_upload_dir()['basedir'] . '/mbcom-private/';
mbcom_check( 'the protected folder blocks direct web access', file_exists( $dir . '.htaccess' ) && file_exists( $dir . 'web.config' ) && file_exists( $dir . 'index.php' ) );
ob_start();
\MemBuild\Community\Export::write( 'files', fopen( 'php://output', 'w' ) );
$csv = ob_get_clean();
mbcom_check( 'chat photos are left out of the admin Files export', false === strpos( $csv, \MemBuild\Community\Files::url( $photo['id'] ) ) && false === strpos( $csv, $row['url'] ) );

/* ---------------------------------------------------------------- */
echo "\nClassroom access\n";
list( , $course ) = mbcom_call( $admin, 'POST', '/courses', array( 'title' => 'VIP masterclass' ) );
$cid    = $course['id'];
$module = $course['modules'][0]['id'];
list( , $lesson ) = mbcom_call( $admin, 'POST', "/modules/{$module}/lessons", array( 'title' => 'Secret lesson' ) );
mbcom_call( $admin, 'PUT', "/courses/{$cid}", array( 'status' => 'published' ) );
$member  = mbcom_user( 'mbct_member' );
// Earlier runs left this member points from finished lessons; start them at level 1.
$wpdb->delete( "{$wpdb->prefix}mbcom_points", array( 'user_id' => $member ) );
$wpdb->delete( "{$wpdb->prefix}mbcom_member_stats", array( 'user_id' => $member ) );
$cust    = mbcom_user( 'mbct_customer' );
( new WP_User( $cust ) )->set_role( 'author' ); // stands in for the role a membership plugin gives buyers
$fresh   = new ReflectionProperty( '\MemBuild\Community\Access', 'mine' ); // group lookups are cached per request; tests reset it
$fresh->setAccessible( true );
list( $s ) = mbcom_call( $member, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'courses are open to everyone by default', 200 === $s, (string) $s );

mbcom_call( $admin, 'PUT', "/courses/{$cid}", array( 'access' => 'level', 'unlock_level' => 5 ) );
list( $s, $err ) = mbcom_call( $member, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'a level course is locked below that level', 403 === $s && 'mbcom_level_locked' === $err['code'], (string) $s );

list( $s, $vip ) = mbcom_call( $admin, 'POST', '/groups', array( 'name' => 'VIP', 'roles' => array( 'author', 'not-a-role' ) ) );
mbcom_check( 'admins create access groups (unknown roles are dropped)', 201 === $s && array( 'author' ) === $vip['roles'], (string) $s );
list( $s ) = mbcom_call( $member, 'POST', '/groups', array( 'name' => 'Sneaky' ) );
mbcom_check( "members can't manage groups", 403 === $s, (string) $s );

mbcom_call( $admin, 'PUT', "/courses/{$cid}", array( 'access' => 'groups', 'access_groups' => array( $vip['id'] ) ) );
list( $s, $err ) = mbcom_call( $member, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'a group course is locked for members outside the group', 403 === $s && 'mbcom_group_locked' === $err['code'], (string) $s );
list( $s ) = mbcom_call( $member, 'POST', "/lessons/{$lesson['id']}/complete" );
mbcom_check( '…and they can’t mark its lessons done', 403 === $s, (string) $s );
list( , $card ) = mbcom_call( $member, 'GET', "/courses/{$cid}" );
mbcom_check( '…which says which group it’s for', 'groups' === $card['lock']['reason'] && array( 'VIP' ) === $card['lock']['groups'] && ! isset( $card['access_groups'] ) );

$sheet = mbcom_upload( $admin, 'text/plain', "Worksheet\n", 'worksheet.txt' );
mbcom_call( $admin, 'PUT', "/lessons/{$lesson['id']}", array( 'resources' => array( array( 'media_id' => $sheet['id'], 'name' => 'Worksheet' ) ) ) );
$sheet_row = \MemBuild\Community\Media::get( $sheet['id'] );
wp_set_current_user( $member );
mbcom_check( 'a locked member can’t download the lesson’s files', 'lesson' === $sheet_row['scope'] && ! \MemBuild\Community\Files::can_view( $sheet_row ) );
wp_set_current_user( $cust );
mbcom_check( '…a member with access can', \MemBuild\Community\Files::can_view( $sheet_row ) );
list( $s ) = mbcom_call( $cust, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'a linked WordPress role opens it', 200 === $s, (string) $s );
( new WP_User( $cust ) )->set_role( 'subscriber' );
$fresh->setValue( null, array() );
list( $s ) = mbcom_call( $cust, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( '…and losing the role closes it again', 403 === $s, (string) $s );

mbcom_call( $admin, 'POST', "/groups/{$vip['id']}/members", array( 'user_ids' => array( $member ) ) );
list( $s ) = mbcom_call( $member, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'adding a member by hand opens it', 200 === $s, (string) $s );
mbcom_call( $member, 'POST', "/lessons/{$lesson['id']}/complete" );
mbcom_call( $admin, 'PUT', "/members/{$member}/groups", array( 'group_ids' => array() ) );
list( $s ) = mbcom_call( $member, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'removing them closes it', 403 === $s, (string) $s );
mbcom_check( '…without deleting their progress', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}mbcom_lesson_progress WHERE user_id = %d AND lesson_id = %d", $member, $lesson['id'] ) ) );

add_filter( 'membuild_community/in_access_group', $mbcom_grant = fn( $in, $gid, $uid ) => $in || $uid === $member, 10, 3 );
$fresh->setValue( null, array() );
list( $s ) = mbcom_call( $member, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'other plugins can grant group membership with a filter', 200 === $s, (string) $s );
remove_filter( 'membuild_community/in_access_group', $mbcom_grant, 10 );
$fresh->setValue( null, array() );

mbcom_call( $admin, 'PUT', "/courses/{$cid}", array( 'hide_locked' => true ) );
list( , $list ) = mbcom_call( $member, 'GET', '/courses' );
list( $s ) = mbcom_call( $member, 'GET', "/courses/{$cid}" );
mbcom_check( 'a hidden locked course is neither listed nor reachable', ! in_array( $cid, wp_list_pluck( $list['items'], 'id' ), true ) && 404 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'GET', "/lessons/{$lesson['id']}" );
mbcom_check( 'admins always open every course', 200 === $s, (string) $s );

mbcom_call( $admin, 'DELETE', "/groups/{$vip['id']}" );
list( , $card ) = mbcom_call( $admin, 'GET', "/courses/{$cid}" );
mbcom_check( 'deleting a group takes it off its courses', array() === $card['access_groups'] );
mbcom_call( $admin, 'DELETE', "/courses/{$cid}" );

/* ---------------------------------------------------------------- */
echo "\nBlocking\n";
$blocker = mbcom_user( 'mbct_blocker' );
$pest    = mbcom_user( 'mbct_pest' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mbcom_blocks WHERE user_id IN (%d, %d)", $blocker, $pest ) );
list( , $pest_post ) = mbcom_call( $pest, 'POST', '/posts', array( 'content' => 'Hello from the pest' ) );
list( $s ) = mbcom_call( $blocker, 'PUT', "/members/{$pest}/block" );
mbcom_check( 'a member can block another member', 200 === $s, (string) $s );
$fresh_blocks = new ReflectionProperty( '\MemBuild\Community\Blocks', 'hidden' );
$fresh_blocks->setAccessible( true );
$fresh_blocks->setValue( null, array() );
list( , $feed ) = mbcom_call( $blocker, 'GET', '/feed' );
mbcom_check( '…their posts disappear from the feed', ! in_array( $pest_post['id'], wp_list_pluck( $feed['items'], 'id' ), true ) );
list( $s ) = mbcom_call( $blocker, 'GET', "/posts/{$pest_post['id']}" );
mbcom_check( '…and can’t be opened', 404 === $s, (string) $s );
$fresh_blocks->setValue( null, array() );
list( $s ) = mbcom_call( $pest, 'GET', "/members/{$blocker}" );
mbcom_check( 'the blocked member can’t see the blocker’s profile', 404 === $s, (string) $s );
$fresh_blocks->setValue( null, array() );
list( $s, $prof ) = mbcom_call( $blocker, 'GET', "/members/{$pest}" );
mbcom_check( '…but the blocker can, to unblock them', 200 === $s && true === $prof['blocked'] && false === $prof['can_message'] );
$fresh_blocks->setValue( null, array() );
list( $s ) = mbcom_call( $pest, 'POST', '/messages/threads', array( 'user_ids' => array( $blocker ) ) );
mbcom_check( 'neither can start a chat with the other', 403 === $s, (string) $s );
list( $s ) = mbcom_call( $blocker, 'PUT', "/members/{$admin}/block" );
mbcom_check( 'admins and moderators can’t be blocked', 403 === $s, (string) $s );
mbcom_call( $blocker, 'DELETE', "/members/{$pest}/block" );
$fresh_blocks->setValue( null, array() );
list( $s ) = mbcom_call( $blocker, 'GET', "/posts/{$pest_post['id']}" );
mbcom_check( 'unblocking brings everything back', 200 === $s, (string) $s );
mbcom_call( $pest, 'DELETE', "/posts/{$pest_post['id']}" );

list( $s ) = mbcom_call( $blocker, 'POST', '/reports', array( 'object_type' => 'member', 'object_id' => $pest, 'reason' => 'spam' ) );
mbcom_check( 'members can report a profile', 200 === $s, (string) $s );
$profile_report = false;
for ( $page = 1; $page <= 10 && ! $profile_report; $page++ ) { // earlier runs leave other open reports ahead of it
	list( , $queue ) = mbcom_call( $admin, 'GET', '/moderation/reports', array( 'page' => $page ) );
	$profile_report  = current( array_filter( $queue['items'], fn( $i ) => 'member' === $i['object_type'] && $i['object_id'] === $pest ) );
}
mbcom_check( '…which moderators see in the queue', (bool) $profile_report );
list( $s ) = mbcom_call( $admin, 'POST', "/moderation/reports/member/{$pest}", array( 'action' => 'remove' ) );
mbcom_check( '…and dismiss (a profile is never "removed")', 200 === $s && (bool) get_userdata( $pest ) );
list( $s ) = mbcom_call( $blocker, 'POST', '/reports', array( 'object_type' => 'member', 'object_id' => $admin, 'reason' => 'spam' ) );
mbcom_check( 'staff profiles can’t be reported', 404 === $s, (string) $s );

/* ---------------------------------------------------------------- */
echo "\nJoining\n";
if ( is_multisite() ) {
	// On a network only the network admin decides who may sign up.
	$network = get_site_option( 'registration' );
	update_site_option( 'registration', 'none' );
	mbcom_call( $admin, 'PUT', '/settings', array( 'registration' => true ) );
	list( , $got ) = mbcom_call( $admin, 'GET', '/settings' );
	mbcom_check( 'on a network, a site admin can’t turn sign-up on', false === $got['settings']['registration'] && ! empty( $got['registration_locked'] ) );
	update_site_option( 'registration', 'user' );
	list( , $again ) = mbcom_call( $admin, 'GET', '/settings' );
	mbcom_check( '…it follows the network’s setting', true === $again['settings']['registration'] );
	update_site_option( 'registration', 'none' );
} else {
	mbcom_call( $admin, 'PUT', '/settings', array( 'registration' => true ) );
	mbcom_check( 'the sign-up switch is WordPress’s own registration setting', '1' === (string) get_option( 'users_can_register' ) );
	list( , $got ) = mbcom_call( $admin, 'GET', '/settings' );
	update_option( 'users_can_register', 0 );
	list( , $again ) = mbcom_call( $admin, 'GET', '/settings' );
	mbcom_check( '…and follows changes made in WordPress', true === $got['settings']['registration'] && false === $again['settings']['registration'] );
}
mbcom_check( 'with sign-up off, "Create new account" uses the owner’s own link', '' === \MemBuild\Community\Login::signup_link() );
mbcom_call( $admin, 'PUT', '/settings', array( 'signup_url' => 'https://shop.example.com/join', 'registration' => false ) );
\MemBuild\Community\Settings::flush();
mbcom_check( '…when one is set', 'https://shop.example.com/join' === \MemBuild\Community\Login::signup_link() );
$role = new ReflectionMethod( '\MemBuild\Community\Login', 'signup_role' );
$role->setAccessible( true );
update_option( 'default_role', 'editor' );
mbcom_check( 'new members never get a role that can edit others’ content', 'subscriber' === $role->invoke( null ) );
update_option( 'default_role', 'subscriber' );
$age = new ReflectionMethod( '\MemBuild\Community\Login', 'age' );
$age->setAccessible( true );
$now = getdate( current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
mbcom_check( 'age counts whole years, never rounding up', 12 === $age->invoke( null, $now['mon'], $now['year'] - 12 ) || 11 === $age->invoke( null, $now['mon'], $now['year'] - 12 ) );
mbcom_check( '…so someone born 14 years ago this month isn’t over 14', $age->invoke( null, $now['mon'], $now['year'] - 14 ) <= 14 );
mbcom_call( $admin, 'PUT', '/settings', array( 'signup_url' => '' ) );

/* ---------------------------------------------------------------- */
echo "\nWhere members land\n";
$community = \MemBuild\Community\Portal::url();
$member_u  = get_userdata( $ben );
$admin_u   = get_userdata( $admin );
mbcom_check( 'members land on the feed after logging in (default)', $community === \MemBuild\Community\Portal::login_redirect( admin_url(), '', $member_u ) );
mbcom_check( '…unless they were heading somewhere specific', home_url( '/shop/' ) === \MemBuild\Community\Portal::login_redirect( home_url( '/shop/' ), home_url( '/shop/' ), $member_u ) );
mbcom_check( '…and admins still go to the dashboard', admin_url() === \MemBuild\Community\Portal::login_redirect( admin_url(), '', $admin_u ) );
$saved = get_option( 'mbcom_settings' );
update_option( 'mbcom_settings', array_merge( (array) $saved, array( 'landing' => 'off' ) ) );
$reset = new ReflectionProperty( '\MemBuild\Community\Settings', 'cache' );
$reset->setAccessible( true );
$reset->setValue( null, null );
mbcom_check( "turned off, logging in doesn't change", admin_url() === \MemBuild\Community\Portal::login_redirect( admin_url(), '', $member_u ) );
update_option( 'mbcom_settings', $saved );
$reset->setValue( null, null );

/* ---------------------------------------------------------------- */
echo "\nLogin pages\n";
$login_page = \MemBuild\Community\Login::url( 'login' );
mbcom_check( 'WordPress login links point to the community login', 0 === strpos( wp_login_url(), $login_page ) );
mbcom_check( '…and keep where the person was going', false !== strpos( wp_login_url( home_url( '/community/members' ) ), 'redirect_to=' ) );
if ( class_exists( 'WooCommerce' ) ) {
	mbcom_check( 'with WooCommerce, lost-password links stay on its My Account page', false !== strpos( wp_lostpassword_url(), 'lost-password' ) && \MemBuild\Community\Login::url( 'forgot-password' ) !== wp_lostpassword_url() );
} else {
	mbcom_check( 'lost-password links point to the community page', \MemBuild\Community\Login::url( 'forgot-password' ) === wp_lostpassword_url() );
}
$mail = \MemBuild\Community\Login::reset_email_message( '', 'abc123', 'mbct_ana', get_userdata( $ben ) );
mbcom_check( 'the reset email links to the community reset page', false !== strpos( $mail, '/reset-password?login=mbct_ana&key=abc123' ) && false === strpos( $mail, 'wp-login.php' ) );
mbcom_check( 'logging out lands on the login page', false !== strpos( \MemBuild\Community\Login::logout_redirect( home_url( '/' ), '' ), '/login?loggedout=1' ) );
mbcom_check( "…unless the link asked for somewhere else", home_url( '/shop/' ) === \MemBuild\Community\Login::logout_redirect( home_url( '/shop/' ), home_url( '/shop/' ) ) );
$neon = \MemBuild\Community\Color::tokens( '#c8f542' );
mbcom_check( 'bright accents get dark text on buttons', '#111214' === $neon['ink'] && '#ffffff' === \MemBuild\Community\Color::tokens( '#3b5bfd' )['ink'] );
mbcom_check( '…and a darker shade for text on white that passes contrast', \MemBuild\Community\Color::contrast( $neon['text'], '#ffffff' ) >= 4.5 );
mbcom_check( 'mid-range accents pick the more readable text color', '#111214' === \MemBuild\Community\Color::tokens( '#f59e0b' )['ink'] );

/* ---------------------------------------------------------------- */
mbcom_section( 'Uploads that are taken back or deleted' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%mbcom\\_rl\\_%'" );
wp_cache_flush();
$img = imagecreatetruecolor( 40, 30 );
ob_start();
imagepng( $img );
$small_png = ob_get_clean();
$file_of   = fn( $media ) => get_attached_file( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attachment_id FROM %i WHERE id = %d', \MemBuild\Community\Install::table( 'media' ), $media['id'] ?? 0 ) ) );

$taken = mbcom_upload( $ana, 'image/png', $small_png, 'oops.png' );
mbcom_check( 'the real image size is stored, not what the browser claimed', 40 === ( $taken['width'] ?? 0 ) && 30 === ( $taken['height'] ?? 0 ) );
$path = $file_of( $taken );
list( $s ) = mbcom_call( $ben, 'DELETE', "/media/{$taken['id']}" );
mbcom_check( "a member can't delete someone else's upload", 404 === $s, (string) $s );
list( $s ) = mbcom_call( $ana, 'DELETE', "/media/{$taken['id']}" );
mbcom_check( 'a member can take back an upload they didn’t post', 200 === $s && $path && ! file_exists( $path ), (string) $s );

$posted = mbcom_upload( $ana, 'image/png', $small_png, 'keep.png' );
list( , $with_photo ) = mbcom_call( $ana, 'POST', '/posts', array( 'content' => 'With a photo', 'media_ids' => array( $posted['id'] ) ) );
$path = $file_of( $posted );
list( $s ) = mbcom_call( $ana, 'DELETE', "/media/{$posted['id']}" );
mbcom_check( '…but not one that is already posted', 404 === $s && file_exists( $path ), (string) $s );
mbcom_call( $ana, 'DELETE', "/posts/{$with_photo['id']}" );
mbcom_check( 'deleting a post deletes its photos from the server', ! file_exists( $path ) && ! \MemBuild\Community\Media::get( (int) $posted['id'] ) );

$poster = mbcom_upload( $ana, 'image/png', $small_png, 'poster.png' );
$video  = (int) $wpdb->insert( \MemBuild\Community\Install::table( 'media' ), array( 'user_id' => $ana, 'kind' => 'video', 'url' => 'https://example.test/v.mp4', 'poster_url' => \MemBuild\Community\Media::claim_poster( $ana, (int) $poster['id'] ), 'mime' => 'video/mp4', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ) ? (int) $wpdb->insert_id : 0;
$path   = $file_of( $poster );
\MemBuild\Community\Media::cleanup_orphans();
mbcom_check( 'an unposted video is cleaned up after a day, with its poster frame', ! \MemBuild\Community\Media::get( $video ) && ! \MemBuild\Community\Media::get( (int) $poster['id'] ) && ! file_exists( $path ) );

$first  = mbcom_upload( $ana, 'image/png', $small_png, 'me.png' );
mbcom_call( $ana, 'PUT', '/me/profile', array( 'avatar' => array( 'id' => $first['id'] ) ) );
$path   = $file_of( $first );
$second = mbcom_upload( $ana, 'image/png', $small_png, 'me2.png' );
mbcom_call( $ana, 'PUT', '/me/profile', array( 'avatar' => array( 'id' => $second['id'] ) ) );
mbcom_check( 'a new profile photo deletes the old one', $path && ! file_exists( $path ) && file_exists( $file_of( $second ) ) );
mbcom_call( $ana, 'PUT', '/me/profile', array( 'avatar' => null ) );

$icon_a = mbcom_upload( $admin, 'image/png', $small_png, 'icon-a.png' );
$icon_b = mbcom_upload( $admin, 'image/png', $small_png, 'icon-b.png' );
mbcom_call( $admin, 'PUT', '/settings/app-icon', array( 'media_id' => $icon_a['id'] ) );
mbcom_call( $admin, 'PUT', '/settings/app-icon', array( 'media_id' => $icon_b['id'] ) );
mbcom_check( 'a new app icon deletes the old one', ! \MemBuild\Community\Media::get( (int) $icon_a['id'] ) && \MemBuild\Community\Media::get( (int) $icon_b['id'] ) );
$logo_a = mbcom_upload( $admin, 'image/png', $small_png, 'logo-a.png' );
mbcom_call( $admin, 'PUT', '/settings', array( 'logo' => array( 'id' => $logo_a['id'] ) ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'logo' => null ) );
mbcom_check( 'removing the logo deletes it', ! \MemBuild\Community\Media::get( (int) $logo_a['id'] ) );

/* ---------------------------------------------------------------- */
mbcom_section( 'Updates from GitHub releases (with a stand-in for GitHub)' );
$fake_release = array( 'tag_name' => 'v9.9.0', 'body' => 'New things.', 'html_url' => 'https://github.com/x/y/releases/v9.9.0', 'published_at' => '2026-10-15T00:00:00Z', 'assets' => array( array( 'name' => 'membuild-community-9.9.0.zip', 'browser_download_url' => 'https://github.com/x/y/releases/download/v9.9.0/membuild-community-9.9.0.zip' ) ) );
$asked        = 0;
$stand_in     = function ( $pre, $args, $url ) use ( &$fake_release, &$asked ) {
	if ( ! str_contains( $url, 'api.github.com/repos/' ) ) {
		return $pre;
	}
	++$asked;
	return array( 'response' => array( 'code' => $fake_release ? 200 : 404 ), 'body' => wp_json_encode( $fake_release ?: array() ), 'headers' => array() );
};
add_filter( 'pre_http_request', $stand_in, 10, 3 );
$basename = plugin_basename( MEMBUILD_COMMUNITY_FILE );
delete_site_transient( \MemBuild\Community\Updater::CACHE );
$t = \MemBuild\Community\Updater::offer( (object) array( 'checked' => array( $basename => '1.0.0' ), 'response' => array() ) );
mbcom_check( 'a newer release is offered as an update', '9.9.0' === ( $t->response[ $basename ]->new_version ?? '' ) && str_ends_with( $t->response[ $basename ]->package, 'membuild-community-9.9.0.zip' ) );
\MemBuild\Community\Updater::offer( (object) array( 'checked' => array( $basename => '1.0.0' ), 'response' => array() ) );
mbcom_check( '…and GitHub is asked once, not on every check', 1 === $asked, (string) $asked );
$t = \MemBuild\Community\Updater::offer( (object) array( 'checked' => array( $basename => '9.9.0' ), 'response' => array() ) );
mbcom_check( 'the installed version isn’t offered again', ! isset( $t->response[ $basename ] ) && isset( $t->no_update[ $basename ] ) );
$info = \MemBuild\Community\Updater::details( false, 'plugin_information', (object) array( 'slug' => 'membuild-community' ) );
mbcom_check( '“View details” shows the release notes', '9.9.0' === ( $info->version ?? '' ) && str_contains( $info->sections['changelog'], 'New things.' ) );
mbcom_check( '…and leaves other plugins alone', false === \MemBuild\Community\Updater::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
$fake_release = null;
delete_site_transient( \MemBuild\Community\Updater::CACHE );
$t = \MemBuild\Community\Updater::offer( (object) array( 'checked' => array( $basename => '1.0.0' ), 'response' => array() ) );
mbcom_check( 'no release (or GitHub unreachable) offers nothing', ! isset( $t->response[ $basename ] ) );
remove_filter( 'pre_http_request', $stand_in, 10 );
delete_site_transient( \MemBuild\Community\Updater::CACHE );

/* ---------------------------------------------------------------- */
mbcom_section( 'Search' );
$finder  = mbcom_user( 'mbct_finder' );
$author  = mbcom_user( 'mbct_writer' );
$buyer   = mbcom_user( 'mbct_buyer' );
$wpdb->delete( "{$wpdb->prefix}mbcom_points", array( 'user_id' => $finder ) );
$wpdb->delete( "{$wpdb->prefix}mbcom_member_stats", array( 'user_id' => $finder ) );
wp_update_user( array( 'ID' => $author, 'display_name' => 'Quokka Writer' ) );
$find    = function ( $user, $q, $type = 'all' ) {
	list( $status, $res ) = mbcom_call( $user, 'GET', '/search', array( 'q' => $q, 'type' => $type ) );
	return 200 === $status ? $res : array( 'status' => $status );
};
$kinds   = fn( $res, $group ) => wp_list_pluck( $res['groups'][ $group ]['items'] ?? array(), 'id' );

list( , $sp ) = mbcom_call( $author, 'POST', '/posts', array( 'content' => 'Our zebrafruit pricing plan is live' ) );
list( , $sc ) = mbcom_call( $finder, 'POST', "/posts/{$sp['id']}/comments", array( 'content' => 'Love the zebrafruit idea' ) );
$res = $find( $finder, 'zebrafruit' );
mbcom_check( 'finds posts and comments by their words', in_array( $sp['id'], $kinds( $res, 'posts' ), true ) && in_array( $sc['id'], $kinds( $res, 'comments' ), true ) );
mbcom_check( '…every word has to match', ! in_array( $sp['id'], $kinds( $find( $finder, 'zebrafruit banana' ), 'posts' ), true ) && in_array( $sp['id'], $kinds( $find( $finder, 'pricing zebrafruit' ), 'posts' ), true ) );
mbcom_check( 'results quote the text around the match and link to the post', str_contains( $res['groups']['posts']['items'][0]['snippet'], 'zebrafruit' ) && array( 'post' => $sp['id'] ) === $res['groups']['posts']['items'][0]['link']['query'] );
mbcom_check( 'members are found by name', in_array( $author, $kinds( $find( $finder, 'quokka' ), 'members' ), true ) );
mbcom_check( '…never by login name or email', ! in_array( $author, $kinds( $find( $finder, 'mbct_writer' ), 'members' ), true ) );
mbcom_check( 'a single letter searches nothing', ! array_filter( wp_list_pluck( $find( $finder, 'z' )['groups'], 'items' ) ) );
list( $s ) = mbcom_call( 0, 'GET', '/search', array( 'q' => 'zebrafruit' ) );
mbcom_check( 'visitors who aren’t signed in can’t search', in_array( $s, array( 401, 403 ), true ), (string) $s );

mbcom_call( $finder, 'PUT', "/members/{$author}/block" );
$res = $find( $finder, 'zebrafruit' );
mbcom_check( 'people you blocked don’t show up, nor their posts', ! in_array( $sp['id'], $kinds( $res, 'posts' ), true ) && ! in_array( $author, $kinds( $find( $finder, 'quokka' ), 'members' ), true ) );
mbcom_check( '…nor comments on their posts', ! in_array( $sc['id'], $kinds( $res, 'comments' ), true ) );
mbcom_call( $finder, 'DELETE', "/members/{$author}/block" );
mbcom_call( $author, 'DELETE', "/posts/{$sp['id']}" );
mbcom_check( 'deleted posts aren’t found', ! in_array( $sp['id'], $kinds( $find( $finder, 'zebrafruit' ), 'posts' ), true ) );

mbcom_check( 'private messages aren’t a kind of result at all', ! isset( $find( $finder, 'zebrafruit' )['groups']['messages'] ) );

// The Classroom: one open course, one locked by a group, one hidden when locked, and a draft.
$make = function ( $title, $lesson_title, $text, array $course_args ) use ( $admin ) {
	list( , $c ) = mbcom_call( $admin, 'POST', '/courses', array( 'title' => $title ) );
	list( , $l ) = mbcom_call( $admin, 'POST', "/modules/{$c['modules'][0]['id']}/lessons", array( 'title' => $lesson_title ) );
	mbcom_call( $admin, 'PUT', "/lessons/{$l['id']}", array( 'content' => $text ) );
	mbcom_call( $admin, 'PUT', "/courses/{$c['id']}", $course_args + array( 'status' => 'published' ) );
	return array( $c['id'], $l['id'] );
};
list( , $club ) = mbcom_call( $admin, 'POST', '/groups', array( 'name' => 'Ocelot club', 'roles' => array( 'author' ) ) );
list( $open_c, $open_l )     = $make( 'Ocelot basics', 'Ocelot first steps', 'The ocelot handshake explained.', array() );
list( $lock_c, $lock_l )     = $make( 'Ocelot advanced', 'Ocelot secrets', 'The ocelot vault code is 1234.', array( 'access' => 'groups', 'access_groups' => array( $club['id'] ) ) );
list( $hide_c, $hide_l )     = $make( 'Ocelot hidden', 'Ocelot backstage', 'Ocelot backstage pass.', array( 'access' => 'groups', 'access_groups' => array( $club['id'] ), 'hide_locked' => true ) );
list( $draft_c, $draft_l )   = $make( 'Ocelot draft', 'Ocelot unfinished', 'Ocelot work in progress.', array( 'status' => 'draft' ) );
$fresh->setValue( null, array() );

$items = $find( $finder, 'ocelot', 'courses' )['items'];
$by    = function ( $kind, $id ) use ( &$items ) { // by reference: $items changes below
	return current( array_filter( $items, fn( $i ) => $kind === $i['kind'] && $id === $i['id'] ) ) ?: null;
};
mbcom_check( 'open courses and their lessons are found', $by( 'course', $open_c ) && $by( 'lesson', $open_l ) );
mbcom_check( 'a locked course’s lessons show by title, with the lock', $by( 'lesson', $lock_l ) && 'groups' === $by( 'lesson', $lock_l )['lock']['reason'] && array( 'Ocelot club' ) === $by( 'lesson', $lock_l )['lock']['groups'] );
mbcom_check( '…but their text is never quoted', '' === $by( 'lesson', $lock_l )['snippet'] && ! str_contains( wp_json_encode( $items ), '1234' ) );
mbcom_check( '…nor searched', ! array_filter( $find( $finder, 'vault', 'courses' )['items'], fn( $i ) => $i['id'] === $lock_l ) );
mbcom_check( 'an open lesson is found by its text too', (bool) array_filter( $find( $finder, 'handshake', 'courses' )['items'], fn( $i ) => 'lesson' === $i['kind'] && $i['id'] === $open_l ) );
mbcom_check( 'a course hidden when locked doesn’t appear at all', ! $by( 'course', $hide_c ) && ! $by( 'lesson', $hide_l ) );
mbcom_check( 'drafts don’t appear for members', ! $by( 'course', $draft_c ) && ! $by( 'lesson', $draft_l ) );

mbcom_call( $admin, 'PUT', "/lessons/{$open_l}", array( 'drip_days' => 3650 ) );
$dripped = $find( $finder, 'handshake', 'courses' )['items'];
$titled  = current( array_filter( $find( $finder, 'ocelot first', 'courses' )['items'], fn( $i ) => $i['id'] === $open_l ) );
mbcom_check( 'a lesson that isn’t released yet is found by title only, with its date', ! array_filter( $dripped, fn( $i ) => $i['id'] === $open_l ) && $titled && $titled['unlock_at'] && '' === $titled['snippet'] );

( new WP_User( $buyer ) )->set_role( 'author' ); // a membership plugin gives the role
$fresh->setValue( null, array() );
$items = $find( $buyer, 'ocelot', 'courses' )['items'];
mbcom_check( 'with the course’s role, the locked course opens up in search', $by( 'lesson', $lock_l ) && null === $by( 'lesson', $lock_l )['lock'] && $by( 'lesson', $hide_l ) && (bool) array_filter( $find( $buyer, 'vault', 'courses' )['items'], fn( $i ) => $i['id'] === $lock_l ) );
( new WP_User( $buyer ) )->set_role( 'subscriber' ); // …and takes it away
$fresh->setValue( null, array() );
$items = $find( $buyer, 'ocelot', 'courses' )['items'];
mbcom_check( '…and losing the role locks it again at once', null !== $by( 'lesson', $lock_l )['lock'] && ! $by( 'lesson', $hide_l ) );
$items = $find( $admin, 'ocelot', 'courses' )['items'];
mbcom_check( 'admins find everything, drafts included', $by( 'course', $hide_c ) && $by( 'course', $draft_c ) && $by( 'course', $draft_c )['draft'] && str_contains( wp_json_encode( $find( $admin, 'vault', 'courses' )['items'] ), '1234' ) );

mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'classroom' => false ) ) );
\MemBuild\Community\Settings::flush();
mbcom_check( 'with the Classroom off, courses aren’t searched', ! isset( $find( $finder, 'ocelot' )['groups']['courses'] ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'classroom' => true, 'search' => false ) ) );
\MemBuild\Community\Settings::flush();
mbcom_check( 'search can be switched off', 403 === ( $find( $finder, 'ocelot' )['status'] ?? 0 ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'search' => true ) ) );
\MemBuild\Community\Settings::flush();

delete_option( \MemBuild\Community\Search::INDEX_OPTION );
\MemBuild\Community\Search::add_index();
$state = get_option( \MemBuild\Community\Search::INDEX_OPTION );
mbcom_check( 'the background job adds a full-text index to posts and comments', 'ready' === ( $state['posts'] ?? '' ) && 'ready' === ( $state['comments'] ?? '' ), wp_json_encode( $state ) );
list( , $sp2 ) = mbcom_call( $author, 'POST', '/posts', array( 'content' => 'Wombatcake recipe for the meetup' ) );
mbcom_check( '…and search uses it', in_array( $sp2['id'], $kinds( $find( $finder, 'wombatcake recipe' ), 'posts' ), true ) && in_array( $sp2['id'], $kinds( $find( $finder, 'wombat' ), 'posts' ), true ) );
mbcom_call( $author, 'DELETE', "/posts/{$sp2['id']}" );

foreach ( array( $open_c, $lock_c, $hide_c, $draft_c ) as $c ) {
	mbcom_call( $admin, 'DELETE', "/courses/{$c}" );
}
mbcom_call( $admin, 'DELETE', "/groups/{$club['id']}" );

/* ---------------------------------------------------------------- */
mbcom_section( 'Credit' );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'toplevel_page_membuild-community' );
$ours = \MemBuild\Community\Admin::footer( 'Thank you for creating with WordPress.' );
set_current_screen( 'dashboard' );
$theirs = \MemBuild\Community\Admin::footer( 'Thank you for creating with WordPress.' );
mbcom_check( 'the Community pages in wp-admin say “Built by MemBuild”', str_contains( $ours, 'Built by' ) && str_contains( $ours, 'membuild.com' ) && str_contains( $ours, MEMBUILD_COMMUNITY_VERSION ) );
mbcom_check( '…and other admin pages keep WordPress’s own footer', 'Thank you for creating with WordPress.' === $theirs );
$GLOBALS['current_screen'] = null;

/* ---------------------------------------------------------------- */
mbcom_section( 'Anonymous usage reports (with a stand-in for MemBuild)' );
delete_option( \MemBuild\Community\Usage::OPTION );
$sent        = array();
$forget_code = 200;
$stand_in    = function ( $pre, $args, $url ) use ( &$sent, &$forget_code ) {
	if ( ! str_starts_with( $url, \MemBuild\Community\Usage::ENDPOINT ) ) {
		return $pre;
	}
	$sent[] = array( 'url' => $url, 'body' => (string) $args['body'] );
	$code   = str_contains( $url, 'community_forget' ) ? $forget_code : 200;
	return array( 'response' => array( 'code' => $code ), 'body' => '{"ok":true}', 'headers' => array() );
};
add_filter( 'pre_http_request', $stand_in, 10, 3 );
add_filter( 'membuild_community/usage_reports', '__return_true' );

$id = \MemBuild\Community\Usage::state()['id'];
mbcom_check( 'each site gets a random install ID, kept between visits', wp_is_uuid( $id, 4 ) && \MemBuild\Community\Usage::state()['id'] === $id );
mbcom_check( 'counts are sent as ranges, never exact', '0' === \MemBuild\Community\Usage::range( 0 ) && '1-10' === \MemBuild\Community\Usage::range( 7 ) && '51-200' === \MemBuild\Community\Usage::range( 51 ) && '201-1000' === \MemBuild\Community\Usage::range( 1000 ) && '5001+' === \MemBuild\Community\Usage::range( 6000 ) );
$report = \MemBuild\Community\Usage::report();
mbcom_check( 'without sharing, the report is only the ID and versions', array( 'install_id', 'plugin', 'wp', 'php', 'locale', 'multisite', 'shared' ) === array_keys( $report ) && false === $report['shared'] );
list( $code ) = mbcom_call( $ana, 'GET', '/settings/usage' );
mbcom_check( 'members can’t see or change sharing', 403 === $code, (string) $code );
list( $code, $got ) = mbcom_call( $admin, 'PUT', '/settings/usage', array( 'share' => true, 'niche' => 'courses', 'niche_other' => 'ignored', 'size' => 'huge' ) );
mbcom_check( 'an admin turns sharing on; unknown answers are dropped', 200 === $code && true === $got['share'] && 'courses' === $got['niche'] && '' === $got['niche_other'] && '' === $got['size'] );
mbcom_call( $admin, 'PUT', '/settings/usage', array( 'niche' => 'other', 'niche_other' => '  Quilting <b>circle</b> and much more text than forty characters allows ' ) );
$report = \MemBuild\Community\Usage::report();
mbcom_check( '…“Something else” keeps a short description', 'other' === $report['niche'] && mb_strlen( $report['niche_other'] ) <= 40 && ! str_contains( $report['niche_other'], '<' ) );
mbcom_check( 'shared reports add features and rough counts', isset( $report['features']['classroom'], $report['members'], $report['posts'] ) && in_array( $report['members'], array( '0', '1-10', '11-50', '51-200', '201-1000', '1001-5000', '5001+' ), true ) );
$sent = array();
mbcom_check( 'the weekly report goes to MemBuild', \MemBuild\Community\Usage::send() && 1 === count( $sent ) && str_contains( $sent[0]['url'], 'action=community_report' ) );
$host  = wp_parse_url( home_url(), PHP_URL_HOST );
$email = get_userdata( $admin )->user_email;
mbcom_check( '…and never carries the site address, emails or names', ! str_contains( $sent[0]['body'], $host ) && ! str_contains( $sent[0]['body'], $email ) && ! str_contains( $sent[0]['body'], 'mbct_' ) && ! str_contains( $sent[0]['body'], get_bloginfo( 'name' ) ) );
mbcom_check( 'Settings shows exactly what’s sent', mbcom_call( $admin, 'GET', '/settings/usage' )[1]['report'] == json_decode( $sent[0]['body'], true ) );

$forget_code = 500;
list( $code ) = mbcom_call( $admin, 'POST', '/settings/usage/forget' );
mbcom_check( 'if MemBuild can’t be reached, “delete my data” says so and changes nothing', 502 === $code && \MemBuild\Community\Usage::state()['id'] === $id && \MemBuild\Community\Usage::state()['share'] );
$forget_code = 200;
list( $code, $got ) = mbcom_call( $admin, 'POST', '/settings/usage/forget' );
mbcom_check( '…otherwise sharing stops and the site gets a new ID', 200 === $code && false === $got['share'] && $got['id'] !== $id && str_contains( end( $sent )['body'], $id ) );

delete_option( \MemBuild\Community\Usage::OPTION );
wp_set_current_user( $admin );
ob_start();
\MemBuild\Community\Usage::setup_card();
$card = ob_get_clean();
mbcom_check( 'the overview asks about the community once', str_contains( $card, 'name="niche"' ) && str_contains( $card, 'name="share"' ) && ! str_contains( $card, 'name="share" value="1" checked' ) );
\MemBuild\Community\Usage::update( array( 'asked' => true ) );
ob_start();
\MemBuild\Community\Usage::setup_card();
mbcom_check( '…and not again after it’s answered or skipped', '' === ob_get_clean() );

remove_filter( 'membuild_community/usage_reports', '__return_true' );
add_filter( 'membuild_community/usage_reports', '__return_false' );
$sent = array();
mbcom_check( 'the filter turns reports off entirely', false === \MemBuild\Community\Usage::send() && ! $sent );
remove_filter( 'membuild_community/usage_reports', '__return_false' );
remove_filter( 'pre_http_request', $stand_in, 10 );
delete_option( \MemBuild\Community\Usage::OPTION );
wp_clear_scheduled_hook( \MemBuild\Community\Usage::HOOK . '_now' );

/* ---------------------------------------------------------------- */
echo "\n{$GLOBALS['mbcom_passed']} passed, {$GLOBALS['mbcom_failed']} failed\n";
if ( $GLOBALS['mbcom_failed'] ) {
	exit( 1 );
}
