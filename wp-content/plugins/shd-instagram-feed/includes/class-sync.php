<?php
/**
 * Background sync: fetch posts, import new images, prune old ones, keep the
 * access token alive.
 *
 * Runs on WP-Cron. Safe to run late or repeatedly: posts are matched by their
 * Instagram media ID, and a lock prevents overlapping runs.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sync job.
 */
final class Sync {

	/** Recurring event. */
	const HOOK = 'shd_igf_sync';

	/** One-off event that imports the rest of a large batch. */
	const CONTINUE_HOOK = 'shd_igf_sync_continue';

	const LOCK = 'shd_igf_sync_lock';

	/** Images imported per run, so a run never risks the PHP time limit. */
	const BATCH_SIZE = 6;

	/** Refresh the 60-day token once a week. */
	const REFRESH_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Make sure the recurring event exists with the configured interval.
	 */
	public static function schedule(): void {
		if ( ! Options::is_connected() ) {
			self::unschedule();
			return;
		}

		$settings = Options::get_settings();
		$event    = wp_get_scheduled_event( self::HOOK );

		if ( $event && $event->schedule === $settings['interval'] ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $settings['interval'], self::HOOK );
	}

	/**
	 * Remove all scheduled events.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Cron callback.
	 */
	public static function run_cron(): void {
		self::run();
	}

	/**
	 * Fetch the latest posts and update the cached feed.
	 *
	 * @return array|WP_Error Summary with `fetched`, `imported`, `pending`, `removed`, `failed`.
	 */
	public static function run() {
		$connection = Options::get_connection();
		if ( '' === $connection['token'] ) {
			return new WP_Error( 'shd_igf_not_connected', __( 'No Instagram account is connected.', 'shd-instagram-feed' ) );
		}

		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'shd_igf_locked', __( 'A sync is already running. Try again in a few minutes.', 'shd-instagram-feed' ) );
		}
		set_transient( self::LOCK, time(), 5 * MINUTE_IN_SECONDS );

		try {
			Options::update_status( array( 'last_run' => time() ) );

			$connection = self::maybe_refresh_token( $connection );
			$settings   = Options::get_settings();
			$client     = new Api_Client( $connection['token'] );
			$raw        = $client->get_media( $settings['keep'] );

			if ( is_wp_error( $raw ) ) {
				self::record_error( $raw );
				return $raw;
			}

			$result = self::apply( $raw, $connection['username'], $client );

			if ( $result['pending'] > 0 && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
			}

			if ( '' !== $connection['token_error'] ) {
				$connection['token_error'] = '';
				Options::save_connection( $connection );
			}

			Options::update_status(
				array(
					'last_success'  => time(),
					'last_error'    => $result['error'],
					'last_error_at' => '' !== $result['error'] ? time() : 0,
					'pending'       => $result['pending'],
					'imported'      => $result['imported'],
					'removed'       => $result['removed'],
				)
			);

			unset( $result['error'] );
			return $result;
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Turn API posts into the cached feed: reuse imported images, import new
	 * ones (bounded per run), keep selected older posts, delete images of posts
	 * that left the feed.
	 *
	 * @param array      $raw      Raw media objects from the API, newest first.
	 * @param string     $username Account username.
	 * @param Api_Client $client   API client (to check selected older posts).
	 * @return array{fetched:int,imported:int,pending:int,removed:int,failed:int,error:string}
	 */
	private static function apply( array $raw, string $username, Api_Client $client ): array {
		$items = array();
		foreach ( $raw as $media ) {
			$item = self::normalize( $media );
			if ( $item ) {
				$items[] = $item;
			}
		}

		// Map media ID => attachment ID; delete duplicates and broken attachments.
		$existing = array();
		$removed  = 0;
		foreach ( Importer::attachments() as $row ) {
			if ( isset( $existing[ $row['media_id'] ] ) || ! Importer::is_usable( $row['attachment_id'] ) ) {
				Importer::delete( $row['attachment_id'] );
				++$removed;
				continue;
			}
			$existing[ $row['media_id'] ] = $row['attachment_id'];
		}

		$feed     = array();
		$imported = 0;
		$pending  = 0;
		$failed   = 0;
		$error    = '';

		foreach ( $items as $item ) {
			$attachment_id = isset( $existing[ $item['id'] ] ) ? (int) $existing[ $item['id'] ] : 0;

			if ( ! $attachment_id ) {
				if ( $imported + $failed >= self::BATCH_SIZE ) {
					++$pending;
					continue;
				}

				$attachment_id = Importer::import( $item );
				if ( is_wp_error( $attachment_id ) ) {
					++$failed;
					$error = sprintf(
						/* translators: %s: error message. */
						__( 'An image could not be imported: %s', 'shd-instagram-feed' ),
						$attachment_id->get_error_message()
					);
					continue;
				}
				++$imported;
				$existing[ $item['id'] ] = $attachment_id;
			}

			unset( $item['image_url'] );
			$item['attachment_id'] = $attachment_id;
			$feed[]                = $item;
		}

		// Selected posts that are older than the newest posts.
		$kept = self::keep_selected( $items, $existing, $client );
		if ( $kept ) {
			$feed = array_merge( $feed, $kept );
			usort(
				$feed,
				static function ( array $a, array $b ): int {
					return (int) $b['timestamp'] <=> (int) $a['timestamp'];
				}
			);
		}

		// Images of posts that are no longer among the latest or selected posts.
		$current = array_merge( wp_list_pluck( $items, 'id' ), wp_list_pluck( $kept, 'id' ) );
		foreach ( $existing as $media_id => $attachment_id ) {
			if ( ! in_array( (string) $media_id, $current, true ) ) {
				Importer::delete( (int) $attachment_id );
				++$removed;
			}
		}

		Options::save_feed(
			array(
				'updated_at' => time(),
				'username'   => $username,
				'items'      => $feed,
			)
		);

		self::forget_missing_posts( $current );

		return array(
			'fetched'  => count( $items ),
			'imported' => $imported,
			'pending'  => $pending,
			'removed'  => $removed,
			'failed'   => $failed,
			'error'    => $error,
		);
	}

	/**
	 * When only selected posts are shown, keep selected posts that dropped
	 * out of the newest posts, as long as they still exist on Instagram
	 * (checked at most once a day per post).
	 *
	 * @param array      $items    Newest posts from the API (normalised).
	 * @param array      $existing Media ID => attachment ID.
	 * @param Api_Client $client   API client.
	 * @return array Posts to keep, from the previous feed.
	 */
	private static function keep_selected( array $items, array $existing, Api_Client $client ): array {
		$moderation = Options::get_moderation();
		if ( 'hide' !== $moderation['new_posts'] || ! $moderation['selected'] ) {
			return array();
		}

		$newest = array_flip( wp_list_pluck( $items, 'id' ) );
		$kept   = array();

		foreach ( Options::get_feed()['items'] as $item ) {
			$id = is_array( $item ) && isset( $item['id'] ) ? (string) $item['id'] : '';

			if ( '' === $id || isset( $newest[ $id ] ) || ! isset( $existing[ $id ] ) || ! in_array( $id, $moderation['selected'], true ) ) {
				continue;
			}
			if ( count( $kept ) >= Options::MAX_SELECTED ) {
				break;
			}

			if ( time() - (int) ( $item['checked_at'] ?? 0 ) > DAY_IN_SECONDS ) {
				$check = $client->get_media_item( $id );
				if ( is_wp_error( $check ) && Api_Client::is_not_found_error( $check ) ) {
					continue; // Deleted on Instagram.
				}
				if ( ! is_wp_error( $check ) ) {
					$item['checked_at'] = time();
				}
				// Other errors (e.g. no connection): keep the post and check again next time.
			}

			$item['attachment_id'] = (int) $existing[ $id ];
			$kept[]                = $item;
		}

		return $kept;
	}

	/**
	 * Forget the selection of posts that are no longer in the feed.
	 *
	 * @param string[] $current Media IDs in the feed or waiting for their image.
	 */
	private static function forget_missing_posts( array $current ): void {
		$moderation = Options::get_moderation();
		$selected   = array_values( array_intersect( $moderation['selected'], $current ) );
		$hidden     = array_values( array_intersect( $moderation['hidden'], $current ) );

		if ( count( $selected ) !== count( $moderation['selected'] ) || count( $hidden ) !== count( $moderation['hidden'] ) ) {
			$moderation['selected'] = $selected;
			$moderation['hidden']   = $hidden;
			Options::save_moderation( $moderation );
		}
	}

	/**
	 * Reduce an API media object to what the widget needs.
	 *
	 * @param mixed $media Raw media object.
	 * @return array|null Null when the post can't be shown.
	 */
	public static function normalize( $media ): ?array {
		if ( ! is_array( $media ) || empty( $media['id'] ) || empty( $media['permalink'] ) ) {
			return null;
		}

		$type      = strtoupper( isset( $media['media_type'] ) ? (string) $media['media_type'] : 'IMAGE' );
		$image_url = self::image_url( $media, $type );
		$permalink = esc_url_raw( (string) $media['permalink'] );
		$host      = (string) wp_parse_url( $permalink, PHP_URL_HOST );

		if ( '' === $image_url || ! preg_match( '/(^|\.)instagram\.com$/', $host ) ) {
			return null;
		}

		$caption = isset( $media['caption'] ) ? sanitize_textarea_field( (string) $media['caption'] ) : '';
		$alt     = isset( $media['alt_text'] ) ? sanitize_text_field( (string) $media['alt_text'] ) : '';
		$time    = isset( $media['timestamp'] ) ? strtotime( (string) $media['timestamp'] ) : false;

		return array(
			'id'        => preg_replace( '/[^0-9A-Za-z_]/', '', (string) $media['id'] ),
			'type'      => 'VIDEO' === $type ? 'video' : ( 'CAROUSEL_ALBUM' === $type ? 'album' : 'image' ),
			'permalink' => $permalink,
			'timestamp' => $time ? (int) $time : 0,
			'caption'   => self::excerpt( $caption, 300 ),
			'alt'       => '' !== $alt ? $alt : self::alt_from_caption( $caption ),
			'image_url' => $image_url,
		);
	}

	/**
	 * Best still image for a post: the photo, a video's cover, or an album's
	 * first item.
	 *
	 * @param array  $media Raw media object.
	 * @param string $type  Media type.
	 * @return string
	 */
	private static function image_url( array $media, string $type ): string {
		$url = '';

		if ( 'VIDEO' === $type ) {
			$url = isset( $media['thumbnail_url'] ) ? (string) $media['thumbnail_url'] : '';
		} elseif ( 'CAROUSEL_ALBUM' === $type && ! empty( $media['children']['data'][0] ) && is_array( $media['children']['data'][0] ) ) {
			$first = $media['children']['data'][0];
			$key   = isset( $first['media_type'] ) && 'VIDEO' === strtoupper( (string) $first['media_type'] ) ? 'thumbnail_url' : 'media_url';
			$url   = isset( $first[ $key ] ) ? (string) $first[ $key ] : '';
		}

		if ( '' === $url && 'VIDEO' !== $type && isset( $media['media_url'] ) ) {
			$url = (string) $media['media_url'];
		}

		// Cheap check only: download_url() validates the URL safely when the image is fetched.
		$is_https = 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && '' !== (string) wp_parse_url( $url, PHP_URL_HOST );

		return $is_https ? esc_url_raw( $url ) : '';
	}

	/**
	 * Short alt text from a caption: no hashtags, mentions, links or emoji.
	 *
	 * @param string $caption Caption.
	 * @return string
	 */
	public static function alt_from_caption( string $caption ): string {
		$text = preg_replace( '~https?://\S+~u', ' ', $caption );
		$text = preg_replace( '/(^|\s)[#@][\p{L}\p{N}_.]+/u', ' ', (string) $text );
		$text = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', (string) $text );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );

		return self::excerpt( $text, 125 );
	}

	/**
	 * Cut text at a word boundary.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum length in characters.
	 * @return string
	 */
	private static function excerpt( string $text, int $length ): string {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $length );
		$cut = preg_replace( '/\s+\S*$/u', '', $cut );
		return rtrim( (string) $cut, " \t\n\r\0\x0B.,;:-" ) . '…';
	}

	/**
	 * Refresh the token when it is a week old. Instagram tokens expire after
	 * 60 days unless refreshed.
	 *
	 * @param array $connection Connection data.
	 * @return array Possibly updated connection data.
	 */
	private static function maybe_refresh_token( array $connection ): array {
		$last = max( $connection['refreshed_at'], $connection['connected_at'] );
		if ( time() - $last < self::REFRESH_AFTER ) {
			return $connection;
		}

		$client = new Api_Client( $connection['token'] );
		$result = $client->refresh_token();

		if ( is_wp_error( $result ) ) {
			if ( Api_Client::is_token_error( $result ) ) {
				$connection['token_error'] = $result->get_error_message();
				Options::save_connection( $connection );
			}
			return $connection;
		}

		$token = isset( $result['access_token'] ) ? self::clean_token( (string) $result['access_token'] ) : '';
		if ( '' !== $token ) {
			$connection['token']        = $token;
			$connection['refreshed_at'] = time();
			$connection['expires_at']   = time() + ( isset( $result['expires_in'] ) ? absint( $result['expires_in'] ) : 60 * DAY_IN_SECONDS );
			$connection['token_error']  = '';
			Options::save_connection( $connection );
		}

		return $connection;
	}

	/**
	 * Remember an API failure. The widget keeps showing the cached posts.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function record_error( WP_Error $error ): void {
		if ( Api_Client::is_token_error( $error ) ) {
			$connection                = Options::get_connection();
			$connection['token_error'] = $error->get_error_message();
			Options::save_connection( $connection );
		}

		Options::update_status(
			array(
				'last_error'    => $error->get_error_message(),
				'last_error_at' => time(),
			)
		);
	}

	/**
	 * Access tokens only contain URL-safe characters.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function clean_token( string $token ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-|.]/', '', trim( $token ) );
	}
}
