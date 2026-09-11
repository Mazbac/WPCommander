<?php
/**
 * Plugin Name: WPCommander
 * Description: A safe WordPress control plane for ChatGPT Actions.
 * Version: 0.1.5
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Mazbac
 * Text Domain: wpcommander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPCOMMANDER_VERSION', '0.1.5' );
define( 'WPCOMMANDER_FILE', __FILE__ );
define( 'WPCOMMANDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCOMMANDER_URL', plugin_dir_url( __FILE__ ) );

require_once WPCOMMANDER_PATH . 'includes/class-wpcommander-resources.php';
require_once WPCOMMANDER_PATH . 'includes/class-wpcommander.php';

WPCommander::instance();
