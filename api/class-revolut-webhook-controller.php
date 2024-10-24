<?php
/**
 * Revolut_Webhook_Controller
 *
 * Controller for handling Revolut webhook callbacks
 *
 * @package    WooCommerce
 * @category   Payment Gateways
 * @author     Revolut
 * @since      2.0.0
 */

/**
 * Revolut_Webhook_Controller class
 */
class Revolut_Webhook_Controller extends \WC_REST_Data_Controller {

	use WC_Gateway_Revolut_Helper_Trait;

	use WC_Gateway_Revolut_Express_Checkout_Helper_Trait;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v3';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'revolut';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_settings = revolut_wc()->api_settings;
		$this->api_client   = new WC_Revolut_API_Client( $this->api_settings );
	}

	/**
	 * Register routes.
	 *
	 * @since 3.5.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::ALLMETHODS,
				'callback'            => array( $this, 'handle_revolut_webhook_deprecated_endpoint' ),
				'permission_callback' => function ( $request = null ) {
					return true;
				},
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/webhook/sandbox',
			array(
				'methods'             => \WP_REST_Server::ALLMETHODS,
				'callback'            => array( $this, 'handle_revolut_webhook' ),
				'permission_callback' => array( $this, 'revolut_webhook_permission_callback_sandbox' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/webhook/live',
			array(
				'methods'             => \WP_REST_Server::ALLMETHODS,
				'callback'            => array( $this, 'handle_revolut_webhook' ),
				'permission_callback' => array( $this, 'revolut_webhook_permission_callback_live' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/address/validation/webhook/sandbox',
			array(
				'methods'             => \WP_REST_Server::ALLMETHODS,
				'callback'            => array( $this, 'handle_revolut_address_validation_webhook' ),
				'permission_callback' => array( $this, 'revolut_address_validation_webhook_permission_callback_sandbox' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/address/validation/webhook/live',
			array(
				'methods'             => \WP_REST_Server::ALLMETHODS,
				'callback'            => array( $this, 'handle_revolut_address_validation_webhook' ),
				'permission_callback' => array( $this, 'revolut_address_validation_webhook_permission_callback_live' ),
			)
		);
	}


	/**
	 * Permissions check
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool
	 */
	public function revolut_address_validation_webhook_permission_callback_live( $request = null ) {
		$signing_secret = get_option( 'revolut_pay_synchronous_webhook_domain_live_signing_key' );
		return $this->revolut_address_validation_webhook_permission_callback( $request, $signing_secret );
	}

	/**
	 * Permissions check
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool
	 */
	public function revolut_address_validation_webhook_permission_callback_sandbox( $request = null ) {
		$signing_secret = get_option( 'revolut_pay_synchronous_webhook_domain_sandbox_signing_key' );
		return $this->revolut_address_validation_webhook_permission_callback( $request, $signing_secret );
	}

	/**
	 * Permissions check
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool
	 */
	public function revolut_webhook_permission_callback_sandbox( $request = null ) {
		$signing_secret = get_option( 'sandbox_revolut_webhook_domain_signing_secret' );
		return $this->revolut_webhook_permission_callback( $request, $signing_secret );
	}

	/**
	 * Permissions check
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool
	 */
	public function revolut_webhook_permission_callback_live( $request = null ) {
		$signing_secret = get_option( 'live_revolut_webhook_domain_signing_secret' );
		return $this->revolut_webhook_permission_callback( $request, $signing_secret );
	}

	/**
	 * Permissions check
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @param string          $signing_secret secret key for signing the request.
	 *
	 * @return bool
	 */
	public function revolut_address_validation_webhook_permission_callback( $request, $signing_secret ) {
		if ( empty( $signing_secret ) ) {
			return false;
		}

		$received_signature = $request->get_header( 'Revolut-Pay-Payload-Signature' );

		if ( empty( $received_signature ) ) {
			return false;
		}

		$calculated_signature = hash_hmac( 'sha256', $request->get_body(), $signing_secret );

		return hash_equals( $calculated_signature, $received_signature );
	}

	/**
	 * Permissions check
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @param string          $signing_secret secret key for signing the request.
	 *
	 * @return bool
	 */
	public function revolut_webhook_permission_callback( $request, $signing_secret ) {
		if ( empty( $signing_secret ) ) {
			return false;
		}

		$request_timestamp  = $request->get_header( 'Revolut-Request-Timestamp' );
		$received_signature = $request->get_header( 'Revolut-Signature' );

		if ( empty( $request_timestamp ) || empty( $received_signature ) ) {
			return false;
		}

		$payload_to_sign = 'v1.' . $request_timestamp . '.' . $request->get_body();

		$calculated_signature = 'v1=' . hash_hmac( 'sha256', $payload_to_sign, $signing_secret );

		return hash_equals( $calculated_signature, $received_signature );
	}

	/**
	 * Revolut webhook callback request
	 *
	 * @param WP_REST_Request $request WP REST Request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_revolut_address_validation_webhook( $request ) {
		$parameters = $request->get_params();

		$this->convert_revolut_order_metadata_into_wc_session( $parameters['order_id'] );

		$requested_address              = $parameters['shipping_address'];
		$requested_address['address']   = $requested_address['street_line_1'];
		$requested_address['address_2'] = '';
		$requested_address['state']     = ! empty( $requested_address['region'] ) ? $requested_address['region'] : '';

		$country  = $requested_address['country'];
		$postcode = $requested_address['postcode'];

		$postcode          = wc_format_postcode( $postcode, $country );
		$is_valid_postcode = WC_Validation::is_postcode( $postcode, $country );

		if ( ! $is_valid_postcode ) {
			$this->log_info( 'Invalid postcode info: ' . $postcode );

			return new WP_REST_Response(
				array(
					'valid'            => false,
					'delivery_methods' => array(),
				),
				200
			);
		}

		$shipping_options = $this->get_shipping_options( $requested_address );

		return new WP_REST_Response(
			array(
				'valid'            => (bool) count( $shipping_options ),
				'delivery_methods' => $shipping_options,
			),
			200
		);
	}

	/**
	 * Revolut webhook callback request
	 *
	 * @param WP_REST_Request $request WP REST Request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_revolut_webhook( $request ) {
		$parameters = $request->get_params();
		$order_id   = '';

		$this->log_info( 'start handle_revolut_webhook_callbacks' );
		$this->log_info( $parameters );

		if ( isset( $parameters['order_id'] ) && isset( $parameters['event'] ) && 'ORDER_COMPLETED' === $parameters['event'] ) {
			$order_id = $parameters['order_id'];
		}

		if ( empty( $order_id ) ) {
			$parameters = $request->get_body();
			$parameters = json_decode( $parameters, true );
			if ( isset( $parameters['order_id'] ) && isset( $parameters['event'] ) ) {
				$order_id = $parameters['order_id'];
			}
		}

		if ( empty( $order_id ) ) {
			return new WP_REST_Response( array( 'status' => 'Failed' ), 400 );
		}

		$wc_order_id = $this->get_wc_order_id( $order_id );

		if ( empty( $wc_order_id ) || empty( $wc_order_id['wc_order_id'] ) ) {
			return new WP_REST_Response( array( 'status' => 'Failed' ), 404 );
		}

		// force webhook callback to wait, in order to be sure that the main payment process has ended.
		$wait_for_main_process_time = ( WC_REVOLUT_WAIT_FOR_ORDER_TIME * 4 );
		sleep( $wait_for_main_process_time );

		$wc_order = wc_get_order( $wc_order_id['wc_order_id'] );

		if ( ! $wc_order || ! empty( $wc_order->get_transaction_id() ) ) {
			return new WP_REST_Response( array( 'status' => 'Failed' ), 422 );
		}

		$wc_order_status = empty( $wc_order->get_status() ) ? '' : $wc_order->get_status();
		$check_wc_status = 'processing' === $wc_order_status || 'completed' === $wc_order_status;
		$check_capture   = isset( $wc_order->get_meta( 'revolut_capture' )[0] ) ? $wc_order->get_meta( 'revolut_capture' )[0] : '';

		$data = array();

		if ( 'yes' === $check_capture || $check_wc_status ) {
			return new WP_REST_Response( array( 'status' => 'Failed' ), 422 );
		}

		/* translators: %s: Revolut Order ID. */
		$wc_order->add_order_note( sprintf( __( 'Payment has been successfully captured (Order ID: %s)', 'revolut-gateway-for-woocommerce' ), $order_id ) );
		$wc_order->payment_complete( $order_id );
		$wc_order->update_meta_data( 'revolut_capture', 'yes', $wc_order_id['wc_order_id'] );
		$wc_order->save();

		$data = array(
			'status'   => 'OK',
			'response' => 'Completed',
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Revolut webhook callback request
	 *
	 * @param WP_REST_Request $request WP REST Request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function handle_revolut_webhook_deprecated_endpoint( $request ) {
		$parameters = $request->get_params();

		if ( in_array( 'shipping_address', array_keys( $parameters ), true ) ) {
			return $this->handle_revolut_address_validation_webhook( $request );
		}

		return $this->handle_revolut_webhook( $request );
	}

	/**
	 * Get Woocommerce Order ID
	 *
	 * @param String $order_id Revolut order id.
	 *
	 * @return array|object|void|null
	 */
	public function get_wc_order_id( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT wc_order_id FROM ' . $wpdb->prefix . "wc_revolut_orders WHERE order_id=UNHEX(REPLACE(%s, '-', ''))", array( $order_id ) ), ARRAY_A ); // db call ok; no-cache ok.
	}
}
