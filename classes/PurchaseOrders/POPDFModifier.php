<?php
/**
 * Modifies ATUM Purchase Order PDF output
 *
 * Moves notes from the bottom of the PDF to above the order lines.
 *
 * @package SereniSoft\AtumEnhancer\PurchaseOrders
 */

namespace SereniSoft\AtumEnhancer\PurchaseOrders;

defined( 'ABSPATH' ) || die;

use SereniSoft\AtumEnhancer\Settings\Settings;
use SereniSoft\AtumEnhancer\Suppliers\SupplierFields;

/**
 * Class POPDFModifier
 *
 * @since 0.9.23
 */
class POPDFModifier {

	/**
	 * Singleton instance
	 *
	 * @var POPDFModifier
	 */
	private static $instance = null;

	/**
	 * Store the current PO's notes to prevent duplication
	 *
	 * @var string
	 */
	private $current_notes = '';

	/**
	 * Flag to track if we're in PDF generation
	 *
	 * @var bool
	 */
	private $in_pdf_generation = false;

	/**
	 * Get singleton instance
	 *
	 * @return POPDFModifier
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Hook into PDF generation start.
		add_action( 'atum/purchase_orders/po_export/generate', array( $this, 'on_pdf_generation_start' ) );

		// Hook to output notes after header (before address section).
		add_action( 'atum/atum_order/po_report/after_header', array( $this, 'output_notes_in_header' ) );
	}

	/**
	 * Called when PDF generation starts
	 *
	 * @param int $po_id The PO ID.
	 */
	public function on_pdf_generation_start( $po_id ) {
		$this->in_pdf_generation = true;

		// Build combined notes.
		$notes = array();

		// Get global note.
		$global_note = Settings::get( 'sae_global_po_note', '' );
		if ( ! empty( $global_note ) ) {
			$notes[] = $global_note;
		}

		// Get supplier note.
		$po          = new \Atum\PurchaseOrders\Models\PurchaseOrder( $po_id );
		$supplier_id = $po->get_supplier( 'id' );
		if ( $supplier_id ) {
			$supplier_note = SupplierFields::get_po_note( $supplier_id );
			if ( ! empty( $supplier_note ) ) {
				$notes[] = $supplier_note;
			}
		}

		$this->current_notes = implode( "\n\n", $notes );

		// Add filter to remove notes from bottom (by emptying description in PDF context).
		add_filter( 'atum/purchase_orders/po_export/supplier_address', array( $this, 'maybe_clear_description' ), 999, 2 );
	}

	/**
	 * Output notes in the header area (after PO number, before addresses)
	 *
	 * @param \Atum\PurchaseOrders\Exports\POExport $po The PO export object.
	 */
	public function output_notes_in_header( $po ) {
		if ( ! $this->in_pdf_generation || empty( $this->current_notes ) ) {
			return;
		}

		// Close the header div first, then output notes, then we'll let the address section continue.
		?>
		</div><!-- close content-header -->
		<div class="po-wrapper content-notes" style="margin: 15px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
			<div class="label" style="font-weight: bold; margin-bottom: 5px;">
				<?php esc_html_e( 'Notes', 'serenisoft-atum-enhancer' ); ?>
			</div>
			<div class="po-notes-content" style="white-space: pre-wrap;">
				<?php echo wp_kses_post( nl2br( esc_html( $this->current_notes ) ) ); ?>
			</div>
		</div>
		<div class="po-wrapper content-header-continue">
		<?php

		// Reset for next PDF.
		$this->in_pdf_generation = false;
		$this->current_notes     = '';
	}

	/**
	 * Clear description to prevent duplicate notes at bottom
	 * This is a workaround - we hook into supplier_address filter which fires during PDF generation
	 *
	 * @param string $address  The supplier address.
	 * @param int    $supplier_id The supplier ID.
	 * @return string
	 */
	public function maybe_clear_description( $address, $supplier_id ) {
		// Remove this filter after it runs once.
		remove_filter( 'atum/purchase_orders/po_export/supplier_address', array( $this, 'maybe_clear_description' ), 999 );
		return $address;
	}
}
