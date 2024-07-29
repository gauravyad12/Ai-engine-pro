<?php

class MeowPro_MWAI_Addons_Qdrant {
  private $core = null;

  // Current Vector DB
  private $env = null;
  private $apiKey = null;
  private $server = null;
  private $collection = null;
  private $maxSelect = 10;

  function __construct() {
    global $mwai_core;
    $this->core = $mwai_core;
    $this->init_settings();

    add_filter( 'mwai_embeddings_list_vectors', array( $this, 'list_vectors' ), 10, 2 );
    add_filter( 'mwai_embeddings_add_vector', [ $this, 'add_vector' ], 10, 3 );
    add_filter( 'mwai_embeddings_get_vector', [ $this, 'get_vector' ], 10, 4 );
    add_filter( 'mwai_embeddings_query_vectors', [ $this, 'query_vectors' ], 10, 4 );
    add_filter( 'mwai_embeddings_delete_vectors', [ $this, 'delete_vectors' ], 10, 2 );

    // We don't have a way to delete everything related to a namespace yet, but it works like that:
    //$this->delete_vectors( null, null, true, 'nekod' );
  }

  function init_settings( $envId = null ) {
    $envId = $envId ?? $this->core->get_option( 'embeddings_env' );
    $this->env = $this->core->get_embeddings_env( $envId );

    // This class has only Pinecone support.
    if ( empty( $this->env ) || $this->env['type'] !== 'qdrant' ) {
      return false;
    }

    $this->apiKey = isset( $this->env['apikey'] ) ? $this->env['apikey'] : null;
    $this->server = isset( $this->env['server'] ) ? $this->env['server'] : null;
    $this->collection = isset( $this->env['collection'] ) ? $this->env['collection'] : null;
    $this->maxSelect = isset( $this->env['max_select'] ) ? (int)$this->env['max_select'] : 10;
    return true;
  }

  function run( $method, $url, $query = null, $json = true, $isAbsoluteUrl = false ) {
    $headers = "accept: application/json, charset=utf-8\r\ncontent-type: application/json\r\n" .
      "api-key: " . $this->apiKey . "\r\n";
    $body = $query ? json_encode( $query ) : null;
    $url = $isAbsoluteUrl ? $url : $this->server . $url;
    $options = [
      "headers" => $headers,
      "method" => $method,
      "timeout" => MWAI_TIMEOUT,
      "body" => $body,
      "sslverify" => false
    ];

    try {
      $response = wp_remote_request( $url, $options );
      if ( is_wp_error( $response ) ) {
        throw new Exception( $response->get_error_message() );
      }
      $response = wp_remote_retrieve_body( $response );
      $data = $response === "" ? true : ( $json ? json_decode( $response, true ) : $response );
      if ( !is_array( $data ) && empty( $data ) && is_string( $response ) ) {
        throw new Exception( $response );
      }
      return $data;
    }
    catch ( Exception $e ) {
      $this->core->log("❌ (Qdrant) " . $e->getMessage() );
      throw new Exception( $e->getMessage() . " (Qdrant)" );
    }
    return [];
  }

  function list_vectors( $vectors, $options ) {
    // Already handled.
    if ( !empty( $vectors ) ) { return $vectors; }
    $envId = $options['envId'];
    $limit = $options['limit'];
    $offset = $options['offset'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $vectors = $this->run( 'POST', "/collections/{$this->collection}/points/scroll", [
      'limit' => $limit,
      'offset' => $offset,
      'with_payload' => false,
      'with_vector' => false,
    ], true );
    $vectors = isset( $vectors['result']['points'] ) ? $vectors['result']['points'] : [];
    // $vectors = array_map( function( $vector ) {
    //   return [
    //     'id' => $vector['id'],
    //     'type' => isset( $vector['payload']['type'] ) ? $vector['payload']['type'] : 'manual',
    //     'title' => isset( $vector['payload']['title'] ) ? $vector['payload']['title'] : '',
    //     'values' => isset( $vector['vector'] ) ? $vector['vector'] : []
    //   ];
    // }, $vectors );
    $vectors = array_map( function( $vector ) { return $vector['id']; }, $vectors );
    return $vectors;
  }

  function delete_vectors( $success, $options ) {
    // Already handled.
    if ( $success ) { return $success; }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $ids = $options['ids'];
    $deleteAll = $options['deleteAll'];
    if ( $deleteAll ) {
      $body = [
        'filter' => [
          'must' => [[
            "is_empty" => [
              "key" => "any"
            ]
          ]]
        ]
      ];
    } else {
      $body = ['points' => $ids];
    }
    $success = $this->run( 'POST', "/collections/{$this->collection}/points/delete", $body );
    $success = true;
    return $success;
  }

  function add_vector( $success, $vector, $options, $tryCreateCollection = true) {
    if ( $success ) { return $success; }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $randomId = $this->get_uuid();
    $body = [
      'points' => [
        [
          'id' => $randomId,
          'vector' => $vector['embedding'],
          'payload' => [
            'type' => $vector['type'],
            'title' => $vector['title'],
            'model' => $vector['model']
          ]
        ]
      ]
    ];
    $res = $this->run( 'PUT', "/collections/{$this->collection}/points", $body );
    $success = isset( $res['status'] ) && $res['status'] === "ok";
    if ( $success ) {
      return $randomId;
    }
    $error = isset( $res['status']['error'] ) ? $res['status']['error'] : 'Unknown error from Qdrant.';

    // Create the collection if it doesn't exist and try again.
    $collectionNotFound = "`{$this->collection}` doesn't exist";
    if ( $tryCreateCollection && strpos( $error, $collectionNotFound ) !== false ) {
      if ( $this->create_collection() ) {
        return $this->add_vector( $success, $vector, $options, false );
      }
    }

    throw new Exception( $error );
  }

  function query_vectors( $vectors, $vector, $options ) {
    // Output the content of the $vector array to see what's inside in the error_log
    if ( !empty( $vectors ) ) { return $vectors; }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $body = [ 'limit' => $this->maxSelect, 'vector' => $vector, 'with_payload' => true ];
    $res = $this->run( 'POST', "/collections/{$this->collection}/points/search", $body );
    $vectors = isset( $res['result'] ) ? $res['result'] : [];
    foreach ( $vectors as &$vector ) {
      $vector['metadata'] = $vector['payload'];
    }
    return $vectors;
  }

  function get_vector( $vector, $vectorId, $options ) {
    // Check if the filter has been already handled.
    if ( !empty( $vector ) ) { return $vector; }
    $vectorId = $vectorId;
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $res = $this->run( 'GET', "/collections/{$this->collection}/points/{$vectorId}" );
    $removeVector = isset( $res['result']['id'] ) ? $res['result'] : null;
    if ( !empty( $removeVector ) ) {
      return [
        'id' => $vectorId,
        'type' => isset( $removeVector['payload']['type'] ) ? $removeVector['payload']['type'] : 'manual',
        'title' => isset( $removeVector['payload']['title'] ) ? $removeVector['payload']['title'] : '',
        'model' => isset( $removeVector['payload']['model'] ) ? $removeVector['payload']['model'] : '',
        'values' => isset( $removeVector['vector'] ) ? $removeVector['vector'] : []
      ];
    }
    return null;
  }

  function create_collection() {
    $res = $this->run( 'PUT', "/collections/{$this->collection}", [
      'vectors' => [
        'distance' => 'Cosine',
        'size' => apply_filters( 'mwai_embeddings_qdrant_vector_size', 1536 )
      ]
    ], true );
    $success = isset( $res['status'] ) && $res['status'] === "ok";
    if ( $success ) {
      return true;
    }
    $error = isset( $res['status']['error'] ) ? $res['status']['error'] : 'Unknown error from Qdrant.';
    throw new Exception( $error );
  }

  function get_uuid( $len = 32, $strong = true ) {
    $data = openssl_random_pseudo_bytes( $len, $strong );
    $data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // set version to 0100
    $data[8] = chr( ord($data[8] ) & 0x3f | 0x80 ); // set bits 6-7 to 10
    return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
  }
}