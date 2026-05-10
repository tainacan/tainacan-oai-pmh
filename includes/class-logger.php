<?php
/**
 * Request/response logger for the OAI-PMH endpoint.
 *
 * Persists log entries to a plugin-owned table (`tainacan_oai_logs`) and
 * tracks unique IP harvesters in a second table (`tainacan_oai_harvesters`).
 * \$_SERVER reads are read-only (not state-mutating), so the WP Security
 * nonce sniff is suppressed line-by-line with that justification rather
 * than via a file-level disable. Each direct \$wpdb call carries its own
 * specific suppression comment.
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	private $table;
	private $harvesters_table;

	public function __construct() {
		global $wpdb;
		$this->table            = $wpdb->prefix . 'tainacan_oai_logs';
		$this->harvesters_table = $wpdb->prefix . 'tainacan_oai_harvesters';
	}

	public function log( $message, $level = 'info', $context = array() ) {
		if ( ! Settings::get( 'log_enabled', true ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-var read-only access; no state mutation triggered by this read.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
			: '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned logs table; $wpdb->insert() escapes values; caching would mask the write.
		$wpdb->insert(
			$this->table,
			array(
				'level'         => $level,
				'message'       => $message,
				'context'       => maybe_serialize( $context ),
				'ip_address'    => $this->get_client_ip(),
				'user_agent'    => $ua,
				'verb'          => $context['verb'] ?? null,
				'response_time' => $context['response_time'] ?? null,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-var read-only access; no state mutation triggered by this read.
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$this->track_harvester();
		}
	}

	private function track_harvester() {
		global $wpdb;

		$ip = $this->get_client_ip();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-var read-only access; no state mutation triggered by this read.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
			: '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned harvesters table; $this->harvesters_table from $wpdb->prefix; IP via %s placeholder.
		$exists = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->harvesters_table} WHERE ip_address = %s",
				$ip
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$now_utc = gmdate( 'Y-m-d H:i:s' );

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned harvesters table; $wpdb->update() escapes values; would mask write if cached.
			$wpdb->update(
				$this->harvesters_table,
				array(
					'last_seen'      => $now_utc,
					'total_requests' => $exists->total_requests + 1,
					'user_agent'     => $ua,
				),
				array( 'ip_address' => $ip )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned harvesters table; first-seen insert; $wpdb->insert() escapes values.
			$wpdb->insert(
				$this->harvesters_table,
				array(
					'ip_address'     => $ip,
					'user_agent'     => $ua,
					'hostname'       => null,
					'first_seen'     => $now_utc,
					'last_seen'      => $now_utc,
					'total_requests' => 1,
					'status'         => 'active',
				)
			);
		}
	}

	/**
	 * Resolves hostnames for harvesters with NULL hostname.
	 * Called from the daily cron — gethostbyaddr() can block for seconds.
	 */
	public function resolve_pending_hostnames( int $limit = 100 ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned harvesters table; cron-time read; $this->harvesters_table from $wpdb->prefix; limit via %d placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ip_address FROM {$this->harvesters_table} WHERE hostname IS NULL LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$resolved = 0;
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gethostbyaddr() emits a PHP warning on unresolvable hosts; we treat that path as "no PTR record" and continue, so silencing is intentional.
			$hostname = @gethostbyaddr( $row->ip_address );
			if ( $hostname && $hostname !== $row->ip_address ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned harvesters table; $wpdb->update() escapes values.
				$wpdb->update( $this->harvesters_table, array( 'hostname' => $hostname ), array( 'ip_address' => $row->ip_address ) );
				++$resolved;
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned harvesters table; marks row as unresolvable to break the retry loop.
				$wpdb->update( $this->harvesters_table, array( 'hostname' => '' ), array( 'ip_address' => $row->ip_address ) );
			}
		}
		return $resolved;
	}

	private function get_client_ip(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-var read-only; logger reads REMOTE_ADDR before any state mutation. No nonce semantically applicable here.
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		if ( Settings::get( 'trust_proxy_headers', false ) ) {
			foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ) as $h ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-var read-only; same justification as above.
				if ( ! empty( $_SERVER[ $h ] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-var read-only; same justification as above.
					$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0];
					$ip        = trim( $forwarded );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
		}
		return '0.0.0.0';
	}

	public function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'  => 100,
			'offset' => 0,
			'level'  => null,
			'verb'   => null,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['level'] ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}

		if ( $args['verb'] ) {
			$where[]  = 'verb = %s';
			$params[] = $args['verb'];
		}

		$params[] = $args['limit'];
		$params[] = $args['offset'];

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) .
				' ORDER BY created_at DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned logs table; $sql built from %s/%d placeholders + trusted $this->table; values via prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public function get_stats( $period = '24 hours' ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-$period" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned logs table; aggregate COUNT/AVG queries; $this->table from $wpdb->prefix; values via %s placeholder.
		return array(
			'total_requests'    => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE created_at >= %s",
					$since
				)
			),
			'errors'            => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE level = 'error' AND created_at >= %s",
					$since
				)
			),
			'avg_response_time' => round(
				(float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT AVG(response_time) FROM {$this->table} WHERE created_at >= %s AND response_time IS NOT NULL",
						$since
					)
				),
				3
			),
			'error_rate'        => 0,
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function get_daily_stats( $days = 14 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d', strtotime( "-$days days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned logs table; daily aggregate GROUP BY; $this->table from $wpdb->prefix; date via %s placeholder.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date,
                    COUNT(*) as total,
                    SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as errors
             FROM {$this->table}
             WHERE DATE(created_at) >= %s
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
				$since
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function get_harvesters( $limit = 50 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned harvesters table; admin list view; limit via %d placeholder.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->harvesters_table} ORDER BY last_seen DESC LIMIT %d",
				$limit
			)
		);
	}

	public function get_harvester_stats() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned harvesters table; aggregate stats; $this->harvesters_table from $wpdb->prefix; no user input.
		return array(
			'total'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->harvesters_table}" ),
			'active'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->harvesters_table} WHERE status = 'active'" ),
			'last_24h'       => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->harvesters_table} WHERE last_seen >= %s",
					gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
				)
			),
			'total_requests' => (int) $wpdb->get_var( "SELECT SUM(total_requests) FROM {$this->harvesters_table}" ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function cleanup( $days = 30 ) {
		global $wpdb;
		$date = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned logs table; cron cleanup; $this->table from $wpdb->prefix; date via %s placeholder.
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE created_at < %s", $date ) );
	}

	public function export_csv() {
		$logs = $this->get_logs( array( 'limit' => 10000 ) );

		// php://temp is an in-memory stream — not the filesystem. WP_Filesystem
		// is the wrong abstraction for a streamed CSV builder; we'd have to
		// re-implement fputcsv()'s quoting rules to avoid it. phpcs:ignore'd
		// because there's no real file being created.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( 'php://temp', 'r+' );
		fputcsv( $output, array( 'ID', 'Date', 'Level', 'Verb', 'Message', 'IP', 'User Agent', 'Response Time' ) );

		foreach ( $logs as $log ) {
			fputcsv(
				$output,
				array(
					$log->id,
					$log->created_at,
					$log->level,
					$log->verb,
					$log->message,
					$log->ip_address,
					$log->user_agent,
					$log->response_time,
				)
			);
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the in-memory stream paired with the fopen above.
		fclose( $output );

		return $csv;
	}
}
