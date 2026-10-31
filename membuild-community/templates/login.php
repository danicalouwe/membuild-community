<?php
/**
 * The community's log in, join, forgot-password, new-password and login-link pages (see class Login).
 * Led by the host: their photo with their one line on a card beside the form, and the next live
 * call (Log in) or members' faces (Join) once they're real. The form: a big two-line heading
 * (the second line highlighted), labels above 52px boxes, one main button. Phones: the form first.
 * Variables: $view, $data, $name, $host, $magic, $tagline, $headline, $picture, $join_label, $logo, $initials, $signup, $icons.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

use MemBuild\Community\Login;

defined( 'ABSPATH' ) || exit;

$mbcom_titles = array(
	/* translators: %s: community name */
	'login'    => sprintf( __( 'Log in to %s', 'membuild-community' ), $name ),
	'linksent' => __( 'Check your email', 'membuild-community' ),
	'linkgo'   => sprintf( __( 'Log in to %s', 'membuild-community' ), $name ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- same string as above
	/* translators: %s: community name */
	'join'     => sprintf( __( 'Join %s', 'membuild-community' ), $name ),
	'forgot'   => __( 'Forgot your password?', 'membuild-community' ),
	'reset'    => __( 'Choose a new password', 'membuild-community' ),
	'expired'  => __( 'This link has expired', 'membuild-community' ),
);
// The big heading: two lines, the second highlighted in the community's color.
$mbcom_h1 = function ( $first, $second, $class = '' ) {
	return '<h1 class="big' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '">' . esc_html( $first ) . '<br><span class="hl">' . esc_html( $second ) . '</span></h1>';
};
// The "Show" word on a password field; its words come from data attributes (see login.js).
$mbcom_show  = '<button type="button" class="show" aria-pressed="false" data-show="' . esc_attr__( 'Show', 'membuild-community' ) . '" data-hide="' . esc_attr__( 'Hide', 'membuild-community' ) . '" aria-label="' . esc_attr__( 'Show password', 'membuild-community' ) . '">' . esc_html__( 'Show', 'membuild-community' ) . '</button>';
$mbcom_arrow = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8h9M8.5 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$mbcom_ok    = '<span class="ok-mark" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>';
$mbcom_warn  = '<svg class="warn-mark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.5"/></svg>';
/* translators: %d: minimum number of characters */
$mbcom_min   = sprintf( __( '%d or more characters', 'membuild-community' ), Login::MIN_PASSWORD );
$mbcom_login = '<a href="' . esc_url( Login::url( 'login' ) ) . '">' . esc_html__( 'Log in', 'membuild-community' ) . '</a>';
// The small logo and name: on phones, where the host's side doesn't show.
$mbcom_mark  = '<span class="logo">' . ( $logo ? '<img src="' . esc_url( $logo ) . '" alt="">' : esc_html( $initials ) ) . '</span>';
$mbcom_brand = '<p class="brand-row">' . $mbcom_mark . '<b>' . esc_html( $name ) . '</b></p>';
$mbcom_live  = function ( $live ) {
	return '<div class="live"><span class="cal" aria-hidden="true"><small>' . esc_html( $live['day'] ) . '</small><b>' . esc_html( $live['date'] ) . '</b></span><span><b>' . esc_html( $live['title'] ) . '</b><br><span>' . esc_html( $live['when'] ) . '</span></span></div>';
};
$mbcom_icons = array(
	'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
	'cap'  => '<path d="M22 9 12 4 2 9l10 5 10-5z"/><path d="M6 11v5c3 2 9 2 12 0v-5"/>',
	'cal'  => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/>',
);
$mbcom_gets = function ( $gets, $class ) use ( $mbcom_icons ) {
	$out = '<ul class="gets ' . esc_attr( $class ) . '">';
	foreach ( $gets as $icon => list( $title, $what ) ) {
		$out .= '<li><span class="gi" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $mbcom_icons[ $icon ] . '</svg></span><span><b>' . esc_html( $title ) . '</b> · ' . esc_html( $what ) . '</span></li>';
	}
	return $out . '</ul>';
};
$mbcom_alert = function ( $html ) use ( $mbcom_warn ) {
	return '<p class="msg error" role="alert">' . $mbcom_warn . '<span>' . $html . '</span></p>';
};
$mbcom_okbox = function ( $html, $role = 'status' ) use ( $mbcom_ok ) {
	return '<p class="msg ok" role="' . esc_attr( $role ) . '">' . $mbcom_ok . '<span>' . $html . '</span></p>';
};
$mbcom_kses = array( 'strong' => array(), 'b' => array(), 'a' => array( 'href' => true ), 'br' => array() );
?><!doctype html>
<html <?php language_attributes(); ?> class="mb-<?php echo esc_attr( \MemBuild\Community\Login::scheme() ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html( $mbcom_titles[ $view ] ); ?></title>
	<?php if ( ! empty( $icons[192] ) ) : ?>
		<link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( $icons[32] ); ?>">
		<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( $icons[192] ); ?>">
	<?php endif; ?>
	<?php wp_print_styles( array( 'mbcom-login' ) ); ?>
	<?php
	/**
	 * The Join and Log in pages' head, after their styles: where a Community theme prints its
	 * stylesheet for these pages.
	 */
	do_action( 'membuild_community/login_head', $view );
	?>
</head>
<body class="view-<?php echo esc_attr( $view ); ?>">
<main class="page">
	<?php
	/**
	 * Theme contract slot "join.picture": the side beside the form on Join and Log in. A Community
	 * theme returns its own HTML (passed through wp_kses_post) to replace the host's card;
	 * returning '' keeps it. $args: view ('login', 'join', …), picture (the host's photo or ''),
	 * headline, tagline (the host's line), logo, initials, name, host (name, role, line, photo).
	 */
	$mbcom_side = (string) apply_filters( 'membuild_community/join_picture', '', array( 'view' => $view, 'picture' => (string) $picture, 'headline' => (string) $headline, 'tagline' => (string) $tagline, 'logo' => (string) $logo, 'initials' => (string) $initials, 'name' => (string) $name, 'host' => $host ) );
	?>
	<aside class="brand-side" aria-label="<?php echo esc_attr( $name ); ?>">
	<?php if ( '' !== trim( $mbcom_side ) ) : ?>
		<?php echo wp_kses_post( $mbcom_side ); ?>
	<?php else : ?>
		<div class="host-panel">
			<?php if ( 'login' === $view && ! empty( $data['live'] ) ) : ?>
				<div class="live-top"><?php echo $mbcom_live( $data['live'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></div>
			<?php endif; ?>
			<figure class="host">
				<?php if ( $host['photo'] ) : ?>
					<img class="portrait" src="<?php echo esc_url( $host['photo'] ); ?>" alt="">
				<?php else : ?>
					<span class="portrait mark"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php else : ?><?php echo esc_html( $initials ); ?><?php endif; ?></span>
				<?php endif; ?>
				<figcaption class="card">
					<?php if ( $host['line'] ) : ?>
						<svg class="qm" width="34" height="26" viewBox="0 0 44 34" aria-hidden="true"><path d="M0 34V20C0 8 7 1 18 0v8c-5 1-8 5-8 11h8v15zm26 0V20c0-12 7-19 18-20v8c-5 1-8 5-8 11h8v15z"/></svg>
						<p class="quote"><?php echo esc_html( $host['line'] ); ?></p>
					<?php endif; ?>
					<?php if ( $host['name'] ) : ?>
						<span class="by"><span><b><?php echo esc_html( $host['name'] ); ?></b><br><small><?php echo esc_html( $host['role'] ); ?></small></span><span class="pill"><?php esc_html_e( 'Host', 'membuild-community' ); ?></span></span>
					<?php endif; ?>
					<?php if ( 'join' === $view && ! empty( $data['faces'] ) ) : ?>
						<span class="faces">
							<span class="stack" aria-hidden="true"><?php foreach ( $data['faces']['photos'] as $mbcom_face ) : ?><img src="<?php echo esc_url( $mbcom_face ); ?>" alt=""><?php endforeach; ?></span>
							<span>
							<?php
							/* translators: %s: number of members */
							echo wp_kses( sprintf( _n( '<b>%s member</b> and growing', '<b>%s members</b> and growing', $data['faces']['count'], 'membuild-community' ), number_format_i18n( $data['faces']['count'] ) ), array( 'b' => array() ) );
							?>
							</span>
						</span>
					<?php endif; ?>
				</figcaption>
			</figure>
		</div>
	<?php endif; ?>
	</aside>

	<section class="form-side">
		<div class="panel">
		<?php if ( 'login' === $view ) : ?>
			<?php echo $mbcom_brand; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<?php echo $mbcom_h1( __( 'Welcome', 'membuild-community' ), __( 'back.', 'membuild-community' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<div class="intro">
				<p class="lead">
				<?php
				/* translators: %s: community name */
				echo esc_html( sprintf( __( 'Log in to %s.', 'membuild-community' ), $name ) );
				?>
				</p>
				<?php if ( $signup ) : ?>
					<p class="small"><?php esc_html_e( 'New here?', 'membuild-community' ); ?> <a href="<?php echo esc_url( $signup ); ?>"><?php echo $join_label ? esc_html( $join_label ) : esc_html__( 'Join free', 'membuild-community' ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php if ( $data['error'] ) : ?>
				<?php
				echo $mbcom_alert( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here
					esc_html( $data['error'] ) . ( $data['wrong'] ? ' <a href="' . esc_url( Login::url( 'forgot-password' ) ) . '">' . esc_html__( 'Reset it', 'membuild-community' ) . '</a>' : '' )
				);
				?>
			<?php elseif ( $data['notice'] ) : ?>
				<?php echo $mbcom_okbox( esc_html( $data['notice'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( Login::url( 'login' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_login', '_mbcom_login', false ); ?>
				<?php if ( $data['redirect'] ) : ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $data['redirect'] ); ?>">
				<?php endif; ?>
				<label class="field">
					<span class="label"><?php esc_html_e( 'Email or username', 'membuild-community' ); ?></span>
					<input name="log" type="text" value="<?php echo esc_attr( $data['login'] ); ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required <?php echo $data['login'] ? '' : 'autofocus'; ?>>
				</label>
				<div class="field">
					<span class="label"><label for="mbcom-pwd"><?php esc_html_e( 'Password', 'membuild-community' ); ?></label><a href="<?php echo esc_url( Login::url( 'forgot-password' ) ); ?>"><?php esc_html_e( 'Forgot password?', 'membuild-community' ); ?></a></span>
					<span class="box"><input id="mbcom-pwd" name="pwd" type="password" autocomplete="current-password" required <?php echo $data['login'] ? 'autofocus' : ''; ?>><?php echo $mbcom_show; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></span>
				</div>
				<label class="check"><input type="checkbox" name="rememberme" value="1" checked><span class="tick" aria-hidden="true"></span><?php esc_html_e( 'Keep me logged in', 'membuild-community' ); ?></label>
				<?php do_action( 'login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, so CAPTCHA and two-factor plugins can add their fields ?>
				<button class="btn" type="submit"><?php esc_html_e( 'Log in', 'membuild-community' ); ?> <?php echo $mbcom_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed icon ?></button>
				<?php if ( $magic ) : ?>
					<p class="or" aria-hidden="true"><span><?php esc_html_e( 'or', 'membuild-community' ); ?></span></p>
					<button class="btn outline" type="submit" name="magic" value="1" formnovalidate><?php esc_html_e( 'Email me a login link', 'membuild-community' ); ?></button>
				<?php endif; ?>
			</form>
			<?php
			/**
			 * Under the login form: room for other ways in, such as "Sign in with Google".
			 * The page's .field, .btn and .btn.outline styles are available.
			 */
			do_action( 'membuild_community/login_after' );
			?>
			<?php if ( ! empty( $data['live'] ) ) : ?>
				<div class="live-under"><?php echo $mbcom_live( $data['live'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></div>
			<?php endif; ?>

		<?php elseif ( 'linksent' === $view ) : ?>
			<?php echo $mbcom_brand . $mbcom_h1( __( 'Check your', 'membuild-community' ), __( 'email.', 'membuild-community' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<?php
			/* translators: %s: the email or username the person typed */
			echo $mbcom_okbox( sprintf( esc_html__( 'Login link sent to %s.', 'membuild-community' ), '<b>' . esc_html( $data['sent'] ) . '</b>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here
			?>
			<form method="post" action="<?php echo esc_url( Login::url( 'login' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_login', '_mbcom_login', false ); ?>
				<input type="hidden" name="log" value="<?php echo esc_attr( $data['sent'] ); ?>">
				<button class="btn outline" type="submit" name="magic" value="1"><?php esc_html_e( 'Send it again', 'membuild-community' ); ?></button>
			</form>
			<p class="foot"><?php esc_html_e( 'Use your password instead?', 'membuild-community' ); ?> <?php echo $mbcom_login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></p>

		<?php elseif ( 'linkgo' === $view ) : ?>
			<?php echo $mbcom_brand . $mbcom_h1( __( 'Welcome', 'membuild-community' ), __( 'back.', 'membuild-community' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<p class="lead">
			<?php
			/* translators: %s: the member's email address */
			echo esc_html( sprintf( __( 'Log in as %s.', 'membuild-community' ), $data['email'] ) );
			?>
			</p>
			<form method="post" action="<?php echo esc_url( Login::url( 'login-link' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_link', '_mbcom_link', false ); ?>
				<input type="hidden" name="u" value="<?php echo (int) $data['u']; ?>">
				<input type="hidden" name="t" value="<?php echo esc_attr( $data['t'] ); ?>">
				<button class="btn" type="submit"><?php esc_html_e( 'Log in', 'membuild-community' ); ?> <?php echo $mbcom_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed icon ?></button>
			</form>
			<p class="foot"><?php esc_html_e( 'Not you?', 'membuild-community' ); ?> <?php echo $mbcom_login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></p>

		<?php elseif ( 'forgot' === $view && $data['sent'] ) : ?>
			<?php echo $mbcom_brand . $mbcom_h1( __( 'Check your', 'membuild-community' ), __( 'email.', 'membuild-community' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<?php
			/* translators: %s: the email or username the person typed */
			echo $mbcom_okbox( sprintf( esc_html__( 'If %s has an account, a link is on its way. It works for 24 hours.', 'membuild-community' ), '<b>' . esc_html( $data['sent'] ) . '</b>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here
			?>
			<p class="lead small-lead"><?php esc_html_e( 'Nothing there after a few minutes? Look in spam, or send it again.', 'membuild-community' ); ?></p>
			<form method="post" action="<?php echo esc_url( Login::url( 'forgot-password' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_forgot', '_mbcom_forgot', false ); ?>
				<input type="hidden" name="user_login" value="<?php echo esc_attr( $data['sent'] ); ?>">
				<button class="btn outline" type="submit"><?php esc_html_e( 'Send it again', 'membuild-community' ); ?></button>
			</form>
			<p class="foot"><?php esc_html_e( 'Back to', 'membuild-community' ); ?> <?php echo $mbcom_login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></p>

		<?php elseif ( 'forgot' === $view ) : ?>
			<?php echo $mbcom_brand . $mbcom_h1( __( 'Forgot your', 'membuild-community' ), __( 'password?', 'membuild-community' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<p class="lead"><?php esc_html_e( 'Type the email you joined with. We’ll send you a link to choose a new one.', 'membuild-community' ); ?></p>
			<?php if ( $data['error'] ) : ?>
				<?php echo $mbcom_alert( esc_html( $data['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( Login::url( 'forgot-password' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_forgot', '_mbcom_forgot', false ); ?>
				<label class="field">
					<span class="label"><?php esc_html_e( 'Email', 'membuild-community' ); ?></span>
					<input name="user_login" type="text" value="<?php echo esc_attr( $data['value'] ); ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus>
				</label>
				<button class="btn" type="submit"><?php esc_html_e( 'Send me a link', 'membuild-community' ); ?> <?php echo $mbcom_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed icon ?></button>
			</form>
			<p class="foot"><?php esc_html_e( 'Remembered it?', 'membuild-community' ); ?> <?php echo $mbcom_login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></p>

		<?php elseif ( 'reset' === $view ) : ?>
			<?php echo $mbcom_brand . $mbcom_h1( __( 'Choose a new', 'membuild-community' ), __( 'password.', 'membuild-community' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<p class="lead">
				<?php
				/* translators: %s: the member's email address */
				echo esc_html( sprintf( __( 'For %s.', 'membuild-community' ), $data['email'] ) );
				?>
			</p>
			<?php if ( $data['error'] ) : ?>
				<?php echo $mbcom_alert( esc_html( $data['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( Login::url( 'reset-password' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_reset', '_mbcom_reset', false ); ?>
				<input type="hidden" name="login" value="<?php echo esc_attr( $data['login'] ); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $data['key'] ); ?>">
				<input type="text" name="username" value="<?php echo esc_attr( $data['login'] ); ?>" autocomplete="username" hidden>
				<div class="field">
					<span class="label"><label for="mbcom-new"><?php esc_html_e( 'New password', 'membuild-community' ); ?></label><small><?php echo esc_html( $mbcom_min ); ?></small></span>
					<span class="box"><input id="mbcom-new" name="pass" type="password" autocomplete="new-password" minlength="<?php echo (int) Login::MIN_PASSWORD; ?>" required autofocus><?php echo $mbcom_show; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></span>
				</div>
				<label class="check"><input type="checkbox" name="rememberme" value="1" checked><span class="tick" aria-hidden="true"></span><?php esc_html_e( 'Keep me logged in', 'membuild-community' ); ?></label>
				<button class="btn" type="submit"><?php esc_html_e( 'Save and log in', 'membuild-community' ); ?> <?php echo $mbcom_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed icon ?></button>
			</form>

		<?php elseif ( 'join' === $view && $data['blocked'] ) : ?>
			<?php echo $mbcom_brand . $mbcom_h1( __( 'Join', 'membuild-community' ), $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<p class="lead"><?php esc_html_e( 'Sorry, you can’t create an account right now.', 'membuild-community' ); ?></p>
			<a class="btn grey" href="<?php echo esc_url( Login::url( 'login' ) ); ?>"><?php esc_html_e( 'Back to log in', 'membuild-community' ); ?></a>

		<?php elseif ( 'join' === $view ) : ?>
			<?php
			$mbcom_words = preg_split( '/\s+/', $headline ? $headline : __( 'Join now.', 'membuild-community' ) );
			$mbcom_last  = count( $mbcom_words ) > 1 ? array_pop( $mbcom_words ) : '';
			echo $mbcom_h1( implode( ' ', $mbcom_words ), $mbcom_last ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built
			?>
			<p class="lead">
			<?php
			/* translators: %s: community name */
			echo esc_html( sprintf( __( '%s is free to join. It takes a minute.', 'membuild-community' ), $name ) );
			?>
			</p>
			<?php if ( $host['line'] && $host['name'] ) : ?>
				<p class="host-row">
					<?php if ( $host['photo'] ) : ?><img src="<?php echo esc_url( $host['photo'] ); ?>" alt=""><?php else : ?><?php echo $mbcom_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?><?php endif; ?>
					<span>“<?php echo esc_html( $host['line'] ); ?>” <b><?php echo esc_html( $host['name'] ); ?></b></span>
				</p>
			<?php endif; ?>
			<?php echo $mbcom_gets( $data['gets'], 'wide' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<?php if ( $data['error'] && 'mbcom_email_taken' !== $data['code'] ) : ?>
				<?php echo $mbcom_alert( esc_html( $data['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( Login::url( 'join' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_join', '_mbcom_join', false ); ?>
				<label class="field">
					<span class="label"><?php esc_html_e( 'First name', 'membuild-community' ); ?></span>
					<input name="first_name" type="text" value="<?php echo esc_attr( $data['values']['first'] ); ?>" autocomplete="given-name" required <?php echo $data['error'] ? '' : 'autofocus'; ?>>
				</label>
				<div class="field<?php echo 'mbcom_email_taken' === $data['code'] ? ' has-error' : ''; ?>">
					<label class="label" for="mbcom-email"><?php esc_html_e( 'Email', 'membuild-community' ); ?></label>
					<input id="mbcom-email" name="email" type="email" value="<?php echo esc_attr( $data['values']['email'] ); ?>" autocomplete="email" required <?php echo 'mbcom_email_taken' === $data['code'] ? 'aria-invalid="true" aria-describedby="mbcom-email-error" autofocus' : ''; ?>>
					<?php if ( 'mbcom_email_taken' === $data['code'] ) : ?>
						<span class="field-error" id="mbcom-email-error" role="alert">
							<?php
							echo esc_html( $data['error'] ) . ' ' . wp_kses(
								/* translators: 1: link to log in, 2: link to reset the password */
								sprintf( __( '%1$s or %2$s.', 'membuild-community' ), $mbcom_login, '<a href="' . esc_url( Login::url( 'forgot-password' ) ) . '">' . esc_html__( 'reset your password', 'membuild-community' ) . '</a>' ),
								array( 'a' => array( 'href' => true ) )
							);
							?>
						</span>
					<?php endif; ?>
				</div>
				<div class="field">
					<span class="label"><label for="mbcom-pass"><?php esc_html_e( 'Password', 'membuild-community' ); ?></label><small><?php echo esc_html( $mbcom_min ); ?></small></span>
					<span class="box"><input id="mbcom-pass" name="pass" type="password" autocomplete="new-password" minlength="<?php echo (int) Login::MIN_PASSWORD; ?>" required><?php echo $mbcom_show; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></span>
				</div>
				<?php if ( $data['min_age'] ) : ?>
					<fieldset class="field birthday">
						<legend class="label"><?php esc_html_e( 'Birthday', 'membuild-community' ); ?> <small><?php esc_html_e( 'Not saved', 'membuild-community' ); ?></small></legend>
						<div class="pair">
							<select name="birth_month" required aria-label="<?php esc_attr_e( 'Month', 'membuild-community' ); ?>">
								<option value=""><?php esc_html_e( 'Month', 'membuild-community' ); ?></option>
								<?php for ( $mbcom_m = 1; $mbcom_m <= 12; $mbcom_m++ ) : ?>
									<option value="<?php echo (int) $mbcom_m; ?>"><?php echo esc_html( wp_date( 'F', gmmktime( 0, 0, 0, $mbcom_m, 1, 2000 ), new DateTimeZone( 'UTC' ) ) ); ?></option>
								<?php endfor; ?>
							</select>
							<select name="birth_year" required aria-label="<?php esc_attr_e( 'Year', 'membuild-community' ); ?>">
								<option value=""><?php esc_html_e( 'Year', 'membuild-community' ); ?></option>
								<?php for ( $mbcom_y = (int) gmdate( 'Y' ); $mbcom_y >= (int) gmdate( 'Y' ) - 110; $mbcom_y-- ) : ?>
									<option value="<?php echo (int) $mbcom_y; ?>"><?php echo (int) $mbcom_y; ?></option>
								<?php endfor; ?>
							</select>
						</div>
					</fieldset>
				<?php endif; ?>
				<label class="trap" aria-hidden="true"><?php esc_html_e( 'Leave this empty', 'membuild-community' ); ?> <input name="website" type="text" tabindex="-1" autocomplete="off"></label>
				<?php do_action( 'register_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, so CAPTCHA plugins can add their fields ?>
				<?php if ( $data['terms'] || $data['privacy'] ) : ?>
					<p class="small terms">
						<?php
						$mbcom_links = array_filter(
							array(
								$data['terms'] ? '<a href="' . esc_url( $data['terms'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Terms', 'membuild-community' ) . '</a>' : '',
								$data['privacy'] ? '<a href="' . esc_url( $data['privacy'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', 'membuild-community' ) . '</a>' : '',
							)
						);
						/* translators: %s: links to the Terms and/or Privacy Policy */
						echo wp_kses( sprintf( __( 'By joining, you agree to the %s.', 'membuild-community' ), wp_sprintf( '%l', $mbcom_links ) ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) );
						?>
					</p>
				<?php endif; ?>
				<button class="btn" type="submit"><?php esc_html_e( 'Join free', 'membuild-community' ); ?> <?php echo $mbcom_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed icon ?></button>
			</form>
			<?php echo $mbcom_gets( $data['gets'], 'narrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<p class="foot"><?php esc_html_e( 'Already a member?', 'membuild-community' ); ?> <?php echo $mbcom_login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></p>

		<?php else : ?>
			<?php $mbcom_link = ! empty( $data['link'] ); ?>
			<?php echo $mbcom_brand; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?>
			<h1 class="sr-only"><?php echo esc_html( $mbcom_titles['expired'] ); ?></h1>
			<?php
			echo $mbcom_alert( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here
				$mbcom_link
					? esc_html__( 'This login link has expired or was already used. Links work once, for 15 minutes.', 'membuild-community' )
					: esc_html__( 'This link has expired or was already used. Links work for 24 hours.', 'membuild-community' )
			);
			?>
			<form method="post" action="<?php echo esc_url( Login::url( $mbcom_link ? 'login' : 'forgot-password' ) ); ?>">
				<?php $mbcom_link ? wp_nonce_field( 'mbcom_login', '_mbcom_login', false ) : wp_nonce_field( 'mbcom_forgot', '_mbcom_forgot', false ); ?>
				<label class="field">
					<span class="label"><?php esc_html_e( 'Email', 'membuild-community' ); ?></span>
					<input name="<?php echo $mbcom_link ? 'log' : 'user_login'; ?>" type="text" value="<?php echo esc_attr( $data['value'] ?? '' ); ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus>
				</label>
				<button class="btn" type="submit"<?php echo $mbcom_link ? ' name="magic" value="1"' : ''; ?>><?php esc_html_e( 'Send me a new link', 'membuild-community' ); ?> <?php echo $mbcom_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed icon ?></button>
			</form>
			<p class="foot"><?php esc_html_e( 'Back to', 'membuild-community' ); ?> <?php echo $mbcom_login; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></p>
		<?php endif; ?>
		</div>
	</section>
</main>
<?php wp_print_scripts( array( 'mbcom-login' ) ); ?>
</body>
</html>
