<?php
/**
 * OAI-PMH cache: maintains a denormalized projection of Tainacan items
 * for the OAI-PMH provider endpoint.
 *
 * The cache table has no WordPress equivalent (no CPT covers this shape),
 * so direct $wpdb access is necessary. Each query carries a line-level
 * phpcs:ignore with the specific justification, replacing the previous
 * file-level disable block.
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projection of Tainacan items into an OAI-PMH-ready cache table.
 */
class Cache {

	/**
	 * Fully-qualified cache table name (prefixed).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor: resolves the prefixed table name from $wpdb.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'tainacan_oai_cache';
	}

	/**
	 * Fetches a single cached row by item ID.
	 *
	 * @param int $item_id Tainacan item ID.
	 * @return object|null Row from the cache table or null when missing.
	 */
	public function get_item( $item_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned cache table; $this->table from $wpdb->prefix (trusted); value via %d placeholder.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE item_id = %d",
				$item_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Paginated query over the cache, filtered by status/collection/date range.
	 *
	 * @param array $args Query args (per_page, page, status[], collection_id, from, until).
	 * @return array Array of row objects.
	 */
	public function get_items( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'      => 100,
			'page'          => 1,
			'status'        => array( 'publish' ),
			'collection_id' => null,
			'from'          => null,
			'until'         => null,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
			$where[]      = "status IN ($placeholders)";
			$params       = array_merge( $params, $args['status'] );
		}

		if ( $args['collection_id'] ) {
			$where[]  = 'collection_id = %d';
			$params[] = $args['collection_id'];
		}

		if ( $args['from'] ) {
			$where[]  = 'datestamp >= %s';
			$params[] = $args['from'];
		}

		if ( $args['until'] ) {
			$where[]  = 'datestamp <= %s';
			$params[] = $args['until'];
		}

		$offset   = ( $args['page'] - 1 ) * $args['per_page'];
		$params[] = $args['per_page'];
		$params[] = $offset;

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) .
				' ORDER BY item_id ASC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is built from %s/%d placeholders + the trusted table name; values pass through prepare(). IN-clause uses array_fill of %s matching $args['status'] count.
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * COUNT(*) version of get_items() with the same filters.
	 *
	 * @param array $args Same args as get_items().
	 * @return int
	 */
	public function count_items( $args = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
			$where[]      = "status IN ($placeholders)";
			$params       = array_merge( $params, $args['status'] );
		}

		if ( ! empty( $args['collection_id'] ) ) {
			$where[]  = 'collection_id = %d';
			$params[] = $args['collection_id'];
		}

		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'datestamp >= %s';
			$params[] = $args['from'];
		}

		if ( ! empty( $args['until'] ) ) {
			$where[]  = 'datestamp <= %s';
			$params[] = $args['until'];
		}

		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode( ' AND ', $where );

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql built with %s/%d placeholders + IN-clause via array_fill matching count.
			$sql = $wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is either a constant query or already prepared above; count of a write-mostly table — caching would mask freshness.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Inserts or updates the cache row for a given Tainacan item.
	 *
	 * Uses $wpdb->insert()/update() — these accept array args and escape values
	 * internally; no manual prepare() needed.
	 *
	 * @param int|\Tainacan\Entities\Item $item Item entity or ID.
	 * @return bool True on success, false on invalid item.
	 */
	public function index_item( $item ) {
		global $wpdb;

		if ( is_numeric( $item ) ) {
			$item = new \Tainacan\Entities\Item( $item );
		}

		if ( ! $item->get_id() ) {
			return false;
		}

		$collection = $item->get_collection();
		$dc_data    = $this->get_item_dc( $item );
		$checksum   = md5( (string) wp_json_encode( $dc_data ) );

		$existing = $this->get_item( $item->get_id() );
		if ( $existing && $existing->checksum === $checksum ) {
			return true;
		}

		$data = array(
			'item_id'       => $item->get_id(),
			'collection_id' => $collection->get_id(),
			'identifier'    => $this->build_identifier( $item->get_id() ),
			'datestamp'     => gmdate(
				'Y-m-d\TH:i:s\Z',
				strtotime( $item->get_modification_date() ? $item->get_modification_date() : $item->get_creation_date() )
			),
			'metadata_json' => wp_json_encode( $dc_data ),
			'status'        => $item->get_status(),
			'checksum'      => $checksum,
			'last_indexed'  => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned cache table; $wpdb->update() escapes values; caching would mask the write.
			$wpdb->update( $this->table, $data, array( 'item_id' => $item->get_id() ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned cache table; $wpdb->insert() escapes values; caching would mask the write.
			$wpdb->insert( $this->table, $data );
		}

		return true;
	}

	/**
	 * Builds an OAI-PMH-style identifier for a Tainacan item.
	 *
	 * @param int $item_id Item ID.
	 * @return string Identifier in the form `oai:<host>:<id>`.
	 */
	private function build_identifier( $item_id ) {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		return "oai:{$domain}:{$item_id}";
	}

	/**
	 * Reverses build_identifier() to recover the Tainacan item ID.
	 *
	 * @param string $identifier OAI identifier.
	 * @return int Item ID, or 0 when malformed.
	 */
	public function extract_item_id( $identifier ) {
		$parts = explode( ':', $identifier );
		return (int) end( $parts );
	}

	/**
	 * Projects a Tainacan item into a Dublin Core array.
	 *
	 * @param \Tainacan\Entities\Item $item Item to project.
	 * @return array<string,mixed> Dublin Core fields.
	 */
	private function get_item_dc( $item ) {
		$dc = array(
			'title'      => $item->get_title(),
			'identifier' => get_permalink( $item->get_id() ),
			'date'       => gmdate( 'Y-m-d', strtotime( $item->get_creation_date() ) ),
		);

		if ( $item->get_description() ) {
			$dc['description'] = $item->get_description();
		}

		$metadata = $item->get_metadata();
		if ( is_array( $metadata ) ) {
			foreach ( $metadata as $item_meta ) {
				$metadatum = $item_meta->get_metadatum();
				if ( ! $metadatum ) {
					continue;
				}

				$mapping = $metadatum->get_exposer_mapping();
				if ( ! empty( $mapping['dublin-core'] ) ) {
					$field = str_replace( 'dc:', '', $mapping['dublin-core'] );
					$value = $item_meta->get_value_as_string();

					if ( ! empty( $value ) ) {
						if ( isset( $dc[ $field ] ) ) {
							if ( ! is_array( $dc[ $field ] ) ) {
								$dc[ $field ] = array( $dc[ $field ] );
							}
							$dc[ $field ][] = $value;
						} else {
							$dc[ $field ] = $value;
						}
					}
				}
			}
		}

		return $dc;
	}

	/**
	 * Updates the status column for a cached item (e.g. publish → trash).
	 *
	 * @param int    $item_id Item ID.
	 * @param string $status  New status value.
	 * @return void
	 */
	public function update_item_status( $item_id, $status ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned cache table; $wpdb->update() escapes values.
		$wpdb->update( $this->table, array( 'status' => $status ), array( 'item_id' => $item_id ) );
	}

	/**
	 * Rebuilds the full cache by iterating every Tainacan item.
	 *
	 * @param callable|null $callback Optional progress callback `(int $total, int $item_id)`.
	 * @return int Total items indexed.
	 */
	public function rebuild_index( $callback = null ) {
		$repo  = \Tainacan\Repositories\Items::get_instance();
		$page  = 1;
		$total = 0;

		do {
			$items = $repo->fetch(
				array(
					'posts_per_page' => 50,
					'paged'          => $page,
					'post_status'    => array( 'publish', 'private' ),
				),
				array(),
				'OBJECT'
			);

			if ( ! is_array( $items ) || empty( $items ) ) {
				break;
			}

			$batch_size = count( $items );
			foreach ( $items as $item ) {
				$this->index_item( $item );
				++$total;
				if ( $callback ) {
					call_user_func( $callback, $total, $item->get_id() );
				}
			}

			++$page;
		} while ( 50 === $batch_size );

		return $total;
	}

	/**
	 * Rebuilds the cache for a single collection.
	 *
	 * @param int $collection_id Collection ID.
	 * @return int Total items indexed.
	 */
	public function reindex_collection( $collection_id ) {
		$repo       = \Tainacan\Repositories\Items::get_instance();
		$collection = new \Tainacan\Entities\Collection( $collection_id );

		if ( ! $collection->get_id() ) {
			return 0;
		}

		$page  = 1;
		$total = 0;

		do {
			$items = $repo->fetch(
				array(
					'posts_per_page' => 50,
					'paged'          => $page,
					'post_status'    => array( 'publish', 'private' ),
				),
				$collection,
				'OBJECT'
			);

			if ( ! is_array( $items ) || empty( $items ) ) {
				break;
			}

			$batch_size = count( $items );
			foreach ( $items as $item ) {
				$this->index_item( $item );
				++$total;
			}

			++$page;
		} while ( 50 === $batch_size );

		return $total;
	}

	/**
	 * Empties the cache. Used by maintenance / rebuild flows.
	 *
	 * @return void
	 */
	public function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned cache table; TRUNCATE has no WP wrapper; $this->table is built from $wpdb->prefix.
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}

	/**
	 * Aggregate stats for the dashboard.
	 *
	 * @return array<string,int|string|null>
	 */
	public function get_stats() {
		global $wpdb;

		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned cache table; aggregate COUNT/MAX over $this->table; constant SQL, no user input.
			'total_items'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above; constant predicate.
			'published_items' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'publish'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above; constant predicate.
			'deleted_items'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE status = 'trash'" ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above; constant aggregate.
			'last_indexed'    => $wpdb->get_var( "SELECT MAX(last_indexed) FROM {$this->table}" ),
		);
	}

	/**
	 * Per-collection breakdown of cached published items.
	 *
	 * Batches collection-name lookups into one IN query instead of N
	 * Collection() constructor calls.
	 *
	 * @return array<int,array<string,int|string>> List of {id,name,count} rows.
	 */
	public function get_collection_stats() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned cache table; GROUP BY aggregate over $this->table (from $wpdb->prefix); no user input.
		$results = $wpdb->get_results(
			"SELECT collection_id, COUNT(*) as count
			 FROM {$this->table}
			 WHERE status = 'publish'
			 GROUP BY collection_id"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $results ) ) {
			return array();
		}

		$ids          = array_map( static fn( $r ) => (int) $r->collection_id, $results );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IN-clause built via array_fill of %d matching count($ids); IDs int-casted via array_map static fn; prepare() runs over assembled SQL.
		$names = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts}
				 WHERE ID IN ($placeholders) AND post_type = 'tainacan-collection'",
				$ids
			),
			OBJECT_K
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$stats = array();
		foreach ( $results as $row ) {
			$cid = (int) $row->collection_id;
			if ( ! isset( $names[ $cid ] ) ) {
				continue;
			}
			$stats[] = array(
				'id'    => $cid,
				'name'  => $names[ $cid ]->post_title,
				'count' => (int) $row->count,
			);
		}
		return $stats;
	}

	/**
	 * Health check: WP-vs-cache sync ratio + stale entries.
	 *
	 * @return array<string,int|float|string>
	 */
	public function get_health() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate COUNT over $wpdb->posts; using repo->fetch(-1) would load every item into memory. LIKE pattern is a constant prefix; no user input.
		$wp_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type LIKE 'tnc_col_%_item' AND post_status IN ('publish', 'private')"
		);

		$cache_count = $this->count_items( array( 'status' => array( 'publish', 'private' ) ) );
		$sync_pct    = $wp_count > 0 ? round( ( $cache_count / $wp_count ) * 100, 1 ) : 100;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned cache table; $this->table from $wpdb->prefix (trusted); threshold via %s placeholder.
		$outdated = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE last_indexed < %s",
				gmdate( 'Y-m-d H:i:s', time() - 86400 )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'wp_items'        => $wp_count,
			'cached_items'    => $cache_count,
			'sync_percentage' => $sync_pct,
			'outdated_items'  => $outdated,
			'status'          => $sync_pct >= 95 ? 'healthy' : ( $sync_pct >= 70 ? 'warning' : 'critical' ),
		);
	}
}
