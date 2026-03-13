<?php
/**
 * Backwards Compatibility — Postmeta filter layer.
 *
 * Intercepts get_post_metadata / update_post_metadata / delete_post_metadata
 * for known product meta keys and reads/writes the custom table instead.
 * This ensures that third-party plugins using direct meta calls still work.
 *
 * @package WPT\Sync
 */

namespace WPT\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Backwards Compatibility class.
 */
class BackwardsCompatibility {

	/**
	 * Meta key → column name map for simple product table columns.
	 *
	 * @var array
	 */
	private $meta_to_column = array(
		'_sku'                    => 'sku',
		'_thumbnail_id'           => 'image_id',
		'_virtual'                => 'virtual',
		'_downloadable'           => 'downloadable',
		'_price'                  => 'price',
		'_regular_price'          => 'regular_price',
		'_sale_price'             => 'sale_price',
		'_sale_price_dates_from'  => 'date_on_sale_from',
		'_sale_price_dates_to'    => 'date_on_sale_to',
		'total_sales'             => 'total_sales',
		'_tax_status'             => 'tax_status',
		'_tax_class'              => 'tax_class',
		'_stock'                  => 'stock_quantity',
		'_stock_status'           => 'stock_status',
		'_manage_stock'           => 'manage_stock',
		'_backorders'             => 'backorders',
		'_low_stock_amount'       => 'low_stock_amount',
		'_sold_individually'      => 'sold_individually',
		'_weight'                 => 'weight',
		'_length'                 => 'length',
		'_width'                  => 'width',
		'_height'                 => 'height',
		'_wc_average_rating'      => 'average_rating',
		'_wc_rating_count'        => 'rating_count',
		'_purchase_note'          => 'purchase_note',
	);

	/**
	 * Whether compat is active (internal flag to prevent recursion).
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.0
	 * @internal
	 */
	public function init() {
		if ( ! wc_string_to_bool( get_option( 'wpt_backwards_compat_enabled', 'yes' ) ) ) {
			return;
		}

		/**
		 * Filters whether backward-compatible postmeta interception is enabled.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $enabled Whether backward compatibility is enabled. Default true.
		 */
		if ( ! apply_filters( 'wpt_enable_backward_compatibility', true ) || defined( 'WPT_DISABLE_BW_COMPAT' ) ) {
			return;
		}

		add_filter( 'get_post_metadata', array( $this, 'filter_get_metadata' ), 99, 4 );
		add_filter( 'update_post_metadata', array( $this, 'filter_update_metadata' ), 99, 5 );
		add_filter( 'delete_post_metadata', array( $this, 'filter_delete_metadata' ), 99, 5 );
	}

	/**
	 * Intercept get_post_meta for product meta keys — return from custom table.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `get_post_metadata`.
	 *
	 * @param mixed  $value    Current value (null if not filtered).
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param bool   $single   Whether to return a single value.
	 * @return mixed
	 */
	public function filter_get_metadata( $value, $post_id, $meta_key, $single ) {
		if ( $this->active ) {
			return $value;
		}

		if ( ! $meta_key || ! isset( $this->meta_to_column[ $meta_key ] ) ) {
			return $value;
		}

		if ( ! $this->is_product_post( $post_id ) ) {
			return $value;
		}

		$this->active = true;

		$column     = $this->meta_to_column[ $meta_key ];
		$table_data = $this->get_product_column( $post_id, $column );

		$this->active = false;

		if ( null === $table_data ) {
			return $value;
		}

		// Convert stored values to expected meta format.
		$table_data = $this->format_for_meta_read( $column, $table_data );

		if ( $single ) {
			return $table_data;
		}

		return array( $table_data );
	}

	/**
	 * Intercept update_post_meta for product meta keys — write to custom table.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `update_post_metadata`.
	 *
	 * @param null|bool $check      Whether to bypass update.
	 * @param int       $post_id    Post ID.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Meta value.
	 * @param mixed     $prev_value Previous value.
	 * @return null|bool
	 */
	public function filter_update_metadata( $check, $post_id, $meta_key, $meta_value, $prev_value ) {
		if ( $this->active ) {
			return $check;
		}

		if ( ! $meta_key || ! isset( $this->meta_to_column[ $meta_key ] ) ) {
			return $check;
		}

		if ( ! $this->is_product_post( $post_id ) ) {
			return $check;
		}

		$this->active = true;

		$column   = $this->meta_to_column[ $meta_key ];
		$db_value = $this->format_for_table_write( $column, $meta_value );

		$this->update_product_column( $post_id, $column, $db_value );

		$this->active = false;

		// Return null to allow WP to also write to postmeta (dual-write).
		return null;
	}

	/**
	 * Intercept delete_post_meta for product meta keys — null the custom table column.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `delete_post_metadata`.
	 *
	 * @param null|bool $check      Whether to bypass delete.
	 * @param int       $post_id    Post ID.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Meta value to match.
	 * @param bool      $delete_all Whether to delete matching meta for all objects.
	 * @return null|bool
	 */
	public function filter_delete_metadata( $check, $post_id, $meta_key, $meta_value, $delete_all ) {
		if ( $this->active ) {
			return $check;
		}

		if ( ! $meta_key || ! isset( $this->meta_to_column[ $meta_key ] ) ) {
			return $check;
		}

		if ( ! $this->is_product_post( $post_id ) ) {
			return $check;
		}

		$this->active = true;

		$column = $this->meta_to_column[ $meta_key ];

		$this->update_product_column( $post_id, $column, null );

		$this->active = false;

		// Return null to allow WP to also delete from postmeta.
		return null;
	}

	/**
	 * Read a single column value from the custom product table.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $product_id Product ID.
	 * @param string $column     Column name.
	 * @return mixed|null
	 */
	private function get_product_column( $product_id, $column ) {
		global $wpdb;

		$allowed_columns = array_values( $this->meta_to_column );
		if ( ! in_array( $column, $allowed_columns, true ) ) {
			return null;
		}

		// Use a dedicated cache key to avoid collisions with WC's product cache.
		$cache_key  = 'wpt_row_' . $product_id;
		$cache_data = wp_cache_get( $cache_key, 'wpt' );

		if ( false === $cache_data ) {
			// Read the full row in one query — all subsequent meta reads are cached.
			$cache_data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wpt_products WHERE product_id = %d",
					$product_id
				),
				ARRAY_A
			);

			if ( ! $cache_data ) {
				// No row — cache empty array to prevent repeated queries.
				wp_cache_set( $cache_key, array(), 'wpt' );
				return null;
			}

			wp_cache_set( $cache_key, $cache_data, 'wpt' );
		}

		if ( empty( $cache_data ) || ! array_key_exists( $column, $cache_data ) ) {
			return null;
		}

		return $cache_data[ $column ];
	}

	/**
	 * Update a single column in the custom product table.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $product_id Product ID.
	 * @param string $column     Column name.
	 * @param mixed  $value      New value.
	 */
	private function update_product_column( $product_id, $column, $value ) {
		global $wpdb;

		$allowed_columns = array_values( $this->meta_to_column );
		if ( ! in_array( $column, $allowed_columns, true ) ) {
			return;
		}

		$wpdb->update(
			"{$wpdb->prefix}wpt_products",
			array( $column => $value ),
			array( 'product_id' => $product_id )
		);

		// Invalidate caches.
		wp_cache_delete( 'wpt_row_' . $product_id, 'wpt' );
		wp_cache_delete( 'woocommerce_product_' . $product_id, 'product' );
	}

	/**
	 * Format a custom table value for meta read (get_post_meta return format).
	 *
	 * @since 2.0.0
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Raw database value.
	 * @return mixed
	 */
	private function format_for_meta_read( $column, $value ) {
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
				return $value ? strtotime( $value ) : '';

			default:
				return $value;
		}
	}

	/**
	 * Format a meta value for custom table storage.
	 *
	 * @since 2.0.0
	 *
	 * @param string $column     Column name.
	 * @param mixed  $meta_value Meta value from update_post_meta.
	 * @return mixed
	 */
	private function format_for_table_write( $column, $meta_value ) {
		switch ( $column ) {
			case 'virtual':
			case 'downloadable':
			case 'manage_stock':
			case 'sold_individually':
				return wc_string_to_bool( $meta_value ) ? 1 : 0;

			case 'date_on_sale_from':
			case 'date_on_sale_to':
				return is_numeric( $meta_value ) ? gmdate( 'Y-m-d H:i:s', (int) $meta_value ) : $meta_value;

			default:
				return $meta_value;
		}
	}

	/**
	 * Check if a post is a product or variation.
	 *
	 * @since 2.0.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_product_post( $post_id ) {
		$post_type = get_post_type( $post_id );
		return in_array( $post_type, array( 'product', 'product_variation' ), true );
	}
}
