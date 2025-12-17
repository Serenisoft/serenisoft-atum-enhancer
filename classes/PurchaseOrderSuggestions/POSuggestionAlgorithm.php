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
use SereniSoft\AtumEnhancer\Suppliers\SupplierFields;
use SereniSoft\AtumEnhancer\Products\ProductFields;
use SereniSoft\AtumEnhancer\Components\ClosedPeriodsHelper;
use Atum\Suppliers\Supplier;
use Atum\Suppliers\Suppliers;

class POSuggestionAlgorithm {

	/**
	 * Get products that need reordering for a specific supplier
	 *
	 * @since 1.0.0
	 *
	 * @param int  $supplier_id    Supplier ID.
	 * @param bool $use_predictive Whether to use predictive ordering logic.
	 *
	 * @return array Array of product data needing reorder.
	 */
	public static function get_products_needing_reorder( $supplier_id, $use_predictive = false ) {

		$products_to_reorder = array();

		// Get all products for this supplier.
		$product_ids = Suppliers::get_supplier_products( $supplier_id );

		if ( empty( $product_ids ) ) {
			return $products_to_reorder;
		}

		// Get settings.
		$use_seasonal         = 'yes' === Settings::get( 'sae_include_seasonal_analysis', 'yes' );
		$default_orders_year  = (int) Settings::get( 'sae_default_orders_per_year', 4 );
		$service_level        = Settings::get( 'sae_service_level', '95' );

		// Check for supplier-specific orders per year override.
		$supplier_orders_year = SupplierFields::get_orders_per_year( $supplier_id );
		$orders_per_year      = $supplier_orders_year ?? $default_orders_year;

		// Calculate days of stock to maintain based on orders per year.
		$days_of_stock_target = ceil( 365 / $orders_per_year );

		// Get supplier lead time (default 14 days if not set).
		// Use Supplier object to get lead_time (correct way to use ATUM)
		$supplier       = new \Atum\Suppliers\Supplier( $supplier_id );
		$base_lead_time = $supplier->lead_time;
		if ( empty( $base_lead_time ) || $base_lead_time < 1 ) {
			$base_lead_time = 14;
		}

		// Keep adjusted lead time separate from base (for Pass 2 calculations).
		$adjusted_lead_time = $base_lead_time;

		// CLOSED PERIODS - TYPE A: Adjust lead time if delivery falls in closed period.
		// Log closed periods configuration for this supplier.
		if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
			$buffer_before = (int) Settings::get( 'sae_closure_buffer_before', 14 );
			$buffer_after  = (int) Settings::get( 'sae_closure_buffer_after', 14 );
			$closed_periods = ClosedPeriodsHelper::get_supplier_closed_periods( $supplier_id );

			if ( ! empty( $closed_periods ) ) {
				error_log( sprintf(
					'SAE DEBUG: [Closed Periods] Supplier #%d | Buffer: %d days before, %d days after | Periods found: %d',
					$supplier_id,
					$buffer_before,
					$buffer_after,
					count( $closed_periods ) / 2 // Divided by 2 because we store current year + next year.
				) );

				// Log each unique period (skip duplicates from next year).
				$logged_ids = array();
				foreach ( $closed_periods as $period ) {
					$period_id = $period['id'] . '_' . $period['start_date'];
					if ( in_array( $period_id, $logged_ids, true ) ) {
						continue;
					}
					$logged_ids[] = $period_id;

					error_log( sprintf(
						'SAE DEBUG: [Closed Periods]   → %s: %s to %s (effective: %s to %s)',
						$period['name'],
						$period['start_date'],
						$period['end_date'],
						gmdate( 'd-M', $period['closure_start'] ),
						gmdate( 'd-M', $period['closure_end'] )
					) );
				}
			}
		}

		$lead_time_adjustment = ClosedPeriodsHelper::get_adjusted_lead_time( $supplier_id, $base_lead_time );
		if ( $lead_time_adjustment['adjusted_lead_time'] > $base_lead_time ) {
			$adjusted_lead_time = $lead_time_adjustment['adjusted_lead_time'];

			if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
				error_log( sprintf(
					'SAE DEBUG: [Closed Period - Type A] Supplier #%d | Lead time: %d → %d days | Reason: %s',
					$supplier_id,
					$base_lead_time,
					$adjusted_lead_time,
					$lead_time_adjustment['reason']
				) );
			}
		}

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}

			$analysis = self::analyze_product( $product, $days_of_stock_target, $base_lead_time, $adjusted_lead_time, $service_level, $use_seasonal, $use_predictive, $supplier_id );

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
	 * @param int         $base_lead_time       Base supplier lead time in days (without closed period adjustment).
	 * @param int         $adjusted_lead_time   Lead time adjusted for closed periods (used for ROP calculation).
	 * @param int         $service_level        Service level percentage (90, 95, 99).
	 * @param bool        $use_seasonal         Whether to use seasonal analysis.
	 * @param bool        $use_predictive       Whether to use predictive ordering logic.
	 * @param int         $supplier_id          Supplier ID (for closed periods check).
	 *
	 * @return array Product analysis data.
	 */
	public static function analyze_product( $product, $days_of_stock_target, $base_lead_time, $adjusted_lead_time, $service_level, $use_seasonal, $use_predictive = false, $supplier_id = 0 ) {

		$product_id    = $product->get_id();
		$current_stock = (int) $product->get_stock_quantity();

		// Get inbound stock (already ordered, waiting to arrive).
		// Use ATUM product wrapper to get inbound stock (correct way to use ATUM)
		$atum_product  = \Atum\Inc\Helpers::get_atum_product( $product_id );
		$inbound_stock = $atum_product ? $atum_product->get_inbound_stock() : 0;
		$inbound_stock = $inbound_stock ?? 0; // Handle null return

		// Effective stock = what we have + what's coming.
		$effective_stock = $current_stock + $inbound_stock;

		// Get average daily sales (use adjusted lead time for seasonal calculations).
		$avg_daily_sales = self::get_average_daily_sales( $product_id, $use_seasonal, $adjusted_lead_time, $days_of_stock_target );

		// Calculate safety stock using statistical formula (use adjusted lead time).
		$safety_stock = self::calculate_safety_stock( $product_id, $adjusted_lead_time, $service_level );

		// Calculate reorder point: (Avg Daily Sales × Lead Time) + Safety Stock.
		// Use adjusted_lead_time to account for closed periods in Pass 1.
		$reorder_point = ceil( ( $avg_daily_sales * $adjusted_lead_time ) + $safety_stock );

		// Calculate optimal stock level (for full order cycle).
		$optimal_stock = ceil( $avg_daily_sales * $days_of_stock_target ) + $safety_stock;

		// Calculate days of stock remaining (based on effective stock).
		$days_remaining = $avg_daily_sales > 0 ? floor( $effective_stock / $avg_daily_sales ) : 999;

		// Get SKU for debug logging (needed early for TYPE B logging)
		$sku = $product->get_sku() ?: 'N/A';

		// CLOSED PERIODS - TYPE B: Check if stock will deplete during closure (PREDICTIVE)
		$closure_check = ClosedPeriodsHelper::check_closure_depletion(
			$supplier_id,
			$effective_stock,
			$avg_daily_sales,
			$adjusted_lead_time
		);

		$needs_closure_order = false;
		$closure_extra_days = 0;

		if ( $closure_check && $closure_check['needs_order'] ) {
			$needs_closure_order = true;
			$closure_extra_days = $closure_check['extra_days'];

			if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
				error_log( sprintf(
					'SAE DEBUG: [Closed Period - Type B] [%s] %s | Stock will deplete during %s (%s to %s) | Extra days: %d',
					$sku,
					$product->get_name(),
					$closure_check['period']['name'],
					date( 'M j', $closure_check['period']['closure_start'] ),
					date( 'M j', $closure_check['period']['closure_end'] ),
					$closure_extra_days
				) );
			}
		}

		// Determine if reorder is needed
		// Basic reorder check (always applies)
		$at_or_below_rop = $effective_stock <= $reorder_point;

		// Debug logging for Pass 1 (basic reorder check with calculations)
		if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
			$reason_text = $at_or_below_rop ? 'REORDER (at/below ROP)' : 'SKIP (above ROP)';

			// Log ROP calculation
			error_log( sprintf(
				'SAE DEBUG: [Pass 1] [%s] %s | ROP Calc: (%.2f avg/day × %d lead days) + %d safety = %d',
				$sku,
				$product->get_name(),
				$avg_daily_sales,
				$adjusted_lead_time,
				$safety_stock,
				$reorder_point
			) );

			// Log order quantity calculation (accounts for lead time consumption)
			$preview_stock_at_arrival = $effective_stock - ( $avg_daily_sales * $adjusted_lead_time );
			$preview_qty              = max( 1, ceil( $optimal_stock - $preview_stock_at_arrival ) );
			error_log( sprintf(
				'SAE DEBUG: [Pass 1] [%s] %s | Order Calc: %d optimal - %.1f stock@arrival (%.1f consumed in %dd) = %d suggested',
				$sku,
				$product->get_name(),
				$optimal_stock,
				$preview_stock_at_arrival,
				$avg_daily_sales * $adjusted_lead_time,
				$adjusted_lead_time,
				$preview_qty
			) );

			// Log decision
			error_log( sprintf(
				'SAE DEBUG: [Pass 1] [%s] %s | Decision: Stock %d vs ROP %d → %s',
				$sku,
				$product->get_name(),
				$effective_stock,
				$reorder_point,
				$reason_text
			) );
		}

		// Predictive logic only applies if enabled
		$within_safety_margin = false;
		$will_reach_rop_soon = false;
		$safety_margin_threshold = $reorder_point; // Default to reorder point
		$predictive_window = 0; // For debug logging

		if ( $use_predictive ) {
			// Get settings
			$safety_margin_percent = (float) Settings::get( 'sae_stock_threshold_percent', 15 );
			$use_time_based = 'yes' === Settings::get( 'sae_use_time_based_prediction', 'yes' );

			// Calculate if within safety margin
			$safety_margin_threshold = $reorder_point * ( 1 + ( $safety_margin_percent / 100 ) );
			$within_safety_margin = $effective_stock <= $safety_margin_threshold;

			// Calculate if will reach ROP within predictive window (if time-based is enabled)
			// IMPORTANT: Use base_lead_time for predictive window calculation, not adjusted_lead_time.
			// This prevents double-counting closed periods (which are already in adjusted_lead_time for Pass 1).
			if ( $use_time_based && $avg_daily_sales > 0 ) {
				$days_until_rop = ( $effective_stock - $reorder_point ) / $avg_daily_sales;

				// Calculate predictive window: 2 × base_lead_time, plus any closed days in that window
				$predictive_window = self::get_predictive_window( $supplier_id, $base_lead_time );

				$will_reach_rop_soon = $days_until_rop <= $predictive_window;
			}
		}

		// Combined logic - include closure check
		$needs_reorder = ( $at_or_below_rop || $within_safety_margin || $will_reach_rop_soon || $needs_closure_order ) && $avg_daily_sales > 0;

		// Calculate suggested quantity to bring stock up to optimal level.
		// Account for stock consumed during lead time - we need optimal stock WHEN ORDER ARRIVES.
		if ( $needs_reorder ) {
			$stock_at_arrival = $effective_stock - ( $avg_daily_sales * $adjusted_lead_time );
			$base_qty         = max( 1, ceil( $optimal_stock - $stock_at_arrival ) );

			// Add extra quantity for closed period buffer
			if ( $needs_closure_order ) {
				$closure_buffer = ceil( $avg_daily_sales * $closure_extra_days );
				$suggested_qty = $base_qty + $closure_buffer;

				if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
					error_log( sprintf(
						'SAE DEBUG: [Closed Period - Type B] [%s] Qty: %d base + %d closure buffer = %d total',
						$sku,
						$base_qty,
						$closure_buffer,
						$suggested_qty
					) );
				}
			} else {
				$suggested_qty = $base_qty;
			}
		} else {
			$suggested_qty = 0;
		}

		// Apply MOQ (Minimum Order Quantity) - round up to MOQ if needed.
		$moq = ProductFields::get_moq( $product_id );
		if ( $moq > 1 && $suggested_qty > 0 && $suggested_qty < $moq ) {
			if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
				error_log( sprintf(
					'SAE DEBUG: [MOQ] [%s] Qty adjusted: %d → %d (MOQ: %d)',
					$sku,
					$suggested_qty,
					$moq,
					$moq
				) );
			}
			$suggested_qty = $moq;
		}

		// Calculate days until ROP for logging (if not already calculated in predictive logic)
		$days_until_rop = $avg_daily_sales > 0 ? ( $effective_stock - $reorder_point ) / $avg_daily_sales : 999;

		// Debug logging for Pass 2 (predictive analysis)
		if ( $use_predictive && 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
			$reasons = array();
			if ( $within_safety_margin ) {
				$reasons[] = 'within safety margin';
			}
			if ( $will_reach_rop_soon ) {
				$reasons[] = 'will reach ROP soon';
			}

			$reason_text = ! empty( $reasons )
				? 'REORDER (' . implode( ', ', $reasons ) . ')'
				: 'SKIP (predictive not triggered)';

			error_log( sprintf(
				'SAE DEBUG: [Pass 2] [%s] %s | Stock: %d | Safety Threshold: %d | Days to ROP: %.1f | Predictive Window: %d (2×%d base) | Suggested Qty: %d | %s',
				$sku,
				$product->get_name(),
				$effective_stock,
				(int) $safety_margin_threshold,
				$days_until_rop,
				$predictive_window,
				$base_lead_time,
				$suggested_qty,
				$reason_text
			) );
		}

		// Add separator line after each product for readability
		if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
			error_log( 'SAE DEBUG: ---' );
		}

		return array(
			'product_id'              => $product_id,
			'product_name'            => $product->get_name(),
			'sku'                     => $product->get_sku(),
			'current_stock'           => $current_stock,
			'inbound_stock'           => $inbound_stock,
			'effective_stock'         => $effective_stock,
			'avg_daily_sales'         => round( $avg_daily_sales, 2 ),
			'safety_stock'            => $safety_stock,
			'reorder_point'           => $reorder_point,
			'optimal_stock'           => $optimal_stock,
			'days_remaining'          => $days_remaining,
			'suggested_qty'           => $suggested_qty,
			'needs_reorder'           => $needs_reorder,
			'purchase_price'          => self::get_purchase_price( $product ),
			// Diagnostic fields for predictive ordering
			'reorder_reason'          => self::get_reorder_reason( $at_or_below_rop, $within_safety_margin, $will_reach_rop_soon, $needs_closure_order ),
			'days_until_rop'          => $avg_daily_sales > 0 ? ( $effective_stock - $reorder_point ) / $avg_daily_sales : 999,
			'safety_margin_threshold' => $safety_margin_threshold,
			// Closed periods fields
			'closure_affected'        => $needs_closure_order,
			'closure_extra_days'      => $closure_extra_days,
			'closure_period'          => $closure_check ? $closure_check['period']['name'] : null,
			// MOQ field
			'moq'                     => $moq,
		);

	}

	/**
	 * Get average daily sales for a product
	 *
	 * @since 1.0.0
	 *
	 * @param int  $product_id           Product ID.
	 * @param bool $use_seasonal         Whether to use seasonal adjustment.
	 * @param int  $lead_time            Supplier lead time in days.
	 * @param int  $days_of_stock_target Target days of stock to maintain.
	 *
	 * @return float Average daily sales.
	 */
	public static function get_average_daily_sales( $product_id, $use_seasonal = true, $lead_time = 14, $days_of_stock_target = 91 ) {

		global $wpdb;

		// Get sales from order items for the last 365 days.
		$year_ago = date( 'Y-m-d', strtotime( '-365 days' ) );

		// Log start of query for this product.
		$query_start = microtime( true );

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

		// Log query duration.
		$query_duration = round( microtime( true ) - $query_start, 3 );

		// Log slow queries (>1 second).
		if ( $query_duration > 1 ) {
			error_log( sprintf(
				'SAE: SLOW QUERY - Product #%d sales query took %s seconds',
				$product_id,
				$query_duration
			) );
		}

		// Log all queries if duration exceeds 0.5 seconds.
		if ( $query_duration > 0.5 ) {
			error_log( sprintf(
				'SAE: Product #%d sales query: %s seconds',
				$product_id,
				$query_duration
			) );
		}

		$total_sales = (float) ( $sales_data->total_sales ?? 0 );

		// Calculate days of history based on product creation date (not first sale).
		// This prevents dormant products with recent first sales from appearing as high-velocity.
		// Example: Product created 2 years ago, sold 4 units last week → 4/365 not 4/7.
		$days_of_history = 365;
		$product         = wc_get_product( $product_id );
		if ( $product ) {
			$date_created = $product->get_date_created();
			if ( $date_created ) {
				$product_age     = ceil( ( time() - $date_created->getTimestamp() ) / DAY_IN_SECONDS );
				$days_of_history = max( 1, min( 365, $product_age ) );
			}
		}

		// Use actual sales period, not always 365 days.
		$avg_daily = $total_sales / $days_of_history;

		// Store original for combined adjustment cap.
		$original_avg = $avg_daily;

		// Apply trend adjustment for growing/declining sales.
		$avg_daily = self::apply_trend_adjustment( $product_id, $avg_daily, $days_of_history );

		// Only apply seasonal adjustment if product has at least 365 days of sales history.
		// New products need a full year of data before seasonal patterns can be reliably identified.
		if ( $use_seasonal && $days_of_history >= 365 ) {
			$avg_daily = self::apply_seasonal_adjustment( $product_id, $avg_daily, $lead_time, $days_of_stock_target );
		} elseif ( $use_seasonal && 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
			$sku = $product ? ( $product->get_sku() ?: 'N/A' ) : 'N/A';
			error_log( sprintf(
				'SAE DEBUG: [Seasonal] [%s] SKIPPED - Only %d days of history (need 365 for seasonal analysis)',
				$sku,
				$days_of_history
			) );
		}

		// Cap combined adjustment to prevent extreme values (0.4x to 10x).
		// 10x cap allows for extreme seasonal variations while providing safety net against data errors.
		if ( $original_avg > 0 ) {
			$min_avg = $original_avg * 0.4;
			$max_avg = $original_avg * 10.0;
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
	 * Calculate predictive window for Pass 2
	 *
	 * Uses 2 × base_lead_time, adjusted for any closed periods within that window.
	 * This prevents double-counting closed periods that are already included in
	 * the adjusted_lead_time used for Pass 1 ROP calculations.
	 *
	 * @since 0.9.17
	 *
	 * @param int $supplier_id    Supplier ID.
	 * @param int $base_lead_time Base lead time in days (without closed period adjustment).
	 *
	 * @return int Predictive window in days.
	 */
	private static function get_predictive_window( $supplier_id, $base_lead_time ) {
		// Start with 2 × base lead time.
		$window = 2 * $base_lead_time;

		// Check for closed periods within this window.
		$today      = current_time( 'timestamp' );
		$window_end = strtotime( "+{$window} days", $today );

		$closed_days = ClosedPeriodsHelper::count_closed_days_in_range( $supplier_id, $today, $window_end );

		// Add closed days to the window.
		return $window + $closed_days;
	}

	/**
	 * Determine the reason why a product needs reordering
	 *
	 * @since 1.0.0
	 *
	 * @param bool $at_or_below_rop      At or below reorder point.
	 * @param bool $within_safety_margin Within safety margin threshold.
	 * @param bool $will_reach_rop_soon  Will reach ROP within lead time.
	 * @param bool $needs_closure_order  Stock will deplete during supplier closure.
	 *
	 * @return string Reason code.
	 */
	private static function get_reorder_reason( $at_or_below_rop, $within_safety_margin, $will_reach_rop_soon, $needs_closure_order = false ) {

		if ( $at_or_below_rop ) {
			return 'at_rop';
		}

		if ( $needs_closure_order ) {
			return 'closure_period';
		}

		if ( $within_safety_margin ) {
			return 'safety_margin';
		}

		if ( $will_reach_rop_soon ) {
			return 'predictive';
		}

		return 'unknown';

	}

	/**
	 * Validate that seasonal pattern is consistent across years (not just trend)
	 *
	 * Compares monthly sales patterns between years using Pearson correlation.
	 * Only applies seasonal adjustment if the pattern is consistent (correlation >= 0.6).
	 *
	 * @since 0.9.19
	 *
	 * @param int $product_id Product ID.
	 * @return array ['is_valid' => bool, 'correlation' => float, 'years_compared' => int, 'reason' => string]
	 */
	private static function validate_seasonal_pattern( $product_id ) {

		global $wpdb;

		// Get monthly sales grouped by year AND month.
		$yearly_monthly = $wpdb->get_results( $wpdb->prepare(
			"SELECT YEAR(p.post_date) as year, MONTH(p.post_date) as month,
					SUM(oim.meta_value) as sales
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
			)
			GROUP BY YEAR(p.post_date), MONTH(p.post_date)
			ORDER BY year, month",
			$product_id
		) );

		// Organize data by year.
		$years_data = array();
		foreach ( $yearly_monthly as $row ) {
			$years_data[ $row->year ][ $row->month ] = (float) $row->sales;
		}

		// Need at least 2 years with meaningful data.
		$valid_years = array();
		foreach ( $years_data as $year => $months ) {
			$year_total = array_sum( $months );
			if ( $year_total >= 12 && count( $months ) >= 6 ) {
				$valid_years[ $year ] = $months;
			}
		}

		if ( count( $valid_years ) < 2 ) {
			return array(
				'is_valid'       => false,
				'correlation'    => 0,
				'years_compared' => count( $valid_years ),
				'reason'         => __( 'Not enough yearly data (need 2+ years with 6+ months each)', 'serenisoft-atum-enhancer' ),
			);
		}

		// Calculate monthly percentages for each year.
		$patterns = array();
		foreach ( $valid_years as $year => $months ) {
			$year_total = array_sum( $months );
			$pattern    = array();
			for ( $m = 1; $m <= 12; $m++ ) {
				$pattern[ $m ] = isset( $months[ $m ] ) ? ( $months[ $m ] / $year_total ) : 0;
			}
			$patterns[ $year ] = $pattern;
		}

		// Calculate correlation between consecutive years.
		$years        = array_keys( $patterns );
		$correlations = array();

		for ( $i = 0; $i < count( $years ) - 1; $i++ ) {
			$year1          = $patterns[ $years[ $i ] ];
			$year2          = $patterns[ $years[ $i + 1 ] ];
			$correlations[] = self::calculate_pattern_correlation( $year1, $year2 );
		}

		$avg_correlation = count( $correlations ) > 0 ? array_sum( $correlations ) / count( $correlations ) : 0;

		// Threshold: 0.6 = moderate correlation indicates consistent pattern.
		$threshold = 0.6;

		return array(
			'is_valid'       => $avg_correlation >= $threshold,
			'correlation'    => round( $avg_correlation, 2 ),
			'years_compared' => count( $valid_years ),
			'reason'         => $avg_correlation >= $threshold
				? __( 'Pattern consistent across years', 'serenisoft-atum-enhancer' )
				: __( 'Pattern varies between years (likely trend, not seasonality)', 'serenisoft-atum-enhancer' ),
		);

	}

	/**
	 * Calculate Pearson correlation between two monthly patterns
	 *
	 * @since 0.9.19
	 *
	 * @param array $pattern1 Monthly percentages for year 1 (1-12 indexed).
	 * @param array $pattern2 Monthly percentages for year 2 (1-12 indexed).
	 * @return float Correlation coefficient (-1 to 1).
	 */
	private static function calculate_pattern_correlation( $pattern1, $pattern2 ) {

		$n        = 12;
		$sum1     = 0;
		$sum2     = 0;
		$sum1_sq  = 0;
		$sum2_sq  = 0;
		$sum_prod = 0;

		for ( $m = 1; $m <= 12; $m++ ) {
			$v1 = isset( $pattern1[ $m ] ) ? $pattern1[ $m ] : 0;
			$v2 = isset( $pattern2[ $m ] ) ? $pattern2[ $m ] : 0;

			$sum1     += $v1;
			$sum2     += $v2;
			$sum1_sq  += $v1 * $v1;
			$sum2_sq  += $v2 * $v2;
			$sum_prod += $v1 * $v2;
		}

		$numerator   = ( $n * $sum_prod ) - ( $sum1 * $sum2 );
		$denominator = sqrt( ( $n * $sum1_sq - $sum1 * $sum1 ) * ( $n * $sum2_sq - $sum2 * $sum2 ) );

		if ( 0 === $denominator ) {
			return 0;
		}

		return $numerator / $denominator;

	}

	/**
	 * Apply seasonal adjustment to daily sales average
	 *
	 * Uses future-looking seasonal analysis based on when the order will arrive
	 * and which period it needs to cover.
	 *
	 * @since 0.8.0
	 *
	 * @param int   $product_id           Product ID.
	 * @param float $avg_daily            Current average daily sales.
	 * @param int   $lead_time            Supplier lead time in days.
	 * @param int   $days_of_stock_target Target days of stock to maintain.
	 *
	 * @return float Seasonally adjusted average.
	 */
	public static function apply_seasonal_adjustment( $product_id, $avg_daily, $lead_time = 14, $days_of_stock_target = 91 ) {

		global $wpdb;

		// Validate that seasonal pattern is consistent across years (not just trend).
		$validation = self::validate_seasonal_pattern( $product_id );

		if ( ! $validation['is_valid'] ) {
			// Log why seasonal was skipped.
			if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
				$product = wc_get_product( $product_id );
				$sku     = $product ? ( $product->get_sku() ?: 'N/A' ) : 'N/A';
				error_log( sprintf(
					'SAE DEBUG: [Seasonal] [%s] SKIPPED - %s (correlation: %.2f, threshold: 0.60)',
					$sku,
					$validation['reason'],
					$validation['correlation']
				) );
			}
			return $avg_daily;
		}

		// Calculate when the order will arrive.
		$arrival_timestamp = strtotime( "+{$lead_time} days" );

		// Calculate the coverage period (from arrival to next reorder).
		$coverage_start = $arrival_timestamp;
		$coverage_end   = strtotime( "+{$days_of_stock_target} days", $coverage_start );

		// Get historical sales data for each month (all years combined).
		$monthly_sales = $wpdb->get_results( $wpdb->prepare(
			"SELECT MONTH(p.post_date) as month_num, SUM(oim.meta_value) as total_sales
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
			)
			GROUP BY MONTH(p.post_date)",
			$product_id
		), OBJECT_K );

		// Get total sales to calculate baseline.
		$total_sales = array_sum( array_column( $monthly_sales, 'total_sales' ) );

		if ( empty( $total_sales ) || $total_sales < 12 ) {
			// Not enough data for seasonal adjustment.
			return $avg_daily;
		}

		// Calculate which months the coverage period spans and how many days in each.
		$month_coverage = array();
		$current_date   = $coverage_start;

		while ( $current_date < $coverage_end ) {
			$month_num  = (int) date( 'n', $current_date );
			$month_end  = strtotime( 'last day of this month', $current_date );
			$days_in_month = min( $coverage_end, $month_end ) - $current_date;
			$days_in_month = ceil( $days_in_month / DAY_IN_SECONDS );

			if ( ! isset( $month_coverage[ $month_num ] ) ) {
				$month_coverage[ $month_num ] = 0;
			}
			$month_coverage[ $month_num ] += $days_in_month;

			// Move to next month.
			$current_date = strtotime( 'first day of next month', $current_date );
		}

		// Calculate weighted seasonal factor based on coverage months.
		$total_days           = array_sum( $month_coverage );
		$weighted_sales       = 0;
		$expected_sales_ratio = 1 / 12; // Each month should be 8.33% of yearly sales.

		foreach ( $month_coverage as $month_num => $days_covered ) {
			// Get historical sales for this month.
			$month_sales_data = isset( $monthly_sales[ $month_num ] ) ? $monthly_sales[ $month_num ]->total_sales : 0;

			// Calculate this month's percentage of total yearly sales.
			$month_ratio = $total_sales > 0 ? ( $month_sales_data / $total_sales ) : $expected_sales_ratio;

			// Weight by how many days of this month are covered.
			$weight = $days_covered / $total_days;
			$weighted_sales += $month_ratio * $weight;
		}

		// Calculate seasonal factor.
		// If weighted_sales is 0.15 (15% of yearly sales) and expected is 0.0833 (8.33%),
		// then seasonal_factor = 0.15 / 0.0833 = 1.8 (80% higher than average).
		$seasonal_factor = $weighted_sales / $expected_sales_ratio;

		// Clamp between 0.5 and 4.0 to avoid extreme adjustments.
		// No dampening - use raw seasonal factor to capture true seasonal variations.
		$seasonal_factor = max( 0.5, min( 4.0, $seasonal_factor ) );

		$adjusted_avg = $avg_daily * $seasonal_factor;

		// Debug logging for seasonal adjustment
		if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
			$product = wc_get_product( $product_id );
			$sku = $product ? ( $product->get_sku() ?: 'N/A' ) : 'N/A';

			// Log validation result.
			error_log( sprintf(
				'SAE DEBUG: [Seasonal] [%s] VALIDATED - %d years compared, correlation: %.2f (threshold: 0.60)',
				$sku,
				$validation['years_compared'],
				$validation['correlation']
			) );

			$month_names = [ '', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];

			// Build coverage months string
			$coverage_parts = array();
			foreach ( $month_coverage as $month_num => $days ) {
				$coverage_parts[] = sprintf( '%s:%dd', $month_names[ $month_num ], $days );
			}

			// Combined coverage and months line
			error_log( sprintf(
				'SAE DEBUG: [Seasonal] [%s] Coverage: %s to %s (%d days) | Months: %s',
				$sku,
				date( 'M j', $coverage_start ),
				date( 'M j', $coverage_end ),
				$total_days,
				implode( ', ', $coverage_parts )
			) );

			// Log historical sales per month
			$sales_parts = array();
			foreach ( $monthly_sales as $month_num => $data ) {
				$sales_parts[] = sprintf( '%s:%d', $month_names[ $month_num ], (int) $data->total_sales );
			}
			if ( ! empty( $sales_parts ) ) {
				error_log( sprintf(
					'SAE DEBUG: [Seasonal] [%s] Historical sales: %s (total: %d)',
					$sku,
					implode( ', ', $sales_parts ),
					(int) $total_sales
				) );
			}

			error_log( sprintf(
				'SAE DEBUG: [Seasonal] [%s] Factor: %.2fx | Avg: %.2f → %.2f units/day',
				$sku,
				$seasonal_factor,
				$avg_daily,
				$adjusted_avg
			) );
		}

		return $adjusted_avg;

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
