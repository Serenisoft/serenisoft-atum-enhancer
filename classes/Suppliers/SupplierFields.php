<?php
/**
 * Supplier custom fields for ATUM Enhancer
 *
 * @package     SereniSoft\AtumEnhancer\Suppliers
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 1.0.0
 */

namespace SereniSoft\AtumEnhancer\Suppliers;

defined( 'ABSPATH' ) || die;

use Atum\Suppliers\Suppliers;

class SupplierFields {

	/**
	 * The singleton instance holder
	 *
	 * @var SupplierFields
	 */
	private static $instance;

	/**
	 * Meta key for orders per year
	 */
	const META_ORDERS_PER_YEAR = '_sae_orders_per_year';

	/**
	 * Meta key for PO note
	 */
	const META_PO_NOTE = '_sae_po_note';

	/**
	 * SupplierFields constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Add meta box to supplier edit screen.
		add_action( 'add_meta_boxes_' . Suppliers::POST_TYPE, array( $this, 'add_meta_boxes' ), 40 );

		// Save meta box data.
		add_action( 'save_post_' . Suppliers::POST_TYPE, array( $this, 'save_meta_boxes' ), 20 );

	}

	/**
	 * Add meta boxes to Supplier edit screen
	 *
	 * @since 1.0.0
	 */
	public function add_meta_boxes() {

		add_meta_box(
			'sae_supplier_settings',
			__( 'Enhancer Settings', 'serenisoft-atum-enhancer' ),
			array( $this, 'render_meta_box' ),
			Suppliers::POST_TYPE,
			'normal',
			'default'
		);

	}

	/**
	 * Render the meta box content
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Current post object.
	 */
	public function render_meta_box( $post ) {

		$orders_per_year = get_post_meta( $post->ID, self::META_ORDERS_PER_YEAR, true );
		$po_note         = get_post_meta( $post->ID, self::META_PO_NOTE, true );

		wp_nonce_field( 'sae_supplier_meta', 'sae_supplier_nonce' );
		?>
		<div class="atum-meta-box supplier">

			<p class="description">
				<?php esc_html_e( 'Override default settings from ATUM Enhancer for this supplier.', 'serenisoft-atum-enhancer' ); ?>
			</p>

			<div class="form-field form-field-wide">
				<label for="sae_orders_per_year"><?php esc_html_e( 'Orders Per Year', 'serenisoft-atum-enhancer' ); ?></label>
				<input type="number"
					step="1"
					min="1"
					max="12"
					id="sae_orders_per_year"
					name="sae_orders_per_year"
					value="<?php echo esc_attr( $orders_per_year ); ?>"
					placeholder="<?php esc_attr_e( 'Use default', 'serenisoft-atum-enhancer' ); ?>">
				<p class="description">
					<?php esc_html_e( 'How many times per year to order from this supplier. Leave empty to use the global default.', 'serenisoft-atum-enhancer' ); ?>
				</p>
			</div>

			<div class="form-field form-field-wide">
				<label for="sae_po_note"><?php esc_html_e( 'PO Note', 'serenisoft-atum-enhancer' ); ?></label>
				<textarea
					id="sae_po_note"
					name="sae_po_note"
					rows="4"
					maxlength="1000"
					placeholder="<?php esc_attr_e( 'Note to include at the top of Purchase Orders for this supplier...', 'serenisoft-atum-enhancer' ); ?>"><?php echo esc_textarea( $po_note ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'This note will appear at the top of all Purchase Orders generated for this supplier.', 'serenisoft-atum-enhancer' ); ?>
				</p>
			</div>

		</div>
		<?php

	}

	/**
	 * Save meta box data
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_boxes( $post_id ) {

		// Verify nonce.
		if ( ! isset( $_POST['sae_supplier_nonce'] ) || ! wp_verify_nonce( $_POST['sae_supplier_nonce'], 'sae_supplier_meta' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save orders per year.
		if ( isset( $_POST['sae_orders_per_year'] ) ) {
			$value = sanitize_text_field( $_POST['sae_orders_per_year'] );

			if ( '' === $value ) {
				delete_post_meta( $post_id, self::META_ORDERS_PER_YEAR );
			} else {
				$value = absint( $value );
				$value = max( 1, min( 12, $value ) ); // Clamp between 1-12.
				update_post_meta( $post_id, self::META_ORDERS_PER_YEAR, $value );
			}
		}

		// Save PO note.
		if ( isset( $_POST['sae_po_note'] ) ) {
			$value = sanitize_textarea_field( $_POST['sae_po_note'] );
			$value = mb_substr( $value, 0, 1000 ); // Limit to 1000 characters.

			if ( '' === $value ) {
				delete_post_meta( $post_id, self::META_PO_NOTE );
			} else {
				update_post_meta( $post_id, self::META_PO_NOTE, $value );
			}
		}

	}

	/**
	 * Get orders per year for a supplier
	 *
	 * @since 1.0.0
	 *
	 * @param int $supplier_id Supplier ID.
	 *
	 * @return int|null Orders per year or null if not set.
	 */
	public static function get_orders_per_year( $supplier_id ) {

		$value = get_post_meta( $supplier_id, self::META_ORDERS_PER_YEAR, true );

		if ( '' === $value || false === $value ) {
			return null;
		}

		return absint( $value );

	}

	/**
	 * Get PO note for a supplier
	 *
	 * @since 1.0.0
	 *
	 * @param int $supplier_id Supplier ID.
	 *
	 * @return string PO note or empty string if not set.
	 */
	public static function get_po_note( $supplier_id ) {

		$value = get_post_meta( $supplier_id, self::META_PO_NOTE, true );

		return ! empty( $value ) ? $value : '';

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
	 * @return SupplierFields instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
