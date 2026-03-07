<?php
/**
 * Product Synchronizer — Dual-write engine (HPOS-Mirror pattern).
 *
 * After every data-store write, this class writes the same values
 * back to wp_postmeta so that plugins relying on meta queries
 * continue to work. This is the "mirror" in HPOS-Mirror.
 *
 * @package WPT\Sync
 */

namespace WPT\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * ProductSynchronizer class.
 */
class ProductSynchronizer {

	/**
	 * Whether sync is currently active (prevent infinite loops).
	 *
	 * @var bool
	 */
	private $syncing = false;

	/**
	 * Map of custom table columns → postmeta key.
	 *
	 * @var array
	 */
	private $column_meta_map = array(
		'sku'               => '_sku',
		'image_id'          => '_thumbnail_id',
		'virtual'           => '_virtual',
		'downloadable'      => '_downloadable',
		'price'             => '_price',
		'regular_price'     => '_regular_price',
		'sale_price'        => '_sale_price',
		'date_on_sale_from' => '_sale_price_dates_from',
		'date_on_sale_to'   => '_sale_price_dates_to',
		'total_sales'       => 'total_sales',
		'tax_status'        => '_tax_status',
		'tax_class'         => '_tax_class',
		'stock_quantity'    => '_stock',
		'stock_status'      => '_stock_status',
		'manage_stock'      => '_manage_stock',
		'backorders'        => '_backorders',
		'low_stock_amount'  => '_low_stock_amount',
		'sold_individually' => '_sold_individually',
		'weight'            => '_weight',
		'length'            => '_length',
		'width'             => '_width',
		'height'            => '_height',
		'average_rating'    => '_wc_average_rating',
		'rating_count'      => '_wc_rating_count',
		'purchase_note'     => '_purchase_note',
	);

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.0
	 * @internal
	 */
	public function init() {
		if ( ! wc_string_to_bool( get_option( 'wpt_dual_write_enabled', 'yes' ) ) ) {
			return;
		}

		// Fire after each product save.
		add_action( 'woocommerce_new_product', array( $this, 'sync_product_to_postmeta' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'sync_product_to_postmeta' ), 10, 1 );
		add_action( 'woocommerce_new_product_variation', array( $this, 'sync_product_to_postmeta' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'sync_product_to_postmeta' ), 10, 1 );

		// Sync relationships.
		add_action( 'woocommerce_new_product', array( $this, 'sync_relationships_to_postmeta' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'sync_relationships_to_postmeta' ), 10, 1 );
	}

	/**
	 * Sync custom table data back to postmeta.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_new_product` and related hooks.
	 *
	 * @param int $product_id Product ID.
	 */
	public function sync_product_to_postmeta( $product_id ) {
		if ( $this->syncing ) {
			return;
		}

		$this->syncing = true;

		global $wpdb;

		// Read the row from the custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpt_products WHERE product_id = %d",
				$product_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->syncing = false;
			return;
		}

		foreach ( $this->column_meta_map as $column => $meta_key ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}

			$value = $row[ $column ];

			// Convert types for postmeta compatibility.
			$value = $this->format_value_for_meta( $column, $value );

			update_post_meta( $product_id, $meta_key, $value );
		}

		// Sync date fields as timestamps for backward compat.
		if ( ! empty( $row['date_on_sale_from'] ) ) {
			update_post_meta( $product_id, '_sale_price_dates_from', strtotime( $row['date_on_sale_from'] ) );
		} else {
			delete_post_meta( $product_id, '_sale_price_dates_from' );
		}

		if ( ! empty( $row['date_on_sale_to'] ) ) {
			update_post_meta( $product_id, '_sale_price_dates_to', strtotime( $row['date_on_sale_to'] ) );
		} else {
			delete_post_meta( $product_id, '_sale_price_dates_to' );
		}

		$this->syncing = false;
	}

	/**
	 * Format a custom table value for postmeta storage.
	 *
	 * @since 2.0.0
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Value from the custom table.
	 * @return mixed
	 */
	private function format_value_for_meta( $column, $value ) {
		switch ( $column ) {
			case 'virtual':
			case 'downloadable':
				return $value ? 'yes' : 'no';

			case 'manage_stock':
				return $value ? 'yes' : 'no';

			case 'sold_individually':
				return $value ? 'yes' : 'no';

			case 'date_on_sale_from':
			case 'date_on_sale_to':
				// Handled separately as timestamps.
				return null;

			default:
				return $value;
		}
	}

	/**
	 * Sync relationship data back to postmeta.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_new_product` and related hooks.
	 *
	 * @param int $product_id Product ID.
	 */
	public function sync_relationships_to_postmeta( $product_id ) {
		if ( $this->syncing ) {
			return;
		}

		$this->syncing = true;

		global $wpdb;

		$relationships = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, child_id FROM {$wpdb->prefix}wpt_product_relationships WHERE product_id = %d ORDER BY position ASC",
				$product_id
			)
		);

		$grouped = array();
		foreach ( $relationships as $rel ) {
			$grouped[ $rel->type ][] = (int) $rel->child_id;
		}

		$meta_map = array(
			'upsell'     => '_upsell_ids',
			'cross_sell' => '_crosssell_ids',
			'grouped'    => '_children',
			'image'      => '_product_image_gallery',
		);

		foreach ( $meta_map as $type => $meta_key ) {
			$ids = $grouped[ $type ] ?? array();

			if ( 'image' === $type ) {
				update_post_meta( $product_id, $meta_key, implode( ',', $ids ) );
			} else {
				update_post_meta( $product_id, $meta_key, $ids );
			}
		}

		$this->syncing = false;
	}
}
