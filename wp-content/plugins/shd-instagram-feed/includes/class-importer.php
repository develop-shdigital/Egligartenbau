<?php
/**
 * Downloads post images into the Media Library.
 *
 * Instagram's image URLs are signed and expire, and loading them from
 * Instagram's CDN would send every visitor's IP address to Meta. Storing them
 * as regular attachments gives us stable local files, responsive `srcset`
 * sizes and compatibility with image optimisation plugins.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Image importer.
 */
final class Importer {

	/** Post meta on attachments created by this plugin: the Instagram media ID. */
	const META_MEDIA_ID = '_shd_igf_media_id';

	const MIME_EXTENSIONS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	/**
	 * All attachments created by this plugin.
	 *
	 * @return array<int,array{attachment_id:int,media_id:string}>
	 */
	public static function attachments(): array {
		global $wpdb;

		// Bounded by the plugin's own pruning (at most Options::MAX_POSTS rows plus leftovers).
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- background job, fresh data required.
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
				WHERE pm.meta_key = %s ORDER BY pm.post_id ASC",
				self::META_MEDIA_ID
			),
			ARRAY_A
		);

		$list = array();
		foreach ( (array) $rows as $row ) {
			$list[] = array(
				'attachment_id' => (int) $row['post_id'],
				'media_id'      => (string) $row['meta_value'],
			);
		}
		return $list;
	}

	/**
	 * Whether an attachment still has its file on disk.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_usable( int $attachment_id ): bool {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}
		$file = get_attached_file( $attachment_id );
		return $file && file_exists( $file );
	}

	/**
	 * Download a post image and create an attachment for it.
	 *
	 * @param array $item Normalised post (see Sync::normalize()).
	 * @return int|WP_Error Attachment ID.
	 */
	public static function import( array $item ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $item['image_url'], 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$mime = (string) wp_get_image_mime( $tmp );
		if ( ! isset( self::MIME_EXTENSIONS[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'shd_igf_not_an_image', __( 'The downloaded file is not a supported image.', 'shd-instagram-feed' ) );
		}

		list( $path, $mime ) = self::prepare_file( $tmp, $mime );

		$file = array(
			'name'     => sanitize_file_name( 'instagram-' . $item['id'] . '.' . self::MIME_EXTENSIONS[ $mime ] ),
			'tmp_name' => $path,
		);

		$attachment_id = media_handle_sideload(
			$file,
			0,
			null,
			array(
				'post_title'   => self::title( $item ),
				'post_content' => '',
				'post_excerpt' => '',
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::META_MEDIA_ID, $item['id'] );
		if ( '' !== $item['alt'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $item['alt'] );
		}

		return (int) $attachment_id;
	}

	/**
	 * Delete an attachment created by this plugin, including its files.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function delete( int $attachment_id ): void {
		if ( '' !== (string) get_post_meta( $attachment_id, self::META_MEDIA_ID, true ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Give the temporary file a real extension and convert it to WebP when
	 * the server supports it (smaller files for every generated size).
	 *
	 * @param string $tmp  Temporary file from download_url().
	 * @param string $mime Detected MIME type.
	 * @return array{0:string,1:string} Path and MIME type of the file to import.
	 */
	private static function prepare_file( string $tmp, string $mime ): array {
		$typed = preg_replace( '/\.[^.\/]+$/', '', $tmp ) . '.' . self::MIME_EXTENSIONS[ $mime ];
		if ( $typed !== $tmp ) {
			if ( ! rename( $tmp, $typed ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- temp file in the uploads temp dir.
				return array( $tmp, $mime );
			}
		}

		/**
		 * Whether to convert downloaded Instagram images to WebP.
		 *
		 * @param bool $convert Default true.
		 */
		$convert = (bool) apply_filters( 'shd_igf_convert_to_webp', true );

		if ( 'image/webp' === $mime || ! $convert || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return array( $typed, $mime );
		}

		$editor = wp_get_image_editor( $typed );
		if ( is_wp_error( $editor ) ) {
			return array( $typed, $mime );
		}

		$editor->set_quality( (int) apply_filters( 'shd_igf_webp_quality', 82 ) );
		$saved = $editor->save( preg_replace( '/\.[^.\/]+$/', '.webp', $typed ), 'image/webp' );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return array( $typed, $mime );
		}

		wp_delete_file( $typed );
		return array( $saved['path'], 'image/webp' );
	}

	/**
	 * Attachment title shown in the Media Library.
	 *
	 * @param array $item Normalised post.
	 * @return string
	 */
	private static function title( array $item ): string {
		$date = $item['timestamp'] ? wp_date( get_option( 'date_format' ), $item['timestamp'] ) : '';
		return $date
			/* translators: %s: date. */
			? sprintf( __( 'Instagram post from %s', 'shd-instagram-feed' ), $date )
			: __( 'Instagram post', 'shd-instagram-feed' );
	}
}
