<?php
/**
 * Repository for the plugin-owned `tainacan_oai_imports` table.
 *
 * Concentrates every $wpdb access against that custom table in one class so
 * the Importer monolith no longer needs a file-level phpcs:disable just for
 * import-row reads/writes. Each query carries a specific line-level
 * justification documenting why direct $wpdb is necessary (no WP_Query
 * equivalent for a custom table; write-mostly flow that would mask state if
 * cached).
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes against {$wpdb->prefix}tainacan_oai_imports.
 */
class Imports_Table {

	/** @var string Fully-qualified table name (with $wpdb->prefix). */
	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'tainacan_oai_imports';
	}

	/**
	 * Inserts a fresh imports row.
	 *
	 * @param array<string,mixed> $data Pre-sanitized column values.
	 * @return int|false Inserted ID, or false on DB failure.
	 */
	public function insert( array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned imports table; $wpdb->insert() escapes values; caching irrelevant on write.
		$ok = $wpdb->insert( $this->table, $data );
		if ( $ok === false ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates fields of an imports row by ID.
	 *
	 * @param int                 $import_id
	 * @param array<string,mixed> $data
	 * @return int|false Affected rows, or false on DB failure.
	 */
	public function update( int $import_id, array $data ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned imports table; $wpdb->update() escapes values; caching would mask the write.
		return $wpdb->update( $this->table, $data, array( 'id' => $import_id ) );
	}

	/**
	 * Fetches one imports row by ID.
	 *
	 * @param int $import_id
	 * @return object|null
	 */
	public function get( int $import_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned imports table; $this->table from $wpdb->prefix (trusted); id via %d placeholder.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $import_id ) );
	}

	/**
	 * Reads just the status column (used by the cooperative-cancellation poll).
	 *
	 * @param int $import_id
	 * @return string|null
	 */
	public function get_status( int $import_id ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned imports table; single-column read used by mid-batch Stop polling; caching would defeat the poll.
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$this->table} WHERE id = %d", $import_id ) );
		return $status === null ? null : (string) $status;
	}

	/**
	 * Returns the most recent imports rows, newest first.
	 *
	 * @param int $limit
	 * @return array<int,object>
	 */
	public function list_recent( int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned imports table; admin list view; limit via %d placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes an imports row.
	 *
	 * @param int $import_id
	 * @return bool
	 */
	public function delete( int $import_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned imports table; admin Delete action; $wpdb->delete() escapes values.
		return (bool) $wpdb->delete( $this->table, array( 'id' => $import_id ) );
	}

	/**
	 * Appends one structured log line to the imports row, capping at 256 KB.
	 *
	 * @param int    $import_id
	 * @param string $level   INFO|WARN|ERROR.
	 * @param string $code    Short event code (no whitespace).
	 * @param string $message Human-readable description.
	 * @return void
	 */
	public function append_log( int $import_id, string $level, string $code, string $message ): void {
		global $wpdb;
		$level = strtoupper( $level );
		if ( ! in_array( $level, array( 'INFO', 'WARN', 'ERROR' ), true ) ) {
			$level = 'INFO';
		}
		$entry = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [' . $level . '] [' . $code . '] ' . $message . "\n";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned imports table; reads the live in-progress log; caching would mask in-flight writes.
		$current  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT error_log FROM {$this->table} WHERE id = %d", $import_id ) );
		$combined = $current . $entry;
		if ( strlen( $combined ) > 262144 ) {
			$combined = substr( $combined, -262144 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned imports table; $wpdb->update() escapes values.
		$wpdb->update( $this->table, array( 'error_log' => $combined ), array( 'id' => $import_id ) );
	}

	/**
	 * Empties the activity log for one import.
	 *
	 * @param int $import_id
	 * @return bool
	 */
	public function clear_log( int $import_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned imports table; $wpdb->update() escapes values.
		return (bool) $wpdb->update( $this->table, array( 'error_log' => null ), array( 'id' => $import_id ) );
	}

	/**
	 * Returns Tainacan item IDs created by the given import job, using both
	 * the new explicit tag (_tainacan_oai_import_id) and a legacy heuristic
	 * (source URL host + collection scope) for pre-tagging installs.
	 *
	 * @param int $import_id
	 * @return int[] Deduplicated item IDs.
	 */
	public function find_items( int $import_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional postmeta lookup of items tagged with this import_id; placeholders used.
		$direct = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				Item_Resolver::META_KEY_IMPORT_ID,
				(string) $import_id
			)
		);
		$ids    = array_map( 'intval', (array) $direct );

		$job = $this->get( $import_id );
		if ( $job ) {
			$host = wp_parse_url( $job->source_url, PHP_URL_HOST );
			if ( $host ) {
				$like = '%' . $wpdb->esc_like( 'oai:' . $host . ':' ) . '%';
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Legacy-tag fallback: LIKE over oai:<host>:* scoped to a single tnc_col_<n>_item post_type; placeholders used.
				$legacy = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT pm.post_id
						 FROM {$wpdb->postmeta} pm
						 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE pm.meta_key = %s
						   AND pm.meta_value LIKE %s
						   AND p.post_type LIKE %s",
						Item_Resolver::META_KEY_SOURCE_ID,
						$like,
						'tnc_col_' . (int) $job->collection_id . '_item'
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				$ids = array_merge( $ids, array_map( 'intval', (array) $legacy ) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Best-effort lock for processing one import.
	 *
	 * @param int $import_id
	 * @return bool True if the lock was acquired; false if another worker holds it.
	 */
	public function acquire_lock( int $import_id ): bool {
		$key = 'tainacan_oai_lock_' . $import_id;
		if ( get_transient( $key ) !== false ) {
			return false;
		}
		set_transient(
			$key,
			array(
				'pid' => function_exists( 'getmypid' ) && getmypid() ? getmypid() : 0,
				't'   => time(),
			),
			30 * MINUTE_IN_SECONDS
		);
		return true;
	}

	/**
	 * Releases the lock taken by acquire_lock().
	 *
	 * @param int $import_id
	 * @return void
	 */
	public function release_lock( int $import_id ): void {
		delete_transient( 'tainacan_oai_lock_' . $import_id );
	}
}
