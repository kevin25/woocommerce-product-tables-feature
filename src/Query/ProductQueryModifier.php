<?php
/**
 * Product Query Modifier.
 *
 * Hooks into WP_Query clauses to join the custom product table so that
 * queries can filter/sort by columns that live in wpt_products instead
 * of postmeta. This significantly speeds up catalog / REST / shortcode
 * queries that touch price, stock, SKU, etc.
 *
 * @package WPT\Query
 */

namespace WPT\Query;

defined( 'ABSPATH' ) || exit;

/**
 * ProductQueryModifier class.
 */
class ProductQueryModifier {

	/**
	 * Whether the JOIN has already been added to the current query.
	 *
	 * @var bool
	 */
	private $joined = false;

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.0
	 * @internal
	 */
	public function init() {
		// Low priority so we run after WC's own query modifications.
		add_filter( 'woocommerce_get_catalog_ordering_args', array( $this, 'catalog_ordering' ), 20 );
		add_filter( 'woocommerce_product_query_meta_query', array( $this, 'optimize_meta_query' ), 20, 2 );

		// Generic WP_Query clause filters for product post types.
		add_action( 'pre_get_posts', array( $this, 'maybe_attach_clause_filters' ) );
	}

	/**
	 * Attach clause filters only for product queries.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `pre_get_posts`.
	 *
	 * @param \WP_Query $query WP Query.
	 */
	public function maybe_attach_clause_filters( $query ) {
		if ( ! $this->is_product_query( $query ) ) {
			return;
		}

		$this->joined = false;

		add_filter( 'posts_join', array( $this, 'add_product_table_join' ), 10, 2 );
		add_filter( 'posts_where', array( $this, 'add_stock_status_where' ), 10, 2 );
		add_filter( 'posts_orderby', array( $this, 'add_custom_orderby' ), 10, 2 );

		// Clean up after the query runs — posts_results fires for all queries (including non-loop).
		add_filter( 'posts_results', array( $this, 'cleanup_clause_filters' ), 10, 2 );
	}

	/**
	 * Add a LEFT JOIN to the custom product table.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `posts_join`.
	 *
	 * @param string    $join  Existing JOIN clause.
	 * @param \WP_Query $query WP Query.
	 * @return string
	 */
	public function add_product_table_join( $join, $query ) {
		if ( ! $this->is_product_query( $query ) || $this->joined ) {
			return $join;
		}

		global $wpdb;
		$join .= " LEFT JOIN {$wpdb->prefix}wpt_products AS wpt ON {$wpdb->posts}.ID = wpt.product_id ";
		$this->joined = true;

		return $join;
	}

	/**
	 * Filter WHERE clause — hide out-of-stock products if the WC setting is active.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `posts_where`.
	 *
	 * @param string    $where Existing WHERE clause.
	 * @param \WP_Query $query WP Query.
	 * @return string
	 */
	public function add_stock_status_where( $where, $query ) {
		if ( ! $this->is_product_query( $query ) ) {
			return $where;
		}

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! is_admin() ) {
			$where .= " AND wpt.stock_status != 'outofstock' ";
		}

		return $where;
	}

	/**
	 * Override ORDER BY for catalog sorting to use custom table columns.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `posts_orderby`.
	 *
	 * @param string    $orderby Existing ORDER BY clause.
	 * @param \WP_Query $query   WP Query.
	 * @return string
	 */
	public function add_custom_orderby( $orderby, $query ) {
		if ( ! $this->is_product_query( $query ) ) {
			return $orderby;
		}

		$wpt_orderby = $query->get( 'wpt_orderby' );
		if ( ! $wpt_orderby ) {
			return $orderby;
		}

		$allowed = array(
			'price'          => 'wpt.price+0',
			'price-desc'     => 'wpt.price+0 DESC',
			'rating'         => 'wpt.average_rating DESC',
			'popularity'     => 'wpt.total_sales DESC',
			'sku'            => 'wpt.sku ASC',
			'stock_quantity' => 'wpt.stock_quantity DESC',
		);

		if ( isset( $allowed[ $wpt_orderby ] ) ) {
			return $allowed[ $wpt_orderby ];
		}

		return $orderby;
	}

	/**
	 * Translate WC catalog ordering args to custom-table ordering.
	 *
	 * Replaces WC's postmeta-based ordering with direct column references.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_get_catalog_ordering_args`.
	 *
	 * @param array $args Ordering args.
	 * @return array
	 */
	public function catalog_ordering( $args ) {
		$orderby = $args['orderby'] ?? '';

		$mapping = array(
			'price'      => 'price',
			'price-desc' => 'price-desc',
			'rating'     => 'rating',
			'popularity' => 'popularity',
		);

		if ( isset( $mapping[ $orderby ] ) ) {
			$args['orderby']  = 'none';
			$args['wpt_orderby'] = $mapping[ $orderby ];
		}

		return $args;
	}

	/**
	 * Remove meta_queries that are now served by the custom table.
	 *
	 * WC adds meta queries for stock status, price ranges, etc. We can remove
	 * them because the JOIN + WHERE on wpt_products handles them.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `woocommerce_product_query_meta_query`.
	 *
	 * @param array                $meta_query Meta query array.
	 * @param \WC_Query|\WP_Query  $query      Query object.
	 * @return array
	 */
	public function optimize_meta_query( $meta_query, $query ) {
		$removable_keys = array( '_stock_status', '_price', '_sku' );

		foreach ( $meta_query as $key => $clause ) {
			if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
				continue;
			}
			if ( in_array( $clause['key'], $removable_keys, true ) ) {
				unset( $meta_query[ $key ] );
			}
		}

		return array_values( $meta_query );
	}

	/**
	 * Remove clause filters after the query has run.
	 *
	 * @since 2.0.0
	 * @internal Hook callback for `posts_results`.
	 *
	 * @param array     $posts Posts array.
	 * @param \WP_Query $query WP Query.
	 * @return array Unmodified posts.
	 */
	public function cleanup_clause_filters( $posts, $query ) {
		remove_filter( 'posts_join', array( $this, 'add_product_table_join' ), 10 );
		remove_filter( 'posts_where', array( $this, 'add_stock_status_where' ), 10 );
		remove_filter( 'posts_orderby', array( $this, 'add_custom_orderby' ), 10 );
		remove_filter( 'posts_results', array( $this, 'cleanup_clause_filters' ), 10 );
		$this->joined = false;

		return $posts;
	}

	/**
	 * Check whether a WP_Query is targeting product post types.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_Query $query WP Query.
	 * @return bool
	 */
	private function is_product_query( $query ) {
		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) ) {
			return ! empty( array_intersect( $post_type, array( 'product', 'product_variation' ) ) );
		}

		return in_array( $post_type, array( 'product', 'product_variation' ), true );
	}
}
