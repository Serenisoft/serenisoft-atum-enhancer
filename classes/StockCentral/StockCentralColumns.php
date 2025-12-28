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

		// Handle AJAX save for MOQ (direct save).
		add_action( 'wp_ajax_sae_save_moq', array( $this, 'ajax_save_moq' ) );

		// Add scripts for Stock Central.
		add_action( 'admin_footer', array( $this, 'add_inline_script' ) );

		// Add Suggested Qty column to the Purchasing group.
		add_filter( 'atum/stock_central_list/column_group_members', array( $this, 'add_suggested_qty_column_to_group' ), 11 );

		// Add Suggested Qty column definition.
		add_filter( 'atum/stock_central_list/table_columns', array( $this, 'add_suggested_qty_column' ), 11 );

		// Render Suggested Qty column value.
		add_filter( 'atum/list_table/column_default__sae_suggested_qty', array( $this, 'render_suggested_qty_column' ), 10, 4 );

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
			'<input type="number" class="sae-moq-input" data-meta="sae_moq" value="%s" min="1" step="1" placeholder="1" style="width:65px;text-align:center;">',
			$moq > 1 ? esc_attr( $moq ) : ''
		);

	}

	/**
	 * Add Suggested Qty column to the Purchasing group
	 *
	 * @since 0.9.24
	 *
	 * @param array $groups Column groups.
	 *
	 * @return array
	 */
	public function add_suggested_qty_column_to_group( $groups ) {

		foreach ( $groups as $group_key => &$group ) {
			if ( 'purchasing' === $group_key && isset( $group['members'] ) ) {
				$group['members'][] = '_sae_suggested_qty';
			}
		}

		return $groups;

	}

	/**
	 * Add Suggested Qty column definition
	 *
	 * @since 0.9.24
	 *
	 * @param array $columns Table columns.
	 *
	 * @return array
	 */
	public function add_suggested_qty_column( $columns ) {

		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Add Suggested Qty after MOQ.
			if ( '_sae_moq' === $key ) {
				$new_columns['_sae_suggested_qty'] = __( 'Suggested', 'serenisoft-atum-enhancer' );
			}
		}

		return $new_columns;

	}

	/**
	 * Render Suggested Qty column value
	 *
	 * @since 0.9.24
	 *
	 * @param string       $column_item Default column content.
	 * @param \WP_Post     $item        The post object.
	 * @param \WC_Product  $product     The product object.
	 * @param object       $list_table  The list table instance.
	 *
	 * @return string
	 */
	public function render_suggested_qty_column( $column_item, $item, $product, $list_table ) {

		$product_id    = $product->get_id();
		$suggested_qty = get_post_meta( $product_id, '_sae_suggested_qty', true );

		if ( empty( $suggested_qty ) || $suggested_qty < 1 ) {
			return '<span class="set-meta" style="display:block;text-align:center;color:#999;">-</span>';
		}

		return sprintf(
			'<span class="set-meta" style="display:block;text-align:center;font-weight:600;color:#0073aa;">%d</span>',
			absint( $suggested_qty )
		);

	}

	/**
	 * AJAX handler to save MOQ directly
	 *
	 * @since 0.9.3
	 */
	public function ajax_save_moq() {

		check_ajax_referer( 'sae_save_moq', 'security' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$moq        = isset( $_POST['moq'] ) ? absint( $_POST['moq'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid product ID' );
		}

		if ( $moq > 1 ) {
			update_post_meta( $product_id, ProductFields::META_MOQ, $moq );
		} else {
			delete_post_meta( $product_id, ProductFields::META_MOQ );
		}

		wp_send_json_success( array( 'moq' => $moq ) );

	}

	/**
	 * Add inline script for MOQ field handling
	 *
	 * @since 0.9.3
	 */
	public function add_inline_script() {

		$screen = get_current_screen();

		if ( ! $screen || 'atum-inventory_page_atum-stock-central' !== $screen->id ) {
			return;
		}

		$nonce = wp_create_nonce( 'sae_save_moq' );
		?>
		<script type="text/javascript">
		jQuery(function($) {
			var saeMoqNonce = '<?php echo esc_js( $nonce ); ?>';

			// Save MOQ on change (when leaving field).
			$(document).on('change', '.sae-moq-input', function() {
				var $input = $(this);
				var $row = $input.closest('tr');
				var productId = $row.data('id');
				var moqValue = $input.val() || '';

				// Visual feedback.
				$input.css('opacity', '0.5');

				$.post(ajaxurl, {
					action: 'sae_save_moq',
					security: saeMoqNonce,
					product_id: productId,
					moq: moqValue
				}, function(response) {
					$input.css('opacity', '1');
					if (response.success) {
						$input.css('border-color', '#46b450');
						setTimeout(function() {
							$input.css('border-color', '#ddd');
						}, 1000);
					} else {
						$input.css('border-color', '#dc3232');
					}
				}).fail(function() {
					$input.css('opacity', '1');
					$input.css('border-color', '#dc3232');
				});
			});
		});
		</script>
		<style type="text/css">
		.sae-moq-input {
			border: 1px solid #ddd;
			border-radius: 3px;
			padding: 2px 4px;
			transition: border-color 0.3s, opacity 0.2s;
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
