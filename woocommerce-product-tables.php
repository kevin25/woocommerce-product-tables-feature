<?php
/**
 * Plugin Name: WooCommerce Product Tables
 * Plugin URI: https://woocommerce.com/
 * Description: Moves product data into dedicated custom tables for improved performance and scalability. HPOS-style dual-write keeps postmeta in sync for full compatibility.
 * Version: 2.0.0-dev
 * Author: Automattic
 * Author URI: https://woocommerce.com
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.5
 *
 * Text Domain: woocommerce-product-tables
 * Domain Path: /languages/
 *
 * @package WooCommerce\ProductTables
 * @author Automattic
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'WPT_VERSION', '2.0.0-dev' );
define( 'WPT_PLUGIN_FILE', __FILE__ );
define( 'WPT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPT_MIN_WC_VERSION', '8.0' );
define( 'WPT_MIN_PHP_VERSION', '7.4' );

/**
 * Display admin notice when WooCommerce is not active or version is too low.
 *
 * @param string $message The notice message.
 */
function wpt_admin_notice_missing_wc( $message ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		wp_kses_post( $message )
	);
}

/**
 * Bootstrap the plugin on plugins_loaded.
 */
function wpt_bootstrap() {
	// PHP version check.
	if ( version_compare( PHP_VERSION, WPT_MIN_PHP_VERSION, '<' ) ) {
		add_action( 'admin_notices', function () {
			wpt_admin_notice_missing_wc(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'WooCommerce Product Tables requires PHP %1$s or higher. You are running PHP %2$s.', 'woocommerce-product-tables' ),
					WPT_MIN_PHP_VERSION,
					PHP_VERSION
				)
			);
		} );
		return;
	}

	// WooCommerce active check.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			wpt_admin_notice_missing_wc(
				__( 'WooCommerce Product Tables requires WooCommerce to be installed and active.', 'woocommerce-product-tables' )
			);
		} );
		return;
	}

	// WooCommerce version check.
	if ( version_compare( WC_VERSION, WPT_MIN_WC_VERSION, '<' ) ) {
		add_action( 'admin_notices', function () {
			wpt_admin_notice_missing_wc(
				sprintf(
					/* translators: 1: required WC version, 2: current WC version */
					__( 'WooCommerce Product Tables requires WooCommerce %1$s or higher. You are running %2$s.', 'woocommerce-product-tables' ),
					WPT_MIN_WC_VERSION,
					WC_VERSION
				)
			);
		} );
		return;
	}

	// Load autoloader.
	require_once WPT_PLUGIN_DIR . 'includes/class-wpt-autoloader.php';

	// Boot the plugin.
	require_once WPT_PLUGIN_DIR . 'includes/class-wpt-bootstrap.php';
	WPT_Bootstrap::instance()->init();
}
add_action( 'plugins_loaded', 'wpt_bootstrap', 20 );

/**
 * Run on plugin activation.
 */
function wpt_activate() {
	// Ensure WC is available during activation.
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once WPT_PLUGIN_DIR . 'includes/class-wpt-autoloader.php';
	require_once WPT_PLUGIN_DIR . 'includes/class-wpt-install.php';
	WPT_Install::activate();
}
register_activation_hook( WPT_PLUGIN_FILE, 'wpt_activate' );

/**
 * Run on plugin deactivation.
 */
function wpt_deactivate() {
	// Unschedule background sync actions.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'wpt_background_sync' );
		as_unschedule_all_actions( 'wpt_migration_batch' );
	}
}
register_deactivation_hook( WPT_PLUGIN_FILE, 'wpt_deactivate' );
