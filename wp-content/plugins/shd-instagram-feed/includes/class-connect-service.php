<?php
/**
 * Login service mode: lets every other site running this plugin use the
 * "Login to Instagram" button through one Meta app.
 *
 * Only active on the website that defines the Meta app credentials in
 * wp-config.php (SH Digital's own site), so the app secret is never part of
 * the plugin zip that client sites install:
 *
 *     define( 'SHD_IGF_APP_ID', '…' );     // Instagram app ID.
 *     define( 'SHD_IGF_APP_SECRET', '…' ); // Instagram app secret.
 *
 * Route GET /wp-json/shd-igf/v1/connect/:
 * 1. ?start=1&state=…  from a client site: redirect to Instagram's login.
 * 2. ?code=…&state=…   from Instagram: exchange the code for a 60-day token
 *    and post it back to the client site with an auto-submitting form, so the
 *    token never appears in an address. Errors are posted back the same way.
 *
 * The state comes from the client site (see Connector): base64url JSON with
 * its return address ("r") and a single-use nonce ("n") that only the client
 * site can check. Nothing is stored here.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Login service.
 */
final class Connect_Service {

	const REST_ROUTE = '/connect';

	/**
	 * Whether this site provides the login for other sites.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '' !== self::app_id() && '' !== self::app_secret();
	}

	/**
	 * Route used by the button (start) and by Instagram (callback). Public by
	 * design: browsers arrive here from other sites and from Instagram.
	 */
	public static function register_routes(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		register_rest_route(
			Connector::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The address to register as OAuth redirect URI in the Meta app.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return (string) apply_filters( 'shd_igf_service_redirect_uri', rest_url( Connector::REST_NAMESPACE . self::REST_ROUTE . '/' ) );
	}

	/**
	 * Hosts allowed to use the service; empty means every https site running
	 * the plugin. SHD_IGF_ALLOWED_HOSTS: comma-separated, "*.example.com" allowed.
	 *
	 * @return string[]
	 */
	public static function allowed_hosts(): array {
		$hosts = defined( 'SHD_IGF_ALLOWED_HOSTS' ) ? (string) constant( 'SHD_IGF_ALLOWED_HOSTS' ) : '';
		$hosts = array_filter( array_map( 'trim', explode( ',', strtolower( $hosts ) ) ) );

		return array_values( (array) apply_filters( 'shd_igf_service_allowed_hosts', $hosts ) );
	}

	/**
	 * Handle a start request or Instagram's callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Only for the health check; other steps exit.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( null !== $request->get_param( 'health' ) ) {
			$response = new WP_REST_Response(
				array(
					'ok'         => true,
					'version'    => VERSION,
					'restricted' => (bool) self::allowed_hosts(),
				)
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		$state  = (string) $request->get_param( 'state' );
		$return = self::return_url( $state );

		if ( '' === $return ) {
			self::message_page( 400, __( 'This login link is not valid or this website may not use it. Please start again in WordPress under Settings → Instagram Feed.', 'shd-instagram-feed' ) );
		}

		// Step 1: send the browser to Instagram's login.
		if ( null !== $request->get_param( 'start' ) ) {
			$params = array(
				'client_id'     => self::app_id(),
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => 'instagram_business_basic',
				'state'         => $state,
			);
			if ( null !== $request->get_param( 'reauth' ) ) {
				$params['force_reauth'] = 'true';
			}

			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			header( 'Location: https://www.instagram.com/oauth/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), true, 302 );
			exit;
		}

		// Step 2: back from Instagram.
		$error = self::query_error( $request );
		$code  = (string) $request->get_param( 'code' );
		$code  = (string) preg_replace( '/#_?$/', '', $code );
		$code  = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $code );

		if ( '' !== $error || '' === $code ) {
			self::post_back(
				$return,
				array(
					'state'             => $state,
					'error'             => '' !== $error ? $error : 'missing_code',
					'error_description' => sanitize_text_field( (string) $request->get_param( 'error_description' ) ),
				)
			);
		}

		$short = self::call(
			'POST',
			'https://api.instagram.com/oauth/access_token',
			array(
				'client_id'     => self::app_id(),
				'client_secret' => self::app_secret(),
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => self::redirect_uri(),
				'code'          => $code,
			)
		);

		// Meta documents the token inside a "data" list; older responses were not wrapped.
		if ( isset( $short['data'][0] ) && is_array( $short['data'][0] ) ) {
			$short = $short['data'][0];
		}

		if ( empty( $short['access_token'] ) || ! is_string( $short['access_token'] ) ) {
			self::post_back( $return, self::failure( $state, $short ) );
		}

		$long = self::call(
			'GET',
			add_query_arg(
				array(
					'grant_type'    => 'ig_exchange_token',
					'client_secret' => rawurlencode( self::app_secret() ),
					'access_token'  => rawurlencode( $short['access_token'] ),
				),
				'https://graph.instagram.com/access_token'
			)
		);

		if ( empty( $long['access_token'] ) || ! is_string( $long['access_token'] ) ) {
			self::post_back( $return, self::failure( $state, $long ) );
		}

		self::post_back(
			$return,
			array(
				'state'        => $state,
				'access_token' => $long['access_token'],
				'expires_in'   => (string) absint( $long['expires_in'] ?? 0 ),
			)
		);
	}

	/**
	 * The client site's return address from the state, or '' when it may not
	 * receive a token: it must be the plugin's route, on https (http only for
	 * localhost with SHD_IGF_ALLOW_HTTP_LOCALHOST), without credentials, and on
	 * an allowed host.
	 *
	 * @param string $state State created by Connector::login_url().
	 * @return string
	 */
	public static function return_url( string $state ): string {
		if ( '' === $state || strlen( $state ) > 2048 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $state ) ) {
			return '';
		}

		$json = base64_decode( strtr( $state, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe transport of a JSON state, not obfuscation.
		$data = false === $json ? null : json_decode( $json, true );

		if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! is_string( $data['r'] ?? null ) || ! is_string( $data['n'] ?? null ) || strlen( $data['n'] ) < 32 ) {
			return '';
		}

		$url   = $data['r'];
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$host   = strtolower( $parts['host'] );
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$local  = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
		$http   = defined( 'SHD_IGF_ALLOW_HTTP_LOCALHOST' ) && constant( 'SHD_IGF_ALLOW_HTTP_LOCALHOST' );

		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local && $http ) ) {
			return '';
		}

		$suffix = '/' . Connector::REST_NAMESPACE . Connector::REST_ROUTE . '/';
		$path   = $parts['path'] ?? '/';
		$query  = $parts['query'] ?? '';
		$pretty = '' === $query && substr( $path, -strlen( $suffix ) ) === $suffix;
		$plain  = false;

		if ( '' !== $query ) {
			parse_str( $query, $vars );
			$plain = array( 'rest_route' ) === array_keys( $vars ) && $suffix === $vars['rest_route'];
		}

		if ( ! $pretty && ! $plain ) {
			return '';
		}

		$allowed = self::allowed_hosts();
		if ( $allowed && ! self::host_allowed( $host, $allowed ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Whether a host matches the allowlist ("example.com" or "*.example.com").
	 *
	 * @param string   $host    Host.
	 * @param string[] $allowed Allowed hosts.
	 * @return bool
	 */
	private static function host_allowed( string $host, array $allowed ): bool {
		foreach ( $allowed as $pattern ) {
			if ( $pattern === $host ) {
				return true;
			}
			if ( 0 === strpos( $pattern, '*.' ) ) {
				$tail = substr( $pattern, 1 );
				if ( strlen( $host ) > strlen( $tail ) && substr( $host, -strlen( $tail ) ) === $tail ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Instagram's `error` parameter. WordPress core unsets $_GET['error'] for
	 * every request that matches a rewrite rule (REST URLs included), so it is
	 * read from the raw query string, with `error_reason` as a fallback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function query_error( WP_REST_Request $request ): string {
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
	 * Call Instagram and decode the JSON answer.
	 *
	 * @param string $method `GET` or `POST`.
	 * @param string $url    URL.
	 * @param array  $body   POST fields.
	 * @return array Decoded response, or `error_message` on failure.
	 */
	private static function call( string $method, string $url, array $body = array() ): array {
		$args = array(
			'timeout'    => 20,
			'headers'    => array( 'Accept' => 'application/json' ),
			'user-agent' => 'SHD-Instagram-Feed/' . VERSION . '; ' . home_url( '/' ),
		);

		if ( 'POST' === $method ) {
			$args['body'] = $body;
			$response     = wp_safe_remote_post( $url, $args );
		} else {
			$response = wp_safe_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'error_message' => sprintf(
					/* translators: %s: technical error message. */
					__( 'Could not reach Instagram: %s', 'shd-instagram-feed' ),
					$response->get_error_message()
				),
			);
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : array(
			'error_message' => sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Instagram sent an unexpected response (HTTP %d).', 'shd-instagram-feed' ),
				(int) wp_remote_retrieve_response_code( $response )
			),
		);
	}

	/**
	 * Error fields for the client site. Never includes the app secret.
	 *
	 * @param string $state State.
	 * @param array  $data  Instagram's answer.
	 * @return array
	 */
	private static function failure( string $state, array $data ): array {
		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			$message = $data['error']['message'];
		} elseif ( isset( $data['error_message'] ) && is_string( $data['error_message'] ) ) {
			$message = $data['error_message'];
		} elseif ( isset( $data['error_description'] ) && is_string( $data['error_description'] ) ) {
			$message = $data['error_description'];
		} else {
			$message = __( 'Instagram did not return an access token.', 'shd-instagram-feed' );
		}

		return array(
			'state'             => $state,
			'error'             => 'exchange_failed',
			'error_description' => sanitize_text_field( str_replace( self::app_secret(), '***', $message ) ),
		);
	}

	/**
	 * Post fields to the client site with an auto-submitting form.
	 *
	 * @param string $url    Validated return address.
	 * @param array  $fields Fields.
	 */
	private static function post_back( string $url, array $fields ): void {
		$parts  = wp_parse_url( $url );
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$script = 'document.getElementById("shd-igf-connect").submit();';

		self::page_headers( "default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-" . base64_encode( hash( 'sha256', $script, true ) ) . "'; form-action " . $origin . "; base-uri 'none'; frame-ancestors 'none'" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP hash.

		$inputs = '';
		foreach ( $fields as $name => $value ) {
			$inputs .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}

		self::page(
			'<p>' . esc_html__( 'Returning to WordPress …', 'shd-instagram-feed' ) . '</p>'
			. '<form id="shd-igf-connect" method="post" action="' . esc_url( $url ) . '">' . $inputs
			. '<noscript><button type="submit">' . esc_html__( 'Continue', 'shd-instagram-feed' ) . '</button></noscript></form>'
			. '<script>' . $script . '</script>'
		);
	}

	/**
	 * Show a message and stop.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 */
	private static function message_page( int $status, string $message ): void {
		status_header( $status );
		self::page_headers( "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'" );
		self::page( '<p>' . esc_html( $message ) . '</p>' );
	}

	/**
	 * Headers for the service's HTML pages.
	 *
	 * @param string $csp Content-Security-Policy.
	 */
	private static function page_headers( string $csp ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Security-Policy: ' . $csp );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	/**
	 * Minimal HTML page, then exit.
	 *
	 * @param string $body Escaped body HTML.
	 */
	private static function page( string $body ): void {
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Instagram</title>'
			. '<style>@view-transition{navigation:auto}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f0f0f1;color:#1d2327;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}main{max-width:520px;background:#fff;padding:32px 40px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.12);text-align:center}button{font:inherit;margin-top:12px;padding:8px 18px;border:0;border-radius:4px;background:#2271b1;color:#fff;cursor:pointer}</style></head>'
			. '<body><main>' . $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- body is escaped by the callers.
		exit;
	}

	/**
	 * Instagram app ID from wp-config.php.
	 *
	 * @return string
	 */
	private static function app_id(): string {
		return defined( 'SHD_IGF_APP_ID' ) ? trim( (string) constant( 'SHD_IGF_APP_ID' ) ) : '';
	}

	/**
	 * Instagram app secret from wp-config.php.
	 *
	 * @return string
	 */
	private static function app_secret(): string {
		return defined( 'SHD_IGF_APP_SECRET' ) ? trim( (string) constant( 'SHD_IGF_APP_SECRET' ) ) : '';
	}
}
