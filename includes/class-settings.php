<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

/**
 * Settings class using Tainacan Settings API
 * Adds OAI-PMH settings to Tainacan Settings Page
 */
class Settings {
    
    /**
     * Initialize settings
     */
    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }
    
    /**
     * Register settings in Tainacan Settings Page
     */
    public static function register_settings() {
        // Add section to Tainacan settings page
        add_settings_section(
            'tainacan_oai_pmh_settings',
            __('OAI-PMH', 'tainacan-oai-pmh'),
            [__CLASS__, 'section_description'],
            'tainacan_settings'
        );
        
        // Get Tainacan Settings instance
        $tainacan_settings = \Tainacan\Settings::get_instance();
        
        // Repository Name
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_repository_name',
            'title' => __('Repository Name', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'string',
            'input_type' => 'text',
            'description' => __('Name displayed in OAI-PMH Identify response.', 'tainacan-oai-pmh'),
            'default' => get_bloginfo('name')
        ]);
        
        // Admin Email
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_admin_email',
            'title' => __('Admin Email', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'string',
            'input_type' => 'email',
            'description' => __('Contact email for harvesters.', 'tainacan-oai-pmh'),
            'default' => get_option('admin_email')
        ]);
        
        // Max Records per Response
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_max_records',
            'title' => __('Records per Response', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'integer',
            'input_type' => 'number',
            'input_attrs' => 'min="10" max="500"',
            'description' => __('Maximum records per OAI-PMH request (10-500).', 'tainacan-oai-pmh'),
            'default' => 100
        ]);
        
        // Token Expiry
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_token_expiry',
            'title' => __('Token Expiry (hours)', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'integer',
            'input_type' => 'number',
            'input_attrs' => 'min="1" max="168"',
            'description' => __('How long resumption tokens remain valid.', 'tainacan-oai-pmh'),
            'default' => 24
        ]);
        
        // Cache Enabled
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_cache_enabled',
            'title' => __('Enable Cache', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'boolean',
            'input_type' => 'checkbox',
            'label' => __('Use MySQL cache for faster responses', 'tainacan-oai-pmh'),
            'default' => true
        ]);
        
        // Auto-Indexing
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_auto_index',
            'title' => __('Auto-Indexing', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'boolean',
            'input_type' => 'checkbox',
            'label' => __('Automatically index items when saved', 'tainacan-oai-pmh'),
            'default' => true
        ]);
        
        // Logging
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_log_enabled',
            'title' => __('Request Logging', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'boolean',
            'input_type' => 'checkbox',
            'label' => __('Log requests for monitoring', 'tainacan-oai-pmh'),
            'default' => true
        ]);
        
        // Rate Limiting
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_rate_limit_enabled',
            'title' => __('Rate Limiting', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'boolean',
            'input_type' => 'checkbox',
            'label' => __('Protect against excessive requests', 'tainacan-oai-pmh'),
            'default' => true
        ]);
        
        // Rate Limit Threshold
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_rate_limit_threshold',
            'title' => __('Rate Limit (req/min)', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'integer',
            'input_type' => 'number',
            'input_attrs' => 'min="10" max="1000"',
            'description' => __('Max requests per minute before blocking.', 'tainacan-oai-pmh'),
            'default' => 60
        ]);
        
        // Whitelist
        $tainacan_settings->create_tainacan_setting([
            'id' => 'oai_rate_limit_whitelist',
            'title' => __('Rate Limit Whitelist', 'tainacan-oai-pmh'),
            'section' => 'tainacan_oai_pmh_settings',
            'type' => 'string',
            'input_type' => 'textarea',
            'description' => __('IPs to skip rate limiting (one per line).', 'tainacan-oai-pmh'),
            'default' => ''
        ]);
    }
    
    /**
     * Section description callback
     */
    public static function section_description() {
        $endpoint = rest_url('tainacan-oai/v1/oai');
        echo '<p class="settings-section-description">';
        echo '<strong>' . esc_html__('Your OAI-PMH Endpoint:', 'tainacan-oai-pmh') . '</strong> ';
        echo '<code>' . esc_html($endpoint) . '</code>';
        echo '<br><br>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=tainacan_oai_pmh')) . '" class="button">';
        echo esc_html__('Open OAI-PMH Dashboard', 'tainacan-oai-pmh');
        echo '</a>';
        echo '</p>';
    }
    
    /**
     * Get setting value (with tainacan_option_ prefix)
     */
    public static function get($key, $default = null) {
        return get_option('tainacan_option_oai_' . $key, $default);
    }
    
    /**
     * Update setting value
     */
    public static function set($key, $value) {
        return update_option('tainacan_option_oai_' . $key, $value);
    }
}
