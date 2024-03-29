<?php
/**
 * Revolut API Client
 *
 * @package WooCommerce
 * @since   2.0
 * @author  Revolut
 */

defined( 'ABSPATH' ) || exit();

/**
 * WC_Revolut_API_Client class.
 */
class WC_Revolut_API_Client {


	use WC_Revolut_Logger_Trait;

	/**
	 * Version
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Api url live mode
	 *
	 * @var string
	 */
	public $api_url_live = 'https://merchant.revolut.com';

	/**
	 * Api url sandbox mode
	 *
	 * @var string
	 */
	public $api_url_sandbox = 'https://sandbox-merchant.revolut.com';

	/**
	 * Api url dev mode
	 *
	 * @var string
	 */
	public $api_url_dev = 'https://merchant.revolut.codes';

	/**
	 * Merchant management Api urls
	 *
	 * @var array
	 */
	public $mgmt_api_urls = array(
		'live'    => 'https://merchant-mgmt.revolut.com',
		'dev'     => 'https://merchant-mgmt.revolut.codes',
		'sandbox' => 'https://sandbox-merchant-mgmt.revolut.com',
	);

	/**
	 * Api mode live|sandbox|develop
	 *
	 * @var string
	 */
	public $mode;

	/**
	 * Api key
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Public key
	 *
	 * @var string
	 */
	public $public_key;

	/**
	 * Api base url
	 *
	 * @var string
	 */
	public $base_url;

	/**
	 * Api url
	 *
	 * @var string
	 */
	public $api_url;

	/**
	 * Public Api url
	 *
	 * @var string
	 */
	public $mgmt_api_url;

	/**
	 * API settings
	 *
	 * @var WC_Revolut_Settings_API
	 */
	private $api_settings;

	/**
	 * Constructor
	 *
	 * @param WC_Revolut_Settings_API $api_settings Api settings.
	 * @param bool                    $new_api      api version.
	 */
	public function __construct( WC_Revolut_Settings_API $api_settings, $new_api = false ) {
		$this->api_settings = $api_settings;
		$this->version      = WC_GATEWAY_REVOLUT_VERSION;

		$this->mode = $this->api_settings->get_option( 'mode' );

		if ( 'live' === $this->mode ) {
			$this->base_url = $this->api_url_live;
			$this->api_key  = $this->api_settings->get_option( 'api_key' );
		} elseif ( 'sandbox' === $this->mode ) {
			$this->base_url = $this->api_url_sandbox;
			$this->api_key  = $this->api_settings->get_option( 'api_key_sandbox' );
		} elseif ( 'dev' === $this->mode ) {
			$this->base_url = $this->api_url_dev;
			$this->api_key  = $this->api_settings->get_option( 'api_key_dev' );
		}

		// switch to the new api if required.
		$this->api_url      = $new_api ? $this->base_url . '/api' : $this->base_url . '/api/1.0';
		$this->mgmt_api_url = $this->mgmt_api_urls[ $this->mode ] . '/api';
	}

	/**
	 * Send post to API.
	 *
	 * @param String     $path Api path.
	 * @param array|null $body Request body.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function post( $path, $body = null ) {
		return $this->request( $path, 'POST', $body );
	}

	/**
	 * Send request to API
	 *
	 * @param String     $path             Api path.
	 * @param String     $method           Request method.
	 * @param array|null $body             Request body.
	 * @param bool       $is_mgmt_endpoint Management API indicator.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	private function request( $path, $method, $body = null, $is_mgmt_endpoint = false ) {
		global $wp_version;
		global $woocommerce;

		if ( empty( $this->api_key ) ) {
			return array();
		}

		$api_key = $this->api_key;

		if ( $is_mgmt_endpoint && WC_GATEWAY_PUBLIC_KEY_ENDPOINT !== $path ) {
			$api_key = $this->public_key;
		}

		$request = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'User-Agent'    => 'Revolut Payment Gateway/' . WC_GATEWAY_REVOLUT_VERSION . ' WooCommerce/' . $woocommerce->version . ' Wordpress/' . $wp_version . ' PHP/' . PHP_VERSION,
				'Content-Type'  => 'application/json',
			),
			'method'  => $method,
		);

		if ( null !== $body ) {
			$request['body'] = wp_json_encode( $body );
		}

		$url = $this->api_url . $path;
		if ( $is_mgmt_endpoint ) {
			$url = $this->mgmt_api_url . $path;
		}

		$response      = wp_remote_request( $url, $request );
		$response_body = wp_remote_retrieve_body( $response );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 && wp_remote_retrieve_response_code( $response ) < 500 && 'GET' !== $method ) {
			$this->log_error( "Failed request to URL $method $url" );
			$this->log_error( $response_body );
			throw new Exception( "Something went wrong: $method $url\n" . $response_body );
		}

		return json_decode( $response_body, true );
	}

	/**
	 * Send request to public API
	 *
	 * @param String     $path             Api path.
	 * @param array      $headers          Request method.
	 * @param String     $method           Request method.
	 * @param array|null $body             Request body.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function public_request( $path, $headers, $method = 'POST', $body = null ) {
		global $wp_version;
		global $woocommerce;

		$headers['User-Agent']   = 'Revolut Payment Gateway/' . WC_GATEWAY_REVOLUT_VERSION . ' WooCommerce/' . $woocommerce->version . ' Wordpress/' . $wp_version . ' PHP/' . PHP_VERSION;
		$headers['Content-Type'] = 'application/json';

		$request = array(
			'headers' => $headers,
			'method'  => $method,
		);

		if ( null !== $body ) {
			$request['body'] = wp_json_encode( $body );
		}

		$url = $this->mgmt_api_url . '/public/' . $path;

		$response      = wp_remote_request( $url, $request );
		$response_body = wp_remote_retrieve_body( $response );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 && wp_remote_retrieve_response_code( $response ) < 500 && 'GET' !== $method ) {
			$this->log_error( "Failed request to URL $method $url" );
			$this->log_error( $response_body );
			throw new Exception( "Something went wrong: $method $url\n" . $response_body );
		}

		return json_decode( $response_body, true );
	}

	/**
	 * Send GET request to API
	 *
	 * @param String  $path Request path.
	 * @param Boolean $is_mgmt Management API indicator.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function get( $path, $is_mgmt = false ) {
		return $this->request( $path, 'GET', null, $is_mgmt );
	}

	/**
	 * Revolut API patch
	 *
	 * @param String     $path Request path.
	 * @param array|null $body Request body.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function patch( $path, $body ) {
		return $this->request( $path, 'PATCH', $body );
	}

	/**
	 * Revolut API delete
	 *
	 * @param String $path Request path.
	 *
	 * @return mixed
	 * @throws Exception Exception.
	 */
	public function delete( $path ) {
		return $this->request( $path, 'DELETE' );
	}

	/**
	 * Set Revolut Merchant Public Key
	 *
	 * @param String $public_key public key.
	 *
	 * @return void
	 */
	public function set_public_key( $public_key ) {
		$this->public_key = $public_key;
	}
}
