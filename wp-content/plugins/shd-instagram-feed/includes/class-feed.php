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
	 * website (see Settings → Instagram Feed → Posts on the website).
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
	 * Number of cached posts and how many of them are shown.
	 *
	 * @return array{total:int,visible:int}
	 */
	public static function counts(): array {
		$total   = 0;
		$visible = 0;
		foreach ( Options::get_feed()['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			++$total;
			if ( Options::is_visible( (string) $item['id'] ) ) {
				++$visible;
			}
		}
		return array(
			'total'   => $total,
			'visible' => $visible,
		);
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
