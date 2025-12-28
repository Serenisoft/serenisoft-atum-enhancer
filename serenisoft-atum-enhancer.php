<?php
/**
 * SereniSoft ATUM Enhancer
 *
 * @package              SereniSoft\AtumEnhancer
 *
 * @wordpress-plugin
 * Plugin Name:          SereniSoft ATUM Enhancer
 * Requires Plugins:     woocommerce, atum-stock-manager-for-woocommerce
 * Plugin URI:           https://serenisoft.no/
 * Description:          SAE (SereniSoft ATUM Enhancer) extends ATUM Inventory Management with automatic purchase order suggestions based on stock levels, lead times, seasonal patterns, and supplier closed periods.
 * Version:              0.9.24
 * Author:               SereniSoft
 * Author URI:           https://serenisoft.no/
 * Requires at least:    5.9
 * Tested up to:         6.9.0
 * Requires PHP:         7.4
 * WC requires at least: 5.0
 * WC tested up to:      10.3.5
 * Text Domain:          serenisoft-atum-enhancer
 * Domain Path:          /languages
 * License:              GPLv2 or later
 * License URI:          http://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || die;

if ( ! defined( 'SAE_VERSION' ) ) {
	define( 'SAE_VERSION', '0.9.24' );
}

if ( ! defined( 'SAE_PHP_MINIMUM_VERSION' ) ) {
	define( 'SAE_PHP_MINIMUM_VERSION', '7.4' );
}

if ( ! defined( 'SAE_WP_MINIMUM_VERSION' ) ) {
	define( 'SAE_WP_MINIMUM_VERSION', '5.9' );
}

if ( ! defined( 'SAE_WC_MINIMUM_VERSION' ) ) {
	define( 'SAE_WC_MINIMUM_VERSION', '5.0' );
}

if ( ! defined( 'SAE_PATH' ) ) {
	define( 'SAE_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SAE_URL' ) ) {
	define( 'SAE_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'SAE_BASENAME' ) ) {
	define( 'SAE_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'SAE_TEXT_DOMAIN' ) ) {
	define( 'SAE_TEXT_DOMAIN', 'serenisoft-atum-enhancer' );
}

if ( ! defined( 'SAE_PREFIX' ) ) {
	define( 'SAE_PREFIX', 'sae_' );
}

// Check minimum PHP version required.
if ( version_compare( phpversion(), SAE_PHP_MINIMUM_VERSION, '<' ) ) {

	add_action( 'admin_notices', function() {
		?>
		<div class="error fade">
			<p>
				<strong>
					<?php
					printf(
						/* translators: %s: minimum PHP version */
						esc_html__( 'SereniSoft ATUM Enhancer requires PHP version %s or greater. Please update or contact your hosting provider.', 'serenisoft-atum-enhancer' ),
						esc_html( SAE_PHP_MINIMUM_VERSION )
					);
					?>
				</strong>
			</p>
		</div>
		<?php
	} );

}
else {

	// Use Composer's autoloader and PSR4 for naming convention.
	require SAE_PATH . 'vendor/autoload.php';
	\SereniSoft\AtumEnhancer\Bootstrap::get_instance();

	// Plugin activation hook.
	register_activation_hook( __FILE__, function() {
		// Schedule cron if auto suggestions are enabled.
		if ( class_exists( '\SereniSoft\AtumEnhancer\PurchaseOrderSuggestions\POSuggestionGenerator' ) ) {
			\SereniSoft\AtumEnhancer\PurchaseOrderSuggestions\POSuggestionGenerator::get_instance()->maybe_reschedule_cron();
		}
	} );

	// Plugin deactivation hook.
	register_deactivation_hook( __FILE__, function() {
		// Unschedule cron.
		if ( class_exists( '\SereniSoft\AtumEnhancer\PurchaseOrderSuggestions\POSuggestionGenerator' ) ) {
			\SereniSoft\AtumEnhancer\PurchaseOrderSuggestions\POSuggestionGenerator::get_instance()->unschedule_cron();
		}
	} );

}
