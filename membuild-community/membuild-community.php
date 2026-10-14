<?php
/**
 * Plugin Name:       MemBuild - Community
 * Description:       A simple, free community for WordPress: feed, reels, courses and gamification.
 * Plugin URI:        https://github.com/danicalouwe/membuild-community
 * Update URI:        https://github.com/danicalouwe/membuild-community
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            MemBuild
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       membuild-community
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MEMBUILD_COMMUNITY_VERSION', '1.1.0' );
define( 'MEMBUILD_COMMUNITY_DB_VERSION', '19' );
define( 'MEMBUILD_COMMUNITY_FILE', __FILE__ );
define( 'MEMBUILD_COMMUNITY_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEMBUILD_COMMUNITY_URL', plugin_dir_url( __FILE__ ) );
define( 'MEMBUILD_COMMUNITY_REST_NS', 'membuild-community/v1' );

require_once MEMBUILD_COMMUNITY_DIR . 'includes/class-plugin.php';
spl_autoload_register( array( 'MemBuild\Community\Plugin', 'autoload' ) );

MemBuild\Community\Plugin::boot();
