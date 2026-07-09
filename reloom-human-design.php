<?php
/**
 * Plugin Name:       Reloom for Human Design
 * Plugin URI:        https://reloom.life
 * Description:       Human Design for WordPress, powered by Reloom (reloom.life). Keep a local roster of people and pull their Bodygraph chart + AI readings through your Reloom account — no Bodygraph or AI keys needed here. One-click Connect; your Reloom plan governs what’s available.
 * Version:           1.2.3
 * Requires at least: 5.8
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Ray Bogman
 * Author URI:        https://bogman.info
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reloom-human-design
 *
 * @package Reloom\HumanDesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RBHDC_VERSION', '1.2.3' );
define( 'RBHDC_PLUGIN_FILE', __FILE__ );
define( 'RBHDC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RBHDC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RBHDC_PLUGIN_DIR . 'includes/class-rbhdc-chart-renderer.php';
require_once RBHDC_PLUGIN_DIR . 'includes/class-rbhdc-client.php';
require_once RBHDC_PLUGIN_DIR . 'includes/class-rbhdc-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		// Translations load automatically for wp.org-hosted plugins since WP 4.6.
		RBHDC_Plugin::init();
	}
);
