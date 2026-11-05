<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The starter pages as they're shown: what's worked out when someone looks, not typed in when
 * the pages were made (Starter_Pages makes them). So the pages keep working when the site moves
 * to its live address, the community's address changes or a plan is renamed, and they know who
 * is looking:
 *
 *  - Join buttons (a link to the community's Join page, or #mbcom-join) say "Go to the
 *    community →" to people who are signed in. Log in turns into the member's face.
 *  - #mbcom-plan-<membership> buttons get that plan's checkout and its current name.
 *  - Buttons into the community (events, the Classroom) take visitors to Join first, with a way
 *    back to what they tapped.
 *  - <mark class="mbs-live-host"> and <mark class="mbs-live-email"> show the host's name and the
 *    support email from Settings, the email written so spam robots can't read it.
 *  - Blocks with mbs-refund follow the money-back promise's days (none: they're left out).
 *  - The header's Site Logo and Site Title: a wide logo shows alone, phones get a small mark.
 *  - The owner's main colour, with readable words on it (accent_css()).
 */
class Starter_Site {

	const FEATURES = 'mbcom_plan_features'; // what each plan includes, a line each: membership id => lines

	public static function register_hooks() {
		add_filter( 'render_block', array( __CLASS__, 'render' ), 10, 3 );
		add_action( 'wp_head', array( __CLASS__, 'favicon' ), 3 );
	}

	/* ---------- Links ---------- */

	/** A link's path on this site ("/community/join"), or null when it goes somewhere else. */
	public static function path( $href ) {
		$href = html_entity_decode( (string) $href, ENT_QUOTES );
		$bits = wp_parse_url( $href );
		if ( ! $bits || ( ! empty( $bits['host'] ) && strtolower( $bits['host'] ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ) {
			return null;
		}
		return untrailingslashit( (string) ( $bits['path'] ?? '' ) ) ?: '/';
	}

	/** A short link that works on any domain: "/community/join". */
	public static function rel( $url ) {
		return wp_make_link_relative( (string) $url ) ?: '/';
	}

	private static function community_path( $page = '' ) {
		return untrailingslashit( (string) self::path( Portal::url() ) ) . ( $page ? '/' . $page : '' );
	}

	/** Saved pages keep their links when the community moves: read those as the current address. */
	private static function moved_path( $path ) {
		$home = untrailingslashit( (string) self::path( home_url( '/' ) ) );
		if ( null === $path || 0 !== strpos( $path . '/', $home . '/' ) ) {
			return $path;
		}
		$to = Portal::current_address_for( substr( $path, strlen( $home ) ) );
		return $to ? self::path( $to ) : $path;
	}

	/** Join, then straight to $after (a course, an event) once they have an account. */
	public static function join_then( $after = '' ) {
		return Login::url( 'join', $after ? array( 'redirect_to' => $after ) : array() );
	}

	/**
	 * Where a link into the community goes for whoever is looking: members open it, visitors
	 * join first and then go there.
	 */
	public static function into( $url ) {
		return is_user_logged_in() ? $url : self::join_then( $url );
	}

	/** The Pricing page with a plan picked, for a members-only course (or Join, without a Pricing page). */
	public static function pricing_for( $group_id ) {
		$made = (array) ( get_option( Starter_Pages::OPTION, array() )['pages'] ?? array() );
		$page = ! empty( $made['pricing'] ) && 'publish' === get_post_status( (int) $made['pricing'] ) ? get_permalink( (int) $made['pricing'] ) : '';
		return $page ? add_query_arg( 'plan', (int) $group_id, $page ) . '#plans' : self::join_then();
	}

	/* ---------- What each plan includes (typed once, in Memberships) ---------- */

	/** The lines a membership includes, as typed in Memberships; empty when none were typed. */
	public static function features( $group_id ) {
		$all = get_option( self::FEATURES, array() );
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( is_array( $all ) ? ( $all[ (int) $group_id ] ?? '' ) : '' ) ) ), 'strlen' ) );
	}

	public static function set_features( $group_id, $text ) {
		$all = get_option( self::FEATURES, array() );
		$all = is_array( $all ) ? $all : array();
		$text = implode( "\n", array_slice( array_filter( array_map( fn( $l ) => mb_substr( sanitize_text_field( $l ), 0, 80 ), explode( "\n", (string) $text ) ), 'strlen' ), 0, 8 ) );
		if ( '' === $text ) {
			unset( $all[ (int) $group_id ] );
		} else {
			$all[ (int) $group_id ] = $text;
		}
		update_option( self::FEATURES, $all, false );
	}

	/** The money-back promise in days (Settings › Main website); 0 when there's none. */
	public static function refund_days() {
		$s = get_option( Starter_Pages::OPTION, array() );
		return max( 0, min( 90, (int) ( is_array( $s ) && isset( $s['refund_days'] ) ? $s['refund_days'] : 14 ) ) );
	}

	/** "14-day money-back", or '' with no promise. */
	public static function money_back() {
		$days = self::refund_days();
		/* translators: %d: number of days */
		return $days ? sprintf( __( '%d-day money-back', 'membuild-community' ), $days ) : '';
	}

	/* ---------- Shown as it is now ---------- */

	public static function render( $html, $block, $instance = null ) {
		$name  = (string) ( $block['blockName'] ?? '' );
		$class = (string) ( $block['attrs']['className'] ?? '' );
		if ( 'core/site-logo' === $name && false !== strpos( $class, 'mbs-logo' ) ) {
			return self::logo( $html, $class ); // with no logo the block shows nothing: the initials instead
		}
		if ( 'core/post-date' === $name && '' === $html && false !== strpos( $class, 'mbs-updated' ) ) {
			// "Last updated": never changed since it was made, so the made date.
			$id = (int) ( $instance ? ( $instance->context["postId"] ?? get_the_ID() ) : get_the_ID() );
			return $id ? '<div class="wp-block-post-date ' . esc_attr( $class ) . '"><time datetime="' . esc_attr( get_the_date( 'c', $id ) ) . '">' . esc_html( get_the_date( '', $id ) ) . '</time></div>' : '';
		}
		if ( '' === $html || ! str_starts_with( $name, 'core/' ) ) {
			return $html;
		}
		if ( 'core/button' === $name ) {
			return self::button( $html );
		}
		if ( 'core/site-title' === $name && false !== strpos( $class, 'mbs-site-name' ) ) {
			return self::logo_has_name() ? '' : $html;
		}
		// The money-back promise: mbs-refund parts go when there's none; mbs-refund-days only change the days.
		if ( preg_match( '/(?:^|\s)mbs-refund(-days)?(?:\s|$)/', $class, $rm ) ) {
			$days = self::refund_days();
			if ( ! $days && empty( $rm[1] ) ) {
				return '';
			}
			$html = $days
				? preg_replace( array( '/\b14-day\b/', '/\b14 days\b/' ), array( $days . '-day', $days . ' days' ), $html )
				: str_replace( __( 'Write to us within 14 days of your first payment and we’ll refund it. After that, cancelling stops the next payment.', 'membuild-community' ), __( 'Payments aren’t refunded, but cancelling stops the next one.', 'membuild-community' ), $html );
		}
		if ( false !== strpos( $class, 'mbs-email-row' ) && '' === self::support_email() ) {
			return ''; // no support email: the line goes, the form still works
		}
		if ( 'core/paragraph' === $name && false !== strpos( $class, 'mbs-login' ) && is_user_logged_in() ) {
			return self::face();
		}
		if ( false !== strpos( $class, 'mbs-join-asks' ) && (int) Settings::get( 'min_age' ) ) {
			$html = str_replace( __( 'Your name, email and a password.', 'membuild-community' ), __( 'Your name, email, a password and your birthday.', 'membuild-community' ), $html );
		}
		if ( false !== strpos( $html, 'mbs-live-' ) ) {
			$html = self::live_words( $html );
		}
		return $html;
	}

	/** The host's name and the support email, from Settings, in place of what was typed. */
	private static function live_words( $html ) {
		$host = (string) ( Login::host()['name'] ?? '' );
		$html = preg_replace_callback( '#<mark([^>]*)class="mbs-live-host"([^>]*)>(.*?)</mark>#s', fn( $m ) => esc_html( '' !== $host ? $host : wp_strip_all_tags( $m[3] ) ), $html );
		$html = preg_replace( '#<mark([^>]*)class="mbs-live-year"([^>]*)>(.*?)</mark>#s', esc_html( wp_date( 'Y' ) ), $html );
		return preg_replace_callback( '#<mark([^>]*)class="mbs-live-email"([^>]*)>(.*?)</mark>#s', fn() => self::email_link(), $html );
	}

	/** Settings › General › Support email, or ''. */
	public static function support_email() {
		$email = (string) Settings::get( 'support_email' );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * The support email as a link that spam robots can't read: the page has it backwards and
	 * encoded, and a line of script turns it into a normal email link. Without the script it
	 * reads "hello [at] example [dot] com". No support email: a link to the contact page.
	 */
	public static function email_link() {
		$email = self::support_email();
		if ( '' === $email ) {
			$made = (array) ( get_option( Starter_Pages::OPTION, array() )['pages'] ?? array() );
			$url  = ! empty( $made['contact'] ) ? get_permalink( (int) $made['contact'] ) : '';
			return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'our contact form', 'membuild-community' ) . '</a>' : esc_html__( 'us', 'membuild-community' );
		}
		static $script = false;
		$shown = str_replace( array( '@', '.' ), array( ' [at] ', ' [dot] ' ), $email );
		$out   = '<a class="mbs-mail" href="#" data-m="' . esc_attr( base64_encode( strrev( $email ) ) ) . '">' . esc_html( $shown ) . '</a>'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- only to keep the address from spam robots
		if ( ! $script ) {
			$script = true;
			$out   .= '<script>document.querySelectorAll("a.mbs-mail[data-m]").forEach(function(a){var e=atob(a.dataset.m).split("").reverse().join("");a.href="mailto:"+e;a.textContent=e;a.removeAttribute("data-m")})</script>';
		}
		return $out;
	}

	/** Signed in: their face, in place of "Log in", opening the community. */
	private static function face() {
		$id    = get_current_user_id();
		$photo = (string) get_user_meta( $id, 'mbcom_avatar_url', true ) ?: (string) get_avatar_url( $id, array( 'size' => 88 ) );
		return '<p class="mbs-login mbs-me"><a href="' . esc_url( Portal::url() ) . '" aria-label="' . esc_attr__( 'Go to the community', 'membuild-community' ) . '"><img src="' . esc_url( $photo ) . '" alt="" width="40" height="40"></a></p>';
	}

	/** A button's link and words, worked out for whoever is looking. */
	private static function button( $html ) {
		if ( ! preg_match( '/<a\b[^>]*\bhref="([^"]*)"/', $html, $m ) ) {
			return $html;
		}
		$href = html_entity_decode( $m[1], ENT_QUOTES );
		$in   = is_user_logged_in();
		if ( preg_match( '/^#mbcom-plan-(\d+)$/', $href, $p ) ) {
			return self::plan_button( $html, (int) $p[1] );
		}
		$path = self::moved_path( self::path( $href ) );
		if ( '#mbcom-join' === $href || self::community_path( 'join' ) === $path ) {
			return $in ? self::relabel( $html, Portal::url(), __( 'Go to the community →', 'membuild-community' ) ) : self::relabel( $html, Login::url( 'join' ) );
		}
		if ( self::community_path( 'login' ) === $path ) {
			return $in ? '' : $html; // the phone menu's Log in, for people already in
		}
		if ( ! $in && null !== $path && in_array( $path, array( self::community_path( 'events' ), self::community_path( 'classroom' ) ), true ) ) {
			return self::relabel( $html, self::join_then( home_url( $path . '/' ) ) ); // "See all events": join first, then the events
		}
		return $html;
	}

	/** #mbcom-plan-<id>: that plan's checkout and current name; "Go to the community" for its members. */
	private static function plan_button( $html, $group_id ) {
		$plans = Starter_Blocks::paid_plans();
		$plan  = null;
		foreach ( $plans as $one ) {
			if ( (int) $one[4] === $group_id ) {
				$plan = $one;
			}
		}
		$plan = $plan ?: ( $plans[0] ?? null ); // the plan was removed: the first one sold now
		if ( ! $plan ) {
			return self::relabel( $html, Login::url( 'join' ), __( 'Join free', 'membuild-community' ) );
		}
		if ( is_user_logged_in() && in_array( (int) $plan[4], Access::member_groups( get_current_user_id() ), true ) ) {
			return self::relabel( $html, Portal::url(), __( 'Go to the community →', 'membuild-community' ) );
		}
		$words = preg_match( '#<a\b[^>]*>(.*?)</a>#s', $html, $w ) ? trim( wp_strip_all_tags( $w[1] ) ) : '';
		/* translators: %s: a membership's name, e.g. "VIP" */
		$label = '' === $words || preg_match( '/^Become an?\s/u', $words ) ? sprintf( __( 'Become a %s', 'membuild-community' ), $plan[0] ) : null;
		return self::relabel( $html, $plan[3], $label );
	}

	private static function relabel( $html, $url, $label = null ) {
		$html = preg_replace( '/(<a\b[^>]*\bhref=")[^"]*(")/', '${1}' . esc_url( $url ) . '${2}', $html, 1 );
		return null === $label ? $html : preg_replace( '#(<a\b[^>]*>).*?(</a>)#s', '${1}' . esc_html( $label ) . '${2}', $html, 1 );
	}

	/* ---------- The logo ---------- */

	/** The Site Logo's attachment, or 0. */
	private static function logo_id() {
		return (int) get_option( 'site_logo' ) ?: (int) get_theme_mod( 'custom_logo' );
	}

	/** Whether the logo already says the name: the owner's switch, else a guess from its shape (2.5 : 1 or wider). */
	public static function logo_has_name() {
		$id = self::logo_id();
		if ( ! $id ) {
			return false;
		}
		$say = (string) ( get_option( Starter_Pages::OPTION, array() )['logo_name'] ?? 'auto' );
		if ( 'yes' === $say || 'no' === $say ) {
			return 'yes' === $say;
		}
		$meta = wp_get_attachment_metadata( $id );
		return ! empty( $meta['width'] ) && ! empty( $meta['height'] ) && $meta['width'] / $meta['height'] >= 2.5;
	}

	/**
	 * What the logo sits on, worked out once per logo: 'white' (a white or near-white box, which gets
	 * a small framed tile so it isn't pasted on), 'tile' (its own coloured box, like an app icon: just
	 * round corners) or '' (see-through).
	 */
	private static function logo_box( $id ) {
		$known = get_post_meta( $id, '_mbcom_logo_box', true );
		if ( in_array( $known, array( 'white', 'tile', 'none' ), true ) ) {
			return 'none' === $known ? '' : $known;
		}
		$file = get_attached_file( $id );
		$type = (string) get_post_mime_type( $id );
		$box  = 'image/jpeg' === $type ? 'white' : '';
		$open = array( 'image/png' => 'imagecreatefrompng', 'image/jpeg' => 'imagecreatefromjpeg' )[ $type ] ?? '';
		if ( $open && $file && function_exists( $open ) && filesize( $file ) < 8 * MB_IN_BYTES ) {
			$img = @$open( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken file just counts as see-through
			if ( $img ) {
				imageistruecolor( $img ) || imagepalettetotruecolor( $img ); // so a colour reads the same way in every PNG
				$w     = imagesx( $img ) - 1;
				$h     = imagesy( $img ) - 1;
				$box   = 'tile';
				$light = 0;
				foreach ( array( array( 0, 0 ), array( $w, 0 ), array( 0, $h ), array( $w, $h ) ) as list( $x, $y ) ) {
					$c = imagecolorat( $img, $x, $y );
					if ( ( ( $c >> 24 ) & 0x7F ) > 64 ) {
						$box = ''; // a see-through corner
						break;
					}
					$light += min( ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF ) > 235 ? 1 : 0;
				}
				if ( 'tile' === $box && $light >= 3 ) {
					$box = 'white';
				}
				imagedestroy( $img );
			}
		}
		update_post_meta( $id, '_mbcom_logo_box', $box ?: 'none' );
		return $box;
	}

	/**
	 * The header's logo. None: the round initials (as before). A logo that already says the name
	 * shows alone; phones get the small mark instead (the site icon, or the first letter).
	 */
	private static function logo( $html, $class = '' ) {
		$id = self::logo_id();
		if ( ! $id || false === strpos( $html, '<img' ) ) {
			$small = false !== strpos( $html, 'mbs-logo-small' ) || doing_filter( 'render_block_core/template-part' ) && false;
			return '<p class="mbs-mark' . ( false !== strpos( $class, 'mbs-logo-small' ) ? ' mbs-mark-small' : '' ) . '"><a href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( Settings::name() ) . '">' . esc_html( Settings::initials() ) . '</a></p>';
		}
		$extra = array();
		if ( self::logo_has_name() ) {
			$extra[] = 'mbs-logo-wide';
		}
		$box = self::logo_box( $id );
		if ( $box ) {
			$extra[] = 'white' === $box ? 'mbs-logo-boxed' : 'mbs-logo-tile';
		}
		$html = preg_replace( '/class="([^"]*\bmbs-logo\b[^"]*)"/', 'class="$1 ' . implode( ' ', $extra ) . '"', $html, 1 );
		if ( in_array( 'mbs-logo-wide', $extra, true ) ) {
			$icon  = get_site_icon_url( 96 );
			$mark  = $icon ? '<img src="' . esc_url( $icon ) . '" alt="" width="40" height="40">' : '<span>' . esc_html( mb_strtoupper( mb_substr( Settings::name(), 0, 1 ) ) ) . '</span>';
			$html .= '<a class="mbs-logo-mark" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( Settings::name() ) . '">' . $mark . '</a>';
		}
		return $html;
	}

	/** Browser tabs: with no site icon, the first letter in the main colour. */
	public static function favicon() {
		if ( is_admin() || has_site_icon() || empty( get_option( Starter_Pages::OPTION, array() )['parts'] ) ) {
			return;
		}
		$t   = Color::tokens( self::accent() );
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="16" fill="' . $t['accent'] . '"/><text x="32" y="44" font-family="Arial,sans-serif" font-size="36" font-weight="700" text-anchor="middle" fill="' . $t['ink'] . '">' . esc_html( mb_strtoupper( mb_substr( Settings::name(), 0, 1 ) ) ) . '</text></svg>';
		echo '<link rel="icon" href="data:image/svg+xml,' . rawurlencode( $svg ) . '">' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts
	}

	/* ---------- The owner's colour ---------- */

	/** The main colour: Settings › General, or the starter pages' lime when none is picked. */
	public static function accent() {
		$a = (string) Settings::get( 'accent' );
		return Color::valid( $a ) ? strtolower( $a ) : '#c8f542';
	}

	/**
	 * The colour tokens the pages use: the colour, the words on it (dark or white, whichever reads
	 * best), a readable shade for words and icons on white, and the soft tint every placeholder
	 * shares (about 10% on white; less for very bright colours, which read strong already).
	 */
	public static function accent_css() {
		$hex  = self::accent();
		$t    = Color::tokens( $hex );
		$lum  = Color::luminance( $hex );
		$tint = Color::mix( $hex, '#ffffff', $lum > 0.6 ? 0.84 : 0.9 );
		$soft = Color::mix( $hex, '#ffffff', 0.82 );
		$deep = Color::readable( Color::mix( $hex, '#000000', 0.35 ), $tint, '#000000' );
		return ':root{--mbs-accent:' . $hex . ';--mbs-on-accent:' . $t['ink'] . ';--mbs-accent-text:' . $deep . ';--mbs-tint-accent:' . $tint . ';--mbs-soft:' . $soft . ';--mbs-olive:' . $deep . '}';
	}
}
