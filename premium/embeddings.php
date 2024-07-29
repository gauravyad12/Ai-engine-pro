<?php

class MeowPro_MWAI_Embeddings {
  private $core = null;
  private $wpdb = null;
  private $db_check = false;
  private $table_vectors = null;
  private $namespace = 'mwai/v1/';

  // Embeddings Settings
  private $settings = [];
  private $sync_posts = false;
  private $sync_post_envId = null;
  private $sync_post_types = [];
  private $sync_post_status = [ 'publish' ];
  private $sync_post_categories = [];
  private $force_recreate = false;
  private $rewrite_content = false;
  private $rewrite_prompt = false;

  // Vector DB Settings
  private $default_envId = null;

  function __construct() {
    global $wpdb, $mwai_core;
    $this->core = $mwai_core;
    $this->wpdb = $wpdb;
    $this->table_vectors = $wpdb->prefix . 'mwai_vectors';

    // Embeddings Services
    new MeowPro_MWAI_Addons_Pinecone();
    new MeowPro_MWAI_Addons_Qdrant();

    // TODO: Remove after July 2024
    $this->march_embeddings_upgrade();

    $this->default_envId = $this->core->get_option( 'embeddings_default_env' );
    $this->settings = $this->core->get_option( 'embeddings' );
    $this->sync_posts = isset( $this->settings['syncPosts'] ) ? $this->settings['syncPosts'] : false;
    $this->sync_post_envId = isset( $this->settings['syncPostsEnvId'] ) ? $this->settings['syncPostsEnvId'] : null;
    $this->sync_post_types = isset( $this->settings['syncPostTypes'] ) ? $this->settings['syncPostTypes'] : [];
    $this->sync_post_status = isset( $this->settings['syncPostStatus'] ) ? $this->settings['syncPostStatus'] : [ 'publish' ];
    $this->sync_post_categories = isset( $this->settings['syncPostCategories'] ) ? $this->settings['syncPostCategories'] : [];
    $this->force_recreate = isset( $this->settings['forceRecreate'] ) ? $this->settings['forceRecreate'] : false;
    $this->rewrite_content = isset( $this->settings['rewriteContent'] ) ? $this->settings['rewriteContent'] : false;
    $this->rewrite_prompt = isset( $this->settings['rewritePrompt'] ) ? $this->settings['rewritePrompt'] : false;

    // Activate the synchronization only if the sync_post_envId is set.
    $this->sync_posts = $this->sync_posts && !empty( $this->sync_post_envId );

    // AI Engine Filters
    add_filter( 'mwai_context_search', [ $this, 'context_search' ], 10, 3 );
    add_action( 'mwai_tasks_run', [ $this, 'run_tasks' ] );
    
    // WordPress Filters
    add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
    add_action( 'save_post', array( $this, 'action_save_post' ), 10, 3 );
    if ( $this->sync_posts ) {
      add_action( 'wp_trash_post', array( $this, 'action_delete_post' ) );
    }
  }

  // TODO: Remove this after June 2024
  function march_embeddings_upgrade() {

    // Check if the upgrade is currently running.
    if ( get_transient( 'mwai_embeddings_upgrade' ) ) {
      return;
    }
    set_transient( 'mwai_embeddings_upgrade', true, 60 * 60 * 24 );

    $dbIndexExists = $this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_vectors LIKE 'dbIndex'" );
    if ( $dbIndexExists ) {
      $settings = $this->core->get_option( 'embeddings' );

      // Update Settings for Envs
      $oldEnvToNew = [];
      $envs = $this->core->get_option( 'embeddings_envs' );
      $minScore = empty( $settings['min_score'] ) ? 35 : (float)$settings['min_score'];
      $maxSelect = empty( $settings['max_select'] ) ? 10 : (int)$settings['max_select'];
      $newEnvs = [];
      foreach ( $envs as $env ) {
        if ( $env['type'] === 'pinecone' ) {
          if ( empty( $env['indexes'] ) ) {
            continue;
          }
          $isFirst = true;
          $oldEnvId = $env['id'];
          $namespaces = isset( $env['namespaces'] ) ? $env['namespaces'] : [];
          array_unshift( $namespaces, '' );
          foreach ( $env['indexes'] as $indexData ) {
            foreach ( $namespaces as $namespace ) {
              $envId = $isFirst ? $oldEnvId : $this->core->get_random_id();
              $server = 'https://' . $indexData['host'];
              $indexName = $indexData['name'];
              $envName = $env['name'] . ( $isFirst ? '' : " ($indexName" . ( !empty( $namespace ) ? ( " - $namespace" ) : '' ) . ")" );
              $newEnv = [
                'id' => $envId,
                'name' => $envName,
                'type' => 'pinecone',
                'apikey' => $env['apikey'],
                'server' => $server,
                'namespace' => $namespace,
                'min_score' => $minScore,
                'max_select' => $maxSelect,
                'ai_embeddings_override' => isset( $env['ai_embeddings_override'] ) ? $env['ai_embeddings_override'] : false,
                'ai_embeddings_env' => isset( $env['ai_embeddings_env'] ) ? $env['ai_embeddings_env'] : null,
                'ai_embeddings_model' => isset( $env['ai_embeddings_model'] ) ? $env['ai_embeddings_model'] : null,
                'ai_embeddings_dimensions' => null
              ];
              $oldKey = $oldEnvId . '-' . $indexName . ( !empty( $namespace ) ? ( '-' . $namespace ) : '' );
              $oldEnvToNew[$oldKey] = $envId;
              $newEnvs[] = $newEnv;
              $isFirst = false;
            }
          }
        }
        else {
          $newEnv = [
            'id' => $env['id'],
            'name' => $env['name'],
            'type' => $env['type'],
            'apikey' => $env['apikey'],
            'server' => $env['server'],
            'min_score' => $minScore,
            'max_select' => $maxSelect,
            'ai_embeddings_override' => isset( $env['ai_embeddings_override'] ) ? $env['ai_embeddings_override'] : false,
            'ai_embeddings_env' => isset( $env['ai_embeddings_env'] ) ? $env['ai_embeddings_env'] : null,
            'ai_embeddings_model' => isset( $env['ai_embeddings_model'] ) ? $env['ai_embeddings_model'] : null,
            'ai_embeddings_dimensions' => null
          ];
          $newEnvs[] = $newEnv;
        }
      }

      function findNewEnvId( $oldEnvId, $indexName, $namespace, $oldEnvToNew ) {
        $key = $oldEnvId . '-' . $indexName . ( !empty( $namespace ) ? ( '-' . $namespace ) : '' );
        return isset( $oldEnvToNew[$key] ) ? $oldEnvToNew[$key] : null;
      }

      // Update Settings for Embeddings
      if ( !isset( $settings['syncPostsEnvId'] ) ) {
        $settings['syncPostsEnvId'] = findNewEnvId( 
          $settings['syncPostsEnv']['envId'],
          $settings['syncPostsEnv']['dbIndex'],
          $settings['syncPostsEnv']['dbNS'],
          $oldEnvToNew
        );
      }

      if ( !empty( $newEnvs ) ) {
        $this->core->update_option( 'embeddings_envs', $newEnvs );
        $this->core->log( "🟢 (Embeddings) Environments have been updated." );
      }

      // Update Chatbots
      $chatbotsHasChanged = false;
      $chatbots = $this->core->get_chatbots();
      foreach ( $chatbots as &$chatbot ) {
        $oldEnvId = isset( $chatbot['embeddingsEnvId'] ) ? $chatbot['embeddingsEnvId'] : null;

        // First, check if this $oldEnvId actually exist in the new envs.
        $oldEnvIsActuallyGood = false;
        foreach ( $envs as $env ) {
          if ( $env['id'] === $oldEnvId ) {
            $oldEnvIsActuallyGood = true;
            break;
          }
        }

        if ( !$oldEnvIsActuallyGood && !empty( $oldEnvId ) ) {
          $embeddingsIndex = $chatbot['embeddingsIndex'];
          $embeddingsNamespace = $chatbot['embeddingsNamespace'];
          $newEnvId = findNewEnvId( $oldEnvId, $embeddingsIndex, $embeddingsNamespace, $oldEnvToNew );
          $chatbot['embeddingsEnvId'] = $newEnvId;
          unset( $chatbot['embeddingsIndex'] );
          unset( $chatbot['embeddingsNamespace'] );
          $chatbotsHasChanged = true;
        }
      }

      $queriesToRun = [];
      $totalVectors = $this->wpdb->get_var( "SELECT COUNT(*) FROM $this->table_vectors" );
      $updatedVectors = 0;
      foreach ( $oldEnvToNew as $oldEnvData => $newEnvId ) {
        $splitted = explode( '-', $oldEnvData );
        $oldEnvId = $splitted[0];
        $index = $splitted[1];
        $namespace = isset( $splitted[2] ) ? $splitted[2] : null;
        $newEnvId = $oldEnvToNew[$oldEnvData];
        $countQuery = $this->wpdb->prepare(
          "SELECT COUNT(*) FROM $this->table_vectors WHERE envId = %s AND dbIndex = %s AND dbNS = %s",
          $oldEnvId, $index, $namespace
        );
        $updateQuery = $this->wpdb->prepare(
          "UPDATE $this->table_vectors SET envId = %s WHERE envId = %s AND dbIndex = %s AND dbNS = %s",
          $newEnvId, $oldEnvId, $index, $namespace
        );
        if ( is_null( $namespace ) ) {
          $countQuery = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM $this->table_vectors WHERE envId = %s AND dbIndex = %s AND dbNS IS NULL",
            $oldEnvId, $index
          );
          $updateQuery = $this->wpdb->prepare(
            "UPDATE $this->table_vectors SET envId = %s WHERE envId = %s AND dbIndex = %s AND dbNS IS NULL",
            $newEnvId, $oldEnvId, $index
          );
        }
        $count = $this->wpdb->get_var( $countQuery );
        if ( $count > 0 ) {
          $queriesToRun[] = $updateQuery;
          $updatedVectors += $count;
        }
      }

      if ( $chatbotsHasChanged ) {
        $this->core->update_chatbots( $chatbots );
        $this->core->log( "🟢 (Embeddings) Chatbots have been updated." );
      }
      if ( isset( $settings['syncPostsEnv'] ) ) {
        unset( $settings['syncPostsEnv'] );
        unset( $settings['minScore'] );
        unset( $settings['maxSelect'] );
        $this->core->update_option( 'embeddings', $settings );
        $this->core->log( "🟢 (Embeddings) Settings have been updated." );
      }
      if ( !empty( $queriesToRun ) ) {
        foreach ( $queriesToRun as $query ) {
          $this->wpdb->query( $query );
        }
        $this->core->log( "⚠️ (Embeddings) $updatedVectors vectors out of $totalVectors have been updated." );
      }

      $dbIndexExists = $this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_vectors LIKE 'dbIndex'" );
      if ( $dbIndexExists ) {
        $this->wpdb->query( "ALTER TABLE $this->table_vectors DROP COLUMN dbIndex" );
      }
      $dbNSExists = $this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_vectors LIKE 'dbNS'" );
      if ( $dbNSExists ) {
        $this->wpdb->query( "ALTER TABLE $this->table_vectors DROP COLUMN dbNS" );
      }
    }
    delete_transient( 'mwai_embeddings_upgrade' );
  }

  #region REST API

  function rest_api_init() {
		try {
      // Vectors
      register_rest_route( $this->namespace, '/vectors/list', array(
				'methods' => 'POST',
				'permission_callback' => array( $this->core, 'can_access_settings' ),
				'callback' => array( $this, 'rest_vectors_list' ),
			) );
			register_rest_route( $this->namespace, '/vectors/add', array(
				'methods' => 'POST',
				'permission_callback' => array( $this->core, 'can_access_settings' ),
				'callback' => array( $this, 'rest_vectors_add' ),
			) );
      register_rest_route( $this->namespace, '/vectors/add_from_remote', array(
				'methods' => 'POST',
				'permission_callback' => array( $this->core, 'can_access_settings' ),
				'callback' => array( $this, 'rest_vectors_add_from_remote' ),
			) );
			register_rest_route( $this->namespace, '/vectors/ref', array(
				'methods' => 'POST',
				'permission_callback' => array( $this->core, 'can_access_settings' ),
				'callback' => array( $this, 'rest_vectors_by_ref' ),
			) );
			register_rest_route( $this->namespace, '/vectors/update', array(
				'methods' => 'POST',
				'permission_callback' => array( $this->core, 'can_access_settings' ),
				'callback' => array( $this, 'rest_vectors_update' ),
			) );
      register_rest_route( $this->namespace, '/vectors/sync', array(
        'methods' => 'POST',
        'permission_callback' => array( $this->core, 'can_access_settings' ),
        'callback' => array( $this, 'rest_vectors_sync' ),
      ) );
			register_rest_route( $this->namespace, '/vectors/delete', array(
				'methods' => 'POST',
				'permission_callback' => array( $this->core, 'can_access_settings' ),
				'callback' => array( $this, 'rest_vectors_delete' ),
			) );
      register_rest_route( $this->namespace, '/vectors/remote_list', array(
				'methods' => 'POST',
				'permission_callback' => array( $this->core, 'can_access_settings' ),
				'callback' => array( $this, 'rest_vectors_remote_list' ),
			) );
      
    }
    catch ( Exception $e ) {
      var_dump( $e );
    }
  }

  function rest_vectors_list( $request ) {
		try {
			$params = $request->get_json_params();
			$page = isset( $params['page'] ) ? $params['page'] : null;
			$limit = isset( $params['limit'] ) ? $params['limit'] : null;
      $offset = (!!$page && !!$limit) ? ( $page - 1 ) * $limit : 0;
			$filters = isset( $params['filters'] ) ? $params['filters'] : null;
			$sort = isset( $params['sort'] ) ? $params['sort'] : null;
      $vectors = $this->query_vectors( $offset, $limit, $filters, $sort );
			return new WP_REST_Response([ 
        'success' => true,
        'total' => $vectors['total'],
        'vectors' => $vectors['rows']
      ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}

  function rest_vectors_remote_list( $request ) {
		try {
			$params = $request->get_json_params();
			$page = isset( $params['page'] ) ? $params['page'] : null;
			$limit = isset( $params['limit'] ) ? $params['limit'] : null;
      $offset = (!!$page && !!$limit) ? ( $page - 1 ) * $limit : 0;
			$filters = isset( $params['filters'] ) ? $params['filters'] : [];
      $envId = $filters['envId'];

      if ( empty( $envId ) ) {
        throw new Exception( "The envId is required." );
      }

      $vectors = apply_filters( 'mwai_embeddings_list_vectors', [], [
        'envId' => $envId,
        'limit' => $limit,
        'offset' => $offset,
      ] );

			return new WP_REST_Response([ 
        'success' => true,
        'total' => count( $vectors ),
        'vectors' => $vectors
      ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}

  function rest_vectors_add_from_remote( $request ) {
    try {
      $params = $request->get_json_params();
      $envId = $params['envId'];
      $dbId = $params['dbId'];
      $metadata = $this->get_vector_metadata_from_remote( $dbId, $envId );
      $title = isset( $metadata['title'] ) ? $metadata['title'] : "Missing Title #$dbId";
      $type = isset( $metadata['type'] ) ? $metadata['type'] : 'manual';
      $refId = isset( $metadata['refId'] ) ? $metadata['refId'] : null;
      $content = isset( $metadata['content'] ) ? $metadata['content'] : '';

      // Check if the postId exists.
      if ( $type === 'postId' ) {
        if ( !$refId ) {
          $type = 'manual';
        }
        else {
          $post = get_post( $refId );
          if ( !$post ) {
            $type = 'manual';
          }
        }
      }

      $status = !empty( $content ) ? 'ok' : 'orphan';

      $vector = [
        'type' => $type,
        'title' => $title,
        'envId' => $envId,
        'dbId' => $dbId,
        'content' => $content,
      ];
      $vector = $this->vectors_add( $vector, $status, true );
      return new WP_REST_Response([ 'success' => !!$vector, 'vector' => $vector ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

	function rest_vectors_add( $request ) {
		try {
			$params = $request->get_json_params();
			$vector = $params['vector'];
      $options = [ 'envId' => $vector['envId'] ];
      $vector = $this->vectors_add( $vector, $options );
			return new WP_REST_Response([ 'success' => !!$vector, 'vector' => $vector ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}

	function rest_vectors_by_ref( $request ) {
		try {
			$params = $request->get_json_params();
			$refId = $params['refId'];
      $vectors = $this->get_vectors_by_refId( $refId );
			return new WP_REST_Response([ 'success' => true, 'vectors' => $vectors ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}

	function rest_vectors_update( $request ) {
		try {
			$params = $request->get_json_params();
			$vector = $params['vector'];
      $vector = $this->update_vector( $vector );
      $success = !empty( $vector );
			return new WP_REST_Response([ 'success' => $success, 'vector' => $vector ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}

  function rest_vectors_sync( $request ) {
    try {
      $params = $request->get_json_params();
      $envId = !empty( $params['envId'] ) ? $params['envId'] : null;
      $vectorId = !empty( $params['vectorId'] ) ? $params['vectorId'] : null;
      $postId = !empty( $params['postId'] ) ? $params['postId'] : null;
      $vector = $this->sync_vector( $vectorId, $postId, $envId );
      $success = !empty( $vector );
      return new WP_REST_Response([ 'success' => $success, 'vector' => $vector ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

	function rest_vectors_delete( $request ) {
		try {
			$params = $request->get_json_params();
      $envId = $params['envId'];
			$localIds = $params['ids'];
      if ( empty( $envId ) || empty( $localIds ) ) {
        throw new Exception( "The envId and ids are required." );
      }
      $force = isset( $params['force'] ) ? $params['force'] : false;
      $success = $this->vectors_delete( $envId, $localIds, $force );
			return new WP_REST_Response([ 'success' => $success ], 200 );
		}
		catch ( Exception $e ) {
			return new WP_REST_Response([ 'success' => false, 'message' => $e->getMessage() ], 500 );
		}
	}
  #endregion

  #region Events (WP & AI Engine)

  function run_tasks() {
    if ( get_transient( 'mwai_embeddings_tasks_sync' ) ) { return; }
    set_transient( 'mwai_embeddings_tasks_sync', true, 60 * 10 );
    $outdated = $this->get_outdated_vectors();
    if ( !empty( $outdated ) ) {
      $this->sync_vector( $outdated[0] );
    }
    delete_transient( 'mwai_embeddings_tasks_sync' );
  }

  function prepare_content( $post ) {
    $content = $this->core->clean_sentences( $post['content'] );
    if ( $this->rewrite_content && !empty( $this->rewrite_prompt ) ) {
      global $mwai;
      $prompt = str_replace( '{CONTENT}', $content, $this->rewrite_prompt );
      $prompt = str_replace( '{TITLE}', $post['title'], $prompt );
      $prompt = str_replace( '{URL}', get_permalink( $post['postId'] ), $prompt );
      $prompt = str_replace( '{EXCERPT}', $post['excerpt'], $prompt );
      $prompt = str_replace( '{LANGUAGE}', $this->core->get_post_language( $post['postId'] ), $prompt );
      $prompt = str_replace( '{ID}', $post['postId'], $prompt );
      $content = $mwai->simpleTextQuery( $prompt, [ 'scope' => 'text-rewrite' ] );
    }
    return $content;
  }

  function sync_vector( $vector = null, $postId = null, $envId = null ) {
    if ( $postId ) {
      $previousVectors = $this->get_vectors_by_refId( $postId, $envId );
      if ( count( $previousVectors ) > 1) {
        $this->core->log( "⚠️ (Embeddings) There are more than one vector with the same refId ({$postId}). It is not handled yet." );
        return;
      }
      else if ( count( $previousVectors ) === 1) {
        $vector = $previousVectors[0];
      }
      else {
        // It's a new vector.
        $post = $this->core->get_post( $postId );
        if ( !$post ) {
          return;
        }
        // Prepare and return the addition of a new vector based on the provided postId.
        $content = $this->prepare_content( $post );
        return $this->vectors_add( [
          'type' => 'postId',
          'title' => $post['title'],
          'refId' => $post['postId'],
          'refChecksum' => $post['checksum'],
          'envId' => !empty( $envId ) ? $envId : $this->sync_post_envId,
          'content' => $content,
          'behavior' => 'context'
        ], 'ok' );
      }
    }

    // Proceed with the original function logic if $postId is not provided.
    if ( is_numeric( $vector ) ) {
      $vector = $this->get_vector( $vector );
    }

    // If the vector does not have a refId, it is not linked to a post, and only need to be updated.
    if ( empty( $vector['refId'] ) ) {
      return $this->update_vector( $vector, $envId );
    }

    $matchedVectors = $this->get_vectors_by_refId( $vector['refId'], $vector['envId'] );
    if ( count( $matchedVectors ) > 1 ) {
      // Handle multiple vectors related to the same post.
      $this->core->log( "⚠️ (Embeddings) There are more than one vector with the same refId ({$vector['refId']}). It is not handled yet." );
      return;
    }
    $matchedVector = $matchedVectors[0];
    $post = $this->core->get_post( $matchedVector['refId'] );
    if ( !$post ) {
      return;
    }

    // If the vector is already up-to-date, we don't do anything.
    if ( !$this->force_recreate && $post['checksum'] === $matchedVector['refChecksum']
      && $matchedVector['status'] === 'ok') {
      return $matchedVector;
    }

    // If the vector is outdated, we delete it.
    $this->vectors_delete( $matchedVector['envId'], [ $matchedVector['id'] ] );

    // Prepare and return the addition of a new vector based on the provided postId.
    $content = $this->prepare_content( $post );
    return $this->vectors_add( [
      'type' => 'postId',
      'title' => $post['title'],
      'refId' => $post['postId'],
      'refChecksum' => $post['checksum'],
      'envId' => $envId ? $envId : $matchedVector['envId'],
      'content' => $content,
      'behavior' => 'context'
    ], 'ok' );
  }

  function action_save_post( $postId, $post, $update ) {
    if ( !in_array( $post->post_type, $this->sync_post_types ) ) {
      return;
    }
    if ( !in_array( $post->post_status, $this->sync_post_status ) ) {
      return;
    }
    if ( !empty( $this->sync_post_categories ) ) {
      $categories = get_the_category( $postId );
      $categorySlugs = [];
      foreach ( $categories as $category ) {
        $categorySlugs[] = $category->slug;
      }
      $intersect = array_intersect( $categorySlugs, $this->sync_post_categories );
      if ( empty( $intersect ) ) {
        return;
      }
    }
    if ( !$this->check_db() ) { return false; }
    $vectors = $this->get_vectors_by_refId( $postId );
    if ( empty( $vectors ) ) {
      if ( $this->sync_posts ) {
        $cleanPost = $this->core->get_post( $post );
        $vector = [
          'type' => 'postId',
          'title' => $cleanPost['title'],
          'refId' => $postId,
          'envId' => $this->sync_post_envId,
        ];
        $this->vectors_add( $vector, 'pending' );
      }
      return;
    }

    $cleanPost = $this->core->get_post( $post );
    foreach ( $vectors as $vector ) {
      if ( $cleanPost['checksum'] === $vector['refChecksum'] ) { continue; }
      $this->wpdb->update( $this->table_vectors, [ 'status' => 'outdated' ],
        [ 'id' => $vector['id'] ]
      );
    }
  }

  function action_delete_post( $postId ) {
    if ( !$this->check_db() ) { return false; }
    $vectorIds = $this->wpdb->get_col( $this->wpdb->prepare(
      "SELECT id FROM $this->table_vectors WHERE refId = %d AND type = 'postId'", $postId
    ) );
    if ( !$vectorIds ) { return; }
    $this->vectors_delete( $this->sync_post_envId, $vectorIds );
  }

  function pull_vector_from_remote( $embedId, $envId ) {
    $remoteVector = $this->get_vector_metadata_from_remote( $embedId, $envId );
    if ( empty( $remoteVector ) ) {
      $this->core->log( "⚠️ (Embeddings) A vector was returned by the Vector DB, but it is not available in the local DB and we could not retrieve it more information about it from the Vector DB (ID {$embedId}).");
    }
    $type = isset( $remoteVector['type'] ) ? $remoteVector['type'] : 'manual';
    $title = isset( $remoteVector['title'] ) ? $remoteVector['title'] : 'N/A';
    $content = isset( $remoteVector['content'] ) ? $remoteVector['content'] : '';
    $isOk = !empty( $content );
    // If there is no content, it is marked as 'orphan'
    // (and only written locally since it's already in the Vector DB).
    $vector = $this->vectors_add( [
      'type' => $type,
      'title' => $title,
      'content' => $content,
      'dbId' => $embedId,
      'envId' => $envId,
    ], $isOk ? 'ok' : 'orphan', true );
    return $vector;
  }

  function context_search( $context, $query, $options = [] ) {
    $embeddingsEnvId = !empty( $options['embeddingsEnvId'] ) ? $options['embeddingsEnvId'] : null;

    // Context already provided? We don't do anything.
    if ( !$embeddingsEnvId || !empty( $context ) ) {
      return $context;
    }

    $queryEmbed = new Meow_MWAI_Query_Embed( $query );
    $env = $this->core->get_embeddings_env( $embeddingsEnvId );
    $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
    if ( $override ) {
      $queryEmbed->set_env_id( $env['ai_embeddings_env'] );
      $queryEmbed->set_model( $env['ai_embeddings_model'] );
      if ( !empty( $env['ai_embeddings_dimensions'] ) ) {
        $queryEmbed->set_dimensions( $env['ai_embeddings_dimensions'] );
      }
    }

    $reply = $this->core->run_query( $queryEmbed );
    if ( empty( $reply->result ) ) {
      return null;
    }
    $embeds = $this->query_db( $reply->result, $embeddingsEnvId );
    if ( empty( $embeds ) ) {
      return null;
    }
    $minScore = empty( $env['min_score'] ) ? 35 : (float)$env['min_score'];
    $maxSelect = empty( $env['max_select'] ) ? 10 : (int)$env['max_select'];
    $embeds = array_slice( $embeds, 0, $maxSelect );

    // Prepare the context
    $context = [];
    $context["content"] = "";
    $context["type"] = "embeddings";
    $context["embeddingIds"] = []; 
    foreach ( $embeds as $embed ) {
      if ( ( $embed['score'] * 100 ) < $minScore ) {
        continue;
      }
      $embedId = $embed['id'];
      $data = $this->get_vector_by_remoteId( $embedId );

      // If the vector is not available locally, we try to get it from the Vector DB.
      if ( empty( $data ) ) {
        $data = $this->pull_vector_from_remote( $embedId, $embeddingsEnvId );
        if ( empty( $data['content'] ) ) {
          continue;
        }
      }

      $context["content"] .= $data['content'] . "\n";
      $context["embeddings"][] = [ 
        'id' => $embedId,
        'type' => $data['type'],
        'title' => $data['title'],
        'ref' => $data['refId'],
        'score' => (float)$embed['score'],
      ];
    }
    
    return empty( $context["content"] ) ? null : $context;
  }
  #endregion

  #region DB Queries

  function query_db( $searchVectors, $envId = null ) {
    $envId = $envId ? $envId : $this->default_envId;
    $options = [ 'envId' => $envId ];
    $vectors = apply_filters( 'mwai_embeddings_query_vectors', [], $searchVectors, $options );
    return $vectors;
  }

  function get_outdated_vectors( $limit = 100 ) {
    if ( !$this->check_db() ) { return false; }
    $query = "SELECT * FROM {$this->table_vectors} WHERE status = 'outdated' OR status = 'pending' LIMIT $limit";
    $vectors = $this->wpdb->get_results( $query, ARRAY_A );
    return $vectors;
  }

  function vectors_delete( $envId, $localIds, $force = false ) {
    if ( !$this->check_db() ) { return false; }

    $toDelete = [];
    foreach ( $localIds as $id ) {
      $vector = $this->get_vector( $id );
      $toDelete[] = [ 'localId' => $id, 'dbId' => $vector['dbId'] ];
    }

    $dbIds = array_map( function ( $mapping ) { return $mapping['dbId']; }, $toDelete );
    $dbIds = array_filter( $dbIds, function ( $dbId ) { return !is_null( $dbId ); } );

    if ( !empty( $dbIds ) ) {
      try {
        $options = [ 'envId' => $envId, 'ids' => $dbIds, 'deleteAll' => false ];
        apply_filters( 'mwai_embeddings_delete_vectors', [], $options );
      }
      catch ( Exception $e ) {
        if ( $force ) {
          $this->core->log( "❌ (Embeddings) " . $e->getMessage() );
        }
        else {
          throw $e;
        }
      }
    }

    // If everything went well, we can delete the local vectors.
    foreach ( $toDelete as $toDeleteItem ) {
      $this->wpdb->delete( $this->table_vectors, [ 'id' => $toDeleteItem['localId'] ], ['%d'] );
    }

    return true;
  }

  // function vectors_delete_all( $success, $index, $syncPineCone = true ) {
  //   if ( $success ) { return $success; }
  //   if ( !$this->check_db() ) { return false; }
  //   if ( $syncPineCone ) { $this->pinecode_delete( null, true ); }
  //   $this->wpdb->delete( $this->table_vectors, [ 'dbIndex' => $index ], array( '%s' ) );
  //   return true;
  // }

  function vectors_add( $vector = [], $status = 'processing', $localOnly = false ) {
    if ( !$this->check_db() ) { return false; }

    // If it doesn't have content, it's basically an empty vector
    // that needs to be processed later, through the UI.
    $hasContent = isset( $vector['content'] );

    if ( $hasContent && strlen( $vector['content'] ) > 65535 ) {
      throw new Exception( 'The content of the embedding is too long (max 65535 characters).' );
    }

    $envId = isset( $vector['envId'] ) ? $vector['envId'] : $this->default_envId;

    $success = $this->wpdb->insert( $this->table_vectors, 
      [
        'id' => null,
        'type' => $vector['type'],
        'title' => $vector['title'],
        'content' => $hasContent ? $vector['content'] : '',
        'refId' => !empty( $vector['refId'] ) ? $vector['refId'] : null,
        'refChecksum' => !empty( $vector['refChecksum'] ) ? $vector['refChecksum'] : null,
        'envId' => $envId,
        'dbId' => isset( $vector['dbId'] ) ? $vector['dbId'] : null,
        'status' => $status,
        'updated' => date( 'Y-m-d H:i:s' ),
        'created' => date( 'Y-m-d H:i:s' )
      ],
      array( '%s', '%s', '%s', '%s', '%s', '%s' )
    );

    if ( !$success ) {
      $error = $this->wpdb->last_error;
      throw new Exception( $error );
    }

    if ( !$localOnly ) { 
      if ( !$hasContent ) { return true; }
      $vector['id'] = $this->wpdb->insert_id;
      $queryEmbed = new Meow_MWAI_Query_Embed( $vector['content'] );
      $queryEmbed->set_scope('admin-tools');

      $env = $this->core->get_embeddings_env( $envId );
      $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
      if ( $override ) {
        $queryEmbed->set_env_id( $env['ai_embeddings_env'] );
        $queryEmbed->set_model( $env['ai_embeddings_model'] );
        if ( !empty( $env['ai_embeddings_dimensions'] ) ) {
          $queryEmbed->set_dimensions( $env['ai_embeddings_dimensions'] );
        }
      }

      try {
        $reply = $this->core->run_query( $queryEmbed );
        $vector['embedding'] = $reply->result;
        $vector['model'] = $queryEmbed->model;
        $vector['dimensions'] = count( $reply->result );
        $dbId = apply_filters( 'mwai_embeddings_add_vector', false, $vector, [
          'envId' => $envId,
        ] );
        if ( $dbId ) {
          $vector['dbId'] = $dbId;
          $this->wpdb->update( $this->table_vectors, [ 
            'dbId' => $dbId,
            'model' => $vector['model'],
            'dimensions' => $vector['dimensions'],
            'status' => "ok"
          ], [ 'id' => $vector['id'] ], array( '%s', '%s', '%s' ), [ '%d' ] );
        }
        else {
          throw new Exception( "AI Engine: Could not add the vector to the Vector DB (no \$dbId). Maybe Auto-Sync Posts is enabled, but the environment is not set up properly." );
        }
      }
      catch ( Exception $e ) {
        $error = $e->getMessage();
        $this->core->log( "❌ (Embeddings) " . $error );
        $this->wpdb->update( $this->table_vectors, [ 'dbId' => null, 'status' => "error", 'error' => $error ],
          [ 'id' => $vector['id'] ], array( '%s', '%s', '%s' ), [ '%d' ]
        );
        return $this->get_vector( $vector['id'] );
      }
    }

    if ( !empty( $vector['dbId'] ) ) {
      return $this->get_vector_by_remoteId( $vector['dbId'] );
    }

    return null;
  }

  function get_vectors_by_refId( $refId, $envId = null ) {
    if ( !$this->check_db() ) { return false; }
    $query = "SELECT * FROM {$this->table_vectors}";
    $where = array();
    $where[] = "refId = '" . esc_sql( $refId ) . "'";
    if ( !empty( $envId ) ) {
      $where[] = "envId = '" . esc_sql( $envId ) . "'";
    }
    $query .= " WHERE " . implode( " AND ", $where );
    $vectors = $this->wpdb->get_results( $query, ARRAY_A );
    return $vectors;
  }

  // This function is a bit tricky, because it can do many things.
  // $fallbackEnvId can be used when the current envId is null or not available anymore.
  function update_vector( $vector = [], $fallbackEnvId = null ) {
    if ( !$this->check_db() ) { return false; }
    if ( empty( $vector['id'] ) ) { throw new Exception( "Missing ID" ); }
    $originalVector = $this->get_vector( $vector['id'] );
    if ( !$originalVector ) { throw new Exception( "Vector not found" ); }
    $newContent = $originalVector['content'] !== $vector['content'];
    $wasError = $originalVector['status'] === 'error';
    $envId = isset( $vector['envId'] ) ? $vector['envId'] : $originalVector['envId'];
    $env = $this->core->get_embeddings_env( $envId );
    if ( !$env ) {
      if ( $fallbackEnvId ) {
        $env = $this->core->get_embeddings_env( $fallbackEnvId );
        if ( !$env ) {
          throw new Exception( "The fallback environment (envId: $fallbackEnvId) is not available." );
        }
        $envId = $fallbackEnvId;
      }
      else {
        throw new Exception( "The environment (envId: $envId) is not available." );
      }
    }
    $newEnv = $envId !== $originalVector['envId'];
    $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
    $ai_model = $override ? $env['ai_embeddings_model'] : null;
    $newModel = $ai_model !== $originalVector['model'];
    $ai_dimensions = $override ? $env['ai_embeddings_dimensions'] : null;
    $newDimensions = !empty( $ai_dimensions ) && $ai_dimensions !== $originalVector['dimensions'];
    
    if ( $newContent || $wasError || $newModel || $newEnv || $newDimensions ) {

      // Update the vector (to mark it as processing)
      $this->wpdb->update( $this->table_vectors, [
          'type' => $vector['type'],
          'title' => $vector['title'],
          'content' => $vector['content'],
          'refId' => !empty( $vector['refId'] ) ? $vector['refId'] : null,
          'envId' => $envId,
          'refChecksum' => !empty( $vector['refChecksum'] ) ? $vector['refChecksum'] : null,
          'status' => ( $newContent || $wasError ) ? "processing" : "ok",
          'updated' => date( 'Y-m-d H:i:s' )
        ],
        [ 'id' => $vector['id'] ],
        [ '%s', '%s', '%s', '%s', '%s' ],
        [ '%d' ]
      );

      try {

        // Delete the original vector
        if ( !empty( $originalVector['dbId'] ) ) {
          $options = [ 
            'envId' => $originalVector['envId'],
            'ids' => $originalVector['dbId'],
            'deleteAll' => false
          ];
          apply_filters( 'mwai_embeddings_delete_vectors', [], $options );
        }
        
        // Create the embedding
        $queryEmbed = new Meow_MWAI_Query_Embed( $vector['content'] );
        $queryEmbed->set_scope('admin-tools');
        $ai_env = $override ? $env['ai_embeddings_env'] : null;
        if ( !empty( $ai_env ) && !empty( $ai_model ) ) {
          $queryEmbed->set_env_id( $ai_env );
          $queryEmbed->set_model( $ai_model );
          if ( !empty( $ai_dimensions ) ) {
            $queryEmbed->set_dimensions( $ai_dimensions );
          }
        }

        $reply = $this->core->run_query( $queryEmbed );
        $vector['embedding'] = $reply->result;
        $vector['model'] = $queryEmbed->model;
        $vector['dimensions'] = count( $reply->result );
        // Re-add the vector
        $dbId = apply_filters( 'mwai_embeddings_add_vector', false, $vector, [ 'envId' => $envId ] );
        if ( $dbId ) {
          $this->wpdb->update( $this->table_vectors,
            [ 
              'dbId' => $dbId,
              'status' => "ok",
              'model' => $vector['model'],
              'dimensions' => $vector['dimensions'],
              'updated' => date( 'Y-m-d H:i:s' )
            ],
            [ 'id' => $vector['id'] ], [ '%s', '%s', '%s', '%s' ], [ '%d' ]
          );
        }
        else {
          throw new Exception( "Could not update the vector to the Vector DB (no \$dbId)." );
        }
      }
      catch ( Exception $e ) {
        $error = $e->getMessage();
        $this->core->log( "❌ (Embeddings) " . $error );
        $this->wpdb->update( $this->table_vectors,
          [ 'dbId' => null, 'status' => "error", 'error' => $error, 'updated' => date( 'Y-m-d H:i:s' ) ],
          [ 'id' => $vector['id'] ], [ '%s', '%s', '%s' ], [ '%d' ]
        );
      }
    }
    else if ( $originalVector['type'] !== $vector['type'] || $originalVector['title'] !== $vector['title'] ) {
      // TODO: For the title, we should also update the Vector DB.
      $this->wpdb->update( $this->table_vectors,
        [ 'type' => $vector['type'], 'title' => $vector['title'], 'updated' => date( 'Y-m-d H:i:s' ) ],
        [ 'id' => $vector['id'] ], [ '%s', '%s' ], [ '%d' ]
      );
    }

    return $this->get_vector( $vector['id'] );
  }

  function get_vector( $id ) {
    if ( !$this->check_db() ) {
      return null;
    }
    $vector = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $this->table_vectors WHERE id = %d", $id ), ARRAY_A );
    return $vector;
  }

  function get_vector_by_remoteId( $remoteId ) {
    if ( !$this->check_db() ) {
      return null;
    }
    $vector = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $this->table_vectors WHERE dbId = %s", $remoteId ), ARRAY_A );
    return $vector;
  }

  function get_vector_metadata_from_remote( $vectorId, $envId ) {
    $options = [ 'envId' => $envId ];
    $vector = apply_filters( 'mwai_embeddings_get_vector', null, $vectorId, $options );
    return $vector;
  }

  function query_vectors( $offset = 0, $limit = null, $filters = null, $sort = null ) {
    if ( !$this->check_db() ) { return [ 'total' => 0, 'rows' => [] ]; }
    $filters = !empty( $filters ) ? $filters : [];
    $envId = $filters['envId'];
    $debugMode = isset( $filters['debugMode'] ) ? $filters['debugMode'] : false;
    if ( empty( $envId ) ) {
      throw new Exception( "The envId is required." );
    }
    $includeAll = $debugMode === 'includeAll';
    $includeOrphans = $debugMode === 'includeOrphans';
    
    if ( $includeAll ) {
      unset( $filters['envId'] );
    }

    // Is AI Search
    $isAiSearch = !empty( $filters['search'] );
    $matchedVectors = [];
    if ( $isAiSearch ) {
      $query = $filters['search'];

      $queryEmbed = new Meow_MWAI_Query_Embed( $query );
      $queryEmbed->set_scope('admin-tools');

      $env = $this->core->get_embeddings_env( $envId );
      $override = isset( $env['ai_embeddings_override'] ) && $env['ai_embeddings_override'] === true;
      if ( $override ) {
        $queryEmbed->set_env_id( $env['ai_embeddings_env'] );
        $queryEmbed->set_model( $env['ai_embeddings_model'] );
        if ( !empty( $env['ai_embeddings_dimensions'] ) ) {
          $queryEmbed->set_dimensions( $env['ai_embeddings_dimensions'] );
        }
      }

			$reply = $this->core->run_query( $queryEmbed );
      $matchedVectors = $this->query_db( $reply->result, $envId );
      if ( empty( $matchedVectors ) ) {
        return [ 'total' => 0, 'rows' => [] ];
      }
      $minScore = empty( $env['min_score'] ) ? 35 : (float)$env['min_score'];
      $matchedVectors = array_filter( $matchedVectors, function( $vector ) use ( $minScore ) {
        return ( $vector['score'] * 100 ) >= $minScore;
      } );
    }

    $offset = !empty( $offset ) ? intval( $offset ) : 0;
    $limit = !empty( $limit ) ? intval( $limit ) : 100;
    $sort = !empty( $sort ) ? $sort : [ "accessor" => "created", "by" => "desc" ];
    $query = "SELECT * FROM $this->table_vectors";

    // Filters
    $where = array();
    if ( isset( $filters['type'] ) ) {
      $where[] = "type = '" . esc_sql( $filters['type'] ) . "'";
    }

    if ( $includeOrphans ) {
      $envs = $this->core->get_option( 'embeddings_envs' );
      $envIds = array_map( function( $env ) { return $env['id']; }, $envs );
      $envIds = array_diff( $envIds, [ $envId ] );
      $where[] = "envId NOT IN ('" . implode( "','", $envIds ) . "')";
    }
    else if ( isset( $filters['envId'] ) ) {
      $where[] = "envId = '" . esc_sql( $filters['envId'] ) . "'";
    }

    // $dbIds is an array of strings
    $dbIds = [];
    $rawDbIds = [];
    if ( $isAiSearch ) {
      if ( empty( $matchedVectors ) ) {
        return [ 'total' => 0, 'rows' => [] ];
      }
      foreach ( $matchedVectors as $vector ) {
        $dbIds[] = "'" . $vector['id'] . "'";
        $rawDbIds[] = $vector['id'];
      }
      if ( !empty( $dbIds ) ) {
          $where[] = "dbId IN (" . implode( ",", $dbIds ) . ")";
      }
    }
    if ( count( $where ) > 0 ) {
      $query .= " WHERE " . implode( " AND ", $where );
    }

    // Count based on this query
    $vectors['total'] = (int)$this->wpdb->get_var( "SELECT COUNT(*) FROM ($query) AS t" );

    // Order by
    if ( !$isAiSearch ) {
      $query .= " ORDER BY " . esc_sql( $sort['accessor'] ) . " " . esc_sql( $sort['by'] );
    }

    // Limits
    if ( !$isAiSearch && $limit > 0 ) {
      $query .= " LIMIT $offset, $limit";
    }

    $vectors['rows'] = $this->wpdb->get_results( $query, ARRAY_A );

    // Consolidate results
    foreach ( $vectors['rows'] as $key => &$vectorRow ) {
      if ( $vectorRow['type'] === 'postId' ) {
        // Get the Post Type
        $vectorRow['subType'] = get_post_type( $vectorRow['refId'] );
      }
    }

    // If it's an AI Search, we need to update the score of the vectors
    if ( $isAiSearch ) {

      // If the count of the result vectors is less than the $ids, then we need to add the missing ones
      if ( $vectors['total'] < count( $rawDbIds ) ) {
        $missingIds = array_diff( $rawDbIds, array_column( $vectors['rows'], 'dbId' ) );
        foreach ( $missingIds as $missingId ) {
          $newRow = $this->pull_vector_from_remote( $missingId, $envId );
          if ( !empty( $newRow ) ) {
            $vectors['rows'][] = $newRow;
          }
        }
      }

      foreach ( $vectors['rows'] as &$vectorRow ) {
        $dbId = $vectorRow['dbId'];
        $queryVector = null;
        foreach ( $matchedVectors as $vector ) {
          if ( (string)$vector['id'] === (string)$dbId ) {
            $queryVector = $vector;
            break;
          }
        }
        if ( !empty( $queryVector ) ) {
          $vectorRow['score'] = $queryVector['score'];
        }
      }
      unset( $vectorRow );
    }

    return $vectors;
  }

  #endregion

  #region DB Setup

  function create_db() {
    $charset_collate = $this->wpdb->get_charset_collate();
    $sqlVectors = "CREATE TABLE $this->table_vectors (
      id BIGINT(20) NOT NULL AUTO_INCREMENT,
      type VARCHAR(32) NULL,
      title VARCHAR(255) NULL,
      content TEXT NULL,
      behavior VARCHAR(32) DEFAULT 'context' NOT NULL,
      status VARCHAR(32) NULL,
      envId VARCHAR(64) NULL,
      model VARCHAR(64) NULL,
      dimensions SMALLINT NULL,
      dbId VARCHAR(64) NULL,
      refId BIGINT(20) NULL,
      refChecksum VARCHAR(64) NULL,
      error TEXT NULL,
      created DATETIME NOT NULL,
      updated DATETIME NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sqlVectors );
  }

  function check_db()
  {
    if ($this->db_check) {
      return true;
    }
    $tableExists = !(strtolower($this->wpdb->get_var("SHOW TABLES LIKE '$this->table_vectors'")) != strtolower($this->table_vectors));
    if (!$tableExists) {
      $this->create_db();
      $tableExists = !(strtolower($this->wpdb->get_var("SHOW TABLES LIKE '$this->table_vectors'")) != strtolower($this->table_vectors));
    }
    $this->db_check = $tableExists;

    // TODO REMOVE THIS AFTER APRIL 2024
    // Add a new column "model" to the table.
    // Since it's new, after it's created, we need to update all the rows to set the model to "text-embedding-ada-002"
    if ($tableExists && !$this->wpdb->get_var("SHOW COLUMNS FROM $this->table_vectors LIKE 'model'")) {
      $this->wpdb->query("ALTER TABLE $this->table_vectors ADD COLUMN model varchar(64) NULL");
      $this->wpdb->update( $this->table_vectors, [
          'model' => 'text-embedding-ada-002',
        ],
        [ 'model' => null ],
        [ '%s' ],
        [ '%s' ]
      );
      $this->db_check = true;
    }

    // TODO: REMOVE THIS AFTER JUNE 2024
    // Add a new column "dimensions" to the table.
    // Since it's new, after it's created, we need to update all the rows to set the dimensions.
    if ($tableExists && !$this->wpdb->get_var("SHOW COLUMNS FROM $this->table_vectors LIKE 'dimensions'")) {
      $this->wpdb->query("ALTER TABLE $this->table_vectors ADD COLUMN dimensions SMALLINT NULL");
      // If the model is 'text-embedding-ada-002', then the dimensions is 1536 
      $this->wpdb->update( $this->table_vectors,
        [ 'dimensions' => 1536, ], [ 'model' => 'text-embedding-ada-002' ],
        [ '%d' ], [ '%s' ]
      );
      // If the model is 'text-embedding-3-large', then the dimensions is 3072
      $this->wpdb->update( $this->table_vectors,
        [ 'dimensions' => 3072, ], [ 'model' => 'text-embedding-3-large' ],
        [ '%d' ], [ '%s' ]
      );
      // If the model is 'text-embedding-3-small', then the dimensions is 1536
      $this->wpdb->update( $this->table_vectors,
        [ 'dimensions' => 1536, ], [ 'model' => 'text-embedding-3-small' ],
        [ '%d' ], [ '%s' ]
      );
      $this->db_check = true;
    }

    return $this->db_check;
  }

  #endregion
}
