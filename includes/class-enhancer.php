<?php
/**
 * Enhancer: layers caching, rate limiting and request logging on top of the
 * OAI-PMH endpoint provided by Tainacan core (tainacan/v2/oai), via the core
 * extension hooks. This replaces the plugin's former competing provider.
 *
 * @package Tainacan_OAI_PMH
 */

namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Enhancer {

	const CACHE_VERSION_OPTION = 'tainacan_oai_enhanced_cache_version';
	const CACHE_PREFIX         = 'tnc_oai_enh_';

	/**
	 * @var Rate_Limiter
	 */
	private $rate_limiter;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @param Rate_Limiter|null $rate_limiter Shared rate limiter, or null to build one.
	 * @param Logger|null       $logger       Shared logger, or null to build one.
	 */
	public function __construct( $rate_limiter = null, $logger = null ) {
		$this->rate_limiter = $rate_limiter instanceof Rate_Limiter ? $rate_limiter : new Rate_Limiter();
		$this->logger       = $logger instanceof Logger ? $logger : new Logger();
	}

	/**
	 * Wire the enhancer into the core OAI-PMH hooks.
	 */
	public function register() {
		add_filter( 'tainacan-oai-permission', array( $this, 'check_rate_limit' ), 10, 2 );
		add_filter( 'tainacan-oai-pre-dispatch', array( $this, 'serve_cache' ), 10, 3 );
		add_action( 'tainacan-oai-response', array( $this, 'store_cache' ), 10, 4 );

		// Invalidate cached responses whenever the catalog changes.
		add_action( 'tainacan-insert', array( $this, 'flush' ) );
		add_action( 'tainacan-update', array( $this, 'flush' ) );
		add_action( 'trashed_post', array( $this, 'flush' ) );
		add_action( 'untrashed_post', array( $this, 'flush' ) );
	}

	/**
	 * Reject the request with HTTP 429 when the client is over the rate limit.
	 *
	 * @param bool|\WP_Error $allowed Current permission decision.
	 * @return bool|\WP_Error
	 */
	public function check_rate_limit( $allowed ) {
		if ( true !== $allowed ) {
			return $allowed;
		}
		if ( ! Settings::get( 'rate_limit_enabled', true ) ) {
			return true;
		}

		$check = $this->rate_limiter->check();
		if ( is_wp_error( $check ) ) {
			return new \WP_Error(
				'tainacan_oai_rate_limited',
				$check->get_error_message(),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Serve a cached response body when available.
	 *
	 * @param string|null $pre    Short-circuit body from an earlier filter.
	 * @param string      $verb   The requested verb.
	 * @param array       $params The request parameters.
	 * @return string|null
	 */
	public function serve_cache( $pre, $verb, $params ) {
		if ( null !== $pre || ! $this->cache_enabled() ) {
			return $pre;
		}
		$cached = get_transient( $this->cache_key( $verb, $params ) );
		return ( is_string( $cached ) && '' !== $cached ) ? $cached : null;
	}

	/**
	 * Store a freshly generated response body and log the request.
	 *
	 * @param string $xml        The response body.
	 * @param string $verb       The requested verb.
	 * @param array  $params     The request parameters.
	 * @param bool   $from_cache Whether the body came from a short-circuit filter.
	 */
	public function store_cache( $xml, $verb, $params, $from_cache ) {
		if ( ! $from_cache && $this->cache_enabled() && '' !== (string) $xml ) {
			set_transient( $this->cache_key( $verb, $params ), $xml, $this->cache_ttl() );
		}
		$this->logger->log( 'OAI-PMH: ' . $verb, 'info', array( 'verb' => $verb ) );
	}

	/**
	 * Invalidate every cached response by bumping the cache version.
	 */
	public function flush() {
		update_option( self::CACHE_VERSION_OPTION, (int) get_option( self::CACHE_VERSION_OPTION, 0 ) + 1 );
	}

	/**
	 * Whether response caching is enabled in the plugin settings.
	 *
	 * @return bool
	 */
	private function cache_enabled() {
		return (bool) Settings::get( 'cache_enabled', true );
	}

	/**
	 * Response cache lifetime, in seconds. Caches are also invalidated on every
	 * catalog change via flush(), so this is mainly a safety bound.
	 *
	 * @return int
	 */
	private function cache_ttl() {
		return HOUR_IN_SECONDS;
	}

	/**
	 * Build a version-scoped cache key for a request.
	 *
	 * @param string $verb   The OAI-PMH verb.
	 * @param array  $params The request parameters.
	 * @return string
	 */
	private function cache_key( $verb, $params ) {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 0 );
		if ( is_array( $params ) ) {
			ksort( $params );
		} else {
			$params = array();
		}
		return self::CACHE_PREFIX . md5( $version . '|' . $verb . '|' . wp_json_encode( $params ) );
	}
}
