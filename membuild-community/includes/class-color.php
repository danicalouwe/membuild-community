<?php
/**
 * Accent color tokens.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the community's accent color into readable tokens, using WCAG contrast
 * ratios. The app computes the same tokens in app/src/lib/theme.js; keep the
 * two in step.
 */
class Color {

	const WHITE   = '#ffffff';
	const INK     = '#111214'; // dark text on light accents
	const DARK_BG = '#18191c'; // the app's dark surface
	const LIGHT_FILL = '#f0f2f5'; // the light grey fill (filters, tags): the hardest place the main colour's words sit
	const DARK_FILL  = '#232529'; // the same in dark mode
	const MIN     = 4.5;       // WCAG AA for normal text

	/**
	 * Tokens for one accent.
	 *
	 * @param string $hex Accent as #rrggbb.
	 * @return array{accent:string,ink:string,text:string,dark:string,dark_ink:string,dark_text:string}
	 */
	public static function tokens( $hex ) {
		$hex = self::valid( $hex ) ? strtolower( $hex ) : '#3b5bfd';
		$dark = self::dark( $hex );
		return array(
			'accent'    => $hex,
			'ink'       => self::ink( $hex ),
			'text'      => self::readable( $hex, self::LIGHT_FILL, '#000000' ), // so it passes on white and on the grey fills
			'dark'      => $dark,
			'dark_ink'  => self::ink( $dark ),
			'dark_text' => self::readable( $dark, self::DARK_FILL, self::WHITE ),
		);
	}

	/**
	 * The accent on dark backgrounds: a very dark accent (like black) is lifted well
	 * toward white so it doesn't vanish, a bright one is kept, and anything between
	 * is lifted a little (85% accent, 15% white).
	 */
	public static function dark( $hex ) {
		$l = self::luminance( $hex );
		if ( $l < 0.08 ) {
			return self::mix( $hex, self::WHITE, 0.75 );
		}
		return $l > 0.45 ? $hex : self::mix( $hex, self::WHITE, 0.15 );
	}

	public static function valid( $hex ) {
		return is_string( $hex ) && (bool) preg_match( '/^#[0-9a-f]{6}$/i', $hex );
	}

	/** Relative luminance (WCAG 2). */
	public static function luminance( $hex ) {
		$sum = 0;
		foreach ( array( array( 0.2126, 1 ), array( 0.7152, 3 ), array( 0.0722, 5 ) ) as list( $weight, $at ) ) {
			$c    = hexdec( substr( $hex, $at, 2 ) ) / 255;
			$sum += $weight * ( $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4 );
		}
		return $sum;
	}

	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** White or near-black, whichever reads better on the color. */
	public static function ink( $hex ) {
		return self::contrast( $hex, self::WHITE ) >= self::contrast( $hex, self::INK ) ? self::WHITE : self::INK;
	}

	/** The color itself if it's readable on $background, otherwise nudged toward $toward in 5% steps until it is. */
	public static function readable( $hex, $background, $toward ) {
		for ( $step = 0; $step <= 20; $step++ ) {
			$candidate = self::mix( $hex, $toward, $step / 20 );
			if ( self::contrast( $candidate, $background ) >= self::MIN ) {
				return $candidate;
			}
		}
		return $toward;
	}

	/** Mixes $amount (0–1) of $b into $a, in plain RGB. */
	public static function mix( $a, $b, $amount ) {
		$out = '#';
		foreach ( array( 1, 3, 5 ) as $at ) {
			$x    = hexdec( substr( $a, $at, 2 ) );
			$y    = hexdec( substr( $b, $at, 2 ) );
			$out .= sprintf( '%02x', (int) round( $x + ( $y - $x ) * $amount ) );
		}
		return $out;
	}
}
