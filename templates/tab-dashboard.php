<?php
if (!defined('ABSPATH')) exit;

$cache_stats = $data['cache_stats'];
$log_stats = $data['log_stats'];
$harvester_stats = $data['harvester_stats'];
$index_health = $data['index_health'];
$collection_stats = $data['collection_stats'];
$daily_stats = $data['daily_stats'];
$base_url = $data['base_url'];
?>

<!-- Endpoint Card -->
<div class="oai-card oai-card-highlight">
    <div class="oai-card-header">
        <h2><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e('Your OAI-PMH Endpoint', 'tainacan-oai-pmh'); ?></h2>
    </div>
    <div class="oai-card-body">
        <p class="oai-help-text"><?php esc_html_e('Share this URL with aggregators like OASISBR, BASE, or others to make your collection discoverable.', 'tainacan-oai-pmh'); ?></p>
        <div class="oai-endpoint-box">
            <code id="endpoint-url"><?php echo esc_html($base_url); ?></code>
            <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($base_url); ?>'); this.innerHTML='<?php echo esc_js(__('Copied!', 'tainacan-oai-pmh')); ?>'; setTimeout(() => this.innerHTML='<?php echo esc_js(__('Copy', 'tainacan-oai-pmh')); ?>', 2000);">
                <?php esc_html_e('Copy', 'tainacan-oai-pmh'); ?>
            </button>
            <button type="button" class="button button-primary" id="btn-test-endpoint">
                <?php esc_html_e('Test Endpoint', 'tainacan-oai-pmh'); ?>
            </button>
        </div>
        <div id="endpoint-result"></div>
        
        <div class="oai-quick-links">
            <span><?php esc_html_e('Quick tests:', 'tainacan-oai-pmh'); ?></span>
            <a href="<?php echo esc_url($base_url . '?verb=Identify'); ?>" target="_blank">Identify</a>
            <a href="<?php echo esc_url($base_url . '?verb=ListSets'); ?>" target="_blank">ListSets</a>
            <a href="<?php echo esc_url($base_url . '?verb=ListRecords&metadataPrefix=oai_dc'); ?>" target="_blank">ListRecords</a>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="oai-stats-grid">
    <div class="oai-stat-card">
        <div class="oai-stat-icon"><span class="dashicons dashicons-database"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo number_format_i18n($cache_stats['total_items']); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Indexed Items', 'tainacan-oai-pmh'); ?></div>
            <div class="oai-stat-sub"><?php echo number_format_i18n($cache_stats['published_items']); ?> <?php esc_html_e('published', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
    
    <div class="oai-stat-card">
        <div class="oai-stat-icon"><span class="dashicons dashicons-chart-bar"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo number_format_i18n($log_stats['total_requests']); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Requests (24h)', 'tainacan-oai-pmh'); ?></div>
            <div class="oai-stat-sub"><?php echo $log_stats['avg_response_time']; ?>s <?php esc_html_e('avg', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
    
    <div class="oai-stat-card <?php echo $log_stats['errors'] > 0 ? 'warning' : ''; ?>">
        <div class="oai-stat-icon"><span class="dashicons dashicons-warning"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo number_format_i18n($log_stats['errors']); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Errors (24h)', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
    
    <div class="oai-stat-card">
        <div class="oai-stat-icon"><span class="dashicons dashicons-groups"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo number_format_i18n($harvester_stats['total']); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Harvesters', 'tainacan-oai-pmh'); ?></div>
            <div class="oai-stat-sub"><?php echo number_format_i18n($harvester_stats['last_24h']); ?> <?php esc_html_e('active today', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
</div>

<div class="oai-grid-2">
    <!-- Index Health -->
    <div class="oai-card">
        <div class="oai-card-header">
            <h2><span class="dashicons dashicons-heart"></span> <?php esc_html_e('Index Health', 'tainacan-oai-pmh'); ?></h2>
        </div>
        <div class="oai-card-body">
            <div class="oai-health-status <?php echo esc_attr($index_health['status']); ?>">
                <span class="dashicons dashicons-<?php echo $index_health['status'] === 'healthy' ? 'yes-alt' : 'warning'; ?>"></span>
                <div>
                    <strong>
                        <?php
                        if ($index_health['status'] === 'healthy') esc_html_e('Healthy', 'tainacan-oai-pmh');
                        elseif ($index_health['status'] === 'warning') esc_html_e('Needs Attention', 'tainacan-oai-pmh');
                        else esc_html_e('Needs Reindex', 'tainacan-oai-pmh');
                        ?>
                    </strong>
                    <p><?php printf(esc_html__('%1$d of %2$d items indexed (%3$s%%)', 'tainacan-oai-pmh'), $index_health['cached_items'], $index_health['wp_items'], $index_health['sync_percentage']); ?></p>
                </div>
            </div>
            
            <div class="oai-progress-bar">
                <div class="oai-progress <?php echo esc_attr($index_health['status']); ?>" style="width: <?php echo esc_attr($index_health['sync_percentage']); ?>%"></div>
            </div>
            
            <p class="oai-meta">
                <?php esc_html_e('Last indexed:', 'tainacan-oai-pmh'); ?> 
                <?php echo $cache_stats['last_indexed'] ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($cache_stats['last_indexed']))) : esc_html__('Never', 'tainacan-oai-pmh'); ?>
            </p>
            
            <div class="oai-actions">
                <button type="button" class="button" id="btn-clear-cache"><?php esc_html_e('Clear Cache', 'tainacan-oai-pmh'); ?></button>
                <button type="button" class="button button-primary" id="btn-reindex"><?php esc_html_e('Reindex All', 'tainacan-oai-pmh'); ?></button>
            </div>
        </div>
    </div>
    
    <!-- Collections -->
    <div class="oai-card">
        <div class="oai-card-header">
            <h2><span class="dashicons dashicons-category"></span> <?php esc_html_e('Collections', 'tainacan-oai-pmh'); ?></h2>
        </div>
        <div class="oai-card-body">
            <?php if ($collection_stats): ?>
                <table class="oai-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Collection', 'tainacan-oai-pmh'); ?></th>
                            <th><?php esc_html_e('Items', 'tainacan-oai-pmh'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($collection_stats as $col): ?>
                        <tr>
                            <td><?php echo esc_html($col['name']); ?></td>
                            <td><?php echo number_format_i18n($col['count']); ?></td>
                            <td>
                                <button type="button" class="button button-small btn-reindex-collection" data-collection="<?php echo esc_attr($col['id']); ?>">
                                    <?php esc_html_e('Reindex', 'tainacan-oai-pmh'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="oai-empty"><?php esc_html_e('No collections indexed yet.', 'tainacan-oai-pmh'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Activity Chart -->
<div class="oai-card">
    <div class="oai-card-header">
        <h2><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e('Activity (14 days)', 'tainacan-oai-pmh'); ?></h2>
    </div>
    <div class="oai-card-body">
        <div class="oai-chart-container">
            <canvas id="activity-chart" data-stats='<?php echo esc_attr(wp_json_encode($daily_stats)); ?>'></canvas>
        </div>
    </div>
</div>
