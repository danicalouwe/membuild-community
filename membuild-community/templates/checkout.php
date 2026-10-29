<?php
/**
 * The checkout and "You're in" pages for a membership sold with FluentCart (see class Fluent_Cart).
 * Simple, like Stripe's: the community's colour, name and what you're joining on the left;
 * only Name, Email and Payment on the right (FluentCart's own form, restyled), or the welcome.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

defined( 'ABSPATH' ) || exit;

use MemBuild\Community\Color;
use MemBuild\Community\Fluent_Cart;
use MemBuild\Community\Portal;
use MemBuild\Community\Settings;

$mbcom_view    = Fluent_Cart::$view;
$mbcom_group   = $mbcom_view['group'];
$mbcom_product = Fluent_Cart::product( Fluent_Cart::link_of( $mbcom_group['id'] ) );
$mbcom_perks   = Fluent_Cart::perks( $mbcom_group['id'] );
$mbcom_name    = Settings::name();
$mbcom_logo    = Settings::get( 'logo' )['url'] ?? '';
$mbcom_c       = Color::tokens( Settings::get( 'accent' ) );
$mbcom_user    = wp_get_current_user();
$mbcom_back    = Portal::url();
$mbcom_period  = array(
	'month' => __( 'per month', 'membuild-community' ),
	'year'  => __( 'per year', 'membuild-community' ),
	'once'  => __( 'one-time', 'membuild-community' ),
)[ $mbcom_product['period'] ?? 'once' ];
$mbcom_paid    = 'paid' === $mbcom_view['step'];
$mbcom_first   = $mbcom_paid ? Portal::url() . '/classroom/' . ( $mbcom_perks[0]['id'] ?? '' ) : '';
$mbcom_buyer   = $mbcom_paid ? ( $mbcom_view['order']->customer ?? null ) : null;
// The buyer's name and email only for the buyer: a receipt link can be forwarded.
$mbcom_mine    = $mbcom_paid && $mbcom_user->exists() && (int) ( $mbcom_buyer->user_id ?? 0 ) === (int) $mbcom_user->ID;
$mbcom_hello   = $mbcom_mine ? ( $mbcom_buyer->first_name ?? '' ) ?: ( $mbcom_user->first_name ?: $mbcom_user->display_name ) : '';

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
	<link rel="stylesheet" href="<?php echo esc_url( MEMBUILD_COMMUNITY_URL . 'assets/checkout/checkout.css?ver=' . MEMBUILD_COMMUNITY_VERSION . '.' . (int) @filemtime( MEMBUILD_COMMUNITY_DIR . 'assets/checkout/checkout.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- this page prints its own head, after the theme's styles ?>">
</head>
<body class="mbck-body<?php echo Fluent_Cart::asks_address() ? ' mbck-address' : ''; ?>">
<main class="mbck">
	<aside class="mbck-side">
		<span class="mbck-shape one" aria-hidden="true"></span><span class="mbck-shape two" aria-hidden="true"></span>
		<a class="mbck-brand" href="<?php echo esc_url( $mbcom_back ); ?>">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
			<span class="mbck-logo"><?php if ( $mbcom_logo ) : ?><img src="<?php echo esc_url( $mbcom_logo ); ?>" alt=""><?php else : ?><?php echo esc_html( Settings::initials() ); ?><?php endif; ?></span>
			<span><?php echo esc_html( $mbcom_name ); ?></span>
		</a>
		<div class="mbck-what">
			<p class="mbck-join">
				<?php
				/* translators: %s: membership name */
				echo esc_html( sprintf( __( 'Join %s', 'membuild-community' ), $mbcom_group['name'] ) );
				?>
			</p>
			<?php if ( $mbcom_product ) : ?>
				<p class="mbck-price"><strong><?php echo esc_html( $mbcom_product['amount'] ); ?></strong> <span><?php echo esc_html( $mbcom_period ); ?></span></p>
			<?php endif; ?>
			<ul class="mbck-perks">
				<?php foreach ( $mbcom_perks as $mbcom_perk ) : ?>
					<li><?php echo esc_html( $mbcom_perk['text'] ); ?></li>
				<?php endforeach; ?>
				<?php if ( 'once' !== ( $mbcom_product['period'] ?? 'once' ) ) : ?>
					<li><?php esc_html_e( 'Cancel any time', 'membuild-community' ); ?></li>
				<?php endif; ?>
			</ul>
		</div>
		<?php if ( $mbcom_product && ! $mbcom_paid ) : ?>
			<p class="mbck-due"><span><?php esc_html_e( 'Due today', 'membuild-community' ); ?></span><strong><?php echo esc_html( $mbcom_product['amount'] ); ?></strong></p>
		<?php endif; ?>
	</aside>

	<section class="mbck-main">
		<?php if ( $mbcom_paid ) : ?>
			<div class="mbck-done">
				<span class="mbck-tick" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg></span>
				<h1>
					<?php
					/* translators: %s: the buyer's first name */
					echo esc_html( $mbcom_hello ? sprintf( __( 'You’re in, %s', 'membuild-community' ), $mbcom_hello ) : __( 'You’re in', 'membuild-community' ) );
					?>
				</h1>
				<p>
					<?php
					$mbcom_email = $mbcom_mine ? ( $mbcom_buyer->email ?? $mbcom_user->user_email ) : __( 'the buyer’s email', 'membuild-community' );
					echo esc_html(
						$mbcom_perks
							/* translators: 1: membership name, 2: course name, 3: email address */
							? sprintf( __( '%1$s is open for you now, with %2$s. We sent the receipt to %3$s.', 'membuild-community' ), $mbcom_group['name'], preg_replace( '/: .*$/', '', $mbcom_perks[0]['text'] ), $mbcom_email )
							/* translators: 1: membership name, 2: email address */
							: sprintf( __( '%1$s is open for you now. We sent the receipt to %2$s.', 'membuild-community' ), $mbcom_group['name'], $mbcom_email )
					);
					?>
				</p>
				<?php if ( $mbcom_perks ) : ?>
					<a class="mbck-go" href="<?php echo esc_url( $mbcom_first ); ?>">
						<?php
						/* translators: %s: course name */
						echo esc_html( sprintf( __( 'Start %s', 'membuild-community' ), preg_replace( '/: .*$/', '', $mbcom_perks[0]['text'] ) ) );
						?>
					</a>
				<?php endif; ?>
				<a class="mbck-back" href="<?php echo esc_url( $mbcom_back ); ?>"><?php esc_html_e( 'Back to the community', 'membuild-community' ); ?></a>
			</div>
		<?php else : ?>
			<div class="mbck-form">
				<h1><?php esc_html_e( 'Your details', 'membuild-community' ); ?></h1>
				<?php if ( $mbcom_user->exists() ) : ?>
					<p class="mbck-who">
						<?php
						/* translators: %s: the member's name */
						echo esc_html( sprintf( __( 'You’re signed in as %s.', 'membuild-community' ), $mbcom_user->display_name ) );
						?>
					</p>
				<?php endif; ?>
				<?php
				// FluentCart's own checkout (it keeps doing the payment); restyled in checkout.css.
				echo do_shortcode( '[fluent_cart_checkout]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FluentCart's own escaped markup.
				?>
				<p class="mbck-note">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 11h12v10H6zM8.5 11V8a3.5 3.5 0 0 1 7 0v3"/></svg>
					<?php echo esc_html( 'once' === ( $mbcom_product['period'] ?? 'once' ) ? __( 'Your payment is handled securely.', 'membuild-community' ) : __( 'You can cancel any time from your account.', 'membuild-community' ) ); ?>
				</p>
			</div>
		<?php endif; ?>
	</section>
</main>
<?php wp_footer(); ?>
<script>
	// FluentCart's script puts "Place order" back on its button; keep our words ("Join for $49.00 a month").
	// It also redraws parts of the form after a change, so the coupon link gets our words back each time.
	( function () {
		var words = <?php echo wp_json_encode( __( 'Have a discount code?', 'membuild-community' ) ); ?>;
		var fix = function () {
			var btn = document.getElementById( 'fluent_cart_order_btn' );
			if ( btn && btn.dataset.value && ! btn.classList.contains( 'loading' ) && ! btn.querySelector( '*' ) && btn.textContent.trim() !== btn.dataset.value ) btn.textContent = btn.dataset.value;
			document.querySelectorAll( '.mbck .fct_coupon_toggle a' ).forEach( function ( a ) { if ( a.textContent.trim() !== words ) a.textContent = words; } );
		};
		var form = document.querySelector( '.mbck-form' );
		if ( form ) new MutationObserver( fix ).observe( form, { childList: true, characterData: true, subtree: true } );
		fix();
	} )();
</script>
</body>
</html>
