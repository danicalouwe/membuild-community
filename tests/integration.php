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
list( $s, $rs ) = mbcom_call( $ben, 'PUT', "/posts/{$pid}/reaction", array( 'type' => 'love' ) );
mbcom_check( 'a member can react', 200 === $s, (string) $s );
mbcom_check( '…and gets each kind’s count back', 1 === ( (array) $rs['reaction_counts'] )['love'] && 'love' === $rs['my_reaction'] );
list( , $rs ) = mbcom_call( $ben, 'PUT', "/posts/{$pid}/reaction", array( 'type' => 'haha' ) );
mbcom_check( 'a second reaction replaces the first, never adds', 1 === $rs['reactions_count'] && ! isset( ( (array) $rs['reaction_counts'] )['love'] ) && 1 === ( (array) $rs['reaction_counts'] )['haha'] );
$after = \MemBuild\Community\Points::stats_for( array( $ana ) )[ $ana ]['points'] ?? 0;
mbcom_check( 'the author earns a point', $after === $before + 1, "{$before} → {$after}" );
mbcom_call( $ben, 'DELETE', "/posts/{$pid}/reaction" );
mbcom_check( 'removing the reaction takes it back', ( \MemBuild\Community\Points::stats_for( array( $ana ) )[ $ana ]['points'] ?? 0 ) === $before );
list( $s, $c ) = mbcom_call( $ben, 'POST', "/posts/{$pid}/comments", array( 'content' => 'Nice one' ) );
mbcom_check( 'a member can comment', 201 === $s, (string) $s );
list( $s, $reply ) = mbcom_call( $ana, 'POST', "/posts/{$pid}/comments", array( 'content' => 'Thanks!', 'parent_id' => $c['id'] ) );
mbcom_check( 'replies thread under the comment', 201 === $s && (int) $reply['parent_id'] === (int) $c['id'] );
mbcom_call( $ana, 'PUT', "/comments/{$c['id']}/reaction", array( 'type' => 'like' ) );
list( , $cr ) = mbcom_call( $ben, 'PUT', "/comments/{$c['id']}/reaction", array( 'type' => 'haha' ) );
list( , $cr ) = mbcom_call( $ben, 'PUT', "/comments/{$c['id']}/reaction", array( 'type' => 'wow' ) );
mbcom_check( 'comments carry each kind’s count, one reaction per person', 2 === $cr['reactions_count'] && array( 'like' => 1, 'wow' => 1 ) == (array) $cr['reaction_counts'] && 'wow' === $cr['my_reaction'] );
list( , $listed ) = mbcom_call( $ben, 'GET', "/posts/{$pid}/comments" );
$listed_c = array_values( array_filter( $listed['items'] ?? $listed, fn( $x ) => (int) $x['id'] === (int) $c['id'] ) )[0] ?? null;
mbcom_check( '…and the counts come with the list', $listed_c && array( 'like' => 1, 'wow' => 1 ) == (array) $listed_c['reaction_counts'] );
mbcom_call( $ben, 'DELETE', "/comments/{$c['id']}/reaction" );
mbcom_call( $ana, 'DELETE', "/comments/{$c['id']}/reaction" );
list( $s, $one ) = mbcom_call( $ben, 'GET', "/comments/{$reply['id']}" );
mbcom_check( 'one comment can be fetched for a shared link, with its parent', 200 === $s && (int) $one['parent_id'] === (int) $c['id'] && (int) $one['post_id'] === (int) $pid );
list( $s ) = mbcom_call( 0, 'GET', "/comments/{$reply['id']}" );
mbcom_check( '…not by visitors', in_array( $s, array( 401, 403 ), true ) );
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
list( , $own ) = mbcom_call( $dee, 'POST', '/posts', array( 'content' => 'My own post, still up.' ) );
list( $s ) = mbcom_call( $dee, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => (int) $own['id'], 'reason' => 'spam' ) );
mbcom_check( "you can't report your own post", 400 === $s, (string) $s );
list( $s ) = mbcom_call( $dee, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => $p2, 'reason' => 'spam' ) );
mbcom_check( '…and a post that is already hidden can’t be reported (404)', 404 === $s, (string) $s );
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
$mbcom_bunny          = array();
$mbcom_bunny_original = 0; // the real size Bunny reports for the uploaded file
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$mbcom_bunny, &$mbcom_bunny_original ) {
		if ( false === strpos( $url, 'video.bunnycdn.com' ) ) {
			return $pre;
		}
		$mbcom_bunny[] = $args['method'] . ' ' . wp_parse_url( $url, PHP_URL_PATH );
		$body        = str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/storage' )
			? array( 'success' => true, 'data' => array( 'originals' => $mbcom_bunny_original ) )
			: array( 'guid' => 'test-guid', 'status' => 4, 'width' => 720, 'height' => 1280, 'length' => 9, 'totalItems' => 0 );
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

// Real size: the browser said 1000 bytes, Bunny says the file is bigger than 2 GB.
$vid                  = mbcom_user( 'mbct_vid' ); // their own uploads, so other checks' daily limits stay as they were
$mbcom_bunny_original = 3 * GB_IN_BYTES;
list( , $big ) = mbcom_call( $vid, 'POST', '/media/bunny', array( 'name' => 'big.mp4', 'size' => 1000 ) );
$mbcom_bunny = array();
list( , $big ) = mbcom_call( $vid, 'POST', "/media/bunny/{$big['media']['id']}/complete" );
mbcom_check( 'a video really over 2 GB is refused once Bunny has it', 'failed' === ( $big['stream']['status'] ?? '' ), $big['stream']['status'] ?? '' );
mbcom_check( '…and deleted from Bunny', in_array( 'DELETE /library/1/videos/test-guid', $mbcom_bunny, true ), implode( ', ', $mbcom_bunny ) );
$mbcom_bunny_original = 500 * MB_IN_BYTES;
list( , $ok ) = mbcom_call( $vid, 'POST', '/media/bunny', array( 'name' => 'ok.mp4', 'size' => 1000 ) );
list( , $ok ) = mbcom_call( $vid, 'POST', "/media/bunny/{$ok['media']['id']}/complete" );
mbcom_check( '…while one under the limit is ready', 'ready' === ( $ok['stream']['status'] ?? '' ) );
mbcom_check( 'without link protection, video links are left as they are', 'https://vz-x.b-cdn.net/test-guid/playlist.m3u8' === $ok['url'], $ok['url'] );

// Link protection: Bunny's token authentication (HMAC-SHA256), signed here the way Bunny's own code does it.
mbcom_call( $admin, 'PUT', '/settings', array( 'video' => array( 'token_key' => 'sec-key' ) ) );
$exp   = 1900000000;
$tok   = fn( $msg ) => 'HS256-' . rtrim( strtr( base64_encode( hash_hmac( 'sha256', $msg, 'sec-key', true ) ), '+/', '-_' ), '=' );
$want  = 'https://vz-x.b-cdn.net/bcdn_token=' . $tok( '/test-guid/' . $exp . 'token_path=/test-guid/' ) . '&token_path=%2Ftest-guid%2F&expires=' . $exp . '/test-guid/playlist.m3u8';
mbcom_check( 'with link protection, a playlist is signed for its folder, the way Bunny expects', MemBuild\Community\Bunny::sign( 'https://vz-x.b-cdn.net/test-guid/playlist.m3u8', $exp ) === $want );
$want  = 'https://vz-x.b-cdn.net/test-guid/thumbnail.jpg?token=' . $tok( '/test-guid/thumbnail.jpg' . $exp ) . '&expires=' . $exp;
mbcom_check( '…and a thumbnail gets its own signed link', MemBuild\Community\Bunny::sign( 'https://vz-x.b-cdn.net/test-guid/thumbnail.jpg', $exp ) === $want );
mbcom_check( '…pictures that aren’t on Bunny are left alone', 'https://example.test/a.jpg' === MemBuild\Community\Bunny::sign( 'https://example.test/a.jpg', $exp ) );
$ok = MemBuild\Community\Media::format( MemBuild\Community\Media::get( (int) $ok['id'] ) );
mbcom_check( '…members get the signed link, which stops working after about 6 hours', (bool) preg_match( '~/bcdn_token=HS256-[^&]+&token_path=%2Ftest-guid%2F&expires=(\d+)/test-guid/playlist\.m3u8$~', $ok['url'], $m ) && $m[1] - time() >= 6 * HOUR_IN_SECONDS && $m[1] - time() <= 7 * HOUR_IN_SECONDS, $ok['url'] );
$ss = MemBuild\Community\Bunny::public_settings();
mbcom_check( 'the key is saved encrypted and never sent back', $ss['has_token_key'] && '' === $ss['token_key'] && 'sec-key' !== MemBuild\Community\Bunny::settings()['token_key'] );
mbcom_call( $admin, 'PUT', '/settings', array( 'video' => array( 'token_key_clear' => true ) ) );
mbcom_check( 'turning link protection off removes the key', ! MemBuild\Community\Bunny::public_settings()['has_token_key'] );
$wpdb->delete( MemBuild\Community\Install::table( 'media' ), array( 'user_id' => $vid ) ); // the 3 GB stand-in mustn't count toward later runs
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
$_GET['s']     = 'Ana'; // test members pile up across runs: find her, not page one
$_REQUEST['s'] = 'Ana';
ob_start();
\MemBuild\Community\Admin::page_table( 'members' );
$html = ob_get_clean();
unset( $_GET['s'], $_REQUEST['s'] );
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
mbcom_check( 'the overview renders its numbers and chart', false !== strpos( $html, 'mb-stat__n' ) && false !== strpos( $html, 'mbcom-chart' ) );

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
echo "\nMember cards: where they live and a cover picture\n";
$png0 = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
list( , $prof ) = mbcom_call( $ana, 'PUT', '/me/profile', array( 'location' => '  Austin, TX  ' ) );
mbcom_check( 'a member can say where they live', 'Austin, TX' === $prof['location'] );
$cov = mbcom_upload( $ana, 'image/png', $png0, 'cover.png' );
list( $s, $prof ) = mbcom_call( $ana, 'PUT', '/me/profile', array( 'cover' => array( 'id' => $cov['id'] ) ) );
mbcom_check( '…and put up a cover picture', 200 === $s && ! empty( $prof['cover'] ), (string) $s );
list( $s ) = mbcom_call( $ben, 'PUT', '/me/profile', array( 'cover' => array( 'id' => $cov['id'] ) ) );
mbcom_check( '…but not someone else’s upload', 400 === $s, (string) $s );
list( , $dir ) = mbcom_call( $ben, 'GET', '/members', array( 'search' => get_userdata( $ana )->display_name ) );
$card = array_values( array_filter( $dir['items'], fn( $m ) => $m['id'] === $ana ) )[0] ?? array();
mbcom_check( 'the member card shows both, and a Message button for others', 'Austin, TX' === ( $card['location'] ?? '' ) && ! empty( $card['cover'] ) && false === $card['is_me'] && isset( $card['can_message'] ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'member_covers' => false ) );
\MemBuild\Community\Settings::flush();
list( , $dir ) = mbcom_call( $ben, 'GET', '/members', array( 'search' => get_userdata( $ana )->display_name ) );
$card = array_values( array_filter( $dir['items'], fn( $m ) => $m['id'] === $ana ) )[0] ?? array();
list( , $prof ) = mbcom_call( $ben, 'GET', "/members/{$ana}" );
mbcom_check( 'with cover photos off on cards, cards drop it but the profile keeps it', null === $card['cover'] && ! empty( $prof['cover'] ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'member_covers' => true ) );
\MemBuild\Community\Settings::flush();
list( , $prof ) = mbcom_call( $ana, 'PUT', '/me/profile', array( 'location' => '', 'cover' => null ) );
mbcom_check( 'emptying them hides them again', '' === $prof['location'] && null === $prof['cover'] && null === \MemBuild\Community\Media::get( $cov['id'] ) );

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
list( , $card ) = mbcom_call( $member, 'GET', "/courses/{$cid}" );
mbcom_check( '…and says how far they are and how to earn points', isset( $card['lock']['points'], $card['lock']['need'], $card['lock']['my_level'] ) && $card['lock']['need'] > $card['lock']['points'] && in_array( 'lesson', wp_list_pluck( $card['lock']['earn'], 'what' ), true ) );

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
mbcom_check( '…invite only until it has a button', 'VIP' === ( $card['lock']['membership']['name'] ?? '' ) && null === $card['lock']['membership']['offer'] && empty( $card['lock']['cta'] ) );
list( , $vipo ) = mbcom_call( $admin, 'PUT', "/groups/{$vip['id']}", array( 'cta_label' => 'Get access', 'price' => '$49', 'period' => 'month', 'cta_url' => 'https://example.com/join-vip' ) );
mbcom_check( 'a membership keeps one button for all its courses: text, price, period and link', '$49' === ( $vipo['offer']['price'] ?? '' ) && 'month' === $vipo['offer']['period'] && 'https://example.com/join-vip' === $vipo['offer']['url'] );
list( , $card ) = mbcom_call( $member, 'GET', "/courses/{$cid}" );
mbcom_check( '…which locked members see on the course', 'https://example.com/join-vip' === ( $card['lock']['cta']['url'] ?? '' ) && '$49' === $card['lock']['membership']['offer']['price'] );
list( , $vipo ) = mbcom_call( $admin, 'PUT', "/groups/{$vip['id']}", array( 'period' => 'weekly' ) );
mbcom_check( '…a period that isn’t one is ignored', 'month' === $vipo['offer']['period'] );
list( , $other ) = mbcom_call( $admin, 'POST', '/courses', array( 'title' => 'Second VIP course' ) );
mbcom_call( $admin, 'PUT', "/courses/{$other['id']}", array( 'status' => 'published', 'access' => 'level', 'unlock_level' => 3 ) );
list( , $vipo ) = mbcom_call( $admin, 'PUT', "/groups/{$vip['id']}", array( 'course_ids' => array( $cid, $other['id'] ) ) );
list( , $oc ) = mbcom_call( $admin, 'GET', "/courses/{$other['id']}" );
mbcom_check( 'choosing a course on the membership puts the course in it', 'groups' === $oc['access'] && in_array( $vip['id'], $oc['access_groups'], true ) && 2 === count( $vipo['courses'] ) );
list( , $card ) = mbcom_call( $member, 'GET', "/courses/{$cid}" );
mbcom_check( '…and the lock names the other courses it opens', array( 'Second VIP course' ) === ( $card['lock']['membership']['more'] ?? null ) );
list( , $vipo ) = mbcom_call( $admin, 'PUT', "/groups/{$vip['id']}", array( 'course_ids' => array( $cid ) ) );
list( , $oc ) = mbcom_call( $admin, 'GET', "/courses/{$other['id']}" );
mbcom_check( '…taking it out again leaves it out', ! in_array( $vip['id'], $oc['access_groups'], true ) && 1 === count( $vipo['courses'] ) );
mbcom_call( $admin, 'DELETE', "/courses/{$other['id']}" );

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
list( , $mine ) = mbcom_call( $admin, 'GET', "/members/{$member}/groups" );
$mine = array_values( array_filter( $mine['items'], fn( $g ) => $g['id'] === $vip['id'] ) )[0] ?? array();
mbcom_check( 'a member’s memberships say how many courses and since when', ! empty( $mine['manual'] ) && 1 === $mine['courses'] && ! empty( $mine['since'] ) );
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

// Open reports left by earlier runs of these tests (the test site only) would push this one past the pages checked.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mbcom_reports WHERE status = 'open' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
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
$fake_release = array( 'tag_name' => 'v9.9.0', 'body' => 'New things.', 'html_url' => 'https://github.com/x/y/releases/v9.9.0', 'published_at' => '2026-10-15T00:00:00Z', 'assets' => array( array( 'name' => 'membuild-community-9.9.0.zip', 'browser_download_url' => 'https://github.com/danicalouwe/membuild-community/releases/download/v9.9.0/membuild-community-9.9.0.zip' ) ) );
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
// Only the words shown (titles and quotes): a file name or a time can contain "1234" by chance.
mbcom_check( '…but their text is never quoted', '' === $by( 'lesson', $lock_l )['snippet'] && ! array_filter( $items, fn( $i ) => str_contains( ( $i['title'] ?? '' ) . ' ' . ( $i['snippet'] ?? '' ), '1234' ) ) );
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
delete_transient( 'mbcom_geo2_' . md5( '1 lake st, seattle' ) );
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
$in_feed = wp_list_pluck( $feed['items'], 'id' );
mbcom_check( '…which shows in the asker’s Home with its lesson, never on their profile', in_array( $q['id'], $in_feed, true ) && $ta['id'] === ( $feed['items'][ array_search( $q['id'], $in_feed, true ) ]['lesson']['id'] ?? 0 ) && ! in_array( $q['id'], wp_list_pluck( $prof['items'], 'id' ), true ) );
list( , $feed ) = mbcom_call( $helper, 'GET', '/feed' );
mbcom_check( '…and not in the Home of someone who hasn’t started that course', ! in_array( $q['id'], wp_list_pluck( $feed['items'], 'id' ), true ) );
mbcom_call( $helper, 'POST', "/lessons/{$ta['id']}/complete" );
list( , $feed ) = mbcom_call( $helper, 'GET', '/feed' );
mbcom_check( '…until they complete a lesson in it', in_array( $q['id'], wp_list_pluck( $feed['items'], 'id' ), true ) );
list( , $feed ) = mbcom_call( $admin, 'GET', '/feed' );
mbcom_check( '…admins see every course’s questions in Home', in_array( $q['id'], wp_list_pluck( $feed['items'], 'id' ), true ) );
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
mbcom_check( 'a lesson says where it sits and about how long it takes', 1 === $one['number'] && 2 === $one['total'] && 'Getting started' === $one['module_title'] && 4 === $one['minutes'], wp_json_encode( array( $one['number'], $one['total'], $one['minutes'] ) ) );

// Resources
$guide = mbcom_upload( $admin, 'text/plain', str_repeat( "A line of the guide.\n", 100 ), 'guide.txt' );
mbcom_check( '(the guide uploads)', isset( $guide['id'] ), wp_json_encode( $guide ) );
$later = mbcom_upload( $admin, 'text/plain', "later\n", 'later.txt' );
mbcom_call( $admin, 'PUT', "/lessons/{$ta['id']}", array( 'resources' => array( array( 'media_id' => $guide['id'], 'name' => 'Guide.pdf' ) ) ) );
mbcom_call( $admin, 'PUT', "/lessons/{$tb['id']}", array( 'resources' => array( array( 'media_id' => $later['id'], 'name' => 'Later.docx' ) ) ) );
list( $s, $res ) = mbcom_call( $helper, 'GET', "/courses/{$tcid}/resources" );
$files = array_merge( ...array_map( fn( $g ) => $g['files'], $res['items'] ) );
// The type comes from the file itself: these test files are plain text, whatever they're called.
mbcom_check( 'the Resources tab lists every lesson’s files with type and size', 200 === $s && 2 === count( $files ) && 'TXT' === $files[0]['ext'] && 2100 === $files[0]['size'] && $files[0]['url'], wp_json_encode( $files ) );
mbcom_check( '…and keeps the name the owner typed, spaces and all', 'Guide.pdf' === $files[0]['name'] );
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
mbcom_check( 'a browser stack keeps the file and line, not the address or build hash', 'at O (index.js:17:2000)' === $P::scrub( 'at O (http://localhost:8891/wp-content/plugins/membuild-community/assets/build/assets/index-D61fuWQ_.js:17:2000)' ) );
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
mbcom_check( 'while the card is up, the overview’s numbers stay readable (nothing blurred)', str_contains( $html, 'name="email"' ) && ! str_contains( $html, 'mbcom-gated' ) );
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
echo "\nEmails you get\n";
$mailer = mbcom_user( 'mbct_mailer' );
delete_user_meta( $mailer, \MemBuild\Community\Email::PREFS );
list( , $ep ) = mbcom_call( $mailer, 'GET', '/me/email' );
mbcom_check( 'event reminders are on by default, with messages and comments', true === $ep['events'] && true === $ep['messages'] && true === $ep['activity'] );
list( , $ep ) = mbcom_call( $mailer, 'PUT', '/me/email', array( 'events' => false, 'frequency' => 'daily' ) );
mbcom_check( '…and can be turned off on their own', false === $ep['events'] && true === $ep['activity'] && 'daily' === $ep['frequency'] );
delete_user_meta( $mailer, \MemBuild\Community\Email::PREFS );

/* ---------------------------------------------------------------- */
mbcom_section( 'Who’s online' );
$seer = mbcom_user( 'mbct_seer' );
$seen = mbcom_user( 'mbct_seen' );
delete_user_meta( $seer, \MemBuild\Community\Online::HIDE );
delete_user_meta( $seen, \MemBuild\Community\Online::HIDE );
delete_user_meta( $seen, \MemBuild\Community\Online::SEEN );
mbcom_call( $seen, 'GET', '/messages/pulse' );
mbcom_check( 'a pulse from an idle tab doesn’t count as being online', ! get_user_meta( $seen, \MemBuild\Community\Online::SEEN, true ) );
mbcom_call( $seen, 'GET', '/messages/pulse', array( 'active' => 1 ) );
mbcom_check( '…real use does', time() - (int) get_user_meta( $seen, \MemBuild\Community\Online::SEEN, true ) < 5 );
list( , $prof ) = mbcom_call( $seer, 'GET', "/members/{$seen}" );
mbcom_check( 'other members see when they were active', ! empty( $prof['active_at'] ) );
list( , $dir ) = mbcom_call( $seer, 'GET', '/members', array( 'online' => 1 ) );
mbcom_check( '…and find them under Online now, with a count', in_array( $seen, wp_list_pluck( $dir['items'], 'id' ), true ) && $dir['online'] >= 1 );
list( , $pv ) = mbcom_call( $seen, 'PUT', '/me/privacy', array( 'show_online' => false ) );
list( , $prof ) = mbcom_call( $seer, 'GET', "/members/{$seen}" );
list( , $dir ) = mbcom_call( $seer, 'GET', '/members', array( 'online' => 1 ) );
mbcom_check( 'a member who hides it isn’t shown as online', false === $pv['show_online'] && null === $prof['active_at'] && ! in_array( $seen, wp_list_pluck( $dir['items'], 'id' ), true ) );
update_user_meta( $seer, \MemBuild\Community\Online::SEEN, time() );
list( , $back ) = mbcom_call( $seen, 'GET', "/members/{$seer}" );
list( , $dir ) = mbcom_call( $seen, 'GET', '/members' );
mbcom_check( '…and doesn’t see anyone else either (fair both ways)', null === $back['active_at'] && null === $dir['online'] );
mbcom_call( $seen, 'PUT', '/me/privacy', array( 'show_online' => true ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'online' => false ) ) );
\MemBuild\Community\Settings::flush();
list( , $prof ) = mbcom_call( $seer, 'GET', "/members/{$seen}" );
mbcom_check( 'with Show who’s online off, nobody is shown', null === $prof['active_at'] );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'online' => true, 'messages' => false ) ) );
\MemBuild\Community\Settings::flush();
list( , $prof ) = mbcom_call( $seer, 'GET', "/members/{$seen}" );
mbcom_check( '…it needs Messages too', null === $prof['active_at'] );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'messages' => true ) ) );
\MemBuild\Community\Settings::flush();

/* ---------------------------------------------------------------- */
mbcom_section( 'Drip from the day a member starts, and the Course map' );
list( , $dc ) = mbcom_call( $admin, 'POST', '/courses', array( 'title' => 'Drip course' ) );
$dcid         = $dc['id'];
mbcom_check( 'a new course starts with a “Getting started” section', 'Getting started' === ( $dc['modules'][0]['title'] ?? '' ) );
list( , $da ) = mbcom_call( $admin, 'POST', "/modules/{$dc['modules'][0]['id']}/lessons", array( 'title' => 'Day one' ) );
list( , $db ) = mbcom_call( $admin, 'POST', "/modules/{$dc['modules'][0]['id']}/lessons", array( 'title' => 'Day three' ) );
mbcom_call( $admin, 'PUT', "/lessons/{$db['id']}", array( 'drip_days' => 3 ) );
mbcom_call( $admin, 'PUT', "/courses/{$dcid}", array( 'status' => 'published' ) );
$dripper = mbcom_user( 'mbct_dripper' );
$starts  = \MemBuild\Community\Install::table( 'course_starts' );
$cache   = new ReflectionProperty( \MemBuild\Community\Rest_Courses::class, 'starts' );
$cache->setAccessible( true );

list( , $view ) = mbcom_call( $dripper, 'GET', "/courses/{$dcid}" );
$later          = $view['modules'][0]['lessons'][1];
mbcom_check( 'before starting, a dripped lesson opens its days after today', $later['locked'] && strtotime( $later['unlock_at'] ) > time() + 2.9 * DAY_IN_SECONDS );
mbcom_check( '…and looking at the course doesn’t start it', ! $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d AND course_id = %d', $starts, $dripper, $dcid ) ) );
list( $s ) = mbcom_call( $dripper, 'GET', "/lessons/{$da['id']}" );
mbcom_check( 'opening the first lesson starts the course', 200 === $s && $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE user_id = %d AND course_id = %d', $starts, $dripper, $dcid ) ) );
list( $s ) = mbcom_call( $dripper, 'GET', "/lessons/{$db['id']}" );
mbcom_check( '…the day-three lesson is still closed on day one', 403 === $s );
$wpdb->update( $starts, array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ) ), array( 'user_id' => $dripper, 'course_id' => $dcid ) );
$cache->setValue( null, array() );
list( $s ) = mbcom_call( $dripper, 'GET', "/lessons/{$db['id']}" );
mbcom_check( '…and open once three days have passed since they started', 200 === $s, (string) $s );

list( $s ) = mbcom_call( $dripper, 'GET', "/courses/{$dcid}/map" );
mbcom_check( 'members can’t open the Course map', 403 === $s || 401 === $s, (string) $s );
list( , $map ) = mbcom_call( $admin, 'GET', "/courses/{$dcid}/map" );
$step = fn( $m, $id ) => array_values( array_filter( $m['lessons'], fn( $x ) => $x['lesson_id'] === $id ) )[0];
mbcom_check( 'the Course map counts who started and who is on the first lesson', 1 === $map['started'] && 1 === $step( $map, $da['id'] )['on'] && $dripper === ( $step( $map, $da['id'] )['people'][0]['id'] ?? 0 ) );
mbcom_call( $dripper, 'POST', "/lessons/{$da['id']}/complete" );
list( , $map ) = mbcom_call( $admin, 'GET', "/courses/{$dcid}/map" );
mbcom_check( '…moves them on when they finish it', 0 === $step( $map, $da['id'] )['on'] && 1 === $step( $map, $db['id'] )['on'] );
$wpdb->update( $starts, array( 'started_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'user_id' => $dripper, 'course_id' => $dcid ) );
list( , $map ) = mbcom_call( $admin, 'GET', "/courses/{$dcid}/map" );
mbcom_check( '…and shows them waiting when the next lesson hasn’t opened for them yet', 0 === $step( $map, $db['id'] )['on'] && 1 === $step( $map, $db['id'] )['waiting'] );
// On a date you pick: the same day for everyone, whenever they started.
list( , $dl ) = mbcom_call( $admin, 'PUT', "/lessons/{$db['id']}", array( 'open_on' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ) );
$cache->setValue( null, array() );
list( $s ) = mbcom_call( $dripper, 'GET', "/lessons/{$db['id']}" );
mbcom_check( 'a lesson set to open on a date stays closed until that day', 403 === $s && gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) === ( $dl['open_on'] ?? '' ), (string) $s );
mbcom_call( $admin, 'PUT', "/lessons/{$db['id']}", array( 'open_on' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) ) );
list( $s ) = mbcom_call( $dripper, 'GET', "/lessons/{$db['id']}" );
mbcom_check( '…and opens for everyone once the day has come', 200 === $s, (string) $s );
list( , $dl ) = mbcom_call( $admin, 'PUT', "/lessons/{$db['id']}", array( 'open_on' => 'not a date' ) );
mbcom_check( '…a date that isn’t one is cleared', null === $dl['open_on'] );

// "We'll send you a notification when it's ready": the hourly job tells them once it opens.
$notes = \MemBuild\Community\Install::table( 'notifications' );
$opened = fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = 'lesson_open' AND object_id = %d", $notes, $dripper, $db['id'] ) );
delete_option( \MemBuild\Community\Rest_Courses::OPENED_OPTION );
$wpdb->update( $starts, array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS + 1800 ) ), array( 'user_id' => $dripper, 'course_id' => $dcid ) );
\MemBuild\Community\Rest_Courses::notify_opened();
mbcom_check( 'the lesson-open job’s first run sends nothing old', 0 === $opened() );
update_option( \MemBuild\Community\Rest_Courses::OPENED_OPTION, time() - 3600, false );
\MemBuild\Community\Rest_Courses::notify_opened();
mbcom_check( '…a lesson that hasn’t opened yet isn’t announced', 0 === $opened() );
$wpdb->update( $starts, array( 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS - 1800 ) ), array( 'user_id' => $dripper, 'course_id' => $dcid ) );
update_option( \MemBuild\Community\Rest_Courses::OPENED_OPTION, time() - 3600, false );
\MemBuild\Community\Rest_Courses::notify_opened();
mbcom_check( '…once it opens, the member gets a notification', 1 === $opened() );
\MemBuild\Community\Rest_Courses::notify_opened();
mbcom_check( '…only once', 1 === $opened() );
list( , $nl ) = mbcom_call( $dripper, 'GET', '/notifications' );
$nfirst = array_values( array_filter( $nl['items'], fn( $n ) => 'lesson_open' === $n['type'] ) )[0] ?? array();
mbcom_check( '…and it opens the lesson', 'course' === ( $nfirst['link']['name'] ?? '' ) && $db['id'] === ( $nfirst['link']['params']['lessonId'] ?? 0 ) && 'Day three' === ( $nfirst['data']['title'] ?? '' ) );
list( , $view ) = mbcom_call( $dripper, 'GET', "/courses/{$dcid}" );
mbcom_check( 'the course says when they started and what finishing it earns', ! empty( $view['started_on'] ) && 20 === $view['finish_points'] );
$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE type = 'lesson_open' AND user_id = %d", $notes, $dripper ) );
delete_option( \MemBuild\Community\Rest_Courses::OPENED_OPTION );

// About this course, and questions off for one course.
list( , $dcv ) = mbcom_call( $admin, 'PUT', "/courses/{$dcid}", array( 'about' => "## What you'll learn\n\n- One\n- Two", 'questions' => 'off' ) );
mbcom_check( 'a course keeps its About text and its questions switch', str_contains( $dcv['about'], 'What you' ) && 'off' === $dcv['questions'] );
list( $s ) = mbcom_call( $dripper, 'POST', '/posts', array( 'content' => 'A question with questions off', 'lesson_id' => $da['id'] ) );
mbcom_check( '…and with questions off, nobody can post under its lessons', 403 === $s, (string) $s );
mbcom_call( $admin, 'PUT', "/courses/{$dcid}", array( 'questions' => 'on' ) );
list( $s ) = mbcom_call( $dripper, 'POST', '/posts', array( 'content' => 'A question with questions on', 'lesson_id' => $da['id'] ) );
mbcom_check( '…until they’re back on', 201 === $s, (string) $s );

// Settings › Features › Courses: parts of every course an owner can turn off.
list( $s, $saved ) = mbcom_call( $admin, 'PUT', '/settings', array( 'courses' => array( 'questions' => false, 'times' => false, 'instructor' => false, 'bogus' => false ) ) );
mbcom_check( 'the Courses switches save (unknown ones are ignored)', 200 === $s && false === $saved['settings']['courses']['questions'] && ! isset( $saved['settings']['courses']['bogus'] ), (string) $s );
\MemBuild\Community\Settings::flush();
mbcom_check( '…Helpful votes and What people are saying need Questions', ! \MemBuild\Community\Settings::course_part( 'helpful' ) && ! \MemBuild\Community\Settings::course_part( 'saying' ) );
list( $s ) = mbcom_call( $dripper, 'POST', '/posts', array( 'content' => 'A question with questions off everywhere', 'lesson_id' => $da['id'] ) );
list( , $view ) = mbcom_call( $dripper, 'GET', "/courses/{$dcid}" );
mbcom_check( '…with Questions off, nobody can post under any lesson, and courses say so', 403 === $s && 'off' === $view['questions'], (string) $s );
mbcom_check( '…with times and instructor off, the course shows neither', null === $view['instructor'] && 0 === $view['modules'][0]['lessons'][0]['minutes'] );
mbcom_call( $admin, 'PUT', '/settings', array( 'courses' => array( 'questions' => true, 'helpful' => false, 'times' => true, 'instructor' => true ) ) );
\MemBuild\Community\Settings::flush();
$asked = mbcom_call( $admin, 'POST', '/posts', array( 'content' => 'A question to vote on', 'lesson_id' => $da['id'] ) )[1];
list( $s ) = mbcom_call( $dripper, 'PUT', "/posts/{$asked['id']}/helpful" );
mbcom_check( '…with Helpful votes off, nobody can vote', 403 === $s, (string) $s );
mbcom_call( $admin, 'PUT', '/settings', array( 'courses' => array( 'helpful' => true ) ) );
\MemBuild\Community\Settings::flush();

mbcom_call( $admin, 'DELETE', "/courses/{$dcid}" );
mbcom_check( 'deleting a course removes its start dates', ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE course_id = %d', $starts, $dcid ) ) );

mbcom_section( 'Events and Places, step 2' );
list( $s, $ev ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'T in person', 'at' => gmdate( 'c', strtotime( '+10 days' ) ), 'where' => 'person', 'venue' => 'Hall', 'address' => 'Main St, Austin, TX', 'tz' => 'America/Chicago', 'repeat' => 'week' ) );
global $wpdb;
$series = (int) ( $ev['event']['series'] ?? 0 );
mbcom_check( 'an in-person event keeps its place and time zone', 201 === $s && 'Hall' === ( $ev['event']['place']['name'] ?? '' ) && 'America/Chicago' === ( $ev['event']['tz'] ?? '' ), wp_json_encode( $ev['event'] ?? $ev ) );
mbcom_check( '…every week makes 13 (about three months)', 13 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_series = %d', $wpdb->prefix . 'mbcom_posts', $series ) ) );
mbcom_call( $admin, 'POST', "/events/{$ev['id']}/cancel", array( 'which' => 'all' ) );
mbcom_check( '…and Cancel all cancels them all', 13 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event_series = %d AND event_status = 'cancelled'", $wpdb->prefix . 'mbcom_posts', $series ) ) );
list( $s ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'x', 'at' => gmdate( 'c', strtotime( '+3 days' ) ), 'where' => 'person' ) );
mbcom_check( 'in person needs a place name', 400 === $s );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'places' => true ) ) );
$wpdb->insert( $wpdb->prefix . 'mbcom_places', array( 'name' => 'T place', 'category' => 'meetup', 'address' => 'A, B', 'lat' => 1, 'lng' => 1, 'status' => 'published', 'created_by' => $admin, 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
$tp = (int) $wpdb->insert_id;
list( $s, $n ) = mbcom_call( $member, 'POST', "/places/{$tp}/news", array( 'text' => 'Half price', 'ends_on' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ) );
list( , $seen ) = mbcom_call( $ana, 'GET', "/places/{$tp}" );
mbcom_check( 'a member’s What’s new waits for a check (others don’t see it)', 'pending' === ( $n['status'] ?? '' ) && ! count( $seen['news'] ?? array() ), wp_json_encode( $n ) );
mbcom_call( $admin, 'PUT', "/places/news/{$n['id']}" );
list( , $seen ) = mbcom_call( $ana, 'GET', "/places/{$tp}" );
mbcom_check( '…and shows once approved', 1 === count( $seen['news'] ?? array() ) );
list( $s ) = mbcom_call( $ana, 'GET', '/places/news' );
mbcom_check( 'members can’t list What’s new waiting for a check', 403 === $s );
list( , $me ) = mbcom_call( $member, 'PUT', '/places/me', array( 'lat' => 30.267153, 'lng' => -97.743057, 'city' => 'Austin, TX' ) );
mbcom_check( 'Show me on the map keeps only the city, rounded to about a kilometre', 30.27 === (float) ( $me['me']['lat'] ?? 0 ) && 'Austin, TX' === ( $me['me']['city'] ?? '' ) );
mbcom_call( $member, 'DELETE', '/places/me' );
list( , $all ) = mbcom_call( $ana, 'GET', '/places/members' );
mbcom_check( '…and Take me off the map removes it', ! in_array( $member, array_map( fn( $m ) => $m['user']['id'], $all['items'] ?? array() ), true ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_series = %d', $wpdb->prefix . 'mbcom_posts', $series ) );
$wpdb->delete( $wpdb->prefix . 'mbcom_places', array( 'id' => $tp ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE place_id = %d', $wpdb->prefix . 'mbcom_place_news', $tp ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'places' => false ) ) );

mbcom_section( 'Leaderboard' );
$lb_a = mbcom_user( 'mbct_lb_ann' );
$lb_b = mbcom_user( 'mbct_lb_bob' );
$stats = $wpdb->prefix . 'mbcom_member_stats';
foreach ( array( $lb_a => 9000, $lb_b => 8990 ) as $u => $pts ) {
	$wpdb->replace( $stats, array( 'user_id' => $u, 'points' => $pts, 'level' => 8 ) );
}
delete_transient( 'mbcom_level_distribution' );
list( $s, $lb ) = mbcom_call( $lb_b, 'GET', '/leaderboard', array( 'period' => 'all' ) );
$top = array_slice( wp_list_pluck( wp_list_pluck( $lb['items'], 'user' ), 'id' ), 0, 2 );
mbcom_check( 'the all-time board lists the most points first', 200 === $s && array( $lb_a, $lb_b ) === $top, wp_json_encode( $top ) );
mbcom_check( '…says where you stand and who you’d pass next, by how much', 2 === $lb['me']['rank'] && 11 === ( $lb['me']['ahead']['needed'] ?? 0 ) && 'Lb_ann' === ( $lb['me']['ahead']['name'] ?? '' ), wp_json_encode( $lb['me'] ) );
list( , $lb ) = mbcom_call( $lb_a, 'GET', '/leaderboard', array( 'period' => 'all' ) );
mbcom_check( '…and nobody to pass when you’re first', 1 === $lb['me']['rank'] && null === $lb['me']['ahead'] );
mbcom_check( '…with your level progress and the real point rules', 8 === $lb['progress']['level'] && 9 === $lb['progress']['next_level'] && 33015 - 9000 === $lb['progress']['to_next'] && in_array( 'post', wp_list_pluck( $lb['rules'], 'reason' ), true ), wp_json_encode( $lb['progress'] ) );
$wpdb->replace( $stats, array( 'user_id' => $lb_b, 'points' => 9000, 'level' => 8 ) );
delete_transient( 'mbcom_level_distribution' );
list( , $lb ) = mbcom_call( $lb_b, 'GET', '/leaderboard', array( 'period' => 'all' ) );
$tied = array_values( array_filter( $lb['items'], fn( $i ) => in_array( $i['user']['id'], array( $lb_a, $lb_b ), true ) ) );
mbcom_check( 'members with the same points share a place, in the list and in “You’re #”', 1 === $tied[0]['rank'] && 1 === $tied[1]['rank'] && 1 === $lb['me']['rank'] && null === $lb['me']['ahead'], wp_json_encode( wp_list_pluck( $tied, 'rank' ) ) );
mbcom_call( $lb_b, 'PUT', "/members/{$lb_a}/block" );
list( , $lb ) = mbcom_call( $lb_b, 'GET', '/leaderboard', array( 'period' => 'all' ) );
mbcom_check( 'someone you blocked isn’t on your leaderboard', ! in_array( $lb_a, wp_list_pluck( wp_list_pluck( $lb['items'], 'user' ), 'id' ), true ) );
mbcom_call( $lb_b, 'DELETE', "/members/{$lb_a}/block" );
set_transient( 'mbcom_member_total', 1, HOUR_IN_SECONDS ); // a remembered count that's out of date
delete_transient( 'mbcom_level_distribution' );
list( , $lb ) = mbcom_call( $lb_b, 'GET', '/leaderboard', array( 'period' => 'all' ) );
mbcom_check( '…and a level’s share of members never goes over 100%', max( wp_list_pluck( $lb['levels'], 'percent' ) ) <= 100 );
$lb_new = mbcom_user( 'mbct_lb_new_' . wp_generate_password( 6, false, false ) );
mbcom_check( '…a new member refreshes the member count', false === get_transient( 'mbcom_member_total' ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $lb_new );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id IN (%d, %d)', $stats, $lb_a, $lb_b ) );
delete_transient( 'mbcom_level_distribution' );

mbcom_section( 'Settings V2' );
list( $s, $set ) = mbcom_call( $admin, 'PUT', '/settings', array( 'display' => 'dark', 'signup_label' => 'Become a member', 'login_headline' => 'Learn and grow together.', 'login_text' => 'A short line.' ) );
mbcom_check( 'Settings keep the default look, the join button’s words and the login page’s lines', 200 === $s && 'dark' === $set['settings']['display'] && 'Become a member' === $set['settings']['signup_label'] && 'Learn and grow together.' === $set['settings']['login_headline'], (string) $s );
mbcom_check( '…and members’ app starts in that look', 'dark' === \MemBuild\Community\Settings::public_config()['display'] );
list( , $set ) = mbcom_call( $admin, 'PUT', '/settings', array( 'display' => 'purple' ) );
mbcom_check( '…a look that isn’t one falls back to Match device', 'auto' === $set['settings']['display'] );
list( $s ) = mbcom_call( $admin, 'PUT', '/settings', array( 'login_picture' => array( 'id' => 999999 ) ) );
mbcom_check( '…a login picture must be the admin’s own upload', 400 === $s, (string) $s );
list( , $gk ) = mbcom_call( $admin, 'POST', '/settings/gifs/check', array( 'key' => 'not a key!' ) );
mbcom_check( 'a GIPHY key that can’t be one is “Not working” without asking GIPHY', false === $gk['working'] );
list( $s ) = mbcom_call( $member, 'POST', '/settings/gifs/check', array( 'key' => str_repeat( 'a', 32 ) ) );
mbcom_check( '…and only admins can check keys', 403 === $s || 401 === $s, (string) $s );
mbcom_call( $admin, 'PUT', '/settings', array( 'display' => 'auto', 'signup_label' => '', 'login_headline' => '', 'login_text' => '' ) );

mbcom_section( 'Emails and first run' );
$fr_m = mbcom_user( 'mbct_fr_new' );
delete_user_meta( $fr_m, \MemBuild\Community\First_Run::WELCOMED ); // fresh each run
$fr_mail = array();
$fr_cap = function ( $r, $a ) use ( &$fr_mail ) {
	$fr_mail[] = $a;
	return true;
};
add_filter( 'pre_wp_mail', $fr_cap, 10, 2 );
update_user_meta( $fr_m, \MemBuild\Community\Email::UNCONFIRMED, time() - 4 * DAY_IN_SECONDS );
\MemBuild\Community\Email::send_welcome( $fr_m );
mbcom_check( 'the welcome email has the confirm button', $fr_mail && str_contains( $fr_mail[0]['message'], 'mbcom_confirm=' . $fr_m ) && str_contains( $fr_mail[0]['subject'], 'Welcome' ) );
mbcom_check( '…and Home asks them to confirm after a few days', \MemBuild\Community\Email::banner( $fr_m ) );
mbcom_check( '…while no activity emails go to an unconfirmed address', false === \MemBuild\Community\Email::send_summary( $fr_m ) );
$fr_q = wp_parse_args( wp_parse_url( \MemBuild\Community\Email::confirm_url( $fr_m ), PHP_URL_QUERY ) );
$fr_bad = wp_parse_args( wp_parse_url( add_query_arg( 'email', 'x', \MemBuild\Community\Email::confirm_url( $fr_m + 1 ) ), PHP_URL_QUERY ) );
mbcom_check( 'confirm links are signed per member', $fr_q['sig'] !== ( $fr_bad['sig'] ?? '' ) );
delete_user_meta( $fr_m, \MemBuild\Community\Email::UNCONFIRMED );
mbcom_check( 'once confirmed, the banner goes', ! \MemBuild\Community\Email::banner( $fr_m ) );
$fr_u = get_userdata( $fr_m );
$fr_r = \MemBuild\Community\Email::reset_email( array( 'to' => $fr_u->user_email, 'subject' => '', 'message' => '', 'headers' => array() ), 'K', $fr_u->user_login, $fr_u );
mbcom_check( 'the password reset email is in the community look with the link written out', str_contains( $fr_r['message'], 'reset-password' ) && str_contains( implode( ' ', $fr_r['headers'] ), 'text/html' ) );
$fr_mail = array();
wp_update_user( array( 'ID' => $fr_m, 'first_name' => 'Zoë & "Al"' ) );
\MemBuild\Community\Email::send_welcome( $fr_m );
mbcom_check( 'a name with & reads normally in the subject (not &amp;)', str_contains( $fr_mail[0]['subject'] ?? '', 'Zoë & "Al"' ), $fr_mail[0]['subject'] ?? '' );
remove_filter( 'pre_wp_mail', $fr_cap, 10 );
wp_set_current_user( $fr_m );
$fr_s = \MemBuild\Community\First_Run::member_steps( $fr_m );
mbcom_check( 'a new member gets the welcome list with joining done', is_array( $fr_s ) && true === $fr_s['joined'] && false === $fr_s['hello'] );
mbcom_call( $fr_m, 'POST', '/first-run/hide' );
mbcom_check( '…and Hide hides it', null === \MemBuild\Community\First_Run::member_steps( $fr_m ) );
mbcom_check( 'the owner’s steps are only for admins', null === \MemBuild\Community\First_Run::config()['owner'] );
list( $s ) = mbcom_call( $fr_m, 'POST', '/first-run/invited' );
mbcom_check( '…and only admins can mark the invite step', 403 === $s || 401 === $s, (string) $s );
wp_set_current_user( $admin );
mbcom_check( 'the owner’s six steps', array( 'look', 'email', 'key', 'course', 'post', 'invite' ) === array_keys( \MemBuild\Community\First_Run::owner_steps() ) );

mbcom_section( 'Moderation V2' );
$md_a = mbcom_user( 'mbct_md_amy' );
$md_b = mbcom_user( 'mbct_md_bo' );
list( , $md_p ) = mbcom_call( $md_a, 'POST', '/posts', array( 'content' => 'Moderation V2 post' ) );
list( , $md_c ) = mbcom_call( $md_b, 'POST', "/posts/{$md_p['id']}/comments", array( 'content' => 'A rude reply' ) );
mbcom_call( $md_a, 'POST', '/reports', array( 'object_type' => 'comment', 'object_id' => $md_c['id'], 'reason' => 'harassment', 'note' => 'Not the first time.' ) );
mbcom_call( $md_b, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => $md_p['id'], 'reason' => 'spam' ) );
$md_prev = new ReflectionMethod( '\\MemBuild\\Community\\Moderation', 'preview' );
$md_prev->setAccessible( true );
mbcom_check( 'a reported comment says whose post it’s on', $md_prev->invoke( null, 'comment', (int) $md_c['id'] )['on'] === get_userdata( $md_a )->display_name );
$md_pp = $md_prev->invoke( null, 'post', (int) $md_p['id'] );
mbcom_check( '…and a reported post in Home has no course', array_key_exists( 'course', $md_pp ) && null === $md_pp['course'] );
// Take it down and mute in one step (two calls from the app).
mbcom_call( $admin, 'POST', "/moderation/reports/comment/{$md_c['id']}", array( 'action' => 'remove' ) );
list( , $md_r ) = mbcom_call( $admin, 'POST', "/members/{$md_b}/restriction", array( 'status' => 'muted', 'days' => 0 ) );
mbcom_check( 'Mute until I unmute has no end date', 'muted' === ( $md_r['restriction']['status'] ?? '' ) && null === $md_r['restriction']['until'] );
mbcom_call( $admin, 'POST', "/members/{$md_b}/restriction", array( 'status' => 'none' ) );
mbcom_call( $admin, 'POST', "/moderation/reports/post/{$md_p['id']}", array( 'action' => 'keep' ) );

// Taking a post down settles the reports on its comments.
list( , $md_p2 ) = mbcom_call( $md_a, 'POST', '/posts', array( 'content' => 'Moderation V2 second post' ) );
list( , $md_c2 ) = mbcom_call( $md_b, 'POST', "/posts/{$md_p2['id']}/comments", array( 'content' => 'Another reply' ) );
mbcom_call( $md_a, 'POST', '/reports', array( 'object_type' => 'comment', 'object_id' => $md_c2['id'], 'reason' => 'spam' ) );
mbcom_call( $md_b, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => $md_p2['id'], 'reason' => 'spam' ) );
mbcom_call( $admin, 'POST', "/moderation/reports/post/{$md_p2['id']}", array( 'action' => 'remove' ) );
mbcom_check( 'taking a post down settles the reports on its comments', 'removed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}mbcom_reports WHERE object_type = 'comment' AND object_id = %d", $md_c2['id'] ) ) );
// Muted & banned: every one counted (no 200 cap), ended mutes left out, a page at a time.
$md_many = array();
for ( $i = 0; $i < 230; $i++ ) {
	$md_many[] = $md_u = mbcom_user( "mbct_md_many{$i}" );
	update_user_meta( $md_u, 'mbcom_restriction', array( 'status' => 'muted', 'until' => $i < 10 ? gmdate( 'c', time() - 60 ) : null, 'by' => $admin, 'at' => gmdate( 'c' ) ) );
}
list( , $md_l1 ) = mbcom_call( $admin, 'GET', '/moderation/members' );
list( , $md_l2 ) = mbcom_call( $admin, 'GET', '/moderation/members' );
mbcom_check( 'Muted & banned counts everyone, the same every time, without ended mutes', $md_l1['counts']['members'] === $md_l2['counts']['members'] && $md_l1['counts']['members'] >= 220 && 50 === count( $md_l1['items'] ) && 2 === $md_l1['next_page'], wp_json_encode( array( $md_l1['counts']['members'], $md_l2['counts']['members'], count( $md_l1['items'] ), $md_l1['next_page'] ) ) );
foreach ( $md_many as $md_u ) {
	delete_user_meta( $md_u, 'mbcom_restriction' );
}

mbcom_section( 'Search V2' );
$se_a = mbcom_user( 'mbct_se_amy' );
list( , $se_post ) = mbcom_call( $se_a, 'POST', '/posts', array( 'content' => "## Zebrafish notes\n**for** the club" ) );
list( , $se_ev ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Zebrafish meetup', 'at' => gmdate( 'c', strtotime( '+3 days' ) ), 'where' => 'online', 'url' => 'https://example.com/zoom' ) );
list( , $res ) = mbcom_call( $se_a, 'GET', '/search', array( 'q' => 'zebrafish' ) );
mbcom_check( 'All gives each kind a count and the total', 1 === ( $res['groups']['posts']['count'] ?? 0 ) && 1 === ( $res['groups']['events']['count'] ?? 0 ) && $res['total'] >= 2, wp_json_encode( array_map( fn( $g ) => $g['count'], $res['groups'] ?? array() ) ) );
mbcom_check( '…events are their own group, not posts', array( (int) $se_post['id'] ) === wp_list_pluck( $res['groups']['posts']['items'], 'id' ) && 'event' === ( $res['groups']['events']['items'][0]['kind'] ?? '' ) );
mbcom_check( '…and snippets are plain words, no ** or ##', 'Zebrafish notes for the club' === $res['groups']['posts']['items'][0]['snippet'], $res['groups']['posts']['items'][0]['snippet'] ?? '' );
list( $s, $around ) = mbcom_call( $se_a, 'GET', '/search/around' );
mbcom_check( 'Look around counts members, courses, upcoming events and places', 200 === $s && $around['members'] > 0 && $around['events'] >= 1 && array_key_exists( 'courses', $around ), wp_json_encode( $around ) );
mbcom_call( $se_a, 'DELETE', "/posts/{$se_post['id']}" );
mbcom_call( $admin, 'DELETE', "/posts/{$se_ev['id']}" );

mbcom_section( 'Stories V2' );
$st_a = mbcom_user( 'mbct_st_amy' );
$st_b = mbcom_user( 'mbct_st_bo' );
list( $s, $sto ) = mbcom_call( $st_a, 'POST', '/stories', array( 'text' => 'In our colour', 'background' => 'community' ) );
mbcom_check( 'a text story can use the community’s own colour', 201 === $s && 'community' === $sto['background'], (string) $s );
list( , $sto2 ) = mbcom_call( $st_a, 'POST', '/stories', array( 'text' => 'Slate', 'background' => 'slate' ) );
list( , $sto3 ) = mbcom_call( $st_a, 'POST', '/stories', array( 'text' => 'Odd', 'background' => 'plaid' ) );
mbcom_check( '…and the new colours, while a made-up one falls back to the default', 'slate' === $sto2['background'] && 'grape' === $sto3['background'] );
mbcom_call( $st_b, 'POST', "/stories/{$sto['id']}/view" );
list( , $tray ) = mbcom_call( $st_a, 'GET', '/stories' );
$mine = array_values( array_filter( $tray['groups'], fn( $g ) => $g['is_me'] ) )[0]['stories'] ?? array();
$first = array_values( array_filter( $mine, fn( $x ) => $x['id'] === $sto['id'] ) )[0] ?? array();
mbcom_check( 'your own story lists the faces of who saw it', array( $st_b ) === wp_list_pluck( $first['seen_faces'] ?? array(), 'id' ), wp_json_encode( $first['seen_faces'] ?? null ) );
list( , $tray_b ) = mbcom_call( $st_b, 'GET', '/stories' );
$theirs = array_values( array_filter( $tray_b['groups'], fn( $g ) => $g['author']['id'] === $st_a ) )[0]['stories'][0] ?? array();
mbcom_check( '…and nobody else sees them', ! array_key_exists( 'seen_faces', $theirs ) );
foreach ( array( $sto, $sto2, $sto3 ) as $x ) {
	mbcom_call( $st_a, 'DELETE', "/stories/{$x['id']}" );
}

mbcom_section( 'How many levels' );
$lv_user = mbcom_user( 'mbct_lv_amy' );
$stats   = $wpdb->prefix . 'mbcom_member_stats';
$wpdb->replace( $stats, array( 'user_id' => $lv_user, 'points' => 300, 'level' => 5, 'updated_at' => current_time( 'mysql', true ) ) );
list( , $lvc ) = mbcom_call( $admin, 'POST', '/courses', array( 'title' => 'Level seven course' ) );
mbcom_call( $admin, 'PUT', "/courses/{$lvc['id']}", array( 'access' => 'level', 'unlock_level' => 7 ) );
list( , $got ) = mbcom_call( $admin, 'GET', '/settings' );
mbcom_check( 'the form starts with the nine default levels and knows which courses unlock at a level', \MemBuild\Community\Points::LEVELS === $got['settings']['level_points'] && in_array( (int) $lvc['id'], array_column( $got['level_courses'], 'id' ), true ) && array( 0, 40, 200 ) === $got['suggested_levels'][3] );
list( $s ) = mbcom_call( $admin, 'PUT', '/settings', array( 'level_points' => array( 0, 50, 40 ) ) );
mbcom_check( 'levels that don’t go up are refused', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'PUT', '/settings', array( 'level_points' => array( 5, 50, 400 ) ) );
mbcom_check( '…and so is a first level above 0', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $admin, 'PUT', '/settings', array( 'level_points' => array( 0, 50 ) ) );
mbcom_check( '…and fewer than 3 levels', 400 === $s, (string) $s );
list( $s, $set ) = mbcom_call( $admin, 'PUT', '/settings', array( 'level_points' => array( 0, 10, 50, 150, 400 ) ) );
mbcom_check( '5 levels saved', 200 === $s && array( 0, 10, 50, 150, 400 ) === \MemBuild\Community\Points::thresholds() && 5 === count( $set['config']['levelNames'] ), (string) $s );
mbcom_check( '…a member keeps their points and their level is worked out again (300 pts: level 4)', array( 'points' => 300, 'level' => 4 ) === \MemBuild\Community\Points::stats_for( array( $lv_user ) )[ $lv_user ] );
mbcom_check( '…a course that unlocked at level 7 now unlocks at the top level, 5', 5 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT unlock_level FROM %i WHERE id = %d', $wpdb->prefix . 'mbcom_courses', $lvc['id'] ) ) );
mbcom_check( '…and a level for a new member comes from the new points', 5 === \MemBuild\Community\Points::level_for( 99999 ) && 2 === \MemBuild\Community\Points::level_for( 10 ) );
list( $s ) = mbcom_call( $admin, 'PUT', "/courses/{$lvc['id']}", array( 'unlock_level' => 8 ) );
mbcom_check( '…a course can’t be set above the top level', 5 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT unlock_level FROM %i WHERE id = %d', $wpdb->prefix . 'mbcom_courses', $lvc['id'] ) ) );
list( , $set ) = mbcom_call( $admin, 'PUT', '/settings', array( 'level_points' => \MemBuild\Community\Points::LEVELS ) );
mbcom_check( 'back to 9: the defaults are stored as “none set” and the member is level 5 again', array() === \MemBuild\Community\Settings::get( 'level_points' ) && 5 === \MemBuild\Community\Points::level_of( $lv_user ) && 9 === count( $set['config']['levelNames'] ) );
mbcom_call( $admin, 'DELETE', "/courses/{$lvc['id']}" );
$wpdb->delete( $stats, array( 'user_id' => $lv_user ) );

mbcom_section( 'Places and Events together' );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'places' => true ) ) );
$pa  = mbcom_user( 'mbct_pe_ann' );
$pb  = mbcom_user( 'mbct_pe_bob' );
$pt  = $wpdb->prefix . 'mbcom_posts';
$at  = fn( $days ) => gmdate( 'c', strtotime( "+{$days} days" ) );
// A member suggests a place: it waits, and can't hold an event until it's approved.
list( $s, $sug ) = mbcom_call( $pa, 'POST', '/places', array( 'name' => 'Corner Studio', 'category' => 'venue', 'address' => '5th St, Austin, TX', 'lat' => 30.27, 'lng' => -97.74 ) );
mbcom_check( 'a suggested place waits for a check', 201 === $s && 'pending' === $sug['status'], (string) $s );
list( , $staff ) = mbcom_call( $admin, 'GET', '/places' );
mbcom_check( '…and staff see it in their list, marked pending', 'pending' === ( array_column( $staff['items'], 'status', 'id' )[ $sug['id'] ] ?? '' ) );
list( $s ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Too soon', 'at' => $at( 5 ), 'where' => 'person', 'place_id' => $sug['id'] ) );
mbcom_check( '…an event can’t be held at a place still waiting (no place, no name: refused)', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $pb, 'DELETE', "/places/{$sug['id']}" );
mbcom_check( '…another member can’t remove someone’s suggestion', 404 === $s || 403 === $s, (string) $s );
list( , $ok ) = mbcom_call( $admin, 'PUT', "/places/{$sug['id']}", array( 'status' => 'published' ) );
mbcom_check( 'once approved it’s published', 'published' === $ok['status'] );
// An event at the place copies its name, address and pin, and the place lists it.
list( $s, $ev ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Studio night', 'at' => $at( 4 ), 'where' => 'person', 'place_id' => $sug['id'], 'tz' => 'America/Chicago' ) );
$e1 = (int) $ev['id'];
mbcom_check( 'an event at one of your Places carries its name, address and link', 201 === $s && $sug['id'] === ( $ev['event']['place']['id'] ?? 0 ) && 'Corner Studio' === $ev['event']['place']['name'] && '5th St, Austin, TX' === $ev['event']['place']['address'], wp_json_encode( $ev['event']['place'] ?? $s ) );
list( , $pl ) = mbcom_call( $pb, 'GET', "/places/{$sug['id']}" );
mbcom_check( '…and the place shows it under Coming up here', array( $e1 ) === wp_list_pluck( $pl['events'] ?? array(), 'id' ), wp_json_encode( $pl['events'] ?? null ) );
// A group-only event at the place stays hidden from everyone outside the group.
$pe_club = mbcom_call( $admin, 'POST', '/groups', array( 'name' => 'Studio club' ) )[1];
list( , $gev ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Club studio', 'at' => $at( 6 ), 'where' => 'person', 'place_id' => $sug['id'], 'group' => $pe_club['id'] ) );
$fresh->setValue( null, array() );
list( , $pl ) = mbcom_call( $pb, 'GET', "/places/{$sug['id']}" );
mbcom_check( 'a group-only event doesn’t show on the place for people outside the group', ! in_array( (int) $gev['id'], wp_list_pluck( $pl['events'] ?? array(), 'id' ), true ) );
list( , $pl ) = mbcom_call( $admin, 'GET', "/places/{$sug['id']}" );
mbcom_check( '…but does for staff', in_array( (int) $gev['id'], wp_list_pluck( $pl['events'] ?? array(), 'id' ), true ) );
// Cancelled and past events drop off; a weekly series shows only the next three.
mbcom_call( $admin, 'POST', "/events/{$gev['id']}/cancel" );
list( , $wk ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Weekly studio', 'at' => $at( 1 ), 'where' => 'person', 'place_id' => $sug['id'], 'repeat' => 'week' ) );
$wser = (int) ( $wk['event']['series'] ?? 0 );
list( , $pl ) = mbcom_call( $admin, 'GET', "/places/{$sug['id']}" );
$ids = wp_list_pluck( $pl['events'] ?? array(), 'id' );
mbcom_check( 'Coming up here lists the next three, soonest first, without cancelled ones', 3 === count( $ids ) && (int) $wk['id'] === $ids[0] && ! in_array( (int) $gev['id'], $ids, true ), wp_json_encode( $ids ) );
$wpdb->update( $pt, array( 'event_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) ) ), array( 'id' => (int) $wk['id'] ) );
list( , $pl ) = mbcom_call( $admin, 'GET', "/places/{$sug['id']}" );
mbcom_check( '…and a past one drops off', ! in_array( (int) $wk['id'], wp_list_pluck( $pl['events'] ?? array(), 'id' ), true ) );
// Changing the place changes its events; removing it leaves the events standing.
mbcom_call( $admin, 'PUT', "/places/{$sug['id']}", array( 'name' => 'Corner Studio B', 'address' => '6th St, Austin, TX', 'lat' => 30.26, 'lng' => -97.75 ) );
list( , $after ) = mbcom_call( $pb, 'GET', "/posts/{$e1}" );
mbcom_check( 'renaming or moving a place updates the events held there', 'Corner Studio B' === ( $after['event']['place']['name'] ?? '' ) && '6th St, Austin, TX' === $after['event']['place']['address'], wp_json_encode( $after['event']['place'] ?? null ) );
list( , $n ) = mbcom_call( $pa, 'POST', "/places/{$sug['id']}/news", array( 'text' => 'Open mic Friday', 'ends_on' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ) ) );
list( , $mine ) = mbcom_call( $pa, 'GET', "/places/{$sug['id']}" );
list( , $other ) = mbcom_call( $pb, 'GET', "/places/{$sug['id']}" );
mbcom_check( 'your own What’s new shows to you while it waits, to nobody else', 1 === count( $mine['news'] ?? array() ) && 'pending' === $mine['news'][0]['status'] && ! count( $other['news'] ?? array() ) );
$wpdb->update( $wpdb->prefix . 'mbcom_place_news', array( 'status' => 'published', 'ends_on' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ), array( 'id' => $n['id'] ) );
list( , $other ) = mbcom_call( $pb, 'GET', "/places/{$sug['id']}" );
mbcom_check( 'What’s new disappears after the day it ends', ! count( $other['news'] ?? array() ) );
list( $s ) = mbcom_call( $admin, 'DELETE', "/places/{$sug['id']}" );
list( , $after ) = mbcom_call( $pb, 'GET', "/posts/{$e1}" );
mbcom_check( 'removing a place keeps its events, with the name and address, no longer linked', 200 === $s && null === ( $after['event']['place']['id'] ?? null ) && 'Corner Studio B' === ( $after['event']['place']['name'] ?? '' ), wp_json_encode( $after['event']['place'] ?? null ) );
mbcom_check( '…and its What’s new goes with it', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE place_id = %d', $wpdb->prefix . 'mbcom_place_news', $sug['id'] ) ) );
// Time zone: the first event teaches the place; owners can change it, moving events still to come.
list( , $zp ) = mbcom_call( $admin, 'POST', '/places', array( 'name' => 'Zone Hall', 'category' => 'venue', 'address' => 'Elm St, Denver, CO', 'lat' => 39.7, 'lng' => -104.9 ) );
mbcom_check( 'a new place has no time zone yet', null === $zp['tz'] );
list( , $ze ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Zone talk', 'at' => $at( 8 ), 'where' => 'person', 'place_id' => $zp['id'], 'tz' => 'America/Denver' ) );
list( , $zp2 ) = mbcom_call( $pb, 'GET', "/places/{$zp['id']}" );
mbcom_check( '…the first event held there gives it one', 'America/Denver' === $zp2['tz'], wp_json_encode( $zp2['tz'] ) );
mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Zone later', 'at' => $at( 9 ), 'where' => 'person', 'place_id' => $zp['id'], 'tz' => 'Asia/Manila' ) );
list( , $zp2 ) = mbcom_call( $pb, 'GET', "/places/{$zp['id']}" );
mbcom_check( '…and a later event doesn’t overwrite it', 'America/Denver' === $zp2['tz'] );
list( , $zold ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Zone past', 'at' => $at( 7 ), 'where' => 'person', 'place_id' => $zp['id'], 'tz' => 'America/Denver' ) );
$wpdb->update( $pt, array( 'event_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) ), array( 'id' => (int) $zold['id'] ) );
list( $s ) = mbcom_call( $pb, 'PUT', "/places/{$zp['id']}", array( 'tz' => 'America/Chicago' ) );
mbcom_check( 'members can’t change a place’s time zone', 403 === $s, (string) $s );
mbcom_call( $admin, 'PUT', "/places/{$zp['id']}", array( 'tz' => 'America/Chicago' ) );
$ztz = fn( $id ) => $wpdb->get_var( $wpdb->prepare( 'SELECT event_tz FROM %i WHERE id = %d', $pt, $id ) );
mbcom_check( 'an owner changing it moves the events still to come, not past ones', 'America/Chicago' === $ztz( (int) $ze['id'] ) && 'America/Denver' === $ztz( (int) $zold['id'] ) );
mbcom_call( $admin, 'PUT', "/places/{$zp['id']}", array( 'tz' => 'Mars/Olympus' ) );
list( , $zp2 ) = mbcom_call( $pb, 'GET', "/places/{$zp['id']}" );
mbcom_check( '…and a made-up zone is ignored (it keeps its own)', 'America/Chicago' === $zp2['tz'] );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_place_id = %d', $pt, $zp['id'] ) );
mbcom_call( $admin, 'DELETE', "/places/{$zp['id']}" );
// Who can see a place: everyone, or one membership.
$vip = mbcom_call( $admin, 'POST', '/groups', array( 'name' => 'Place VIPs' ) )[1];
list( , $vp ) = mbcom_call( $admin, 'POST', '/places', array( 'name' => 'VIP Lounge', 'category' => 'venue', 'address' => 'Oak St, Austin, TX', 'lat' => 30.3, 'lng' => -97.7, 'access_group' => $vip['id'] ) );
$fresh->setValue( null, array() );
list( , $plist ) = mbcom_call( $pb, 'GET', '/places' );
list( $s1 ) = mbcom_call( $pb, 'GET', "/places/{$vp['id']}" );
list( $s2 ) = mbcom_call( $pb, 'PUT', "/places/{$vp['id']}/going" );
list( , $found ) = mbcom_call( $pb, 'GET', '/search', array( 'q' => 'VIP Lounge', 'type' => 'places' ) );
mbcom_check( 'a members-only place is hidden from everyone else: list, page, I go here and search', ! in_array( (int) $vp['id'], wp_list_pluck( $plist['items'], 'id' ), true ) && 404 === $s1 && 404 === $s2 && ! in_array( (int) $vp['id'], wp_list_pluck( $found['groups']['places']['items'] ?? $found['items'] ?? array(), 'id' ), true ), "$s1 $s2" );
mbcom_check( '…staff see it, with a lock tag naming the membership', 'Place VIPs' === ( $vp['access']['name'] ?? '' ) );
list( , $vev ) = mbcom_call( $admin, 'POST', '/events', array( 'title' => 'Open night at the lounge', 'at' => $at( 5 ), 'where' => 'person', 'place_id' => $vp['id'] ) );
$fresh->setValue( null, array() );
list( , $seen_ev ) = mbcom_call( $pb, 'GET', "/posts/{$vev['id']}" );
mbcom_check( '…an event for everyone held there shows the name and address, without a link to the place', 'VIP Lounge' === ( $seen_ev['event']['place']['name'] ?? '' ) && null === $seen_ev['event']['place']['id'] );
mbcom_call( $admin, 'POST', "/groups/{$vip['id']}/members", array( 'user_ids' => array( $pb ) ) );
$fresh->setValue( null, array() );
list( $s1, $vin ) = mbcom_call( $pb, 'GET', "/places/{$vp['id']}" );
mbcom_check( '…members with the membership see it, with no lock tag', 200 === $s1 && null === $vin['access'], (string) $s1 );
list( $s ) = mbcom_call( $pb, 'PUT', "/places/{$vp['id']}", array( 'access_group' => 0 ) );
mbcom_check( '…and only staff can change who sees it', 403 === $s, (string) $s );
// The phone's country.
list( , $hp ) = mbcom_call( $admin, 'PUT', "/places/{$vp['id']}", array( 'phone' => '032 412 5678', 'phone_country' => 'ph' ) );
mbcom_check( 'a place’s phone keeps the number as typed and its country', '032 412 5678' === $hp['phone'] && 'PH' === $hp['phone_country'] );
list( , $hp ) = mbcom_call( $admin, 'PUT', "/places/{$vp['id']}", array( 'phone_country' => 'Philippines' ) );
mbcom_check( '…and anything but a two-letter country is dropped', null === $hp['phone_country'] );
// Hours by day.
list( , $hp ) = mbcom_call( $admin, 'PUT', "/places/{$vp['id']}", array( 'hours' => 'Thursdays 9–11am', 'week' => array( 'thu' => array( array( '09:00', '11:00' ) ), 'sat' => array( array( '10:00', '12:00' ), array( '13:00', '17:00' ) ), 'xyz' => array( array( '01:00', '02:00' ) ), 'mon' => array( array( '18:00', '09:00' ), array( '7am', '9am' ) ) ) ) );
mbcom_check( 'hours by day keep good times (two on one day), drop bad ones, and replace the typed hours', array( 'thu' => array( array( '09:00', '11:00' ) ), 'sat' => array( array( '10:00', '12:00' ), array( '13:00', '17:00' ) ) ) === $hp['week'] && '' === $hp['hours'], wp_json_encode( array( $hp['week'], $hp['hours'] ) ) );
list( , $hp ) = mbcom_call( $admin, 'PUT', "/places/{$vp['id']}", array( 'week' => array() ) );
mbcom_check( '…and no days means no set hours', null === $hp['week'] );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', $pt, $vev['id'] ) );
mbcom_call( $admin, 'DELETE', "/places/{$vp['id']}" );
mbcom_call( $admin, 'DELETE', "/groups/{$vip['id']}" );
// Muted members can't suggest; blocked members aren't on your map.
mbcom_call( $admin, 'POST', "/members/{$pb}/restriction", array( 'status' => 'muted', 'days' => 1 ) );
list( $s1 ) = mbcom_call( $pb, 'POST', '/places', array( 'name' => 'Muted spot', 'category' => 'venue', 'address' => 'X, Y', 'lat' => 1, 'lng' => 1 ) );
list( , $cafe_list ) = mbcom_call( $admin, 'GET', '/places' );
$any = (int) ( $cafe_list['items'][0]['id'] ?? 0 );
list( $s2 ) = $any ? mbcom_call( $pb, 'POST', "/places/{$any}/news", array( 'text' => 'x', 'ends_on' => gmdate( 'Y-m-d', strtotime( '+2 days' ) ) ) ) : array( 403 );
mbcom_check( 'muted members can’t suggest a place or a What’s new', 403 === $s1 && 403 === $s2, "$s1 $s2" );
mbcom_call( $admin, 'POST', "/members/{$pb}/restriction", array( 'status' => 'none' ) );
mbcom_call( $pb, 'PUT', '/places/me', array( 'lat' => 30.2, 'lng' => -97.7, 'city' => 'Austin, TX' ) );
mbcom_call( $pa, 'PUT', "/members/{$pb}/block" );
list( , $map ) = mbcom_call( $pa, 'GET', '/places/members' );
mbcom_check( 'someone you blocked isn’t on your map', ! in_array( $pb, array_map( fn( $m ) => $m['user']['id'], $map['items'] ?? array() ), true ) );
mbcom_call( $pa, 'DELETE', "/members/{$pb}/block" );
mbcom_call( $pb, 'DELETE', '/places/me' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (%d, %d) OR event_series = %d', $pt, $e1, (int) $gev['id'], $wser ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE post_id IN (%d, %d) OR type LIKE 'event%%'", $wpdb->prefix . 'mbcom_notifications', $e1, (int) $gev['id'] ) );
mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'places' => false ) ) );

mbcom_section( 'See it as a member' );
$_COOKIE['mbcom_as_member'] = '1';
wp_set_current_user( $admin );
mbcom_check( 'an admin seeing it as a member isn’t an admin or staff', ! \MemBuild\Community\Helpers::can_manage() && ! \MemBuild\Community\Settings::is_staff( $admin ) && \MemBuild\Community\Helpers::as_member() );
list( $s ) = mbcom_call( $admin, 'GET', '/settings' );
mbcom_check( '…so the owner-only settings are closed', 403 === $s || 401 === $s, (string) $s );
wp_set_current_user( $member );
mbcom_check( '…and the same cookie does nothing for a member', ! \MemBuild\Community\Helpers::as_member() );
$_COOKIE['mbcom_as_member'] = 'm.1';
wp_set_current_user( $admin );
$lvl_course = array( 'id' => 0, 'access' => 'level', 'unlock_level' => 3 );
mbcom_check( 'seeing it as a new member (level 1): a level 3 course is locked', 'level' === ( \MemBuild\Community\Access::lock( $lvl_course )['reason'] ?? '' ) );
$_COOKIE['mbcom_as_member'] = 'm.3';
mbcom_check( '…and at level 3 it opens', null === \MemBuild\Community\Access::lock( $lvl_course ) );
$_COOKIE['mbcom_as_member'] = 'mbad';
mbcom_check( '…a broken choice is ignored (owner view)', ! \MemBuild\Community\Helpers::as_member() );
unset( $_COOKIE['mbcom_as_member'] );
wp_set_current_user( $admin );
mbcom_check( 'back to your view: an admin again', \MemBuild\Community\Helpers::can_manage() && \MemBuild\Community\Settings::is_staff( $admin ) );

mbcom_section( 'Muted chats, For you and Alerts' );
mbcom_call( $ben, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => 'Are you there?' ) );
list( , $inb ) = mbcom_call( $ana, 'GET', '/messages/threads' );
$row    = current( array_filter( $inb['items'], fn( $t ) => (int) $t['id'] === $tid ) );
$before = (int) $inb['unread'];
mbcom_check( 'a new message counts towards the Messages badge', $row && $row['unread'] > 0 && false === $row['muted'] && $before >= $row['unread'] );
list( $s, $mu ) = mbcom_call( $ana, 'PUT', "/messages/threads/{$tid}/mute" );
mbcom_check( 'a member can mute a chat, and it stops counting', 200 === $s && true === $mu['muted'] && $before - $row['unread'] === (int) $mu['unread'], wp_json_encode( $mu ) );
list( , $inb ) = mbcom_call( $ana, 'GET', '/messages/threads' );
$row = current( array_filter( $inb['items'], fn( $t ) => (int) $t['id'] === $tid ) );
mbcom_check( '…it stays in the list, marked muted, with its own unread count', $row && true === $row['muted'] && $row['unread'] > 0 );
list( , $pl ) = mbcom_call( $ana, 'GET', '/messages/pulse' );
mbcom_check( '…and the live badge leaves it out too', (int) $pl['unread'] === (int) $mu['unread'] );
$mbcom_mails = array();
// Her other chats (some left by earlier runs of these tests) are read, so only the muted one could be emailed.
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mbcom_thread_members m SET m.last_read_id = (SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}mbcom_messages) WHERE m.user_id = %d AND m.thread_id <> %d", $ana, $tid ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mbcom_messages SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) WHERE thread_id = %d", $tid ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mbcom_notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = %d", $ana ) );
delete_user_meta( $ana, 'mbcom_email_last' );
delete_user_meta( $ana, 'mbcom_last_active' );
\MemBuild\Community\Email::send_summary( $ana );
$mute_mail = implode( ' ', array_map( fn( $m ) => $m['message'], array_filter( $mbcom_mails, fn( $m ) => 'mbct_ana@example.test' === $m['to'] ) ) );
mbcom_check( '…and no email about a muted chat', false === strpos( $mute_mail, 'sent you' ), substr( wp_strip_all_tags( $mute_mail ), 0, 120 ) );
list( $s ) = mbcom_call( $cy, 'PUT', "/messages/threads/{$tid}/mute" );
mbcom_check( 'outsiders can’t mute someone else’s chat', in_array( $s, array( 403, 404 ), true ), (string) $s );
list( $s, $um ) = mbcom_call( $ana, 'DELETE', "/messages/threads/{$tid}/mute" );
list( , $pl2 ) = mbcom_call( $ana, 'GET', '/messages/pulse' );
mbcom_check( 'unmuting brings it back into the count', 200 === $s && false === $um['muted'] && (int) $um['unread'] >= (int) $row['unread'] && (int) $pl2['unread'] === (int) $um['unread'], wp_json_encode( array( $um, $row['unread'] ) ) );
$mbcom_mails = array();
delete_user_meta( $ana, 'mbcom_email_last' );
delete_user_meta( $ana, 'mbcom_last_active' );
\MemBuild\Community\Email::send_summary( $ana );
$mute_mail = implode( ' ', array_map( fn( $m ) => $m['message'], array_filter( $mbcom_mails, fn( $m ) => 'mbct_ana@example.test' === $m['to'] ) ) );
mbcom_check( '…and into the emails', false !== strpos( $mute_mail, 'sent you' ) );
update_user_meta( $ana, 'mbcom_muted_chats', array( '5 OR 1=1', -3, 'x' ) );
mbcom_check( 'a broken mute list can’t change the SQL', ' AND m.thread_id NOT IN (5)' === \MemBuild\Community\Rest_Messages::not_muted_sql( $ana, 'm.thread_id' ) );
delete_user_meta( $ana, 'mbcom_muted_chats' );

list( , $grp ) = mbcom_call( $ana, 'POST', '/messages/threads', array( 'user_ids' => array( $ben, $cy ) ) );
$gid = (int) ( $grp['id'] ?? 0 );
list( $s, $rn ) = mbcom_call( $ben, 'PUT', "/messages/threads/{$gid}", array( 'title' => '  Launch crew  ' ) );
mbcom_check( 'anyone in a group can rename it, and spaces around the name are dropped', 200 === $s && 'Launch crew' === $rn['title'], wp_json_encode( $rn['title'] ?? $rn ) );
list( , $rn ) = mbcom_call( $ana, 'PUT', "/messages/threads/{$gid}", array( 'title' => str_repeat( 'é', 120 ) ) );
mbcom_check( '…a very long name is cut to 80 characters', 80 === mb_strlen( (string) $rn['title'] ) );
list( , $rn ) = mbcom_call( $ana, 'PUT', "/messages/threads/{$gid}", array( 'title' => '<b>Bold</b> crew' ) );
mbcom_check( '…tags are stripped', 'Bold crew' === $rn['title'], (string) $rn['title'] );
list( , $rn ) = mbcom_call( $ana, 'PUT', "/messages/threads/{$gid}", array( 'title' => '   ' ) );
mbcom_check( '…and an empty name goes back to the members’ names', null === $rn['title'] );
list( $s ) = mbcom_call( $ana, 'PUT', "/messages/threads/{$tid}", array( 'title' => 'Not a group' ) );
mbcom_check( '…a one-to-one chat has no name to change', 400 === $s, (string) $s );

mbcom_call( $admin, 'PUT', '/settings', array( 'features' => array( 'reels' => true ) ) );
$mt = $wpdb->prefix . 'mbcom_media';
$embed_row = function ( $user, $provider ) use ( $wpdb, $mt ) {
	$wpdb->insert( $mt, array( 'user_id' => $user, 'kind' => 'embed', 'url' => 'https://www.youtube-nocookie.com/embed/abcdefghijk', 'mime' => 'embed/' . $provider, 'width' => 9, 'height' => 16, 'meta' => wp_json_encode( array( 'provider' => $provider, 'id' => 'abcdefghijk', 'title' => 'A short', 'author' => 'Someone', 'vertical' => true ) ), 'created_at' => current_time( 'mysql', true ) ) );
	return (int) $wpdb->insert_id;
};
list( $s, $yt_reel ) = mbcom_call( $ana, 'POST', '/posts', array( 'type' => 'reel', 'content' => 'Worth a watch', 'media_ids' => array( $embed_row( $ana, 'youtube' ) ) ) );
mbcom_check( 'a YouTube Short can be shared as a reel', 201 === $s && 'reel' === $yt_reel['type'] && 'embed' === $yt_reel['media'][0]['kind'], (string) $s );
list( $s ) = mbcom_call( $ana, 'POST', '/posts', array( 'type' => 'reel', 'content' => 'Vimeo', 'media_ids' => array( $embed_row( $ana, 'vimeo' ) ) ) );
mbcom_check( '…but not a Vimeo link (those go in a normal post)', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $ben, 'POST', '/posts', array( 'type' => 'reel', 'content' => 'Not mine', 'media_ids' => array( $embed_row( $ana, 'youtube' ) ) ) );
mbcom_check( '…and nobody can post someone else’s link', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $ben, 'DELETE', "/posts/{$yt_reel['id']}" );
mbcom_check( 'members can’t remove someone else’s reel', in_array( $s, array( 403, 404 ), true ), (string) $s );
list( $s ) = mbcom_call( $admin, 'DELETE', "/posts/{$yt_reel['id']}" );
mbcom_check( '…the owner can (Remove reel)', 200 === $s, (string) $s );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND kind = %s AND post_id IS NULL', $mt, $ana, 'embed' ) );

// The chat list: tabs and search answered by the server, pages that never skip a chat.
$tm = $wpdb->prefix . 'mbcom_threads';
$wpdb->query( $wpdb->prepare( "UPDATE {$tm} t JOIN {$wpdb->prefix}mbcom_thread_members m ON m.thread_id = t.id AND m.user_id = %d SET t.last_message_at = %s WHERE t.last_message_id > 0", $ana, gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); // the same time for all: only ids tell them apart
list( , $all ) = mbcom_call( $ana, 'GET', '/messages/threads' );
$seen_ids = array();
$cursor   = array();
do {
	list( , $pg ) = mbcom_call( $ana, 'GET', '/messages/threads', $cursor );
	$seen_ids     = array_merge( $seen_ids, array_column( $pg['items'], 'id' ) );
	$last         = end( $pg['items'] );
	$cursor       = $last ? array( 'before' => $last['last_at'], 'before_id' => $last['id'] ) : array();
} while ( $pg['has_more'] && $last );
$total_chats = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tm} t JOIN {$wpdb->prefix}mbcom_thread_members m ON m.thread_id = t.id AND m.user_id = %d AND m.left_at IS NULL WHERE t.last_message_id > 0", $ana ) );
mbcom_check( 'chat pages reach every chat once, even when they share a time', count( $seen_ids ) === $total_chats && count( array_unique( $seen_ids ) ) === $total_chats, count( $seen_ids ) . ' of ' . $total_chats );
mbcom_call( $ben, 'POST', "/messages/threads/{$gid}/messages", array( 'content' => 'Hello group' ) );
list( , $grp_only ) = mbcom_call( $ana, 'GET', '/messages/threads', array( 'filter' => 'groups' ) );
mbcom_check( 'the Groups tab lists only groups', in_array( $gid, array_column( $grp_only['items'], 'id' ), true ) && ! array_filter( $grp_only['items'], fn( $t ) => 'group' !== $t['type'] ) );
mbcom_call( $ben, 'POST', "/messages/threads/{$tid}/messages", array( 'content' => 'Unread tab test' ) );
list( , $unr ) = mbcom_call( $ana, 'GET', '/messages/threads', array( 'filter' => 'unread' ) );
mbcom_check( 'the Unread tab lists only chats with new messages', $unr['items'] && ! array_filter( $unr['items'], fn( $t ) => ! $t['unread'] ) && in_array( $tid, array_column( $unr['items'], 'id' ), true ) );
$ben_name = get_userdata( $ben )->display_name;
list( , $found ) = mbcom_call( $ana, 'GET', '/messages/threads', array( 'search' => mb_substr( $ben_name, 0, 4 ) ) );
mbcom_check( 'chat search finds a chat by a member’s name', in_array( $tid, array_column( $found['items'], 'id' ), true ) );
list( , $none ) = mbcom_call( $ana, 'GET', '/messages/threads', array( 'search' => "%' OR 1=1 --" ) );
mbcom_check( '…and SQL-looking text finds nothing', array() === $none['items'] );

list( $s, $fy ) = mbcom_call( $ana, 'GET', '/home/for-you' );
mbcom_check( 'For you answers with an event, a course and new members (each may be empty)', 200 === $s && array_key_exists( 'event', $fy ) && array_key_exists( 'course', $fy ) && array_key_exists( 'members', $fy ) );
mbcom_check( '…new members this week counts people who just joined, not you', $fy['members'] && $fy['members']['count'] >= 1 && ! in_array( $ana, array_column( $fy['members']['faces'], 'id' ), true ) && count( $fy['members']['faces'] ) <= 3 );
list( $s ) = mbcom_call( 0, 'GET', '/home/for-you' );
mbcom_check( '…and is only for members', in_array( $s, array( 401, 403 ), true ), (string) $s );

mbcom_call( $ben, 'POST', "/posts/{$p3['id']}/comments", array( 'content' => 'One more for the alerts' ) );
list( , $al ) = mbcom_call( $ana, 'GET', '/notifications' );
$real = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_notifications WHERE user_id = %d AND read_at IS NULL", $ana ) );
mbcom_check( 'Alerts tell the app how many are unread (for “Unread · 3”)', isset( $al['unread'] ) && $real === (int) $al['unread'] && $real > 0, wp_json_encode( array( $al['unread'] ?? null, $real ) ) );

mbcom_section( 'Staying logged in' );
$NS = 'MemBuild\\Community\\';
$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
mbcom_check( '“Keep me logged in” keeps a member logged in for a year', YEAR_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $ana, true ) );
mbcom_check( '…without it, WordPress’s 2 days', 2 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $ana, false ) );
mbcom_check( '…site admins keep WordPress’s 14 days', 14 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $admin_id, true ) );
$tok_mgr = WP_Session_Tokens::get_instance( $ana );
$old_tok = $tok_mgr->create( time() + 14 * DAY_IN_SECONDS ); // a remembered login from before this change
$sess = $tok_mgr->get( $old_tok );
$sess['login'] = time() - 3 * DAY_IN_SECONDS;
$tok_mgr->update( $old_tok, $sess );
mbcom_check( 'a remembered login in use gets a fresh year', call_user_func( array( $NS . 'Login', 'renew_session' ), $ana, array( 'token' => $old_tok, 'expiration' => $sess['expiration'] ) ) && $tok_mgr->get( $old_tok )['expiration'] >= time() + YEAR_IN_SECONDS - 60 );
mbcom_check( '…but only once a day', ! call_user_func( array( $NS . 'Login', 'renew_session' ), $ana, array( 'token' => $old_tok, 'expiration' => time() + YEAR_IN_SECONDS ) ) );
$short_tok = $tok_mgr->create( time() + 2 * DAY_IN_SECONDS );
mbcom_check( '…a login without “Keep me logged in” is left to end', ! call_user_func( array( $NS . 'Login', 'renew_session' ), $ana, array( 'token' => $short_tok, 'expiration' => time() + 2 * DAY_IN_SECONDS ) ) && $tok_mgr->get( $short_tok )['expiration'] <= time() + 2 * DAY_IN_SECONDS );
$tok_mgr->destroy( $old_tok );
mbcom_check( '…and logging out still ends it', ! call_user_func( array( $NS . 'Login', 'renew_session' ), $ana, array( 'token' => $old_tok, 'expiration' => time() ) ) && null === $tok_mgr->get( $old_tok ) );
$tok_mgr->destroy( $short_tok );

mbcom_section( 'Security review, 30 Oct' );
$sr_a = mbcom_user( 'mbct_sr_ada' );
$sr_c = mbcom_user( 'mbct_sr_cleo' );
list( , $sr_story ) = mbcom_call( $sr_a, 'POST', '/stories', array( 'text' => 'Only for people I haven’t blocked' ) );
list( , $sr_post )  = mbcom_call( $sr_a, 'POST', '/posts', array( 'content' => 'A post by Ada' ) );
mbcom_call( $sr_c, 'POST', "/posts/{$sr_post['id']}/reaction", array( 'type' => 'love' ) );
mbcom_call( $sr_c, 'PUT', "/posts/{$sr_post['id']}/reaction", array( 'type' => 'love' ) );
mbcom_call( $sr_a, 'PUT', "/members/{$sr_c}/block" );
list( $s ) = mbcom_call( $sr_c, 'POST', "/stories/{$sr_story['id']}/view" );
mbcom_check( 'someone blocked can’t open your story by its number', 404 === $s, (string) $s );
list( $s ) = mbcom_call( $sr_c, 'PUT', "/stories/{$sr_story['id']}/reaction", array( 'type' => 'love' ) );
mbcom_check( '…or react to it', 404 === $s, (string) $s );
list( $s ) = mbcom_call( $sr_c, 'POST', "/stories/{$sr_story['id']}/reply", array( 'content' => 'hi' ) );
mbcom_check( '…or reply to it', 404 === $s, (string) $s );
list( $s ) = mbcom_call( $sr_c, 'POST', '/reports', array( 'object_type' => 'post', 'object_id' => (int) $sr_post['id'], 'reason' => 'spam' ) );
mbcom_check( 'someone blocked can’t report (and so help hide) your post', 404 === $s, (string) $s );
list( , $sr_who ) = mbcom_call( $sr_a, 'GET', "/posts/{$sr_post['id']}/reactions" );
mbcom_check( 'who reacted leaves out people you blocked', ! in_array( $sr_c, array_map( fn( $i ) => (int) $i['user']['id'], $sr_who['items'] ?? array() ), true ), wp_json_encode( $sr_who ) );
mbcom_call( $sr_a, 'DELETE', "/members/{$sr_c}/block" );
mbcom_call( $sr_a, 'DELETE', "/stories/{$sr_story['id']}" );
list( $s ) = mbcom_call( $sr_c, 'DELETE', "/lessons/{$draft_l}/complete" );
mbcom_check( 'a draft course’s lessons can’t be found by marking them not done', 404 === $s, (string) $s );
$sr_ed = mbcom_user( 'mbct_sr_editor', 'editor' );
mbcom_check( 'editors keep WordPress’s 14 days, not a year', 14 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $sr_ed, true ) );
$sr_tok  = WP_Session_Tokens::get_instance( $sr_a );
$sr_long = $sr_tok->create( time() + 3 * DAY_IN_SECONDS );
$sr_sess = $sr_tok->get( $sr_long );
$sr_sess['login'] = time() - 2 * YEAR_IN_SECONDS;
$sr_tok->update( $sr_long, $sr_sess );
mbcom_check( 'a login two years old isn’t renewed again, however often it’s used', ! call_user_func( array( 'MemBuild\\Community\\Login', 'renew_session' ), $sr_a, array( 'token' => $sr_long, 'expiration' => $sr_sess['expiration'] ) ) );
$sr_tok->destroy( $sr_long );

mbcom_section( 'Topics' );
$tp_admin  = mbcom_user( 'mbct_admin', 'administrator' );
$tp_mod    = mbcom_user( 'mbct_tp_mod', 'editor' );
$tp_in     = mbcom_user( 'mbct_tp_inside' );
$tp_out    = mbcom_user( 'mbct_tp_outside' );
list( $s ) = mbcom_call( $tp_out, 'POST', '/topics', array( 'name' => 'Sneaky' ) );
mbcom_check( 'members can’t make topics', in_array( $s, array( 401, 403 ), true ), (string) $s );
list( $s, $tp_list ) = mbcom_call( $tp_admin, 'POST', '/topics', array( 'name' => 'Pricing club', 'color' => '#087a66', 'description' => 'Prices, tiers, offers' ) );
mbcom_check( 'an admin makes a topic', 201 === $s && 'Pricing club' === end( $tp_list['items'] )['name'], (string) $s );
$tp_open = (int) end( $tp_list['items'] )['id'];
list( $s ) = mbcom_call( $tp_admin, 'POST', '/topics', array( 'name' => 'pricing CLUB' ) );
mbcom_check( '…names are unique, whatever the capitals', 400 === $s, (string) $s );
list( , $tp_grp ) = mbcom_call( $tp_admin, 'POST', '/groups', array( 'name' => 'Topic VIPs' ) );
mbcom_call( $tp_admin, 'POST', "/groups/{$tp_grp['id']}/members", array( 'user_ids' => array( $tp_in ) ) );
list( , $tp_list ) = mbcom_call( $tp_admin, 'POST', '/topics', array( 'name' => 'VIP lounge', 'group_id' => (int) $tp_grp['id'] ) );
$tp_vip = (int) end( $tp_list['items'] )['id'];
list( , $tp_list ) = mbcom_call( $tp_admin, 'POST', '/topics', array( 'name' => 'Announcements', 'staff_only' => true ) );
$tp_news = (int) end( $tp_list['items'] )['id'];
list( , $tl ) = mbcom_call( $tp_out, 'GET', '/topics' );
mbcom_check( 'a members-only topic isn’t listed for others', ! in_array( $tp_vip, wp_list_pluck( $tl['items'], 'id' ), true ) && in_array( $tp_open, wp_list_pluck( $tl['items'], 'id' ), true ) );
list( , $tl ) = mbcom_call( $tp_in, 'GET', '/topics' );
mbcom_check( '…but is for its members', in_array( $tp_vip, wp_list_pluck( $tl['items'], 'id' ), true ) );
list( $s, $tp_p ) = mbcom_call( $tp_out, 'POST', '/posts', array( 'content' => 'My first price rise', 'topic_id' => $tp_open ) );
mbcom_check( 'a post can go in a topic', 201 === $s && $tp_open === (int) ( $tp_p['topic']['id'] ?? 0 ), wp_json_encode( $tp_p['topic'] ?? null ) );
list( $s ) = mbcom_call( $tp_out, 'POST', '/posts', array( 'content' => 'Sneaking into the lounge', 'topic_id' => $tp_vip ) );
mbcom_check( '…not one you can’t see', 400 === $s, (string) $s );
list( $s ) = mbcom_call( $tp_out, 'POST', '/posts', array( 'content' => 'Big news from me', 'topic_id' => $tp_news ) );
mbcom_check( '…and only the team posts in a team-only topic', 403 === $s, (string) $s );
list( $s ) = mbcom_call( $tp_mod, 'POST', '/posts', array( 'content' => 'Big news from the team', 'topic_id' => $tp_news ) );
mbcom_check( '…which moderators can', 201 === $s, (string) $s );
list( , $tp_v ) = mbcom_call( $tp_in, 'POST', '/posts', array( 'content' => 'Lounge secret: the ocelot code', 'topic_id' => $tp_vip ) );
list( , $f ) = mbcom_call( $tp_out, 'GET', '/feed' );
mbcom_check( 'a members-only topic’s posts stay out of others’ Home', ! in_array( (int) $tp_v['id'], wp_list_pluck( $f['items'], 'id' ), true ) );
list( $s ) = mbcom_call( $tp_out, 'GET', "/posts/{$tp_v['id']}" );
mbcom_check( '…and can’t be opened by their number', 404 === $s, (string) $s );
list( , $f ) = mbcom_call( $tp_in, 'GET', '/feed' );
mbcom_check( '…while its members see them', in_array( (int) $tp_v['id'], wp_list_pluck( $f['items'], 'id' ), true ) );
list( , $f ) = mbcom_call( $tp_out, 'GET', '/feed', array( 'topic' => $tp_open ) );
mbcom_check( 'Home filtered to a topic shows only its posts', $f['items'] && ! array_filter( $f['items'], fn( $x ) => $tp_open !== (int) ( $x['topic']['id'] ?? 0 ) ) );
list( $s ) = mbcom_call( $tp_out, 'PUT', "/posts/{$tp_p['id']}/topic", array( 'topic_id' => $tp_news ) );
mbcom_check( 'members can’t move other posts between topics', in_array( $s, array( 401, 403 ), true ), (string) $s );
list( $s, $mv ) = mbcom_call( $tp_mod, 'PUT', "/posts/{$tp_p['id']}/topic", array( 'topic_id' => 0 ) );
mbcom_check( 'a moderator moves a post (here: to no topic)', 200 === $s && null === $mv['topic'], (string) $s );
$tp_note = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_notifications WHERE user_id = %d AND type = 'post_moved'", $tp_out ) );
mbcom_check( '…and its author is told', (int) $tp_note >= 1 );
list( , $tp_p2 ) = mbcom_call( $tp_out, 'PUT', "/posts/{$tp_p['id']}", array( 'content' => 'My first price rise (edited)', 'topic_id' => $tp_open ) );
mbcom_check( 'the author can change it back when editing', $tp_open === (int) ( $tp_p2['topic']['id'] ?? 0 ) );
list( , $tl ) = mbcom_call( $tp_admin, 'PUT', '/topics/order', array( 'ids' => array( $tp_news, $tp_open ) ) );
mbcom_check( 'owners set the order', $tp_news === (int) $tl['items'][0]['id'] );
list( , $tl ) = mbcom_call( $tp_admin, 'DELETE', "/topics/{$tp_open}", array( 'move_to' => 0 ) );
$tp_still = $wpdb->get_row( $wpdb->prepare( "SELECT status, topic_id FROM {$wpdb->prefix}mbcom_posts WHERE id = %d", $tp_p['id'] ), ARRAY_A );
mbcom_check( 'deleting a topic keeps its posts, without a topic', 'published' === $tp_still['status'] && 0 === (int) $tp_still['topic_id'] );
foreach ( array( $tp_vip, $tp_news ) as $tp_id ) {
	mbcom_call( $tp_admin, 'DELETE', "/topics/{$tp_id}" );
}
mbcom_call( $tp_admin, 'DELETE', "/groups/{$tp_grp['id']}" );

mbcom_section( 'Login forms and where they come from' );
$lf = new ReflectionMethod( \MemBuild\Community\Login::class, 'cross_site' );
$lf->setAccessible( true );
$lf_try = function ( $origin, $fetch = null ) use ( $lf ) {
	unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE'] );
	if ( null !== $origin ) {
		$_SERVER['HTTP_ORIGIN'] = $origin;
	}
	if ( null !== $fetch ) {
		$_SERVER['HTTP_SEC_FETCH_SITE'] = $fetch;
	}
	$r = $lf->invoke( null );
	unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE'] );
	return $r;
};
mbcom_check( 'a login form whose origin the browser hides ("null", as Chrome sends) is accepted', false === $lf_try( 'null', 'same-origin' ) );
mbcom_check( '…and one from this site', false === $lf_try( home_url(), 'same-origin' ) );
mbcom_check( '…but one posted from another site is refused', true === $lf_try( 'https://evil.example', null ) && true === $lf_try( home_url(), 'cross-site' ) );

mbcom_section( 'Lesson order' );
$lo_admin = mbcom_user( 'mbct_admin', 'administrator' );
$lo_mod   = mbcom_user( 'mbct_tp_mod', 'editor' );
$lo_m     = mbcom_user( 'mbct_lo_member' );
list( , $lo_c ) = mbcom_call( $lo_admin, 'POST', '/courses', array( 'title' => 'Step by step' ) );
$lo_mid  = $lo_c['modules'][0]['id'];
$lo_ids  = array();
foreach ( array( 'One', 'Two', 'Three' ) as $lo_t ) {
	list( , $lo_l ) = mbcom_call( $lo_admin, 'POST', "/modules/{$lo_mid}/lessons", array( 'title' => $lo_t ) );
	$lo_ids[]       = (int) $lo_l['id'];
}
mbcom_call( $lo_admin, 'PUT', "/courses/{$lo_c['id']}", array( 'status' => 'published' ) );
list( $s ) = mbcom_call( $lo_m, 'GET', "/lessons/{$lo_ids[2]}" );
mbcom_check( 'a course opens in any order by default', 200 === $s, (string) $s );
list( , $lo_v ) = mbcom_call( $lo_admin, 'PUT', "/courses/{$lo_c['id']}", array( 'in_order' => true ) );
mbcom_check( 'an owner sets it to one after another', true === ( $lo_v['in_order'] ?? null ) );
list( $s, $lo_e ) = mbcom_call( $lo_m, 'GET', "/lessons/{$lo_ids[1]}" );
mbcom_check( '…then a lesson waits for the one before it, and says which', 403 === $s && 'mbcom_order_locked' === ( $lo_e['code'] ?? '' ) && false !== strpos( $lo_e['message'], 'One' ), wp_json_encode( $lo_e ) );
list( $s ) = mbcom_call( $lo_m, 'POST', "/lessons/{$lo_ids[1]}/complete" );
mbcom_check( '…and can’t be marked done early', 403 === $s, (string) $s );
list( , $lo_o ) = mbcom_call( $lo_m, 'GET', "/courses/{$lo_c['id']}" );
$lo_ls = $lo_o['modules'][0]['lessons'];
mbcom_check( 'the outline shows the first open, the rest locked with what comes before', ! $lo_ls[0]['locked'] && $lo_ls[1]['locked'] && 'One' === ( $lo_ls[1]['after']['title'] ?? '' ) && $lo_ls[2]['locked'], wp_json_encode( array_column( $lo_ls, 'locked' ) ) );
mbcom_call( $lo_m, 'POST', "/lessons/{$lo_ids[0]}/complete" );
list( $s ) = mbcom_call( $lo_m, 'GET', "/lessons/{$lo_ids[1]}" );
list( $s3 ) = mbcom_call( $lo_m, 'GET', "/lessons/{$lo_ids[2]}" );
mbcom_check( 'finishing a lesson opens the next one, not the one after', 200 === $s && 403 === $s3, "{$s} {$s3}" );
list( $s ) = mbcom_call( $lo_mod, 'GET', "/lessons/{$lo_ids[2]}" );
mbcom_check( 'moderators open every lesson', 200 === $s, (string) $s );
// A lesson someone finished stays theirs, even when one is added before it.
mbcom_call( $lo_m, 'POST', "/lessons/{$lo_ids[1]}/complete" );
$wpdb->update( \MemBuild\Community\Install::table( 'lessons' ), array( 'sort_order' => 5 ), array( 'id' => $lo_ids[0] ) ); // "One" moves to the end, after "Three"
list( $s ) = mbcom_call( $lo_m, 'GET', "/lessons/{$lo_ids[1]}" );
mbcom_check( 'a finished lesson never locks again, whatever changes', 200 === $s, (string) $s );
mbcom_call( $lo_admin, 'DELETE', "/courses/{$lo_c['id']}" );

mbcom_section( 'Import' );
$im_admin = mbcom_user( 'mbct_admin', 'administrator' );
$im_a     = mbcom_user( 'mbct_im_ana' );
$im_b     = mbcom_user( 'mbct_im_ben' );
$im_out   = mbcom_user( 'mbct_im_outside' );
$im_p     = $wpdb->base_prefix;
delete_option( 'mbcom_import_job' );
delete_option( 'mbcom_imports' );
$im_run = function () {
	for ( $i = 0; $i < 60; $i++ ) {
		delete_transient( 'mbcom_import_lock' );
		\MemBuild\Community\Import::tick( 3 );
		$j = \MemBuild\Community\Import::job();
		if ( 'running' !== $j['status'] ) {
			return $j;
		}
	}
	return \MemBuild\Community\Import::job();
};
list( $s ) = mbcom_call( $im_out, 'GET', '/import' );
mbcom_check( 'members can’t open Import', in_array( $s, array( 401, 403 ), true ), (string) $s );

// A small BuddyPress community, in BuddyPress's own tables (the plugin isn't here, as when it's switched off).
$wpdb->query( "CREATE TABLE {$im_p}bp_activity (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint, component varchar(75), type varchar(75), action text, content longtext, primary_link text, item_id bigint, secondary_item_id bigint, date_recorded datetime, hide_sitewide tinyint DEFAULT 0, mptt_left int DEFAULT 0, mptt_right int DEFAULT 0, is_spam tinyint DEFAULT 0)" );
$wpdb->query( "CREATE TABLE {$im_p}bp_activity_meta (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, activity_id bigint, meta_key varchar(255), meta_value longtext)" );
$wpdb->query( "CREATE TABLE {$im_p}bp_groups (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, creator_id bigint, name varchar(100), slug varchar(200), description longtext, status varchar(10), parent_id bigint DEFAULT 0, enable_forum tinyint DEFAULT 0, date_created datetime)" );
$wpdb->query( "CREATE TABLE {$im_p}bp_groups_members (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, group_id bigint, user_id bigint, inviter_id bigint DEFAULT 0, is_admin tinyint DEFAULT 0, is_mod tinyint DEFAULT 0, user_title varchar(100) DEFAULT '', date_modified datetime, comments longtext, is_confirmed tinyint DEFAULT 0, is_banned tinyint DEFAULT 0, invite_sent tinyint DEFAULT 0)" );
$wpdb->query( "CREATE TABLE {$im_p}bp_messages_messages (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, thread_id bigint, sender_id bigint, subject varchar(200), message longtext, date_sent datetime)" );
$wpdb->query( "CREATE TABLE {$im_p}bp_messages_recipients (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint, thread_id bigint, unread_count int DEFAULT 0, sender_only tinyint DEFAULT 0, is_deleted tinyint DEFAULT 0)" );
$wpdb->query( "CREATE TABLE {$im_p}bp_xprofile_fields (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, group_id bigint, parent_id bigint DEFAULT 0, type varchar(150), name varchar(150))" );
$wpdb->query( "CREATE TABLE {$im_p}bp_xprofile_data (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, field_id bigint, user_id bigint, value longtext, last_updated datetime)" );
$wpdb->insert( "{$im_p}bp_xprofile_fields", array( 'group_id' => 1, 'type' => 'textbox', 'name' => 'Location' ) );
$wpdb->insert( "{$im_p}bp_xprofile_data", array( 'field_id' => $wpdb->insert_id, 'user_id' => $im_a, 'value' => 'Cebu City', 'last_updated' => '2025-01-01 00:00:00' ) );
$wpdb->insert( "{$im_p}bp_groups", array( 'creator_id' => $im_a, 'name' => 'Imported Insiders', 'slug' => 'insiders', 'description' => 'Members <b>only</b>', 'status' => 'private', 'date_created' => '2025-01-01 00:00:00' ) );
$im_g = (int) $wpdb->insert_id;
$wpdb->insert( "{$im_p}bp_groups_members", array( 'group_id' => $im_g, 'user_id' => $im_a, 'is_admin' => 1, 'is_confirmed' => 1, 'date_modified' => '2025-01-01 00:00:00', 'comments' => '' ) );
$wpdb->insert( "{$im_p}bp_groups_members", array( 'group_id' => $im_g, 'user_id' => $im_out, 'is_confirmed' => 0, 'invite_sent' => 1, 'date_modified' => '2025-01-01 00:00:00', 'comments' => '' ) );
$im_act = function ( $row ) use ( $wpdb, $im_p ) {
	$wpdb->insert( "{$im_p}bp_activity", $row + array( 'action' => '', 'primary_link' => '', 'item_id' => 0, 'secondary_item_id' => 0 ) );
	return (int) $wpdb->insert_id;
};
$im_p1 = $im_act( array( 'user_id' => $im_a, 'component' => 'activity', 'type' => 'activity_update', 'content' => "Hello from the old site, <a class='bp-suggestions-mention' href='http://x/members/ben/'>@Ben</a>!", 'date_recorded' => '2024-05-01 10:00:00' ) );
$im_p2 = $im_act( array( 'user_id' => $im_a, 'component' => 'groups', 'type' => 'activity_update', 'item_id' => $im_g, 'content' => 'Insiders only: the ocelot plan', 'date_recorded' => '2024-05-02 10:00:00' ) );
$im_act( array( 'user_id' => $im_b, 'component' => 'activity', 'type' => 'activity_update', 'content' => 'Buy cheap pills', 'date_recorded' => '2024-05-03 10:00:00', 'is_spam' => 1 ) );
$im_c1 = $im_act( array( 'user_id' => $im_b, 'component' => 'activity', 'type' => 'activity_comment', 'item_id' => $im_p1, 'secondary_item_id' => $im_p1, 'content' => 'Welcome!', 'date_recorded' => '2024-05-01 11:00:00' ) );
$im_act( array( 'user_id' => $im_a, 'component' => 'activity', 'type' => 'activity_comment', 'item_id' => $im_p1, 'secondary_item_id' => $im_c1, 'content' => 'Thanks Ben', 'date_recorded' => '2024-05-01 12:00:00' ) );
$im_act( array( 'user_id' => $im_b, 'component' => 'activity', 'type' => 'activity_comment', 'item_id' => $im_p1, 'secondary_item_id' => $im_c1 + 1, 'content' => 'Anytime', 'date_recorded' => '2024-05-01 13:00:00' ) );
update_user_meta( $im_b, 'bp_favorite_activities', array( $im_p1 ) );
$wpdb->insert( "{$im_p}bp_messages_messages", array( 'thread_id' => 7, 'sender_id' => $im_a, 'subject' => 'Hi', 'message' => 'A private hello', 'date_sent' => '2024-05-04 09:00:00' ) );
$wpdb->insert( "{$im_p}bp_messages_messages", array( 'thread_id' => 7, 'sender_id' => $im_b, 'subject' => 'Re: Hi', 'message' => 'Hello back', 'date_sent' => '2024-05-04 09:05:00' ) );
foreach ( array( $im_a, $im_b ) as $u ) {
	$wpdb->insert( "{$im_p}bp_messages_recipients", array( 'user_id' => $u, 'thread_id' => 7 ) );
}

list( $s, $st ) = mbcom_call( $im_admin, 'GET', '/import' );
$im_bp = array_values( array_filter( $st['found'] ?? array(), fn( $x ) => 'buddypress' === $x['key'] ) )[0] ?? null;
mbcom_check( 'BuddyPress is found with its plugin switched off, with its numbers', 200 === $s && $im_bp && 2 === $im_bp['posts'] && 3 === $im_bp['comments'] && 1 === $im_bp['groups'], wp_json_encode( $im_bp ) );
$im_all = array( 'profiles', 'posts', 'reactions', 'messages', 'points' );
list( $s, $ck ) = mbcom_call( $im_admin, 'POST', '/import/check', array( 'source' => 'buddypress', 'bring' => $im_all ) );
mbcom_check( 'the check says what will come over, and changes nothing', 200 === $s && false !== strpos( wp_json_encode( $ck['items'] ), '2 posts and 3 comments' ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_import_map" ), wp_json_encode( $ck['items'][1] ?? null ) );
list( $s, $j ) = mbcom_call( $im_admin, 'POST', '/import/start', array( 'source' => 'buddypress', 'bring' => $im_all ) );
mbcom_check( 'an import starts', 200 === $s && 'running' === $j['status'], (string) $s );
list( $s ) = mbcom_call( $im_admin, 'POST', '/import/start', array( 'source' => 'buddypress', 'bring' => $im_all ) );
mbcom_check( '…and a second can’t start while it runs', 409 === $s, (string) $s );
$j = $im_run();
mbcom_check( 'it runs to the end in the background', 'done' === $j['status'] && 2 === $j['counts']['posts'] && 3 === $j['counts']['comments'], wp_json_encode( $j['counts'] ) . ' ' . ( $j['error'] ?? '' ) );
$im_map  = fn( $kind, $src ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT local_id FROM {$wpdb->prefix}mbcom_import_map WHERE source = 'buddypress' AND kind = %s AND source_id = %s", $kind, (string) $src ) );
$im_post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mbcom_posts WHERE id = %d", $im_map( 'post', $im_p1 ) ), ARRAY_A );
mbcom_check( 'a post keeps its author and date, its mention as plain words', $im_post && (int) $im_post['user_id'] === $im_a && '2024-05-01 10:00:00' === $im_post['created_at'] && 'Hello from the old site, @Ben!' === $im_post['content'], wp_json_encode( $im_post['content'] ?? null ) );
$im_replies = $wpdb->get_results( $wpdb->prepare( "SELECT parent_id, reply_to_user_id FROM {$wpdb->prefix}mbcom_comments WHERE post_id = %d ORDER BY id", $im_post['id'] ), ARRAY_A );
mbcom_check( 'a reply to a reply joins the top comment and says who it answers', 3 === count( $im_replies ) && (int) $im_replies[2]['parent_id'] === (int) $im_replies[1]['parent_id'] && (int) $im_replies[2]['reply_to_user_id'] === $im_a, wp_json_encode( $im_replies ) );
mbcom_check( 'favourites become likes, and the counts add up', 1 === (int) $im_post['reactions_count'] && 3 === (int) $im_post['comments_count'], $im_post['reactions_count'] . '/' . $im_post['comments_count'] );
mbcom_check( 'spam doesn’t come over', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_posts WHERE content = %s", 'Buy cheap pills' ) ) );
mbcom_check( 'an empty profile field is filled in', 'Cebu City' === get_user_meta( $im_a, 'mbcom_location', true ) );
$im_topic = \MemBuild\Community\Topics::get( $im_map( 'topic', $im_g ) );
mbcom_check( 'a private group becomes a members-only topic with its confirmed members', $im_topic && $im_topic['group_id'] && in_array( $im_a, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}mbcom_group_members WHERE group_id = %d", $im_topic['group_id'] ) ) ), true ) && ! in_array( $im_out, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}mbcom_group_members WHERE group_id = %d", $im_topic['group_id'] ) ) ), true ) );
list( $s ) = mbcom_call( $im_out, 'GET', '/posts/' . $im_map( 'post', $im_p2 ) );
mbcom_check( '…so its posts stay private', 404 === $s, (string) $s );
$im_thread = $im_map( 'thread', 7 );
$im_msgs   = $wpdb->get_col( $wpdb->prepare( "SELECT content FROM {$wpdb->prefix}mbcom_messages WHERE thread_id = %d ORDER BY id", $im_thread ) );
mbcom_check( 'chats come over encrypted, and read back', 2 === count( $im_msgs ) && \MemBuild\Community\Vault::is_sealed( $im_msgs[0] ) && 'Hello back' === \MemBuild\Community\Vault::open( $im_msgs[1], 'message:' . $im_thread ) );
mbcom_check( 'points are worked out from what came over', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_points WHERE object_type = 'post' AND object_id = %d", $im_post['id'] ) ) >= 1 );
mbcom_call( $im_admin, 'POST', '/import/dismiss' );
mbcom_call( $im_admin, 'POST', '/import/start', array( 'source' => 'buddypress', 'bring' => $im_all ) );
$j2 = $im_run();
mbcom_check( 'importing again only brings what’s new (nothing)', 'done' === $j2['status'] && 0 === (int) ( $j2['counts']['posts'] ?? 0 ) && 0 === (int) ( $j2['counts']['comments'] ?? 0 ), wp_json_encode( $j2['counts'] ) );
mbcom_call( $im_admin, 'POST', '/import/dismiss' );
$im_act( array( 'user_id' => $im_b, 'component' => 'activity', 'type' => 'activity_update', 'content' => 'Written after the first import', 'date_recorded' => '2024-06-01 10:00:00' ) );
mbcom_call( $im_admin, 'POST', '/import/start', array( 'source' => 'buddypress', 'bring' => $im_all ) );
$j3 = $im_run();
mbcom_check( '…and a post written since does', 1 === (int) ( $j3['counts']['posts'] ?? 0 ), wp_json_encode( $j3['counts'] ) );
list( $s, $u3 ) = mbcom_call( $im_admin, 'POST', '/import/undo' );
mbcom_check( 'Undo removes exactly what the last import brought', 200 === $s && 'undone' === $u3['status'] && 1 === $u3['undone']['post'] && $im_map( 'post', $im_p1 ), wp_json_encode( $u3['undone'] ?? null ) );
list( , $st ) = mbcom_call( $im_admin, 'GET', '/import' );
$im_first = end( $st['past'] );
mbcom_check( 'past imports are listed, newest first, with what they brought', count( $st['past'] ) >= 3 && 'undone' === $st['past'][0]['status'] && 'done' === $im_first['status'] && 2 === (int) $im_first['counts']['posts'], wp_json_encode( array_column( $st['past'], 'status' ) ) );
$im_post_id = $im_map( 'post', $im_p1 );
list( $s ) = mbcom_call( $im_out, 'POST', "/posts/{$im_post_id}/comments", array( 'content' => 'Written here after the import' ) );
list( , $imp ) = mbcom_call( $im_admin, 'GET', '/import/impact', array( 'id' => (int) $im_first['id'] ) );
mbcom_check( 'undoing an older import first says what members added to it since', 2 === $imp['brought']['posts'] && 1 === $imp['since']['comments'], wp_json_encode( $imp ) . " comment {$s}" );
list( $s, $uo ) = mbcom_call( $im_admin, 'POST', '/import/undo', array( 'id' => (int) $im_first['id'] ) );
mbcom_check( 'an older import undoes on its own', 200 === $s && 2 === $uo['undone']['post'] && 'undone' === array_values( array_filter( $uo['past'], fn( $x ) => (int) $x['id'] === (int) $im_first['id'] ) )[0]['status'], wp_json_encode( $uo['undone'] ?? $uo ) );
mbcom_check( '…taking the comment written since with its post', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_comments WHERE post_id = %d", $im_post_id ) ) );
list( $s ) = mbcom_call( $im_admin, 'POST', '/import/undo', array( 'id' => (int) $im_first['id'] ) );
mbcom_check( '…and only once', 400 === $s, (string) $s );

// The Pro hook: an add-on can take the groups (as spaces) instead of topics.
$im_spaces = array();
$im_hook   = function ( $handler, $source, $groups ) use ( &$im_spaces ) {
	return function ( $gs ) use ( &$im_spaces ) {
		$im_spaces = $gs;
		return array_combine( array_column( $gs, 'source_id' ), array_fill( 0, count( $gs ), 999 ) );
	};
};
add_filter( 'membuild_community/import_groups', $im_hook, 10, 3 );
list( , $st ) = mbcom_call( $im_admin, 'GET', '/import' );
mbcom_check( 'with an add-on taking groups, Import offers spaces', true === $st['pro'] );
remove_filter( 'membuild_community/import_groups', $im_hook, 10 );

// FluentCommunity: its own tables, its times in the site's time zone, its meta serialized.
update_option( 'timezone_string', 'Asia/Manila' );
$fp = $wpdb->prefix . 'fcom_';
$wpdb->query( "CREATE TABLE {$fp}posts (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint, parent_id bigint NULL, title varchar(192) NULL, slug varchar(192) NULL, message longtext NULL, message_rendered longtext NULL, type varchar(100) DEFAULT 'feed', content_type varchar(100) DEFAULT 'text', space_id bigint NULL, privacy varchar(100) DEFAULT 'public', status varchar(100) DEFAULT 'published', featured_image text NULL, meta longtext NULL, is_sticky tinyint DEFAULT 0, comments_count int DEFAULT 0, reactions_count int DEFAULT 0, priority int DEFAULT 0, created_at timestamp NULL, updated_at timestamp NULL)" );
$wpdb->query( "CREATE TABLE {$fp}spaces (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, created_by bigint, parent_id bigint NULL, title varchar(192), slug varchar(192), logo text NULL, cover_photo text NULL, description longtext NULL, type varchar(100), privacy varchar(100) DEFAULT 'public', status varchar(100) DEFAULT 'published', serial int DEFAULT 1, settings longtext NULL, created_at timestamp NULL, updated_at timestamp NULL)" );
$wpdb->query( "CREATE TABLE {$fp}space_user (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, space_id bigint, user_id bigint, status varchar(100) DEFAULT 'active', role varchar(100) DEFAULT 'member', meta text NULL, created_at timestamp NULL, updated_at timestamp NULL)" );
$wpdb->query( "CREATE TABLE {$fp}post_comments (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint, post_id bigint, parent_id bigint NULL, reactions_count int DEFAULT 0, message longtext NULL, message_rendered longtext NULL, meta longtext NULL, type varchar(100) DEFAULT 'comment', content_type varchar(100) DEFAULT 'text', status varchar(100) DEFAULT 'published', is_sticky tinyint DEFAULT 0, created_at timestamp NULL, updated_at timestamp NULL)" );
$wpdb->query( "CREATE TABLE {$fp}post_reactions (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id bigint, object_id bigint, parent_id bigint NULL, object_type varchar(100), type varchar(100), ip_address varchar(100) NULL, created_at timestamp NULL, updated_at timestamp NULL)" );
$wpdb->insert( "{$fp}spaces", array( 'created_by' => $im_a, 'title' => 'Fluent Launchers', 'slug' => 'fl', 'description' => '', 'type' => 'community', 'privacy' => 'public', 'created_at' => '2025-01-01 08:00:00' ) );
$im_fs = (int) $wpdb->insert_id;
$wpdb->insert( "{$fp}posts", array( 'user_id' => $im_b, 'message' => 'x', 'message_rendered' => '<p>From <strong>Fluent</strong></p>', 'type' => 'text', 'space_id' => $im_fs, 'meta' => serialize( array( 'media_items' => array( array( 'url' => 'https://example.com/a.jpg', 'type' => 'image', 'width' => 800, 'height' => 600 ) ) ) ), 'created_at' => '2025-02-01 18:00:00' ) );
$im_fpost = (int) $wpdb->insert_id;
$wpdb->insert( "{$fp}spaces", array( 'created_by' => $im_admin, 'title' => 'Fluent Course', 'slug' => 'fc', 'description' => 'Learn', 'type' => 'course', 'privacy' => 'private', 'created_at' => '2025-01-01 08:00:00' ) );
$im_fc = (int) $wpdb->insert_id;
$wpdb->insert( "{$fp}posts", array( 'user_id' => $im_admin, 'title' => 'Week 1', 'type' => 'course_section', 'space_id' => $im_fc, 'priority' => 1, 'created_at' => '2025-01-01 08:00:00' ) );
$im_sec = (int) $wpdb->insert_id;
$wpdb->insert( "{$fp}posts", array( 'user_id' => $im_admin, 'parent_id' => $im_sec, 'title' => 'Lesson one', 'message_rendered' => '<p>Read this</p>', 'type' => 'course_lesson', 'space_id' => $im_fc, 'priority' => 1, 'created_at' => '2025-01-01 08:00:00' ) );
$im_les = (int) $wpdb->insert_id;
$wpdb->insert( "{$fp}space_user", array( 'space_id' => $im_fc, 'user_id' => $im_b, 'role' => 'student' ) );
$wpdb->insert( "{$fp}post_reactions", array( 'user_id' => $im_b, 'object_id' => $im_les, 'parent_id' => $im_fc, 'object_type' => 'lesson_completed', 'type' => 'completed', 'created_at' => '2025-01-05 08:00:00' ) );
mbcom_call( $im_admin, 'POST', '/import/dismiss' );
mbcom_call( $im_admin, 'POST', '/import/start', array( 'source' => 'fluentcommunity', 'bring' => array( 'posts', 'courses' ) ) );
$jf = $im_run();
$im_fl = $wpdb->get_row( $wpdb->prepare( "SELECT p.created_at, p.content, (SELECT url FROM {$wpdb->prefix}mbcom_media m WHERE m.post_id = p.id) AS photo FROM {$wpdb->prefix}mbcom_posts p WHERE p.id = (SELECT local_id FROM {$wpdb->prefix}mbcom_import_map WHERE source = 'fluentcommunity' AND kind = 'post' AND source_id = %s)", (string) $im_fpost ), ARRAY_A );
mbcom_check( 'FluentCommunity: its local times become UTC, its photos come along', $im_fl && '2025-02-01 10:00:00' === $im_fl['created_at'] && 'From Fluent' === $im_fl['content'] && 'https://example.com/a.jpg' === $im_fl['photo'], wp_json_encode( $im_fl ) . ' ' . ( $jf['error'] ?? '' ) );
$im_course = (int) $wpdb->get_var( $wpdb->prepare( "SELECT local_id FROM {$wpdb->prefix}mbcom_import_map WHERE source = 'fluentcommunity' AND kind = 'course' AND source_id = %s", (string) $im_fc ) );
$im_cg     = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT access_groups FROM {$wpdb->prefix}mbcom_courses WHERE id = %d", $im_course ) ), true );
mbcom_check( '…its course goes into the Classroom with progress, private as a membership', $im_course && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_lesson_progress WHERE course_id = %d AND user_id = %d", $im_course, $im_b ) ) && $im_cg && in_array( $im_b, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}mbcom_group_members WHERE group_id = %d", $im_cg[0] ) ) ), true ) );
mbcom_call( $im_admin, 'POST', '/import/undo' );
mbcom_check( '…and Undo takes the course away again', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_courses WHERE id = %d", $im_course ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mbcom_lessons WHERE course_id = %d", $im_course ) ) );
update_option( 'timezone_string', '' );

// A spreadsheet: columns guessed, bad rows found, people added, a plan matched to a membership.
list( , $im_vip ) = mbcom_call( $im_admin, 'POST', '/groups', array( 'name' => 'Sheet Gold' ) );
update_user_meta( $im_b, 'last_name', 'Original' );
$im_csv = "Email,First Name,Last Name,Member since,Plan\nmbct_sheet_new@example.test,Nia,Sheet,2024-03-02,Sheet Gold\nmbct_im_ben@example.test,Ben,Already,2020-01-01,Sheet Gold\nnot-an-email,Bad,Row,2024-01-01,Free\n";
wp_set_current_user( $im_admin );
$r = new WP_REST_Request( 'POST', $GLOBALS['mbcom_ns'] . '/import/file' );
$r->set_param( 'name', 'members.csv' );
$r->set_header( 'content-type', 'application/octet-stream' );
$r->set_body( $im_csv );
$x  = rest_do_request( $r );
$up = $x->get_data();
mbcom_check( 'a spreadsheet’s columns are guessed from their names', 200 === $x->get_status() && array( 'email', 'first_name', 'last_name', 'joined', 'membership' ) === array_column( $up['columns'], 'guess' ), wp_json_encode( $up['columns'] ?? $up ) );
$im_body = array( 'source' => 'csv', 'file' => $up['file'], 'map' => array_column( $up['columns'], 'guess' ), 'plans' => array(), 'welcome' => false );
list( , $ck ) = mbcom_call( $im_admin, 'POST', '/import/check', $im_body );
mbcom_check( 'the check counts who’s new, who’s here, and rows without an email', 1 === $ck['new'] && 1 === $ck['here'] && 1 === count( $ck['invalid'] ) && 4 === $ck['invalid'][0]['row'], wp_json_encode( array( $ck['new'] ?? null, $ck['here'] ?? null, $ck['invalid'] ?? null ) ) );
mbcom_call( $im_admin, 'POST', '/import/dismiss' );
mbcom_call( $im_admin, 'POST', '/import/start', $im_body );
$jc   = $im_run();
$im_n = get_user_by( 'email', 'mbct_sheet_new@example.test' );
mbcom_check( 'a new member gets an account with their name and joined date', $im_n && 'Nia' === $im_n->first_name && 0 === strpos( $im_n->user_registered, '2024-03-02' ), wp_json_encode( $jc['counts'] ?? null ) );
mbcom_check( '…their plan matches a membership by name', $im_n && in_array( (int) $im_vip['id'], \MemBuild\Community\Access::manual_groups( $im_n->ID ), true ) );
mbcom_check( 'someone already here keeps their name', 'Original' === get_userdata( $im_b )->last_name );
mbcom_check( 'the file is deleted when it’s done', ! file_exists( \MemBuild\Community\Files::dir() . 'import-' . $up['file'] ) );
mbcom_call( $im_admin, 'POST', '/import/undo' );
mbcom_check( 'Undo takes the membership back but keeps the account', $im_n && get_userdata( $im_n->ID ) && ! in_array( (int) $im_vip['id'], \MemBuild\Community\Access::manual_groups( $im_n->ID ), true ) );

// Tidy up: the fake plugins' tables and what's left of the imports.
foreach ( array( 'bp_activity', 'bp_activity_meta', 'bp_groups', 'bp_groups_members', 'bp_messages_messages', 'bp_messages_recipients', 'bp_xprofile_fields', 'bp_xprofile_data' ) as $tb ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$im_p}{$tb}" );
}
foreach ( array( 'posts', 'spaces', 'space_user', 'post_comments', 'post_reactions' ) as $tb ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$fp}{$tb}" );
}
foreach ( $wpdb->get_col( "SELECT DISTINCT import_id FROM {$wpdb->prefix}mbcom_import_map" ) as $im_id ) {
	\MemBuild\Community\Import::undo( (int) $im_id );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}mbcom_import_map" );
delete_option( 'mbcom_import_job' );
delete_option( 'mbcom_imports' );
delete_user_meta( $im_b, 'bp_favorite_activities' );
require_once ABSPATH . 'wp-admin/includes/user.php';
$im_n && wp_delete_user( $im_n->ID );
mbcom_call( $im_admin, 'DELETE', "/groups/{$im_vip['id']}" );

/* ---------------------------------------------------------------- */
echo "\n{$GLOBALS['mbcom_passed']} passed, {$GLOBALS['mbcom_failed']} failed\n";
if ( $GLOBALS['mbcom_failed'] ) {
	exit( 1 );
}
