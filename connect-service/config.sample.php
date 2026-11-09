<?php
/**
 * Connect service configuration. Copy to config.php and fill in, or set the
 * environment variables named in the comments instead (they take priority).
 *
 * Keep config.php out of version control: it contains the app secret.
 */

return array(
	// Meta App Dashboard → your app → Instagram → API setup with Instagram
	// business login → Set up Instagram business login → Business login settings.
	'app_id'        => '', // SHD_IGF_APP_ID: "Instagram app ID".
	'app_secret'    => '', // SHD_IGF_APP_SECRET: "Instagram app secret".

	// The public address of this service, exactly as entered under
	// "OAuth redirect URIs" in the same settings (with the trailing slash).
	'redirect_uri'  => 'https://connect.shdigital.ch/instagram/', // SHD_IGF_REDIRECT_URI.

	// Optional: only these WordPress sites may log in through this service,
	// e.g. array( 'egligartenbau.com', 'www.egligartenbau.com', '*.shdigital.ch' ).
	// Empty: any https site running the plugin.
	'allowed_hosts' => array(), // SHD_IGF_ALLOWED_HOSTS: comma-separated.
);
