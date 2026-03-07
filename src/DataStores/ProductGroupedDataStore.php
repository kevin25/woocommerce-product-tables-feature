<?php
/**
 * WC Grouped Product Data Store: Stored in Custom Tables.
 *
 * Extends ProductDataStore with grouped-product price sync,
 * using custom tables for price data with proper post_status filtering.
 *
 * @package WPT\DataStores
 */

namespace WPT\DataStores;

defined( 'ABSPATH' ) || exit;

/**
 * Grouped Product Data Store class.
 */
class ProductGroupedDataStore extends ProductDataStore {

	/**
	 * Handle updated props — sync prices when children change.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function handle_updated_props( &$product ) {
		if ( in_array( 'children', $this->updated_props, true ) ) {
			$this->update_prices_from_children( $product );
		}

		parent::handle_updated_props( $product );
	}

	/**
	 * Sync the grouped product price with the cheapest child.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function sync_price( &$product ) {
		$this->update_prices_from_children( $product );
	}

	/**
	 * For grouped products, _children meta is handled via relationships,
	 * so skip meta-based extra_data loading.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_extra_data( &$product ) {
		// No-op: children come from wpt_product_relationships.
	}

	/**
	 * Update the grouped product's price column to the cheapest active child price.
	 *
	 * Only considers published or privately published children (excludes drafts/trash).
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function update_prices_from_children( &$product ) {
		global $wpdb;

		$child_product_ids = $product->get_children();

		if ( empty( $child_product_ids ) ) {
			$wpdb->update(
				"{$wpdb->prefix}wpt_products",
				array( 'price' => null ),
				array( 'product_id' => $product->get_id() )
			);
			return;
		}

		// Filter to only published/private children.
		$placeholders = implode( ',', array_fill( 0, count( $child_product_ids ), '%d' ) );
		$args         = array_merge( $child_product_ids, array( 'publish', 'private' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$min_price = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(p.price) FROM {$wpdb->prefix}wpt_products AS p
				INNER JOIN {$wpdb->posts} AS posts ON p.product_id = posts.ID
				WHERE p.product_id IN ({$placeholders})
				AND posts.post_status IN (%s, %s)
				AND p.price IS NOT NULL
				AND p.price > 0",
				$args
			)
		);

		$wpdb->update(
			"{$wpdb->prefix}wpt_products",
			array( 'price' => $min_price ),
			array( 'product_id' => $product->get_id() )
		);
	}
}
