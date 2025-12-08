<?php
/**
 * Settings integration for ATUM
 *
 * @package     SereniSoft\AtumEnhancer\Settings
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 1.0.0
 */

namespace SereniSoft\AtumEnhancer\Settings;

defined( 'ABSPATH' ) || die;

class Settings {

	/**
	 * The singleton instance holder
	 *
	 * @var Settings
	 */
	private static $instance;

	/**
	 * The settings tab key
	 */
	const TAB_KEY = 'sae_enhancer';

	/**
	 * Settings constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Add settings tab to ATUM.
		add_filter( 'atum/settings/tabs', array( $this, 'add_settings_tab' ) );

		// Add settings fields to ATUM.
		add_filter( 'atum/settings/defaults', array( $this, 'add_settings_defaults' ) );

	}

	/**
	 * Add the Enhancer tab to ATUM settings
	 *
	 * @since 1.0.0
	 *
	 * @param array $tabs Current ATUM settings tabs.
	 *
	 * @return array Modified tabs array.
	 */
	public function add_settings_tab( $tabs ) {

		$tabs[ self::TAB_KEY ] = array(
			'label'    => __( 'Enhancer', 'serenisoft-atum-enhancer' ),
			'icon'     => 'atmi-star',
			'sections' => array(
				'general'         => __( 'General Settings', 'serenisoft-atum-enhancer' ),
				'po_algorithm'    => __( 'Purchase Order Algorithm', 'serenisoft-atum-enhancer' ),
				'supplier_import' => __( 'Supplier Import', 'serenisoft-atum-enhancer' ),
			),
		);

		return $tabs;

	}

	/**
	 * Add the Enhancer settings fields
	 *
	 * @since 1.0.0
	 *
	 * @param array $defaults Current ATUM settings defaults.
	 *
	 * @return array Modified defaults array.
	 */
	public function add_settings_defaults( $defaults ) {

		// General Settings.
		$defaults['sae_admin_email'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'general',
			'name'    => __( 'Notification Email', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Email address for purchase order suggestion notifications. Defaults to admin email.', 'serenisoft-atum-enhancer' ),
			'type'    => 'text',
			'default' => get_option( 'admin_email' ),
		);

		$defaults['sae_enable_auto_suggestions'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'general',
			'name'    => __( 'Enable Automatic Suggestions', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Automatically generate purchase order suggestions based on stock levels and sales patterns.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'no',
		);

		$defaults['sae_generate_suggestions'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'general',
			'name'    => __( 'Generate PO Suggestions', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Manually trigger the generation of Purchase Order suggestions.', 'serenisoft-atum-enhancer' ),
			'type'    => 'html',
			'default' => '',
			'options' => array(
				'html' => $this->get_generate_button_html(),
			),
		);

		// Purchase Order Algorithm Settings.
		$defaults['sae_default_orders_per_year'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'po_algorithm',
			'name'    => __( 'Default Orders Per Year', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Default number of orders per year per supplier. Can be overridden per supplier.', 'serenisoft-atum-enhancer' ),
			'type'    => 'number',
			'default' => 4,
			'options' => array(
				'min'  => 1,
				'max'  => 12,
				'step' => 1,
			),
		);

		$defaults['sae_min_days_before_reorder'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'po_algorithm',
			'name'    => __( 'Minimum Days Between Orders', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Minimum number of days that must pass before a new order suggestion is created for the same supplier.', 'serenisoft-atum-enhancer' ),
			'type'    => 'number',
			'default' => 30,
			'options' => array(
				'min'  => 7,
				'max'  => 365,
				'step' => 1,
			),
		);

		$defaults['sae_stock_threshold_percent'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'po_algorithm',
			'name'    => __( 'Stock Threshold (%)', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'When stock falls below this percentage of optimal level, include product in suggestions.', 'serenisoft-atum-enhancer' ),
			'type'    => 'number',
			'default' => 25,
			'options' => array(
				'min'  => 5,
				'max'  => 50,
				'step' => 5,
			),
		);

		$defaults['sae_include_seasonal_analysis'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'po_algorithm',
			'name'    => __( 'Include Seasonal Analysis', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Analyze historical sales data to adjust reorder quantities based on seasonal patterns.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'yes',
		);

		$defaults['sae_service_level'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'po_algorithm',
			'name'    => __( 'Service Level', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'How often you want products to be in stock. 95% means you accept being out of stock ~18 days/year. Higher = more safety stock = less stockouts, but more capital tied up in inventory.', 'serenisoft-atum-enhancer' ),
			'type'    => 'select',
			'default' => '95',
			'options' => array(
				'options' => array(
					'90' => __( '90% - Out of stock ~36 days/year (minimal safety stock)', 'serenisoft-atum-enhancer' ),
					'95' => __( '95% - Out of stock ~18 days/year (recommended)', 'serenisoft-atum-enhancer' ),
					'99' => __( '99% - Out of stock ~4 days/year (maximum safety)', 'serenisoft-atum-enhancer' ),
				),
			),
		);

		// Supplier Import Settings.
		$defaults['sae_supplier_import'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'supplier_import',
			'name'    => __( 'Import Suppliers from CSV', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Upload a CSV file with supplier data. Duplicates (by code or name) will be skipped.', 'serenisoft-atum-enhancer' ),
			'type'    => 'html',
			'default' => '',
			'options' => array(
				'html' => $this->get_import_form_html(),
			),
		);

		return $defaults;

	}

	/**
	 * Get the import form HTML
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML for the import form.
	 */
	private function get_import_form_html() {

		$nonce = wp_create_nonce( 'sae_import_suppliers' );

		ob_start();
		?>
		<div class="sae-import-form">
			<p class="description">
				<?php esc_html_e( 'CSV format: Semicolon-separated with columns: Leverandørnummer, Navn, Organisasjonsnummer, Telefonnummer, Faksnummer, E-postadresse, Postadresse, Postnr., Sted, Land', 'serenisoft-atum-enhancer' ); ?>
			</p>
			<input type="file" id="sae-csv-file" accept=".csv" />
			<button type="button" class="button button-primary" id="sae-import-btn">
				<?php esc_html_e( 'Import Suppliers', 'serenisoft-atum-enhancer' ); ?>
			</button>
			<span class="spinner" style="float: none; margin-top: 0;"></span>
			<div id="sae-import-result" style="margin-top: 10px;"></div>
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#sae-import-btn').on('click', function() {
				var fileInput = $('#sae-csv-file')[0];
				if (!fileInput.files.length) {
					alert('<?php echo esc_js( __( 'Please select a CSV file.', 'serenisoft-atum-enhancer' ) ); ?>');
					return;
				}

				var formData = new FormData();
				formData.append('action', 'sae_import_suppliers');
				formData.append('nonce', '<?php echo esc_js( $nonce ); ?>');
				formData.append('csv_file', fileInput.files[0]);

				var $btn = $(this);
				var $spinner = $btn.next('.spinner');
				var $result = $('#sae-import-result');

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');

						if (response.success) {
							$result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							if (response.data.errors && response.data.errors.length) {
								$result.append('<div class="notice notice-warning"><p><strong><?php echo esc_js( __( 'Errors:', 'serenisoft-atum-enhancer' ) ); ?></strong><br>' + response.data.errors.join('<br>') + '</p></div>');
							}
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during import.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});
		});
		</script>
		<?php
		return ob_get_clean();

	}

	/**
	 * Get the generate button HTML
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML for the generate button.
	 */
	private function get_generate_button_html() {

		$nonce = wp_create_nonce( 'sae_generate_po_suggestions' );

		ob_start();
		?>
		<div class="sae-generate-form">
			<button type="button" class="button button-primary" id="sae-generate-btn">
				<?php esc_html_e( 'Generate PO Suggestions Now', 'serenisoft-atum-enhancer' ); ?>
			</button>
			<span class="spinner" style="float: none; margin-top: 0;"></span>
			<div id="sae-generate-result" style="margin-top: 10px;"></div>
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#sae-generate-btn').on('click', function() {
				if (!confirm('<?php echo esc_js( __( 'This will analyze all products and create draft POs for suppliers. Continue?', 'serenisoft-atum-enhancer' ) ); ?>')) {
					return;
				}

				var $btn = $(this);
				var $spinner = $btn.next('.spinner');
				var $result = $('#sae-generate-result');

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'sae_generate_po_suggestions',
						nonce: '<?php echo esc_js( $nonce ); ?>'
					},
					success: function(response) {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');

						if (response.success) {
							var html = '<div class="notice notice-success"><p>' + response.data.message + '</p>';
							if (response.data.created && response.data.created.length) {
								html += '<ul>';
								response.data.created.forEach(function(po) {
									html += '<li><a href="' + po.edit_url + '" target="_blank">' + po.supplier_name + '</a> - ' + po.items_count + ' <?php echo esc_js( __( 'items', 'serenisoft-atum-enhancer' ) ); ?></li>';
								});
								html += '</ul>';
							}
							html += '</div>';
							$result.html(html);

							if (response.data.errors && response.data.errors.length) {
								$result.append('<div class="notice notice-warning"><p><strong><?php echo esc_js( __( 'Errors:', 'serenisoft-atum-enhancer' ) ); ?></strong><br>' + response.data.errors.join('<br>') + '</p></div>');
							}
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during generation.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});
		});
		</script>
		<?php
		return ob_get_clean();

	}

	/**
	 * Get a setting value using ATUM Helpers
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed Setting value.
	 */
	public static function get( $key, $default = '' ) {

		if ( class_exists( '\Atum\Inc\Helpers' ) ) {
			return \Atum\Inc\Helpers::get_option( $key, $default );
		}

		return $default;

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
	 * @return Settings instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
