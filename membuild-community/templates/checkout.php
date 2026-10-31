<?php
/**
 * The checkout and thank-you pages for a membership sold with FluentCart (see class Fluent_Cart).
 * Checkout: a slim bar (logo, name, ← Back to the plans), the plan on a dark card on the left,
 * and on the right "Join VIP." over FluentCart's own form, restyled (labels above the boxes;
 * a member sees "Paying as …" instead of Name and Email). Thank you: just the message, centred.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

defined( 'ABSPATH' ) || exit;

use MemBuild\Community\Color;
use MemBuild\Community\Fluent_Cart;
use MemBuild\Community\Login;
use MemBuild\Community\Portal;
use MemBuild\Community\Settings;

$mbcom_view    = Fluent_Cart::$view;
$mbcom_step    = $mbcom_view['step'];
$mbcom_group   = $mbcom_view['group'];
$mbcom_link    = Fluent_Cart::link_of( $mbcom_group['id'] );
$mbcom_product = Fluent_Cart::product( $mbcom_link );
$mbcom_perks   = Fluent_Cart::perks( $mbcom_group['id'] );
$mbcom_name    = Settings::name();
$mbcom_logo    = Settings::get( 'logo' )['url'] ?? '';
$mbcom_c       = Color::tokens( Settings::get( 'accent' ) );
$mbcom_user    = wp_get_current_user();
$mbcom_plans   = Fluent_Cart::plans_url();
$mbcom_period  = array(
	'month' => __( '/ month', 'membuild-community' ),
	'year'  => __( '/ year', 'membuild-community' ),
	'once'  => __( 'one-time', 'membuild-community' ),
)[ $mbcom_product['period'] ?? 'once' ];
$mbcom_mark    = '<span class="mbck-logo">' . ( $mbcom_logo ? '<img src="' . esc_url( $mbcom_logo ) . '" alt="">' : esc_html( Settings::initials() ) ) . '</span>';
$mbcom_arrow   = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8h9M8.5 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

// Thank you: the buyer's first name, for the buyer (a receipt link can be forwarded, so only
// when it's their account, or the order is minutes old), and whether the account is brand new.
$mbcom_order   = $mbcom_view['order'] ?? null;
$mbcom_buyer   = $mbcom_order ? ( $mbcom_order->customer ?? null ) : null;
$mbcom_buyer_u = (int) ( $mbcom_buyer->user_id ?? 0 );
$mbcom_fresh   = $mbcom_order && strtotime( (string) $mbcom_order->created_at . ' UTC' ) > time() - HOUR_IN_SECONDS;
$mbcom_mine    = $mbcom_buyer && ( ( $mbcom_user->exists() && $mbcom_buyer_u === (int) $mbcom_user->ID ) || $mbcom_fresh );
$mbcom_hello   = $mbcom_mine ? trim( (string) ( $mbcom_buyer->first_name ?? '' ) ) : '';
$mbcom_new     = $mbcom_buyer_u && ! ( $mbcom_user->exists() && $mbcom_buyer_u === (int) $mbcom_user->ID ) && $mbcom_fresh
	&& strtotime( (string) get_userdata( $mbcom_buyer_u )->user_registered . ' UTC' ) >= strtotime( (string) $mbcom_order->created_at . ' UTC' ) - 120;

// Checkout, signed in: who's paying, instead of FluentCart's Name and Email boxes.
$mbcom_avatar = $mbcom_user->exists() ? ( (string) get_user_meta( $mbcom_user->ID, 'mbcom_avatar_url', true ) ?: get_avatar_url( $mbcom_user->ID, array( 'size' => 80 ) ) ) : '';
$mbcom_here   = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
$mbcom_here   = wp_validate_redirect( $mbcom_here, Portal::url() );

add_filter( 'show_admin_bar', '__return_false' );
?><!doctype html>
<html <?php language_attributes(); ?> class="mb-<?php echo esc_attr( \MemBuild\Community\Login::scheme() ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<?php if ( 'pending' === $mbcom_step ) : ?>
		<meta http-equiv="refresh" content="6">
	<?php endif; ?>
	<?php wp_head(); ?>
	<style>
		:root { --mb-accent: <?php echo esc_html( $mbcom_c['accent'] ); ?>; --mb-ink: <?php echo esc_html( $mbcom_c['ink'] ); ?>; --mb-accent-dark: <?php echo esc_html( $mbcom_c['dark'] ); ?>; --mb-ink-dark: <?php echo esc_html( $mbcom_c['dark_ink'] ); ?>; }
	</style>
	<link rel="stylesheet" href="<?php echo esc_url( MEMBUILD_COMMUNITY_URL . 'assets/checkout/checkout.css?ver=' . MEMBUILD_COMMUNITY_VERSION . '.' . (int) @filemtime( MEMBUILD_COMMUNITY_DIR . 'assets/checkout/checkout.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- this page prints its own head, after the theme's styles ?>">
</head>
<body class="mbck-body mbck-<?php echo esc_attr( $mbcom_step ); ?><?php echo Fluent_Cart::asks_address() ? ' mbck-address' : ''; ?><?php echo $mbcom_user->exists() ? ' mbck-member' : ''; ?>">

<?php if ( 'checkout' !== $mbcom_step ) : ?>
<main class="mbck-thanks">
	<div class="mbck-msg">
		<?php if ( 'paid' === $mbcom_step ) : ?>
			<span class="mbck-confetti" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
			<h1><?php esc_html_e( 'You’re', 'membuild-community' ); ?> <span class="mbck-hl"><?php esc_html_e( 'in.', 'membuild-community' ); ?></span> <span aria-hidden="true">🎉</span></h1>
			<p>
				<?php
				echo esc_html(
					$mbcom_hello
						/* translators: 1: membership name, 2: the buyer's first name */
						? sprintf( __( 'Welcome to %1$s, %2$s.', 'membuild-community' ), $mbcom_group['name'], $mbcom_hello )
						/* translators: %s: membership name */
						: sprintf( __( 'Welcome to %s.', 'membuild-community' ), $mbcom_group['name'] )
				);
				if ( $mbcom_new ) {
					echo ' ' . esc_html__( 'We emailed you a link to choose your password.', 'membuild-community' );
				}
				?>
			</p>
			<a class="mbck-btn" href="<?php echo esc_url( $mbcom_user->exists() ? Portal::url() : Login::url( 'login' ) ); ?>"><?php esc_html_e( 'Open the community', 'membuild-community' ); ?> <?php echo $mbcom_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed icon ?></a>
		<?php else : ?>
			<h1><?php esc_html_e( 'Almost', 'membuild-community' ); ?> <span class="mbck-hl"><?php esc_html_e( 'there.', 'membuild-community' ); ?></span></h1>
			<p role="status">
				<?php
				/* translators: %s: membership name */
				echo esc_html( sprintf( __( 'Your bank is confirming the payment. %s opens by itself in a minute.', 'membuild-community' ), $mbcom_group['name'] ) );
				?>
			</p>
		<?php endif; ?>
	</div>
</main>

<?php else : ?>
<header class="mbck-bar">
	<?php echo $mbcom_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
	<b><?php echo esc_html( $mbcom_name ); ?></b>
	<a class="mbck-plans" href="<?php echo esc_url( $mbcom_plans ); ?>"><span aria-hidden="true">←</span> <span class="wide"><?php esc_html_e( 'Back to the plans', 'membuild-community' ); ?></span><span class="narrow"><?php esc_html_e( 'Plans', 'membuild-community' ); ?></span></a>
</header>
<main class="mbck">
	<aside class="mbck-side">
		<div class="mbck-sum">
			<span class="mbck-pill"><?php echo esc_html( $mbcom_group['name'] ); ?></span>
			<?php if ( $mbcom_product ) : ?>
				<p class="mbck-price"><strong><?php echo esc_html( $mbcom_product['price_text'] ); ?></strong> <span><?php echo esc_html( $mbcom_period ); ?></span></p>
			<?php endif; ?>
			<?php if ( $mbcom_perks ) : ?>
				<ul class="mbck-perks" id="mbck-perks">
					<?php foreach ( $mbcom_perks as $mbcom_perk ) : ?>
						<li><?php echo esc_html( $mbcom_perk['text'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $mbcom_product ) : ?>
				<p class="mbck-due"><span><?php esc_html_e( 'Due today', 'membuild-community' ); ?></span><strong><?php echo esc_html( $mbcom_product['amount'] ); ?></strong></p>
			<?php endif; ?>
		</div>
	</aside>

	<section class="mbck-main">
		<div class="mbck-form">
			<?php if ( $mbcom_product ) : ?>
				<details class="mbck-band">
					<summary>
						<span><small><?php echo esc_html( $mbcom_group['name'] ); ?></small><br><b><?php echo esc_html( $mbcom_product['price_text'] ); ?></b> <small><?php echo esc_html( $mbcom_period ); ?></small></span>
						<?php if ( $mbcom_perks ) : ?><u><?php esc_html_e( 'What’s included', 'membuild-community' ); ?></u><?php endif; ?>
					</summary>
					<?php if ( $mbcom_perks ) : ?>
						<ul class="mbck-perks">
							<?php foreach ( $mbcom_perks as $mbcom_perk ) : ?>
								<li><?php echo esc_html( $mbcom_perk['text'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</details>
			<?php endif; ?>
			<h1><?php esc_html_e( 'Join', 'membuild-community' ); ?><br><span class="mbck-hl"><?php echo esc_html( $mbcom_group['name'] . '.' ); ?></span></h1>
			<?php $mbcom_blurb = Fluent_Cart::blurb( $mbcom_link ); ?>
			<?php if ( $mbcom_blurb ) : ?>
				<p class="mbck-lead"><?php echo esc_html( $mbcom_blurb ); ?></p>
			<?php endif; ?>
			<?php if ( $mbcom_user->exists() ) : ?>
				<p class="mbck-me">
					<?php if ( $mbcom_avatar ) : ?><img src="<?php echo esc_url( $mbcom_avatar ); ?>" alt=""><?php endif; ?>
					<span>
						<b>
						<?php
						/* translators: %s: the member's name */
						echo esc_html( sprintf( __( 'Paying as %s', 'membuild-community' ), $mbcom_user->display_name ) );
						?>
						</b><br><span><?php echo esc_html( $mbcom_user->user_email ); ?></span>
					</span>
					<a href="<?php echo esc_url( wp_logout_url( Login::url( 'login', array( 'redirect_to' => $mbcom_here ) ) ) ); ?>"><?php esc_html_e( 'Not you?', 'membuild-community' ); ?></a>
				</p>
			<?php else : ?>
				<p class="mbck-already"><span><?php esc_html_e( 'Already a member?', 'membuild-community' ); ?></span> <a href="<?php echo esc_url( Login::url( 'login', array( 'redirect_to' => $mbcom_here ) ) ); ?>"><?php esc_html_e( 'Log in first', 'membuild-community' ); ?></a></p>
			<?php endif; ?>
			<?php
			// FluentCart's own checkout (it keeps doing the payment); restyled in checkout.css.
			echo do_shortcode( '[fluent_cart_checkout]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FluentCart's own escaped markup.
			?>
			<?php if ( ! $mbcom_user->exists() ) : ?>
				<p class="mbck-note"><?php esc_html_e( 'New here? We make your account with this email and send you a link to choose a password.', 'membuild-community' ); ?></p>
			<?php endif; ?>
		</div>
	</section>
</main>
<?php endif; ?>
<?php wp_footer(); ?>
<?php if ( 'checkout' === $mbcom_step ) : ?>
<script>
	// FluentCart's form, in our words: labels above the boxes (it only has words inside them),
	// "Join for $49.00 a month" on its button, "Have a discount code?". A member's own Name and
	// Email (already filled in) hide behind "Paying as …". FluentCart redraws parts of the form,
	// so this runs again after each change.
	( function () {
		var words = <?php echo wp_json_encode( __( 'Have a discount code?', 'membuild-community' ) ); ?>;
		var member = document.body.classList.contains( 'mbck-member' );
		var fix = function () {
			var btn = document.getElementById( 'fluent_cart_order_btn' );
			if ( btn && btn.dataset.value && ! btn.classList.contains( 'loading' ) && ! btn.querySelector( '*' ) && btn.textContent.trim() !== btn.dataset.value ) btn.textContent = btn.dataset.value;
			document.querySelectorAll( '.mbck .fct_coupon_toggle a' ).forEach( function ( a ) { if ( a.textContent.trim() !== words ) a.textContent = words; } );
			document.querySelectorAll( '.mbck .fct_input_wrapper[data-fct-label]' ).forEach( function ( w ) {
				var input = w.querySelector( 'input, select' );
				if ( ! input || ! input.id ) return;
				if ( member && /^billing_(full_name|email)$/.test( input.id ) && input.value.trim() ) { w.classList.add( 'mbck-known' ); return; }
				if ( ! w.querySelector( '.mbck-label' ) ) {
					var label = document.createElement( 'label' );
					label.className = 'mbck-label';
					label.htmlFor = input.id;
					label.textContent = w.dataset.fctLabel.replace( /\s*\*\s*$/, '' );
					w.insertBefore( label, w.firstChild );
					input.removeAttribute( 'aria-label' );
				}
				if ( input.placeholder ) input.placeholder = '';
			} );
			// It calls itself the page's main part, inside ours: one is enough.
			document.querySelectorAll( '.mbck [role="main"]' ).forEach( function ( m ) { m.removeAttribute( 'role' ); } );
			// Its section titles are small h4s under our h1: read as the next level down.
			document.querySelectorAll( '.mbck h4.fct_form_section_header_label:not([aria-level])' ).forEach( function ( h ) { h.setAttribute( 'role', 'heading' ); h.setAttribute( 'aria-level', '2' ); } );
		};
		var form = document.querySelector( '.mbck-form' );
		if ( form ) new MutationObserver( fix ).observe( form, { childList: true, characterData: true, subtree: true } );
		fix();
	} )();
</script>
<?php endif; ?>
</body>
</html>
