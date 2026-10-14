<?php
/**
 * Anonymous usage reports to MemBuild.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Once a week the plugin tells MemBuild it's still installed, so we know how
 * many communities run it and on which versions. That report holds only a
 * random ID made on this site, the plugin, WordPress and PHP versions, and
 * the site's language: no address, no names, no emails.
 *
 * When an admin ticks "Share anonymous usage" (asked once, on the Community
 * overview, and changeable in Settings → Advanced), the report also says what
 * the community is about, which features are on and roughly how big it is, in
 * ranges ("51–200 members"), never exact numbers. Post and message text, member
 * names and emails are never sent.
 *
 * Nothing is sent from local or development sites (wp_get_environment_type()),
 * or when the membuild_community/usage_reports filter returns false.
 *
 *   GET  /settings/usage          what's shared, the niche and this site's ID
 *   PUT  /settings/usage          {share, niche, niche_other, size}
 *   POST /settings/usage/forget   MemBuild deletes what it has for this site
 */
class Usage {

	const OPTION   = 'mbcom_usage';
	const HOOK     = 'mbcom_usage_report';
	const ENDPOINT = 'https://tools.membuild.com/api.php';

	/** What a community can be about, as asked on the overview. */
	public static function niches() {
		return array(
			'coaching'  => __( 'Coaching or mentoring', 'membuild-community' ),
			'courses'   => __( 'Online courses', 'membuild-community' ),
			'creator'   => __( 'Creator and fans', 'membuild-community' ),
			'fitness'   => __( 'Fitness and health', 'membuild-community' ),
			'business'  => __( 'Business or professional', 'membuild-community' ),
			'hobby'     => __( 'Hobbies and interests', 'membuild-community' ),
			'faith'     => __( 'Faith or spirituality', 'membuild-community' ),
			'nonprofit' => __( 'Nonprofit or cause', 'membuild-community' ),
			'local'     => __( 'Local group or club', 'membuild-community' ),
			'team'      => __( 'Company or internal team', 'membuild-community' ),
			'other'     => __( 'Something else', 'membuild-community' ),
		);
	}

	/** How many members the community expects. */
	public static function sizes() {
		return array(
			'start' => __( 'Just starting (under 50)', 'membuild-community' ),
			'small' => __( '50 to 500', 'membuild-community' ),
			'mid'   => __( '500 to 5,000', 'membuild-community' ),
			'large' => __( 'More than 5,000', 'membuild-community' ),
		);
	}

	public static function register_hooks() {
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
		add_action( self::HOOK . '_now', array( __CLASS__, 'send' ) );
		add_action( 'admin_post_mbcom_usage', array( __CLASS__, 'handle_setup' ) );
		add_action(
			'init',
			function () {
				if ( self::enabled() && ! wp_next_scheduled( self::HOOK ) ) {
					// The first report a few minutes after installing, then weekly.
					wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'weekly', self::HOOK );
				}
			}
		);
	}

	public static function register_routes() {
		$admin = fn() => is_user_logged_in() && Helpers::can_manage();
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/settings/usage',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get' ), 'permission_callback' => $admin ),
				array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route( MEMBUILD_COMMUNITY_REST_NS, '/settings/usage/forget', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_forget' ), 'permission_callback' => $admin ) );
	}

	/** Whether reports go out at all from this site. */
	public static function enabled() {
		/**
		 * Whether to send the weekly anonymous report to MemBuild.
		 *
		 * @param bool $enabled Default true, except on local and development sites.
		 */
		return (bool) apply_filters( 'membuild_community/usage_reports', ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );
	}

	/** The saved answers: id, share, niche, niche_other, size, asked (the overview question was answered or skipped). */
	public static function state() {
		$saved = get_option( self::OPTION, array() );
		$state = array_merge(
			array( 'id' => '', 'share' => false, 'niche' => '', 'niche_other' => '', 'size' => '', 'asked' => false, 'sent_at' => 0 ),
			is_array( $saved ) ? $saved : array()
		);
		if ( ! $state['id'] ) {
			$state['id'] = wp_generate_uuid4(); // random, made here; nothing about the site is in it
			update_option( self::OPTION, $state, false );
		}
		return $state;
	}

	/** Saves answers from the overview or Settings; anything not known is dropped. */
	public static function update( array $in ) {
		$state = self::state();
		if ( array_key_exists( 'share', $in ) ) {
			$state['share'] = (bool) $in['share'];
		}
		if ( array_key_exists( 'niche', $in ) ) {
			$state['niche'] = isset( self::niches()[ $in['niche'] ] ) ? (string) $in['niche'] : '';
		}
		if ( array_key_exists( 'niche_other', $in ) ) {
			$state['niche_other'] = 'other' === $state['niche'] ? mb_substr( sanitize_text_field( (string) $in['niche_other'] ), 0, 40 ) : '';
		}
		if ( array_key_exists( 'size', $in ) ) {
			$state['size'] = isset( self::sizes()[ $in['size'] ] ) ? (string) $in['size'] : '';
		}
		if ( ! empty( $in['asked'] ) ) {
			$state['asked'] = true;
		}
		update_option( self::OPTION, $state, false );
		return $state;
	}

	/** 0, "1-10", "11-50" … "5001+": enough to see the shape, never the exact number. */
	public static function range( $n ) {
		$n = (int) $n;
		foreach ( array( 10, 50, 200, 1000, 5000 ) as $i => $top ) {
			if ( $n <= $top ) {
				return 0 === $n ? '0' : ( array( 1, 11, 51, 201, 1001 )[ $i ] ) . '-' . $top;
			}
		}
		return '5001+';
	}

	/** Exactly what the next report will send. Shown in Settings, too. */
	public static function report() {
		global $wp_version;
		$state  = self::state();
		$report = array(
			'install_id' => $state['id'],
			'plugin'     => MEMBUILD_COMMUNITY_VERSION,
			'wp'         => $wp_version,
			'php'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'     => get_locale(),
			'multisite'  => is_multisite(),
			'shared'     => (bool) $state['share'],
		);
		if ( ! $state['share'] ) {
			return $report;
		}
		global $wpdb;
		$posts    = Install::table( 'posts' );
		$settings = Settings::all();
		return $report + array(
			'niche'       => $state['niche'],
			'niche_other' => $state['niche_other'],
			'size'        => $state['size'],
			'features'    => array_map( 'boolval', $settings['features'] ),
			'members'     => self::range( Helpers::member_total() ),
			'active_week' => self::range( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_active >= %s', Install::table( 'member_stats' ), gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) ) ) ),
			'posts'       => self::range( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND type = 'post'", $posts ) ) ),
			'reels'       => self::range( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND type = 'reel'", $posts ) ) ),
			'courses'     => self::range( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'courses' ) ) ) ),
			'chats'       => self::range( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'threads' ) ) ) ),
			'video'       => Bunny::enabled() ? 'bunny' : 'local',
			'landing'     => $settings['landing'],
			'login_page'  => (bool) $settings['login_page'],
			'signup'      => Login::registration_open() ? 'open' : ( $settings['signup_url'] ? 'elsewhere' : 'closed' ),
		);
	}

	/** Sends the report (weekly, and right after the answers change). */
	public static function send() {
		if ( ! self::enabled() ) {
			return false;
		}
		$response = wp_remote_post(
			self::ENDPOINT . '?action=community_report',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json', 'User-Agent' => 'MemBuild-Community/' . MEMBUILD_COMMUNITY_VERSION ),
				'body'    => wp_json_encode( self::report() ),
			)
		);
		$ok = 200 === wp_remote_retrieve_response_code( $response );
		if ( $ok ) {
			$state            = self::state();
			$state['sent_at'] = time();
			update_option( self::OPTION, $state, false );
		}
		return $ok;
	}

	/** Sends soon, off the admin's request: after an answer changes. */
	private static function send_soon() {
		if ( self::enabled() ) {
			wp_schedule_single_event( time() + 30, self::HOOK . '_now' );
		}
	}

	/* ---------- The question on the Community overview ---------- */

	/** The card above the numbers, until it's answered or skipped. */
	public static function setup_card() {
		$state = self::state();
		if ( $state['asked'] || ! Helpers::can_manage() ) {
			return;
		}
		echo '<form class="mbcom-panel mbcom-setup" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mbcom_usage' );
		echo '<input type="hidden" name="action" value="mbcom_usage">';
		printf( '<h2>%s</h2><p class="mbcom-muted">%s</p>', esc_html__( 'Tell us about your community', 'membuild-community' ), esc_html__( 'Two quick questions. They help us build what communities like yours need next.', 'membuild-community' ) );
		echo '<div class="mbcom-setup-fields">';
		printf( '<label><span>%s</span><select name="niche" required><option value="">%s</option>', esc_html__( 'What is it about?', 'membuild-community' ), esc_html__( 'Choose one', 'membuild-community' ) );
		foreach ( self::niches() as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select></label>';
		printf( '<label class="mbcom-setup-other"><span>%s</span><input type="text" name="niche_other" maxlength="40"></label>', esc_html__( 'In a few words', 'membuild-community' ) );
		printf( '<label><span>%s</span><select name="size" required><option value="">%s</option>', esc_html__( 'How many members do you expect?', 'membuild-community' ), esc_html__( 'Choose one', 'membuild-community' ) );
		foreach ( self::sizes() as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select></label></div>';
		printf(
			'<label class="mbcom-setup-share"><input type="checkbox" name="share" value="1"> <span><strong>%s</strong> %s</span></label>',
			esc_html__( 'Share anonymous usage with MemBuild.', 'membuild-community' ),
			esc_html__( 'Once a week: your answers, which features are on and rough counts, like “51–200 members”. Never names, emails, posts or messages. You can turn it off in Settings → Advanced.', 'membuild-community' )
		);
		printf(
			'<p class="mbcom-setup-actions"><button class="button button-primary" name="do" value="save">%s</button> <button class="button-link" name="do" value="skip" formnovalidate>%s</button></p>',
			esc_html__( 'Save', 'membuild-community' ),
			esc_html__( 'Not now', 'membuild-community' )
		);
		echo '</form>';
	}

	public static function handle_setup() {
		if ( ! Helpers::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'membuild-community' ), 403 );
		}
		check_admin_referer( 'mbcom_usage' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$in = array( 'asked' => true );
		if ( 'save' === sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ) ) {
			$in += array(
				'niche'       => sanitize_key( wp_unslash( $_POST['niche'] ?? '' ) ),
				'niche_other' => sanitize_text_field( wp_unslash( $_POST['niche_other'] ?? '' ) ),
				'size'        => sanitize_key( wp_unslash( $_POST['size'] ?? '' ) ),
				'share'       => ! empty( $_POST['share'] ),
			);
		}
		// phpcs:enable
		self::update( $in );
		if ( ! empty( $in['share'] ) ) {
			self::send_soon();
		}
		wp_safe_redirect( Admin::url() );
		exit;
	}

	/* ---------- REST, for Settings → Advanced ---------- */

	public static function rest_get() {
		$state = self::state();
		return array(
			'share'       => (bool) $state['share'],
			'niche'       => $state['niche'],
			'niche_other' => $state['niche_other'],
			'size'        => $state['size'],
			'id'          => $state['id'],
			'enabled'     => self::enabled(),
			'sent_at'     => $state['sent_at'] ? gmdate( 'c', $state['sent_at'] ) : null,
			'report'      => self::report(),
			'niches'      => self::niches(),
			'sizes'       => self::sizes(),
		);
	}

	public static function rest_save( \WP_REST_Request $req ) {
		$before = self::state();
		$after  = self::update( ( $req->get_json_params() ?: array() ) + array( 'asked' => true ) );
		if ( $before['share'] !== $after['share'] || ( $after['share'] && ( $before['niche'] !== $after['niche'] || $before['size'] !== $after['size'] ) ) ) {
			self::send_soon(); // turning sharing off sends the short report, which clears the rest on our side
		}
		return self::rest_get();
	}

	/** Asks MemBuild to delete everything it has for this site's ID, then starts over with a new ID. */
	public static function rest_forget() {
		$state    = self::state();
		$response = wp_remote_post(
			self::ENDPOINT . '?action=community_forget',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'install_id' => $state['id'] ) ),
			)
		);
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'mbcom_forget_failed', __( 'MemBuild couldn’t be reached. Please try again in a few minutes.', 'membuild-community' ), array( 'status' => 502 ) );
		}
		$state['id']      = wp_generate_uuid4();
		$state['share']   = false;
		$state['sent_at'] = 0;
		update_option( self::OPTION, $state, false );
		return self::rest_get();
	}
}
