<?php
/**
 * OAI-PMH Importer — orchestrator that wires together:
 *   - OAI_Client      (HTTP + XML + protocol verbs, no DB)
 *   - Record_Parser   (XML → array for oai_dc / qdc / xoai, no DB)
 *   - Imports_Table   ($wpdb wrapper for the plugin's own imports table)
 *   - Item_Resolver   ($wpdb wrapper for postmeta dedup queries)
 *
 * After this decomposition, the file no longer carries a file-level
 * phpcs:disable: every $wpdb call has migrated into the helper class
 * that owns it, with line-level justifications there. Internal proxy
 * methods (request/parse_xml/append_log/...) preserve the call sites
 * inside this class so the bitstream-fetch code keeps reading naturally
 * without needing direct knowledge of the helpers.
 *
 * Remaining file-level concerns documented inline:
 *  - set_time_limit() in harvest_loop() carries a single line-level
 *    Squiz.PHP.DiscouragedFunctions suppression with a strong reason
 *    (cron-driven multi-page sync; bg-process migration is Phase 2.5).
 *  - process_batch() no longer calls set_time_limit() — batches of 10
 *    fit in the default PHP execution window.
 *
 * @package Tainacan_OAI_PMH
 * @see https://www.openarchives.org/OAI/openarchivesprotocol.html
 * @see https://tainacan.github.io/tainacan-wiki/#/dev/README
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAI-PMH Importer (orchestrator).
 */
class Importer {

	// Namespaces still needed locally because the bitstream-strategy methods
	// (fetch_ore_bitstreams, fetch_mets_bitstreams, fetch_xoai_bitstreams)
	// register XPath namespaces directly on the SimpleXMLElement they receive.
	private const ATOM_NS    = 'http://www.w3.org/2005/Atom';
	private const OREATOM_NS = 'http://www.openarchives.org/ore/atom/';
	private const RDF_NS     = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
	private const DCTERMS_NS = 'http://purl.org/dc/terms/';

	private OAI_Client $oai;
	private Record_Parser $parser;
	private Imports_Table $imports;
	private Item_Resolver $resolver;

	public function __construct() {
		$this->parser   = new Record_Parser();
		$this->oai      = new OAI_Client( $this->parser );
		$this->imports  = new Imports_Table();
		$this->resolver = new Item_Resolver();
	}

	// ---------- Internal proxies: keep call sites in this class unchanged ----------

	/** @return string|\WP_Error */
	private function request( string $url ) {
		return $this->oai->request( $url );
	}

	/** @return \SimpleXMLElement|\WP_Error */
	private function parse_xml( string $body ) {
		return $this->oai->parse_xml( $body );
	}

	private function normalize_url( string $url ): string {
		return $this->oai->normalize_url( $url );
	}

	/** @return true|\WP_Error */
	private function validate_url( string $url ) {
		return $this->oai->validate_url( $url );
	}

	private function is_valid_oai_date( string $date ): bool {
		return $this->oai->is_valid_oai_date( $date );
	}

	private function extract_oai_error( \SimpleXMLElement $xml ) {
		return $this->oai->extract_oai_error( $xml );
	}

	private function parse_record( \SimpleXMLElement $record, string $prefix = 'oai_dc' ): ?array {
		return $this->parser->parse_record( $record, $prefix );
	}

	private function lookup_metadata_value( array $bag, array $keys ): ?string {
		return $this->parser->lookup_metadata_value( $bag, $keys );
	}

	private function find_item_by_oai_identifier( string $oai_identifier, ?int $collection_id = null ): ?int {
		return $this->resolver->find_by_oai_identifier( $oai_identifier, $collection_id );
	}

	private function find_trashed_item_by_oai_identifier( string $oai_identifier, ?int $collection_id = null ): ?int {
		return $this->resolver->find_trashed_by_oai_identifier( $oai_identifier, $collection_id );
	}

	private function find_oai_id_in_other_collections( string $oai_identifier, int $exclude_collection_id ): array {
		return $this->resolver->find_in_other_collections( $oai_identifier, $exclude_collection_id );
	}

	private function untrash_attachments( int $item_id ): int {
		return $this->resolver->untrash_attachments( $item_id );
	}

	private function item_has_oai_bitstreams( int $item_id ): bool {
		return $this->resolver->item_has_oai_bitstreams( $item_id );
	}

	// ---------- Public API: forwards to OAI_Client (no DB side effects) ----------

	public function fetch_repository_info( string $url ) {
		return $this->oai->fetch_repository_info( $url );
	}

	public function fetch_metadata_formats( string $url ) {
		return $this->oai->fetch_metadata_formats( $url );
	}

	public function fetch_sets( string $url ) {
		return $this->oai->fetch_sets( $url );
	}

	public function preview_records( string $url, string $set = '', int $limit = 5, string $prefix = 'oai_dc' ) {
		return $this->oai->preview_records( $url, $set, $limit, $prefix );
	}

	// ---------- Public API: forwards to Imports_Table (concentrated DB access) ----------

	public function get_imports( int $limit = 20 ) {
		return $this->imports->list_recent( $limit );
	}

	public function get_import( int $id ) {
		return $this->imports->get( $id );
	}

	public function append_log( int $import_id, string $level, string $code, string $message ): void {
		$this->imports->append_log( $import_id, $level, $code, $message );
	}

	public function clear_log( int $import_id ): bool {
		return $this->imports->clear_log( $import_id );
	}

	public function find_import_items( int $import_id ): array {
		return $this->imports->find_items( $import_id );
	}

	public function count_import_items( int $import_id ): int {
		return count( $this->imports->find_items( $import_id ) );
	}

	public function release_import_lock( int $import_id ): void {
		$this->imports->release_lock( $import_id );
	}

	// ---------- Internal proxy: imports-table log helper used by bitstream code ----------

	private function log_if( ?int $import_id, string $level, string $code, string $message ): void {
		if ( $import_id !== null ) {
			$this->imports->append_log( $import_id, $level, $code, $message );
		}
	}

	public function create_import( array $args ) {
		if ( empty( $args['source_url'] ) || empty( $args['collection_id'] ) ) {
			return new \WP_Error( 'missing_field', __( 'Source URL and collection are required.', 'tainacan-oai-pmh' ) );
		}

		$url_check = $this->validate_url( $args['source_url'] );
		if ( is_wp_error( $url_check ) ) {
			return $url_check;
		}

		$collection = new \Tainacan\Entities\Collection( (int) $args['collection_id'] );
		if ( ! $collection->get_id() ) {
			return new \WP_Error( 'invalid_collection', __( 'Collection not found.', 'tainacan-oai-pmh' ) );
		}

		// Validate optional dates against OAI-PMH granularity.
		foreach ( array( 'from_date', 'until_date' ) as $f ) {
			if ( ! empty( $args[ $f ] ) && ! $this->is_valid_oai_date( $args[ $f ] ) ) {
				/* translators: %s: name of the invalid date field (from_date or until_date) */
				return new \WP_Error( 'invalid_date', sprintf( __( 'Invalid %s — use YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ.', 'tainacan-oai-pmh' ), $f ) );
			}
		}

		// Per-import bitstream override: NULL = follow global setting,
		// 0 = force-disable, 1 = force-enable for this run.
		$download_bs = null;
		if ( array_key_exists( 'download_bitstreams', $args ) && $args['download_bitstreams'] !== null && $args['download_bitstreams'] !== '' ) {
			$download_bs = (int) (bool) $args['download_bitstreams'];
		}

		// Whitelist allowed prefixes — anything else is silently coerced to oai_dc.
		$prefix = ! empty( $args['metadata_prefix'] ) ? strtolower( (string) $args['metadata_prefix'] ) : 'oai_dc';
		if ( ! in_array( $prefix, array( 'oai_dc', 'qdc', 'xoai' ), true ) ) {
			$prefix = 'oai_dc';
		}

		$id = $this->imports->insert(
			array(
				'source_url'          => $this->normalize_url( $args['source_url'] ),
				'collection_id'       => (int) $args['collection_id'],
				'set_spec'            => $args['set_spec'] ?? '',
				'from_date'           => ! empty( $args['from_date'] ) ? $args['from_date'] : null,
				'until_date'          => ! empty( $args['until_date'] ) ? $args['until_date'] : null,
				'metadata_mapping'    => maybe_serialize( $args['metadata_mapping'] ?? array() ),
				'download_bitstreams' => $download_bs,
				'metadata_prefix'     => $prefix,
				'status'              => 'pending',
				'created_at'          => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( $id === false ) {
			return new \WP_Error( 'db_error', __( 'Could not persist import row.', 'tainacan-oai-pmh' ) );
		}
		return $id;
	}

	public function process_batch( int $import_id, int $batch_size = 10 ) {
		// Batches of 10 records fit comfortably in default PHP execution
		// windows; the JS poller fires a follow-up request per batch.
		// set_time_limit(0) used to be set here defensively — removed because
		// it was the only thing forcing a file-level
		// Squiz.PHP.DiscouragedFunctions suppression. If your server's
		// max_execution_time is set very low and OAI fetches stall, raise it
		// at the PHP-FPM/.htaccess level rather than here.
		ignore_user_abort( true );

		$import = $this->imports->get( $import_id );
		if ( ! $import ) {
			return new \WP_Error( 'not_found', __( 'Import not found.', 'tainacan-oai-pmh' ) );
		}
		if ( $import->status === 'completed' ) {
			return array( 'status' => 'completed' );
		}

		// Honor a previously requested cancellation. The "Stop import" button
		// sets status='cancelled'; we exit cleanly without processing more pages.
		if ( $import->status === 'cancelled' ) {
			$this->append_log(
				$import_id,
				'INFO',
				'import.cancelled',
				'Run aborted — admin clicked Stop. Already-imported items are preserved.'
			);
			$this->release_import_lock( $import_id );
			return array(
				'status'         => 'cancelled',
				'has_more'       => false,
				'total_imported' => (int) $import->imported_records,
				'total_records'  => (int) $import->total_records,
				'failed'         => (int) $import->failed_records,
				'skipped'        => 0,
			);
		}

		// Concurrency guard: when AJAX times out (default 5 min) and the JS
		// poller fires a retry, the server-side worker is still running. Without
		// a lock, every retry spawns ANOTHER worker that re-fetches page 1
		// because the running one hasn't saved the resumption_token yet.
		// Result: stuck on page 1 forever, multiple workers competing.
		// The lock returns 'busy' so the poller backs off until the active
		// worker finishes its batch and releases the lock.
		$lock = $this->imports->acquire_lock( $import_id );
		if ( ! $lock ) {
			return array(
				'status'         => 'busy',
				'has_more'       => true,
				'busy'           => true,
				'total_imported' => (int) $import->imported_records,
				'total_records'  => (int) $import->total_records,
				'failed'         => (int) $import->failed_records,
				'skipped'        => 0,
				'message'        => 'Another worker is still processing this import — waiting',
			);
		}
		// Even on fatal/timeout, release the lock so the next poll can proceed.
		register_shutdown_function( array( $this, 'release_import_lock' ), $import_id );

		if ( 'pending' === $import->status ) {
			$this->imports->update(
				$import_id,
				array(
					'status'     => 'processing',
					'started_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		// Allow per-import override of the metadata format. Keeps oai_dc as
		// default for safety, but xoai/qdc preserve qualified DSpace field
		// names (e.g. dc.contributor.author distinct from dc.contributor.advisor).
		$prefix = ! empty( $import->metadata_prefix ) ? (string) $import->metadata_prefix : 'oai_dc';

		// Per OAI-PMH spec: when using a resumption token, only verb + token allowed.
		if ( ! empty( $import->resumption_token ) ) {
			$url = $import->source_url . '?verb=ListRecords&resumptionToken=' . urlencode( $import->resumption_token );
			$this->append_log( $import_id, 'INFO', 'page.fetch', 'Fetching with resumption token (token len ' . strlen( $import->resumption_token ) . ')' );
		} else {
			$url = $import->source_url . '?verb=ListRecords&metadataPrefix=' . urlencode( $prefix );
			if ( ! empty( $import->set_spec ) ) {
				$url .= '&set=' . urlencode( $import->set_spec );
			}
			if ( ! empty( $import->from_date ) ) {
				$url .= '&from=' . urlencode( $import->from_date );
			}
			if ( ! empty( $import->until_date ) ) {
				$url .= '&until=' . urlencode( $import->until_date );
			}
			$this->append_log( $import_id, 'INFO', 'page.fetch', 'First page (prefix=' . $prefix . '): ' . $url );
		}

		$t_request = microtime( true );
		$response  = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			$this->append_log( $import_id, 'ERROR', 'request_failed', $response->get_error_message() );
			$this->release_import_lock( $import_id );
			return $response;
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			$this->append_log( $import_id, 'ERROR', 'parse_error', $xml->get_error_message() );
			$this->release_import_lock( $import_id );
			return $xml;
		}

		if ( isset( $xml->error ) ) {
			$code = (string) $xml->error['code'];
			if ( 'noRecordsMatch' === $code ) {
				$this->append_log( $import_id, 'INFO', 'noRecordsMatch', 'Upstream reports no records match the criteria — marking import completed.' );
				$this->imports->update(
					$import_id,
					array(
						'status'       => 'completed',
						'completed_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->release_import_lock( $import_id );
				return array(
					'status'         => 'completed',
					'has_more'       => false,
					'total_imported' => (int) $import->imported_records,
					'failed'         => (int) $import->failed_records,
				);
			}
			$this->append_log( $import_id, 'ERROR', $code, (string) $xml->error );
			$this->release_import_lock( $import_id );
			return new \WP_Error( $code, (string) $xml->error );
		}

		$records       = $xml->ListRecords->record ?? array();
		$records_count = is_countable( $records ) ? count( $records ) : iterator_count( $records );
		$rt_preview    = $xml->ListRecords->resumptionToken ?? null;
		$clsize        = ( $rt_preview && isset( $rt_preview['completeListSize'] ) ) ? (int) $rt_preview['completeListSize'] : 0;
		$this->append_log(
			$import_id,
			'INFO',
			'page.received',
			sprintf(
				'Got %d record(s)%s in %.2fs',
				$records_count,
				$clsize > 0 ? sprintf( ' (completeListSize=%d)', $clsize ) : '',
				microtime( true ) - $t_request
			)
		);

		$mapping = maybe_unserialize( $import->metadata_mapping );
		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}

		$imported      = 0;
		$failed        = 0;
		$skipped       = 0;
		$cancelled_mid = false;

		// In-memory dedup safety net: if the upstream OAI server lists the same
		// identifier in multiple records of one response (rare but observed in
		// DSpace with overlapping sets), or if the postmeta tagging on the first
		// copy hasn't committed by the time we look up the second, we still
		// catch the duplicate here.
		$seen_in_batch = array();

		// Per-import override (column download_bitstreams) wins over the global
		// setting. NULL/missing → fall back to the global default.
		$per_import_override = isset( $import->download_bitstreams ) ? (int) $import->download_bitstreams : null;
		if ( $per_import_override === 1 ) {
			$bitstreams_enabled = true;
		} elseif ( $per_import_override === 0 ) {
			$bitstreams_enabled = false;
		} else {
			$bitstreams_enabled = (bool) Settings::get( 'import_bitstreams', true );
		}

		// Make the resolved decision visible — silent skipping was the source of
		// many "imported but no images" surprises.
		if ( $bitstreams_enabled ) {
			$this->append_log(
				$import_id,
				'INFO',
				'config',
				'Bitstream download is ENABLED. Will try ORE → METS → xOAI for each item.'
			);
		} else {
			$this->append_log(
				$import_id,
				'INFO',
				'config',
				'Bitstream download is DISABLED. Items will be created with metadata only. Toggle "Importer: Download Bitstreams" in Tainacan → Settings → OAI-PMH (or check the wizard option) to enable.'
			);
		}

		$this->append_log(
			$import_id,
			'INFO',
			'page.processing',
			sprintf( 'Entering record loop (%d record(s) in this page)', is_countable( $records ) ? count( $records ) : iterator_count( $records ) )
		);

		// SimpleXML foreach yields the element NAME as key (always 'record'),
		// not an int — using `$idx => $record` and `$idx % 5` was raising a
		// PHP 8 TypeError on every record. Use an explicit counter instead.
		$idx = -1;
		foreach ( $records as $record ) {
			++$idx;
			try {
				// Cooperative cancellation: re-check status every 5 records so a
				// Stop click during a long batch takes effect within seconds.
				if ( $idx > 0 && 0 === $idx % 5 ) {
					$cur_status = $this->imports->get_status( $import_id );
					if ( 'cancelled' === $cur_status ) {
						$this->append_log(
							$import_id,
							'INFO',
							'import.cancelled_mid_batch',
							sprintf( 'Stop requested — aborted after %d record(s) in this batch.', $idx )
						);
						$cancelled_mid = true;
						break;
					}
				}

				$parsed = $this->parse_record( $record, $prefix );
				if ( ! $parsed ) {
					++$failed;
					$this->append_log( $import_id, 'ERROR', 'parse_record', 'Failed to parse a record (missing header?)' );
					continue;
				}
				if ( $parsed['status'] === 'deleted' ) {
					++$skipped;
					$this->append_log( $import_id, 'INFO', 'record.deleted_upstream', '[' . $parsed['identifier'] . '] Marked deleted upstream — skipped' );
					continue;
				}

				// Already handled in this very batch? Catches upstream-duplicated
				// records and protects against postmeta-tagging races.
				if ( isset( $seen_in_batch[ $parsed['identifier'] ] ) ) {
					++$skipped;
					$this->append_log(
						$import_id,
						'WARN',
						'record.duplicate_in_batch',
						'[' . $parsed['identifier'] . '] Already processed earlier in this batch (upstream returned duplicate) — skipped to prevent local duplication'
					);
					continue;
				}
				$seen_in_batch[ $parsed['identifier'] ] = true;

				// Deduplicate by OAI identifier within the SAME target collection.
				// Same identifier present in another collection is treated as separate.
				$existing = $this->find_item_by_oai_identifier( $parsed['identifier'], (int) $import->collection_id );
				if ( $existing ) {
					$had_bs = $this->item_has_oai_bitstreams( $existing );
					if ( $bitstreams_enabled && ! $had_bs ) {
						$this->append_log( $import_id, 'INFO', 'bitstream.backfill_start', '[' . $parsed['identifier'] . '] Item ' . $existing . ' exists but has no bitstreams — backfilling' );
						$bs_errors = $this->enrich_item_with_bitstreams( $existing, $parsed['identifier'], $import->source_url, $import_id );
						foreach ( $bs_errors as $bs_err ) {
							$this->append_log( $import_id, 'WARN', 'bitstream_backfill', '[' . $parsed['identifier'] . '] ' . $bs_err );
						}
						if ( empty( $bs_errors ) ) {
							$this->append_log( $import_id, 'INFO', 'bitstream.backfill_done', '[' . $parsed['identifier'] . '] Backfill completed for item ' . $existing );
						}
					} else {
						$this->append_log( $import_id, 'INFO', 'record.skipped_existing', '[' . $parsed['identifier'] . '] Item ' . $existing . ' exists' . ( $had_bs ? ' (has bitstreams)' : '' ) . ' — skipped' );
					}
					++$skipped;
					continue;
				}

				// Item was previously trashed (e.g. via the Delete-import button).
				// Restore it instead of creating a duplicate: untrash the post,
				// un-trash its attachments, refresh metadata, optionally re-fetch
				// bitstreams. Counts as "imported" in the run summary.
				$trashed = $this->find_trashed_item_by_oai_identifier( $parsed['identifier'], (int) $import->collection_id );
				if ( $trashed ) {
					wp_untrash_post( $trashed );
					wp_update_post(
						array(
							'ID'          => $trashed,
							'post_status' => 'publish',
						)
					);
					$att_count = $this->untrash_attachments( $trashed );
					$this->update_item_from_oai( $trashed, $parsed, $mapping );
					update_post_meta( $trashed, '_tainacan_oai_import_id', $import_id );
					$this->append_log(
						$import_id,
						'INFO',
						'record.restored',
						'[' . $parsed['identifier'] . '] Restored item ' . $trashed . ' from Trash (and ' . $att_count . ' attachment(s))'
					);
					if ( $bitstreams_enabled && ! $this->item_has_oai_bitstreams( $trashed ) ) {
						$bs_errors = $this->enrich_item_with_bitstreams( $trashed, $parsed['identifier'], $import->source_url, $import_id );
						foreach ( $bs_errors as $bs_err ) {
							$this->append_log( $import_id, 'WARN', 'bitstream', '[' . $parsed['identifier'] . '] ' . $bs_err );
						}
					}
					++$imported;
					continue;
				}

				$result = $this->create_item( (int) $import->collection_id, $parsed, $mapping, $import_id );
				if ( is_wp_error( $result ) ) {
					++$failed;
					$this->append_log( $import_id, 'ERROR', $result->get_error_code(), '[' . $parsed['identifier'] . '] ' . $result->get_error_message() );
				} else {
					++$imported;
					$this->append_log( $import_id, 'INFO', 'record.created', '[' . $parsed['identifier'] . '] Created item ' . (int) $result );

					// Heads-up if the same OAI identifier is already imported elsewhere
					$other = $this->find_oai_id_in_other_collections( $parsed['identifier'], (int) $import->collection_id );
					if ( ! empty( $other ) ) {
						$other_summary = array_map( fn( $o ) => 'item ' . $o['id'] . ' (collection ' . $o['collection_id'] . ')', $other );
						$this->append_log(
							$import_id,
							'INFO',
							'record.duplicate_other_collection',
							'[' . $parsed['identifier'] . '] Same OAI identifier already exists in another collection: ' . implode( ', ', $other_summary )
						);
					}

					if ( $bitstreams_enabled ) {
						$bs_errors = $this->enrich_item_with_bitstreams( (int) $result, $parsed['identifier'], $import->source_url, $import_id );
						foreach ( $bs_errors as $bs_err ) {
							$this->append_log( $import_id, 'WARN', 'bitstream', '[' . $parsed['identifier'] . '] ' . $bs_err );
						}
					}
				}
			} catch ( \Throwable $e ) {
				// Per-record safety net: an exception on one record (Tainacan
				// validation, broken XML, plugin conflict) must not nuke the
				// whole batch — the lock would never release and subsequent
				// polls would loop forever fetching page 1.
				++$failed;
				$oai_id = isset( $parsed['identifier'] ) ? $parsed['identifier'] : 'record-' . $idx;
				$this->append_log(
					$import_id,
					'ERROR',
					'record_exception',
					'[' . $oai_id . '] Unhandled: ' . $e->getMessage()
				);
			}
		}

		$rt                  = $xml->ListRecords->resumptionToken ?? null;
		$token               = $rt ? trim( (string) $rt ) : '';
		$total               = isset( $rt['completeListSize'] ) ? (int) $rt['completeListSize'] : (int) $import->total_records;
		$cumulative_imported = $import->imported_records + $imported;

		$update = array(
			'imported_records' => $cumulative_imported,
			'failed_records'   => $import->failed_records + $failed,
			'resumption_token' => $token !== '' ? $token : null,
		);
		if ( $total > 0 ) {
			$update['total_records'] = $total;
		}

		// Mid-batch cancellation: persist what we managed to import and stop.
		if ( $cancelled_mid ) {
			$update['status']       = 'cancelled';
			$update['completed_at'] = gmdate( 'Y-m-d H:i:s' );
			$this->imports->update( $import_id, $update );
			$this->release_import_lock( $import_id );
			return array(
				'status'         => 'cancelled',
				'has_more'       => false,
				'total_imported' => $cumulative_imported,
				'total_records'  => $total ? $total : (int) $import->total_records,
				'failed'         => $import->failed_records + $failed,
				'skipped'        => $skipped,
			);
		}

		if ( '' === $token ) {
			// OAI servers signal end-of-list with an empty resumptionToken.
			// Detect the suspicious case where the response advertises more records
			// than we actually imported — this is upstream misbehavior worth flagging.
			if ( $total > 0 && $cumulative_imported < $total && ( $skipped + $failed ) < ( $total - $cumulative_imported ) ) {
				$this->append_log(
					$import_id,
					'WARN',
					'page.unexpected_end',
					sprintf(
						'Upstream returned an empty resumption token but only %d / %d records were processed across all batches. The server may have stopped pagination prematurely.',
						$cumulative_imported + $skipped + $failed,
						$total
					)
				);
			}
			$update['status']       = 'completed';
			$update['completed_at'] = gmdate( 'Y-m-d H:i:s' );
			$this->append_log(
				$import_id,
				'INFO',
				'import.completed',
				sprintf(
					'Run finished. Cumulative imported=%d, failed=%d, this-batch skipped=%d',
					$cumulative_imported,
					$import->failed_records + $failed,
					$skipped
				)
			);
			// Heads-up when an entire run produced zero new items — most often this
			// means the records already exist as active items in this WP install.
			if ( 0 === $cumulative_imported && $total > 0 ) {
				$this->append_log(
					$import_id,
					'WARN',
					'import.no_new_items',
					'No new items were created. All records matched existing items (dedup). To re-import from scratch, click "Delete" on this import and choose "move items to Trash" — the next run will then restore them as fresh items.'
				);
			}
		} else {
			$this->append_log( $import_id, 'INFO', 'page.has_more', 'Resumption token received — more pages to fetch.' );
		}

		$this->imports->update( $import_id, $update );
		$this->release_import_lock( $import_id );

		return array(
			'status'         => '' === $token ? 'completed' : 'processing',
			'total_imported' => $cumulative_imported,
			'total_records'  => $total ? $total : (int) $import->total_records,
			'failed'         => $import->failed_records + $failed,
			'skipped'        => $skipped,
			'has_more'       => '' !== $token,
		);
	}

	/**
	 * Periodic harvest loop with insert/update/delete diff.
	 *
	 * Driven by Harvester for scheduled runs. Differs from process_batch:
	 *  - Stateless (no DB-persisted resumption — runs to completion or fails)
	 *  - Uses OAI `from` parameter for incremental sync
	 *  - On existing items: compares header.datestamp vs stored datestamp
	 *      → newer  : update_item_from_oai (re-applies mapping)
	 *      → equal  : skip
	 *  - On records with status="deleted": trashes the local item
	 *  - Caps resumption-token follow-through at 200 pages so a misbehaving
	 *    upstream can't pin the cron worker forever
	 *
	 * @return array{
	 *   created:int, updated:int, skipped:int, failed:int, deleted:int,
	 *   pages:int, last_datestamp:?string, errors:string[]
	 * }
	 */
	public function harvest_loop( array $config ): array {
		// Scheduled cron-driven multi-page sync. Unlike process_batch (which
		// returns after 10 records so the browser poller can pace the work),
		// harvest_loop walks the entire resumption-token chain in a single
		// pass — there's no DB-persisted state to resume from. It legitimately
		// needs an unbounded time window; the @-silenced set_time_limit is
		// the only Squiz.PHP.DiscouragedFunctions site left in this class.
		// Migrating this loop into \Tainacan\Background_Process eliminates
		// this suppression (tracked as Phase 2.5).
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- See block comment above; set_time_limit may be disabled (open_basedir / safe_mode) and we don't want a fatal there.
			@set_time_limit( 0 );
		}
		ignore_user_abort( true );

		$stats = array(
			'created'        => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'deleted'        => 0,
			'pages'          => 0,
			'last_datestamp' => null,
			'errors'         => array(),
		);

		$url = $this->normalize_url( $config['source_url'] ?? '' );
		$val = $this->validate_url( $url );
		if ( is_wp_error( $val ) ) {
			$stats['errors'][] = $val->get_error_message();
			return $stats; }

		$set_spec      = (string) ( $config['set_spec'] ?? '' );
		$collection_id = (int) ( $config['collection_id'] ?? 0 );
		$mapping       = is_array( $config['metadata_mapping'] ?? null ) ? $config['metadata_mapping'] : array();
		$download_bs   = ! empty( $config['download_bitstreams'] );
		$from          = (string) ( $config['from'] ?? '' );
		$until         = (string) ( $config['until'] ?? '' );

		if ( $collection_id <= 0 ) {
			$stats['errors'][] = 'invalid collection_id';
			return $stats; }

		$resumption = '';
		$max_pages  = 200;

		do {
			$page_url = $this->oai->build_list_records_url( $url, 'oai_dc', $set_spec, $from, $until, $resumption );

			$body = $this->request( $page_url );
			if ( is_wp_error( $body ) ) {
				$stats['errors'][] = $body->get_error_message();
				break; }

			$xml = $this->parse_xml( $body );
			if ( is_wp_error( $xml ) ) {
				$stats['errors'][] = $xml->get_error_message();
				break; }

			if ( isset( $xml->error ) ) {
				$code = (string) $xml->error['code'];
				if ( $code === 'noRecordsMatch' ) {
					break; // not an error — empty delta
				}
				$stats['errors'][] = $code . ': ' . (string) $xml->error;
				break;
			}

			$records = $xml->ListRecords->record ?? array();
			foreach ( $records as $record ) {
				// harvest_loop always requests metadataPrefix=oai_dc (see build_list_records_url call above).
				$parsed = $this->parse_record( $record, 'oai_dc' );
				if ( ! $parsed ) {
					++$stats['failed'];
					continue; }

				if ( $parsed['datestamp'] !== '' && (string) $parsed['datestamp'] > (string) ( $stats['last_datestamp'] ?? '' ) ) {
					$stats['last_datestamp'] = $parsed['datestamp'];
				}

				$existing = $this->find_item_by_oai_identifier( $parsed['identifier'], $collection_id );

				// Tombstone: upstream tells us this record was deleted
				if ( $parsed['status'] === 'deleted' ) {
					if ( $existing ) {
						wp_trash_post( $existing );
						++$stats['deleted'];
					} else {
						++$stats['skipped'];
					}
					continue;
				}

				if ( $existing ) {
					$stored = (string) get_post_meta( $existing, '_tainacan_oai_source_datestamp', true );
					if ( $stored !== '' && $parsed['datestamp'] !== '' && $parsed['datestamp'] <= $stored ) {
						// Untouched upstream — but backfill bitstreams if they're missing
						if ( $download_bs && ! $this->item_has_oai_bitstreams( $existing ) ) {
							$this->enrich_item_with_bitstreams( $existing, $parsed['identifier'], $url );
						}
						++$stats['skipped'];
						continue;
					}
					$upd = $this->update_item_from_oai( $existing, $parsed, $mapping );
					if ( is_wp_error( $upd ) ) {
						++$stats['failed'];
						$stats['errors'][] = '[' . $parsed['identifier'] . '] update: ' . $upd->get_error_message();
					} else {
						++$stats['updated'];
						if ( $download_bs && ! $this->item_has_oai_bitstreams( $existing ) ) {
							$this->enrich_item_with_bitstreams( $existing, $parsed['identifier'], $url );
						}
					}
					continue;
				}

				// Restore-from-trash path (counts as updated in scheduled-harvest stats)
				$trashed = $this->find_trashed_item_by_oai_identifier( $parsed['identifier'], $collection_id );
				if ( $trashed ) {
					wp_untrash_post( $trashed );
					wp_update_post(
						array(
							'ID'          => $trashed,
							'post_status' => 'publish',
						)
					);
					$this->untrash_attachments( $trashed );
					$upd = $this->update_item_from_oai( $trashed, $parsed, $mapping );
					if ( is_wp_error( $upd ) ) {
						++$stats['failed'];
						$stats['errors'][] = '[' . $parsed['identifier'] . '] restore: ' . $upd->get_error_message();
					} else {
						++$stats['updated'];
						if ( $download_bs && ! $this->item_has_oai_bitstreams( $trashed ) ) {
							$this->enrich_item_with_bitstreams( $trashed, $parsed['identifier'], $url );
						}
					}
					continue;
				}

				$created = $this->create_item( $collection_id, $parsed, $mapping );
				if ( is_wp_error( $created ) ) {
					++$stats['failed'];
					$stats['errors'][] = '[' . $parsed['identifier'] . '] create: ' . $created->get_error_message();
					continue;
				}
				++$stats['created'];
				if ( $download_bs ) {
					$this->enrich_item_with_bitstreams( (int) $created, $parsed['identifier'], $url );
				}
			}

			++$stats['pages'];
			$rt         = $xml->ListRecords->resumptionToken ?? null;
			$resumption = $rt ? trim( (string) $rt ) : '';
		} while ( $resumption !== '' && $stats['pages'] < $max_pages );

		return $stats;
	}

	/**
	 * Updates an existing Tainacan item from a freshly-parsed OAI record.
	 * Re-applies the user's DC mapping (overwriting prior values for those
	 * metadata) and refreshes title/description + the source datestamp.
	 */
	public function update_item_from_oai( int $item_id, array $parsed, array $mapping ) {
		$post = get_post( $item_id );
		if ( ! $post ) {
			return new \WP_Error( 'not_found', 'Item not found.' );
		}

		$title = $parsed['metadata']['title'] ?? $parsed['identifier'];
		if ( is_array( $title ) ) {
			$title = $title[0] ?? '';
		}
		if ( ! is_string( $title ) || $title === '' ) {
			$title = $parsed['identifier'] ?: $post->post_title;
		}

		$desc = $parsed['metadata']['description'] ?? '';
		if ( is_array( $desc ) ) {
			$desc = implode( "\n\n", array_filter( $desc ) );
		}

		wp_update_post(
			array(
				'ID'           => $item_id,
				'post_title'   => $title,
				'post_content' => (string) $desc,
			)
		);

		if ( ! empty( $mapping ) ) {
			try {
				$item = new \Tainacan\Entities\Item( $item_id );
				if ( $item->get_id() ) {
					$this->apply_mapping( $item, $parsed['metadata'], $mapping );
				}
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'mapping_error', $e->getMessage() );
			}
		}

		update_post_meta( $item_id, '_tainacan_oai_source_datestamp', (string) $parsed['datestamp'] );
		return true;
	}

	/**
	 * Looks up an ACTIVE local item that was previously imported from this
	 * OAI identifier. Trashed/auto-draft posts are deliberately excluded so
	 * a previous "Delete import" doesn't pollute the dedup check — those go
	 * through find_trashed_item_by_oai_identifier() and get restored instead.
	 *
	 * Scope-by-collection: Tainacan items live under post_type
	 * `tnc_col_<id>_item`, so passing $collection_id constrains the lookup
	 * to that exact collection. Without it, the same OAI identifier in two
	 * collections would dedup-match the first one we find, causing imports
	 * targeted at collection B to silently update items in collection A.
	 */

	/** Counterpart of find_item_by_oai_identifier scoped to trashed items only. */

	/**
	 * Returns active item IDs for the same OAI identifier in *other* collections,
	 * for informational logging only. Helps admins notice that the same source
	 * record was previously imported elsewhere.
	 */

	/** Restores trashed bitstream attachments belonging to the given item. */
	private function create_item( int $collection_id, array $record, array $mapping, ?int $import_id = null ) {
		$collection = new \Tainacan\Entities\Collection( $collection_id );
		if ( ! $collection->get_id() ) {
			return new \WP_Error( 'invalid_collection', 'Collection not found.' );
		}

		$item_repo = \Tainacan\Repositories\Items::get_instance();
		$item      = new \Tainacan\Entities\Item();
		$item->set_collection( $collection );

		// Title/description live under different keys depending on the metadata
		// format used to fetch the record:
		// oai_dc → title, description
		// qdc    → title, abstract / description
		// xoai   → dc.title, dc.description.abstract / dc.description
		$title = $this->lookup_metadata_value( $record['metadata'], array( 'title', 'dc.title' ) );
		if ( ! is_string( $title ) || $title === '' ) {
			$title = $record['identifier'] ?: __( 'Untitled imported item', 'tainacan-oai-pmh' );
		}
		$item->set_title( $title );

		$desc = $this->lookup_metadata_value(
			$record['metadata'],
			array( 'description', 'dc.description.abstract', 'abstract', 'dc.description' )
		);
		if ( $desc !== null && $desc !== '' ) {
			$item->set_description( (string) $desc );
		}

		$item->set_status( 'publish' );

		if ( ! $item->validate() ) {
			$errors = $item->get_errors();
			$msg    = is_array( $errors ) ? implode( ', ', array_map( fn( $e ) => is_array( $e ) ? implode( ';', $e ) : (string) $e, $errors ) ) : (string) $errors;
			return new \WP_Error( 'validation_error', $msg );
		}
		$item = $item_repo->insert( $item );

		// Persist OAI identifier for deduplication and audit trail.
		// Verify the dedup tag landed — if any meta call silently fails, the
		// next import would create a duplicate. We retry once and surface the
		// failure if still missing.
		if ( $item && $item->get_id() ) {
			$iid = $item->get_id();
			update_post_meta( $iid, '_tainacan_oai_source_id', $record['identifier'] );
			update_post_meta( $iid, '_tainacan_oai_source_datestamp', $record['datestamp'] );
			if ( $import_id !== null ) {
				update_post_meta( $iid, '_tainacan_oai_import_id', $import_id );
			}

			$verify = get_post_meta( $iid, '_tainacan_oai_source_id', true );
			if ( $verify !== $record['identifier'] ) {
				update_post_meta( $iid, '_tainacan_oai_source_id', $record['identifier'] );
				$verify = get_post_meta( $iid, '_tainacan_oai_source_id', true );
				if ( $verify !== $record['identifier'] ) {
					return new \WP_Error(
						'dedup_tag_failed',
						'Could not persist _tainacan_oai_source_id postmeta on item ' . $iid .
						' — refusing to count as imported to avoid future duplicates'
					);
				}
			}
		}

		if ( ! empty( $mapping ) ) {
			$errors = $this->apply_mapping( $item, $record['metadata'], $mapping );
			if ( ! empty( $errors ) ) {
				// Mapping errors are non-fatal — log them but consider the item imported.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; surfaces only in dev to surface mapping drift in OAI sources.
					error_log( '[Tainacan OAI Importer] Item ' . $item->get_id() . ' mapping warnings: ' . implode( '; ', $errors ) );
				}
			}
		}

		return $item->get_id();
	}

	private function apply_mapping( $item, array $source, array $mapping ): array {
		$repo   = \Tainacan\Repositories\Item_Metadata::get_instance();
		$errors = array();

		foreach ( $mapping as $dc_field => $metadatum_id ) {
			$metadatum_id = (int) $metadatum_id;
			if ( $metadatum_id <= 0 || ! isset( $source[ $dc_field ] ) ) {
				continue;
			}

			$metadatum = new \Tainacan\Entities\Metadatum( $metadatum_id );
			if ( ! $metadatum->get_id() ) {
				continue;
			}

			$value = $source[ $dc_field ];
			if ( is_array( $value ) ) {
				if ( ! $metadatum->is_multiple() ) {
					$value = $value[0] ?? '';
				} else {
					$value = array_values( array_filter( $value, fn( $v ) => $v !== null && $v !== '' ) );
				}
			}

			try {
				$item_meta = new \Tainacan\Entities\Item_Metadata_Entity( $item, $metadatum );
				$item_meta->set_value( $value );
				if ( $item_meta->validate() ) {
					$repo->insert( $item_meta );
				} else {
					$meta_errors = $item_meta->get_errors();
					$errors[]    = "$dc_field → " . $metadatum->get_name() . ': ' . ( is_array( $meta_errors ) ? json_encode( $meta_errors ) : (string) $meta_errors );
				}
			} catch ( \Throwable $e ) {
				$errors[] = "$dc_field → " . $metadatum->get_name() . ': ' . $e->getMessage();
			}
		}
		return $errors;
	}



	/**
	 * Downloads ORIGINAL + THUMBNAIL bitstreams advertised in the ORE/Atom view
	 * of the OAI record, sideloads them as attachments under the Tainacan item,
	 * and wires them into Tainacan's display model:
	 *
	 *  - First ORIGINAL → Tainacan's main "Documento" (set_document + set_document_type)
	 *    AND the WordPress featured image (used by Tainacan card listings)
	 *  - Remaining bitstreams → "Anexos" via post_parent (Tainacan auto-lists these)
	 *
	 * Tainacan's get_attachments() excludes BOTH the featured image and the
	 * document attachment from the "Anexos" panel — that's why we must wire
	 * the first ORIGINAL into both slots so additional bitstreams remain visible.
	 *
	 * @return string[] Human-readable error messages (one per failed bitstream).
	 */
	private function enrich_item_with_bitstreams( int $item_id, string $oai_identifier, string $source_url, ?int $import_id = null ): array {
		$errors = array();
		$this->log_if( $import_id, 'INFO', 'bitstream.start', '[' . $oai_identifier . '] Looking up bitstreams via ORE' );

		$bitstreams = $this->fetch_ore_bitstreams( $source_url, $oai_identifier, $import_id );

		// Fallback to METS if ORE returns nothing (some DSpace deployments emit ORE
		// with an empty oreatom:triples / no atom:link aggregates for certain items)
		if ( empty( $bitstreams ) && ! is_wp_error( $bitstreams ) ) {
			$this->log_if( $import_id, 'INFO', 'bitstream.fallback', '[' . $oai_identifier . '] ORE returned no bitstreams, trying METS' );
			$bitstreams = $this->fetch_mets_bitstreams( $source_url, $oai_identifier, $import_id );
		}

		// Final fallback: xOAI is DSpace's native format and exposes bitstreams
		// via <element name="bundles"> structure even when ORE/METS are stingy.
		if ( empty( $bitstreams ) && ! is_wp_error( $bitstreams ) ) {
			$this->log_if( $import_id, 'INFO', 'bitstream.fallback', '[' . $oai_identifier . '] METS also empty, trying xOAI (DSpace native)' );
			$bitstreams = $this->fetch_xoai_bitstreams( $source_url, $oai_identifier, $import_id );
		}

		// 4th: DSpace REST API — when available it returns a structured list with
		// bundleName ("ORIGINAL" / "THUMBNAIL") and sizeBytes, so we get high-res
		// ORIGINALs reliably instead of whatever the public HTML happens to expose.
		if ( empty( $bitstreams ) && ! is_wp_error( $bitstreams ) ) {
			$this->log_if( $import_id, 'INFO', 'bitstream.fallback', '[' . $oai_identifier . '] OAI empty, trying DSpace REST API' );
			$bitstreams = $this->fetch_dspace_rest_bitstreams( $source_url, $oai_identifier, $import_id );
		}

		// Last-resort: scrape the public DSpace handle page when ALL OAI formats
		// and the REST API come back empty. Some DSpace deployments don't list
		// bitstreams in OAI for permissions or config reasons even though the
		// public web shows them.
		if ( empty( $bitstreams ) && ! is_wp_error( $bitstreams ) ) {
			$this->log_if( $import_id, 'INFO', 'bitstream.fallback', '[' . $oai_identifier . '] REST also empty, scraping DSpace handle page' );
			$bitstreams = $this->fetch_html_bitstreams( $source_url, $oai_identifier, $import_id );
		}

		if ( is_wp_error( $bitstreams ) ) {
			$this->log_if( $import_id, 'WARN', 'bitstream.fetch_failed', '[' . $oai_identifier . '] ' . $bitstreams->get_error_message() );
			return $errors;
		}
		if ( empty( $bitstreams ) ) {
			$this->log_if( $import_id, 'INFO', 'bitstream.none', '[' . $oai_identifier . '] No bitstreams advertised by upstream (item may be metadata-only or restricted)' );
			return $errors;
		}
		$this->log_if(
			$import_id,
			'INFO',
			'bitstream.found',
			'[' . $oai_identifier . '] ' . count( $bitstreams ) . ' bitstream(s) found: ' .
			implode( ', ', array_map( fn( $b ) => ( $b['bundle'] ?? '?' ) . ' ' . basename( wp_parse_url( $b['url'], PHP_URL_PATH ) ?: '' ), $bitstreams ) )
		);

		// Drop THUMBNAIL bundle when at least one ORIGINAL exists. The DSpace
		// THUMBNAILs are small auto-derivatives — WordPress generates its own
		// sizes from the ORIGINAL on attachment_id, so keeping the upstream
		// derivative just clutters Anexos with redundant low-res copies.
		// When no ORIGINAL is available we keep the THUMBNAILs as visual fallback.
		$bitstreams = $this->drop_redundant_thumbnails( $bitstreams, $oai_identifier, $import_id );

		// Process ORIGINALs first so we can promote one before the THUMBNAILs land
		usort(
			$bitstreams,
			function ( $a, $b ) {
				$rank = fn( $x ) => $x['bundle'] === 'ORIGINAL' ? 0 : 1;
				return $rank( $a ) <=> $rank( $b );
			}
		);

		$first_original_id  = null;
		$first_thumbnail_id = null;
		foreach ( $bitstreams as $bs ) {
			$attachment_id = $this->download_bitstream( $item_id, $bs );
			if ( is_wp_error( $attachment_id ) ) {
				$errors[] = $bs['url'] . ': ' . $attachment_id->get_error_message();
				$this->log_if(
					$import_id,
					'WARN',
					'bitstream.download_failed',
					'[' . $oai_identifier . '] ' . $bs['url'] . ' → ' . $attachment_id->get_error_message()
				);
				continue;
			}
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.attached',
				'[' . $oai_identifier . '] ' . ( $bs['bundle'] ?? 'ORIGINAL' ) . ' → attachment ' . (int) $attachment_id
			);
			if ( $first_original_id === null && ( $bs['bundle'] ?? '' ) === 'ORIGINAL' ) {
				$first_original_id = (int) $attachment_id;
			}
			if ( $first_thumbnail_id === null && ( $bs['bundle'] ?? '' ) === 'THUMBNAIL' ) {
				$first_thumbnail_id = (int) $attachment_id;
			}
		}

		// Pick the best image for Tainacan documento + WordPress featured image.
		// ORIGINAL bundle = high-res full-size source.
		// THUMBNAIL bundle = DSpace-generated derivative (small, fixed resolution).
		// Prefer ORIGINAL but fall back to THUMBNAIL so the item at least gets a
		// miniatura instead of an empty media panel.
		$documento_id   = $first_original_id;
		$documento_kind = 'ORIGINAL';
		if ( $documento_id === null && $first_thumbnail_id !== null ) {
			$documento_id   = $first_thumbnail_id;
			$documento_kind = 'THUMBNAIL';
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.thumbnail_used_as_main',
				'[' . $oai_identifier . '] No ORIGINAL bundle available — using THUMBNAIL as featured image and Tainacan documento (admin can replace later if a higher-res file becomes available)'
			);
		}

		if ( $documento_id !== null ) {
			if ( ! get_post_thumbnail_id( $item_id ) ) {
				set_post_thumbnail( $item_id, $documento_id );
				$this->log_if(
					$import_id,
					'INFO',
					'bitstream.thumbnail_set',
					'[' . $oai_identifier . '] Featured image set → attachment ' . $documento_id . ' (' . $documento_kind . ')'
				);
			}

			// Tainacan main document — separate from WP featured image, drives the
			// big media viewer on the item page. Skip if already set.
			// Tainacan's Items::insert() requires entity->validate() FIRST or it
			// throws "Entities must be validated before you can save them".
			try {
				$item         = new \Tainacan\Entities\Item( $item_id );
				$current_doc  = (string) ( $item->get_document() ?? '' );
				$current_type = (string) ( $item->get_document_type() ?? '' );
				if ( $current_doc === '' || $current_doc === '0' || $current_type === '' || $current_type === 'empty' ) {
					$item->set_document( (string) $documento_id );
					$item->set_document_type( 'attachment' );
					if ( $item->validate() ) {
						\Tainacan\Repositories\Items::get_instance()->insert( $item );
						$this->log_if(
							$import_id,
							'INFO',
							'bitstream.document_set',
							'[' . $oai_identifier . '] Tainacan documento set → attachment ' . $documento_id . ' (' . $documento_kind . ')'
						);
					} else {
						$errs     = $item->get_errors();
						$msg      = is_array( $errs ) ? json_encode( $errs ) : (string) $errs;
						$errors[] = 'set_document validation failed: ' . $msg;
						$this->log_if(
							$import_id,
							'WARN',
							'bitstream.document_invalid',
							'[' . $oai_identifier . '] Tainacan rejected documento update: ' . $msg
						);
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = 'set_document: ' . $e->getMessage();
				$this->log_if(
					$import_id,
					'WARN',
					'bitstream.document_failed',
					'[' . $oai_identifier . '] set_document threw: ' . $e->getMessage()
				);
			}
		} else {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.no_visual',
				'[' . $oai_identifier . '] No ORIGINAL or THUMBNAIL bitstream — featured image and documento not set'
			);
		}

		return $errors;
	}

	/**
	 * Removes THUMBNAIL bundle bitstreams from the list when at least one
	 * ORIGINAL is present. WordPress already generates its own thumbnail
	 * sizes (150x150, 300x300, etc.) from any image attachment, so the
	 * upstream DSpace derivatives add no value to the Anexos panel.
	 *
	 * Pass-through when only THUMBNAILs are available (so the item still
	 * gets a miniatura as a last resort).
	 */
	private function drop_redundant_thumbnails( array $bitstreams, string $oai_identifier, ?int $import_id ): array {
		$originals  = 0;
		$thumbnails = 0;
		foreach ( $bitstreams as $bs ) {
			$b = $bs['bundle'] ?? '';
			if ( $b === 'ORIGINAL' ) {
				++$originals;
			} elseif ( $b === 'THUMBNAIL' ) {
				++$thumbnails;
			}
		}
		if ( $originals === 0 || $thumbnails === 0 ) {
			return $bitstreams;
		}

		$this->log_if(
			$import_id,
			'INFO',
			'bitstream.skip_thumbnails',
			'[' . $oai_identifier . '] Dropping ' . $thumbnails . ' THUMBNAIL bitstream(s) — ' . $originals . ' ORIGINAL(s) already available; WordPress will generate its own thumbnail sizes'
		);

		return array_values( array_filter( $bitstreams, fn( $bs ) => ( $bs['bundle'] ?? '' ) !== 'THUMBNAIL' ) );
	}

	/**
	 * Returns the first non-empty value for any of $keys in $bag.
	 * Multi-valued keys are joined when extracting (used for description fields).
	 * Used by create_item to find title/description across oai_dc / qdc / xoai
	 * shapes without forcing the caller to know which prefix produced the bag.
	 */

	/** No-op wrapper: only logs when an import_id was supplied (e.g. from process_batch). */


	/**
	 * GetRecord using metadataPrefix=ore and parses bitstreams.
	 *
	 * The ORE atom feed exposes:
	 *   - <atom:link rel="aggregates" type="image/jpeg" length="…" href="…"/> → all bitstreams
	 *   - <oreatom:triples><rdf:Description rdf:about="…"><dcterms:description>{ORIGINAL|THUMBNAIL}
	 * Without the triples block we default unknown URLs to ORIGINAL.
	 */
	private function fetch_ore_bitstreams( string $source_url, string $oai_identifier, ?int $import_id = null ) {
		if ( $oai_identifier === '' ) {
			return array();
		}

		$url      = $source_url . '?verb=GetRecord&metadataPrefix=ore&identifier=' . urlencode( $oai_identifier );
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.ore_request_failed',
				'[' . $oai_identifier . '] ORE GetRecord failed: ' . $response->get_error_message()
			);
			return $response;
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.ore_parse_failed',
				'[' . $oai_identifier . '] ORE response unparseable: ' . $xml->get_error_message()
			);
			return $xml;
		}
		if ( isset( $xml->error ) ) {
			$code = (string) $xml->error['code'];
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.ore_unsupported',
				'[' . $oai_identifier . '] ORE error: ' . $code . ' — will try METS fallback'
			);
			return array();
		}

		$xml->registerXPathNamespace( 'atom', self::ATOM_NS );
		$xml->registerXPathNamespace( 'oreatom', self::OREATOM_NS );
		$xml->registerXPathNamespace( 'rdf', self::RDF_NS );
		$xml->registerXPathNamespace( 'dcterms', self::DCTERMS_NS );

		// Map URL → bundle (ORIGINAL/THUMBNAIL) from ore triples
		$bundle_map = array();
		$triples    = $xml->xpath( '//oreatom:triples/rdf:Description' ) ?: array();
		foreach ( $triples as $desc ) {
			$rdf_attrs = $desc->attributes( self::RDF_NS );
			$about     = $rdf_attrs ? (string) ( $rdf_attrs->about ?? '' ) : '';
			if ( $about === '' ) {
				continue;
			}
			$dc_desc = (string) ( $desc->children( self::DCTERMS_NS )->description ?? '' );
			if ( in_array( $dc_desc, array( 'ORIGINAL', 'THUMBNAIL' ), true ) ) {
				$bundle_map[ $about ] = $dc_desc;
			}
		}

		$bitstreams = array();
		$links      = $xml->xpath( '//atom:entry/atom:link[@rel="aggregates"]' ) ?: array();
		foreach ( $links as $link ) {
			$href = (string) ( $link['href'] ?? '' );
			if ( $href === '' ) {
				continue;
			}
			$type         = (string) ( $link['type'] ?? '' );
			$length       = isset( $link['length'] ) ? (int) $link['length'] : 0;
			$bitstreams[] = array(
				'url'    => $href,
				'mime'   => $type,
				'size'   => $length,
				'bundle' => $bundle_map[ $href ] ?? 'ORIGINAL',
			);
		}

		return $bitstreams;
	}

	/**
	 * Fallback bitstream discovery via metadataPrefix=mets.
	 * Used when ORE returns empty or unsupported (some DSpace deployments
	 * answer ORE successfully but with no atom:link aggregates for items
	 * uploaded a certain way).
	 *
	 * METS uses <mets:fileGrp USE="ORIGINAL|THUMBNAIL"><mets:file>
	 *   <mets:FLocat xlink:href="..."/></mets:file></mets:fileGrp>
	 */
	private function fetch_mets_bitstreams( string $source_url, string $oai_identifier, ?int $import_id = null ) {
		if ( $oai_identifier === '' ) {
			return array();
		}

		$url      = $source_url . '?verb=GetRecord&metadataPrefix=mets&identifier=' . urlencode( $oai_identifier );
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.mets_request_failed',
				'[' . $oai_identifier . '] METS GetRecord failed: ' . $response->get_error_message()
			);
			return array();
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			return array();
		}
		if ( isset( $xml->error ) ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.mets_unsupported',
				'[' . $oai_identifier . '] METS error: ' . (string) $xml->error['code']
			);
			return array();
		}

		$METS_NS  = 'http://www.loc.gov/METS/';
		$XLINK_NS = 'http://www.w3.org/1999/xlink';
		$xml->registerXPathNamespace( 'mets', $METS_NS );
		$xml->registerXPathNamespace( 'xlink', $XLINK_NS );

		$bitstreams = array();
		$groups     = $xml->xpath( '//mets:fileGrp' ) ?: array();
		foreach ( $groups as $grp ) {
			$bundle = (string) ( $grp['USE'] ?? '' );
			if ( $bundle === '' ) {
				$bundle = 'ORIGINAL';
			}

			$files = $grp->xpath( './/mets:file' ) ?: array();
			foreach ( $files as $f ) {
				$mime = (string) ( $f['MIMETYPE'] ?? '' );
				$size = isset( $f['SIZE'] ) ? (int) $f['SIZE'] : 0;
				$locs = $f->xpath( './/mets:FLocat' ) ?: array();
				foreach ( $locs as $loc ) {
					$href_attr = $loc->attributes( $XLINK_NS );
					$href      = $href_attr ? (string) ( $href_attr->href ?? '' ) : '';
					if ( $href === '' ) {
						continue;
					}
					$bitstreams[] = array(
						'url'    => $href,
						'mime'   => $mime,
						'size'   => $size,
						'bundle' => strtoupper( $bundle ) === 'THUMBNAIL' ? 'THUMBNAIL' : 'ORIGINAL',
					);
				}
			}
		}
		return $bitstreams;
	}

	/**
	 * Third-tier fallback via DSpace's native xOAI format.
	 *
	 * xOAI structure (Lyncode):
	 *   <doc xmlns="http://www.lyncode.com/xoai">
	 *     <element name="bundles">
	 *       <element name="bundle">
	 *         <field name="name">ORIGINAL</field>
	 *         <element name="bitstreams">
	 *           <element name="bitstream">
	 *             <field name="url">https://…/bitstream/handle/…</field>
	 *             <field name="format">image/jpeg</field>
	 *             <field name="size">12345</field>
	 *           </element>
	 *           …
	 *         </element>
	 *       </element>
	 *     </element>
	 *   </doc>
	 */
	private function fetch_xoai_bitstreams( string $source_url, string $oai_identifier, ?int $import_id = null ) {
		if ( $oai_identifier === '' ) {
			return array();
		}

		$url      = $source_url . '?verb=GetRecord&metadataPrefix=xoai&identifier=' . urlencode( $oai_identifier );
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.xoai_request_failed',
				'[' . $oai_identifier . '] xOAI GetRecord failed: ' . $response->get_error_message()
			);
			return array();
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			return array();
		}
		if ( isset( $xml->error ) ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.xoai_unsupported',
				'[' . $oai_identifier . '] xOAI error: ' . (string) $xml->error['code']
			);
			return array();
		}

		$XOAI_NS = 'http://www.lyncode.com/xoai';
		$xml->registerXPathNamespace( 'x', $XOAI_NS );

		$bitstreams = array();
		// Find every <element name="bundle"> regardless of nesting depth
		$bundles = $xml->xpath( "//x:element[@name='bundle']" ) ?: array();
		foreach ( $bundles as $bundle ) {
			// Bundle's name field tells us ORIGINAL vs THUMBNAIL vs LICENSE etc
			$bundle_name_nodes = $bundle->xpath( "./x:field[@name='name']" );
			$bundle_name       = $bundle_name_nodes ? trim( (string) $bundle_name_nodes[0] ) : 'ORIGINAL';
			if ( ! in_array( strtoupper( $bundle_name ), array( 'ORIGINAL', 'THUMBNAIL' ), true ) ) {
				continue;
			}

			$bs_nodes = $bundle->xpath( ".//x:element[@name='bitstream']" ) ?: array();
			foreach ( $bs_nodes as $bs ) {
				$url_nodes = $bs->xpath( "./x:field[@name='url']" );
				if ( ! $url_nodes ) {
					continue;
				}
				$href = trim( (string) $url_nodes[0] );
				if ( $href === '' ) {
					continue;
				}

				$fmt_nodes  = $bs->xpath( "./x:field[@name='format']" );
				$size_nodes = $bs->xpath( "./x:field[@name='size']" );

				$bitstreams[] = array(
					'url'    => $href,
					'mime'   => $fmt_nodes ? trim( (string) $fmt_nodes[0] ) : '',
					'size'   => $size_nodes ? (int) trim( (string) $size_nodes[0] ) : 0,
					'bundle' => strtoupper( $bundle_name ) === 'THUMBNAIL' ? 'THUMBNAIL' : 'ORIGINAL',
				);
			}
		}
		return $bitstreams;
	}

	/**
	 * DSpace 5/6 REST API fallback. Returns full bundle structure with size info.
	 *
	 *   GET <base>/rest/handle/<handle>?expand=bitstreams
	 *   → { "bitstreams": [
	 *         { "bundleName": "ORIGINAL", "retrieveLink": "...", "sizeBytes": N,
	 *           "format": "image/jpeg", "name": "..." }, ...
	 *     ] }
	 *
	 * Many DSpace 5/6 installs keep this endpoint enabled even when their OAI
	 * suppresses bitstream listings. Returns empty list on 404 / disabled REST
	 * (typical of DSpace 7+ unless mapped to /server/api/...).
	 */
	private function fetch_dspace_rest_bitstreams( string $source_url, string $oai_identifier, ?int $import_id = null ): array {
		if ( $oai_identifier === '' ) {
			return array();
		}

		if ( ! preg_match( '/^oai:[^:]+:(.+)$/', $oai_identifier, $m ) ) {
			return array();
		}
		$handle = $m[1];

		$parts = wp_parse_url( $source_url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return array();
		}
		$base    = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host']
				. ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$api_url = $base . '/rest/handle/' . $handle . '?expand=bitstreams';

		$this->log_if(
			$import_id,
			'INFO',
			'bitstream.rest_fetch',
			'[' . $oai_identifier . '] GET ' . $api_url
		);

		$sslverify = (bool) Settings::get( 'importer_sslverify', true );
		$response  = wp_remote_get(
			$api_url,
			array(
				'timeout'     => 30,
				'sslverify'   => $sslverify,
				'redirection' => 3,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Tainacan-OAI-PMH-Importer/' . TAINACAN_OAI_PMH_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.rest_failed',
				'[' . $oai_identifier . '] REST request failed: ' . $response->get_error_message()
			);
			return array();
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.rest_unsupported',
				'[' . $oai_identifier . '] DSpace REST returned HTTP ' . $code
			);
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['bitstreams'] ) || ! is_array( $data['bitstreams'] ) ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.rest_empty',
				'[' . $oai_identifier . '] DSpace REST returned no bitstreams'
			);
			return array();
		}

		$bitstreams = array();
		foreach ( $data['bitstreams'] as $bs ) {
			$href = (string) ( $bs['retrieveLink'] ?? '' );
			if ( $href === '' ) {
				continue;
			}
			if ( strpos( $href, 'http' ) !== 0 ) {
				$href = $base . ( str_starts_with( $href, '/' ) ? $href : '/' . $href );
			}

			$bundle_name = strtoupper( (string) ( $bs['bundleName'] ?? 'ORIGINAL' ) );
			// Skip housekeeping bundles (LICENSE, METADATA, TEXT, etc.)
			if ( ! in_array( $bundle_name, array( 'ORIGINAL', 'THUMBNAIL' ), true ) ) {
				continue;
			}

			$bitstreams[] = array(
				'url'    => $href,
				'mime'   => (string) ( $bs['format'] ?? '' ),
				'size'   => isset( $bs['sizeBytes'] ) ? (int) $bs['sizeBytes'] : 0,
				'bundle' => $bundle_name,
			);
		}
		if ( ! empty( $bitstreams ) ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.rest_found',
				'[' . $oai_identifier . '] DSpace REST returned ' . count( $bitstreams ) . ' bitstream(s)'
			);
		}
		return $bitstreams;
	}

	/**
	 * Last-resort fallback: scrape the public DSpace handle page HTML for
	 * bitstream URLs. Used when ORE/METS/xOAI all come back empty — some
	 * DSpace deployments suppress bitstream listings in OAI-PMH (permissions,
	 * config, item-level overrides) even though the public website renders
	 * them just fine.
	 *
	 * Strategy:
	 *   1. Extract handle from `oai:HOST:HANDLE` (e.g. `acervo/9981`)
	 *   2. Derive site base URL from the OAI source_url
	 *   3. GET `<base>/handle/<handle>` HTML
	 *   4. Regex out every href containing `/bitstream/handle/<handle>/`
	 *   5. Classify ORIGINAL vs THUMBNAIL by the .jpg.jpg double-extension
	 *      DSpace uses for auto-generated thumbnails
	 *
	 * Less precise than OAI parsing — but robust against missing OAI metadata.
	 */
	private function fetch_html_bitstreams( string $source_url, string $oai_identifier, ?int $import_id = null ): array {
		if ( $oai_identifier === '' ) {
			return array();
		}

		// Extract handle: oai:HOST:HANDLE → HANDLE
		if ( ! preg_match( '/^oai:[^:]+:(.+)$/', $oai_identifier, $m ) ) {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.html_no_handle',
				'[' . $oai_identifier . '] Could not extract handle from identifier'
			);
			return array();
		}
		$handle = $m[1];

		// Build site base from source_url
		$parts = wp_parse_url( $source_url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return array();
		}
		$base       = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( ! empty( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$handle_url = $base . '/handle/' . $handle;

		$this->log_if(
			$import_id,
			'INFO',
			'bitstream.html_fetch',
			'[' . $oai_identifier . '] GET ' . $handle_url
		);

		$sslverify = (bool) Settings::get( 'importer_sslverify', true );
		$response  = wp_remote_get(
			$handle_url,
			array(
				'timeout'     => 30,
				'sslverify'   => $sslverify,
				'redirection' => 3,
				'headers'     => array( 'User-Agent' => 'Tainacan-OAI-PMH-Importer/' . TAINACAN_OAI_PMH_VERSION ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.html_failed',
				'[' . $oai_identifier . '] Handle page fetch failed: ' . $response->get_error_message()
			);
			return array();
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$this->log_if(
				$import_id,
				'WARN',
				'bitstream.html_failed',
				'[' . $oai_identifier . '] Handle page returned HTTP ' . $code
			);
			return array();
		}

		$html       = wp_remote_retrieve_body( $response );
		$bitstreams = array();
		$seen       = array();

		// Match every href / src that points at a bitstream of THIS handle.
		// Pattern: ".../bitstream/handle/<handle>/<filename>?<query>"
		$handle_quoted = preg_quote( '/bitstream/handle/' . $handle . '/', '#' );
		if ( preg_match_all( '#(?:href|src)\s*=\s*["\']([^"\']*' . $handle_quoted . '[^"\']+)["\']#i', $html, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				// Normalize: relative → absolute
				$href = $raw;
				if ( strpos( $href, 'http' ) !== 0 ) {
					$href = $base . ( str_starts_with( $href, '/' ) ? $href : '/' . $href );
				}
				if ( isset( $seen[ $href ] ) ) {
					continue;
				}
				$seen[ $href ] = true;

				$path = strtolower( wp_parse_url( $href, PHP_URL_PATH ) ?? '' );
				// DSpace generates thumbnails as <name>.jpg.jpg in the THUMBNAIL bundle
				$is_thumbnail = (bool) preg_match( '/\.jpg\.jpg$/', $path );

				$mime = 'application/octet-stream';
				if ( preg_match( '/\.(jpe?g)(\.jpg)?$/', $path ) ) {
					$mime = 'image/jpeg';
				} elseif ( str_ends_with( $path, '.png' ) ) {
					$mime = 'image/png';
				} elseif ( str_ends_with( $path, '.gif' ) ) {
					$mime = 'image/gif';
				} elseif ( str_ends_with( $path, '.pdf' ) ) {
					$mime = 'application/pdf';
				} elseif ( preg_match( '/\.tiff?$/', $path ) ) {
					$mime = 'image/tiff';
				} elseif ( str_ends_with( $path, '.webp' ) ) {
					$mime = 'image/webp';
				}

				$bitstreams[] = array(
					'url'    => $href,
					'mime'   => $mime,
					'size'   => 0,
					'bundle' => $is_thumbnail ? 'THUMBNAIL' : 'ORIGINAL',
				);
			}
		}

		if ( ! empty( $bitstreams ) ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.html_found',
				'[' . $oai_identifier . '] HTML scrape found ' . count( $bitstreams ) . ' bitstream(s)'
			);
		} else {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.html_empty',
				'[' . $oai_identifier . '] Handle page parsed but no bitstream links matched'
			);
			return $bitstreams;
		}

		// DSpace pages typically only inline THUMBNAILs (.jpg.jpg) — the ORIGINAL
		// bitstreams (.jpg) aren't linked from the public page in some themes.
		// Probe via HEAD: for every THUMBNAIL we found, try url-stripped of one
		// .jpg extension under a few sequence variants. If any 200-OKs back as
		// an image bigger than the thumbnail, attach it as ORIGINAL.
		$has_original = false;
		foreach ( $bitstreams as $bs ) {
			if ( ( $bs['bundle'] ?? '' ) === 'ORIGINAL' ) {
				$has_original = true;
				break; }
		}
		if ( ! $has_original ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.probing',
				'[' . $oai_identifier . '] Only THUMBNAILs in HTML — probing for ORIGINAL versions'
			);
			$sslverify = (bool) Settings::get( 'importer_sslverify', true );
			$extra     = array();
			foreach ( $bitstreams as $bs ) {
				if ( ( $bs['bundle'] ?? '' ) !== 'THUMBNAIL' ) {
					continue;
				}
				$found = $this->probe_dspace_original( $bs['url'], $sslverify, $import_id, $oai_identifier );
				if ( $found ) {
					$extra[] = $found;
				}
			}
			if ( ! empty( $extra ) ) {
				$bitstreams = array_merge( $bitstreams, $extra );
			}
		}

		return $bitstreams;
	}

	/**
	 * For a DSpace THUMBNAIL URL like
	 *   /bitstream/handle/H/foo.jpg.jpg?sequence=15&isAllowed=y
	 * try the matching ORIGINAL at
	 *   /bitstream/handle/H/foo.jpg            (no query string)
	 *
	 * DSpace resolves bitstreams by FILENAME when no sequence is supplied,
	 * so this always serves the correct ORIGINAL (verified empirically against
	 * DAMI). We deliberately AVOID guessing sequence numbers because the
	 * filename in the URL is decorative — DSpace looks up by sequence first
	 * and ignores the URL filename, so a wrong sequence guess could attach a
	 * DIFFERENT item's original under this thumbnail's name.
	 */
	private function probe_dspace_original( string $thumb_url, bool $sslverify, ?int $import_id, string $oai_identifier ): ?array {
		// Strip exactly one trailing .jpg
		$stripped = preg_replace( '/\.jpg\.jpg(\?|$)/i', '.jpg$1', $thumb_url, 1, $count );
		if ( ! $count ) {
			return null;
		}

		// Drop the query string — without ?sequence=, DSpace matches by filename
		$candidate = preg_replace( '/\?.*$/', '', $stripped );

		$head = wp_remote_head(
			$candidate,
			array(
				'timeout'     => 10,
				'sslverify'   => $sslverify,
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $head ) ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.probe_failed',
				'[' . $oai_identifier . '] HEAD ' . $candidate . ' → ' . $head->get_error_message()
			);
			return null;
		}
		$code = wp_remote_retrieve_response_code( $head );
		if ( $code !== 200 ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.probe_no_match',
				'[' . $oai_identifier . '] HEAD ' . $candidate . ' → HTTP ' . $code
			);
			return null;
		}
		$type = (string) wp_remote_retrieve_header( $head, 'content-type' );
		if ( stripos( $type, 'image/' ) !== 0 ) {
			$this->log_if(
				$import_id,
				'INFO',
				'bitstream.probe_not_image',
				'[' . $oai_identifier . '] HEAD ' . $candidate . ' → ' . $type
			);
			return null;
		}
		$cl = (int) wp_remote_retrieve_header( $head, 'content-length' );

		$this->log_if(
			$import_id,
			'INFO',
			'bitstream.probed_original',
			'[' . $oai_identifier . '] Found ORIGINAL via probe: ' . $candidate
			. ( $cl > 0 ? ' (' . round( $cl / 1048576, 2 ) . ' MB)' : '' ) . ', ' . $type
		);
		return array(
			'url'    => $candidate,
			'mime'   => trim( explode( ';', $type )[0] ),
			'size'   => $cl,
			'bundle' => 'ORIGINAL',
		);
	}

	/**
	 * Sideloads a remote file as a WordPress attachment under the given item.
	 *
	 * Pre-flights via HEAD to skip oversize files without downloading them.
	 * Skips bitstreams already imported (matches on _oai_bitstream_url postmeta).
	 *
	 * @return int|\WP_Error Attachment ID on success.
	 */
	private function download_bitstream( int $item_id, array $bitstream ) {
		if ( empty( $bitstream['url'] ) ) {
			return new \WP_Error( 'empty_url', 'Empty bitstream URL.' );
		}

		$url       = $bitstream['url'];
		$max_bytes = max( 1, (int) Settings::get( 'import_max_size_mb', 20 ) ) * 1024 * 1024;
		$sslverify = (bool) Settings::get( 'importer_sslverify', true );

		// Dedup: same item already has this bitstream attached
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => $item_id,
				'meta_key'       => '_oai_bitstream_url',
				'meta_value'     => $url,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		// Pre-flight size check via HEAD — saves bandwidth on oversize files
		$head = wp_remote_head(
			$url,
			array(
				'timeout'     => 30,
				'sslverify'   => $sslverify,
				'redirection' => 3,
			)
		);
		if ( ! is_wp_error( $head ) ) {
			$cl = (int) wp_remote_retrieve_header( $head, 'content-length' );
			if ( $cl > 0 && $cl > $max_bytes ) {
				return new \WP_Error(
					'too_large',
					sprintf(
						'Bitstream is %s MB, exceeds %s MB limit.',
						number_format( $cl / 1048576, 1 ),
						number_format( $max_bytes / 1048576, 0 )
					)
				);
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Verify size after download (handles servers that don't send Content-Length)
		$actual = @filesize( $tmp ) ?: 0;
		if ( $actual > $max_bytes ) {
			wp_delete_file( $tmp );
			return new \WP_Error(
				'too_large',
				sprintf(
					'Downloaded file is %s MB, exceeds %s MB limit.',
					number_format( $actual / 1048576, 1 ),
					number_format( $max_bytes / 1048576, 0 )
				)
			);
		}

		// Build a clean filename from the URL path (decode + sanitize)
		$path     = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
		$basename = basename( $path );
		$filename = sanitize_file_name( urldecode( $basename ) );
		if ( $filename === '' || ! str_contains( $filename, '.' ) ) {
			// Fall back to a hash if the URL has no usable filename
			$ext      = $this->guess_ext_from_mime( $bitstream['mime'] ?? '' );
			$filename = 'bitstream-' . substr( md5( $url ), 0, 12 ) . $ext;
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// Suppress WP MIME-by-extension check failures by passing the upstream MIME hint
		$overrides = array( 'test_form' => false );
		if ( ! empty( $bitstream['mime'] ) ) {
			// tell WP what to expect; otherwise sideload may reject .jpg.jpg etc.
			add_filter(
				'upload_mimes',
				$mime_filter = function ( $mimes ) use ( $bitstream ) {
					$mimes['jpg|jpeg|jpe'] = 'image/jpeg';
					return $mimes;
				}
			);
		}

		$attachment_id = media_handle_sideload( $file_array, $item_id, null, $overrides );

		if ( isset( $mime_filter ) ) {
			remove_filter( 'upload_mimes', $mime_filter );
		}

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_oai_bitstream_url', $url );
		update_post_meta( $attachment_id, '_oai_bitstream_bundle', $bitstream['bundle'] ?? 'ORIGINAL' );
		if ( ! empty( $bitstream['mime'] ) ) {
			update_post_meta( $attachment_id, '_oai_bitstream_mime', $bitstream['mime'] );
		}
		return (int) $attachment_id;
	}

	private function guess_ext_from_mime( string $mime ): string {
		$map = array(
			'image/jpeg'      => '.jpg',
			'image/png'       => '.png',
			'image/gif'       => '.gif',
			'image/webp'      => '.webp',
			'image/tiff'      => '.tif',
			'application/pdf' => '.pdf',
		);
		return $map[ $mime ] ?? '.bin';
	}

	/**
	 * Appends a structured entry to the import's activity log.
	 *
	 * Format:  [YYYY-MM-DD HH:MM:SS] [LEVEL] [code] message
	 * Levels:  INFO  — normal lifecycle (created, updated, skipped reasons…)
	 *          WARN  — non-fatal anomaly (token unexpectedly empty, partial data)
	 *          ERROR — failure (HTTP, parse, validation, mapping)
	 *
	 * Caps total log at 256 KB so verbose runs don't bloat the imports row.
	 */

	/**
	 * Resolves the items that belong to a given import job.
	 *
	 * Strategy (precise → permissive):
	 *   1. Direct: items tagged with _tainacan_oai_import_id = $import_id (only
	 *      possible for items created after this column existed).
	 *   2. Legacy fallback: items in the import's target collection that carry
	 *      _tainacan_oai_source_id matching the import's source URL host
	 *      (oai:HOST:…). This covers items imported before tagging existed.
	 *
	 * Returned IDs are de-duplicated.
	 */


	/**
	 * Deletes one import. Always removes the imports row + activity log.
	 * If $delete_items is true, also moves every item the job created to Trash
	 * (and trashes their bitstream attachments). Items go to Trash, not
	 * permanent delete, so admins can recover from Trash if needed.
	 *
	 * @return array{import_deleted:bool, items_trashed:int, attachments_trashed:int}
	 */
	public function delete_import( int $import_id, bool $delete_items = false ): array {
		$stats = array(
			'import_deleted'      => false,
			'items_trashed'       => 0,
			'attachments_trashed' => 0,
		);

		if ( $delete_items ) {
			$item_ids = $this->find_import_items( $import_id );
			foreach ( $item_ids as $iid ) {
				// Trash bitstream attachments (post_parent = item_id).
				$atts = get_posts(
					array(
						'post_type'      => 'attachment',
						'post_parent'    => $iid,
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'post_status'    => 'any',
					)
				);
				foreach ( $atts as $aid ) {
					if ( wp_trash_post( $aid ) ) {
						++$stats['attachments_trashed'];
					}
				}
				if ( wp_trash_post( $iid ) ) {
					++$stats['items_trashed'];
				}
			}
		}

		$stats['import_deleted'] = $this->imports->delete( $import_id );
		return $stats;
	}


	/** Wipes the activity log for one import. */

	/**
	 * Back-compat thin wrapper. New code should call append_log() directly.
	 *
	 * @deprecated use append_log()
	 */
	private function append_error_log( int $import_id, string $code, string $message ): void {
		$this->append_log( $import_id, 'ERROR', $code, $message );
	}


	/**
	 * SSRF guard: reject URLs that resolve to private/loopback/link-local IPs
	 * unless explicitly allowed in settings (for self-hosted WP testing local OAI).
	 */
}
