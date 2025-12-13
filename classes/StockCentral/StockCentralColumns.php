<?php
/**
 * Stock Central custom columns for ATUM Enhancer
 *
 * @package     SereniSoft\AtumEnhancer\StockCentral
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 0.9.3
 */

namespace SereniSoft\AtumEnhancer\StockCentral;

defined( 'ABSPATH' ) || die;

use SereniSoft\AtumEnhancer\Products\ProductFields;

class StockCentralColumns {

	/**
	 * The singleton instance holder
	 *
	 * @var StockCentralColumns
	 */
	private static $instance;

	/**
	 * StockCentralColumns constructor
	 *
	 * @since 0.9.3
	 */
	private function __construct() {

		// Add MOQ column to the Purchasing group.
		add_filter( 'atum/stock_central_list/column_group_members', array( $this, 'add_moq_column_to_group' ) );

		// Add MOQ column definition.
		add_filter( 'atum/stock_central_list/table_columns', array( $this, 'add_moq_column' ) );

		// Render MOQ column value.
		add_filter( 'atum/list_table/column_default__sae_moq', array( $this, 'render_moq_column' ), 10, 4 );

		// Handle AJAX save for MOQ.
		add_filter( 'atum/product_data', array( $this, 'save_moq_via_atum' ), 10, 2 );

		// Add scripts for Stock Central.
		add_action( 'admin_footer', array( $this, 'add_inline_script' ) );

	}

	/**
	 * Add MOQ column to the Purchasing group
	 *
	 * @since 0.9.3
	 *
	 * @param array $groups Column groups.
	 *
	 * @return array
	 */
	public function add_moq_column_to_group( $groups ) {

		foreach ( $groups as $group_key => &$group ) {
			// Add MOQ to the Purchasing group (where Supplier is).
			if ( 'purchasing' === $group_key && isset( $group['members'] ) ) {
				$group['members'][] = '_sae_moq';
			}
		}

		return $groups;

	}

	/**
	 * Add MOQ column definition
	 *
	 * @since 0.9.3
	 *
	 * @param array $columns Table columns.
	 *
	 * @return array
	 */
	public function add_moq_column( $columns ) {

		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Add MOQ after purchase price.
			if ( '_purchase_price' === $key ) {
				$new_columns['_sae_moq'] = __( 'MOQ', 'serenisoft-atum-enhancer' );
			}
		}

		return $new_columns;

	}

	/**
	 * Render MOQ column value
	 *
	 * @since 0.9.3
	 *
	 * @param string       $column_item Default column content.
	 * @param \WP_Post     $item        The post object.
	 * @param \WC_Product  $product     The product object.
	 * @param object       $list_table  The list table instance.
	 *
	 * @return string
	 */
	public function render_moq_column( $column_item, $item, $product, $list_table ) {

		$product_id = $product->get_id();
		$moq        = ProductFields::get_moq( $product_id );

		// Direct input field for easy bulk editing.
		return sprintf(
			'<input type="number" class="sae-moq-input" data-meta="sae_moq" value="%s" min="1" step="1" placeholder="1" style="width:50px;text-align:center;">',
			$moq > 1 ? esc_attr( $moq ) : ''
		);

	}

	/**
	 * Save MOQ when ATUM saves product data
	 *
	 * @since 0.9.3
	 *
	 * @param array $product_data Product data to save.
	 * @param int   $product_id   Product ID.
	 *
	 * @return array
	 */
	public function save_moq_via_atum( $product_data, $product_id ) {

		if ( isset( $product_data['sae_moq'] ) ) {
			$moq = absint( $product_data['sae_moq'] );

			if ( $moq > 1 ) {
				update_post_meta( $product_id, ProductFields::META_MOQ, $moq );
			} else {
				delete_post_meta( $product_id, ProductFields::META_MOQ );
			}

			// Remove from array so ATUM doesn't try to handle it.
			unset( $product_data['sae_moq'] );
		}

		return $product_data;

	}

	/**
	 * Add inline script for MOQ field handling
	 *
	 * @since 0.9.3
	 */
	public function add_inline_script() {

		$screen = get_current_screen();

		if ( ! $screen || 'toplevel_page_stock-central' !== $screen->id ) {
			return;
		}

		?>
		<script type="text/javascript">
		jQuery(function($) {
			// Track changes in MOQ input fields.
			$(document).on('change', '.sae-moq-input', function() {
				var $input = $(this);
				var $row = $input.closest('tr');

				// Mark the row as edited (ATUM's pattern).
				$row.addClass('dirty');

				// Ensure ATUM knows this field changed.
				$input.attr('data-changed', 'yes');
			});

			// Before ATUM collects data, add our MOQ values.
			$(document).on('atum-edited-cols-data', function(e, editedCols, $rows) {
				$rows.each(function() {
					var $row = $(this);
					var productId = $row.data('id');
					var $moqInput = $row.find('.sae-moq-input');

					if ($moqInput.length && $moqInput.attr('data-changed') === 'yes') {
						if (!editedCols[productId]) {
							editedCols[productId] = {};
						}
						editedCols[productId].sae_moq = $moqInput.val() || '1';
					}
				});
			});
		});
		</script>
		<style type="text/css">
		.sae-moq-input {
			border: 1px solid #ddd;
			border-radius: 3px;
			padding: 2px 4px;
		}
		.sae-moq-input:focus {
			border-color: #00a8f0;
			outline: none;
		}
		</style>
		<?php

	}


	/*******************
	 * Instance methods
	 *******************/

	/**
	 * Cannot be cloned
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Cloning is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.3' );
	}

	/**
	 * Cannot be serialized
	 */
	public function __sleep() {
		_doing_it_wrong( __FUNCTION__, esc_attr__( 'Serialization is not allowed.', 'serenisoft-atum-enhancer' ), '0.9.3' );
	}

	/**
	 * Get Singleton instance
	 *
	 * @return StockCentralColumns instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
