<?php
/**
 * Attachment abuse: what a hostile or careless member might upload, sent
 * through the same chunked upload API the app uses.
 */
global $wpdb;
$member = (int) get_option( 'mbcom_stress_busy_user' ) + 40;
wp_set_current_user( $member );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%mbcom\\_rl\\_%'" );
wp_cache_flush();

$call = function ( $method, $route, $body = null, $json = true ) {
	$req = new WP_REST_Request( $method, '/' . MEMBUILD_COMMUNITY_REST_NS . $route );
	if ( null !== $body ) {
		if ( $json ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		} else {
			$req->set_header( 'content-type', 'application/octet-stream' );
			$req->set_body( $body );
		}
	}
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data() );
};
$upload = function ( $mime, $bytes, $meta = array(), $claimed = null ) use ( $call ) {
	list( $s, $d ) = $call( 'POST', '/media/uploads', array( 'mime' => $mime, 'size' => $claimed ?? strlen( $bytes ), 'name' => 'test' ) );
	if ( $s >= 400 ) {
		return array( 'start', $s, $d['message'] ?? '' );
	}
	foreach ( str_split( $bytes, $d['chunk_bytes'] ) as $i => $chunk ) {
		list( $s, $e ) = $call( 'POST', "/media/uploads/{$d['upload_id']}/chunks/{$i}", $chunk, false );
		if ( $s >= 400 ) {
			return array( 'chunk', $s, $e['message'] ?? '' );
		}
	}
	list( $s, $e ) = $call( 'POST', "/media/uploads/{$d['upload_id']}/complete", $meta );
	return array( 'complete', $s, $s >= 400 ? ( $e['message'] ?? '' ) : ( $e['width'] ?? '' ) . 'x' . ( $e['height'] ?? '' ) . ' ' . basename( $e['url'] ?? '' ) );
};

// Real files.
$img = imagecreatetruecolor( 1200, 800 );
imagefilledrectangle( $img, 0, 0, 1199, 799, imagecolorallocate( $img, 200, 120, 40 ) );
ob_start(); imagejpeg( $img, null, 85 ); $jpeg = ob_get_clean();
ob_start(); imagepng( $img ); $png = ob_get_clean();
ob_start(); imagewebp( $img ); $webp = ob_get_clean();

// A "pixel bomb": a tiny PNG that claims 20,000 x 20,000 pixels (1.6 GB once a browser decodes it).
$w = 20000; $h = 20000;
$z = deflate_init( ZLIB_ENCODING_DEFLATE, array( 'level' => 9 ) );
$idat = '';
$row = str_repeat( "\0", $w + 1 );
for ( $y = 0; $y < $h; $y++ ) { $idat .= deflate_add( $z, $row, ZLIB_NO_FLUSH ); }
$idat .= deflate_add( $z, '', ZLIB_FINISH );
$chunk = fn( $type, $data ) => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
$bomb = "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, 0, 0, 0, 0 ) ) . $chunk( 'IDAT', $idat ) . $chunk( 'IEND', '' );

$cases = array(
	'real JPEG photo'                      => array( 'image/jpeg', $jpeg, array( 'width' => 1200, 'height' => 800 ) ),
	'real PNG'                             => array( 'image/png', $png, array( 'width' => 1200, 'height' => 800 ) ),
	'real WebP'                            => array( 'image/webp', $webp, array( 'width' => 1200, 'height' => 800 ) ),
	'JPEG that lies about its size (1x1)'  => array( 'image/jpeg', $jpeg, array( 'width' => 1, 'height' => 1 ) ),
	'PHP script called a JPEG'             => array( 'image/jpeg', "<?php system(\$_GET['c']); ?>" ),
	'JPEG with PHP code appended'          => array( 'image/jpeg', $jpeg . "<?php system(\$_GET['c']); ?>", array( 'width' => 1200, 'height' => 800 ) ),
	'SVG with script, sent as PNG'         => array( 'image/png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ),
	'HTML page sent as PNG'                => array( 'image/png', '<html><script>alert(document.cookie)</script></html>' ),
	'PNG sent as a video'                  => array( 'video/mp4', $png ),
	'PDF from a member (admins only)'      => array( 'application/pdf', "%PDF-1.4\n%%EOF" ),
	'SVG type'                             => array( 'image/svg+xml', '<svg/>' ),
	'pixel bomb PNG (20000x20000)'         => array( 'image/png', $bomb, array( 'width' => 1200, 'height' => 800 ) ),
	'claims 1 GB'                          => array( 'image/jpeg', $jpeg, array(), 1024 * MB_IN_BYTES ),
	'claims 10 bytes, sends more'          => array( 'image/jpeg', $jpeg, array(), 10 ),
	'empty file'                           => array( 'image/jpeg', '' ),
);
foreach ( $cases as $label => $c ) {
	list( $step, $status, $msg ) = $upload( $c[0], $c[1], $c[2] ?? array(), $c[3] ?? null );
	printf( "%-38s %-9s %d  %s\n", $label, $step, $status, $msg );
}
printf( "pixel bomb file size: %d KB\n", strlen( $bomb ) / 1024 );

// Upload sessions: out of order, someone else's, resent chunk.
list( , $d ) = $call( 'POST', '/media/uploads', array( 'mime' => 'image/jpeg', 'size' => strlen( $jpeg ), 'name' => 'x' ) );
printf( "%-38s %d\n", 'chunk 3 before chunk 0', $call( 'POST', "/media/uploads/{$d['upload_id']}/chunks/3", 'abc', false )[0] );
wp_set_current_user( $member + 1 );
printf( "%-38s %d\n", 'another member appends to it', $call( 'POST', "/media/uploads/{$d['upload_id']}/chunks/0", $jpeg, false )[0] );
printf( "%-38s %d\n", 'another member completes it', $call( 'POST', "/media/uploads/{$d['upload_id']}/complete", array() )[0] );
wp_set_current_user( $member );
printf( "%-38s %d\n", 'path traversal in upload id', $call( 'POST', '/media/uploads/..%2F..%2Fwp-config/complete', array() )[0] );

// Rate limit: a member starting upload after upload.
$codes = array();
for ( $i = 0; $i < 70; $i++ ) {
	$codes[] = $call( 'POST', '/media/uploads', array( 'mime' => 'image/jpeg', 'size' => 100, 'name' => 'x' ) )[0];
}
$counts = array_count_values( $codes );
ksort( $counts );
echo '70 uploads started in a row: ', implode( ', ', array_map( fn( $k, $v ) => "{$v}x {$k}", array_keys( $counts ), $counts ) ), "\n";
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%mbcom\\_rl\\_%'" );
wp_cache_flush();

// Daily allowance: a member who already uploaded close to the limit today.
add_filter( 'membuild_community/daily_upload_bytes', fn() => 5 * MB_IN_BYTES );
list( $step, $status, $msg ) = $upload( 'image/jpeg', $jpeg, array() , 5 * MB_IN_BYTES );
printf( "%-38s %-9s %d  %s\n", 'over the daily allowance', $step, $status, $msg );
