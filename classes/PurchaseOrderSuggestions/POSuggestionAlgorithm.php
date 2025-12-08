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
use Atum\Suppliers\Supplier;
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
		$use_seasonal      = 'yes' === Settings::get( 'sae_include_seasonal_analysis', 'yes' );
		$orders_per_year   = (int) Settings::get( 'sae_default_orders_per_year', 4 );
		$service_level     = Settings::get( 'sae_service_level', '95' );

		// Calculate days of stock to maintain based on orders per year.
		$days_of_stock_target = ceil( 365 / $orders_per_year );

		// Get supplier lead time (default 14 days if not set).
		$supplier  = new Supplier( $supplier_id );
		$lead_time = $supplier->get_lead_time();
		if ( empty( $lead_time ) || $lead_time < 1 ) {
			$lead_time = 14;
		}

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}

			$analysis = self::analyze_product( $product, $days_of_stock_target, $lead_time, $service_level, $use_seasonal );

			if ( $analysis['needs_reorder'] ) {
				$products_to_reorder[] = $analysis;
			}
		}

		return $products_to_reorder;

	}

	/**
	 * Analyze a single product for reorder needs
	 *
	 * Uses the Reorder Point formula: ROP = (Avg Daily Sales × Lead Time) + Safety Stock
	 * Safety Stock = Z × σ(Demand) × √Lead Time
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product $product              Product object.
	 * @param int         $days_of_stock_target Target days of stock to maintain.
	 * @param int         $lead_time            Supplier lead time in days.
	 * @param int         $service_level        Service level percentage (90, 95, 99).
	 * @param bool        $use_seasonal         Whether to use seasonal analysis.
	 *
	 * @return array Product analysis data.
	 */
	public static function analyze_product( $product, $days_of_stock_target, $lead_time, $service_level, $use_seasonal ) {

		$product_id    = $product->get_id();
		$current_stock = (int) $product->get_stock_quantity();

		// Get inbound stock (already ordered, waiting to arrive).
		$inbound_stock = self::get_inbound_stock( $product );

		// Effective stock = what we have + what's coming.
		$effective_stock = $current_stock + $inbound_stock;

		// Get average daily sales.
		$avg_daily_sales = self::get_average_daily_sales( $product_id, $use_seasonal );

		// Calculate safety stock using statistical formula.
		$safety_stock = self::calculate_safety_stock( $product_id, $lead_time, $service_level );

		// Calculate reorder point: (Avg Daily Sales × Lead Time) + Safety Stock.
		$reorder_point = ceil( ( $avg_daily_sales * $lead_time ) + $safety_stock );

		// Calculate optimal stock level (for full order cycle).
		$optimal_stock = ceil( $avg_daily_sales * $days_of_stock_target ) + $safety_stock;

		// Calculate days of stock remaining (based on effective stock).
		$days_remaining = $avg_daily_sales > 0 ? floor( $effective_stock / $avg_daily_sales ) : 999;

		// Determine if reorder is needed when effective stock falls to or below reorder point.
		$needs_reorder = $effective_stock <= $reorder_point && $avg_daily_sales > 0;

		// Calculate suggested quantity to bring stock up to optimal level.
		$suggested_qty = $needs_reorder ? max( 1, $optimal_stock - $effective_stock ) : 0;

		return array(
			'product_id'       => $product_id,
			'product_name'     => $product->get_name(),
			'sku'              => $product->get_sku(),
			'current_stock'    => $current_stock,
			'inbound_stock'    => $inbound_stock,
			'effective_stock'  => $effective_stock,
			'avg_daily_sales'  => round( $avg_daily_sales, 2 ),
			'safety_stock'     => $safety_stock,
			'reorder_point'    => $reorder_point,
			'optimal_stock'    => $optimal_stock,
			'days_remaining'   => $days_remaining,
			'suggested_qty'    => $suggested_qty,
			'needs_reorder'    => $needs_reorder,
			'purchase_price'   => self::get_purchase_price( $product ),
		);

	}

	/**
	 * Get inbound stock for a product (from pending POs)
	 *
	 * @since 1.0.0
	 *
	 * @param \WC_Product $product Product object.
	 *
	 * @return int Inbound stock quantity.
	 */
	public static function get_inbound_stock( $product ) {

		// Use ATUM's helper if available.
		if ( class_exists( '\Atum\Inc\Helpers' ) && method_exists( '\Atum\Inc\Helpers', 'get_product_inbound_stock' ) ) {
			return (int) \Atum\Inc\Helpers::get_product_inbound_stock( $product );
		}

		// Fallback: check if product has the method directly.
		if ( method_exists( $product, 'get_inbound_stock' ) ) {
			$inbound = $product->get_inbound_stock();
			return ! is_null( $inbound ) ? (int) $inbound : 0;
		}

		return 0;

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

		// Get total sales and first sale date for this product.
		$sales_data = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM(oim.meta_value) as total_sales, MIN(p.post_date) as first_sale_date
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

		$total_sales = (float) ( $sales_data->total_sales ?? 0 );

		// Calculate days since first sale (minimum 1, maximum 365).
		$days_of_history = 365;
		if ( ! empty( $sales_data->first_sale_date ) ) {
			$first_sale      = strtotime( $sales_data->first_sale_date );
			$days_of_history = max( 1, min( 365, ceil( ( time() - $first_sale ) / DAY_IN_SECONDS ) ) );
		}

		// Use actual sales period, not always 365 days.
		$avg_daily = $total_sales / $days_of_history;

		// Store original for combined adjustment cap.
		$original_avg = $avg_daily;

		// Apply trend adjustment for growing/declining sales.
		$avg_daily = self::apply_trend_adjustment( $product_id, $avg_daily, $days_of_history );

		if ( $use_seasonal ) {
			$avg_daily = self::apply_seasonal_adjustment( $product_id, $avg_daily );
		}

		// Cap combined adjustment to prevent extreme values (0.4x to 2.5x).
		if ( $original_avg > 0 ) {
			$min_avg = $original_avg * 0.4;
			$max_avg = $original_avg * 2.5;
			$avg_daily = max( $min_avg, min( $max_avg, $avg_daily ) );
		}

		return $avg_daily;

	}

	/**
	 * Get demand standard deviation for a product
	 *
	 * Calculates the standard deviation of daily sales over the last 90 days.
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return float Standard deviation of daily sales.
	 */
	public static function get_demand_standard_deviation( $product_id ) {

		global $wpdb;

		// Get daily sales for the last 90 days.
		$days_ago = date( 'Y-m-d', strtotime( '-90 days' ) );

		// First, find the first sale date within the period.
		$first_sale_in_period = $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(p.post_date)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = p.ID
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oim2.order_item_id = oi.order_item_id
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-completed', 'wc-processing')
			AND p.post_date >= %s
			AND oi.order_item_type = 'line_item'
			AND oim2.meta_key IN ('_product_id', '_variation_id')
			AND oim2.meta_value = %d",
			$days_ago,
			$product_id
		) );

		// Calculate actual days of history (minimum 7 days for meaningful std dev).
		$days_of_history = 90;
		if ( ! empty( $first_sale_in_period ) ) {
			$first_sale      = strtotime( $first_sale_in_period );
			$days_of_history = max( 7, min( 90, ceil( ( time() - $first_sale ) / DAY_IN_SECONDS ) ) );
		}

		$daily_sales = $wpdb->get_col( $wpdb->prepare(
			"SELECT COALESCE(SUM(oim.meta_value), 0) as daily_qty
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_id = p.ID
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_qty'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oim2.order_item_id = oi.order_item_id AND oim2.meta_key IN ('_product_id', '_variation_id') AND oim2.meta_value = %d
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-completed', 'wc-processing')
			AND p.post_date >= %s
			AND oi.order_item_type = 'line_item'
			AND oim2.meta_value IS NOT NULL
			GROUP BY DATE(p.post_date)",
			$product_id,
			$days_ago
		) );

		if ( empty( $daily_sales ) ) {
			return 0;
		}

		// Convert to floats.
		$daily_sales = array_map( 'floatval', $daily_sales );

		// Pad with zeros for days with no sales (use actual history period).
		$count = count( $daily_sales );
		if ( $count < $days_of_history ) {
			$daily_sales = array_merge( $daily_sales, array_fill( 0, $days_of_history - $count, 0 ) );
		}

		// Calculate mean.
		$mean = array_sum( $daily_sales ) / count( $daily_sales );

		// Calculate variance.
		$squared_diff_sum = 0;
		foreach ( $daily_sales as $value ) {
			$squared_diff_sum += pow( $value - $mean, 2 );
		}
		$variance = $squared_diff_sum / count( $daily_sales );

		// Standard deviation is square root of variance.
		return sqrt( $variance );

	}

	/**
	 * Calculate safety stock for a product
	 *
	 * Uses the formula: Safety Stock = Z × σ(Demand) × √Lead Time
	 * Where Z is the z-score for the desired service level.
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id   Product ID.
	 * @param int $lead_time    Lead time in days.
	 * @param int $service_level Service level percentage (90, 95, or 99).
	 *
	 * @return int Safety stock quantity.
	 */
	public static function calculate_safety_stock( $product_id, $lead_time, $service_level = 95 ) {

		// Z-scores for common service levels.
		$z_scores = array(
			'90' => 1.28,
			'95' => 1.65,
			'99' => 2.33,
		);

		$z = isset( $z_scores[ $service_level ] ) ? $z_scores[ $service_level ] : $z_scores['95'];

		// Get demand standard deviation.
		$std_dev = self::get_demand_standard_deviation( $product_id );

		// Safety Stock = Z × σ × √Lead Time.
		$safety_stock = $z * $std_dev * sqrt( max( 1, $lead_time ) );

		return (int) ceil( $safety_stock );

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
	 * Apply trend adjustment for growing or declining sales
	 *
	 * Compares recent sales (last 30 days) to overall average and adjusts
	 * the forecast to better capture sales trends.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $product_id      Product ID.
	 * @param float $avg_daily       Current average daily sales.
	 * @param int   $days_of_history Number of days of sales history available.
	 *
	 * @return float Trend-adjusted average daily sales.
	 */
	public static function apply_trend_adjustment( $product_id, $avg_daily, $days_of_history ) {

		// Need at least 30 days of history to calculate trend.
		if ( $days_of_history < 30 ) {
			return $avg_daily;
		}

		global $wpdb;

		// Get sales for the last 30 days.
		$thirty_days_ago = date( 'Y-m-d', strtotime( '-30 days' ) );

		$recent_sales = $wpdb->get_var( $wpdb->prepare(
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
			$thirty_days_ago,
			$product_id
		) );

		$recent_sales = (float) $recent_sales;
		$recent_avg   = $recent_sales / 30;

		// If no recent sales or no historical average, return original.
		if ( $recent_avg <= 0 || $avg_daily <= 0 ) {
			return $avg_daily;
		}

		// Weight recent sales higher: 70% recent, 30% historical.
		$weighted_avg = ( $avg_daily * 0.3 ) + ( $recent_avg * 0.7 );

		// Clamp the adjustment to avoid extreme swings (0.5x to 2.0x of original).
		$min_avg = $avg_daily * 0.5;
		$max_avg = $avg_daily * 2.0;

		return max( $min_avg, min( $max_avg, $weighted_avg ) );

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
