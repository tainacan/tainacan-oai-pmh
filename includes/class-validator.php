<?php
namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Validator {

	private $base_url;
	private $results;

	public function __construct() {
		$this->base_url = rest_url( 'tainacan-oai/v1/oai' );
	}

	public function run() {
		$this->results = array(
			'timestamp' => current_time( 'mysql' ),
			'tests'     => array(),
			'passed'    => 0,
			'failed'    => 0,
			'warnings'  => 0,
		);

		$this->test_identify();
		$this->test_list_metadata_formats();
		$this->test_list_sets();
		$this->test_list_records();
		$this->test_list_identifiers();
		$this->test_get_record();
		$this->test_error_handling();
		$this->test_xml_validity();

		foreach ( $this->results['tests'] as $test ) {
			$key = $test['status'] === 'passed' ? 'passed' : ( $test['status'] === 'warning' ? 'warnings' : 'failed' );
			++$this->results[ $key ];
		}

		$total                  = count( $this->results['tests'] );
		$this->results['score'] = $total > 0 ? round( ( $this->results['passed'] / $total ) * 100 ) : 0;

		update_option( 'tainacan_oai_last_validation', $this->results );
		return $this->results;
	}

	private function test_identify() {
		$response = $this->request( array( 'verb' => 'Identify' ) );
		$test     = array(
			'name'        => 'Identify',
			'description' => __( 'Checks Identify response.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		if ( $response['error'] ) {
			$test['status']    = 'failed';
			$test['details'][] = 'Error: ' . $response['error'];
		} else {
			foreach ( array( 'repositoryName', 'baseURL', 'protocolVersion', 'adminEmail', 'earliestDatestamp', 'deletedRecord', 'granularity' ) as $field ) {
				if ( strpos( $response['body'], "<$field>" ) !== false ) {
					$test['details'][] = "✓ $field";
				} else {
					$test['status']    = 'failed';
					$test['details'][] = "✗ Missing: $field";
				}
			}
		}
		$this->results['tests'][] = $test;
	}

	private function test_list_metadata_formats() {
		$response = $this->request( array( 'verb' => 'ListMetadataFormats' ) );
		$test     = array(
			'name'        => 'ListMetadataFormats',
			'description' => __( 'Checks oai_dc support.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		if ( $response['error'] ) {
			$test['status']    = 'failed';
			$test['details'][] = 'Error: ' . $response['error'];
		} elseif ( strpos( $response['body'], 'oai_dc' ) !== false ) {
			$test['details'][] = '✓ oai_dc supported';
		} else {
			$test['status']    = 'failed';
			$test['details'][] = '✗ oai_dc not found';
		}
		$this->results['tests'][] = $test;
	}

	private function test_list_sets() {
		$response = $this->request( array( 'verb' => 'ListSets' ) );
		$test     = array(
			'name'        => 'ListSets',
			'description' => __( 'Checks sets/collections.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		if ( $response['error'] ) {
			$test['status']    = 'warning';
			$test['details'][] = 'Error: ' . $response['error'];
		} elseif ( strpos( $response['body'], '<set>' ) !== false ) {
			preg_match_all( '/<set>/', $response['body'], $matches );
			$test['details'][] = '✓ ' . count( $matches[0] ) . ' set(s) found';
		} elseif ( strpos( $response['body'], 'noSetHierarchy' ) !== false ) {
			$test['status']    = 'warning';
			$test['details'][] = 'No sets (optional)';
		}
		$this->results['tests'][] = $test;
	}

	private function test_list_records() {
		$response = $this->request(
			array(
				'verb'           => 'ListRecords',
				'metadataPrefix' => 'oai_dc',
			)
		);
		$test     = array(
			'name'        => 'ListRecords',
			'description' => __( 'Checks record listing.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		if ( $response['error'] ) {
			$test['status']    = 'failed';
			$test['details'][] = 'Error: ' . $response['error'];
		} elseif ( strpos( $response['body'], '<record' ) !== false ) {
			preg_match_all( '/<record[^>]*>/', $response['body'], $matches );
			$test['details'][] = '✓ ' . count( $matches[0] ) . ' record(s)';
		} elseif ( strpos( $response['body'], 'noRecordsMatch' ) !== false ) {
			$test['status']    = 'warning';
			$test['details'][] = 'No records (index empty?)';
		} else {
			$test['status']    = 'failed';
			$test['details'][] = '✗ Invalid response';
		}
		$this->results['tests'][] = $test;
	}

	private function test_list_identifiers() {
		$response = $this->request(
			array(
				'verb'           => 'ListIdentifiers',
				'metadataPrefix' => 'oai_dc',
			)
		);
		$test     = array(
			'name'        => 'ListIdentifiers',
			'description' => __( 'Checks identifier listing.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		if ( strpos( $response['body'], '<header' ) !== false || strpos( $response['body'], 'noRecordsMatch' ) !== false ) {
			$test['details'][] = '✓ Valid response';
		} else {
			$test['status']    = 'warning';
			$test['details'][] = 'Unexpected response';
		}
		$this->results['tests'][] = $test;
	}

	private function test_get_record() {
		$test = array(
			'name'        => 'GetRecord',
			'description' => __( 'Checks single record retrieval.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		// Get an identifier first
		$list = $this->request(
			array(
				'verb'           => 'ListIdentifiers',
				'metadataPrefix' => 'oai_dc',
			)
		);
		if ( preg_match( '/<identifier>([^<]+)<\/identifier>/', $list['body'], $matches ) ) {
			$response = $this->request(
				array(
					'verb'           => 'GetRecord',
					'identifier'     => $matches[1],
					'metadataPrefix' => 'oai_dc',
				)
			);
			if ( strpos( $response['body'], '<record' ) !== false ) {
				$test['details'][] = '✓ Record retrieved';
			} else {
				$test['status']    = 'failed';
				$test['details'][] = '✗ Failed to get record';
			}
		} else {
			$test['status']    = 'warning';
			$test['details'][] = 'No records to test';
		}
		$this->results['tests'][] = $test;
	}

	private function test_error_handling() {
		$test = array(
			'name'        => 'Error Handling',
			'description' => __( 'Checks OAI-PMH error responses.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		$response = $this->request( array( 'verb' => 'InvalidVerb' ) );
		if ( strpos( $response['body'], 'badVerb' ) !== false ) {
			$test['details'][] = '✓ badVerb handled';
		} else {
			$test['status']    = 'failed';
			$test['details'][] = '✗ badVerb not handled';
		}

		$response = $this->request( array( 'verb' => 'ListRecords' ) );
		if ( strpos( $response['body'], 'badArgument' ) !== false ) {
			$test['details'][] = '✓ badArgument handled';
		}

		$this->results['tests'][] = $test;
	}

	private function test_xml_validity() {
		$response = $this->request( array( 'verb' => 'Identify' ) );
		$test     = array(
			'name'        => 'XML Validity',
			'description' => __( 'Checks XML well-formedness.', 'tainacan-oai-pmh' ),
			'status'      => 'passed',
			'details'     => array(),
		);

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $response['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( $xml === false ) {
			$test['status']    = 'failed';
			$test['details'][] = '✗ Invalid XML';
		} else {
			$test['details'][] = '✓ Well-formed XML';
		}
		libxml_clear_errors();

		$this->results['tests'][] = $test;
	}

	private function request( $params ) {
		$url = add_query_arg( $params, $this->base_url );
		// Validator hits our own REST endpoint — sslverify can be disabled only for local dev
		$sslverify = ! $this->is_local_url( $url );
		$response  = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => $sslverify,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'error' => $response->get_error_message(),
				'body'  => '',
			);
		}
		return array(
			'error' => null,
			'body'  => wp_remote_retrieve_body( $response ),
		);
	}

	private function is_local_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $site_host === $host;
	}

	public function get_last_result() {
		return get_option( 'tainacan_oai_last_validation', null );
	}
}
