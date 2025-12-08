<?php
/**
 * Purchase Order Suggestion Algorithm
 *
 * Analyzes stock levels, sales patterns, and lead times to determine
 * which products need to be reordered.
 *
 * @package     SereniSoft\AtumEnhancer\PurchaseOrderSuggestions
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 1.0.0
 */

namespace SereniSoft\AtumEnhancer\PurchaseOrderSuggestions;

defined( 'ABSPATH' ) || die;

use SereniSoft\AtumEnhancer\Settings\Settings;
use Atum\Suppliers\Suppliers;

class POSuggestionAlgorithm {

	/**
	 * Get products that need reordering for a specific supplier
	 *
	 * @since 1.0.0
	 *
	 * @param int $supplier_id Supplier ID.
	 *
	 * @return array Array of product data needing reorder.
	 */
	public static function get_products_needing_reorder( $supplier_id ) {

		$products_to_reorder = array();

		// Get all products for this supplier.
		$product_ids = Suppliers::get_supplier_products( $supplier_id );

		if ( empty( $product_ids ) ) {
			return $products_to_reorder;
		}

		// Get settings.
		$stock_threshold   = (int) Settings::get( 'sae_stock_threshold_percent', 25 );
		$use_seasonal      = 'yes' === Settings::get( 'sae_include_seasonal_analysis', 'yes' );
		$orders_per_year   = (int) Settings::get( 'sae_default_orders_per_year', 4 );

		// Calculate days of stock to maintain based on orders per year.
		$days_of_stock_target = ceil( 365 / $orders_per_year );

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}

			$analysis = self::analyze_product( $product, $days_of_stock_target, $stock_threshold, $use_seasonal );

			if ( $analysis['needs_reorder'] ) {
				$products_to_reorder[] = $analysis;
			}
		}

		return $products_to_reorder;

	}

	/**
	 * Analyze a single product for reorder needs
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product $product              Product object.
	 * @param int         $days_of_stock_target Target days of stock to maintain.
	 * @param int         $stock_threshold      Percentage threshold for reorder.
	 * @param bool        $use_seasonal         Whether to use seasonal analysis.
	 *
	 * @return array Product analysis data.
	 */
	public static function analyze_product( $product, $days_of_stock_target, $stock_threshold, $use_seasonal ) {

		$product_id    = $product->get_id();
		$current_stock = (int) $product->get_stock_quantity();

		// Get average daily sales.
		$avg_daily_sales = self::get_average_daily_sales( $product_id, $use_seasonal );

		// Calculate optimal stock level.
		$optimal_stock = ceil( $avg_daily_sales * $days_of_stock_target );

		// Calculate days of stock remaining.
		$days_remaining = $avg_daily_sales > 0 ? floor( $current_stock / $avg_daily_sales ) : 999;

		// Calculate threshold level.
		$threshold_level = ceil( $optimal_stock * ( $stock_threshold / 100 ) );

		// Determine if reorder is needed.
		$needs_reorder = $current_stock <= $threshold_level && $avg_daily_sales > 0;

		// Calculate suggested quantity.
		$suggested_qty = $needs_reorder ? max( 1, $optimal_stock - $current_stock ) : 0;

		return array(
			'product_id'       => $product_id,
			'product_name'     => $product->get_name(),
			'sku'              => $product->get_sku(),
			'current_stock'    => $current_stock,
			'avg_daily_sales'  => round( $avg_daily_sales, 2 ),
			'optimal_stock'    => $optimal_stock,
			'threshold_level'  => $threshold_level,
			'days_remaining'   => $days_remaining,
			'suggested_qty'    => $suggested_qty,
			'needs_reorder'    => $needs_reorder,
			'purchase_price'   => self::get_purchase_price( $product ),
		);

	}

	/**
	 * Get average daily sales for a product
	 *
	 * @since 1.0.0
	 *
	 * @param int  $product_id   Product ID.
	 * @param bool $use_seasonal Whether to use seasonal adjustment.
	 *
	 * @return float Average daily sales.
	 */
	public static function get_average_daily_sales( $product_id, $use_seasonal = true ) {

		global $wpdb;

		// Get sales from order items for the last 365 days.
		$year_ago = date( 'Y-m-d', strtotime( '-365 days' ) );

		$total_sales = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(oim.meta_value)
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id
			INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			WHERE oim.meta_key = '_qty'
			AND oi.order_item_type = 'line_item'
			AND p.post_type = 'shop_order'
			AND p.post_status IN ('wc-completed', 'wc-processing')
			AND p.post_date >= %s
			AND oi.order_item_id IN (
				SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE meta_key IN ('_product_id', '_variation_id')
				AND meta_value = %d
			)",
			$year_ago,
			$product_id
		) );

		$total_sales = (float) $total_sales;
		$avg_daily   = $total_sales / 365;

		if ( $use_seasonal ) {
			$avg_daily = self::apply_seasonal_adjustment( $product_id, $avg_daily );
		}

		return $avg_daily;

	}

	/**
	 * Apply seasonal adjustment to daily sales average
	 *
	 * @since 1.0.0
	 *
	 * @param int   $product_id Product ID.
	 * @param float $avg_daily  Current average daily sales.
	 *
	 * @return float Seasonally adjusted average.
	 */
	public static function apply_seasonal_adjustment( $product_id, $avg_daily ) {

		global $wpdb;

		$current_month = (int) date( 'n' );

		// Get sales for current month across previous years.
		$month_sales = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(oim.meta_value)
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id
			INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			WHERE oim.meta_key = '_qty'
			AND oi.order_item_type = 'line_item'
			AND p.post_type = 'shop_order'
			AND p.post_status IN ('wc-completed', 'wc-processing')
			AND MONTH(p.post_date) = %d
			AND oi.order_item_id IN (
				SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE meta_key IN ('_product_id', '_variation_id')
				AND meta_value = %d
			)",
			$current_month,
			$product_id
		) );

		// Get total sales all time.
		$total_sales = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(oim.meta_value)
			FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id
			INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
			WHERE oim.meta_key = '_qty'
			AND oi.order_item_type = 'line_item'
			AND p.post_type = 'shop_order'
			AND p.post_status IN ('wc-completed', 'wc-processing')
			AND oi.order_item_id IN (
				SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE meta_key IN ('_product_id', '_variation_id')
				AND meta_value = %d
			)",
			$product_id
		) );

		if ( empty( $total_sales ) || $total_sales < 12 ) {
			// Not enough data for seasonal adjustment.
			return $avg_daily;
		}

		// Calculate expected monthly percentage (1/12 = 8.33%).
		$expected_monthly_percent = 100 / 12;

		// Calculate actual monthly percentage.
		$actual_monthly_percent = ( $month_sales / $total_sales ) * 100;

		// Calculate seasonal factor.
		$seasonal_factor = $actual_monthly_percent / $expected_monthly_percent;

		// Apply factor with dampening (don't swing too wildly).
		$dampened_factor = 1 + ( ( $seasonal_factor - 1 ) * 0.5 );

		// Clamp between 0.5 and 2.0.
		$dampened_factor = max( 0.5, min( 2.0, $dampened_factor ) );

		return $avg_daily * $dampened_factor;

	}

	/**
	 * Get purchase price for a product
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return float Purchase price.
	 */
	public static function get_purchase_price( $product ) {

		// Try to get ATUM purchase price.
		$purchase_price = $product->get_meta( '_purchase_price' );

		if ( ! empty( $purchase_price ) ) {
			return (float) $purchase_price;
		}

		// Fallback to regular price with discount assumption.
		$regular_price = $product->get_regular_price();

		if ( ! empty( $regular_price ) ) {
			// Assume 50% markup, so purchase price is roughly 66% of regular.
			return (float) $regular_price * 0.66;
		}

		return 0;

	}

	/**
	 * Check if a supplier had a PO created recently
	 *
	 * @since 1.0.0
	 *
	 * @param int $supplier_id Supplier ID.
	 *
	 * @return bool True if supplier had recent PO.
	 */
	public static function supplier_has_recent_po( $supplier_id ) {

		global $wpdb;

		$min_days = (int) Settings::get( 'sae_min_days_before_reorder', 30 );
		$date_threshold = date( 'Y-m-d', strtotime( "-{$min_days} days" ) );

		$recent_po = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'atum_purchase_order'
			AND p.post_status != 'trash'
			AND p.post_date >= %s
			AND pm.meta_key = '_supplier'
			AND pm.meta_value = %d
			LIMIT 1",
			$date_threshold,
			$supplier_id
		) );

		return ! empty( $recent_po );

	}

}
