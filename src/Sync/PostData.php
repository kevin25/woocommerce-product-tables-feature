<?php
/**
 * Post Data — Clean up custom table rows on product deletion.
 *
 * Hooks into delete_post to remove orphaned rows from all six WPT tables
 * when a product or variation is permanently deleted.
 *
 * @package WPT\Sync
 */

namespace WPT\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * PostData class.
 */
class PostData {

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.0
	 * @internal
	 */
	public function init() {
		add_action( 'delete_post', array( $this, 'delete_post' ) );
	}

	/**
	 * Clean up custom tables when a product or variation post is permanently deleted.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `delete_post`.
	 *
	 * @param int $post_id Post ID being deleted.
	 */
	public function delete_post( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		global $wpdb;

		// Clear caches first.
		wp_cache_delete( 'wpt_row_' . $post_id, 'wpt' );
		wp_cache_delete( 'woocommerce_product_' . $post_id, 'product' );
		wp_cache_delete( $post_id, 'products' );
		wp_cache_delete( 'woocommerce_product_attributes_' . $post_id, 'product' );
		wp_cache_delete( 'woocommerce_product_downloads_' . $post_id, 'product' );
		wp_cache_delete( 'woocommerce_product_relationships_' . $post_id, 'product' );
		wp_cache_delete( 'woocommerce_product_variation_attribute_values_' . $post_id, 'product' );
		wp_cache_delete( 'woocommerce_product_type_' . $post_id, 'product' );

		// Delete from all custom tables.
		$wpdb->delete( "{$wpdb->prefix}wpt_products", array( 'product_id' => $post_id ) );
		$wpdb->delete( "{$wpdb->prefix}wpt_product_attributes", array( 'product_id' => $post_id ) );
		$wpdb->delete( "{$wpdb->prefix}wpt_product_attribute_values", array( 'product_id' => $post_id ) );
		$wpdb->delete( "{$wpdb->prefix}wpt_product_downloads", array( 'product_id' => $post_id ) );
		$wpdb->delete( "{$wpdb->prefix}wpt_product_relationships", array( 'product_id' => $post_id ) );
		$wpdb->delete( "{$wpdb->prefix}wpt_product_variation_attribute_values", array( 'product_id' => $post_id ) );

		// Also clean relationships where this product is a child (e.g. variation of a variable, upsell target).
		$wpdb->delete( "{$wpdb->prefix}wpt_product_relationships", array( 'child_id' => $post_id ) );
	}
}
