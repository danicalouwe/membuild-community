<?php
/**
 * Anonymous usage reports to MemBuild, and the "Keep me updated" subscription.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Once a week the plugin tells MemBuild it's still installed, so we know how
 * many communities run it and on which versions. That report holds only a
 * random ID made on this site, the plugin, WordPress and PHP versions, and
 * the site's language: no address, no names, no emails.
 *
 * When an admin ticks "Share usage" (on by default on the Community overview's
 * card, and changeable in Settings → Updates & usage), the report also says what the
 * community is about, which features are on and roughly how big it is, in
 * ranges ("51–200 members"), never exact numbers, plus the signatures of
 * errors caught in our own code (see Problems: file, line, scrubbed message,
 * count). Post and message text, member names and emails are never sent.
 *
 * "Keep me updated" is separate and explicit: the admin gives an email address
 * to hear about new features. That sends the email, the site's address and
 * name, and the versions, to the same server; while sharing is on, it also
 * sends the community's exact counts (members, posts, courses, events, which
 * features are on), refreshed with each weekly report. "Stop" in Settings →
 * Updates & usage removes it from our server. The overview asks once, and once more
 * two weeks after "Not now"; after that only Settings offers it.
 *
 * Nothing anonymous is sent from local or development sites
 * (wp_get_environment_type()); the subscription, being the admin's own ask,
 * goes out from any site. The membuild_community/usage_reports filter stops
 * both when it returns false.
 *
 *   GET    /settings/usage          what's shared, the niche, the subscription and this site's ID
 *   PUT    /settings/usage          {share, niche, niche_other, size}
 *   POST   /settings/usage/forget   MemBuild deletes what it has for this site
 *   POST   /settings/updates        {email}: keep me updated
 *   DELETE /settings/updates        stop
 */
class Usage {

	const OPTION   = 'mbcom_usage';
	const HOOK     = 'mbcom_usage_report';
	const ENDPOINT = 'https://tools.membuild.com/api.php';

	/** After "Not now", the overview card comes back once, this much later. */
	const RETURN_AFTER = 14 * DAY_IN_SECONDS;

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
		register_rest_route(
			MEMBUILD_COMMUNITY_REST_NS,
			'/settings/updates',
			array(
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_subscribe' ), 'permission_callback' => $admin ),
				array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_unsubscribe' ), 'permission_callback' => $admin ),
			)
		);
	}

	/** Whether the anonymous weekly report goes out from this site. */
	public static function enabled() {
		/**
		 * Whether to send the weekly anonymous report to MemBuild.
		 *
		 * @param bool $enabled Default true, except on local and development sites.
		 */
		return (bool) apply_filters( 'membuild_community/usage_reports', ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );
	}

	/** Whether "Keep me updated" can reach MemBuild: the admin asked, so only the filter can say no. */
	public static function allowed() {
		return (bool) apply_filters( 'membuild_community/usage_reports', true );
	}

	/**
	 * The saved answers: id, share, niche, niche_other, size, asked (the overview
	 * card was answered or skipped), email, subscribed, subscribed_at, skips and
	 * skipped_at (how often "Not now" was pressed, and when last).
	 */
	public static function state() {
		$saved = get_option( self::OPTION, array() );
		$state = array_merge(
			array( 'id' => '', 'share' => false, 'niche' => '', 'niche_other' => '', 'size' => '', 'asked' => false, 'sent_at' => 0, 'email' => '', 'subscribed' => false, 'subscribed_at' => 0, 'skips' => 0, 'skipped_at' => 0 ),
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

	/** Exactly what the next weekly report will send. Shown in Settings, too. */
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
		$counts   = self::counts();
		$settings = Settings::all();
		return $report + array(
			'niche'       => $state['niche'],
			'niche_other' => $state['niche_other'],
			'size'        => $state['size'],
			'features'    => $counts['features'],
			'members'     => self::range( $counts['members'] ),
			'active_week' => self::range( $counts['active_week'] ),
			'posts'       => self::range( $counts['posts'] ),
			'reels'       => self::range( $counts['reels'] ),
			'courses'     => self::range( $counts['courses'] ),
			'chats'       => self::range( $counts['chats'] ),
			'video'       => Bunny::enabled() ? 'bunny' : 'local',
			'landing'     => $settings['landing'],
			'login_page'  => (bool) $settings['login_page'],
			'signup'      => Login::registration_open() ? 'open' : ( $settings['signup_url'] ? 'elsewhere' : 'closed' ),
			'problems'    => Problems::for_report(),
		);
	}

	/** The community's numbers, exact; the weekly report turns them into ranges. */
	private static function counts() {
		global $wpdb;
		$posts = Install::table( 'posts' );
		$stats = Install::table( 'member_stats' );
		return array(
			'features'    => array_map( 'boolval', Settings::all()['features'] ),
			'members'     => Helpers::member_total(),
			'active_week' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_active >= %s', $stats, gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) ) ),
			'active_30'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE last_active >= %s', $stats, gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ) ),
			'last_active' => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(last_active) FROM %i', $stats ) ),
			'posts'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND type = 'post'", $posts ) ),
			'reels'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND type = 'reel'", $posts ) ),
			'events'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'published' AND event_at IS NOT NULL", $posts ) ),
			'courses'     => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'courses' ) ) ),
			'chats'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Install::table( 'threads' ) ) ),
		);
	}

	/** Exactly what "Keep me updated" sends, or null when not subscribed. Shown in Settings, too. */
	public static function subscriber() {
		global $wp_version;
		$state = self::state();
		if ( ! $state['subscribed'] || ! $state['email'] ) {
			return null;
		}
		$out = array(
			'install_id' => $state['id'],
			'email'      => $state['email'],
			'site_url'   => home_url(),
			'site_name'  => get_bloginfo( 'name' ),
			'plugin'     => MEMBUILD_COMMUNITY_VERSION,
			'wp'         => $wp_version,
			'php'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'     => get_locale(),
		);
		if ( $state['share'] ) {
			$c            = self::counts();
			$out['usage'] = array(
				'members'     => $c['members'],
				'active_30'   => $c['active_30'],
				'posts'       => $c['posts'] + $c['reels'],
				'courses'     => $c['courses'],
				'events'      => $c['events'],
				'features'    => $c['features'],
				'last_active' => $c['last_active'],
			);
		}
		return $out;
	}

	/** Sends the weekly report, and the subscription while there is one (weekly, and right after the answers change). True when everything due went out. */
	public static function send() {
		$sent = array();
		if ( self::enabled() ) {
			$sent[] = self::post( 'community_report', self::report() );
			if ( end( $sent ) ) {
				$state            = self::state();
				$state['sent_at'] = time();
				update_option( self::OPTION, $state, false );
			}
		}
		$subscriber = self::subscriber();
		if ( $subscriber && self::allowed() ) {
			$sent[] = self::post( 'community_subscribe', $subscriber );
		}
		return $sent && ! in_array( false, $sent, true );
	}

	/** One request to MemBuild; true when it answered 200. */
	private static function post( $action, array $body, $timeout = 8 ) {
		$response = wp_remote_post(
			self::ENDPOINT . '?action=' . $action,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json', 'User-Agent' => 'MemBuild-Community/' . MEMBUILD_COMMUNITY_VERSION ),
				'body'    => wp_json_encode( $body ),
			)
		);
		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/** Sends soon, off the admin's request: after an answer changes. */
	private static function send_soon() {
		if ( self::enabled() || self::subscriber() ) {
			wp_schedule_single_event( time() + 30, self::HOOK . '_now' );
		}
	}

	/**
	 * "Keep me updated": remembers the email and tells MemBuild now. A failed
	 * request still keeps the subscription; the weekly report retries it.
	 * Returns false only for a bad email.
	 */
	public static function subscribe( $email ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$state                  = self::state();
		$state['email']         = $email;
		$state['subscribed']    = true;
		$state['subscribed_at'] = $state['subscribed_at'] ?: time();
		$state['asked']         = true;
		update_option( self::OPTION, $state, false );
		if ( self::allowed() && ! self::post( 'community_subscribe', self::subscriber() ) ) {
			self::send_soon();
		}
		return true;
	}

	/** "Stop": asks MemBuild to drop the subscription, then forgets the email here. False when MemBuild couldn't be reached. */
	public static function unsubscribe() {
		$state = self::state();
		if ( $state['subscribed'] && self::allowed() && ! self::post( 'community_unsubscribe', array( 'install_id' => $state['id'], 'email' => $state['email'] ), 10 ) ) {
			return false;
		}
		$state['email']         = '';
		$state['subscribed']    = false;
		$state['subscribed_at'] = 0;
		$state['skips']         = 2; // the overview doesn't ask again; Settings still offers it
		update_option( self::OPTION, $state, false );
		return true;
	}

	/* ---------- The card on the Community overview ---------- */

	/** Whether the overview shows the card: until subscribed, once, and once more two weeks after "Not now". */
	public static function card_due() {
		$state = self::state();
		if ( $state['subscribed'] || $state['skips'] >= 2 ) {
			return false;
		}
		return 0 === (int) $state['skips'] || time() - (int) $state['skipped_at'] >= self::RETURN_AFTER;
	}

	/** The card above the numbers. */
	public static function setup_card() {
		$state = self::state();
		if ( ! Helpers::can_manage() || ! self::card_due() ) {
			return;
		}
		echo '<form class="mb-card mbcom-setup" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mbcom_usage' );
		echo '<input type="hidden" name="action" value="mbcom_usage">';
		printf(
			'<h2>%s</h2><p class="mbcom-muted">%s</p>',
			esc_html__( 'Get the next features before anyone else', 'membuild-community' ),
			esc_html__( 'An email when something new ships in MemBuild - Community, and the odd question about what to build next. No newsletters, nothing for sale.', 'membuild-community' )
		);
		echo '<div class="mbcom-setup-fields">';
		printf( '<label><span>%s</span><input type="email" name="email" value="%s" required></label>', esc_html__( 'Your email', 'membuild-community' ), esc_attr( wp_get_current_user()->user_email ) );
		printf( '<label><span>%s</span><input type="text" value="%s" readonly></label>', esc_html__( 'Your site', 'membuild-community' ), esc_attr( home_url() ) );
		printf( '<label><span>%s</span><select name="niche"><option value="">%s</option>', esc_html__( 'What is it about?', 'membuild-community' ), esc_html__( 'Choose one', 'membuild-community' ) );
		foreach ( self::niches() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $state['niche'], $key, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		printf( '<label class="mbcom-setup-other"><span>%s</span><input type="text" name="niche_other" maxlength="40" value="%s"></label>', esc_html__( 'In a few words', 'membuild-community' ), esc_attr( $state['niche_other'] ) );
		printf( '<label><span>%s</span><select name="size"><option value="">%s</option>', esc_html__( 'How many members do you expect?', 'membuild-community' ), esc_html__( 'Choose one', 'membuild-community' ) );
		foreach ( self::sizes() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $state['size'], $key, false ), esc_html( $label ) );
		}
		echo '</select></label></div>';
		printf(
			'<label class="mbcom-setup-share"><input type="checkbox" name="share" value="1"%s> <span><strong>%s</strong> %s</span></label>',
			checked( $state['asked'] ? $state['share'] : true, true, false ),
			esc_html__( 'Also share how your community is used.', 'membuild-community' ),
			esc_html__( 'Which features are on and how many members, posts, courses and events there are, refreshed weekly. Never member names, emails, posts or messages.', 'membuild-community' )
		);
		printf(
			'<p class="mbcom-setup-actions"><button class="mb-btn mb-btn--accent" name="do" value="save">%s</button> <button class="mb-btn mb-btn--quiet" name="do" value="skip" formnovalidate>%s</button></p>',
			esc_html__( 'Keep me updated', 'membuild-community' ),
			esc_html__( 'Not now', 'membuild-community' )
		);
		printf(
			'<p class="mbcom-setup-fine">%s</p>',
			esc_html__( 'We get your email, your site’s address and name, and the plugin, WordPress and PHP versions. Stop any time in the community’s Settings → Updates & usage, which also shows exactly what’s sent.', 'membuild-community' )
		);
		echo '</form>';
	}

	public static function handle_setup() {
		if ( ! Helpers::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'membuild-community' ), 403 );
		}
		check_admin_referer( 'mbcom_usage' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		if ( 'save' === sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ) ) {
			$before = self::state();
			$after  = self::update(
				array(
					'niche'       => sanitize_key( wp_unslash( $_POST['niche'] ?? '' ) ),
					'niche_other' => sanitize_text_field( wp_unslash( $_POST['niche_other'] ?? '' ) ),
					'size'        => sanitize_key( wp_unslash( $_POST['size'] ?? '' ) ),
					'share'       => ! empty( $_POST['share'] ),
					'asked'       => true,
				)
			);
			if ( $before['share'] !== $after['share'] ) {
				self::send_soon();
			}
			self::subscribe( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ); // the subscription goes out right away
		} else {
			self::skip();
		}
		// phpcs:enable
		wp_safe_redirect( Admin::url() );
		exit;
	}

	/** "Not now": the card comes back once, two weeks later. */
	public static function skip() {
		$state               = self::state();
		$state['skips']      = (int) $state['skips'] + 1;
		$state['skipped_at'] = time();
		$state['asked']      = true;
		update_option( self::OPTION, $state, false );
	}

	/* ---------- REST, for Settings → Updates & usage ---------- */

	public static function rest_get() {
		$state = self::state();
		return array(
			'share'         => (bool) $state['share'],
			'niche'         => $state['niche'],
			'niche_other'   => $state['niche_other'],
			'size'          => $state['size'],
			'id'            => $state['id'],
			'enabled'       => self::enabled(),
			'allowed'       => self::allowed(),
			'sent_at'       => $state['sent_at'] ? gmdate( 'c', $state['sent_at'] ) : null,
			'report'        => self::report(),
			'niches'        => self::niches(),
			'sizes'         => self::sizes(),
			'email'         => $state['email'],
			'subscribed'    => (bool) $state['subscribed'],
			'subscribed_at' => $state['subscribed_at'] ? gmdate( 'c', $state['subscribed_at'] ) : null,
			'admin_email'   => wp_get_current_user()->user_email,
			'site_url'      => home_url(),
			'site_name'     => get_bloginfo( 'name' ),
			'subscriber'    => self::subscriber(),
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

	public static function rest_subscribe( \WP_REST_Request $req ) {
		if ( ! self::subscribe( $req->get_json_params()['email'] ?? '' ) ) {
			return new \WP_Error( 'mbcom_bad_email', __( 'That doesn’t look like an email address.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		return self::rest_get();
	}

	public static function rest_unsubscribe() {
		if ( ! self::unsubscribe() ) {
			return new \WP_Error( 'mbcom_unsubscribe_failed', __( 'MemBuild couldn’t be reached. Please try again in a few minutes.', 'membuild-community' ), array( 'status' => 502 ) );
		}
		return self::rest_get();
	}

	/** Asks MemBuild to delete everything it has for this site's ID (the subscription too), then starts over with a new ID. */
	public static function rest_forget() {
		$state = self::state();
		if ( ! self::post( 'community_forget', array( 'install_id' => $state['id'] ), 10 ) ) {
			return new \WP_Error( 'mbcom_forget_failed', __( 'MemBuild couldn’t be reached. Please try again in a few minutes.', 'membuild-community' ), array( 'status' => 502 ) );
		}
		$state['id']            = wp_generate_uuid4();
		$state['share']         = false;
		$state['sent_at']       = 0;
		$state['email']         = '';
		$state['subscribed']    = false;
		$state['subscribed_at'] = 0;
		update_option( self::OPTION, $state, false );
		return self::rest_get();
	}
}
