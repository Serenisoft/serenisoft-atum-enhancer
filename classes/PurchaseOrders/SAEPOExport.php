<?php
/**
 * Extended POExport class that moves notes above order lines
 *
 * @package SereniSoft\AtumEnhancer\PurchaseOrders
 */

namespace SereniSoft\AtumEnhancer\PurchaseOrders;

defined( 'ABSPATH' ) || die;

use Atum\PurchaseOrders\Exports\POExport;
use Mpdf\Output\Destination;

/**
 * Class SAEPOExport
 *
 * Extends ATUM's POExport to modify PDF layout.
 *
 * @since 0.9.23
 */
class SAEPOExport extends POExport {

	/**
	 * Generate the PO PDF/HTML with notes above order lines
	 *
	 * @param string $destination_mode The mPDF destination mode.
	 * @return string|\WP_Error
	 */
	public function generate( $destination_mode = Destination::INLINE ) {
		// For HTML output (debug mode), let parent handle it.
		if ( $this->get_debug_mode() ) {
			return parent::generate( $destination_mode );
		}

		// Get the HTML content.
		$html = $this->get_content();

		// Modify HTML to move notes above order lines.
		$html = POPDFModifier::move_notes_above_lines( $html, $this->get_id() );

		// Now generate PDF with modified HTML.
		return $this->generate_pdf_from_html( $html, $destination_mode );
	}

	/**
	 * Generate PDF from modified HTML
	 *
	 * @param string $html             The HTML content.
	 * @param string $destination_mode The mPDF destination mode.
	 * @return string|\WP_Error
	 */
	private function generate_pdf_from_html( $html, $destination_mode ) {
		try {
			$atum_dir = \Atum\Inc\Helpers::get_atum_uploads_dir();
			$temp_dir = $atum_dir . apply_filters( 'atum/purchase_orders/po_export/temp_pdf_dir', 'tmp' );

			if ( ! is_dir( $temp_dir ) ) {
				$success = wp_mkdir_p( $temp_dir );
				if ( ! $success || ! is_writable( $temp_dir ) ) {
					$temp_dir = $atum_dir;
				}
			}

			do_action( 'atum/purchase_orders/po_export/generate', $this->get_id() );

			// Try to set the backtrack limit.
			@ini_set( 'pcre.backtrack_limit', '9999999' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$mpdf = new \Mpdf\Mpdf( array(
				'mode'    => 'utf-8',
				'format'  => 'A4',
				'tempDir' => $temp_dir,
			) );

			// Add support for non-Latin languages.
			$mpdf->useAdobeCJK      = true;
			$mpdf->autoScriptToLang = true;
			$mpdf->autoLangToFont   = true;

			$mpdf->SetTitle( __( 'Purchase Order', 'atum-stock-manager-for-woocommerce' ) );

			$mpdf->default_available_fonts = $mpdf->available_unifonts;

			// Add CSS.
			$css = $this->get_stylesheets( 'path' );
			foreach ( $css as $file ) {
				if ( file_exists( $file ) ) {
					$stylesheet = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
					$mpdf->WriteHTML( $stylesheet, 1 );
				}
			}

			$mpdf->WriteHTML( $html );

			return $mpdf->Output( "po-{$this->id}.pdf", $destination_mode );

		} catch ( \Mpdf\MpdfException $e ) {
			return new \WP_Error( 'atum_pdf_generation_error', $e->getMessage() );
		}
	}
}
