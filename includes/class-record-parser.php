<?php
/**
 * Pure XML → array record parser for OAI-PMH ListRecords payloads.
 *
 * Extracted from the Importer monolith. Has no DB, no HTTP, no Tainacan
 * dependencies — feed it a SimpleXMLElement, get a normalized array back.
 * Trivially unit-testable.
 *
 * Supports three metadata formats:
 *  - oai_dc: unqualified Dublin Core (keys: title, creator, …)
 *  - qdc:    qualified DC (keys: title, abstract, isPartOf, …)
 *  - xoai:   DSpace native (keys: dc.contributor.author, dc.title, …)
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XML → array parser for one OAI-PMH record at a time.
 */
class Record_Parser {

	private const XOAI_NS = 'http://www.lyncode.com/xoai';

	/**
	 * Parses one <record> element into a normalized array.
	 *
	 * @param \SimpleXMLElement $record
	 * @param string            $prefix oai_dc | qdc | xoai.
	 * @return array{identifier:string,datestamp:string,status:string,set_specs:string[],metadata:array}|null
	 *         Null when the record has no <header> (malformed input).
	 */
	public function parse_record( \SimpleXMLElement $record, string $prefix = 'oai_dc' ): ?array {
		$header = $record->header ?? null;
		if ( ! $header ) {
			return null;
		}

		$data = array(
			'identifier' => trim( (string) ( $header->identifier ?? '' ) ),
			'datestamp'  => (string) ( $header->datestamp ?? '' ),
			'status'     => isset( $header['status'] ) ? (string) $header['status'] : 'active',
			'set_specs'  => array(),
			'metadata'   => array(),
		);

		if ( isset( $header->setSpec ) ) {
			foreach ( $header->setSpec as $ss ) {
				$data['set_specs'][] = (string) $ss;
			}
		}

		$metadata = $record->metadata ?? null;
		if ( ! $metadata ) {
			return $data;
		}

		// Each format yields a different "shape" of metadata bag:
		// oai_dc → unqualified DC, keys like "title", "creator", "contributor"
		// qdc    → qualified DC, keys like "title", "abstract", "isPartOf"
		// xoai   → DSpace native, dotted full paths like "dc.contributor.author"
		$prefix = strtolower( $prefix );
		if ( $prefix === 'xoai' ) {
			$data['metadata'] = $this->parse_xoai_metadata( $metadata );
		} elseif ( $prefix === 'qdc' ) {
			$data['metadata'] = $this->parse_qdc_metadata( $metadata );
		} else {
			$data['metadata'] = $this->parse_oai_dc_metadata( $metadata );
		}

		return $data;
	}

	/**
	 * Parses unqualified oai_dc metadata, with a permissive fallback that
	 * picks up DC-like elements when the upstream answered with a different
	 * schema than expected.
	 *
	 * @param \SimpleXMLElement $metadata
	 * @return array<string,string|array<int,string>>
	 */
	private function parse_oai_dc_metadata( \SimpleXMLElement $metadata ): array {
		$bag = array();
		$dc  = $metadata->children( OAI_Client::OAI_DC_NS );
		if ( $dc && $dc->dc ) {
			$dc_elements = $dc->dc->children( OAI_Client::DC_NS );
			$this->collect_elements_into( $bag, $dc_elements );
			return $bag;
		}

		foreach ( $metadata->children() as $child ) {
			$this->collect_elements_into( $bag, $child->children() );
			foreach ( $child->getDocNamespaces( true ) as $p => $ns ) {
				if ( ! $p ) {
					continue;
				}
				$this->collect_elements_into( $bag, $child->children( $ns ) );
			}
		}
		return $bag;
	}

	/**
	 * Parses xOAI (DSpace native) into a flat bag with DOTTED qualified field
	 * names. Preserves the full path so the admin sees the actual DSpace schema
	 * in the mapping table.
	 *
	 * Structure:
	 *   <doc:metadata>
	 *     <doc:element name="dc">
	 *       <doc:element name="contributor">
	 *         <doc:element name="author">
	 *           <doc:element name="none">           ← language (none|en|pt-br…)
	 *             <doc:field name="value">…</doc:field>
	 *
	 * Resulting key: "dc.contributor.author" → "Author Name"
	 *
	 * @param \SimpleXMLElement $metadata
	 * @return array<string,string|array<int,string>>
	 */
	public function parse_xoai_metadata( \SimpleXMLElement $metadata ): array {
		$bag = array();

		// Iterate xoai-namespaced children of the OAI <metadata> wrapper.
		// In well-formed DSpace responses the xmlns:doc declaration sits on
		// an ancestor (typically <OAI-PMH>), so the namespace is in scope at
		// this level and ->children( \$ns ) finds the <doc:metadata> /
		// <doc:element> wrappers correctly.
		foreach ( $metadata->children( self::XOAI_NS ) as $child ) {
			$this->walk_xoai_element( $child, '', $bag, self::XOAI_NS );
		}
		return $bag;
	}

	/**
	 * Recursive walker for xOAI elements; mutates $bag in place.
	 *
	 * @param \SimpleXMLElement                      $node
	 * @param string                                 $path Dotted ancestry built up during recursion.
	 * @param array<string,string|array<int,string>> $bag  Accumulator.
	 * @param string                                 $ns   xOAI namespace URI.
	 * @return void
	 */
	private function walk_xoai_element( \SimpleXMLElement $node, string $path, array &$bag, string $ns ): void {
		$tag  = $node->getName();
		$name = isset( $node['name'] ) ? (string) $node['name'] : '';

		if ( 'field' === $tag ) {
			// Only collect <field name="value"> — DSpace also emits authority/confidence
			// fields we don't want to expose in the mapping table.
			if ( 'value' !== $name ) {
				return;
			}
			$value = trim( (string) $node );
			if ( '' === $value ) {
				return;
			}
			// Strip the trailing language segment (last path component is the lang).
			// Patterns: "dc.contributor.author.none" → "dc.contributor.author"
			// "dc.title.pt_BR"             → "dc.title"
			$key = preg_replace( '/\.(?:none|[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?)$/', '', $path );
			if ( '' === $key || null === $key ) {
				return;
			}

			if ( isset( $bag[ $key ] ) ) {
				if ( ! is_array( $bag[ $key ] ) ) {
					$bag[ $key ] = array( $bag[ $key ] );
				}
				$bag[ $key ][] = $value;
			} else {
				$bag[ $key ] = $value;
			}
			return;
		}

		// 'element' is the standard xoai container; 'metadata' is the outer
		// root wrapper used by DSpace (<doc:metadata>). Both need to descend
		// into xoai-namespaced children.
		$is_named_element = ( 'element' === $tag && '' !== $name );
		$is_root_wrapper  = ( 'metadata' === $tag );
		if ( $is_named_element || $is_root_wrapper ) {
			$new_path = $is_root_wrapper ? $path : ( '' === $path ? $name : "$path.$name" );
			foreach ( $node->children( $ns ) as $child ) {
				$this->walk_xoai_element( $child, $new_path, $bag, $ns );
			}
		}
	}

	/**
	 * Parses qualified DC (Lyncode qdc / DSpace qdc).
	 *
	 * Keys keep the local element name (e.g. "title", "abstract", "isPartOf") —
	 * dcterms qualifiers come through as their own keys, distinct from base dc.
	 *
	 * @param \SimpleXMLElement $metadata
	 * @return array<string,string|array<int,string>>
	 */
	public function parse_qdc_metadata( \SimpleXMLElement $metadata ): array {
		$bag        = array();
		$namespaces = array(
			OAI_Client::DC_NS,
			'http://purl.org/dc/terms/',
		);

		// Walk every wrapper inside <metadata> regardless of its own namespace
		// (oai_qdc:qualifieddc / qdc:qualifieddc / etc.). SimpleXML's ->children()
		// without args returns only same-namespace children, which misses the
		// usual qdc wrapper because <metadata> is in OAI_NS but the wrapper is
		// in qdc. xpath('*') is namespace-agnostic and gets them all.
		$wrappers = $metadata->xpath( '*' );
		if ( is_array( $wrappers ) ) {
			foreach ( $wrappers as $wrapper ) {
				foreach ( $namespaces as $ns ) {
					$this->collect_elements_into( $bag, $wrapper->children( $ns ) );
				}
			}
		}
		// Some servers don't nest the wrapper — try metadata's own children too.
		foreach ( $namespaces as $ns ) {
			$this->collect_elements_into( $bag, $metadata->children( $ns ) );
		}
		return $bag;
	}

	/**
	 * Appends every non-empty element value into $bag, keyed by tag name.
	 * Promotes single → array on second occurrence.
	 *
	 * @param array<string,string|array<int,string>>                                 $bag      Accumulator (by reference).
	 * @param \SimpleXMLElement|\SimpleXMLElement[]|iterable<\SimpleXMLElement>|null $elements
	 * @return void
	 */
	public function collect_elements_into( array &$bag, $elements ): void {
		if ( ! $elements ) {
			return;
		}
		foreach ( $elements as $element ) {
			$name  = $element->getName();
			$value = trim( (string) $element );
			if ( $value === '' ) {
				continue;
			}

			if ( isset( $bag[ $name ] ) ) {
				if ( ! is_array( $bag[ $name ] ) ) {
					$bag[ $name ] = array( $bag[ $name ] );
				}
				$bag[ $name ][] = $value;
			} else {
				$bag[ $name ] = $value;
			}
		}
	}

	/**
	 * Returns the union of all metadata fields actually present in a sample
	 * of parsed records, with sample values, occurrence counts, and detection
	 * of multi-valued fields. Powers the "Discover fields" wizard step.
	 *
	 * @param array<int,array{metadata?:array<string,string|array<int,string>>}> $records
	 * @return array<int,array{name:string,label:string,sample:string,occurrences:int,is_multi:bool}>
	 */
	public function discover_source_fields( array $records ): array {
		$fields = array();
		foreach ( $records as $record ) {
			foreach ( ( $record['metadata'] ?? array() ) as $key => $value ) {
				$values = is_array( $value ) ? $value : array( $value );
				if ( ! isset( $fields[ $key ] ) ) {
					$fields[ $key ] = array(
						'name'        => $key,
						'label'       => ucfirst( $key ),
						'sample'      => '',
						'occurrences' => 0,
						'is_multi'    => false,
					);
				}
				++$fields[ $key ]['occurrences'];
				if ( count( $values ) > 1 ) {
					$fields[ $key ]['is_multi'] = true;
				}
				if ( empty( $fields[ $key ]['sample'] ) && ! empty( $values[0] ) ) {
					$fields[ $key ]['sample'] = mb_substr( (string) $values[0], 0, 120 );
				}
			}
		}
		return array_values( $fields );
	}

	/**
	 * Looks up the first non-empty value across a list of candidate keys.
	 * Used by callers that need to read title/description across oai_dc/qdc/xoai
	 * key conventions.
	 *
	 * @param array<string,string|array<int,string>> $bag  Parsed metadata.
	 * @param array<int,string>                      $keys Candidate keys in priority order.
	 * @return string|null
	 */
	public function lookup_metadata_value( array $bag, array $keys ): ?string {
		foreach ( $keys as $key ) {
			if ( ! isset( $bag[ $key ] ) ) {
				continue;
			}
			$value = $bag[ $key ];
			if ( is_array( $value ) ) {
				// Multi-valued fields (typical for description/abstract) are
				// joined with a paragraph break so the caller gets the full
				// concatenated text, not just the first segment.
				$value = implode( "\n\n", array_filter( $value, static fn( $v ) => is_string( $v ) && '' !== $v ) );
			}
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value ) {
				return $value;
			}
		}
		return null;
	}
}
