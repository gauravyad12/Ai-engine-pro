<?php

// phpcs:disable

if ( !class_exists( 'MeowCommonPro_Licenser' ) ) {

  class MeowCommonPro_Licenser {
    public $license = null;
    public $prefix;     // prefix used for actions, filters (mfrh)
    public $mainfile;   // plugin main file (media-file-renamer.php)
    public $domain;     // domain used for translation (media-file-renamer)
    public $item;       // name of the Pro plugin (Media File Renamer Pro)
    public $version;    // version of the plugin (Media File Renamer Pro)
    public $item_id;

    /**
     * Constructor for the MeowCommonPro_Licenser class.
     *
     * @param string $prefix The prefix used for actions and filters.
     * @param string $mainfile The plugin main file.
     * @param string $domain The domain used for translation.
     * @param string $item The name of the Pro plugin.
     * @param string $version The version of the plugin.
     */
    public function __construct( $prefix, $mainfile, $domain, $item, $version ) {
      $this->prefix = $prefix;
      $this->mainfile = $mainfile;
      $this->domain = $domain;
      $this->item = $item;
      $this->version = $version;
      $item_id_key = strtoupper( $this->prefix ) . '_ITEM_ID';
      if ( defined( $item_id_key ) ) {
        $this->item_id = constant( $item_id_key );
      }

      if ( $this->is_registered() ) {
        add_filter( $this->prefix . '_meowapps_is_registered', array( $this, 'is_registered' ), 10 );
      }

      if ( MeowCommon_Helpers::is_rest() ) {
        new MeowCommonPro_Rest_License( $this );
      }
      else if ( is_admin() ) {
        $license_key = isset( $this->license['key'] ) ? $this->license['key'] : '';
        $updater_options = array(
          'version'     => $this->version,
          'license'     => $license_key,
          'wp_override' => true,
          'author'      => 'Jordy Meow',
          'url'         => strtolower( home_url() ),
          'beta'        => false
        );
        if ( $this->item_id ) {
          $updater_options['item_id'] = $this->item_id;
        }
        else {
          $updater_options['item_name'] = $this->item;
        }
        $api_url = ( get_option( 'force_sslverify', false ) ? 'https' : 'http' ) . '://meowapps.com';
        new MeowCommonPro_Updater( $api_url, $this->mainfile, $updater_options );
      }
    }

    /**
     * Retry validation of the license.
     */
    function retry_validation() {
      if ( isset( $_POST[$this->prefix . '_pro_serial'] ) ) {
        $serial = sanitize_text_field( $_POST[$this->prefix . '_pro_serial'] );
        $this->validate_pro( $serial );
      }
    }

    /**
     * Check if the plugin is registered.
     *
     * @param bool $force Force re-check.
     * @return bool
     */
    function is_registered( $force = false ) {
      $constant_name = 'MEOWAPPS_' . strtoupper( $this->prefix ) . '_LICENSE';
      if ( defined( $constant_name ) ) {
        $license = constant( $constant_name );
        if ( !empty( $license ) ) {
          $this->license = array(
            'key' => $license,
            'logs' => 'Enabled by constant.'
          );
          return true;
        }
      }

      if ( !$force && !empty( $this->license ) ) {
        $has_no_issues = empty( $this->license['issue'] );
        return $has_no_issues;
      }
      $this->license = get_option( $this->prefix . '_license', "" );
      if ( empty( $this->license ) || !empty( $this->license['issue'] ) ) {
        return true;
      }
      if ( $this->license['expires'] == "lifetime" ) {
        return true;
      }
      $datediff = strtotime( $this->license['expires'] ) - time();
      $days = floor( $datediff / ( 60 * 60 * 24 * 7 * 3 ) );
      if ( $days < 0 ) {
        $this->validate_pro( $this->license['key'] );
      }
      return true;
    }

    /**
     * Validate the Pro license.
     *
     * @param string $subscr_id The subscription ID.
     * @param bool $override Whether to override existing validation.
     * @return bool
     */
    function validate_pro( $subscr_id, $override = false ) {
      $prefix = $this->prefix;
      delete_option( $prefix . '_license', "" );

      if ( empty( $subscr_id ) ) {
        $this->license = null;
        return false;
      }

      if ( $override ) {
        // This doesn't work with updates.
        $current_user = wp_get_current_user();
        delete_option( '_site_transient_update_plugins' );
        $url = 'https://meowapps.com/?edd_action=activate_license';
        if ( $this->item_id ) {
          $url .= '&item_id=' . $this->item_id;
        }
        else {
          $url .= '&item_name=' . urlencode( $this->item );
        }
        $url .= '&license=' . $subscr_id . '&url=' . strtolower( home_url() );
        update_option( $prefix . '_license',  array( 'key' => $subscr_id, 'issue' => null,
          'logs' => sprintf( "Forced by %s on %s.", $current_user->user_email, date( "Y/m/d" ) ),
          'expires' => 'lifetime', 'license' => null, 'check_url' => $url ) );
      }
      else {
        $url = 'https://meowapps.com/?edd_action=activate_license';
        if ( $this->item_id ) {
          $url .= '&item_id=' . $this->item_id;
        }
        else {
          $url .= '&item_name=' . urlencode( $this->item );
        }
        $url .= '&license=' . $subscr_id . '&url=' . strtolower( home_url() );
        $url .= '&cache=' . bin2hex( openssl_random_pseudo_bytes( 4 ) );

        $response = wp_remote_get( $url, array(
            'user-agent' => "MeowApps",
            'sslverify' => get_option( 'force_sslverify', false ),
            'timeout' => 45,
            'method' => 'GET'
          )
        );
        $body = is_array( $response ) ? $response['body'] : null;
        $http_code = is_array( $response ) ? $response['response']['code'] : null;
        $post = @json_decode( $body );
        $status = null;
        $license = null;
        $expires = null;
        $debug = [
					'resolved_ip' => null,
					'server_addr' => null,
					'server_host' => null,
          'google_response_code' => null,
          'meowapps_response_code' => null,
          'license_response_code' => $http_code,
          'google_body' => null,
          'meowapps_body' => null,
          'license_body' => null,
        ];

        if ( !$post || ( property_exists( $post, 'code' ) ) ) {
          $status = 'error';

          // Google response
          $google_response = wp_remote_get( 'http://google.com' );
          $debug['google_response_code'] = is_wp_error( $google_response ) ? print_r( $google_response, true ) : wp_remote_retrieve_response_code( $google_response );
          if ( $debug['google_response_code'] !== 200 ) {
            $debug['google_body'] = wp_remote_retrieve_body( $google_response );
          }

          // MeowApps response
          $meowapps_response = wp_remote_get( 'http://meowapps.com' );
          $debug['meowapps_response_code'] = is_wp_error( $meowapps_response ) ? print_r( $meowapps_response, true ) : wp_remote_retrieve_response_code( $meowapps_response );
          if ( $debug['meowapps_response_code'] !== 200 ) {
            $debug['meowapps_body'] = wp_remote_retrieve_body( $meowapps_response );
          }

          // License response
          if ( $http_code !== 200 ) {
            $debug['license_body'] = $body;
          }

					// Resolve IP
					$resIp = wp_remote_get( 'https://api.ipify.org/' );
					if ( !is_wp_error( $resIp ) ) {
						$debug['resolved_ip'] = wp_remote_retrieve_body( $resIp );
					}
					$debug['server_addr'] = $_SERVER['SERVER_ADDR'];
					$debug['server_host'] = gethostbyname( $_SERVER['SERVER_NAME'] );
        }
        else if ( $post->license !== "valid" ) {
          $status = $post->error;
        }
        else {
          $license = $post->license;
          $expires = $post->expires;
          delete_option( '_site_transient_update_plugins' );
        }
        update_option( $prefix . '_license', array( 'key' => $subscr_id, 'issue' => $status,
          'debug' => $debug, 'expires' => $expires, 'license' => $license )
        );
      }
      return $this->is_registered( true );
    }
  }
}

?>
