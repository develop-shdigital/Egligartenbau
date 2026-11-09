<?php
/**
 * Wires the plugin into WordPress and Elementor.
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	const SCRIPT_HANDLE = 'shd-igf-slider';

	/**
	 * Register hooks. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( Sync::HOOK, array( Sync::class, 'run_cron' ) );
		add_action( Sync::CONTINUE_HOOK, array( Sync::class, 'run_cron' ) );
		add_action( 'rest_api_init', array( Connector::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( Connect_Service::class, 'register_routes' ) );

		if ( is_admin() ) {
			require_once __DIR__ . '/class-admin.php';
			Admin::init();
		}

		if ( self::elementor_is_compatible() ) {
			add_action( 'elementor/widgets/register', array( self::class, 'register_widget' ) );
			add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
		}
	}

	/**
	 * Load translations from the plugin's languages folder.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'shd-instagram-feed', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Elementor is active and new enough for the widget API used here.
	 *
	 * @return bool
	 */
	public static function elementor_is_compatible(): bool {
		return did_action( 'elementor/loaded' )
			&& defined( 'ELEMENTOR_VERSION' )
			&& version_compare( ELEMENTOR_VERSION, MIN_ELEMENTOR_VERSION, '>=' );
	}

	/**
	 * Register the widget. The class is only loaded once Elementor is ready.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
	 */
	public static function register_widget( $widgets_manager ): void {
		require_once __DIR__ . '/elementor/class-widget.php';
		$widgets_manager->register( new Elementor\Widget() );
	}

	/**
	 * Register the slider script. Elementor only enqueues it on pages that
	 * contain the widget (see Widget::get_script_depends()).
	 */
	public static function register_assets(): void {
		$file = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? 'slider.js' : 'slider.min.js';

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/' . $file, PLUGIN_FILE ),
			array(),
			VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Activation: create options and schedule the sync if already connected.
	 */
	public static function activate(): void {
		Options::install();
		Sync::schedule();
	}

	/**
	 * Deactivation: stop background work. Data is kept until uninstall.
	 */
	public static function deactivate(): void {
		Sync::unschedule();
		delete_transient( Sync::LOCK );
	}
}
