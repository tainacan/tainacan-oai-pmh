<?php
/**
 * Postmeta-backed dedup resolver for OAI-imported Tainacan items.
 *
 * Every item we create stamps the OAI identifier into postmeta
 * (_tainacan_oai_source_id). Subsequent imports use this resolver to
 * answer "do I already have this OAI identifier in this collection?".
 *
 * Why direct $wpdb here is intentional (not an avoidable disable):
 *  - We need to scope by post_type = `tnc_col_<id>_item` AND filter by
 *    post_status. WP_Query with meta_query CAN express this but pays a
 *    JOIN with post_status_filter quirks that produce wrong results on
 *    trashed posts; the explicit JOIN/predicate set here is verified.
 *  - The dedup query runs once per record in the import loop and must
 *    be cheap — get_posts(meta_query) is measurably slower.
 *
 * Each suppression in this file carries that justification per line.
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find / restore items by OAI identifier.
 */
class Item_Resolver {

	public const META_KEY_SOURCE_ID        = '_tainacan_oai_source_id';
	public const META_KEY_SOURCE_DATESTAMP = '_tainacan_oai_source_datestamp';
	public const META_KEY_IMPORT_ID        = '_tainacan_oai_import_id';

	/**
	 * Active item ID for this OAI identifier in the given collection, or null.
	 *
	 * Trashed/auto-draft posts are excluded so a previous "Delete import"
	 * doesn't pollute the dedup check — those go through
	 * find_trashed_by_oai_identifier() and get restored instead.
	 *
	 * Collection scoping: passing $collection_id constrains the lookup to
	 * post_type `tnc_col_<id>_item`. Without it, the same OAI identifier in
	 * two collections would dedup-match whichever is found first.
	 *
	 * @param string   $oai_identifier
	 * @param int|null $collection_id
	 * @return int|null
	 */
	public function find_by_oai_identifier( string $oai_identifier, ?int $collection_id = null ): ?int {
		if ( $oai_identifier === '' ) {
			return null;
		}
		return $this->find_in_status( $oai_identifier, $collection_id, false );
	}

	/**
	 * Counterpart of find_by_oai_identifier() scoped to trashed items only.
	 *
	 * @param string   $oai_identifier
	 * @param int|null $collection_id
	 * @return int|null
	 */
	public function find_trashed_by_oai_identifier( string $oai_identifier, ?int $collection_id = null ): ?int {
		if ( $oai_identifier === '' ) {
			return null;
		}
		return $this->find_in_status( $oai_identifier, $collection_id, true );
	}

	/**
	 * Returns active item IDs for the same OAI identifier in *other* collections,
	 * for informational logging only. Helps admins notice that the same source
	 * record was previously imported elsewhere.
	 *
	 * @param string $oai_identifier
	 * @param int    $exclude_collection_id
	 * @return array<int,array{id:int,collection_id:int}>
	 */
	public function find_in_other_collections( string $oai_identifier, int $exclude_collection_id ): array {
		if ( $oai_identifier === '' ) {
			return array();
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional postmeta dedup query; scoped to tnc_col_<n>_item post_types; placeholders used for all values; the SlowDBQuery sniff fires on meta_key/meta_value lookups that are exactly what we need here.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, p.post_type FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %s
				   AND p.post_type LIKE %s
				   AND p.post_type <> %s
				   AND p.post_status NOT IN ('trash', 'auto-draft')",
				self::META_KEY_SOURCE_ID,
				$oai_identifier,
				'tnc_col_%_item',
				'tnc_col_' . $exclude_collection_id . '_item'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$out = array();
		foreach ( $rows as $r ) {
			if ( preg_match( '/^tnc_col_(\d+)_item$/', $r->post_type, $m ) ) {
				$out[] = array(
					'id'            => (int) $r->post_id,
					'collection_id' => (int) $m[1],
				);
			}
		}
		return $out;
	}

	/**
	 * Checks whether the given item already has at least one attachment that
	 * was sideloaded by a previous OAI import (tagged with _oai_bitstream_url).
	 *
	 * @param int $item_id
	 * @return bool
	 */
	public function item_has_oai_bitstreams( int $item_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional postmeta lookup of attachments tagged with _oai_bitstream_url; cheap thanks to LIMIT 1; bitstream presence changes mid-import so caching would mask state.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_parent = %d AND pm.meta_key = '_oai_bitstream_url' LIMIT 1",
				$item_id
			)
		);
	}

	/**
	 * Restores trashed bitstream attachments belonging to the given item.
	 *
	 * @param int $item_id
	 * @return int Number of attachments untrashed.
	 */
	public function untrash_attachments( int $item_id ): int {
		$atts  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => $item_id,
				'post_status'    => 'trash',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$count = 0;
		foreach ( $atts as $aid ) {
			if ( wp_untrash_post( $aid ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Shared implementation for find_by_oai_identifier / find_trashed_by_oai_identifier.
	 *
	 * @param string   $oai_identifier
	 * @param int|null $collection_id
	 * @param bool     $trashed_only   When true, only trashed posts match.
	 * @return int|null
	 */
	private function find_in_status( string $oai_identifier, ?int $collection_id, bool $trashed_only ): ?int {
		global $wpdb;

		$status_predicate = $trashed_only
			? "p.post_status = 'trash'"
			: "p.post_status NOT IN ('trash', 'auto-draft')";

		$sql  = "SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %s
				   AND {$status_predicate}";
		$args = array( self::META_KEY_SOURCE_ID, $oai_identifier );
		if ( $collection_id !== null && $collection_id > 0 ) {
			$sql   .= ' AND p.post_type = %s';
			$args[] = 'tnc_col_' . (int) $collection_id . '_item';
		}
		$sql .= ' LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional postmeta dedup query; $status_predicate is a hardcoded branch, all user-controlled values via %s placeholders; SlowDBQuery sniff fires on meta lookups that are exactly the point.
		$found = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		return $found ? (int) $found : null;
	}
}
