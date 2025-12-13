<?php
/**
 * Send Purchase Orders to suppliers via email
 *
 * @package     SereniSoft\AtumEnhancer\PurchaseOrders
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 0.9.14
 */

namespace SereniSoft\AtumEnhancer\PurchaseOrders;

defined( 'ABSPATH' ) || die;

use Atum\PurchaseOrders\Exports\POExport;
use Atum\PurchaseOrders\PurchaseOrders;
use Atum\Suppliers\Supplier;
use Mpdf\Output\Destination;
use SereniSoft\AtumEnhancer\Settings\Settings;

class POEmailSender {

	/**
	 * The singleton instance holder
	 *
	 * @var POEmailSender
	 */
	private static $instance;

	/**
	 * POEmailSender constructor
	 *
	 * @since 0.9.14
	 */
	private function __construct() {

		// Add "Send to Supplier" button to PO edit page.
		add_filter( 'atum/atum_purchase_order/admin_order_actions', array( $this, 'add_send_email_action' ), 10, 2 );

		// Add inline scripts for the PO edit page.
		add_action( 'admin_footer', array( $this, 'print_email_scripts' ) );

		// AJAX handler for sending email.
		add_action( 'wp_ajax_sae_send_po_email', array( $this, 'ajax_send_po_email' ) );

	}

	/**
	 * Add "Send to Supplier" action button to PO edit page
	 *
	 * @since 0.9.14
	 *
	 * @param array                                       $actions        Current actions.
	 * @param \Atum\PurchaseOrders\Models\PurchaseOrder   $purchase_order The PO object.
	 *
	 * @return array
	 */
	public function add_send_email_action( $actions, $purchase_order ) {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		// Check if supplier has email.
		$supplier_id = $purchase_order->get_supplier_id();
		if ( ! $supplier_id ) {
			return $actions;
		}

		$supplier = new Supplier( $supplier_id );
		$email    = $supplier->ordering_email ?: $supplier->general_email;

		if ( empty( $email ) ) {
			return $actions;
		}

		$actions['send_email'] = array(
			'url'    => '#',
			'name'   => __( 'Send to Supplier', 'serenisoft-atum-enhancer' ),
			'action' => 'send_email sae-send-po-email',
			'target' => '_self',
			'icon'   => '<i class="atum-icon atmi-envelope"></i>',
		);

		return $actions;

	}

	/**
	 * Print scripts for the email functionality
	 *
	 * @since 0.9.14
	 */
	public function print_email_scripts() {

		$screen = get_current_screen();

		// Only on PO edit page.
		if ( ! $screen || 'atum_purchase_order' !== $screen->id ) {
			return;
		}

		global $post;

		if ( ! $post || 'atum_purchase_order' !== $post->post_type ) {
			return;
		}

		$po_id   = $post->ID;
		$nonce   = wp_create_nonce( 'sae_send_po_email' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<!-- SAE Email Modal -->
		<div id="sae-email-modal" class="sae-modal" style="display: none;">
			<div class="sae-modal-overlay"></div>
			<div class="sae-modal-content">
				<h2><?php esc_html_e( 'Send Purchase Order to Supplier', 'serenisoft-atum-enhancer' ); ?></h2>
				<p><?php esc_html_e( 'This will send the Purchase Order as a PDF attachment to the supplier.', 'serenisoft-atum-enhancer' ); ?></p>
				<p id="sae-email-recipient"></p>
				<div class="sae-modal-buttons">
					<button type="button" class="button button-primary" id="sae-email-confirm">
						<?php esc_html_e( 'Send Email', 'serenisoft-atum-enhancer' ); ?>
					</button>
					<button type="button" class="button" id="sae-email-cancel">
						<?php esc_html_e( 'Cancel', 'serenisoft-atum-enhancer' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Loading Overlay -->
		<div id="sae-email-loading" style="display: none;">
			<div class="sae-loading-content">
				<span class="spinner is-active"></span>
				<p><?php esc_html_e( 'Sending email...', 'serenisoft-atum-enhancer' ); ?></p>
			</div>
		</div>

		<style>
		.sae-modal {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			z-index: 100000;
		}
		.sae-modal-overlay {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.7);
		}
		.sae-modal-content {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			background: white;
			padding: 25px;
			border-radius: 5px;
			box-shadow: 0 5px 30px rgba(0, 0, 0, 0.3);
			min-width: 400px;
			max-width: 500px;
		}
		.sae-modal-content h2 {
			margin-top: 0;
			margin-bottom: 15px;
			font-size: 18px;
		}
		.sae-modal-content p {
			margin-bottom: 15px;
			line-height: 1.5;
		}
		.sae-modal-buttons {
			text-align: right;
			margin-top: 20px;
		}
		.sae-modal-buttons button {
			margin-left: 10px;
		}
		#sae-email-loading {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(255, 255, 255, 0.9);
			z-index: 100001;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.sae-loading-content {
			text-align: center;
			background: white;
			padding: 40px 60px;
			border-radius: 5px;
			box-shadow: 0 2px 20px rgba(0, 0, 0, 0.15);
		}
		.sae-loading-content .spinner {
			float: none;
			margin: 0 auto 20px;
			display: block;
		}
		.sae-loading-content p {
			margin: 10px 0 0;
			font-size: 16px;
		}
		/* Style for the send email action button */
		.atum-order-actions .send_email {
			color: #2271b1 !important;
		}
		.atum-order-actions .send_email:hover {
			color: #135e96 !important;
		}
		</style>

		<script type="text/javascript">
		jQuery(function($) {
			var poId = <?php echo intval( $po_id ); ?>;
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';

			// Handle click on "Send to Supplier" button.
			$(document).on('click', '.sae-send-po-email, a.send_email', function(e) {
				e.preventDefault();

				// First, get supplier email via AJAX.
				$.post(ajaxUrl, {
					action: 'sae_send_po_email',
					po_id: poId,
					nonce: nonce,
					get_info: 1
				}, function(response) {
					if (response.success) {
						$('#sae-email-recipient').html(
							'<strong><?php echo esc_js( __( 'Recipient:', 'serenisoft-atum-enhancer' ) ); ?></strong> ' +
							response.data.supplier_name + ' &lt;' + response.data.supplier_email + '&gt;'
						);
						$('#sae-email-modal').fadeIn(200);
					} else {
						alert(response.data.message || '<?php echo esc_js( __( 'Could not get supplier information.', 'serenisoft-atum-enhancer' ) ); ?>');
					}
				});
			});

			// Cancel button.
			$(document).on('click', '#sae-email-cancel, .sae-modal-overlay', function() {
				$('#sae-email-modal').fadeOut(200);
			});

			// Confirm send.
			$(document).on('click', '#sae-email-confirm', function() {
				$('#sae-email-modal').fadeOut(200);
				$('#sae-email-loading').fadeIn(200);

				$.post(ajaxUrl, {
					action: 'sae_send_po_email',
					po_id: poId,
					nonce: nonce,
					send: 1
				}, function(response) {
					$('#sae-email-loading').fadeOut(200);

					if (response.success) {
						alert(response.data.message);
					} else {
						alert(response.data.message || '<?php echo esc_js( __( 'Failed to send email.', 'serenisoft-atum-enhancer' ) ); ?>');
					}
				}).fail(function() {
					$('#sae-email-loading').fadeOut(200);
					alert('<?php echo esc_js( __( 'An error occurred while sending the email.', 'serenisoft-atum-enhancer' ) ); ?>');
				});
			});
		});
		</script>
		<?php

	}

	/**
	 * AJAX handler for sending PO email
	 *
	 * @since 0.9.14
	 */
	public function ajax_send_po_email() {

		check_ajax_referer( 'sae_send_po_email', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'serenisoft-atum-enhancer' ) ) );
		}

		$po_id = isset( $_POST['po_id'] ) ? absint( $_POST['po_id'] ) : 0;

		if ( ! $po_id || 'atum_purchase_order' !== get_post_type( $po_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid Purchase Order.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Get PO and supplier info.
		$po          = new \Atum\PurchaseOrders\Models\PurchaseOrder( $po_id );
		$supplier_id = $po->get_supplier_id();

		if ( ! $supplier_id ) {
			wp_send_json_error( array( 'message' => __( 'No supplier assigned to this Purchase Order.', 'serenisoft-atum-enhancer' ) ) );
		}

		$supplier       = new Supplier( $supplier_id );
		$supplier_email = $supplier->ordering_email ?: $supplier->general_email;
		$supplier_name  = $supplier->name;

		if ( empty( $supplier_email ) ) {
			wp_send_json_error( array( 'message' => __( 'Supplier does not have an email address.', 'serenisoft-atum-enhancer' ) ) );
		}

		// If just getting info, return it.
		if ( isset( $_POST['get_info'] ) && $_POST['get_info'] ) {
			wp_send_json_success( array(
				'supplier_name'  => $supplier_name,
				'supplier_email' => $supplier_email,
			) );
		}

		// Send the email.
		if ( isset( $_POST['send'] ) && $_POST['send'] ) {
			$result = $this->send_po_email( $po_id, $supplier );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: supplier email */
					__( 'Purchase Order sent successfully to %s', 'serenisoft-atum-enhancer' ),
					$supplier_email
				),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'serenisoft-atum-enhancer' ) ) );

	}

	/**
	 * Send PO email to supplier
	 *
	 * @since 0.9.14
	 *
	 * @param int      $po_id    The PO ID.
	 * @param Supplier $supplier The supplier object.
	 *
	 * @return bool|\WP_Error
	 */
	private function send_po_email( $po_id, $supplier ) {

		$supplier_email = $supplier->ordering_email ?: $supplier->general_email;
		$supplier_name  = $supplier->name;

		// Get email settings.
		$from_name    = Settings::get( 'sae_email_from_name', '' ) ?: get_bloginfo( 'name' );
		$from_email   = Settings::get( 'sae_email_from_address', '' ) ?: get_option( 'admin_email' );
		$cc_email     = Settings::get( 'sae_email_cc_address', '' );
		$body_template = Settings::get( 'sae_email_body_template', '' );
		$signature    = Settings::get( 'sae_email_signature', '' );

		// Default body if not set.
		if ( empty( $body_template ) ) {
			$body_template = "Dear {supplier_name},\n\nPlease find attached Purchase Order #{po_number}.\n\nPlease confirm receipt and expected delivery date.\n\nBest regards,";
		}

		// Get PO data for placeholders.
		$po         = new \Atum\PurchaseOrders\Models\PurchaseOrder( $po_id );
		$order_date = $po->get_date_created() ? $po->get_date_created()->format( get_option( 'date_format' ) ) : '';
		$total      = $po->get_formatted_total();

		// Replace placeholders.
		$placeholders = array(
			'{po_number}'     => $po_id,
			'{supplier_name}' => $supplier_name,
			'{order_date}'    => $order_date,
			'{total}'         => $total,
		);

		$body = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $body_template );

		// Add signature.
		if ( ! empty( $signature ) ) {
			$body .= "\n\n" . $signature;
		}

		// Build subject.
		$subject = sprintf(
			/* translators: 1: PO number, 2: supplier name */
			__( 'Purchase Order #%1$d - %2$s', 'serenisoft-atum-enhancer' ),
			$po_id,
			$supplier_name
		);

		// Build headers.
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
			sprintf( 'Reply-To: %s <%s>', $from_name, $from_email ),
		);

		if ( ! empty( $cc_email ) && is_email( $cc_email ) ) {
			$headers[] = sprintf( 'Cc: %s', $cc_email );
		}

		// Generate PDF.
		$pdf_path = $this->generate_pdf( $po_id );

		if ( is_wp_error( $pdf_path ) ) {
			return $pdf_path;
		}

		// Send email.
		$sent = wp_mail( $supplier_email, $subject, $body, $headers, array( $pdf_path ) );

		// Delete temp PDF.
		if ( file_exists( $pdf_path ) ) {
			wp_delete_file( $pdf_path );
		}

		if ( ! $sent ) {
			return new \WP_Error( 'email_failed', __( 'Failed to send email. Please check your server email configuration.', 'serenisoft-atum-enhancer' ) );
		}

		// Log the send in PO meta.
		$this->log_email_sent( $po_id, $supplier_email, $cc_email );

		return true;

	}

	/**
	 * Generate PDF for the PO
	 *
	 * @since 0.9.14
	 *
	 * @param int $po_id The PO ID.
	 *
	 * @return string|\WP_Error Path to the generated PDF or error.
	 */
	private function generate_pdf( $po_id ) {

		try {
			$po_export = new POExport( $po_id );

			// Get temp directory.
			$upload_dir = wp_upload_dir();
			$temp_dir   = $upload_dir['basedir'] . '/sae-temp';

			if ( ! is_dir( $temp_dir ) ) {
				wp_mkdir_p( $temp_dir );
			}

			$pdf_filename = 'po-' . $po_id . '-' . time() . '.pdf';
			$pdf_path     = $temp_dir . '/' . $pdf_filename;

			// Generate PDF as string and save to file.
			$pdf_content = $po_export->generate( Destination::STRING_RETURN );

			if ( is_wp_error( $pdf_content ) ) {
				return $pdf_content;
			}

			// Write PDF to file.
			$written = file_put_contents( $pdf_path, $pdf_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( false === $written ) {
				return new \WP_Error( 'pdf_write_failed', __( 'Failed to write PDF file.', 'serenisoft-atum-enhancer' ) );
			}

			return $pdf_path;

		} catch ( \Exception $e ) {
			return new \WP_Error( 'pdf_exception', $e->getMessage() );
		}

	}

	/**
	 * Log email sent to PO meta
	 *
	 * @since 0.9.14
	 *
	 * @param int    $po_id          The PO ID.
	 * @param string $supplier_email The supplier email.
	 * @param string $cc_email       The CC email.
	 */
	private function log_email_sent( $po_id, $supplier_email, $cc_email = '' ) {

		$log = get_post_meta( $po_id, '_sae_email_log', true ) ?: array();

		$log[] = array(
			'date'           => current_time( 'mysql' ),
			'supplier_email' => $supplier_email,
			'cc_email'       => $cc_email,
			'user_id'        => get_current_user_id(),
		);

		update_post_meta( $po_id, '_sae_email_log', $log );

	}


	/*******************
	 * Instance methods
	 *******************/

	/**
	 * Cannot be cloned
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cloning is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.14' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Serialization is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.14' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return POEmailSender instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
