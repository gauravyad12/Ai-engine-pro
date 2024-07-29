<?php

class MeowPro_MWAI_Statistics {
  private $core = null;
  private $wpdb = null;
  private $db_check = false;
  private $table_logs = null;
  private $table_logmeta = null;
  private $apiRef = null;

  public function __construct() {
    global $wpdb, $mwai_core;
    $this->core = $mwai_core;
    $this->wpdb = $wpdb;
    $this->apiRef = $this->core->get_option( 'openai_apikey' );
    $this->table_logs = $wpdb->prefix . 'mwai_logs';
    $this->table_logmeta = $wpdb->prefix . 'mwai_logmeta';
    add_filter( 'mwai_stats_query', [ $this, 'query' ], 10, 1 );
    add_filter( 'mwai_stats_logs', [ $this, 'logs_query' ], 10, 5 );
    add_filter( 'mwai_stats_logs_delete', [ $this, 'logs_delete' ], 10, 2 );
    add_filter( 'mwai_stats_logs_meta', [ $this, 'logs_meta_query' ], 10, 5 );
    add_shortcode( 'mwai_stats_current', [ $this, 'shortcode_current' ] );
    add_shortcode( 'mwai_stats', [ $this, 'shortcode_current' ] );

    // The log all should probably be an option
    add_filter( 'mwai_ai_reply', function ( $reply, $query ) {
      global $mwai_stats;
      $mwai_stats->addCasually( $query, $reply, [
        //'tags' => ['chatbot', 'index'],
      ]);
      return $reply;
    }, 10, 2 );

    // We need a cookie to track the session
    if ( !isset( $_COOKIE['mwai_session_id'] ) ) {
      @setcookie( 'mwai_session_id', uniqid(), [
        'expires' => 0,
        'path' => '/',
        // TODO: 'secure' and 'httponly' were added on May 17th, 2024, let's see if there are any issues.
        'secure' => true,
        'httponly' => true
      ] );
    }

    $limits = $this->core->get_option( 'limits' );
    if ( isset( $limits['enabled'] ) && $limits['enabled'] ) {
      add_filter( 'mwai_ai_allowed', array( $this, 'check_limits' ), 10, 3 );
    }
  }

  function check_limits( $allowed, $query, $limits ) {
    global $mwai_stats;
    if ( empty( $mwai_stats ) ){
      return $allowed;
    }
  
    // If there is no limit, or if it is admin or editor and they are ignored, then return $allowed.
    $hasLimits = $limits && $limits['enabled'];
    if ( !$hasLimits ) {
      return $allowed;
    }

    // System-wise check.
    // System is new from 1.3.99, so we need to check if it's set.
    if ( isset( $limits['system'] ) && $limits['system']['credits'] > 0 ) {
      $credits = $limits['system']['credits'];
      if ( $credits > 0 ) {
        $stats = $this->query( null, null, null, null, null, true );
        if ( !empty( $stats ) && $stats['overLimit'] ) {
          return $limits['system']['overLimitMessage'];
        }
      }
    }

    // Get Target
    $userId = $this->core->get_user_id();
    if ( $userId >= 0 ) {
      wp_set_current_user( $userId );
    }
    $target = $userId ? 'users' : 'guests';

    // Check Ignored Users
    if ( $target === 'users' ) {
      $ignoredUsers = $limits['users']['ignoredUsers'];
      $isAdministrator = current_user_can( 'administrator', $userId );
      if ( $isAdministrator && strpos( $ignoredUsers, 'administrator' ) !== false ) {
        return $allowed;
      }
      $isEditor = current_user_can( 'editor' );
      if ( $isEditor && strpos( $ignoredUsers, 'editor' ) !== false ) {
        return $allowed;
      }
    }

    // Users-wise check.
    $credits = apply_filters( 'mwai_stats_credits', $limits[$target]['credits'], $userId );
    if ( $credits === 0 ) {
      return $limits[$target]['overLimitMessage'];
    }
    $stats = $this->query();
    if ( !empty( $stats ) && $stats['overLimit'] ) {
      return $limits[$target]['overLimitMessage'];
    }

    return $allowed;
  }

  function addCasually( Meow_MWAI_Query_Base $query, Meow_MWAI_Reply $reply, $overrides ) {
    $type = null;
    $units = 0;
    if ( is_a( $query, 'Meow_MWAI_Query_Text' ) || is_a( $query, 'Meow_MWAI_Query_Embed' ) ||
      is_a( $query, 'Meow_MWAI_Query_Assistant' ) || is_a( $query, 'Meow_MWAI_Query_Feedback' ) ) {
      $type = 'tokens';
      $units = $reply->get_units();
    }
    else if ( is_a( $query, 'Meow_MWAI_Query_Image' ) ) {
      $type = 'images';
      $units = $reply->get_units();
    }
    else if ( is_a( $query, 'Meow_MWAI_QueryTranscribe' ) ) {
      $type = 'seconds';
      $units = $reply->get_units();
    }
    else {
      return;
    }
    $stats = [ 
      'scope' => $query->scope,
      'session' => $query->session,
      'mode' => $query->mode,
      'model' => $query->model,
      'apiRef' => $query->apiKey,
      'envId' => $query->envId,
      // TODO: Do we really even need apiOwn?
      'apiOwn' => 'admin', // 'admin' means the request is being ran with the API Keys of the WordPress install.
      'units' => $units,
      'type' => $type,
    ];
    $stats = array_merge( $stats, $overrides );
    if ( empty( $stats['price'] ) ) {
      $engine = Meow_MWAI_Engines_Factory::get( $this->core, $query->envId );
      $stats['price'] = $engine->get_price( $query, $reply );
    }

    $logId = $this->add( $stats );
    $jsonQuery = $query->toJson();
    $jsonAnswer = $reply->toJson();

    if ( $this->core->get_option( 'statistics_data' ) ) {
      $this->addMetadata( $logId, 'query', $jsonQuery );
      $this->addMetadata( $logId, 'reply', $jsonAnswer );
    }

    if ( $this->core->get_option( 'statistics_forms_data' ) ) {
      if ( $query->scope === 'form' && $query instanceof Meow_MWAI_Query_Text ) {
        $fields = $query->getExtraParam( 'fields' );
        if ( !empty( $fields ) ) {
          $this->addMetadata( $logId, 'fields', json_encode( $fields ) );
        }
      }
    }

    return true;
  }

  function buildTagsForDb( $tags ) {
    if ( is_array( $tags ) ) {
      $tags = implode( '|', $tags );
    }
    if ( !empty( $tags ) ) {
      $tags .= '|';
    }
    else {
      $tags = null;
    }
    return $tags;
  }

  // Query Usage
  function query( $timeFrame = null, $isAbsolute = null, $userId = null, $ipAddress = null,
    $apiRef = null, $system = false ) {

    // Validate apiRef, target, userId and ipAddress
    if ( $apiRef === null ) {
      $apiRef = $this->apiRef;
    }

    if ( $system ) {
      $userId = null;
      $ipAddress = null;
      $target = 'system';
    }
    else {
      $target = 'guests';
      if ( $userId === null && $ipAddress === null ) {
        $userId = $this->core->get_user_id();
        if ( $userId ) {
          $target = 'users';
        }
        else {
          $ipAddress =  $this->core->get_ip_address();
          if ( $ipAddress === null ) {
            $this->core->log("⚠️ (Stats) There should be an userId or an ipAddress." );
            return null;
          }
        }
      }
    }

    $limitsOption = $this->core->get_option('limits');
    $hasLimits = $limitsOption && isset( $limitsOption['enabled'] ) && $limitsOption['enabled'];
    $limits = $limitsOption[$target];
    if ( $timeFrame === null ) {
      $timeFrame = $limits['timeFrame'];
    }
    if ( $isAbsolute === null ) {
      $isAbsolute = $limits['isAbsolute'];
    }
    // Create the SQL query
    $this->check_db();
    $prefix = esc_sql( $this->wpdb->prefix );
    $sql = "SELECT COUNT(*) AS queries, SUM(units) AS units, SUM(price) AS price FROM {$prefix}mwai_logs WHERE ";
    
    // Condition: UserId ot IpAddress
    if ( $target === 'users' ) {
      $sql .= "userId = " . esc_sql( $userId ) . "";
    }
    else if ( $target === 'guests' ) {
      $sql .= "ip = '" . esc_sql( $ipAddress ) . "'";
    }
    else if ( $target === 'system' ) {
      $sql .= "1 = 1";
    }

    // Condition ApiRef
    // TODO: Removed this as it will impact badly people who aren't using OpenAI keys.
    // if ( $apiRef ) {
    //   $sql .= " AND apiRef = '" . esc_sql( $apiRef ) . "'";
    // }
    
    // Condition: Time Frame (Relative or Absolute)
    $timeUnits = ['second', 'minute', 'hour', 'day', 'week', 'month', 'year'];
    if ( in_array( $timeFrame, $timeUnits ) ) {
      $now = date( 'Y-m-d H:i:s' );
      if ( $isAbsolute ) {
        $sql .= " AND " . strtoupper( $timeFrame ) . "(time) = " . strtoupper( $timeFrame ) . "(\"$now\")";
      }
      else {
        $timeAgo = date( 'Y-m-d H:i:s', strtotime( "-1 $timeFrame" ) );
        $sql .= " AND time >= \"$timeAgo\"";
      }
    }
    else {
      $this->core->log("⚠️ (Stats) TimeFrame should be hour, day, week, month, or year." );
      return null;
    }

    // Process the results
    $results = $this->wpdb->get_results( $sql );
    if ( count( $results ) === 0 ) {
      $this->core->log("⚠️ (Stats) No results found for the statistics query." );
      return null;
    }
    $result = $results[0];
    $stats = [];
    $stats['userId'] = $userId;
    $stats['ipAddress'] = $ipAddress;
    $stats['queries'] = intVal( $result->queries );
    $stats['units'] = intVal( $result->units );
    $stats['price'] = round( floatVal( $result->price ), 4 );
    
    // Give a chance to the dev to override the credits
    $credits = apply_filters( 'mwai_stats_credits',  $limits['credits'], $userId );

    $stats['queriesLimit'] = intVal( $hasLimits && $limits['creditType'] === "queries" ? $credits : 0 );
    $stats['unitsLimit'] = intVal( $hasLimits && $limits['creditType'] === "units" ? $credits : 0 );
    $stats['priceLimit'] = floatVal( $hasLimits && $limits['creditType'] === "price" ? $credits : 0 );

    // Check if the limits are exceeded
    $stats['overLimit'] = false;
    if ( $hasLimits ) {
      if ( $limits['creditType'] === "queries" ) {
        $stats['overLimit'] = $stats['queries'] >= $credits;
        $stats['usagePercentage'] = $stats['queriesLimit'] > 0 ? round( $stats['queries'] / $stats['queriesLimit'] * 100, 2 ) : 0;
      }
      else if ( $limits['creditType'] === "units" ) {
        $stats['overLimit'] = $stats['units'] >= $credits;
        $stats['usagePercentage'] = $stats['unitsLimit'] > 0 ? round( $stats['units'] / $stats['unitsLimit'] * 100, 2 ) : 0;
      }
      else if ( $limits['creditType'] === "price" ) {
        $stats['overLimit'] = $stats['price'] >= $credits;
        $stats['usagePercentage'] = $stats['priceLimit'] > 0 ? round( $stats['price'] / $stats['priceLimit'] * 100, 2 ) : 0;
      }
    }

    return $stats;
  }

  function shortcode_current( $atts ) {
    $display = isset( $atts['display'] ) ? $atts['display'] : 'debug';
    if ( $display === 'debug' ) {
      $display = 'stats';
    }
    else if ( $display === 'usage' ) {
      $display = 'usagebar';
    }

    $showWho = filter_var( isset( $atts['display_who'] ) ?
      $atts['display_who'] : true, FILTER_VALIDATE_BOOLEAN );
    $showQueries = filter_var( isset( $atts['display_queries'] ) ?
      $atts['display_queries'] : true, FILTER_VALIDATE_BOOLEAN );
    $showUnits = filter_var( isset( $atts['display_units'] ) ?
      $atts['display_units'] : true, FILTER_VALIDATE_BOOLEAN );
    $showPrice = filter_var( isset( $atts['display_price'] ) ?
      $atts['display_price'] : true, FILTER_VALIDATE_BOOLEAN );
    $showUsage = filter_var( isset( $atts['display_usage'] ) ?
      $atts['display_usage'] : true, FILTER_VALIDATE_BOOLEAN );
    $showCoins = filter_var( isset( $atts['display_coins'] ) ?
      $atts['display_coins'] : true, FILTER_VALIDATE_BOOLEAN );

    $stats = $this->query();

    if ( $display === "usagebar" ) {
      $percent = isset( $stats['usagePercentage'] ) ? $stats['usagePercentage'] : 0;
      $cssPercent = $percent > 100 ? 100 : $percent;
      $output = '<div class="mwai-statistics mwai-statistics-usage">';
      $output .= '<div class="mwai-statistics-bar-container">';
      $output .= '<div class="mwai-statistics-bar" style="width: ' . $cssPercent . '%;"></div>';
      $output .= '</div>';
      $output .= '<div class="mwai-statistics-bar-text">' . $percent . '%</div>';
      $output .= '</div>';
      $css = file_get_contents( MWAI_PATH . '/premium/styles/stats_ChatGPT.css' );
      $output .= "<style>" . $css . "</style>";
      return $output;
    }
    else if ( $display === "stats" ) {
      if ( $stats === null ) {
        return "No stats available.";
      }

      $output = '<div class="mwai-statistics mwai-statistics-debug">';

      if ( $showWho ) {
        if ( !empty( $stats['userId'] ) ) {
          $output .= "<div>User ID: {$stats['userId']}</div>";
        }
        if ( !empty( $stats['ipAddress'] ) ) {
          $output .= "<div>IP Address: {$stats['ipAddress']}</div>";
        }
      }

      if ( $showQueries ) {
        $output .= "<div>Queries: {$stats['queries']}" . 
          ( !empty( $stats['queriesLimit'] ) ? " / {$stats['queriesLimit']}" : "" ) . "</div>";
      }
        
      if ( $showUnits ) {
        $output .= "<div>Units: {$stats['units']}" . 
          ( !empty( $stats['unitsLimit'] ) ? " / {$stats['unitsLimit']}" : "" ) . "</div>";
        $output .= "<small>Note: Units are Tokens and Images Count.</small>";
      }

      if ( $showPrice ) {
        $output .= "<div>Price: {$stats['price']}$" . 
          ( !empty( $stats['priceLimit'] ) ? " / {$stats['priceLimit']}$" : "" ) . "</div>";
      }

      if ( $showCoins ) {
        $coins = apply_filters( 'mwai_stats_coins', $stats['price'], $stats, $atts );
        $coinsLimit = apply_filters( 'mwai_stats_coins_limit', $stats['priceLimit'], $stats, $atts );
        $output .= "<div>Coins: {$coins}" . 
          ( !empty( $coinsLimit ) ? " / {$coinsLimit}" : "" ) . "</div>";
      }

      if ( $showUsage && isset( $stats['usagePercentage'] ) ) {
        $output .= "<div>Usage: {$stats['usagePercentage']}% " . 
          ( $stats['overLimit'] ? '<span class="mwai-over">(OVER LIMIT)</span>' :
            '<span class="mwai-ok">(OK)</span>' ) . "</div>";
      }

      $output .= '</div>';
      return $output;
    }
  }

  function validate_data( $data ) {
    $data['time'] = date( 'Y-m-d H:i:s' );
    $data['userId'] = $this->core->get_user_id( $data );
    $data['session'] = isset( $data['session'] ) ? (string)$data['session'] : null;
    $data['ip'] = $this->core->get_ip_address( $data );
    $data['model'] = isset( $data['model'] ) ? (string)$data['model'] : null;
    $data['mode'] = isset( $data['mode'] ) ? (string)$data['mode'] : null;
    $data['units'] = isset( $data['units'] ) ? intval( $data['units'] ) : 0;
    $data['type'] = isset( $data['type'] ) ? (string)$data['type'] : null;
    $data['price'] = isset( $data['price'] ) ? floatval( $data['price'] ) : 0;
    $data['scope'] = isset( $data['scope'] )? (string)$data['scope'] : null;
    $data['apiRef'] = isset( $data['apiRef'] ) ? (string)$data['apiRef'] : null;
    $data['envId'] = isset( $data['envId'] ) ? (string)$data['envId'] : null;
    $data['apiOwn'] = isset( $data['apiOwn'] ) ? (string)$data['apiOwn'] : null;
    $data['tags'] = $this->buildTagsForDb( isset( $data['tags'] ) ? $data['tags'] : null );
    $data['time'] = date( 'Y-m-d H:i:s' );
    return $data;
  }

  function add( $data ) {
    $this->check_db();
    $data = $this->validate_data( $data );
    if ( empty( $data ) ) {
      return false;
    }
    $res = $this->wpdb->insert( $this->table_logs, $data );
    if ( $res === false ) {
      $this->core->log("⚠️ (Stats) Error while writing logs (" . $this->wpdb->last_error . ")" );
      return false;
    }
    return $this->wpdb->insert_id;
  }

  function addMetadata( $logId, $metaKey, $metaValue ) {
    $data = [
      'log_id' => $logId,
      'meta_key' => $metaKey,
      'meta_value' => $metaValue
    ];
    $res = $this->wpdb->insert( $this->table_logmeta, $data );
    if ( $res === false ) {
      $this->core->log("⚠️ (Stats) Error while writing logs metadata (" . $this->wpdb->last_error . ")" );
      return false;
    }
    return $this->wpdb->insert_id;
  }

  function check_db() {
    if ( $this->db_check ) {
      return true;
    }
    $this->db_check = !( strtolower( 
      $this->wpdb->get_var( "SHOW TABLES LIKE '$this->table_logs'" ) ) != strtolower( $this->table_logs )
    );
    if ( !$this->db_check ) {
      $this->create_db();
      $this->db_check = !( strtolower( 
        $this->wpdb->get_var( "SHOW TABLES LIKE '$this->table_logs'" ) ) != strtolower( $this->table_logs )
      );
    }

    // LATER: REMOVE THIS AFTER MARCH 2024
    // Rename 'env' column in $this->table_logs to 'scope'
    $this->db_check = $this->db_check && $this->wpdb->get_var( "SHOW COLUMNS FROM $this->table_logs LIKE 'scope'" );
    if ( !$this->db_check ) {
      $this->wpdb->query( "ALTER TABLE $this->table_logs CHANGE COLUMN env scope VARCHAR(64) NULL" );
      $this->wpdb->query( "ALTER TABLE $this->table_logs CHANGE COLUMN apiSrv envId VARCHAR(128) NULL" );
      $this->db_check = true;
    }

    return $this->db_check;
  }

  function create_db() {
    $charset_collate = $this->wpdb->get_charset_collate();

    $sqlLogs = "CREATE TABLE $this->table_logs (
      id BIGINT(20) NOT NULL AUTO_INCREMENT,
      userId BIGINT(20) NULL,
      ip VARCHAR(64) NULL,
      session VARCHAR(64) NULL,
      model VARCHAR(64) NULL,
      mode VARCHAR(64) NULL,
      units INT(11) NOT NULL DEFAULT 0,
      type VARCHAR(64) NULL,
      price FLOAT NOT NULL DEFAULT 0,
      scope VARCHAR(64) NULL,
      tags VARCHAR(128) NULL,
      apiRef VARCHAR(128) NULL,
      envId VARCHAR(128) NULL,
      apiOwn VARCHAR(128) NULL,
      time DATETIME NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";

    $sqlLogMeta = "CREATE TABLE $this->table_logmeta (
      meta_id BIGINT(20) NOT NULL AUTO_INCREMENT,
      log_id BIGINT(20) NOT NULL,
      meta_key varchar(255) NULL,
      meta_value longtext NULL,
      PRIMARY KEY  (meta_id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sqlLogs );
    dbDelta( $sqlLogMeta );
  }

  function remove_db() {
    $sql = "DROP TABLE IF EXISTS $this->table_logs, $this->table_logmeta;";
    $this->wpdb->query( $sql );
  }

  function logs_meta_query( array $meta, int $logId, array $metaKeys ) {
    $query = "SELECT * FROM $this->table_logmeta";
    $where = [];
    $where[] = "log_id = " . intval( $logId );
    if ( !empty( $metaKeys ) ) {
      $where[] = "meta_key IN ('" . implode( "','", $metaKeys ) . "')";
    }
    if ( !empty( $where ) ) {
      $query .= " WHERE " . implode( " AND ", $where );
    }
    $query .= " ORDER BY meta_key ASC";
    $res = $this->wpdb->get_results( $query, ARRAY_A );
    foreach ( $res as $key => $value ) {
      if ( $value['meta_key'] === 'query' ) {
        $meta['query'] = json_decode( $value['meta_value'], true );
      }
      else if ( $value['meta_key'] === 'reply' ) {
        $meta['reply'] = json_decode( $value['meta_value'], true );
      }
      else if ( $value['meta_key'] === 'fields' ) {
        $meta['fields'] = json_decode( $value['meta_value'], true );
      }
    }
    return $meta;
  }

  function logs_delete( $success, $logIds ) {
    if ( !$success ) {
      return false;
    }
    $logIds = !empty( $logIds ) ? $logIds : [];
    if ( empty( $logIds ) ) {
      $query = "DELETE FROM $this->table_logs";
      $this->wpdb->query( $query );
      $query = "DELETE FROM $this->table_logmeta";
      $this->wpdb->query( $query );
      return true;
    }
    $logIds = array_map( 'intval', $logIds );
    $logIds = implode( ',', $logIds );
    $query = "DELETE FROM $this->table_logs WHERE id IN ($logIds)";
    $this->wpdb->query( $query );

    // Clean up meta
    $query = "DELETE FROM $this->table_logmeta WHERE log_id NOT IN (SELECT id FROM $this->table_logs)";
    $this->wpdb->query( $query );

    return true;
  }

  function logs_query( $logs = [], $offset = 0, $limit = null, $filters = null, $sort = null ) {
    $this->check_db();
    $offset = !empty( $offset ) ? intval( $offset ) : 0;
    $limit = !empty( $limit ) ? intval( $limit ) : 100;
    $filters = !empty( $filters ) ? $filters : [];
    $sort = !empty( $sort ) ? $sort : [ "accessor" => "time", "by" => "desc" ];
    $query = "SELECT * FROM $this->table_logs";

    // Filters
    $where = [];
    if ( !empty( $filters ) ) {
      foreach ( $filters as $filter ) {
        $accessor = $filter['accessor'];
        $value = $filter['value'];
        if ( empty( $value ) ) {
          continue;
        }
        if ( $accessor === 'user' ) {
          $isIP = filter_var( $value, FILTER_VALIDATE_IP );
          if ( $isIP ) {
            $where[] = "ip = '" . esc_sql( $value ) . "'";
          }
          else {
            $where[] = "userId = " . intval( $value );
          }
        }
        else if ( $accessor === 'session' ) {
          $where[] = "session = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'model' ) {
          $where[] = "model = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'mode' ) {
          $where[] = "mode = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'units' ) {
          $where[] = "units = " . intval( $value );
        }
        else if ( $accessor === 'type' ) {
          $where[] = "type = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'price' ) {
          $where[] = "price = " . floatval( $value );
        }
        else if ( $accessor === 'scope' ) {
          // $value is an array so we need to use OR
          $where[] = "scope IN ('" . implode( "','", $value ) . "')";
        }
        else if ( $accessor === 'tags' ) {
          $where[] = "tags = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'apiRef' ) {
          $where[] = "apiRef = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'envId' ) {
          $where[] = "envId = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'apiOwn' ) {
          $where[] = "apiOwn = '" . esc_sql( $value ) . "'";
        }
        else if ( $accessor === 'time' ) {
          $where[] = "time = '" . esc_sql( $value ) . "'";
        }
      }
    }
    if ( count( $where ) > 0 ) {
      $query .= " WHERE " . implode( " AND ", $where );
    }

    // Count based on this query
    $logs['total'] = $this->wpdb->get_var( "SELECT COUNT(*) FROM ($query) AS t" );

    // Order by
    $query .= " ORDER BY " . esc_sql( $sort['accessor'] ) . " " . esc_sql( $sort['by'] );

    // Limits
    if ( $limit > 0 ) {
      $query .= " LIMIT $offset, $limit";
    }

    $logs['rows'] = $this->wpdb->get_results( $query, ARRAY_A );
    return $logs;
  }
}