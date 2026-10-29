<?php
/**
 * The community's sign-up, login, forgot-password and reset-password pages (see class Login).
 * Every page has the community's logo, picture and headline on the left and the form on the
 * right: labels above 54px fields, a "Show" word on passwords, one main button.
 * Variables: $view, $data, $name, $tagline, $headline, $picture, $join_label, $logo, $initials, $signup, $icons.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

defined( 'ABSPATH' ) || exit;

$mbcom_titles = array(
	/* translators: %s: community name */
	'login'   => sprintf( __( 'Log in to %s', 'membuild-community' ), $name ),
	/* translators: %s: community name */
	'join'    => sprintf( __( 'Join %s', 'membuild-community' ), $name ),
	'forgot'  => __( 'Forgot your password?', 'membuild-community' ),
	'reset'   => __( 'Choose a new password', 'membuild-community' ),
	'expired' => __( 'This link has expired', 'membuild-community' ),
);
// The "Show" word on a password field; its words come from data attributes (see login.js).
$mbcom_show = '<button type="button" class="show" aria-pressed="false" data-show="' . esc_attr__( 'Show', 'membuild-community' ) . '" data-hide="' . esc_attr__( 'Hide', 'membuild-community' ) . '" aria-label="' . esc_attr__( 'Show password', 'membuild-community' ) . '">' . esc_html__( 'Show', 'membuild-community' ) . '</button>';
/* translators: %d: minimum number of characters */
$mbcom_min  = sprintf( __( '%d or more characters', 'membuild-community' ), \MemBuild\Community\Login::MIN_PASSWORD );
?><!doctype html>
<html <?php language_attributes(); ?>>
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
<body>
<main class="page">
	<section class="brand-side" aria-hidden="true">
	<?php
	/**
	 * Theme contract slot "join.picture": the picture side of Join and Log in. A Community
	 * theme returns its own HTML (passed through wp_kses_post) to replace the logo, picture and
	 * headline; returning '' keeps them. $args: view ('login', 'join', …), picture (the owner's
	 * picture or ''), headline, tagline, logo, initials, name.
	 */
	$mbcom_side = (string) apply_filters( 'membuild_community/join_picture', '', array( 'view' => $view, 'picture' => (string) $picture, 'headline' => (string) $headline, 'tagline' => (string) $tagline, 'logo' => (string) $logo, 'initials' => (string) $initials, 'name' => (string) $name ) );
	?>
	<?php if ( '' !== trim( $mbcom_side ) ) : ?>
		<?php echo wp_kses_post( $mbcom_side ); ?>
	<?php else : ?>
		<span class="logo"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php else : ?><?php echo esc_html( $initials ); ?><?php endif; ?></span>
		<div class="stage">
			<?php if ( $picture ) : ?>
			<div class="photo"><img src="<?php echo esc_url( $picture ); ?>" alt=""></div>
			<?php else : ?>
			<div class="art">
				<div class="reel"></div>
				<div class="post"><i></i><i></i><b></b></div>
				<div class="member"></div>
				<div class="chip"><?php esc_html_e( 'Level up!', 'membuild-community' ); ?></div>
				<div class="emoji e1">😆</div>
				<div class="emoji e2">❤️</div>
			</div>
			<?php endif; ?>
			<div>
				<?php if ( $headline ) : ?>
					<?php $mbcom_words = preg_split( '/\s+/', $headline ); $mbcom_last = array_pop( $mbcom_words ); ?>
				<p class="headline"><?php echo esc_html( implode( ' ', $mbcom_words ) ); ?> <em><?php echo esc_html( $mbcom_last ); ?></em></p>
				<?php else : ?>
				<p class="headline">
					<?php
					/* translators: the start of the login page headline; it continues with "together." */
					esc_html_e( 'Learn, share and grow', 'membuild-community' );
					?>
					<em>
					<?php
					/* translators: the end of the login page headline, shown in the community's color */
					esc_html_e( 'together.', 'membuild-community' );
					?>
					</em>
				</p>
				<?php endif; ?>
				<?php if ( $tagline ) : ?>
					<p class="tagline"><?php echo esc_html( $tagline ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
	</section>

	<section class="form-side">
		<div class="panel">
		<?php if ( 'login' === $view ) : ?>
			<h1><?php echo esc_html( $mbcom_titles['login'] ); ?></h1>
			<?php if ( $data['error'] ) : ?>
				<p class="msg error" role="alert"><?php echo esc_html( $data['error'] ); ?></p>
			<?php elseif ( $data['notice'] ) : ?>
				<p class="msg" role="status"><?php echo esc_html( $data['notice'] ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_login', '_mbcom_login', false ); ?>
				<?php if ( $data['redirect'] ) : ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $data['redirect'] ); ?>">
				<?php endif; ?>
				<label class="field">
					<span class="label"><?php esc_html_e( 'Email or username', 'membuild-community' ); ?></span>
					<input name="log" type="text" value="<?php echo esc_attr( $data['login'] ); ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required <?php echo $data['login'] ? '' : 'autofocus'; ?>>
				</label>
				<div class="field">
					<span class="label"><label for="mbcom-pwd"><?php esc_html_e( 'Password', 'membuild-community' ); ?></label><a href="<?php echo esc_url( \MemBuild\Community\Login::url( 'forgot-password' ) ); ?>"><?php esc_html_e( 'Forgot password?', 'membuild-community' ); ?></a></span>
					<span class="box"><input id="mbcom-pwd" name="pwd" type="password" autocomplete="current-password" required <?php echo $data['login'] ? 'autofocus' : ''; ?>><?php echo $mbcom_show; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></span>
				</div>
				<label class="check"><input type="checkbox" name="rememberme" value="1" checked><span class="tick" aria-hidden="true"></span><?php esc_html_e( 'Keep me logged in', 'membuild-community' ); ?></label>
				<?php do_action( 'login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, so CAPTCHA and two-factor plugins can add their fields ?>
				<button class="btn" type="submit"><?php esc_html_e( 'Log in', 'membuild-community' ); ?></button>
			</form>
			<?php if ( $signup ) : ?>
				<p class="or"><span><?php esc_html_e( 'New here?', 'membuild-community' ); ?></span></p>
				<a class="btn outline" href="<?php echo esc_url( $signup ); ?>"><?php echo $join_label ? esc_html( $join_label ) : esc_html__( 'Join the community', 'membuild-community' ); ?></a>
			<?php endif; ?>
			<?php
			/**
			 * Under the login form: room for other ways in, such as "Sign in with Google".
			 * The page's .field, .btn and .btn.outline styles are available.
			 */
			do_action( 'membuild_community/login_after' );
			?>

		<?php elseif ( 'forgot' === $view && $data['sent'] ) : ?>
			<span class="icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18v12H3zM3 7l9 6 9-6"/></svg></span>
			<h1><?php esc_html_e( 'Check your email', 'membuild-community' ); ?></h1>
			<p class="lead">
				<?php
				/* translators: %s: the email or username the person typed */
				echo wp_kses( sprintf( __( 'If %s has an account, a link to choose a new password is on its way. It works for 24 hours.', 'membuild-community' ), '<strong>' . esc_html( $data['sent'] ) . '</strong>' ), array( 'strong' => array() ) );
				?>
			</p>
			<form class="note" method="post" action="<?php echo esc_url( \MemBuild\Community\Login::url( 'forgot-password' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_forgot', '_mbcom_forgot', false ); ?>
				<input type="hidden" name="user_login" value="<?php echo esc_attr( $data['sent'] ); ?>">
				<?php esc_html_e( 'Nothing after a few minutes? Look in your spam folder, or', 'membuild-community' ); ?> <button type="submit" class="text-btn"><?php esc_html_e( 'send it again', 'membuild-community' ); ?></button>.
			</form>
			<a class="btn grey" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>"><?php esc_html_e( 'Back to log in', 'membuild-community' ); ?></a>

		<?php elseif ( 'forgot' === $view ) : ?>
			<h1><?php echo esc_html( $mbcom_titles['forgot'] ); ?></h1>
			<p class="lead"><?php esc_html_e( 'Type the email you joined with. We’ll send you a link to choose a new one.', 'membuild-community' ); ?></p>
			<?php if ( $data['error'] ) : ?>
				<p class="msg error" role="alert"><?php echo esc_html( $data['error'] ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( \MemBuild\Community\Login::url( 'forgot-password' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_forgot', '_mbcom_forgot', false ); ?>
				<label class="field">
					<span class="label"><?php esc_html_e( 'Email', 'membuild-community' ); ?></span>
					<input name="user_login" type="text" value="<?php echo esc_attr( $data['value'] ); ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus>
				</label>
				<button class="btn" type="submit"><?php esc_html_e( 'Send me a link', 'membuild-community' ); ?></button>
			</form>
			<a class="link" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>">‹ <?php esc_html_e( 'Back to log in', 'membuild-community' ); ?></a>

		<?php elseif ( 'reset' === $view ) : ?>
			<h1><?php echo esc_html( $mbcom_titles['reset'] ); ?></h1>
			<p class="lead">
				<?php
				/* translators: %s: the member's email address */
				echo esc_html( sprintf( __( 'For %s.', 'membuild-community' ), $data['email'] ) );
				?>
			</p>
			<?php if ( $data['error'] ) : ?>
				<p class="msg error" role="alert"><?php echo esc_html( $data['error'] ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( \MemBuild\Community\Login::url( 'reset-password' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_reset', '_mbcom_reset', false ); ?>
				<input type="hidden" name="login" value="<?php echo esc_attr( $data['login'] ); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $data['key'] ); ?>">
				<input type="text" name="username" value="<?php echo esc_attr( $data['login'] ); ?>" autocomplete="username" hidden>
				<div class="field">
					<span class="label"><label for="mbcom-new"><?php esc_html_e( 'New password', 'membuild-community' ); ?></label><small><?php echo esc_html( $mbcom_min ); ?></small></span>
					<span class="box"><input id="mbcom-new" name="pass" type="password" autocomplete="new-password" minlength="<?php echo (int) \MemBuild\Community\Login::MIN_PASSWORD; ?>" required autofocus><?php echo $mbcom_show; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></span>
				</div>
				<button class="btn" type="submit"><?php esc_html_e( 'Save and log in', 'membuild-community' ); ?></button>
			</form>

		<?php elseif ( 'join' === $view && $data['blocked'] ) : ?>
			<h1><?php echo esc_html( $mbcom_titles['join'] ); ?></h1>
			<p class="lead"><?php esc_html_e( 'Sorry, you can’t create an account right now.', 'membuild-community' ); ?></p>
			<a class="btn grey" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>"><?php esc_html_e( 'Back to log in', 'membuild-community' ); ?></a>

		<?php elseif ( 'join' === $view ) : ?>
			<h1><?php echo esc_html( $mbcom_titles['join'] ); ?></h1>
			<p class="lead"><?php esc_html_e( 'Free, and it takes a minute.', 'membuild-community' ); ?></p>
			<?php if ( $data['error'] ) : ?>
				<p class="msg error" role="alert"><?php echo esc_html( $data['error'] ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( \MemBuild\Community\Login::url( 'join' ) ); ?>">
				<?php wp_nonce_field( 'mbcom_join', '_mbcom_join', false ); ?>
				<div class="pair">
					<label class="field">
						<span class="label"><?php esc_html_e( 'First name', 'membuild-community' ); ?></span>
						<input name="first_name" type="text" value="<?php echo esc_attr( $data['values']['first'] ); ?>" autocomplete="given-name" required autofocus>
					</label>
					<label class="field">
						<span class="label"><?php esc_html_e( 'Last name', 'membuild-community' ); ?></span>
						<input name="last_name" type="text" value="<?php echo esc_attr( $data['values']['last'] ); ?>" autocomplete="family-name">
					</label>
				</div>
				<label class="field">
					<span class="label"><?php esc_html_e( 'Email', 'membuild-community' ); ?></span>
					<input name="email" type="email" value="<?php echo esc_attr( $data['values']['email'] ); ?>" autocomplete="email" required>
				</label>
				<div class="field">
					<span class="label"><label for="mbcom-pass"><?php esc_html_e( 'Password', 'membuild-community' ); ?></label><small><?php echo esc_html( $mbcom_min ); ?></small></span>
					<span class="box"><input id="mbcom-pass" name="pass" type="password" autocomplete="new-password" minlength="<?php echo (int) \MemBuild\Community\Login::MIN_PASSWORD; ?>" required><?php echo $mbcom_show; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where built ?></span>
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
					<p class="small">
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
				<button class="btn" type="submit"><?php esc_html_e( 'Join the community', 'membuild-community' ); ?></button>
			</form>
			<p class="link plain"><?php esc_html_e( 'Already a member?', 'membuild-community' ); ?> <a href="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>"><?php esc_html_e( 'Log in', 'membuild-community' ); ?></a></p>

		<?php else : ?>
			<h1><?php echo esc_html( $mbcom_titles['expired'] ); ?></h1>
			<p class="lead"><?php esc_html_e( 'Password links work once and for 24 hours. Ask for a new one and use the newest email.', 'membuild-community' ); ?></p>
			<a class="btn" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'forgot-password' ) ); ?>"><?php esc_html_e( 'Send a new link', 'membuild-community' ); ?></a>
		<?php endif; ?>
		</div>
	</section>
</main>
<?php wp_print_scripts( array( 'mbcom-login' ) ); ?>
</body>
</html>
