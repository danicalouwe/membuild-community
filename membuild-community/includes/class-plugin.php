<?php
/**
 * Boots the plugin.
 *
 * @package membuild-community
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every part of the plugin into WordPress from one place.
 *
 * Each class registers its own hooks in a static register_hooks() (or its
 * REST routes in register_routes()); this class only decides when.
 */
final class Plugin {

	/** Registered as soon as the plugin loads. */
	const HOOKS = array( Media::class, Files::class, Blocks::class, Bunny::class, Health::class, Privacy::class, Menu::class, Login::class, Access::class, Email::class, Updater::class, Usage::class );

	/** Registered on init, once translations, roles and post types exist. */
	const INIT = array( Portal::class, Rest_Members::class, Notifications::class, Points::class );

	/** Classes with REST routes. */
	const REST = array( Rest_Feed::class, Rest_Media::class, Rest_Comments::class, Rest_Stories::class, Rest_Courses::class, Access::class, Rest_Gamification::class, Rest_Messages::class, Notifications::class, Settings::class, Rest_Members::class, Moderation::class, Email::class, App::class, Bunny::class, Blocks::class, Usage::class );

	/** Hourly maintenance, each bounded so a late or doubled cron run stays cheap. */
	const MAINTENANCE = array(
		array( Media::class, 'cleanup_orphans' ),   // uploads nobody used within a day
		array( Notifications::class, 'cleanup' ),
		array( Rest_Stories::class, 'cleanup' ),    // expired stories are already hidden; this frees storage
		array( Embeds::class, 'refresh_stale' ),    // YouTube allows keeping video details for 30 days
	);

	public static function boot() {
		register_activation_hook( MEMBUILD_COMMUNITY_FILE, array( Install::class, 'activate' ) );
		register_deactivation_hook( MEMBUILD_COMMUNITY_FILE, array( Install::class, 'deactivate' ) );

		add_action( 'plugins_loaded', array( Install::class, 'maybe_upgrade' ) );

		foreach ( self::HOOKS as $class ) {
			$class::register_hooks();
		}
		add_action(
			'init',
			function () {
				foreach ( self::INIT as $class ) {
					$class::register_hooks();
				}
				if ( ! wp_next_scheduled( Install::CRON_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Install::CRON_HOOK );
				}
			}
		);
		add_action(
			'rest_api_init',
			function () {
				foreach ( self::REST as $class ) {
					$class::register_routes();
				}
			}
		);
		add_action( Vault::MIGRATE_HOOK, array( Rest_Messages::class, 'encrypt_existing' ) );
		foreach ( self::MAINTENANCE as $job ) {
			add_action( Install::CRON_HOOK, $job );
		}

		if ( is_admin() ) {
			Admin::register_hooks();
			Settings::register_admin();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mbcom', CLI::class );
		}
	}

	/**
	 * Loads MemBuild\Community\Some_Class from includes/class-some-class.php (or includes/admin/).
	 * The wp-admin list tables share one file.
	 */
	public static function autoload( $class ) {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$name = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		if ( str_ends_with( $name, '_Table' ) ) {
			require_once MEMBUILD_COMMUNITY_DIR . 'includes/admin/class-list-tables.php';
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		foreach ( array( 'includes/', 'includes/admin/' ) as $dir ) {
			if ( is_readable( MEMBUILD_COMMUNITY_DIR . $dir . $file ) ) {
				require_once MEMBUILD_COMMUNITY_DIR . $dir . $file;
				return;
			}
		}
	}
}
