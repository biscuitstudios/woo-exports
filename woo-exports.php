<?php
/**
 * Plugin Name:       WooExports
 * Plugin URI:        https://github.com/biscuitstudios/woo-exports
 * Description:       WooCommerce reporting for agency use. Build named export configurations (Products, Orders, Customers, Attendees), schedule emailed exports, and download CSV/XLSX attachments.
 * Version:           0.10.1
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            Biscuit Studios
 * Author URI:        https://biscuitstudios.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-exports
 * Update URI:        https://github.com/biscuitstudios/woo-exports
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOOEX_VERSION', '0.10.1' );
define( 'WOOEX_FILE', __FILE__ );
define( 'WOOEX_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOOEX_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOEX_BASENAME', plugin_basename( __FILE__ ) );

if ( file_exists( WOOEX_DIR . 'vendor/autoload.php' ) ) {
	require_once WOOEX_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'Wooex_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', $class ) );

		$candidates = [
			WOOEX_DIR . 'includes/class-' . $slug . '.php',
			WOOEX_DIR . 'admin/class-' . $slug . '.php',
		];

		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, [ 'Wooex_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Wooex_Activator', 'deactivate' ] );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WOOEX_FILE,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>WooExports</strong> requires WooCommerce to be installed and active.</p></div>';
				}
			);
			return;
		}

		// Action Scheduler ships with WC, so if WC is active it should be too.
		// Detect missing AS anyway — without it, scheduled exports silently
		// won't fire (they'd just sit unscheduled forever).
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p><strong>WooExports:</strong> Action Scheduler is not available. Scheduled exports will not run. Reinstall or update WooCommerce.</p></div>';
				}
			);
		}

		// Scheduler runs in any context — Action Scheduler fires callbacks via wp-cron,
		// which is not admin. Must register the hook outside the is_admin() gate.
		if ( class_exists( 'Wooex_Scheduler' ) ) {
			( new Wooex_Scheduler() )->init();
		}

		if ( is_admin() ) {
			if ( class_exists( 'Wooex_Admin' ) ) {
				( new Wooex_Admin() )->init();
			}
		}
	}
);
