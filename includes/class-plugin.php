<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

/**
 * Main Plugin class extending Tainacan Pages
 * Creates admin page integrated with Tainacan menu
 */
class Plugin extends \Tainacan\Pages {
    use \Tainacan\Traits\Singleton_Instance;
    
    private $cache;
    private $logger;
    private $importer;
    private $rate_limiter;
    private $token_manager;
    
    /**
     * Required: Define unique page slug
     */
    protected function get_page_slug(): string {
        return 'tainacan_oai_pmh';
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        parent::init();
        
        $this->cache = new Cache();
        $this->logger = new Logger();
        $this->importer = new Importer();
        $this->rate_limiter = new Rate_Limiter();
        $this->token_manager = new Token_Manager();
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // REST API
        add_action('rest_api_init', function() {
            $controller = new REST_Controller();
            $controller->register_routes();
        });
        
        // Auto-indexing
        add_action('tainacan-insert', [$this, 'on_item_save'], 10, 2);
        add_action('tainacan-update', [$this, 'on_item_save'], 10, 2);
        add_action('trashed_post', [$this, 'on_item_trash']);
        
        // AJAX handlers
        $this->register_ajax_handlers();
        
        // Cron
        add_action('tainacan_oai_daily_maintenance', [$this, 'daily_maintenance']);
        if (!wp_next_scheduled('tainacan_oai_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'tainacan_oai_daily_maintenance');
        }
    }
    
    /**
     * Register admin menu item in Tainacan
     */
    public function add_admin_menu() {
        $page_suffix = add_submenu_page(
            $this->tainacan_root_menu_slug,
            __('OAI-PMH', 'tainacan-oai-pmh'),
            '<span class="icon">' . $this->get_svg_icon('share') . '</span>' .
            '<span class="menu-text">' . __('OAI-PMH', 'tainacan-oai-pmh') . '</span>',
            'manage_options',
            $this->get_page_slug(),
            [$this, 'render_page'],
            4
        );
        
        add_action('load-' . $page_suffix, [$this, 'load_page']);
    }
    
    /**
     * Enqueue CSS
     */
    public function admin_enqueue_css() {
        wp_enqueue_style(
            'tainacan-oai-admin',
            TAINACAN_OAI_PMH_URL . 'assets/css/admin.css',
            [],
            TAINACAN_OAI_PMH_VERSION
        );
    }
    
    /**
     * Enqueue JavaScript
     */
    public function admin_enqueue_js() {
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
        
        wp_enqueue_script(
            'tainacan-oai-admin',
            TAINACAN_OAI_PMH_URL . 'assets/js/admin.js',
            ['jquery', 'chartjs'],
            TAINACAN_OAI_PMH_VERSION,
            true
        );
        
        wp_localize_script('tainacan-oai-admin', 'tainacanOAI', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tainacan_oai_nonce'),
            'strings' => [
                'confirm_reindex' => __('This will rebuild the entire index. Continue?', 'tainacan-oai-pmh'),
                'confirm_clear' => __('This will clear all cached data. Continue?', 'tainacan-oai-pmh'),
                'confirm_unblock' => __('Unblock this IP address?', 'tainacan-oai-pmh'),
                'success' => __('Operation completed!', 'tainacan-oai-pmh'),
                'error' => __('An error occurred.', 'tainacan-oai-pmh'),
                'copied' => __('Copied!', 'tainacan-oai-pmh'),
            ]
        ]);
    }
    
    /**
     * Required: Render main page content
     */
    public function render_page_content() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        
        $data = [
            'tab' => $tab,
            'base_url' => rest_url('tainacan-oai/v1/oai'),
            'cache_stats' => $this->cache->get_stats(),
            'index_health' => $this->cache->get_health(),
            'collection_stats' => $this->cache->get_collection_stats(),
            'log_stats' => $this->logger->get_stats('24 hours'),
            'daily_stats' => $this->logger->get_daily_stats(14),
            'harvesters' => $this->logger->get_harvesters(),
            'harvester_stats' => $this->logger->get_harvester_stats(),
            'blocked_ips' => $this->rate_limiter->get_blocked(),
            'collections' => $this->get_collections(),
            'imports' => $this->importer->get_imports(),
        ];
        
        if ($tab === 'validation') {
            $validator = new Validator();
            $data['last_validation'] = $validator->get_last_result();
        }
        
        include TAINACAN_OAI_PMH_DIR . 'templates/page.php';
    }
    
    private function get_collections() {
        $repo = \Tainacan\Repositories\Collections::get_instance();
        return $repo->fetch([], 'OBJECT');
    }
    
    private function register_ajax_handlers() {
        $handlers = [
            'tainacan_oai_reindex',
            'tainacan_oai_reindex_collection',
            'tainacan_oai_clear_cache',
            'tainacan_oai_validate',
            'tainacan_oai_test_endpoint',
            'tainacan_oai_export_logs',
            'tainacan_oai_fetch_repository',
            'tainacan_oai_fetch_sets',
            'tainacan_oai_preview_records',
            'tainacan_oai_start_import',
            'tainacan_oai_process_import',
            'tainacan_oai_get_collection_metadata',
            'tainacan_oai_unblock_ip',
        ];
        
        foreach ($handlers as $action) {
            $method = str_replace('tainacan_oai_', 'ajax_', $action);
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }
    
    // Item hooks
    public function on_item_save($entity, $args = []) {
        if (!Settings::get('auto_index', true)) return;
        if ($entity instanceof \Tainacan\Entities\Item) {
            $this->cache->index_item($entity);
        }
    }
    
    public function on_item_trash($post_id) {
        $post = get_post($post_id);
        if ($post && strpos($post->post_type, 'tnc_col_') === 0 && strpos($post->post_type, '_item') !== false) {
            $this->cache->update_item_status($post_id, 'trash');
        }
    }
    
    public function daily_maintenance() {
        $this->logger->cleanup(30);
        $this->token_manager->cleanup();
        $this->rate_limiter->cleanup(7);
    }
    
    // AJAX: Reindex all
    public function ajax_reindex() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tainacan-oai-pmh')]);
        }
        
        $count = $this->cache->rebuild_index();
        wp_send_json_success([
            'message' => sprintf(__('Indexed %d items.', 'tainacan-oai-pmh'), $count),
            'count' => $count
        ]);
    }
    
    // AJAX: Reindex collection
    public function ajax_reindex_collection() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tainacan-oai-pmh')]);
        }
        
        $collection_id = absint($_POST['collection_id'] ?? 0);
        if (!$collection_id) {
            wp_send_json_error(['message' => __('Invalid collection.', 'tainacan-oai-pmh')]);
        }
        
        $count = $this->cache->reindex_collection($collection_id);
        wp_send_json_success([
            'message' => sprintf(__('Reindexed %d items.', 'tainacan-oai-pmh'), $count)
        ]);
    }
    
    // AJAX: Clear cache
    public function ajax_clear_cache() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tainacan-oai-pmh')]);
        }
        
        $this->cache->clear();
        wp_send_json_success(['message' => __('Cache cleared.', 'tainacan-oai-pmh')]);
    }
    
    // AJAX: Validate
    public function ajax_validate() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        $validator = new Validator();
        wp_send_json_success($validator->run());
    }
    
    // AJAX: Test endpoint
    public function ajax_test_endpoint() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        
        $start = microtime(true);
        $response = wp_remote_get(
            rest_url('tainacan-oai/v1/oai') . '?verb=Identify',
            ['timeout' => 30, 'sslverify' => false]
        );
        $time = round(microtime(true) - $start, 3);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        
        $body = wp_remote_retrieve_body($response);
        if (strpos($body, 'repositoryName') === false) {
            wp_send_json_error(['message' => __('Invalid response.', 'tainacan-oai-pmh')]);
        }
        
        wp_send_json_success([
            'message' => __('Endpoint working!', 'tainacan-oai-pmh'),
            'time' => $time
        ]);
    }
    
    // AJAX: Export logs
    public function ajax_export_logs() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'tainacan-oai-pmh'));
        }
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="oai-pmh-logs-' . gmdate('Y-m-d') . '.csv"');
        echo $this->logger->export_csv();
        exit;
    }
    
    // AJAX: Fetch repository info
    public function ajax_fetch_repository() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        
        $url = esc_url_raw($_POST['url'] ?? '');
        if (empty($url)) {
            wp_send_json_error(['message' => __('URL is required.', 'tainacan-oai-pmh')]);
        }
        
        $info = $this->importer->fetch_repository_info($url);
        if (is_wp_error($info)) {
            wp_send_json_error(['message' => $info->get_error_message()]);
        }
        
        wp_send_json_success($info);
    }
    
    // AJAX: Fetch sets
    public function ajax_fetch_sets() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        
        $url = esc_url_raw($_POST['url'] ?? '');
        $sets = $this->importer->fetch_sets($url);
        
        if (is_wp_error($sets)) {
            wp_send_json_error(['message' => $sets->get_error_message()]);
        }
        
        wp_send_json_success($sets);
    }
    
    // AJAX: Preview records
    public function ajax_preview_records() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        
        $url = esc_url_raw($_POST['url'] ?? '');
        $set = sanitize_text_field($_POST['set'] ?? '');
        
        $records = $this->importer->preview_records($url, $set, 5);
        if (is_wp_error($records)) {
            wp_send_json_error(['message' => $records->get_error_message()]);
        }
        
        wp_send_json_success($records);
    }
    
    // AJAX: Start import
    public function ajax_start_import() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tainacan-oai-pmh')]);
        }
        
        $args = [
            'source_url' => esc_url_raw($_POST['source_url'] ?? ''),
            'collection_id' => absint($_POST['collection_id'] ?? 0),
            'set_spec' => sanitize_text_field($_POST['set_spec'] ?? ''),
            'from_date' => sanitize_text_field($_POST['from_date'] ?? ''),
            'until_date' => sanitize_text_field($_POST['until_date'] ?? ''),
            'metadata_mapping' => isset($_POST['metadata_mapping']) 
                ? json_decode(stripslashes($_POST['metadata_mapping']), true) 
                : [],
        ];
        
        $import_id = $this->importer->create_import($args);
        if (is_wp_error($import_id)) {
            wp_send_json_error(['message' => $import_id->get_error_message()]);
        }
        
        wp_send_json_success(['import_id' => $import_id]);
    }
    
    // AJAX: Process import batch
    public function ajax_process_import() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        
        $import_id = absint($_POST['import_id'] ?? 0);
        $result = $this->importer->process_batch($import_id, 10);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success($result);
    }
    
    // AJAX: Get collection metadata
    public function ajax_get_collection_metadata() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        
        $collection_id = absint($_POST['collection_id'] ?? 0);
        if (!$collection_id) {
            wp_send_json_error(['message' => __('Collection ID required.', 'tainacan-oai-pmh')]);
        }
        
        $metadata = Metadata_Mapper::get_collection_metadata($collection_id);
        wp_send_json_success($metadata);
    }
    
    // AJAX: Unblock IP
    public function ajax_unblock_ip() {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tainacan-oai-pmh')]);
        }
        
        $ip = sanitize_text_field($_POST['ip'] ?? '');
        if (empty($ip)) {
            wp_send_json_error(['message' => __('IP required.', 'tainacan-oai-pmh')]);
        }
        
        $this->rate_limiter->unblock($ip);
        wp_send_json_success(['message' => __('IP unblocked.', 'tainacan-oai-pmh')]);
    }
    
    // Getters
    public function get_cache() { return $this->cache; }
    public function get_logger() { return $this->logger; }
    public function get_importer() { return $this->importer; }
}
