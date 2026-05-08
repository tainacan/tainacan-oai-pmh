<?php
/**
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

/**
 * Persistent scheduled OAI-PMH harvest sources.
 *
 * Each "source" is a saved configuration (URL + collection + mapping + cadence)
 * that gets re-run by WP-Cron at the chosen interval. Each run does an
 * incremental harvest using the OAI `from` parameter set to the most recent
 * datestamp seen, then diffs every record it gets back against the local items:
 * create / update / skip / trash.
 */
class Harvester {

    /** WP cron hook fired per source (single arg: source_id). */
    public const CRON_HOOK = 'tainacan_oai_run_source';

    /** Allowed schedule keys. Maps to wp_schedule_event recurrence names. */
    public const SCHEDULES = ['hourly', 'twicedaily', 'daily', 'weekly'];

    private string $table;
    private Importer $importer;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_sources';
        $this->importer = new Importer();
    }

    public static function register_hooks(): void {
        add_filter('cron_schedules', [self::class, 'add_weekly_schedule']);
        add_action(self::CRON_HOOK, [self::class, 'run_via_cron'], 10, 1);
    }

    public static function add_weekly_schedule(array $schedules): array {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => 7 * 86400,
                'display' => __('Once Weekly', 'tainacan-oai-pmh'),
            ];
        }
        return $schedules;
    }

    public static function run_via_cron(int $source_id): void {
        $h = new self();
        $h->run($source_id);
    }

    /**
     * Formats one log line in the canonical "[ts] [LEVEL] [code] message" form
     * shared with the Importer's activity log. Keeps log parsing simple in the UI.
     */
    private function log_line(string $level, string $code, string $message): string {
        $level = strtoupper($level);
        if (!in_array($level, ['INFO', 'WARN', 'ERROR'], true)) $level = 'INFO';
        return '[' . gmdate('Y-m-d H:i:s') . '] [' . $level . '] [' . $code . '] ' . $message;
    }

    // ---------- CRUD ----------

    public function create(array $args) {
        global $wpdb;

        $clean = $this->sanitize_input($args);
        if (is_wp_error($clean)) return $clean;

        $now = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->insert($this->table, array_merge($clean, [
            'last_run_status' => 'never',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        if ($ok === false) return new \WP_Error('db_error', $wpdb->last_error ?: 'Insert failed.');

        $id = (int) $wpdb->insert_id;
        if (!empty($clean['is_active']) && $clean['schedule'] !== 'paused') {
            $this->schedule($id);
        }
        return $id;
    }

    public function update(int $id, array $args) {
        global $wpdb;

        $existing = $this->get($id);
        if (!$existing) return new \WP_Error('not_found', 'Source not found.');

        $clean = $this->sanitize_input($args, $existing);
        if (is_wp_error($clean)) return $clean;

        $clean['updated_at'] = gmdate('Y-m-d H:i:s');
        $ok = $wpdb->update($this->table, $clean, ['id' => $id]);
        if ($ok === false) return new \WP_Error('db_error', $wpdb->last_error ?: 'Update failed.');

        // Reflect schedule/active changes in cron
        $this->unschedule($id);
        if (!empty($clean['is_active']) && $clean['schedule'] !== 'paused') {
            $this->schedule($id);
        }
        return true;
    }

    public function delete(int $id): bool {
        global $wpdb;
        $this->unschedule($id);
        return (bool) $wpdb->delete($this->table, ['id' => $id]);
    }

    /** Wipes the activity log for a single source. */
    public function clear_log(int $id): bool {
        global $wpdb;
        return (bool) $wpdb->update($this->table, ['error_log' => null], ['id' => $id]);
    }

    /** Wipes the activity log for every source. */
    public function clear_all_logs(): int {
        global $wpdb;
        return (int) $wpdb->query("UPDATE {$this->table} SET error_log = NULL");
    }

    public function get(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
    }

    public function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY label ASC");
        return is_array($rows) ? $rows : [];
    }

    private function sanitize_input(array $args, $existing = null) {
        $label = sanitize_text_field($args['label'] ?? ($existing->label ?? ''));
        $url = esc_url_raw($args['source_url'] ?? ($existing->source_url ?? ''));
        $collection_id = (int) ($args['collection_id'] ?? ($existing->collection_id ?? 0));
        $set_spec = sanitize_text_field($args['set_spec'] ?? ($existing->set_spec ?? ''));
        $schedule = $args['schedule'] ?? ($existing->schedule ?? 'daily');
        $is_active = isset($args['is_active']) ? (int) (bool) $args['is_active'] : (int) ($existing->is_active ?? 1);
        $download = isset($args['download_bitstreams']) ? (int) (bool) $args['download_bitstreams'] : (int) ($existing->download_bitstreams ?? 1);

        if ($label === '') return new \WP_Error('missing_label', __('Label is required.', 'tainacan-oai-pmh'));
        if ($url === '') return new \WP_Error('missing_url', __('Source URL is required.', 'tainacan-oai-pmh'));
        if ($collection_id <= 0) return new \WP_Error('missing_collection', __('Collection is required.', 'tainacan-oai-pmh'));
        if (!in_array($schedule, array_merge(self::SCHEDULES, ['paused']), true)) {
            return new \WP_Error('bad_schedule', __('Invalid schedule.', 'tainacan-oai-pmh'));
        }

        $mapping = $args['metadata_mapping'] ?? null;
        if (is_string($mapping)) $mapping = json_decode($mapping, true);
        if (!is_array($mapping)) {
            $mapping = $existing ? maybe_unserialize($existing->metadata_mapping) : [];
            if (!is_array($mapping)) $mapping = [];
        }

        return [
            'label' => $label,
            'source_url' => $url,
            'collection_id' => $collection_id,
            'set_spec' => $set_spec,
            'metadata_mapping' => maybe_serialize($mapping),
            'schedule' => $schedule,
            'is_active' => $is_active,
            'download_bitstreams' => $download,
        ];
    }

    // ---------- Cron ----------

    /**
     * Schedule a recurring run for this source. Idempotent: clears any existing
     * scheduling first so changing the cadence picks up immediately.
     */
    public function schedule(int $id): void {
        $source = $this->get($id);
        if (!$source || $source->schedule === 'paused' || empty($source->is_active)) return;
        if (!in_array($source->schedule, self::SCHEDULES, true)) return;

        wp_clear_scheduled_hook(self::CRON_HOOK, [$id]);
        // First run is shifted slightly into the future so an admin saving
        // a source and immediately clicking Run-Now doesn't double-fire.
        wp_schedule_event(time() + 60, $source->schedule, self::CRON_HOOK, [$id]);
    }

    public function unschedule(int $id): void {
        wp_clear_scheduled_hook(self::CRON_HOOK, [$id]);
    }

    public function next_scheduled(int $id): ?int {
        $ts = wp_next_scheduled(self::CRON_HOOK, [$id]);
        return $ts ?: null;
    }

    public static function unschedule_all(): void {
        // Used at deactivation. Walk the cron table and clear all our hooks.
        $cron = _get_cron_array();
        if (!is_array($cron)) return;
        foreach ($cron as $timestamp => $hooks) {
            if (!isset($hooks[self::CRON_HOOK])) continue;
            foreach ($hooks[self::CRON_HOOK] as $event) {
                wp_unschedule_event($timestamp, self::CRON_HOOK, $event['args'] ?? []);
            }
        }
    }

    // ---------- Run ----------

    /**
     * Executes one harvest pass for the given source.
     * Uses incremental `from` based on the source's last_datestamp.
     */
    public function run(int $id) {
        global $wpdb;
        $source = $this->get($id);
        if (!$source) return new \WP_Error('not_found', 'Source not found.');

        $start = microtime(true);
        $wpdb->update($this->table, [
            'last_run_status' => 'running',
            'last_run_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);

        $config = [
            'source_url' => $source->source_url,
            'set_spec' => (string) ($source->set_spec ?? ''),
            'collection_id' => (int) $source->collection_id,
            'metadata_mapping' => maybe_unserialize($source->metadata_mapping) ?: [],
            'download_bitstreams' => (bool) $source->download_bitstreams,
            'from' => $source->last_datestamp ? (string) $source->last_datestamp : '',
        ];

        try {
            $stats = $this->importer->harvest_loop($config);
        } catch (\Throwable $e) {
            $stats = ['created'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'deleted'=>0,
                      'pages'=>0,'last_datestamp'=>null,'errors'=>[$e->getMessage()]];
        }

        $duration = round(microtime(true) - $start, 2);
        $had_errors = !empty($stats['errors']);
        $status = $had_errors && ($stats['created'] + $stats['updated']) === 0 ? 'error' : 'success';

        $msg = sprintf(
            'Pages: %d | Created: %d | Updated: %d | Skipped: %d | Failed: %d | Deleted: %d | %.2fs',
            $stats['pages'], $stats['created'], $stats['updated'], $stats['skipped'],
            $stats['failed'], $stats['deleted'], $duration
        );

        // Build a structured activity log: lifecycle events + (optional) error tail
        $log_lines = [];
        $log_lines[] = $this->log_line('INFO', 'run.start',
            sprintf('Started harvest %s (from=%s, set=%s, collection=%d)',
                $source->label, $config['from'] ?: '(full)', $config['set_spec'] ?: '(all)', (int) $source->collection_id));
        $log_lines[] = $this->log_line('INFO', 'run.fetch',
            'Pages fetched: ' . $stats['pages'] . ' (cap 200)');
        $log_lines[] = $this->log_line('INFO', 'run.diff',
            sprintf('created=%d, updated=%d, skipped=%d, failed=%d, deleted=%d',
                $stats['created'], $stats['updated'], $stats['skipped'], $stats['failed'], $stats['deleted']));
        if (!empty($stats['last_datestamp'])) {
            $log_lines[] = $this->log_line('INFO', 'run.watermark',
                'New incremental watermark: ' . $stats['last_datestamp']);
        }
        foreach (array_slice($stats['errors'], -20) as $err) {
            $log_lines[] = $this->log_line('ERROR', 'harvest', $err);
        }
        $log_lines[] = $this->log_line('INFO', 'run.end', $msg);

        // Append to existing log (preserve history across runs), capped at 256 KB
        $previous = (string) ($source->error_log ?? '');
        $combined = $previous . implode("\n", $log_lines) . "\n";
        if (strlen($combined) > 262144) $combined = substr($combined, -262144);
        $error_log = $combined;

        $update = [
            'last_run_status' => $status,
            'last_run_message' => $msg,
            'error_log' => $error_log,
            'items_created' => $source->items_created + $stats['created'],
            'items_updated' => $source->items_updated + $stats['updated'],
            'items_skipped' => $source->items_skipped + $stats['skipped'],
            'items_failed' => $source->items_failed + $stats['failed'],
            'items_deleted' => $source->items_deleted + $stats['deleted'],
        ];

        if ($status === 'success') {
            $update['last_success_at'] = gmdate('Y-m-d H:i:s');
            // Advance the watermark so the next run only fetches newer changes
            if (!empty($stats['last_datestamp'])) {
                $update['last_datestamp'] = $stats['last_datestamp'];
            }
        }

        $wpdb->update($this->table, $update, ['id' => $id]);
        return $stats;
    }
}
