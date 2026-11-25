<?php
/**
 * Clear page caches when the posts on the website change.
 *
 * The widget is part of the page's HTML. A page cache (SHD Performance,
 * WP Rocket, LiteSpeed …) keeps serving the old HTML until it is cleared, so
 * an approved post would only appear once the cache expires.
 *
 * Caches this plugin doesn't know can listen to the `shd_igf_feed_changed`
 * action, e.g. `add_action( 'shd_igf_feed_changed', 'my_cache_clear_all' );`.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

defined( 'ABSPATH' ) || exit;

/**
 * Page cache integration.
 */
final class Cache {

	/**
	 * Fingerprint of what the widget shows: the visible posts and their images.
	 *
	 * @return string
	 */
	public static function fingerprint(): string {
		$parts = array();
		foreach ( Options::get_feed()['items'] as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) && Options::is_visible( (string) $item['id'] ) ) {
				$parts[] = $item['id'] . ':' . (int) ( $item['attachment_id'] ?? 0 );
			}
		}
		return md5( implode( ',', $parts ) );
	}

	/**
	 * Clear page caches if the posts on the website differ from a fingerprint
	 * taken before a change.
	 *
	 * @param string $before Result of fingerprint() before the change.
	 */
	public static function clear_if_changed( string $before ): void {
		if ( self::fingerprint() !== $before ) {
			self::clear();
		}
	}

	/**
	 * Tell page caches that the website changed, and clear the common ones.
	 */
	public static function clear(): void {
		/**
		 * The posts shown by the Instagram Feed widget changed: clear page caches.
		 */
		do_action( 'shd_igf_feed_changed' );

		// Plugins that offer a function.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); // WP Rocket.
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // W3 Total Cache.
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache.
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache(); // SiteGround Optimizer.
		}

		// Plugins that listen to an action (nothing happens when they aren't installed).
		do_action( 'litespeed_purge_all' ); // LiteSpeed Cache.
		do_action( 'wpfc_clear_all_cache' ); // WP Fastest Cache.
		do_action( 'breeze_clear_all_cache' ); // Breeze.
		do_action( 'cache_enabler_clear_complete_cache' ); // Cache Enabler.
		do_action( 'wphb_clear_page_cache' ); // Hummingbird.
		do_action( 'rt_nginx_helper_purge_all' ); // Nginx Helper.
	}
}
