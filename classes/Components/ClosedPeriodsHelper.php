<?php
/**
 * Closed Periods Helper
 *
 * Handles supplier closed periods (holidays, vacations, factory maintenance).
 * Provides date logic for Type A (delivery date adjustment) and Type B (predictive ordering).
 *
 * @package SereniSoft\AtumEnhancer
 * @since   0.9.0
 */

namespace SereniSoft\AtumEnhancer\Components;

defined( 'ABSPATH' ) || exit;

/**
 * ClosedPeriodsHelper class
 */
class ClosedPeriodsHelper {

	/**
	 * Get global closed period presets from settings
	 *
	 * @return array Array of period objects.
	 */
	public static function get_global_presets() {
		// Presets are stored in a separate WordPress option (not ATUM settings).
		$presets = get_option( 'sae_global_closed_periods', array() );

		return is_array( $presets ) ? $presets : array();
	}

	/**
	 * Get all closed periods for a supplier (global presets + custom periods)
	 *
	 * Converts DD-MM format to timestamps for easy comparison.
	 *
	 * @param int $supplier_id Supplier ID.
	 * @return array Array of normalized period objects with timestamps.
	 */
	public static function get_supplier_closed_periods( $supplier_id ) {
		$periods = array();

		// Get supplier's selected presets and custom periods.
		$supplier_periods = get_post_meta( $supplier_id, '_sae_closed_periods', true );
		if ( ! is_array( $supplier_periods ) ) {
			return array();
		}

		$global_presets = self::get_global_presets();

		// Add selected global presets.
		if ( ! empty( $supplier_periods['presets'] ) && is_array( $supplier_periods['presets'] ) ) {
			foreach ( $supplier_periods['presets'] as $preset_id ) {
				foreach ( $global_presets as $preset ) {
					if ( isset( $preset['id'] ) && $preset['id'] === $preset_id ) {
						$periods[] = $preset;
						break;
					}
				}
			}
		}

		// Add custom periods.
		if ( ! empty( $supplier_periods['custom'] ) && is_array( $supplier_periods['custom'] ) ) {
			foreach ( $supplier_periods['custom'] as $custom ) {
				if ( ! empty( $custom['start_date'] ) && ! empty( $custom['end_date'] ) ) {
					$periods[] = $custom;
				}
			}
		}

		// Normalize periods - convert DD-MM to timestamps for current and next year.
		$normalized = array();
		$current_year = (int) gmdate( 'Y' );

		foreach ( $periods as $period ) {
			if ( empty( $period['start_date'] ) || empty( $period['end_date'] ) ) {
				continue;
			}

			// Convert DD-MM to MM-DD for PHP date functions.
			$start_mm_dd = self::dd_mm_to_mm_dd( $period['start_date'] );
			$end_mm_dd   = self::dd_mm_to_mm_dd( $period['end_date'] );

			if ( ! $start_mm_dd || ! $end_mm_dd ) {
				continue;
			}

			// Create timestamps for current year.
			$start_this_year = strtotime( $current_year . '-' . $start_mm_dd );
			$end_this_year   = strtotime( $current_year . '-' . $end_mm_dd );

			// Handle year-crossing periods (e.g., 20-12 to 05-01).
			if ( $start_mm_dd > $end_mm_dd ) {
				// Period crosses year boundary.
				// End date is in the next year.
				$end_this_year = strtotime( ( $current_year + 1 ) . '-' . $end_mm_dd );
			}

			$normalized[] = array(
				'id'            => $period['id'] ?? '',
				'name'          => $period['name'] ?? __( 'Closed Period', 'serenisoft-atum-enhancer' ),
				'start_date'    => $period['start_date'],
				'end_date'      => $period['end_date'],
				'closure_start' => $start_this_year,
				'closure_end'   => $end_this_year,
				'crosses_year'  => $start_mm_dd > $end_mm_dd,
			);

			// Also add period for next year (to catch upcoming closures).
			$start_next_year = strtotime( ( $current_year + 1 ) . '-' . $start_mm_dd );
			$end_next_year   = strtotime( ( $current_year + 1 ) . '-' . $end_mm_dd );

			if ( $start_mm_dd > $end_mm_dd ) {
				$end_next_year = strtotime( ( $current_year + 2 ) . '-' . $end_mm_dd );
			}

			$normalized[] = array(
				'id'            => $period['id'] ?? '',
				'name'          => $period['name'] ?? __( 'Closed Period', 'serenisoft-atum-enhancer' ),
				'start_date'    => $period['start_date'],
				'end_date'      => $period['end_date'],
				'closure_start' => $start_next_year,
				'closure_end'   => $end_next_year,
				'crosses_year'  => $start_mm_dd > $end_mm_dd,
			);
		}

		return $normalized;
	}

	/**
	 * Convert DD-MM format to MM-DD format
	 *
	 * @param string $dd_mm Date in DD-MM format (e.g., "01-07" for 1st July).
	 * @return string|false Date in MM-DD format (e.g., "07-01") or false on error.
	 */
	private static function dd_mm_to_mm_dd( $dd_mm ) {
		if ( ! preg_match( '/^(\d{2})-(\d{2})$/', $dd_mm, $matches ) ) {
			return false;
		}

		$day   = $matches[1];
		$month = $matches[2];

		// Validate day and month ranges.
		if ( (int) $day < 1 || (int) $day > 31 || (int) $month < 1 || (int) $month > 12 ) {
			return false;
		}

		return $month . '-' . $day;
	}

	/**
	 * Check if a date (timestamp) falls within any closed period for a supplier
	 *
	 * @param int $supplier_id Supplier ID.
	 * @param int $timestamp   Unix timestamp to check.
	 * @return array|false Period array if date is in closed period, false otherwise.
	 */
	public static function is_date_in_closed_period( $supplier_id, $timestamp ) {
		$periods = self::get_supplier_closed_periods( $supplier_id );

		foreach ( $periods as $period ) {
			// Check if timestamp falls within this period.
			if ( $timestamp >= $period['closure_start'] && $timestamp <= $period['closure_end'] ) {
				return $period;
			}
		}

		return false;
	}

	/**
	 * Get the next open date after a given timestamp
	 *
	 * If the timestamp falls in a closed period, returns the day after the closure ends.
	 * Handles multiple consecutive closures.
	 *
	 * @param int $supplier_id Supplier ID.
	 * @param int $timestamp   Unix timestamp.
	 * @return int Unix timestamp of next open date.
	 */
	public static function get_next_open_date( $supplier_id, $timestamp ) {
		$check_date = $timestamp;
		$max_iterations = 365; // Safety limit to avoid infinite loop.
		$iterations = 0;

		while ( $iterations < $max_iterations ) {
			$period = self::is_date_in_closed_period( $supplier_id, $check_date );

			if ( ! $period ) {
				// Date is not in closed period - this is the next open date.
				return $check_date;
			}

			// Date is in closed period - move to day after closure ends.
			$check_date = $period['closure_end'] + DAY_IN_SECONDS;
			++$iterations;
		}

		// Fallback: return original timestamp if we hit max iterations.
		return $timestamp;
	}

	/**
	 * TYPE A: Get adjusted lead time if delivery would fall in closed period
	 *
	 * Calculates expected delivery date (today + lead_time) and checks if it falls
	 * within a supplier's closed period. If yes, extends lead time to after reopening.
	 *
	 * @param int $supplier_id   Supplier ID.
	 * @param int $base_lead_time Base lead time in days.
	 * @return array ['adjusted_lead_time' => int, 'reason' => string]
	 */
	public static function get_adjusted_lead_time( $supplier_id, $base_lead_time ) {
		$today = current_time( 'timestamp' );
		$expected_delivery = strtotime( "+{$base_lead_time} days", $today );

		$period = self::is_date_in_closed_period( $supplier_id, $expected_delivery );

		if ( ! $period ) {
			// Delivery date is fine - no adjustment needed.
			return array(
				'adjusted_lead_time' => $base_lead_time,
				'reason'             => '',
			);
		}

		// Delivery falls in closed period - adjust to day after closure.
		$next_open_date = self::get_next_open_date( $supplier_id, $expected_delivery );
		$adjusted_days = ceil( ( $next_open_date - $today ) / DAY_IN_SECONDS );

		$reason = sprintf(
			/* translators: %1$s: period name, %2$s: start date, %3$s: end date */
			__( 'Delivery would fall during %1$s (%2$s to %3$s)', 'serenisoft-atum-enhancer' ),
			$period['name'],
			$period['start_date'],
			$period['end_date']
		);

		return array(
			'adjusted_lead_time' => max( $base_lead_time, $adjusted_days ),
			'reason'             => $reason,
		);
	}

	/**
	 * TYPE B: Check if stock will deplete during a closed period (PREDICTIVE)
	 *
	 * This is the CRITICAL function that implements the user's requirement:
	 * "If we're at risk of running out during a closed period, order in time to get goods BEFORE the closure."
	 *
	 * Logic:
	 * 1. Calculate when stock will run out: current_stock / avg_daily_sales
	 * 2. For each upcoming closed period:
	 *    a. Calculate order deadline: closure_start - lead_time
	 *    b. If stockout date > order deadline: WE'RE TOO LATE - trigger order NOW
	 *    c. If stockout date falls DURING closure: trigger order NOW
	 * 3. Calculate extra quantity needed to survive closure + reopening lead time
	 *
	 * @param int   $supplier_id     Supplier ID.
	 * @param int   $current_stock   Current effective stock level.
	 * @param float $avg_daily_sales Average daily sales.
	 * @param int   $lead_time       Lead time in days.
	 * @return array|false ['needs_order' => bool, 'period' => array, 'extra_days' => int] or false.
	 */
	public static function check_closure_depletion( $supplier_id, $current_stock, $avg_daily_sales, $lead_time ) {
		if ( $avg_daily_sales <= 0 ) {
			return false;
		}

		$today = current_time( 'timestamp' );
		$periods = self::get_supplier_closed_periods( $supplier_id );

		// Only check upcoming periods (ignore past periods).
		$upcoming_periods = array_filter(
			$periods,
			function( $period ) use ( $today ) {
				return $period['closure_start'] > $today;
			}
		);

		// Sort by start date (earliest first).
		usort(
			$upcoming_periods,
			function( $a, $b ) {
				return $a['closure_start'] - $b['closure_start'];
			}
		);

		// Calculate when stock will run out.
		$days_until_stockout = $current_stock / $avg_daily_sales;
		$stockout_timestamp = strtotime( "+{$days_until_stockout} days", $today );

		foreach ( $upcoming_periods as $period ) {
			$closure_start = $period['closure_start'];
			$closure_end   = $period['closure_end'];

			// Calculate order deadline: last day we can place order and get it before closure.
			$order_deadline = strtotime( "-{$lead_time} days", $closure_start );

			// SCENARIO 1: Stockout date is AFTER order deadline but BEFORE/DURING closure.
			// This means we can't order in time - we need to order NOW.
			if ( $stockout_timestamp > $order_deadline && $stockout_timestamp < $closure_end ) {
				// Calculate extra days needed.
				// We need stock to last until closure ends + lead time after reopening.
				$days_from_now_to_closure_end = ceil( ( $closure_end - $today ) / DAY_IN_SECONDS );
				$extra_days = $days_from_now_to_closure_end + $lead_time;

				return array(
					'needs_order' => true,
					'period'      => $period,
					'extra_days'  => $extra_days,
				);
			}

			// SCENARIO 2: Stockout date falls DURING closure.
			if ( $stockout_timestamp >= $closure_start && $stockout_timestamp <= $closure_end ) {
				// We'll run out during closure - order NOW.
				$days_from_now_to_closure_end = ceil( ( $closure_end - $today ) / DAY_IN_SECONDS );
				$extra_days = $days_from_now_to_closure_end + $lead_time;

				return array(
					'needs_order' => true,
					'period'      => $period,
					'extra_days'  => $extra_days,
				);
			}

			// If stockout is before order deadline, we're safe for this period.
			// Check next period.
		}

		// No upcoming closures will cause stockouts.
		return false;
	}
}
