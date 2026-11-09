<?php
/**
 * The words layer (theme contract 1): a theme renames the community's own words everywhere,
 * server messages, login pages and emails included. Word keys: classroom, course, lesson, module,
 * home, leaderboard, member, point.
 *
 *   add_filter( 'membuild_community/words', function ( $words, $locale ) {
 *       $words['course'] = array( 'one' => 'program', 'many' => 'programs' );
 *       return $words;
 *   }, 10, 2 );
 *
 * Add the filter when the theme plugin loads, not on a page hook: emails go out with no page open.
 *
 * The swap happens after a sentence is translated, so sentences and translation files never change.
 * It only runs on English sites. These rules are the same in app/src/lib/words.js;
 * tests/words-cases.json runs against both.
 *
 * Contract 1 promise: the keys and Words::get() are only ever added to.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

final class Words {

	const FORMS = array(
		'classroom'   => array( 'classroom', 'classrooms' ),
		'course'      => array( 'course', 'courses' ),
		'lesson'      => array( 'lesson', 'lessons' ),
		'module'      => array( 'module', 'modules' ),
		'home'        => array( 'home', 'homes' ),
		'leaderboard' => array( 'leaderboard', 'leaderboards' ),
		'member'      => array( 'member', 'members' ),
		'point'       => array( 'point', 'points' ),
	);

	// Not our word: a phone's home screen, the site's home page, and other platforms' own screens
	// (the import how-to says "in Skool, open Members", and Skool's tab keeps its name).
	const KEEP = '\b(?:home[ -]screens?|home ?pages?|of course)\b|<b>members</b>|admin › members › member list';

	const MAX = 40;

	private static $lists = array(); // locale => cleaned words
	private static $seen  = array(); // locale => sentence => swapped
	private static $busy  = false;   // a theme's filter calling __() mustn't come back here

	public static function register_hooks() {
		add_action( 'plugins_loaded', array( __CLASS__, 'listen' ), 1 );
	}

	/** Only with a theme's words, so other sites pay nothing. */
	public static function listen() {
		if ( ! has_filter( 'membuild_community/words' ) ) {
			return;
		}
		add_filter( 'gettext_membuild-community', array( __CLASS__, 'swap' ), 10, 1 );
		add_filter( 'gettext_with_context_membuild-community', array( __CLASS__, 'swap' ), 10, 1 );
		add_filter( 'ngettext_membuild-community', array( __CLASS__, 'swap' ), 10, 1 );
		add_filter( 'ngettext_with_context_membuild-community', array( __CLASS__, 'swap' ), 10, 1 );
	}

	/** Forgets the words, for when they change mid-request (the tests). */
	public static function flush() {
		self::$lists = array();
		self::$seen  = array();
	}

	/** A theme's or add-on's own text: the word to use, or "" where there's none for this language. */
	public static function get( $key, $n = 1 ) {
		$locale = self::locale();
		$list   = self::list_for( $locale );
		if ( isset( $list[ $key ] ) ) {
			return 1 === (int) $n ? $list[ $key ]['one'] : $list[ $key ]['many'];
		}
		return self::english( $locale ) && isset( self::FORMS[ $key ] ) ? self::FORMS[ $key ][ 1 === (int) $n ? 0 : 1 ] : '';
	}

	/** For the app, in the page's config: tab names are made before a theme's script runs. */
	public static function config() {
		$locale = self::locale();
		return array(
			'english' => self::english( $locale ),
			'list'    => (object) self::list_for( $locale ),
		);
	}

	public static function swap( $text ) {
		if ( self::$busy || ! is_string( $text ) || '' === $text ) {
			return $text;
		}
		$locale = self::locale();
		if ( ! self::english( $locale ) ) {
			return $text;
		}
		if ( ! isset( self::$seen[ $locale ][ $text ] ) ) {
			self::$seen[ $locale ][ $text ] = self::swap_with( $text, self::list_for( $locale ) );
		}
		return self::$seen[ $locale ][ $text ];
	}

	/** One pass over a sentence, so a swapped word is never swapped again. */
	public static function swap_with( $text, $words ) {
		$forms = array();
		foreach ( $words as $key => $w ) {
			if ( isset( self::FORMS[ $key ] ) ) {
				$forms[ self::FORMS[ $key ][0] ] = array( $w, $w['one'] );
				$forms[ self::FORMS[ $key ][1] ] = array( $w, $w['many'] );
			}
		}
		if ( ! $forms ) {
			return $text;
		}
		$names = array_keys( $forms );
		usort( $names, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
		$out = preg_replace_callback(
			'~' . self::KEEP . '|\b(?:(an?)(\s+))?(' . implode( '|', $names ) . ')\b~iu',
			function ( $m ) use ( $forms ) {
				if ( empty( $m[3] ) ) {
					return $m[0];
				}
				list( $w, $word ) = $forms[ strtolower( $m[3] ) ];
				$found = $m[3];
				$caps  = strlen( $found ) > 1 && strtoupper( $found ) === $found;
				$up    = ctype_upper( $found[0] );
				$new   = $caps ? mb_strtoupper( $word ) : ( $up ? self::capital( $word ) : $word );
				if ( '' === $m[1] ) {
					return $new;
				}
				$a = $w['a'] ?? ( preg_match( '/^[aeiou]/i', $word ) ? 'an' : 'a' );
				$a = $caps ? strtoupper( $a ) : ( ctype_upper( $m[1][0] ) ? ucfirst( $a ) : $a );
				return $a . $m[2] . $new;
			},
			$text
		);
		return null === $out ? $text : $out;
	}

	private static function capital( $word ) {
		return mb_strtoupper( mb_substr( $word, 0, 1 ) ) . mb_substr( $word, 1 );
	}

	private static function english( $locale ) {
		return 'en' === $locale || 0 === strpos( $locale, 'en_' );
	}

	private static function locale() {
		if ( ! did_action( 'after_setup_theme' ) ) {
			return get_locale(); // too early to know who's asking
		}
		return is_locale_switched() ? get_locale() : determine_locale();
	}

	private static function list_for( $locale ) {
		if ( isset( self::$lists[ $locale ] ) ) {
			return self::$lists[ $locale ];
		}
		self::$busy = true;
		/**
		 * The theme's words: key => array( 'one' => , 'many' => , 'a' => optional "a" or "an" ).
		 *
		 * @param array  $words  Starts empty. A word left out stays as it is.
		 * @param string $locale The language being shown.
		 */
		$raw        = apply_filters( 'membuild_community/words', array(), $locale );
		self::$busy = false;
		$clean      = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $w ) {
			if ( ! isset( self::FORMS[ $key ] ) || ! is_array( $w ) ) {
				continue;
			}
			$one  = self::clean( $w['one'] ?? '' );
			$many = self::clean( $w['many'] ?? '' );
			if ( '' === $one || '' === $many ) {
				continue;
			}
			$clean[ $key ] = array( 'one' => $one, 'many' => $many );
			if ( in_array( $w['a'] ?? '', array( 'a', 'an' ), true ) ) {
				$clean[ $key ]['a'] = $w['a'];
			}
		}
		self::$lists[ $locale ] = $clean;
		return $clean;
	}

	/** Plain words only: no tags, no % (sentences are formatted after the swap). */
	private static function clean( $word ) {
		$word = trim( preg_replace( '/\s+/', ' ', str_replace( '%', '', wp_strip_all_tags( (string) $word ) ) ) );
		return mb_substr( $word, 0, self::MAX );
	}
}
