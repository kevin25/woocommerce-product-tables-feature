<?php
/**
 * Cache Invalidator — Centralised product cache cleanup.
 *
 * Listens to product-lifecycle hooks and clears all WPT-specific caches,
 * object caches, and transients so stale data is never served.
 *
 * @package WPT\Cache
 */

namespace WPT\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * CacheInvalidator class.
 */
class CacheInvalidator {

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.0
	 * @internal
	 */
	public function init() {
		// Product save / delete.
		add_action( 'woocommerce_new_product', array( $this, 'invalidate_product' ) );
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_product' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'invalidate_product' ) );
		add_action( 'woocommerce_trash_product', array( $this, 'invalidate_product' ) );

		// Variation save / delete.
		add_action( 'woocommerce_new_product_variation', array( $this, 'invalidate_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'invalidate_variation' ) );
		add_action( 'woocommerce_delete_product_variation', array( $this, 'invalidate_variation' ) );
		add_action( 'woocommerce_trash_product_variation', array( $this, 'invalidate_variation' ) );

		// Stock changes.
		add_action( 'woocommerce_product_set_stock', array( $this, 'invalidate_stock_cache' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'invalidate_stock_cache' ) );

		// Scheduled cleanup of orphaned transients.
		add_action( 'wpt_daily_cache_cleanup', array( $this, 'cleanup_expired_transients' ) );
	}

	/**
	 * Invalidate all caches for a product.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_new_product` and related hooks.
	 *
	 * @param int $product_id Product ID.
	 */
	public function invalidate_product( $product_id ) {
		$this->clear_product_caches( $product_id );
	}

	/**
	 * Invalidate caches for a variation and its parent.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_new_product_variation` and related hooks.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public function invalidate_variation( $variation_id ) {
		$this->clear_product_caches( $variation_id );

		$parent_id = wp_get_post_parent_id( $variation_id );
		if ( $parent_id ) {
			$this->clear_product_caches( $parent_id );
			$this->clear_variable_transients( $parent_id );
		}
	}

	/**
	 * Handle stock-specific cache invalidation.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_product_set_stock`.
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function invalidate_stock_cache( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$product_id = $product->get_id();
		$this->clear_product_caches( $product_id );

		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$this->clear_product_caches( $parent_id );
			delete_transient( 'wc_product_children_stock_status_' . $parent_id );
		}
	}

	/**
	 * Clear all caches for a single product ID.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 */
	public function clear_product_caches( $product_id ) {
		// WP object cache (product row).
		wp_cache_delete( 'woocommerce_product_' . $product_id, 'product' );

		// WC core product cache group.
		wp_cache_delete( $product_id, 'products' );

		// Post meta cache.
		wp_cache_delete( $product_id, 'post_meta' );

		// WPT-specific caches.
		wp_cache_delete( 'wpt_product_row_' . $product_id, 'wpt' );
		wp_cache_delete( 'wpt_product_attributes_' . $product_id, 'wpt' );
		wp_cache_delete( 'wpt_product_downloads_' . $product_id, 'wpt' );
		wp_cache_delete( 'wpt_product_relationships_' . $product_id, 'wpt' );
		wp_cache_delete( 'woocommerce_product_variation_attribute_values_' . $product_id, 'wpt' );

		// Transients.
		delete_transient( 'wc_product_children_' . $product_id );
		delete_transient( 'wc_var_prices_' . $product_id );
		delete_transient( 'wc_product_children_stock_status_' . $product_id );
	}

	/**
	 * Clear transients specific to variable products.
	 *
	 * @since 2.0.0
	 *
	 * @param int $parent_id Parent product ID.
	 */
	private function clear_variable_transients( $parent_id ) {
		delete_transient( 'wc_product_children_' . $parent_id );
		delete_transient( 'wc_var_prices_' . $parent_id );
		delete_transient( 'wc_product_children_stock_status_' . $parent_id );
	}

	/**
	 * Cleanup expired WPT transients.
	 *
	 * Hooked to wpt_daily_cache_cleanup via Action Scheduler.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `wpt_daily_cache_cleanup`.
	 */
	public function cleanup_expired_transients() {
		global $wpdb;

		$wpdb->query(
			"DELETE a, b FROM {$wpdb->options} a
			 INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
			 WHERE a.option_name LIKE '\_transient\_wc\_var\_prices\_%'
			 AND b.option_value < UNIX_TIMESTAMP()"
		);
	}
}
