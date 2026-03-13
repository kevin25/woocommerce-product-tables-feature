<?php
/**
 * WC Variable Product Data Store: Stored in Custom Tables.
 *
 * Extends ProductDataStore with variable-specific child management,
 * price syncing, and variation attribute handling.
 *
 * @package WPT\DataStores
 */

namespace WPT\DataStores;

defined( 'ABSPATH' ) || exit;

/**
 * Variable Product Data Store class.
 */
class ProductVariableDataStore extends ProductDataStore {

	/**
	 * Cached & hashed prices array for child variations.
	 *
	 * @var array
	 */
	protected $prices_array = array();

	/**
	 * Relationships — excludes grouped/children since variations are child posts.
	 *
	 * @var array
	 */
	protected $relationships = array(
		'image'      => 'gallery_image_ids',
		'upsell'     => 'upsell_ids',
		'cross_sell' => 'cross_sell_ids',
	);

	/*
	|--------------------------------------------------------------------------
	| Data Reading
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read product data. Unsets props that don't apply to variable products.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_product_data( &$product ) {
		parent::read_product_data( $product );

		$product->set_regular_price( '' );
		$product->set_sale_price( '' );
	}

	/**
	 * Load variation child IDs with transient caching.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product    Product object.
	 * @param bool        $force_read True to bypass the transient.
	 * @return array
	 */
	public function read_children( &$product, $force_read = false ) {
		$children_transient_name = 'wc_product_children_' . $product->get_id();
		$children                = get_transient( $children_transient_name );

		if ( empty( $children ) || ! is_array( $children ) || ! isset( $children['all'] ) || ! isset( $children['visible'] ) || $force_read ) {
			$children = array(); // Prevent PHP 8.1+ deprecation when get_transient() returns false.

			$all_args = $this->map_legacy_product_args(
				array(
					'parent'  => $product->get_id(),
					'type'    => 'variation',
					'orderby' => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
					'order'   => 'ASC',
					'limit'   => -1,
					'return'  => 'ids',
					'status'  => array( 'publish', 'private' ),
				)
			);

			$visible_only_args                = $all_args;
			$visible_only_args['status'] = 'publish';

			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$visible_only_args['stock_status'] = 'instock';
			}

			$children['all']     = wc_get_products(
				apply_filters( 'woocommerce_variable_children_args', $all_args, $product, false )
			);
			$children['visible'] = wc_get_products(
				apply_filters( 'woocommerce_variable_children_args', $visible_only_args, $product, true )
			);

			set_transient( $children_transient_name, $children, DAY_IN_SECONDS * 30 );
		}

		$children['all']     = wp_parse_id_list( (array) $children['all'] );
		$children['visible'] = wp_parse_id_list( (array) $children['visible'] );

		return $children;
	}

	/**
	 * Map legacy WP_Query args to wc_get_products args.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	protected function map_legacy_product_args( $args ) {
		$legacy_map = array(
			'post_parent'    => 'parent',
			'post_type'      => 'type',
			'post_status'    => 'status',
			'fields'         => 'return',
			'posts_per_page' => 'limit',
			'paged'          => 'page',
			'numberposts'    => 'limit',
		);

		foreach ( $legacy_map as $from => $to ) {
			if ( isset( $args[ $from ] ) ) {
				$args[ $to ] = $args[ $from ];
			}
		}

		return $args;
	}

	/**
	 * Load variation attributes and their possible values using the attribute_name schema.
	 *
	 * Uses wpt_product_variation_attribute_values directly with attribute_name
	 * for a simpler, FK-free lookup compared to the original product_attribute_id approach.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @return array
	 */
	public function read_variation_attributes( &$product ) {
		global $wpdb;

		$variation_attributes = array();
		$attributes           = $product->get_attributes();
		$child_ids            = $product->get_children();
		$cache_key            = \WC_Cache_Helper::get_cache_prefix( 'products' ) . 'product_variation_attributes_' . $product->get_id();
		$cache_group          = 'products';
		$cached_data          = wp_cache_get( $cache_key, $cache_group );

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		// Check if the parent product is in the custom table.
		$is_migrated = (bool) $this->get_product_row_from_db( $product->get_id() );

		if ( ! empty( $attributes ) ) {
			foreach ( $attributes as $attribute ) {
				if ( ! $attribute->get_variation() ) {
					continue;
				}

				$values = array();

				if ( ! empty( $child_ids ) ) {
					if ( $is_migrated ) {
						// Read from custom table.
						$format   = array_fill( 0, count( $child_ids ), '%d' );
						$query_in = '(' . implode( ',', $format ) . ')';

						$values = array_unique(
							$wpdb->get_col(
								$wpdb->prepare(
									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
									"SELECT attribute_value FROM {$wpdb->prefix}wpt_product_variation_attribute_values
									WHERE attribute_name = %s
									AND product_id = %d
									AND variation_id IN {$query_in}",
									array_merge(
										array( $attribute->get_name(), $product->get_id() ),
										$child_ids
									)
								)
							)
						);
					} else {
						// Fallback: read from variation postmeta (attribute_pa_color, etc.).
						$meta_key = 'attribute_' . sanitize_title( $attribute->get_name() );
						foreach ( $child_ids as $child_id ) {
							$val = get_post_meta( $child_id, $meta_key, true );
							if ( '' !== $val && false !== $val ) {
								$values[] = $val;
							}
						}
						$values = array_unique( $values );
					}
				}

				// Empty value indicates all options for the attribute are available.
				if ( in_array( null, $values, true ) || in_array( '', $values, true ) || empty( $values ) ) {
					$values = $attribute->get_slugs();
				} elseif ( ! $attribute->is_taxonomy() ) {
					$text_attributes          = array_map( 'trim', $attribute->get_options() );
					$assigned_text_attributes = $values;
					$values                   = array();

					foreach ( $text_attributes as $text_attribute ) {
						if ( in_array( $text_attribute, $assigned_text_attributes, true ) ) {
							$values[] = $text_attribute;
						}
					}
				}

				$variation_attributes[ $attribute->get_name() ] = array_unique( $values );
			}
		}

		wp_cache_set( $cache_key, $variation_attributes, $cache_group );

		return $variation_attributes;
	}

	/**
	 * Get variation prices for display or caching.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product     Product object.
	 * @param bool        $for_display If true, prices adapted for display.
	 * @return array
	 */
	public function read_price_data( &$product, $for_display = false ) {
		$transient_name = 'wc_var_prices_' . $product->get_id();
		$price_hash     = $this->get_price_hash( $product, $for_display );

		if ( empty( $this->prices_array[ $price_hash ] ) ) {
			$transient_cached_prices_array = array_filter( (array) json_decode( strval( get_transient( $transient_name ) ), true ) );

			if ( empty( $transient_cached_prices_array['version'] ) || \WC_Cache_Helper::get_transient_version( 'product' ) !== $transient_cached_prices_array['version'] ) {
				$transient_cached_prices_array = array( 'version' => \WC_Cache_Helper::get_transient_version( 'product' ) );
			}

			if ( empty( $transient_cached_prices_array[ $price_hash ] ) ) {
				$prices_array = array(
					'price'         => array(),
					'regular_price' => array(),
					'sale_price'    => array(),
				);

				$variation_ids = $product->get_visible_children();

				if ( is_callable( '_prime_post_caches' ) ) {
					_prime_post_caches( $variation_ids );
				}

				foreach ( $variation_ids as $variation_id ) {
					$variation = wc_get_product( $variation_id );

					if ( ! $variation ) {
						continue;
					}

					$price         = apply_filters( 'woocommerce_variation_prices_price', $variation->get_price( 'edit' ), $variation, $product );
					$regular_price = apply_filters( 'woocommerce_variation_prices_regular_price', $variation->get_regular_price( 'edit' ), $variation, $product );
					$sale_price    = apply_filters( 'woocommerce_variation_prices_sale_price', $variation->get_sale_price( 'edit' ), $variation, $product );

					if ( '' === $price ) {
						continue;
					}

					if ( $sale_price === $regular_price || $sale_price !== $price ) {
						$sale_price = $regular_price;
					}

					if ( $for_display ) {
						if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
							$price         = '' === $price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
							$regular_price = '' === $regular_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
							$sale_price    = '' === $sale_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
						} else {
							$price         = '' === $price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
							$regular_price = '' === $regular_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
							$sale_price    = '' === $sale_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
						}
					}

					$prices_array['price'][ $variation_id ]         = wc_format_decimal( $price, wc_get_price_decimals() );
					$prices_array['regular_price'][ $variation_id ] = wc_format_decimal( $regular_price, wc_get_price_decimals() );
					$prices_array['sale_price'][ $variation_id ]    = wc_format_decimal( $sale_price, wc_get_price_decimals() );

					$prices_array = apply_filters( 'woocommerce_variation_prices_array', $prices_array, $variation, $for_display );
				}

				foreach ( $prices_array as $key => $values ) {
					$transient_cached_prices_array[ $price_hash ][ $key ] = $values;
				}

				set_transient( $transient_name, wp_json_encode( $transient_cached_prices_array ), DAY_IN_SECONDS * 30 );
			}

			$this->prices_array[ $price_hash ] = apply_filters( 'woocommerce_variation_prices', $transient_cached_prices_array[ $price_hash ], $product, $for_display );
		}

		return $this->prices_array[ $price_hash ];
	}

	/**
	 * Create a price hash based on tax location, product version, and active price filters.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product       Product object.
	 * @param bool        $include_taxes Whether taxes should be included.
	 * @return string
	 */
	protected function get_price_hash( &$product, $include_taxes = false ) {
		global $wp_filter;

		$price_hash   = $include_taxes ? array( get_option( 'woocommerce_tax_display_shop', 'excl' ), \WC_Tax::get_rates() ) : array( false );
		$filter_names = array( 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_regular_price', 'woocommerce_variation_prices_sale_price' );

		foreach ( $filter_names as $filter_name ) {
			if ( ! empty( $wp_filter[ $filter_name ] ) ) {
				$price_hash[ $filter_name ] = array();

				foreach ( $wp_filter[ $filter_name ] as $priority => $callbacks ) {
					$price_hash[ $filter_name ][] = array_values( wp_list_pluck( $callbacks, 'function' ) );
				}
			}
		}

		$price_hash[] = \WC_Cache_Helper::get_transient_version( 'product' );
		$price_hash   = md5( wp_json_encode( apply_filters( 'woocommerce_get_variation_prices_hash', $price_hash, $product, $include_taxes ) ) );

		return $price_hash;
	}

	/*
	|--------------------------------------------------------------------------
	| Child Property Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Does a child variation have a weight set?
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @return bool
	 */
	public function child_has_weight( $product ) {
		global $wpdb;

		$child_has_weight = wp_cache_get( 'woocommerce_product_child_has_weight_' . $product->get_id(), 'product' );

		if ( false === $child_has_weight ) {
			$query = $wpdb->prepare(
				"SELECT product_id
				FROM {$wpdb->prefix}wpt_products AS products
				INNER JOIN {$wpdb->posts} AS posts ON products.product_id = posts.ID
				WHERE posts.post_parent = %d
				AND posts.post_status IN ('publish', 'private')
				AND products.weight > 0",
				$product->get_id()
			);

			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$query .= " AND products.stock_status = 'instock'";
			}

			$query .= ' LIMIT 1';

			$child_has_weight = null !== $wpdb->get_var( $query ) ? 1 : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			wp_cache_set( 'woocommerce_product_child_has_weight_' . $product->get_id(), $child_has_weight, 'product' );
		}

		return (bool) $child_has_weight;
	}

	/**
	 * Does a child variation have dimensions set?
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @return bool
	 */
	public function child_has_dimensions( $product ) {
		global $wpdb;

		$child_has_dimensions = wp_cache_get( 'woocommerce_product_child_has_dimensions_' . $product->get_id(), 'product' );

		if ( false === $child_has_dimensions ) {
			$query = $wpdb->prepare(
				"SELECT product_id
				FROM {$wpdb->prefix}wpt_products AS products
				INNER JOIN {$wpdb->posts} AS posts ON products.product_id = posts.ID
				WHERE posts.post_parent = %d
				AND posts.post_status IN ('publish', 'private')
				AND (products.length > 0 OR products.width > 0 OR products.height > 0)",
				$product->get_id()
			);

			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$query .= " AND products.stock_status = 'instock'";
			}

			$query .= ' LIMIT 1';

			$child_has_dimensions = null !== $wpdb->get_var( $query ) ? 1 : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			wp_cache_set( 'woocommerce_product_child_has_dimensions_' . $product->get_id(), $child_has_dimensions, 'product' );
		}

		return (bool) $child_has_dimensions;
	}

	/**
	 * Is a child in stock?
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @return bool
	 */
	public function child_is_in_stock( $product ) {
		return $this->child_has_stock_status( $product, 'instock' );
	}

	/**
	 * Does a child have a given stock status?
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param string      $status  'instock', 'outofstock', or 'onbackorder'.
	 * @return bool
	 */
	public function child_has_stock_status( $product, $status ) {
		global $wpdb;

		$children_stock_status = wp_cache_get( 'woocommerce_product_children_stock_status_' . $product->get_id(), 'product' );

		if ( false === $children_stock_status ) {
			$children_stock_status = array();
		}

		if ( ! isset( $children_stock_status[ $status ] ) ) {
			$children_stock_status[ $status ] = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT product_id
					FROM {$wpdb->prefix}wpt_products AS products
					INNER JOIN {$wpdb->posts} AS posts ON products.product_id = posts.ID
					WHERE posts.post_parent = %d
					AND posts.post_status IN ('publish', 'private')
					AND products.stock_status = %s
					LIMIT 1",
					$product->get_id(),
					$status
				)
			);

			wp_cache_set( 'woocommerce_product_children_stock_status_' . $product->get_id(), $children_stock_status, 'product' );
		}

		return $children_stock_status[ $status ];
	}

	/*
	|--------------------------------------------------------------------------
	| Sync Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Sync all variation names if the parent name changes.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product       Product object.
	 * @param string      $previous_name Previous name.
	 * @param string      $new_name      New name.
	 */
	public function sync_variation_names( &$product, $previous_name = '', $new_name = '' ) {
		if ( $new_name !== $previous_name ) {
			global $wpdb;

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts}
					SET post_title = REPLACE( post_title, %s, %s )
					WHERE post_type = 'product_variation'
					AND post_parent = %d",
					$previous_name ? $previous_name : 'AUTO-DRAFT',
					$new_name,
					$product->get_id()
				)
			);
		}
	}

	/**
	 * Stock managed at the parent level — update children being managed by this product.
	 * Syncs downward from parent to child when the variable product is saved.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function sync_managed_variation_stock_status( &$product ) {
		global $wpdb;

		if ( $product->get_manage_stock() ) {
			$status       = $product->get_stock_status();
			$children_ids = $product->get_children();

			if ( ! empty( $children_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $children_ids ), '%d' ) );
				$changed      = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"UPDATE {$wpdb->prefix}wpt_products SET stock_status = %s WHERE product_id IN ({$placeholders})",
						array_merge( array( $status ), $children_ids )
					)
				);

				if ( $changed ) {
					$children = $this->read_children( $product, true );
					$product->set_children( $children['all'] );
					$product->set_visible_children( $children['visible'] );
				}
			}
		}
	}

	/**
	 * Sync variable product price with lowest visible child price.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function sync_price( &$product ) {
		global $wpdb;

		$children = $product->get_visible_children();

		if ( ! empty( $children ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $children ), '%d' ) );
			$min_price    = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT price FROM {$wpdb->prefix}wpt_products WHERE product_id IN ({$placeholders}) ORDER BY price ASC LIMIT 1",
					$children
				)
			);
		} else {
			$min_price = null;
		}

		if ( ! is_null( $min_price ) ) {
			$wpdb->update(
				"{$wpdb->prefix}wpt_products",
				array( 'price' => wc_format_decimal( $min_price ) ),
				array( 'product_id' => $product->get_id() )
			);
		}
	}

	/**
	 * Sync variable product stock status based on children.
	 * Does not persist — caller must save.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function sync_stock_status( &$product ) {
		if ( $product->child_is_in_stock() ) {
			$product->set_stock_status( 'instock' );
		} elseif ( $product->child_is_on_backorder() ) {
			$product->set_stock_status( 'onbackorder' );
		} else {
			$product->set_stock_status( 'outofstock' );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Variation CRUD
	|--------------------------------------------------------------------------
	*/

	/**
	 * Delete variations of a product.
	 *
	 * @since 2.0.0
	 *
	 * @param int  $product_id   Product ID.
	 * @param bool $force_delete False to trash.
	 */
	public function delete_variations( $product_id, $force_delete = false ) {
		global $wpdb;

		if ( ! is_numeric( $product_id ) || 0 >= $product_id ) {
			return;
		}

		$variation_ids = wp_parse_id_list(
			get_posts(
				array(
					'post_parent' => $product_id,
					'post_type'   => 'product_variation',
					'fields'      => 'ids',
					'post_status' => array( 'any', 'trash', 'auto-draft' ),
					'numberposts' => -1,
				)
			)
		);

		if ( ! empty( $variation_ids ) ) {
			foreach ( $variation_ids as $variation_id ) {
				if ( $force_delete ) {
					wp_delete_post( $variation_id, true );
					$this->delete_from_custom_tables( $variation_id );
				} else {
					wp_trash_post( $variation_id );
				}
			}
		}

		delete_transient( 'wc_product_children_' . $product_id );
	}

	/**
	 * Untrash variations.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 */
	public function untrash_variations( $product_id ) {
		$variation_ids = wp_parse_id_list(
			get_posts(
				array(
					'post_parent' => $product_id,
					'post_type'   => 'product_variation',
					'fields'      => 'ids',
					'post_status' => 'trash',
					'numberposts' => -1,
				)
			)
		);

		if ( ! empty( $variation_ids ) ) {
			foreach ( $variation_ids as $variation_id ) {
				wp_untrash_post( $variation_id );
			}
		}

		delete_transient( 'wc_product_children_' . $product_id );
	}

	/**
	 * Create all possible product variations for a variable product.
	 *
	 * Delegates to WC's core CPT implementation since it handles
	 * the combinatorial logic. Our data store hooks will pick up
	 * the new variations and write them to the custom tables.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product        Variable product object.
	 * @param int         $limit          Max variations to create. Default -1 (unlimited).
	 * @param array       $default_values Default attribute values.
	 * @param array       $metadata       Additional metadata for variations.
	 * @return int Number of variations created.
	 */
	public function create_all_product_variations( $product, $limit = -1, $default_values = array(), $metadata = array() ) {
		$cpt_store = new \WC_Product_Variable_Data_Store_CPT();
		return $cpt_store->create_all_product_variations( $product, $limit, $default_values, $metadata );
	}

	/**
	 * Delete a variation's data from all custom tables.
	 *
	 * Reuses parent's helper which already cleans all 6 wpt_ tables.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Variation ID.
	 */
	protected function delete_from_custom_tables( $product_id ) {
		global $wpdb;

		$wpdb->delete( "{$wpdb->prefix}wpt_products", array( 'product_id' => $product_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}wpt_product_variation_attribute_values", array( 'variation_id' => $product_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}wpt_product_downloads", array( 'product_id' => $product_id ), array( '%d' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Cache
	|--------------------------------------------------------------------------
	*/

	/**
	 * Clear variable-specific caches plus parent caches.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function clear_caches( &$product ) {
		wp_cache_delete( 'woocommerce_product_child_has_weight_' . $product->get_id(), 'product' );
		wp_cache_delete( 'woocommerce_product_child_has_dimensions_' . $product->get_id(), 'product' );
		wp_cache_delete( 'woocommerce_product_children_stock_status_' . $product->get_id(), 'product' );
		delete_transient( 'wc_product_children_' . $product->get_id() );
		delete_transient( 'wc_var_prices_' . $product->get_id() );
		parent::clear_caches( $product );
	}
}
