<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$logger = new \Tainacan_OAI_PMH\Logger();
$logs   = $logger->get_logs( array( 'limit' => 100 ) );
?>

<div class="oai-card">
	<div class="oai-card-header" style="display:flex; justify-content:space-between; align-items:center;">
		<h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Request Logs', 'tainacan-oai-pmh' ); ?></h2>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=tainacan_oai_export_logs' ), 'tainacan_oai_nonce', 'nonce' ) ); ?>" class="button">
			<span class="dashicons dashicons-download" style="vertical-align:middle;"></span>
			<?php esc_html_e( 'Export CSV', 'tainacan-oai-pmh' ); ?>
		</a>
	</div>
	<div class="oai-card-body">
		<?php if ( $logs ) : ?>
		<table class="oai-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'tainacan-oai-pmh' ); ?></th>
					<th><?php esc_html_e( 'Level', 'tainacan-oai-pmh' ); ?></th>
					<th><?php esc_html_e( 'Verb', 'tainacan-oai-pmh' ); ?></th>
					<th><?php esc_html_e( 'Message', 'tainacan-oai-pmh' ); ?></th>
					<th><?php esc_html_e( 'IP', 'tainacan-oai-pmh' ); ?></th>
					<th><?php esc_html_e( 'Time', 'tainacan-oai-pmh' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $log->created_at ) ) ); ?></td>
					<td><span class="oai-badge oai-badge-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( $log->level ); ?></span></td>
					<td><?php echo esc_html( $log->verb ?: '-' ); ?></td>
					<td><?php echo esc_html( $log->message ); ?></td>
					<td><code><?php echo esc_html( $log->ip_address ); ?></code></td>
					<td><?php echo $log->response_time ? esc_html( $log->response_time ) . 's' : '-'; ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="oai-empty"><?php esc_html_e( 'No logs yet. Logs will appear here when requests are made to your OAI-PMH endpoint.', 'tainacan-oai-pmh' ); ?></p>
		<?php endif; ?>
	</div>
</div>
