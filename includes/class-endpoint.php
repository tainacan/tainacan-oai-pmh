<?php
/**
 * Resolves the local OAI-PMH endpoint URL: core provider first, plugin fallback.
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Endpoint {

	const CORE_ROUTE          = 'tainacan/v2/oai';
	const LEGACY_PLUGIN_ROUTE = 'tainacan-oai/v1/oai';

	/**
	 * Base URL harvesters should use for this repository.
	 *
	 * Prefers the endpoint registered by Tainacan core. Falls back to the
	 * plugin's legacy route only when core does not expose OAI-PMH.
	 *
	 * @return string
	 */
	public static function get_url() {
		$filtered = apply_filters( 'tainacan_oai_pmh_endpoint_url', null );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			return $filtered;
		}

		$core_url = self::get_core_url();
		if ( null !== $core_url ) {
			return $core_url;
		}

		return rest_url( self::LEGACY_PLUGIN_ROUTE );
	}

	/**
	 * @return string|null Core endpoint URL when OAI-PMH is provided by Tainacan.
	 */
	private static function get_core_url() {
		if ( class_exists( '\Tainacan\OAIPMH\OAIPMH_Data_Provider' ) ) {
			return ( new \Tainacan\OAIPMH\OAIPMH_Data_Provider() )->get_base_url();
		}

		if ( class_exists( '\Tainacan\API\EndPoints\REST_Oaipmh_Controller' ) ) {
			return rest_url( self::CORE_ROUTE );
		}

		if ( self::rest_route_is_registered( self::CORE_ROUTE ) ) {
			return rest_url( self::CORE_ROUTE );
		}

		return null;
	}

	/**
	 * @param string $route REST route without leading slash, e.g. "tainacan/v2/oai".
	 * @return bool
	 */
	private static function rest_route_is_registered( $route ) {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return false;
		}

		$normalized = '/' . trim( $route, '/' );
		$routes     = rest_get_server()->get_routes();

		return isset( $routes[ $normalized ] ) || isset( $routes[ $normalized . '/' ] );
	}
}
