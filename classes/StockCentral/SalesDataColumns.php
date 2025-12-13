<?php
/**
 * Stock Central sales data columns for ATUM Enhancer
 *
 * @package     SereniSoft\AtumEnhancer\StockCentral
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 0.9.5
 */

namespace SereniSoft\AtumEnhancer\StockCentral;

defined( 'ABSPATH' ) || die;

use SereniSoft\AtumEnhancer\Settings\Settings;
use SereniSoft\AtumEnhancer\Suppliers\SupplierFields;

class SalesDataColumns {

	/**
	 * The singleton instance holder
	 *
	 * @var SalesDataColumns
	 */
	private static $instance;

	/**
	 * SalesDataColumns constructor
	 *
	 * @since 0.9.5
	 */
	private function __construct() {

		// Add sales columns to the Stock group.
		add_filter( 'atum/stock_central_list/column_group_members', array( $this, 'add_columns_to_group' ) );

		// Add sales column definitions.
		add_filter( 'atum/stock_central_list/table_columns', array( $this, 'add_columns' ) );

		// Render sales column values.
		add_filter( 'atum/list_table/column_default__sae_sales_year', array( $this, 'render_sales_year_column' ), 10, 4 );
		add_filter( 'atum/list_table/column_default__sae_sales_period', array( $this, 'render_sales_period_column' ), 10, 4 );


		// Handle AJAX fetch for sales data.
		add_action( 'wp_ajax_sae_fetch_sales_data', array( $this, 'ajax_fetch_sales_data' ) );

		// Add scripts for Stock Central.
		add_action( 'admin_footer', array( $this, 'add_inline_script' ) );

	}

	/**
	 * Add sales columns to the Stock group
	 *
	 * @since 0.9.5
	 *
	 * @param array $groups Column groups.
	 *
	 * @return array
	 */
	public function add_columns_to_group( $groups ) {

		foreach ( $groups as $group_key => &$group ) {
			// Add to the Stock group.
			if ( 'stock-counters' === $group_key && isset( $group['members'] ) ) {
				$group['members'][] = '_sae_sales_year';
				$group['members'][] = '_sae_sales_period';
			}
		}

		return $groups;

	}

	/**
	 * Add sales column definitions
	 *
	 * @since 0.9.5
	 *
	 * @param array $columns Table columns.
	 *
	 * @return array
	 */
	public function add_columns( $columns ) {

		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Add sales columns after _stock column.
			if ( '_stock' === $key ) {
				$new_columns['_sae_sales_year']   = __( 'Sold (Year)', 'serenisoft-atum-enhancer' );
				$new_columns['_sae_sales_period'] = __( 'Sold (Period)', 'serenisoft-atum-enhancer' );
			}
		}

		return $new_columns;

	}

	/**
	 * Render sales year column value
	 *
	 * @since 0.9.5
	 *
	 * @param string      $column_item Default column content.
	 * @param \WP_Post    $item        The post object.
	 * @param \WC_Product $product     The product object.
	 * @param object      $list_table  The list table instance.
	 *
	 * @return string
	 */
	public function render_sales_year_column( $column_item, $item, $product, $list_table ) {

		return '<span class="sae-sales-year" data-product-id="' . esc_attr( $product->get_id() ) . '">-</span>';

	}

	/**
	 * Render sales period column value
	 *
	 * @since 0.9.5
	 *
	 * @param string      $column_item Default column content.
	 * @param \WP_Post    $item        The post object.
	 * @param \WC_Product $product     The product object.
	 * @param object      $list_table  The list table instance.
	 *
	 * @return string
	 */
	public function render_sales_period_column( $column_item, $item, $product, $list_table ) {

		return '<span class="sae-sales-period" data-product-id="' . esc_attr( $product->get_id() ) . '">-</span>';

	}


	/**
	 * AJAX handler to fetch sales data for products
	 *
	 * @since 0.9.5
	 */
	public function ajax_fetch_sales_data() {

		check_ajax_referer( 'sae_fetch_sales', 'security' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();

		if ( empty( $product_ids ) ) {
			wp_send_json_error( 'No products specified' );
		}

		// Get default orders per year setting.
		$default_orders_year = (int) Settings::get( 'orders_per_year', 4 );

		$results = array();

		foreach ( $product_ids as $product_id ) {
			try {
				// Get supplier-specific orders_per_year if available.
				$atum_product = \Atum\Inc\Helpers::get_atum_product( $product_id );
				$supplier_id  = $atum_product ? $atum_product->get_supplier_id() : 0;

				$supplier_orders_year = $supplier_id ? SupplierFields::get_orders_per_year( $supplier_id ) : null;
				$orders_per_year      = $supplier_orders_year ?? $default_orders_year;

				$year_sales = $this->get_product_sales( $product_id, 365 );

				$results[ $product_id ] = array(
					'year'   => $year_sales,
					'period' => round( $year_sales / $orders_per_year ),
				);
			} catch ( \Exception $e ) {
				$results[ $product_id ] = array(
					'year'   => 0,
					'period' => 0,
				);
			}
		}

		wp_send_json_success( array(
			'sales' => $results,
		) );

	}

	/**
	 * Get total quantity sold for a product in the last N days
	 *
	 * Uses WooCommerce's wc_order_product_lookup table which has pre-calculated product_qty.
	 *
	 * @since 0.9.5
	 *
	 * @param int $product_id Product ID.
	 * @param int $days       Number of days to look back.
	 *
	 * @return int
	 */
	private function get_product_sales( $product_id, $days ) {

		global $wpdb;

		$date_from = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Check if using HPOS.
		$use_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $use_hpos ) {
			// HPOS: Use wc_orders table.
			$sql = $wpdb->prepare(
				"SELECT SUM(opl.product_qty) as qty
				FROM {$wpdb->prefix}wc_order_product_lookup AS opl
				INNER JOIN {$wpdb->prefix}wc_orders AS o ON opl.order_id = o.id
				WHERE (opl.product_id = %d OR opl.variation_id = %d)
				AND o.status IN ('wc-completed', 'wc-processing')
				AND opl.date_created >= %s",
				$product_id,
				$product_id,
				$date_from
			);
		} else {
			// Legacy: Use posts table for order status.
			$sql = $wpdb->prepare(
				"SELECT SUM(opl.product_qty) as qty
				FROM {$wpdb->prefix}wc_order_product_lookup AS opl
				INNER JOIN {$wpdb->posts} AS p ON opl.order_id = p.ID
				WHERE (opl.product_id = %d OR opl.variation_id = %d)
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND opl.date_created >= %s",
				$product_id,
				$product_id,
				$date_from
			);
		}

		$result = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return intval( $result );

	}

	/**
	 * Add inline script for sales data handling
	 *
	 * @since 0.9.5
	 */
	public function add_inline_script() {

		$screen = get_current_screen();

		if ( ! $screen || 'atum-inventory_page_atum-stock-central' !== $screen->id ) {
			return;
		}

		$nonce = wp_create_nonce( 'sae_fetch_sales' );
		?>
		<script type="text/javascript">
		jQuery(function($) {
			var saeSalesNonce = '<?php echo esc_js( $nonce ); ?>';
			var saeFetching = false;

			// Fetch sales data for visible products.
			function saeFetchSalesData() {
				if (saeFetching) return;

				var productIds = [];
				$('.atum-list-wrapper table tbody tr[data-id]').each(function() {
					productIds.push($(this).data('id'));
				});

				if (productIds.length === 0) return;

				saeFetching = true;
				$('.sae-sales-year, .sae-sales-period').text('...');

				$.post(ajaxurl, {
					action: 'sae_fetch_sales_data',
					security: saeSalesNonce,
					product_ids: productIds
				}, function(response) {
					saeFetching = false;

					if (response.success && response.data.sales) {
						$.each(response.data.sales, function(productId, data) {
							$('.sae-sales-year[data-product-id="' + productId + '"]').text(data.year);
							$('.sae-sales-period[data-product-id="' + productId + '"]').text(data.period);
						});
					} else {
						$('.sae-sales-year, .sae-sales-period').text('-');
					}
				}).fail(function() {
					saeFetching = false;
					$('.sae-sales-year, .sae-sales-period').text('-');
				});
			}

			// Auto-fetch on initial page load.
			setTimeout(saeFetchSalesData, 500);

			// Auto-fetch when ATUM updates the table (pagination, filtering, sorting).
			if (typeof wp !== 'undefined' && wp.hooks) {
				wp.hooks.addAction('atum_listTable_tableUpdated', 'sae', function() {
					setTimeout(saeFetchSalesData, 100);
				});
			}
		});
		</script>
		<?php

	}


	/*******************
	 * Instance methods
	 *******************/

	/**
	 * Cannot be cloned
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cloning is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.5' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Serialization is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.5' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return SalesDataColumns instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
