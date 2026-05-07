<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

/**
 * OAI-PMH Importer.
 *
 * Fetches records from external repositories (DSpace, EPrints, Tainacan, etc.)
 * and creates Tainacan items via the Items repository, applying user-defined
 * Dublin Core → metadatum mappings.
 *
 * @see https://www.openarchives.org/OAI/openarchivesprotocol.html
 * @see https://tainacan.github.io/tainacan-wiki/#/dev/README
 */
class Importer {

    private const OAI_NS = 'http://www.openarchives.org/OAI/2.0/';
    private const OAI_DC_NS = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_imports';
    }

    public function fetch_repository_info(string $url) {
        $url = $this->normalize_url($url);
        $validation = $this->validate_url($url);
        if (is_wp_error($validation)) return $validation;

        $response = $this->request($url . '?verb=Identify');
        if (is_wp_error($response)) return $response;

        $xml = $this->parse_xml($response);
        if (is_wp_error($xml)) return $xml;

        if ($err = $this->extract_oai_error($xml)) return $err;

        $xml->registerXPathNamespace('oai', self::OAI_NS);
        $identify = $xml->Identify ?? ($xml->xpath('//oai:Identify')[0] ?? null);

        if (!$identify) return new \WP_Error('invalid_response', __('Invalid Identify response.', 'tainacan-oai-pmh'));

        return [
            'repository_name' => (string) ($identify->repositoryName ?? ''),
            'base_url' => (string) ($identify->baseURL ?? $url),
            'admin_email' => (string) ($identify->adminEmail ?? ''),
            'earliest_datestamp' => (string) ($identify->earliestDatestamp ?? ''),
            'protocol_version' => (string) ($identify->protocolVersion ?? ''),
            'granularity' => (string) ($identify->granularity ?? ''),
        ];
    }

    public function fetch_metadata_formats(string $url) {
        $url = $this->normalize_url($url);
        $validation = $this->validate_url($url);
        if (is_wp_error($validation)) return $validation;

        $response = $this->request($url . '?verb=ListMetadataFormats');
        if (is_wp_error($response)) return $response;

        $xml = $this->parse_xml($response);
        if (is_wp_error($xml)) return $xml;

        $formats = [];
        $xml->registerXPathNamespace('oai', self::OAI_NS);
        $nodes = $xml->ListMetadataFormats->metadataFormat ?? $xml->xpath('//oai:metadataFormat') ?? [];

        foreach ($nodes as $node) {
            $formats[] = [
                'prefix' => (string) ($node->metadataPrefix ?? ''),
                'schema' => (string) ($node->schema ?? ''),
                'namespace' => (string) ($node->metadataNamespace ?? ''),
            ];
        }
        return $formats;
    }

    public function fetch_sets(string $url) {
        $url = $this->normalize_url($url);
        $validation = $this->validate_url($url);
        if (is_wp_error($validation)) return $validation;

        $response = $this->request($url . '?verb=ListSets');
        if (is_wp_error($response)) return $response;

        $xml = $this->parse_xml($response);
        if (is_wp_error($xml)) return $xml;

        $sets = [];
        if (isset($xml->error) && (string) $xml->error['code'] === 'noSetHierarchy') return $sets;

        // Some servers return sets across multiple pages with resumptionToken — follow up to 5 pages
        $base = $url;
        $pages = 0;
        do {
            $xml->registerXPathNamespace('oai', self::OAI_NS);
            $set_nodes = $xml->ListSets->set ?? $xml->xpath('//oai:set') ?? [];

            foreach ($set_nodes as $set) {
                $sets[] = [
                    'spec' => (string) ($set->setSpec ?? ''),
                    'name' => (string) ($set->setName ?? ''),
                    'description' => isset($set->setDescription) ? trim((string) $set->setDescription) : '',
                ];
            }

            $rt = $xml->ListSets->resumptionToken ?? null;
            if (!$rt || (string) $rt === '' || ++$pages >= 5) break;

            $response = $this->request($base . '?verb=ListSets&resumptionToken=' . urlencode((string) $rt));
            if (is_wp_error($response)) break;
            $xml = $this->parse_xml($response);
            if (is_wp_error($xml)) break;
        } while (true);

        return $sets;
    }

    public function preview_records(string $url, string $set = '', int $limit = 5, string $prefix = 'oai_dc') {
        $url = $this->normalize_url($url);
        $validation = $this->validate_url($url);
        if (is_wp_error($validation)) return $validation;

        $query = '?verb=ListRecords&metadataPrefix=' . urlencode($prefix);
        if ($set !== '') $query .= '&set=' . urlencode($set);

        $response = $this->request($url . $query);
        if (is_wp_error($response)) return $response;

        $xml = $this->parse_xml($response);
        if (is_wp_error($xml)) return $xml;

        if ($err = $this->extract_oai_error($xml)) return $err;

        $records = [];
        $record_nodes = $xml->ListRecords->record ?? [];

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
            'dc_fields' => $this->discover_source_fields($records),
        ];
    }

    /**
     * Returns the union of all metadata fields actually present in the sample records,
     * with sample values, occurrence count, and detection of multi-valued fields.
     */
    private function discover_source_fields(array $records): array {
        $fields = [];
        foreach ($records as $record) {
            foreach (($record['metadata'] ?? []) as $key => $value) {
                $values = is_array($value) ? $value : [$value];
                if (!isset($fields[$key])) {
                    $fields[$key] = [
                        'name' => $key,
                        'label' => ucfirst($key),
                        'sample' => '',
                        'occurrences' => 0,
                        'is_multi' => false,
                    ];
                }
                $fields[$key]['occurrences']++;
                if (count($values) > 1) $fields[$key]['is_multi'] = true;
                if (empty($fields[$key]['sample']) && !empty($values[0])) {
                    $fields[$key]['sample'] = mb_substr((string) $values[0], 0, 120);
                }
            }
        }
        return array_values($fields);
    }

    private function parse_record(\SimpleXMLElement $record): ?array {
        $header = $record->header ?? null;
        if (!$header) return null;

        $data = [
            'identifier' => trim((string) ($header->identifier ?? '')),
            'datestamp' => (string) ($header->datestamp ?? ''),
            'status' => isset($header['status']) ? (string) $header['status'] : 'active',
            'set_specs' => [],
            'metadata' => [],
        ];

        // header may have multiple setSpec children
        if (isset($header->setSpec)) {
            foreach ($header->setSpec as $ss) {
                $data['set_specs'][] = (string) $ss;
            }
        }

        $metadata = $record->metadata ?? null;
        if (!$metadata) return $data;

        // Try oai_dc first; if not present, dump every namespaced child as a field bag.
        $dc = $metadata->children(self::OAI_DC_NS);
        if ($dc && $dc->dc) {
            $dc_elements = $dc->dc->children(self::DC_NS);
            $this->collect_elements_into($data['metadata'], $dc_elements);
        } else {
            // fall back: walk every namespace and collect by local name (e.g. qdc, mods)
            foreach ($metadata->children() as $child) {
                $this->collect_elements_into($data['metadata'], $child->children());
                foreach ($child->getDocNamespaces(true) as $prefix => $ns) {
                    if (!$prefix) continue;
                    $this->collect_elements_into($data['metadata'], $child->children($ns));
                }
            }
        }

        return $data;
    }

    private function collect_elements_into(array &$bag, $elements): void {
        if (!$elements) return;
        foreach ($elements as $element) {
            $name = $element->getName();
            $value = trim((string) $element);
            if ($value === '') continue;

            if (isset($bag[$name])) {
                if (!is_array($bag[$name])) $bag[$name] = [$bag[$name]];
                $bag[$name][] = $value;
            } else {
                $bag[$name] = $value;
            }
        }
    }

    public function create_import(array $args) {
        global $wpdb;

        if (empty($args['source_url']) || empty($args['collection_id'])) {
            return new \WP_Error('missing_field', __('Source URL and collection are required.', 'tainacan-oai-pmh'));
        }

        $url_check = $this->validate_url($args['source_url']);
        if (is_wp_error($url_check)) return $url_check;

        $collection = new \Tainacan\Entities\Collection((int) $args['collection_id']);
        if (!$collection->get_id()) return new \WP_Error('invalid_collection', __('Collection not found.', 'tainacan-oai-pmh'));

        // Validate optional dates against OAI-PMH granularity
        foreach (['from_date', 'until_date'] as $f) {
            if (!empty($args[$f]) && !$this->is_valid_oai_date($args[$f])) {
                return new \WP_Error('invalid_date', sprintf(__('Invalid %s — use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.', 'tainacan-oai-pmh'), $f));
            }
        }

        $wpdb->insert($this->table, [
            'source_url' => $this->normalize_url($args['source_url']),
            'collection_id' => (int) $args['collection_id'],
            'set_spec' => $args['set_spec'] ?? '',
            'from_date' => !empty($args['from_date']) ? $args['from_date'] : null,
            'until_date' => !empty($args['until_date']) ? $args['until_date'] : null,
            'metadata_mapping' => maybe_serialize($args['metadata_mapping'] ?? []),
            'status' => 'pending',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $wpdb->insert_id;
    }

    public function process_batch(int $import_id, int $batch_size = 10) {
        global $wpdb;

        $import = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $import_id));
        if (!$import) return new \WP_Error('not_found', __('Import not found.', 'tainacan-oai-pmh'));
        if ($import->status === 'completed') return ['status' => 'completed'];

        if ($import->status === 'pending') {
            $wpdb->update($this->table, [
                'status' => 'processing',
                'started_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $import_id]);
        }

        // Per OAI-PMH spec: when using a resumption token, only verb + token allowed.
        if (!empty($import->resumption_token)) {
            $url = $import->source_url . '?verb=ListRecords&resumptionToken=' . urlencode($import->resumption_token);
        } else {
            $url = $import->source_url . '?verb=ListRecords&metadataPrefix=oai_dc';
            if (!empty($import->set_spec))   $url .= '&set='   . urlencode($import->set_spec);
            if (!empty($import->from_date))  $url .= '&from='  . urlencode($import->from_date);
            if (!empty($import->until_date)) $url .= '&until=' . urlencode($import->until_date);
        }

        $response = $this->request($url);
        if (is_wp_error($response)) {
            $this->append_error_log($import_id, 'request_failed', $response->get_error_message());
            return $response;
        }

        $xml = $this->parse_xml($response);
        if (is_wp_error($xml)) {
            $this->append_error_log($import_id, 'parse_error', $xml->get_error_message());
            return $xml;
        }

        if (isset($xml->error)) {
            $code = (string) $xml->error['code'];
            if ($code === 'noRecordsMatch') {
                $wpdb->update($this->table, [
                    'status' => 'completed',
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                ], ['id' => $import_id]);
                return ['status' => 'completed', 'has_more' => false, 'total_imported' => (int) $import->imported_records, 'failed' => (int) $import->failed_records];
            }
            $this->append_error_log($import_id, $code, (string) $xml->error);
            return new \WP_Error($code, (string) $xml->error);
        }

        $records = $xml->ListRecords->record ?? [];
        $mapping = maybe_unserialize($import->metadata_mapping);
        if (!is_array($mapping)) $mapping = [];

        $imported = 0; $failed = 0; $skipped = 0;

        foreach ($records as $record) {
            $parsed = $this->parse_record($record);
            if (!$parsed) { $failed++; continue; }
            if ($parsed['status'] === 'deleted') { $skipped++; continue; }

            // Deduplicate by OAI identifier — find existing item if previously imported
            $existing = $this->find_item_by_oai_identifier($parsed['identifier']);
            if ($existing) { $skipped++; continue; }

            $result = $this->create_item((int) $import->collection_id, $parsed, $mapping);
            if (is_wp_error($result)) {
                $failed++;
                $this->append_error_log($import_id, $result->get_error_code(), '[' . $parsed['identifier'] . '] ' . $result->get_error_message());
            } else {
                $imported++;
            }
        }

        $rt = $xml->ListRecords->resumptionToken ?? null;
        $token = $rt ? trim((string) $rt) : '';
        $total = isset($rt['completeListSize']) ? (int) $rt['completeListSize'] : (int) $import->total_records;

        $update = [
            'imported_records' => $import->imported_records + $imported,
            'failed_records' => $import->failed_records + $failed,
            'resumption_token' => $token !== '' ? $token : null,
        ];
        if ($total > 0) $update['total_records'] = $total;
        if ($token === '') {
            $update['status'] = 'completed';
            $update['completed_at'] = gmdate('Y-m-d H:i:s');
        }

        $wpdb->update($this->table, $update, ['id' => $import_id]);

        return [
            'status' => $token === '' ? 'completed' : 'processing',
            'total_imported' => $import->imported_records + $imported,
            'total_records' => $total ?: (int) $import->total_records,
            'failed' => $import->failed_records + $failed,
            'skipped' => $skipped,
            'has_more' => $token !== '',
        ];
    }

    private function find_item_by_oai_identifier(string $oai_identifier): ?int {
        if ($oai_identifier === '') return null;
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            '_tainacan_oai_source_id',
            $oai_identifier
        ));
        return $found ? (int) $found : null;
    }

    private function create_item(int $collection_id, array $record, array $mapping) {
        $collection = new \Tainacan\Entities\Collection($collection_id);
        if (!$collection->get_id()) return new \WP_Error('invalid_collection', 'Collection not found.');

        $item_repo = \Tainacan\Repositories\Items::get_instance();
        $item = new \Tainacan\Entities\Item();
        $item->set_collection($collection);

        $title = $record['metadata']['title'] ?? $record['identifier'];
        if (is_array($title)) $title = $title[0] ?? '';
        if (!is_string($title) || $title === '') $title = $record['identifier'] ?: __('Untitled imported item', 'tainacan-oai-pmh');
        $item->set_title($title);

        if (!empty($record['metadata']['description'])) {
            $desc = $record['metadata']['description'];
            if (is_array($desc)) $desc = implode("\n\n", array_filter($desc));
            $item->set_description((string) $desc);
        }

        $item->set_status('publish');

        if (!$item->validate()) {
            $errors = $item->get_errors();
            $msg = is_array($errors) ? implode(', ', array_map(fn($e) => is_array($e) ? implode(';', $e) : (string) $e, $errors)) : (string) $errors;
            return new \WP_Error('validation_error', $msg);
        }
        $item = $item_repo->insert($item);

        // Persist OAI identifier for deduplication and audit trail
        if ($item && $item->get_id()) {
            update_post_meta($item->get_id(), '_tainacan_oai_source_id', $record['identifier']);
            update_post_meta($item->get_id(), '_tainacan_oai_source_datestamp', $record['datestamp']);
        }

        if (!empty($mapping)) {
            $errors = $this->apply_mapping($item, $record['metadata'], $mapping);
            if (!empty($errors)) {
                // Mapping errors are non-fatal — log them but consider the item imported
                error_log('[Tainacan OAI Importer] Item ' . $item->get_id() . ' mapping warnings: ' . implode('; ', $errors));
            }
        }

        return $item->get_id();
    }

    private function apply_mapping($item, array $source, array $mapping): array {
        $repo = \Tainacan\Repositories\Item_Metadata::get_instance();
        $errors = [];

        foreach ($mapping as $dc_field => $metadatum_id) {
            $metadatum_id = (int) $metadatum_id;
            if ($metadatum_id <= 0 || !isset($source[$dc_field])) continue;

            $metadatum = new \Tainacan\Entities\Metadatum($metadatum_id);
            if (!$metadatum->get_id()) continue;

            $value = $source[$dc_field];
            if (is_array($value)) {
                if (!$metadatum->is_multiple()) {
                    $value = $value[0] ?? '';
                } else {
                    $value = array_values(array_filter($value, fn($v) => $v !== null && $v !== ''));
                }
            }

            try {
                $item_meta = new \Tainacan\Entities\Item_Metadata_Entity($item, $metadatum);
                $item_meta->set_value($value);
                if ($item_meta->validate()) {
                    $repo->insert($item_meta);
                } else {
                    $meta_errors = $item_meta->get_errors();
                    $errors[] = "$dc_field → " . $metadatum->get_name() . ': ' . (is_array($meta_errors) ? json_encode($meta_errors) : (string) $meta_errors);
                }
            } catch (\Throwable $e) {
                $errors[] = "$dc_field → " . $metadatum->get_name() . ': ' . $e->getMessage();
            }
        }
        return $errors;
    }

    public function get_imports(int $limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d", $limit
        ));
    }

    public function get_import(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
    }

    private function append_error_log(int $import_id, string $code, string $message): void {
        global $wpdb;
        $entry = '[' . gmdate('Y-m-d H:i:s') . '] ' . $code . ': ' . $message . "\n";
        // Cap log size at 64 KB to prevent runaway growth
        $current = (string) $wpdb->get_var($wpdb->prepare("SELECT error_log FROM {$this->table} WHERE id = %d", $import_id));
        $combined = $current . $entry;
        if (strlen($combined) > 65536) $combined = substr($combined, -65536);
        $wpdb->update($this->table, ['error_log' => $combined], ['id' => $import_id]);
    }

    private function normalize_url(string $url): string {
        $url = trim($url);
        // Strip query string entirely — OAI requires a clean base URL we control
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) return $url;
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        return $scheme . '://' . $parts['host'] . $port . $path;
    }

    /**
     * SSRF guard: reject URLs that resolve to private/loopback/link-local IPs
     * unless explicitly allowed in settings (for self-hosted WP testing local OAI).
     */
    private function validate_url(string $url) {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return new \WP_Error('invalid_url', __('Malformed URL.', 'tainacan-oai-pmh'));
        }
        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return new \WP_Error('invalid_scheme', __('Only HTTP/HTTPS URLs are allowed.', 'tainacan-oai-pmh'));
        }

        if (Settings::get('importer_allow_private_ips', false)) {
            return true;
        }

        // Resolve host to all IPs and check each
        $host = $parts['host'];
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ip'])) $ips[] = $r['ip'];
                    if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
                }
            }
            if (empty($ips)) {
                $resolved = @gethostbyname($host);
                if ($resolved && $resolved !== $host) $ips[] = $resolved;
            }
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return new \WP_Error(
                    'private_address',
                    sprintf(__('URL points to a private/reserved address (%s) — refused for security.', 'tainacan-oai-pmh'), $ip)
                );
            }
        }

        return true;
    }

    private function is_valid_oai_date(string $date): bool {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}Z)?$/', $date);
    }

    private function parse_xml(string $body) {
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET disables network access during XML parsing (defense-in-depth vs. XXE)
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            $msg = !empty($errors) ? trim($errors[0]->message) : __('Failed to parse XML response.', 'tainacan-oai-pmh');
            return new \WP_Error('parse_error', $msg);
        }
        return $xml;
    }

    private function extract_oai_error(\SimpleXMLElement $xml) {
        if (!isset($xml->error)) return null;
        $code = (string) $xml->error['code'];
        if ($code === 'noRecordsMatch') return null; // not a hard error
        return new \WP_Error($code, (string) $xml->error);
    }

    private function request(string $url) {
        $sslverify = (bool) Settings::get('importer_sslverify', true);
        $timeout = max(5, (int) Settings::get('importer_timeout', 60));

        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'sslverify' => $sslverify,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'text/xml, application/xml',
                'User-Agent' => 'Tainacan-OAI-PMH-Importer/' . TAINACAN_OAI_PMH_VERSION,
            ],
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) return new \WP_Error('http_error', sprintf('HTTP %d from upstream OAI server.', $code));
        return wp_remote_retrieve_body($response);
    }
}
