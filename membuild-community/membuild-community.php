<?php
/**
 * Plugin Name:       MemBuild - Community
 * Description:       A simple, free community for WordPress: feed, reels, courses and gamification.
 * Plugin URI:        https://github.com/danicalouwe/membuild-community
 * Update URI:        https://github.com/danicalouwe/membuild-community
 * Version:           1.10.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            MemBuild
 * Author URI:        https://membuild.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       membuild-community
 * Domain Path:       /languages
 *
 * Copyright (C) 2026 Danica Louwe (MemBuild, https://membuild.com)
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option)
 * any later version. It is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * The GPL asks that copies keep these notices, and that changed copies say
 * what was changed. "MemBuild" is the author's name and brand; the license
 * doesn't cover using it for other products.
 */

defined( 'ABSPATH' ) || exit;

define( 'MEMBUILD_COMMUNITY_VERSION', '1.10.0' );
define( 'MEMBUILD_COMMUNITY_DB_VERSION', '28' );
define( 'MEMBUILD_COMMUNITY_FILE', __FILE__ );
define( 'MEMBUILD_COMMUNITY_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEMBUILD_COMMUNITY_URL', plugin_dir_url( __FILE__ ) );
define( 'MEMBUILD_COMMUNITY_REST_NS', 'membuild-community/v1' );

require_once MEMBUILD_COMMUNITY_DIR . 'includes/class-plugin.php';
spl_autoload_register( array( 'MemBuild\Community\Plugin', 'autoload' ) );

MemBuild\Community\Plugin::boot();
