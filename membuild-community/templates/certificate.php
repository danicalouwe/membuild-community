<?php
/**
 * The public check page for a certificate (see Certificates::maybe_check_page): anyone with the
 * link sees that it's real (name, course, the day it was finished, who gave it), or "Not found or
 * removed" with nothing else. No community menu and no login: only the logo and the name, so it
 * looks trustworthy to an employer. Light, unless this browser's member picked dark.
 * Variables: $cert (null when not found or removed), $limited (too many wrong codes from this
 * address), $look, $scheme, $code, $tokens.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

defined( 'ABSPATH' ) || exit;

// finished_on is already the site's calendar day: read and print it in UTC, so no time zone moves it (as in the PDF).
$mbcom_day = $cert ? wp_date( 'F j, Y', strtotime( $cert['finished_on'] . ' 12:00:00 UTC' ), new DateTimeZone( 'UTC' ) ) : '';
$mbcom_now = wp_date( 'M j, Y' );
?><!doctype html>
<html <?php language_attributes(); ?> class="mb-<?php echo esc_attr( $scheme ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html( $cert ? __( 'This certificate is real', 'membuild-community' ) : ( $limited ? __( 'Too many tries', 'membuild-community' ) : __( 'Not found or removed', 'membuild-community' ) ) ); ?> · <?php echo esc_html( $look['community'] ); ?></title>
	<style>
		:root { --bg: #f4f5f7; --surface: #ffffff; --text: #16181d; --text-2: #5d6370; --border: #e3e5e8; --ok-bg: #e3f5ea; --ok-text: #1f7a49; --soft: #f0f2f5; --accent: <?php echo esc_html( $tokens['accent'] ); ?>; --accent-ink: <?php echo esc_html( $tokens['ink'] ); ?>; color-scheme: light; }
		:root.mb-dark { --bg: #111214; --surface: #1b1c20; --text: #eceef1; --text-2: #a2a7b0; --border: #2c2e33; --ok-bg: #173826; --ok-text: #7fd4a2; --soft: #24262b; --accent: <?php echo esc_html( $tokens['dark'] ); ?>; --accent-ink: <?php echo esc_html( $tokens['dark_ink'] ); ?>; color-scheme: dark; }
		@media (prefers-color-scheme: dark) { :root.mb-auto { --bg: #111214; --surface: #1b1c20; --text: #eceef1; --text-2: #a2a7b0; --border: #2c2e33; --ok-bg: #173826; --ok-text: #7fd4a2; --soft: #24262b; --accent: <?php echo esc_html( $tokens['dark'] ); ?>; --accent-ink: <?php echo esc_html( $tokens['dark_ink'] ); ?>; color-scheme: dark; } }
		* { box-sizing: border-box; }
		body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text); font: 16px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
		main { width: min(560px, 100%); margin: 0 auto; padding: 48px 16px; }
		.brand { display: flex; align-items: center; gap: 12px; margin: 0 0 24px; font-size: 17px; font-weight: 700; }
		.logo { display: grid; place-items: center; width: 40px; height: 40px; overflow: hidden; border-radius: 10px; background: var(--accent); color: var(--accent-ink); font-size: 14px; font-weight: 800; }
		.logo img { width: 100%; height: 100%; object-fit: cover; }
		.card { padding: 28px; border-radius: 16px; background: var(--surface); box-shadow: 0 1px 2px rgb(16 24 40 / 6%), 0 1px 3px rgb(16 24 40 / 8%); }
		.mark { display: grid; place-items: center; width: 52px; height: 52px; margin-bottom: 16px; border-radius: 50%; background: var(--ok-bg); color: var(--ok-text); }
		.mark.none { background: var(--soft); color: var(--text-2); }
		h1 { margin: 0 0 6px; font-size: 26px; line-height: 1.2; }
		.lead { margin: 0; color: var(--text-2); }
		dl { display: grid; gap: 14px; margin: 24px 0 0; padding: 20px 0 0; border-top: 1px solid var(--border); }
		dl div { display: grid; gap: 2px; }
		dt { color: var(--text-2); font-size: 14px; font-weight: 600; }
		dd { margin: 0; font-size: 18px; font-weight: 700; }
		.foot { margin: 20px 0 0; color: var(--text-2); font-size: 14px; }
		@media (max-width: 480px) { main { padding: 24px 16px; } .card { padding: 22px; } h1 { font-size: 24px; } }
	</style>
</head>
<body>
<main>
	<p class="brand"><span class="logo" aria-hidden="true"><?php if ( $look['logo'] ) : ?><img src="<?php echo esc_url( $look['logo'] ); ?>" alt=""><?php else : ?><?php echo esc_html( $look['initials'] ); ?><?php endif; ?></span><?php echo esc_html( $look['community'] ); ?></p>
	<?php if ( $cert ) : ?>
		<section class="card" aria-labelledby="mbcom-check-title">
			<span class="mark" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
			<h1 id="mbcom-check-title"><?php esc_html_e( 'This certificate is real', 'membuild-community' ); ?></h1>
			<?php /* translators: %s: community name */ ?>
			<p class="lead"><?php echo esc_html( sprintf( __( '%s gave it when the course was finished.', 'membuild-community' ), $look['community'] ) ); ?></p>
			<dl>
				<div><dt><?php esc_html_e( 'Name', 'membuild-community' ); ?></dt><dd><?php echo esc_html( $cert['name'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Course', 'membuild-community' ); ?></dt><dd><?php echo esc_html( $cert['course'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Finished on', 'membuild-community' ); ?></dt><dd><?php echo esc_html( $mbcom_day ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Given by', 'membuild-community' ); ?></dt><dd><?php echo esc_html( $look['community'] ); ?></dd></div>
			</dl>
			<?php /* translators: 1: the certificate's code, 2: today's date */ ?>
			<p class="foot"><?php echo esc_html( sprintf( __( 'Certificate %1$s · checked %2$s', 'membuild-community' ), $code, $mbcom_now ) ); ?></p>
		</section>
	<?php elseif ( $limited ) : ?>
		<section class="card" aria-labelledby="mbcom-check-title">
			<span class="mark none" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
			<h1 id="mbcom-check-title"><?php esc_html_e( 'Too many tries', 'membuild-community' ); ?></h1>
			<p class="lead"><?php esc_html_e( 'Lots of links that don’t match a certificate came from here. Try again in an hour, or ask the person for their link again.', 'membuild-community' ); ?></p>
		</section>
	<?php else : ?>
		<section class="card" aria-labelledby="mbcom-check-title">
			<span class="mark none" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></span>
			<h1 id="mbcom-check-title"><?php esc_html_e( 'Not found or removed', 'membuild-community' ); ?></h1>
			<p class="lead"><?php esc_html_e( 'This link doesn’t match a certificate. It may have been copied wrong, or the community removed it.', 'membuild-community' ); ?></p>
		</section>
	<?php endif; ?>
</main>
</body>
</html>
