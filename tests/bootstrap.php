<?php
/**
 * PHPUnit bootstrap: WordPress test suite + WooCommerce (HPOS on) + this plugin.
 *
 * Needs: WP_CORE_DIR (a WordPress copy), WC_DIR (an unzipped WooCommerce), and a test database
 * (WP_TESTS_DB_NAME / _USER / _PASS / _HOST). See README "Running the tests".
 *
 * @package Lumaha_Simple_Event_Tickets
 */

$lset_root = dirname( __DIR__ );
require_once $lset_root . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
$lset_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ? getenv( 'WP_PHPUNIT__DIR' ) : $lset_root . '/vendor/wp-phpunit/wp-phpunit';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $lset_root . '/vendor/yoast/phpunit-polyfills' );

require_once $lset_tests_dir . '/includes/functions.php';

$lset_wc_dir = rtrim( getenv( 'WC_DIR' ) ? getenv( 'WC_DIR' ) : '/tmp/woocommerce', '/' );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $lset_wc_dir, $lset_root ) {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		require $lset_wc_dir . '/woocommerce.php';
		require $lset_root . '/lumaha-simple-event-tickets.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		WC_Install::install();
		update_option( 'timezone_string', 'Europe/London' );
		update_option( 'woocommerce_currency', 'GBP' );
		LSET_Install::activate();
		$GLOBALS['wp_roles'] = new WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload caps added above.
	}
);

require $lset_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-lset-test-case.php';
