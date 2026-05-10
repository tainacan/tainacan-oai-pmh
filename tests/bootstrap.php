<?php
/**
 * PHPUnit bootstrap for Tainacan OAI-PMH.
 *
 * Loads the WordPress test suite (set up via tests/bin/install-wp-tests.sh)
 * and registers the plugin via the muplugins_loaded hook so the test suite
 * finds it before WordPress fully boots. Tainacan core must also be present
 * in the test environment for integration tests to run.
 *
 * @package Tainacan_OAI_PMH
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( empty( $_tests_dir ) ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php. Run tests/bin/install-wp-tests.sh first.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI bootstrap output, not a WP runtime context.
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin (and its Tainacan dependency, if installed).
 */
function _manually_load_plugin() {
	$tainacan_main = dirname( __DIR__, 2 ) . '/tainacan/tainacan.php';
	if ( file_exists( $tainacan_main ) ) {
		require $tainacan_main;
	}
	require dirname( __DIR__ ) . '/tainacan-oai-pmh.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
