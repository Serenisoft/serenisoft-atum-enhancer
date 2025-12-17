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
		// Use the CSS filter to inject our output buffer hooks.
		add_filter( 'atum/purchase_orders/po_export/css', array( $this, 'start_output_capture' ), 1 );
	}

	/**
	 * Start capturing output when PDF CSS is loaded (early in generation)
	 *
	 * @param array $css Array of CSS files.
	 * @return array
	 */
	public function start_output_capture( $css ) {
		// Hook into the view load to modify the HTML.
		add_filter( 'atum/views/reports/purchase-order-html', array( $this, 'modify_pdf_html' ), 10, 2 );

		return $css;
	}

	/**
	 * Modify the PDF HTML to move notes above order lines
	 *
	 * This filter doesn't exist in ATUM, so we need another approach.
	 * Let's use the mpdf WriteHTML filter if available, or modify via the_content.
	 *
	 * @param string $html The HTML content.
	 * @param array  $args The view arguments.
	 * @return string
	 */
	public function modify_pdf_html( $html, $args ) {
		return $html;
	}

	/**
	 * Get combined notes for a PO
	 *
	 * @param int $po_id The PO ID.
	 * @return string Combined notes.
	 */
	public static function get_combined_notes( $po_id ) {
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

		return implode( "\n\n", $notes );
	}

	/**
	 * Build notes HTML block
	 *
	 * @param string $notes The notes text.
	 * @return string HTML for notes section.
	 */
	public static function build_notes_html( $notes ) {
		if ( empty( $notes ) ) {
			return '';
		}

		$html  = '<div class="po-wrapper content-description" style="margin-bottom: 15px;">';
		$html .= '<div class="label">' . esc_html__( 'Notes', 'serenisoft-atum-enhancer' ) . '</div>';
		$html .= '<div class="po-content">' . wp_kses_post( nl2br( esc_html( $notes ) ) ) . '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Move notes in HTML from bottom to above order lines
	 *
	 * @param string $html    The full PDF HTML.
	 * @param int    $po_id   The PO ID.
	 * @return string Modified HTML with notes moved.
	 */
	public static function move_notes_above_lines( $html, $po_id ) {
		// Get combined notes.
		$notes = self::get_combined_notes( $po_id );

		if ( empty( $notes ) ) {
			return $html;
		}

		// Build notes HTML.
		$notes_html = self::build_notes_html( $notes );

		// Remove existing notes section from bottom (if any).
		// Structure: <div class="po-wrapper content-description"><div class="label">...</div><div class="po-content">...</div></div>
		$html = preg_replace(
			'/<div class="po-wrapper content-description">\s*<div class="label">.*?<\/div>\s*<div class="po-content">.*?<\/div>\s*<\/div>/s',
			'',
			$html
		);

		// Insert notes before content-lines.
		$html = str_replace(
			'<div class="po-wrapper content-lines">',
			$notes_html . '<div class="po-wrapper content-lines">',
			$html
		);

		return $html;
	}
}
