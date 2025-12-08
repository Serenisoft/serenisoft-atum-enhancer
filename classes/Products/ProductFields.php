<?php
/**
 * Product custom fields for ATUM Enhancer
 *
 * @package     SereniSoft\AtumEnhancer\Products
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 1.0.0
 */

namespace SereniSoft\AtumEnhancer\Products;

defined( 'ABSPATH' ) || die;

class ProductFields {

	/**
	 * The singleton instance holder
	 *
	 * @var ProductFields
	 */
	private static $instance;

	/**
	 * Meta key for PO note
	 */
	const META_PO_NOTE = '_sae_po_note';

	/**
	 * ProductFields constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Add field to ATUM product data panel.
		add_action( 'atum/after_product_data_panel', array( $this, 'render_product_fields' ) );

		// Save field when product is saved.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ), 20 );

		// Also save for variations.
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 20, 2 );

		// Add field to variations.
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_fields' ), 20, 3 );

	}

	/**
	 * Render product fields in ATUM panel
	 *
	 * @since 1.0.0
	 */
	public function render_product_fields() {

		global $post;

		if ( ! $post ) {
			return;
		}

		$po_note = get_post_meta( $post->ID, self::META_PO_NOTE, true );

		?>
		<div class="options_group">
			<p class="form-field _sae_po_note_field">
				<label for="_sae_po_note"><?php esc_html_e( 'PO Note', 'serenisoft-atum-enhancer' ); ?></label>
				<textarea
					id="_sae_po_note"
					name="_sae_po_note"
					rows="3"
					maxlength="1000"
					class="short"
					style="width: 50%; height: 70px;"
					placeholder="<?php esc_attr_e( 'Note to include on Purchase Order line for this product...', 'serenisoft-atum-enhancer' ); ?>"><?php echo esc_textarea( $po_note ); ?></textarea>
				<?php echo wc_help_tip( __( 'This note will appear on the order line when this product is added to a Purchase Order.', 'serenisoft-atum-enhancer' ) ); ?>
			</p>
		</div>
		<?php

	}

	/**
	 * Render variation fields
	 *
	 * @since 1.0.0
	 *
	 * @param int      $loop           Loop index.
	 * @param array    $variation_data Variation data.
	 * @param \WP_Post $variation      Variation post object.
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ) {

		$po_note = get_post_meta( $variation->ID, self::META_PO_NOTE, true );

		?>
		<div class="form-row form-row-full sae-variation-po-note">
			<label for="_sae_po_note_<?php echo esc_attr( $loop ); ?>">
				<?php esc_html_e( 'PO Note', 'serenisoft-atum-enhancer' ); ?>
				<?php echo wc_help_tip( __( 'Note to include on Purchase Order line for this variation.', 'serenisoft-atum-enhancer' ) ); ?>
			</label>
			<textarea
				id="_sae_po_note_<?php echo esc_attr( $loop ); ?>"
				name="_sae_variation_po_note[<?php echo esc_attr( $loop ); ?>]"
				rows="2"
				maxlength="1000"
				placeholder="<?php esc_attr_e( 'PO note for this variation...', 'serenisoft-atum-enhancer' ); ?>"><?php echo esc_textarea( $po_note ); ?></textarea>
		</div>
		<?php

	}

	/**
	 * Save product fields
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_fields( $product_id ) {

		if ( isset( $_POST['_sae_po_note'] ) ) {
			$value = sanitize_textarea_field( wp_unslash( $_POST['_sae_po_note'] ) );
			$value = mb_substr( $value, 0, 1000 );

			if ( '' === $value ) {
				delete_post_meta( $product_id, self::META_PO_NOTE );
			} else {
				update_post_meta( $product_id, self::META_PO_NOTE, $value );
			}
		}

	}

	/**
	 * Save variation fields
	 *
	 * @since 1.0.0
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 */
	public function save_variation_fields( $variation_id, $loop ) {

		if ( isset( $_POST['_sae_variation_po_note'][ $loop ] ) ) {
			$value = sanitize_textarea_field( wp_unslash( $_POST['_sae_variation_po_note'][ $loop ] ) );
			$value = mb_substr( $value, 0, 1000 );

			if ( '' === $value ) {
				delete_post_meta( $variation_id, self::META_PO_NOTE );
			} else {
				update_post_meta( $variation_id, self::META_PO_NOTE, $value );
			}
		}

	}

	/**
	 * Get PO note for a product
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return string PO note or empty string if not set.
	 */
	public static function get_po_note( $product_id ) {

		$value = get_post_meta( $product_id, self::META_PO_NOTE, true );

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
	 * @return ProductFields instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
