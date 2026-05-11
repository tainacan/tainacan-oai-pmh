<?php
/**
 * Tabbed admin page shell — picks the right tab-*.php partial to include.
 *
 * Variable names are intentionally prefixed `oai_*` to avoid the WPCS
 * NonPrefixedVariableFound + GlobalVariablesOverride sniffs, which flag
 * generic identifiers like $tab / $tabs that collide with core globals.
 *
 * @package Tainacan_OAI_PMH
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$oai_current_tab = $data['tab'] ?? 'dashboard';
$oai_tabs        = array(
	'dashboard'  => array(
		'icon'  => 'dashicons-dashboard',
		'label' => __( 'Dashboard', 'tainacan-oai-pmh' ),
	),
	'importer'   => array(
		'icon'  => 'dashicons-download',
		'label' => __( 'Importer', 'tainacan-oai-pmh' ),
	),
	'harvest'    => array(
		'icon'  => 'dashicons-update',
		'label' => __( 'Scheduled Harvest', 'tainacan-oai-pmh' ),
	),
	'harvesters' => array(
		'icon'  => 'dashicons-networking',
		'label' => __( 'Harvesters', 'tainacan-oai-pmh' ),
	),
	'logs'       => array(
		'icon'  => 'dashicons-list-view',
		'label' => __( 'Logs', 'tainacan-oai-pmh' ),
	),
	'validation' => array(
		'icon'  => 'dashicons-yes-alt',
		'label' => __( 'Validation', 'tainacan-oai-pmh' ),
	),
);
?>
<div class="wrap tainacan-page-container-content">
	<!-- Fixed Subheader (Tainacan pattern) -->
	<div class="tainacan-fixed-subheader">
		<h1 class="tainacan-page-title">
			<?php esc_html_e( 'OAI-PMH', 'tainacan-oai-pmh' ); ?>
		</h1>
		<p class="tainacan-oai-subtitle">
			<?php esc_html_e( 'Share your collections and import from external repositories.', 'tainacan-oai-pmh' ); ?>
		</p>
	</div>

	<!-- Tab Navigation -->
	<nav class="tainacan-oai-tabs">
		<?php foreach ( $oai_tabs as $oai_slug => $oai_tab_def ) : ?>
			<a href="<?php echo esc_url( admin_url( "admin.php?page=tainacan_oai_pmh&tab=$oai_slug" ) ); ?>"
				class="tainacan-oai-tab <?php echo $oai_current_tab === $oai_slug ? 'active' : ''; ?>">
				<span class="dashicons <?php echo esc_attr( $oai_tab_def['icon'] ); ?>"></span>
				<span><?php echo esc_html( $oai_tab_def['label'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<!-- Content -->
	<div class="tainacan-oai-content">
		<?php
		switch ( $oai_current_tab ) {
			case 'importer':
				include __DIR__ . '/tab-importer.php';
				break;
			case 'harvest':
				include __DIR__ . '/tab-harvest.php';
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
