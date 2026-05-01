<?php

/**
 * Plugin Name:       Product Health Check for WooCommerce
 * Plugin URI:        https://github.com/Open-WP-Club/product-health-check-woocommerce
 * Description:       Scans WooCommerce products for common issues such as missing images, empty SKUs, missing prices, and more.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            OpenWPClub.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-product-health-check
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WPHC_VERSION', '1.0.0' );
define( 'WPHC_PLUGIN_FILE', __FILE__ );
define( 'WPHC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPHC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation hook — verify WooCommerce is active.
 */
function wphc_activate() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
		deactivate_plugins( plugin_basename( WPHC_PLUGIN_FILE ) );
		wp_die(
			esc_html__( 'WooCommerce Product Health Check requires WooCommerce to be installed and active.', 'wc-product-health-check' ),
			esc_html__( 'Plugin Activation Error', 'wc-product-health-check' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( WPHC_PLUGIN_FILE, 'wphc_activate' );

/**
 * Boot the plugin after all plugins have loaded.
 */
function wphc_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once WPHC_PLUGIN_DIR . 'includes/class-health-checker.php';
	require_once WPHC_PLUGIN_DIR . 'includes/class-admin-page.php';

	$admin_page = new WPHC_Admin_Page();
	$admin_page->init();
}
add_action( 'plugins_loaded', 'wphc_init' );
