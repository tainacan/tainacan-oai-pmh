<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */
if (!defined('ABSPATH')) exit;
$harvesters = $data['harvesters'];
$stats = $data['harvester_stats'];
$blocked = $data['blocked_ips'];
?>

<div class="oai-stats-grid">
    <div class="oai-stat-card">
        <div class="oai-stat-icon"><span class="dashicons dashicons-groups"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo esc_html(number_format_i18n($stats['total'])); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Total Harvesters', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
    <div class="oai-stat-card">
        <div class="oai-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo esc_html(number_format_i18n($stats['active'])); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Active', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
    <div class="oai-stat-card">
        <div class="oai-stat-icon"><span class="dashicons dashicons-clock"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo esc_html(number_format_i18n($stats['last_24h'])); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Last 24h', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
    <div class="oai-stat-card">
        <div class="oai-stat-icon"><span class="dashicons dashicons-chart-bar"></span></div>
        <div class="oai-stat-content">
            <div class="oai-stat-number"><?php echo esc_html(number_format_i18n($stats['total_requests'])); ?></div>
            <div class="oai-stat-label"><?php esc_html_e('Total Requests', 'tainacan-oai-pmh'); ?></div>
        </div>
    </div>
</div>

<?php if ($blocked): ?>
<div class="oai-card">
    <div class="oai-card-header">
        <h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e('Blocked IPs', 'tainacan-oai-pmh'); ?></h2>
    </div>
    <div class="oai-card-body">
        <table class="oai-table">
            <thead><tr><th><?php esc_html_e('IP Address', 'tainacan-oai-pmh'); ?></th><th><?php esc_html_e('Blocked Until', 'tainacan-oai-pmh'); ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($blocked as $b): ?>
                <tr>
                    <td><code><?php echo esc_html($b->ip_address); ?></code></td>
                    <td><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($b->blocked_until))); ?></td>
                    <td><button type="button" class="button button-small btn-unblock-ip" data-ip="<?php echo esc_attr($b->ip_address); ?>"><?php esc_html_e('Unblock', 'tainacan-oai-pmh'); ?></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="oai-card">
    <div class="oai-card-header">
        <h2><span class="dashicons dashicons-networking"></span> <?php esc_html_e('Harvester List', 'tainacan-oai-pmh'); ?></h2>
    </div>
    <div class="oai-card-body">
        <?php if ($harvesters): ?>
        <table class="oai-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('IP / Hostname', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('User Agent', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('First Seen', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Last Seen', 'tainacan-oai-pmh'); ?></th>
                    <th><?php esc_html_e('Requests', 'tainacan-oai-pmh'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($harvesters as $h): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($h->ip_address); ?></strong>
                        <?php if ($h->hostname && $h->hostname !== $h->ip_address): ?>
                        <br><small><?php echo esc_html($h->hostname); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo esc_html(substr($h->user_agent, 0, 60)); ?></small></td>
                    <td><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($h->first_seen))); ?></td>
                    <td><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($h->last_seen))); ?></td>
                    <td><strong><?php echo esc_html(number_format_i18n($h->total_requests)); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="oai-empty"><?php esc_html_e('No harvesters detected yet. Harvesters will appear here when they access your OAI-PMH endpoint.', 'tainacan-oai-pmh'); ?></p>
        <?php endif; ?>
    </div>
</div>
