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
    private $harvester;
    
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
        $this->harvester = new Harvester();

        // Custom cron schedule + per-source cron action
        Harvester::register_hooks();

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
            'harvest_sources' => $this->build_harvest_sources_view(),
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

    /**
     * Decorates harvest sources with the next scheduled run timestamp
     * (kept here so the template stays free of cron lookups).
     */
    private function build_harvest_sources_view(): array {
        $sources = $this->harvester->get_all();
        foreach ($sources as $s) {
            $s->next_run_ts = $this->harvester->next_scheduled((int) $s->id);
        }
        return $sources;
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
            'tainacan_oai_build_mapping',
            'tainacan_oai_get_import_status',
            'tainacan_oai_unblock_ip',
            'tainacan_oai_save_harvest_source',
            'tainacan_oai_delete_harvest_source',
            'tainacan_oai_run_harvest_source',
            'tainacan_oai_toggle_harvest_source',
            'tainacan_oai_get_harvest_source',
            'tainacan_oai_clear_import_log',
            'tainacan_oai_clear_harvest_log',
            'tainacan_oai_get_import_log',
            'tainacan_oai_get_harvest_log',
            'tainacan_oai_count_import_items',
            'tainacan_oai_delete_import',
        ];

        foreach ($handlers as $action) {
            $method = str_replace('tainacan_oai_', 'ajax_', $action);
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }

    /**
     * Centralized authorization for every privileged AJAX endpoint.
     * Refuses request if nonce invalid or user lacks manage_options.
     */
    private function authorize_ajax(): void {
        check_ajax_referer('tainacan_oai_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tainacan-oai-pmh')], 403);
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
        $this->logger->resolve_pending_hostnames(200);
        $this->token_manager->cleanup();
        $this->rate_limiter->cleanup(7);
    }
    
    public function ajax_reindex() {
        $this->authorize_ajax();
        $count = $this->cache->rebuild_index();
        wp_send_json_success([
            'message' => sprintf(__('Indexed %d items.', 'tainacan-oai-pmh'), $count),
            'count' => $count
        ]);
    }

    public function ajax_reindex_collection() {
        $this->authorize_ajax();
        $collection_id = absint($_POST['collection_id'] ?? 0);
        if (!$collection_id) {
            wp_send_json_error(['message' => __('Invalid collection.', 'tainacan-oai-pmh')]);
        }
        $count = $this->cache->reindex_collection($collection_id);
        wp_send_json_success([
            'message' => sprintf(__('Reindexed %d items.', 'tainacan-oai-pmh'), $count)
        ]);
    }

    public function ajax_clear_cache() {
        $this->authorize_ajax();
        $this->cache->clear();
        wp_send_json_success(['message' => __('Cache cleared.', 'tainacan-oai-pmh')]);
    }

    public function ajax_validate() {
        $this->authorize_ajax();
        $validator = new Validator();
        wp_send_json_success($validator->run());
    }

    public function ajax_test_endpoint() {
        $this->authorize_ajax();

        $endpoint = rest_url('tainacan-oai/v1/oai') . '?verb=Identify';
        $sslverify = !$this->is_self_local_url($endpoint);

        $start = microtime(true);
        $response = wp_remote_get($endpoint, ['timeout' => 30, 'sslverify' => $sslverify]);
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

    private function is_self_local_url(string $url): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
        return $host === wp_parse_url(home_url(), PHP_URL_HOST);
    }

    public function ajax_export_logs() {
        $this->authorize_ajax();
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="oai-pmh-logs-' . gmdate('Y-m-d') . '.csv"');
        echo $this->logger->export_csv();
        exit;
    }

    public function ajax_fetch_repository() {
        $this->authorize_ajax();
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        if (empty($url)) {
            wp_send_json_error(['message' => __('URL is required.', 'tainacan-oai-pmh')]);
        }
        $info = $this->importer->fetch_repository_info($url);
        if (is_wp_error($info)) wp_send_json_error(['message' => $info->get_error_message()]);
        wp_send_json_success($info);
    }

    public function ajax_fetch_sets() {
        $this->authorize_ajax();
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $sets = $this->importer->fetch_sets($url);
        if (is_wp_error($sets)) wp_send_json_error(['message' => $sets->get_error_message()]);
        wp_send_json_success($sets);
    }

    public function ajax_preview_records() {
        $this->authorize_ajax();
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $set = sanitize_text_field(wp_unslash($_POST['set'] ?? ''));
        $records = $this->importer->preview_records($url, $set, 5);
        if (is_wp_error($records)) wp_send_json_error(['message' => $records->get_error_message()]);
        wp_send_json_success($records);
    }

    public function ajax_start_import() {
        $this->authorize_ajax();
        $mapping_raw = isset($_POST['metadata_mapping'])
            ? wp_unslash($_POST['metadata_mapping'])
            : '';
        $mapping = $mapping_raw !== '' ? json_decode($mapping_raw, true) : [];
        if (!is_array($mapping)) $mapping = [];

        // Wizard checkbox; absent → null (follow global setting), '0'/'1' → explicit override
        $bs_override = null;
        if (isset($_POST['download_bitstreams']) && $_POST['download_bitstreams'] !== '') {
            $bs_override = !empty($_POST['download_bitstreams']) ? 1 : 0;
        }

        $args = [
            'source_url' => esc_url_raw(wp_unslash($_POST['source_url'] ?? '')),
            'collection_id' => absint($_POST['collection_id'] ?? 0),
            'set_spec' => sanitize_text_field(wp_unslash($_POST['set_spec'] ?? '')),
            'from_date' => sanitize_text_field(wp_unslash($_POST['from_date'] ?? '')),
            'until_date' => sanitize_text_field(wp_unslash($_POST['until_date'] ?? '')),
            'metadata_mapping' => $mapping,
            'download_bitstreams' => $bs_override,
        ];

        $import_id = $this->importer->create_import($args);
        if (is_wp_error($import_id)) wp_send_json_error(['message' => $import_id->get_error_message()]);
        wp_send_json_success(['import_id' => $import_id]);
    }

    public function ajax_process_import() {
        $this->authorize_ajax();
        $import_id = absint($_POST['import_id'] ?? 0);
        $result = $this->importer->process_batch($import_id, 10);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        wp_send_json_success($result);
    }

    public function ajax_get_collection_metadata() {
        $this->authorize_ajax();
        $collection_id = absint($_POST['collection_id'] ?? 0);
        if (!$collection_id) {
            wp_send_json_error(['message' => __('Collection ID required.', 'tainacan-oai-pmh')]);
        }
        $metadata = Metadata_Mapper::get_collection_metadata($collection_id);
        wp_send_json_success($metadata);
    }

    /**
     * Returns the full mapping table the importer wizard should render:
     * all 15 standard DC rows + extra fields seen in the source preview,
     * each with sample value, multi-value flag, and an auto-suggested target.
     */
    public function ajax_build_mapping() {
        $this->authorize_ajax();
        $collection_id = absint($_POST['collection_id'] ?? 0);
        if (!$collection_id) {
            wp_send_json_error(['message' => __('Collection ID required.', 'tainacan-oai-pmh')]);
        }
        $source_raw = isset($_POST['source_fields']) ? wp_unslash($_POST['source_fields']) : '';
        $source_fields = $source_raw !== '' ? json_decode($source_raw, true) : [];
        if (!is_array($source_fields)) $source_fields = [];

        wp_send_json_success(Metadata_Mapper::build_mapping_rows($collection_id, $source_fields));
    }

    /**
     * Lets the UI poll an in-flight import for progress + recent errors,
     * so users can see what failed without scraping the database.
     */
    public function ajax_get_import_status() {
        $this->authorize_ajax();
        $import_id = absint($_POST['import_id'] ?? 0);
        $import = $this->importer->get_import($import_id);
        if (!$import) wp_send_json_error(['message' => __('Import not found.', 'tainacan-oai-pmh')]);

        wp_send_json_success([
            'status' => $import->status,
            'imported' => (int) $import->imported_records,
            'failed' => (int) $import->failed_records,
            'total' => (int) $import->total_records,
            'error_log' => $import->error_log ? array_slice(array_filter(explode("\n", $import->error_log)), -20) : [],
        ]);
    }

    /**
     * Create or update a harvest source. Posts JSON-encoded metadata_mapping.
     * If `id` is present, updates; otherwise creates.
     */
    public function ajax_save_harvest_source() {
        $this->authorize_ajax();
        $id = absint($_POST['id'] ?? 0);
        $mapping_raw = isset($_POST['metadata_mapping']) ? wp_unslash($_POST['metadata_mapping']) : '';
        $mapping = $mapping_raw !== '' ? json_decode($mapping_raw, true) : [];
        if (!is_array($mapping)) $mapping = [];

        $args = [
            'label' => sanitize_text_field(wp_unslash($_POST['label'] ?? '')),
            'source_url' => esc_url_raw(wp_unslash($_POST['source_url'] ?? '')),
            'collection_id' => absint($_POST['collection_id'] ?? 0),
            'set_spec' => sanitize_text_field(wp_unslash($_POST['set_spec'] ?? '')),
            'schedule' => sanitize_key($_POST['schedule'] ?? 'daily'),
            'is_active' => !empty($_POST['is_active']),
            'download_bitstreams' => !empty($_POST['download_bitstreams']),
            'metadata_mapping' => $mapping,
        ];

        $result = $id > 0 ? $this->harvester->update($id, $args) : $this->harvester->create($args);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);

        $saved_id = $id > 0 ? $id : (int) $result;
        wp_send_json_success([
            'id' => $saved_id,
            'next_run' => $this->harvester->next_scheduled($saved_id),
            'message' => __('Harvest source saved.', 'tainacan-oai-pmh'),
        ]);
    }

    public function ajax_delete_harvest_source() {
        $this->authorize_ajax();
        $id = absint($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => __('Invalid source ID.', 'tainacan-oai-pmh')]);
        $this->harvester->delete($id);
        wp_send_json_success(['message' => __('Harvest source deleted.', 'tainacan-oai-pmh')]);
    }

    /**
     * Trigger an immediate run of a saved source. The run is synchronous —
     * could take minutes for large initial harvests but the response carries
     * the actual stats so the UI doesn't have to poll.
     */
    public function ajax_run_harvest_source() {
        $this->authorize_ajax();
        $id = absint($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => __('Invalid source ID.', 'tainacan-oai-pmh')]);

        $stats = $this->harvester->run($id);
        if (is_wp_error($stats)) wp_send_json_error(['message' => $stats->get_error_message()]);
        wp_send_json_success(['stats' => $stats]);
    }

    public function ajax_toggle_harvest_source() {
        $this->authorize_ajax();
        $id = absint($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => __('Invalid source ID.', 'tainacan-oai-pmh')]);
        $source = $this->harvester->get($id);
        if (!$source) wp_send_json_error(['message' => __('Source not found.', 'tainacan-oai-pmh')]);

        $result = $this->harvester->update($id, ['is_active' => !$source->is_active]);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        wp_send_json_success([
            'is_active' => !$source->is_active,
            'next_run' => $this->harvester->next_scheduled($id),
        ]);
    }

    public function ajax_get_harvest_source() {
        $this->authorize_ajax();
        $id = absint($_POST['id'] ?? 0);
        $source = $this->harvester->get($id);
        if (!$source) wp_send_json_error(['message' => __('Not found.', 'tainacan-oai-pmh')]);

        $source->metadata_mapping = maybe_unserialize($source->metadata_mapping) ?: [];
        wp_send_json_success($source);
    }

    /**
     * Returns how many items the given import created (best-effort: direct
     * tag match + legacy heuristic via source URL host). Used by the UI to
     * show the count in the delete confirmation prompt.
     */
    public function ajax_count_import_items() {
        $this->authorize_ajax();
        $id = absint($_POST['import_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => __('Invalid import ID.', 'tainacan-oai-pmh')]);
        wp_send_json_success(['count' => $this->importer->count_import_items($id)]);
    }

    /**
     * Removes the import history row and (optionally) trashes the items it
     * created plus their bitstream attachments. Items go to Trash so admins
     * can recover them — the row in tainacan_oai_imports is deleted permanently.
     */
    public function ajax_delete_import() {
        $this->authorize_ajax();
        $id = absint($_POST['import_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => __('Invalid import ID.', 'tainacan-oai-pmh')]);

        $delete_items = !empty($_POST['delete_items']);
        $stats = $this->importer->delete_import($id, $delete_items);

        $msg = $delete_items
            ? sprintf(__('Import deleted. %d item(s) and %d attachment(s) moved to Trash.', 'tainacan-oai-pmh'),
                $stats['items_trashed'], $stats['attachments_trashed'])
            : __('Import history removed. Imported items were preserved.', 'tainacan-oai-pmh');

        wp_send_json_success(['stats' => $stats, 'message' => $msg]);
    }

    /** Clears the activity log of one import job. */
    public function ajax_clear_import_log() {
        $this->authorize_ajax();
        $id = absint($_POST['import_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => __('Invalid import ID.', 'tainacan-oai-pmh')]);
        $this->importer->clear_log($id);
        wp_send_json_success(['message' => __('Log cleared.', 'tainacan-oai-pmh')]);
    }

    /** Clears the activity log of one harvest source (or all if id=0). */
    public function ajax_clear_harvest_log() {
        $this->authorize_ajax();
        $id = absint($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->harvester->clear_log($id);
        } else {
            $this->harvester->clear_all_logs();
        }
        wp_send_json_success(['message' => __('Log cleared.', 'tainacan-oai-pmh')]);
    }

    /** Returns the full activity log of one import for the modal viewer. */
    public function ajax_get_import_log() {
        $this->authorize_ajax();
        $id = absint($_POST['import_id'] ?? 0);
        global $wpdb;
        $table = $wpdb->prefix . 'tainacan_oai_imports';
        $log = $wpdb->get_var($wpdb->prepare("SELECT error_log FROM $table WHERE id = %d", $id));
        wp_send_json_success(['log' => (string) $log]);
    }

    public function ajax_get_harvest_log() {
        $this->authorize_ajax();
        $id = absint($_POST['id'] ?? 0);
        $source = $this->harvester->get($id);
        wp_send_json_success(['log' => (string) ($source->error_log ?? '')]);
    }

    public function ajax_unblock_ip() {
        $this->authorize_ajax();
        $ip = sanitize_text_field(wp_unslash($_POST['ip'] ?? ''));
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error(['message' => __('Invalid IP.', 'tainacan-oai-pmh')]);
        }
        $this->rate_limiter->unblock($ip);
        wp_send_json_success(['message' => __('IP unblocked.', 'tainacan-oai-pmh')]);
    }
    
    // Getters
    public function get_cache() { return $this->cache; }
    public function get_logger() { return $this->logger; }
    public function get_importer() { return $this->importer; }
}
