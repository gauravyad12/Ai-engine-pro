<?php

class MeowPro_MWAI_ContentAware {
  private $core = null;

  function __construct( $core ) {
    $this->core = $core;
    add_filter( 'mwai_chatbot_params', array( $this, 'chatbot_params' ) );
  }

  function chatbot_params( $params ) {
    if ( !isset( $params['content_aware'] ) && !isset( $params['contentAware'] ) ) {
      return $params;
    }
    $post = get_post( isset( $params['contextId'] ) ? $params['contextId'] : null );
    if ( empty( $post ) ) {
      return $params;
    }

    // Content
    if ( !strpos( $params['instructions'], '{CONTENT}' ) === false ) {
      $content = $this->core->get_post_content( $post->ID );

      // If WooCommerce, get the Product Description
      if ( class_exists( 'WooCommerce' ) ) {
        if ( is_product() ) {
          global $product;
          $shortDescription = $this->core->clean_text( $product->get_short_description() );
          if ( !empty( $shortDescription ) ) {
            $content .= $shortDescription;
          }
        }
      }
      $content = $this->core->clean_sentences( $content );
      $content = apply_filters( 'mwai_contentaware_content', $content, $post );
      $params['instructions'] = str_replace( '{CONTENT}', $content, $params['instructions'] );
    }

    // Excerpt
    if ( !strpos( $params['instructions'], '{EXCERPT}' ) === false ) {
      if ( !empty( $post ) ) {
        $excerpt = $this->core->clean_text( $post->post_excerpt );
        $params['instructions'] = str_replace( '{EXCERPT}', $excerpt, $params['instructions'] );
      }
    }

    // Title
    if ( !strpos( $params['instructions'], '{TITLE}' ) === false ) {
      $title = $this->core->clean_text( $post->post_title );
      $params['instructions'] = str_replace( '{TITLE}', $title, $params['instructions'] );
    }

    // URL
    if ( !strpos( $params['instructions'], '{URL}' ) === false ) {
      $url = get_permalink( $post->ID );
      $params['instructions'] = str_replace( '{URL}', $url, $params['instructions'] );
    }

    return $params;
  }
}
