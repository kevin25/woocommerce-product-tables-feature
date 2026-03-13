<?php
/**
 * WC Variation Product Data Store: Stored in Custom Tables.
 *
 * Extends ProductDataStore with variation-specific CRUD,
 * reduced column set, attribute_name-based variation attributes,
 * and parent data inheritance.
 *
 * @package WPT\DataStores
 */

namespace WPT\DataStores;

defined( 'ABSPATH' ) || exit;

/**
 * Variation Product Data Store class.
 */
class ProductVariationDataStore extends ProductDataStore {

	/**
	 * Variations have no relationships (no gallery, upsells, etc.).
	 *
	 * @var array
	 */
	protected $relationships = array();

	/*
	|--------------------------------------------------------------------------
	| CRUD Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read a variation from the database.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function read( &$product ) {
		$product->set_defaults();

		$post_object = $product->get_id() ? get_post( $product->get_id() ) : null;

		if ( ! $product->get_id() || ! $post_object || ! in_array( $post_object->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$product->set_props(
			array(
				'name'            => $post_object->post_title,
				'slug'            => $post_object->post_name,
				'date_created'    => 0 < $post_object->post_date_gmt ? wc_string_to_timestamp( $post_object->post_date_gmt ) : null,
				'date_modified'   => 0 < $post_object->post_modified_gmt ? wc_string_to_timestamp( $post_object->post_modified_gmt ) : null,
				'status'          => $post_object->post_status,
				'description'     => $post_object->post_content,
				'parent_id'       => $post_object->post_parent,
				'menu_order'      => $post_object->menu_order,
				'reviews_allowed' => 'open' === $post_object->comment_status,
			)
		);

		// Ensure the post parent is a valid variable product.
		if ( $product->get_parent_id( 'edit' ) && 'product' !== get_post_type( $product->get_parent_id( 'edit' ) ) ) {
			$product->set_parent_id( 0 );
		}

		// Check if the variation has been migrated to custom tables.
		if ( $this->get_product_row_from_db( $product->get_id() ) ) {
			$this->read_attributes( $product );
			$this->read_downloads( $product );
			$this->read_product_data( $product );
		} else {
			// Variation not yet migrated — read from postmeta.
			$this->read_product_data_from_meta( $product );
			$this->read_attributes_from_meta( $product );
			$this->read_downloads_from_meta( $product );
		}

		$this->read_extra_data( $product );

		// Sync variation title with parent if needed.
		$new_title = $this->generate_product_title( $product );

		if ( $post_object->post_title !== $new_title ) {
			$product->set_name( $new_title );
			$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_title' => $new_title ), array( 'ID' => $product->get_id() ) );
			clean_post_cache( $product->get_id() );
		}

		$product->set_object_read( true );
	}

	/**
	 * Read product data for a variation.
	 *
	 * Reads from the custom table with a reduced column set,
	 * then loads parent data for inherited props.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_product_data( &$product ) {
		$id    = $product->get_id();
		$props = $this->get_product_row_from_db( $id );

		if ( ! $props ) {
			$props = array();
		}

		// Explicit manage_stock column (boolean).
		if ( isset( $props['manage_stock'] ) ) {
			$props['manage_stock'] = (bool) $props['manage_stock'];
		}

		// Props stored in postmeta (variation-specific).
		$meta_to_props = array(
			'_backorders'        => 'backorders',
			'_sold_individually' => 'sold_individually',
			'_purchase_note'     => 'purchase_note',
			'_download_limit'    => 'download_limit',
			'_download_expiry'   => 'download_expiry',
		);

		foreach ( $meta_to_props as $meta_key => $prop ) {
			$props[ $prop ] = get_post_meta( $id, $meta_key, true );
		}

		// Taxonomy props.
		$props['shipping_class_id'] = current( $this->get_term_ids( $product, 'product_shipping_class' ) );

		// Relationships (empty for variations, but keep consistent).
		$relationship_rows_from_db = $this->get_product_relationship_rows_from_db( $product->get_id() );

		foreach ( $this->relationships as $type => $prop ) {
			$relationships  = array_filter(
				$relationship_rows_from_db,
				function ( $relationship ) use ( $type ) {
					return ! empty( $relationship->type ) && $relationship->type === $type;
				}
			);
			$values         = array_values( wp_list_pluck( $relationships, 'child_id' ) );
			$props[ $prop ] = $values;
		}

		$product->set_props( $props );

		// Set price based on sale status.
		if ( $product->is_on_sale( 'edit' ) ) {
			$product->set_price( $product->get_sale_price( 'edit' ) );
		} else {
			$product->set_price( $product->get_regular_price( 'edit' ) );
		}

		// Inherit parent data.
		$parent = wc_get_product( $product->get_parent_id() );

		if ( $parent ) {
			$product->set_parent_data(
				array(
					'title'              => $parent->get_title(),
					'status'             => $parent->get_status(),
					'sku'                => $parent->get_sku(),
					'manage_stock'       => $parent->get_manage_stock(),
					'backorders'         => $parent->get_backorders(),
					'low_stock_amount'   => $parent->get_low_stock_amount(),
					'stock_quantity'     => $parent->get_stock_quantity(),
					'weight'             => $parent->get_weight(),
					'length'             => $parent->get_length(),
					'width'              => $parent->get_width(),
					'height'             => $parent->get_height(),
					'tax_class'          => $parent->get_tax_class(),
					'shipping_class_id'  => $parent->get_shipping_class_id(),
					'image_id'           => $parent->get_image_id(),
					'purchase_note'      => $parent->get_purchase_note(),
					'catalog_visibility' => $parent->get_catalog_visibility(),
				)
			);

			// Inherit props with no variation-specific UI.
			$product->set_sold_individually( $parent->get_sold_individually() );
			$product->set_tax_status( $parent->get_tax_status() );
			$product->set_cross_sell_ids( $parent->get_cross_sell_ids() );
		}
	}

	/**
	 * Read product data from postmeta (fallback for unmigrated variations).
	 *
	 * Extends the parent method with parent product data inheritance,
	 * matching what the custom-table read_product_data() does.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_product_data_from_meta( &$product ) {
		parent::read_product_data_from_meta( $product );

		// Inherit parent data (same as custom-table read path).
		$parent = wc_get_product( $product->get_parent_id() );

		if ( $parent ) {
			$product->set_parent_data(
				array(
					'title'              => $parent->get_title(),
					'status'             => $parent->get_status(),
					'sku'                => $parent->get_sku(),
					'manage_stock'       => $parent->get_manage_stock(),
					'backorders'         => $parent->get_backorders(),
					'low_stock_amount'   => $parent->get_low_stock_amount(),
					'stock_quantity'     => $parent->get_stock_quantity(),
					'weight'             => $parent->get_weight(),
					'length'             => $parent->get_length(),
					'width'              => $parent->get_width(),
					'height'             => $parent->get_height(),
					'tax_class'          => $parent->get_tax_class(),
					'shipping_class_id'  => $parent->get_shipping_class_id(),
					'image_id'           => $parent->get_image_id(),
					'purchase_note'      => $parent->get_purchase_note(),
					'catalog_visibility' => $parent->get_catalog_visibility(),
				)
			);

			$product->set_sold_individually( $parent->get_sold_individually() );
			$product->set_tax_status( $parent->get_tax_status() );
			$product->set_cross_sell_ids( $parent->get_cross_sell_ids() );
		}
	}

	/**
	 * Create a new product variation.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @throws \Exception If unable to create post.
	 */
	public function create( &$product ) {
		try {
			wc_transaction_query( 'start' );

			if ( ! $product->get_date_created() ) {
				$product->set_date_created( time() );
			}

			$new_title = $this->generate_product_title( $product );

			if ( $product->get_name( 'edit' ) !== $new_title ) {
				$product->set_name( $new_title );
			}

			// Ensure valid parent.
			if ( $product->get_parent_id( 'edit' ) && 'product' !== get_post_type( $product->get_parent_id( 'edit' ) ) ) {
				$product->set_parent_id( 0 );
			}

			$id = wp_insert_post(
				apply_filters(
					'woocommerce_new_product_variation_data',
					array(
						'post_type'      => 'product_variation',
						'post_status'    => $product->get_status() ? $product->get_status() : 'publish',
						'post_author'    => get_current_user_id(),
						'post_title'     => $product->get_name( 'edit' ),
						'post_content'   => $product->get_description( 'edit' ),
						'post_parent'    => $product->get_parent_id(),
						'comment_status' => 'closed',
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

			do_action( 'woocommerce_new_product_variation', $id );
		} catch ( \Exception $e ) {
			wc_transaction_query( 'rollback' );
		}
	}

	/**
	 * Update an existing product variation.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function update( &$product ) {
		$product->save_meta_data();

		if ( ! $product->get_date_created() ) {
			$product->set_date_created( time() );
		}

		$new_title = $this->generate_product_title( $product );

		if ( $product->get_name( 'edit' ) !== $new_title ) {
			$product->set_name( $new_title );
		}

		// Ensure valid parent.
		if ( $product->get_parent_id( 'edit' ) && 'product' !== get_post_type( $product->get_parent_id( 'edit' ) ) ) {
			$product->set_parent_id( 0 );
		}

		$changes = $product->get_changes();

		if ( array_intersect( array( 'name', 'parent_id', 'status', 'menu_order', 'date_created', 'date_modified', 'description' ), array_keys( $changes ) ) ) {
			$post_data = array(
				'post_title'        => $product->get_name( 'edit' ),
				'post_parent'       => $product->get_parent_id( 'edit' ),
				'comment_status'    => 'closed',
				'post_content'      => $product->get_description( 'edit' ),
				'post_status'       => $product->get_status( 'edit' ) ? $product->get_status( 'edit' ) : 'publish',
				'menu_order'        => $product->get_menu_order( 'edit' ),
				'post_date'         => gmdate( 'Y-m-d H:i:s', $product->get_date_created( 'edit' )->getOffsetTimestamp() ),
				'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $product->get_date_created( 'edit' )->getTimestamp() ),
				'post_modified'     => isset( $changes['date_modified'] ) ? gmdate( 'Y-m-d H:i:s', $product->get_date_modified( 'edit' )->getOffsetTimestamp() ) : current_time( 'mysql' ),
				'post_modified_gmt' => isset( $changes['date_modified'] ) ? gmdate( 'Y-m-d H:i:s', $product->get_date_modified( 'edit' )->getTimestamp() ) : current_time( 'mysql', 1 ),
				'post_type'         => 'product_variation',
				'post_name'         => $product->get_slug( 'edit' ),
			);

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
		$this->update_visibility( $product, true );
		$this->update_attributes( $product );
		$this->handle_updated_props( $product );

		$product->apply_changes();

		update_post_meta( $product->get_id(), '_product_version', \WC_VERSION );

		$this->clear_caches( $product );

		do_action( 'woocommerce_update_product_variation', $product->get_id() );
	}

	/*
	|--------------------------------------------------------------------------
	| Product Data — Reduced Column Set for Variations
	|--------------------------------------------------------------------------
	*/

	/**
	 * Store data into the custom product table with variation-specific columns only.
	 *
	 * Excludes columns that don't apply to variations (e.g., rating_count,
	 * sold_individually in the table — those are inherited from parent).
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function update_product_data( &$product ) {
		global $wpdb;

		$data    = array( 'type' => $product->get_type() );
		$changes = $product->get_changes();
		$row     = $this->get_product_row_from_db( $product->get_id() );
		$insert  = ! $row;

		// Variation-specific columns (reduced set).
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
		);

		$date_columns = array( 'date_on_sale_from', 'date_on_sale_to' );

		$allow_null = array(
			'height', 'length', 'width', 'weight', 'stock_quantity',
			'price', 'regular_price', 'sale_price',
			'date_on_sale_from', 'date_on_sale_to', 'average_rating',
		);

		if ( array_key_exists( 'manage_stock', $changes ) && ! $product->get_stock_quantity( 'edit' ) ) {
			$data['stock_quantity'] = 0;
			$this->updated_props[] = 'stock_quantity';
		}

		foreach ( $columns as $column ) {
			if ( $insert || array_key_exists( $column, $changes ) ) {
				$value = $product->{"get_$column"}( 'edit' );

				if ( in_array( $column, $date_columns, true ) ) {
					$data[ $column ] = empty( $value ) ? null : gmdate( 'Y-m-d H:i:s', $value->getOffsetTimestamp() );
				} elseif ( 'manage_stock' === $column ) {
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
		} elseif ( count( $data ) > 1 ) {
			$wpdb->update(
				"{$wpdb->prefix}wpt_products",
				$data,
				array( 'product_id' => $product->get_id() )
			);
		}

		// Variations have no relationships, but keep parent pattern.
		foreach ( $this->relationships as $type => $prop ) {
			if ( array_key_exists( $prop, $changes ) || $insert ) {
				$this->update_relationship( $product, $type );
				$this->updated_props[] = $type;
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Attributes — Variation Key-Value Pairs (attribute_name based)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Read variation attributes (name-value pairs) from the custom table.
	 *
	 * Uses attribute_name directly instead of product_attribute_id FK lookup.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	public function read_attributes( &$product ) {
		global $wpdb;

		// Check if variation is in the custom table.
		if ( ! $this->get_product_row_from_db( $product->get_id() ) ) {
			// Fallback: read variation attributes from postmeta.
			$this->read_variation_attributes_from_meta( $product );
			return;
		}

		$product_attributes = wp_cache_get( 'woocommerce_product_variation_attribute_values_' . $product->get_id(), 'product' );

		if ( false === $product_attributes ) {
			$product_attributes = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT attribute_name, attribute_value FROM {$wpdb->prefix}wpt_product_variation_attribute_values WHERE variation_id = %d",
					$product->get_id()
				)
			);

			wp_cache_set( 'woocommerce_product_variation_attribute_values_' . $product->get_id(), $product_attributes, 'product' );
		}

		if ( ! empty( $product_attributes ) ) {
			$attributes = array();
			foreach ( $product_attributes as $attr ) {
				$attributes[ sanitize_title( $attr->attribute_name ) ] = $attr->attribute_value;
			}
			$product->set_attributes( $attributes );
		}
	}

	/**
	 * Read variation attributes from postmeta (fallback for unmigrated variations).
	 *
	 * WC stores variation attributes as individual meta entries: attribute_pa_color, etc.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function read_variation_attributes_from_meta( &$product ) {
		$parent_id = $product->get_parent_id();
		if ( ! $parent_id ) {
			return;
		}

		$parent_attributes = get_post_meta( $parent_id, '_product_attributes', true );
		if ( ! is_array( $parent_attributes ) ) {
			return;
		}

		$attributes = array();
		foreach ( $parent_attributes as $slug => $attr_data ) {
			if ( empty( $attr_data['is_variation'] ) ) {
				continue;
			}
			$meta_key  = 'attribute_' . sanitize_title( $attr_data['name'] );
			$meta_val  = get_post_meta( $product->get_id(), $meta_key, true );
			$attributes[ sanitize_title( $attr_data['name'] ) ] = $meta_val ? $meta_val : '';
		}

		if ( ! empty( $attributes ) ) {
			$product->set_attributes( $attributes );
		}
	}

	/**
	 * Update variation attribute values in the custom table.
	 *
	 * Stores attribute_name directly (no FK lookup needed).
	 * Uses prepared statements for all queries including deletions.
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

		$variation_id = $product->get_id();
		$parent_id    = $product->get_parent_id();
		$attributes   = $product->get_attributes();

		// Get existing attribute rows for this variation.
		$existing_attributes = wp_list_pluck(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT attribute_name, attribute_value FROM {$wpdb->prefix}wpt_product_variation_attribute_values WHERE variation_id = %d",
					$variation_id
				)
			),
			'attribute_value',
			'attribute_name'
		);

		if ( ! empty( $attributes ) ) {
			$updated_attribute_names = array();

			foreach ( $attributes as $attribute_key => $attribute_value ) {
				// Resolve the attribute_name from the slug.
				$attribute_name = $this->resolve_attribute_name( $product, $attribute_key );

				if ( ! $attribute_name ) {
					continue;
				}

				if ( isset( $existing_attributes[ $attribute_name ] ) ) {
					$wpdb->update(
						"{$wpdb->prefix}wpt_product_variation_attribute_values",
						array( 'attribute_value' => $attribute_value ),
						array(
							'attribute_name' => $attribute_name,
							'variation_id'   => $variation_id,
						)
					);
				} else {
					$wpdb->insert(
						"{$wpdb->prefix}wpt_product_variation_attribute_values",
						array(
							'product_id'      => $parent_id,
							'variation_id'    => $variation_id,
							'attribute_name'  => $attribute_name,
							'attribute_value' => $attribute_value,
						)
					);
				}
				$updated_attribute_names[] = $attribute_name;
			}

			// Remove attributes that are no longer assigned.
			$attributes_to_delete = array_diff( array_keys( $existing_attributes ), $updated_attribute_names );

			if ( ! empty( $attributes_to_delete ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $attributes_to_delete ), '%s' ) );
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"DELETE FROM {$wpdb->prefix}wpt_product_variation_attribute_values WHERE variation_id = %d AND attribute_name IN ({$placeholders})",
						array_merge( array( $variation_id ), array_values( $attributes_to_delete ) )
					)
				);
			}
		}
	}

	/**
	 * Resolve attribute_name from a slug key, using parent product's attributes.
	 *
	 * Variation objects store slug=>value pairs (e.g., 'pa_color' => 'red').
	 * We need the canonical attribute name for storage.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product       Product object (variation).
	 * @param string      $attribute_slug Attribute slug/key.
	 * @return string|false Attribute name or false if not found.
	 */
	protected function resolve_attribute_name( &$product, $attribute_slug ) {
		$parent = wc_get_product( $product->get_parent_id() );

		if ( ! $parent ) {
			return false;
		}

		$parent_attributes = $parent->get_attributes();

		foreach ( $parent_attributes as $attribute ) {
			if ( sanitize_title( $attribute->get_name() ) === $attribute_slug ) {
				return $attribute->get_name();
			}
		}

		return false;
	}

	/*
	|--------------------------------------------------------------------------
	| Terms & Visibility — Variation Specific Overrides
	|--------------------------------------------------------------------------
	*/

	/**
	 * Variations only have shipping_class taxonomy.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $force   Force update.
	 */
	protected function update_terms( &$product, $force = false ) {
		$changes = $product->get_changes();

		if ( $force || array_key_exists( 'shipping_class_id', $changes ) ) {
			wp_set_post_terms( $product->get_id(), array( $product->get_shipping_class_id( 'edit' ) ), 'product_shipping_class', false );
		}
	}

	/**
	 * Variation visibility is based on stock status only.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @param bool        $force   Force update.
	 */
	protected function update_visibility( &$product, $force = false ) {
		$changes = $product->get_changes();

		if ( $force || array_intersect( array( 'stock_status' ), array_keys( $changes ) ) ) {
			$terms = array();

			if ( 'outofstock' === $product->get_stock_status() ) {
				$terms[] = 'outofstock';
			}

			wp_set_post_terms( $product->get_id(), $terms, 'product_visibility', false );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Title Generation
	|--------------------------------------------------------------------------
	*/

	/**
	 * Generate a variation title: "Parent Name - Attr1, Attr2" or just "Parent Name".
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	protected function generate_product_title( $product ) {
		$attributes = (array) $product->get_attributes();

		$should_include_attributes = count( $attributes ) < 3;

		if ( $should_include_attributes && 1 < count( $attributes ) ) {
			foreach ( $attributes as $name => $value ) {
				if ( false !== strpos( $name, '-' ) ) {
					$should_include_attributes = false;
					break;
				}
			}
		}

		$should_include_attributes = apply_filters( 'woocommerce_product_variation_title_include_attributes', $should_include_attributes, $product );
		$separator                 = apply_filters( 'woocommerce_product_variation_title_attributes_separator', ' - ', $product );
		$title_base                = get_post_field( 'post_title', $product->get_parent_id() );
		$title_suffix              = $should_include_attributes ? wc_get_formatted_variation( $product, true, false ) : '';

		return apply_filters( 'woocommerce_product_variation_title', $title_suffix ? $title_base . $separator . $title_suffix : $title_base, $product, $title_base, $title_suffix );
	}

	/*
	|--------------------------------------------------------------------------
	| Cache
	|--------------------------------------------------------------------------
	*/

	/**
	 * Clear variation-specific caches plus parent caches.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product $product Product object.
	 */
	protected function clear_caches( &$product ) {
		wp_cache_delete( 'woocommerce_product_children_stock_status_' . $product->get_parent_id(), 'product' );
		wp_cache_delete( 'woocommerce_product_variation_attribute_values_' . $product->get_id(), 'product' );
		parent::clear_caches( $product );
	}
}
