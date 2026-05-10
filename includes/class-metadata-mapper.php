<?php
namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps source OAI-PMH fields (Dublin Core) to Tainacan collection metadata.
 *
 * @see https://tainacan.github.io/tainacan-wiki/#/dev/README
 */
class Metadata_Mapper {

	/** Standard 15 Dublin Core elements (DCMES 1.1) — always offered as mappable rows. */
	public static function get_standard_dc_fields(): array {
		return array(
			'title'       => __( 'Title', 'tainacan-oai-pmh' ),
			'creator'     => __( 'Creator', 'tainacan-oai-pmh' ),
			'subject'     => __( 'Subject', 'tainacan-oai-pmh' ),
			'description' => __( 'Description', 'tainacan-oai-pmh' ),
			'publisher'   => __( 'Publisher', 'tainacan-oai-pmh' ),
			'contributor' => __( 'Contributor', 'tainacan-oai-pmh' ),
			'date'        => __( 'Date', 'tainacan-oai-pmh' ),
			'type'        => __( 'Type', 'tainacan-oai-pmh' ),
			'format'      => __( 'Format', 'tainacan-oai-pmh' ),
			'identifier'  => __( 'Identifier', 'tainacan-oai-pmh' ),
			'source'      => __( 'Source', 'tainacan-oai-pmh' ),
			'language'    => __( 'Language', 'tainacan-oai-pmh' ),
			'relation'    => __( 'Relation', 'tainacan-oai-pmh' ),
			'coverage'    => __( 'Coverage', 'tainacan-oai-pmh' ),
			'rights'      => __( 'Rights', 'tainacan-oai-pmh' ),
		);
	}

	/**
	 * Returns Tainacan metadata of the given collection, including:
	 *  - own metadata
	 *  - inherited (parent/repository-level)
	 *  - any existing Dublin Core exposer mapping declared in Tainacan
	 *
	 * Mirrors Tainacan's metadata API:
	 *
	 * @see https://tainacan.github.io/tainacan-wiki/#/dev/README — Metadata Repository / Exposers
	 */
	public static function get_collection_metadata( int $collection_id ): array {
		$collection = new \Tainacan\Entities\Collection( $collection_id );
		if ( ! $collection->get_id() ) {
			return array();
		}

		$repo     = \Tainacan\Repositories\Metadata::get_instance();
		$metadata = $repo->fetch_by_collection( $collection, array(), 'OBJECT' );

		$result = array();
		if ( ! is_array( $metadata ) ) {
			return $result;
		}

		foreach ( $metadata as $metadatum ) {
			// Some metadata types (Core_Title, Core_Description) are virtual proxies
			// for post fields and are not user-mappable as separate metadata.
			$type    = $metadatum->get_metadata_type();
			$is_core = in_array( $type, array( 'Tainacan\\Metadata_Types\\Core_Title', 'Tainacan\\Metadata_Types\\Core_Description' ), true );

			$dc = null;
			if ( method_exists( $metadatum, 'get_exposer_mapping' ) ) {
				$mapping = $metadatum->get_exposer_mapping();
				if ( is_array( $mapping ) && isset( $mapping['dublin-core'] ) ) {
					$dc = preg_replace( '/^dc:/', '', (string) $mapping['dublin-core'] );
				}
			}

			$result[] = array(
				'id'          => $metadatum->get_id(),
				'name'        => $metadatum->get_name(),
				'slug'        => method_exists( $metadatum, 'get_slug' ) ? $metadatum->get_slug() : '',
				'type'        => self::short_type_name( $type ),
				'multiple'    => $metadatum->is_multiple(),
				'required'    => method_exists( $metadatum, 'is_required' ) ? $metadatum->is_required() : false,
				'dc_mapping'  => $dc,
				'is_core'     => $is_core,
				'description' => method_exists( $metadatum, 'get_description' ) ? $metadatum->get_description() : '',
			);
		}
		return $result;
	}

	private static function short_type_name( string $fqn ): string {
		$parts = explode( '\\', $fqn );
		return end( $parts ) ?: $fqn;
	}

	/**
	 * Builds the rows shown in the importer mapping table:
	 *  - all 15 standard DC elements (always present)
	 *  - any extra fields discovered in source records (qdc, mods, dim, etc.)
	 * Each row carries a sample value, occurrence stats, and an auto-suggested target metadatum.
	 */
	public static function build_mapping_rows( int $collection_id, array $source_fields ): array {
		$standard            = self::get_standard_dc_fields();
		$collection_metadata = self::get_collection_metadata( $collection_id );
		$rows                = array();

		// Index source fields by name for lookup
		$source_by_name = array();
		foreach ( $source_fields as $f ) {
			$source_by_name[ $f['name'] ] = $f;
		}

		// 1) Standard DC fields — always offered, even if empty in sample
		foreach ( $standard as $name => $label ) {
			$found  = $source_by_name[ $name ] ?? null;
			$rows[] = array(
				'name'                   => $name,
				'label'                  => $label,
				'is_standard_dc'         => true,
				'present_in_source'      => $found !== null,
				'sample'                 => $found['sample'] ?? '',
				'is_multi'               => $found['is_multi'] ?? false,
				'occurrences'            => $found['occurrences'] ?? 0,
				'suggested_metadatum_id' => self::suggest_for_field( $name, $collection_metadata ),
			);
		}

		// 2) Extra fields found in source but outside DC 1.1 (qdc dcterms, mods, dim…)
		foreach ( $source_fields as $f ) {
			if ( isset( $standard[ $f['name'] ] ) ) {
				continue;
			}
			$rows[] = array(
				'name'                   => $f['name'],
				'label'                  => $f['label'] ?? ucfirst( $f['name'] ),
				'is_standard_dc'         => false,
				'present_in_source'      => true,
				'sample'                 => $f['sample'] ?? '',
				'is_multi'               => $f['is_multi'] ?? false,
				'occurrences'            => $f['occurrences'] ?? 0,
				'suggested_metadatum_id' => self::suggest_for_field( $f['name'], $collection_metadata ),
			);
		}

		return array(
			'rows'                => $rows,
			'collection_metadata' => $collection_metadata,
		);
	}

	/**
	 * Tiered suggestion strategy (high confidence → low):
	 *   1) Exact dublin-core exposer match against the FULL field name
	 *      (handles oai_dc unqualified like "title" and dc.* fully qualified)
	 *   2) Match dublin-core exposer / name / slug against any segment of the
	 *      qualified path:  "dc.contributor.author" → tries "author", then
	 *      "contributor", then the full name. This lets xoai's qualified
	 *      DSpace fields auto-suggest sensible Tainacan targets.
	 *   3) Substring containment (>= 6 chars, to avoid trivial matches)
	 * Similar_text is intentionally NOT used — it produced too many false positives.
	 */
	private static function suggest_for_field( string $field_name, array $collection_metadata ): ?int {
		$field_name = strtolower( $field_name );

		// Tier 1: full-name exact match against declared Tainacan exposers/names
		foreach ( $collection_metadata as $meta ) {
			if ( $meta['is_core'] ) {
				continue;
			}
			if ( $meta['dc_mapping'] === $field_name ) {
				return (int) $meta['id'];
			}
		}
		foreach ( $collection_metadata as $meta ) {
			if ( $meta['is_core'] ) {
				continue;
			}
			$name = strtolower( $meta['name'] );
			$slug = strtolower( $meta['slug'] ?? '' );
			if ( $name === $field_name || $slug === $field_name ) {
				return (int) $meta['id'];
			}
		}

		// Tier 2: try every meaningful segment of a qualified path.
		// For "dc.contributor.author" we try ["author", "contributor", "dc"]
		// (last-most specific first), skipping single-letter segments and "dc"/"dcterms".
		$segments = self::candidate_segments( $field_name );
		foreach ( $segments as $seg ) {
			foreach ( $collection_metadata as $meta ) {
				if ( $meta['is_core'] ) {
					continue;
				}
				if ( $meta['dc_mapping'] === $seg ) {
					return (int) $meta['id'];
				}
				if ( strtolower( $meta['name'] ) === $seg ) {
					return (int) $meta['id'];
				}
				if ( strtolower( $meta['slug'] ?? '' ) === $seg ) {
					return (int) $meta['id'];
				}
			}
		}

		// Tier 3: substring containment using the most specific segment
		$needle = $segments[0] ?? $field_name;
		if ( strlen( $needle ) >= 6 ) {
			foreach ( $collection_metadata as $meta ) {
				if ( $meta['is_core'] ) {
					continue;
				}
				$name = strtolower( $meta['name'] );
				if ( $name !== '' && ( str_contains( $name, $needle ) || str_contains( $needle, $name ) ) ) {
					return (int) $meta['id'];
				}
			}
		}
		return null;
	}

	/**
	 * Splits a qualified field name into a ranked list of candidate segments
	 * for matching, most specific first. Filters out namespace prefixes that
	 * would otherwise match too aggressively (dc, dcterms, oai_dc).
	 */
	private static function candidate_segments( string $field_name ): array {
		if ( ! str_contains( $field_name, '.' ) ) {
			return array();
		}
		$parts = explode( '.', $field_name );
		$parts = array_values(
			array_filter(
				array_map( 'strtolower', $parts ),
				fn( $p ) => strlen( $p ) >= 2 && ! in_array( $p, array( 'dc', 'dcterms', 'oai_dc', 'none' ), true )
			)
		);
		// Reverse so "author" comes before "contributor" comes before "dc"
		return array_reverse( $parts );
	}
}
