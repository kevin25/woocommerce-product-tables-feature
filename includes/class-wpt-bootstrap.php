<?php
/**
 * Plugin bootstrap — registers hooks, swaps data stores, loads components.
 *
 * @package WooCommerce\ProductTables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPT_Bootstrap
 *
 * Singleton that wires everything together once WooCommerce is confirmed available.
 */
class WPT_Bootstrap {

	/**
	 * Singleton instance.
	 *
	 * @var WPT_Bootstrap|null
	 */
	private static $instance = null;

	/**
	 * Whether custom tables are enabled.
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Get singleton instance.
	 *
	 * @since 2.0.0
	 *
	 * @return WPT_Bootstrap
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Initialize the plugin.
	 *
	 * @since 2.0.0
	 * @internal
	 */
	public function init() {
		$this->enabled = wc_string_to_bool( get_option( 'wpt_custom_product_tables_enabled', 'no' ) );

		// Always load admin settings (so users can enable/disable).
		if ( is_admin() ) {
			$this->load_admin();
		}

		// Always register WP-CLI commands (migrate/rollback/status/verify).
		$cli = new \WPT\CLI\Commands();
		$cli->init();

		// Only swap data stores and register sync when enabled.
		if ( ! $this->enabled ) {
			return;
		}

		// Ensure tables exist.
		$this->maybe_create_tables();

		// Swap WC data stores.
		add_filter( 'woocommerce_data_stores', array( $this, 'replace_data_stores' ) );

		// Initialize synchronizer (dual-write).
		$synchronizer = new \WPT\Sync\ProductSynchronizer();
		$synchronizer->init();

		// Initialize backwards compatibility layer.
		$compat = new \WPT\Sync\BackwardsCompatibility();
		$compat->init();

		// Initialize cache invalidator.
		$cache = new \WPT\Cache\CacheInvalidator();
		$cache->init();

		// Schedule daily cache cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'wpt_daily_cache_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'wpt_daily_cache_cleanup' );
		}

		// Initialize query modifier.
		$query = new \WPT\Query\ProductQueryModifier();
		$query->init();

		// Initialize post-delete cleanup.
		$post_data = new \WPT\Sync\PostData();
		$post_data->init();
	}

	/**
	 * Whether custom product tables are enabled.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->enabled;
	}

	/**
	 * Load admin components.
	 *
	 * @since 2.0.0
	 */
	private function load_admin() {
		require_once WPT_PLUGIN_DIR . 'includes/admin/class-wpt-settings.php';

		add_filter( 'woocommerce_get_settings_pages', function ( $settings ) {
			$settings[] = new \WPT_Settings();
			return $settings;
		} );
	}

	/**
	 * Create custom tables if they don't exist yet.
	 *
	 * @since 2.0.0
	 */
	private function maybe_create_tables() {
		$db_version = get_option( 'wpt_db_version', '0' );

		if ( version_compare( $db_version, WPT_VERSION, '<' ) ) {
			require_once WPT_PLUGIN_DIR . 'includes/class-wpt-install.php';
			WPT_Install::create_tables();
			update_option( 'wpt_db_version', WPT_VERSION );
		}
	}

	/**
	 * Replace WooCommerce data stores with custom table implementations.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_data_stores`.
	 *
	 * @param array $stores Registered data stores.
	 * @return array
	 */
	public function replace_data_stores( $stores ) {
		$stores['product']           = \WPT\DataStores\ProductDataStore::class;
		$stores['product-grouped']   = \WPT\DataStores\ProductGroupedDataStore::class;
		$stores['product-variable']  = \WPT\DataStores\ProductVariableDataStore::class;
		$stores['product-variation'] = \WPT\DataStores\ProductVariationDataStore::class;

		return $stores;
	}
}
