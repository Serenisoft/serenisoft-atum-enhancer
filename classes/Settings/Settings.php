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

		// Inject HTML for generate button.
		add_filter( 'atum/settings/display_html', array( $this, 'display_generate_button' ), 10, 2 );

		// Add JavaScript in footer (inline scripts are stripped from HTML fields).
		add_action( 'admin_print_footer_scripts', array( $this, 'print_footer_scripts' ) );

		// Sanitize custom fields before saving.
		add_filter( 'atum/settings/sanitize_option', array( $this, 'sanitize_custom_fields' ), 10, 3 );

		// AJAX handler for saving closed periods (bypasses ATUM's HTML field limitation).
		add_action( 'wp_ajax_sae_save_closed_periods', array( $this, 'ajax_save_closed_periods' ) );

		// AJAX handler for exporting suppliers.
		add_action( 'wp_ajax_sae_export_suppliers', array( $this, 'ajax_export_suppliers' ) );

		// AJAX handlers for product-supplier mapping.
		add_action( 'wp_ajax_sae_export_product_suppliers', array( $this, 'ajax_export_product_suppliers' ) );
		add_action( 'wp_ajax_sae_preview_product_suppliers', array( $this, 'ajax_preview_product_suppliers' ) );
		add_action( 'wp_ajax_sae_import_product_suppliers', array( $this, 'ajax_import_product_suppliers' ) );

		// Filter to hide backordered on PO PDF.
		add_filter( 'atum/atum_order/po_report/hidden_item_meta', array( $this, 'filter_po_pdf_hidden_meta' ) );

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
				'sae_po_suggestions'      => __( 'PO Suggestions', 'serenisoft-atum-enhancer' ),
				'sae_predictive_ordering' => __( 'Predictive Ordering', 'serenisoft-atum-enhancer' ),
				'sae_closed_periods'      => __( 'Closed Periods', 'serenisoft-atum-enhancer' ),
				'sae_po_pdf'              => __( 'PO PDF', 'serenisoft-atum-enhancer' ),
				'sae_po_email'            => __( 'PO Email', 'serenisoft-atum-enhancer' ),
				'sae_import_export'       => __( 'Import & Export', 'serenisoft-atum-enhancer' ),
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

		// PO Suggestions Settings.
		$defaults['sae_default_orders_per_year'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_suggestions',
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

		$defaults['sae_enable_auto_suggestions'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_suggestions',
			'name'    => __( 'Enable Automatic Suggestions', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Automatically generate purchase order suggestions based on stock levels and sales patterns.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'no',
		);

		$defaults['sae_cron_frequency'] = array(
			'group'      => self::TAB_KEY,
			'section'    => 'sae_po_suggestions',
			'name'       => __( 'Run Frequency', 'serenisoft-atum-enhancer' ),
			'desc'       => __( 'How often to automatically generate PO suggestions. Weekly is recommended for most stores.', 'serenisoft-atum-enhancer' ),
			'type'       => 'select',
			'default'    => 'weekly',
			'options'    => array(
				'values' => array(
					'daily'        => __( 'Daily', 'serenisoft-atum-enhancer' ),
					'twice_weekly' => __( 'Twice Weekly (Monday & Thursday)', 'serenisoft-atum-enhancer' ),
					'weekly'       => __( 'Weekly', 'serenisoft-atum-enhancer' ),
					'monthly'      => __( 'Monthly', 'serenisoft-atum-enhancer' ),
				),
			),
			'dependency' => array(
				'field' => 'sae_enable_auto_suggestions',
				'value' => 'yes',
			),
		);

		$defaults['sae_cron_day'] = array(
			'group'      => self::TAB_KEY,
			'section'    => 'sae_po_suggestions',
			'name'       => __( 'Run Day', 'serenisoft-atum-enhancer' ),
			'desc'       => __( 'Which day to run weekly suggestions, or day of month for monthly (1-28).', 'serenisoft-atum-enhancer' ),
			'type'       => 'select',
			'default'    => '1',
			'options'    => array(
				'values' => array(
					'1' => __( 'Monday (or 1st of month)', 'serenisoft-atum-enhancer' ),
					'2' => __( 'Tuesday (or 2nd of month)', 'serenisoft-atum-enhancer' ),
					'3' => __( 'Wednesday (or 3rd of month)', 'serenisoft-atum-enhancer' ),
					'4' => __( 'Thursday (or 4th of month)', 'serenisoft-atum-enhancer' ),
					'5' => __( 'Friday (or 5th of month)', 'serenisoft-atum-enhancer' ),
					'6' => __( 'Saturday (or 6th of month)', 'serenisoft-atum-enhancer' ),
					'7' => __( 'Sunday (or 7th of month)', 'serenisoft-atum-enhancer' ),
				),
			),
			'dependency' => array(
				'field' => 'sae_enable_auto_suggestions',
				'value' => 'yes',
			),
		);

		$defaults['sae_cron_time'] = array(
			'group'      => self::TAB_KEY,
			'section'    => 'sae_po_suggestions',
			'name'       => __( 'Scheduled Run Time', 'serenisoft-atum-enhancer' ),
			'desc'       => __( 'Time of day to automatically generate PO suggestions (24h format, server time).', 'serenisoft-atum-enhancer' ),
			'type'       => 'select',
			'default'    => '06:00',
			'options'    => array(
				'values' => array(
					'00:00' => '00:00',
					'02:00' => '02:00',
					'04:00' => '04:00',
					'06:00' => '06:00',
					'08:00' => '08:00',
					'10:00' => '10:00',
					'12:00' => '12:00',
					'14:00' => '14:00',
					'16:00' => '16:00',
					'18:00' => '18:00',
					'20:00' => '20:00',
					'22:00' => '22:00',
				),
			),
			'dependency' => array(
				'field' => 'sae_enable_auto_suggestions',
				'value' => 'yes',
			),
		);

		$defaults['sae_generate_suggestions'] = array(
			'group'    => self::TAB_KEY,
			'section'  => 'sae_po_suggestions',
			'name'     => __( 'Generate PO Suggestions', 'serenisoft-atum-enhancer' ),
			'desc'     => __( 'Manually trigger the generation of Purchase Order suggestions.', 'serenisoft-atum-enhancer' ),
			'type'     => 'html',
			'default'  => $this->get_generate_button_html(),
		);

		$defaults['sae_enable_dry_run'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_suggestions',
			'name'    => __( 'Enable Dry Run Mode', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'When enabled, PO generation will analyze and preview what would be created without actually creating Purchase Orders. Use this to test the algorithm without creating data.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'no',
		);

		$defaults['sae_enable_debug_logging'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_suggestions',
			'name'    => __( 'Enable Debug Logging', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Log detailed analysis for each product during PO generation. Check WordPress debug.log for output.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'no',
		);

		$defaults['sae_min_days_before_reorder'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_suggestions',
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

		$defaults['sae_enable_predictive_ordering'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_predictive_ordering',
			'name'    => __( 'Enable Predictive Ordering', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Consolidates purchase orders by including products that are close to their reorder point or will reach it soon. This reduces the number of POs needed while ensuring products are ordered before stockouts occur.<br><br><strong>Two-Pass System:</strong><br>• <strong>Pass 1:</strong> Identifies suppliers with urgent products (at or below reorder point)<br>• <strong>Pass 2:</strong> For suppliers where products were identified in Pass 1, re-runs analysis including products within safety margin or predicted to reach reorder point within 2× lead time<br><br>This prevents creating premature POs while consolidating orders when they are needed.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'yes',
		);

		$defaults['sae_stock_threshold_percent'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_predictive_ordering',
			'name'    => __( 'Safety Margin (%)', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Include products within this % above their reorder point. Example: With 15% margin and reorder point of 100, products with stock ≤ 115 will be included. Only applies when Predictive Ordering is enabled.', 'serenisoft-atum-enhancer' ),
			'type'    => 'number',
			'default' => 15,
			'options' => array(
				'min'  => 0,
				'max'  => 100,
				'step' => 5,
			),
		);

		$defaults['sae_use_time_based_prediction'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_predictive_ordering',
			'name'    => __( 'Use Time-Based Prediction', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Include products that will reach their reorder point within 2× the supplier\'s lead time. Example: If lead time is 60 days and a product will hit reorder point in 90 days, include it now. This provides a safety buffer so orders arrive before stockout. Only applies when Predictive Ordering is enabled.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'yes',
		);

		$defaults['sae_include_seasonal_analysis'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_suggestions',
			'name'    => __( 'Include Seasonal Analysis', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Analyze historical sales data to adjust reorder quantities based on seasonal patterns.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'yes',
		);

		$defaults['sae_service_level'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_suggestions',
			'name'    => __( 'Service Level', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'How often you want products to be in stock. 95% means you accept being out of stock ~18 days/year. Higher = more safety stock = less stockouts, but more capital tied up in inventory.', 'serenisoft-atum-enhancer' ),
			'type'    => 'select',
			'default' => '95',
			'options' => array(
				'values' => array(
					'90' => __( '90% - Minimal safety stock', 'serenisoft-atum-enhancer' ),
					'95' => __( '95% - Recommended', 'serenisoft-atum-enhancer' ),
					'99' => __( '99% - Maximum safety', 'serenisoft-atum-enhancer' ),
				),
			),
		);

		// Import & Export Settings.
		$defaults['sae_supplier_import'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_import_export',
			'name'    => __( 'Import Suppliers from CSV', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Upload a CSV file with supplier data. Duplicates (by code or name) will be skipped.', 'serenisoft-atum-enhancer' ),
			'type'    => 'html',
			'default' => '',
		);

		$defaults['sae_supplier_export'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_import_export',
			'name'    => __( 'Export Suppliers to CSV', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Download all suppliers as a CSV file.', 'serenisoft-atum-enhancer' ),
			'type'    => 'html',
			'default' => '',
		);

		$defaults['sae_product_supplier_export'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_import_export',
			'name'    => __( 'Export Product-Supplier Mapping', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Download the mapping between products (SKU) and suppliers (Code).', 'serenisoft-atum-enhancer' ),
			'type'    => 'html',
			'default' => '',
		);

		$defaults['sae_product_supplier_import'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_import_export',
			'name'    => __( 'Import Product-Supplier Mapping', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Upload a CSV file to update the supplier assignments for products.', 'serenisoft-atum-enhancer' ),
			'type'    => 'html',
			'default' => '',
		);

		// Closed Periods Presets - HTML UI only, data saved via AJAX to separate option.
		$defaults['sae_closed_periods_presets'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_closed_periods',
			'name'    => __( 'Global Closed Period Presets', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Define reusable closed periods that can be applied to multiple suppliers. Dates use DD-MM format (e.g., 01-07 for July 1st) and automatically handle year boundaries (e.g., 20-12 to 05-01 for Christmas spanning years).', 'serenisoft-atum-enhancer' ),
			'type'    => 'html',
			'default' => '',
		);

		// Buffer before closure - safety margin for pre-holiday delays.
		$defaults['sae_closure_buffer_before'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_closed_periods',
			'name'    => __( 'Buffer Before Closure (Days)', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Safety margin before official closure. Accounts for pre-holiday delivery delays when suppliers rush to clear orders.', 'serenisoft-atum-enhancer' ),
			'type'    => 'number',
			'default' => 14,
			'options' => array(
				'min'  => 0,
				'max'  => 30,
				'step' => 1,
			),
		);

		// Buffer after closure - factory ramp-up time.
		$defaults['sae_closure_buffer_after'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_closed_periods',
			'name'    => __( 'Buffer After Closure (Days)', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Factory ramp-up time after reopening. Accounts for post-holiday startup delays before normal production resumes.', 'serenisoft-atum-enhancer' ),
			'type'    => 'number',
			'default' => 14,
			'options' => array(
				'min'  => 0,
				'max'  => 30,
				'step' => 1,
			),
		);

		// PO PDF Settings.
		$defaults['sae_hide_backordered_on_pdf'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_pdf',
			'name'    => __( 'Hide Backordered on PDF', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Hide the "Backordered" field from Purchase Order PDF exports.', 'serenisoft-atum-enhancer' ),
			'type'    => 'switcher',
			'default' => 'no',
		);

		// PO Email Settings.
		$defaults['sae_email_from_name'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_email',
			'name'    => __( 'From Name', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'The sender name that appears in the email. Leave empty to use site name.', 'serenisoft-atum-enhancer' ),
			'type'    => 'text',
			'default' => '',
		);

		$defaults['sae_email_from_address'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_email',
			'name'    => __( 'From Email Address', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'The sender email address. This will also be used as Reply-To. Leave empty to use admin email.', 'serenisoft-atum-enhancer' ),
			'type'    => 'text',
			'default' => '',
		);

		$defaults['sae_email_cc_address'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_email',
			'name'    => __( 'CC Email Address', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Admin email address to receive a copy of all PO emails sent to suppliers.', 'serenisoft-atum-enhancer' ),
			'type'    => 'text',
			'default' => '',
		);

		$defaults['sae_email_body_template'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_email',
			'name'    => __( 'Email Body Template', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Default email message. Available placeholders: {po_number}, {supplier_name}, {order_date}, {total}', 'serenisoft-atum-enhancer' ),
			'type'    => 'textarea',
			'default' => "Dear {supplier_name},\n\nPlease find attached Purchase Order #{po_number}.\n\nPlease confirm receipt and expected delivery date.\n\nBest regards,",
		);

		$defaults['sae_email_signature'] = array(
			'group'   => self::TAB_KEY,
			'section' => 'sae_po_email',
			'name'    => __( 'Email Signature', 'serenisoft-atum-enhancer' ),
			'desc'    => __( 'Signature to appear at the bottom of the email.', 'serenisoft-atum-enhancer' ),
			'type'    => 'textarea',
			'default' => '',
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

		ob_start();
		?>
		<div class="sae-import-form">
			<p class="description">
				<?php esc_html_e( 'CSV format: Comma-separated with columns including: Leverandørnummer, Navn, Organisasjonsnummer, Telefonnummer, Faksnummer, E-postadresse, Postadresse, Postnr., Sted, Land', 'serenisoft-atum-enhancer' ); ?>
			</p>

			<div class="sae-button-group">
				<input type="file" id="sae-csv-file" accept=".csv" style="margin-right: 10px;" />
				<button type="button" class="button btn-styled" id="sae-preview-btn">
					<?php esc_html_e( 'Preview', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<span class="spinner" style="float: none; margin-top: 0;"></span>
			</div>

			<div id="sae-preview-result" style="margin-top: 10px;"></div>

			<div id="sae-import-actions" style="margin-top: 10px; display: none;">
				<button type="button" class="button button-primary btn-styled" id="sae-import-btn">
					<?php esc_html_e( 'Import', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<button type="button" class="button btn-styled" id="sae-cancel-btn" style="margin-left: 10px;">
					<?php esc_html_e( 'Cancel', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<span class="spinner" style="float: none; margin-top: 0;"></span>
			</div>

			<div id="sae-import-result" style="margin-top: 10px;"></div>
		</div>
		<style>
		.sae-preview-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
		.sae-preview-table th, .sae-preview-table td { padding: 8px; text-align: left; border: 1px solid #ddd; }
		.sae-preview-table th { background: #f5f5f5; }
		.sae-status-import { color: #46b450; }
		.sae-status-skip { color: #ffb900; }
		.sae-status-error { color: #dc3232; }
		</style>
		<?php
		return ob_get_clean();

	}

	/**
	 * Get the export form HTML
	 *
	 * @since 0.9.15
	 *
	 * @return string HTML for the export form.
	 */
	private function get_export_form_html() {

		ob_start();
		?>
		<div class="sae-export-form">
			<p class="description">
				<?php esc_html_e( 'Export all suppliers to a CSV file. The file will include: Code, Name, Tax Number, Phone, Fax, Email, Address, City, Zip Code, Country, Lead Time, and more.', 'serenisoft-atum-enhancer' ); ?>
			</p>

			<div class="sae-button-group" style="margin-top: 10px;">
				<button type="button" class="button button-primary btn-styled" id="sae-export-btn">
					<?php esc_html_e( 'Export Suppliers', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<span class="spinner" style="float: none; margin-top: 0;"></span>
			</div>

			<div id="sae-export-result" style="margin-top: 10px;"></div>
		</div>
		<?php
		return ob_get_clean();

	}

	/**
	 * Get the product-supplier export form HTML
	 *
	 * @since 0.9.15
	 *
	 * @return string HTML for the product-supplier export form.
	 */
	private function get_product_supplier_export_html() {

		ob_start();
		?>
		<div class="sae-product-supplier-export-form">
			<p class="description">
				<?php esc_html_e( 'Export all products with their supplier assignments. Columns: Product SKU, Product Name, Supplier Code, Supplier Name, Supplier SKU.', 'serenisoft-atum-enhancer' ); ?>
			</p>

			<div class="sae-button-group" style="margin-top: 10px;">
				<button type="button" class="button button-primary btn-styled" id="sae-product-supplier-export-btn">
					<?php esc_html_e( 'Export Product-Supplier Mapping', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<span class="spinner" style="float: none; margin-top: 0;"></span>
			</div>

			<div id="sae-product-supplier-export-result" style="margin-top: 10px;"></div>
		</div>
		<?php
		return ob_get_clean();

	}

	/**
	 * Get the product-supplier import form HTML
	 *
	 * @since 0.9.15
	 *
	 * @return string HTML for the product-supplier import form.
	 */
	private function get_product_supplier_import_html() {

		ob_start();
		?>
		<div class="sae-product-supplier-import-form">
			<p class="description">
				<?php esc_html_e( 'Upload a CSV file to update supplier assignments for products. Required columns: Product SKU, Supplier Code. Optional: Supplier SKU.', 'serenisoft-atum-enhancer' ); ?>
			</p>

			<div class="sae-button-group" style="margin-top: 10px;">
				<input type="file" id="sae-product-supplier-csv-file" accept=".csv" style="margin-right: 10px;" />
				<button type="button" class="button btn-styled" id="sae-product-supplier-preview-btn">
					<?php esc_html_e( 'Preview', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<span class="spinner" style="float: none; margin-top: 0;"></span>
			</div>

			<div id="sae-product-supplier-preview-result" style="margin-top: 10px;"></div>

			<div id="sae-product-supplier-import-actions" style="margin-top: 10px; display: none;">
				<button type="button" class="button button-primary btn-styled" id="sae-product-supplier-import-btn">
					<?php esc_html_e( 'Import', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<button type="button" class="button btn-styled" id="sae-product-supplier-cancel-btn" style="margin-left: 10px;">
					<?php esc_html_e( 'Cancel', 'serenisoft-atum-enhancer' ); ?>
				</button>
				<span class="spinner" style="float: none; margin-top: 0;"></span>
			</div>

			<div id="sae-product-supplier-import-result" style="margin-top: 10px;"></div>
		</div>
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

		ob_start();
		?>
		<div class="sae-button-group">
			<button type="button" class="button button-primary btn-styled" id="sae-generate-btn">
				<?php esc_html_e( 'Generate PO Suggestions Now', 'serenisoft-atum-enhancer' ); ?>
			</button>
			<span class="spinner" style="float: none; margin-top: 0;"></span>
		</div>

		<div id="sae-generate-result" style="margin-top: 10px;"></div>

		<!-- Custom confirmation modal (Brave Browser compatible) -->
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

		<!-- Loading Overlay -->
		<div id="sae-loading-overlay" style="display: none;">
			<div class="sae-loading-content">
				<span class="spinner is-active"></span>
				<p><?php esc_html_e( 'Generating PO Suggestions...', 'serenisoft-atum-enhancer' ); ?></p>
				<p class="description"><?php esc_html_e( 'Please wait, this may take a moment.', 'serenisoft-atum-enhancer' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();

	}

	/**
	 * Get the closed periods management HTML
	 *
	 * @since 0.9.0
	 *
	 * @return string HTML for closed periods table.
	 */
	private function get_closed_periods_html() {

		// Get presets from our own WordPress option (separate from ATUM settings).
		$presets = get_option( 'sae_global_closed_periods', array() );
		if ( ! is_array( $presets ) ) {
			$presets = array();
		}

		ob_start();
		?>
		<div class="sae-closed-periods-manager"
			data-presets="<?php echo esc_attr( wp_json_encode( $presets ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'sae_closed_periods_nonce' ) ); ?>">

			<p class="description">
				<?php esc_html_e( 'Examples: Summer vacation (01-07 to 15-08), Christmas (20-12 to 05-01). Year-crossing periods are handled automatically.', 'serenisoft-atum-enhancer' ); ?>
			</p>

			<table class="sae-periods-table widefat">
				<thead>
					<tr>
						<th style="width: 40%;"><?php esc_html_e( 'Period Name', 'serenisoft-atum-enhancer' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'Start (DD-MM)', 'serenisoft-atum-enhancer' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'End (DD-MM)', 'serenisoft-atum-enhancer' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'Actions', 'serenisoft-atum-enhancer' ); ?></th>
					</tr>
				</thead>
				<tbody id="sae-periods-list"></tbody>
			</table>

			<button type="button" class="button btn-styled" id="sae-add-period">
				<?php esc_html_e( '+ Add Period', 'serenisoft-atum-enhancer' ); ?>
			</button>

			<span class="sae-periods-status" style="margin-left: 10px; color: #666;"></span>
		</div>

		<style>
		.sae-periods-table { border-collapse: collapse; margin: 10px 0; }
		.sae-periods-table th, .sae-periods-table td { padding: 10px; border: 1px solid #ddd; }
		.sae-periods-table th { background: #f5f5f5; }
		.sae-periods-table input[type="text"] { width: 95%; padding: 5px; }
		.sae-remove-period { color: #dc3232; text-decoration: none; cursor: pointer; }
		</style>
		<?php
		return ob_get_clean();

	}

	/**
	 * Display the generate button for HTML field
	 *
	 * @since 1.0.0
	 *
	 * @param string $output Current output.
	 * @param array  $args   Field arguments.
	 *
	 * @return string Modified output.
	 */
	public function display_generate_button( $output, $args ) {

		if ( 'sae_generate_suggestions' === $args['id'] ) {
			return $this->get_generate_button_html();
		}

		if ( 'sae_supplier_import' === $args['id'] ) {
			return $this->get_import_form_html();
		}

		if ( 'sae_closed_periods_presets' === $args['id'] ) {
			return $this->get_closed_periods_html();
		}

		if ( 'sae_supplier_export' === $args['id'] ) {
			return $this->get_export_form_html();
		}

		if ( 'sae_product_supplier_export' === $args['id'] ) {
			return $this->get_product_supplier_export_html();
		}

		if ( 'sae_product_supplier_import' === $args['id'] ) {
			return $this->get_product_supplier_import_html();
		}

		return $output;

	}

	/**
	 * Sanitize custom fields before saving
	 *
	 * @since 0.9.0
	 *
	 * @param mixed  $value      The value to sanitize.
	 * @param string $option_key The option key.
	 * @param array  $args       Field arguments.
	 *
	 * @return mixed Sanitized value.
	 */
	public function sanitize_custom_fields( $value, $option_key, $args ) {
		// Closed periods are saved via AJAX, not through ATUM settings.
		return $value;
	}

	/**
	 * AJAX handler for saving closed periods presets
	 *
	 * Saves to a separate WordPress option, bypassing ATUM's HTML field limitation.
	 *
	 * @since 0.9.0
	 */
	public function ajax_save_closed_periods() {

		check_ajax_referer( 'sae_closed_periods_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'serenisoft-atum-enhancer' ) ) );
		}

		$presets = isset( $_POST['presets'] ) ? wp_unslash( $_POST['presets'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$decoded = json_decode( $presets, true );

		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data format.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Sanitize each period.
		$sanitized = array();
		foreach ( $decoded as $period ) {
			if ( isset( $period['start_date'], $period['end_date'] )
				&& preg_match( '/^\d{2}-\d{2}$/', $period['start_date'] )
				&& preg_match( '/^\d{2}-\d{2}$/', $period['end_date'] ) ) {
				$sanitized[] = array(
					'id'         => sanitize_text_field( $period['id'] ?? 'period_' . time() ),
					'name'       => sanitize_text_field( $period['name'] ?? '' ),
					'start_date' => sanitize_text_field( $period['start_date'] ),
					'end_date'   => sanitize_text_field( $period['end_date'] ),
				);
			}
		}

		update_option( 'sae_global_closed_periods', $sanitized );

		wp_send_json_success( array( 'message' => __( 'Closed periods saved.', 'serenisoft-atum-enhancer' ) ) );

	}

	/**
	 * AJAX handler for exporting suppliers to CSV
	 *
	 * @since 0.9.15
	 */
	public function ajax_export_suppliers() {

		check_ajax_referer( 'sae_export_suppliers', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Get all suppliers.
		$suppliers = get_posts( array(
			'post_type'      => \Atum\Suppliers\Suppliers::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		if ( empty( $suppliers ) ) {
			wp_send_json_error( array( 'message' => __( 'No suppliers found to export.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Define CSV columns.
		$columns = array(
			'code'           => __( 'Code', 'serenisoft-atum-enhancer' ),
			'name'           => __( 'Name', 'serenisoft-atum-enhancer' ),
			'tax_number'     => __( 'Tax Number', 'serenisoft-atum-enhancer' ),
			'phone'          => __( 'Phone', 'serenisoft-atum-enhancer' ),
			'fax'            => __( 'Fax', 'serenisoft-atum-enhancer' ),
			'general_email'  => __( 'General Email', 'serenisoft-atum-enhancer' ),
			'ordering_email' => __( 'Ordering Email', 'serenisoft-atum-enhancer' ),
			'website'        => __( 'Website', 'serenisoft-atum-enhancer' ),
			'ordering_url'   => __( 'Ordering URL', 'serenisoft-atum-enhancer' ),
			'address'        => __( 'Address', 'serenisoft-atum-enhancer' ),
			'address_2'      => __( 'Address 2', 'serenisoft-atum-enhancer' ),
			'city'           => __( 'City', 'serenisoft-atum-enhancer' ),
			'state'          => __( 'State', 'serenisoft-atum-enhancer' ),
			'zip_code'       => __( 'Zip Code', 'serenisoft-atum-enhancer' ),
			'country'        => __( 'Country', 'serenisoft-atum-enhancer' ),
			'currency'       => __( 'Currency', 'serenisoft-atum-enhancer' ),
			'lead_time'      => __( 'Lead Time (days)', 'serenisoft-atum-enhancer' ),
			'discount'       => __( 'Discount (%)', 'serenisoft-atum-enhancer' ),
			'tax_rate'       => __( 'Tax Rate (%)', 'serenisoft-atum-enhancer' ),
			'location'       => __( 'Location', 'serenisoft-atum-enhancer' ),
			'description'    => __( 'Description', 'serenisoft-atum-enhancer' ),
		);

		// Build CSV content.
		$csv_rows = array();

		// Header row.
		$csv_rows[] = array_values( $columns );

		// Data rows.
		foreach ( $suppliers as $supplier_post ) {
			$supplier = new \Atum\Suppliers\Supplier( $supplier_post->ID );
			$row      = array();

			foreach ( array_keys( $columns ) as $field ) {
				if ( 'name' === $field ) {
					$row[] = $supplier->name ?: '';
				} else {
					$row[] = $supplier->$field ?? '';
				}
			}

			$csv_rows[] = $row;
		}

		// Generate CSV file.
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/sae-temp';

		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$filename = 'suppliers-export-' . gmdate( 'Y-m-d-His' ) . '.csv';
		$filepath = $temp_dir . '/' . $filename;

		$handle = fopen( $filepath, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			wp_send_json_error( array( 'message' => __( 'Could not create export file.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Add BOM for Excel UTF-8 compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// Write rows.
		foreach ( $csv_rows as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Return download URL.
		$download_url = $upload_dir['baseurl'] . '/sae-temp/' . $filename;

		wp_send_json_success( array(
			'message'      => sprintf(
				/* translators: %d: number of suppliers exported */
				__( 'Exported %d suppliers successfully.', 'serenisoft-atum-enhancer' ),
				count( $suppliers )
			),
			'download_url' => $download_url,
			'filename'     => $filename,
			'count'        => count( $suppliers ),
		) );

	}

	/**
	 * AJAX handler for exporting product-supplier mapping to CSV
	 *
	 * @since 0.9.15
	 */
	public function ajax_export_product_suppliers() {

		check_ajax_referer( 'sae_product_supplier_export', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'serenisoft-atum-enhancer' ) ) );
		}

		global $wpdb;

		// Get all products with ATUM data (including supplier info).
		$atum_table = $wpdb->prefix . 'atum_product_data';

		// Check if ATUM table exists.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $atum_table ) ) !== $atum_table ) {
			wp_send_json_error( array( 'message' => __( 'ATUM product data table not found.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Get products with their SKU and supplier data.
		$results = $wpdb->get_results(
			"SELECT
				p.ID as product_id,
				p.post_title as product_name,
				pm.meta_value as product_sku,
				apd.supplier_id,
				apd.supplier_sku
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
			LEFT JOIN {$atum_table} apd ON p.ID = apd.product_id
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status = 'publish'
			ORDER BY p.post_title ASC"
		);

		if ( empty( $results ) ) {
			wp_send_json_error( array( 'message' => __( 'No products found.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Build supplier lookup by ID.
		$supplier_lookup = array();
		$suppliers       = get_posts( array(
			'post_type'      => \Atum\Suppliers\Suppliers::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		foreach ( $suppliers as $supplier_post ) {
			$supplier                              = new \Atum\Suppliers\Supplier( $supplier_post->ID );
			$supplier_lookup[ $supplier_post->ID ] = array(
				'code' => $supplier->code ?: '',
				'name' => $supplier->name ?: '',
			);
		}

		// Define CSV columns (use fixed English names to match import).
		$columns = array(
			'Product SKU',
			'Product Name',
			'Supplier Code',
			'Supplier Name',
			'Supplier SKU',
		);

		// Build CSV content.
		$csv_rows   = array();
		$csv_rows[] = $columns;

		foreach ( $results as $row ) {
			$supplier_code = '';
			$supplier_name = '';

			if ( ! empty( $row->supplier_id ) && isset( $supplier_lookup[ $row->supplier_id ] ) ) {
				$supplier_code = $supplier_lookup[ $row->supplier_id ]['code'];
				$supplier_name = $supplier_lookup[ $row->supplier_id ]['name'];
			}

			$csv_rows[] = array(
				$row->product_sku ?: '',
				$row->product_name ?: '',
				$supplier_code,
				$supplier_name,
				$row->supplier_sku ?: '',
			);
		}

		// Generate CSV file.
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/sae-temp';

		if ( ! is_dir( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$filename = 'product-supplier-mapping-' . gmdate( 'Y-m-d-His' ) . '.csv';
		$filepath = $temp_dir . '/' . $filename;

		$handle = fopen( $filepath, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			wp_send_json_error( array( 'message' => __( 'Could not create export file.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Add BOM for Excel UTF-8 compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// Write rows.
		foreach ( $csv_rows as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Return download URL.
		$download_url = $upload_dir['baseurl'] . '/sae-temp/' . $filename;

		wp_send_json_success( array(
			'message'      => sprintf(
				/* translators: %d: number of products exported */
				__( 'Exported %d products successfully.', 'serenisoft-atum-enhancer' ),
				count( $results )
			),
			'download_url' => $download_url,
			'filename'     => $filename,
			'count'        => count( $results ),
		) );

	}

	/**
	 * AJAX handler for previewing product-supplier import
	 *
	 * @since 0.9.15
	 */
	public function ajax_preview_product_suppliers() {

		check_ajax_referer( 'sae_product_supplier_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'serenisoft-atum-enhancer' ) ) );
		}

		if ( empty( $_FILES['csv_file'] ) || empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'serenisoft-atum-enhancer' ) ) );
		}

		$file = $_FILES['csv_file']['tmp_name'];

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_send_json_error( array( 'message' => __( 'Could not read the CSV file.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Read header row.
		$header = fgetcsv( $handle );
		if ( false === $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( array( 'message' => __( 'Invalid CSV file format.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Clean BOM from first column if present (multiple methods for robustness).
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$header[0] = str_replace( "\xEF\xBB\xBF", '', $header[0] );
		$header[0] = trim( $header[0], "\xEF\xBB\xBF \t\n\r\0\x0B" );

		// Find column indices.
		$col_indices = $this->find_product_supplier_columns( $header );

		if ( ! isset( $col_indices['product_sku'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( array( 'message' => __( 'Required column "Product SKU" not found in CSV.', 'serenisoft-atum-enhancer' ) ) );
		}

		if ( ! isset( $col_indices['supplier_code'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( array( 'message' => __( 'Required column "Supplier Code" not found in CSV.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Build supplier lookup by code.
		$supplier_lookup = $this->build_supplier_lookup_by_code();

		// Analyze rows.
		$rows        = array();
		$will_update = 0;
		$will_skip   = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			$product_sku   = isset( $row[ $col_indices['product_sku'] ] ) ? trim( $row[ $col_indices['product_sku'] ] ) : '';
			$supplier_code = isset( $col_indices['supplier_code'], $row[ $col_indices['supplier_code'] ] ) ? trim( $row[ $col_indices['supplier_code'] ] ) : '';
			$supplier_sku  = isset( $col_indices['supplier_sku'], $row[ $col_indices['supplier_sku'] ] ) ? trim( $row[ $col_indices['supplier_sku'] ] ) : '';

			$status = 'update';
			$reason = '';

			// Check if product exists.
			$product_id = wc_get_product_id_by_sku( $product_sku );
			if ( ! $product_id ) {
				$status = 'skip';
				$reason = __( 'Product not found', 'serenisoft-atum-enhancer' );
			} elseif ( ! empty( $supplier_code ) && ! isset( $supplier_lookup[ $supplier_code ] ) ) {
				$status = 'skip';
				$reason = __( 'Supplier not found', 'serenisoft-atum-enhancer' );
			}

			if ( 'update' === $status ) {
				$will_update++;
			} else {
				$will_skip++;
			}

			$rows[] = array(
				'product_sku'   => $product_sku,
				'supplier_code' => $supplier_code,
				'supplier_sku'  => $supplier_sku,
				'status'        => $status,
				'reason'        => $reason,
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		wp_send_json_success( array(
			'rows'        => $rows,
			'will_update' => $will_update,
			'will_skip'   => $will_skip,
			'total'       => count( $rows ),
		) );

	}

	/**
	 * AJAX handler for importing product-supplier mapping
	 *
	 * @since 0.9.15
	 */
	public function ajax_import_product_suppliers() {

		check_ajax_referer( 'sae_product_supplier_import', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'serenisoft-atum-enhancer' ) ) );
		}

		if ( empty( $_FILES['csv_file'] ) || empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'serenisoft-atum-enhancer' ) ) );
		}

		$file = $_FILES['csv_file']['tmp_name'];

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			wp_send_json_error( array( 'message' => __( 'Could not read the CSV file.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Read header row.
		$header = fgetcsv( $handle );
		if ( false === $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( array( 'message' => __( 'Invalid CSV file format.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Clean BOM from first column if present (multiple methods for robustness).
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$header[0] = str_replace( "\xEF\xBB\xBF", '', $header[0] );
		$header[0] = trim( $header[0], "\xEF\xBB\xBF \t\n\r\0\x0B" );

		// Find column indices.
		$col_indices = $this->find_product_supplier_columns( $header );

		if ( ! isset( $col_indices['product_sku'] ) || ! isset( $col_indices['supplier_code'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( array( 'message' => __( 'Required columns not found in CSV.', 'serenisoft-atum-enhancer' ) ) );
		}

		// Build supplier lookup by code.
		$supplier_lookup = $this->build_supplier_lookup_by_code();

		$updated = 0;
		$skipped = 0;
		$errors  = array();

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			$product_sku   = isset( $row[ $col_indices['product_sku'] ] ) ? trim( $row[ $col_indices['product_sku'] ] ) : '';
			$supplier_code = isset( $col_indices['supplier_code'], $row[ $col_indices['supplier_code'] ] ) ? trim( $row[ $col_indices['supplier_code'] ] ) : '';
			$supplier_sku  = isset( $col_indices['supplier_sku'], $row[ $col_indices['supplier_sku'] ] ) ? trim( $row[ $col_indices['supplier_sku'] ] ) : '';

			// Get product by SKU.
			$product_id = wc_get_product_id_by_sku( $product_sku );
			if ( ! $product_id ) {
				$skipped++;
				continue;
			}

			// Get supplier ID from code.
			$supplier_id = 0;
			if ( ! empty( $supplier_code ) ) {
				if ( isset( $supplier_lookup[ $supplier_code ] ) ) {
					$supplier_id = $supplier_lookup[ $supplier_code ];
				} else {
					$skipped++;
					continue;
				}
			}

			// Update product supplier using ATUM's method.
			try {
				$product = \Atum\Inc\Helpers::get_atum_product( $product_id );

				if ( $product && method_exists( $product, 'set_supplier_id' ) ) {
					$product->set_supplier_id( $supplier_id );

					if ( ! empty( $supplier_sku ) && method_exists( $product, 'set_supplier_sku' ) ) {
						$product->set_supplier_sku( $supplier_sku );
					}

					$product->save_atum_data();
					$updated++;
				} else {
					$skipped++;
				}
			} catch ( \Exception $e ) {
				$errors[] = sprintf(
					/* translators: 1: product SKU, 2: error message */
					__( 'Error updating %1$s: %2$s', 'serenisoft-atum-enhancer' ),
					$product_sku,
					$e->getMessage()
				);
				$skipped++;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		wp_send_json_success( array(
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
			'message' => sprintf(
				/* translators: 1: updated count, 2: skipped count */
				__( 'Import complete. %1$d products updated, %2$d skipped.', 'serenisoft-atum-enhancer' ),
				$updated,
				$skipped
			),
		) );

	}

	/**
	 * Find column indices for product-supplier CSV
	 *
	 * @since 0.9.15
	 *
	 * @param array $header CSV header row.
	 *
	 * @return array Column indices.
	 */
	private function find_product_supplier_columns( $header ) {

		$column_mapping = array(
			'Product SKU'   => 'product_sku',
			'Supplier Code' => 'supplier_code',
			'Supplier SKU'  => 'supplier_sku',
		);

		$indices = array();

		foreach ( $header as $index => $column_name ) {
			// Clean and trim column name.
			$column_name = trim( $column_name );
			$column_name = preg_replace( '/^\xEF\xBB\xBF/', '', $column_name );
			$column_name = str_replace( "\xEF\xBB\xBF", '', $column_name );
			$column_name = trim( $column_name );

			if ( isset( $column_mapping[ $column_name ] ) ) {
				$indices[ $column_mapping[ $column_name ] ] = $index;
			}
		}

		return $indices;

	}

	/**
	 * Build supplier lookup array by code
	 *
	 * @since 0.9.15
	 *
	 * @return array Supplier IDs keyed by code.
	 */
	private function build_supplier_lookup_by_code() {

		$lookup    = array();
		$suppliers = get_posts( array(
			'post_type'      => \Atum\Suppliers\Suppliers::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		foreach ( $suppliers as $supplier_post ) {
			$supplier = new \Atum\Suppliers\Supplier( $supplier_post->ID );
			$code     = $supplier->code;

			if ( ! empty( $code ) ) {
				$lookup[ $code ] = $supplier_post->ID;
			}
		}

		return $lookup;

	}

	/**
	 * Filter hidden item meta on PO PDF exports
	 *
	 * @since 0.9.11
	 *
	 * @param array $hidden_meta Array of meta keys to hide.
	 *
	 * @return array Modified array of hidden meta keys.
	 */
	public function filter_po_pdf_hidden_meta( $hidden_meta ) {

		if ( 'yes' === self::get( 'sae_hide_backordered_on_pdf', 'no' ) ) {
			$hidden_meta[] = 'backordered';
			$hidden_meta[] = 'Backordered';
			$hidden_meta[] = '_backordered';
		}

		return $hidden_meta;

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


	/**
	 * Print JavaScript in footer
	 *
	 * Inline scripts in HTML fields are stripped by WordPress sanitization,
	 * so we output them via admin_print_footer_scripts instead.
	 *
	 * @since 1.0.0
	 */
	public function print_footer_scripts() {

		// Only load on ATUM settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'atum-inventory_page_atum-settings' !== $screen->id ) {
			return;
		}

		$import_nonce                 = wp_create_nonce( 'sae_import_suppliers' );
		$generate_nonce               = wp_create_nonce( 'sae_generate_po_suggestions' );
		$export_nonce                 = wp_create_nonce( 'sae_export_suppliers' );
		$product_supplier_export_nonce = wp_create_nonce( 'sae_product_supplier_export' );
		$product_supplier_import_nonce = wp_create_nonce( 'sae_product_supplier_import' );
		$ajax_url                     = esc_url( admin_url( 'admin-ajax.php' ) );
		?>
		<style>
		/* Force display of Enable Automatic Suggestions checkbox */
		#atum_sae_enable_auto_suggestions:is(th, td),
		#atum_sae_enable_auto_suggestions:is(th, td) ~ td,
		tr:has(#atum_sae_enable_auto_suggestions) th,
		tr:has(#atum_sae_enable_auto_suggestions) td {
			display: table-cell !important;
		}

		/* Custom modal styles (Brave Browser compatible) */
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
			background: #fff;
			padding: 20px;
			border-radius: 4px;
			box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
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

		/* ATUM-style buttons without triggering ATUM's JavaScript */
		.btn-styled {
			min-height: 32px;
			padding: 0 12px;
			line-height: 30px;
			border-radius: 3px;
			font-weight: 500;
			text-shadow: none;
			border-width: 1px;
			border-style: solid;
		}
		.sae-button-group {
			display: inline-block;
			vertical-align: middle;
		}

		/* PO Choice Dialog */
		.sae-choice-overlay {
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.7);
			z-index: 100000;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.sae-choice-modal {
			background: white;
			padding: 30px;
			border-radius: 5px;
			box-shadow: 0 5px 15px rgba(0,0,0,0.3);
			max-width: 800px;
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
		.sae-choice-item .products-info {
			margin-bottom: 10px;
			color: #666;
		}
		.sae-existing-pos {
			margin: 10px 0;
		}
		.sae-po-option {
			padding: 8px;
			margin: 5px 0;
			border: 1px solid #ccc;
			background: white;
			border-radius: 3px;
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
		jQuery(document).ready(function($) {
			console.log('SAE: Script loaded and jQuery ready');

			// === Supplier Import Scripts ===
			// Use event delegation for all buttons (ATUM pattern)
			var selectedFile = null;

			// Preview button click - using event delegation
			$(document).on('click', '#sae-preview-btn', function() {
				var fileInput = $('#sae-csv-file')[0];
				if (!fileInput.files.length) {
					alert('<?php echo esc_js( __( 'Please select a CSV file.', 'serenisoft-atum-enhancer' ) ); ?>');
					return;
				}

				selectedFile = fileInput.files[0];
				var formData = new FormData();
				formData.append('action', 'sae_preview_suppliers');
				formData.append('nonce', '<?php echo esc_js( $import_nonce ); ?>');
				formData.append('csv_file', selectedFile);

				var $btn = $(this);
				var $spinner = $btn.next('.spinner');
				var $preview = $('#sae-preview-result');

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$preview.html('');
				$('#sae-import-actions').hide();
				$('#sae-import-result').html('');

				$.ajax({
					url: '<?php echo $ajax_url; ?>',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');

						if (response.success) {
							var data = response.data;
							var html = '<p><strong><?php echo esc_js( __( 'Preview:', 'serenisoft-atum-enhancer' ) ); ?></strong> ';
							html += data.will_import + ' <?php echo esc_js( __( 'will be imported', 'serenisoft-atum-enhancer' ) ); ?>, ';
							html += data.will_skip + ' <?php echo esc_js( __( 'will be skipped', 'serenisoft-atum-enhancer' ) ); ?></p>';

							html += '<table class="sae-preview-table"><thead><tr>';
							html += '<th><?php echo esc_js( __( 'Code', 'serenisoft-atum-enhancer' ) ); ?></th>';
							html += '<th><?php echo esc_js( __( 'Name', 'serenisoft-atum-enhancer' ) ); ?></th>';
							html += '<th><?php echo esc_js( __( 'Status', 'serenisoft-atum-enhancer' ) ); ?></th>';
							html += '</tr></thead><tbody>';

							data.rows.forEach(function(row) {
								var statusClass = 'sae-status-' + row.status;
								var statusText = row.status === 'import' ? '<?php echo esc_js( __( 'Will import', 'serenisoft-atum-enhancer' ) ); ?>' : row.reason;
								html += '<tr><td>' + (row.code || '-') + '</td>';
								html += '<td>' + (row.name || '-') + '</td>';
								html += '<td class="' + statusClass + '">' + statusText + '</td></tr>';
							});

							html += '</tbody></table>';
							$preview.html(html);

							if (data.will_import > 0) {
								$('#sae-import-actions').show();
							}
						} else {
							$preview.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$preview.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during preview.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// Cancel button click - using event delegation
			$(document).on('click', '#sae-cancel-btn', function() {
				$('#sae-preview-result').html('');
				$('#sae-import-actions').hide();
				$('#sae-csv-file').val('');
				selectedFile = null;
			});

			// Import button click - using event delegation
			$(document).on('click', '#sae-import-btn', function() {
				if (!selectedFile) {
					alert('<?php echo esc_js( __( 'Please preview the file first.', 'serenisoft-atum-enhancer' ) ); ?>');
					return;
				}

				var formData = new FormData();
				formData.append('action', 'sae_import_suppliers');
				formData.append('nonce', '<?php echo esc_js( $import_nonce ); ?>');
				formData.append('csv_file', selectedFile);

				var $btn = $(this);
				var $result = $('#sae-import-result');

				$btn.prop('disabled', true);
				$('#sae-cancel-btn').prop('disabled', true);
				$result.html('<p><?php echo esc_js( __( 'Importing...', 'serenisoft-atum-enhancer' ) ); ?></p>');

				$.ajax({
					url: '<?php echo $ajax_url; ?>',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						$btn.prop('disabled', false);
						$('#sae-cancel-btn').prop('disabled', false);

						if (response.success) {
							$result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							$('#sae-preview-result').html('');
							$('#sae-import-actions').hide();
							$('#sae-csv-file').val('');
							selectedFile = null;

							if (response.data.errors && response.data.errors.length) {
								$result.append('<div class="notice notice-warning"><p><strong><?php echo esc_js( __( 'Errors:', 'serenisoft-atum-enhancer' ) ); ?></strong><br>' + response.data.errors.join('<br>') + '</p></div>');
							}
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$('#sae-cancel-btn').prop('disabled', false);
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during import.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// === Supplier Export Scripts ===
			$(document).on('click', '#sae-export-btn', function() {
				var $btn = $(this);
				var $spinner = $btn.next('.spinner');
				var $result = $('#sae-export-result');

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');

				$.ajax({
					url: '<?php echo $ajax_url; ?>',
					type: 'POST',
					data: {
						action: 'sae_export_suppliers',
						nonce: '<?php echo esc_js( $export_nonce ); ?>'
					},
					success: function(response) {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');

						if (response.success) {
							var html = '<div class="notice notice-success">';
							html += '<p>' + response.data.message + '</p>';
							html += '<p><a href="' + response.data.download_url + '" class="button" download="' + response.data.filename + '">';
							html += '<?php echo esc_js( __( 'Download CSV', 'serenisoft-atum-enhancer' ) ); ?>';
							html += '</a></p>';
							html += '</div>';
							$result.html(html);
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during export.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// === Product-Supplier Export Scripts ===
			$(document).on('click', '#sae-product-supplier-export-btn', function() {
				var $btn = $(this);
				var $spinner = $btn.next('.spinner');
				var $result = $('#sae-product-supplier-export-result');

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');

				$.ajax({
					url: '<?php echo $ajax_url; ?>',
					type: 'POST',
					data: {
						action: 'sae_export_product_suppliers',
						nonce: '<?php echo esc_js( $product_supplier_export_nonce ); ?>'
					},
					success: function(response) {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');

						if (response.success) {
							var html = '<div class="notice notice-success">';
							html += '<p>' + response.data.message + '</p>';
							html += '<p><a href="' + response.data.download_url + '" class="button" download="' + response.data.filename + '">';
							html += '<?php echo esc_js( __( 'Download CSV', 'serenisoft-atum-enhancer' ) ); ?>';
							html += '</a></p>';
							html += '</div>';
							$result.html(html);
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during export.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// === Product-Supplier Import Scripts ===
			var productSupplierSelectedFile = null;

			// Preview button
			$(document).on('click', '#sae-product-supplier-preview-btn', function() {
				var fileInput = $('#sae-product-supplier-csv-file')[0];
				if (!fileInput.files.length) {
					alert('<?php echo esc_js( __( 'Please select a CSV file.', 'serenisoft-atum-enhancer' ) ); ?>');
					return;
				}

				productSupplierSelectedFile = fileInput.files[0];
				var formData = new FormData();
				formData.append('action', 'sae_preview_product_suppliers');
				formData.append('nonce', '<?php echo esc_js( $product_supplier_import_nonce ); ?>');
				formData.append('csv_file', productSupplierSelectedFile);

				var $btn = $(this);
				var $spinner = $btn.next('.spinner');
				var $preview = $('#sae-product-supplier-preview-result');

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$preview.html('');
				$('#sae-product-supplier-import-actions').hide();
				$('#sae-product-supplier-import-result').html('');

				$.ajax({
					url: '<?php echo $ajax_url; ?>',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');

						if (response.success) {
							var data = response.data;
							var html = '<p><strong><?php echo esc_js( __( 'Preview:', 'serenisoft-atum-enhancer' ) ); ?></strong> ';
							html += data.will_update + ' <?php echo esc_js( __( 'will be updated', 'serenisoft-atum-enhancer' ) ); ?>, ';
							html += data.will_skip + ' <?php echo esc_js( __( 'will be skipped', 'serenisoft-atum-enhancer' ) ); ?></p>';

							html += '<table class="sae-preview-table"><thead><tr>';
							html += '<th><?php echo esc_js( __( 'Product SKU', 'serenisoft-atum-enhancer' ) ); ?></th>';
							html += '<th><?php echo esc_js( __( 'Supplier Code', 'serenisoft-atum-enhancer' ) ); ?></th>';
							html += '<th><?php echo esc_js( __( 'Status', 'serenisoft-atum-enhancer' ) ); ?></th>';
							html += '</tr></thead><tbody>';

							data.rows.forEach(function(row) {
								var statusClass = 'sae-status-' + row.status;
								var statusText = row.status === 'update' ? '<?php echo esc_js( __( 'Will update', 'serenisoft-atum-enhancer' ) ); ?>' : row.reason;
								html += '<tr>';
								html += '<td>' + (row.product_sku || '-') + '</td>';
								html += '<td>' + (row.supplier_code || '-') + '</td>';
								html += '<td class="' + statusClass + '">' + statusText + '</td>';
								html += '</tr>';
							});

							html += '</tbody></table>';
							$preview.html(html);

							if (data.will_update > 0) {
								$('#sae-product-supplier-import-actions').show();
							}
						} else {
							$preview.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$preview.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during preview.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// Cancel button
			$(document).on('click', '#sae-product-supplier-cancel-btn', function() {
				$('#sae-product-supplier-preview-result').html('');
				$('#sae-product-supplier-import-actions').hide();
				$('#sae-product-supplier-csv-file').val('');
				productSupplierSelectedFile = null;
			});

			// Import button
			$(document).on('click', '#sae-product-supplier-import-btn', function() {
				if (!productSupplierSelectedFile) {
					alert('<?php echo esc_js( __( 'No file selected.', 'serenisoft-atum-enhancer' ) ); ?>');
					return;
				}

				var formData = new FormData();
				formData.append('action', 'sae_import_product_suppliers');
				formData.append('nonce', '<?php echo esc_js( $product_supplier_import_nonce ); ?>');
				formData.append('csv_file', productSupplierSelectedFile);

				var $btn = $(this);
				var $result = $('#sae-product-supplier-import-result');

				$btn.prop('disabled', true);
				$('#sae-product-supplier-cancel-btn').prop('disabled', true);

				$.ajax({
					url: '<?php echo $ajax_url; ?>',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(response) {
						$btn.prop('disabled', false);
						$('#sae-product-supplier-cancel-btn').prop('disabled', false);

						if (response.success) {
							$result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							$('#sae-product-supplier-preview-result').html('');
							$('#sae-product-supplier-import-actions').hide();
							$('#sae-product-supplier-csv-file').val('');
							productSupplierSelectedFile = null;

							if (response.data.errors && response.data.errors.length) {
								$result.append('<div class="notice notice-warning"><p><strong><?php echo esc_js( __( 'Errors:', 'serenisoft-atum-enhancer' ) ); ?></strong><br>' + response.data.errors.join('<br>') + '</p></div>');
							}
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$('#sae-product-supplier-cancel-btn').prop('disabled', false);
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during import.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// === Closed Periods Management Scripts ===
			// Note: ATUM Settings uses React/SPA - elements are rendered dynamically.
			// We must read data fresh from DOM when needed, not cache at document.ready.
			var saePeriodsData = [];
			var saePeriodsDebounce = null;

			// Load initial data (will be empty if element not rendered yet, but
			// event handlers use delegation so they'll work when element exists)
			function loadPeriodsData() {
				var $manager = $('.sae-closed-periods-manager');
				if ($manager.length && $manager.data('presets')) {
					var data = $manager.data('presets');
					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch(e) { data = []; }
					}
					return Array.isArray(data) ? data : [];
				}
				return [];
			}
			saePeriodsData = loadPeriodsData();

			// Save periods via AJAX
			function savePeriodsViaAjax() {
				var $status = $('.sae-periods-status');
				$status.text('<?php echo esc_js( __( 'Saving...', 'serenisoft-atum-enhancer' ) ); ?>');

				$.post(ajaxurl, {
					action: 'sae_save_closed_periods',
					nonce: $('.sae-closed-periods-manager').data('nonce'),  // Read fresh from DOM
					presets: JSON.stringify(saePeriodsData)
				}, function(response) {
					if (response.success) {
						$status.text('<?php echo esc_js( __( 'Saved', 'serenisoft-atum-enhancer' ) ); ?>');
						setTimeout(function() { $status.text(''); }, 2000);
					} else {
						$status.text('<?php echo esc_js( __( 'Error saving', 'serenisoft-atum-enhancer' ) ); ?>');
					}
				}).fail(function() {
					$status.text('<?php echo esc_js( __( 'Error saving', 'serenisoft-atum-enhancer' ) ); ?>');
				});
			}

			// Debounced save (wait 500ms after last change)
			function debouncedSave() {
				clearTimeout(saePeriodsDebounce);
				saePeriodsDebounce = setTimeout(savePeriodsViaAjax, 500);
			}

			function renderPeriodRows() {
				var html = '';
				$.each(saePeriodsData, function(index, period) {
					html += '<tr data-index="' + index + '">';
					html += '<td><input type="text" class="period-name" value="' + (period.name || '') + '" placeholder="<?php echo esc_js( __( 'e.g., Summer Vacation', 'serenisoft-atum-enhancer' ) ); ?>"></td>';
					html += '<td><input type="text" class="period-start" value="' + (period.start_date || '') + '" placeholder="01-07" pattern="\\d{2}-\\d{2}"></td>';
					html += '<td><input type="text" class="period-end" value="' + (period.end_date || '') + '" placeholder="15-08" pattern="\\d{2}-\\d{2}"></td>';
					html += '<td><a href="#" class="sae-remove-period"><?php echo esc_js( __( 'Remove', 'serenisoft-atum-enhancer' ) ); ?></a></td>';
					html += '</tr>';
				});

				if (!html) {
					html = '<tr><td colspan="4" style="text-align: center; color: #999;"><?php echo esc_js( __( 'No periods defined. Click "+ Add Period" to create one.', 'serenisoft-atum-enhancer' ) ); ?></td></tr>';
				}

				$('#sae-periods-list').html(html);
			}

			// Initial render - poll for element since ATUM uses React/SPA
			var saeInitInterval = setInterval(function() {
				if ($('#sae-periods-list').length) {
					clearInterval(saeInitInterval);
					saePeriodsData = loadPeriodsData();
					renderPeriodRows();
				}
			}, 200);

			// Add period button
			$(document).on('click', '#sae-add-period', function(e) {
				e.preventDefault();
				// Load data fresh on first interaction (in case doc ready ran before React rendered)
				if (!saePeriodsData.length) {
					saePeriodsData = loadPeriodsData();
				}
				saePeriodsData.push({
					id: 'period_' + Date.now(),
					name: '',
					start_date: '',
					end_date: ''
				});
				renderPeriodRows();
				debouncedSave();
			});

			// Remove period button
			$(document).on('click', '.sae-remove-period', function(e) {
				e.preventDefault();
				var index = $(this).closest('tr').data('index');
				saePeriodsData.splice(index, 1);
				renderPeriodRows();
				debouncedSave();
			});

			// Update on input change
			$(document).on('input', '.sae-periods-table input', function() {
				var row = $(this).closest('tr');
				var index = row.data('index');

				if (typeof saePeriodsData[index] !== 'undefined') {
					saePeriodsData[index].name = row.find('.period-name').val();
					saePeriodsData[index].start_date = row.find('.period-start').val();
					saePeriodsData[index].end_date = row.find('.period-end').val();
					debouncedSave();
				}
			});

			// === Generate PO Suggestions Scripts ===
			// Use event delegation since ATUM adds buttons dynamically after DOM ready
			// This makes it compatible with all browsers including Brave
			$(document).on('click', '#sae-generate-btn', function() {
				console.log('SAE: Generate button clicked');
				$('#sae-confirm-modal').css('display', 'block').show();
			});

			// Handle modal Cancel button (also use delegation)
			$(document).on('click', '#sae-confirm-no, .sae-modal-overlay', function() {
				console.log('SAE: Modal cancelled');
				$('#sae-confirm-modal').css('display', 'none').hide();
			});

			// Handle modal Yes button - actually run the generation
			$(document).on('click', '#sae-confirm-yes', function() {
				console.log('SAE: Modal confirmed, starting generation');
				// Hide modal
				$('#sae-confirm-modal').css('display', 'none').hide();

				var $btn = $('#sae-generate-btn');
				var $spinner = $btn.next('.spinner');
				var $result = $('#sae-generate-result');

				$btn.prop('disabled', true);
				$spinner.addClass('is-active');
				$result.html('');
				$('#sae-loading-overlay').fadeIn(200);

				$.ajax({
					url: '<?php echo $ajax_url; ?>',
					type: 'POST',
					data: {
						action: 'sae_generate_po_suggestions',
						nonce: '<?php echo esc_js( $generate_nonce ); ?>'
					},
					success: function(response) {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$('#sae-loading-overlay').fadeOut(200);

						if (response.success) {
							var data = response.data;

							// Check if choices are needed
							if (data.choices_needed && data.choices_needed.length > 0) {
								// Show choice dialog
								showPoChoiceDialog(data);
							} else {
								// Show normal results
								showGenerationResults(data);
							}
						} else {
							$result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						$spinner.removeClass('is-active');
						$('#sae-loading-overlay').fadeOut(200);
						$result.html('<div class="notice notice-error"><p><?php echo esc_js( __( 'An error occurred during generation.', 'serenisoft-atum-enhancer' ) ); ?></p></div>');
					}
				});
			});

			// === PO Choice Dialog Functions ===

			// Show PO choice dialog
			function showPoChoiceDialog(data) {
				var html = '';
				var $result = $('#sae-generate-result');
				var isDryRun = data.dry_run || false;

				// Store auto-created POs for later use in final summary
				var autoCreatedPos = data.created || [];

				// Build HTML for each supplier choice
				$.each(data.choices_needed, function(index, choice) {
					html += '<div class="sae-choice-item" data-supplier-id="' + choice.supplier_id + '">';
					html += '<h3>' + choice.supplier_name + '</h3>';
					html += '<div class="supplier-info">';
					html += choice.product_count + ' <?php echo esc_js( __( 'products need reordering', 'serenisoft-atum-enhancer' ) ); ?>';
					html += '</div>';

					html += '<div class="sae-existing-pos">';
					html += '<p><strong><?php echo esc_js( __( 'Choose action:', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';

					// Add radio buttons for each existing PO
					$.each(choice.existing_pos, function(i, po) {
						var poDate = new Date(po.date).toLocaleDateString();
						html += '<div class="sae-po-option">';
						html += '<input type="radio" name="choice_' + choice.supplier_id + '" ';
						html += 'id="po_' + po.id + '" value="add_to_' + po.id + '" ';
						if (i === 0) html += 'checked';
						html += '>';
						html += '<label for="po_' + po.id + '">';
						html += '<?php echo esc_js( __( 'Add to', 'serenisoft-atum-enhancer' ) ); ?> ';
						html += 'PO #' + po.id + ' (' + po.status_label + ') - ';
						html += po.product_count + ' <?php echo esc_js( __( 'products', 'serenisoft-atum-enhancer' ) ); ?>, ';
						html += poDate;
						html += '</label>';
						html += '</div>';
					});

					// Add "Create new PO" option
					html += '<div class="sae-po-option">';
					html += '<input type="radio" name="choice_' + choice.supplier_id + '" ';
					html += 'id="new_' + choice.supplier_id + '" value="create_new">';
					html += '<label for="new_' + choice.supplier_id + '">';
					html += '<?php echo esc_js( __( 'Create new Purchase Order', 'serenisoft-atum-enhancer' ) ); ?>';
					html += '</label>';
					html += '</div>';

					html += '</div>'; // .sae-existing-pos

					// Store products data
					html += '<input type="hidden" class="sae-choice-products" value="' +
							encodeURIComponent(JSON.stringify(choice.products)) + '">';

					html += '</div>'; // .sae-choice-item
				});

				$('.sae-choices-container').html(html);

				// Store auto-created POs as data attribute for later use in final summary
				$('.sae-choice-overlay').data('auto-created-pos', autoCreatedPos);

				// Handle Execute Choices button based on dry run mode
				if (isDryRun) {
					// Hide the Execute Choices button in dry run mode
					$('#sae-execute-choices').hide();

					// Show dry run warning before Cancel button
					var warningHtml = '<div class="sae-dry-run-warning" style="padding: 12px; background: #fff3cd; border-left: 4px solid #ffc107; margin-bottom: 15px;">';
					warningHtml += '<strong>⚠️ <?php echo esc_js( __( 'Choice execution is disabled in Dry Run mode', 'serenisoft-atum-enhancer' ) ); ?></strong><br>';
					warningHtml += '<?php echo esc_js( __( 'This is a preview only. Disable Dry Run Mode to execute choices and modify Purchase Orders.', 'serenisoft-atum-enhancer' ) ); ?>';
					warningHtml += '</div>';
					$('.sae-choice-actions').prepend(warningHtml);
				} else {
					// Show the Execute Choices button in normal mode
					$('#sae-execute-choices').show();
					$('.sae-dry-run-warning').remove();
				}

				$('.sae-choice-overlay').fadeIn(200);

				// Also show auto-created results if any
				if (data.created && data.created.length > 0) {
					var isDryRun = data.dry_run || false;
					var noticeClass = isDryRun ? 'notice-warning' : 'notice-success';
					var html = '<div class="notice ' + noticeClass + '">';

					if (isDryRun) {
						html += '<p><strong>⚠️ <?php echo esc_js( __( 'DRY RUN - Preview Only', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';
						html += '<p>' + data.created.length + ' <?php echo esc_js( __( 'PO suggestions would be created for suppliers without existing POs.', 'serenisoft-atum-enhancer' ) ); ?></p>';
						html += '<p style="color: #856404; background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0;">';
						html += '<?php echo esc_js( __( 'ℹ️ No Purchase Orders were created. This is a preview. Disable Dry Run Mode to create actual POs.', 'serenisoft-atum-enhancer' ) ); ?>';
						html += '</p>';
					} else {
						html += '<p><?php echo esc_js( __( 'Auto-created', 'serenisoft-atum-enhancer' ) ); ?> ' + data.created.length + ' <?php echo esc_js( __( 'Purchase Orders for suppliers without existing POs.', 'serenisoft-atum-enhancer' ) ); ?></p>';
					}

					html += '</div>';
					$result.html(html);
				}
			}

			// Show normal generation results
			function showGenerationResults(data) {
				var $result = $('#sae-generate-result');
				var isDryRun = data.dry_run || false;
				var noticeClass = isDryRun ? 'notice-warning' : 'notice-success';

				var html = '<div class="notice ' + noticeClass + '">';
				html += '<p><strong>' + data.message + '</strong></p>';

				// Dry run warning
				if (isDryRun) {
					html += '<p style="color: #856404; background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 15px 0;">';
					html += '<?php echo esc_js( __( 'ℹ️ No Purchase Orders were created. This is a preview of what would be generated. Disable Dry Run Mode to create actual POs.', 'serenisoft-atum-enhancer' ) ); ?>';
					html += '</p>';
				}

				// Statistics
				html += '<h4><?php echo esc_js( __( 'Analysis Summary:', 'serenisoft-atum-enhancer' ) ); ?></h4>';
				html += '<ul>';
				html += '<li><?php echo esc_js( __( 'Total suppliers analyzed:', 'serenisoft-atum-enhancer' ) ); ?> <strong>' + data.total_suppliers + '</strong></li>';
				html += '<li><?php echo esc_js( __( 'Total products analyzed:', 'serenisoft-atum-enhancer' ) ); ?> <strong>' + data.total_products + '</strong></li>';
				html += '<li><?php echo esc_js( __( 'Products below reorder point:', 'serenisoft-atum-enhancer' ) ); ?> <strong>' + data.products_below_reorder + '</strong></li>';
				html += '</ul>';

				// Created POs / Preview
				if (data.created && data.created.length) {
					if (isDryRun) {
						html += '<h4><?php echo esc_js( __( 'Preview - Would Create:', 'serenisoft-atum-enhancer' ) ); ?></h4>';
					} else {
						html += '<h4><?php echo esc_js( __( 'Purchase Orders Created:', 'serenisoft-atum-enhancer' ) ); ?></h4>';
					}
					html += '<ul>';
					data.created.forEach(function(po) {
						if (isDryRun) {
							html += '<li><strong>' + po.supplier_name + '</strong> - ' + po.items_count + ' <?php echo esc_js( __( 'items', 'serenisoft-atum-enhancer' ) ); ?></li>';
						} else {
							html += '<li><a href="' + po.edit_url + '" target="_blank">' + po.supplier_name + '</a> - ' + po.items_count + ' <?php echo esc_js( __( 'items', 'serenisoft-atum-enhancer' ) ); ?></li>';
						}
					});
					html += '</ul>';
				}

				html += '</div>';

				// Products without supplier warning
				if (data.products_without_supplier && data.products_without_supplier.length) {
					html += '<div class="notice notice-warning">';
					html += '<p><strong><?php echo esc_js( __( 'Products Without Supplier:', 'serenisoft-atum-enhancer' ) ); ?></strong> ';
					html += data.products_without_supplier.length + ' <?php echo esc_js( __( 'products found', 'serenisoft-atum-enhancer' ) ); ?></p>';
					html += '<ul style="max-height: 200px; overflow-y: auto;">';
					data.products_without_supplier.forEach(function(product) {
						html += '<li>';
						html += '<a href="<?php echo admin_url( 'post.php?action=edit&post=' ); ?>' + product.id + '" target="_blank">';
						html += product.name + ' (SKU: ' + (product.sku || '-') + ')';
						html += '</a>';
						html += ' - <?php echo esc_js( __( 'Stock:', 'serenisoft-atum-enhancer' ) ); ?> ' + (product.stock || '0');
						html += '</li>';
					});
					html += '</ul>';
					html += '</div>';
				}

				$result.html(html);

				// Errors
				if (data.errors && data.errors.length) {
					$result.append('<div class="notice notice-error"><p><strong><?php echo esc_js( __( 'Errors:', 'serenisoft-atum-enhancer' ) ); ?></strong><br>' + data.errors.join('<br>') + '</p></div>');
				}
			}

			// Handle "Execute Choices" button
			$(document).on('click', '#sae-execute-choices', function() {
				var choices = [];

				// Collect all choices
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

				// Execute each choice sequentially
				var results = [];

				function executeNext(index) {
					if (index >= choices.length) {
						// All done - get auto-created POs and show combined results
						var autoCreatedPos = $('.sae-choice-overlay').data('auto-created-pos') || [];
						$('.sae-choice-overlay').fadeOut(200);
						showFinalResults(results, autoCreatedPos);
						return;
					}

					var choice = choices[index];

					$.ajax({
						url: '<?php echo $ajax_url; ?>',
						type: 'POST',
						data: {
							action: 'sae_execute_po_choice',
							nonce: '<?php echo wp_create_nonce( 'sae_execute_po_choice' ); ?>',
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
								data: {message: '<?php echo esc_js( __( 'AJAX error for supplier', 'serenisoft-atum-enhancer' ) ); ?> ' + choice.supplier_id}
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

			// Show final results after executing all choices
			function showFinalResults(results, autoCreatedPos) {
				var successCount = 0;
				var errorCount = 0;
				var createdPos = [];

				// Count choice execution results
				$.each(results, function(i, result) {
					if (result.success) {
						successCount++;
						createdPos.push(result.data);
					} else {
						errorCount++;
					}
				});

				// Combine auto-created and choice execution POs
				var allPOs = (autoCreatedPos || []).concat(createdPos);
				var totalPOs = allPOs.length;
				var totalItems = 0;

				// Calculate total items across all POs
				$.each(allPOs, function(i, po) {
					totalItems += po.items_count || 0;
				});

				var $result = $('#sae-generate-result');
				var html = '<div class="notice notice-success">';
				html += '<p><strong><?php echo esc_js( __( 'PO Generation Complete', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';
				html += '<p><?php echo esc_js( __( 'Total:', 'serenisoft-atum-enhancer' ) ); ?> <strong>' + totalPOs + ' <?php echo esc_js( __( 'Purchase Orders created with', 'serenisoft-atum-enhancer' ) ); ?> ' + totalItems + ' <?php echo esc_js( __( 'items', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';

				// Show breakdown if both types exist
				if ((autoCreatedPos && autoCreatedPos.length > 0) && createdPos.length > 0) {
					html += '<ul style="margin: 10px 0;">';
					html += '<li>' + autoCreatedPos.length + ' <?php echo esc_js( __( 'POs auto-created for suppliers without existing orders', 'serenisoft-atum-enhancer' ) ); ?></li>';
					html += '<li>' + createdPos.length + ' <?php echo esc_js( __( 'PO(s) created from choice execution', 'serenisoft-atum-enhancer' ) ); ?></li>';
					html += '</ul>';
				}

				if (createdPos.length > 0) {
					html += '<h4><?php echo esc_js( __( 'Results:', 'serenisoft-atum-enhancer' ) ); ?></h4>';
					html += '<ul style="list-style-type: none;">';
					createdPos.forEach(function(po) {
						html += '<li style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #2271b1;">';
						html += '<strong>' + po.message + '</strong>';

						// Show supplier name if available
						if (po.supplier_name) {
							html += '<br><em><?php echo esc_js( __( 'Supplier:', 'serenisoft-atum-enhancer' ) ); ?> ' + po.supplier_name + '</em>';
						}

						// Show product list if available
						if (po.products && po.products.length > 0) {
							html += '<br><span style="font-size: 0.9em; color: #666;"><?php echo esc_js( __( 'Products:', 'serenisoft-atum-enhancer' ) ); ?></span>';
							html += '<ul style="margin: 5px 0 0 20px; font-size: 0.9em;">';
							po.products.forEach(function(product) {
								html += '<li>';
								html += product.name;
								if (product.sku) {
									html += ' <span style="color: #666;">(<?php echo esc_js( __( 'SKU:', 'serenisoft-atum-enhancer' ) ); ?> ' + product.sku + ')</span>';
								}
								html += ' - <strong><?php echo esc_js( __( 'Qty:', 'serenisoft-atum-enhancer' ) ); ?> ' + product.qty + '</strong>';
								html += '</li>';
							});
							html += '</ul>';
						}

						html += '</li>';
					});
					html += '</ul>';
				}

				html += '</div>';

				if (errorCount > 0) {
					html += '<div class="notice notice-error">';
					html += '<p><strong><?php echo esc_js( __( 'Some choices failed to execute. Check logs for details.', 'serenisoft-atum-enhancer' ) ); ?></strong></p>';
					html += '</div>';
				}

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
