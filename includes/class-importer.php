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
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const OREATOM_NS = 'http://www.openarchives.org/ore/atom/';
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const DCTERMS_NS = 'http://purl.org/dc/terms/';

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

        // Bitstream downloads may take minutes per batch — disable PHP timeout.
        // The browser polls in chunks, so user-perceived progress remains responsive.
        if (function_exists('set_time_limit')) @set_time_limit(0);
        ignore_user_abort(true);

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
            $this->append_log($import_id, 'INFO', 'page.fetch', 'Fetching with resumption token (token len ' . strlen($import->resumption_token) . ')');
        } else {
            $url = $import->source_url . '?verb=ListRecords&metadataPrefix=oai_dc';
            if (!empty($import->set_spec))   $url .= '&set='   . urlencode($import->set_spec);
            if (!empty($import->from_date))  $url .= '&from='  . urlencode($import->from_date);
            if (!empty($import->until_date)) $url .= '&until=' . urlencode($import->until_date);
            $this->append_log($import_id, 'INFO', 'page.fetch', 'First page: ' . $url);
        }

        $t_request = microtime(true);
        $response = $this->request($url);
        if (is_wp_error($response)) {
            $this->append_log($import_id, 'ERROR', 'request_failed', $response->get_error_message());
            return $response;
        }

        $xml = $this->parse_xml($response);
        if (is_wp_error($xml)) {
            $this->append_log($import_id, 'ERROR', 'parse_error', $xml->get_error_message());
            return $xml;
        }

        if (isset($xml->error)) {
            $code = (string) $xml->error['code'];
            if ($code === 'noRecordsMatch') {
                $this->append_log($import_id, 'INFO', 'noRecordsMatch', 'Upstream reports no records match the criteria — marking import completed.');
                $wpdb->update($this->table, [
                    'status' => 'completed',
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                ], ['id' => $import_id]);
                return ['status' => 'completed', 'has_more' => false, 'total_imported' => (int) $import->imported_records, 'failed' => (int) $import->failed_records];
            }
            $this->append_log($import_id, 'ERROR', $code, (string) $xml->error);
            return new \WP_Error($code, (string) $xml->error);
        }

        $records = $xml->ListRecords->record ?? [];
        $records_count = is_countable($records) ? count($records) : iterator_count($records);
        $rt_preview = $xml->ListRecords->resumptionToken ?? null;
        $clsize = ($rt_preview && isset($rt_preview['completeListSize'])) ? (int) $rt_preview['completeListSize'] : 0;
        $this->append_log(
            $import_id, 'INFO', 'page.received',
            sprintf('Got %d record(s)%s in %.2fs',
                $records_count,
                $clsize > 0 ? sprintf(' (completeListSize=%d)', $clsize) : '',
                microtime(true) - $t_request
            )
        );

        $mapping = maybe_unserialize($import->metadata_mapping);
        if (!is_array($mapping)) $mapping = [];

        $imported = 0; $failed = 0; $skipped = 0;

        $bitstreams_enabled = (bool) Settings::get('import_bitstreams', true);

        foreach ($records as $record) {
            $parsed = $this->parse_record($record);
            if (!$parsed) {
                $failed++;
                $this->append_log($import_id, 'ERROR', 'parse_record', 'Failed to parse a record (missing header?)');
                continue;
            }
            if ($parsed['status'] === 'deleted') {
                $skipped++;
                $this->append_log($import_id, 'INFO', 'record.deleted_upstream', '[' . $parsed['identifier'] . '] Marked deleted upstream — skipped');
                continue;
            }

            // Deduplicate by OAI identifier — find existing item if previously imported
            $existing = $this->find_item_by_oai_identifier($parsed['identifier']);
            if ($existing) {
                $had_bs = $this->item_has_oai_bitstreams($existing);
                if ($bitstreams_enabled && !$had_bs) {
                    $this->append_log($import_id, 'INFO', 'bitstream.backfill_start', '[' . $parsed['identifier'] . '] Item ' . $existing . ' exists but has no bitstreams — backfilling');
                    $bs_errors = $this->enrich_item_with_bitstreams($existing, $parsed['identifier'], $import->source_url);
                    foreach ($bs_errors as $bs_err) {
                        $this->append_log($import_id, 'WARN', 'bitstream_backfill', '[' . $parsed['identifier'] . '] ' . $bs_err);
                    }
                    if (empty($bs_errors)) {
                        $this->append_log($import_id, 'INFO', 'bitstream.backfill_done', '[' . $parsed['identifier'] . '] Backfill completed for item ' . $existing);
                    }
                } else {
                    $this->append_log($import_id, 'INFO', 'record.skipped_existing', '[' . $parsed['identifier'] . '] Item ' . $existing . ' exists' . ($had_bs ? ' (has bitstreams)' : '') . ' — skipped');
                }
                $skipped++;
                continue;
            }

            $result = $this->create_item((int) $import->collection_id, $parsed, $mapping);
            if (is_wp_error($result)) {
                $failed++;
                $this->append_log($import_id, 'ERROR', $result->get_error_code(), '[' . $parsed['identifier'] . '] ' . $result->get_error_message());
            } else {
                $imported++;
                $this->append_log($import_id, 'INFO', 'record.created', '[' . $parsed['identifier'] . '] Created item ' . (int) $result);
                if ($bitstreams_enabled) {
                    $bs_errors = $this->enrich_item_with_bitstreams((int) $result, $parsed['identifier'], $import->source_url);
                    foreach ($bs_errors as $bs_err) {
                        $this->append_log($import_id, 'WARN', 'bitstream', '[' . $parsed['identifier'] . '] ' . $bs_err);
                    }
                }
            }
        }

        $rt = $xml->ListRecords->resumptionToken ?? null;
        $token = $rt ? trim((string) $rt) : '';
        $total = isset($rt['completeListSize']) ? (int) $rt['completeListSize'] : (int) $import->total_records;
        $cumulative_imported = $import->imported_records + $imported;

        $update = [
            'imported_records' => $cumulative_imported,
            'failed_records' => $import->failed_records + $failed,
            'resumption_token' => $token !== '' ? $token : null,
        ];
        if ($total > 0) $update['total_records'] = $total;

        if ($token === '') {
            // OAI servers signal end-of-list with an empty resumptionToken.
            // Detect the suspicious case where the response advertises more records
            // than we actually imported — this is upstream misbehavior worth flagging.
            if ($total > 0 && $cumulative_imported < $total && ($skipped + $failed) < ($total - $cumulative_imported)) {
                $this->append_log(
                    $import_id, 'WARN', 'page.unexpected_end',
                    sprintf('Upstream returned an empty resumption token but only %d / %d records were processed across all batches. The server may have stopped pagination prematurely.',
                        $cumulative_imported + $skipped + $failed, $total)
                );
            }
            $update['status'] = 'completed';
            $update['completed_at'] = gmdate('Y-m-d H:i:s');
            $this->append_log($import_id, 'INFO', 'import.completed',
                sprintf('Run finished. Cumulative imported=%d, failed=%d, this-batch skipped=%d',
                    $cumulative_imported, $import->failed_records + $failed, $skipped));
        } else {
            $this->append_log($import_id, 'INFO', 'page.has_more', 'Resumption token received — more pages to fetch.');
        }

        $wpdb->update($this->table, $update, ['id' => $import_id]);

        return [
            'status' => $token === '' ? 'completed' : 'processing',
            'total_imported' => $cumulative_imported,
            'total_records' => $total ?: (int) $import->total_records,
            'failed' => $import->failed_records + $failed,
            'skipped' => $skipped,
            'has_more' => $token !== '',
        ];
    }

    /**
     * Periodic harvest loop with insert/update/delete diff.
     *
     * Driven by Harvester for scheduled runs. Differs from process_batch:
     *  - Stateless (no DB-persisted resumption — runs to completion or fails)
     *  - Uses OAI `from` parameter for incremental sync
     *  - On existing items: compares header.datestamp vs stored datestamp
     *      → newer  : update_item_from_oai (re-applies mapping)
     *      → equal  : skip
     *  - On records with status="deleted": trashes the local item
     *  - Caps resumption-token follow-through at 200 pages so a misbehaving
     *    upstream can't pin the cron worker forever
     *
     * @return array{
     *   created:int, updated:int, skipped:int, failed:int, deleted:int,
     *   pages:int, last_datestamp:?string, errors:string[]
     * }
     */
    public function harvest_loop(array $config): array {
        if (function_exists('set_time_limit')) @set_time_limit(0);
        ignore_user_abort(true);

        $stats = [
            'created' => 0, 'updated' => 0, 'skipped' => 0,
            'failed' => 0, 'deleted' => 0, 'pages' => 0,
            'last_datestamp' => null, 'errors' => [],
        ];

        $url = $this->normalize_url($config['source_url'] ?? '');
        $val = $this->validate_url($url);
        if (is_wp_error($val)) { $stats['errors'][] = $val->get_error_message(); return $stats; }

        $set_spec = (string) ($config['set_spec'] ?? '');
        $collection_id = (int) ($config['collection_id'] ?? 0);
        $mapping = is_array($config['metadata_mapping'] ?? null) ? $config['metadata_mapping'] : [];
        $download_bs = !empty($config['download_bitstreams']);
        $from = (string) ($config['from'] ?? '');
        $until = (string) ($config['until'] ?? '');

        if ($collection_id <= 0) { $stats['errors'][] = 'invalid collection_id'; return $stats; }

        $resumption = '';
        $max_pages = 200;

        do {
            if ($resumption !== '') {
                $page_url = $url . '?verb=ListRecords&resumptionToken=' . urlencode($resumption);
            } else {
                $page_url = $url . '?verb=ListRecords&metadataPrefix=oai_dc';
                if ($set_spec !== '') $page_url .= '&set='   . urlencode($set_spec);
                if ($from !== '')     $page_url .= '&from='  . urlencode($from);
                if ($until !== '')    $page_url .= '&until=' . urlencode($until);
            }

            $body = $this->request($page_url);
            if (is_wp_error($body)) { $stats['errors'][] = $body->get_error_message(); break; }

            $xml = $this->parse_xml($body);
            if (is_wp_error($xml)) { $stats['errors'][] = $xml->get_error_message(); break; }

            if (isset($xml->error)) {
                $code = (string) $xml->error['code'];
                if ($code === 'noRecordsMatch') break; // not an error — empty delta
                $stats['errors'][] = $code . ': ' . (string) $xml->error;
                break;
            }

            $records = $xml->ListRecords->record ?? [];
            foreach ($records as $record) {
                $parsed = $this->parse_record($record);
                if (!$parsed) { $stats['failed']++; continue; }

                if ($parsed['datestamp'] !== '' && (string) $parsed['datestamp'] > (string) ($stats['last_datestamp'] ?? '')) {
                    $stats['last_datestamp'] = $parsed['datestamp'];
                }

                $existing = $this->find_item_by_oai_identifier($parsed['identifier']);

                // Tombstone: upstream tells us this record was deleted
                if ($parsed['status'] === 'deleted') {
                    if ($existing) {
                        wp_trash_post($existing);
                        $stats['deleted']++;
                    } else {
                        $stats['skipped']++;
                    }
                    continue;
                }

                if ($existing) {
                    $stored = (string) get_post_meta($existing, '_tainacan_oai_source_datestamp', true);
                    if ($stored !== '' && $parsed['datestamp'] !== '' && $parsed['datestamp'] <= $stored) {
                        // Untouched upstream — but backfill bitstreams if they're missing
                        if ($download_bs && !$this->item_has_oai_bitstreams($existing)) {
                            $this->enrich_item_with_bitstreams($existing, $parsed['identifier'], $url);
                        }
                        $stats['skipped']++;
                        continue;
                    }
                    $upd = $this->update_item_from_oai($existing, $parsed, $mapping);
                    if (is_wp_error($upd)) {
                        $stats['failed']++;
                        $stats['errors'][] = '[' . $parsed['identifier'] . '] update: ' . $upd->get_error_message();
                    } else {
                        $stats['updated']++;
                        if ($download_bs && !$this->item_has_oai_bitstreams($existing)) {
                            $this->enrich_item_with_bitstreams($existing, $parsed['identifier'], $url);
                        }
                    }
                    continue;
                }

                $created = $this->create_item($collection_id, $parsed, $mapping);
                if (is_wp_error($created)) {
                    $stats['failed']++;
                    $stats['errors'][] = '[' . $parsed['identifier'] . '] create: ' . $created->get_error_message();
                    continue;
                }
                $stats['created']++;
                if ($download_bs) {
                    $this->enrich_item_with_bitstreams((int) $created, $parsed['identifier'], $url);
                }
            }

            $stats['pages']++;
            $rt = $xml->ListRecords->resumptionToken ?? null;
            $resumption = $rt ? trim((string) $rt) : '';
        } while ($resumption !== '' && $stats['pages'] < $max_pages);

        return $stats;
    }

    /**
     * Updates an existing Tainacan item from a freshly-parsed OAI record.
     * Re-applies the user's DC mapping (overwriting prior values for those
     * metadata) and refreshes title/description + the source datestamp.
     */
    public function update_item_from_oai(int $item_id, array $parsed, array $mapping) {
        $post = get_post($item_id);
        if (!$post) return new \WP_Error('not_found', 'Item not found.');

        $title = $parsed['metadata']['title'] ?? $parsed['identifier'];
        if (is_array($title)) $title = $title[0] ?? '';
        if (!is_string($title) || $title === '') $title = $parsed['identifier'] ?: $post->post_title;

        $desc = $parsed['metadata']['description'] ?? '';
        if (is_array($desc)) $desc = implode("\n\n", array_filter($desc));

        wp_update_post([
            'ID' => $item_id,
            'post_title' => $title,
            'post_content' => (string) $desc,
        ]);

        if (!empty($mapping)) {
            try {
                $item = new \Tainacan\Entities\Item($item_id);
                if ($item->get_id()) {
                    $this->apply_mapping($item, $parsed['metadata'], $mapping);
                }
            } catch (\Throwable $e) {
                return new \WP_Error('mapping_error', $e->getMessage());
            }
        }

        update_post_meta($item_id, '_tainacan_oai_source_datestamp', (string) $parsed['datestamp']);
        return true;
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

    /**
     * Downloads ORIGINAL + THUMBNAIL bitstreams advertised in the ORE/Atom view
     * of the OAI record, sideloads them as attachments under the Tainacan item,
     * and wires them into Tainacan's display model:
     *
     *  - First ORIGINAL → Tainacan's main "Documento" (set_document + set_document_type)
     *    AND the WordPress featured image (used by Tainacan card listings)
     *  - Remaining bitstreams → "Anexos" via post_parent (Tainacan auto-lists these)
     *
     * Tainacan's get_attachments() excludes BOTH the featured image and the
     * document attachment from the "Anexos" panel — that's why we must wire
     * the first ORIGINAL into both slots so additional bitstreams remain visible.
     *
     * @return string[] Human-readable error messages (one per failed bitstream).
     */
    private function enrich_item_with_bitstreams(int $item_id, string $oai_identifier, string $source_url): array {
        $errors = [];
        $bitstreams = $this->fetch_ore_bitstreams($source_url, $oai_identifier);
        if (is_wp_error($bitstreams) || empty($bitstreams)) return $errors;

        // Process ORIGINALs first so we can promote one before the THUMBNAILs land
        usort($bitstreams, function ($a, $b) {
            $rank = fn($x) => $x['bundle'] === 'ORIGINAL' ? 0 : 1;
            return $rank($a) <=> $rank($b);
        });

        $first_original_id = null;
        foreach ($bitstreams as $bs) {
            $attachment_id = $this->download_bitstream($item_id, $bs);
            if (is_wp_error($attachment_id)) {
                $errors[] = $bs['url'] . ': ' . $attachment_id->get_error_message();
                continue;
            }
            if ($first_original_id === null && ($bs['bundle'] ?? '') === 'ORIGINAL') {
                $first_original_id = (int) $attachment_id;
            }
        }

        if ($first_original_id !== null) {
            // Featured image — used by Tainacan list/card thumbnails. Don't overwrite
            // anything the user may have set manually.
            if (!get_post_thumbnail_id($item_id)) {
                set_post_thumbnail($item_id, $first_original_id);
            }

            // Tainacan main document — separate from WP featured image, drives the
            // big media viewer on the item page. Skip if already set.
            try {
                $item = new \Tainacan\Entities\Item($item_id);
                $current_doc = (string) ($item->get_document() ?? '');
                $current_type = (string) ($item->get_document_type() ?? '');
                if ($current_doc === '' || $current_doc === '0' || $current_type === '' || $current_type === 'empty') {
                    $item->set_document((string) $first_original_id);
                    $item->set_document_type('attachment');
                    \Tainacan\Repositories\Items::get_instance()->insert($item);
                }
            } catch (\Throwable $e) {
                $errors[] = 'set_document: ' . $e->getMessage();
            }
        }

        return $errors;
    }

    private function item_has_oai_bitstreams(int $item_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_parent = %d AND pm.meta_key = '_oai_bitstream_url' LIMIT 1",
            $item_id
        ));
    }

    /**
     * GetRecord using metadataPrefix=ore and parses bitstreams.
     *
     * The ORE atom feed exposes:
     *   - <atom:link rel="aggregates" type="image/jpeg" length="…" href="…"/> → all bitstreams
     *   - <oreatom:triples><rdf:Description rdf:about="…"><dcterms:description>{ORIGINAL|THUMBNAIL}
     * Without the triples block we default unknown URLs to ORIGINAL.
     */
    private function fetch_ore_bitstreams(string $source_url, string $oai_identifier) {
        if ($oai_identifier === '') return [];

        $url = $source_url . '?verb=GetRecord&metadataPrefix=ore&identifier=' . urlencode($oai_identifier);
        $response = $this->request($url);
        if (is_wp_error($response)) return $response;

        $xml = $this->parse_xml($response);
        if (is_wp_error($xml)) return $xml;
        if (isset($xml->error)) return []; // cannotDisseminateFormat etc — repo doesn't speak ORE

        $xml->registerXPathNamespace('atom', self::ATOM_NS);
        $xml->registerXPathNamespace('oreatom', self::OREATOM_NS);
        $xml->registerXPathNamespace('rdf', self::RDF_NS);
        $xml->registerXPathNamespace('dcterms', self::DCTERMS_NS);

        // Map URL → bundle (ORIGINAL/THUMBNAIL) from ore triples
        $bundle_map = [];
        $triples = $xml->xpath('//oreatom:triples/rdf:Description') ?: [];
        foreach ($triples as $desc) {
            $rdf_attrs = $desc->attributes(self::RDF_NS);
            $about = $rdf_attrs ? (string) ($rdf_attrs->about ?? '') : '';
            if ($about === '') continue;
            $dc_desc = (string) ($desc->children(self::DCTERMS_NS)->description ?? '');
            if (in_array($dc_desc, ['ORIGINAL', 'THUMBNAIL'], true)) {
                $bundle_map[$about] = $dc_desc;
            }
        }

        $bitstreams = [];
        $links = $xml->xpath('//atom:entry/atom:link[@rel="aggregates"]') ?: [];
        foreach ($links as $link) {
            $href = (string) ($link['href'] ?? '');
            if ($href === '') continue;
            $type = (string) ($link['type'] ?? '');
            $length = isset($link['length']) ? (int) $link['length'] : 0;
            $bitstreams[] = [
                'url' => $href,
                'mime' => $type,
                'size' => $length,
                'bundle' => $bundle_map[$href] ?? 'ORIGINAL',
            ];
        }

        return $bitstreams;
    }

    /**
     * Sideloads a remote file as a WordPress attachment under the given item.
     *
     * Pre-flights via HEAD to skip oversize files without downloading them.
     * Skips bitstreams already imported (matches on _oai_bitstream_url postmeta).
     *
     * @return int|\WP_Error Attachment ID on success.
     */
    private function download_bitstream(int $item_id, array $bitstream) {
        if (empty($bitstream['url'])) return new \WP_Error('empty_url', 'Empty bitstream URL.');

        $url = $bitstream['url'];
        $max_bytes = max(1, (int) Settings::get('import_max_size_mb', 20)) * 1024 * 1024;
        $sslverify = (bool) Settings::get('importer_sslverify', true);

        // Dedup: same item already has this bitstream attached
        $existing = get_posts([
            'post_type' => 'attachment',
            'post_parent' => $item_id,
            'meta_key' => '_oai_bitstream_url',
            'meta_value' => $url,
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        if (!empty($existing)) return (int) $existing[0];

        // Pre-flight size check via HEAD — saves bandwidth on oversize files
        $head = wp_remote_head($url, ['timeout' => 30, 'sslverify' => $sslverify, 'redirection' => 3]);
        if (!is_wp_error($head)) {
            $cl = (int) wp_remote_retrieve_header($head, 'content-length');
            if ($cl > 0 && $cl > $max_bytes) {
                return new \WP_Error('too_large', sprintf(
                    'Bitstream is %s MB, exceeds %s MB limit.',
                    number_format($cl / 1048576, 1),
                    number_format($max_bytes / 1048576, 0)
                ));
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 120);
        if (is_wp_error($tmp)) return $tmp;

        // Verify size after download (handles servers that don't send Content-Length)
        $actual = @filesize($tmp) ?: 0;
        if ($actual > $max_bytes) {
            @unlink($tmp);
            return new \WP_Error('too_large', sprintf(
                'Downloaded file is %s MB, exceeds %s MB limit.',
                number_format($actual / 1048576, 1),
                number_format($max_bytes / 1048576, 0)
            ));
        }

        // Build a clean filename from the URL path (decode + sanitize)
        $path = wp_parse_url($url, PHP_URL_PATH) ?: '';
        $basename = basename($path);
        $filename = sanitize_file_name(urldecode($basename));
        if ($filename === '' || !str_contains($filename, '.')) {
            // Fall back to a hash if the URL has no usable filename
            $ext = $this->guess_ext_from_mime($bitstream['mime'] ?? '');
            $filename = 'bitstream-' . substr(md5($url), 0, 12) . $ext;
        }

        $file_array = ['name' => $filename, 'tmp_name' => $tmp];

        // Suppress WP MIME-by-extension check failures by passing the upstream MIME hint
        $overrides = ['test_form' => false];
        if (!empty($bitstream['mime'])) {
            // tell WP what to expect; otherwise sideload may reject .jpg.jpg etc.
            add_filter('upload_mimes', $mime_filter = function ($mimes) use ($bitstream) {
                $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
                return $mimes;
            });
        }

        $attachment_id = media_handle_sideload($file_array, $item_id, null, $overrides);

        if (isset($mime_filter)) remove_filter('upload_mimes', $mime_filter);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        update_post_meta($attachment_id, '_oai_bitstream_url', $url);
        update_post_meta($attachment_id, '_oai_bitstream_bundle', $bitstream['bundle'] ?? 'ORIGINAL');
        if (!empty($bitstream['mime'])) {
            update_post_meta($attachment_id, '_oai_bitstream_mime', $bitstream['mime']);
        }
        return (int) $attachment_id;
    }

    private function guess_ext_from_mime(string $mime): string {
        $map = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'image/tiff' => '.tif',
            'application/pdf' => '.pdf',
        ];
        return $map[$mime] ?? '.bin';
    }

    /**
     * Appends a structured entry to the import's activity log.
     *
     * Format:  [YYYY-MM-DD HH:MM:SS] [LEVEL] [code] message
     * Levels:  INFO  — normal lifecycle (created, updated, skipped reasons…)
     *          WARN  — non-fatal anomaly (token unexpectedly empty, partial data)
     *          ERROR — failure (HTTP, parse, validation, mapping)
     *
     * Caps total log at 256 KB so verbose runs don't bloat the imports row.
     */
    public function append_log(int $import_id, string $level, string $code, string $message): void {
        global $wpdb;
        $level = strtoupper($level);
        if (!in_array($level, ['INFO', 'WARN', 'ERROR'], true)) $level = 'INFO';
        $entry = '[' . gmdate('Y-m-d H:i:s') . '] [' . $level . '] [' . $code . '] ' . $message . "\n";
        $current = (string) $wpdb->get_var($wpdb->prepare("SELECT error_log FROM {$this->table} WHERE id = %d", $import_id));
        $combined = $current . $entry;
        if (strlen($combined) > 262144) $combined = substr($combined, -262144);
        $wpdb->update($this->table, ['error_log' => $combined], ['id' => $import_id]);
    }

    /** Wipes the activity log for one import. */
    public function clear_log(int $import_id): bool {
        global $wpdb;
        return (bool) $wpdb->update($this->table, ['error_log' => null], ['id' => $import_id]);
    }

    /**
     * Back-compat thin wrapper. New code should call append_log() directly.
     * @deprecated use append_log()
     */
    private function append_error_log(int $import_id, string $code, string $message): void {
        $this->append_log($import_id, 'ERROR', $code, $message);
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
