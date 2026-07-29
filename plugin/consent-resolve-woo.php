<?php
/**
 * Plugin Name:       Consent Resolve for WooCommerce
 * Plugin URI:        https://consentresolve.com/woocommerce
 * Description:       Consent-first abandoned-cart recovery for WooCommerce. Capture abandoning shoppers, win back carts with a compliant email sequence, and build retargeting-ready audiences — without emailing people who never opted in.
 * Version:           0.4.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 * Author:            Consent Resolve
 * Author URI:        https://consentresolve.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       consent-resolve-woo
 * Domain Path:       /languages
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

define( 'CRW_VERSION', '0.4.2' );
define( 'CRW_FILE', __FILE__ );
define( 'CRW_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRW_URL', plugin_dir_url( __FILE__ ) );
define( 'CRW_BASENAME', plugin_basename( __FILE__ ) );

// Lightweight autoloader for the CRW_ class prefix in /inc (+ /inc/destinations).
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'CRW_' ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', substr( $class, 4 ) ) );
		$path = CRW_DIR . 'inc/class-crw-' . $slug . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'CRW_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CRW_Install', 'deactivate' ) );

// Register the 5-minute cron interval at load — must exist before activate()
// calls wp_schedule_event() in the same request. phpcs:ignore WordPress.WP.CronInterval
add_filter( 'cron_schedules', array( 'CRW_Install', 'cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval

// Declare High-Performance Order Storage (HPOS) compatibility — we only read
// orders through the CRUD API, so we are compatible with custom order tables.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Boot once WooCommerce (and any other plugins) are loaded. If WooCommerce is
 * inactive we degrade to an admin notice and do nothing else — the plugin never
 * fatals a store.
 */
function consent_resolve_woo() {
	static $plugin = null;
	if ( null === $plugin ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'crw_woocommerce_missing_notice' );
			return null;
		}
		$plugin = new CRW_Plugin();
		$plugin->boot();
	}
	return $plugin;
}
add_action( 'plugins_loaded', 'consent_resolve_woo', 20 ); // After WooCommerce (10) and Consent Resolve (5).

/**
 * Admin notice shown when WooCommerce is not active.
 */
function crw_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Consent Resolve for WooCommerce', 'consent-resolve-woo' ) . '</strong> ' . esc_html__( 'needs WooCommerce to be installed and active.', 'consent-resolve-woo' ) . '</p></div>';
}
