<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$validation = $data['last_validation'];
?>

<div class="oai-card">
	<div class="oai-card-header" style="display:flex; justify-content:space-between; align-items:center;">
		<h2><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'OAI-PMH Validation', 'tainacan-oai-pmh' ); ?></h2>
		<button type="button" class="button button-primary" id="btn-validate">
			<span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
			<?php esc_html_e( 'Run Validation', 'tainacan-oai-pmh' ); ?>
		</button>
	</div>
	<div class="oai-card-body">
		<p class="oai-help-text"><?php esc_html_e( 'Validate your OAI-PMH endpoint against the protocol specification before registering with aggregators.', 'tainacan-oai-pmh' ); ?></p>
		
		<div id="validation-results">
			<?php if ( $validation ) : ?>
				<div class="oai-score">
					<div class="oai-score-circle <?php echo $validation['score'] >= 80 ? 'good' : ( $validation['score'] >= 50 ? 'warning' : 'bad' ); ?>">
						<span class="oai-score-value"><?php echo esc_html( $validation['score'] ); ?>%</span>
					</div>
					<div class="oai-score-summary">
						<span class="passed"><?php echo esc_html( $validation['passed'] ); ?> <?php esc_html_e( 'passed', 'tainacan-oai-pmh' ); ?></span>
						<span class="warnings"><?php echo esc_html( $validation['warnings'] ); ?> <?php esc_html_e( 'warnings', 'tainacan-oai-pmh' ); ?></span>
						<span class="failed"><?php echo esc_html( $validation['failed'] ); ?> <?php esc_html_e( 'failed', 'tainacan-oai-pmh' ); ?></span>
					</div>
					<p class="oai-meta">
						<?php esc_html_e( 'Last run:', 'tainacan-oai-pmh' ); ?> 
						<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $validation['timestamp'] ) ) ); ?>
					</p>
				</div>
				
				<table class="oai-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Test', 'tainacan-oai-pmh' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tainacan-oai-pmh' ); ?></th>
							<th><?php esc_html_e( 'Details', 'tainacan-oai-pmh' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $validation['tests'] as $test ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $test['name'] ); ?></strong>
								<br><small><?php echo esc_html( $test['description'] ); ?></small>
							</td>
							<td>
								<span class="oai-badge oai-badge-<?php echo esc_attr( $test['status'] ); ?>">
									<?php echo esc_html( ucfirst( $test['status'] ) ); ?>
								</span>
							</td>
							<td>
								<?php foreach ( $test['details'] as $detail ) : ?>
									<div><?php echo esc_html( $detail ); ?></div>
								<?php endforeach; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="oai-empty"><?php esc_html_e( 'No validation run yet. Click "Run Validation" to check your repository compliance.', 'tainacan-oai-pmh' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</div>
