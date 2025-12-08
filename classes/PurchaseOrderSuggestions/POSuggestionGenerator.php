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

		// Register cron hook.
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_generation' ) );

		// Re-schedule cron when settings change.
		add_action( 'update_option_atum_settings', array( $this, 'maybe_reschedule_cron' ) );

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

		$result = $this->generate_suggestions();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );

	}

	/**
	 * Generate PO suggestions for all suppliers
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error Result array or error.
	 */
	public function generate_suggestions() {

		$created_pos = array();
		$skipped     = 0;
		$errors      = array();

		// Get all suppliers.
		$suppliers = $this->get_all_suppliers();

		if ( empty( $suppliers ) ) {
			return array(
				'created'  => array(),
				'skipped'  => 0,
				'errors'   => array(),
				'message'  => __( 'No suppliers found.', 'serenisoft-atum-enhancer' ),
			);
		}

		foreach ( $suppliers as $supplier_id ) {
			// Check if supplier had recent PO.
			if ( POSuggestionAlgorithm::supplier_has_recent_po( $supplier_id ) ) {
				$skipped++;
				continue;
			}

			// Get products needing reorder for this supplier.
			$products_to_reorder = POSuggestionAlgorithm::get_products_needing_reorder( $supplier_id );

			if ( empty( $products_to_reorder ) ) {
				continue;
			}

			// Create PO for this supplier.
			$result = $this->create_po_for_supplier( $supplier_id, $products_to_reorder );

			if ( is_wp_error( $result ) ) {
				$supplier = new Supplier( $supplier_id );
				$errors[] = sprintf(
					/* translators: 1: supplier name, 2: error message */
					__( 'Supplier %1$s: %2$s', 'serenisoft-atum-enhancer' ),
					$supplier->get_name(),
					$result->get_error_message()
				);
			} else {
				$created_pos[] = $result;
			}
		}

		// Send email notification if POs were created.
		if ( ! empty( $created_pos ) ) {
			$this->send_notification_email( $created_pos );
		}

		return array(
			'created'  => $created_pos,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'message'  => sprintf(
				/* translators: 1: created count, 2: skipped count */
				__( '%1$d PO suggestions created, %2$d suppliers skipped (recent orders).', 'serenisoft-atum-enhancer' ),
				count( $created_pos ),
				$skipped
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

		try {
			$supplier = new Supplier( $supplier_id );

			// Create new PO.
			$po = new PurchaseOrder();
			$po->set_supplier( $supplier_id );
			$po->set_status( 'atum_pending' );
			$po->set_currency( get_woocommerce_currency() );
			$po->set_date_created( current_time( 'mysql' ) );

			// Calculate expected date based on lead time.
			$lead_time = $supplier->get_lead_time();
			if ( empty( $lead_time ) ) {
				$lead_time = 14; // Default 2 weeks.
			}
			$expected_date = date( 'Y-m-d H:i:s', strtotime( "+{$lead_time} days" ) );
			$po->set_date_expected( $expected_date );

			// Save PO first (required before adding items).
			$po->save();

			$po_id = $po->get_id();
			if ( empty( $po_id ) ) {
				return new \WP_Error( 'po_create_failed', __( 'Failed to create Purchase Order.', 'serenisoft-atum-enhancer' ) );
			}

			// Add supplier PO note if set.
			$supplier_note = SupplierFields::get_po_note( $supplier_id );
			if ( ! empty( $supplier_note ) ) {
				$po->add_order_note( $supplier_note );
			}

			// Add products to PO.
			$total = 0;
			foreach ( $products_to_reorder as $product_data ) {
				$product = wc_get_product( $product_data['product_id'] );

				if ( ! $product ) {
					continue;
				}

				$qty   = $product_data['suggested_qty'];
				$price = $product_data['purchase_price'];

				// Add product to PO.
				$item_id = $po->add_product(
					$product,
					$qty,
					array(
						'subtotal' => $price * $qty,
						'total'    => $price * $qty,
					)
				);

				// Add product PO note as item meta if set.
				$product_note = ProductFields::get_po_note( $product_data['product_id'] );
				if ( ! empty( $product_note ) && $item_id ) {
					$item = $po->get_item( $item_id );
					if ( $item ) {
						$item->add_meta_data( __( 'Note', 'serenisoft-atum-enhancer' ), $product_note );
						$item->save();
					}
				}

				$total += $price * $qty;
			}

			// Save PO with items.
			$po->save();

			return array(
				'po_id'         => $po_id,
				'supplier_id'   => $supplier_id,
				'supplier_name' => $supplier->get_name(),
				'items_count'   => count( $products_to_reorder ),
				'total'         => $total,
				'edit_url'      => admin_url( 'post.php?post=' . $po_id . '&action=edit' ),
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'po_exception', $e->getMessage() );
		}

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
	 * Send email notification about created POs
	 *
	 * @since 1.0.0
	 *
	 * @param array $created_pos Array of created PO data.
	 */
	private function send_notification_email( $created_pos ) {

		$admin_email = Settings::get( 'sae_admin_email', get_option( 'admin_email' ) );

		if ( empty( $admin_email ) ) {
			return;
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

		wp_mail( $admin_email, $subject, $message );

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
	 * Schedule the cron job
	 *
	 * @since 1.0.0
	 */
	public function schedule_cron() {

		// Unschedule first to avoid duplicates.
		$this->unschedule_cron();

		// Get configured time.
		$time_setting = Settings::get( 'sae_cron_time', '06:00' );
		list( $hour, $minute ) = explode( ':', $time_setting );

		// Calculate next run time (today or tomorrow at the specified time).
		$timestamp = strtotime( "today {$hour}:{$minute}" );
		if ( $timestamp < time() ) {
			$timestamp = strtotime( "tomorrow {$hour}:{$minute}" );
		}

		wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );

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
