<?php

class MeowPro_MWAI_Core {
	private $item = 'AI Engine Pro';
	private $core = null;

	public function __construct( $core  ) {
		$this->core = $core;

		// Common behaviors, license, update system, etc.
		new MeowCommonPro_Licenser( MWAI_PREFIX, MWAI_ENTRY, MWAI_DOMAIN, $this->item, MWAI_VERSION );

		// Content Aware
		new MeowPro_MWAI_ContentAware( $core );

		// Function Aware
		new MeowPro_MWAI_FunctionAware( $core );

		// Assistants
		if ( $this->core->get_option( 'module_assistants', false ) ) {
			$mwai_assistants = new MeowPro_MWAI_Assistants( $core );
		}

		// Statistics
		if ( $this->core->get_option( 'module_statistics', false ) ) {
			global $mwai_stats;
			$mwai_stats = new MeowPro_MWAI_Statistics();
		}

		// Embeddings
		if ( $this->core->get_option( 'module_embeddings', false ) ) {
			global $mwai_embeddings;
			$mwai_embeddings = new MeowPro_MWAI_Embeddings();
		}

		// Forms	
		if ( !is_admin() && $this->core->get_option( 'module_forms', false ) ) {
			global $mwai_forms;
			$mwai_forms = new MeowPro_MWAI_Forms();
		}

		// Overrides for the Pro
		add_filter( 'mwai_plugin_title', array( $this, 'plugin_title' ), 10, 1 );
		add_action( 'mwai_support_db_loaded', array( $this, 'support_db_loaded' ) );
	}

	public function __destruct() {
		remove_filter( 'mwai_plugin_title', array( $this, 'plugin_title' ), 10 );
	}

	function plugin_title( $string ) {
		return $string . " (Pro)";
	}
}