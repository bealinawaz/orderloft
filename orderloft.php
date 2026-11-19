<?php
/**
 * Plugin Name:          Orderloft – Archive Old Orders for WooCommerce
 * Plugin URI:           https://github.com/bealinawaz/orderloft
 * Description:          Move old WooCommerce orders out of your live order tables into an archive, automatically or by hand. Restore any order exactly as it was. WooCommerce Analytics keeps counting archived orders.
 * Version:              1.0.2
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Ali Nawaz
 * Author URI:           https://bealinawaz.com
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          orderloft
 * Domain Path:          /languages
 * WC requires at least: 8.2
 * WC tested up to:      11.3
 *
 * @package Orderloft
 */

defined( 'ABSPATH' ) || exit;

define( 'ORDERLOFT_VERSION', '1.0.2' );
define( 'ORDERLOFT_FILE', __FILE__ );
define( 'ORDERLOFT_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-orderloft-settings.php';
require_once __DIR__ . '/includes/class-orderloft-schema.php';
require_once __DIR__ . '/includes/class-orderloft-archiver.php';
require_once __DIR__ . '/includes/class-orderloft-scheduler.php';
require_once __DIR__ . '/includes/class-orderloft-account.php';
require_once __DIR__ . '/includes/class-orderloft-privacy.php';

// Orderloft reads orders through WooCommerce's CRUD API and moves rows of whichever order storage is in use
// (HPOS, HPOS with compatibility sync, or legacy posts), so it supports HPOS. It adds nothing to checkout.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ORDERLOFT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ORDERLOFT_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'Orderloft_Schema', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Orderloft_Scheduler', 'unschedule_all' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Orderloft_Schema::maybe_upgrade();
		Orderloft_Scheduler::init();
		Orderloft_Account::init();
		Orderloft_Privacy::init();

		if ( is_admin() ) {
			require_once ORDERLOFT_DIR . '/includes/class-orderloft-list-table.php';
			require_once ORDERLOFT_DIR . '/includes/class-orderloft-admin.php';
			Orderloft_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ORDERLOFT_DIR . '/includes/class-orderloft-cli.php';
			WP_CLI::add_command( 'orderloft', 'Orderloft_CLI' );
		}
	}
);
