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
				'general'      => __( 'General Settings', 'serenisoft-atum-enhancer' ),
				'po_algorithm' => __( 'Purchase Order Algorithm', 'serenisoft-atum-enhancer' ),
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

		return $defaults;

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
