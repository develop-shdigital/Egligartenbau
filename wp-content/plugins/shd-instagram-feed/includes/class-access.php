<?php
/**
 * Who may manage the Instagram feed.
 *
 * Administrators always can. The "Instagram Manager" role gives someone the
 * Instagram page and nothing else of the website: it only adds `read` (to
 * open the WordPress admin and their own profile) and this plugin's
 * capability.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Role and capability.
 */
final class Access {

	/** Approve posts, log in to Instagram, change the feed settings. */
	const CAP = 'shd_igf_manage';

	/** Role for people who only manage Instagram. */
	const ROLE = 'shd_igf_manager';

	/**
	 * Register hooks. Runs on every request: capability checks also happen
	 * in REST requests (the Instagram login callback) and cron.
	 */
	public static function init(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_to_administrators' ) );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 10, 3 );
		add_action( 'login_init', array( self::class, 'skip_login_form' ) );
	}

	/**
	 * The link in the notification email goes through the login page, so it
	 * also works when the login address is hidden (e.g. Solid Security's
	 * "Hide Backend") and the manager isn't logged in. Who is logged in goes
	 * straight on instead of seeing the login form again. Only for links to
	 * the Instagram page.
	 */
	public static function skip_login_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only forwards a logged-in user to the Instagram page.
		$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'login';
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		$special  = isset( $_GET['reauth'] ) || isset( $_GET['interim-login'] );
		// phpcs:enable

		if ( 'login' !== $action || $special || 0 !== strpos( $redirect, Connector::page_url() ) ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( self::CAP ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * After logging in, Instagram managers land on the Instagram page.
	 * WordPress would send users who can't edit posts to their profile.
	 * An explicit destination (e.g. the link in the notification email) is
	 * kept.
	 *
	 * @param string            $redirect_to           Destination.
	 * @param string            $requested_redirect_to Destination asked for in the login link.
	 * @param WP_User|\WP_Error $user                  User who logged in.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		$requested = (string) $requested_redirect_to;
		$default   = '' === $requested || admin_url() === $requested || 'wp-admin/' === $requested;

		if ( $default && $user instanceof WP_User && self::is_limited( $user ) ) {
			return Connector::page_url();
		}
		return $redirect_to;
	}

	/**
	 * Everyone who can manage options can manage the feed, without storing
	 * the capability in their role.
	 *
	 * @param array $allcaps Capabilities of the user.
	 * @return array
	 */
	public static function grant_to_administrators( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	/**
	 * Create the role if it is missing (activation, updates, new sites of a
	 * network). Cheap: roles are already loaded on every request.
	 */
	public static function install_role(): void {
		if ( get_role( self::ROLE ) ) {
			return;
		}

		add_role(
			self::ROLE,
			'Instagram Manager',
			array(
				'read'                 => true,
				self::CAP              => true,
				// Lets plugins that keep customers out of wp-admin (e.g. WooCommerce) let managers in.
				'view_admin_dashboard' => true,
			)
		);
	}

	/**
	 * Whether a user has the Instagram Manager role.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_manager( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Whether the Instagram page is all a user can work with in the admin:
	 * they may manage the feed but not edit content.
	 *
	 * @param WP_User|null $user User, default the current user.
	 * @return bool
	 */
	public static function is_limited( ?WP_User $user = null ): bool {
		$user = $user ?? wp_get_current_user();
		return $user->exists() && user_can( $user, self::CAP ) && ! user_can( $user, 'edit_posts' );
	}
}
