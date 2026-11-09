<?php
/**
 * Connecting an Instagram account with the "Login to Instagram" button.
 *
 * The login runs through the SH Digital connect service (see the
 * connect-service folder in the repository), which owns the Meta app and its
 * secret, like Smash Balloon's connect server:
 *
 * 1. The button stores a single-use nonce for the administrator and sends
 *    the browser to the connect service with a state that contains this
 *    site's return address and the nonce.
 * 2. The connect service sends the browser to Instagram. After the login it
 *    exchanges Instagram's code for a 60-day token and posts the token back
 *    to this site's REST route.
 * 3. This site checks the nonce, verifies the token with Instagram, saves it
 *    and runs the first sync. From then on the site renews the token itself.
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

	/**
	 * Address of the SH Digital connect service. Override with the
	 * SHD_IGF_CONNECT_URL constant or the shd_igf_connect_url filter.
	 */
	const DEFAULT_CONNECT_URL = 'https://connect.shdigital.ch/instagram/';

	const REST_NAMESPACE = 'shd-igf/v1';

	const REST_ROUTE = '/oauth';

	const STATE_PREFIX = 'shd_igf_oauth_';

	/** Time to finish the Instagram login (incl. two-factor authentication). */
	const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	const CAPABILITY = 'manage_options';

	/**
	 * Route that receives the token from the connect service.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_callback' ),
				// Public by design: the connect service posts here from the
				// visitor's browser without a WordPress nonce. Every request is
				// authorised by the single-use nonce in the state instead.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The connect service address.
	 *
	 * @return string
	 */
	public static function connect_url(): string {
		$url = defined( 'SHD_IGF_CONNECT_URL' ) ? (string) constant( 'SHD_IGF_CONNECT_URL' ) : self::DEFAULT_CONNECT_URL;
		return esc_url_raw( (string) apply_filters( 'shd_igf_connect_url', $url ) );
	}

	/**
	 * Where the connect service sends the token back to.
	 *
	 * @return string
	 */
	public static function return_url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE . '/' );
	}

	/**
	 * Address the "Login to Instagram" button leads to.
	 *
	 * @param int  $user_id   Administrator who clicks the button.
	 * @param bool $reconnect Ask Instagram for a new login, e.g. to switch accounts.
	 * @return string|WP_Error
	 */
	public static function login_url( int $user_id, bool $reconnect = false ) {
		$connect = self::connect_url();
		if ( '' === $connect ) {
			return new WP_Error( 'shd_igf_no_connect_service', __( 'The Instagram login is not available: no connect service is configured.', 'shd-instagram-feed' ) );
		}

		$nonce = wp_generate_password( 40, false, false );
		set_transient(
			self::STATE_PREFIX . hash( 'sha256', $nonce ),
			array(
				'user_id' => $user_id,
				'created' => time(),
			),
			self::STATE_TTL
		);

		$state = self::encode_state(
			array(
				'v' => 1,
				'r' => self::return_url(),
				'n' => $nonce,
			)
		);

		$args = array(
			'start' => '1',
			'state' => $state,
		);
		if ( $reconnect ) {
			$args['reauth'] = '1';
		}

		return add_query_arg( array_map( 'rawurlencode', $args ), $connect );
	}

	/**
	 * The connect service posts the result of the login here.
	 *
	 * @param WP_REST_Request $request Body: `state` plus `access_token` and `expires_in`, or `error`.
	 * @return WP_REST_Response Redirect to the settings page.
	 */
	public static function handle_callback( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_body_params();
		$state  = self::decode_state( isset( $params['state'] ) ? (string) $params['state'] : '' );
		$nonce  = isset( $state['n'] ) && is_string( $state['n'] ) ? $state['n'] : '';
		$key    = self::STATE_PREFIX . hash( 'sha256', $nonce );
		$stored = '' !== $nonce ? get_transient( $key ) : false;

		if ( false !== $stored ) {
			delete_transient( $key ); // Single use.
		}

		if ( ! is_array( $stored ) || empty( $stored['user_id'] ) || ! user_can( (int) $stored['user_id'], self::CAPABILITY ) ) {
			return self::redirect( 0, 'error', '' );
		}

		$user_id = (int) $stored['user_id'];
		$error   = isset( $params['error'] ) ? sanitize_key( (string) $params['error'] ) : '';

		if ( '' !== $error ) {
			if ( 'access_denied' === $error ) {
				return self::redirect( $user_id, 'warning', __( 'The Instagram login was cancelled. Nothing was changed.', 'shd-instagram-feed' ) );
			}
			$description = isset( $params['error_description'] ) ? sanitize_text_field( (string) $params['error_description'] ) : '';
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

		$token = isset( $params['access_token'] ) ? Sync::clean_token( (string) $params['access_token'] ) : '';
		if ( '' === $token ) {
			return self::redirect( $user_id, 'error', __( 'Instagram did not return an access token. Please try again.', 'shd-instagram-feed' ) );
		}

		$expires_in = isset( $params['expires_in'] ) ? absint( $params['expires_in'] ) : 0;

		// Attribute the imported images to the administrator who connected.
		wp_set_current_user( $user_id );

		list( $type, $message ) = self::connect( $token, $expires_in ? time() + $expires_in : 0 );

		return self::redirect( $user_id, $type, $message );
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
	 * Base64url-encoded JSON, safe in URLs without further escaping.
	 *
	 * @param array $data State data.
	 * @return string
	 */
	private static function encode_state( array $data ): string {
		return rtrim( strtr( base64_encode( (string) wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport of a JSON state, not obfuscation.
	}

	/**
	 * Decode a state created by encode_state().
	 *
	 * @param string $state State.
	 * @return array Empty when invalid.
	 */
	private static function decode_state( string $state ): array {
		if ( '' === $state || strlen( $state ) > 2048 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $state ) ) {
			return array();
		}
		$json = base64_decode( strtr( $state, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encode_state().
		$data = false === $json ? null : json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Send the browser to the settings page (303: the callback is a POST).
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

		$response = new WP_REST_Response( null, 303 );
		$response->header( 'Location', $url );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
