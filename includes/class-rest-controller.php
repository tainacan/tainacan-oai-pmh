<?php
namespace Tainacan_OAI_PMH;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Controller {

	private $namespace = 'tainacan-oai/v1';
	private $data_provider;
	private $cache;
	private $logger;
	private $token_manager;
	private $rate_limiter;

	public function __construct() {
		$this->data_provider = new Data_Provider();
		$this->cache         = new Cache();
		$this->logger        = new Logger();
		$this->token_manager = new Token_Manager();
		$this->rate_limiter  = new Rate_Limiter();
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/oai',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_request( \WP_REST_Request $request ) {
		$start = microtime( true );

		// Rate limiting
		if ( Settings::get( 'rate_limit_enabled', true ) ) {
			$check = $this->rate_limiter->check();
			if ( is_wp_error( $check ) ) {
				$xml = new XML_Generator();
				$xml->init( $this->data_provider->get_base_url(), '' )->add_error( '503', $check->get_error_message() );
				return $this->respond( $xml, '', array(), $start );
			}
		}

		$params = array_merge( $request->get_query_params(), $request->get_body_params() );
		$verb   = $params['verb'] ?? '';

		$xml         = new XML_Generator();
		$base_url    = $this->data_provider->get_base_url();
		$valid_verbs = array( 'Identify', 'ListMetadataFormats', 'ListSets', 'ListRecords', 'ListIdentifiers', 'GetRecord' );

		if ( empty( $verb ) ) {
			$xml->init( $base_url, '' )->add_error( 'badVerb', 'Missing verb argument.' );
			return $this->respond( $xml, $verb, $params, $start );
		}

		if ( ! in_array( $verb, $valid_verbs ) ) {
			$xml->init( $base_url, $verb )->add_error( 'badVerb', 'Illegal OAI verb.' );
			return $this->respond( $xml, $verb, $params, $start );
		}

		$xml->init( $base_url, $verb, $params );

		switch ( $verb ) {
			case 'Identify':
				$xml->create_identify( $this->data_provider->get_identify() );
				break;
			case 'ListMetadataFormats':
				$this->handle_list_metadata_formats( $xml, $params );
				break;
			case 'ListSets':
				$xml->create_sets( $this->data_provider->get_sets() );
				break;
			case 'ListRecords':
				$this->handle_list_records( $xml, $params, true );
				break;
			case 'ListIdentifiers':
				$this->handle_list_records( $xml, $params, false );
				break;
			case 'GetRecord':
				$this->handle_get_record( $xml, $params );
				break;
		}

		return $this->respond( $xml, $verb, $params, $start );
	}

	private function handle_list_metadata_formats( $xml, $params ) {
		if ( ! empty( $params['identifier'] ) && ! $this->data_provider->item_exists( $params['identifier'] ) ) {
			$xml->add_error( 'idDoesNotExist', 'The identifier does not exist.' );
			return;
		}
		$xml->create_metadata_formats();
	}

	private function handle_list_records( $xml, $params, $include_metadata ) {
		$query = $this->parse_list_params( $xml, $params );
		if ( $query === false ) {
			return;
		}

		$max_records = (int) Settings::get( 'max_records', 100 );

		$items = $this->cache->get_items(
			array(
				'per_page'      => $max_records,
				'page'          => $query['page'],
				'status'        => array( 'publish' ),
				'collection_id' => $query['set'] ?? null,
				'from'          => $query['from'] ?? null,
				'until'         => $query['until'] ?? null,
			)
		);

		$total = $this->cache->count_items(
			array(
				'status'        => array( 'publish' ),
				'collection_id' => $query['set'] ?? null,
				'from'          => $query['from'] ?? null,
				'until'         => $query['until'] ?? null,
			)
		);

		if ( empty( $items ) ) {
			$xml->add_error( 'noRecordsMatch', 'No records match the request criteria.' );
			return;
		}

		$list_type = $include_metadata ? 'ListRecords' : 'ListIdentifiers';
		$list      = $xml->start_list( $list_type );

		foreach ( $items as $item ) {
			$data = array(
				'identifier' => $item->identifier,
				'datestamp'  => $item->datestamp,
				'setSpec'    => (string) $item->collection_id,
				'status'     => $item->status,
				'metadata'   => json_decode( $item->metadata_json, true ),
			);

			if ( $include_metadata ) {
				$xml->add_record( $list, $data, true );
			} else {
				$xml->add_header( $list, $data );
			}
		}

		$delivered = $query['page'] * $max_records;
		if ( $delivered < $total ) {
			$next_query         = $query;
			$next_query['page'] = $query['page'] + 1;

			$token        = $this->token_manager->create( $next_query );
			$expiry_hours = (int) Settings::get( 'token_expiry', 24 );
			$expiration   = gmdate( 'Y-m-d\TH:i:s\Z', time() + ( $expiry_hours * 3600 ) );
			$cursor       = ( $query['page'] - 1 ) * $max_records;

			$xml->add_resumption_token( $list, $token, $total, $cursor, $expiration );
		} elseif ( ! empty( $params['resumptionToken'] ) ) {
			$cursor = ( $query['page'] - 1 ) * $max_records;
			$xml->add_resumption_token( $list, '', $total, $cursor );
		}
	}

	private function handle_get_record( $xml, $params ) {
		if ( empty( $params['identifier'] ) ) {
			$xml->add_error( 'badArgument', 'Missing identifier argument.' );
			return;
		}
		if ( empty( $params['metadataPrefix'] ) ) {
			$xml->add_error( 'badArgument', 'Missing metadataPrefix argument.' );
			return;
		}
		if ( $params['metadataPrefix'] !== 'oai_dc' ) {
			$xml->add_error( 'cannotDisseminateFormat', 'Metadata format not supported.' );
			return;
		}

		$item = $this->data_provider->get_item( $params['identifier'] );
		if ( ! $item ) {
			$xml->add_error( 'idDoesNotExist', 'The identifier does not exist.' );
			return;
		}

		$list = $xml->start_list( 'GetRecord' );
		$xml->add_record( $list, $item, true );
	}

	private function parse_list_params( $xml, $params ) {
		$query = array( 'page' => 1 );

		if ( ! empty( $params['resumptionToken'] ) ) {
			$data = $this->token_manager->get( $params['resumptionToken'] );
			if ( ! $data ) {
				$xml->add_error( 'badResumptionToken', 'Invalid or expired token.' );
				return false;
			}
			return $data;
		}

		if ( empty( $params['metadataPrefix'] ) ) {
			$xml->add_error( 'badArgument', 'Missing metadataPrefix argument.' );
			return false;
		}
		if ( $params['metadataPrefix'] !== 'oai_dc' ) {
			$xml->add_error( 'cannotDisseminateFormat', 'Metadata format not supported.' );
			return false;
		}

		$query['metadataPrefix'] = 'oai_dc';

		// Treat null/'' as "no set filter"; everything else (including '0') must validate
		if ( isset( $params['set'] ) && $params['set'] !== '' ) {
			if ( ! $this->data_provider->set_exists( $params['set'] ) ) {
				$xml->add_error( 'badArgument', 'Invalid set specification.' );
				return false;
			}
			$query['set'] = (int) $params['set'];
		}

		if ( ! empty( $params['from'] ) ) {
			$query['from'] = $this->parse_date( $params['from'] );
		}
		if ( ! empty( $params['until'] ) ) {
			$query['until'] = $this->parse_date( $params['until'] );
		}

		return $query;
	}

	private function parse_date( $date ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date ) ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( $date ) );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date . ' 00:00:00';
		}
		return null;
	}

	private function respond( $xml, $verb, $params, $start ) {
		$time = round( microtime( true ) - $start, 3 );
		$this->logger->log(
			"OAI Request: $verb",
			'info',
			array(
				'verb'          => $verb,
				'response_time' => $time,
			)
		);

		$response = new \WP_REST_Response();
		$response->set_headers( array( 'Content-Type' => 'text/xml; charset=utf-8' ) );
		$response->set_data( $xml->output() );

		add_filter(
			'rest_pre_serve_request',
			function ( $served, $result ) {
				// Output is well-formed OAI-PMH XML produced by DOMDocument::saveXML
				// (XML_Generator). Running it through esc_html() would corrupt the
				// XML structure (entities re-encoded, tags broken).
				echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				return true;
			},
			10,
			2
		);

		return $response;
	}
}
