<?php
/**
 * Unit tests for the OAI_Client class — URL handling, date validation,
 * XML parsing (XXE guard), and OAI error extraction.
 *
 * These cover the pure-function surface of the client. The four protocol
 * verbs (Identify/ListMetadataFormats/ListSets/ListRecords) are not unit-
 * tested here because they make outbound HTTP — integration tests against
 * a captured-response fixture would be a separate effort.
 *
 * @package Tainacan_OAI_PMH
 */

use Tainacan_OAI_PMH\OAI_Client;
use Tainacan_OAI_PMH\Record_Parser;

/**
 * @covers \Tainacan_OAI_PMH\OAI_Client
 */
class OAI_Client_Test extends WP_UnitTestCase {

	private OAI_Client $client;

	public function setUp(): void {
		parent::setUp();
		$this->client = new OAI_Client( new Record_Parser() );
	}

	// ---------- normalize_url ----------

	public function test_normalize_url_trims_trailing_slash(): void {
		$this->assertSame( 'https://example.org/oai', $this->client->normalize_url( 'https://example.org/oai/' ) );
	}

	public function test_normalize_url_strips_query_string(): void {
		$this->assertSame(
			'https://example.org/oai',
			$this->client->normalize_url( 'https://example.org/oai?verb=Identify' )
		);
	}

	public function test_normalize_url_handles_empty_input(): void {
		$this->assertSame( '', $this->client->normalize_url( '' ) );
		$this->assertSame( '', $this->client->normalize_url( '   ' ) );
	}

	// ---------- validate_url ----------

	public function test_validate_url_accepts_https(): void {
		$this->assertTrue( $this->client->validate_url( 'https://demo.dspace.org/oai/request' ) );
	}

	public function test_validate_url_accepts_http(): void {
		$this->assertTrue( $this->client->validate_url( 'http://demo.dspace.org/oai/request' ) );
	}

	public function test_validate_url_rejects_empty(): void {
		$result = $this->client->validate_url( '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_url', $result->get_error_code() );
	}

	public function test_validate_url_rejects_malformed(): void {
		$result = $this->client->validate_url( 'not a url' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_validate_url_rejects_non_http_schemes(): void {
		foreach ( array( 'ftp://example.org/oai', 'file:///etc/passwd', 'gopher://example.org' ) as $url ) {
			$result = $this->client->validate_url( $url );
			$this->assertInstanceOf( WP_Error::class, $result, "Should reject {$url}" );
			$this->assertSame( 'invalid_scheme', $result->get_error_code(), "Should reject {$url} as invalid_scheme" );
		}
	}

	public function test_validate_url_blocks_loopback_by_default(): void {
		foreach ( array( 'http://localhost/oai', 'http://127.0.0.1/oai', 'http://[::1]/oai' ) as $url ) {
			$result = $this->client->validate_url( $url );
			$this->assertInstanceOf( WP_Error::class, $result, "Should block {$url}" );
			$this->assertSame( 'local_blocked', $result->get_error_code() );
		}
	}

	public function test_validate_url_blocks_private_ip_ranges(): void {
		foreach ( array( 'http://10.0.0.1/', 'http://192.168.1.1/', 'http://172.16.0.1/' ) as $url ) {
			$result = $this->client->validate_url( $url );
			$this->assertInstanceOf( WP_Error::class, $result, "Should block {$url}" );
			$this->assertSame( 'private_ip_blocked', $result->get_error_code() );
		}
	}

	public function test_validate_url_loopback_can_be_unblocked_via_filter(): void {
		add_filter( 'tainacan_oai_pmh_allow_local_urls', '__return_true' );
		$result = $this->client->validate_url( 'http://localhost/oai' );
		remove_filter( 'tainacan_oai_pmh_allow_local_urls', '__return_true' );
		$this->assertTrue( $result );
	}

	// ---------- is_valid_oai_date ----------

	public function test_is_valid_oai_date_accepts_yyyy_mm_dd(): void {
		$this->assertTrue( $this->client->is_valid_oai_date( '2024-01-31' ) );
		$this->assertTrue( $this->client->is_valid_oai_date( '1970-01-01' ) );
	}

	public function test_is_valid_oai_date_accepts_full_iso8601(): void {
		$this->assertTrue( $this->client->is_valid_oai_date( '2024-01-31T00:00:00Z' ) );
		$this->assertTrue( $this->client->is_valid_oai_date( '2024-12-31T23:59:59Z' ) );
	}

	public function test_is_valid_oai_date_rejects_malformed(): void {
		foreach ( array( '31/01/2024', '2024/01/31', '2024-1-1', '', 'today', '2024-01-31T00:00:00+02:00' ) as $bad ) {
			$this->assertFalse( $this->client->is_valid_oai_date( $bad ), "Should reject {$bad}" );
		}
	}

	// ---------- parse_xml ----------

	public function test_parse_xml_parses_well_formed_payload(): void {
		$xml = $this->client->parse_xml( '<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><responseDate>2024-01-01T00:00:00Z</responseDate></OAI-PMH>' );
		$this->assertInstanceOf( SimpleXMLElement::class, $xml );
		$this->assertSame( '2024-01-01T00:00:00Z', (string) $xml->responseDate );
	}

	public function test_parse_xml_returns_wp_error_on_malformed(): void {
		$result = $this->client->parse_xml( '<this is broken' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'xml_parse', $result->get_error_code() );
	}

	public function test_parse_xml_rejects_external_entity_xxe(): void {
		// Classic XXE payload: would resolve to /etc/passwd if we let libxml fetch entities.
		$payload = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>';
		$xml     = $this->client->parse_xml( $payload );
		// We accept that the document parses (libxml may strip or fail), but the
		// entity MUST NOT have resolved to file contents — verify the body is empty.
		if ( $xml instanceof SimpleXMLElement ) {
			$this->assertSame( '', (string) $xml, 'XXE entity must not be expanded into the parsed tree.' );
		} else {
			$this->assertInstanceOf( WP_Error::class, $xml );
		}
	}

	// ---------- extract_oai_error ----------

	public function test_extract_oai_error_returns_null_when_no_error(): void {
		$xml = simplexml_load_string( '<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><responseDate>2024-01-01T00:00:00Z</responseDate></OAI-PMH>' );
		$this->assertNull( $this->client->extract_oai_error( $xml ) );
	}

	public function test_extract_oai_error_surfaces_oai_error_code(): void {
		$xml    = simplexml_load_string( '<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><error code="noRecordsMatch">no records</error></OAI-PMH>' );
		$result = $this->client->extract_oai_error( $xml );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'noRecordsMatch', $result->get_error_code() );
	}

	public function test_extract_oai_error_falls_back_to_code_when_message_empty(): void {
		$xml    = simplexml_load_string( '<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><error code="badArgument"/></OAI-PMH>' );
		$result = $this->client->extract_oai_error( $xml );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'badArgument', $result->get_error_code() );
	}

	// ---------- build_list_records_url ----------

	public function test_build_list_records_url_first_page_has_metadata_prefix(): void {
		$this->assertSame(
			'https://example.org/oai?verb=ListRecords&metadataPrefix=oai_dc',
			$this->client->build_list_records_url( 'https://example.org/oai', 'oai_dc' )
		);
	}

	public function test_build_list_records_url_resumption_token_overrides_all(): void {
		// Per OAI-PMH spec: when using a resumption token, only verb + token allowed.
		$url = $this->client->build_list_records_url(
			'https://example.org/oai',
			'oai_dc',
			'theatre',
			'2024-01-01',
			'2024-12-31',
			'abc123'
		);
		$this->assertStringNotContainsString( 'metadataPrefix', $url );
		$this->assertStringNotContainsString( 'set=', $url );
		$this->assertStringNotContainsString( 'from=', $url );
		$this->assertStringContainsString( 'resumptionToken=abc123', $url );
	}

	public function test_build_list_records_url_includes_optional_filters(): void {
		$url = $this->client->build_list_records_url(
			'https://example.org/oai',
			'oai_dc',
			'theatre',
			'2024-01-01',
			'2024-12-31'
		);
		$this->assertStringContainsString( 'metadataPrefix=oai_dc', $url );
		$this->assertStringContainsString( 'set=theatre', $url );
		$this->assertStringContainsString( 'from=2024-01-01', $url );
		$this->assertStringContainsString( 'until=2024-12-31', $url );
	}

	public function test_build_list_records_url_urlencodes_special_characters(): void {
		$url = $this->client->build_list_records_url(
			'https://example.org/oai',
			'oai_dc',
			'foo bar/baz'
		);
		$this->assertStringContainsString( 'set=foo+bar%2Fbaz', $url );
	}
}
