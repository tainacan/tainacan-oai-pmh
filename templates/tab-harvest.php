<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */
if (!defined('ABSPATH')) exit;

$collections = $data['collections'] ?? [];
$sources = $data['harvest_sources'] ?? [];

$schedule_labels = [
    'hourly' => __('Hourly', 'tainacan-oai-pmh'),
    'twicedaily' => __('Twice daily', 'tainacan-oai-pmh'),
    'daily' => __('Daily', 'tainacan-oai-pmh'),
    'weekly' => __('Weekly', 'tainacan-oai-pmh'),
    'paused' => __('Paused', 'tainacan-oai-pmh'),
];
?>

<div class="oai-card">
    <div class="oai-card-header">
        <h2><span class="dashicons dashicons-update"></span> <?php esc_html_e('Scheduled Harvest Sources', 'tainacan-oai-pmh'); ?></h2>
        <button type="button" class="button button-primary" id="btn-new-harvest-source">
            <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('New Source', 'tainacan-oai-pmh'); ?>
        </button>
    </div>
    <div class="oai-card-body">
        <p class="oai-help-text">
            <?php esc_html_e('Save remote OAI-PMH endpoints to be harvested automatically on a recurring schedule. Each run is incremental — only records modified since the last successful harvest are fetched. New items are inserted, modified items are updated, and records flagged as deleted upstream are moved to Trash.', 'tainacan-oai-pmh'); ?>
        </p>

        <table class="oai-table" id="harvest-sources-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Label', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Source', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Collection', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Schedule', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Last run', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Next run', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Counters', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Actions', 'tainacan-oai-pmh'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sources)): ?>
                <tr><td colspan="8" style="text-align:center; padding:24px; color:#666;">
                    <?php esc_html_e('No harvest sources yet. Click "New Source" above to create one.', 'tainacan-oai-pmh'); ?>
                </td></tr>
            <?php else: foreach ($sources as $s):
                $col = new \Tainacan\Entities\Collection((int) $s->collection_id);
                $col_name = $col->get_id() ? $col->get_name() : '—';
                $sched_label = $schedule_labels[$s->schedule] ?? $s->schedule;
                if (!$s->is_active) $sched_label .= ' (' . esc_html__('inactive', 'tainacan-oai-pmh') . ')';

                $last_run = $s->last_run_at
                    ? date_i18n(get_option('date_format') . ' H:i', strtotime($s->last_run_at . ' UTC'))
                    : '—';
                $next_run = !empty($s->next_run_ts)
                    ? date_i18n(get_option('date_format') . ' H:i', $s->next_run_ts)
                    : '—';
            ?>
                <tr data-source-id="<?php echo esc_attr($s->id); ?>" class="oai-source-row oai-source-<?php echo esc_attr($s->last_run_status); ?>">
                    <td><strong><?php echo esc_html($s->label); ?></strong></td>
                    <td><small><?php echo esc_html(wp_parse_url($s->source_url, PHP_URL_HOST)); ?></small></td>
                    <td><?php echo esc_html($col_name); ?></td>
                    <td><?php echo esc_html($sched_label); ?></td>
                    <td>
                        <?php echo esc_html($last_run); ?>
                        <?php if ($s->last_run_status === 'error'): ?>
                            <br><span class="oai-badge oai-badge-error"><?php esc_html_e('error', 'tainacan-oai-pmh'); ?></span>
                        <?php elseif ($s->last_run_status === 'success'): ?>
                            <br><span class="oai-badge oai-badge-completed"><?php esc_html_e('ok', 'tainacan-oai-pmh'); ?></span>
                        <?php elseif ($s->last_run_status === 'running'): ?>
                            <br><span class="oai-badge oai-badge-processing"><?php esc_html_e('running', 'tainacan-oai-pmh'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($next_run); ?></td>
                    <td>
                        <small>
                            <?php
                            /* translators: 1: items created, 2: items updated, 3: items deleted */
                            echo esc_html(sprintf(
                                __('+%1$d / ~%2$d / -%3$d', 'tainacan-oai-pmh'),
                                (int) $s->items_created, (int) $s->items_updated, (int) $s->items_deleted
                            ));
                            ?>
                            <?php if ((int) $s->items_failed > 0): ?>
                                <br><span style="color:#d63638;"><?php
                                    /* translators: %d: number of failed items */
                                    echo esc_html(sprintf(__('%d failed', 'tainacan-oai-pmh'), (int) $s->items_failed));
                                ?></span>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td class="oai-row-actions">
                        <button type="button" class="button button-small btn-run-harvest" title="<?php esc_attr_e('Run now', 'tainacan-oai-pmh'); ?>">
                            <span class="dashicons dashicons-controls-play"></span>
                        </button>
                        <button type="button" class="button button-small btn-toggle-harvest" title="<?php esc_attr_e('Pause/Resume', 'tainacan-oai-pmh'); ?>">
                            <span class="dashicons dashicons-<?php echo $s->is_active ? 'controls-pause' : 'controls-play'; ?>"></span>
                        </button>
                        <button type="button" class="button button-small btn-edit-harvest" title="<?php esc_attr_e('Edit', 'tainacan-oai-pmh'); ?>">
                            <span class="dashicons dashicons-edit"></span>
                        </button>
                        <button type="button" class="button button-small btn-delete-harvest" title="<?php esc_attr_e('Delete', 'tainacan-oai-pmh'); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
                <?php if (!empty($s->error_log)):
                    $log_lines = array_slice(array_filter(explode("\n", $s->error_log)), -50);
                ?>
                <tr class="oai-log-row oai-harvest-log-row" id="harvest-log-<?php echo esc_attr($s->id); ?>" style="display:none;">
                    <td colspan="8">
                        <div class="oai-log-toolbar">
                            <strong><?php esc_html_e('Activity log (last 50 entries):', 'tainacan-oai-pmh'); ?></strong>
                            <span class="oai-log-actions">
                                <button type="button" class="button button-small oai-load-full-harvest-log" data-source-id="<?php echo esc_attr($s->id); ?>">
                                    <?php esc_html_e('Show full log', 'tainacan-oai-pmh'); ?>
                                </button>
                                <button type="button" class="button button-small button-link-delete oai-clear-harvest-log" data-source-id="<?php echo esc_attr($s->id); ?>">
                                    <?php esc_html_e('Clear log', 'tainacan-oai-pmh'); ?>
                                </button>
                            </span>
                        </div>
                        <div class="oai-log-pane" id="harvest-log-pane-<?php echo esc_attr($s->id); ?>">
                            <?php foreach ($log_lines as $line):
                                $cls = 'oai-log-info';
                                if (strpos($line, '[ERROR]') !== false) $cls = 'oai-log-error';
                                elseif (strpos($line, '[WARN]') !== false) $cls = 'oai-log-warn';
                            ?>
                                <div class="oai-log-line <?php echo esc_attr($cls); ?>"><?php echo esc_html($line); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if (!empty($sources)): ?>
            <p style="margin-top:12px;">
                <a href="#" id="toggle-all-harvest-errors" class="button button-small">
                    <?php esc_html_e('Show/Hide all error logs', 'tainacan-oai-pmh'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Create/Edit harvest source -->
<div id="harvest-source-modal" class="oai-modal" style="display:none;">
    <div class="oai-modal-backdrop"></div>
    <div class="oai-modal-content">
        <h2 id="harvest-modal-title"><?php esc_html_e('New Harvest Source', 'tainacan-oai-pmh'); ?></h2>
        <input type="hidden" id="harvest-id" value="">

        <div class="oai-form-group">
            <label for="harvest-label"><?php esc_html_e('Label', 'tainacan-oai-pmh'); ?> *</label>
            <input type="text" id="harvest-label" class="regular-text" placeholder="<?php esc_attr_e('e.g. DAMI Museu Imperial', 'tainacan-oai-pmh'); ?>">
        </div>

        <div class="oai-form-group">
            <label for="harvest-url"><?php esc_html_e('OAI-PMH Endpoint URL', 'tainacan-oai-pmh'); ?> *</label>
            <input type="url" id="harvest-url" class="regular-text" placeholder="https://example.org/oai/request">
            <p class="description"><?php esc_html_e('Base URL only — no ?verb= parameters.', 'tainacan-oai-pmh'); ?></p>
        </div>

        <div class="oai-form-row">
            <div class="oai-form-group">
                <label for="harvest-collection"><?php esc_html_e('Target Collection', 'tainacan-oai-pmh'); ?> *</label>
                <select id="harvest-collection">
                    <option value=""><?php esc_html_e('Select…', 'tainacan-oai-pmh'); ?></option>
                    <?php foreach ($collections as $col): ?>
                        <option value="<?php echo esc_attr($col->get_id()); ?>"><?php echo esc_html($col->get_name()); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="oai-form-group">
                <label for="harvest-set"><?php esc_html_e('Set (optional)', 'tainacan-oai-pmh'); ?></label>
                <input type="text" id="harvest-set" placeholder="<?php esc_attr_e('setSpec from upstream', 'tainacan-oai-pmh'); ?>">
            </div>
        </div>

        <div class="oai-form-group">
            <label for="harvest-schedule"><?php esc_html_e('Schedule', 'tainacan-oai-pmh'); ?></label>
            <select id="harvest-schedule">
                <option value="hourly"><?php echo esc_html($schedule_labels['hourly']); ?></option>
                <option value="twicedaily"><?php echo esc_html($schedule_labels['twicedaily']); ?></option>
                <option value="daily" selected><?php echo esc_html($schedule_labels['daily']); ?></option>
                <option value="weekly"><?php echo esc_html($schedule_labels['weekly']); ?></option>
                <option value="paused"><?php echo esc_html($schedule_labels['paused']); ?></option>
            </select>
        </div>

        <div class="oai-form-group">
            <label><input type="checkbox" id="harvest-active" checked> <?php esc_html_e('Active', 'tainacan-oai-pmh'); ?></label>
            <label style="margin-left:16px;"><input type="checkbox" id="harvest-bitstreams" checked> <?php esc_html_e('Download bitstreams (DSpace ORE)', 'tainacan-oai-pmh'); ?></label>
        </div>

        <div class="oai-form-group">
            <label><?php esc_html_e('Metadata Mapping (DC → Tainacan metadatum)', 'tainacan-oai-pmh'); ?></label>
            <p class="description">
                <?php esc_html_e('Pick the target collection above and we will fetch the available metadata.', 'tainacan-oai-pmh'); ?>
            </p>
            <table class="oai-table" id="harvest-mapping-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('DC field', 'tainacan-oai-pmh'); ?></th>
                        <th><?php esc_html_e('Target metadatum', 'tainacan-oai-pmh'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="2" style="text-align:center;color:#999;"><?php esc_html_e('Select a collection to load mapping options.', 'tainacan-oai-pmh'); ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="oai-modal-actions">
            <button type="button" class="button" id="btn-cancel-harvest"><?php esc_html_e('Cancel', 'tainacan-oai-pmh'); ?></button>
            <button type="button" class="button button-primary" id="btn-save-harvest"><?php esc_html_e('Save', 'tainacan-oai-pmh'); ?></button>
        </div>
    </div>
</div>
