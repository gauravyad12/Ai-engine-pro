<?php

class MeowPro_MWAI_Assistants {
  private $core = null;
  private $namespace = 'mwai/v1/';

  function __construct( $core ) {
    $this->core = $core;
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );

    // Handle mwai_files_delete
    add_filter( 'mwai_files_delete', [ $this, 'files_delete_filter' ], 10, 2 );
  }

  #region REST API

  function rest_api_init() {
    register_rest_route( $this->namespace, '/openai/assistants/list', [
      'methods' => 'GET',
      'permission_callback' => [ $this->core, 'can_access_settings' ],
      'callback' => [ $this, 'rest_assistants_list' ],
    ] );
    register_rest_route( $this->namespace, '/openai/assistants/set_functions', [
      'methods' => 'POST',
      'permission_callback' => [ $this->core, 'can_access_settings' ],
      'callback' => [ $this, 'rest_assistants_set_functions' ],
    ] );
  }

  function rest_assistants_list( $request ) {
    try {
      $envId = $request->get_param( 'envId' );
      $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
      $rawAssistants = [];
      $hasMore = true;
      $lastId = null;
      while ( $hasMore ) {
        $query = '/assistants?limit=25';
        if ($lastId !== null) {
          $query .= '&after=' . $lastId;
        }
        $res = $openai->execute( 'GET', $query, null, null, true, [
          'OpenAI-Beta' => 'assistants=v2'
        ] );
        $data = $res['data'];
        $rawAssistants = array_merge( $rawAssistants, $data );
        $lastId = $res['last_id'];
        $hasMore = $res['has_more'];
      }

      $assistants = array_map( function ( $assistant ) {
        //$assistant['files_count'] = count( $assistant['file_ids'] );
        $assistant['createdOn'] = date( 'Y-m-d H:i:s', $assistant['created_at'] );
        $has_code_interpreter = false;
        $has_file_search = false;
        foreach ( $assistant['tools'] as $tool ) {
          if ( $tool['type'] === 'code_interpreter' ) {
            $has_code_interpreter = true;
          }
          if ( $tool['type'] === 'file_search' ) {
            $has_file_search = true;
          }
        }
        $assistant['has_code_interpreter'] = $has_code_interpreter;
        $assistant['has_file_search'] = $has_file_search;
        unset( $assistant['file_ids'] );
        unset( $assistant['metadata'] );
        unset( $assistant['tools'] );
        unset( $assistant['created_at'] );
        unset( $assistant['updated_at'] );
        unset( $assistant['deleted_at'] );
        unset( $assistant['tools'] );
        unset( $assistant['object'] );
        return $assistant;
      }, $rawAssistants );
      $this->core->update_ai_env( $envId, 'assistants', $assistants );
      return new WP_REST_Response([ 'success' => true, 'assistants' => $assistants ], 200 ); 
    }
    catch ( Exception $e ) {
			$message = apply_filters( 'mwai_ai_exception', $e->getMessage() );
			return new WP_REST_Response([ 'success' => false, 'message' => $message ], 500 );
		}
  }

  function rest_assistants_set_functions( $request ) {
    try {
      $envId = $request->get_param( 'envId' );
      $assistantId = $request->get_param( 'assistantId' );
      $functions = $request->get_param( 'functions' );
      $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
      $tools = [];
      foreach ( $functions as $function ) {
        $queryFunction = MeowPro_MWAI_FunctionAware::create_query_function( $function['type'], $function['id'] );
        $tools[] = [ 'type' => 'function', 'function' => $queryFunction->serializeForOpenAI() ];
      }
      $res = $openai->execute( 'POST', "/assistants/{$assistantId}", [ 'tools' => $tools ], null, true, 
        [ 'OpenAI-Beta' => 'assistants=v2' ]
      );
      return new WP_REST_Response([ 'success' => !empty( $res ) ], 200 );
    }
    catch ( Exception $e ) {
      $message = apply_filters( 'mwai_ai_exception', $e->getMessage() );
      return new WP_REST_Response([ 'success' => false, 'message' => $message ], 500 );
    }
  }

  #endregion

  #region Files Delete Filter
  public function get_env_id_from_assistant_id( $assistantId ) {
    $envs = $this->core->get_option( 'ai_envs' );
    foreach ( $envs as $env ) {
      if ( !empty( $env['assistants'] ) ) {
        foreach ( $env['assistants'] as $assistant ) {
          if ( $assistant['id'] === $assistantId ) {
            return $env['id'];
          }
        }
      }
    }
    return null;
  }

  public function files_delete_filter( $refIds ) {
    foreach ( $refIds as $refId ) {
      $metadata = $this->core->files->get_metadata( $refId );
      $assistantId = $metadata['assistant_id'] ?? null;
      $threadId = $metadata['assistant_threadId'] ?? null;
      if ( !empty( $assistantId ) && !empty( $threadId ) ) {
        $envId = $this->get_env_id_from_assistant_id( $assistantId );
        if ( !empty( $envId ) ) {
          $openai = Meow_MWAI_Engines_Factory::get_openai( $this->core, $envId );
          try {
            $openai->execute( 'DELETE', "/files/{$refId}", null, null, true, [ 'OpenAI-Beta' => 'assistants=v2' ] );
          }
          catch ( Exception $e ) {
            $this->core->log( '❌ (Assistants) ' . $e->getMessage() );
          }
        }
      }
    }
    return $refIds;
  }
  #endregion
}