<?php
/**
 * Purchase Order Suggestion Generator
 *
 * Creates draft Purchase Orders based on the analysis from POSuggestionAlgorithm.
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
use Atum\PurchaseOrders\Models\PurchaseOrder;
use Atum\Suppliers\Supplier;
use Atum\Suppliers\Suppliers;

class POSuggestionGenerator {

	/**
	 * The singleton instance holder
	 *
	 * @var POSuggestionGenerator
	 */
	private static $instance;

	/**
	 * Cron hook name
	 */
	const CRON_HOOK = 'sae_daily_po_suggestions';

	/**
	 * POSuggestionGenerator constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Register AJAX handler for manual trigger.
		add_action( 'wp_ajax_sae_generate_po_suggestions', array( $this, 'ajax_generate_suggestions' ) );

		// Register AJAX handler for executing user's PO choice.
		add_action( 'wp_ajax_sae_execute_po_choice', array( $this, 'ajax_execute_po_choice' ) );

		// Register cron hook.
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_generation' ) );

		// Re-schedule cron when settings change.
		add_action( 'update_option_atum_settings', array( $this, 'maybe_reschedule_cron' ) );

		// Register custom cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );

		// Register AJAX handler for restock-only update.
		add_action( 'wp_ajax_sae_update_restock_only', array( $this, 'ajax_update_restock_only' ) );

		// Override ATUM's restock_status calculation.
		add_filter( 'atum/is_product_restock_status', array( $this, 'override_atum_restock' ), 10, 2 );

		// Add button to Stock Central page.
		add_action( 'atum/stock_central_list/page_title_buttons', array( $this, 'render_restock_button' ) );

		// Add inline script for the button.
		add_action( 'admin_footer', array( $this, 'add_restock_button_script' ) );

	}

	/**
	 * AJAX handler for generating PO suggestions
	 *
	 * @since 1.0.0
	 */
	public function ajax_generate_suggestions() {

		// Check nonce.
		if ( ! check_ajax_referer( 'sae_generate_po_suggestions', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to generate PO suggestions.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Log start time and memory usage.
		$start_time   = microtime( true );
		$start_memory = memory_get_usage();
		error_log( sprintf(
			'SAE: Starting PO generation. Memory: %s MB, Time: %s',
			round( $start_memory / 1024 / 1024, 2 ),
			current_time( 'mysql' )
		) );

		try {
			$result = $this->generate_suggestions();

			if ( is_wp_error( $result ) ) {
				error_log( 'SAE: PO generation returned WP_Error: ' . $result->get_error_message() );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			// Log completion.
			$end_time   = microtime( true );
			$end_memory = memory_get_usage();
			$duration   = round( $end_time - $start_time, 2 );
			$memory_used = round( ( $end_memory - $start_memory ) / 1024 / 1024, 2 );

			error_log( sprintf(
				'SAE: PO generation completed successfully. Duration: %s seconds, Memory used: %s MB, Created: %d POs',
				$duration,
				$memory_used,
				count( $result['created'] ?? array() )
			) );

			wp_send_json_success( $result );

		} catch ( Exception $e ) {
			// Log the exception.
			error_log( sprintf(
				'SAE: EXCEPTION in PO generation: %s in %s:%d. Trace: %s',
				$e->getMessage(),
				$e->getFile(),
				$e->getLine(),
				$e->getTraceAsString()
			) );

			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'An error occurred: %s', 'serenisoft-atum-enhancer' ),
					$e->getMessage()
				),
			) );
		}

	}

	/**
	 * Generate PO suggestions for all suppliers
	 *
	 * @since 1.0.0
	 *
	 * @param bool $create_pos Whether to create POs (true) or just update restock status (false).
	 *
	 * @return array|WP_Error Result array or error.
	 */
	public function generate_suggestions( $create_pos = true ) {

		$created_pos            = array();
		$skipped                = 0;
		$errors                 = array();
		$choices                = array(); // NEW: Track suppliers needing user choice
		$total_products         = 0;
		$products_below_reorder = 0;

		// If restock-only mode, reset all products first.
		if ( ! $create_pos ) {
			$this->reset_all_restock_status();
		}

		// Check if dry run mode is enabled.
		$dry_run = 'yes' === \Atum\Inc\Helpers::get_option( 'sae_enable_dry_run', 'no' );

		if ( $dry_run ) {
			error_log( 'SAE: Running in DRY RUN mode - no POs will be created' );
		}

		// Get all suppliers.
		$suppliers = $this->get_all_suppliers();

		if ( empty( $suppliers ) ) {
			return array(
				'created'                => array(),
				'skipped'                => 0,
				'errors'                 => array(),
				'total_suppliers'        => 0,
				'total_products'         => 0,
				'products_below_reorder' => 0,
				'products_without_supplier' => $this->get_products_without_supplier(),
				'message'                => __( 'No suppliers found.', 'serenisoft-atum-enhancer' ),
			);
		}

		error_log( sprintf( 'SAE: Processing %d suppliers', count( $suppliers ) ) );

		foreach ( $suppliers as $supplier_id ) {

			// Validate that the supplier post actually exists and is published.
			$post = get_post( $supplier_id );

			if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== Suppliers::POST_TYPE ) {
				continue;
			}

			$supplier_start = microtime( true );

			// Get supplier name from post title directly (safer than ATUM's get_name())
			$supplier_name = $post->post_title;

			// If supplier name is empty, skip this supplier.
			if ( empty( $supplier_name ) ) {
				continue;
			}

			try {
				$supplier = new Supplier( $supplier_id );
			} catch ( Exception $e ) {
				error_log( sprintf( 'SAE: Failed to create Supplier object for #%d: %s', $supplier_id, $e->getMessage() ) );
				continue;
			}
			error_log( sprintf( 'SAE: Processing supplier #%d (%s)', $supplier_id, $supplier_name ) );

			// Debug logging: Supplier header
			if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
				error_log( sprintf( 'SAE DEBUG: === Processing Supplier #%d: %s ===', $supplier_id, $supplier_name ) );
				error_log( '' ); // Empty line for readability
			}

			// Get existing POs for this supplier.
			error_log( sprintf( 'SAE: Checking for existing POs for supplier #%d...', $supplier_id ) );
			$existing_pos = $this->get_existing_pos_for_supplier( $supplier_id );

			// Get product IDs already in existing POs.
			$existing_po_product_ids = array();
			if ( ! empty( $existing_pos ) ) {
				$po_ids                  = array_column( $existing_pos, 'id' );
				$existing_po_product_ids = $this->get_product_ids_from_pos( $po_ids );
				error_log( sprintf(
					'SAE: Supplier #%d has %d existing PO(s) with %d products already ordered',
					$supplier_id,
					count( $existing_pos ),
					count( $existing_po_product_ids )
				) );
			}

			// Get all products for this supplier first to log count.
			error_log( sprintf( 'SAE: Getting products for supplier #%d...', $supplier_id ) );
			$all_supplier_products = Suppliers::get_supplier_products( $supplier_id );
			$product_count         = count( $all_supplier_products );
			error_log( sprintf( 'SAE: Supplier #%d (%s) has %d products - analyzing reorder needs', $supplier_id, $supplier_name, $product_count ) );

			// Two-pass approach: Only include predictive products if supplier has urgent products
			// Check if predictive ordering is enabled
			$predictive_enabled = 'yes' === Settings::get( 'sae_enable_predictive_ordering', 'yes' );

			// PASS 1: Check for urgent products (original logic)
			$urgent_products = POSuggestionAlgorithm::get_products_needing_reorder( $supplier_id, false );

			// If no urgent products, skip this supplier entirely
			if ( empty( $urgent_products ) ) {
				error_log( sprintf( 'SAE: Supplier #%d (%s) - no urgent products, skipping', $supplier_id, $supplier_name ) );
				continue; // Skip to next supplier
			}

			// PASS 2: If predictive is enabled, re-run with enhanced logic
			if ( $predictive_enabled ) {
				$all_products_to_reorder = POSuggestionAlgorithm::get_products_needing_reorder( $supplier_id, true );
				error_log( sprintf( 'SAE: Supplier #%d (%s) - Pass 1: %d urgent, Pass 2: %d total products',
					$supplier_id, $supplier_name, count( $urgent_products ), count( $all_products_to_reorder ) ) );
			} else {
				// Predictive disabled, use urgent products only
				$all_products_to_reorder = $urgent_products;
				error_log( sprintf( 'SAE: Supplier #%d (%s) - %d urgent products (predictive disabled)',
					$supplier_id, $supplier_name, count( $urgent_products ) ) );
			}

			// Filter out products already in existing POs.
			$products_to_reorder = array_filter(
				$all_products_to_reorder,
				function ( $product ) use ( $existing_po_product_ids ) {
					return ! in_array( $product['product_id'], $existing_po_product_ids, true );
				}
			);

			$analysis_duration = round( microtime( true ) - $supplier_start, 2 );
			error_log( sprintf(
				'SAE: Supplier #%d (%s) analysis completed in %s seconds - %d products need reorder (after filtering)',
				$supplier_id,
				$supplier_name,
				$analysis_duration,
				count( $products_to_reorder )
			) );

			// Debug logging: Supplier summary
			if ( 'yes' === Settings::get( 'sae_enable_debug_logging', 'no' ) ) {
				error_log( sprintf( 'SAE DEBUG: Supplier #%d: %d products need reordering', $supplier_id, count( $products_to_reorder ) ) );
				error_log( '' ); // Empty line for readability
			}

			$total_products       += $product_count;
			$products_below_reorder += count( $all_products_to_reorder ); // Count all, not just filtered

			// Update restock meta for all products needing reorder.
			foreach ( $all_products_to_reorder as $product_data ) {
				$this->update_product_restock_meta( $product_data );
			}

			// If no products left to order after filtering, skip.
			if ( empty( $products_to_reorder ) ) {
				error_log( sprintf( 'SAE: Supplier #%d (%s) - no products need reordering (all already in existing POs), skipping', $supplier_id, $supplier_name ) );
				continue;
			}

			// If existing POs found, add to choices array for user selection.
			if ( ! empty( $existing_pos ) ) {
				error_log( sprintf( 'SAE: Supplier #%d (%s) has existing POs - adding to choices', $supplier_id, $supplier_name ) );
				$choices[] = array(
					'supplier_id'    => $supplier_id,
					'supplier_name'  => $supplier_name,
					'products'       => $products_to_reorder,
					'existing_pos'   => $existing_pos,
					'product_count'  => count( $products_to_reorder ),
				);
				continue; // Don't auto-create, wait for user choice
			}

			// No existing POs - create new (or simulate in dry run mode).
			if ( ! $dry_run && $create_pos ) {
				error_log( sprintf( 'SAE: Creating PO for supplier #%d (%s) with %d products', $supplier_id, $supplier_name, count( $products_to_reorder ) ) );
				$result = $this->create_po_for_supplier( $supplier_id, $products_to_reorder );

				if ( is_wp_error( $result ) ) {
					error_log( sprintf( 'SAE: Failed to create PO for supplier #%d (%s): %s', $supplier_id, $supplier_name, $result->get_error_message() ) );
					$errors[] = sprintf(
						/* translators: 1: supplier name, 2: error message */
						__( 'Supplier %1$s: %2$s', 'serenisoft-atum-enhancer' ),
						$supplier_name,
						$result->get_error_message()
					);
				} else {
					$supplier_duration = round( microtime( true ) - $supplier_start, 2 );
					error_log( sprintf(
						'SAE: PO created for supplier #%d (%s) - total time: %s seconds',
						$supplier_id,
						$supplier_name,
						$supplier_duration
					) );
					$created_pos[] = $result;
				}
			} elseif ( $dry_run ) {
				error_log( sprintf( 'SAE: DRY RUN - Would create PO for supplier #%d (%s) with %d products', $supplier_id, $supplier_name, count( $products_to_reorder ) ) );
				$result        = $this->simulate_po_creation( $supplier_id, $supplier_name, $products_to_reorder );
				$created_pos[] = $result;
			}
		}

		// Get products without supplier.
		$products_without_supplier = $this->get_products_without_supplier();

		// Send email notification if POs were created (skip in dry run mode).
		if ( ! $dry_run && ! empty( $created_pos ) ) {
			$this->send_notification_email( $created_pos );
		}

		return array(
			'created'                   => $created_pos,
			'skipped'                   => $skipped,
			'errors'                    => $errors,
			'choices_needed'            => $choices, // NEW: Suppliers needing user choice
			'total_suppliers'           => count( $suppliers ),
			'total_products'            => $total_products,
			'products_below_reorder'    => $products_below_reorder,
			'products_without_supplier' => $products_without_supplier,
			'dry_run'                   => $dry_run, // NEW: Indicate dry run mode.
			'message'                   => sprintf(
				/* translators: 1: created count, 2: created/would be created, 3: skipped count, 4: choices count */
				__( '%1$d PO suggestions %2$s, %3$d suppliers skipped, %4$d need your choice.', 'serenisoft-atum-enhancer' ),
				count( $created_pos ),
				$dry_run ? __( 'would be created', 'serenisoft-atum-enhancer' ) : __( 'created', 'serenisoft-atum-enhancer' ),
				$skipped,
				count( $choices )
			),
		);

	}

	/**
	 * Create a Purchase Order for a supplier
	 *
	 * @since 1.0.0
	 *
	 * @param int   $supplier_id         Supplier ID.
	 * @param array $products_to_reorder Array of product analysis data.
	 *
	 * @return array|WP_Error PO data or error.
	 */
	private function create_po_for_supplier( $supplier_id, $products_to_reorder ) {

		error_log( sprintf( 'SAE: create_po_for_supplier - START - supplier_id=%d, products_count=%d', $supplier_id, count( $products_to_reorder ) ) );

		try {
			error_log( 'SAE: create_po_for_supplier - Creating Supplier object' );
			$supplier = new Supplier( $supplier_id );
			error_log( 'SAE: create_po_for_supplier - Supplier object created successfully' );

			// Create new PO.
			error_log( 'SAE: create_po_for_supplier - Creating new PurchaseOrder object' );
			$po = new PurchaseOrder();
			error_log( 'SAE: create_po_for_supplier - PurchaseOrder object created' );

			error_log( sprintf( 'SAE: create_po_for_supplier - Setting supplier: %d', $supplier_id ) );
			$po->set_supplier( $supplier_id );
			error_log( 'SAE: create_po_for_supplier - Setting status: atum_pending' );
			$po->set_status( 'atum_pending' );
			error_log( sprintf( 'SAE: create_po_for_supplier - Setting currency: %s', get_woocommerce_currency() ) );
			$po->set_currency( get_woocommerce_currency() );
			error_log( sprintf( 'SAE: create_po_for_supplier - Setting date_created: %s', current_time( 'mysql' ) ) );
			$po->set_date_created( current_time( 'mysql' ) );

			// Calculate expected date based on lead time.
			// Get lead time from Supplier object (correct way to use ATUM)
			$lead_time = $supplier->lead_time;
			if ( empty( $lead_time ) || ! is_numeric( $lead_time ) ) {
				$lead_time = 14; // Default 2 weeks.
			}
			$expected_date = date( 'Y-m-d H:i:s', strtotime( "+{$lead_time} days" ) );
			error_log( sprintf( 'SAE: create_po_for_supplier - Setting expected_date: %s (lead_time=%d)', $expected_date, $lead_time ) );
			$po->set_date_expected( $expected_date );

			// Save PO first (required before adding items).
			error_log( 'SAE: create_po_for_supplier - Saving PO (first save)' );
			$po->save();

			$po_id = $po->get_id();
			error_log( sprintf( 'SAE: create_po_for_supplier - PO saved, ID: %d', $po_id ) );

			if ( empty( $po_id ) ) {
				error_log( 'SAE: create_po_for_supplier - ERROR: PO ID is empty after save' );
				return new \WP_Error( 'po_create_failed', __( 'Failed to create Purchase Order.', 'serenisoft-atum-enhancer' ) );
			}

			// Add global and supplier PO notes to description (shows on PDF).
			$global_note   = Settings::get( 'sae_global_po_note', '' );
			$supplier_note = SupplierFields::get_po_note( $supplier_id );

			$combined_notes = array_filter( array( $global_note, $supplier_note ) );
			if ( ! empty( $combined_notes ) ) {
				$po->set_description( implode( "\n\n", $combined_notes ) );
				$po->save();
			}

			// Add products to PO.
			error_log( sprintf( 'SAE: create_po_for_supplier - Adding %d products to PO', count( $products_to_reorder ) ) );
			$total = 0;
			$added_count = 0;
			foreach ( $products_to_reorder as $product_data ) {

				error_log( sprintf( 'SAE: create_po_for_supplier - Processing product #%d', $product_data['product_id'] ) );

				// Get ATUM product wrapper (CRITICAL: ATUM's add_product expects ATUM product, not WC product)
				$product = \Atum\Inc\Helpers::get_atum_product( $product_data['product_id'] );

				if ( ! $product ) {
					error_log( sprintf( 'SAE: create_po_for_supplier - WARNING: Could not get ATUM product for #%d', $product_data['product_id'] ) );
					continue;
				}

				$qty   = $product_data['suggested_qty'];
				$price = $product_data['purchase_price'];
				error_log( sprintf( 'SAE: create_po_for_supplier - Adding product #%d, qty=%d, price=%s', $product_data['product_id'], $qty, $price ) );

				// Add product to PO (ATUM automatically handles purchase price via filter)
				$item = $po->add_product( $product, $qty );

				if ( ! $item ) {
					error_log( sprintf( 'SAE: create_po_for_supplier - WARNING: add_product failed for #%d', $product_data['product_id'] ) );
					continue;
				}

				error_log( sprintf( 'SAE: create_po_for_supplier - Product #%d added successfully', $product_data['product_id'] ) );
				$added_count++;

				// Add product PO note as item meta if set.
				$product_note = ProductFields::get_po_note( $product_data['product_id'] );
				if ( ! empty( $product_note ) && $item ) {
					error_log( sprintf( 'SAE: create_po_for_supplier - Adding product note for #%d', $product_data['product_id'] ) );
					$item->add_meta_data( __( 'Note', 'serenisoft-atum-enhancer' ), $product_note );
					$item->save();
				}

				// Calculate total from item's actual total
				$item_total = $item->get_total();
				$total += $item_total;
				error_log( sprintf( 'SAE: create_po_for_supplier - Item total: %s, running total: %s', $item_total, $total ) );
			}

			error_log( sprintf( 'SAE: create_po_for_supplier - Added %d/%d products successfully', $added_count, count( $products_to_reorder ) ) );

			// Save PO with items.
			error_log( 'SAE: create_po_for_supplier - Saving PO (final save with items)' );
			$po->save();
			error_log( 'SAE: create_po_for_supplier - PO saved successfully' );

			$supplier_name = $supplier->name;

			error_log( sprintf( 'SAE: Created PO #%d for supplier %s with %d items', $po_id, $supplier_name, count( $products_to_reorder ) ) );

			// Prepare product details for response.
			$products_list = array();
			foreach ( $products_to_reorder as $product_data ) {
				$product = wc_get_product( $product_data['product_id'] );
				if ( $product ) {
					$products_list[] = array(
						'id'   => $product_data['product_id'],
						'name' => $product->get_name(),
						'sku'  => $product->get_sku(),
						'qty'  => $product_data['suggested_qty'],
					);
				}
			}

			$result = array(
				'po_id'         => $po_id,
				'supplier_id'   => $supplier_id,
				'supplier_name' => $supplier_name,
				'items_count'   => count( $products_to_reorder ),
				'products'      => $products_list,
				'total'         => $total,
				'edit_url'      => admin_url( 'post.php?post=' . $po_id . '&action=edit' ),
				'message'       => sprintf(
					/* translators: 1: number of items, 2: PO ID */
					__( 'Created new PO #%2$d with %1$d items', 'serenisoft-atum-enhancer' ),
					count( $products_to_reorder ),
					$po_id
				),
			);

			error_log( sprintf( 'SAE: create_po_for_supplier - Returning success: PO #%d', $po_id ) );
			return $result;

		} catch ( \Exception $e ) {
			error_log( sprintf( 'SAE: Exception in create_po_for_supplier: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) );
			error_log( sprintf( 'SAE: Exception trace: %s', $e->getTraceAsString() ) );
			return new \WP_Error( 'po_exception', $e->getMessage() );
		}

	}

	/**
	 * Simulate PO creation without saving to database (DRY RUN mode)
	 *
	 * @since 0.6.0
	 *
	 * @param int    $supplier_id         Supplier ID.
	 * @param string $supplier_name       Supplier name.
	 * @param array  $products_to_reorder Array of product analysis data.
	 *
	 * @return array Simulated PO data.
	 */
	private function simulate_po_creation( $supplier_id, $supplier_name, $products_to_reorder ) {

		$products_list = array();
		$total         = 0;

		foreach ( $products_to_reorder as $product_data ) {
			$product = wc_get_product( $product_data['product_id'] );

			if ( ! $product ) {
				continue;
			}

			// Calculate item total (if purchase price available).
			$purchase_price = isset( $product_data['purchase_price'] ) ? $product_data['purchase_price'] : 0;
			$item_total     = $product_data['suggested_qty'] * $purchase_price;
			$total         += $item_total;

			$products_list[] = array(
				'id'             => $product_data['product_id'],
				'name'           => $product->get_name(),
				'sku'            => $product->get_sku() ?: __( 'N/A', 'serenisoft-atum-enhancer' ),
				'qty'            => $product_data['suggested_qty'],
				'purchase_price' => $purchase_price,
				'item_total'     => $item_total,
				'reason'         => $product_data['reorder_reason'] ?? '',
			);
		}

		return array(
			'po_id'         => 0, // No actual PO created.
			'supplier_id'   => $supplier_id,
			'supplier_name' => $supplier_name,
			'items_count'   => count( $products_to_reorder ),
			'products'      => $products_list,
			'total'         => $total,
			'edit_url'      => '', // No URL for dry run.
			'message'       => sprintf(
				/* translators: 1: items count, 2: supplier name */
				__( 'DRY RUN: Would create PO with %1$d items for %2$s', 'serenisoft-atum-enhancer' ),
				count( $products_to_reorder ),
				$supplier_name
			),
		);
	}

	/**
	 * Get all supplier IDs
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of supplier IDs.
	 */
	private function get_all_suppliers() {

		$args = array(
			'post_type'      => Suppliers::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		return get_posts( $args );

	}

	/**
	 * Get products without supplier assigned
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of product data without supplier.
	 */
	private function get_products_without_supplier() {

		global $wpdb;

		// IMPORTANT: Use ATUM's product data table, not wp_postmeta!
		// ATUM stores supplier_id in wp_atum_product_data table, not as post meta.
		$atum_table = $wpdb->prefix . \Atum\Inc\Globals::ATUM_PRODUCT_DATA_TABLE;

		// Get all published products that don't have a supplier assigned.
		// Use ATUM's table with supplier_id column (correct way to check for suppliers).
		$query = "SELECT DISTINCT p.ID, p.post_title
			FROM {$wpdb->posts} p
			LEFT JOIN {$atum_table} apd ON p.ID = apd.product_id
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status = 'publish'
			AND (apd.supplier_id IS NULL OR apd.supplier_id = 0)
			ORDER BY p.post_title ASC";

		$products = $wpdb->get_results( $query );

		$products_without_supplier = array();

		foreach ( $products as $product ) {
			$wc_product = wc_get_product( $product->ID );

			if ( ! $wc_product ) {
				continue;
			}

			$products_without_supplier[] = array(
				'id'    => $product->ID,
				'name'  => $product->post_title,
				'sku'   => $wc_product->get_sku(),
				'stock' => $wc_product->get_stock_quantity(),
			);
		}

		return $products_without_supplier;

	}

	/**
	 * Send email notification about created POs
	 *
	 * @since 1.0.0
	 *
	 * @param array $created_pos Array of created PO data.
	 */
	private function send_notification_email( $created_pos ) {

		$to_email = Settings::get( 'sae_notification_email', get_option( 'admin_email' ) );

		if ( empty( $to_email ) ) {
			return;
		}

		// Build headers.
		$headers = array();

		// From name and email.
		$from_name  = Settings::get( 'sae_notification_from_name', '' );
		$from_email = Settings::get( 'sae_notification_from_email', '' );

		if ( empty( $from_name ) ) {
			$from_name = get_bloginfo( 'name' );
		}

		if ( ! empty( $from_email ) ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
		} elseif ( ! empty( $from_name ) ) {
			// Only set From name, let WordPress handle the email.
			$headers[] = 'From: ' . $from_name;
		}

		// CC.
		$cc_email = Settings::get( 'sae_notification_cc', '' );
		if ( ! empty( $cc_email ) ) {
			$headers[] = 'Cc: ' . $cc_email;
		}

		$subject = sprintf(
			/* translators: %d: number of POs created */
			__( '[%s] %d Purchase Order Suggestions Created', 'serenisoft-atum-enhancer' ),
			get_bloginfo( 'name' ),
			count( $created_pos )
		);

		$message = __( 'The following Purchase Order suggestions have been created:', 'serenisoft-atum-enhancer' ) . "\n\n";

		foreach ( $created_pos as $po_data ) {
			$message .= sprintf(
				/* translators: 1: supplier name, 2: items count, 3: total, 4: edit URL */
				__( '- %1$s: %2$d items, Total: %3$s', 'serenisoft-atum-enhancer' ),
				$po_data['supplier_name'],
				$po_data['items_count'],
				wc_price( $po_data['total'] )
			) . "\n";
			$message .= '  ' . $po_data['edit_url'] . "\n\n";
		}

		$message .= __( 'Please review these suggestions and update the quantities as needed.', 'serenisoft-atum-enhancer' );

		wp_mail( $to_email, $subject, $message, $headers );

	}


	/*******************
	 * Cron methods
	 *******************/

	/**
	 * Run scheduled generation (called by WP Cron)
	 *
	 * @since 1.0.0
	 */
	public function run_scheduled_generation() {

		// Check if auto suggestions are enabled.
		if ( 'yes' !== Settings::get( 'sae_enable_auto_suggestions', 'no' ) ) {
			return;
		}

		// Run the generation.
		$this->generate_suggestions();

	}

	/**
	 * Add custom cron schedules
	 *
	 * @since 0.3.3
	 *
	 * @param array $schedules Existing schedules.
	 *
	 * @return array Modified schedules.
	 */
	public function add_custom_cron_schedules( $schedules ) {

		$schedules['twice_weekly'] = array(
			'interval' => 3.5 * DAY_IN_SECONDS,
			'display'  => __( 'Twice Weekly', 'serenisoft-atum-enhancer' ),
		);

		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'serenisoft-atum-enhancer' ),
		);

		$schedules['monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly', 'serenisoft-atum-enhancer' ),
		);

		return $schedules;

	}

	/**
	 * Schedule the cron job
	 *
	 * @since 1.0.0
	 */
	public function schedule_cron() {

		// Unschedule first to avoid duplicates.
		$this->unschedule_cron();

		// Get settings.
		$frequency    = Settings::get( 'sae_cron_frequency', 'weekly' );
		$day          = (int) Settings::get( 'sae_cron_day', '1' );
		$time_setting = Settings::get( 'sae_cron_time', '06:00' );
		list( $hour, $minute ) = explode( ':', $time_setting );

		// Calculate next run timestamp based on frequency.
		$timestamp = $this->calculate_next_run_time( $frequency, $day, $hour, $minute );

		// Schedule event with appropriate recurrence.
		wp_schedule_event( $timestamp, $frequency, self::CRON_HOOK );

	}

	/**
	 * Calculate next run time based on frequency and day
	 *
	 * @since 0.3.3
	 *
	 * @param string $frequency Frequency (daily, twice_weekly, weekly, monthly).
	 * @param int    $day       Day (1-7 for weekly, 1-28 for monthly).
	 * @param int    $hour      Hour (0-23).
	 * @param int    $minute    Minute (0-59).
	 *
	 * @return int Timestamp for next run.
	 */
	private function calculate_next_run_time( $frequency, $day, $hour, $minute ) {

		$now = time();

		switch ( $frequency ) {
			case 'daily':
				// Run at specified time today or tomorrow.
				$timestamp = strtotime( "today {$hour}:{$minute}" );
				if ( $timestamp < $now ) {
					$timestamp = strtotime( "tomorrow {$hour}:{$minute}" );
				}
				break;

			case 'twice_weekly':
				// Run on Monday and Thursday.
				$current_day = (int) date( 'N' ); // 1 = Monday, 7 = Sunday.
				if ( $current_day < 1 || ( $current_day === 1 && strtotime( "today {$hour}:{$minute}" ) >= $now ) ) {
					// Next Monday.
					$timestamp = strtotime( "next Monday {$hour}:{$minute}" );
				} elseif ( $current_day < 4 || ( $current_day === 4 && strtotime( "today {$hour}:{$minute}" ) >= $now ) ) {
					// Next Thursday.
					$timestamp = strtotime( "next Thursday {$hour}:{$minute}" );
				} else {
					// Next Monday.
					$timestamp = strtotime( "next Monday {$hour}:{$minute}" );
				}
				break;

			case 'weekly':
				// Run on specified day of week (1 = Monday, 7 = Sunday).
				$day_names  = array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' );
				$target_day = $day_names[ $day ] ?? 'Monday';

				$current_day = (int) date( 'N' );
				if ( $current_day === $day && strtotime( "today {$hour}:{$minute}" ) >= $now ) {
					// Today is the day and time hasn't passed yet.
					$timestamp = strtotime( "today {$hour}:{$minute}" );
				} else {
					// Next occurrence of target day.
					$timestamp = strtotime( "next {$target_day} {$hour}:{$minute}" );
				}
				break;

			case 'monthly':
				// Run on specified day of month (1-28).
				$current_day = (int) date( 'j' );
				$current_month = date( 'Y-m' );

				if ( $current_day < $day || ( $current_day === $day && strtotime( "today {$hour}:{$minute}" ) >= $now ) ) {
					// This month.
					$timestamp = strtotime( "{$current_month}-{$day} {$hour}:{$minute}" );
				} else {
					// Next month.
					$next_month = date( 'Y-m', strtotime( '+1 month' ) );
					$timestamp  = strtotime( "{$next_month}-{$day} {$hour}:{$minute}" );
				}
				break;

			default:
				// Default to daily.
				$timestamp = strtotime( "today {$hour}:{$minute}" );
				if ( $timestamp < $now ) {
					$timestamp = strtotime( "tomorrow {$hour}:{$minute}" );
				}
		}

		return $timestamp;

	}

	/**
	 * Unschedule the cron job
	 *
	 * @since 1.0.0
	 */
	public function unschedule_cron() {

		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

	}

	/**
	 * Maybe reschedule cron when settings change
	 *
	 * @since 1.0.0
	 */
	public function maybe_reschedule_cron() {

		if ( 'yes' === Settings::get( 'sae_enable_auto_suggestions', 'no' ) ) {
			$this->schedule_cron();
		} else {
			$this->unschedule_cron();
		}

	}

	/**
	 * Check if cron is scheduled
	 *
	 * @since 1.0.0
	 *
	 * @return bool|int False if not scheduled, timestamp if scheduled.
	 */
	public function is_cron_scheduled() {

		return wp_next_scheduled( self::CRON_HOOK );

	}

	/**
	 * Get existing (not received) POs for a supplier
	 *
	 * @since 1.0.0
	 *
	 * @param int $supplier_id Supplier ID.
	 * @return array Array of PO data (id, title, date, product_count, status).
	 */
	private function get_existing_pos_for_supplier( $supplier_id ) {

		$args = array(
			'post_type'      => \Atum\PurchaseOrders\PurchaseOrders::POST_TYPE,
			'post_status'    => array( 'atum_pending', 'atum_ordered', 'atum_onthewayin' ),
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => '_supplier',
					'value'   => $supplier_id,
					'compare' => '=',
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$po_posts = get_posts( $args );

		if ( empty( $po_posts ) ) {
			return array();
		}

		$existing_pos = array();

		foreach ( $po_posts as $po_post ) {
			$po = new PurchaseOrder( $po_post->ID, true );

			// Get items.
			$items = $po->get_items();

			// Get status label.
			$status_name = wc_get_order_status_name( $po->get_status() );

			$existing_pos[] = array(
				'id'            => $po_post->ID,
				'title'         => $po_post->post_title,
				'date'          => $po_post->post_date,
				'product_count' => count( $items ),
				'status'        => $po->get_status(),
				'status_label'  => $status_name,
			);
		}

		return $existing_pos;

	}

	/**
	 * Get all product IDs from a set of POs
	 *
	 * @since 1.0.0
	 *
	 * @param array $po_ids Array of PO IDs.
	 * @return array Array of product IDs.
	 */
	private function get_product_ids_from_pos( $po_ids ) {

		if ( empty( $po_ids ) ) {
			return array();
		}

		$product_ids = array();

		foreach ( $po_ids as $po_id ) {
			$po = new PurchaseOrder( $po_id, true );

			if ( ! $po ) {
				continue;
			}

			$items = $po->get_items();

			foreach ( $items as $item ) {
				$product_ids[] = $item->get_product_id();
			}
		}

		return array_unique( $product_ids );

	}

	/**
	 * AJAX handler for executing user's PO choice
	 *
	 * @since 1.0.0
	 */
	public function ajax_execute_po_choice() {

		error_log( 'SAE: ajax_execute_po_choice - START' );

		// Check nonce.
		if ( ! check_ajax_referer( 'sae_execute_po_choice', 'nonce', false ) ) {
			error_log( 'SAE: ajax_execute_po_choice - Nonce check FAILED' );
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'serenisoft-atum-enhancer' ) ) );
		}
		error_log( 'SAE: ajax_execute_po_choice - Nonce check OK' );

		// Check permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			error_log( 'SAE: ajax_execute_po_choice - Permission check FAILED' );
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'serenisoft-atum-enhancer' ) ) );
		}
		error_log( 'SAE: ajax_execute_po_choice - Permission check OK' );

		// Check if dry run mode is enabled.
		$dry_run = 'yes' === \Atum\Inc\Helpers::get_option( 'sae_enable_dry_run', 'no' );

		if ( $dry_run ) {
			error_log( 'SAE: ajax_execute_po_choice - DRY RUN mode detected - choices should not be executed in dry run' );
			wp_send_json_error( array( 'message' => __( 'Cannot execute choices in Dry Run mode. Please disable Dry Run Mode first.', 'serenisoft-atum-enhancer' ) ) );
			return;
		}

		// Get choice data.
		$supplier_id    = isset( $_POST['supplier_id'] ) ? absint( $_POST['supplier_id'] ) : 0;
		$action         = isset( $_POST['action_type'] ) ? sanitize_text_field( $_POST['action_type'] ) : '';
		$selected_po_id = isset( $_POST['selected_po_id'] ) ? absint( $_POST['selected_po_id'] ) : 0;
		$products       = isset( $_POST['products'] ) ? json_decode( stripslashes( $_POST['products'] ), true ) : array();

		error_log( sprintf( 'SAE: ajax_execute_po_choice - Data received: supplier_id=%d, action=%s, selected_po_id=%d, products_count=%d', $supplier_id, $action, $selected_po_id, count( $products ) ) );

		if ( ! $supplier_id || ! $action || empty( $products ) ) {
			error_log( 'SAE: ajax_execute_po_choice - Data validation FAILED' );
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'serenisoft-atum-enhancer' ) ) );
		}
		error_log( 'SAE: ajax_execute_po_choice - Data validation OK' );

		try {
			if ( $action === 'add_to_existing' ) {
				error_log( sprintf( 'SAE: ajax_execute_po_choice - Action: add_to_existing PO #%d', $selected_po_id ) );
				if ( ! $selected_po_id ) {
					error_log( 'SAE: ajax_execute_po_choice - No PO selected for add_to_existing' );
					wp_send_json_error( array( 'message' => __( 'No PO selected.', 'serenisoft-atum-enhancer' ) ) );
				}

				$result = $this->add_products_to_existing_po( $selected_po_id, $products );
				error_log( sprintf( 'SAE: ajax_execute_po_choice - add_products_to_existing_po returned: %s', is_wp_error( $result ) ? 'WP_Error' : 'success' ) );
			} else {
				error_log( sprintf( 'SAE: ajax_execute_po_choice - Action: create_new for supplier #%d', $supplier_id ) );
				$result = $this->create_po_for_supplier( $supplier_id, $products );
				error_log( sprintf( 'SAE: ajax_execute_po_choice - create_po_for_supplier returned: %s', is_wp_error( $result ) ? 'WP_Error: ' . $result->get_error_message() : 'success' ) );
			}

			if ( is_wp_error( $result ) ) {
				error_log( sprintf( 'SAE: ajax_execute_po_choice - WP_Error detected: %s', $result->get_error_message() ) );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			error_log( sprintf( 'SAE: ajax_execute_po_choice - SUCCESS - PO #%d', $result['po_id'] ) );
			wp_send_json_success(
				array(
					'po_id'   => $result['po_id'],
					'message' => $result['message'],
				)
			);

		} catch ( Exception $e ) {
			error_log( sprintf( 'SAE: ajax_execute_po_choice - EXCEPTION caught: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

	}

	/**
	 * Add products to an existing Purchase Order
	 *
	 * @since 1.0.0
	 *
	 * @param int   $po_id    PO ID.
	 * @param array $products Array of product data.
	 * @return array|WP_Error Result or error.
	 */
	private function add_products_to_existing_po( $po_id, $products ) {

		try {
			// Load existing PO.
			$po = new PurchaseOrder( $po_id, true );

			if ( ! $po || ! $po->get_id() ) {
				return new \WP_Error( 'invalid_po', __( 'Purchase Order not found.', 'serenisoft-atum-enhancer' ) );
			}

			$added_count   = 0;
			$products_list = array();

			// Add each product.
			foreach ( $products as $product_data ) {
				$product_id = $product_data['product_id'];
				$qty        = $product_data['suggested_qty'];

				// CRITICAL: Use get_atum_product(), NOT wc_get_product().
				$product = \Atum\Inc\Helpers::get_atum_product( $product_id );

				if ( ! $product ) {
					error_log( sprintf( 'SAE: Could not load ATUM product #%d', $product_id ) );
					continue;
				}

				// Add product to PO.
				$item = $po->add_product( $product, $qty );

				if ( ! $item ) {
					error_log( sprintf( 'SAE: Failed to add product #%d to PO #%d', $product_id, $po_id ) );
					continue;
				}

				// Add product note if exists.
				$product_note = ProductFields::get_po_note( $product_id );
				if ( ! empty( $product_note ) ) {
					$item->add_meta_data( __( 'Note', 'serenisoft-atum-enhancer' ), $product_note );
					$item->save();
				}

				// Add to products list for response.
				$products_list[] = array(
					'id'   => $product_id,
					'name' => $product->get_name(),
					'sku'  => $product->get_sku(),
					'qty'  => $qty,
				);

				$added_count++;
			}

			// Save PO after adding all items.
			$po->save();

			// Add system note about the addition.
			$po->add_order_note(
				sprintf(
					/* translators: %d: number of products added */
					__( 'Added %d products via SereniSoft ATUM Enhancer auto-generation.', 'serenisoft-atum-enhancer' ),
					$added_count
				)
			);

			// Get supplier name.
			$supplier_id   = $po->get_supplier();
			$supplier      = new Supplier( $supplier_id );
			$supplier_name = $supplier->exists() ? $supplier->name : __( 'Unknown Supplier', 'serenisoft-atum-enhancer' );

			return array(
				'po_id'         => $po->get_id(),
				'supplier_id'   => $supplier_id,
				'supplier_name' => $supplier_name,
				'items_count'   => $added_count,
				'products'      => $products_list,
				'message'       => sprintf(
					/* translators: 1: number of products added, 2: PO ID */
					__( 'Added %1$d products to existing PO #%2$d', 'serenisoft-atum-enhancer' ),
					$added_count,
					$po->get_id()
				),
			);

		} catch ( Exception $e ) {
			error_log( sprintf( 'SAE: Exception adding to PO #%d: %s', $po_id, $e->getMessage() ) );
			return new \WP_Error( 'exception', $e->getMessage() );
		}

	}


	/*******************
	 * Restock status methods
	 *******************/

	/**
	 * Reset restock status for all products with suppliers
	 *
	 * @since 0.9.24
	 */
	private function reset_all_restock_status() {

		global $wpdb;

		$atum_table = $wpdb->prefix . \Atum\Inc\Globals::ATUM_PRODUCT_DATA_TABLE;

		// Get all products with supplier.
		$product_ids = $wpdb->get_col(
			"SELECT product_id FROM {$atum_table} WHERE supplier_id IS NOT NULL AND supplier_id > 0"
		);

		foreach ( $product_ids as $product_id ) {
			update_post_meta( $product_id, '_sae_needs_reorder', 'no' );
			update_post_meta( $product_id, '_sae_suggested_qty', 0 );

			$atum_product = \Atum\Inc\Helpers::get_atum_product( $product_id );
			if ( $atum_product ) {
				$atum_product->set_restock_status( 'no' );
				$atum_product->save_atum_data();
			}
		}

	}

	/**
	 * Update restock meta for a single product
	 *
	 * @since 0.9.24
	 *
	 * @param array $product_data Product data from algorithm.
	 */
	private function update_product_restock_meta( $product_data ) {

		$product_id = $product_data['product_id'];

		update_post_meta( $product_id, '_sae_needs_reorder', 'yes' );
		update_post_meta( $product_id, '_sae_suggested_qty', $product_data['suggested_qty'] );
		update_post_meta( $product_id, '_sae_restock_updated', current_time( 'timestamp' ) );

		$atum_product = \Atum\Inc\Helpers::get_atum_product( $product_id );
		if ( $atum_product ) {
			$atum_product->set_restock_status( 'yes' );
			$atum_product->save_atum_data();
		}

	}

	/**
	 * Override ATUM's restock_status calculation
	 *
	 * @since 0.9.24
	 *
	 * @param bool        $restock_needed Original ATUM restock status.
	 * @param \WC_Product $product        Product object.
	 *
	 * @return bool SAE's calculated restock status.
	 */
	public function override_atum_restock( $restock_needed, $product ) {

		$sae_value = get_post_meta( $product->get_id(), '_sae_needs_reorder', true );

		if ( '' !== $sae_value ) {
			return 'yes' === $sae_value;
		}

		return $restock_needed;

	}

	/**
	 * AJAX handler for restock-only update
	 *
	 * @since 0.9.24
	 */
	public function ajax_update_restock_only() {

		// Check nonce.
		if ( ! check_ajax_referer( 'sae_update_restock_only', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to update restock status.', 'serenisoft-atum-enhancer' ) ) );
		}

		try {
			$result = $this->generate_suggestions( false );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %d: number of products needing restock */
					__( 'Restock status updated. %d products need restocking.', 'serenisoft-atum-enhancer' ),
					$result['products_below_reorder']
				),
				'products_needing_restock' => $result['products_below_reorder'],
			) );

		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

	}

	/**
	 * Render restock button in Stock Central
	 *
	 * @since 0.9.24
	 */
	public function render_restock_button() {
		?>
		<button id="sae-update-restock-status" class="page-title-action" style="margin-left: 10px;">
			<?php esc_html_e( 'Update Restock Status (SAE)', 'serenisoft-atum-enhancer' ); ?>
		</button>
		<span id="sae-restock-status-spinner" style="display: none; margin-left: 5px;">
			<span class="spinner" style="visibility: visible; float: none;"></span>
		</span>
		<?php
	}

	/**
	 * Add inline script for restock button
	 *
	 * @since 0.9.24
	 */
	public function add_restock_button_script() {

		$screen = get_current_screen();

		if ( ! $screen || false === strpos( $screen->id, 'atum-stock-central' ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'sae_update_restock_only' );
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#sae-update-restock-status').on('click', function(e) {
				e.preventDefault();

				var $button = $(this);
				var $spinner = $('#sae-restock-status-spinner');

				if ($button.prop('disabled')) {
					return;
				}

				if (!confirm('<?php echo esc_js( __( 'This will update the restock status for all products based on SAE algorithm. Continue?', 'serenisoft-atum-enhancer' ) ); ?>')) {
					return;
				}

				$button.prop('disabled', true);
				$spinner.show();

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sae_update_restock_only',
						nonce: '<?php echo esc_js( $nonce ); ?>'
					},
					success: function(response) {
						$button.prop('disabled', false);
						$spinner.hide();

						if (response.success) {
							alert(response.data.message);
							location.reload();
						} else {
							alert('<?php echo esc_js( __( 'Error:', 'serenisoft-atum-enhancer' ) ); ?> ' + response.data.message);
						}
					},
					error: function(xhr, status, error) {
						$button.prop('disabled', false);
						$spinner.hide();
						alert('<?php echo esc_js( __( 'AJAX Error:', 'serenisoft-atum-enhancer' ) ); ?> ' + error);
					}
				});
			});
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
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cloning is not allowed.', 'serenisoft-atum-enhancer' ), '1.0.0' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Serialization is not allowed.', 'serenisoft-atum-enhancer' ), '1.0.0' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return POSuggestionGenerator instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
