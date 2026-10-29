<?php
/**
 * App shell for /community. Variables: $built, $config, $icons, $manifest, $accent.
 * The app's files are registered in Portal::register_assets() and printed here.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex">
	<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
	<meta name="theme-color" content="#111214" media="(prefers-color-scheme: dark)">
	<title><?php echo esc_html( $config['siteName'] ); ?></title>
	<link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $config['siteName'] ); ?>">
	<?php if ( ! empty( $icons[180] ) ) : ?>
		<link rel="apple-touch-icon" href="<?php echo esc_url( $icons[180] ); ?>">
		<link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( $icons[32] ); ?>">
		<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( $icons[192] ); ?>">
	<?php endif; ?>
	<?php wp_print_styles( array( 'mbcom-app', 'mbcom-app-1' ) ); ?>
	<script>window.MBCommunity = window.MBCommunity || { queue: [], ready: function ( fn ) { this.queue.push( fn ); } };</script>
	<?php
	/**
	 * The community page's head, after the app's styles. A Community theme prints its
	 * stylesheet here (so its token values win) and may print its script here or in
	 * membuild_community/app_footer; see app/src/lib/slots.js for MBCommunity.ready().
	 */
	do_action( 'membuild_community/app_head' );
	?>
</head>
<body>
	<div id="mbcom-app"></div>
	<?php if ( ! $built ) : ?>
		<p style="font:16px system-ui;padding:24px"><?php esc_html_e( 'The community’s files are missing. Please reinstall the plugin.', 'membuild-community' ); ?></p>
	<?php endif; ?>
	<?php wp_print_scripts( array( 'mbcom-app' ) ); ?>
	<?php
	/**
	 * The end of the community's page. It has no theme and no wp_footer(), so
	 * this is where an add-on prints its own markup, styles or scripts.
	 */
	do_action( 'membuild_community/app_footer' );
	?>
</body>
</html>
