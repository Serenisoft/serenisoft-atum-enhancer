<?php
/**
 * Add Generate PO Suggestions button to ATUM Purchase Orders list page
 *
 * @package     SereniSoft\AtumEnhancer\PurchaseOrders
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 0.9.7
 */

namespace SereniSoft\AtumEnhancer\PurchaseOrders;

defined( 'ABSPATH' ) || die;

class POListButton {

	/**
	 * The singleton instance holder
	 *
	 * @var POListButton
	 */
	private static $instance;

	/**
	 * POListButton constructor
	 *
	 * @since 0.9.7
	 */
	private function __construct() {

		add_action( 'load-edit.php', array( $this, 'maybe_add_button' ) );

	}

	/**
	 * Check if we're on the PO list page and add button
	 *
	 * @since 0.9.7
	 */
	public function maybe_add_button() {

		global $typenow;

		if ( 'atum_purchase_order' !== $typenow ) {
			return;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_action( 'admin_print_footer_scripts', array( $this, 'print_button_and_scripts' ) );

	}

	/**
	 * Print button HTML, CSS, and JavaScript
	 *
	 * @since 0.9.7
	 */
	public function print_button_and_scripts() {

		$ajax_url       = admin_url( 'admin-ajax.php' );
		$generate_nonce = wp_create_nonce( 'sae_generate_po_suggestions' );
		$choice_nonce   = wp_create_nonce( 'sae_execute_po_choice' );
		?>
		<!-- SAE Modal HTML -->
		<div id="sae-confirm-modal" class="sae-modal" style="display: none;">
			<div class="sae-modal-overlay"></div>
			<div class="sae-modal-content">
				<h2><?php esc_html_e( 'Confirm Generation', 'serenisoft-atum-enhancer' ); ?></h2>
				<p><?php esc_html_e( 'This will analyze all products and create draft POs for suppliers. Continue?', 'serenisoft-atum-enhancer' ); ?></p>
				<div class="sae-modal-buttons">
					<button type="button" class="button button-primary" id="sae-confirm-yes">
						<?php esc_html_e( 'Yes, Continue', 'serenisoft-atum-enhancer' ); ?>
					</button>
					<button type="button" class="button" id="sae-confirm-no">
						<?php esc_html_e( 'Cancel', 'serenisoft-atum-enhancer' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- PO Choice Dialog -->
		<div class="sae-choice-overlay" style="display: none;">
			<div class="sae-choice-modal">
				<h2><?php esc_html_e( 'Purchase Order Choices', 'serenisoft-atum-enhancer' ); ?></h2>
				<p><?php esc_html_e( 'Some suppliers have existing Purchase Orders. Please choose how to handle each:', 'serenisoft-atum-enhancer' ); ?></p>

				<div class="sae-choices-container">
					<!-- Choices will be inserted here by JavaScript -->
				</div>

				<div class="sae-choice-actions">
					<button type="button" class="button button-primary" id="sae-execute-choices">
						<?php esc_html_e( 'Execute Choices', 'serenisoft-atum-enhancer' ); ?>
					</button>
					<button type="button" class="button" id="sae-cancel-choices">
						<?php esc_html_e( 'Cancel', 'serenisoft-atum-enhancer' ); ?>
					</button>
				</div>
			</div>
		</div>

		<div id="sae-generate-result" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 500px; z-index: 9999;"></div>

		<!-- Loading Overlay -->
		<div id="sae-loading-overlay" style="display: none;">
			<div class="sae-loading-content">
				<span class="spinner is-active"></span>
				<p><?php esc_html_e( 'Generating PO Suggestions...', 'serenisoft-atum-enhancer' ); ?></p>
				<p class="description"><?php esc_html_e( 'Please wait, this may take a moment.', 'serenisoft-atum-enhancer' ); ?></p>
			</div>
		</div>

		<style>
		/* Custom modal styles */
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
			margin-bottom: 20px;
			line-height: 1.5;
		}
		.sae-modal-buttons {
			text-align: right;
		}
		.sae-modal-buttons button {
			margin-left: 10px;
		}

		/* PO Choice Dialog */
		.sae-choice-overlay {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.7);
			z-index: 100001;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.sae-choice-modal {
			background: white;
			padding: 30px;
			border-radius: 5px;
			box-shadow: 0 5px 30px rgba(0, 0, 0, 0.3);
			max-width: 700px;
			max-height: 80vh;
			overflow-y: auto;
			width: 90%;
		}
		.sae-choice-modal h2 {
			margin-top: 0;
			margin-bottom: 10px;
		}
		.sae-choices-container {
			margin: 20px 0;
		}
		.sae-choice-item {
			border: 1px solid #ddd;
			padding: 15px;
			margin-bottom: 15px;
			border-radius: 3px;
			background: #f9f9f9;
		}
		.sae-choice-item h3 {
			margin-top: 0;
			margin-bottom: 10px;
		}
		.sae-choice-item .supplier-info {
			margin-bottom: 10px;
			font-weight: bold;
		}
		.sae-po-option {
			margin: 8px 0;
		}
		.sae-po-option input[type="radio"] {
			margin-right: 8px;
		}
		.sae-po-option label {
			cursor: pointer;
			display: inline;
		}
		.sae-choice-actions {
			text-align: right;
			margin-top: 20px;
			padding-top: 15px;
			border-top: 1px solid #ddd;
		}
		.sae-choice-actions button {
			margin-left: 10px;
		}

		/* Button spinner */
		#sae-generate-btn.loading {
			pointer-events: none;
			opacity: 0.7;
		}

		/* Loading overlay */
		#sae-loading-overlay {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(255, 255, 255, 0.9);
			z-index: 100002;
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
		.sae-loading-content .description {
			font-size: 13px;
			color: #666;
		}
		</style>

		<script type="text/javascript">
		jQuery(function($) {
			// Inject button after "Add New" button
			$('.wrap .page-title-action').first().after(
				'<a href="#" id="sae-generate-btn" class="page-title-action"><?php echo esc_js( __( 'Generate PO Suggestions', 'serenisoft-atum-enhancer' ) ); ?></a>'
			);

			// Handle button click - show confirmation modal
			$(document).on('click', '#sae-generate-btn', function(e) {
				e.preventDefault();
				$('#sae-confirm-modal').css('display', 'block').show();
			});

			// Handle modal Cancel button
			$(document).on('click', '#sae-confirm-no, .sae-modal-overlay', function() {
				$('#sae-confirm-modal').css('display', 'none').hide();
			});

			// Handle modal Yes button - run generation
			$(document).on('click', '#sae-confirm-yes', function() {
				$('#sae-confirm-modal').css('display', 'none').hide();

				var $btn = $('#sae-generate-btn');
				var originalText = $btn.text();
				var $result = $('#sae-generate-result');

				$btn.addClass('loading').text('<?php echo esc_js( __( 'Generating...', 'serenisoft-atum-enhancer' ) ); ?>');
				$result.html('');
				$('#sae-loading-overlay').fadeIn(200);

				$.ajax({
					url: '<?php echo esc_js( $ajax_url ); ?>',
					type: 'POST',
					data: {
						action: 'sae_generate_po_suggestions',
						nonce: '<?php echo esc_js( $generate_nonce ); ?>'
					},
					success: function(response) {
						$btn.removeClass('loading').text(originalText);
						$('#sae-loading-overlay').fadeOut(200);

						if (response.success) {
							var data = response.data;

							if (data.choices_needed && data.choices_needed.length > 0) {
								showPoChoiceDialog(data);
							} else {
								showGenerationResults(data);
							}
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.removeClass('loading').text(originalText);
						$('#sae-loading-overlay').fadeOut(200);
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during generation.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// Show PO choice dialog
			function showPoChoiceDialog(data) {
				var html = '';
				var $result = $('#sae-generate-result');
				var isDryRun = data.dry_run || false;
				var autoCreatedPos = data.created || [];

				$.each(data.choices_needed, function(index, choice) {
					html += '<div class="sae-choice-item" data-supplier-id="' + choice.supplier_id + '">';
					html += '<h3>' + choice.supplier_name + '</h3>';
					html += '<div class="supplier-info">' + choice.product_count + ' <?php echo esc_js( __( 'products need reordering', 'serenisoft-atum-enhancer' ) ); ?></div>';

					html += '<div class="sae-existing-pos">';
					html += '<p><strong><?php echo esc_js( __( 'Choose action:', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';

					$.each(choice.existing_pos, function(i, po) {
						var poDate = new Date(po.date).toLocaleDateString();
						html += '<div class="sae-po-option">';
						html += '<input type="radio" name="choice_' + choice.supplier_id + '" id="po_' + po.id + '" value="add_to_' + po.id + '"' + (i === 0 ? ' checked' : '') + '>';
						html += '<label for="po_' + po.id + '"><?php echo esc_js( __( 'Add to', 'serenisoft-atum-enhancer' ) ); ?> PO #' + po.id + ' (' + po.status_label + ') - ' + po.product_count + ' <?php echo esc_js( __( 'products', 'serenisoft-atum-enhancer' ) ); ?>, ' + poDate + '</label>';
						html += '</div>';
					});

					html += '<div class="sae-po-option">';
					html += '<input type="radio" name="choice_' + choice.supplier_id + '" id="new_' + choice.supplier_id + '" value="create_new">';
					html += '<label for="new_' + choice.supplier_id + '"><?php echo esc_js( __( 'Create new Purchase Order', 'serenisoft-atum-enhancer' ) ); ?></label>';
					html += '</div>';

					html += '</div>';
					html += '<input type="hidden" class="sae-choice-products" value="' + encodeURIComponent(JSON.stringify(choice.products)) + '">';
					html += '</div>';
				});

				$('.sae-choices-container').html(html);
				$('.sae-choice-overlay').data('auto-created-pos', autoCreatedPos);

				if (isDryRun) {
					$('#sae-execute-choices').hide();
					var warningHtml = '<div class="sae-dry-run-warning" style="padding: 12px; background: #fff3cd; border-left: 4px solid #ffc107; margin-bottom: 15px;">';
					warningHtml += '<strong><?php echo esc_js( __( 'Choice execution is disabled in Dry Run mode', 'serenisoft-atum-enhancer' ) ); ?></strong>';
					warningHtml += '</div>';
					$('.sae-choice-actions').prepend(warningHtml);
				} else {
					$('#sae-execute-choices').show();
					$('.sae-dry-run-warning').remove();
				}

				$('.sae-choice-overlay').fadeIn(200);

				if (data.created && data.created.length > 0) {
					var noticeClass = isDryRun ? 'notice-warning' : 'notice-success';
					var resultHtml = '<div class="notice ' + noticeClass + '">';
					if (isDryRun) {
						resultHtml += '<p><strong><?php echo esc_js( __( 'DRY RUN - Preview Only', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';
					}
					resultHtml += '<p>' + data.created.length + ' <?php echo esc_js( __( 'PO suggestions created for suppliers without existing POs.', 'serenisoft-atum-enhancer' ) ); ?></p>';
					resultHtml += '</div>';
					$result.html(resultHtml);
				}
			}

			// Show normal generation results
			function showGenerationResults(data) {
				var $result = $('#sae-generate-result');
				var isDryRun = data.dry_run || false;
				var noticeClass = isDryRun ? 'notice-warning' : 'notice-success';

				var html = '<div class="notice ' + noticeClass + '" style="padding: 15px;">';
				html += '<p><strong>' + data.message + '</strong></p>';

				if (isDryRun) {
					html += '<p style="color: #856404;"><?php echo esc_js( __( 'This is a preview. Disable Dry Run Mode to create actual POs.', 'serenisoft-atum-enhancer' ) ); ?></p>';
				}

				html += '<p><?php echo esc_js( __( 'Suppliers:', 'serenisoft-atum-enhancer' ) ); ?> ' + data.total_suppliers + ' | <?php echo esc_js( __( 'Products:', 'serenisoft-atum-enhancer' ) ); ?> ' + data.total_products + ' | <?php echo esc_js( __( 'Below ROP:', 'serenisoft-atum-enhancer' ) ); ?> ' + data.products_below_reorder + '</p>';

				if (data.created && data.created.length) {
					html += '<p><?php echo esc_js( __( 'Created:', 'serenisoft-atum-enhancer' ) ); ?> ' + data.created.length + ' POs</p>';
					if (!isDryRun) {
						html += '<p><a href="' + window.location.href + '" class="button"><?php echo esc_js( __( 'Refresh Page', 'serenisoft-atum-enhancer' ) ); ?></a></p>';
					}
				}

				html += '</div>';
				$result.html(html);

				// Auto-hide after 10 seconds if no POs created
				if (!data.created || data.created.length === 0) {
					setTimeout(function() {
						$result.fadeOut();
					}, 10000);
				}
			}

			// Handle "Execute Choices" button
			$(document).on('click', '#sae-execute-choices', function() {
				var choices = [];

				$('.sae-choice-item').each(function() {
					var supplierId = $(this).data('supplier-id');
					var selectedOption = $('input[name="choice_' + supplierId + '"]:checked').val();
					var products = JSON.parse(decodeURIComponent($(this).find('.sae-choice-products').val()));

					var actionType, selectedPoId;
					if (selectedOption === 'create_new') {
						actionType = 'create_new';
						selectedPoId = 0;
					} else {
						actionType = 'add_to_existing';
						selectedPoId = parseInt(selectedOption.replace('add_to_', ''));
					}

					choices.push({
						supplier_id: supplierId,
						action_type: actionType,
						selected_po_id: selectedPoId,
						products: products
					});
				});

				var results = [];

				function executeNext(index) {
					if (index >= choices.length) {
						var autoCreatedPos = $('.sae-choice-overlay').data('auto-created-pos') || [];
						$('.sae-choice-overlay').fadeOut(200);
						showFinalResults(results, autoCreatedPos);
						return;
					}

					var choice = choices[index];

					$.ajax({
						url: '<?php echo esc_js( $ajax_url ); ?>',
						type: 'POST',
						data: {
							action: 'sae_execute_po_choice',
							nonce: '<?php echo esc_js( $choice_nonce ); ?>',
							supplier_id: choice.supplier_id,
							action_type: choice.action_type,
							selected_po_id: choice.selected_po_id,
							products: JSON.stringify(choice.products)
						},
						success: function(response) {
							results.push(response);
							executeNext(index + 1);
						},
						error: function() {
							results.push({
								success: false,
								data: {message: '<?php echo esc_js( __( 'AJAX error', 'serenisoft-atum-enhancer' ) ); ?>'}
							});
							executeNext(index + 1);
						}
					});
				}

				executeNext(0);
			});

			// Handle "Cancel" button
			$(document).on('click', '#sae-cancel-choices', function() {
				$('.sae-choice-overlay').fadeOut(200);
			});

			// Show final results
			function showFinalResults(results, autoCreatedPos) {
				var successCount = 0;
				var createdPos = [];

				$.each(results, function(i, result) {
					if (result.success) {
						successCount++;
						createdPos.push(result.data);
					}
				});

				var allPOs = (autoCreatedPos || []).concat(createdPos);
				var totalPOs = allPOs.length;

				var $result = $('#sae-generate-result');
				var html = '<div class="notice notice-success" style="padding: 15px;">';
				html += '<p><strong><?php echo esc_js( __( 'PO Generation Complete', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';
				html += '<p><?php echo esc_js( __( 'Total:', 'serenisoft-atum-enhancer' ) ); ?> ' + totalPOs + ' <?php echo esc_js( __( 'Purchase Orders created', 'serenisoft-atum-enhancer' ) ); ?></p>';
				html += '<p><a href="' + window.location.href + '" class="button button-primary"><?php echo esc_js( __( 'Refresh Page', 'serenisoft-atum-enhancer' ) ); ?></a></p>';
				html += '</div>';

				$result.html(html);
			}
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
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cloning is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.7' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Serialization is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.7' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return POListButton instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
