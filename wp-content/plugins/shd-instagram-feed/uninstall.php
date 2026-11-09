<?php
/**
 * Uninstall: remove the plugin's options, scheduled events and the images it
 * imported. Images uploaded by people are never touched: only attachments
 * carrying the plugin's media-ID meta are deleted.
 *
 * @package SHD\InstagramFeed
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up the current site.
 */
function shd_igf_uninstall_site() {
	global $wpdb;

	$attachment_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup.
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_shd_igf_media_id'
		)
	);

	foreach ( $attachment_ids as $attachment_id ) {
		wp_delete_attachment( (int) $attachment_id, true );
	}

	foreach ( array( 'shd_igf_settings', 'shd_igf_connection', 'shd_igf_feed', 'shd_igf_status' ) as $option ) {
		delete_option( $option );
	}

	delete_transient( 'shd_igf_sync_lock' );
	wp_clear_scheduled_hook( 'shd_igf_sync' );
	wp_clear_scheduled_hook( 'shd_igf_sync_continue' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $shd_igf_site_id ) {
		switch_to_blog( $shd_igf_site_id );
		shd_igf_uninstall_site();
		restore_current_blog();
	}
} else {
	shd_igf_uninstall_site();
}
