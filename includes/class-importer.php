<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Importer {
    
    private $table;
    
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_imports';
    }
    
    public function fetch_repository_info($url) {
        $url = $this->normalize_url($url);
        $response = $this->request($url . '?verb=Identify');
        
        if (is_wp_error($response)) return $response;
        
        $xml = @simplexml_load_string($response);
        if (!$xml) return new \WP_Error('parse_error', __('Failed to parse response.', 'tainacan-oai-pmh'));
        
        $xml->registerXPathNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
        $identify = $xml->Identify ?? $xml->xpath('//oai:Identify')[0] ?? null;
        
        if (!$identify) return new \WP_Error('invalid_response', __('Invalid Identify response.', 'tainacan-oai-pmh'));
        
        return [
            'repository_name' => (string) ($identify->repositoryName ?? ''),
            'base_url' => (string) ($identify->baseURL ?? $url),
            'admin_email' => (string) ($identify->adminEmail ?? ''),
            'earliest_datestamp' => (string) ($identify->earliestDatestamp ?? ''),
        ];
    }
    
    public function fetch_sets($url) {
        $url = $this->normalize_url($url);
        $response = $this->request($url . '?verb=ListSets');
        
        if (is_wp_error($response)) return $response;
        
        $xml = @simplexml_load_string($response);
        if (!$xml) return new \WP_Error('parse_error', __('Failed to parse response.', 'tainacan-oai-pmh'));
        
        $sets = [];
        if (isset($xml->error) && (string) $xml->error['code'] === 'noSetHierarchy') return $sets;
        
        $xml->registerXPathNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
        $set_nodes = $xml->ListSets->set ?? $xml->xpath('//oai:set') ?? [];
        
        foreach ($set_nodes as $set) {
            $sets[] = [
                'spec' => (string) ($set->setSpec ?? ''),
                'name' => (string) ($set->setName ?? ''),
            ];
        }
        return $sets;
    }
    
    public function preview_records($url, $set = '', $limit = 5) {
        $url = $this->normalize_url($url);
        $query = '?verb=ListRecords&metadataPrefix=oai_dc';
        if ($set) $query .= '&set=' . urlencode($set);
        
        $response = $this->request($url . $query);
        if (is_wp_error($response)) return $response;
        
        $xml = @simplexml_load_string($response);
        if (!$xml) return new \WP_Error('parse_error', __('Failed to parse response.', 'tainacan-oai-pmh'));
        
        $xml->registerXPathNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
        
        $records = [];
        $record_nodes = $xml->ListRecords->record ?? $xml->xpath('//oai:record') ?? [];
        
        $count = 0;
        foreach ($record_nodes as $record) {
            if ($count >= $limit) break;
            $parsed = $this->parse_record($record);
            if ($parsed) { $records[] = $parsed; $count++; }
        }
        
        $total = null;
        $rt = $xml->ListRecords->resumptionToken ?? null;
        if ($rt && isset($rt['completeListSize'])) $total = (int) $rt['completeListSize'];
        
        return [
            'records' => $records,
            'total' => $total,
            'dc_fields' => $this->get_dc_fields($records),
        ];
    }
    
    private function get_dc_fields($records) {
        $fields = [];
        foreach ($records as $record) {
            foreach ($record['metadata'] as $key => $value) {
                if (!isset($fields[$key])) {
                    $fields[$key] = ['name' => $key, 'label' => ucfirst($key), 'sample' => is_array($value) ? implode(', ', array_slice($value, 0, 2)) : $value];
                }
            }
        }
        return array_values($fields);
    }
    
    private function parse_record($record) {
        $header = $record->header ?? null;
        if (!$header) return null;
        
        $data = [
            'identifier' => (string) ($header->identifier ?? ''),
            'datestamp' => (string) ($header->datestamp ?? ''),
            'status' => isset($header['status']) ? (string) $header['status'] : 'active',
            'metadata' => [],
        ];
        
        $metadata = $record->metadata ?? null;
        if ($metadata) {
            $dc = $metadata->children('http://www.openarchives.org/OAI/2.0/oai_dc/');
            if ($dc && $dc->dc) {
                $dc_elements = $dc->dc->children('http://purl.org/dc/elements/1.1/');
                foreach ($dc_elements as $element) {
                    $name = $element->getName();
                    $value = trim((string) $element);
                    if (empty($value)) continue;
                    
                    if (isset($data['metadata'][$name])) {
                        if (!is_array($data['metadata'][$name])) $data['metadata'][$name] = [$data['metadata'][$name]];
                        $data['metadata'][$name][] = $value;
                    } else {
                        $data['metadata'][$name] = $value;
                    }
                }
            }
        }
        return $data;
    }
    
    public function create_import($args) {
        global $wpdb;
        
        if (empty($args['source_url']) || empty($args['collection_id'])) {
            return new \WP_Error('missing_field', __('Source URL and collection are required.', 'tainacan-oai-pmh'));
        }
        
        $collection = new \Tainacan\Entities\Collection($args['collection_id']);
        if (!$collection->get_id()) return new \WP_Error('invalid_collection', __('Collection not found.', 'tainacan-oai-pmh'));
        
        $wpdb->insert($this->table, [
            'source_url' => $this->normalize_url($args['source_url']),
            'collection_id' => $args['collection_id'],
            'set_spec' => $args['set_spec'] ?? '',
            'from_date' => $args['from_date'] ?: null,
            'until_date' => $args['until_date'] ?: null,
            'metadata_mapping' => maybe_serialize($args['metadata_mapping'] ?? []),
            'status' => 'pending',
            'created_at' => current_time('mysql'),
        ]);
        
        return $wpdb->insert_id;
    }
    
    public function process_batch($import_id, $batch_size = 10) {
        global $wpdb;
        
        $import = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $import_id));
        if (!$import) return new \WP_Error('not_found', __('Import not found.', 'tainacan-oai-pmh'));
        if ($import->status === 'completed') return ['status' => 'completed'];
        
        if ($import->status === 'pending') {
            $wpdb->update($this->table, ['status' => 'processing', 'started_at' => current_time('mysql')], ['id' => $import_id]);
        }
        
        $url = $import->source_url . '?verb=ListRecords&metadataPrefix=oai_dc';
        if ($import->resumption_token) {
            $url = $import->source_url . '?verb=ListRecords&resumptionToken=' . urlencode($import->resumption_token);
        } else {
            if ($import->set_spec) $url .= '&set=' . urlencode($import->set_spec);
            if ($import->from_date) $url .= '&from=' . urlencode($import->from_date);
            if ($import->until_date) $url .= '&until=' . urlencode($import->until_date);
        }
        
        $response = $this->request($url);
        if (is_wp_error($response)) return $response;
        
        $xml = @simplexml_load_string($response);
        if (!$xml) return new \WP_Error('parse_error', __('Failed to parse response.', 'tainacan-oai-pmh'));
        
        if (isset($xml->error)) {
            $code = (string) $xml->error['code'];
            if ($code === 'noRecordsMatch') {
                $wpdb->update($this->table, ['status' => 'completed', 'completed_at' => current_time('mysql')], ['id' => $import_id]);
                return ['status' => 'completed'];
            }
            return new \WP_Error($code, (string) $xml->error);
        }
        
        $records = $xml->ListRecords->record ?? [];
        $mapping = maybe_unserialize($import->metadata_mapping);
        $imported = 0; $failed = 0;
        
        foreach ($records as $record) {
            $parsed = $this->parse_record($record);
            if (!$parsed || $parsed['status'] === 'deleted') continue;
            
            $result = $this->create_item($import->collection_id, $parsed, $mapping);
            if (is_wp_error($result)) $failed++; else $imported++;
        }
        
        $rt = $xml->ListRecords->resumptionToken ?? null;
        $token = $rt ? (string) $rt : '';
        $total = isset($rt['completeListSize']) ? (int) $rt['completeListSize'] : $import->total_records;
        
        $update = [
            'imported_records' => $import->imported_records + $imported,
            'failed_records' => $import->failed_records + $failed,
            'resumption_token' => $token,
        ];
        if ($total) $update['total_records'] = $total;
        if (empty($token)) {
            $update['status'] = 'completed';
            $update['completed_at'] = current_time('mysql');
            $update['resumption_token'] = null;
        }
        
        $wpdb->update($this->table, $update, ['id' => $import_id]);
        
        return [
            'status' => empty($token) ? 'completed' : 'processing',
            'total_imported' => $import->imported_records + $imported,
            'total_records' => $total ?: $import->total_records,
            'failed' => $import->failed_records + $failed,
            'has_more' => !empty($token),
        ];
    }
    
    private function create_item($collection_id, $record, $mapping) {
        $collection = new \Tainacan\Entities\Collection($collection_id);
        if (!$collection->get_id()) return new \WP_Error('invalid_collection', 'Collection not found.');
        
        $item_repo = \Tainacan\Repositories\Items::get_instance();
        $item = new \Tainacan\Entities\Item();
        $item->set_collection($collection);
        
        $title = $record['metadata']['title'] ?? $record['identifier'];
        if (is_array($title)) $title = $title[0];
        $item->set_title($title);
        
        if (!empty($record['metadata']['description'])) {
            $desc = $record['metadata']['description'];
            if (is_array($desc)) $desc = implode("\n\n", $desc);
            $item->set_description($desc);
        }
        
        $item->set_status('publish');
        
        if (!$item->validate()) return new \WP_Error('validation_error', implode(', ', $item->get_errors()));
        $item = $item_repo->insert($item);
        
        if (!empty($mapping) && is_array($mapping)) {
            $this->apply_mapping($item, $record['metadata'], $mapping);
        }
        
        return $item->get_id();
    }
    
    private function apply_mapping($item, $source, $mapping) {
        $repo = \Tainacan\Repositories\Item_Metadata::get_instance();
        
        foreach ($mapping as $dc_field => $metadatum_id) {
            if (empty($metadatum_id) || !isset($source[$dc_field])) continue;
            
            $metadatum = new \Tainacan\Entities\Metadatum($metadatum_id);
            if (!$metadatum->get_id()) continue;
            
            $value = $source[$dc_field];
            if (is_array($value) && !$metadatum->is_multiple()) $value = $value[0];
            
            $item_meta = new \Tainacan\Entities\Item_Metadata_Entity($item, $metadatum);
            $item_meta->set_value($value);
            if ($item_meta->validate()) $repo->insert($item_meta);
        }
    }
    
    public function get_imports($limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d", $limit
        ));
    }
    
    private function normalize_url($url) {
        $url = trim($url);
        $url = rtrim($url, '?&');
        $url = preg_replace('/[?&]verb=[^&]*/', '', $url);
        return rtrim($url, '?&');
    }
    
    private function request($url) {
        $response = wp_remote_get($url, [
            'timeout' => 60,
            'sslverify' => false,
            'headers' => ['Accept' => 'text/xml, application/xml', 'User-Agent' => 'Tainacan-OAI-PMH-Importer/' . TAINACAN_OAI_PMH_VERSION],
        ]);
        
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) return new \WP_Error('http_error', "HTTP $code");
        return wp_remote_retrieve_body($response);
    }
}
