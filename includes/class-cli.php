<?php
namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class CLI {

	/**
	 * Rebuild OAI-PMH index
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Clear cache before reindexing
	 *
	 * [--collection=<id>]
	 * : Only reindex specific collection
	 *
	 * ## EXAMPLES
	 *     wp tainacan-oai reindex
	 *     wp tainacan-oai reindex --clear
	 *     wp tainacan-oai reindex --collection=123
	 *
	 * @when after_wp_load
	 */
	public function reindex( $args, $assoc_args ) {
		$cache = new Cache();

		if ( ! empty( $assoc_args['clear'] ) ) {
			$cache->clear();
			\WP_CLI::log( 'Cache cleared.' );
		}

		\WP_CLI::log( 'Starting reindex...' );
		$start = microtime( true );

		if ( ! empty( $assoc_args['collection'] ) ) {
			$count = $cache->reindex_collection( (int) $assoc_args['collection'] );
		} else {
			$count = $cache->rebuild_index(
				function ( $n, $id ) {
					if ( $n % 100 === 0 ) {
						\WP_CLI::log( "Indexed $n items..." );
					}
				}
			);
		}

		$time = round( microtime( true ) - $start, 2 );
		\WP_CLI::success( "Indexed $count items in {$time}s" );
	}

	/**
	 * Clear OAI-PMH cache
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		$cache = new Cache();
		$cache->clear();
		\WP_CLI::success( 'Cache cleared.' );
	}

	/**
	 * Show statistics
	 *
	 * @when after_wp_load
	 */
	public function stats( $args, $assoc_args ) {
		$cache  = new Cache();
		$stats  = $cache->get_stats();
		$health = $cache->get_health();

		\WP_CLI::log( 'OAI-PMH Statistics' );
		\WP_CLI::log( '==================' );
		\WP_CLI::log( "Total Items: {$stats['total_items']}" );
		\WP_CLI::log( "Published: {$stats['published_items']}" );
		\WP_CLI::log( "Deleted: {$stats['deleted_items']}" );
		\WP_CLI::log( 'Last Indexed: ' . ( $stats['last_indexed'] ?: 'Never' ) );
		\WP_CLI::log( "Sync: {$health['sync_percentage']}%" );
		\WP_CLI::log( "Status: {$health['status']}" );
	}

	/**
	 * Run validation
	 *
	 * @when after_wp_load
	 */
	public function validate( $args, $assoc_args ) {
		$validator = new Validator();
		$results   = $validator->run();

		\WP_CLI::log( "Score: {$results['score']}%" );
		\WP_CLI::log( "Passed: {$results['passed']}" );
		\WP_CLI::log( "Warnings: {$results['warnings']}" );
		\WP_CLI::log( "Failed: {$results['failed']}" );

		foreach ( $results['tests'] as $test ) {
			$icon = $test['status'] === 'passed' ? '✓' : ( $test['status'] === 'warning' ? '!' : '✗' );
			\WP_CLI::log( "[$icon] {$test['name']}" );
		}
	}

	/**
	 * Show endpoint info
	 *
	 * @when after_wp_load
	 */
	public function info( $args, $assoc_args ) {
		\WP_CLI::log( 'Endpoint: ' . rest_url( 'tainacan-oai/v1/oai' ) );
		\WP_CLI::log( 'Repository: ' . Settings::get( 'repository_name', get_bloginfo( 'name' ) ) );
		\WP_CLI::log( 'Max Records: ' . Settings::get( 'max_records', 100 ) );
		\WP_CLI::log( 'Cache: ' . ( Settings::get( 'cache_enabled', true ) ? 'Enabled' : 'Disabled' ) );
	}

	/**
	 * Removes legacy THUMBNAIL bundle attachments left behind by pre-0.6.4
	 * imports, when the parent item already has a separate ORIGINAL bundle
	 * attachment. Optionally re-points Tainacan documento + WordPress
	 * featured image to the ORIGINAL when they were previously pointing at
	 * the (now-removed) THUMBNAIL.
	 *
	 * Safe by default: only operates on items where BOTH an ORIGINAL and a
	 * THUMBNAIL exist. Items with only a THUMBNAIL (the legitimate fallback
	 * case from the 0.6.4 two-pass policy) are never touched.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List candidate attachments without deleting anything.
	 *
	 * [--repoint]
	 * : Before deleting a THUMBNAIL, check whether its parent item is using
	 *   it as Tainacan documento and/or WP featured image; if so, repoint
	 *   those to the ORIGINAL attachment. Without this flag, items keeping
	 *   the THUMBNAIL as documento are SKIPPED (their attachment is not
	 *   touched so the item's display doesn't break).
	 *
	 * [--force]
	 * : Permanent delete via wp_delete_attachment(true). Default is
	 *   wp_trash_post() so admins can recover from /wp-admin/upload.php.
	 *
	 * ## EXAMPLES
	 *     wp tainacan-oai cleanup-legacy-thumbnails --dry-run
	 *     wp tainacan-oai cleanup-legacy-thumbnails --repoint
	 *     wp tainacan-oai cleanup-legacy-thumbnails --repoint --force
	 *
	 * @subcommand cleanup-legacy-thumbnails
	 * @when after_wp_load
	 */
	public function cleanup_legacy_thumbnails( $args, $assoc_args ) {
		global $wpdb;
		$dry     = ! empty( $assoc_args['dry-run'] );
		$force   = ! empty( $assoc_args['force'] );
		$repoint = ! empty( $assoc_args['repoint'] );

		// Locate every plugin-tagged THUMBNAIL attachment whose parent has a
		// separate ORIGINAL also tagged by the plugin. Single query: JOIN
		// postmeta twice (once on THUMBNAIL row, once on ORIGINAL row of a
		// sibling attachment under the same parent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot CLI maintenance task; bypassing object cache is desired so admin sees fresh state.
		$rows = $wpdb->get_results(
			"SELECT thumb.ID AS thumb_id, thumb.post_parent, thumb.post_title, orig.ID AS original_id
			 FROM {$wpdb->posts} AS thumb
			 JOIN {$wpdb->postmeta} AS thumb_meta
			   ON thumb_meta.post_id = thumb.ID
			   AND thumb_meta.meta_key = '_oai_bitstream_bundle'
			   AND thumb_meta.meta_value = 'THUMBNAIL'
			 JOIN {$wpdb->posts} AS orig
			   ON orig.post_parent = thumb.post_parent
			   AND orig.post_type = 'attachment'
			   AND orig.post_status NOT IN ('trash','auto-draft')
			   AND orig.ID != thumb.ID
			 JOIN {$wpdb->postmeta} AS orig_meta
			   ON orig_meta.post_id = orig.ID
			   AND orig_meta.meta_key = '_oai_bitstream_bundle'
			   AND orig_meta.meta_value = 'ORIGINAL'
			 WHERE thumb.post_type = 'attachment'
			   AND thumb.post_status NOT IN ('trash','auto-draft')
			   AND thumb.post_parent > 0
			 GROUP BY thumb.ID"
		);

		if ( empty( $rows ) ) {
			\WP_CLI::success( 'No legacy THUMBNAIL attachments to clean up.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d legacy THUMBNAIL attachment(s).', count( $rows ) ) );

		$deleted     = 0;
		$repointed   = 0;
		$skipped     = 0;
		$repoint_err = 0;

		foreach ( $rows as $r ) {
			$thumb_id    = (int) $r->thumb_id;
			$original_id = (int) $r->original_id;
			$parent_id   = (int) $r->post_parent;

			// Detect whether the parent item is using the THUMBNAIL as documento
			// or featured image. Without --repoint, skip those entirely so we
			// don't visually break the item.
			$featured       = (int) get_post_thumbnail_id( $parent_id );
			$is_featured    = ( $featured === $thumb_id );
			$is_documento   = false;
			$tainacan_avail = class_exists( '\Tainacan\Entities\Item' ) && class_exists( '\Tainacan\Repositories\Items' );
			if ( $tainacan_avail ) {
				try {
					$item = new \Tainacan\Entities\Item( $parent_id );
					if ( $item->get_id() ) {
						$current_doc  = (string) ( $item->get_document() ?? '' );
						$current_type = (string) ( $item->get_document_type() ?? '' );
						$is_documento = ( $current_type === 'attachment' && (int) $current_doc === $thumb_id );
					}
				} catch ( \Throwable $e ) {
					$tainacan_avail = false;
				}
			}

			$pretty = sprintf( 'attachment %d (parent item %d): "%s"', $thumb_id, $parent_id, $r->post_title );
			if ( $is_featured ) {
				$pretty .= ' [is featured]';
			}
			if ( $is_documento ) {
				$pretty .= ' [is Tainacan documento]';
			}
			\WP_CLI::log( '  ' . $pretty );

			if ( $dry ) {
				continue;
			}

			// Re-point featured / documento BEFORE deletion if requested.
			if ( ( $is_featured || $is_documento ) && ! $repoint ) {
				\WP_CLI::log( '    SKIP — pass --repoint to update featured/documento to attachment ' . $original_id . ' before deleting' );
				++$skipped;
				continue;
			}
			if ( $is_featured && $repoint ) {
				set_post_thumbnail( $parent_id, $original_id );
				++$repointed;
			}
			if ( $is_documento && $repoint && $tainacan_avail ) {
				try {
					$item = new \Tainacan\Entities\Item( $parent_id );
					$item->set_document( (string) $original_id );
					$item->set_document_type( 'attachment' );
					if ( $item->validate() ) {
						\Tainacan\Repositories\Items::get_instance()->insert( $item );
						++$repointed;
					} else {
						\WP_CLI::warning( 'Item ' . $parent_id . ' rejected documento update; THUMBNAIL kept.' );
						++$repoint_err;
						continue;
					}
				} catch ( \Throwable $e ) {
					\WP_CLI::warning( 'Item ' . $parent_id . ' set_document threw: ' . $e->getMessage() );
					++$repoint_err;
					continue;
				}
			}

			$ok = $force ? wp_delete_attachment( $thumb_id, true ) : wp_trash_post( $thumb_id );
			if ( $ok ) {
				++$deleted;
			}
		}

		if ( $dry ) {
			\WP_CLI::log( "\n--dry-run: no changes made." );
			return;
		}

		$verb = $force ? 'deleted permanently' : 'moved to Trash';
		\WP_CLI::success(
			sprintf(
				'%d attachment(s) %s; %d repointing(s); %d skipped (run with --repoint); %d repointing error(s).',
				$deleted,
				$verb,
				$repointed,
				$skipped,
				$repoint_err
			)
		);
	}
}

\WP_CLI::add_command( 'tainacan-oai', '\Tainacan_OAI_PMH\CLI' );
