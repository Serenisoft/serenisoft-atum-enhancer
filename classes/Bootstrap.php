<?php
/**
 * Main loader for SereniSoft ATUM Enhancer
 *
 * @package     SereniSoft\AtumEnhancer
 * @author      SereniSoft
 * @copyright   2025 SereniSoft
 *
 * @since 1.0.0
 */

namespace SereniSoft\AtumEnhancer;

defined( 'ABSPATH' ) || die;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use SereniSoft\AtumEnhancer\Settings\Settings;
use SereniSoft\AtumEnhancer\SupplierImport\SupplierImport;
use SereniSoft\AtumEnhancer\Suppliers\SupplierFields;
use SereniSoft\AtumEnhancer\Products\ProductFields;
use SereniSoft\AtumEnhancer\PurchaseOrderSuggestions\POSuggestionGenerator;
use SereniSoft\AtumEnhancer\BulkActions\BulkSupplierAssignment;
use SereniSoft\AtumEnhancer\StockCentral\StockCentralColumns;
use SereniSoft\AtumEnhancer\StockCentral\SalesDataColumns;

class Bootstrap {

	/**
	 * The singleton instance holder
	 *
	 * @var Bootstrap
	 */
	private static $instance;

	/**
	 * Flag to indicate the plugin has been bootstrapped
	 *
	 * @var bool
	 */
	private $bootstrapped = false;

	/**
	 * Bootstrap constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Check all the requirements before bootstrapping.
		add_action( 'plugins_loaded', array( $this, 'maybe_bootstrap' ) );

		// Register compatibility with new WC features.
		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibilities' ) );

		// Load text domain.
		add_action( 'init', array( $this, 'load_textdomain' ) );

	}

	/**
	 * Initial checking and plugin bootstrap
	 *
	 * @since 1.0.0
	 */
	public function maybe_bootstrap() {

		if ( $this->bootstrapped ) {
			return;
		}

		// Check that the plugin dependencies are met.
		if ( ! $this->check_dependencies() ) {
			return;
		}

		// Bootstrap the plugin.
		$this->bootstrapped = true;

		// Initialize plugin components.
		$this->init_components();

	}

	/**
	 * Initialize plugin components
	 *
	 * @since 1.0.0
	 */
	private function init_components() {

		// Initialize Settings.
		Settings::get_instance();

		// Initialize Supplier Import.
		SupplierImport::get_instance();

		// Initialize Supplier Fields.
		SupplierFields::get_instance();

		// Initialize Product Fields.
		ProductFields::get_instance();

		// Initialize PO Suggestion Generator.
		POSuggestionGenerator::get_instance();

		// Initialize Bulk Supplier Assignment.
		BulkSupplierAssignment::get_instance();

		// Initialize Stock Central Columns (MOQ).
		StockCentralColumns::get_instance();

		// Initialize Stock Central Sales Data Columns.
		SalesDataColumns::get_instance();

	}

	/**
	 * Check the plugin dependencies before bootstrapping
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if all dependencies are met.
	 */
	private function check_dependencies() {

		$errors = array();

		// WooCommerce required.
		if ( ! function_exists( 'WC' ) ) {
			$errors[] = __( 'SereniSoft ATUM Enhancer requires WooCommerce to be activated.', 'serenisoft-atum-enhancer' );
		}

		// ATUM required.
		if ( ! class_exists( '\Atum\Bootstrap' ) ) {
			$errors[] = __( 'SereniSoft ATUM Enhancer requires ATUM Inventory Management to be activated.', 'serenisoft-atum-enhancer' );
		}

		// Minimum WordPress version required.
		global $wp_version;
		if ( version_compare( $wp_version, SAE_WP_MINIMUM_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: minimum WordPress version */
				__( 'SereniSoft ATUM Enhancer requires WordPress version %s or greater.', 'serenisoft-atum-enhancer' ),
				SAE_WP_MINIMUM_VERSION
			);
		}

		// Minimum WooCommerce version required.
		if ( function_exists( 'WC' ) && version_compare( WC()->version, SAE_WC_MINIMUM_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: minimum WooCommerce version */
				__( 'SereniSoft ATUM Enhancer requires WooCommerce version %s or greater.', 'serenisoft-atum-enhancer' ),
				SAE_WC_MINIMUM_VERSION
			);
		}

		// Display errors if any.
		if ( ! empty( $errors ) ) {
			add_action( 'admin_notices', function() use ( $errors ) {
				foreach ( $errors as $error ) {
					?>
					<div class="error fade">
						<p><strong><?php echo esc_html( $error ); ?></strong></p>
					</div>
					<?php
				}
			} );

			return false;
		}

		return true;

	}

	/**
	 * Register compatibility with new WC features.
	 *
	 * @since 1.0.0
	 */
	public function declare_wc_compatibilities() {

		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', SAE_BASENAME ); // HPOS compatibility.
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SAE_BASENAME ); // Checkout block compatibility.
		}

	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {

		load_plugin_textdomain(
			'serenisoft-atum-enhancer',
			false,
			dirname( SAE_BASENAME ) . '/languages'
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
	 * @return Bootstrap instance
	 */
	public static function get_instance() {

		if ( ! ( self::$instance && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

}
