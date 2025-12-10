<?php
/**
 * Bulk Supplier Assignment for ATUM Stock Central
 *
 * @package     SereniSoft\AtumEnhancer
 * @subpackage  BulkActions
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 0.5.0
 */

namespace SereniSoft\AtumEnhancer\BulkActions;

defined( 'ABSPATH' ) || die;

use Atum\Inc\Helpers;
use Atum\Components\AtumCapabilities;

/**
 * Bulk Supplier Assignment class
 *
 * Adds "Set Supplier" bulk action to ATUM Stock Central.
 * Uses IDENTICAL code path as ATUM's individual supplier assignment.
 *
 * @since 0.5.0
 */
class BulkSupplierAssignment {

	/**
	 * The singleton instance holder
	 *
	 * @var BulkSupplierAssignment
	 */
	private static $instance;

	/**
	 * BulkSupplierAssignment constructor
	 *
	 * @since 0.5.0
	 */
	private function __construct() {
		$this->init_hooks();
		$this->enqueue_assets();
	}

	/**
	 * Register hooks
	 *
	 * @since 0.5.0
	 */
	private function init_hooks() {
		// Add "Set Supplier" to bulk actions dropdown.
		add_filter( 'atum/list_table/bulk_actions', array( $this, 'add_bulk_action' ), 10, 2 );

		// Handle bulk supplier assignment AJAX.
		add_action( 'wp_ajax_sae_bulk_assign_supplier', array( $this, 'ajax_bulk_assign_supplier' ) );
	}

	/**
	 * Enqueue modal JS/CSS assets
	 *
	 * @since 0.5.0
	 */
	private function enqueue_assets() {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_assets' ) );
	}

	/**
	 * Add "Set Supplier" to bulk actions dropdown
	 *
	 * @since 0.5.0
	 *
	 * @param array  $bulk_actions Array of bulk actions.
	 * @param object $list_table   ATUM List Table instance.
	 *
	 * @return array Modified bulk actions array.
	 */
	public function add_bulk_action( $bulk_actions, $list_table ) {
		// Only show if user has edit_suppliers capability.
		if ( AtumCapabilities::current_user_can( 'edit_suppliers' ) ) {
			$bulk_actions['sae_set_supplier'] = __( 'Set Supplier', 'serenisoft-atum-enhancer' );
		}

		return $bulk_actions;
	}

	/**
	 * AJAX handler for bulk supplier assignment
	 *
	 * Uses IDENTICAL code path as ATUM's individual supplier assignment:
	 * Helpers::get_atum_product() -> set_supplier_id() -> save_atum_data()
	 *
	 * @since 0.5.0
	 */
	public function ajax_bulk_assign_supplier() {
		// Nonce check (same nonce as ATUM's list table).
		check_ajax_referer( 'atum-list-table-nonce', 'security' );

		// Capability check.
		if ( ! AtumCapabilities::current_user_can( 'edit_suppliers' ) ) {
			wp_send_json_error( __( 'Permission denied', 'serenisoft-atum-enhancer' ) );
		}

		// Get data from AJAX request.
		$supplier_id = isset( $_POST['supplier_id'] ) ? absint( $_POST['supplier_id'] ) : 0;
		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', $_POST['product_ids'] ) : array();

		if ( empty( $product_ids ) ) {
			wp_send_json_error( __( 'No products selected', 'serenisoft-atum-enhancer' ) );
		}

		$success_count = 0;
		$errors        = array();

		foreach ( $product_ids as $product_id ) {
			// Skip non-numeric IDs (same pattern as ATUM's bulk actions).
			if ( ! is_numeric( $product_id ) ) {
				continue;
			}

			try {
				// CRITICAL: Use ATUM wrapper (same as individual assignment).
				$product = Helpers::get_atum_product( $product_id );

				if ( ! $product ) {
					/* translators: %d: product ID */
					$errors[] = sprintf( __( 'Product %d not found', 'serenisoft-atum-enhancer' ), $product_id );
					continue;
				}

				// CRITICAL: Use IDENTICAL method as ATUM's individual assignment.
				// Set supplier_id (NULL to clear, or supplier ID).
				$product->set_supplier_id( $supplier_id ?: null );

				// Save ATUM data (updates wp_atum_product_data table).
				$product->save_atum_data();

				// Handle variations if parent variable product.
				if ( $product->is_type( 'variable' ) ) {
					$variations = $product->get_children();
					foreach ( $variations as $variation_id ) {
						$variation = Helpers::get_atum_product( $variation_id );
						if ( $variation ) {
							$variation->set_supplier_id( $supplier_id ?: null );
							$variation->save_atum_data();
						}
					}
				}

				$success_count++;

			} catch ( \Exception $e ) {
				/* translators: 1: product ID, 2: error message */
				$errors[] = sprintf( __( 'Product %1$d: %2$s', 'serenisoft-atum-enhancer' ), $product_id, $e->getMessage() );
			}
		}

		// Return response.
		if ( $success_count > 0 ) {
			/* translators: %d: number of products */
			$message = sprintf( __( 'Supplier assigned to %d product(s)', 'serenisoft-atum-enhancer' ), $success_count );

			wp_send_json_success(
				array(
					'message' => $message,
					'errors'  => $errors,
				)
			);
		} else {
			wp_send_json_error( __( 'No products updated', 'serenisoft-atum-enhancer' ) );
		}
	}

	/**
	 * Enqueue JS/CSS on Stock Central page
	 *
	 * @since 0.5.0
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function load_assets( $hook ) {
		// Check if we're on ATUM Stock Central page.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'atum-stock-central' ) ) {
			return;
		}

		// Enqueue JavaScript.
		wp_enqueue_script(
			'sae-bulk-supplier-modal',
			SAE_URL . 'classes/BulkActions/assets/js/bulk-supplier-modal.js',
			array( 'jquery', 'select2' ),
			SAE_VERSION,
			true
		);

		// Enqueue CSS.
		wp_enqueue_style(
			'sae-bulk-supplier-modal',
			SAE_URL . 'classes/BulkActions/assets/css/bulk-supplier-modal.css',
			array(),
			SAE_VERSION
		);

		// Pass translations to JavaScript.
		wp_localize_script(
			'sae-bulk-supplier-modal',
			'saeBulkSupplier',
			array(
				'selectSupplier'      => __( 'Select Supplier', 'serenisoft-atum-enhancer' ),
				'assign'              => __( 'Assign', 'serenisoft-atum-enhancer' ),
				'cancel'              => __( 'Cancel', 'serenisoft-atum-enhancer' ),
				'searchSupplier'      => __( 'Search Supplier by Name or ID…', 'serenisoft-atum-enhancer' ),
				'selectSupplierFirst' => __( 'Please select a supplier first', 'serenisoft-atum-enhancer' ),
				'error'               => __( 'An error occurred', 'serenisoft-atum-enhancer' ),
				'nonce'               => wp_create_nonce( 'atum-list-table-nonce' ),
				'searchNonce'         => wp_create_nonce( 'search-products' ), // CRITICAL: Nonce for supplier search.
			)
		);
	}

	/******************
	 * Instance methods
	 ******************/

	/**
	 * Cannot be cloned
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cloning is not allowed.', 'serenisoft-atum-enhancer' ), '0.5.0' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Serialization is not allowed.', 'serenisoft-atum-enhancer' ), '0.5.0' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return BulkSupplierAssignment instance
	 */
	public static function get_instance() {
		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

}
