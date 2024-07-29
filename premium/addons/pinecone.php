<?php

class MeowPro_MWAI_Addons_Pinecone {
  private $core = null;

  // Current Vector DB
  private $env = null;
  private $apiKey = null;
  private $server = null;
  private $namespace = null;
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
    if ( empty( $this->env ) || $this->env['type'] !== 'pinecone' ) {
      return false;
    }

    $this->apiKey = isset( $this->env['apikey'] ) ? $this->env['apikey'] : null;
    $this->server = isset( $this->env['server'] ) ? $this->env['server'] : null;
    $this->namespace = isset( $this->env['namespace'] ) ? $this->env['namespace'] : null;
    $this->maxSelect = isset( $this->env['max_select'] ) ? (int)$this->env['max_select'] : 10;
    return true;
  }

  // Generic function to run a request to Pinecone.
  function run( $method, $url, $query = null, $json = true, $isAbsoluteUrl = false )
  {
    $headers = "accept: application/json, charset=utf-8\r\ncontent-type: application/json\r\n" . 
      "Api-Key: " . $this->apiKey . "\r\n";
    $body = $query ? json_encode( $query ) : null;
    $url = $isAbsoluteUrl ? $url : "https://controller." . $this->server . ".pinecone.io" . $url;
    $url = untrailingslashit( esc_url_raw( $url ) );
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
      $this->core->log("❌ (Pinecone) " . $e->getMessage() );
      throw new Exception( $e->getMessage() . " (Pinecone)" );
    }
    return [];
  }

  // List all vectors from Pinecone.
  function list_vectors( $vectors, $options ) {
    if ( !empty( $vectors ) ) { return $vectors; }
    $envId = $options['envId'];
    $limit = $options['limit'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    // We are using a trick here to get all the vectors. We are querying for a vector that doesn't exist.
    include_once( 'empty_vector.php' );
    $body = [ 'topK' => $limit, 'vector' => VECTOR_SPACE ];
    if ( $this->namespace ) {
      $body['namespace'] = $this->namespace;
    }
    $res = $this->run( 'POST', "{$this->server}/query", $body, true, true );
    $vectors = isset( $res['matches'] ) ? $res['matches'] : [];
    $vectors = array_map( function( $v ) { return $v['id']; }, $vectors );
    return $vectors;
  }

  // Delete vectors from Pinecone.
  function delete_vectors( $success, $options ) {
    // Already handled.
    if ( $success ) { return $success; }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $ids = $options['ids'];
    $deleteAll = $options['deleteAll'];
    $body = [
      'ids' => $deleteAll ? null : $ids,
      'deleteAll' => $deleteAll
    ];
    if ( $this->namespace ) {
      $body['namespace'] = $this->namespace;
    }
    // If delete fails, an exception will be thrown. Otherwise, it's successful.
    $success = $this->run( 'POST', "{$this->server}/vectors/delete", $body, true, true );
    $success = true;
    return $success;
  }

  // Add a vector to Pinecone.
  function add_vector( $success, $vector, $options ) {
    if ( $success ) { return $success; }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $randomId = bin2hex( random_bytes( 32 ) );
    $body = [
      'vectors' => [
        'id' => $randomId,
        'values' => $vector['embedding'],
        'metadata' => [
          'type' => $vector['type'],
          'title' => $vector['title'],
          'model' => $vector['model']
        ]
      ]
    ];
    if ( $this->namespace ) {
      $body['namespace'] = $this->namespace;
    }
    $res = $this->run( 'POST', "{$this->server}/vectors/upsert", $body, true, true );
    $success = isset( $res['upsertedCount'] ) && $res['upsertedCount'] > 0;
    if ( $success ) {
      return $randomId;
    }
    $error = isset( $res['message'] ) ? $res['message'] : 'Unknown error from Pinecone.';
    throw new Exception( $error );
  }

  // Query vectors from Pinecone.
  function query_vectors( $vectors, $vector, $options ) {
    if ( !empty( $vectors ) ) { return $vectors; }
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $body = [ 'topK' => $this->maxSelect, 'vector' => $vector ];
    if ( $this->namespace ) {
      $body['namespace'] = $this->namespace;
    }
    $res = $this->run( 'POST', "{$this->server}/query", $body, true, true );
    $vectors = isset( $res['matches'] ) ? $res['matches'] : [];
    return $vectors;
  }

  // Get a vector from Pinecone.
  function get_vector( $vector, $vectorId, $options ) {
    // Check if the filter has been already handled.
    if ( !empty( $vector ) ) { return $vector; }
    $vectorId = $vectorId;
    $envId = $options['envId'];
    if ( !$this->init_settings( $envId ) ) {
      return false;
    }
    $url = "{$this->server}/vectors/fetch?ids={$vectorId}";
    if ( $this->namespace ) {
      $url .= "&namespace={$this->namespace}";
    } 
    $res = $this->run( 'GET', $url, null, true, true );
    if ( isset( $res['vectors'] ) && isset( $res['vectors'][$vectorId] ) ) {
      $vector = $res['vectors'][$vectorId];
      return [
        'id' => $vectorId,
        'type' => isset( $vector['metadata']['type'] ) ? $vector['metadata']['type'] : 'manual',
        'title' => isset( $vector['metadata']['title'] ) ? $vector['metadata']['title'] : '',
        'model' => isset( $vector['metadata']['model'] ) ? $vector['metadata']['model'] : '',
        'values' => isset( $vector['values'] ) ? $vector['values'] : []
      ];
    }
    return null;
  }
}
