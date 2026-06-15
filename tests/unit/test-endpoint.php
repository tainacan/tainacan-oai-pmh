<?php
/**
 * @package Tainacan_OAI_PMH
 */

use Tainacan_OAI_PMH\Endpoint;

/**
 * @covers \Tainacan_OAI_PMH\Endpoint
 */
class Endpoint_Test extends WP_UnitTestCase {

	public function test_prefers_core_data_provider_when_available(): void {
		if ( ! class_exists( '\Tainacan\OAIPMHExpose\OAIPMH_Data_Provider' ) ) {
			$this->markTestSkipped( 'Tainacan core OAI-PMH provider is not available in this environment.' );
		}

		$provider = new \Tainacan\OAIPMHExpose\OAIPMH_Data_Provider();

		$this->assertSame( $provider->get_base_url(), Endpoint::get_url() );
		$this->assertStringContainsString( 'tainacan/v2/oai', Endpoint::get_url() );
	}

	public function test_filter_can_override_detected_url(): void {
		add_filter(
			'tainacan_oai_pmh_endpoint_url',
			static function () {
				return 'https://example.org/custom-oai';
			}
		);

		$this->assertSame( 'https://example.org/custom-oai', Endpoint::get_url() );

		remove_all_filters( 'tainacan_oai_pmh_endpoint_url' );
	}
}
