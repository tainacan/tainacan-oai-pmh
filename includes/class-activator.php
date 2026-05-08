<?php
/**
 * Plugin Check / phpcs suppressions: this file creates the plugin's custom
 * tables (CREATE TABLE / ALTER TABLE) — that's its entire job. The
 * $imports_table variable holds the literal table name and is used inside
 * an ALTER TABLE statement which can't take placeholders.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Activator {
    
    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Cache table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tainacan_oai_cache (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_id BIGINT UNSIGNED NOT NULL,
            collection_id BIGINT UNSIGNED NOT NULL,
            identifier VARCHAR(255) NOT NULL,
            datestamp DATETIME NOT NULL,
            metadata_json LONGTEXT,
            status VARCHAR(20) DEFAULT 'publish',
            checksum VARCHAR(32),
            last_indexed DATETIME NOT NULL,
            UNIQUE KEY item_id (item_id),
            KEY collection_id (collection_id),
            KEY datestamp (datestamp),
            KEY status (status)
        ) $charset");
        
        // Logs table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tainacan_oai_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            verb VARCHAR(50),
            response_time FLOAT,
            created_at DATETIME NOT NULL,
            KEY level (level),
            KEY verb (verb),
            KEY created_at (created_at)
        ) $charset");
        
        // Harvesters table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tainacan_oai_harvesters (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(500),
            hostname VARCHAR(255),
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            total_requests INT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            UNIQUE KEY ip_address (ip_address),
            KEY status (status)
        ) $charset");
        
        // Import jobs table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tainacan_oai_imports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
            KEY status (status),
            KEY collection_id (collection_id)
        ) $charset");

        // Backfill columns for installs created before they existed
        $imports_table = $wpdb->prefix . 'tainacan_oai_imports';
        foreach (
            [
                'download_bitstreams' => "ADD COLUMN download_bitstreams TINYINT(1) DEFAULT NULL AFTER error_log",
                'metadata_prefix'     => "ADD COLUMN metadata_prefix VARCHAR(20) DEFAULT 'oai_dc' AFTER download_bitstreams",
            ] as $col => $alter
        ) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                DB_NAME, $imports_table, $col
            ));
            if (!$exists) {
                $wpdb->query("ALTER TABLE $imports_table $alter");
            }
        }
        
        // Tokens table (database-based)
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tainacan_oai_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL,
            data LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY token (token),
            KEY expires_at (expires_at)
        ) $charset");
        
        // Harvest sources (persistent scheduled harvesters)
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tainacan_oai_sources (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
            KEY collection_id (collection_id),
            KEY schedule (schedule),
            KEY is_active (is_active)
        ) $charset");

        // Rate limits table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tainacan_oai_rate_limits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            request_count INT UNSIGNED DEFAULT 1,
            window_start DATETIME NOT NULL,
            blocked_until DATETIME,
            UNIQUE KEY ip_address (ip_address),
            KEY blocked_until (blocked_until)
        ) $charset");
        
        // Schedule cron
        if (!wp_next_scheduled('tainacan_oai_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'tainacan_oai_daily_maintenance');
        }
        
        flush_rewrite_rules();
    }
    
    public static function deactivate() {
        wp_clear_scheduled_hook('tainacan_oai_daily_maintenance');
        // Clear every per-source harvest cron event (one per saved source)
        if (class_exists('\\Tainacan_OAI_PMH\\Harvester')) {
            \Tainacan_OAI_PMH\Harvester::unschedule_all();
        }
        flush_rewrite_rules();
    }
}
