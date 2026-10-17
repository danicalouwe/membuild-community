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
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", '_transient_mbcom_rl_%', '_transient_timeout_mbcom_rl_%', '_transient%mbcom_shared_%', 'mbcom\\_rl\\_%' ) );
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
$hi = end( $hist['items'] );
list( $s, $r ) = mbcom_call( $ben, 'PUT', "/messages/{$hi['id']}/reaction", array( 'type' => '🥳' ) );
mbcom_check( 'any one emoji can react to a message, like Messenger’s “+”', 200 === $s && '🥳' === $r['my_reaction'], wp_json_encode( $r['my_reaction'] ?? $r ) );
list( , $r ) = mbcom_call( $ben, 'PUT', "/messages/{$hi['id']}/reaction", array( 'type' => '👩🏽‍💻' ) );
mbcom_check( '…skin tones and joined emoji included', '👩🏽‍💻' === $r['my_reaction'] );
list( , $r ) = mbcom_call( $ben, 'PUT', "/messages/{$hi['id']}/reaction", array( 'type' => '<b>hi</b>' ) );
mbcom_check( '…but words and markup aren’t reactions', 'love' === $r['my_reaction'] );
mbcom_call( $ben, 'DELETE', "/messages/{$hi['id']}/reaction" );
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
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%mbcom\\_rl\\_%' OR option_name LIKE 'mbcom\\_rl\\_%'" );
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
$pic = mbcom_upload( $author, 'image/png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ), 'zebrafruit.png' );
list( , $ip ) = mbcom_call( $author, 'POST', '/posts', array( 'content' => 'zebrafruit orchard photo', 'media_ids' => array( $pic['id'] ?? 0 ) ) );
// A reel with a video whose poster is known, written straight in (uploads of real video are tested elsewhere).
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( \MemBuild\Community\Install::table( 'posts' ), array( 'user_id' => $author, 'type' => 'reel', 'content' => 'zebrafruit harvest short', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
$ir = (int) $wpdb->insert_id;
$wpdb->insert( \MemBuild\Community\Install::table( 'media' ), array( 'user_id' => $author, 'post_id' => $ir, 'kind' => 'video', 'url' => home_url( '/zebrafruit.mp4' ), 'poster_url' => home_url( '/zebrafruit-poster.jpg' ), 'mime' => 'video/mp4', 'width' => 720, 'height' => 1280, 'created_at' => $now ) );
$by_id = array_column( $find( $finder, 'zebrafruit' )['groups']['posts']['items'], null, 'id' );
mbcom_check( 'a post with a photo shows it in the results; a plain post has no picture and isn’t video', ( $by_id[ $ip['id'] ]['thumb'] ?? '' ) === $pic['url'] && false === $by_id[ $ip['id'] ]['video'] && null === $by_id[ $sp['id'] ]['thumb'] && false === $by_id[ $sp['id'] ]['video'], wp_json_encode( $by_id[ $ip['id'] ] ?? null ) );
mbcom_check( '…and a reel shows its poster and is marked as video', 'reel' === ( $by_id[ $ir ]['kind'] ?? '' ) && true === $by_id[ $ir ]['video'] && home_url( '/zebrafruit-poster.jpg' ) === $by_id[ $ir ]['thumb'] );
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
mbcom_section( 'Places' );
$guide  = mbcom_user( 'mbct_guide' );
$hiker  = mbcom_user( 'mbct_hiker' );
\MemBuild\Community\Settings::flush();
mbcom_check( 'Places is off by default', false === \MemBuild\Community\Settings::defaults()['features']['places'] );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'places' => false ) ) );
\MemBuild\Community\Settings::flush();
list( $s ) = mbcom_call( $guide, 'GET', '/places' );
mbcom_check( '…and while it’s off, nobody can use it', 403 === $s, (string) $s );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'places' => true ) ) );
\MemBuild\Community\Settings::flush();

list( $s, $err ) = mbcom_call( $admin, 'POST', '/places', array( 'name' => 'Lake café' ) );
mbcom_check( 'a place needs a spot on the map', 400 === $s && 'mbcom_invalid_place' === $err['code'], (string) $s );
list( $s, $cafe ) = mbcom_call( $admin, 'POST', '/places', array( 'name' => 'Lake café', 'category' => 'perk', 'perk' => '10% off', 'address' => '1 Lake St', 'lat' => 47.6, 'lng' => -122.3, 'website' => 'lakecafe.example', 'description' => '<b>Cozy</b>' ) );
mbcom_check( 'admins add places, published at once', 201 === $s && 'published' === $cafe['status'] && 'perk' === $cafe['category'] && '10% off' === $cafe['perk'], (string) $s );
mbcom_check( '…with a tidy website address and plain text', 'https://lakecafe.example' === $cafe['website'] && ! str_contains( $cafe['description'], '<' ) );
list( , $js ) = mbcom_call( $admin, 'PUT', "/places/{$cafe['id']}", array( 'website' => 'javascript:alert(1)' ) );
mbcom_check( '…and only web links: javascript: and the like are dropped', '' === $js['website'] );
mbcom_call( $admin, 'PUT', "/places/{$cafe['id']}", array( 'website' => 'lakecafe.example' ) );
list( , $list ) = mbcom_call( $guide, 'GET', '/places' );
mbcom_check( 'members see published places', in_array( $cafe['id'], wp_list_pluck( $list['items'], 'id' ), true ) && false === $list['can_edit'] );

list( $s, $tip ) = mbcom_call( $guide, 'POST', '/places', array( 'name' => 'Trail head', 'category' => 'meetup', 'lat' => 47.7, 'lng' => -122.2 ) );
mbcom_check( 'members can suggest a place, which waits for review', 201 === $s && 'pending' === $tip['status'], (string) $s );
list( , $list ) = mbcom_call( $hiker, 'GET', '/places' );
list( $s ) = mbcom_call( $hiker, 'GET', "/places/{$tip['id']}" );
mbcom_check( '…unseen by other members until then', ! in_array( $tip['id'], wp_list_pluck( $list['items'], 'id' ), true ) && 404 === $s );
list( , $list ) = mbcom_call( $guide, 'GET', '/places' );
mbcom_check( '…but shown to the member who suggested it', in_array( $tip['id'], wp_list_pluck( $list['items'], 'id' ), true ) );
list( $s ) = mbcom_call( $guide, 'PUT', "/places/{$tip['id']}", array( 'status' => 'published' ) );
mbcom_check( 'members can’t approve or edit places', 403 === $s, (string) $s );
list( $s ) = mbcom_call( $guide, 'PUT', "/places/{$tip['id']}/going" );
mbcom_check( 'nobody can say they go to a place that isn’t approved', 404 === $s, (string) $s );
list( $s, $ok ) = mbcom_call( $admin, 'PUT', "/places/{$tip['id']}", array( 'status' => 'published' ) );
list( $s2 ) = mbcom_call( $hiker, 'GET', "/places/{$tip['id']}" );
mbcom_check( 'an admin approves it, and everyone sees it', 200 === $s && 'published' === $ok['status'] && 200 === $s2 );

list( $s, $went ) = mbcom_call( $hiker, 'PUT', "/places/{$cafe['id']}/going" );
mbcom_call( $hiker, 'PUT', "/places/{$cafe['id']}/going" ); // twice: still once
list( , $one ) = mbcom_call( $guide, 'GET', "/places/{$cafe['id']}" );
mbcom_check( '“I go here” counts once and shows who', 200 === $s && $went['going'] && 1 === $one['going_count'] && array( $hiker ) === wp_list_pluck( $one['members'], 'id' ) );
mbcom_call( $guide, 'PUT', "/members/{$hiker}/block" );
list( , $one ) = mbcom_call( $guide, 'GET', "/places/{$cafe['id']}" );
mbcom_check( '…except people you blocked', ! in_array( $hiker, wp_list_pluck( $one['members'], 'id' ), true ) );
mbcom_call( $guide, 'DELETE', "/members/{$hiker}/block" );
list( , $back ) = mbcom_call( $hiker, 'DELETE', "/places/{$cafe['id']}/going" );
mbcom_check( '…and can be taken back', false === $back['going'] && 0 === $back['going_count'] );

list( , $res ) = mbcom_call( $guide, 'GET', '/search', array( 'q' => 'lake' ) );
mbcom_check( 'search finds places', in_array( $cafe['id'], wp_list_pluck( $res['groups']['places']['items'] ?? array(), 'id' ), true ) );
mbcom_check( 'the app gets the map tiles only while Places is on', isset( \MemBuild\Community\Settings::public_config()['mapTiles']['url'] ) );

$geo_calls  = array();
$geo_stand  = function ( $pre, $args, $url ) use ( &$geo_calls ) {
	if ( ! str_contains( $url, 'nominatim.openstreetmap.org' ) ) {
		return $pre;
	}
	$geo_calls[] = array( $url, $args['headers']['User-Agent'] ?? '' );
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( array( 'display_name' => '1 Lake St, Seattle', 'lat' => '47.61', 'lon' => '-122.33' ) ) ), 'headers' => array() );
};
add_filter( 'pre_http_request', $geo_stand, 10, 3 );
delete_transient( 'mbcom_geo_' . md5( '1 lake st, seattle' ) );
list( $s, $geo ) = mbcom_call( $guide, 'GET', '/places/geocode', array( 'q' => '1 Lake St, Seattle' ) );
mbcom_call( $guide, 'GET', '/places/geocode', array( 'q' => '1 Lake St, Seattle' ) );
mbcom_check( 'an address is looked up once, then remembered', 200 === $s && 47.61 === $geo['items'][0]['lat'] && 1 === count( $geo_calls ) );
mbcom_check( '…sending only the address, with a User-Agent naming the site', str_contains( $geo_calls[0][0], 'Lake' ) && str_contains( $geo_calls[0][1], 'MemBuild-Community/' ) && str_contains( $geo_calls[0][1], home_url( '/' ) ) );
remove_filter( 'pre_http_request', $geo_stand, 10 );

list( $s ) = mbcom_call( $guide, 'DELETE', "/places/{$cafe['id']}" );
mbcom_check( 'members can’t remove places', 403 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'DELETE', "/places/{$cafe['id']}" );
mbcom_call( $admin, 'DELETE', "/places/{$tip['id']}" );
mbcom_check( 'admins can', 200 === $s && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_reactions WHERE object_type = 'place' AND object_id = %d", $cafe['id'] ) ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'places' => false ) ) );
\MemBuild\Community\Settings::flush();

/* ---------------------------------------------------------------- */
mbcom_section( 'GIFs from GIPHY' );
$gif = array( 'id' => 'xT9IgG50Fb7Mi0prBC', 'url' => 'https://media2.giphy.com/media/v1.Y2lkPTc5/xT9IgG50Fb7Mi0prBC/giphy.webp?cid=79&rid=giphy.webp&ct=g', 'mp4' => 'https://media2.giphy.com/media/v1.Y2lkPTc5/xT9IgG50Fb7Mi0prBC/giphy.mp4?cid=79&rid=giphy.mp4&ct=g', 'still' => 'https://media2.giphy.com/media/v1.Y2lkPTc5/xT9IgG50Fb7Mi0prBC/giphy_s.gif?cid=79&ct=g', 'width' => 480, 'height' => 270, 'title' => 'Happy dance' );
mbcom_call( $admin, 'PUT', '/settings', array( 'gifs' => array( 'key' => '' ) ) );
\MemBuild\Community\Settings::flush();
list( $s ) = mbcom_call( $ana, 'POST', '/media/gif', $gif );
mbcom_check( 'without a GIPHY key there’s no GIF picker', 403 === $s && null === \MemBuild\Community\Settings::public_config()['giphy'], (string) $s );
list( $s, $err ) = mbcom_call( $admin, 'PUT', '/settings', array( 'gifs' => array( 'key' => 'not a key!' ) ) );
mbcom_check( 'a key that isn’t one is refused', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'PUT', '/settings', array( 'gifs' => array( 'key' => 'AbCdEf0123456789AbCdEf0123456789', 'rating' => 'nc-17' ) ) );
\MemBuild\Community\Settings::flush();
$giphy = \MemBuild\Community\Settings::public_config()['giphy'];
mbcom_check( 'with a key, the app gets it and a safe rating', 200 === $s && 'AbCdEf0123456789AbCdEf0123456789' === $giphy['key'] && 'pg' === $giphy['rating'] );

list( $s, $m ) = mbcom_call( $ana, 'POST', '/media/gif', $gif );
mbcom_check( 'a picked GIF becomes the member’s own image, at GIPHY’s address unchanged', 201 === $s && 'image' === $m['kind'] && $gif['url'] === $m['url'] && 'giphy' === $m['via'] && 480 === $m['width'], (string) $s );
mbcom_check( '…with GIPHY’s video version to play and its first frame, also unchanged', $gif['mp4'] === $m['mp4'] && $gif['still'] === $m['poster'] );
foreach ( array(
	'another site'       => array( 'url' => 'https://evil.example/giphy.webp' ),
	'a look-alike host'  => array( 'url' => 'https://media.giphy.com.evil.example/x.gif' ),
	'plain http'         => array( 'url' => 'http://media.giphy.com/media/x/giphy.gif' ),
	'a script link'      => array( 'url' => 'javascript:alert(1)' ),
	'a silly size'       => array( 'width' => 99999 ),
	'a bad ID'           => array( 'id' => '../../etc' ),
	'a video elsewhere'  => array( 'mp4' => 'https://evil.example/x.mp4' ),
	'a frame elsewhere'  => array( 'still' => 'javascript:alert(1)' ),
) as $what => $bad ) {
	list( $s ) = mbcom_call( $ana, 'POST', '/media/gif', $bad + $gif );
	mbcom_check( "refused: {$what}", 400 === $s, (string) $s );
}

list( $s, $gp ) = mbcom_call( $ana, 'POST', '/posts', array( 'content' => 'Friday mood', 'media_ids' => array( $m['id'] ) ) );
mbcom_check( 'a GIF goes in a post like a photo', 201 === $s && $gif['url'] === ( $gp['media'][0]['url'] ?? '' ) && 'giphy' === ( $gp['media'][0]['via'] ?? '' ) && $gif['mp4'] === ( $gp['media'][0]['mp4'] ?? '' ), (string) $s );
list( , $m2 ) = mbcom_call( $ben, 'POST', '/media/gif', $gif );
list( $s, $gc ) = mbcom_call( $ben, 'POST', "/posts/{$gp['id']}/comments", array( 'content' => '', 'media_id' => $m2['id'] ) );
mbcom_check( '…in a comment', in_array( $s, array( 200, 201 ), true ) && $gif['url'] === ( $gc['image']['url'] ?? '' ) && $gif['mp4'] === ( $gc['image']['mp4'] ?? '' ), (string) $s );
list( , $m3 ) = mbcom_call( $ana, 'POST', '/media/gif', $gif );
list( $s, $gm ) = mbcom_call( $ana, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => '', 'media_ids' => array( $m3['id'] ) ) );
list( , $hist ) = mbcom_call( $ben, 'GET', "/messages/threads/{$tid}/messages" );
mbcom_check( '…and in a chat, still pointing at GIPHY (nothing to protect on this site)', in_array( $s, array( 200, 201 ), true ) && $gif['url'] === ( end( $hist['items'] )['media'][0]['url'] ?? '' ) && $gif['mp4'] === ( end( $hist['items'] )['media'][0]['mp4'] ?? '' ), wp_json_encode( end( $hist['items'] )['media'] ?? null ) );
$plain = mbcom_upload( $ana, 'image/png', $small_png, 'plain.png' );
mbcom_check( 'an ordinary photo has no video version', null === ( $plain['mp4'] ?? null ) );
list( , $m4 ) = mbcom_call( $ana, 'POST', '/media/gif', $gif );
list( $s ) = mbcom_call( $ben, 'POST', '/posts', array( 'content' => 'stolen', 'media_ids' => array( $m4['id'] ) ) );
mbcom_check( 'nobody can post someone else’s picked GIF', ! $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}mbcom_media WHERE id = %d", $m4['id'] ) ) );
mbcom_call( $ana, 'DELETE', "/media/{$m4['id']}" );
mbcom_call( $ana, 'DELETE', "/posts/{$gp['id']}" );
mbcom_call( $admin, 'PUT', '/settings', array( 'gifs' => array( 'key' => '' ) ) );
\MemBuild\Community\Settings::flush();

/* ---------------------------------------------------------------- */
mbcom_section( 'Course discussions' );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'classroom' => true, 'search' => true ) ) );
\MemBuild\Community\Settings::flush();
list( , $tc )  = mbcom_call( $admin, 'POST', '/courses', array( 'title' => 'Talk course' ) );
$tcid          = $tc['id'];
$tmod          = $tc['modules'][0]['id'];
list( , $ta )  = mbcom_call( $admin, 'POST', "/modules/{$tmod}/lessons", array( 'title' => 'Open lesson' ) );
list( , $tb )  = mbcom_call( $admin, 'POST', "/modules/{$tmod}/lessons", array( 'title' => 'Later lesson' ) );
mbcom_call( $admin, 'PUT', "/lessons/{$tb['id']}", array( 'drip_days' => 3650 ) );
mbcom_call( $admin, 'PUT', "/courses/{$tcid}", array( 'status' => 'published' ) );
$asker   = mbcom_user( 'mbct_asker' );
$helper  = mbcom_user( 'mbct_helper' );
$outside = mbcom_user( 'mbct_outsider' );

list( $s, $q ) = mbcom_call( $asker, 'POST', '/posts', array( 'content' => 'How do zebracornish pricing tiers work?', 'lesson_id' => $ta['id'] ) );
mbcom_check( 'members post under a lesson like on the feed', 201 === $s && $tcid === ( $q['course']['id'] ?? 0 ) && $ta['id'] === ( $q['lesson']['id'] ?? 0 ), (string) $s );
list( , $feed ) = mbcom_call( $asker, 'GET', '/feed' );
list( , $prof ) = mbcom_call( $asker, 'GET', '/feed', array( 'author' => $asker ) );
mbcom_check( '…which stays in the course: not in the feed or on their profile', ! in_array( $q['id'], wp_list_pluck( $feed['items'], 'id' ), true ) && ! in_array( $q['id'], wp_list_pluck( $prof['items'], 'id' ), true ) );
list( , $lp ) = mbcom_call( $helper, 'GET', "/lessons/{$ta['id']}/posts" );
list( , $cp ) = mbcom_call( $helper, 'GET', "/courses/{$tcid}/posts" );
mbcom_check( 'it shows under the lesson and in the course’s Discussion tab', in_array( $q['id'], wp_list_pluck( $lp['items'], 'id' ), true ) && in_array( $q['id'], wp_list_pluck( $cp['items'], 'id' ), true ) && 'Open lesson' === $cp['items'][0]['lesson']['title'] );
list( $s ) = mbcom_call( $asker, 'POST', '/posts', array( 'content' => 'Early!', 'lesson_id' => $tb['id'] ) );
mbcom_check( 'a lesson that hasn’t opened yet can’t be posted under', 403 === $s, (string) $s );
list( $s, $gen ) = mbcom_call( $helper, 'POST', '/posts', array( 'content' => 'Hi everyone, glad to be here', 'course_id' => $tcid ) );
mbcom_check( 'posts can go in the Discussion tab without a lesson', 201 === $s && null === $gen['lesson'], (string) $s );

// Helpful
list( $s ) = mbcom_call( $asker, 'PUT', "/posts/{$q['id']}/helpful" );
mbcom_check( 'nobody can vote their own post helpful', 400 === $s, (string) $s );
list( $s, $v ) = mbcom_call( $helper, 'PUT', "/posts/{$q['id']}/helpful" );
mbcom_call( $helper, 'PUT', "/posts/{$q['id']}/helpful" );
mbcom_check( 'others can, once each', 200 === $s && 1 === $v['helpful_count'] && $v['my_helpful'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT helpful_count FROM %i WHERE id = %d', "{$wpdb->prefix}mbcom_posts", $q['id'] ) ) );
list( , $newer ) = mbcom_call( $helper, 'POST', '/posts', array( 'content' => 'Loved this one', 'lesson_id' => $ta['id'] ) );
list( , $lp ) = mbcom_call( $helper, 'GET', "/lessons/{$ta['id']}/posts" );
mbcom_check( 'a lesson lists its most helpful posts first', $q['id'] === $lp['items'][0]['id'] && $lp['items'][0]['my_helpful'] );
list( , $lp ) = mbcom_call( $helper, 'GET', "/lessons/{$ta['id']}/posts", array( 'sort' => 'newest' ) );
mbcom_check( '…or the newest', $newer['id'] === $lp['items'][0]['id'] );
list( , $v ) = mbcom_call( $helper, 'DELETE', "/posts/{$q['id']}/helpful" );
mbcom_check( 'a vote can be taken back', 0 === $v['helpful_count'] && ! $v['my_helpful'] );
mbcom_call( $helper, 'PUT', "/posts/{$q['id']}/helpful" );

// Best answer
list( , $ans ) = mbcom_call( $helper, 'POST', "/posts/{$q['id']}/comments", array( 'content' => 'Start with three tiers.' ) );
list( , $ans2 ) = mbcom_call( $admin, 'POST', "/posts/{$q['id']}/comments", array( 'content' => 'Later answer' ) );
list( , $rep ) = mbcom_call( $asker, 'POST', "/posts/{$q['id']}/comments", array( 'content' => 'Thanks!', 'parent_id' => $ans['id'] ) );
list( $s ) = mbcom_call( $helper, 'PUT', "/posts/{$q['id']}/best", array( 'comment_id' => $ans['id'] ) );
mbcom_check( 'only the person who asked (or staff) picks the best answer', 403 === $s, (string) $s );
list( $s ) = mbcom_call( $asker, 'PUT', "/posts/{$q['id']}/best", array( 'comment_id' => $rep['id'] ) );
mbcom_check( '…and it has to be an answer, not a reply', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $asker, 'PUT', "/posts/{$q['id']}/best", array( 'comment_id' => $ans['id'] ) );
list( , $cm ) = mbcom_call( $helper, 'GET', "/posts/{$q['id']}/comments", array( 'sort' => 'newest' ) );
mbcom_check( 'the best answer leads the comments, whatever the order', 200 === $s && $ans['id'] === $cm['items'][0]['id'] && $cm['items'][0]['best'] && 1 === count( array_filter( wp_list_pluck( $cm['items'], 'id' ), fn( $id ) => $id === $ans['id'] ) ) );
list( , $lp ) = mbcom_call( $helper, 'GET', "/lessons/{$ta['id']}/posts" );
mbcom_check( '…and is the preview under the post', $ans['id'] === ( $lp['items'][0]['preview_comment']['id'] ?? 0 ) && $lp['items'][0]['preview_comment']['best'] && $ans['id'] === $lp['items'][0]['best_comment_id'] );

// Pins
list( $s, $pin ) = mbcom_call( $admin, 'PUT', "/posts/{$newer['id']}/pin", array( 'scope' => 'lesson' ) );
list( , $lp ) = mbcom_call( $helper, 'GET', "/lessons/{$ta['id']}/posts" );
list( , $cp ) = mbcom_call( $helper, 'GET', "/courses/{$tcid}/posts" );
mbcom_check( 'staff pin a post to its lesson', 200 === $s && 'lesson' === $pin['pin'] && array( $newer['id'] ) === wp_list_pluck( $lp['pinned'], 'id' ) && ! in_array( $newer['id'], wp_list_pluck( $cp['pinned'], 'id' ), true ) );
mbcom_call( $admin, 'PUT', "/posts/{$gen['id']}/pin", array( 'scope' => 'course' ) );
list( , $cp ) = mbcom_call( $helper, 'GET', "/courses/{$tcid}/posts" );
list( , $feed ) = mbcom_call( $helper, 'GET', '/feed' );
mbcom_check( '…or to the course’s Discussion tab, never the feed', array( $gen['id'] ) === wp_list_pluck( $cp['pinned'], 'id' ) && 'course' === $cp['pinned'][0]['pin'] && ! in_array( $gen['id'], wp_list_pluck( $feed['pinned'], 'id' ), true ) );
for ( $i = 0; $i < 3; $i++ ) {
	list( , $extra ) = mbcom_call( $helper, 'POST', '/posts', array( 'content' => "Pin me {$i}", 'lesson_id' => $ta['id'] ) );
	list( $s, $err ) = mbcom_call( $admin, 'PUT', "/posts/{$extra['id']}/pin", array( 'scope' => 'lesson' ) );
}
mbcom_check( 'up to three pins per lesson', 400 === $s && 'mbcom_pin_limit' === $err['code'], (string) $s );
list( $s ) = mbcom_call( $helper, 'PUT', "/posts/{$q['id']}/pin", array( 'scope' => 'lesson' ) );
mbcom_check( 'members can’t pin', 403 === $s, (string) $s );

// Search inside the course, and the main search
list( , $cp ) = mbcom_call( $helper, 'GET', "/courses/{$tcid}/posts", array( 'q' => 'three tiers' ) );
mbcom_check( 'the Discussion tab searches posts and their replies', array( $q['id'] ) === wp_list_pluck( $cp['items'], 'id' ), wp_json_encode( wp_list_pluck( $cp['items'], 'id' ) ) );
list( , $found ) = mbcom_call( $helper, 'GET', '/search', array( 'q' => 'zebracornish', 'type' => 'posts' ) );
mbcom_check( 'the main search finds course posts for members of the course', in_array( $q['id'], wp_list_pluck( $found['items'], 'id' ), true ) );

// Locked course: nothing leaks
$club = mbcom_call( $admin, 'POST', '/groups', array( 'name' => 'Talk club' ) )[1];
mbcom_call( $admin, 'PUT', "/courses/{$tcid}", array( 'access' => 'groups', 'access_groups' => array( $club['id'] ) ) );
$fresh->setValue( null, array() );
list( $s1 ) = mbcom_call( $outside, 'GET', "/posts/{$q['id']}" );
list( $s2 ) = mbcom_call( $outside, 'GET', "/lessons/{$ta['id']}/posts" );
list( $s3 ) = mbcom_call( $outside, 'GET', "/courses/{$tcid}/posts" );
list( $s4 ) = mbcom_call( $outside, 'GET', "/posts/{$q['id']}/comments" );
list( $s5 ) = mbcom_call( $outside, 'PUT', "/posts/{$q['id']}/reaction", array( 'type' => 'like' ) );
list( $s6 ) = mbcom_call( $outside, 'POST', '/posts', array( 'content' => 'let me in', 'course_id' => $tcid ) );
list( , $found ) = mbcom_call( $outside, 'GET', '/search', array( 'q' => 'zebracornish', 'type' => 'posts' ) );
list( , $found_c ) = mbcom_call( $outside, 'GET', '/search', array( 'q' => 'three tiers', 'type' => 'comments' ) );
mbcom_check( 'members who can’t open the course can’t read, react to or search its posts', 404 === $s1 && 403 === $s2 && 403 === $s3 && 404 === $s4 && 404 === $s5 && 403 === $s6 && ! in_array( $q['id'], wp_list_pluck( $found['items'], 'id' ), true ) && ! array_filter( $found_c['items'], fn( $i ) => $i['post_id'] === $q['id'] ), "$s1 $s2 $s3 $s4 $s5 $s6" );
list( , $grid ) = mbcom_call( $outside, 'GET', '/courses' );
$card = current( array_filter( $grid['items'], fn( $c ) => $c['id'] === $tcid ) );
mbcom_check( '…but the card still shows how lively it is (counts only)', $card && $card['posts_count'] >= 5 && null === $card['next_lesson'] );
mbcom_call( $admin, 'PUT', "/courses/{$tcid}", array( 'access' => 'everyone' ) );
$fresh->setValue( null, array() );

// Course cards and the outline
mbcom_call( $asker, 'POST', "/lessons/{$ta['id']}/complete" );
list( , $grid ) = mbcom_call( $helper, 'GET', '/courses' );
$card = current( array_filter( $grid['items'], fn( $c ) => $c['id'] === $tcid ) );
mbcom_check( 'cards show who’s taking the course', 1 <= $card['learners'] && in_array( $asker, wp_list_pluck( $card['faces'], 'id' ), true ) );
list( , $grid ) = mbcom_call( $asker, 'GET', '/courses' );
$card = current( array_filter( $grid['items'], fn( $c ) => $c['id'] === $tcid ) );
mbcom_check( '…and where “Continue” goes (not a lesson that hasn’t opened yet)', null === $card['next_lesson'] && $card['last_activity'] );
list( , $outline ) = mbcom_call( $helper, 'GET', "/courses/{$tcid}" );
$first = $outline['modules'][0]['lessons'][0];
mbcom_check( 'the outline counts each lesson’s posts', 5 <= $first['posts'] && $outline['posts_count'] >= 6 );
mbcom_check( '…and names the instructor and who’s taking it, for the overview', $admin === ( $outline['instructor']['id'] ?? 0 ) && 1 <= $outline['learners'] && in_array( $asker, wp_list_pluck( $outline['faces'], 'id' ), true ) );
mbcom_call( $admin, 'PUT', "/lessons/{$ta['id']}", array( 'content' => str_repeat( 'word ', 900 ) ) );
list( , $one ) = mbcom_call( $helper, 'GET', "/lessons/{$ta['id']}" );
mbcom_check( 'a lesson says where it sits and about how long it takes', 1 === $one['number'] && 2 === $one['total'] && 'Module 1' === $one['module_title'] && 4 === $one['minutes'], wp_json_encode( array( $one['number'], $one['total'], $one['minutes'] ) ) );

// Resources
$guide = mbcom_upload( $admin, 'text/plain', str_repeat( "A line of the guide.\n", 100 ), 'guide.txt' );
mbcom_check( '(the guide uploads)', isset( $guide['id'] ), wp_json_encode( $guide ) );
$later = mbcom_upload( $admin, 'text/plain', "later\n", 'later.txt' );
mbcom_call( $admin, 'PUT', "/lessons/{$ta['id']}", array( 'resources' => array( array( 'media_id' => $guide['id'], 'name' => 'Guide.pdf' ) ) ) );
mbcom_call( $admin, 'PUT', "/lessons/{$tb['id']}", array( 'resources' => array( array( 'media_id' => $later['id'], 'name' => 'Later.docx' ) ) ) );
list( $s, $res ) = mbcom_call( $helper, 'GET', "/courses/{$tcid}/resources" );
$files = array_merge( ...array_map( fn( $g ) => $g['files'], $res['items'] ) );
mbcom_check( 'the Resources tab lists every lesson’s files with type and size', 200 === $s && 2 === count( $files ) && 'PDF' === $files[0]['ext'] && 2100 === $files[0]['size'] && $files[0]['url'], wp_json_encode( $files ) );
mbcom_check( '…and a lesson that hasn’t opened shows its file without a link', $res['items'][1]['locked'] && null === $files[1]['url'] && $res['items'][1]['unlock_at'] );

// Clean-up follows the lesson
mbcom_call( $admin, 'DELETE', "/lessons/{$ta['id']}" );
mbcom_check( 'deleting a lesson deletes its posts', 'deleted' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', "{$wpdb->prefix}mbcom_posts", $q['id'] ) ) );
mbcom_call( $admin, 'DELETE', "/courses/{$tcid}" );
mbcom_check( '…and deleting a course, the rest', 'deleted' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', "{$wpdb->prefix}mbcom_posts", $gen['id'] ) ) );
mbcom_call( $admin, 'DELETE', "/groups/{$club['id']}" );

/* ---------------------------------------------------------------- */
mbcom_section( 'Events' );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'events' => true ) ) );
\MemBuild\Community\Settings::flush();
$guest  = mbcom_user( 'mbct_eventguest' );
$mod    = mbcom_user( 'mbct_eventmod' );
mbcom_call( $admin, 'PUT', '/settings', array( 'moderators' => array( $mod ) ) );
\MemBuild\Community\Settings::flush();
$in2d   = gmdate( 'c', time() + 2 * DAY_IN_SECONDS );
list( $s ) = mbcom_call( $guest, 'POST', '/events', array( 'title' => 'Sneaky', 'at' => $in2d ) );
mbcom_check( 'members can’t add events', 403 === $s, (string) $s );
list( $s, $err ) = mbcom_call( $mod, 'POST', '/events', array( 'title' => '', 'at' => $in2d ) );
mbcom_check( 'an event needs a title', 400 === $s && 'mbcom_invalid_event' === $err['code'], (string) $s );
list( $s, $err ) = mbcom_call( $mod, 'POST', '/events', array( 'title' => 'Q&A', 'at' => $in2d, 'url' => 'javascript:alert(1)' ) );
mbcom_check( '…and only a web link', 400 === $s, (string) $s );
list( $s, $ev ) = mbcom_call( $mod, 'POST', '/events', array( 'title' => 'Thursday Q&A', 'at' => $in2d, 'minutes' => 45, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'description' => 'Bring questions' ) );
mbcom_check( 'moderators add events; a YouTube link is a stream', 201 === $s && 'Thursday Q&A' === $ev['event']['title'] && 'youtube' === $ev['event']['kind'] && 45 === $ev['event']['minutes'] && 'scheduled' === $ev['event']['status'], (string) $s );
$eid = (int) $ev['id'];
mbcom_check( '…and everyone is told once', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND post_id = %d AND user_id = %d', "{$wpdb->prefix}mbcom_notifications", 'event_new', $eid, $guest ) ) === 1 );
list( , $feed ) = mbcom_call( $guest, 'GET', '/feed' );
$in_feed = current( array_filter( $feed['items'], fn( $p ) => $p['id'] === $eid ) );
mbcom_check( 'the event is a post in the feed, carrying its event', $in_feed && 'Thursday Q&A' === ( $in_feed['event']['title'] ?? '' ) );

list( $s, $r ) = mbcom_call( $guest, 'PUT', "/events/{$eid}/rsvp", array( 'status' => 'going' ) );
mbcom_check( 'members say they’re going', 200 === $s && 1 === $r['going_count'] && 'going' === $r['my_rsvp'] && $guest === ( $r['faces'][0]['id'] ?? 0 ), (string) $s );
list( , $r ) = mbcom_call( $guest, 'PUT', "/events/{$eid}/rsvp", array( 'status' => 'interested' ) );
mbcom_check( '…or interested, one answer per member', 0 === $r['going_count'] && 1 === $r['interested_count'] && 'interested' === $r['my_rsvp'] );
list( , $r ) = mbcom_call( $guest, 'DELETE', "/events/{$eid}/rsvp" );
mbcom_check( '…and take it back', 0 === $r['interested_count'] && null === $r['my_rsvp'] );
mbcom_call( $guest, 'PUT', "/events/{$eid}/rsvp", array( 'status' => 'going' ) );
list( , $who ) = mbcom_call( $mod, 'GET', "/events/{$eid}/going" );
mbcom_check( 'hosts see who’s going', array( $guest ) === array_map( fn( $i ) => $i['user']['id'], $who['items'] ) );

list( , $up ) = mbcom_call( $guest, 'GET', '/events' );
list( , $past ) = mbcom_call( $guest, 'GET', '/events', array( 'when' => 'past' ) );
mbcom_check( 'the Events tab lists it under Upcoming, not Past', in_array( $eid, wp_list_pluck( $up['items'], 'id' ), true ) && ! in_array( $eid, wp_list_pluck( $past['items'], 'id' ), true ) && ! $up['can_add'] );

// Reminders: the day-before one is due now (the event is in 2 days? no: within 24h). Move it to 20 hours away, then 50 minutes away.
$notes = "{$wpdb->prefix}mbcom_notifications";
$wpdb->update( "{$wpdb->prefix}mbcom_posts", array( 'event_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * HOUR_IN_SECONDS ) ), array( 'id' => $eid ) );
\MemBuild\Community\Events::remind();
\MemBuild\Community\Events::remind();
$day = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND post_id = %d AND user_id = %d', $notes, 'event_reminder', $eid, $guest ) );
mbcom_check( 'a reminder goes to everyone going, once, the day before', 1 === $day, (string) $day );
$wpdb->update( "{$wpdb->prefix}mbcom_posts", array( 'event_at' => gmdate( 'Y-m-d H:i:s', time() + 50 * MINUTE_IN_SECONDS ) ), array( 'id' => $eid ) );
\MemBuild\Community\Events::remind();
\MemBuild\Community\Events::remind();
$soon = $wpdb->get_results( $wpdb->prepare( 'SELECT data FROM %i WHERE type = %s AND post_id = %d AND user_id = %d ORDER BY id', $notes, 'event_reminder', $eid, $guest ), ARRAY_A );
mbcom_check( '…and again an hour before', 2 === count( $soon ) && ! empty( json_decode( $soon[1]['data'], true )['soon'] ), (string) count( $soon ) );
$mbcom_mails = array();
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mbcom_notifications SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) WHERE user_id = %d", $guest ) );
delete_user_meta( $guest, 'mbcom_email_last' );
delete_user_meta( $guest, 'mbcom_last_active' );
\MemBuild\Community\Email::run();
$to_guest = array_values( array_filter( $mbcom_mails, fn( $m ) => 'mbct_eventguest@example.test' === $m['to'] ) );
mbcom_check( '…and the reminder arrives by email, through the regular email job', 1 === count( $to_guest ) && str_contains( $to_guest[0]['message'], 'Thursday Q&amp;A' ) && str_contains( $to_guest[0]['message'], "/events/{$eid}" ), count( $to_guest ) . ' emails ' . ( $to_guest[0]['subject'] ?? '' ) );

list( $s, $moved ) = mbcom_call( $mod, 'PUT', "/events/{$eid}", array( 'at' => gmdate( 'c', time() + 3 * DAY_IN_SECONDS ) ) );
mbcom_check( 'a new time resets the reminders and tells everyone going', 200 === $s && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT event_reminded FROM %i WHERE id = %d', "{$wpdb->prefix}mbcom_posts", $eid ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND post_id = %d AND user_id = %d', $notes, 'event_changed', $eid, $guest ) ), (string) $s );
list( $s, $moved ) = mbcom_call( $mod, 'PUT', "/events/{$eid}", array( 'recording' => 'https://vimeo.com/123456' ) );
mbcom_check( 'a recording link can be added later', 200 === $s && 'https://vimeo.com/123456' === $moved['event']['recording'] );

// A group-only event
$club = mbcom_call( $admin, 'POST', '/groups', array( 'name' => 'Event club' ) )[1];
list( $s, $priv ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Club call', 'at' => $in2d, 'group' => $club['id'] ) );
$pid = (int) $priv['id'];
$fresh->setValue( null, array() );
list( $s1 ) = mbcom_call( $guest, 'GET', "/posts/{$pid}" );
list( $s2 ) = mbcom_call( $guest, 'PUT', "/events/{$pid}/rsvp", array( 'status' => 'going' ) );
list( , $up ) = mbcom_call( $guest, 'GET', '/events' );
list( , $feed ) = mbcom_call( $guest, 'GET', '/feed' );
$told = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND post_id = %d AND user_id = %d', $notes, 'event_new', $pid, $guest ) );
mbcom_check( 'an event for one group is invisible to everyone else', 404 === $s1 && 404 === $s2 && ! in_array( $pid, wp_list_pluck( $up['items'], 'id' ), true ) && ! in_array( $pid, wp_list_pluck( $feed['items'], 'id' ), true ) && 0 === $told, "$s1 $s2 $told" );
mbcom_call( $admin, 'POST', "/groups/{$club['id']}/members", array( 'user_ids' => array( $guest ) ) );
$fresh->setValue( null, array() );
list( $s1 ) = mbcom_call( $guest, 'GET', "/posts/{$pid}" );
mbcom_check( '…until they join the group', 200 === $s1, (string) $s1 );

list( $s, $c ) = mbcom_call( $mod, 'POST', "/events/{$eid}/cancel" );
list( $s2 ) = mbcom_call( $guest, 'PUT', "/events/{$eid}/rsvp", array( 'status' => 'going' ) );
mbcom_check( 'cancelling marks it, tells everyone going, and stops RSVPs', 200 === $s && 'cancelled' === $c['event']['status'] && 400 === $s2 && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s AND post_id = %d AND user_id = %d', $notes, 'event_cancelled', $eid, $guest ) ), "$s $s2" );

mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'events' => false ) ) );
\MemBuild\Community\Settings::flush();
list( $s ) = mbcom_call( $guest, 'GET', '/events' );
list( , $feed ) = mbcom_call( $guest, 'GET', '/feed' );
mbcom_check( 'with Events off, the tab is gone and events leave the feed', 403 === $s && ! in_array( $eid, wp_list_pluck( $feed['items'], 'id' ), true ), (string) $s );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'events' => true ), 'moderators' => array() ) );
\MemBuild\Community\Settings::flush();
foreach ( array( $eid, $pid ) as $x ) {
	\MemBuild\Community\Rest_Feed::remove_post( $x );
	$wpdb->delete( $notes, array( 'post_id' => $x ) );
	$wpdb->delete( "{$wpdb->prefix}mbcom_reactions", array( 'object_type' => 'event', 'object_id' => $x ) );
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
mbcom_section( 'Problems (errors in our own code, kept for the admin)' );
\MemBuild\Community\Problems::clear();
$P = '\MemBuild\Community\Problems';
mbcom_check( 'a PHP warning is not a problem', null === $P::from_php( array( 'type' => E_WARNING, 'file' => '/srv/wp/wp-content/plugins/membuild-community/includes/class-feed.php', 'line' => 3, 'message' => 'x' ) ) );
mbcom_check( 'a fatal in another plugin or the theme is not ours', null === $P::from_php( array( 'type' => E_ERROR, 'file' => '/srv/wp/wp-content/plugins/woocommerce/x.php', 'line' => 3, 'message' => 'x' ) ) && ! $P::all() );
$sig = $P::from_php( array( 'type' => E_ERROR, 'file' => 'C:\\sites\\wp\\wp-content\\plugins\\membuild-community\\includes\\class-events.php', 'line' => 42, 'message' => "Uncaught TypeError: count(): Argument #1 must be of type Countable in /srv/wp/wp-content/plugins/membuild-community/includes/class-events.php:42\nStack trace:\n#0 /srv/wp/wp-includes/class-wp-hook.php(324): MemBuild\\Community\\Events::remind('secret@example.test', 'https://example.test/x')\n#1 {main}\n  thrown" ) );
$p = $P::all()[ $sig ] ?? null;
mbcom_check( 'a fatal in our files is kept with a plugin-relative path and the message apart from the stack', $p && 'php' === $p['kind'] && 'includes/class-events.php' === $p['file'] && 42 === $p['line'] && str_starts_with( $p['message'], 'Uncaught TypeError' ) && ! str_contains( $p['message'], 'Stack trace' ) && str_contains( $p['stack'], 'Events::remind' ) );
mbcom_check( '…and the stack has no email, URL or server path', ! str_contains( $p['stack'], 'secret@' ) && ! str_contains( $p['stack'], 'https://' ) && ! str_contains( $p['stack'], '/srv/wp' ) && ! str_contains( $p['message'], '/srv/wp' ) );
$P::from_php( array( 'type' => E_ERROR, 'file' => '/srv/wp/wp-content/plugins/membuild-community/includes/class-events.php', 'line' => 42, 'message' => "Uncaught TypeError: count(): Argument #1 must be of type Countable in /srv/wp/wp-content/plugins/membuild-community/includes/class-events.php:42\nStack trace:\n#0 {main}\n  thrown" ) );
mbcom_check( 'the same place again counts, not duplicates', 1 === count( $P::all() ) && 2 === $P::all()[ $sig ]['count'] );
$scrubbed = $P::scrub( 'Member ana@example.test opened https://example.test/community/post/9 with key "sk_live_abcdef" and id 1234567 at /var/www/html/wp-content/plugins/membuild-community/includes/class-feed.php' );
mbcom_check( 'scrubbing drops emails, links, quoted text, long numbers and server paths', ! str_contains( $scrubbed, '@' ) && ! str_contains( $scrubbed, 'http' ) && ! str_contains( $scrubbed, 'sk_live' ) && ! str_contains( $scrubbed, '1234567' ) && ! str_contains( $scrubbed, '/var/www' ) && str_contains( $scrubbed, 'membuild-community/includes/class-feed.php' ), $scrubbed );

list( $code ) = mbcom_call( 0, 'POST', '/problems', array( 'message' => 'x' ) );
mbcom_check( 'visitors can’t report', 401 === $code || 403 === $code, (string) $code );
list( $code ) = mbcom_call( $ana, 'POST', '/problems', array( 'message' => '' ) );
mbcom_check( 'an empty report is refused', 400 === $code );
list( $code ) = mbcom_call( $ana, 'POST', '/problems', array( 'message' => "Cannot read properties of undefined (reading 'faces') from ana@example.test", 'source' => 'http://localhost:8891/wp-content/plugins/membuild-community/assets/build/assets/index-D61fuWQ_.js', 'line' => 17, 'stack' => "TypeError: Cannot read properties of undefined\n    at EventCard (http://localhost:8891/.../index-D61fuWQ_.js:17:2000)", 'route' => 'events', 'extra' => str_repeat( 'x', 9000 ) ) );
$js = array_values( array_filter( $P::all(), fn( $p ) => 'js' === $p['kind'] ) )[0] ?? null;
mbcom_check( 'the app’s errors are kept: build hash off the file name, route, scrubbed message', 200 === $code && $js && 'index.js' === $js['file'] && 17 === $js['line'] && 'events' === $js['where'] && ! str_contains( $js['message'], '@' ) && str_contains( $js['message'], 'reading' ) );
for ( $i = 0; $i < 12; $i++ ) {
	list( $code ) = mbcom_call( $ana, 'POST', '/problems', array( 'message' => "flood {$i}" ) );
}
mbcom_check( 'a member is limited to ten reports a minute', 429 === $code );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mbcom\\_rl\\_problem%'" );
for ( $i = 0; $i < 60; $i++ ) {
	$P::record( 'php', "includes/x{$i}.php", $i, "overflow {$i}" );
}
mbcom_check( 'at most 50 signatures are kept, the oldest dropped', 50 === count( $P::all() ) && ! isset( $P::all()[ $sig ] ) );
$P::clear();
$P::record( 'php', 'includes/class-feed.php', 7, 'Boom at https://example.test/x', "#0 /srv/wp/wp-content/plugins/membuild-community/includes/class-feed.php(7)\n#1 {main}", 'wp-admin' );
mbcom_check( 'the overview says when something was caught', str_contains( $P::strip_item(), '1 problem in the last 7 days' ) );
wp_set_current_user( $admin );
ob_start();
$P::page();
$page = ob_get_clean();
mbcom_check( 'the Problems page lists it, with the stack and a copy box', str_contains( $page, 'Boom at' ) && str_contains( $page, 'includes/class-feed.php:7' ) && str_contains( $page, 'Copy for support' ) && ! str_contains( $page, 'https://example.test' ) );
wp_set_current_user( $ana );
ob_start();
$P::page();
mbcom_check( 'members never see the page (and no route lists problems)', '' === ob_get_clean() );
wp_set_current_user( $admin );

/* ---------------------------------------------------------------- */
mbcom_section( 'Anonymous usage reports (with a stand-in for MemBuild)' );
delete_option( \MemBuild\Community\Usage::OPTION );
$sent        = array();
$forget_code = 200; // what the stand-in answers to community_forget and community_unsubscribe
$stand_in    = function ( $pre, $args, $url ) use ( &$sent, &$forget_code ) {
	if ( ! str_starts_with( $url, \MemBuild\Community\Usage::ENDPOINT ) ) {
		return $pre;
	}
	$sent[] = array( 'url' => $url, 'body' => (string) $args['body'] );
	$code   = str_contains( $url, 'community_forget' ) || str_contains( $url, 'community_unsubscribe' ) ? $forget_code : 200;
	return array( 'response' => array( 'code' => $code ), 'body' => '{"ok":true}', 'headers' => array() );
};
$sent_to = function ( $action ) use ( &$sent ) {
	return array_values( array_filter( $sent, fn( $s ) => str_contains( $s['url'], "action={$action}" ) ) );
};
add_filter( 'pre_http_request', $stand_in, 10, 3 );
add_filter( 'membuild_community/usage_reports', '__return_true' );

$id = \MemBuild\Community\Usage::state()['id'];
mbcom_check( 'each site gets a random install ID, kept between visits', wp_is_uuid( $id, 4 ) && \MemBuild\Community\Usage::state()['id'] === $id );
mbcom_check( 'counts are sent as ranges, never exact', '0' === \MemBuild\Community\Usage::range( 0 ) && '1-10' === \MemBuild\Community\Usage::range( 7 ) && '51-200' === \MemBuild\Community\Usage::range( 51 ) && '201-1000' === \MemBuild\Community\Usage::range( 1000 ) && '5001+' === \MemBuild\Community\Usage::range( 6000 ) );
$report = \MemBuild\Community\Usage::report();
mbcom_check( 'without sharing, the report is only the ID and versions', array( 'install_id', 'plugin', 'wp', 'php', 'locale', 'multisite', 'shared' ) === array_keys( $report ) && false === $report['shared'] );
mbcom_check( '…so no problems either', ! isset( $report['problems'] ) );
list( $code ) = mbcom_call( $ana, 'GET', '/settings/usage' );
mbcom_check( 'members can’t see or change sharing', 403 === $code, (string) $code );
list( $code, $got ) = mbcom_call( $admin, 'PUT', '/settings/usage', array( 'share' => true, 'niche' => 'courses', 'niche_other' => 'ignored', 'size' => 'huge' ) );
mbcom_check( 'an admin turns sharing on; unknown answers are dropped', 200 === $code && true === $got['share'] && 'courses' === $got['niche'] && '' === $got['niche_other'] && '' === $got['size'] );
mbcom_call( $admin, 'PUT', '/settings/usage', array( 'niche' => 'other', 'niche_other' => '  Quilting <b>circle</b> and much more text than forty characters allows ' ) );
$report = \MemBuild\Community\Usage::report();
mbcom_check( '…“Something else” keeps a short description', 'other' === $report['niche'] && mb_strlen( $report['niche_other'] ) <= 40 && ! str_contains( $report['niche_other'], '<' ) );
mbcom_check( 'shared reports add features and rough counts', isset( $report['features']['classroom'], $report['members'], $report['posts'] ) && in_array( $report['members'], array( '0', '1-10', '11-50', '51-200', '201-1000', '1001-5000', '5001+' ), true ) );
mbcom_check( '…and the problems caught: file, line, scrubbed message and count, never the stack', 1 === count( $report['problems'] ) && 'includes/class-feed.php' === $report['problems'][0]['file'] && 7 === $report['problems'][0]['line'] && str_contains( $report['problems'][0]['message'], 'Boom at' ) && ! str_contains( $report['problems'][0]['message'], 'example.test' ) && ! isset( $report['problems'][0]['stack'], $report['problems'][0]['where'] ) );
$sent = array();
mbcom_check( 'the weekly report goes to MemBuild', \MemBuild\Community\Usage::send() && 1 === count( $sent ) && str_contains( $sent[0]['url'], 'action=community_report' ) );
$host  = wp_parse_url( home_url(), PHP_URL_HOST );
$email = get_userdata( $admin )->user_email;
$leaks = fn( $body ) => str_contains( $body, $host ) || str_contains( $body, $email ) || str_contains( $body, 'mbct_' ) || str_contains( $body, get_bloginfo( 'name' ) );
mbcom_check( '…and never carries the site address, emails or names', ! $leaks( $sent[0]['body'] ) );
mbcom_check( 'Settings shows exactly what’s sent', mbcom_call( $admin, 'GET', '/settings/usage' )[1]['report'] == json_decode( $sent[0]['body'], true ) );

/* Keep me updated */
list( $code, $got ) = mbcom_call( $admin, 'GET', '/settings/usage' );
mbcom_check( 'nobody is subscribed until an admin asks', false === $got['subscribed'] && null === $got['subscriber'] && $email === $got['admin_email'] );
list( $code ) = mbcom_call( $admin, 'POST', '/settings/updates', array( 'email' => 'not an address' ) );
mbcom_check( 'a bad email is refused', 400 === $code && ! \MemBuild\Community\Usage::state()['subscribed'] );
list( $code ) = mbcom_call( $ana, 'POST', '/settings/updates', array( 'email' => $email ) );
mbcom_check( 'members can’t subscribe the site', 403 === $code );
$sent = array();
list( $code, $got ) = mbcom_call( $admin, 'POST', '/settings/updates', array( 'email' => ' ' . $email . ' ' ) );
$sub  = $sent_to( 'community_subscribe' );
$body = $sub ? json_decode( $sub[0]['body'], true ) : array();
mbcom_check( '“Keep me updated” goes to MemBuild right away, with the email, site and versions', 200 === $code && $got['subscribed'] && 1 === count( $sub ) && $body['email'] === $email && home_url() === $body['site_url'] && get_bloginfo( 'name' ) === $body['site_name'] && MEMBUILD_COMMUNITY_VERSION === $body['plugin'] && $body['install_id'] === \MemBuild\Community\Usage::state()['id'] );
mbcom_check( '…with exact counts while sharing is on', isset( $body['usage'] ) && is_int( $body['usage']['members'] ) && $body['usage']['members'] > 0 && isset( $body['usage']['active_30'], $body['usage']['posts'], $body['usage']['courses'], $body['usage']['events'], $body['usage']['features']['classroom'], $body['usage']['last_active'] ) && ! isset( $body['usage']['names'] ) );
mbcom_check( '…and never member names or emails', ! str_contains( $sub[0]['body'], get_userdata( $ana )->user_email ) && ! str_contains( $sub[0]['body'], get_userdata( $ana )->display_name ) );
mbcom_check( 'Settings shows exactly what the subscription sends', $got['subscriber'] == $body && $email === $got['email'] );
mbcom_call( $admin, 'PUT', '/settings/usage', array( 'share' => false ) );
$sent = array();
\MemBuild\Community\Usage::send();
$sub = $sent_to( 'community_subscribe' );
mbcom_check( 'the weekly send refreshes the subscription too; without sharing it carries no counts', 1 === count( $sent_to( 'community_report' ) ) && 1 === count( $sub ) && ! isset( json_decode( $sub[0]['body'], true )['usage'] ) );
mbcom_check( '…while the anonymous report still never carries the site address or email', ! $leaks( $sent_to( 'community_report' )[0]['body'] ) );
mbcom_call( $admin, 'PUT', '/settings/usage', array( 'share' => true ) );
$forget_code = 500;
list( $code ) = mbcom_call( $admin, 'DELETE', '/settings/updates' );
mbcom_check( 'if MemBuild can’t be reached, Stop says so and keeps the subscription', 502 === $code && \MemBuild\Community\Usage::state()['subscribed'] );
$forget_code = 200;
$sent        = array();
list( $code, $got ) = mbcom_call( $admin, 'DELETE', '/settings/updates' );
$un = $sent_to( 'community_unsubscribe' );
mbcom_check( 'Stop tells MemBuild and forgets the email here', 200 === $code && ! $got['subscribed'] && '' === $got['email'] && 1 === count( $un ) && json_decode( $un[0]['body'], true )['email'] === $email );
$sent = array();
\MemBuild\Community\Usage::send();
mbcom_check( '…and the weekly send is anonymous again', 1 === count( $sent ) && ! $sent_to( 'community_subscribe' ) );
mbcom_check( '…and the overview won’t ask again', ! \MemBuild\Community\Usage::card_due() );

$forget_code = 500;
list( $code ) = mbcom_call( $admin, 'POST', '/settings/usage/forget' );
mbcom_check( 'if MemBuild can’t be reached, “delete my data” says so and changes nothing', 502 === $code && \MemBuild\Community\Usage::state()['id'] === $id && \MemBuild\Community\Usage::state()['share'] );
$forget_code = 200;
list( $code, $got ) = mbcom_call( $admin, 'POST', '/settings/usage/forget' );
mbcom_check( '…otherwise sharing stops and the site gets a new ID', 200 === $code && false === $got['share'] && $got['id'] !== $id && str_contains( end( $sent )['body'], $id ) );

delete_option( \MemBuild\Community\Usage::OPTION );
wp_set_current_user( $admin );
$card = function () {
	ob_start();
	\MemBuild\Community\Usage::setup_card();
	return ob_get_clean();
};
$html = $card();
mbcom_check( 'the overview asks for an email, prefilled, with sharing ticked by default', str_contains( $html, 'name="email" value="' . $email . '" required' ) && str_contains( $html, 'name="niche"' ) && str_contains( $html, 'name="share" value="1" checked' ) && str_contains( $html, home_url() ) );
\MemBuild\Community\Usage::skip();
mbcom_check( '…not again right after “Not now”', '' === $card() );
$state               = \MemBuild\Community\Usage::state();
$state['skipped_at'] = time() - \MemBuild\Community\Usage::RETURN_AFTER - 1;
update_option( \MemBuild\Community\Usage::OPTION, $state, false );
mbcom_check( '…once more two weeks later', str_contains( $card(), 'name="email"' ) );
\MemBuild\Community\Usage::skip();
$state               = \MemBuild\Community\Usage::state();
$state['skipped_at'] = time() - 10 * \MemBuild\Community\Usage::RETURN_AFTER;
update_option( \MemBuild\Community\Usage::OPTION, $state, false );
mbcom_check( '…and never after a second “Not now”', '' === $card() );
delete_option( \MemBuild\Community\Usage::OPTION );
\MemBuild\Community\Usage::subscribe( $email );
mbcom_check( '…nor once subscribed', '' === $card() );
wp_set_current_user( $ana );
delete_option( \MemBuild\Community\Usage::OPTION );
mbcom_check( 'members never see the card', '' === $card() );
wp_set_current_user( $admin );
$overview = function () {
	ob_start();
	\MemBuild\Community\Admin::page_overview();
	return ob_get_clean();
};
$html = $overview();
mbcom_check( 'while the card is up, the overview’s numbers sit blurred behind it', str_contains( $html, 'name="email"' ) && str_contains( $html, 'class="mbcom-gated" inert' ) && str_contains( $html, 'mbcom-stats' ) );
\MemBuild\Community\Usage::skip();
$html = $overview();
mbcom_check( '…and “Not now” clears the way', ! str_contains( $html, 'name="email"' ) && ! str_contains( $html, 'mbcom-gated' ) && str_contains( $html, 'mbcom-stats' ) );
delete_option( \MemBuild\Community\Usage::OPTION );

remove_filter( 'membuild_community/usage_reports', '__return_true' );
add_filter( 'membuild_community/usage_reports', '__return_false' );
$sent = array();
mbcom_check( 'the filter turns reports off entirely', false === \MemBuild\Community\Usage::send() && ! $sent );
remove_filter( 'membuild_community/usage_reports', '__return_false' );
remove_filter( 'pre_http_request', $stand_in, 10 );
delete_option( \MemBuild\Community\Usage::OPTION );
wp_clear_scheduled_hook( \MemBuild\Community\Usage::HOOK . '_now' );
\MemBuild\Community\Problems::clear();

/* ---------------------------------------------------------------- */
echo "\n{$GLOBALS['mbcom_passed']} passed, {$GLOBALS['mbcom_failed']} failed\n";
if ( $GLOBALS['mbcom_failed'] ) {
	exit( 1 );
}
