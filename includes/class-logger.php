<?php
/**
 * Plugin Check / phpcs suppressions: this class operates on the plugin's
 * custom logs/harvesters tables and accumulates rows from public OAI endpoints.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
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

		$wpdb->insert(
			$this->table,
			array(
				'level'         => $level,
				'message'       => $message,
				'context'       => maybe_serialize( $context ),
				'ip_address'    => $this->get_client_ip(),
				'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
				'verb'          => $context['verb'] ?? null,
				'response_time' => $context['response_time'] ?? null,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$this->track_harvester();
		}
	}

	private function track_harvester() {
		global $wpdb;

		$ip = $this->get_client_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '';

		$exists = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->harvesters_table} WHERE ip_address = %s",
				$ip
			)
		);

		$now_utc = gmdate( 'Y-m-d H:i:s' );

		if ( $exists ) {
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
			// hostname is resolved later by the daily cron — never block the request path
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
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ip_address FROM {$this->harvesters_table} WHERE hostname IS NULL LIMIT %d",
				$limit
			)
		);
		$resolved = 0;
		foreach ( $rows as $row ) {
			$hostname = @gethostbyaddr( $row->ip_address );
			if ( $hostname && $hostname !== $row->ip_address ) {
				$wpdb->update( $this->harvesters_table, array( 'hostname' => $hostname ), array( 'ip_address' => $row->ip_address ) );
				++$resolved;
			} else {
				// Mark as unresolvable so we don't keep retrying
				$wpdb->update( $this->harvesters_table, array( 'hostname' => '' ), array( 'ip_address' => $row->ip_address ) );
			}
		}
		return $resolved;
	}

	private function get_client_ip(): string {
		// REMOTE_ADDR is the only trustworthy source (forwarded headers are spoofable)
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		if ( Settings::get( 'trust_proxy_headers', false ) ) {
			foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ) as $h ) {
				if ( ! empty( $_SERVER[ $h ] ) ) {
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

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is built from %s/%d placeholders + the trusted table name; values pass through prepare().
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public function get_stats( $period = '24 hours' ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-$period" ) );

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
	}

	public function get_daily_stats( $days = 14 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d', strtotime( "-$days days" ) );

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
	}

	public function get_harvesters( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->harvesters_table} ORDER BY last_seen DESC LIMIT %d",
				$limit
			)
		);
	}

	public function get_harvester_stats() {
		global $wpdb;

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
	}

	public function cleanup( $days = 30 ) {
		global $wpdb;
		$date = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );
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
