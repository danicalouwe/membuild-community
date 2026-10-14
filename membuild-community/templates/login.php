<?php
/**
 * The community's sign-up, login, forgot-password and reset-password pages (see class Login),
 * laid out like Facebook's: brand and an illustration on the left of the login form, the
 * others as a single centered card.
 * Variables: $view, $data, $name, $tagline, $logo, $initials, $signup, $icons.
 *
 * @package membuild-community
 */

defined( 'ABSPATH' ) || exit;

$mbcom_titles = array(
	/* translators: %s: community name */
	'login'   => sprintf( __( 'Log into %s', 'membuild-community' ), $name ),
	'join'    => __( 'Create a new account', 'membuild-community' ),
	'forgot'  => __( 'Find your account', 'membuild-community' ),
	'reset'   => __( 'Choose a new password', 'membuild-community' ),
	'expired' => __( 'This link has expired', 'membuild-community' ),
);
$mbcom_eye = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
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
</head>
<body>
<?php if ( 'login' === $view ) : ?>
	<main class="page">
		<section class="brand-side" aria-hidden="true">
			<span class="logo"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php else : ?><?php echo esc_html( $initials ); ?><?php endif; ?></span>
			<div class="stage">
				<div class="art">
					<div class="reel"></div>
					<div class="post"><i></i><i></i><b></b></div>
					<div class="member"></div>
					<div class="chip"><?php esc_html_e( 'Level up!', 'membuild-community' ); ?></div>
					<div class="emoji e1">😆</div>
					<div class="emoji e2">❤️</div>
				</div>
				<div>
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
					<?php if ( $tagline ) : ?>
						<p class="tagline"><?php echo esc_html( $tagline ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<section class="form-side">
			<div class="panel">
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
						<input name="log" type="text" value="<?php echo esc_attr( $data['login'] ); ?>" placeholder=" " autocomplete="username" autocapitalize="off" spellcheck="false" required <?php echo $data['login'] ? '' : 'autofocus'; ?>>
						<span><?php esc_html_e( 'Email or username', 'membuild-community' ); ?></span>
					</label>
					<label class="field pw">
						<input name="pwd" type="password" placeholder=" " autocomplete="current-password" required <?php echo $data['login'] ? 'autofocus' : ''; ?>>
						<span><?php esc_html_e( 'Password', 'membuild-community' ); ?></span>
						<button type="button" class="eye" aria-pressed="false" aria-label="<?php esc_attr_e( 'Show password', 'membuild-community' ); ?>" data-show="<?php esc_attr_e( 'Show password', 'membuild-community' ); ?>" data-hide="<?php esc_attr_e( 'Hide password', 'membuild-community' ); ?>"><?php echo $mbcom_eye; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup above ?></button>
					</label>
					<?php do_action( 'login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, so CAPTCHA and two-factor plugins can add their fields ?>
					<button class="btn" type="submit"><?php esc_html_e( 'Log in', 'membuild-community' ); ?></button>
				</form>
				<a class="link" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'forgot-password' ) ); ?>"><?php esc_html_e( 'Forgot password?', 'membuild-community' ); ?></a>
				<?php if ( $signup ) : ?>
					<div class="join"><a class="btn outline" href="<?php echo esc_url( $signup ); ?>"><?php esc_html_e( 'Create new account', 'membuild-community' ); ?></a></div>
				<?php endif; ?>
				<?php
				/**
				 * Under the login form: room for other ways in, such as "Sign in with Google".
				 * The page's .field, .btn and .btn.outline styles are available.
				 */
				do_action( 'membuild_community/login_after' );
				?>
				<p class="foot"><?php echo esc_html( $name ); ?></p>
			</div>
		</section>
	</main>

<?php else : ?>
	<main class="solo-page">
		<div class="solo">
			<span class="logo logo-corner" aria-hidden="true"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php else : ?><?php echo esc_html( $initials ); ?><?php endif; ?></span>
			<a class="back" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>" aria-label="<?php esc_attr_e( 'Back to log in', 'membuild-community' ); ?>"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></a>

			<?php if ( 'forgot' === $view && $data['sent'] ) : ?>
				<h1><?php esc_html_e( 'Check your email', 'membuild-community' ); ?></h1>
				<p class="lead">
					<?php
					/* translators: %s: the email or username the person typed */
					echo esc_html( sprintf( __( 'If an account matches %s, we’ve sent it a link to reset the password. The link works for 24 hours.', 'membuild-community' ), $data['sent'] ) );
					?>
				</p>
				<p class="small"><?php esc_html_e( 'Nothing arrived? Check your spam folder, or try again in a few minutes.', 'membuild-community' ); ?></p>
				<a class="btn" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>"><?php esc_html_e( 'Back to log in', 'membuild-community' ); ?></a>

			<?php elseif ( 'forgot' === $view ) : ?>
				<h1><?php echo esc_html( $mbcom_titles['forgot'] ); ?></h1>
				<p class="lead"><?php esc_html_e( 'Enter your email or username.', 'membuild-community' ); ?></p>
				<?php if ( $data['error'] ) : ?>
					<p class="msg error" role="alert"><?php echo esc_html( $data['error'] ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( \MemBuild\Community\Login::url( 'forgot-password' ) ); ?>">
					<?php wp_nonce_field( 'mbcom_forgot', '_mbcom_forgot', false ); ?>
					<label class="field">
						<input name="user_login" type="text" value="<?php echo esc_attr( $data['value'] ); ?>" placeholder=" " autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus>
						<span><?php esc_html_e( 'Email or username', 'membuild-community' ); ?></span>
					</label>
					<button class="btn" type="submit"><?php esc_html_e( 'Continue', 'membuild-community' ); ?></button>
				</form>

			<?php elseif ( 'reset' === $view ) : ?>
				<h1><?php echo esc_html( $mbcom_titles['reset'] ); ?></h1>
				<p class="lead">
					<?php
					/* translators: 1: the member's name, 2: minimum number of characters */
					echo esc_html( sprintf( __( 'Hi %1$s, pick a new password with at least %2$d characters.', 'membuild-community' ), $data['name'], \MemBuild\Community\Login::MIN_PASSWORD ) );
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
					<label class="field pw">
						<input name="pass" type="password" placeholder=" " autocomplete="new-password" minlength="<?php echo (int) \MemBuild\Community\Login::MIN_PASSWORD; ?>" required autofocus>
						<span><?php esc_html_e( 'New password', 'membuild-community' ); ?></span>
						<button type="button" class="eye" aria-pressed="false" aria-label="<?php esc_attr_e( 'Show password', 'membuild-community' ); ?>" data-show="<?php esc_attr_e( 'Show password', 'membuild-community' ); ?>" data-hide="<?php esc_attr_e( 'Hide password', 'membuild-community' ); ?>"><?php echo $mbcom_eye; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup above ?></button>
					</label>
					<button class="btn" type="submit"><?php esc_html_e( 'Save and log in', 'membuild-community' ); ?></button>
				</form>

			<?php elseif ( 'join' === $view && $data['blocked'] ) : ?>
				<h1><?php echo esc_html( $mbcom_titles['join'] ); ?></h1>
				<p class="lead"><?php esc_html_e( 'Sorry, you can’t create an account right now.', 'membuild-community' ); ?></p>

			<?php elseif ( 'join' === $view ) : ?>
				<h1><?php echo esc_html( $mbcom_titles['join'] ); ?></h1>
				<p class="lead"><?php esc_html_e( 'It’s quick and easy.', 'membuild-community' ); ?></p>
				<?php if ( $data['error'] ) : ?>
					<p class="msg error" role="alert"><?php echo esc_html( $data['error'] ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( \MemBuild\Community\Login::url( 'join' ) ); ?>">
					<?php wp_nonce_field( 'mbcom_join', '_mbcom_join', false ); ?>
					<div class="pair">
						<label class="field">
							<input name="first_name" type="text" value="<?php echo esc_attr( $data['values']['first'] ); ?>" placeholder=" " autocomplete="given-name" required autofocus>
							<span><?php esc_html_e( 'First name', 'membuild-community' ); ?></span>
						</label>
						<label class="field">
							<input name="last_name" type="text" value="<?php echo esc_attr( $data['values']['last'] ); ?>" placeholder=" " autocomplete="family-name">
							<span><?php esc_html_e( 'Last name', 'membuild-community' ); ?></span>
						</label>
					</div>
					<label class="field">
						<input name="email" type="email" value="<?php echo esc_attr( $data['values']['email'] ); ?>" placeholder=" " autocomplete="email" required>
						<span><?php esc_html_e( 'Email', 'membuild-community' ); ?></span>
					</label>
					<label class="field pw">
						<input name="pass" type="password" placeholder=" " autocomplete="new-password" minlength="<?php echo (int) \MemBuild\Community\Login::MIN_PASSWORD; ?>" required>
						<span><?php esc_html_e( 'New password', 'membuild-community' ); ?></span>
						<button type="button" class="eye" aria-pressed="false" aria-label="<?php esc_attr_e( 'Show password', 'membuild-community' ); ?>" data-show="<?php esc_attr_e( 'Show password', 'membuild-community' ); ?>" data-hide="<?php esc_attr_e( 'Hide password', 'membuild-community' ); ?>"><?php echo $mbcom_eye; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup above ?></button>
					</label>
					<?php if ( $data['min_age'] ) : ?>
						<fieldset class="birthday">
							<legend><?php esc_html_e( 'Birthday', 'membuild-community' ); ?></legend>
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
							echo wp_kses( sprintf( __( 'By signing up, you agree to our %s.', 'membuild-community' ), wp_sprintf( '%l', $mbcom_links ) ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) );
							?>
						</p>
					<?php endif; ?>
					<button class="btn" type="submit"><?php esc_html_e( 'Sign up', 'membuild-community' ); ?></button>
				</form>
				<a class="link" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'login' ) ); ?>"><?php esc_html_e( 'Already have an account? Log in', 'membuild-community' ); ?></a>

			<?php else : ?>
				<h1><?php echo esc_html( $mbcom_titles['expired'] ); ?></h1>
				<p class="lead"><?php esc_html_e( 'Password links work once and for 24 hours. Ask for a new one and use the newest email.', 'membuild-community' ); ?></p>
				<a class="btn" href="<?php echo esc_url( \MemBuild\Community\Login::url( 'forgot-password' ) ); ?>"><?php esc_html_e( 'Send a new link', 'membuild-community' ); ?></a>
			<?php endif; ?>
		</div>
	</main>
<?php endif; ?>
<?php wp_print_scripts( array( 'mbcom-login' ) ); ?>
</body>
</html>
