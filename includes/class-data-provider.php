<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Data_Provider {
    
    private $identifier_prefix;
    
    public function __construct() {
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);
        $this->identifier_prefix = "oai:{$domain}:";
    }
    
    public function get_base_url() {
        return rest_url('tainacan-oai/v1/oai');
    }
    
    public function get_identifier_prefix() {
        return $this->identifier_prefix;
    }
    
    public function build_identifier($item_id) {
        return $this->identifier_prefix . $item_id;
    }
    
    public function extract_item_id($identifier) {
        return (int) str_replace($this->identifier_prefix, '', $identifier);
    }
    
    public function get_identify() {
        return [
            'repositoryName' => Settings::get('repository_name', get_bloginfo('name')),
            'baseURL' => $this->get_base_url(),
            'protocolVersion' => '2.0',
            'adminEmail' => Settings::get('admin_email', get_option('admin_email')),
            'earliestDatestamp' => $this->get_earliest_datestamp(),
            'deletedRecord' => 'transient',
            'granularity' => 'YYYY-MM-DDThh:mm:ssZ',
        ];
    }
    
    private function get_earliest_datestamp() {
        global $wpdb;
        $earliest = $wpdb->get_var(
            "SELECT MIN(post_date_gmt) FROM {$wpdb->posts}
             WHERE post_type LIKE 'tnc_col_%_item' AND post_status IN ('publish', 'private')
                AND post_date_gmt > '1970-01-01 00:00:00'"
        );
        if ($earliest) {
            return gmdate('Y-m-d\TH:i:s\Z', strtotime($earliest . ' UTC'));
        }
        // No items yet — return today (per OAI-PMH spec, earliestDatestamp must be set)
        return gmdate('Y-m-d\TH:i:s\Z');
    }
    
    public function get_sets() {
        $repo = \Tainacan\Repositories\Collections::get_instance();
        $collections = $repo->fetch([], 'OBJECT');
        
        $sets = [];
        if (is_array($collections)) {
            foreach ($collections as $col) {
                $sets[] = [
                    'setSpec' => (string) $col->get_id(),
                    'setName' => $col->get_name(),
                    'setDescription' => $col->get_description(),
                ];
            }
        }
        return $sets;
    }
    
    public function set_exists($set_spec) {
        // Plugin exposes only Tainacan collections as sets (numeric IDs).
        // Reject non-numeric set specs deliberately — yields badArgument upstream.
        if (!ctype_digit((string) $set_spec)) return false;
        $collection = new \Tainacan\Entities\Collection((int) $set_spec);
        return $collection->get_id() > 0;
    }
    
    public function get_item($identifier) {
        $item_id = $this->extract_item_id($identifier);
        $item = new \Tainacan\Entities\Item($item_id);
        if (!$item->get_id()) return null;
        return $this->format_item($item);
    }
    
    public function item_exists($identifier) {
        $item_id = $this->extract_item_id($identifier);
        $item = new \Tainacan\Entities\Item($item_id);
        return $item->get_id() > 0;
    }
    
    private function format_item($item) {
        $collection = $item->get_collection();
        return [
            'identifier' => $this->build_identifier($item->get_id()),
            'datestamp' => gmdate('Y-m-d\TH:i:s\Z', strtotime($item->get_modification_date() ?: $item->get_creation_date())),
            'setSpec' => $collection ? (string) $collection->get_id() : null,
            'status' => $item->get_status(),
            'metadata' => $this->get_item_dc($item),
        ];
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
                            if (!is_array($dc[$field])) $dc[$field] = [$dc[$field]];
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
}
