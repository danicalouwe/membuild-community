<?php
/**
 * Updates from GitHub releases.
 *
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */

namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin is published as GitHub releases, so WordPress learns about new
 * versions from there: twice a day it reads the repository's latest release,
 * and when its version is newer, Dashboard → Updates and the Plugins screen
 * offer it like any other update, installing the zip attached to the release.
 *
 * Nothing is sent to GitHub but the request itself (no site address, no
 * usage data). Turn it off with the membuild_community/github_updates filter,
 * e.g. when installing from WordPress.org, which updates plugins itself.
 */
class Updater {

	const REPO      = 'danicalouwe/membuild-community';
	const CACHE     = 'mbcom_github_release';
	const ZIP_START = 'membuild-community-'; // the release asset: membuild-community-<version>.zip

	public static function register_hooks() {
		/**
		 * Whether to look for new versions on GitHub.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'membuild_community/github_updates', true ) ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'offer' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', fn() => delete_site_transient( self::CACHE ) );
	}

	private static function basename() {
		return plugin_basename( MEMBUILD_COMMUNITY_FILE );
	}

	/**
	 * The latest release, from cache or GitHub: array( version, zip, notes, url, date ),
	 * or null when there is none or GitHub couldn't be reached (asked again in an hour).
	 */
	public static function latest() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'MemBuild-Community/' . MEMBUILD_COMMUNITY_VERSION ),
			)
		);
		$release = 200 === wp_remote_retrieve_response_code( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		$zip     = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			// Only a zip on this repo's own GitHub release downloads, never a link to anywhere else.
			$link = (string) ( $asset['browser_download_url'] ?? '' );
			if ( str_starts_with( (string) ( $asset['name'] ?? '' ), self::ZIP_START ) && str_ends_with( (string) $asset['name'], '.zip' ) && str_starts_with( $link, 'https://github.com/' . self::REPO . '/releases/download/' ) ) {
				$zip = $link;
			}
		}
		$found = array(
			'version' => $zip ? ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' ) : '',
			'zip'     => $zip,
			'notes'   => (string) ( $release['body'] ?? '' ),
			'url'     => (string) ( $release['html_url'] ?? 'https://github.com/' . self::REPO ),
			'date'    => (string) ( $release['published_at'] ?? '' ),
		);
		set_site_transient( self::CACHE, $found, $found['version'] ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $found['version'] ? $found : null;
	}

	/** Adds the update to WordPress's list when the latest release is newer than what's installed. */
	public static function offer( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$installed = (string) ( $transient->checked[ self::basename() ] ?? MEMBUILD_COMMUNITY_VERSION );
		$latest    = self::latest();
		$item      = (object) array(
			'id'          => 'github.com/' . self::REPO,
			'slug'        => 'membuild-community',
			'plugin'      => self::basename(),
			'new_version' => $latest['version'] ?? $installed,
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $latest['zip'] ?? '',
		);
		if ( $latest && version_compare( $latest['version'], $installed, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			unset( $transient->response[ self::basename() ] );
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** The "View details" window on the Plugins screen. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'membuild-community' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$latest = self::latest();
		return (object) array(
			'name'          => 'MemBuild - Community',
			'slug'          => 'membuild-community',
			'version'       => $latest['version'] ?? MEMBUILD_COMMUNITY_VERSION,
			'author'        => '<a href="https://membuild.com/">MemBuild</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.4',
			'requires_php'  => '8.1',
			'last_updated'  => $latest['date'] ?? '',
			'download_link' => $latest['zip'] ?? '',
			'sections'      => array(
				'changelog' => $latest ? wpautop( esc_html( $latest['notes'] ) ) . '<p><a href="' . esc_url( $latest['url'] ) . '">' . esc_html__( 'Release on GitHub', 'membuild-community' ) . '</a></p>' : '',
			),
		);
	}
}
