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
	 * Meta key for closed periods
	 */
	const META_CLOSED_PERIODS = '_sae_closed_periods';

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

			<?php
			$closed_periods = self::get_closed_periods( $post->ID );
			$global_presets = \SereniSoft\AtumEnhancer\Components\ClosedPeriodsHelper::get_global_presets();
			?>

			<!-- Closed Periods -->
			<div class="form-field form-field-wide">
				<label><?php esc_html_e( 'Closed Periods', 'serenisoft-atum-enhancer' ); ?></label>
				<p class="description">
					<?php esc_html_e( 'Select when this supplier is closed (vacations, holidays, etc.). The system will automatically adjust ordering to prevent stockouts during closures.', 'serenisoft-atum-enhancer' ); ?>
				</p>

				<?php if ( ! empty( $global_presets ) ) : ?>
				<div style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 4px solid #2271b1;">
					<strong><?php esc_html_e( 'Global Presets:', 'serenisoft-atum-enhancer' ); ?></strong>
					<?php foreach ( $global_presets as $preset ) : ?>
					<label style="display: block; margin: 5px 0 5px 10px;">
						<input type="checkbox" name="sae_closed_periods_presets[]" value="<?php echo esc_attr( $preset['id'] ); ?>"
							<?php checked( in_array( $preset['id'], $closed_periods['presets'] ?? array(), true ) ); ?>>
						<?php echo esc_html( $preset['name'] ); ?>
						<span style="color: #666;">(<?php echo esc_html( $preset['start_date'] . ' - ' . $preset['end_date'] ); ?>)</span>
					</label>
					<?php endforeach; ?>
				</div>
				<?php else : ?>
				<p style="margin: 10px 0; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
					<?php
					/* translators: %s: link to settings page */
					printf(
						esc_html__( 'No global presets defined. You can %s or add custom periods below.', 'serenisoft-atum-enhancer' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=atum-settings&tab=sae_enhancer&section=sae_closed_periods' ) ) . '">' . esc_html__( 'create global presets in settings', 'serenisoft-atum-enhancer' ) . '</a>'
					);
					?>
				</p>
				<?php endif; ?>

				<div style="margin-top: 15px;">
					<strong><?php esc_html_e( 'Custom Periods (supplier-specific):', 'serenisoft-atum-enhancer' ); ?></strong>
					<table class="sae-custom-periods widefat" style="margin-top: 10px;">
						<thead>
							<tr>
								<th style="width: 40%;"><?php esc_html_e( 'Name', 'serenisoft-atum-enhancer' ); ?></th>
								<th style="width: 25%;"><?php esc_html_e( 'Start (DD-MM)', 'serenisoft-atum-enhancer' ); ?></th>
								<th style="width: 25%;"><?php esc_html_e( 'End (DD-MM)', 'serenisoft-atum-enhancer' ); ?></th>
								<th style="width: 10%;"><?php esc_html_e( 'Remove', 'serenisoft-atum-enhancer' ); ?></th>
							</tr>
						</thead>
						<tbody id="sae-custom-periods-list"></tbody>
					</table>
					<button type="button" class="button" id="sae-add-custom-period" style="margin-top: 10px;">
						<?php esc_html_e( '+ Add Custom Period', 'serenisoft-atum-enhancer' ); ?>
					</button>
				</div>

				<input type="hidden" id="sae_custom_periods_data" name="sae_custom_periods_data"
					value="<?php echo esc_attr( wp_json_encode( $closed_periods['custom'] ?? array() ) ); ?>">
			</div>

			<style>
			.sae-custom-periods { border-collapse: collapse; }
			.sae-custom-periods th, .sae-custom-periods td { padding: 8px; border: 1px solid #ddd; }
			.sae-custom-periods th { background: #f5f5f5; }
			.sae-custom-periods input[type="text"] { width: 95%; padding: 5px; }
			.remove-custom { color: #dc3232; text-decoration: none; cursor: pointer; }
			</style>

			<script>
			jQuery(document).ready(function($) {
				var customPeriods = <?php echo wp_json_encode( $closed_periods['custom'] ?? array() ); ?>;

				function renderCustom() {
					var html = '';
					$.each(customPeriods, function(i, p) {
						html += '<tr data-index="' + i + '">';
						html += '<td><input type="text" class="custom-name" value="' + (p.name || '') + '" placeholder="<?php echo esc_js( __( 'e.g., Factory Maintenance', 'serenisoft-atum-enhancer' ) ); ?>"></td>';
						html += '<td><input type="text" class="custom-start" value="' + (p.start_date || '') + '" pattern="\\d{2}-\\d{2}" placeholder="01-07"></td>';
						html += '<td><input type="text" class="custom-end" value="' + (p.end_date || '') + '" pattern="\\d{2}-\\d{2}" placeholder="15-08"></td>';
						html += '<td style="text-align: center;"><a href="#" class="remove-custom"><?php echo esc_js( __( 'Remove', 'serenisoft-atum-enhancer' ) ); ?></a></td>';
						html += '</tr>';
					});

					if (!html) {
						html = '<tr><td colspan="4" style="text-align:center;color:#999;"><?php echo esc_js( __( 'No custom periods. Click "+ Add Custom Period" to create one.', 'serenisoft-atum-enhancer' ) ); ?></td></tr>';
					}

					$('#sae-custom-periods-list').html(html);
				}

				renderCustom();

				$('#sae-add-custom-period').on('click', function(e) {
					e.preventDefault();
					customPeriods.push({ id: 'custom_' + Date.now(), name: '', start_date: '', end_date: '' });
					renderCustom();
					$('#sae_custom_periods_data').val(JSON.stringify(customPeriods));
				});

				$(document).on('click', '.remove-custom', function(e) {
					e.preventDefault();
					customPeriods.splice($(this).closest('tr').data('index'), 1);
					renderCustom();
					$('#sae_custom_periods_data').val(JSON.stringify(customPeriods));
				});

				$(document).on('input', '#sae-custom-periods-list input', function() {
					var row = $(this).closest('tr');
					var i = row.data('index');
					customPeriods[i].name = row.find('.custom-name').val();
					customPeriods[i].start_date = row.find('.custom-start').val();
					customPeriods[i].end_date = row.find('.custom-end').val();
					$('#sae_custom_periods_data').val(JSON.stringify(customPeriods));
				});
			});
			</script>

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

		// Save closed periods.
		$closed_periods_data = array(
			'presets' => array(),
			'custom'  => array(),
		);

		if ( isset( $_POST['sae_closed_periods_presets'] ) && is_array( $_POST['sae_closed_periods_presets'] ) ) {
			$closed_periods_data['presets'] = array_map( 'sanitize_text_field', $_POST['sae_closed_periods_presets'] );
		}

		if ( isset( $_POST['sae_custom_periods_data'] ) ) {
			$custom_decoded = json_decode( sanitize_text_field( $_POST['sae_custom_periods_data'] ), true );
			if ( is_array( $custom_decoded ) ) {
				foreach ( $custom_decoded as $period ) {
					if ( ! empty( $period['start_date'] ) && ! empty( $period['end_date'] )
						&& preg_match( '/^\d{2}-\d{2}$/', $period['start_date'] )
						&& preg_match( '/^\d{2}-\d{2}$/', $period['end_date'] ) ) {
						$closed_periods_data['custom'][] = array(
							'id'         => sanitize_text_field( $period['id'] ?? 'custom_' . time() ),
							'name'       => sanitize_text_field( $period['name'] ?? '' ),
							'start_date' => sanitize_text_field( $period['start_date'] ),
							'end_date'   => sanitize_text_field( $period['end_date'] ),
						);
					}
				}
			}
		}

		update_post_meta( $post_id, self::META_CLOSED_PERIODS, $closed_periods_data );

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

	/**
	 * Get closed periods for a supplier
	 *
	 * @since 0.9.0
	 *
	 * @param int $supplier_id Supplier ID.
	 *
	 * @return array Closed periods data with 'presets' and 'custom' keys.
	 */
	public static function get_closed_periods( $supplier_id ) {

		$value = get_post_meta( $supplier_id, self::META_CLOSED_PERIODS, true );

		return is_array( $value ) ? $value : array(
			'presets' => array(),
			'custom'  => array(),
		);

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
