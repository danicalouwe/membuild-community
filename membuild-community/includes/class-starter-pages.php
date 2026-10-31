<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Starter pages: the rest of the website (Home, Pricing, About, Courses, Community
 * guidelines, Contact & help, and the legal pages), in the community's look.
 *
 * Everything is WordPress blocks, so owners edit any word or photo in the block
 * editor with no page builder. The pages live in patterns/*.html; each top-level
 * section with a name is also a pattern ("MemBuild Community" in the inserter), so
 * it can go on any page. Five live blocks show the community's own content:
 * Courses, Upcoming events, Member faces, Plans (prices from FluentCart) and a
 * Contact form.
 *
 * Nothing is made until the owner presses "Create my starter pages" in Settings
 * (WordPress.org's rule), and Remove undoes it: the pages go to the bin, and the
 * old front page, header and footer come back.
 *
 *   GET    /starter-pages   what can be made, and what was made
 *   POST   /starter-pages   make them { pages: [slug], front: bool, parts: bool }
 *   DELETE /starter-pages   undo
 *   POST   /contact         the Contact form's message, emailed to the site's admin
 */
class Starter_Pages {

	const OPTION   = 'mbcom_starter_pages'; // what was made, and what it replaced
	const TEMPLATE = 'membuild-community//mbcom-starter'; // the full-width page layout
	const STYLE    = 'mbcom-starter';

	/** slug => [ title, file ]. The order is the menu's and the Settings list's. */
	public static function pages() {
		return array(
			'home'                 => array( __( 'Home', 'membuild-community' ), 'home' ),
			'pricing'              => array( __( 'Pricing', 'membuild-community' ), 'pricing' ),
			'about'                => array( __( 'About', 'membuild-community' ), 'about' ),
			'courses'              => array( __( 'Courses', 'membuild-community' ), 'courses' ),
			'community-guidelines' => array( __( 'Community guidelines', 'membuild-community' ), 'guidelines' ),
			'contact'              => array( __( 'Contact & help', 'membuild-community' ), 'contact' ),
			'privacy-policy'       => array( __( 'Privacy policy', 'membuild-community' ), 'privacy' ),
			'terms'                => array( __( 'Terms of membership', 'membuild-community' ), 'terms' ),
			'refunds'              => array( __( 'Refunds & cancelling', 'membuild-community' ), 'refunds' ),
		);
	}

	public static function register_hooks() {
		add_action( 'init', array( Starter_Blocks::class, 'register' ) );
		// The patterns are only for the editor: not built on every visit to the site.
		add_action( 'admin_init', array( __CLASS__, 'register_patterns' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_patterns' ) );
		add_action( 'init', array( __CLASS__, 'register_template' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_style' ) );
		add_action( 'wp_head', array( __CLASS__, 'header_glass' ), 1 );
	}

	public static function register_routes() {
		$admin = fn() => is_user_logged_in() && Helpers::can_manage();
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/starter-pages',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_state' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $admin ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_remove' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/contact', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_contact' ), 'permission_callback' => '__return_true' ) );
	}

	/* ---------- Look ---------- */

	/** The pages' own styles, on the site and in the editor, so both look the same. */
	public static function enqueue_style() {
		wp_enqueue_style( self::STYLE, MEMBUILD_COMMUNITY_URL . 'assets/starter/starter.css', array(), MEMBUILD_COMMUNITY_VERSION );
	}

	/**
	 * The header turns to frosted glass once the page scrolls; at the very top it's clear over the
	 * hero. html.mbs-top says "at the top", set before the page draws so nothing flickers (no script:
	 * the header simply stays glass). Only on pages with our header.
	 */
	public static function header_glass() {
		if ( is_admin() || empty( self::state()['parts'] ) ) {
			return;
		}
		wp_print_inline_script_tag( '(function(){var r=document.documentElement,f=function(){r.classList.toggle("mbs-top",window.scrollY<8)};f();addEventListener("scroll",f,{passive:true})})();' );
	}

	/** One full-width layout: the theme's header and footer around the page's own blocks, no page title. */
	public static function register_template() {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}
		register_block_template(
			self::TEMPLATE,
			array(
				'title'       => __( 'Community page (full width)', 'membuild-community' ),
				'description' => __( 'The starter pages’ layout: header, the page’s blocks edge to edge, footer.', 'membuild-community' ),
				'post_types'  => array( 'page' ),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /--><!-- wp:group {"tagName":"main","layout":{"type":"default"}} --><main class="wp-block-group"><!-- wp:post-content {"layout":{"type":"default"},"className":"mbs-page"} /--></main><!-- /wp:group --><!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);
	}

	/* ---------- Patterns ---------- */

	/** A page's blocks from patterns/<file>.html, with the site's own links and names filled in. */
	public static function markup( $file ) {
		$path = MEMBUILD_COMMUNITY_DIR . 'patterns/' . sanitize_file_name( $file ) . '.html';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		return self::avatars( strtr( (string) file_get_contents( $path ), self::tokens() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own file
	}

	/** {{name}} => what it is on this site: the community's pages, the starter pages, the host. */
	private static function tokens() {
		$made  = self::state()['pages'] ?? array();
		$page  = fn( $slug ) => ! empty( $made[ $slug ] ) && get_post( $made[ $slug ] ) ? get_permalink( $made[ $slug ] ) : home_url( '/' . $slug . '/' );
		$host  = wp_get_current_user();
		$host  = $host && $host->exists() ? $host : ( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null );
		$photo = $host ? (string) get_user_meta( $host->ID, 'mbcom_avatar_url', true ) : '';
		$paid  = Starter_Blocks::paid_plans()[0] ?? null;
		$out   = array(
			'{{site}}'       => esc_html( Settings::get( 'name' ) ?: get_bloginfo( 'name' ) ),
			'{{tagline}}'    => esc_html( get_bloginfo( 'description' ) ),
			'{{home}}'       => esc_url( home_url( '/' ) ),
			'{{community}}'  => esc_url( Portal::url() ),
			'{{classroom}}'  => esc_url( Portal::url() . 'classroom' ),
			'{{events}}'     => esc_url( Portal::url() . 'events' ),
			'{{join}}'       => esc_url( Login::url( 'join' ) ),
			'{{login}}'      => esc_url( Login::url( 'login' ) ),
			'{{host_name}}'  => esc_html( $host ? $host->display_name : __( 'Your name', 'membuild-community' ) ),
			'{{host_photo}}' => esc_url( $photo ?: ( $host ? get_avatar_url( $host->ID, array( 'size' => 800 ) ) : '' ) ),
			'{{phone_shot}}' => esc_url( MEMBUILD_COMMUNITY_URL . 'assets/starter/phone.jpg' ),
			'{{email}}'      => esc_html( get_option( 'admin_email' ) ),
			'{{date}}'       => esc_html( wp_date( get_option( 'date_format' ) ) ),
			'{{members}}'    => esc_html( number_format_i18n( (int) count_users()['total_users'] ) ),
			'{{course_count}}' => esc_html( (string) self::course_count() ),
			'{{initials}}'   => esc_html( self::initials() ),
			'{{about}}'      => esc_html( self::about() ),
			/* translators: %s: a membership's name, e.g. "VIP" */
			'{{paid_cta}}'   => esc_html( $paid ? sprintf( __( 'Become a %s', 'membuild-community' ), $paid[0] ) : __( 'See the courses', 'membuild-community' ) ),
			'{{paid_url}}'   => esc_url( $paid ? $paid[3] : $page( 'courses' ) ),
			'{{host_level}}' => esc_html( (string) ( $host ? Points::level_of( $host->ID ) : 1 ) ),
		);
		foreach ( (array) glob( MEMBUILD_COMMUNITY_DIR . 'assets/starter/icons/*.svg' ) as $icon ) {
			$out[ '{{icon:' . basename( $icon, '.svg' ) . '}}' ] = esc_url( MEMBUILD_COMMUNITY_URL . 'assets/starter/icons/' . basename( $icon ) );
		}
		foreach ( array_keys( self::pages() ) as $slug ) {
			$out[ '{{page:' . $slug . '}}' ] = esc_url( 'home' === $slug ? home_url( '/' ) : $page( $slug ) );
		}
		return $out;
	}

	/** The community's one-line description (its first sentence), for the footer. */
	private static function about() {
		$text = trim( (string) Settings::get( 'description' ) ) ?: (string) get_bloginfo( 'description' );
		return preg_match( '/^.+?[.!?](?=\s|$)/u', $text, $m ) ? $m[0] : $text;
	}

	/**
	 * {{avatar:Grace Kim}}: that member's photo when the community has them (the demo does),
	 * else the first members with a photo, in turn, so quotes never show a grey blank.
	 */
	private static function avatars( $markup ) {
		static $spare = null;
		return preg_replace_callback(
			'/\{\{avatar:([^}]+)\}\}/',
			function ( $m ) use ( &$spare ) {
				$u = get_users( array( 'search' => trim( $m[1] ), 'search_columns' => array( 'display_name' ), 'number' => 1, 'fields' => 'ID' ) );
				if ( ! $u ) {
					$spare = $spare ?? Starter_Blocks::faces( 12 );
					$u     = $spare ? array( array_shift( $spare ) ) : array();
				}
				$url = $u ? ( (string) get_user_meta( (int) $u[0], 'mbcom_avatar_url', true ) ?: get_avatar_url( (int) $u[0], array( 'size' => 120 ) ) ) : '';
				return esc_url( (string) $url );
			},
			$markup
		);
	}

	private static function course_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published'", Install::table( 'courses' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The round mark beside the name: the same letters as the community app's header ("MM"). */
	private static function initials() {
		return Settings::initials();
	}

	/**
	 * "MemBuild Community" in the block inserter: every whole page (also offered when a
	 * new page is made) and each named section of them, to add to any page.
	 */
	public static function register_patterns() {
		static $done = false;
		if ( $done || ! function_exists( 'register_block_pattern' ) ) {
			return;
		}
		$done = true;
		register_block_pattern_category( 'membuild-community', array( 'label' => __( 'MemBuild Community', 'membuild-community' ) ) );
		foreach ( self::pages() as $slug => list( $title, $file ) ) {
			$markup = self::markup( $file );
			if ( ! $markup ) {
				continue;
			}
			register_block_pattern(
				'membuild-community/page-' . $slug,
				array(
					/* translators: %s: a page, e.g. "Pricing" */
					'title'      => sprintf( __( '%s page', 'membuild-community' ), $title ),
					'categories' => array( 'membuild-community' ),
					'blockTypes' => array( 'core/post-content' ),
					'postTypes'  => array( 'page' ),
					'content'    => $markup,
				)
			);
			foreach ( parse_blocks( $markup ) as $i => $block ) {
				$name = $block['attrs']['metadata']['name'] ?? '';
				if ( ! $name || 'core/group' !== $block['blockName'] ) {
					continue;
				}
				register_block_pattern(
					'membuild-community/' . $slug . '-' . $i,
					array(
						'title'      => $title . ' · ' . $name,
						'categories' => array( 'membuild-community' ),
						'content'    => serialize_block( $block ),
					)
				);
			}
		}
	}

	public static function rest_contact( \WP_REST_Request $req ) {
		if ( '' !== trim( (string) $req['website'] ) ) {
			return array( 'ok' => true ); // the hidden field only robots fill in
		}
		$slow = Helpers::throttle( 'contact', is_user_logged_in() ? 5 : 20, HOUR_IN_SECONDS );
		if ( $slow ) {
			return $slow;
		}
		$name    = sanitize_text_field( (string) $req['name'] );
		$email   = sanitize_email( (string) $req['email'] );
		$message = sanitize_textarea_field( (string) $req['message'] );
		if ( ! $name || ! is_email( $email ) || mb_strlen( $message ) < 2 ) {
			return new \WP_Error( 'mbcom_contact', __( 'Please add your name, your email and a message.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		/* translators: 1: the site's name, 2: who wrote */
		$subject = sprintf( __( '[%1$s] A message from %2$s', 'membuild-community' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $name );
		$label   = trim( (string) preg_replace( '/[^\p{L}\p{N} .\'-]/u', '', $name ) ); // the Reply-To line: a plain name, nothing an email program could read as another address
		$sent    = wp_mail( get_option( 'admin_email' ), $subject, $message . "\n\n— " . $name . ' <' . $email . '>', array( 'Reply-To: ' . ( $label ? $label . ' ' : '' ) . '<' . $email . '>' ) );
		return $sent ? array( 'ok' => true ) : new \WP_Error( 'mbcom_contact', __( 'That didn’t send. Please try again.', 'membuild-community' ), array( 'status' => 500 ) );
	}

	/* ---------- Make and undo ---------- */

	private static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function rest_state() {
		$s     = self::state();
		$pages = array();
		foreach ( self::pages() as $slug => list( $title ) ) {
			$id      = (int) ( $s['pages'][ $slug ] ?? 0 );
			$post    = $id ? get_post( $id ) : null;
			$pages[] = array(
				'slug'  => $slug,
				'title' => $title,
				'id'    => $post && 'trash' !== $post->post_status ? $id : 0,
				'edit'  => $post ? get_edit_post_link( $id, 'raw' ) : '',
				'view'  => $post ? get_permalink( $id ) : '',
				'have'  => $post ? '' : self::have( $slug, $title ),
			);
		}
		return array(
			'pages'      => $pages,
			'made'       => ! empty( $s['pages'] ),
			'front'      => ! empty( $s['front'] ),
			'parts'      => ! empty( $s['parts'] ),
			'blockTheme' => wp_is_block_theme(),
			'site'       => home_url( '/' ),
		);
	}

	/** The address of a live page the site already has for this one (same address or name), or ''. */
	private static function have( $slug, $title ) {
		if ( 'home' === $slug ) {
			return '';
		}
		$found = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'name' => $slug, 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( ! $found ) {
			global $wpdb;
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'page' AND post_status = 'publish' AND LOWER(post_title) = LOWER(%s) LIMIT 1", $wpdb->posts, $title ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $found ? (string) get_permalink( (int) $found[0] ) : '';
	}

	public static function rest_create( \WP_REST_Request $req ) {
		$s = self::state();
		if ( ! empty( $s['pages'] ) ) {
			return new \WP_Error( 'mbcom_starter', __( 'The starter pages are already made. Remove them first to make them again.', 'membuild-community' ), array( 'status' => 409 ) );
		}
		$want  = array_intersect( array_keys( self::pages() ), (array) ( $req['pages'] ?? array_keys( self::pages() ) ) );
		$state = array( 'pages' => array(), 'made_at' => time() );
		// Pages first (empty), so every page's links can point at the others.
		foreach ( $want as $slug ) {
			// A draft nobody published (WordPress makes a "Privacy Policy" draft on install) mustn't
			// push ours to /privacy-policy-2/: it moves to …-draft, and gets its address back on Remove.
			$draft = 'home' === $slug ? array() : get_posts( array( 'post_type' => 'page', 'post_status' => array( 'draft', 'pending', 'auto-draft' ), 'name' => $slug, 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( $draft ) {
				wp_update_post( array( 'ID' => (int) $draft[0], 'post_name' => wp_unique_post_slug( $slug . '-draft', (int) $draft[0], 'draft', 'page', 0 ) ) );
				$state['renamed'][ (int) $draft[0] ] = $slug;
			}
			$id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => self::pages()[ $slug ][0],
					'post_name'   => 'home' === $slug ? 'home' : $slug,
					'meta_input'  => array( '_mbcom_starter' => $slug ),
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$state['pages'][ $slug ] = (int) $id;
			}
		}
		update_option( self::OPTION, $state, false );
		foreach ( $state['pages'] as $slug => $id ) {
			wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( self::markup( self::pages()[ $slug ][1] ) ) ) );
			if ( wp_is_block_theme() ) {
				update_post_meta( $id, '_wp_page_template', 'mbcom-starter' );
			}
		}
		if ( ! empty( $state['pages']['privacy-policy'] ) ) {
			$state['privacy_was'] = (int) get_option( 'wp_page_for_privacy_policy' );
			update_option( 'wp_page_for_privacy_policy', $state['pages']['privacy-policy'] );
		}
		if ( ! empty( $req['front'] ) && ! empty( $state['pages']['home'] ) ) {
			$state['front'] = array( 'show_on_front' => get_option( 'show_on_front' ), 'page_on_front' => (int) get_option( 'page_on_front' ) );
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $state['pages']['home'] );
			// "Everyone opens the community from the home page" would send visitors past the new Home:
			// members still go straight in, visitors see the website. Put back on Remove.
			if ( 'everyone' === Settings::get( 'landing' ) ) {
				$state['landing_was'] = 'everyone';
				self::set_landing( 'members' );
			}
		}
		if ( ! empty( $req['parts'] ) && wp_is_block_theme() ) {
			$state['parts'] = self::put_parts( $state['pages'] );
		}
		update_option( self::OPTION, $state, false );
		return self::rest_state();
	}

	/**
	 * The matching header and footer: saved as this theme's own header and footer, the way
	 * the Site Editor saves them, so the whole site uses them and the owner edits them there.
	 * Whatever the owner had saved before is kept, to put back on Remove.
	 */
	private static function put_parts( array $pages ) {
		$theme = get_stylesheet();
		$done  = array();
		$links = array();
		foreach ( array( 'home', 'courses', 'pricing', 'about' ) as $slug ) {
			if ( ! empty( $pages[ $slug ] ) ) {
				$links[] = '<!-- wp:navigation-link {"label":"' . esc_attr( self::pages()[ $slug ][0] ) . '","type":"page","id":' . (int) $pages[ $slug ] . ',"url":"' . esc_url( get_permalink( $pages[ $slug ] ) ) . '","kind":"post-type"} /-->';
			}
		}
		$nav = wp_insert_post( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => __( 'Main website', 'membuild-community' ), 'post_content' => implode( '', $links ) ) );
		$done['navigation'] = is_wp_error( $nav ) ? 0 : (int) $nav;
		foreach ( array( 'header', 'footer' ) as $part ) {
			$markup = str_replace( '{{nav}}', (string) $done['navigation'], self::markup( $part ) );
			if ( ! $markup ) {
				continue;
			}
			$existing = get_posts( array( 'post_type' => 'wp_template_part', 'name' => $part, 'post_status' => 'any', 'numberposts' => 1, 'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $theme ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			if ( $existing ) {
				$done[ $part ] = array( 'id' => $existing[0]->ID, 'was' => $existing[0]->post_content );
				wp_update_post( array( 'ID' => $existing[0]->ID, 'post_content' => wp_slash( $markup ) ) );
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => $part, 'post_title' => ucfirst( $part ), 'post_content' => wp_slash( $markup ) ), true );
			if ( ! is_wp_error( $id ) ) {
				wp_set_object_terms( $id, $theme, 'wp_theme' );
				wp_set_object_terms( $id, $part, 'wp_template_part_area' );
				$done[ $part ] = array( 'id' => (int) $id, 'was' => null );
			}
		}
		return $done;
	}

	private static function set_landing( $mode ) {
		$all            = (array) get_option( Settings::OPTION, array() );
		$all['landing'] = $mode;
		update_option( Settings::OPTION, $all );
		Settings::flush();
	}

	/** Undo: the pages to the bin, the old front page, privacy page, header and footer back. */
	public static function rest_remove() {
		$s = self::state();
		foreach ( (array) ( $s['pages'] ?? array() ) as $id ) {
			if ( get_post( $id ) ) {
				wp_trash_post( $id );
			}
		}
		if ( ! empty( $s['front'] ) ) {
			update_option( 'show_on_front', $s['front']['show_on_front'] );
			update_option( 'page_on_front', $s['front']['page_on_front'] );
		}
		if ( ! empty( $s['landing_was'] ) ) {
			self::set_landing( $s['landing_was'] );
		}
		if ( isset( $s['privacy_was'] ) ) {
			update_option( 'wp_page_for_privacy_policy', (int) $s['privacy_was'] );
		}
		foreach ( (array) ( $s['renamed'] ?? array() ) as $draft_id => $slug ) {
			if ( get_post( (int) $draft_id ) ) {
				wp_update_post( array( 'ID' => (int) $draft_id, 'post_name' => $slug ) );
			}
		}
		foreach ( array( 'header', 'footer' ) as $part ) {
			$p = $s['parts'][ $part ] ?? null;
			if ( ! $p ) {
				continue;
			}
			if ( null === $p['was'] ) {
				wp_delete_post( $p['id'], true ); // ours: the theme's own header or footer shows again
			} else {
				wp_update_post( array( 'ID' => $p['id'], 'post_content' => wp_slash( $p['was'] ) ) );
			}
		}
		if ( ! empty( $s['parts']['navigation'] ) ) {
			wp_delete_post( (int) $s['parts']['navigation'], true );
		}
		delete_option( self::OPTION );
		return self::rest_state();
	}
}
