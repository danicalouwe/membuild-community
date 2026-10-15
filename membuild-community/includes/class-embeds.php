<?php
/**
 * @package membuild-community
 * @copyright 2026 Danica Louwe (MemBuild, https://membuild.com). GPL-2.0-or-later.
 */
namespace MemBuild\Community;

defined( 'ABSPATH' ) || exit;

/**
 * YouTube and Vimeo videos (reels, lessons), played in the provider's own player.
 *
 * Nothing is downloaded: only the video ID and its public oEmbed metadata
 * (title, channel) are stored. YouTube's API terms allow keeping that
 * metadata for up to 30 days, so stale entries are refreshed on a schedule.
 */
class Embeds {

	const MAX_AGE = 25 * DAY_IN_SECONDS; // refresh comfortably inside YouTube's 30-day limit

	const REFRESH_BATCH = 10; // embeds re-fetched per hourly run (each is a blocking HTTP call)

	/** Extracts an 11-character YouTube video ID from any common URL shape. */
	public static function youtube_id( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '~(?:youtube\.com/(?:shorts/|embed/|live/|watch\?(?:.*&)?v=)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/** Extracts a numeric Vimeo ID, keeping the privacy hash of unlisted videos. */
	public static function vimeo_id( $url ) {
		if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)(?:/([a-f0-9]{6,}))?~', (string) $url, $m ) ) {
			return array( $m[1], $m[2] ?? '' );
		}
		return null;
	}

	/** The first YouTube or Vimeo link in some text, if any. */
	public static function first_link( $text ) {
		if ( preg_match_all( '~https?://[^\s<>"\']+~i', (string) $text, $m ) ) {
			foreach ( $m[0] as $url ) {
				if ( self::youtube_id( $url ) || self::vimeo_id( $url ) ) {
					return $url;
				}
			}
		}
		return null;
	}

	/** @return array|\WP_Error */
	public static function create( $user_id, $url ) {
		global $wpdb;
		$id = self::youtube_id( $url );
		if ( $id ) {
			$meta = self::fetch( $id, str_contains( $url, '/shorts/' ) );
		} elseif ( $vimeo = self::vimeo_id( $url ) ) {
			$meta = self::fetch_vimeo( $vimeo[0], $vimeo[1] );
		} else {
			return new \WP_Error( 'mbcom_bad_link', __( 'Paste a YouTube or Vimeo link.', 'membuild-community' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$player = 'vimeo' === $meta['provider']
			? 'https://player.vimeo.com/video/' . $meta['id'] . ( $meta['hash'] ? '?h=' . $meta['hash'] : '' )
			: 'https://www.youtube-nocookie.com/embed/' . $meta['id'];

		$wpdb->insert(
			Install::table( 'media' ),
			array(
				'user_id'      => $user_id,
				'kind'         => 'embed',
				'url'          => $player,
				'poster_url'   => $meta['thumbnail'],
				'mime'         => 'embed/' . $meta['provider'],
				'width'        => $meta['vertical'] ? 9 : 16,
				'height'       => $meta['vertical'] ? 16 : 9,
				'meta'         => wp_json_encode( $meta ),
				'refreshed_at' => Helpers::now(),
				'created_at'   => Helpers::now(),
			)
		);
		return Media::format( Media::get( (int) $wpdb->insert_id ) );
	}

	/**
	 * Public metadata from YouTube's oEmbed endpoint (no API key needed).
	 *
	 * @return array|\WP_Error
	 */
	public static function fetch( $id, $is_short ) {
		$source = $is_short ? "https://www.youtube.com/shorts/{$id}" : "https://www.youtube.com/watch?v={$id}";
		$res    = wp_safe_remote_get( 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( $source ), array( 'timeout' => 8 ) );
		$code   = wp_remote_retrieve_response_code( $res );
		if ( is_wp_error( $res ) || 200 !== $code ) {
			$message = in_array( $code, array( 401, 403 ), true )
				? __( "This video's owner doesn't allow it to be embedded.", 'membuild-community' )
				: __( "That video couldn't be found. Check the link and try again.", 'membuild-community' );
			return new \WP_Error( 'mbcom_embed_unavailable', $message, array( 'status' => 422 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true ) ?: array();
		return array(
			'provider'   => 'youtube',
			'id'         => $id,
			'title'      => sanitize_text_field( $data['title'] ?? '' ),
			'author'     => sanitize_text_field( $data['author_name'] ?? '' ),
			'author_url' => esc_url_raw( $data['author_url'] ?? '' ),
			'source_url' => $source,
			'thumbnail'  => esc_url_raw( $data['thumbnail_url'] ?? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" ),
			'vertical'   => $is_short,
			'fetched_at' => time(),
		);
	}

	/** @return array|\WP_Error */
	public static function fetch_vimeo( $id, $hash = '' ) {
		$source = "https://vimeo.com/{$id}" . ( $hash ? "/{$hash}" : '' );
		$res    = wp_safe_remote_get( 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( $source ), array( 'timeout' => 8 ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'mbcom_embed_unavailable', __( "That Vimeo video couldn't be found, or its privacy settings don't allow embedding.", 'membuild-community' ), array( 'status' => 422 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true ) ?: array();
		return array(
			'provider'   => 'vimeo',
			'id'         => $id,
			'hash'       => $hash,
			'title'      => sanitize_text_field( $data['title'] ?? '' ),
			'author'     => sanitize_text_field( $data['author_name'] ?? '' ),
			'author_url' => esc_url_raw( $data['author_url'] ?? '' ),
			'source_url' => $source,
			'thumbnail'  => esc_url_raw( $data['thumbnail_url'] ?? '' ),
			'vertical'   => ( (int) ( $data['height'] ?? 0 ) ) > ( (int) ( $data['width'] ?? 1 ) ),
			'duration'   => (int) ( $data['duration'] ?? 0 ),
			'fetched_at' => time(),
		);
	}

	/** What the app needs to render the player and credit the channel. */
	public static function public_meta( $meta ) {
		if ( ! $meta ) {
			return null;
		}
		return array_intersect_key( $meta, array_flip( array( 'provider', 'id', 'hash', 'title', 'author', 'author_url', 'source_url', 'vertical', 'duration' ) ) );
	}

	/** Hourly, a small batch: re-fetch embeds older than MAX_AGE; removed videos are hidden. */
	public static function refresh_stale() {
		global $wpdb;
		$table = Install::table( 'media' );
		// The longest-unrefreshed first (never-refreshed rows sort first), a few per run so cron stays quick.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, meta FROM %i WHERE kind = 'embed' AND ( refreshed_at IS NULL OR refreshed_at < %s ) ORDER BY refreshed_at ASC LIMIT %d",
				$table,
				gmdate( 'Y-m-d H:i:s', time() - self::MAX_AGE ),
				self::REFRESH_BATCH
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$meta = json_decode( (string) $row['meta'], true ) ?: array();
			$wpdb->update( $table, array( 'refreshed_at' => Helpers::now() ), array( 'id' => $row['id'] ) );
			$fresh = 'vimeo' === ( $meta['provider'] ?? 'youtube' )
				? self::fetch_vimeo( $meta['id'] ?? '', $meta['hash'] ?? '' )
				: self::fetch( $meta['id'] ?? '', ! empty( $meta['vertical'] ) );
			if ( is_wp_error( $fresh ) ) {
				// The video was removed or made private: drop our copy of its metadata and hide the reel.
				$wpdb->update( $table, array( 'meta' => wp_json_encode( array( 'provider' => 'youtube', 'id' => $meta['id'] ?? '', 'unavailable' => true, 'fetched_at' => time() ) ) ), array( 'id' => $row['id'] ) );
				if ( $row['post_id'] ) {
					$wpdb->update( Install::table( 'posts' ), array( 'status' => 'unavailable' ), array( 'id' => $row['post_id'] ) );
				}
				continue;
			}
			$wpdb->update( $table, array( 'meta' => wp_json_encode( $fresh ), 'poster_url' => $fresh['thumbnail'] ), array( 'id' => $row['id'] ) );
		}
	}
}
