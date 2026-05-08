<?php
/**
 * Plugin Name: Tainacan OAI-PMH Enhanced
 * Plugin URI: https://tainacan.org
 * Description: OAI-PMH provider and importer for Tainacan with caching, monitoring, and validation.
 * Version: 2.1.2
 * Author: Tainacan Team
 * Author URI: https://tainacan.org
 * License: GPL v3 or later
 * Text Domain: tainacan-oai-pmh
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: tainacan
 */

if (!defined('ABSPATH')) exit;

define('TAINACAN_OAI_PMH_VERSION', '2.1.2');
define('TAINACAN_OAI_PMH_FILE', __FILE__);
define('TAINACAN_OAI_PMH_DIR', plugin_dir_path(__FILE__));
define('TAINACAN_OAI_PMH_URL', plugin_dir_url(__FILE__));
define('TAINACAN_OAI_PMH_BASENAME', plugin_basename(__FILE__));

// Load textdomain
add_action('plugins_loaded', function() {
    load_plugin_textdomain('tainacan-oai-pmh', false, dirname(TAINACAN_OAI_PMH_BASENAME) . '/languages');
});

// Initialize plugin after Tainacan loads
add_action('plugins_loaded', function() {
    // Check if Tainacan is active and has Pages class (version >= 1.0.0)
    if (!class_exists('\Tainacan\Pages')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>';
            esc_html_e('Tainacan OAI-PMH Enhanced requires Tainacan plugin version 1.0.0 or higher.', 'tainacan-oai-pmh');
            echo '</p></div>';
        });
        return;
    }
    
    // Load dependencies
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-activator.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-settings.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-cache.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-logger.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-data-provider.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-xml-generator.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-rest-controller.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-validator.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-importer.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-metadata-mapper.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-rate-limiter.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-token-manager.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-harvester.php';
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-plugin.php';
    
    if (defined('WP_CLI') && WP_CLI) {
        require_once TAINACAN_OAI_PMH_DIR . 'includes/class-cli.php';
    }

    // Lightweight schema migration: re-runs activator (CREATE TABLE IF NOT EXISTS
    // is idempotent) whenever plugin version changes. Catches users who upgrade
    // by replacing files instead of going through deactivate/activate.
    $stored_version = get_option('tainacan_oai_pmh_db_version');
    if ($stored_version !== TAINACAN_OAI_PMH_VERSION) {
        \Tainacan_OAI_PMH\Activator::activate();
        update_option('tainacan_oai_pmh_db_version', TAINACAN_OAI_PMH_VERSION);
    }

    // Initialize plugin
    \Tainacan_OAI_PMH\Plugin::get_instance();
    
    // Register settings in Tainacan settings page
    \Tainacan_OAI_PMH\Settings::init();
    
}, 20);

// Activation
register_activation_hook(__FILE__, function() {
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-activator.php';
    \Tainacan_OAI_PMH\Activator::activate();
});

// Deactivation
register_deactivation_hook(__FILE__, function() {
    require_once TAINACAN_OAI_PMH_DIR . 'includes/class-activator.php';
    \Tainacan_OAI_PMH\Activator::deactivate();
});
