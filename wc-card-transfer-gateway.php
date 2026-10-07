<?php
/**
 * Plugin Name: WC Card Transfer Gateway
 * Plugin URI: https://github.com/example/wc-card-transfer-gateway
 * Description: Manual card/bank transfer payment gateways for WooCommerce (Iran card-to-card with receipt review) plus an international gateway with manual transfer or Stripe Checkout.
 * Version: 2.1.1
 * Author: Open-source contributor
 * License: GPL-2.0-or-later
 * Text Domain: wc-card-transfer-gateway
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.9
 * WC tested up to: 10.6
 */

defined( 'ABSPATH' ) || exit;

define( 'WCCTC_VERSION', '2.1.1' );
define( 'WCCTC_FILE', __FILE__ );
define( 'WCCTC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCCTC_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCCTC_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCCTC_FILE, true );
	}
} );

/*
 * IMPORTANT: nothing loaded here may extend a WooCommerce class. This plugin is
 * loaded alphabetically BEFORE woocommerce/, so WC_Payment_Gateway does not exist
 * yet. Classes that extend WooCommerce classes live in src/Gateways and src/Blocks
 * and are only loaded after WooCommerce is available.
 */
require_once WCCTC_DIR . 'src/Helpers.php';
require_once WCCTC_DIR . 'src/Database.php';
require_once WCCTC_DIR . 'src/Providers.php';
require_once WCCTC_DIR . 'src/OCR.php';
require_once WCCTC_DIR . 'src/Receipts.php';
require_once WCCTC_DIR . 'src/REST.php';
require_once WCCTC_DIR . 'src/Gateway.php';
require_once WCCTC_DIR . 'src/Blocks.php';
require_once WCCTC_DIR . 'src/Admin.php';
require_once WCCTC_DIR . 'src/Plugin.php';

register_activation_hook( __FILE__, array( 'WCCTC\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WCCTC\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'wc-card-transfer-gateway', false, dirname( plugin_basename( WCCTC_FILE ) ) . '/languages' );
}, 5 );

add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'WC Card Transfer Gateway requires WooCommerce to be installed and active.', 'wc-card-transfer-gateway' ) . '</p></div>';
			}
		} );
		return;
	}
	WCCTC\Plugin::instance()->boot();
}, 20 );
