<?php
/**
 * WC Product Data Store: Stored in Custom Tables (wpt_products, etc.)
 *
 * Authoritative data store that reads/writes to dedicated product tables.
 * Extends WC_Data_Store_WP for meta handling compatibility with WooCommerce core.
 *
 * @package WooCommerce\ProductTables\DataStores
 */

namespace WPT\DataStores;

defined( 'ABSPATH' ) || exit;

/**
 * Product Data Store class.
 *
 * Implements WC_Object_Data_Store_Interface and WC_Product_Data_Store_Interface
 * for full compatibility with WooCommerce product CRUD operations.
 */
class ProductDataStore extends \WC_Data_Store_WP implements \WC_Object_Data_Store_Interface, \WC_Product_Data_Store_Interface {

	/**
	 * Meta keys handled internally — excluded from generic meta reads.
	 *
	 * @var array
	 */
	protected $internal_meta_keys = array(
		'_backorders',
		'_sold_individually',
		'_purchase_note',
		'_manage_stock',
		'_low_stock_amount',
		'_wc_rating_count',
		'_wc_review_count',
		'_product_version',
		'_wp_old_slug',
		'_edit_last',
		'_edit_lock',
		'_download_limit',
		'_download_expiry',
	);

	/**
	 * Relationship type → product prop mapping.
	 *
	 * @var array
	 */
	protected $relationships = array(
		'image'      => 'gallery_image_ids',
		'upsell'     => 'upsell_ids',
		'cross_sell' => 'cross_sell_ids',
		'grouped'    => 'children',
	);

	/**
	 * List of props updated during current save operation.
	 *
	 * @var array
	 */
	protected $updated_props = array();

	/*
	|--------------------------------------------------------------------------
	| CRUD Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Create a new product.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function create( &$product ) {
		try {
			wc_transaction_query( 'start' );

			if ( ! $product->get_date_created( 'edit' ) ) {
				$product->set_date_created( time() );
			}

			$id = wp_insert_post(
				apply_filters(
					'woocommerce_new_product_data',
					array(
						'post_type'      => 'product',
						'post_status'    => $product->get_status() ? $product->get_status() : 'publish',
						'post_author'    => get_current_user_id(),
						'post_title'     => $product->get_name() ? $product->get_name() : __( 'Product', 'woocommerce' ),
						'post_content'   => $product->get_description(),
						'post_excerpt'   => $product->get_short_description(),
						'post_parent'    => $product->get_parent_id(),
						'comment_status' => $product->get_reviews_allowed() ? 'open' : 'closed',
						'ping_status'    => 'closed',
						'menu_order'     => $product->get_menu_order(),
						'post_date'      => gmdate( 'Y-m-d H:i:s', $product->get_date_created( 'edit' )->getOffsetTimestamp() ),
						'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $product->get_date_created( 'edit' )->getTimestamp() ),
						'post_name'      => $product->get_slug( 'edit' ),
					)
				),
				true
			);

			if ( empty( $id ) || is_wp_error( $id ) ) {
				throw new \Exception( 'db_error' );
			}

			$product->set_id( $id );

			$this->update_product_data( $product );
			$this->update_post_meta( $product, true );
			$this->update_terms( $product, true );
			$this->update_visibility( $product, true );
			$this->update_attributes( $product, true );
			$this->handle_updated_props( $product );

			$product->save_meta_data();
			$product->apply_changes();

			update_post_meta( $product->get_id(), '_product_version', \WC_VERSION );

			$this->clear_caches( $product );

			wc_transaction_query( 'commit' );

			/**
			 * Fires after a product is created via custom tables.
			 *
			 * @param int $product_id Product ID.
			 */
			do_action( 'woocommerce_new_product', $id, $product );
		} catch ( \Exception $e ) {
			wc_transaction_query( 'rollback' );
		}
	}

	/**
	 * Read a product from the database.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @throws \Exception If invalid product.
	 */
	public function read( &$product ) {
		$product->set_defaults();

		$post_object = $product->get_id() ? get_post( $product->get_id() ) : null;

		if ( ! $product->get_id() || ! $post_object || 'product' !== $post_object->post_type ) {
			throw new \Exception( __( 'Invalid product.', 'woocommerce' ) );
		}

		$product->set_props(
			array(
				'name'              => $post_object->post_title,
				'slug'              => $post_object->post_name,
				'date_created'      => 0 < $post_object->post_date_gmt ? wc_string_to_timestamp( $post_object->post_date_gmt ) : null,
				'date_modified'     => 0 < $post_object->post_modified_gmt ? wc_string_to_timestamp( $post_object->post_modified_gmt ) : null,
				'status'            => $post_object->post_status,
				'description'       => $post_object->post_content,
				'short_description' => $post_object->post_excerpt,
				'parent_id'         => $post_object->post_parent,
				'menu_order'        => $post_object->menu_order,
				'post_password'     => $post_object->post_password,
				'reviews_allowed'   => 'open' === $post_object->comment_status,
			)
		);

		// Check if the product has been migrated to custom tables.
		if ( $this->get_product_row_from_db( $product->get_id() ) ) {
			$this->read_attributes( $product );
			$this->read_downloads( $product );
			$this->read_product_data( $product );
		} else {
			// Product not yet migrated — read from postmeta.
			$this->read_product_data_from_meta( $product );
			$this->read_attributes_from_meta( $product );
			$this->read_downloads_from_meta( $product );
		}

		$this->read_visibility( $product );
		$this->read_extra_data( $product );

		$product->set_object_read( true );
	}

	/**
	 * Update an existing product.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function update( &$product ) {
		$product->save_meta_data();

		if ( ! $product->get_date_created( 'edit' ) ) {
			$product->set_date_created( time() );
		}

		$changes = $product->get_changes();

		if ( array_intersect(
			array( 'name', 'parent_id', 'status', 'menu_order', 'date_created', 'date_modified', 'description', 'short_description', 'post_password', 'reviews_allowed' ),
			array_keys( $changes )
		) ) {
			$post_data = array(
				'post_content'      => $product->get_description( 'edit' ),
				'post_excerpt'      => $product->get_short_description( 'edit' ),
				'post_title'        => $product->get_name( 'edit' ),
				'post_parent'       => $product->get_parent_id( 'edit' ),
				'comment_status'    => $product->get_reviews_allowed( 'edit' ) ? 'open' : 'closed',
				'post_status'       => $product->get_status( 'edit' ) ? $product->get_status( 'edit' ) : 'publish',
				'menu_order'        => $product->get_menu_order( 'edit' ),
				'post_password'     => $product->get_post_password( 'edit' ),
				'post_type'         => 'product',
				'post_date'         => gmdate( 'Y-m-d H:i:s', $product->get_date_created( 'edit' )->getOffsetTimestamp() ),
				'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $product->get_date_created( 'edit' )->getTimestamp() ),
				'post_modified'     => isset( $changes['date_modified'] ) ? gmdate( 'Y-m-d H:i:s', $product->get_date_modified( 'edit' )->getOffsetTimestamp() ) : current_time( 'mysql' ),
				'post_modified_gmt' => isset( $changes['date_modified'] ) ? gmdate( 'Y-m-d H:i:s', $product->get_date_modified( 'edit' )->getTimestamp() ) : current_time( 'mysql', 1 ),
			);

			/**
			 * When updating this object, to prevent infinite loops, use $wpdb
			 * to update data when inside save_post action, since wp_update_post
			 * spawns more calls to the save_post action.
			 */
			if ( doing_action( 'save_post' ) ) {
				$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, $post_data, array( 'ID' => $product->get_id() ) );
				clean_post_cache( $product->get_id() );
			} else {
				wp_update_post( array_merge( array( 'ID' => $product->get_id() ), $post_data ) );
			}
			$product->read_meta_data( true );
		}

		$this->update_product_data( $product );
		$this->update_post_meta( $product );
		$this->update_terms( $product );
		$this->update_visibility( $product );
		$this->update_attributes( $product );
		$this->handle_updated_props( $product );

		$product->apply_changes();

		update_post_meta( $product->get_id(), '_product_version', \WC_VERSION );

		$this->clear_caches( $product );

		do_action( 'woocommerce_update_product', $product->get_id(), $product );
	}

	/**
	 * Delete a product from the database.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param array       $args Array of args to pass to the delete method.
	 */
	public function delete( &$product, $args = array() ) {
		$id   = $product->get_id();
		$args = wp_parse_args( $args, array( 'force_delete' => false ) );

		if ( ! $id ) {
			return;
		}

		if ( $args['force_delete'] ) {
			do_action( 'woocommerce_before_delete_product', $id );

			$this->delete_from_custom_tables( $id );

			wp_delete_post( $id, true );
			$product->set_id( 0 );

			do_action( 'woocommerce_delete_product', $id );
		} else {
			wp_trash_post( $id );
			$product->set_status( 'trash' );

			do_action( 'woocommerce_trash_product', $id );
		}
	}

	/**
	 * Delete product data from all custom tables.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 */
	protected function delete_from_custom_tables( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );
		$tables     = array(
			"{$wpdb->prefix}wpt_product_variation_attribute_values" => 'product_id',
			"{$wpdb->prefix}wpt_product_attribute_values"          => 'product_id',
			"{$wpdb->prefix}wpt_product_attributes"                => 'product_id',
			"{$wpdb->prefix}wpt_product_downloads"                 => 'product_id',
			"{$wpdb->prefix}wpt_product_relationships"             => 'product_id',
			"{$wpdb->prefix}wpt_products"                          => 'product_id',
		);

		foreach ( $tables as $table => $column ) {
			$wpdb->delete( $table, array( $column => $product_id ), array( '%d' ) );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Product Data — Custom Table Read/Write
	|--------------------------------------------------------------------------
	*/

	/**
	 * Store data into the custom wpt_products table.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product The product object.
	 */
	protected function update_product_data( &$product ) {
		global $wpdb;

		$data    = array( 'type' => $product->get_type() );
		$changes = $product->get_changes();
		$row     = $this->get_product_row_from_db( $product->get_id() );
		$insert  = ! $row;

		$columns = array(
			'sku',
			'image_id',
			'height',
			'length',
			'width',
			'weight',
			'stock_quantity',
			'virtual',
			'downloadable',
			'tax_class',
			'tax_status',
			'total_sales',
			'price',
			'regular_price',
			'sale_price',
			'date_on_sale_from',
			'date_on_sale_to',
			'average_rating',
			'stock_status',
			'manage_stock',
			'backorders',
			'low_stock_amount',
			'sold_individually',
			'purchase_note',
		);

		$date_columns = array( 'date_on_sale_from', 'date_on_sale_to' );

		$allow_null = array(
			'height', 'length', 'width', 'weight', 'stock_quantity',
			'price', 'regular_price', 'sale_price',
			'date_on_sale_from', 'date_on_sale_to',
			'average_rating', 'low_stock_amount', 'purchase_note',
		);

		foreach ( $columns as $column ) {
			if ( $insert || array_key_exists( $column, $changes ) ) {
				$value = $product->{"get_$column"}( 'edit' );

				if ( in_array( $column, $date_columns, true ) ) {
					$data[ $column ] = empty( $value ) ? null : gmdate( 'Y-m-d H:i:s', $value->getOffsetTimestamp() );
				} elseif ( 'manage_stock' === $column ) {
					$data[ $column ] = wc_bool_to_string( $value ) === 'yes' ? 1 : 0;
				} elseif ( 'sold_individually' === $column ) {
					$data[ $column ] = wc_bool_to_string( $value ) === 'yes' ? 1 : 0;
				} else {
					$data[ $column ] = '' === $value && in_array( $column, $allow_null, true ) ? null : $value;
				}
				$this->updated_props[] = $column;
			}
		}

		if ( $insert ) {
			$data['product_id'] = $product->get_id();
			$wpdb->insert( "{$wpdb->prefix}wpt_products", $data );
		} elseif ( count( $data ) > 1 ) { // More than just 'type'.
			$wpdb->update(
				"{$wpdb->prefix}wpt_products",
				$data,
				array( 'product_id' => $product->get_id() )
			);
		}

		// Relationship updates.
		foreach ( $this->relationships as $type => $prop ) {
			if ( array_key_exists( $prop, $changes ) || $insert ) {
				$this->update_relationship( $product, $type );
				$this->updated_props[] = $type;
			}
		}
	}

	/**
	 * Get a single product row from the custom table (cached).
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 * @return array|false Row as associative array, or false.
	 */
	protected function get_product_row_from_db( $product_id ) {
		global $wpdb;

		// Use dedicated cache key to avoid collision with WC's product object cache.
		$cache_key = 'wpt_row_' . $product_id;
		$data      = wp_cache_get( $cache_key, 'wpt' );

		if ( false === $data ) {
			$data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wpt_products WHERE product_id = %d",
					$product_id
				),
				ARRAY_A
			);

			// Cache result (empty array for miss) to prevent repeated queries.
			wp_cache_set( $cache_key, $data ? $data : array(), 'wpt' );
		}

		return ! empty( $data ) ? $data : false;
	}

	/**
	 * Get product relationships from the database (cached).
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	protected function get_product_relationship_rows_from_db( $product_id ) {
		global $wpdb;

		$cache_key = 'woocommerce_product_relationships_' . $product_id;
		$data      = wp_cache_get( $cache_key, 'product' );

		if ( false === $data ) {
			$data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT type, child_id, position
					 FROM {$wpdb->prefix}wpt_product_relationships
					 WHERE product_id = %d
					 ORDER BY position ASC",
					$product_id
				)
			);

			wp_cache_set( $cache_key, $data, 'product' );
		}

		return (array) $data;
	}

	/**
	 * Get product download rows from the database (cached).
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	protected function get_product_downloads_rows_from_db( $product_id ) {
		global $wpdb;

		$cache_key = 'woocommerce_product_downloads_' . $product_id;
		$data      = wp_cache_get( $cache_key, 'product' );

		if ( false === $data ) {
			$data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT download_key, name, file
					 FROM {$wpdb->prefix}wpt_product_downloads
					 WHERE product_id = %d
					 ORDER BY download_id ASC",
					$product_id
				)
			);

			wp_cache_set( $cache_key, $data, 'product' );
		}

		return (array) $data;
	}

	/**
	 * Read product data from custom table and set product props.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_product_data( &$product ) {
		$id    = $product->get_id();
		$props = $this->get_product_row_from_db( $id );

		if ( ! $props ) {
			return;
		}

		// Convert manage_stock from int to bool.
		$props['manage_stock'] = ! empty( $props['manage_stock'] );

		// Convert sold_individually from int to bool.
		$props['sold_individually'] = ! empty( $props['sold_individually'] );

		// Props still in postmeta (not in custom table).
		$meta_to_props = array(
			'_wc_rating_count' => 'rating_counts',
			'_wc_review_count' => 'review_count',
			'_download_limit'  => 'download_limit',
			'_download_expiry' => 'download_expiry',
		);

		foreach ( $meta_to_props as $meta_key => $prop ) {
			$props[ $prop ] = get_post_meta( $id, $meta_key, true );
		}

		// Taxonomy props.
		$taxonomies_to_props = array(
			'product_cat'            => 'category_ids',
			'product_tag'            => 'tag_ids',
			'product_shipping_class' => 'shipping_class_id',
		);

		foreach ( $taxonomies_to_props as $taxonomy => $prop ) {
			$props[ $prop ] = $this->get_term_ids( $product, $taxonomy );

			if ( 'shipping_class_id' === $prop ) {
				$props[ $prop ] = current( $props[ $prop ] );
			}
		}

		// Relationship props.
		$relationship_rows = $this->get_product_relationship_rows_from_db( $id );

		foreach ( $this->relationships as $type => $prop ) {
			$related = array_filter(
				$relationship_rows,
				function ( $row ) use ( $type ) {
					return ! empty( $row->type ) && $row->type === $type;
				}
			);

			$props[ $prop ] = array_values( wp_list_pluck( $related, 'child_id' ) );
		}

		// Remove keys that don't map to product props.
		unset( $props['product_id'], $props['type'], $props['rating_count'] );

		$product->set_props( $props );

		// Set price based on current sale status.
		if ( $product->is_on_sale( 'edit' ) ) {
			$product->set_price( $product->get_sale_price( 'edit' ) );
		} else {
			$product->set_price( $product->get_regular_price( 'edit' ) );
		}
	}

	/**
	 * Handle updated props after saving — price syncing, stock actions, etc.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function handle_updated_props( &$product ) {
		global $wpdb;

		// Sale/regular price logic: if sale_price >= regular_price, clear sale_price.
		if ( in_array( 'regular_price', $this->updated_props, true ) || in_array( 'sale_price', $this->updated_props, true ) ) {
			if ( $product->get_sale_price( 'edit' ) >= $product->get_regular_price( 'edit' ) ) {
				$wpdb->update(
					"{$wpdb->prefix}wpt_products",
					array( 'sale_price' => null ),
					array( 'product_id' => $product->get_id() )
				);
				$product->set_sale_price( '' );
			}
		}

		// Set price to sale or regular.
		if ( in_array( 'date_on_sale_from', $this->updated_props, true )
			|| in_array( 'date_on_sale_to', $this->updated_props, true )
			|| in_array( 'regular_price', $this->updated_props, true )
			|| in_array( 'sale_price', $this->updated_props, true )
		) {
			if ( $product->is_on_sale( 'edit' ) ) {
				$price = $product->get_sale_price( 'edit' );
			} else {
				$price = $product->get_regular_price( 'edit' );
			}

			$wpdb->update(
				"{$wpdb->prefix}wpt_products",
				array( 'price' => '' === $price ? null : $price ),
				array( 'product_id' => $product->get_id() )
			);
		}

		// Stock status transitions.
		if ( in_array( 'stock_quantity', $this->updated_props, true ) ) {
			do_action( 'woocommerce_product_set_stock', $product );
		}

		if ( in_array( 'stock_status', $this->updated_props, true ) ) {
			do_action( 'woocommerce_product_set_stock_status', $product->get_id(), $product->get_stock_status(), $product );
		}

		if ( array_intersect( $this->updated_props, array( 'sku', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'total_sales', 'average_rating', 'stock_quantity', 'stock_status', 'manage_stock', 'downloadable', 'virtual' ) ) ) {
			$this->update_lookup_table( $product->get_id(), 'wc_product_meta_lookup' );
		}

		/**
		 * Fires after product props have been updated.
		 *
		 * @param \WC_Product $product       Product object.
		 * @param array       $updated_props List of updated props.
		 */
		do_action( 'woocommerce_product_object_updated_props', $product, $this->updated_props );

		// Reset for next save.
		$this->updated_props = array();
	}

	/**
	 * Clear product caches.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function clear_caches( &$product ) {
		$id = $product->get_id();

		// Custom table caches.
		wp_cache_delete( 'wpt_row_' . $id, 'wpt' );

		// WC standard caches.
		wp_cache_delete( 'woocommerce_product_' . $id, 'product' );
		wp_cache_delete( 'woocommerce_product_relationships_' . $id, 'product' );
		wp_cache_delete( 'woocommerce_product_downloads_' . $id, 'product' );
		wp_cache_delete( 'woocommerce_product_attributes_' . $id, 'product' );

		wc_delete_product_transients( $id );

		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
	}

	/**
	 * Get the product type from the custom table.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 * @return string|false
	 */
	public function get_product_type( $product_id ) {
		global $wpdb;

		$cache_key = 'woocommerce_product_type_' . $product_id;
		$type      = wp_cache_get( $cache_key, 'product' );

		if ( false === $type ) {
			$type = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT type FROM {$wpdb->prefix}wpt_products WHERE product_id = %d",
					$product_id
				)
			);

			// Fallback to product_type taxonomy for unmigrated products.
			if ( ! $type ) {
				$terms = get_the_terms( $product_id, 'product_type' );
				$type  = ! empty( $terms ) && ! is_wp_error( $terms ) ? sanitize_title( current( $terms )->name ) : 'simple';
			}

			wp_cache_set( $cache_key, $type, 'product' );
		}

		return $type ? $type : false;
	}

	/*
	|--------------------------------------------------------------------------
	| Meta / Terms / Visibility
	|--------------------------------------------------------------------------
	*/

	/**
	 * Update postmeta for product properties NOT stored in the custom table.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $force   Force update all meta.
	 */
	protected function update_post_meta( &$product, $force = false ) {
		$meta_key_to_props = array(
			'_download_limit'  => 'download_limit',
			'_download_expiry' => 'download_expiry',
		);

		$props_to_update = $force
			? $meta_key_to_props
			: $this->get_props_to_update( $product, $meta_key_to_props );

		foreach ( $props_to_update as $meta_key => $prop ) {
			$value = is_callable( array( $product, "get_{$prop}" ) )
				? $product->{"get_$prop"}( 'edit' )
				: '';

			$updated = update_post_meta( $product->get_id(), $meta_key, $value );

			if ( $updated ) {
				$this->updated_props[] = $prop;
			}
		}

		// Handle extra data (custom meta from extensions).
		if ( method_exists( $product, 'get_extra_data_keys' ) ) {
			foreach ( $product->get_extra_data_keys() as $key ) {
				$function = 'get_' . $key;

				if ( is_callable( array( $product, $function ) )
					&& ( $force || array_key_exists( $key, $product->get_changes() ) ) ) {
					update_post_meta( $product->get_id(), '_' . $key, $product->{$function}( 'edit' ) );
				}
			}
		}

		$this->update_downloads( $product, $force );
	}

	/**
	 * Update product taxonomy terms.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $force   Force update.
	 */
	protected function update_terms( &$product, $force = false ) {
		if ( $force || array_key_exists( 'category_ids', $product->get_changes() ) ) {
			wp_set_post_terms( $product->get_id(), $product->get_category_ids( 'edit' ), 'product_cat', false );
		}
		if ( $force || array_key_exists( 'tag_ids', $product->get_changes() ) ) {
			wp_set_post_terms( $product->get_id(), $product->get_tag_ids( 'edit' ), 'product_tag', false );
		}
		if ( $force || array_key_exists( 'shipping_class_id', $product->get_changes() ) ) {
			wp_set_post_terms( $product->get_id(), array( $product->get_shipping_class_id( 'edit' ) ), 'product_shipping_class', false );
		}
	}

	/**
	 * Update product visibility terms.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $force   Force update.
	 */
	protected function update_visibility( &$product, $force = false ) {
		$changes = $product->get_changes();

		if ( $force || array_intersect( array( 'catalog_visibility', 'stock_status', 'featured' ), array_keys( $changes ) ) ) {
			$terms = array();

			if ( $product->get_featured() ) {
				$terms[] = 'featured';
			}

			$visibility = $product->get_catalog_visibility();

			if ( 'catalog' === $visibility ) {
				$terms[] = 'exclude-from-search';
			} elseif ( 'search' === $visibility ) {
				$terms[] = 'exclude-from-catalog';
			} elseif ( 'hidden' === $visibility ) {
				$terms[] = 'exclude-from-catalog';
				$terms[] = 'exclude-from-search';
			}

			if ( 'outofstock' === $product->get_stock_status() ) {
				$terms[] = 'outofstock';
			}

			wp_set_post_terms( $product->get_id(), $terms, 'product_visibility', false );
		}
	}

	/**
	 * Read visibility from taxonomy terms.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_visibility( &$product ) {
		$terms = get_the_terms( $product->get_id(), 'product_visibility' );

		if ( ! $terms || is_wp_error( $terms ) ) {
			$terms = array();
		}

		$term_names = wp_list_pluck( $terms, 'name' );

		if ( in_array( 'exclude-from-catalog', $term_names, true ) && in_array( 'exclude-from-search', $term_names, true ) ) {
			$product->set_catalog_visibility( 'hidden' );
		} elseif ( in_array( 'exclude-from-catalog', $term_names, true ) ) {
			$product->set_catalog_visibility( 'search' );
		} elseif ( in_array( 'exclude-from-search', $term_names, true ) ) {
			$product->set_catalog_visibility( 'catalog' );
		} else {
			$product->set_catalog_visibility( 'visible' );
		}

		$product->set_featured( in_array( 'featured', $term_names, true ) );
	}

	/**
	 * Read extra data from postmeta (for extensions that register extra data keys).
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_extra_data( &$product ) {
		if ( ! method_exists( $product, 'get_extra_data_keys' ) ) {
			return;
		}

		foreach ( $product->get_extra_data_keys() as $key ) {
			$function = 'set_' . $key;

			if ( is_callable( array( $product, $function ) ) ) {
				$product->{$function}( get_post_meta( $product->get_id(), '_' . $key, true ) );
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Postmeta Fallback — for products not yet migrated to custom tables
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read product data from postmeta (fallback for unmigrated products).
	 *
	 * Mirrors what WC_Product_Data_Store_CPT::read_product_data() does,
	 * so products display correctly even before `wp wpt migrate` runs.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_product_data_from_meta( &$product ) {
		$id = $product->get_id();

		$meta_to_props = array(
			'_sku'                   => 'sku',
			'_thumbnail_id'          => 'image_id',
			'_virtual'               => 'virtual',
			'_downloadable'          => 'downloadable',
			'_regular_price'         => 'regular_price',
			'_sale_price'            => 'sale_price',
			'_sale_price_dates_from' => 'date_on_sale_from',
			'_sale_price_dates_to'   => 'date_on_sale_to',
			'total_sales'            => 'total_sales',
			'_tax_status'            => 'tax_status',
			'_tax_class'             => 'tax_class',
			'_stock'                 => 'stock_quantity',
			'_stock_status'          => 'stock_status',
			'_manage_stock'          => 'manage_stock',
			'_backorders'            => 'backorders',
			'_low_stock_amount'      => 'low_stock_amount',
			'_sold_individually'     => 'sold_individually',
			'_weight'                => 'weight',
			'_length'                => 'length',
			'_width'                 => 'width',
			'_height'                => 'height',
			'_wc_average_rating'     => 'average_rating',
			'_wc_rating_count'       => 'rating_count',
			'_purchase_note'         => 'purchase_note',
			'_wc_review_count'       => 'review_count',
			'_download_limit'        => 'download_limit',
			'_download_expiry'       => 'download_expiry',
		);

		$set_props = array();
		foreach ( $meta_to_props as $meta_key => $prop ) {
			$set_props[ $prop ] = get_post_meta( $id, $meta_key, true );
		}

		// Taxonomy props.
		$set_props['category_ids']      = $this->get_term_ids( $product, 'product_cat' );
		$set_props['tag_ids']           = $this->get_term_ids( $product, 'product_tag' );
		$set_props['shipping_class_id'] = current( $this->get_term_ids( $product, 'product_shipping_class' ) );

		// Relationship props from postmeta.
		$set_props['upsell_ids']       = array_filter( array_map( 'intval', (array) get_post_meta( $id, '_upsell_ids', true ) ) );
		$set_props['cross_sell_ids']   = array_filter( array_map( 'intval', (array) get_post_meta( $id, '_crosssell_ids', true ) ) );
		$set_props['children']         = array_filter( array_map( 'intval', (array) get_post_meta( $id, '_children', true ) ) );

		$gallery_raw = get_post_meta( $id, '_product_image_gallery', true );
		$set_props['gallery_image_ids'] = $gallery_raw ? array_filter( array_map( 'intval', explode( ',', $gallery_raw ) ) ) : array();

		$product->set_props( $set_props );

		// Set active price based on sale status.
		if ( $product->is_on_sale( 'edit' ) ) {
			$product->set_price( $product->get_sale_price( 'edit' ) );
		} else {
			$product->set_price( $product->get_regular_price( 'edit' ) );
		}
	}

	/**
	 * Read product attributes from postmeta (fallback for unmigrated products).
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_attributes_from_meta( &$product ) {
		$raw_attributes = get_post_meta( $product->get_id(), '_product_attributes', true );

		if ( ! is_array( $raw_attributes ) ) {
			return;
		}

		$attributes      = array();
		$default_attributes = get_post_meta( $product->get_id(), '_default_attributes', true );

		if ( ! is_array( $default_attributes ) ) {
			$default_attributes = array();
		}

		foreach ( $raw_attributes as $slug => $attr_data ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( $attr_data['name'] ?? $slug );
			$attribute->set_position( $attr_data['position'] ?? 0 );
			$attribute->set_visible( ! empty( $attr_data['is_visible'] ) );
			$attribute->set_variation( ! empty( $attr_data['is_variation'] ) );

			if ( ! empty( $attr_data['is_taxonomy'] ) ) {
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $attr_data['name'] ) );
				$terms = wp_get_post_terms( $product->get_id(), $attr_data['name'], array( 'fields' => 'ids' ) );
				$attribute->set_options( is_wp_error( $terms ) ? array() : $terms );
			} else {
				$attribute->set_id( 0 );
				$options = ! empty( $attr_data['value'] )
					? array_map( 'trim', explode( '|', $attr_data['value'] ) )
					: array();
				$attribute->set_options( array_filter( $options ) );
			}

			$attributes[ $slug ] = $attribute;
		}

		$product->set_attributes( $attributes );

		if ( ! empty( $default_attributes ) ) {
			$product->set_default_attributes( $default_attributes );
		}
	}

	/**
	 * Read product downloads from postmeta (fallback for unmigrated products).
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_downloads_from_meta( &$product ) {
		$raw = get_post_meta( $product->get_id(), '_downloadable_files', true );

		if ( ! is_array( $raw ) ) {
			return;
		}

		$downloads = array();
		foreach ( $raw as $download_key => $file_data ) {
			$download = new \WC_Product_Download();
			$download->set_id( $download_key );
			$download->set_name( $file_data['name'] ?? '' );
			$download->set_file( $file_data['file'] ?? '' );
			$downloads[ $download_key ] = $download;
		}

		$product->set_downloads( $downloads );
	}

	/*
	|--------------------------------------------------------------------------
	| Attributes
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read product attributes from custom tables.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_attributes( &$product ) {
		global $wpdb;

		$cache_key          = 'woocommerce_product_attributes_' . $product->get_id();
		$cached_attributes  = wp_cache_get( $cache_key, 'product' );

		if ( false !== $cached_attributes ) {
			$product->set_attributes( $cached_attributes );
			return;
		}

		$raw_attributes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_attribute_id, name, value, position, is_visible, is_variation, is_taxonomy
				 FROM {$wpdb->prefix}wpt_product_attributes
				 WHERE product_id = %d
				 ORDER BY position ASC",
				$product->get_id()
			)
		);

		if ( ! $raw_attributes ) {
			wp_cache_set( $cache_key, array(), 'product' );
			return;
		}

		// Collect attribute IDs for batch value lookup.
		$attr_ids    = wp_list_pluck( $raw_attributes, 'product_attribute_id' );
		$attr_values = array();

		if ( ! empty( $attr_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $attr_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$value_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT product_attribute_id, value, is_default
					 FROM {$wpdb->prefix}wpt_product_attribute_values
					 WHERE product_attribute_id IN ({$placeholders})",
					...$attr_ids
				)
			);

			foreach ( $value_rows as $row ) {
				$attr_values[ $row->product_attribute_id ][] = $row;
			}
		}

		$attributes = array();

		foreach ( $raw_attributes as $raw ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( $raw->is_taxonomy ? wc_attribute_taxonomy_id_by_name( $raw->name ) : 0 );
			$attribute->set_name( $raw->name );
			$attribute->set_position( $raw->position );
			$attribute->set_visible( $raw->is_visible );
			$attribute->set_variation( $raw->is_variation );

			$values = $attr_values[ $raw->product_attribute_id ] ?? array();

			if ( $raw->is_taxonomy ) {
				$term_ids = array();
				foreach ( $values as $v ) {
					$term = get_term_by( 'slug', $v->value, $raw->name );
					if ( $term ) {
						$term_ids[] = $term->term_id;
					}
				}
				$attribute->set_options( $term_ids );
			} else {
				$attribute->set_options( wp_list_pluck( $values, 'value' ) );
			}

			$attributes[ sanitize_title( $raw->name ) ] = $attribute;
		}

		$product->set_attributes( $attributes );
		wp_cache_set( $cache_key, $attributes, 'product' );
	}

	/**
	 * Update product attributes in custom tables.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $force   Force update.
	 */
	protected function update_attributes( &$product, $force = false ) {
		global $wpdb;

		$changes = $product->get_changes();

		if ( ! $force && ! array_key_exists( 'attributes', $changes ) ) {
			return;
		}

		$attributes  = $product->get_attributes();
		$product_id  = $product->get_id();
		$table_attrs = "{$wpdb->prefix}wpt_product_attributes";
		$table_vals  = "{$wpdb->prefix}wpt_product_attribute_values";

		// Get existing attribute IDs for cleanup.
		$existing_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product_attribute_id FROM {$table_attrs} WHERE product_id = %d",
				$product_id
			)
		);

		$updated_ids = array();

		foreach ( $attributes as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) ) {
				continue;
			}

			$attr_data = array(
				'product_id'   => $product_id,
				'name'         => $attribute->get_name(),
				'value'        => '',
				'position'     => $attribute->get_position(),
				'is_visible'   => $attribute->get_visible() ? 1 : 0,
				'is_variation' => $attribute->get_variation() ? 1 : 0,
				'is_taxonomy'  => $attribute->is_taxonomy() ? 1 : 0,
			);

			// Check for existing row by product_id + name.
			$existing_attr_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT product_attribute_id FROM {$table_attrs} WHERE product_id = %d AND name = %s",
					$product_id,
					$attribute->get_name()
				)
			);

			if ( $existing_attr_id ) {
				$wpdb->update(
					$table_attrs,
					$attr_data,
					array( 'product_attribute_id' => $existing_attr_id )
				);
				$attr_id = $existing_attr_id;
			} else {
				$wpdb->insert( $table_attrs, $attr_data );
				$attr_id = $wpdb->insert_id;
			}

			$updated_ids[] = (int) $attr_id;

			// Update attribute values.
			$wpdb->delete( $table_vals, array( 'product_attribute_id' => $attr_id ), array( '%d' ) );

			$options = $attribute->is_taxonomy()
				? wp_get_post_terms( $product_id, $attribute->get_name(), array( 'fields' => 'slugs' ) )
				: $attribute->get_options();

			foreach ( (array) $options as $value ) {
				$wpdb->insert(
					$table_vals,
					array(
						'product_id'           => $product_id,
						'product_attribute_id'  => $attr_id,
						'value'                => is_array( $value ) ? '' : (string) $value,
						'is_default'           => 0,
					)
				);
			}

			// Sync taxonomy terms.
			if ( $attribute->is_taxonomy() ) {
				wp_set_object_terms( $product_id, wp_list_pluck( (array) $attribute->get_options(), null ), $attribute->get_name() );
			}
		}

		// Delete removed attributes safely using prepared statement.
		$removed_ids = array_diff( array_map( 'intval', $existing_ids ), $updated_ids );

		if ( ! empty( $removed_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $removed_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_vals} WHERE product_attribute_id IN ({$placeholders})",
					...$removed_ids
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_attrs} WHERE product_attribute_id IN ({$placeholders})",
					...$removed_ids
				)
			);
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Downloads
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read product downloads from custom table.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_downloads( &$product ) {
		$rows      = $this->get_product_downloads_rows_from_db( $product->get_id() );
		$downloads = array();

		foreach ( $rows as $row ) {
			$download = new \WC_Product_Download();
			$download->set_id( $row->download_key );
			$download->set_name( $row->name );
			$download->set_file( $row->file );
			$downloads[ $row->download_key ] = $download;
		}

		$product->set_downloads( $downloads );
	}

	/**
	 * Update product downloads in custom table.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $force   Force update.
	 */
	protected function update_downloads( &$product, $force = false ) {
		global $wpdb;

		$changes = $product->get_changes();

		if ( ! $force && ! array_key_exists( 'downloads', $changes ) ) {
			return;
		}

		$product_id = $product->get_id();
		$downloads  = $product->get_downloads();

		// Get existing download keys.
		$existing_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT download_key FROM {$wpdb->prefix}wpt_product_downloads WHERE product_id = %d",
				$product_id
			)
		);

		$new_keys = array_keys( $downloads );

		// Delete removed downloads safely.
		$removed_keys = array_diff( $existing_keys, $new_keys );

		if ( ! empty( $removed_keys ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $removed_keys ), '%s' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}wpt_product_downloads WHERE product_id = %d AND download_key IN ({$placeholders})",
					array_merge( array( $product_id ), array_values( $removed_keys ) )
				)
			);
		}

		// Insert or update remaining downloads.
		foreach ( $downloads as $download ) {
			$exists = in_array( $download->get_id(), $existing_keys, true );

			if ( $exists ) {
				$wpdb->update(
					"{$wpdb->prefix}wpt_product_downloads",
					array(
						'name' => $download->get_name(),
						'file' => $download->get_file(),
					),
					array(
						'product_id'   => $product_id,
						'download_key' => $download->get_id(),
					)
				);
			} else {
				$wpdb->insert(
					"{$wpdb->prefix}wpt_product_downloads",
					array(
						'product_id'   => $product_id,
						'download_key' => $download->get_id(),
						'name'         => $download->get_name(),
						'file'         => $download->get_file(),
					)
				);
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Relationships
	|--------------------------------------------------------------------------
	*/

	/**
	 * Update a relationship type in the custom table.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param string      $type    Relationship type.
	 */
	protected function update_relationship( $product, $type ) {
		global $wpdb;

		$prop      = $this->relationships[ $type ];
		$child_ids = $product->{"get_$prop"}( 'edit' );
		$child_ids = array_filter( array_map( 'absint', (array) $child_ids ) );

		// Delete all existing for this type.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wpt_product_relationships WHERE product_id = %d AND type = %s",
				$product->get_id(),
				$type
			)
		);

		// Re-insert with position.
		$position = 0;
		foreach ( $child_ids as $child_id ) {
			$wpdb->insert(
				"{$wpdb->prefix}wpt_product_relationships",
				array(
					'product_id' => $product->get_id(),
					'child_id'   => $child_id,
					'type'       => $type,
					'position'   => $position++,
				)
			);
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Query & Search
	|--------------------------------------------------------------------------
	*/

	/**
	 * Search products by keyword.
	 *
	 * @since 2.0.0
	 *
	 * @param string $term       Search term.
	 * @param string $type       Product type.
	 * @param bool   $include_variations Include variations.
	 * @param bool   $all_statuses       Include all statuses.
	 * @param int    $limit              Limit.
	 * @param array  $include            Product IDs to include.
	 * @param array  $exclude            Product IDs to exclude.
	 * @return array Product IDs.
	 */
	public function search_products( $term, $type = '', $include_variations = false, $all_statuses = false, $limit = null, $include = null, $exclude = null ) {
		global $wpdb;

		$post_types   = $include_variations ? array( 'product', 'product_variation' ) : array( 'product' );
		$type_where   = " AND posts.post_type IN ('" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "')";
		$status_where = $all_statuses ? '' : " AND posts.post_status = 'publish'";
		$limit_query  = $limit ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
		$term         = wc_strtolower( $term );

		if ( ! empty( $include ) ) {
			$include_ids  = implode( ',', array_map( 'absint', $include ) );
			$type_where  .= " AND posts.ID IN ({$include_ids})";
		}

		if ( ! empty( $exclude ) ) {
			$exclude_ids  = implode( ',', array_map( 'absint', $exclude ) );
			$type_where  .= " AND posts.ID NOT IN ({$exclude_ids})";
		}

		// Break phrase into words and search each.
		$search_terms  = array_filter( array_map( 'trim', explode( ' ', $term ) ) );
		$search_where  = array();

		foreach ( $search_terms as $search_term ) {
			$like           = '%' . $wpdb->esc_like( $search_term ) . '%';
			$search_where[] = $wpdb->prepare(
				"(posts.post_title LIKE %s OR posts.post_excerpt LIKE %s OR posts.post_content LIKE %s OR products.sku LIKE %s)",
				$like,
				$like,
				$like,
				$like
			);
		}

		$search_sql = ! empty( $search_where ) ? ' AND (' . implode( ' OR ', $search_where ) . ')' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$product_ids = $wpdb->get_col(
			"SELECT DISTINCT posts.ID FROM {$wpdb->posts} AS posts
			 LEFT JOIN {$wpdb->prefix}wpt_products AS products ON posts.ID = products.product_id
			 WHERE 1=1 {$search_sql} {$type_where} {$status_where}
			 ORDER BY posts.post_title ASC {$limit_query}"
		);

		if ( is_numeric( $term ) ) {
			$product_ids = array_unique(
				array_merge(
					array( absint( $term ) ),
					$product_ids,
					$wpdb->get_col(
						$wpdb->prepare(
							"SELECT product_id FROM {$wpdb->prefix}wpt_products WHERE sku = %s",
							$term
						)
					)
				)
			);
		}

		return wp_parse_id_list( $product_ids );
	}

	/**
	 * Get products.
	 *
	 * @since 2.0.0
	 *
	 * @param array $query_vars WC_Product_Query args.
	 * @return array
	 */
	public function get_products( $query_vars = array() ) {
		// This is an alias for query() — used by wc_get_products.
		$query_args = $this->get_wp_query_args( $query_vars );

		// Attach custom table filters.
		$custom_table_query = $query_args['wpt_products_query'] ?? array();

		if ( ! empty( $custom_table_query ) ) {
			add_filter( 'posts_join', array( $this, 'products_join' ), 10, 2 );
			add_filter( 'posts_where', array( $this, 'products_where' ), 10, 2 );
		}

		$query_args['post_type']           = isset( $query_vars['type'] ) && 'variation' === $query_vars['type'] ? 'product_variation' : 'product';
		$query_args['wpt_products_query']  = $custom_table_query;

		$results = new \WP_Query( $query_args );

		// Remove filters.
		remove_filter( 'posts_join', array( $this, 'products_join' ), 10 );
		remove_filter( 'posts_where', array( $this, 'products_where' ), 10 );

		$return = $query_vars['return'] ?? 'objects';

		if ( 'ids' === $return ) {
			$products = $results->posts;
		} else {
			$products = array_filter( array_map( 'wc_get_product', $results->posts ) );
		}

		if ( isset( $query_vars['paginate'] ) && $query_vars['paginate'] ) {
			return (object) array(
				'products' => $products,
				'total'    => $results->found_posts,
				'max_num_pages' => $results->max_num_pages,
			);
		}

		return $products;
	}

	/**
	 * Map WC_Product_Query arguments into WP_Query arguments.
	 *
	 * @since 2.0.0
	 *
	 * @param array $query_vars Query vars from WC_Product_Query.
	 * @return array
	 */
	protected function get_wp_query_args( $query_vars ) {
		$wp_query_args = parent::get_wp_query_args( $query_vars );

		// Map custom table columns to a separate query array.
		// Note: 'type' is intentionally excluded — it is already handled by the
		// post_type mapping at line 1473 and via product_type taxonomy.  Including
		// it here would add an INNER-JOIN-like filter on wpt_products that silently
		// drops variations (or other types) whose rows are absent from the custom table.
		$custom_table_columns = array(
			'sku', 'price', 'regular_price', 'sale_price', 'stock_quantity',
			'stock_status', 'average_rating', 'total_sales', 'virtual', 'downloadable',
			'tax_class', 'tax_status', 'manage_stock', 'backorders',
			'sold_individually', 'date_on_sale_from', 'date_on_sale_to',
			'height', 'length', 'width', 'weight', 'image_id',
			'low_stock_amount', 'rating_count',
		);

		$wpt_query = array();

		foreach ( $custom_table_columns as $column ) {
			if ( isset( $query_vars[ $column ] ) && '' !== $query_vars[ $column ] ) {
				$wpt_query[ $column ] = $query_vars[ $column ];
				unset( $wp_query_args['meta_query'][ $column ] );
			}
		}

		// Handle taxonomy queries.
		if ( ! empty( $query_vars['category'] ) ) {
			$wp_query_args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $query_vars['category'],
			);
		}

		if ( ! empty( $query_vars['tag'] ) ) {
			$wp_query_args['tax_query'][] = array(
				'taxonomy' => 'product_tag',
				'field'    => 'slug',
				'terms'    => $query_vars['tag'],
			);
		}

		// Handle reviews_allowed.
		if ( isset( $query_vars['reviews_allowed'] ) && is_bool( $query_vars['reviews_allowed'] ) ) {
			$wp_query_args['comment_status'] = $query_vars['reviews_allowed'] ? 'open' : 'closed';
		}

		// Handle visibility.
		if ( ! empty( $query_vars['visibility'] ) ) {
			switch ( $query_vars['visibility'] ) {
				case 'search':
					$wp_query_args['tax_query'][] = array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => 'exclude-from-search',
						'operator' => 'NOT IN',
					);
					break;
				case 'catalog':
					$wp_query_args['tax_query'][] = array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => 'exclude-from-catalog',
						'operator' => 'NOT IN',
					);
					break;
				case 'visible':
					$wp_query_args['tax_query'][] = array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
						'operator' => 'NOT IN',
					);
					break;
				case 'hidden':
					$wp_query_args['tax_query'][] = array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
						'operator' => 'AND',
					);
					break;
			}
		}

		// Handle date queries for custom table columns.
		foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $date_column ) {
			if ( isset( $query_vars[ $date_column ] ) ) {
				$wpt_query[ $date_column ] = $this->parse_date_for_custom_table( $query_vars[ $date_column ], $date_column );
			}
		}

		// Pagination.
		if ( isset( $query_vars['paginate'] ) && $query_vars['paginate'] ) {
			$wp_query_args['no_found_rows'] = false;
		}

		if ( ! empty( $wpt_query ) ) {
			$wp_query_args['wpt_products_query'] = $wpt_query;
		}

		return $wp_query_args;
	}

	/**
	 * Parse a date value for use in custom table WHERE clauses.
	 *
	 * Supports: timestamp, Y-m-d, Y-m-d H:i:s, or array with 'after'/'before'.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed  $raw_value Date value.
	 * @param string $column    Column name.
	 * @return array Array of ['value' => ..., 'compare' => '='].
	 */
	protected function parse_date_for_custom_table( $raw_value, $column ) {
		if ( is_array( $raw_value ) ) {
			$parsed = array();

			if ( isset( $raw_value['after'] ) ) {
				$parsed[] = array(
					'column'  => $column,
					'value'   => $this->normalize_date_value( $raw_value['after'] ),
					'compare' => '>',
				);
			}

			if ( isset( $raw_value['before'] ) ) {
				$parsed[] = array(
					'column'  => $column,
					'value'   => $this->normalize_date_value( $raw_value['before'] ),
					'compare' => '<',
				);
			}

			return $parsed;
		}

		$operator = '=';
		$value    = $raw_value;

		if ( is_string( $raw_value ) && preg_match( '/^([><=!]+)(.+)$/', $raw_value, $matches ) ) {
			$operator = $matches[1];
			$value    = $matches[2];
		}

		return array(
			array(
				'column'  => $column,
				'value'   => $this->normalize_date_value( $value ),
				'compare' => $operator,
			),
		);
	}

	/**
	 * Normalize a date value to 'Y-m-d H:i:s' format.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value Date value (timestamp, string, or WC_DateTime).
	 * @return string Formatted datetime string.
	 */
	protected function normalize_date_value( $value ) {
		if ( $value instanceof \WC_DateTime ) {
			return $value->date( 'Y-m-d H:i:s' );
		}

		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}

		return (string) $value;
	}

	/**
	 * Add custom table JOIN when querying products.
	 *
	 * @since 2.0.0
	 *
	 * @param string    $join     SQL JOIN clause.
	 * @param \WP_Query $wp_query WP_Query instance.
	 * @return string
	 */
	public function products_join( $join, $wp_query ) {
		global $wpdb;

		if ( ! empty( $wp_query->query_vars['wpt_products_query'] ) ) {
			$join .= " LEFT JOIN {$wpdb->prefix}wpt_products AS wpt_p ON {$wpdb->posts}.ID = wpt_p.product_id";
		}

		return $join;
	}

	/**
	 * Add custom table WHERE conditions when querying products.
	 *
	 * @since 2.0.0
	 *
	 * @param string    $where    SQL WHERE clause.
	 * @param \WP_Query $wp_query WP_Query instance.
	 * @return string
	 */
	public function products_where( $where, $wp_query ) {
		global $wpdb;

		$custom_query = $wp_query->query_vars['wpt_products_query'] ?? array();

		if ( empty( $custom_query ) ) {
			return $where;
		}

		foreach ( $custom_query as $column => $raw ) {
			// Handle date columns with parsed array format.
			if ( is_array( $raw ) && isset( $raw[0]['column'] ) ) {
				foreach ( $raw as $part ) {
					$compare = in_array( $part['compare'], array( '=', '!=', '>', '>=', '<', '<=' ), true ) ? $part['compare'] : '=';
					$where  .= $wpdb->prepare( " AND wpt_p.{$part['column']} {$compare} %s", $part['value'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				continue;
			}

			// Handle 'compare' arrays.
			if ( is_array( $raw ) && isset( $raw['value'] ) ) {
				$compare = isset( $raw['compare'] ) ? strtoupper( $raw['compare'] ) : '=';
				$value   = $raw['value'];
			} else {
				$compare = '=';
				$value   = $raw;
			}

			$safe_column = sanitize_key( $column );

			switch ( $compare ) {
				case 'IN':
				case 'NOT IN':
					if ( is_array( $value ) ) {
						$placeholders = implode( ',', array_fill( 0, count( $value ), '%s' ) );
						$where       .= $wpdb->prepare( " AND wpt_p.{$safe_column} {$compare} ({$placeholders})", $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					}
					break;

				case 'IS NULL':
				case 'IS NOT NULL':
					$where .= " AND wpt_p.{$safe_column} {$compare}";
					break;

				case 'LIKE':
				case 'NOT LIKE':
					$where .= $wpdb->prepare( " AND wpt_p.{$safe_column} {$compare} %s", '%' . $wpdb->esc_like( $value ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					break;

				case '!=':
				case '>':
				case '>=':
				case '<':
				case '<=':
				case '=':
				default:
					$where .= $wpdb->prepare( " AND wpt_p.{$safe_column} {$compare} %s", $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					break;
			}
		}

		return $where;
	}

	/**
	 * Run the product query and return results.
	 *
	 * @since 2.0.0
	 *
	 * @param array $query_vars Query vars.
	 * @return array
	 */
	public function query( $query_vars ) {
		return $this->get_products( $query_vars );
	}

	/*
	|--------------------------------------------------------------------------
	| Stock & Sales
	|--------------------------------------------------------------------------
	*/

	/**
	 * Update a product's stock amount.
	 *
	 * @since 2.0.0
	 *
	 * @param int      $product_id_with_stock Product ID.
	 * @param int|null $stock_quantity        New stock quantity.
	 * @param string   $operation             Operation ('set', 'increase', 'decrease').
	 * @return int|float New stock quantity.
	 */
	public function update_product_stock( $product_id_with_stock, $stock_quantity = null, $operation = 'set' ) {
		global $wpdb;

		switch ( $operation ) {
			case 'increase':
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}wpt_products SET stock_quantity = stock_quantity + %f WHERE product_id = %d",
						$stock_quantity,
						$product_id_with_stock
					)
				);
				break;
			case 'decrease':
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}wpt_products SET stock_quantity = stock_quantity - %f WHERE product_id = %d",
						$stock_quantity,
						$product_id_with_stock
					)
				);
				break;
			default:
				$wpdb->update(
					"{$wpdb->prefix}wpt_products",
					array( 'stock_quantity' => $stock_quantity ),
					array( 'product_id' => $product_id_with_stock )
				);
				break;
		}

		// Also update meta lookup for compatibility.
		$this->update_lookup_table( $product_id_with_stock, 'wc_product_meta_lookup' );

		wp_cache_delete( 'woocommerce_product_' . $product_id_with_stock, 'product' );

		$new_stock = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT stock_quantity FROM {$wpdb->prefix}wpt_products WHERE product_id = %d",
				$product_id_with_stock
			)
		);

		// Sync stock to postmeta for dual-write compatibility.
		update_post_meta( $product_id_with_stock, '_stock', wc_stock_amount( $new_stock ) );

		return wc_stock_amount( $new_stock );
	}

	/**
	 * Read current stock quantity from the custom table.
	 *
	 * Called by WC core immediately after update_product_stock() to
	 * refresh the product object's stock_quantity property.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product   Product object.
	 * @param int|float   $new_stock Optional new stock value (already set in DB).
	 * @return int|float
	 */
	public function read_stock_quantity( &$product, $new_stock = null ) {
		if ( ! is_null( $new_stock ) ) {
			$product->set_stock_quantity( wc_stock_amount( $new_stock ) );
			return wc_stock_amount( $new_stock );
		}

		global $wpdb;

		$stock = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT stock_quantity FROM {$wpdb->prefix}wpt_products WHERE product_id = %d",
				$product->get_id()
			)
		);

		$stock = wc_stock_amount( $stock );
		$product->set_stock_quantity( $stock );

		return $stock;
	}

	/**
	 * Update a product's total sales count.
	 *
	 * @since 2.0.0
	 *
	 * @param int      $product_id Product ID.
	 * @param int|null $quantity   Quantity to adjust. Default null (1).
	 * @param string   $operation  Operation: 'set', 'increase', or 'decrease'. Default 'set'.
	 */
	public function update_product_sales( $product_id, $quantity = null, $operation = 'set' ) {
		global $wpdb;

		$quantity = is_null( $quantity ) ? 1 : absint( $quantity );

		switch ( $operation ) {
			case 'increase':
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}wpt_products SET total_sales = total_sales + %d WHERE product_id = %d",
						$quantity,
						$product_id
					)
				);
				break;
			case 'decrease':
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}wpt_products SET total_sales = GREATEST(0, total_sales - %d) WHERE product_id = %d",
						$quantity,
						$product_id
					)
				);
				break;
			default: // 'set'
				$wpdb->update(
					"{$wpdb->prefix}wpt_products",
					array( 'total_sales' => $quantity ),
					array( 'product_id' => $product_id )
				);
				break;
		}

		// Sync to postmeta.
		update_post_meta( $product_id, 'total_sales', $wpdb->get_var(
			$wpdb->prepare( "SELECT total_sales FROM {$wpdb->prefix}wpt_products WHERE product_id = %d", $product_id )
		) );

		wp_cache_delete( 'woocommerce_product_' . $product_id, 'product' );
	}

	/**
	 * Get on-sale product IDs.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_on_sale_products() {
		global $wpdb;

		$now = current_time( 'mysql', true );

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}wpt_products
				 WHERE sale_price IS NOT NULL
				 AND sale_price > 0
				 AND sale_price < regular_price
				 AND (date_on_sale_from IS NULL OR date_on_sale_from <= %s)
				 AND (date_on_sale_to IS NULL OR date_on_sale_to >= %s)",
				$now,
				$now
			)
		);
	}

	/**
	 * Get featured product IDs.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_featured_product_ids() {
		return get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'tax_query'      => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => 'featured',
					),
				),
				'fields'         => 'ids',
			)
		);
	}

	/**
	 * Get products that have sales starting (scheduled sale starts now).
	 *
	 * @since 2.0.0
	 *
	 * @return array Product IDs.
	 */
	public function get_starting_sales() {
		global $wpdb;

		$now = current_time( 'mysql', true );

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}wpt_products
				 WHERE date_on_sale_from IS NOT NULL
				 AND date_on_sale_from <= %s
				 AND sale_price IS NOT NULL
				 AND sale_price > 0",
				$now
			)
		);
	}

	/**
	 * Get products that have sales ending (scheduled sale ended).
	 *
	 * @since 2.0.0
	 *
	 * @return array Product IDs.
	 */
	public function get_ending_sales() {
		global $wpdb;

		$now = current_time( 'mysql', true );

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}wpt_products
				 WHERE date_on_sale_to IS NOT NULL
				 AND date_on_sale_to < %s
				 AND sale_price IS NOT NULL
				 AND sale_price > 0",
				$now
			)
		);
	}

	/**
	 * Return a query string for checking product stock.
	 *
	 * Used by WC's ReserveStock class during checkout.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 * @return string SQL query returning stock_quantity for the product.
	 */
	public function get_query_for_stock( $product_id ) {
		global $wpdb;

		return $wpdb->prepare(
			"SELECT stock_quantity FROM {$wpdb->prefix}wpt_products WHERE product_id = %d AND manage_stock = 1",
			$product_id
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Ratings & Reviews
	|--------------------------------------------------------------------------
	*/

	/**
	 * Update average rating in the custom table.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function update_average_rating( $product ) {
		global $wpdb;

		$average = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(meta_value) FROM {$wpdb->commentmeta} AS cm
				 LEFT JOIN {$wpdb->comments} AS c ON cm.comment_id = c.comment_ID
				 WHERE c.comment_post_ID = %d
				 AND c.comment_approved = '1'
				 AND c.comment_type IN ('review', '')
				 AND cm.meta_key = 'rating'
				 AND cm.meta_value > 0",
				$product->get_id()
			)
		);

		$average = wc_format_decimal( $average, 2 );

		$wpdb->update(
			"{$wpdb->prefix}wpt_products",
			array( 'average_rating' => $average ),
			array( 'product_id' => $product->get_id() )
		);

		// Also keep in postmeta for compat.
		update_post_meta( $product->get_id(), '_wc_average_rating', $average );

		wp_cache_delete( 'woocommerce_product_' . $product->get_id(), 'product' );
	}

	/**
	 * Update review count.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function update_review_count( $product ) {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments}
				 WHERE comment_post_ID = %d
				 AND comment_approved = '1'
				 AND comment_type IN ('review', '')",
				$product->get_id()
			)
		);

		update_post_meta( $product->get_id(), '_wc_review_count', absint( $count ) );
	}

	/**
	 * Update rating counts (per-star breakdown).
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function update_rating_counts( $product ) {
		global $wpdb;

		$counts     = array();
		$raw_counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cm.meta_value AS rating, COUNT(*) AS count
				 FROM {$wpdb->commentmeta} AS cm
				 LEFT JOIN {$wpdb->comments} AS c ON cm.comment_id = c.comment_ID
				 WHERE c.comment_post_ID = %d
				 AND c.comment_approved = '1'
				 AND c.comment_type IN ('review', '')
				 AND cm.meta_key = 'rating'
				 AND cm.meta_value > 0
				 GROUP BY cm.meta_value",
				$product->get_id()
			)
		);

		foreach ( $raw_counts as $row ) {
			$counts[ $row->rating ] = absint( $row->count );
		}

		// Store per-star breakdown in postmeta.
		update_post_meta( $product->get_id(), '_wc_rating_count', $counts );

		// Store total count in custom table for SQL sorting/filtering.
		$total = array_sum( $counts );

		$wpdb->update(
			"{$wpdb->prefix}wpt_products",
			array( 'rating_count' => $total ),
			array( 'product_id' => $product->get_id() )
		);
	}

	/*
	|--------------------------------------------------------------------------
	| SKU
	|--------------------------------------------------------------------------
	*/

	/**
	 * Check if a SKU exists for any product (optionally excluding a product ID).
	 *
	 * @since 2.0.0
	 *
	 * @param int    $product_id Product ID to exclude.
	 * @param string $sku        SKU to check.
	 * @return bool
	 */
	public function is_existing_sku( $product_id, $sku ) {
		global $wpdb;

		// Check custom table.
		$found = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}wpt_products
				 WHERE sku = %s AND product_id != %d
				 LIMIT 1",
				$sku,
				$product_id
			)
		);

		if ( $found ) {
			return true;
		}

		// Fallback: check postmeta for unmigrated products.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} AS pm
				 LEFT JOIN {$wpdb->prefix}wpt_products AS wpt ON pm.post_id = wpt.product_id
				 WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND pm.post_id != %d
				 AND wpt.product_id IS NULL
				 LIMIT 1",
				$sku,
				$product_id
			)
		);
	}

	/**
	 * Get a product ID by its SKU.
	 *
	 * @since 2.0.0
	 *
	 * @param string $sku SKU string.
	 * @return int Product ID or 0.
	 */
	public function get_product_id_by_sku( $sku ) {
		global $wpdb;

		// Check custom table first.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.product_id FROM {$wpdb->prefix}wpt_products AS p
				 LEFT JOIN {$wpdb->posts} AS posts ON p.product_id = posts.ID
				 WHERE p.sku = %s
				 AND posts.post_status != 'trash'
				 LIMIT 1",
				$sku
			)
		);

		if ( $id ) {
			return absint( $id );
		}

		// Fallback: check postmeta for unmigrated products.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} AS pm
				 LEFT JOIN {$wpdb->posts} AS posts ON pm.post_id = posts.ID
				 WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
				 AND posts.post_status != 'trash'
				 AND posts.post_type IN ('product', 'product_variation')
				 LIMIT 1",
				$sku
			)
		);

		return absint( $id );
	}

	/*
	|--------------------------------------------------------------------------
	| Variations
	|--------------------------------------------------------------------------
	*/

	/**
	 * Find a matching product variation based on attribute values.
	 *
	 * Uses the custom variation attribute values table for efficient lookup.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product    Variable product.
	 * @param array       $match_attributes Attribute values to match.
	 * @return int Matching variation ID, or 0.
	 */
	public function find_matching_product_variation( $product, $match_attributes = array() ) {
		global $wpdb;

		$parent_id     = $product->get_id();
		$variation_ids = $product->get_children();

		if ( empty( $variation_ids ) || empty( $match_attributes ) ) {
			return 0;
		}

		// Check if the parent is migrated.
		$is_migrated = (bool) $this->get_product_row_from_db( $parent_id );

		// Group attributes by variation.
		$variation_attrs = array();

		if ( $is_migrated ) {
			// Read from custom table.
			$placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$all_attrs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT variation_id, attribute_name, attribute_value
					 FROM {$wpdb->prefix}wpt_product_variation_attribute_values
					 WHERE product_id = %d AND variation_id IN ({$placeholders})",
					array_merge( array( $parent_id ), $variation_ids )
				)
			);

			foreach ( $all_attrs as $row ) {
				$variation_attrs[ $row->variation_id ][ strtolower( $row->attribute_name ) ] = $row->attribute_value;
			}
		} else {
			// Fallback: read from variation postmeta.
			$parent_attributes = get_post_meta( $parent_id, '_product_attributes', true );
			$attr_names        = array();

			if ( is_array( $parent_attributes ) ) {
				foreach ( $parent_attributes as $attr_data ) {
					if ( ! empty( $attr_data['is_variation'] ) ) {
						$attr_names[] = $attr_data['name'];
					}
				}
			}

			foreach ( $variation_ids as $variation_id ) {
				foreach ( $attr_names as $attr_name ) {
					$meta_key = 'attribute_' . sanitize_title( $attr_name );
					$meta_val = get_post_meta( $variation_id, $meta_key, true );
					$variation_attrs[ $variation_id ][ strtolower( sanitize_title( $attr_name ) ) ] = $meta_val !== false ? $meta_val : '';
				}
			}
		}

		// Try to find exact match.
		foreach ( $variation_ids as $variation_id ) {
			$attrs = $variation_attrs[ $variation_id ] ?? array();
			$match = true;

			foreach ( $match_attributes as $name => $value ) {
				$name = strtolower( sanitize_title( $name ) );

				if ( ! isset( $attrs[ $name ] ) ) {
					$match = false;
					break;
				}

				// Empty stored value means "any" — always matches.
				if ( '' === $attrs[ $name ] ) {
					continue;
				}

				if ( strtolower( $attrs[ $name ] ) !== strtolower( $value ) ) {
					$match = false;
					break;
				}
			}

			if ( $match ) {
				return $variation_id;
			}
		}

		return 0;
	}

	/**
	 * Sort all product variations by menu_order.
	 *
	 * @since 2.0.0
	 *
	 * @param int $parent_id Parent product ID.
	 * @return array Sorted variation IDs.
	 */
	public function sort_all_product_variations( $parent_id ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_parent = %d
				 AND post_type = 'product_variation'
				 AND post_status IN ('publish', 'private')
				 ORDER BY menu_order ASC, ID ASC",
				absint( $parent_id )
			)
		);

		$menu_order = 0;
		foreach ( $ids as $id ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $menu_order++ ),
				array( 'ID' => absint( $id ) )
			);
		}

		return $ids;
	}

	/*
	|--------------------------------------------------------------------------
	| Related Products
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get related products based on category and tag.
	 *
	 * @since 2.0.0
	 *
	 * @param array $cats_array  Category IDs.
	 * @param array $tags_array  Tag IDs.
	 * @param array $exclude_ids Product IDs to exclude.
	 * @param int   $limit       Limit.
	 * @param int   $product_id  Product ID.
	 * @return array
	 */
	public function get_related_products( $cats_array, $tags_array, $exclude_ids, $limit, $product_id ) {
		global $wpdb;

		$replacements = array( $product_id );

		$include_term_ids = array_merge( $cats_array, $tags_array );

		if ( empty( $include_term_ids ) ) {
			return array();
		}

		$term_placeholders = implode( ',', array_fill( 0, count( $include_term_ids ), '%d' ) );
		$replacements      = array_merge( $replacements, $include_term_ids );

		$exclude_sql = '';
		if ( ! empty( $exclude_ids ) ) {
			$exclude_placeholders = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$exclude_sql          = " AND p.ID NOT IN ({$exclude_placeholders})";
			$replacements         = array_merge( $replacements, array_map( 'absint', $exclude_ids ) );
		}

		$replacements[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} AS p
				 INNER JOIN {$wpdb->term_relationships} AS tr ON p.ID = tr.object_id
				 INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				 INNER JOIN {$wpdb->prefix}wpt_products AS wpt ON p.ID = wpt.product_id
				 WHERE p.post_status = 'publish'
				 AND p.post_type = 'product'
				 AND p.ID != %d
				 AND tt.term_id IN ({$term_placeholders})
				 {$exclude_sql}
				 AND wpt.stock_status = 'instock'
				 ORDER BY RAND()
				 LIMIT %d",
				...$replacements
			)
		);
	}

	/**
	 * Get related products query (deprecated — use get_related_products).
	 *
	 * @since 2.0.0
	 *
	 * @param array $cats_array  Category IDs.
	 * @param array $tags_array  Tag IDs.
	 * @param array $exclude_ids Exclude IDs.
	 * @param int   $limit       Limit.
	 * @return array
	 */
	public function get_related_products_query( $cats_array, $tags_array, $exclude_ids, $limit ) {
		// Deprecated method signature — redirect.
		return $this->get_related_products( $cats_array, $tags_array, $exclude_ids, $limit, 0 );
	}

	/*
	|--------------------------------------------------------------------------
	| Shipping
	|--------------------------------------------------------------------------
	*/

	/**
	 * Get shipping class ID by slug.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug Shipping class slug.
	 * @return int|false
	 */
	public function get_shipping_class_id_by_slug( $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );
		return $term ? $term->term_id : false;
	}

	/*
	|--------------------------------------------------------------------------
	| Lookup Table Integration
	|--------------------------------------------------------------------------
	*/

	/**
	 * Update the wc_product_meta_lookup table used by WC core for queries.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $product_id Product ID.
	 * @param string $table      Table name.
	 */
	public function update_lookup_table( $product_id, $table ) {
		global $wpdb;

		if ( 'wc_product_meta_lookup' !== $table ) {
			parent::update_lookup_table( $product_id, $table );
			return;
		}

		$row = $this->get_product_row_from_db( $product_id );

		if ( ! $row ) {
			return;
		}

		$lookup_data = array(
			'product_id'     => $product_id,
			'sku'            => $row['sku'] ?? '',
			'virtual'        => $row['virtual'] ?? 0,
			'downloadable'   => $row['downloadable'] ?? 0,
			'min_price'      => $row['price'] ?? null,
			'max_price'      => $row['price'] ?? null,
			'onsale'         => ( isset( $row['sale_price'] ) && '' !== $row['sale_price'] && $row['sale_price'] < $row['regular_price'] ) ? 1 : 0,
			'stock_quantity' => $row['stock_quantity'] ?? null,
			'stock_status'   => $row['stock_status'] ?? 'instock',
			'rating_count'   => $row['rating_count'] ?? 0,
			'average_rating' => $row['average_rating'] ?? 0,
			'total_sales'    => $row['total_sales'] ?? 0,
			'tax_status'     => $row['tax_status'] ?? 'taxable',
			'tax_class'      => $row['tax_class'] ?? '',
		);

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d",
				$product_id
			)
		);

		if ( $existing ) {
			$wpdb->update(
				"{$wpdb->prefix}wc_product_meta_lookup",
				$lookup_data,
				array( 'product_id' => $product_id )
			);
		} else {
			$wpdb->insert( "{$wpdb->prefix}wc_product_meta_lookup", $lookup_data );
		}
	}
}
