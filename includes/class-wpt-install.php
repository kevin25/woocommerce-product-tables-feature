<?php
/**
 * Table installation and schema management.
 *
 * @package WooCommerce\ProductTables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPT_Install
 *
 * Handles creation and updates of custom product tables.
 */
class WPT_Install {

	/**
	 * Run on plugin activation.
	 *
	 * @since 2.0.0
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'wpt_db_version', WPT_VERSION );
		update_option( 'wpt_installed_at', current_time( 'mysql', true ) );
	}

	/**
	 * Create all custom product tables using dbDelta.
	 *
	 * @since 2.0.0
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::get_schema( $charset_collate );

		dbDelta( $tables );
	}

	/**
	 * Get the full SQL schema for all custom tables.
	 *
	 * @since 2.0.0
	 *
	 * @param string $charset_collate Database charset and collation.
	 * @return string SQL statements.
	 */
	public static function get_schema( $charset_collate ) {
		global $wpdb;

		$tables = "
CREATE TABLE {$wpdb->prefix}wpt_products (
	product_id bigint(20) unsigned NOT NULL,
	sku varchar(100) DEFAULT NULL,
	image_id bigint(20) unsigned DEFAULT 0,
	height decimal(10,4) DEFAULT NULL,
	width decimal(10,4) DEFAULT NULL,
	length decimal(10,4) DEFAULT NULL,
	weight decimal(10,4) DEFAULT NULL,
	stock_quantity double DEFAULT NULL,
	type varchar(30) NOT NULL DEFAULT 'simple',
	virtual tinyint(1) NOT NULL DEFAULT 0,
	downloadable tinyint(1) NOT NULL DEFAULT 0,
	tax_class varchar(100) NOT NULL DEFAULT '',
	tax_status varchar(30) NOT NULL DEFAULT 'taxable',
	total_sales decimal(19,4) NOT NULL DEFAULT 0,
	price decimal(19,4) DEFAULT NULL,
	regular_price decimal(19,4) DEFAULT NULL,
	sale_price decimal(19,4) DEFAULT NULL,
	date_on_sale_from datetime DEFAULT NULL,
	date_on_sale_to datetime DEFAULT NULL,
	average_rating decimal(3,2) NOT NULL DEFAULT 0,
	stock_status varchar(30) NOT NULL DEFAULT 'instock',
	rating_count bigint(20) NOT NULL DEFAULT 0,
	manage_stock tinyint(1) NOT NULL DEFAULT 0,
	backorders varchar(10) NOT NULL DEFAULT 'no',
	low_stock_amount int(11) DEFAULT NULL,
	sold_individually tinyint(1) NOT NULL DEFAULT 0,
	purchase_note text DEFAULT NULL,
	PRIMARY KEY  (product_id),
	KEY sku (sku),
	KEY type (type),
	KEY price (price),
	KEY stock_status_price (stock_status, price),
	KEY date_on_sale_from (date_on_sale_from),
	KEY date_on_sale_to (date_on_sale_to),
	KEY average_rating (average_rating)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}wpt_product_attributes (
	product_attribute_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	product_id bigint(20) unsigned NOT NULL,
	name varchar(1000) NOT NULL DEFAULT '',
	value text NOT NULL,
	position int(11) unsigned NOT NULL DEFAULT 0,
	is_visible tinyint(1) NOT NULL DEFAULT 1,
	is_variation tinyint(1) NOT NULL DEFAULT 0,
	is_taxonomy tinyint(1) NOT NULL DEFAULT 0,
	PRIMARY KEY  (product_attribute_id),
	KEY product_id (product_id)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}wpt_product_attribute_values (
	attribute_value_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	product_id bigint(20) unsigned NOT NULL,
	product_attribute_id bigint(20) unsigned NOT NULL,
	value varchar(1000) NOT NULL DEFAULT '',
	is_default tinyint(1) NOT NULL DEFAULT 0,
	PRIMARY KEY  (attribute_value_id),
	KEY product_attribute_id (product_attribute_id),
	KEY product_id (product_id)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}wpt_product_downloads (
	download_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	product_id bigint(20) unsigned NOT NULL,
	download_key varchar(36) NOT NULL DEFAULT '',
	name varchar(1000) NOT NULL DEFAULT '',
	file text NOT NULL,
	PRIMARY KEY  (download_id),
	KEY product_id (product_id)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}wpt_product_relationships (
	relationship_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	product_id bigint(20) unsigned NOT NULL,
	child_id bigint(20) unsigned NOT NULL,
	type varchar(30) NOT NULL,
	position int(11) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (relationship_id),
	UNIQUE KEY product_type_child (product_id, type, child_id),
	KEY child_id (child_id)
) $charset_collate;

CREATE TABLE {$wpdb->prefix}wpt_product_variation_attribute_values (
	variation_attribute_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	product_id bigint(20) unsigned NOT NULL,
	variation_id bigint(20) unsigned NOT NULL,
	attribute_name varchar(1000) NOT NULL,
	attribute_value varchar(1000) NOT NULL DEFAULT '',
	PRIMARY KEY  (variation_attribute_id),
	KEY product_id (product_id),
	KEY variation_id (variation_id)
) $charset_collate;
";

		return $tables;
	}

	/**
	 * Check if all custom tables exist.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;

		$tables = array(
			"{$wpdb->prefix}wpt_products",
			"{$wpdb->prefix}wpt_product_attributes",
			"{$wpdb->prefix}wpt_product_attribute_values",
			"{$wpdb->prefix}wpt_product_downloads",
			"{$wpdb->prefix}wpt_product_relationships",
			"{$wpdb->prefix}wpt_product_variation_attribute_values",
		);

		foreach ( $tables as $table ) {
			$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $result !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop all custom tables. Used during rollback/uninstall.
	 *
	 * @since 2.0.0
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			"{$wpdb->prefix}wpt_product_variation_attribute_values",
			"{$wpdb->prefix}wpt_product_relationships",
			"{$wpdb->prefix}wpt_product_downloads",
			"{$wpdb->prefix}wpt_product_attribute_values",
			"{$wpdb->prefix}wpt_product_attributes",
			"{$wpdb->prefix}wpt_products",
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
