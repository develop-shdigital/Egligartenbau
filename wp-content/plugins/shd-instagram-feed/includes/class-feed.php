<?php
/**
 * Read access to the cached feed for rendering. Never calls Instagram.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

defined( 'ABSPATH' ) || exit;

/**
 * Feed reader.
 */
final class Feed {

	/**
	 * Posts to display, newest first: only posts that are shown on the
	 * website (approved, or new in "show automatically" mode).
	 *
	 * @param int  $limit          Maximum number of posts.
	 * @param bool $include_videos Whether to include videos and reels (shown with their cover image).
	 * @return array List of posts with `id`, `type`, `permalink`, `timestamp`, `caption`, `alt`, `attachment_id`.
	 */
	public static function get_items( int $limit, bool $include_videos = true ): array {
		$feed  = Options::get_feed();
		$items = array();

		foreach ( $feed['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['attachment_id'] ) || empty( $item['permalink'] ) ) {
				continue;
			}
			if ( ! Options::is_visible( (string) ( $item['id'] ?? '' ) ) ) {
				continue;
			}
			if ( ! $include_videos && isset( $item['type'] ) && 'video' === $item['type'] ) {
				continue;
			}

			$items[] = $item;
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		/**
		 * Filter the posts before they are rendered, e.g. to hide a post.
		 *
		 * @param array $items Posts.
		 * @param int   $limit Requested number of posts.
		 */
		return (array) apply_filters( 'shd_igf_feed_items', $items, $limit );
	}

	/**
	 * Number of cached posts, how many of them are shown and how many wait
	 * for approval.
	 *
	 * @return array{total:int,visible:int,awaiting:int}
	 */
	public static function counts(): array {
		$counts = array(
			'total'    => 0,
			'visible'  => 0,
			'awaiting' => 0,
		);
		foreach ( Options::feed_ids() as $id ) {
			++$counts['total'];
			if ( Options::is_visible( $id ) ) {
				++$counts['visible'];
			} elseif ( Options::is_awaiting( $id ) ) {
				++$counts['awaiting'];
			}
		}
		return $counts;
	}

	/**
	 * Posts that wait for approval, newest first.
	 *
	 * @return array
	 */
	public static function awaiting_items(): array {
		$items = array();
		foreach ( Options::get_feed()['items'] as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) && Options::is_awaiting( (string) $item['id'] ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * One line about a post for lists and emails: the start of the caption,
	 * else the alt text.
	 *
	 * @param array $item   Post.
	 * @param int   $length Maximum length in characters.
	 * @return string
	 */
	public static function summary( array $item, int $length = 100 ): string {
		$text = trim( (string) ( $item['caption'] ?? '' ) );
		$text = '' !== $text ? $text : trim( (string) ( $item['alt'] ?? '' ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) > $length ) {
			$text = rtrim( mb_substr( $text, 0, $length - 1 ), " \t\n\r\0\x0B.,;:-" ) . '…';
		}
		return $text;
	}

	/**
	 * Username of the account the feed came from.
	 *
	 * @return string
	 */
	public static function username(): string {
		$feed = Options::get_feed();
		return $feed['username'];
	}
}
