<?php
/**
 * Connecting an Instagram account: "Log in with Instagram" (OAuth) and
 * pasted access tokens.
 *
 * OAuth flow (Business Login for Instagram):
 * 1. An administrator clicks the button; we store a single-use `state` and
 *    send the browser to instagram.com.
 * 2. Instagram redirects back to our REST route with `code` and `state`.
 * 3. We exchange the code for a short-lived token, then for a 60-day token,
 *    save it and run the first sync.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Account connection.
 */
final class Connector {

	const REST_NAMESPACE = 'shd-igf/v1';

	const REST_ROUTE = '/oauth';

	const STATE_PREFIX = 'shd_igf_oauth_';

	/** Time to finish the Instagram login (incl. two-factor authentication). */
	const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	const CAPABILITY = 'manage_options';

	/**
	 * OAuth callback route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle_callback' ),
				// Public by design: Instagram's redirect carries no WordPress nonce.
				// Every request is authorised by the single-use state instead.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The redirect URI to register in the Meta app. No query string, with a
	 * trailing slash (the App Dashboard adds one).
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return (string) apply_filters( 'shd_igf_redirect_uri', rest_url( self::REST_NAMESPACE . self::REST_ROUTE . '/' ) );
	}

	/**
	 * Instagram's login URL for this administrator.
	 *
	 * @param int  $user_id   Administrator who starts the login.
	 * @param bool $reconnect Force a new Instagram login, e.g. to switch accounts.
	 * @return string|WP_Error
	 */
	public static function authorize_url( int $user_id, bool $reconnect = false ) {
		if ( ! Options::app_is_configured() ) {
			return new WP_Error( 'shd_igf_no_app', __( 'Add the Instagram app ID and secret first (Instagram app section below).', 'shd-instagram-feed' ) );
		}

		$state = wp_generate_password( 40, false, false );
		set_transient(
			self::STATE_PREFIX . hash( 'sha256', $state ),
			array(
				'user_id' => $user_id,
				'created' => time(),
			),
			self::STATE_TTL
		);

		$app = Options::get_app();
		return Api_Client::authorize_url( $app['app_id'], self::redirect_uri(), $state, $reconnect );
	}

	/**
	 * Instagram redirects here after the login.
	 *
	 * @param WP_REST_Request $request Request with `code` and `state`, or `error`.
	 * @return WP_REST_Response Redirect to the settings page.
	 */
	public static function handle_callback( WP_REST_Request $request ): WP_REST_Response {
		$state  = (string) $request->get_param( 'state' );
		$key    = self::STATE_PREFIX . hash( 'sha256', $state );
		$stored = '' !== $state ? get_transient( $key ) : false;

		if ( false !== $stored ) {
			delete_transient( $key ); // Single use.
		}

		if ( ! is_array( $stored ) || empty( $stored['user_id'] ) || ! user_can( (int) $stored['user_id'], self::CAPABILITY ) ) {
			return self::redirect( 0, 'error', '' );
		}

		$user_id = (int) $stored['user_id'];
		$error   = self::oauth_error( $request );

		if ( '' !== $error ) {
			if ( 'access_denied' === $error ) {
				return self::redirect( $user_id, 'warning', __( 'The Instagram login was cancelled. Nothing was changed.', 'shd-instagram-feed' ) );
			}
			$description = sanitize_text_field( (string) $request->get_param( 'error_description' ) );
			return self::redirect(
				$user_id,
				'error',
				sprintf(
					/* translators: %s: error message from Instagram. */
					__( 'Instagram login failed: %s', 'shd-instagram-feed' ),
					'' !== $description ? $description : $error
				)
			);
		}

		// Instagram appends "#_" to the redirect; it is not part of the code.
		$code = (string) $request->get_param( 'code' );
		$code = (string) preg_replace( '/#_?$/', '', $code );
		$code = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $code );
		if ( '' === $code ) {
			return self::redirect( $user_id, 'error', __( 'Instagram did not send an authorization code. Please try again.', 'shd-instagram-feed' ) );
		}

		$app = Options::get_app();
		if ( '' === $app['app_id'] || '' === $app['app_secret'] ) {
			return self::redirect( $user_id, 'error', __( 'Add the Instagram app ID and secret first (Instagram app section below).', 'shd-instagram-feed' ) );
		}

		$short = Api_Client::exchange_code( $app['app_id'], $app['app_secret'], self::redirect_uri(), $code );
		$long  = is_wp_error( $short ) ? $short : Api_Client::exchange_long_lived( $app['app_secret'], (string) $short['access_token'] );

		if ( is_wp_error( $long ) ) {
			return self::redirect(
				$user_id,
				'error',
				sprintf(
					/* translators: %s: error message from Instagram. */
					__( 'Instagram login failed: %s', 'shd-instagram-feed' ),
					$long->get_error_message()
				)
			);
		}

		// Attribute the imported images to the administrator who connected.
		wp_set_current_user( $user_id );

		$expires_in = isset( $long['expires_in'] ) ? absint( $long['expires_in'] ) : 0;

		list( $type, $message ) = self::connect( Sync::clean_token( (string) $long['access_token'] ), $expires_in ? time() + $expires_in : 0 );

		return self::redirect( $user_id, $type, $message );
	}

	/**
	 * The `error` Instagram sends when the login is cancelled or fails.
	 *
	 * WordPress core unsets $_GET['error'] for every request that matches a
	 * rewrite rule (a leftover of its 404 handling), REST URLs included, so it
	 * is read from the raw query string, with `error_reason` as a fallback.
	 *
	 * @param WP_REST_Request $request Callback request.
	 * @return string Sanitized error code, empty when there is none.
	 */
	private static function oauth_error( WP_REST_Request $request ): string {
		$error = (string) $request->get_param( 'error' );

		if ( '' === $error && isset( $_SERVER['QUERY_STRING'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed here; only `error` is used, through sanitize_key() below.
			parse_str( (string) wp_unslash( $_SERVER['QUERY_STRING'] ), $query );
			$error = isset( $query['error'] ) && is_string( $query['error'] ) ? $query['error'] : '';
		}

		if ( '' === $error ) {
			$reason = sanitize_key( (string) $request->get_param( 'error_reason' ) );
			$error  = 'user_denied' === $reason ? 'access_denied' : $reason;
		}

		return sanitize_key( $error );
	}

	/**
	 * Check a token with Instagram, store it, schedule the sync and import
	 * the first posts.
	 *
	 * @param string $token      Long-lived access token.
	 * @param int    $expires_at Expiry timestamp, 0 if unknown (assume 60 days).
	 * @return array{0:string,1:string} Notice type and message.
	 */
	public static function connect( string $token, int $expires_at = 0 ): array {
		$client  = new Api_Client( $token );
		$profile = $client->get_profile();

		if ( is_wp_error( $profile ) ) {
			return array(
				'error',
				sprintf(
					/* translators: %s: error message from Instagram. */
					__( 'Instagram did not accept this token: %s', 'shd-instagram-feed' ),
					$profile->get_error_message()
				),
			);
		}

		$user_id = isset( $profile['user_id'] ) ? $profile['user_id'] : ( isset( $profile['id'] ) ? $profile['id'] : '' );

		Options::save_connection(
			array(
				'token'        => $token,
				'user_id'      => preg_replace( '/[^0-9A-Za-z_]/', '', (string) $user_id ),
				'username'     => preg_replace( '/[^A-Za-z0-9._]/', '', isset( $profile['username'] ) ? (string) $profile['username'] : '' ),
				'account_type' => sanitize_text_field( isset( $profile['account_type'] ) ? (string) $profile['account_type'] : '' ),
				'connected_at' => time(),
				'refreshed_at' => 0,
				// Long-lived tokens last 60 days at most.
				'expires_at'   => $expires_at ? $expires_at : time() + 60 * DAY_IN_SECONDS,
				'token_error'  => '',
			)
		);
		Sync::schedule();

		$connection = Options::get_connection();
		$message    = sprintf(
			/* translators: %s: Instagram username. */
			__( 'Connected to @%s.', 'shd-instagram-feed' ),
			$connection['username']
		);

		list( $type, $summary ) = self::sync_message( Sync::run() );

		return array( $type, $message . ' ' . $summary );
	}

	/**
	 * Human-readable result of a sync.
	 *
	 * @param array|WP_Error $result Result of Sync::run().
	 * @return array{0:string,1:string} Notice type and message.
	 */
	public static function sync_message( $result ): array {
		if ( is_wp_error( $result ) ) {
			return array(
				'error',
				sprintf(
					/* translators: %s: error message. */
					__( 'Sync failed: %s', 'shd-instagram-feed' ),
					$result->get_error_message()
				),
			);
		}

		$parts = array(
			sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post is up to date.', '%d posts are up to date.', $result['fetched'], 'shd-instagram-feed' ),
				$result['fetched']
			),
		);

		if ( $result['imported'] ) {
			$parts[] = sprintf(
				/* translators: %d: number of images. */
				_n( '%d new image imported.', '%d new images imported.', $result['imported'], 'shd-instagram-feed' ),
				$result['imported']
			);
		}
		if ( $result['pending'] ) {
			$parts[] = sprintf(
				/* translators: %d: number of images. */
				_n( '%d more image will be imported in the background within a few minutes.', '%d more images will be imported in the background within a few minutes.', $result['pending'], 'shd-instagram-feed' ),
				$result['pending']
			);
		}
		if ( $result['failed'] ) {
			$parts[] = sprintf(
				/* translators: %d: number of images. */
				_n( '%d image could not be imported and will be retried.', '%d images could not be imported and will be retried.', $result['failed'], 'shd-instagram-feed' ),
				$result['failed']
			);
		}

		return array( $result['failed'] ? 'warning' : 'success', implode( ' ', $parts ) );
	}

	/**
	 * Remember a message for the settings page.
	 *
	 * @param int    $user_id User who sees the message.
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 */
	public static function notify( int $user_id, string $type, string $message ): void {
		set_transient(
			'shd_igf_notice_' . $user_id,
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	public static function settings_url(): string {
		return admin_url( 'options-general.php?page=shd-instagram-feed' );
	}

	/**
	 * Send the browser back to the settings page.
	 *
	 * @param int    $user_id User to show the message to; 0 when the state was invalid.
	 * @param string $type    Notice type.
	 * @param string $message Message.
	 * @return WP_REST_Response
	 */
	private static function redirect( int $user_id, string $type, string $message ): WP_REST_Response {
		$url = self::settings_url();

		if ( $user_id ) {
			self::notify( $user_id, $type, $message );
		} else {
			// Unknown or reused state: we can't tell who this is, so only flag it.
			$url = add_query_arg( 'shd_igf_login', 'expired', $url );
		}

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
