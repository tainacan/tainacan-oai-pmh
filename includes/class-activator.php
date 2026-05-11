<?php
/**
 * Plugin activator: provisions custom tables and schedules cron events.
 *
 * Uses dbDelta() (the WordPress-recommended schema API) instead of raw
 * $wpdb->query("CREATE TABLE …"), which eliminates several PHPCS warnings
 * about DirectDatabaseQuery / SchemaChange / NoCaching and gives us
 * idempotent ALTER TABLE handling for free when columns are added in
 * later versions.
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator: creates and migrates the plugin's custom tables.
 */
class Activator {

	/**
	 * Runs on plugin activation and on version bumps.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::create_tables();

		if ( ! wp_next_scheduled( 'tainacan_oai_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'tainacan_oai_daily_maintenance' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Defines and applies the schema via dbDelta().
	 *
	 * The dbDelta() routine is idempotent: it compares the desired schema against the
	 * live tables and emits ALTER TABLE statements only for differences.
	 * Replaces the previous CREATE TABLE IF NOT EXISTS + information_schema
	 * probe + manual ALTER block.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$tables = array(
			"{$prefix}tainacan_oai_cache"       => "CREATE TABLE {$prefix}tainacan_oai_cache (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				item_id BIGINT UNSIGNED NOT NULL,
				collection_id BIGINT UNSIGNED NOT NULL,
				identifier VARCHAR(255) NOT NULL,
				datestamp DATETIME NOT NULL,
				metadata_json LONGTEXT,
				status VARCHAR(20) DEFAULT 'publish',
				checksum VARCHAR(32),
				last_indexed DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY item_id (item_id),
				KEY collection_id (collection_id),
				KEY datestamp (datestamp),
				KEY status (status)
			) $charset",

			"{$prefix}tainacan_oai_logs"        => "CREATE TABLE {$prefix}tainacan_oai_logs (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				level VARCHAR(20) NOT NULL,
				message TEXT NOT NULL,
				context TEXT,
				ip_address VARCHAR(45),
				user_agent VARCHAR(500),
				verb VARCHAR(50),
				response_time FLOAT,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY level (level),
				KEY verb (verb),
				KEY created_at (created_at)
			) $charset",

			"{$prefix}tainacan_oai_harvesters"  => "CREATE TABLE {$prefix}tainacan_oai_harvesters (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				ip_address VARCHAR(45) NOT NULL,
				user_agent VARCHAR(500),
				hostname VARCHAR(255),
				first_seen DATETIME NOT NULL,
				last_seen DATETIME NOT NULL,
				total_requests INT UNSIGNED DEFAULT 0,
				status VARCHAR(20) DEFAULT 'active',
				PRIMARY KEY  (id),
				UNIQUE KEY ip_address (ip_address),
				KEY status (status)
			) $charset",

			"{$prefix}tainacan_oai_imports"     => "CREATE TABLE {$prefix}tainacan_oai_imports (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				source_url VARCHAR(500) NOT NULL,
				collection_id BIGINT UNSIGNED NOT NULL,
				metadata_mapping LONGTEXT,
				set_spec VARCHAR(255),
				from_date DATE,
				until_date DATE,
				status VARCHAR(20) DEFAULT 'pending',
				total_records INT UNSIGNED DEFAULT 0,
				imported_records INT UNSIGNED DEFAULT 0,
				failed_records INT UNSIGNED DEFAULT 0,
				resumption_token TEXT,
				error_log TEXT,
				download_bitstreams TINYINT(1) DEFAULT NULL,
				metadata_prefix VARCHAR(20) DEFAULT 'oai_dc',
				created_at DATETIME NOT NULL,
				started_at DATETIME,
				completed_at DATETIME,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY collection_id (collection_id)
			) $charset",

			"{$prefix}tainacan_oai_tokens"      => "CREATE TABLE {$prefix}tainacan_oai_tokens (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				token VARCHAR(64) NOT NULL,
				data LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				expires_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY expires_at (expires_at)
			) $charset",

			"{$prefix}tainacan_oai_sources"     => "CREATE TABLE {$prefix}tainacan_oai_sources (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				label VARCHAR(255) NOT NULL,
				source_url VARCHAR(500) NOT NULL,
				collection_id BIGINT UNSIGNED NOT NULL,
				set_spec VARCHAR(255),
				metadata_mapping LONGTEXT,
				schedule VARCHAR(20) NOT NULL DEFAULT 'daily',
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				download_bitstreams TINYINT(1) NOT NULL DEFAULT 1,
				last_run_at DATETIME,
				last_success_at DATETIME,
				last_datestamp VARCHAR(30),
				last_run_status VARCHAR(20) DEFAULT 'never',
				last_run_message TEXT,
				items_created INT UNSIGNED DEFAULT 0,
				items_updated INT UNSIGNED DEFAULT 0,
				items_skipped INT UNSIGNED DEFAULT 0,
				items_failed INT UNSIGNED DEFAULT 0,
				items_deleted INT UNSIGNED DEFAULT 0,
				error_log TEXT,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY collection_id (collection_id),
				KEY schedule (schedule),
				KEY is_active (is_active)
			) $charset",

			"{$prefix}tainacan_oai_rate_limits" => "CREATE TABLE {$prefix}tainacan_oai_rate_limits (
				id BIGINT UNSIGNED AUTO_INCREMENT,
				ip_address VARCHAR(45) NOT NULL,
				request_count INT UNSIGNED DEFAULT 1,
				window_start DATETIME NOT NULL,
				blocked_until DATETIME,
				PRIMARY KEY  (id),
				UNIQUE KEY ip_address (ip_address),
				KEY blocked_until (blocked_until)
			) $charset",
		);

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'tainacan_oai_daily_maintenance' );

		if ( class_exists( '\\Tainacan_OAI_PMH\\Harvester' ) ) {
			\Tainacan_OAI_PMH\Harvester::unschedule_all();
		}

		flush_rewrite_rules();
	}
}
