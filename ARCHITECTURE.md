# WooCommerce Product Tables — Architecture Design

## Approach: HPOS-Mirror Architecture

Directly mirrors WooCommerce's proven HPOS (High-Performance Order Storage) pattern, adapted for products. Maximum compatibility, battle-tested design.

---

## Requirements Summary

1. **Deployment scope**: Both existing stores (migration + rollback) AND new/clean installs
2. **Data sync strategy**: Full dual-write (HPOS-style) — custom tables authoritative, postmeta kept in sync on every write
3. **WooCommerce compatibility**: 8.0+
4. **PHP version**: 7.4+
5. **Plugin type**: Standalone extension — own namespace (`WPT\`)
6. **Performance target**: Maximum optimization at any scale
7. **Security**: All queries via `$wpdb->prepare()`, nonce verification on all admin actions
8. **API compatibility**: REST API V3/V4 + Store API (via data store layer)
9. **Migration engine**: Action Scheduler batch processing, resumable, progress tracking, rollback
10. **Caching**: Multi-layer with proper invalidation on all write paths

---

## Directory Structure

```
woocommerce-product-tables-feature-plugin/
├── woocommerce-product-tables.php           Entry point (activation, deactivation, bootstrap)
├── uninstall.php                            Clean removal (options, cron; tables only if WPT_REMOVE_ALL_DATA)
├── ARCHITECTURE.md                          This file
├── includes/
│   ├── class-wpt-autoloader.php             PSR-4 autoloader for src/ (WPT\ namespace)
│   ├── class-wpt-bootstrap.php              Singleton — wires stores, sync, cache, query mods
│   ├── class-wpt-install.php                Table creation via dbDelta, activate/drop helpers
│   └── Admin/
│       └── class-wpt-settings.php           WC_Settings_Page — enable/disable, sync controls, migration status
├── src/
│   ├── DataStores/
│   │   ├── ProductDataStore.php             Core data store (~1100 lines) — CRUD, relationships, attributes, downloads, query
│   │   ├── ProductVariableDataStore.php     Variable product store — children, variation attributes, price sync
│   │   ├── ProductVariationDataStore.php    Variation store — parent inheritance, reduced columns, title generation
│   │   └── ProductGroupedDataStore.php      Grouped store — child price sync with post_status filter
│   ├── Sync/
│   │   ├── ProductSynchronizer.php          Dual-write engine — mirrors 24+ columns to postmeta
│   │   ├── BackwardsCompatibility.php       get/update/delete_post_metadata interception layer
│   │   └── PostData.php                     delete_post hook — cleans all 6 custom tables
│   ├── Cache/
│   │   └── CacheInvalidator.php             Hooks into product lifecycle — clears WPT + WC caches/transients
│   ├── Query/
│   │   └── ProductQueryModifier.php         LEFT JOIN wpt_products on WP_Query, custom ordering, stock filter
│   └── CLI/
│       └── Commands.php                     WP-CLI: migrate, rollback, status, verify (with embedded batch logic)
└── tests/
    ├── Unit/
    └── Integration/
```

---

## Database Schema

### Table: `{prefix}wpt_products`

Core product data. One row per product (including variations).

| Column | Type | Notes |
|---|---|---|
| `product_id` | `bigint(20) unsigned NOT NULL` | PK, matches `wp_posts.ID` |
| `sku` | `varchar(100)` | UNIQUE index |
| `image_id` | `bigint(20) unsigned DEFAULT 0` | Thumbnail attachment ID |
| `height` | `decimal(10,4) DEFAULT NULL` | |
| `width` | `decimal(10,4) DEFAULT NULL` | |
| `length` | `decimal(10,4) DEFAULT NULL` | |
| `weight` | `decimal(10,4) DEFAULT NULL` | |
| `stock_quantity` | `double DEFAULT NULL` | Matches WC core type |
| `type` | `varchar(30) DEFAULT 'simple'` | Product type slug |
| `virtual` | `tinyint(1) DEFAULT 0` | |
| `downloadable` | `tinyint(1) DEFAULT 0` | |
| `tax_class` | `varchar(100) DEFAULT ''` | |
| `tax_status` | `varchar(30) DEFAULT 'taxable'` | |
| `total_sales` | `decimal(19,4) DEFAULT 0` | |
| `price` | `decimal(19,4) DEFAULT NULL` | Active price |
| `regular_price` | `decimal(19,4) DEFAULT NULL` | |
| `sale_price` | `decimal(19,4) DEFAULT NULL` | |
| `date_on_sale_from` | `datetime DEFAULT NULL` | |
| `date_on_sale_to` | `datetime DEFAULT NULL` | |
| `average_rating` | `decimal(3,2) DEFAULT 0` | |
| `stock_status` | `varchar(30) DEFAULT 'instock'` | |
| `rating_count` | `bigint(20) DEFAULT 0` | |
| `manage_stock` | `tinyint(1) DEFAULT 0` | Stored explicitly (was derived) |
| `backorders` | `varchar(10) DEFAULT 'no'` | |
| `low_stock_amount` | `int(11) DEFAULT NULL` | |
| `sold_individually` | `tinyint(1) DEFAULT 0` | |
| `purchase_note` | `text DEFAULT NULL` | |

**Indexes:**
- `PRIMARY KEY (product_id)`
- `UNIQUE KEY sku (sku)`
- `KEY type (type)`
- `KEY price (price)`
- `KEY stock_status_price (stock_status, price)`
- `KEY date_on_sale_from (date_on_sale_from)`
- `KEY date_on_sale_to (date_on_sale_to)`
- `KEY average_rating (average_rating)`

### Table: `{prefix}wpt_product_attributes`

| Column | Type | Notes |
|---|---|---|
| `product_attribute_id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `product_id` | `bigint(20) unsigned NOT NULL` | FK to wpt_products |
| `name` | `varchar(1000) NOT NULL DEFAULT ''` | Attribute name/taxonomy |
| `value` | `text NOT NULL` | Serialized terms or custom values |
| `position` | `int(11) unsigned NOT NULL DEFAULT 0` | Sort order |
| `is_visible` | `tinyint(1) NOT NULL DEFAULT 1` | |
| `is_variation` | `tinyint(1) NOT NULL DEFAULT 0` | |
| `is_taxonomy` | `tinyint(1) NOT NULL DEFAULT 0` | |

**Indexes:**
- `PRIMARY KEY (product_attribute_id)`
- `KEY product_id (product_id)`

### Table: `{prefix}wpt_product_attribute_values`

| Column | Type | Notes |
|---|---|---|
| `attribute_value_id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `product_id` | `bigint(20) unsigned NOT NULL` | |
| `product_attribute_id` | `bigint(20) unsigned NOT NULL` | FK to wpt_product_attributes |
| `value` | `varchar(1000) NOT NULL DEFAULT ''` | |
| `is_default` | `tinyint(1) NOT NULL DEFAULT 0` | |

**Indexes:**
- `PRIMARY KEY (attribute_value_id)`
- `KEY product_attribute_id (product_attribute_id)`
- `KEY product_id (product_id)`

### Table: `{prefix}wpt_product_downloads`

| Column | Type | Notes |
|---|---|---|
| `download_id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `product_id` | `bigint(20) unsigned NOT NULL` | |
| `download_key` | `varchar(36) NOT NULL` | UUID |
| `name` | `varchar(1000) NOT NULL DEFAULT ''` | |
| `file` | `text NOT NULL` | URL |

**Indexes:**
- `PRIMARY KEY (download_id)`
- `KEY product_id (product_id)`

### Table: `{prefix}wpt_product_relationships`

| Column | Type | Notes |
|---|---|---|
| `relationship_id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `product_id` | `bigint(20) unsigned NOT NULL` | |
| `child_id` | `bigint(20) unsigned NOT NULL` | Related product/attachment ID |
| `type` | `varchar(30) NOT NULL` | upsell, cross_sell, grouped, image_gallery |
| `position` | `int(11) unsigned NOT NULL DEFAULT 0` | Sort order |

**Indexes:**
- `PRIMARY KEY (relationship_id)`
- `UNIQUE KEY product_type_child (product_id, type, child_id)`
- `KEY child_id (child_id)`

### Table: `{prefix}wpt_product_variation_attribute_values`

| Column | Type | Notes |
|---|---|---|
| `variation_attribute_id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | PK |
| `product_id` | `bigint(20) unsigned NOT NULL` | Parent variable product |
| `variation_id` | `bigint(20) unsigned NOT NULL` | Variation post ID |
| `attribute_name` | `varchar(1000) NOT NULL` | Taxonomy or custom name |
| `attribute_value` | `varchar(1000) NOT NULL DEFAULT ''` | Empty = "any" |

**Indexes:**
- `PRIMARY KEY (variation_attribute_id)`
- `KEY product_id (product_id)`
- `KEY variation_id (variation_id)`

---

## Dual-Write Synchronizer Flow

```
Product Save (via WC_Data::save())
    ↓
ProductDataStore::update($product)
    ├── 1. Write to wpt_products (authoritative)
    ├── 2. Write related tables (attributes, downloads, relationships)
    ├── 3. Sync to wp_postmeta (via ProductSynchronizer)
    │   └── Updates all 24+ mapped meta keys
    ├── 4. Update wc_product_meta_lookup (WC's existing denormalized index)
    └── 5. Invalidate caches (CacheInvalidator)
```

When the plugin is **deactivated**, WC falls back to `WC_Product_Data_Store_CPT` — postmeta is already current, zero data loss.

### Meta Key Mappings (24 keys)

| Meta Key | Custom Table Column | Table |
|---|---|---|
| `_thumbnail_id` | `image_id` | wpt_products |
| `_sku` | `sku` | wpt_products |
| `_price` | `price` | wpt_products |
| `_regular_price` | `regular_price` | wpt_products |
| `_sale_price` | `sale_price` | wpt_products |
| `_sale_price_dates_from` | `date_on_sale_from` | wpt_products |
| `_sale_price_dates_to` | `date_on_sale_to` | wpt_products |
| `total_sales` | `total_sales` | wpt_products |
| `_tax_status` | `tax_status` | wpt_products |
| `_tax_class` | `tax_class` | wpt_products |
| `_manage_stock` | `manage_stock` | wpt_products |
| `_backorders` | `backorders` | wpt_products |
| `_sold_individually` | `sold_individually` | wpt_products |
| `_stock` | `stock_quantity` | wpt_products |
| `_stock_status` | `stock_status` | wpt_products |
| `_low_stock_amount` | `low_stock_amount` | wpt_products |
| `_height` | `height` | wpt_products |
| `_width` | `width` | wpt_products |
| `_length` | `length` | wpt_products |
| `_weight` | `weight` | wpt_products |
| `_virtual` | `virtual` | wpt_products |
| `_downloadable` | `downloadable` | wpt_products |
| `_wc_average_rating` | `average_rating` | wpt_products |
| `_wc_rating_count` | `rating_count` | wpt_products |
| `_purchase_note` | `purchase_note` | wpt_products |
| `_upsell_ids` | type='upsell' | wpt_product_relationships |
| `_crosssell_ids` | type='cross_sell' | wpt_product_relationships |
| `_product_image_gallery` | type='image_gallery' | wpt_product_relationships |
| `_children` | type='grouped' | wpt_product_relationships |
| `_downloadable_files` | rows | wpt_product_downloads |
| `_product_attributes` | rows | wpt_product_attributes |
| `_default_attributes` | is_default | wpt_product_attribute_values |
| `_variation_description` | post_content | wp_posts (unchanged) |

---

## Migration Engine (CLI-based)

Migration is embedded directly in `WPT\CLI\Commands` for simplicity and reliability:

```
wp wpt migrate [--batch-size=<n>] [--dry-run]
    ├── Creates tables if needed (WPT_Install::create_tables)
    ├── Batches product IDs not yet in wpt_products
    ├── For each product:
    │   ├── Reads postmeta → INSERTs to wpt_products via $wpdb->replace()
    │   ├── Migrates relationships (_upsell_ids, _crosssell_ids, _children, _product_image_gallery)
    │   ├── Migrates attributes (_product_attributes with taxonomy term lookup)
    │   └── Migrates downloads (_downloadable_files with UUID download_key)
    ├── Shows WP-CLI progress bar
    └── Sets wpt_custom_product_tables_enabled = yes on completion

wp wpt rollback [--drop-tables]
    ├── For each migrated product:
    │   └── Syncs custom table data back to postmeta via ProductSynchronizer
    ├── Sets wpt_custom_product_tables_enabled = no
    └── Optionally drops all 6 custom tables

wp wpt status
    └── Shows: enabled state, table existence, migration count/percentage, sync settings

wp wpt verify
    └── Compares _sku, _price, _regular_price, _stock, _stock_status between postmeta and custom table
```

---

## Multi-Layer Cache Strategy

| Layer | What | TTL | Invalidation |
|---|---|---|---|
| WordPress Object Cache | Full product row from `wpt_products` | Per-request (or persistent with Redis/Memcached) | On product save, delete, stock change |
| Transient Cache | Expensive aggregate queries (on-sale IDs, featured IDs, out-of-stock count) | 1 hour | `wc_delete_product_transients()` hook |
| Query Cache | Price filter ranges, attribute filter counts | Versioned (increment on any product change) | Version key bump |
| WC ProductCache | Integrates with WC's built-in product instance caching feature | WC-managed | `CacheInvalidator` fires `clean_post_cache` |

---

## Settings (WC > Settings > Product Tables)

| Setting | Type | Default | Option Key |
|---|---|---|---|
| Enable custom product tables | checkbox | no | `wpt_custom_product_tables_enabled` |
| Dual-write to postmeta | checkbox | yes | `wpt_dual_write_enabled` |
| Backwards-compatible meta reads | checkbox | yes | `wpt_backwards_compat_enabled` |
| Migration batch size | number (5–500) | 50 | `wpt_migration_batch_size` |

When "Enable custom product tables" is toggled on via settings, `WPT_Install::create_tables()` is called immediately. The admin page also shows migration progress (X of Y products) when partially migrated.

---

## WP-CLI Commands

```
wp wpt migrate [--batch-size=50] [--dry-run]    Batch migrate postmeta → custom tables
wp wpt rollback [--drop-tables]                  Sync back to postmeta, disable tables, optionally drop
wp wpt status                                    Show enabled/disabled, table existence, migration progress
wp wpt verify                                    Data integrity check between postmeta and custom tables
```

CLI commands are always registered (even when tables are disabled) so `wp wpt migrate` works as the initial setup path.

---

## Version Compatibility

| Feature | WC Version | Handling |
|---|---|---|
| Product instance caching | 9.2+ | Feature-detect before integrating with ProductCache |
| Action Scheduler 3.8+ | 8.0+ ships with AS | Direct usage, no bundling needed |
| `woocommerce_data_stores` filter | 3.0+ | Stable, no version gate needed |
| `wc_product_meta_lookup` table | 3.6+ | Always available in our 8.0+ range |
| Store API | 7.6+ | Works via data store layer, no special handling |
| REST API V4 | 9.0+ | Works via data store layer |
