<?php
/**
 * Minimal client for Meta's "Instagram API with Instagram Login".
 *
 * Docs: https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/
 * Only called from the background sync and the settings page, never while a
 * visitor's page is rendering.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Instagram Graph API client.
 */
final class Api_Client {

	const HOST = 'https://graph.instagram.com';

	const DEFAULT_VERSION = 'v27.0';

	/**
	 * Fields requested for each post. `caption` is documented as Facebook
	 * Login only, so a request that Instagram rejects is retried with
	 * MEDIA_FIELDS_MINIMAL.
	 */
	const MEDIA_FIELDS = 'id,caption,alt_text,media_type,media_url,thumbnail_url,permalink,timestamp,children{media_type,media_url,thumbnail_url}';

	const MEDIA_FIELDS_MINIMAL = 'id,media_type,media_url,thumbnail_url,permalink,timestamp,children{media_type,media_url,thumbnail_url}';

	/**
	 * Long-lived Instagram user access token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Access token.
	 */
	public function __construct( string $token ) {
		$this->token = $token;
	}

	/**
	 * Graph API version, filterable for when Meta retires the default.
	 *
	 * @return string
	 */
	public static function version(): string {
		$version = (string) apply_filters( 'shd_igf_api_version', self::DEFAULT_VERSION );
		return preg_match( '/^v\d+\.\d+$/', $version ) ? $version : self::DEFAULT_VERSION;
	}

	/**
	 * The connected professional account.
	 *
	 * @return array|WP_Error
	 */
	public function get_profile() {
		return $this->get( '/me', array( 'fields' => 'user_id,username,account_type' ) );
	}

	/**
	 * The account's most recent posts, newest first.
	 *
	 * @param int $limit Number of posts.
	 * @return array|WP_Error List of raw media objects.
	 */
	public function get_media( int $limit ) {
		$limit  = max( 1, min( 100, $limit ) );
		$result = $this->get(
			'/me/media',
			array(
				'fields' => self::MEDIA_FIELDS,
				'limit'  => $limit,
			)
		);

		if ( is_wp_error( $result ) && self::is_field_error( $result ) ) {
			$result = $this->get(
				'/me/media',
				array(
					'fields' => self::MEDIA_FIELDS_MINIMAL,
					'limit'  => $limit,
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$count = count( $items );
		$pages = 1;

		// Instagram may return fewer posts per page than requested.
		while ( $count < $limit && $pages < 5 && ! empty( $result['paging']['next'] ) ) {
			$next = (string) $result['paging']['next'];
			if ( ! self::is_api_url( $next ) ) {
				break;
			}
			$result = $this->request( $next );
			if ( is_wp_error( $result ) || empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
				break;
			}
			$items = array_merge( $items, $result['data'] );
			$count = count( $items );
			++$pages;
		}

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Exchange the token for a fresh one that is valid for another 60 days.
	 * Instagram only allows this for tokens that are at least 24 hours old.
	 *
	 * @return array|WP_Error `access_token`, `token_type`, `expires_in`.
	 */
	public function refresh_token() {
		return $this->get( '/refresh_access_token', array( 'grant_type' => 'ig_refresh_token' ), false );
	}

	/**
	 * Whether the error means the token is invalid or expired.
	 *
	 * @param WP_Error $error Error from this client.
	 * @return bool
	 */
	public static function is_token_error( WP_Error $error ): bool {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['api_code'] ) && in_array( (int) $data['api_code'], array( 102, 190 ), true );
	}

	/**
	 * Whether the error means a requested field isn't available.
	 *
	 * @param WP_Error $error Error from this client.
	 * @return bool
	 */
	public static function is_field_error( WP_Error $error ): bool {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['api_code'] ) && in_array( (int) $data['api_code'], array( 10, 100, 200 ), true );
	}

	/**
	 * Build and send a GET request.
	 *
	 * @param string $path      API path, starting with a slash.
	 * @param array  $params    Query parameters.
	 * @param bool   $versioned Whether the path is versioned.
	 * @return array|WP_Error Decoded JSON body.
	 */
	private function get( string $path, array $params, bool $versioned = true ) {
		$params['access_token'] = $this->token;
		$url                    = self::HOST . ( $versioned ? '/' . self::version() : '' ) . $path;

		return $this->request( add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $params ) ), $url ) );
	}

	/**
	 * Send a GET request to the API and decode the response.
	 *
	 * @param string $url Full URL including the access token.
	 * @return array|WP_Error
	 */
	private function request( string $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'application/json' ),
				'user-agent'  => 'SHD-Instagram-Feed/' . VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'shd_igf_http',
				sprintf(
					/* translators: %s: technical error message. */
					__( 'Could not reach Instagram: %s', 'shd-instagram-feed' ),
					$this->redact( $response->get_error_message() )
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'shd_igf_bad_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Instagram sent an unexpected response (HTTP %d).', 'shd-instagram-feed' ),
					$status
				),
				array( 'status' => $status )
			);
		}

		if ( isset( $body['error'] ) || $status >= 400 ) {
			$error   = isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
			$message = isset( $error['message'] ) ? (string) $error['message'] : sprintf( 'HTTP %d', $status );

			return new WP_Error(
				'shd_igf_api',
				sanitize_text_field( $this->redact( $message ) ),
				array(
					'status'      => $status,
					'api_code'    => isset( $error['code'] ) ? (int) $error['code'] : 0,
					'api_subcode' => isset( $error['error_subcode'] ) ? (int) $error['error_subcode'] : 0,
				)
			);
		}

		return $body;
	}

	/**
	 * Only follow paging links that point back at the Instagram API.
	 *
	 * @param string $url URL from an API response.
	 * @return bool
	 */
	private static function is_api_url( string $url ): bool {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && 'graph.instagram.com' === wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Never let the token end up in a stored error message.
	 *
	 * @param string $message Message that might contain the request URL.
	 * @return string
	 */
	private function redact( string $message ): string {
		return '' === $this->token ? $message : str_replace( $this->token, '***', $message );
	}
}
