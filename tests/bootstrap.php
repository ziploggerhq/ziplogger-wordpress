<?php
/**
 * PHPUnit bootstrap: loads WordPress's test library and the plugin.
 */

$zl_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';
$zl_plugin    = getenv( 'ZIPLOGGER_PLUGIN_DIR' ) ? getenv( 'ZIPLOGGER_PLUGIN_DIR' ) : dirname( __DIR__ ) . '/plugin/ziplogger';

if ( ! file_exists( $zl_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$zl_tests_dir}. Run the tests through docker-compose.test.yml.\n" ); // phpcs:ignore
	exit( 1 );
}

if ( '1' === getenv( 'WP_MULTISITE' ) && ! defined( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

require_once $zl_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $zl_plugin ) {
		require $zl_plugin . '/ziplogger.php';
	}
);

// WooCommerce, when the image has it: loaded before the plugin, tables installed once before the tests.
$zl_wc = getenv( 'WC_PLUGIN_DIR' );
if ( $zl_wc && is_readable( $zl_wc . '/woocommerce.php' ) ) {
	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $zl_wc ) {
			require $zl_wc . '/woocommerce.php';
		},
		1
	);
	tests_add_filter(
		'setup_theme',
		static function () {
			if ( class_exists( 'WC_Install' ) ) {
				WC_Install::install();
			}
		}
	);
}

require $zl_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/phpunit/class-zl-testcase.php';
