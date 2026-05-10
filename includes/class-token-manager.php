<?php
/**
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Token_Manager {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'tainacan_oai_tokens';
	}

	public function create( array $data ): string {
		global $wpdb;

		$token        = bin2hex( random_bytes( 32 ) );
		$expiry_hours = max( 1, (int) Settings::get( 'token_expiry', 24 ) );
		$now_utc      = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			$this->table,
			array(
				'token'      => $token,
				'data'       => wp_json_encode( $data ),
				'created_at' => $now_utc,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( $expiry_hours * 3600 ) ),
			)
		);

		return $token;
	}

	public function get( string $token ) {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return false;
		}

		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE token = %s AND expires_at > %s",
				$token,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( ! $record ) {
			return false;
		}

		return json_decode( $record->data, true );
	}

	public function delete( string $token ): void {
		global $wpdb;
		$wpdb->delete( $this->table, array( 'token' => $token ) );
	}

	public function cleanup(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE expires_at < %s",
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
