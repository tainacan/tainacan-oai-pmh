<?php
if (!defined('ABSPATH')) exit;
$collections = $data['collections'];
$imports = $data['imports'];
?>

<div class="oai-card">
    <div class="oai-card-header">
        <h2><span class="dashicons dashicons-download"></span> <?php esc_html_e('Import from OAI-PMH Repository', 'tainacan-oai-pmh'); ?></h2>
    </div>
    <div class="oai-card-body">
        <p class="oai-help-text"><?php esc_html_e('Import records from external OAI-PMH repositories like DSpace, EPrints, or other systems.', 'tainacan-oai-pmh'); ?></p>
        
        <div id="import-wizard">
            <!-- Step 1 -->
            <div class="import-step active" data-step="1">
                <h3><?php esc_html_e('Step 1: Connect to Repository', 'tainacan-oai-pmh'); ?></h3>
                <div class="oai-form-group">
                    <label for="source-url"><?php esc_html_e('OAI-PMH Endpoint URL', 'tainacan-oai-pmh'); ?></label>
                    <div class="oai-input-group">
                        <input type="url" id="source-url" class="regular-text" placeholder="https://repositorio.example.com/oai/request">
                        <button type="button" class="button button-primary" id="btn-fetch-repository"><?php esc_html_e('Connect', 'tainacan-oai-pmh'); ?></button>
                    </div>
                    <p class="description"><?php esc_html_e('Enter only the base URL, without ?verb= parameters.', 'tainacan-oai-pmh'); ?></p>
                </div>
                
                <div id="repository-info" style="display:none;">
                    <div class="oai-info-box">
                        <h4><?php esc_html_e('Connected Repository', 'tainacan-oai-pmh'); ?></h4>
                        <p><strong><?php esc_html_e('Name:', 'tainacan-oai-pmh'); ?></strong> <span id="repo-name"></span></p>
                        <p><strong><?php esc_html_e('Email:', 'tainacan-oai-pmh'); ?></strong> <span id="repo-email"></span></p>
                        <p><strong><?php esc_html_e('Earliest Date:', 'tainacan-oai-pmh'); ?></strong> <span id="repo-earliest"></span></p>
                    </div>
                </div>
            </div>
            
            <!-- Step 2 -->
            <div class="import-step" data-step="2" style="display:none;">
                <h3><?php esc_html_e('Step 2: Filter Records', 'tainacan-oai-pmh'); ?></h3>
                <div class="oai-form-group">
                    <label for="source-set"><?php esc_html_e('Set (Collection)', 'tainacan-oai-pmh'); ?></label>
                    <select id="source-set"><option value=""><?php esc_html_e('All sets', 'tainacan-oai-pmh'); ?></option></select>
                </div>
                <div class="oai-form-row">
                    <div class="oai-form-group">
                        <label for="from-date"><?php esc_html_e('From Date', 'tainacan-oai-pmh'); ?></label>
                        <input type="date" id="from-date">
                    </div>
                    <div class="oai-form-group">
                        <label for="until-date"><?php esc_html_e('Until Date', 'tainacan-oai-pmh'); ?></label>
                        <input type="date" id="until-date">
                    </div>
                </div>
                <button type="button" class="button" id="btn-preview-records"><?php esc_html_e('Preview Records', 'tainacan-oai-pmh'); ?></button>
                
                <div id="preview-results" style="display:none;">
                    <h4 id="preview-count"></h4>
                    <table class="oai-table" id="preview-table">
                        <thead><tr><th><?php esc_html_e('Identifier', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Title', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Date', 'tainacan-oai-pmh'); ?></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            
            <!-- Step 3 -->
            <div class="import-step" data-step="3" style="display:none;">
                <h3><?php esc_html_e('Step 3: Select Target Collection', 'tainacan-oai-pmh'); ?></h3>
                <div class="oai-form-group">
                    <label for="target-collection"><?php esc_html_e('Import to Collection', 'tainacan-oai-pmh'); ?></label>
                    <select id="target-collection" required>
                        <option value=""><?php esc_html_e('Select a collection...', 'tainacan-oai-pmh'); ?></option>
                        <?php foreach ($collections as $col): ?>
                            <option value="<?php echo esc_attr($col->get_id()); ?>"><?php echo esc_html($col->get_name()); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Step 4 -->
            <div class="import-step" data-step="4" style="display:none;">
                <h3><?php esc_html_e('Step 4: Map Metadata', 'tainacan-oai-pmh'); ?></h3>
                <p class="oai-help-text"><?php esc_html_e('Map Dublin Core fields to your collection metadata.', 'tainacan-oai-pmh'); ?></p>
                <table class="oai-table" id="mapping-table">
                    <thead><tr><th><?php esc_html_e('Source (DC)', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Sample', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Target Metadatum', 'tainacan-oai-pmh'); ?></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            
            <!-- Step 5 -->
            <div class="import-step" data-step="5" style="display:none;">
                <h3><?php esc_html_e('Step 5: Start Import', 'tainacan-oai-pmh'); ?></h3>
                <div class="oai-info-box">
                    <p><strong><?php esc_html_e('Source:', 'tainacan-oai-pmh'); ?></strong> <span id="summary-source"></span></p>
                    <p><strong><?php esc_html_e('Set:', 'tainacan-oai-pmh'); ?></strong> <span id="summary-set"></span></p>
                    <p><strong><?php esc_html_e('Collection:', 'tainacan-oai-pmh'); ?></strong> <span id="summary-collection"></span></p>
                    <p><strong><?php esc_html_e('Records:', 'tainacan-oai-pmh'); ?></strong> <span id="summary-count"></span></p>
                </div>
                
                <div id="import-progress" style="display:none;">
                    <div class="oai-progress-bar"><div class="oai-progress" id="import-progress-bar" style="width:0%"></div></div>
                    <p id="import-status"></p>
                </div>
                
                <button type="button" class="button button-primary button-hero" id="btn-start-import">
                    <span class="dashicons dashicons-download"></span> <?php esc_html_e('Start Import', 'tainacan-oai-pmh'); ?>
                </button>
            </div>
            
            <!-- Navigation -->
            <div class="import-nav">
                <button type="button" class="button" id="btn-prev-step" style="display:none;"><?php esc_html_e('← Previous', 'tainacan-oai-pmh'); ?></button>
                <button type="button" class="button button-primary" id="btn-next-step" style="display:none;"><?php esc_html_e('Next →', 'tainacan-oai-pmh'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($imports)): ?>
<div class="oai-card">
    <div class="oai-card-header">
        <h2><span class="dashicons dashicons-backup"></span> <?php esc_html_e('Import History', 'tainacan-oai-pmh'); ?></h2>
    </div>
    <div class="oai-card-body">
        <table class="oai-table">
            <thead><tr><th><?php esc_html_e('Source', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Collection', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Status', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Imported', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Date', 'tainacan-oai-pmh'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($imports as $import): 
                    $col = new \Tainacan\Entities\Collection($import->collection_id); ?>
                <tr>
                    <td><?php echo esc_html(wp_parse_url($import->source_url, PHP_URL_HOST)); ?></td>
                    <td><?php echo esc_html($col->get_name()); ?></td>
                    <td><span class="oai-badge oai-badge-<?php echo esc_attr($import->status); ?>"><?php echo esc_html(ucfirst($import->status)); ?></span></td>
                    <td><?php echo number_format_i18n($import->imported_records); ?> / <?php echo number_format_i18n($import->total_records); ?></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($import->created_at))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
