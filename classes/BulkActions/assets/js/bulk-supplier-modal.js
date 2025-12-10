/**
 * Bulk Supplier Assignment Modal
 *
 * Handles the supplier selection modal for bulk assignment in ATUM Stock Central.
 * Uses ATUM's existing atum_json_search_suppliers AJAX action for supplier search.
 *
 * @package SereniSoft ATUM Enhancer
 * @since 0.5.0
 */

(function($) {
	'use strict';

	/**
	 * Hook into ATUM's bulk action filter (WordPress hooks system)
	 *
	 * CRITICAL: Use WordPress hooks, not jQuery events!
	 * ATUM checks this filter BEFORE sending AJAX (line 113 of _bulk-actions.ts)
	 *
	 * This intercepts the bulk action BEFORE ATUM sends its AJAX request,
	 * allowing us to show our modal instead.
	 */
	wp.hooks.addFilter(
		'atum_listTable_applyBulkAction',
		'serenisoft-atum-enhancer',
		function( allow, bulkAction, selectedItems, bulkActionsInstance ) {
			// Only intercept our custom action
			if ( bulkAction !== 'sae_set_supplier' ) {
				return allow; // Not ours, let ATUM continue
			}

			// Show our modal instead of ATUM's AJAX
			showSupplierModal( selectedItems );

			// Return FALSE to prevent ATUM from sending AJAX
			return false;
		}
	);

	/**
	 * Show supplier selection modal
	 *
	 * @param {Array} selectedItems - Array of product IDs to assign supplier to
	 */
	function showSupplierModal(selectedItems) {
		var productIds = selectedItems;
		// Create modal HTML
		var modalHtml = `
			<div class="sae-bulk-supplier-overlay">
				<div class="sae-bulk-supplier-modal">
					<h2>${saeBulkSupplier.selectSupplier}</h2>
					<div class="sae-modal-body">
						<label for="sae-supplier-select">${saeBulkSupplier.selectSupplier}</label>
						<select id="sae-supplier-select"
						        class="wc-product-search atum-enhanced-select"
						        data-action="atum_json_search_suppliers"
						        data-placeholder="${saeBulkSupplier.searchSupplier}"
						        data-allow_clear="true"
						        data-multiple="false"
						        data-minimum_input_length="1"
						        style="width: 100%;">
						</select>
					</div>
					<div class="sae-modal-footer">
						<button type="button" class="button button-primary" id="sae-bulk-assign-btn">
							${saeBulkSupplier.assign}
						</button>
						<button type="button" class="button" id="sae-bulk-cancel-btn">
							${saeBulkSupplier.cancel}
						</button>
					</div>
				</div>
			</div>
		`;

		// Append modal to body
		$('body').append(modalHtml);

		// CRITICAL: Use WooCommerce's enhanced select initialization
		// This is IDENTICAL to how ATUM initializes supplier selects
		$('#sae-supplier-select').selectWoo({
			ajax: {
				url: ajaxurl,
				dataType: 'json',
				delay: 250,
				data: function(params) {
					return {
						action: 'atum_json_search_suppliers',
						term: params.term,
						security: saeBulkSupplier.searchNonce // CRITICAL: Use search-products nonce
					};
				},
				processResults: function(data) {
					// ATUM returns: { "123": "Supplier Name", "456": "Another Supplier" }
					// Select2 expects: { results: [ { id: "123", text: "Supplier Name" } ] }
					var results = [];
					if (data && typeof data === 'object') {
						results = Object.keys(data).map(function(id) {
							return {
								id: id,
								text: data[id]
							};
						});
					}
					return { results: results };
				}
			},
			minimumInputLength: 1,
			allowClear: true,
			placeholder: saeBulkSupplier.searchSupplier
		});

		// Handle assign button click
		$('#sae-bulk-assign-btn').on('click', function() {
			var supplierId = $('#sae-supplier-select').val();

			if (!supplierId) {
				alert(saeBulkSupplier.selectSupplierFirst);
				return;
			}

			// Disable button during processing
			var $btn = $(this);
			$btn.prop('disabled', true).text('Processing...');

			// Send AJAX to our handler
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'sae_bulk_assign_supplier',
					security: saeBulkSupplier.nonce,
					supplier_id: supplierId,
					product_ids: productIds
				},
				success: function(response) {
					closeModal();

					if (response.success) {
						// If there were any errors, show them
						if (response.data.errors && response.data.errors.length > 0) {
							var errorMsg = 'Some products had errors:\n' + response.data.errors.join('\n');
							alert(errorMsg);
						}

						// Reload table to show updated suppliers
						window.location.reload();
					} else {
						alert(response.data.message || response.data || saeBulkSupplier.error);
					}
				},
				error: function(xhr, status, error) {
					closeModal();
					console.error('AJAX Error:', status, error);
					alert(saeBulkSupplier.error);
				}
			});
		});

		// Handle cancel button click
		$('#sae-bulk-cancel-btn').on('click', function() {
			closeModal();
		});

		// Handle overlay click (close modal when clicking outside)
		$('.sae-bulk-supplier-overlay').on('click', function(e) {
			if (e.target === this) {
				closeModal();
			}
		});
	}

	/**
	 * Close and remove modal
	 */
	function closeModal() {
		$('.sae-bulk-supplier-overlay').fadeOut(200, function() {
			$(this).remove();
		});
	}

})(jQuery);
