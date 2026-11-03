<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Starter pages: the rest of the website (Home, Pricing, About, Courses, Events, Blog,
 * Community guidelines, Contact & help, Welcome, and the legal pages), in the community's look.
 * On a block theme the Blog also brings layouts for the posts list and each post, and there's a
 * page-not-found layout (see layouts()).
 *
 * Everything is WordPress blocks, so owners edit any word or photo in the block
 * editor with no page builder. The pages live in patterns/*.html; each top-level
 * section with a name is also a pattern ("MemBuild Community" in the inserter), so
 * it can go on any page. Live blocks show the community's own content (Starter_Blocks):
 * courses, events, member faces, plans (prices from FluentCart), a contact form…
 *
 * Nothing is made until the owner presses "Create my starter pages" in Settings
 * (WordPress.org's rule), and Remove undoes it: the pages go to the bin, and the
 * old front page, posts page, header, footer and layouts come back. Pages added in an
 * update can be made later, on their own, without touching the ones already made.
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
			'events'               => array( __( 'Events', 'membuild-community' ), 'events' ),
			'blog'                 => array( __( 'Blog', 'membuild-community' ), 'blog' ),
			'community-guidelines' => array( __( 'Community guidelines', 'membuild-community' ), 'guidelines' ),
			'contact'              => array( __( 'Contact & help', 'membuild-community' ), 'contact' ),
			'welcome'              => array( __( 'Welcome', 'membuild-community' ), 'welcome' ),
			'coming-soon'          => array( __( 'Coming soon', 'membuild-community' ), 'coming-soon' ),
			'privacy-policy'       => array( __( 'Privacy policy', 'membuild-community' ), 'privacy' ),
			'terms'                => array( __( 'Terms of membership', 'membuild-community' ), 'terms' ),
			'refunds'              => array( __( 'Refunds & cancelling', 'membuild-community' ), 'refunds' ),
		);
	}

	/**
	 * The layouts (block-theme templates) made with them: slug => [ name, file, made with ].
	 * The Blog brings the posts list and the single post; "404" is its own choice in Settings.
	 */
	public static function layouts() {
		return array(
			'home'   => array( __( 'Blog: the list of posts', 'membuild-community' ), 'blog-home', 'blog' ),
			'single' => array( __( 'Blog post', 'membuild-community' ), 'blog-single', 'blog' ),
			'404'    => array( __( 'Page not found', 'membuild-community' ), '404', '404' ),
		);
	}

	/** Everything Settings lists: the pages, then the page-not-found layout (block themes only). */
	private static function items() {
		return array_merge( array_keys( self::pages() ), wp_is_block_theme() ? array( '404' ) : array() );
	}

	public static function register_hooks() {
		add_action( 'init', array( Starter_Blocks::class, 'register' ) );
		// The patterns are only for the editor: not built on every visit to the site, nor on every
		// app request (the 15-second Messages check would read all the page files each time).
		add_action( 'admin_init', array( __CLASS__, 'register_patterns' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'patterns_for_editor' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'register_template' ) );
		// WordPress lists a plugin's templates by name, but its editor reads the first as item 0 (block-editor.php),
		// so editing a starter page printed a warning above the editor. A plain numbered list fixes that.
		add_filter( 'get_block_templates', 'array_values', 99 );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_style' ) );
		add_action( 'wp_head', array( __CLASS__, 'header_glass' ), 1 );
		add_filter( 'render_block_context', array( __CLASS__, 'more_posts' ), 10, 3 );
		add_filter( 'block_editor_settings_all', array( __CLASS__, 'editor_settings' ), 10, 2 );
	}

	/**
	 * A starter page opens in the editor inside its layout (header, page, footer), as it looks on
	 * the site: no page title above the hero that the site never shows.
	 */
	public static function editor_settings( $settings, $context ) {
		$post = $context->post ?? null;
		if ( $post && 'page' === $post->post_type && get_post_meta( $post->ID, '_mbcom_starter', true ) && wp_is_block_theme() ) {
			$settings['defaultRenderingMode'] = 'template-locked';
		}
		return $settings;
	}

	/** "More posts" under a post (the Blog post layout) never shows the post you're reading. */
	public static function more_posts( $context, $parsed, $parent = null ) {
		if ( $parent instanceof \WP_Block && 'core/query' === $parent->name && false !== strpos( (string) ( $parent->attributes['className'] ?? '' ), 'mbs-more-posts' ) && is_singular() && isset( $context['query'] ) ) {
			$context['query']['exclude'] = array_merge( (array) ( $context['query']['exclude'] ?? array() ), array( (int) get_queried_object_id() ) );
		}
		return $context;
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
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/starter-pages/look', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_look' ), 'permission_callback' => $admin ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/starter-pages/parts', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_parts' ), 'permission_callback' => $admin ) );
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/contact', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_contact' ), 'permission_callback' => '__return_true' ) );
	}

	/* ---------- Look ---------- */

	/** The pages' own styles, on the site and in the editor, so both look the same. */
	public static function enqueue_style() {
		wp_enqueue_style( self::STYLE, MEMBUILD_COMMUNITY_URL . 'assets/starter/starter.css', array(), MEMBUILD_COMMUNITY_VERSION );
		wp_add_inline_style( self::STYLE, Starter_Site::accent_css() ); // the owner's main colour, with readable words on it
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

	/**
	 * What the owner said about the community when the pages were made (Settings' three
	 * questions), or neutral words for any paid community when they skipped them.
	 */
	private static $answers = array();

	/**
	 * {{name}} => what it is on this site. Links are short ("/community/join") so they keep
	 * working when the site moves to another address; a paid plan is a marker (#mbcom-plan-3) that
	 * gets its checkout and current name when the page is shown (Starter_Site).
	 */
	private static function tokens() {
		$made  = self::state()['pages'] ?? array();
		// A page that wasn't made: its usual address, or for Events the community's own list.
		$page  = fn( $slug ) => ! empty( $made[ $slug ] ) && get_post( $made[ $slug ] ) ? get_permalink( $made[ $slug ] ) : ( 'events' === $slug ? Portal::url() . 'events' : home_url( '/' . $slug . '/' ) );
		$rel   = array( Starter_Site::class, 'rel' );
		$host  = Login::host();
		$photo = $host['photo'];
		if ( '' === $photo ) {
			$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
			$id    = (int) Settings::get( 'login_host' ) ?: (int) ( $admin[0] ?? 0 );
			$photo = $id ? (string) get_avatar_url( $id, array( 'size' => 800 ) ) : '';
		}
		$paid  = Starter_Blocks::paid_plans()[0] ?? null;
		$say   = self::$answers;
		$who   = trim( (string) ( $say['who'] ?? '' ), " .\t" );
		$gets  = trim( (string) ( $say['gets'] ?? '' ) );
		$topic = trim( (string) ( $say['topic'] ?? '' ), " .\t" );
		$out   = array(
			'{{site}}'       => esc_html( Settings::name() ),
			'{{tagline}}'    => esc_html( get_bloginfo( 'description' ) ),
			'{{home}}'       => esc_url( $rel( home_url( '/' ) ) ),
			'{{community}}'  => esc_url( $rel( Portal::url() ) ),
			'{{classroom}}'  => esc_url( $rel( Portal::url() . 'classroom' ) ),
			'{{events}}'     => esc_url( $rel( Portal::url() . 'events' ) ),
			'{{profile_edit}}' => esc_url( $rel( Portal::url() . 'me/edit' ) ),
			'{{join}}'       => esc_url( $rel( Login::url( 'join' ) ) ),
			'{{login}}'      => esc_url( $rel( Login::url( 'login' ) ) ),
			'{{host_name}}'  => esc_html( $host['name'] ?: __( 'Your name', 'membuild-community' ) ),
			'{{host_photo}}' => esc_url( $photo ?: MEMBUILD_COMMUNITY_URL . 'assets/starter/face.svg' ),
			'{{picture}}'    => esc_url( MEMBUILD_COMMUNITY_URL . 'assets/starter/picture.svg' ), // "Add your picture"
			'{{face}}'       => esc_url( MEMBUILD_COMMUNITY_URL . 'assets/starter/face.svg' ), // a placeholder face
			'{{email}}'      => esc_html( Starter_Site::support_email() ?: __( 'your support email', 'membuild-community' ) ),
			'{{date}}'       => esc_html( wp_date( get_option( 'date_format' ) ) ),
			'{{course_count}}' => esc_html( (string) self::course_count() ),
			'{{initials}}'   => esc_html( self::initials() ),
			'{{about}}'      => esc_html( self::about() ),
			'{{year}}'       => esc_html( wp_date( 'Y' ) ),
			/* translators: %s: a membership's name, e.g. "VIP" */
			'{{paid_cta}}'   => esc_html( $paid ? sprintf( __( 'Become a %s', 'membuild-community' ), $paid[0] ) : __( 'See the courses', 'membuild-community' ) ),
			'{{paid_url}}'   => esc_url( $paid ? '#mbcom-plan-' . (int) $paid[4] : $rel( $page( 'courses' ) ) ),
			'{{paid_name}}'  => esc_html( $paid ? $paid[0] : __( 'members', 'membuild-community' ) ),
			// The three questions: what it's about, who it's for, what members get.
			'{{topic}}'      => esc_html( $topic ?: __( '[your topic]', 'membuild-community' ) ),
			'{{who}}'        => esc_html( $who ? mb_strtolower( mb_substr( $who, 0, 1 ) ) . mb_substr( $who, 1 ) : __( 'anyone who wants to get better at [your topic]', 'membuild-community' ) ),
			'{{gets}}'       => esc_html( $gets ? rtrim( $gets, '.' ) . '.' : __( 'Courses, live calls and friendly people who’ve done it.', 'membuild-community' ) ),
			// Home's headline: their topic, or neutral words for any paid community.
			'{{hero_title}}' => $topic
				/* translators: %s: what the community is about */
				? sprintf( esc_html__( '%s, together.', 'membuild-community' ), esc_html( mb_strtoupper( mb_substr( $topic, 0, 1 ) ) . mb_substr( $topic, 1 ) ) )
				: esc_html__( 'Build a community people', 'membuild-community' ) . ' <mark class="mbs-hl">' . esc_html__( 'stay in.', 'membuild-community' ) . '</mark>',
		);
		foreach ( (array) glob( MEMBUILD_COMMUNITY_DIR . 'assets/starter/icons/*.svg' ) as $icon ) {
			$out[ '{{icon:' . basename( $icon, '.svg' ) . '}}' ] = esc_url( MEMBUILD_COMMUNITY_URL . 'assets/starter/icons/' . basename( $icon ) );
		}
		foreach ( array_keys( self::pages() ) as $slug ) {
			$out[ '{{page:' . $slug . '}}' ] = esc_url( $rel( 'home' === $slug ? home_url( '/' ) : $page( $slug ) ) );
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

	/** The editor asks for patterns and their groups at /wp/v2/block-patterns/…: only then are ours made. */
	public static function patterns_for_editor( $result, $server, $request ) {
		if ( str_starts_with( (string) $request->get_route(), '/wp/v2/block-patterns/' ) ) {
			self::register_patterns();
		}
		return $result;
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
		register_block_pattern_category( 'membuild-community-pages', array( 'label' => __( 'MemBuild Community: whole pages', 'membuild-community' ) ) );
		// Sections are also where owners already look; most themes have no Pricing or FAQ group.
		$registry = \WP_Block_Pattern_Categories_Registry::get_instance();
		foreach ( array( 'pricing' => __( 'Pricing', 'membuild-community' ), 'faq' => __( 'FAQ', 'membuild-community' ) ) as $slug => $label ) {
			if ( ! $registry->is_registered( $slug ) ) {
				register_block_pattern_category( $slug, array( 'label' => $label ) );
			}
		}
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
					'categories' => array( 'membuild-community-pages' ),
					'blockTypes' => array( 'core/post-content' ),
					'postTypes'  => array( 'page' ),
					'content'    => $markup,
				)
			);
			self::section_patterns( $slug, $title, $markup );
		}
		// The layouts' own sections (a post's title, More posts…), for the Site Editor. The posts
		// list has the Blog page's sections, so only the post and page-not-found ones are new.
		foreach ( array( 'single', '404' ) as $slug ) {
			list( $title, $file ) = self::layouts()[ $slug ];
			foreach ( parse_blocks( self::markup( $file ) ) as $block ) {
				if ( 'core/group' === $block['blockName'] && 'main' === ( $block['attrs']['tagName'] ?? '' ) ) {
					self::section_patterns( $slug, $title, serialize_blocks( $block['innerBlocks'] ), array( 'wp_template' ) );
				}
			}
		}
	}

	/** The core pattern group a section also belongs in, from its name. */
	private static function core_category( $name ) {
		$map = array(
			'Hero'                    => 'banner',
			'Members say'             => 'testimonials',
			'Closing call to action'  => 'call-to-action',
			'Already a member'        => 'call-to-action',
			'Need help'               => 'call-to-action',
			'Pricing'                 => 'pricing',
			'Plans'                   => 'pricing',
			'Compare'                 => 'pricing',
			'Promises'                => 'pricing',
			'Questions'               => 'faq',
			'Questions and the form'  => 'contact',
			'The team'                => 'team',
			'My story'                => 'about',
		);
		return isset( $map[ $name ] ) ? array( $map[ $name ] ) : array();
	}

	/** Each named section of a page as its own pattern: "Events · Hero". */
	private static function section_patterns( $slug, $title, $markup, $post_types = null ) {
		foreach ( parse_blocks( $markup ) as $i => $block ) {
			$name = $block['attrs']['metadata']['name'] ?? '';
			if ( ! $name || 'core/group' !== $block['blockName'] ) {
				continue;
			}
			register_block_pattern(
				'membuild-community/' . $slug . '-' . $i,
				array(
					'title'      => $title . ' · ' . $name,
					'categories' => array_merge( array( 'membuild-community' ), self::core_category( $name ) ),
					'content'    => serialize_block( $block ),
				) + ( $post_types ? array( 'postTypes' => $post_types ) : array() )
			);
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
		$sent    = wp_mail( Starter_Site::support_email() ?: get_option( 'admin_email' ), $subject, $message . "\n\n— " . $name . ' <' . $email . '>', array( 'Reply-To: ' . ( $label ? $label . ' ' : '' ) . '<' . $email . '>' ) );
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
				'kind'  => 'page',
				'id'    => $post && 'trash' !== $post->post_status ? $id : 0,
				'edit'  => $post ? get_edit_post_link( $id, 'raw' ) : '',
				'view'  => $post ? get_permalink( $id ) : '',
				'have'  => $post ? '' : self::have( $slug, $title ),
				'note'  => 'blog' === $slug ? self::blog_note( $s ) : '',
			);
		}
		if ( wp_is_block_theme() ) {
			$id      = (int) ( $s['templates']['404'] ?? 0 );
			$id      = $id && get_post( $id ) ? $id : 0;
			$pages[] = array(
				'slug'  => '404',
				'title' => self::layouts()['404'][0],
				'kind'  => 'layout',
				'id'    => $id,
				'edit'  => $id ? self::site_editor( '404' ) : '',
				'view'  => $id ? home_url( '/this-page-does-not-exist/' ) : '',
				'have'  => $id ? '' : ( self::own_template( '404' ) ? self::site_editor( '404' ) : '' ),
				'note'  => __( 'Shown when a link is broken. A layout, not a page.', 'membuild-community' ),
			);
		}
		$logo = (int) get_option( 'site_logo' ) ?: (int) get_theme_mod( 'custom_logo' );
		return array(
			'pages'      => $pages,
			'made'       => ! empty( $s['pages'] ) || ! empty( $s['templates'] ),
			'front'      => ! empty( $s['front'] ),
			'parts'      => ! empty( $s['parts'] ),
			'blockTheme' => wp_is_block_theme(),
			'site'       => home_url( '/' ),
			// The look: the main colour (Settings › General), the logo, and the money-back promise.
			'accent'     => (string) Settings::get( 'accent' ),
			'accents'    => Settings::ACCENTS,
			'logo'       => $logo ? (string) wp_get_attachment_image_url( $logo, 'medium' ) : ( Settings::get( 'logo' )['url'] ?? '' ),
			'logoName'   => (string) ( $s['logo_name'] ?? 'auto' ),
			'logoWide'   => Starter_Site::logo_has_name(),
			'refundDays' => Starter_Site::refund_days(),
			// Changed the theme since: the header and footer were saved for the old one.
			'partsGone'  => ! empty( $s['parts'] ) && wp_is_block_theme() && ( $s['parts_theme'] ?? get_stylesheet() ) !== get_stylesheet(),
		);
	}

	/** Settings › Main website › Look: the main colour, "My logo already shows the name", the money-back days. */
	public static function rest_look( \WP_REST_Request $req ) {
		$s = self::state();
		if ( isset( $req['accent'] ) ) {
			self::set_accent( (string) $req['accent'] );
		}
		if ( isset( $req['logoName'] ) && in_array( $req['logoName'], array( 'auto', 'yes', 'no' ), true ) ) {
			$s['logo_name'] = (string) $req['logoName'];
		}
		if ( isset( $req['refundDays'] ) ) {
			$s['refund_days'] = max( 0, min( 90, (int) $req['refundDays'] ) );
		}
		update_option( self::OPTION, $s, false );
		return self::rest_state();
	}

	/** "Put the header and footer back": after a theme change, saved again for this theme. */
	public static function rest_parts() {
		$s = self::state();
		if ( empty( $s['pages'] ) || ! wp_is_block_theme() ) {
			return new \WP_Error( 'mbcom_starter', __( 'Make the starter pages first, on a block theme.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		$old = (array) ( $s['parts'] ?? array() );
		if ( ! empty( $old['navigation'] ) && get_post( (int) $old['navigation'] ) ) {
			wp_delete_post( (int) $old['navigation'], true ); // made again with the header
		}
		$s['parts']       = self::put_parts( $s['pages'] );
		$s['parts_theme'] = get_stylesheet();
		// The old theme's header and footer stay as they were saved, for switching back.
		update_option( self::OPTION, $s, false );
		return self::rest_state();
	}

	/** What Settings says under Blog: what comes with it, or what was kept. */
	private static function blog_note( array $s ) {
		if ( ! empty( $s['pages']['blog'] ) ) {
			$kept = array_diff( array( 'home', 'single' ), array_keys( (array) ( $s['templates'] ?? array() ) ) );
			if ( wp_is_block_theme() && $kept ) {
				return __( 'Your own post layouts were kept.', 'membuild-community' );
			}
			return empty( $s['posts_page'] ) ? __( 'Your posts page stays as it was.', 'membuild-community' ) : '';
		}
		return wp_is_block_theme() ? __( 'Your latest posts, with a layout for each post.', 'membuild-community' ) : __( 'Your latest posts.', 'membuild-community' );
	}

	/** Where the Site Editor opens one of this theme's layouts. */
	private static function site_editor( $slug ) {
		return admin_url( 'site-editor.php?p=' . rawurlencode( '/wp_template/' . get_stylesheet() . '//' . $slug ) . '&canvas=edit' );
	}

	/** This theme's saved layout with that name (the owner's, or ours), or null. */
	private static function own_template( $slug ) {
		$found = get_posts( array( 'post_type' => 'wp_template', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => get_stylesheet() ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		return $found ? $found[0] : null;
	}

	/** The address of a live page the site already has for this one (same address or name), or ''. */
	private static function have( $slug, $title ) {
		if ( 'home' === $slug ) {
			return '';
		}
		if ( 'blog' === $slug && (int) get_option( 'page_for_posts' ) && 'publish' === get_post_status( (int) get_option( 'page_for_posts' ) ) ) {
			return (string) get_permalink( (int) get_option( 'page_for_posts' ) ); // the site's posts page
		}
		$found = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'name' => $slug, 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( ! $found ) {
			global $wpdb;
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'page' AND post_status = 'publish' AND LOWER(post_title) = LOWER(%s) LIMIT 1", $wpdb->posts, $title ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $found ? (string) get_permalink( (int) $found[0] ) : '';
	}

	/**
	 * Make the picked pages. Once they're made, only the ones not made yet (new in an update, like
	 * Events and Blog) can be added; nothing is ever made twice, and the made pages aren't touched.
	 */
	public static function rest_create( \WP_REST_Request $req ) {
		$s      = self::state();
		$adding = ! empty( $s['pages'] ) || ! empty( $s['templates'] );
		$done   = array_merge( array_keys( (array) ( $s['pages'] ?? array() ) ), empty( $s['templates']['404'] ) ? array() : array( '404' ) );
		$asked  = (array) ( $req['pages'] ?? self::items() );
		$todo   = array_values( array_diff( array_intersect( self::items(), $asked ), $done ) );
		if ( $adding && ! $todo ) {
			return new \WP_Error( 'mbcom_starter', __( 'The starter pages are already made. Remove them first to make them again.', 'membuild-community' ), array( 'status' => 409 ) );
		}
		$want  = array_values( array_intersect( array_keys( self::pages() ), $todo ) );
		$state = $adding ? $s : array( 'pages' => array(), 'made_at' => time() );
		// The three questions (what it's about, who it's for, what members get) go into the words;
		// skipped, the pages say neutral words for any paid community. Kept for pages added later.
		if ( ! $adding ) {
			$state['answers'] = array_filter( array_map( fn( $k ) => mb_substr( sanitize_text_field( (string) ( $req['answers'][ $k ] ?? '' ) ), 0, 120 ), array( 'topic' => 'topic', 'who' => 'who', 'gets' => 'gets' ) ), 'strlen' );
			if ( isset( $req['accent'] ) ) {
				self::set_accent( (string) $req['accent'] );
			}
			if ( isset( $req['logoName'] ) && in_array( $req['logoName'], array( 'auto', 'yes', 'no' ), true ) ) {
				$state['logo_name'] = (string) $req['logoName'];
			}
			// The header shows the Site Logo: the community's logo, when the site has none yet.
			$logo = (int) ( Settings::get( 'logo' )['id'] ?? 0 );
			if ( $logo && ! (int) get_option( 'site_logo' ) && ! (int) get_theme_mod( 'custom_logo' ) && wp_attachment_is_image( $logo ) ) {
				update_option( 'site_logo', $logo );
				$state['site_logo_set'] = $logo; // taken away again on Remove
			}
		}
		self::$answers = (array) ( $state['answers'] ?? array() );
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
		$new = array_intersect_key( $state['pages'], array_flip( $want ) ); // only the pages made just now
		foreach ( $new as $slug => $id ) {
			wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( self::markup( self::pages()[ $slug ][1] ) ) ) );
			if ( wp_is_block_theme() ) {
				update_post_meta( $id, '_wp_page_template', 'mbcom-starter' );
			}
		}
		// The Blog lists the posts, if the site has no posts page yet (Settings › Reading). An owner's
		// own choice stays; Remove puts it back.
		if ( ! empty( $new['blog'] ) && ! (int) get_option( 'page_for_posts' ) ) {
			update_option( 'page_for_posts', $new['blog'] );
			$state['posts_page'] = $new['blog'];
		}
		if ( wp_is_block_theme() ) {
			$layouts = array_keys( array_filter( self::layouts(), fn( $l ) => ( 'blog' === $l[2] && ! empty( $new['blog'] ) ) || ( '404' === $l[2] && in_array( '404', $todo, true ) ) ) );
			$state['templates'] = (array) ( $state['templates'] ?? array() ) + self::put_templates( $layouts );
		}
		if ( $adding ) {
			if ( ! empty( $state['parts']['navigation'] ) ) {
				self::add_to_menu( (int) $state['parts']['navigation'], $state['pages'], array_keys( $new ) );
			}
			update_option( self::OPTION, $state, false );
			return self::rest_state();
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
			$state['parts']       = self::put_parts( $state['pages'] );
			$state['parts_theme'] = get_stylesheet();
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
		foreach ( self::MENU as $slug ) {
			if ( ! empty( $pages[ $slug ] ) ) {
				$links[] = self::menu_link( $slug, (int) $pages[ $slug ] );
			}
		}
		// The phone menu also has Contact & help, and Join free and Log in at the bottom where thumbs are.
		if ( ! empty( $pages['contact'] ) ) {
			$links[] = str_replace( '"kind":"post-type"', '"kind":"post-type","className":"mbs-nav-phone"', self::menu_link( 'contact', (int) $pages['contact'] ) );
		}
		$links[] = '<!-- wp:buttons {"className":"mbs-nav-cta","layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-buttons mbs-nav-cta"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( Starter_Site::rel( Login::url( 'join' ) ) ) . '">' . esc_html__( 'Join free →', 'membuild-community' ) . '</a></div><!-- /wp:button --><!-- wp:button {"className":"mbs-btn-light"} --><div class="wp-block-button mbs-btn-light"><a class="wp-block-button__link wp-element-button" href="' . esc_url( Starter_Site::rel( Login::url( 'login' ) ) ) . '">' . esc_html__( 'Log in', 'membuild-community' ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
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

	/** The header menu's pages, in order. */
	const MENU = array( 'home', 'courses', 'events', 'blog', 'pricing', 'about' );

	private static function menu_link( $slug, $id ) {
		return '<!-- wp:navigation-link {"label":"' . esc_attr( self::pages()[ $slug ][0] ) . '","type":"page","id":' . (int) $id . ',"url":"' . esc_url( get_permalink( $id ) ) . '","kind":"post-type"} /-->';
	}

	/** Pages added later (Events, Blog) join the menu made with the others, in their place: after Courses. */
	private static function add_to_menu( $nav_id, array $pages, array $added ) {
		$nav = get_post( $nav_id );
		if ( ! $nav || 'wp_navigation' !== $nav->post_type ) {
			return;
		}
		$content = (string) $nav->post_content;
		foreach ( array_intersect( self::MENU, $added ) as $slug ) {
			$link = self::menu_link( $slug, (int) $pages[ $slug ] );
			$at   = false;
			// After the nearest page before it in the menu that's in there (Courses, then Home).
			foreach ( array_reverse( array_slice( self::MENU, 0, (int) array_search( $slug, self::MENU, true ) ) ) as $before ) {
				$pos = empty( $pages[ $before ] ) ? false : strpos( $content, '"id":' . (int) $pages[ $before ] . ',' );
				if ( false !== $pos && false !== strpos( $content, '/-->', $pos ) ) {
					$at = strpos( $content, '/-->', $pos ) + 4;
					break;
				}
			}
			$content = false === $at ? $content . $link : substr( $content, 0, $at ) . $link . substr( $content, $at );
		}
		wp_update_post( array( 'ID' => $nav_id, 'post_content' => wp_slash( $content ) ) );
	}

	/**
	 * The Blog's and page-not-found layouts, saved as this theme's own templates the way the
	 * Site Editor saves them. A layout the owner already saved is never replaced: it's skipped.
	 *
	 * @return array slug => the template's ID, for the ones made.
	 */
	private static function put_templates( array $slugs ) {
		$made  = array();
		$types = function_exists( 'get_default_block_template_types' ) ? get_default_block_template_types() : array();
		foreach ( $slugs as $slug ) {
			$markup = self::markup( self::layouts()[ $slug ][1] );
			if ( ! $markup || self::own_template( $slug ) ) {
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => (string) $slug, 'post_title' => $types[ $slug ]['title'] ?? self::layouts()[ $slug ][0], 'post_content' => wp_slash( $markup ) ), true );
			if ( ! is_wp_error( $id ) ) {
				wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
				$made[ (string) $slug ] = (int) $id;
			}
		}
		return $made;
	}

	/** The main colour, the same setting as Settings › General (an empty one is the first swatch). */
	private static function set_accent( $hex ) {
		$hex = strtolower( trim( $hex ) );
		if ( '' !== $hex && ! Color::valid( $hex ) ) {
			return;
		}
		$all           = (array) get_option( Settings::OPTION, array() );
		$all['accent'] = $hex;
		update_option( Settings::OPTION, $all );
		Settings::flush();
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
		foreach ( (array) ( $s['templates'] ?? array() ) as $id ) {
			if ( $id && 'wp_template' === get_post_type( (int) $id ) ) {
				wp_delete_post( (int) $id, true ); // ours: the theme's own layout shows again
			}
		}
		if ( ! empty( $s['posts_page'] ) && (int) get_option( 'page_for_posts' ) === (int) $s['posts_page'] ) {
			update_option( 'page_for_posts', 0 ); // there was none before
		}
		if ( ! empty( $s['site_logo_set'] ) && (int) get_option( 'site_logo' ) === (int) $s['site_logo_set'] ) {
			delete_option( 'site_logo' ); // the site had none before
		}
		if ( ! empty( $s['pages']['coming-soon'] ) ) {
			update_option( Coming_Soon::OPTION, array_merge( Coming_Soon::settings(), array( 'on' => false ) ), false ); // its page is gone
		}
		delete_option( self::OPTION );
		return self::rest_state();
	}
}
