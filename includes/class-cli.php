<?php
namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class CLI {

	/**
	 * Rebuild OAI-PMH index
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Clear cache before reindexing
	 *
	 * [--collection=<id>]
	 * : Only reindex specific collection
	 *
	 * ## EXAMPLES
	 *     wp tainacan-oai reindex
	 *     wp tainacan-oai reindex --clear
	 *     wp tainacan-oai reindex --collection=123
	 *
	 * @when after_wp_load
	 */
	public function reindex( $args, $assoc_args ) {
		$cache = new Cache();

		if ( ! empty( $assoc_args['clear'] ) ) {
			$cache->clear();
			\WP_CLI::log( 'Cache cleared.' );
		}

		\WP_CLI::log( 'Starting reindex...' );
		$start = microtime( true );

		if ( ! empty( $assoc_args['collection'] ) ) {
			$count = $cache->reindex_collection( (int) $assoc_args['collection'] );
		} else {
			$count = $cache->rebuild_index(
				function ( $n, $id ) {
					if ( $n % 100 === 0 ) {
						\WP_CLI::log( "Indexed $n items..." );
					}
				}
			);
		}

		$time = round( microtime( true ) - $start, 2 );
		\WP_CLI::success( "Indexed $count items in {$time}s" );
	}

	/**
	 * Clear OAI-PMH cache
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		$cache = new Cache();
		$cache->clear();
		\WP_CLI::success( 'Cache cleared.' );
	}

	/**
	 * Show statistics
	 *
	 * @when after_wp_load
	 */
	public function stats( $args, $assoc_args ) {
		$cache  = new Cache();
		$stats  = $cache->get_stats();
		$health = $cache->get_health();

		\WP_CLI::log( 'OAI-PMH Statistics' );
		\WP_CLI::log( '==================' );
		\WP_CLI::log( "Total Items: {$stats['total_items']}" );
		\WP_CLI::log( "Published: {$stats['published_items']}" );
		\WP_CLI::log( "Deleted: {$stats['deleted_items']}" );
		\WP_CLI::log( 'Last Indexed: ' . ( $stats['last_indexed'] ?: 'Never' ) );
		\WP_CLI::log( "Sync: {$health['sync_percentage']}%" );
		\WP_CLI::log( "Status: {$health['status']}" );
	}

	/**
	 * Run validation
	 *
	 * @when after_wp_load
	 */
	public function validate( $args, $assoc_args ) {
		$validator = new Validator();
		$results   = $validator->run();

		\WP_CLI::log( "Score: {$results['score']}%" );
		\WP_CLI::log( "Passed: {$results['passed']}" );
		\WP_CLI::log( "Warnings: {$results['warnings']}" );
		\WP_CLI::log( "Failed: {$results['failed']}" );

		foreach ( $results['tests'] as $test ) {
			$icon = $test['status'] === 'passed' ? '✓' : ( $test['status'] === 'warning' ? '!' : '✗' );
			\WP_CLI::log( "[$icon] {$test['name']}" );
		}
	}

	/**
	 * Show endpoint info
	 *
	 * @when after_wp_load
	 */
	public function info( $args, $assoc_args ) {
		\WP_CLI::log( 'Endpoint: ' . rest_url( 'tainacan-oai/v1/oai' ) );
		\WP_CLI::log( 'Repository: ' . Settings::get( 'repository_name', get_bloginfo( 'name' ) ) );
		\WP_CLI::log( 'Max Records: ' . Settings::get( 'max_records', 100 ) );
		\WP_CLI::log( 'Cache: ' . ( Settings::get( 'cache_enabled', true ) ? 'Enabled' : 'Disabled' ) );
	}
}

\WP_CLI::add_command( 'tainacan-oai', '\Tainacan_OAI_PMH\CLI' );
