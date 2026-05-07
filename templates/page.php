<?php
if (!defined('ABSPATH')) exit;

$tab = $data['tab'] ?? 'dashboard';
$tabs = [
    'dashboard' => ['icon' => 'dashicons-dashboard', 'label' => __('Dashboard', 'tainacan-oai-pmh')],
    'importer' => ['icon' => 'dashicons-download', 'label' => __('Importer', 'tainacan-oai-pmh')],
    'harvesters' => ['icon' => 'dashicons-networking', 'label' => __('Harvesters', 'tainacan-oai-pmh')],
    'logs' => ['icon' => 'dashicons-list-view', 'label' => __('Logs', 'tainacan-oai-pmh')],
    'validation' => ['icon' => 'dashicons-yes-alt', 'label' => __('Validation', 'tainacan-oai-pmh')],
];
?>
<div class="wrap tainacan-page-container-content">
    <!-- Fixed Subheader (Tainacan pattern) -->
    <div class="tainacan-fixed-subheader">
        <h1 class="tainacan-page-title">
            <?php esc_html_e('OAI-PMH', 'tainacan-oai-pmh'); ?>
        </h1>
        <p class="tainacan-oai-subtitle">
            <?php esc_html_e('Share your collections and import from external repositories.', 'tainacan-oai-pmh'); ?>
        </p>
    </div>
    
    <!-- Tab Navigation -->
    <nav class="tainacan-oai-tabs">
        <?php foreach ($tabs as $slug => $t): ?>
            <a href="<?php echo esc_url(admin_url("admin.php?page=tainacan_oai_pmh&tab=$slug")); ?>" 
               class="tainacan-oai-tab <?php echo $tab === $slug ? 'active' : ''; ?>">
                <span class="dashicons <?php echo esc_attr($t['icon']); ?>"></span>
                <span><?php echo esc_html($t['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    
    <!-- Content -->
    <div class="tainacan-oai-content">
        <?php
        switch ($tab) {
            case 'importer':
                include __DIR__ . '/tab-importer.php';
                break;
            case 'harvesters':
                include __DIR__ . '/tab-harvesters.php';
                break;
            case 'logs':
                include __DIR__ . '/tab-logs.php';
                break;
            case 'validation':
                include __DIR__ . '/tab-validation.php';
                break;
            default:
                include __DIR__ . '/tab-dashboard.php';
                break;
        }
        ?>
    </div>
</div>
