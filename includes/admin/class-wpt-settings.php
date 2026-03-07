<?php
/**
 * WPT Settings — WooCommerce Settings tab for Product Tables.
 *
 * Adds a "Product Tables" section under WooCommerce > Settings > Advanced
 * to enable/disable custom tables, control migration, and toggle sync.
 *
 * @package WPT\Admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * WPT_Settings class.
 */
class WPT_Settings extends \WC_Settings_Page {

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->id    = 'wpt';
		$this->label = __( 'Product Tables', 'woocommerce-product-tables' );

		parent::__construct();
	}

	/**
	 * Get settings array.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = array(
			array(
				'title' => __( 'Product Tables', 'woocommerce-product-tables' ),
				'type'  => 'title',
				'desc'  => __( 'Configure the custom product tables feature. When enabled, product data is stored in dedicated database tables instead of wp_postmeta for improved performance.', 'woocommerce-product-tables' ),
				'id'    => 'wpt_section_general',
			),

			array(
				'title'   => __( 'Enable custom tables', 'woocommerce-product-tables' ),
				'desc'    => __( 'Use custom product tables for data storage.', 'woocommerce-product-tables' ),
				'id'      => 'wpt_custom_product_tables_enabled',
				'default' => 'no',
				'type'    => 'checkbox',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'wpt_section_general',
			),

			array(
				'title' => __( 'Synchronisation', 'woocommerce-product-tables' ),
				'type'  => 'title',
				'desc'  => __( 'Control how data is kept in sync between custom tables and postmeta.', 'woocommerce-product-tables' ),
				'id'    => 'wpt_section_sync',
			),

			array(
				'title'   => __( 'Dual-write to postmeta', 'woocommerce-product-tables' ),
				'desc'    => __( 'Keep postmeta in sync when writing to custom tables. Recommended for compatibility with third-party plugins.', 'woocommerce-product-tables' ),
				'id'      => 'wpt_dual_write_enabled',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'title'   => __( 'Backwards compatibility filters', 'woocommerce-product-tables' ),
				'desc'    => __( 'Intercept get_post_meta / update_post_meta for product meta keys and redirect to custom tables.', 'woocommerce-product-tables' ),
				'id'      => 'wpt_backwards_compat_enabled',
				'default' => 'yes',
				'type'    => 'checkbox',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'wpt_section_sync',
			),

			array(
				'title' => __( 'Migration', 'woocommerce-product-tables' ),
				'type'  => 'title',
				'desc'  => $this->get_migration_status_html(),
				'id'    => 'wpt_section_migration',
			),

			array(
				'title'   => __( 'Batch size', 'woocommerce-product-tables' ),
				'desc'    => __( 'Number of products to process per batch during migration. Lower values reduce memory usage.', 'woocommerce-product-tables' ),
				'id'      => 'wpt_migration_batch_size',
				'default' => '50',
				'type'    => 'number',
				'css'     => 'width: 80px;',
				'custom_attributes' => array(
					'min'  => '5',
					'max'  => '500',
					'step' => '5',
				),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'wpt_section_migration',
			),
		);

		/**
		 * Filter WPT settings.
		 *
		 * @since 2.0.0
		 *
		 * @param array $settings Settings array.
		 */
		return apply_filters( 'wpt_settings', $settings );
	}

	/**
	 * Output the settings page.
	 *
	 * @since 2.0.0
	 */
	public function output() {
		$settings = $this->get_settings();
		\WC_Admin_Settings::output_fields( $settings );
	}

	/**
	 * Save settings.
	 *
	 * @since 2.0.0
	 */
	public function save() {
		$settings = $this->get_settings();
		\WC_Admin_Settings::save_fields( $settings );

		// If tables are being enabled for the first time, create them.
		if ( 'yes' === get_option( 'wpt_custom_product_tables_enabled' ) ) {
			\WPT_Install::create_tables();
		}
	}

	/**
	 * Build HTML showing migration status.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function get_migration_status_html() {
		$total    = $this->get_total_products();
		$migrated = $this->get_migrated_count();

		if ( 0 === $total ) {
			return __( 'No products found.', 'woocommerce-product-tables' );
		}

		$percent = round( ( $migrated / $total ) * 100 );

		$html  = '<div class="wpt-migration-status">';
		$html .= sprintf(
			/* translators: %1$d migrated count, %2$d total count, %3$d percentage */
			__( '<strong>%1$d</strong> of <strong>%2$d</strong> products migrated (%3$d%%).', 'woocommerce-product-tables' ),
			$migrated,
			$total,
			$percent
		);
		$html .= '</div>';

		if ( $migrated < $total ) {
			$html .= '<p class="description">';
			$html .= __( 'Use <code>wp wpt migrate</code> or the button below to start migration.', 'woocommerce-product-tables' );
			$html .= '</p>';
		}

		return $html;
	}

	/**
	 * Get total product count.
	 *
	 * @since 2.0.0
	 *
	 * @return int
	 */
	private function get_total_products() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ('product', 'product_variation')
			 AND post_status != 'auto-draft'"
		);
	}

	/**
	 * Get count of products already in the custom table.
	 *
	 * @since 2.0.0
	 *
	 * @return int
	 */
	private function get_migrated_count() {
		global $wpdb;

		if ( ! \WPT_Install::tables_exist() ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wpt_products"
		);
	}
}
