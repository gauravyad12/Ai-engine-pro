<?php

class MeowPro_MWAI_FunctionAware {
  private $core = null;

  function __construct( $core ) {
    $this->core = $core;
    add_filter( 'mwai_chatbot_query', array( $this, 'chatbot_query' ), 10, 2 );
    add_filter( 'mwai_ai_feedback', array( $this, 'ai_feedbacks' ), 10, 2 );
    add_filter( 'mwai_functions_list', array( $this, 'functions_list' ), 10, 1 );
  }

  /**
   * Create a Meow_MWAI_Query_Function object based on type and id
   *
   * @param string $type
   * @param string $id
   * @return Meow_MWAI_Query_Function|null
   */
  public static function create_query_function( $type, $id ) {
    global $mwai_core;
    if ( $type !== 'snippet-vault' ) {
      $mwai_core->log( "⚠️ (Functions) The type '{$type}' for the function is not supported." );
      return null;
    }

    global $mwcode;
    if ( empty( $mwcode ) ) {
      $mwai_core->log( "⚠️ (Functions) Snippet Vault is not available." );
      return null;
    }

    $func = $mwcode->get_function( 
      $id,
      [ 'php_ready_args' => false ] // Get the "raw" arguments' names.
    );

    if ( empty( $func ) ) {
      $mwai_core->log( "⚠️ (Functions) The function '{$id}' was not found." );
      return null;
    }

    $args = [];
    foreach ( $func['args'] as $arg ) {
      $name = $arg['name'] ?? "";
      if ( substr( $name, 0, 1 ) === '$' ) {
        $mwai_core->log( "⚠️ (Functions) The argument '{$name}' should not start with a dollar sign." );
        $name = substr( $name, 1 );
      }
      $desc = $arg['desc'] ?? "";
      $type = $arg['type'] ?? "string";
      $required = $arg['required'] ?? true;
      $args[] = new Meow_MWAI_Query_Parameter( $name, $desc, $type, $required );
    }

    return new Meow_MWAI_Query_Function( $func['name'], $func['desc'], $args, 'PHP', $func['snippetId'] );
  }

  function functions_list( $functions ) {
    global $mwcode;
    if ( isset( $mwcode ) ) {
      $more_functions = $mwcode->get_functions();
      if ( !empty( $more_functions ) ) {
        $functions = array_merge( $functions, $more_functions );
      }
    }
    return $functions;
  }

  function ai_feedbacks( $value, $functionCall ) {
    $function = $functionCall['function'];
    if ( empty( $function ) || empty( $function->id ) ) {
      return $value;
    }
    $arguments = $functionCall['arguments'] ?? [];
    // Not sure why Anthropic is sending an object with a type of 'object' when there is nothing
    // in the object. This is a workaround for that.
    if ( is_array( $arguments ) && count( $arguments ) === 1 && 
      isset( $arguments['type'] ) && $arguments['type'] === 'object' ) {
      $arguments = [];
    }
    global $mwcode;
    if ( empty( $mwcode ) ) {
      $this->core->log( "⚠️ (Functions) Snippet Vault is not available." );
      return $value;
    }
    $value = $mwcode->execute_function( $function->id, $functionCall['arguments'] );
    return $value;
  }

  function chatbot_query( $query, $params ) {
    $functions = $params['functions'] ?? [];
    foreach ( $functions as $function ) {
      $query_function = self::create_query_function( $function['type'] ?? null, $function['id'] ?? null );
      if ( $query_function ) {
        $query->add_function( $query_function );
      }
    }
    return $query;
  }
}
