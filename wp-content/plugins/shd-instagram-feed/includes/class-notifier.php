<?php
/**
 * Email the people who approve posts when new posts are waiting.
 *
 * One email per sync that found new posts, sent once all their images are
 * imported. Posts that were on Instagram before the account was connected
 * are never announced: whoever connected sees them on the page right away.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Notification emails.
 */
final class Notifier {

	/**
	 * User option (per site): '1' email on, '0' off. Not set: on for
	 * Instagram managers, off for everyone else.
	 */
	const USER_OPTION = 'shd_igf_notify';

	/**
	 * Whether a user gets an email when new posts are waiting.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function wants_email( int $user_id ): bool {
		$value = get_user_option( self::USER_OPTION, $user_id );
		if ( false === $value || '' === $value ) {
			return Access::is_manager( $user_id );
		}
		return '1' === (string) $value;
	}

	/**
	 * Turn the email on or off for a user.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $enabled Whether to send emails.
	 */
	public static function set_wants_email( int $user_id, bool $enabled ): void {
		update_user_option( $user_id, self::USER_OPTION, $enabled ? '1' : '0' );
	}

	/**
	 * Users who get the email.
	 *
	 * @return WP_User[]
	 */
	public static function recipients(): array {
		global $wpdb;

		$candidates = array_merge(
			get_users( array( 'role' => Access::ROLE ) ),
			get_users(
				array(
					'meta_key'   => $wpdb->get_blog_prefix() . self::USER_OPTION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of users, only when new posts arrive.
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
				)
			)
		);

		$users = array();
		foreach ( $candidates as $user ) {
			if ( isset( $users[ $user->ID ] ) || ! is_email( $user->user_email ) || ! user_can( $user, Access::CAP ) || ! self::wants_email( $user->ID ) ) {
				continue;
			}
			$users[ $user->ID ] = $user;
		}

		return array_values( $users );
	}

	/**
	 * Announce posts that are waiting for approval and newer than the last
	 * announcement.
	 *
	 * @return int Number of emails sent.
	 */
	public static function maybe_notify(): int {
		$moderation = Options::get_moderation();
		if ( 'hide' !== $moderation['new_posts'] ) {
			return 0;
		}

		$new    = array();
		$newest = $moderation['notified'];
		foreach ( Feed::awaiting_items() as $item ) {
			$time = (int) ( $item['timestamp'] ?? 0 );
			if ( $time > $moderation['notified'] ) {
				$new[]  = $item;
				$newest = max( $newest, $time );
			}
		}
		if ( ! $new ) {
			return 0;
		}

		// First, so a failing mail server never repeats the same email every hour.
		Options::set_notified( $newest );

		$sent = 0;
		foreach ( self::recipients() as $user ) {
			// Whoever clicked "Check for new posts now" sees the posts already.
			if ( get_current_user_id() === $user->ID ) {
				continue;
			}
			if ( self::send( $user, $new ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Send the email to one user, in their language.
	 *
	 * @param WP_User $user  Recipient.
	 * @param array   $items Posts waiting for approval, newest first.
	 * @return bool Whether the email was handed to the mail server.
	 */
	private static function send( WP_User $user, array $items ): bool {
		$switched = switch_to_user_locale( $user->ID );

		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$count = count( $items );
		$lines = array(
			/* translators: %s: name of the recipient. */
			sprintf( __( 'Hello %s,', 'shd-instagram-feed' ), $user->display_name ),
			'',
			sprintf(
				/* translators: 1: number of posts, 2: Instagram username. */
				_n(
					'%1$d new post from @%2$s is waiting for your approval. It only appears on the website after you approve it.',
					'%1$d new posts from @%2$s are waiting for your approval. They only appear on the website after you approve them.',
					$count,
					'shd-instagram-feed'
				),
				$count,
				Feed::username()
			),
			'',
		);

		foreach ( $items as $item ) {
			$summary = Feed::summary( $item, 90 );
			$lines[] = '- ' . wp_date( (string) get_option( 'date_format' ), (int) $item['timestamp'] ) . ( '' !== $summary ? ': ' . $summary : '' );
			$lines[] = '  ' . $item['permalink'];
		}

		$lines[] = '';
		$lines[] = _n( 'Approve or hide it here:', 'Approve or hide them here:', $count, 'shd-instagram-feed' );
		$lines[] = Connector::page_url() . '#shd-igf-review';
		$lines[] = '';
		$lines[] = '-- ';
		$lines[] = sprintf(
			/* translators: %s: website name. */
			__( 'You get this email because you manage Instagram on %s. To stop it, untick “Email me when new posts are waiting” on the page above.', 'shd-instagram-feed' ),
			$site
		);

		$subject = sprintf(
			/* translators: 1: website name, 2: number of posts. */
			_n( '[%1$s] %2$d new Instagram post is waiting for approval', '[%1$s] %2$d new Instagram posts are waiting for approval', $count, 'shd-instagram-feed' ),
			$site,
			$count
		);

		$sent = wp_mail( $user->user_email, $subject, implode( "\n", $lines ) );

		if ( $switched ) {
			restore_previous_locale();
		}
		return (bool) $sent;
	}
}
