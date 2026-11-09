<?php
/**
 * Typed access to the plugin's stored data.
 *
 * Everything is stored in non-autoloaded options: none of it is needed on
 * normal page loads except the feed itself, which is only read when a page
 * actually renders the widget.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

defined( 'ABSPATH' ) || exit;

/**
 * Options storage.
 */
final class Options {

	/** User settings: how many posts to keep, how often to sync. */
	const SETTINGS = 'shd_igf_settings';

	/** Connected account and access token. */
	const CONNECTION = 'shd_igf_connection';

	/** Cached posts that the widget renders. */
	const FEED = 'shd_igf_feed';

	/** Result of the last sync, for the settings page and admin notices. */
	const STATUS = 'shd_igf_status';

	/** Instagram app credentials for "Log in with Instagram". */
	const APP = 'shd_igf_app';

	const MAX_POSTS = 50;

	const INTERVALS = array( 'hourly', 'twicedaily', 'daily' );

	/**
	 * Per-request cache of the feed option.
	 *
	 * @var array|null
	 */
	private static $feed = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'keep'     => 24,
			'interval' => 'hourly',
		);
	}

	/**
	 * Settings merged with defaults and validated.
	 *
	 * @return array{keep:int,interval:string}
	 */
	public static function get_settings(): array {
		$stored = get_option( self::SETTINGS, array() );
		return self::sanitize_settings( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Validate settings coming from the settings form or the database.
	 *
	 * @param mixed $input Raw settings.
	 * @return array{keep:int,interval:string}
	 */
	public static function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::default_settings();

		$keep = isset( $input['keep'] ) ? absint( $input['keep'] ) : $defaults['keep'];
		$keep = max( 1, min( self::MAX_POSTS, $keep ) );

		$interval = isset( $input['interval'] ) ? sanitize_key( $input['interval'] ) : $defaults['interval'];
		if ( ! in_array( $interval, self::INTERVALS, true ) ) {
			$interval = $defaults['interval'];
		}

		return array(
			'keep'     => $keep,
			'interval' => $interval,
		);
	}

	/**
	 * Connected account data.
	 *
	 * @return array{token:string,user_id:string,username:string,account_type:string,connected_at:int,refreshed_at:int,expires_at:int,token_error:string}
	 */
	public static function get_connection(): array {
		$stored = get_option( self::CONNECTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'token'        => isset( $stored['token'] ) ? (string) $stored['token'] : '',
			'user_id'      => isset( $stored['user_id'] ) ? (string) $stored['user_id'] : '',
			'username'     => isset( $stored['username'] ) ? (string) $stored['username'] : '',
			'account_type' => isset( $stored['account_type'] ) ? (string) $stored['account_type'] : '',
			'connected_at' => isset( $stored['connected_at'] ) ? (int) $stored['connected_at'] : 0,
			'refreshed_at' => isset( $stored['refreshed_at'] ) ? (int) $stored['refreshed_at'] : 0,
			'expires_at'   => isset( $stored['expires_at'] ) ? (int) $stored['expires_at'] : 0,
			'token_error'  => isset( $stored['token_error'] ) ? (string) $stored['token_error'] : '',
		);
	}

	/**
	 * Whether an access token is stored.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$connection = self::get_connection();
		return '' !== $connection['token'];
	}

	/**
	 * Store connection data.
	 *
	 * @param array $connection Connection data (see get_connection()).
	 */
	public static function save_connection( array $connection ): void {
		update_option( self::CONNECTION, $connection, false );
	}

	/**
	 * Forget the access token and account.
	 */
	public static function clear_connection(): void {
		delete_option( self::CONNECTION );
	}

	/**
	 * Cached feed.
	 *
	 * @return array{updated_at:int,username:string,items:array}
	 */
	public static function get_feed(): array {
		if ( null === self::$feed ) {
			$stored     = get_option( self::FEED, array() );
			$stored     = is_array( $stored ) ? $stored : array();
			self::$feed = array(
				'updated_at' => isset( $stored['updated_at'] ) ? (int) $stored['updated_at'] : 0,
				'username'   => isset( $stored['username'] ) ? (string) $stored['username'] : '',
				'items'      => isset( $stored['items'] ) && is_array( $stored['items'] ) ? $stored['items'] : array(),
			);
		}
		return self::$feed;
	}

	/**
	 * Store the feed.
	 *
	 * @param array $feed Feed data (see get_feed()).
	 */
	public static function save_feed( array $feed ): void {
		self::$feed = null;
		update_option( self::FEED, $feed, false );
	}

	/**
	 * Last sync status.
	 *
	 * @return array{last_run:int,last_success:int,last_error:string,last_error_at:int,pending:int,imported:int,removed:int}
	 */
	public static function get_status(): array {
		$stored = get_option( self::STATUS, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'last_run'      => isset( $stored['last_run'] ) ? (int) $stored['last_run'] : 0,
			'last_success'  => isset( $stored['last_success'] ) ? (int) $stored['last_success'] : 0,
			'last_error'    => isset( $stored['last_error'] ) ? (string) $stored['last_error'] : '',
			'last_error_at' => isset( $stored['last_error_at'] ) ? (int) $stored['last_error_at'] : 0,
			'pending'       => isset( $stored['pending'] ) ? (int) $stored['pending'] : 0,
			'imported'      => isset( $stored['imported'] ) ? (int) $stored['imported'] : 0,
			'removed'       => isset( $stored['removed'] ) ? (int) $stored['removed'] : 0,
		);
	}

	/**
	 * Merge changes into the sync status.
	 *
	 * @param array $changes Keys to update.
	 */
	public static function update_status( array $changes ): void {
		update_option( self::STATUS, array_merge( self::get_status(), $changes ), false );
	}

	/**
	 * Instagram app credentials. Constants in wp-config.php win over the
	 * settings page, so the secret can be kept out of the database:
	 * define( 'SHD_IGF_APP_ID', '…' ); define( 'SHD_IGF_APP_SECRET', '…' );
	 *
	 * @return array{app_id:string,app_secret:string,id_constant:bool,secret_constant:bool}
	 */
	public static function get_app(): array {
		$stored = get_option( self::APP, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$id_constant     = defined( 'SHD_IGF_APP_ID' ) && '' !== (string) constant( 'SHD_IGF_APP_ID' );
		$secret_constant = defined( 'SHD_IGF_APP_SECRET' ) && '' !== (string) constant( 'SHD_IGF_APP_SECRET' );

		return array(
			'app_id'          => $id_constant ? (string) constant( 'SHD_IGF_APP_ID' ) : ( isset( $stored['app_id'] ) ? (string) $stored['app_id'] : '' ),
			'app_secret'      => $secret_constant ? (string) constant( 'SHD_IGF_APP_SECRET' ) : ( isset( $stored['app_secret'] ) ? (string) $stored['app_secret'] : '' ),
			'id_constant'     => $id_constant,
			'secret_constant' => $secret_constant,
		);
	}

	/**
	 * Whether "Log in with Instagram" can be used.
	 *
	 * @return bool
	 */
	public static function app_is_configured(): bool {
		$app = self::get_app();
		return '' !== $app['app_id'] && '' !== $app['app_secret'];
	}

	/**
	 * Validate app credentials from the settings form. An empty secret field
	 * keeps the saved secret, so it never has to be printed back.
	 *
	 * @param mixed $input Raw input.
	 * @return array{app_id:string,app_secret:string}
	 */
	public static function sanitize_app( $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$stored = get_option( self::APP, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$app_id = isset( $input['app_id'] ) ? preg_replace( '/\D/', '', (string) $input['app_id'] ) : '';
		$secret = isset( $input['app_secret'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['app_secret'] ) : '';

		if ( '' === $secret && isset( $stored['app_secret'] ) ) {
			$secret = (string) $stored['app_secret'];
		}

		return array(
			'app_id'     => (string) $app_id,
			'app_secret' => (string) $secret,
		);
	}

	/**
	 * Create the options as non-autoloaded so they never load on every
	 * request (the app secret in particular). Runs on activation and when the
	 * settings page opens, i.e. before the Settings API could create an
	 * option with the default autoload value.
	 */
	public static function install(): void {
		$names = array( self::SETTINGS, self::CONNECTION, self::FEED, self::STATUS, self::APP );

		foreach ( $names as $name ) {
			if ( false === get_option( $name ) ) {
				add_option( $name, self::SETTINGS === $name ? self::default_settings() : array(), '', false );
			}
		}

		// Fix options created before they were registered here.
		wp_set_option_autoload_values( array_fill_keys( $names, false ) );
	}
}
