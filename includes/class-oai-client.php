<?php
/**
 * OAI-PMH protocol client: HTTP + XML primitives + verb wrappers.
 *
 * Extracted from the former Importer monolith. Has no database side effects
 * and no Tainacan dependencies, which means:
 *  - zero $wpdb access → no DB-related phpcs:disable needed at file level
 *  - testable in isolation against fixtures
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks the OAI-PMH 2.0 protocol over HTTP and parses SimpleXML responses.
 *
 * Covers the four verbs the plugin uses: Identify, ListMetadataFormats,
 * ListSets, ListRecords. The Record_Parser companion handles the per-record
 * XML→array mapping for ListRecords payloads.
 */
class OAI_Client {

	public const OAI_NS    = 'http://www.openarchives.org/OAI/2.0/';
	public const OAI_DC_NS = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
	public const DC_NS     = 'http://purl.org/dc/elements/1.1/';

	/**
	 * Optional Record_Parser dependency (only required by preview_records()).
	 *
	 * @var Record_Parser|null
	 */
	private ?Record_Parser $parser;

	/**
	 * @param Record_Parser|null $parser Used by preview_records() to convert
	 *                                    each <record> into a normalized array.
	 *                                    Other verbs do not need it.
	 */
	public function __construct( ?Record_Parser $parser = null ) {
		$this->parser = $parser;
	}

	/**
	 * Trims trailing slashes and ?query parts from a candidate base URL.
	 *
	 * @param string $url Raw user-supplied URL.
	 * @return string
	 */
	public function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		$qpos = strpos( $url, '?' );
		if ( $qpos !== false ) {
			$url = substr( $url, 0, $qpos );
		}
		return rtrim( $url, '/' );
	}

	/**
	 * Validates an OAI-PMH base URL.
	 *
	 * Rejects malformed URLs, non-http(s) schemes, and (by default) local
	 * loopback / RFC1918 hosts to prevent SSRF against the WP server's own
	 * intranet. The loopback guard can be disabled via the
	 * `tainacan_oai_pmh_allow_local_urls` filter for development.
	 *
	 * @param string $url
	 * @return true|\WP_Error
	 */
	public function validate_url( string $url ) {
		if ( '' === $url ) {
			return new \WP_Error( 'empty_url', __( 'URL is required.', 'tainacan-oai-pmh' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! $parts ) {
			return new \WP_Error( 'invalid_url', __( 'Malformed URL.', 'tainacan-oai-pmh' ) );
		}

		// Check scheme BEFORE host, so file:// (which has scheme but no host)
		// reports the more informative 'invalid_scheme' rather than 'invalid_url'.
		if ( ! isset( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_scheme', __( 'Only http and https are supported.', 'tainacan-oai-pmh' ) );
		}
		if ( ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return new \WP_Error( 'invalid_url', __( 'Malformed URL.', 'tainacan-oai-pmh' ) );
		}

		$allow_local = (bool) apply_filters( 'tainacan_oai_pmh_allow_local_urls', false );
		if ( ! $allow_local ) {
			// wp_parse_url preserves brackets around IPv6 literals ([::1]); strip
			// them for a clean comparison against the loopback list.
			$host = strtolower( trim( $parts['host'], '[]' ) );
			if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
				return new \WP_Error( 'local_blocked', __( 'Loopback URLs are blocked. Use the tainacan_oai_pmh_allow_local_urls filter to allow them in development.', 'tainacan-oai-pmh' ) );
			}
			$ip = filter_var( $host, FILTER_VALIDATE_IP );
			if ( $ip && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new \WP_Error( 'private_ip_blocked', __( 'Private/reserved IP addresses are blocked.', 'tainacan-oai-pmh' ) );
			}
		}

		return true;
	}

	/**
	 * Accepts YYYY-MM-DD or YYYY-MM-DDThh:mm:ssZ (OAI granularities).
	 *
	 * @param string $date
	 * @return bool
	 */
	public function is_valid_oai_date( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}Z)?$/', $date );
	}

	/**
	 * Fetches the body of an OAI request via wp_remote_get.
	 *
	 * @param string $url Fully-formed OAI request URL.
	 * @return string|\WP_Error Response body or error.
	 */
	public function request( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => (int) apply_filters( 'tainacan_oai_pmh_request_timeout', 60 ),
				'user-agent' => 'Tainacan-OAI-PMH/' . ( defined( 'TAINACAN_OAI_PMH_VERSION' ) ? TAINACAN_OAI_PMH_VERSION : '0' ) . '; ' . home_url(),
				'sslverify'  => (bool) apply_filters( 'tainacan_oai_pmh_sslverify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'http_error',
				/* translators: %d: HTTP status code returned by the OAI server */
				sprintf( __( 'OAI server returned HTTP %d.', 'tainacan-oai-pmh' ), $code )
			);
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Wraps SimpleXML loading with libxml entity loading disabled (XXE guard).
	 *
	 * @param string $body Raw XML body.
	 * @return \SimpleXMLElement|\WP_Error
	 */
	public function parse_xml( string $body ) {
		$prev_internal = libxml_use_internal_errors( true );
		// LIBXML_NONET disables fetching of external resources during parsing.
		// IMPORTANTLY we do NOT pass LIBXML_NOENT — that flag enables entity
		// substitution, which is the opposite of what we want. With libxml >= 2.9
		// external entity loading is off by default; LIBXML_NONET adds the no-net
		// belt to the suspenders so file:// and remote DTDs are also blocked.
		$xml    = simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_internal );

		if ( false === $xml ) {
			$first = $errors[0] ?? null;
			$msg   = $first ? trim( $first->message ) : 'unknown';
			return new \WP_Error( 'xml_parse', sprintf( 'XML parse error: %s', $msg ) );
		}

		return $xml;
	}

	/**
	 * Detects an OAI error element in a response and surfaces it as WP_Error.
	 *
	 * @param \SimpleXMLElement $xml
	 * @return \WP_Error|null Null when no error present.
	 */
	public function extract_oai_error( \SimpleXMLElement $xml ): ?\WP_Error {
		if ( ! isset( $xml->error ) ) {
			return null;
		}
		$code = isset( $xml->error['code'] ) ? (string) $xml->error['code'] : 'oai_error';
		$msg  = (string) $xml->error;
		return new \WP_Error( $code, $msg !== '' ? $msg : $code );
	}

	/**
	 * Calls OAI verb=Identify on the given base URL.
	 *
	 * @param string $url Base OAI endpoint.
	 * @return array<string,string>|\WP_Error
	 */
	public function fetch_repository_info( string $url ) {
		$url        = $this->normalize_url( $url );
		$validation = $this->validate_url( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$response = $this->request( $url . '?verb=Identify' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$err = $this->extract_oai_error( $xml );
		if ( $err ) {
			return $err;
		}

		$xml->registerXPathNamespace( 'oai', self::OAI_NS );
		$identify = $xml->Identify ?? ( $xml->xpath( '//oai:Identify' )[0] ?? null );

		if ( ! $identify ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid Identify response.', 'tainacan-oai-pmh' ) );
		}

		return array(
			'repository_name'    => (string) ( $identify->repositoryName ?? '' ),
			'base_url'           => (string) ( $identify->baseURL ?? $url ),
			'admin_email'        => (string) ( $identify->adminEmail ?? '' ),
			'earliest_datestamp' => (string) ( $identify->earliestDatestamp ?? '' ),
			'protocol_version'   => (string) ( $identify->protocolVersion ?? '' ),
			'granularity'        => (string) ( $identify->granularity ?? '' ),
		);
	}

	/**
	 * Calls OAI verb=ListMetadataFormats.
	 *
	 * @param string $url
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	public function fetch_metadata_formats( string $url ) {
		$url        = $this->normalize_url( $url );
		$validation = $this->validate_url( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$response = $this->request( $url . '?verb=ListMetadataFormats' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$formats = array();
		$xml->registerXPathNamespace( 'oai', self::OAI_NS );
		$nodes = $xml->ListMetadataFormats->metadataFormat ?? $xml->xpath( '//oai:metadataFormat' ) ?? array();

		foreach ( $nodes as $node ) {
			$formats[] = array(
				'prefix'    => (string) ( $node->metadataPrefix ?? '' ),
				'schema'    => (string) ( $node->schema ?? '' ),
				'namespace' => (string) ( $node->metadataNamespace ?? '' ),
			);
		}
		return $formats;
	}

	/**
	 * Calls OAI verb=ListSets with up to 5 resumption-token follow-ups.
	 *
	 * @param string $url
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	public function fetch_sets( string $url ) {
		$url        = $this->normalize_url( $url );
		$validation = $this->validate_url( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$response = $this->request( $url . '?verb=ListSets' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$sets = array();
		if ( isset( $xml->error ) && (string) $xml->error['code'] === 'noSetHierarchy' ) {
			return $sets;
		}

		$base  = $url;
		$pages = 0;
		do {
			$xml->registerXPathNamespace( 'oai', self::OAI_NS );
			$set_nodes = $xml->ListSets->set ?? $xml->xpath( '//oai:set' ) ?? array();

			foreach ( $set_nodes as $set ) {
				$sets[] = array(
					'spec'        => (string) ( $set->setSpec ?? '' ),
					'name'        => (string) ( $set->setName ?? '' ),
					'description' => isset( $set->setDescription ) ? trim( (string) $set->setDescription ) : '',
				);
			}

			$rt = $xml->ListSets->resumptionToken ?? null;
			if ( ! $rt || (string) $rt === '' || ++$pages >= 5 ) {
				break;
			}

			$response = $this->request( $base . '?verb=ListSets&resumptionToken=' . urlencode( (string) $rt ) );
			if ( is_wp_error( $response ) ) {
				break;
			}
			$xml = $this->parse_xml( $response );
			if ( is_wp_error( $xml ) ) {
				break;
			}
		} while ( true );

		return $sets;
	}

	/**
	 * Calls OAI verb=ListRecords for preview purposes — returns the first
	 * $limit records parsed into associative arrays via the Record_Parser.
	 *
	 * @param string $url
	 * @param string $set
	 * @param int    $limit
	 * @param string $prefix
	 * @return array{records:array<int,array>,total:?int,dc_fields:array}|\WP_Error
	 */
	public function preview_records( string $url, string $set = '', int $limit = 5, string $prefix = 'oai_dc' ) {
		if ( ! $this->parser ) {
			return new \WP_Error( 'parser_missing', 'OAI_Client::preview_records requires a Record_Parser dependency.' );
		}

		$url        = $this->normalize_url( $url );
		$validation = $this->validate_url( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$query = '?verb=ListRecords&metadataPrefix=' . urlencode( $prefix );
		if ( $set !== '' ) {
			$query .= '&set=' . urlencode( $set );
		}

		$response = $this->request( $url . $query );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$xml = $this->parse_xml( $response );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$err = $this->extract_oai_error( $xml );
		if ( $err ) {
			return $err;
		}

		$records      = array();
		$record_nodes = $xml->ListRecords->record ?? array();

		$count = 0;
		foreach ( $record_nodes as $record ) {
			if ( $count >= $limit ) {
				break;
			}
			$parsed = $this->parser->parse_record( $record, $prefix );
			if ( $parsed ) {
				$records[] = $parsed;
				++$count;
			}
		}

		$total = null;
		$rt    = $xml->ListRecords->resumptionToken ?? null;
		if ( $rt && isset( $rt['completeListSize'] ) ) {
			$total = (int) $rt['completeListSize'];
		}

		return array(
			'records'   => $records,
			'total'     => $total,
			'dc_fields' => $this->parser->discover_source_fields( $records ),
		);
	}

	/**
	 * Builds a ListRecords URL for either the first page or a follow-up
	 * using a resumption token (per the OAI-PMH spec, when a resumption
	 * token is present, only verb + token can appear in the query).
	 *
	 * @param string $base_url       Normalized base URL.
	 * @param string $prefix         Metadata prefix.
	 * @param string $set_spec       Optional set.
	 * @param string $from           Optional from date.
	 * @param string $until          Optional until date.
	 * @param string $resumption     Resumption token (overrides everything else when non-empty).
	 * @return string
	 */
	public function build_list_records_url(
		string $base_url,
		string $prefix = 'oai_dc',
		string $set_spec = '',
		string $from = '',
		string $until = '',
		string $resumption = ''
	): string {
		if ( $resumption !== '' ) {
			return $base_url . '?verb=ListRecords&resumptionToken=' . urlencode( $resumption );
		}
		$url = $base_url . '?verb=ListRecords&metadataPrefix=' . urlencode( $prefix );
		if ( $set_spec !== '' ) {
			$url .= '&set=' . urlencode( $set_spec );
		}
		if ( $from !== '' ) {
			$url .= '&from=' . urlencode( $from );
		}
		if ( $until !== '' ) {
			$url .= '&until=' . urlencode( $until );
		}
		return $url;
	}
}
