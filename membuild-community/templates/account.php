<?php
/**
 * FluentCart's account page ("Manage billing", receipts, the card) in the community's look
 * (see class Fluent_Cart): a white bar with ‹ Back to the community, the logo and name, then
 * "Your account" and FluentCart's own app (it keeps doing the billing), restyled in account.css.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

defined( 'ABSPATH' ) || exit;

use MemBuild\Community\Color;
use MemBuild\Community\Portal;
use MemBuild\Community\Settings;

$mbcom_name = Settings::name();
$mbcom_logo = Settings::get( 'logo' )['url'] ?? '';
$mbcom_c    = Color::tokens( Settings::get( 'accent' ) );
$mbcom_user = wp_get_current_user();
$mbcom_back = Portal::url() . '/me/billing';
$mbcom_css  = 'assets/checkout/account.css';

add_filter( 'show_admin_bar', '__return_false' );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<?php wp_head(); ?>
	<style>
		:root { --mb-accent: <?php echo esc_html( $mbcom_c['accent'] ); ?>; --mb-ink: <?php echo esc_html( $mbcom_c['ink'] ); ?>; }
	</style>
	<link rel="stylesheet" href="<?php echo esc_url( MEMBUILD_COMMUNITY_URL . $mbcom_css . '?ver=' . MEMBUILD_COMMUNITY_VERSION . '.' . (int) @filemtime( MEMBUILD_COMMUNITY_DIR . $mbcom_css ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- this page prints its own head, after the theme's styles ?>">
</head>
<body class="mbac-body">
<header class="mbac-top">
	<a class="mbac-back" href="<?php echo esc_url( $mbcom_back ); ?>">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
		<span><?php esc_html_e( 'Back to the community', 'membuild-community' ); ?></span>
	</a>
	<a class="mbac-brand" href="<?php echo esc_url( Portal::url() ); ?>">
		<span class="mbac-logo"><?php if ( $mbcom_logo ) : ?><img src="<?php echo esc_url( $mbcom_logo ); ?>" alt=""><?php else : ?><?php echo esc_html( Settings::initials() ); ?><?php endif; ?></span>
		<strong><?php echo esc_html( $mbcom_name ); ?></strong>
	</a>
</header>
<main class="mbac">
	<h1><?php esc_html_e( 'Your account', 'membuild-community' ); ?></h1>
	<p class="mbac-who"><?php echo esc_html( $mbcom_user->display_name . ' · ' . $mbcom_user->user_email ); ?></p>
	<div class="mbac-app">
		<?php
		// FluentCart's own customer app, from the page's content (its block or shortcode).
		echo do_blocks( do_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FluentCart's own escaped markup.
		?>
	</div>
</main>
<?php wp_footer(); ?>
</body>
</html>
