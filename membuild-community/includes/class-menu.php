<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The shortcuts menu behind the grid button in the community header.
 * Admins build it the usual WordPress way (Appearance → Menus, location
 * "Community shortcuts"), so they can point members at pages they've made.
 * One level of sub-items is kept; they show as a group under their parent.
 */
class Menu {

	const LOCATION = 'mbcom_shortcuts';

	public static function register_hooks() {
		add_action( 'after_setup_theme', array( __CLASS__, 'register_location' ), 20 );
	}

	public static function register_location() {
		register_nav_menus( array( self::LOCATION => __( 'Community shortcuts (grid menu)', 'membuild-community' ) ) );
	}

	/** The menu as the app shows it: top-level links, each with any sub-links. */
	public static function items() {
		$locations = get_nav_menu_locations();
		if ( empty( $locations[ self::LOCATION ] ) ) {
			return array();
		}
		$items = wp_get_nav_menu_items( (int) $locations[ self::LOCATION ], array( 'update_post_term_cache' => false ) );
		if ( ! $items ) {
			return array();
		}

		$out = array();
		$top = array();
		foreach ( $items as $item ) {
			$parent = (int) $item->menu_item_parent;
			$link   = array(
				'id'     => (int) $item->ID,
				'title'  => wp_strip_all_tags( $item->title ),
				'url'    => esc_url_raw( $item->url ),
				'newTab' => '_blank' === $item->target,
				'note'   => wp_strip_all_tags( (string) $item->description ),
			);
			if ( $parent && isset( $top[ $parent ] ) ) {
				$out[ $top[ $parent ] ]['children'][] = $link;
			} elseif ( ! $parent ) {
				$link['children'] = array();
				$top[ $item->ID ] = count( $out );
				$out[]            = $link;
			}
			// Deeper levels are left out: the dropdown stays one group deep.
		}
		return $out;
	}

	/** Where an admin goes to edit the menu. */
	public static function edit_url() {
		return admin_url( 'nav-menus.php?action=locations' );
	}
}
