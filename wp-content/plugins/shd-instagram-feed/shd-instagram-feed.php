<?php
/**
 * Plugin Name:       SHD Instagram Feed
 * Plugin URI:        https://www.sh-digital.ch/
 * Description:       Shows your latest Instagram posts in a fast, accessible slider with an Elementor widget. Posts and images are synced in the background and stored locally, so visitors never load anything from Instagram.
 * Version:           0.5.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  elementor
 * Author:            SH Digital
 * Author URI:        https://www.sh-digital.ch/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shd-instagram-feed
 * Domain Path:       /languages
 * Elementor tested up to: 4.3.6
 * Elementor Pro tested up to: 4.3.3
 *
 * @package SHD\InstagramFeed
 */

namespace SHD\InstagramFeed;

defined( 'ABSPATH' ) || exit;

const VERSION               = '0.5.0';
const PLUGIN_FILE           = __FILE__;
const MIN_ELEMENTOR_VERSION = '3.5.0';

require_once __DIR__ . '/includes/class-options.php';
require_once __DIR__ . '/includes/class-api-client.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-sync.php';
require_once __DIR__ . '/includes/class-feed.php';
require_once __DIR__ . '/includes/class-connector.php';
require_once __DIR__ . '/includes/class-connect-service.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
