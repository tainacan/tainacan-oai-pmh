<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Cache {
    
    private $table;
    
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_cache';
    }
    
    public function get_item($item_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE item_id = %d",
            $item_id
        ));
    }
    
    public function get_items($args = []) {
        global $wpdb;
        
        $defaults = [
            'per_page' => 100,
            'page' => 1,
            'status' => ['publish'],
            'collection_id' => null,
            'from' => null,
            'until' => null,
        ];
        $args = wp_parse_args($args, $defaults);
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($args['status'])) {
            $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
            $where[] = "status IN ($placeholders)";
            $params = array_merge($params, $args['status']);
        }
        
        if ($args['collection_id']) {
            $where[] = 'collection_id = %d';
            $params[] = $args['collection_id'];
        }
        
        if ($args['from']) {
            $where[] = 'datestamp >= %s';
            $params[] = $args['from'];
        }
        
        if ($args['until']) {
            $where[] = 'datestamp <= %s';
            $params[] = $args['until'];
        }
        
        $offset = ($args['page'] - 1) * $args['per_page'];
        $params[] = $args['per_page'];
        $params[] = $offset;
        
        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . 
               " ORDER BY item_id ASC LIMIT %d OFFSET %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    public function count_items($args = []) {
        global $wpdb;
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($args['status'])) {
            $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
            $where[] = "status IN ($placeholders)";
            $params = array_merge($params, $args['status']);
        }
        
        if (!empty($args['collection_id'])) {
            $where[] = 'collection_id = %d';
            $params[] = $args['collection_id'];
        }
        
        if (!empty($args['from'])) {
            $where[] = 'datestamp >= %s';
            $params[] = $args['from'];
        }
        
        if (!empty($args['until'])) {
            $where[] = 'datestamp <= %s';
            $params[] = $args['until'];
        }
        
        $sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode(' AND ', $where);
        
        if ($params) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return (int) $wpdb->get_var($sql);
    }
    
    public function index_item($item) {
        global $wpdb;
        
        if (is_numeric($item)) {
            $item = new \Tainacan\Entities\Item($item);
        }
        
        if (!$item->get_id()) return false;
        
        $collection = $item->get_collection();
        $dc_data = $this->get_item_dc($item);
        $checksum = md5(json_encode($dc_data));
        
        // Check if needs update
        $existing = $this->get_item($item->get_id());
        if ($existing && $existing->checksum === $checksum) {
            return true; // No changes
        }
        
        $data = [
            'item_id' => $item->get_id(),
            'collection_id' => $collection->get_id(),
            'identifier' => $this->build_identifier($item->get_id()),
            'datestamp' => gmdate('Y-m-d\TH:i:s\Z', strtotime($item->get_modification_date() ?: $item->get_creation_date())),
            'metadata_json' => json_encode($dc_data),
            'status' => $item->get_status(),
            'checksum' => $checksum,
            'last_indexed' => current_time('mysql'),
        ];
        
        if ($existing) {
            $wpdb->update($this->table, $data, ['item_id' => $item->get_id()]);
        } else {
            $wpdb->insert($this->table, $data);
        }
        
        return true;
    }
    
    private function build_identifier($item_id) {
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        return "oai:{$domain}:{$item_id}";
    }
    
    public function extract_item_id($identifier) {
        $parts = explode(':', $identifier);
        return (int) end($parts);
    }
    
    private function get_item_dc($item) {
        $dc = [
            'title' => $item->get_title(),
            'identifier' => get_permalink($item->get_id()),
            'date' => gmdate('Y-m-d', strtotime($item->get_creation_date())),
        ];
        
        if ($item->get_description()) {
            $dc['description'] = $item->get_description();
        }
        
        // Get mapped metadata
        $metadata = $item->get_metadata();
        if (is_array($metadata)) {
            foreach ($metadata as $item_meta) {
                $metadatum = $item_meta->get_metadatum();
                if (!$metadatum) continue;
                
                $mapping = $metadatum->get_exposer_mapping();
                if (!empty($mapping['dublin-core'])) {
                    $field = str_replace('dc:', '', $mapping['dublin-core']);
                    $value = $item_meta->get_value_as_string();
                    
                    if (!empty($value)) {
                        if (isset($dc[$field])) {
                            if (!is_array($dc[$field])) {
                                $dc[$field] = [$dc[$field]];
                            }
                            $dc[$field][] = $value;
                        } else {
                            $dc[$field] = $value;
                        }
                    }
                }
            }
        }
        
        return $dc;
    }
    
    public function update_item_status($item_id, $status) {
        global $wpdb;
        $wpdb->update($this->table, ['status' => $status], ['item_id' => $item_id]);
    }
    
    public function rebuild_index($callback = null) {
        $repo = \Tainacan\Repositories\Items::get_instance();
        $page = 1;
        $total = 0;
        
        do {
            $items = $repo->fetch([
                'posts_per_page' => 50,
                'paged' => $page,
                'post_status' => ['publish', 'private'],
            ], [], 'OBJECT');
            
            if (!is_array($items) || empty($items)) break;
            
            foreach ($items as $item) {
                $this->index_item($item);
                $total++;
                if ($callback) call_user_func($callback, $total, $item->get_id());
            }
            
            $page++;
        } while (count($items) === 50);
        
        return $total;
    }
    
    public function reindex_collection($collection_id) {
        $repo = \Tainacan\Repositories\Items::get_instance();
        $collection = new \Tainacan\Entities\Collection($collection_id);
        
        if (!$collection->get_id()) return 0;
        
        $page = 1;
        $total = 0;
        
        do {
            $items = $repo->fetch([
                'posts_per_page' => 50,
                'paged' => $page,
                'post_status' => ['publish', 'private'],
            ], $collection, 'OBJECT');
            
            if (!is_array($items) || empty($items)) break;
            
            foreach ($items as $item) {
                $this->index_item($item);
                $total++;
            }
            
            $page++;
        } while (count($items) === 50);
        
        return $total;
    }
    
    public function clear() {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table}");
    }
    
    public function get_stats() {
        global $wpdb;
        
        return [
            'total_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}"),
            'published_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'publish'"),
            'deleted_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE status = 'trash'"),
            'last_indexed' => $wpdb->get_var("SELECT MAX(last_indexed) FROM {$this->table}"),
        ];
    }
    
    public function get_collection_stats() {
        global $wpdb;
        
        $results = $wpdb->get_results(
            "SELECT collection_id, COUNT(*) as count 
             FROM {$this->table} 
             WHERE status = 'publish' 
             GROUP BY collection_id"
        );
        
        $stats = [];
        foreach ($results as $row) {
            $collection = new \Tainacan\Entities\Collection($row->collection_id);
            if ($collection->get_id()) {
                $stats[] = [
                    'id' => $row->collection_id,
                    'name' => $collection->get_name(),
                    'count' => (int) $row->count,
                ];
            }
        }
        
        return $stats;
    }
    
    public function get_health() {
        global $wpdb;
        
        $repo = \Tainacan\Repositories\Items::get_instance();
        $wp_items = $repo->fetch(['posts_per_page' => -1, 'post_status' => ['publish', 'private']], [], 'OBJECT');
        $wp_count = is_array($wp_items) ? count($wp_items) : 0;
        
        $cache_count = $this->count_items(['status' => ['publish', 'private']]);
        $sync_pct = $wp_count > 0 ? round(($cache_count / $wp_count) * 100, 1) : 100;
        
        $outdated = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE last_indexed < %s",
            gmdate('Y-m-d H:i:s', strtotime('-1 day'))
        ));
        
        return [
            'wp_items' => $wp_count,
            'cached_items' => $cache_count,
            'sync_percentage' => $sync_pct,
            'outdated_items' => $outdated,
            'status' => $sync_pct >= 95 ? 'healthy' : ($sync_pct >= 70 ? 'warning' : 'critical'),
        ];
    }
}
