<?php
/**
 * WP-CLI Commands for WPT.
 *
 * Provides:
 *   wp wpt migrate   — Batch-migrate products from postmeta to custom tables.
 *   wp wpt rollback  — Move data back to postmeta and drop custom tables.
 *   wp wpt status    — Show migration progress.
 *   wp wpt verify    — Check data integrity between custom tables and postmeta.
 *
 * @package WPT\CLI
 */

namespace WPT\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Commands class.
 */
class Commands {

	/**
	 * Register commands if WP-CLI is available.
	 *
	 * @since 2.0.0
	 * @internal
	 */
	public function init() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		// Ensure WPT_Install is available — it's not autoloaded (no namespace).
		require_once WPT_PLUGIN_DIR . 'includes/class-wpt-install.php';

		\WP_CLI::add_command( 'wpt migrate', array( $this, 'migrate' ) );
		\WP_CLI::add_command( 'wpt rollback', array( $this, 'rollback' ) );
		\WP_CLI::add_command( 'wpt status', array( $this, 'status' ) );
		\WP_CLI::add_command( 'wpt verify', array( $this, 'verify' ) );
	}

	/**
	 * Migrate products from postmeta to custom tables.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Products per batch. Default 50.
	 *
	 * [--dry-run]
	 * : Show what would be migrated without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpt migrate
	 *     wp wpt migrate --batch-size=100
	 *     wp wpt migrate --dry-run
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function migrate( $args, $assoc_args ) {
		global $wpdb;

		\WPT_Install::create_tables();

		$batch_size = (int) ( $assoc_args['batch-size'] ?? get_option( 'wpt_migration_batch_size', 50 ) );
		$dry_run    = isset( $assoc_args['dry-run'] );

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ('product', 'product_variation')
			 AND post_status != 'auto-draft'"
		);

		$already_migrated = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wpt_products"
		);

		$remaining = $total - $already_migrated;

		if ( $remaining <= 0 ) {
			\WP_CLI::success( "All {$total} products are already migrated." );
			return;
		}

		\WP_CLI::log( "Total products: {$total}, Already migrated: {$already_migrated}, Remaining: {$remaining}" );

		if ( $dry_run ) {
			\WP_CLI::success( "Dry run — {$remaining} products would be migrated in batches of {$batch_size}." );
			return;
		}

		$progress      = \WP_CLI\Utils\make_progress_bar( 'Migrating products', $remaining );
		$migrated      = 0;
		$failed        = 0;
		$processed_ids = array();

		while ( true ) {
			// Exclude ALL previously processed IDs to prevent infinite loops.
			$exclude_sql = '';
			if ( ! empty( $processed_ids ) ) {
				$id_list     = implode( ',', array_map( 'intval', $processed_ids ) );
				$exclude_sql = " AND p.ID NOT IN ({$id_list})";
			}

			$product_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->prefix}wpt_products wpt ON p.ID = wpt.product_id
					 WHERE p.post_type IN ('product', 'product_variation')
					 AND p.post_status != 'auto-draft'
					 AND wpt.product_id IS NULL
					 {$exclude_sql}
					 LIMIT %d",
					$batch_size
				)
			);

			if ( empty( $product_ids ) ) {
				break;
			}

			foreach ( $product_ids as $product_id ) {
				$pid    = (int) $product_id;
				$result = $this->migrate_single_product( $pid );

				$processed_ids[] = $pid;

				if ( false === $result ) {
					$failed++;
					\WP_CLI::warning( "Failed product #{$pid}: {$wpdb->last_error}" );
				} else {
					$migrated++;
				}

				$progress->tick();
			}

			// Free memory.
			$this->clear_batch_caches();
		}

		$progress->finish();

		// Sync stock status for variable products based on children.
		$this->sync_variable_stock_status();

		update_option( 'wpt_custom_product_tables_enabled', 'yes' );

		if ( $failed > 0 ) {
			\WP_CLI::warning( "{$failed} products failed to migrate. Re-run to retry or check the errors above." );
		}

		\WP_CLI::success( "Migrated {$migrated} products to custom tables." );
	}

	/**
	 * Rollback: sync custom tables back to postmeta and optionally drop tables.
	 *
	 * ## OPTIONS
	 *
	 * [--drop-tables]
	 * : Drop custom tables after rollback.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpt rollback
	 *     wp wpt rollback --drop-tables --yes
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function rollback( $args, $assoc_args ) {
		global $wpdb;

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'This will sync all custom table data back to postmeta. Continue?' );
		}

		if ( ! \WPT_Install::tables_exist() ) {
			\WP_CLI::error( 'Custom tables do not exist. Nothing to rollback.' );
		}

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wpt_products"
		);

		if ( 0 === $total ) {
			\WP_CLI::warning( 'No data in custom tables.' );
		} else {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Rolling back', $total );

			$synchronizer = new \WPT\Sync\ProductSynchronizer();

			$offset = 0;
			while ( true ) {
				$product_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT product_id FROM {$wpdb->prefix}wpt_products LIMIT %d OFFSET %d",
						100,
						$offset
					)
				);

				if ( empty( $product_ids ) ) {
					break;
				}

				foreach ( $product_ids as $product_id ) {
					$synchronizer->sync_product_to_postmeta( (int) $product_id );
					$synchronizer->sync_relationships_to_postmeta( (int) $product_id );
					$progress->tick();
				}

				$offset += 100;
			}

			$progress->finish();
		}

		update_option( 'wpt_custom_product_tables_enabled', 'no' );

		if ( isset( $assoc_args['drop-tables'] ) ) {
			\WPT_Install::drop_tables();
			\WP_CLI::success( 'Rollback complete. Custom tables dropped.' );
		} else {
			\WP_CLI::success( 'Rollback complete. Custom tables preserved (use --drop-tables to remove).' );
		}
	}

	/**
	 * Show migration status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpt status
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function status( $args, $assoc_args ) {
		global $wpdb;

		$enabled = get_option( 'wpt_custom_product_tables_enabled', 'no' );
		$tables  = \WPT_Install::tables_exist();

		\WP_CLI::log( 'Custom Tables Enabled: ' . ( 'yes' === $enabled ? 'Yes' : 'No' ) );
		\WP_CLI::log( 'Tables Exist: ' . ( $tables ? 'Yes' : 'No' ) );

		if ( ! $tables ) {
			\WP_CLI::log( 'Run `wp wpt migrate` to create tables and migrate data.' );
			return;
		}

		$total_posts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ('product', 'product_variation')
			 AND post_status != 'auto-draft'"
		);

		$total_custom = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wpt_products"
		);

		$percent = $total_posts > 0 ? round( ( $total_custom / $total_posts ) * 100 ) : 0;

		\WP_CLI::log( "Products in WP: {$total_posts}" );
		\WP_CLI::log( "Products in Custom Tables: {$total_custom}" );
		\WP_CLI::log( "Migration Progress: {$percent}%" );

		$dual_write = get_option( 'wpt_dual_write_enabled', 'yes' );
		$bw_compat  = get_option( 'wpt_backwards_compat_enabled', 'yes' );

		\WP_CLI::log( 'Dual-Write: ' . ( 'yes' === $dual_write ? 'Enabled' : 'Disabled' ) );
		\WP_CLI::log( 'Backwards Compat: ' . ( 'yes' === $bw_compat ? 'Enabled' : 'Disabled' ) );
	}

	/**
	 * Verify data integrity between custom tables and postmeta.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Max products to check. Default: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpt verify
	 *     wp wpt verify --limit=100
	 *
	 * @since 2.0.0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function verify( $args, $assoc_args ) {
		global $wpdb;

		if ( ! \WPT_Install::tables_exist() ) {
			\WP_CLI::error( 'Custom tables do not exist.' );
		}

		$limit_sql = '';
		if ( isset( $assoc_args['limit'] ) ) {
			$limit_sql = $wpdb->prepare( ' LIMIT %d', (int) $assoc_args['limit'] );
		}

		$product_ids = $wpdb->get_col(
			"SELECT product_id FROM {$wpdb->prefix}wpt_products{$limit_sql}"
		);

		if ( empty( $product_ids ) ) {
			\WP_CLI::warning( 'No products in custom tables to verify.' );
			return;
		}

		$checks = array(
			'_sku'           => 'sku',
			'_price'         => 'price',
			'_regular_price' => 'regular_price',
			'_stock'         => 'stock_quantity',
			'_stock_status'  => 'stock_status',
		);

		$mismatches = 0;
		$progress   = \WP_CLI\Utils\make_progress_bar( 'Verifying', count( $product_ids ) );

		foreach ( $product_ids as $product_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wpt_products WHERE product_id = %d",
					$product_id
				),
				ARRAY_A
			);

			foreach ( $checks as $meta_key => $column ) {
				$meta_value  = get_post_meta( $product_id, $meta_key, true );
				$table_value = $row[ $column ] ?? '';

				// Normalize for comparison.
				$meta_value  = (string) $meta_value;
				$table_value = (string) $table_value;

				// Normalize numeric values — DECIMAL(10,4) adds trailing zeros.
				if ( is_numeric( $meta_value ) && is_numeric( $table_value ) ) {
					$meta_value  = rtrim( rtrim( $meta_value, '0' ), '.' );
					$table_value = rtrim( rtrim( $table_value, '0' ), '.' );
				}

				if ( $meta_value !== $table_value ) {
					\WP_CLI::warning( "Mismatch: Product #{$product_id} — {$meta_key}: meta='{$meta_value}' vs table='{$table_value}'" );
					$mismatches++;
				}
			}

			$progress->tick();
		}

		$progress->finish();

		if ( 0 === $mismatches ) {
			\WP_CLI::success( 'All checked products are in sync.' );
		} else {
			\WP_CLI::warning( "{$mismatches} mismatches found. Run `wp wpt migrate` to re-sync." );
		}
	}

	/**
	 * Migrate a single product from postmeta to custom table.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 * @return bool True on success, false on failure.
	 */
	private function migrate_single_product( $product_id ) {
		global $wpdb;

		$meta_to_column = array(
			'_sku'                   => 'sku',
			'_thumbnail_id'          => 'image_id',
			'_virtual'               => 'virtual',
			'_downloadable'          => 'downloadable',
			'_price'                 => 'price',
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
		);

		$data = array( 'product_id' => $product_id );

		// Product type comes from taxonomy, not postmeta.
		$terms = get_the_terms( $product_id, 'product_type' );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$data['type'] = sanitize_title( current( $terms )->name );
		} else {
			// Variations don't have the product_type taxonomy.
			$post_type = get_post_type( $product_id );
			$data['type'] = ( 'product_variation' === $post_type ) ? 'variation' : 'simple';
		}

		foreach ( $meta_to_column as $meta_key => $column ) {
			$value = get_post_meta( $product_id, $meta_key, true );

			// get_post_meta returns false when the key doesn't exist — normalize to ''.
			if ( false === $value ) {
				$value = '';
			}

			switch ( $column ) {
				// Boolean columns (NOT NULL).
				case 'virtual':
				case 'downloadable':
				case 'manage_stock':
				case 'sold_individually':
					$data[ $column ] = wc_string_to_bool( $value ) ? 1 : 0;
					break;

				// Date columns (nullable).
				case 'date_on_sale_from':
				case 'date_on_sale_to':
					$data[ $column ] = is_numeric( $value ) ? gmdate( 'Y-m-d H:i:s', (int) $value ) : null;
					break;

				// Nullable integer columns.
				case 'image_id':
				case 'stock_quantity':
				case 'low_stock_amount':
					$data[ $column ] = '' !== $value ? (int) $value : null;
					break;

				// NOT NULL integer columns — default to 0.
				case 'total_sales':
				case 'rating_count':
					$data[ $column ] = '' !== $value ? (int) $value : 0;
					break;

				// Nullable decimal/string columns.
				case 'price':
				case 'regular_price':
				case 'sale_price':
				case 'weight':
				case 'length':
				case 'width':
				case 'height':
				case 'purchase_note':
					$data[ $column ] = '' !== $value ? $value : null;
					break;

				// NOT NULL decimal — default to 0.
				case 'average_rating':
					$data[ $column ] = '' !== $value ? $value : 0;
					break;

				// Nullable string — empty SKU stored as NULL.
				case 'sku':
					$data[ $column ] = '' !== $value ? $value : null;
					break;

				// NOT NULL string columns — use schema defaults.
				case 'tax_class':
					$data[ $column ] = '' !== $value ? $value : '';
					break;

				case 'tax_status':
					$data[ $column ] = '' !== $value ? $value : 'taxable';
					break;

				case 'stock_status':
					$data[ $column ] = '' !== $value ? $value : 'instock';
					break;

				case 'backorders':
					$data[ $column ] = '' !== $value ? $value : 'no';
					break;

				default:
					$data[ $column ] = '' !== $value ? $value : '';
					break;
			}
		}

		$wpdb->replace(
			"{$wpdb->prefix}wpt_products",
			$data
		);

		// Check immediately — subsequent queries would clear last_error.
		if ( $wpdb->last_error ) {
			return false;
		}

		// Migrate relationships.
		$this->migrate_relationships( $product_id );

		// Migrate attributes.
		$this->migrate_attributes( $product_id );

		// Migrate downloads.
		$this->migrate_downloads( $product_id );

		return true;
	}

	/**
	 * Migrate product relationships (upsells, cross-sells, grouped, gallery).
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 */
	private function migrate_relationships( $product_id ) {
		global $wpdb;

		$table = "{$wpdb->prefix}wpt_product_relationships";

		// Clear existing.
		$wpdb->delete( $table, array( 'product_id' => $product_id ) );

		$types = array(
			'_upsell_ids'           => 'upsell',
			'_crosssell_ids'        => 'cross_sell',
			'_children'             => 'grouped',
			'_product_image_gallery' => 'image',
		);

		foreach ( $types as $meta_key => $type ) {
			$raw = get_post_meta( $product_id, $meta_key, true );

			if ( 'image' === $type && is_string( $raw ) ) {
				$ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );
			} elseif ( is_array( $raw ) ) {
				$ids = array_filter( array_map( 'intval', $raw ) );
			} else {
				continue;
			}

			$position = 0;
			foreach ( $ids as $child_id ) {
				$wpdb->insert(
					$table,
					array(
						'product_id' => $product_id,
						'child_id'   => $child_id,
						'type'       => $type,
						'position'   => $position++,
					)
				);
			}
		}
	}

	/**
	 * Migrate product attributes.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 */
	private function migrate_attributes( $product_id ) {
		global $wpdb;

		$attrs_table  = "{$wpdb->prefix}wpt_product_attributes";
		$values_table = "{$wpdb->prefix}wpt_product_attribute_values";

		// Clear existing.
		$wpdb->delete( $attrs_table, array( 'product_id' => $product_id ) );
		$wpdb->delete( $values_table, array( 'product_id' => $product_id ) );

		$raw_attrs = get_post_meta( $product_id, '_product_attributes', true );
		if ( ! is_array( $raw_attrs ) ) {
			return;
		}

		$position = 0;
		foreach ( $raw_attrs as $slug => $attr_data ) {
			$wpdb->insert(
				$attrs_table,
				array(
					'product_id'   => $product_id,
					'name'         => $attr_data['name'] ?? $slug,
					'value'        => $attr_data['value'] ?? '',
					'is_visible'   => ! empty( $attr_data['is_visible'] ) ? 1 : 0,
					'is_variation' => ! empty( $attr_data['is_variation'] ) ? 1 : 0,
					'is_taxonomy'  => ! empty( $attr_data['is_taxonomy'] ) ? 1 : 0,
					'position'     => $attr_data['position'] ?? $position,
				)
			);

			$attr_id = (int) $wpdb->insert_id;

			// Values — taxonomy terms or pipe-separated custom values.
			if ( ! empty( $attr_data['is_taxonomy'] ) ) {
				$terms = wp_get_post_terms( $product_id, $attr_data['name'], array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term_name ) {
						$wpdb->insert(
							$values_table,
							array(
								'product_id'           => $product_id,
								'product_attribute_id' => $attr_id,
								'value'                => $term_name,
							)
						);
					}
				}
			} elseif ( ! empty( $attr_data['value'] ) ) {
				$values = array_map( 'trim', explode( '|', $attr_data['value'] ) );
				foreach ( $values as $val ) {
					if ( '' === $val ) {
						continue;
					}
					$wpdb->insert(
						$values_table,
						array(
							'product_id'           => $product_id,
							'product_attribute_id' => $attr_id,
							'value'                => $val,
						)
					);
				}
			}

			$position++;
		}
	}

	/**
	 * Migrate downloadable files.
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id Product ID.
	 */
	private function migrate_downloads( $product_id ) {
		global $wpdb;

		$table = "{$wpdb->prefix}wpt_product_downloads";

		$wpdb->delete( $table, array( 'product_id' => $product_id ) );

		$raw = get_post_meta( $product_id, '_downloadable_files', true );
		if ( ! is_array( $raw ) ) {
			return;
		}

		foreach ( $raw as $download_key => $file_data ) {
			$wpdb->insert(
				$table,
				array(
					'product_id'   => $product_id,
					'download_key' => sanitize_key( $download_key ),
					'name'         => $file_data['name'] ?? '',
					'file'         => $file_data['file'] ?? '',
				)
			);
		}
	}

	/**
	 * Clear caches between batches to free memory.
	 *
	 * @since 2.0.0
	 */
	/**
	 * Sync stock status for variable products based on their children.
	 *
	 * After migration, variable products may have stale stock_status from
	 * postmeta. This recalculates based on child variation stock statuses.
	 *
	 * @since 2.0.0
	 */
	private function sync_variable_stock_status() {
		global $wpdb;

		\WP_CLI::log( 'Syncing variable product stock status...' );

		// Set variable products to 'instock' if any published child variation is in stock.
		$updated = $wpdb->query(
			"UPDATE {$wpdb->prefix}wpt_products p
			SET p.stock_status = 'instock'
			WHERE p.type = 'variable'
			AND p.stock_status != 'instock'
			AND EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wpt_products c
				INNER JOIN {$wpdb->posts} cp ON c.product_id = cp.ID
				WHERE cp.post_parent = p.product_id
				AND cp.post_status IN ('publish', 'private')
				AND c.stock_status = 'instock'
			)"
		);

		// Set variable products to 'onbackorder' if no child is in stock but some are on backorder.
		$wpdb->query(
			"UPDATE {$wpdb->prefix}wpt_products p
			SET p.stock_status = 'onbackorder'
			WHERE p.type = 'variable'
			AND p.stock_status = 'outofstock'
			AND EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wpt_products c
				INNER JOIN {$wpdb->posts} cp ON c.product_id = cp.ID
				WHERE cp.post_parent = p.product_id
				AND cp.post_status IN ('publish', 'private')
				AND c.stock_status = 'onbackorder'
			)"
		);

		// Also fix any variations with empty stock_status — default to 'instock'.
		$fixed = $wpdb->query(
			"UPDATE {$wpdb->prefix}wpt_products
			SET stock_status = 'instock'
			WHERE stock_status = '' OR stock_status IS NULL"
		);

		if ( $fixed ) {
			\WP_CLI::log( "Fixed {$fixed} products with empty stock_status." );
		}

		\WP_CLI::log( "Stock status synced. {$updated} variable products updated." );
	}

	private function clear_batch_caches() {
		wp_cache_flush();

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}
}
