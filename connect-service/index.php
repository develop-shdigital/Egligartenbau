<?php
/**
 * SHD Instagram Feed – connect service.
 *
 * Lets every WordPress site running the SHD Instagram Feed plugin connect an
 * Instagram account with one "Login to Instagram" button, through one Meta
 * app owned by SH Digital. The app secret never leaves this server.
 *
 * 1. WordPress → GET ?start=1&state=…      Redirect to Instagram's login.
 * 2. Instagram → GET ?code=…&state=…       Exchange the code for a 60-day
 *    token and post it back to the WordPress site (auto-submitting form).
 *    Cancelled or failed logins are posted back as an error.
 *
 * The state is created by the plugin: base64url JSON with the site's return
 * address ("r") and a single-use nonce ("n") that the site checks. This
 * service stores nothing.
 *
 * Configuration: environment variables or config.php (see config.sample.php).
 * Requires PHP 7.4+ with the curl extension and HTTPS.
 */

declare( strict_types=1 );

const SHD_CONNECT_VERSION = '1.0.0';

/** Path of the plugin's REST route that receives the token. */
const SHD_CONNECT_RETURN_PATH = '/shd-igf/v1/oauth/';

header( 'Cache-Control: no-store' );
header( 'Referrer-Policy: no-referrer' );
header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: DENY' );
header( 'X-Robots-Tag: noindex, nofollow' );

$config = shd_connect_config();

if ( isset( $_GET['health'] ) ) {
	header( 'Content-Type: application/json' );
	echo json_encode(
		array(
			'ok'         => '' !== $config['app_id'] && '' !== $config['app_secret'] && '' !== $config['redirect_uri'],
			'version'    => SHD_CONNECT_VERSION,
			'restricted' => (bool) $config['allowed_hosts'],
		)
	);
	exit;
}

if ( '' === $config['app_id'] || '' === $config['app_secret'] || '' === $config['redirect_uri'] ) {
	shd_connect_page( 500, 'The Instagram connect service is not configured yet.' );
}

$state  = shd_connect_param( 'state' );
$return = shd_connect_return_url( $state, $config );

if ( null === $return ) {
	shd_connect_page( 400, 'This login link is not valid or this website may not use it. Please start again in WordPress under Settings → Instagram Feed.' );
}

// Step 1: send the browser to Instagram's login.
if ( isset( $_GET['start'] ) ) {
	$params = array(
		'client_id'     => $config['app_id'],
		'redirect_uri'  => $config['redirect_uri'],
		'response_type' => 'code',
		'scope'         => 'instagram_business_basic',
		'state'         => $state,
	);
	if ( '' !== shd_connect_param( 'reauth' ) ) {
		$params['force_reauth'] = 'true';
	}

	header( 'Location: https://www.instagram.com/oauth/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), true, 302 );
	exit;
}

// Step 2: back from Instagram.
$error = shd_connect_param( 'error' );
$code  = (string) preg_replace( '/#_?$/', '', shd_connect_param( 'code' ) );

if ( '' !== $error || '' === $code ) {
	shd_connect_post_back(
		$return,
		array(
			'state'             => $state,
			'error'             => '' !== $error ? $error : 'missing_code',
			'error_description' => shd_connect_param( 'error_description' ),
		)
	);
}

$short = shd_connect_request(
	$config['api_url'] . '/oauth/access_token',
	array(
		'client_id'     => $config['app_id'],
		'client_secret' => $config['app_secret'],
		'grant_type'    => 'authorization_code',
		'redirect_uri'  => $config['redirect_uri'],
		'code'          => $code,
	)
);

// Meta documents the token inside a "data" list; older responses were not wrapped.
if ( isset( $short['data'][0] ) && is_array( $short['data'][0] ) ) {
	$short = $short['data'][0];
}

if ( empty( $short['access_token'] ) || ! is_string( $short['access_token'] ) ) {
	shd_connect_post_back( $return, shd_connect_failure( $state, $short, $config ) );
}

$long = shd_connect_request(
	$config['graph_url'] . '/access_token?' . http_build_query(
		array(
			'grant_type'    => 'ig_exchange_token',
			'client_secret' => $config['app_secret'],
			'access_token'  => $short['access_token'],
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	)
);

if ( empty( $long['access_token'] ) || ! is_string( $long['access_token'] ) ) {
	shd_connect_post_back( $return, shd_connect_failure( $state, $long, $config ) );
}

shd_connect_post_back(
	$return,
	array(
		'state'        => $state,
		'access_token' => $long['access_token'],
		'expires_in'   => (string) (int) ( $long['expires_in'] ?? 0 ),
	)
);

/**
 * Settings from environment variables, falling back to config.php.
 *
 * @return array{app_id:string,app_secret:string,redirect_uri:string,allowed_hosts:string[],allow_http_localhost:bool,api_url:string,graph_url:string}
 */
function shd_connect_config(): array {
	$file = __DIR__ . '/config.php';
	$file = is_readable( $file ) ? require $file : array();
	$file = is_array( $file ) ? $file : array();

	$get = static function ( string $key, string $env, $fallback ) use ( $file ) {
		$value = getenv( $env );
		if ( false !== $value && '' !== $value ) {
			return $value;
		}
		return array_key_exists( $key, $file ) ? $file[ $key ] : $fallback;
	};

	$hosts = $get( 'allowed_hosts', 'SHD_IGF_ALLOWED_HOSTS', array() );
	if ( is_string( $hosts ) ) {
		$hosts = explode( ',', $hosts );
	}
	$hosts = array_values( array_filter( array_map( static function ( $host ): string {
		return strtolower( trim( (string) $host ) );
	}, (array) $hosts ) ) );

	return array(
		'app_id'               => trim( (string) $get( 'app_id', 'SHD_IGF_APP_ID', '' ) ),
		'app_secret'           => trim( (string) $get( 'app_secret', 'SHD_IGF_APP_SECRET', '' ) ),
		'redirect_uri'         => trim( (string) $get( 'redirect_uri', 'SHD_IGF_REDIRECT_URI', '' ) ),
		'allowed_hosts'        => $hosts,
		// Testing only: accept http://localhost return addresses and other API hosts.
		'allow_http_localhost' => (bool) filter_var( $get( 'allow_http_localhost', 'SHD_IGF_ALLOW_HTTP_LOCALHOST', false ), FILTER_VALIDATE_BOOLEAN ),
		'api_url'              => rtrim( (string) $get( 'api_url', 'SHD_IGF_API_URL', 'https://api.instagram.com' ), '/' ),
		'graph_url'            => rtrim( (string) $get( 'graph_url', 'SHD_IGF_GRAPH_URL', 'https://graph.instagram.com' ), '/' ),
	);
}

/**
 * A query parameter as string.
 *
 * @param string $name Parameter name.
 * @return string
 */
function shd_connect_param( string $name ): string {
	return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? $_GET[ $name ] : '';
}

/**
 * The WordPress address from the state, if it may receive a token: https,
 * the plugin's REST route, no credentials, and an allowed host.
 *
 * @param string $state  State created by the plugin.
 * @param array  $config Configuration.
 * @return string|null
 */
function shd_connect_return_url( string $state, array $config ): ?string {
	if ( '' === $state || strlen( $state ) > 2048 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $state ) ) {
		return null;
	}

	$json = base64_decode( strtr( $state, '-_', '+/' ), true );
	$data = false === $json ? null : json_decode( $json, true );

	if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! is_string( $data['r'] ?? null ) || ! is_string( $data['n'] ?? null ) || strlen( $data['n'] ) < 32 ) {
		return null;
	}

	$url   = $data['r'];
	$parts = parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
		return null;
	}

	$host   = strtolower( $parts['host'] );
	$scheme = strtolower( $parts['scheme'] ?? '' );
	$local  = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );

	if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local && $config['allow_http_localhost'] ) ) {
		return null;
	}

	$path   = $parts['path'] ?? '/';
	$query  = $parts['query'] ?? '';
	$pretty = '' === $query && SHD_CONNECT_RETURN_PATH === substr( $path, -strlen( SHD_CONNECT_RETURN_PATH ) );
	$plain  = false;

	if ( '' !== $query ) {
		parse_str( $query, $vars );
		$plain = array( 'rest_route' ) === array_keys( $vars ) && SHD_CONNECT_RETURN_PATH === $vars['rest_route'];
	}

	if ( ! $pretty && ! $plain ) {
		return null;
	}

	if ( $config['allowed_hosts'] && ! shd_connect_host_allowed( $host, $config['allowed_hosts'] ) ) {
		return null;
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
function shd_connect_host_allowed( string $host, array $allowed ): bool {
	foreach ( $allowed as $pattern ) {
		if ( $pattern === $host ) {
			return true;
		}
		if ( 0 === strpos( $pattern, '*.' ) ) {
			$suffix = substr( $pattern, 1 );
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Call Instagram and decode the JSON response. POST when fields are given.
 *
 * @param string $url    URL.
 * @param array  $fields POST fields.
 * @return array Decoded response, or `error_message` on failure.
 */
function shd_connect_request( string $url, array $fields = array() ): array {
	if ( ! function_exists( 'curl_init' ) ) {
		return array( 'error_message' => 'The connect service needs the PHP curl extension.' );
	}

	$curl = curl_init( $url );
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
			CURLOPT_USERAGENT      => 'SHD-Instagram-Connect/' . SHD_CONNECT_VERSION,
		)
	);
	if ( $fields ) {
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ) );
	}

	$body   = curl_exec( $curl );
	$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	$error  = curl_error( $curl );
	curl_close( $curl );

	if ( false === $body ) {
		return array( 'error_message' => 'Could not reach Instagram: ' . $error );
	}

	$data = json_decode( (string) $body, true );
	return is_array( $data ) ? $data : array( 'error_message' => 'Instagram sent an unexpected response (HTTP ' . $status . ').' );
}

/**
 * Error fields for the WordPress site. Never includes the app secret.
 *
 * @param string $state  State.
 * @param array  $data   Instagram's response.
 * @param array  $config Configuration.
 * @return array
 */
function shd_connect_failure( string $state, array $data, array $config ): array {
	if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
		$message = $data['error']['message'];
	} elseif ( isset( $data['error_message'] ) && is_string( $data['error_message'] ) ) {
		$message = $data['error_message'];
	} elseif ( isset( $data['error_description'] ) && is_string( $data['error_description'] ) ) {
		$message = $data['error_description'];
	} else {
		$message = 'Instagram did not return an access token.';
	}

	return array(
		'state'             => $state,
		'error'             => 'exchange_failed',
		'error_description' => str_replace( $config['app_secret'], '***', $message ),
	);
}

/**
 * Post fields to the WordPress site with an auto-submitting form, so the
 * token travels in a POST body and never appears in an address.
 *
 * @param string $url    WordPress return address (validated).
 * @param array  $fields Fields.
 */
function shd_connect_post_back( string $url, array $fields ): void {
	$parts  = parse_url( $url );
	$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	$script = 'document.getElementById("shd-connect").submit();';

	header( 'Content-Type: text/html; charset=utf-8' );
	header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-" . base64_encode( hash( 'sha256', $script, true ) ) . "'; form-action " . $origin . "; base-uri 'none'; frame-ancestors 'none'" );

	$inputs = '';
	foreach ( $fields as $name => $value ) {
		$inputs .= '<input type="hidden" name="' . shd_connect_escape( (string) $name ) . '" value="' . shd_connect_escape( (string) $value ) . '">';
	}

	echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Instagram</title>',
		'<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f0f0f1;color:#1d2327;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}main{background:#fff;padding:32px 40px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.12);text-align:center}button{font:inherit;margin-top:12px;padding:8px 18px;border:0;border-radius:4px;background:#2271b1;color:#fff;cursor:pointer}</style></head>',
		'<body><main><p>Zurück zu WordPress …<br>Returning to WordPress …</p>',
		'<form id="shd-connect" method="post" action="', shd_connect_escape( $url ), '">', $inputs, '<noscript><button type="submit">Weiter / Continue</button></noscript></form>',
		'</main><script>', $script, '</script></body></html>';
	exit;
}

/**
 * Simple message page.
 *
 * @param int    $status  HTTP status.
 * @param string $message Message.
 */
function shd_connect_page( int $status, string $message ): void {
	http_response_code( $status );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'" );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Instagram connect</title>',
		'<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f0f0f1;color:#1d2327;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}main{max-width:520px;background:#fff;padding:32px 40px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.12)}</style></head>',
		'<body><main><p>', shd_connect_escape( $message ), '</p></main></body></html>';
	exit;
}

/**
 * Escape for HTML text and attributes.
 *
 * @param string $value Value.
 * @return string
 */
function shd_connect_escape( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
