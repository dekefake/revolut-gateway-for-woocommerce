<?php
/**
 * Revolut Payment Request
 *
 * Provides a gateway to accept payments through Apple and Google Pay.
 *
 * @package WooCommerce
 * @category Payment Gateways
 * @author Revolut
 * @since 3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Revolut_Payment_Request class.
 */
class WC_Gateway_Revolut_Payment_Request extends WC_Payment_Gateway_Revolut {

	const GATEWAY_ID = 'revolut_payment_request';
	/**
	 * Constructor
	 */
	public function __construct() {

		$this->id = self::GATEWAY_ID;

		$this->method_title = __( 'Apple Pay / Google Pay', 'revolut-gateway-for-woocommerce' );
		/* translators:%1s: %$2s: */
		$this->method_description = sprintf( __( 'Accept Apple and Google payments easily and securely via %1$sRevolut%2$s.', 'revolut-gateway-for-woocommerce' ), '<a href="https://www.revolut.com/business/online-payments">', '</a>' );
		$this->tab_title          = __( 'Apple Pay / Google Pay', 'revolut-gateway-for-woocommerce' );

		$this->title = __( 'Digital Wallet (ApplePay/GooglePay)', 'revolut-gateway-for-woocommerce' );

		parent::__construct();

		add_filter( 'wc_revolut_settings_nav_tabs', array( $this, 'admin_nav_tab' ), 2 );
		add_action( 'wc_ajax_revolut_payment_request_add_to_cart', array( $this, 'revolut_payment_request_ajax_add_to_cart' ) );
		add_action( 'wc_ajax_revolut_payment_request_get_shipping_options', array( $this, 'revolut_payment_request_ajax_get_shipping_options' ) );
		add_action( 'wc_ajax_revolut_payment_request_update_shipping_method', array( $this, 'revolut_payment_request_ajax_update_shipping_method' ) );
		add_action( 'wc_ajax_revolut_payment_request_update_payment_total', array( $this, 'revolut_payment_request_update_revolut_order_with_cart_total' ) );
		add_action( 'wc_ajax_revolut_payment_request_create_order', array( $this, 'revolut_payment_request_ajax_create_order' ) );
		add_action( 'wc_ajax_revolut_payment_request_get_payment_request_params', array( $this, 'revolut_payment_request_ajax_get_payment_request_params' ) );

		if ( 'yes' !== $this->enabled || $this->api_settings->get_option( 'mode' ) === 'sandbox' ) {
			return;
		}

		add_action( 'woocommerce_after_add_to_cart_quantity', array( $this, 'display_payment_request_button_html' ), 2 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'display_payment_request_button_html' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'revolut_enqueue_payment_request_scripts' ) );
	}

	/**
	 * Get express checkout params
	 */
	public function revolut_payment_request_ajax_get_payment_request_params() {
		check_ajax_referer( 'wc-revolut-get-payment-request-params', 'security' );

		try {
			wp_send_json(
				array(
					'success'           => true,
					'revolut_public_id' => $this->create_express_checkout_public_id(),
					'checkout_nonce'    => wp_create_nonce( 'woocommerce-process_checkout' ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json( array( 'success' => false ) );
			$this->log_error( $e );
		}
	}

	/**
	 * Add required scripts
	 */
	public function revolut_enqueue_payment_request_scripts() {
		try {
			wp_localize_script(
				'revolut-woocommerce',
				'revolut_payment_request_button_style',
				array(
					'payment_request_button_title'  => $this->get_option( 'title' ),
					'payment_request_button_type'   => $this->get_option( 'payment_request_button_type' ),
					'payment_request_button_theme'  => $this->get_option( 'payment_request_button_theme' ),
					'payment_request_button_radius' => $this->get_option( 'payment_request_button_radius' ),
					'payment_request_button_size'   => $this->get_option( 'payment_request_button_size' ),
				)
			);

			if ( ! $this->page_supports_payment_request_button( $this->get_option( 'payment_request_button_locations' ) ) ) {
				return false;
			}

			wp_register_script( 'revolut-core', $this->api_client->base_url . '/embed.js', false, WC_GATEWAY_REVOLUT_VERSION, true );
			wp_register_script(
				'revolut-woocommerce-payment-request',
				plugins_url( 'assets/js/revolut-payment-request.js', WC_REVOLUT_MAIN_FILE ),
				array(
					'revolut-core',
					'jquery',
				),
				WC_GATEWAY_REVOLUT_VERSION,
				true
			);

			wp_localize_script(
				'revolut-woocommerce-payment-request',
				'wc_revolut_payment_request_params',
				$this->get_wc_revolut_payment_request_params()
			);

			wp_enqueue_script( 'revolut-woocommerce-payment-request' );
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
		}
	}

	/**
	 * Check if the Revolut Pay Fast checkout payments active.
	 */
	public function is_revolut_pay_fast_checkout_active() {
		$revolut_cc_gateway_options = get_option( 'woocommerce_revolut_pay_settings' );
		return isset( $revolut_cc_gateway_options['revolut_pay_button_locations'] ) && $this->page_supports_payment_request_button( $revolut_cc_gateway_options['revolut_pay_button_locations'] ) && $this->is_shipping_required();
	}

	/**
	 * Update Revolut order with cart total amount
	 *
	 * @param string $revolut_public_id Revolut public id.
	 *
	 * @param bool   $is_revolut_pay indicator.
	 *
	 * @throws Exception Exception.
	 */
	public function update_revolut_order_with_cart_total( $revolut_public_id, $is_revolut_pay = false ) {
		$revolut_order_id             = $this->get_revolut_order_by_public_id( $revolut_public_id );
		$revolut_order                = $this->api_client->get( "/orders/$revolut_order_id" );
		$revolut_order_shipping_total = $this->get_revolut_order_total_shipping( $revolut_order );

		$cart_subtotal = round( (float) ( WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() + $revolut_order_shipping_total ), 2 );

		$this->log_info(
			array(
				'is_revolut_pay'              => $is_revolut_pay,
				'cart_total_without_shipping' => $cart_subtotal,
				'cart_total'                  => WC()->cart->get_total( '' ),
				'wc_shipping_total'           => WC()->cart->get_shipping_total(),
				'revolut_shipping_total'      => $revolut_order_shipping_total,
			)
		);

		$descriptor = new WC_Revolut_Order_Descriptor( $is_revolut_pay ? $cart_subtotal : WC()->cart->get_total( '' ), get_woocommerce_currency(), null );
		$public_id  = $this->update_revolut_order( $descriptor, $revolut_public_id, true );
		if ( $public_id !== $revolut_public_id ) {
			throw new Exception( 'Can not update the Order' );
		}
		$revolut_order_id = $this->get_revolut_order_by_public_id( $public_id );
		$revolut_order    = $this->api_client->get( "/orders/$revolut_order_id" );
		$this->log_info( 'revolut_order' );
		$this->log_info( $revolut_order );

		return $this->get_revolut_order_amount( $revolut_order );
	}

	/**
	 * Check is payment method available
	 */
	public function is_available() {
		if ( ( 'yes' === $this->enabled && is_product() ) || ( $this->check_is_post_data_submitted( 'payment_method' ) && $this->get_post_request_data( 'payment_method' ) === $this->id ) ) {
			return true;
		}

		$payment_request_button_locations = $this->get_option( 'payment_request_button_locations' );

		if ( empty( $payment_request_button_locations ) ) {
			$payment_request_button_locations = array();
		}

		if ( is_checkout() ) {
			return in_array( 'checkout', $payment_request_button_locations, true ) && ! $this->api_settings->is_sandbox();
		}

		return false;
	}

	/**
	 * Initialize Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                          => array(
				'title'       => __( 'Enable/Disable', 'revolut-gateway-for-woocommerce' ),
				'label'       => sprintf(
				/* translators:%1s: %$2s: %3$s: %4$s: */
					__( 'Enable Payment Request Buttons. (Apple Pay/Google Pay) %1$sBy using Apple Pay, you agree with %2$sApple\'s%3$s terms of service. (Apple Pay domain verification is performed automatically in live mode.) %4$s', 'revolut-gateway-for-woocommerce' ),
					'<br />',
					'<a href="https://www.revolut.com/legal/payment-terms-applepay" target="_blank">',
					'</a>',
					$this->check_authentication_required() ? '<br /> <p style="color:red">' . __( 'Payment with Apple/Google Pay buttons wont be possible for guest users until Guest checkout is enabled', 'revolut-gateway-for-woocommerce' ) . '</p>' : ''
				),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, users will be able to pay using Apple Pay (Safari & iOS), Google Pay (Chrome & Android) or native W3C Payment Requests if supported by the browser.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'title'                            => array(
				'title'       => __( 'Title', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title that the user sees during checkout. Plugin will add the button\'s name (Apple Pay or Google Pay) before this title.', 'revolut-gateway-for-woocommerce' ),
				'default'     => '(via Revolut)',
				'desc_tip'    => true,
			),
			'payment_request_button_type'      => array(
				'title'       => __( 'Payment Request Button Action', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Action', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button type you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'buy',
				'desc_tip'    => true,
				'options'     => array(
					'buy'    => __( 'Buy', 'revolut-gateway-for-woocommerce' ),
					'donate' => __( 'Donate', 'revolut-gateway-for-woocommerce' ),
					'pay'    => __( 'Pay', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_theme'     => array(
				'title'       => __( 'Payment Request Button Theme', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Theme', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button theme you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'dark',
				'desc_tip'    => true,
				'options'     => array(
					'dark'           => __( 'Dark', 'revolut-gateway-for-woocommerce' ),
					'light'          => __( 'Light', 'revolut-gateway-for-woocommerce' ),
					'light-outlined' => __( 'Light-Outline', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_radius'    => array(
				'title'       => __( 'Payment Request Button Radius', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Radius', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button radius you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'none',
				'desc_tip'    => true,
				'options'     => array(
					'none'  => __( 'None', 'revolut-gateway-for-woocommerce' ),
					'small' => __( 'Small', 'revolut-gateway-for-woocommerce' ),
					'large' => __( 'Large', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_size'      => array(
				'title'       => __( 'Payment Request Button Size', 'revolut-gateway-for-woocommerce' ),
				'label'       => __( 'Button Size', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Select the button size you would like to show.', 'revolut-gateway-for-woocommerce' ),
				'default'     => 'large',
				'desc_tip'    => true,
				'options'     => array(
					'small' => __( 'Small', 'revolut-gateway-for-woocommerce' ),
					'large' => __( 'Large', 'revolut-gateway-for-woocommerce' ),
				),
			),
			'payment_request_button_locations' => array(
				'title'             => __( 'Payment Request Button Locations', 'revolut-gateway-for-woocommerce' ),
				'type'              => 'multiselect',
				'description'       => __( 'Select where you would like Payment Request Buttons to be displayed', 'revolut-gateway-for-woocommerce' ),
				'desc_tip'          => true,
				'class'             => 'wc-enhanced-select',
				'options'           => array(
					'product'  => __( 'Product', 'revolut-gateway-for-woocommerce' ),
					'cart'     => __( 'Cart', 'revolut-gateway-for-woocommerce' ),
					'checkout' => __( 'Checkout', 'revolut-gateway-for-woocommerce' ),
				),
				'default'           => array( 'product', 'cart' ),
				'custom_attributes' => array(
					'data-placeholder' => __( 'Select pages', 'revolut-gateway-for-woocommerce' ),
				),
			),
		);

		if ( $this->get_option( 'apple_pay_merchant_onboarded' ) === 'no' ) {
			$this->form_fields['onboard_applepay'] = array(
				'title'       => __( 'Onboard shop domain for Apple Pay', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => '<button class="setup-applepay-domain" style="min-height: 30px;">Setup</button>
                                    <p class="setup-applepay-domain-error" style="color:red;display: none"></p>
            					<p>Seems that there is a problem automatically onboarding your website for Apple Pay. You can also set this up manually by downloading this <a href="https://assets.revolut.com/api-docs/merchant-api/files/domain_validation_file_prod">file</a> and adding it to the root folder of your shop with the following uri <code>/.well-known/apple-developer-merchantid-domain-association</code>. To learn more about how to do this, visit our <a href="https://developer.revolut.com/docs/accept-payments/plugins/woocommerce/features#set-up-apple-pay-manually" target="_blank">documentation</a></p>',
			);
		}
	}

	/**
	 * Supported functionality
	 */
	public function init_supports() {
		parent::init_supports();
		$this->supports[] = 'refunds';
	}

	/**
	 * Display payment request button html
	 */
	public function display_payment_request_button_html() {
		if ( ! $this->page_supports_payment_request_button( $this->get_option( 'payment_request_button_locations' ) ) ) {
			return false;
		}

		if ( $this->is_revolut_pay_fast_checkout_active() ) {
			?>
			<div class="wc-revolut-payment-request-instance" id="wc-revolut-payment-request-container" style="clear:both;">
			<div id="revolut-payment-request-button"></div>
			<p id="wc-revolut-payment-request-button-separator" style="margin-top:1.5em;text-align:center;">&mdash;&nbsp;<?php echo esc_html( __( 'OR', 'revolut-gateway-for-woocommerce' ) ); ?>
				&nbsp;&mdash;</p>
		</div>
			<?php
			return;
		}

		?>
		<div class="wc-revolut-payment-request-instance" id="wc-revolut-payment-request-container" style="clear:both;padding-top:1.5em;">
			<div id="revolut-payment-request-button"></div>
			<p id="wc-revolut-payment-request-button-separator" style="margin-top:1.5em;text-align:center;">&mdash;&nbsp;<?php echo esc_html( __( 'OR', 'revolut-gateway-for-woocommerce' ) ); ?>
				&nbsp;&mdash;</p>
		</div>
		<?php
	}

	/**
	 * Ajax endpoint in order to create WooCommerce order
	 */
	public function revolut_payment_request_ajax_create_order() {

		if ( WC()->cart->is_empty() ) {
			wp_send_json_error( __( 'Empty cart', 'revolut-gateway-for-woocommerce' ) );
		}

		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		$errors = new WP_Error();

		try {
			$wc_order_data = $this->get_wc_order_details();
		} catch ( Exception $e ) {
			$this->log_error( $e->getMessage() );
			$errors->add( 'payment', __( 'Something went wrong', 'woocommerce' ) );
			if ( ! empty( $this->get_post_request_data( 'revolut_payment_error' ) ) ) {
				$errors->add( 'payment', $this->get_post_request_data( 'revolut_payment_error' ) );
				$this->log_error( $this->get_post_request_data( 'revolut_payment_error' ) );
			}
		}

		foreach ( $errors->errors as $code => $messages ) {
			$data = $errors->get_error_data( $code );
			foreach ( $messages as $message ) {
				wc_add_notice( $message, 'error', $data );
			}
		}

		if ( 0 === wc_notice_count( 'error' ) ) {
			$_POST = array_merge( $_POST, $wc_order_data ); // phpcs:ignore
			unset( $_POST['address_info'] ); // phpcs:ignore
			$_POST['_wpnonce'] = wp_create_nonce( 'woocommerce-process_checkout' );
			WC()->checkout()->process_checkout();
		}

		$messages = wc_print_notices( true );

		wp_send_json(
			array(
				'result'   => 'failure',
				'messages' => $messages,
			)
		);
	}

	/**
	 * Get order details
	 *
	 * @throws Exception Exception.
	 */
	public function get_wc_order_details() {
		$public_id = $this->get_post_request_data( 'revolut_public_id' );

		if ( empty( $public_id ) ) {
			throw new Exception( 'Public ID is missing for the session' );
		}

		$order_id = $this->get_revolut_order_by_public_id( $public_id );

		if ( empty( $order_id ) ) {
			throw new Exception( 'Can not find revolut order id' );
		}

		$address_info = $this->get_post_request_data( 'address_info' );

		if ( empty( $address_info ) ) {
			throw new Exception( 'Address information is missing' );
		}

		return $this->format_wc_order_details(
			$this->get_post_request_data( 'address_info' ),
			$this->get_posted_integer_data( 'shipping_required' ),
			$this->get_post_request_data( 'revolut_gateway' )
		);
	}

	/**
	 * Ajax endpoint for adding product to cart.
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_ajax_add_to_cart() {
		try {
			check_ajax_referer( 'wc-revolut-pr-add-to-cart', 'security' );

			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			WC()->shipping->reset_shipping();

			$product_id     = $this->get_posted_integer_data( 'product_id' );
			$is_revolut_pay = $this->get_posted_integer_data( 'is_revolut_pay' );
			$qty            = ! $this->check_is_post_data_submitted( 'qty' ) ? 1 : $this->get_posted_integer_data( 'qty' );
			$product        = wc_get_product( $product_id );
			$product_type   = $product->get_type();
			$global_cart    = WC()->cart;

			if ( ! $this->get_posted_integer_data( 'add_to_cart' ) ) {
				WC()->cart = clone WC()->cart;
			}

			WC()->cart->empty_cart();

			if ( 'simple' === $product_type || 'subscription' === $product_type ) {
				WC()->cart->add_to_cart( $product->get_id(), $qty );
			} elseif ( $this->check_is_post_data_submitted( 'attributes' ) && ( 'variable' === $product_type || 'variable-subscription' === $product_type ) ) {
				$attributes   = $this->get_post_request_data( 'attributes' );
				$data_store   = WC_Data_Store::load( 'product' );
				$variation_id = $data_store->find_matching_product_variation( $product, $attributes );
				WC()->cart->add_to_cart( $product->get_id(), $qty, $variation_id, $attributes );
			}

			WC()->shipping->reset_shipping();
			WC()->cart->calculate_totals();

			$total_amount  = $this->update_revolut_order_with_cart_total( $revolut_public_id, $is_revolut_pay );
			$is_cart_empty = ! WC()->cart->is_empty();

			if ( ! $this->get_posted_integer_data( 'add_to_cart' ) ) {
				WC()->cart = $global_cart;
			}

			$data['total']['amount'] = $total_amount;
			$data['checkout_nonce']  = wp_create_nonce( 'woocommerce-process_checkout' );
			$data['status']          = 'success';
			$data['success']         = $is_cart_empty;
			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']  = 'fail';
			$data['success'] = false;
			wp_send_json( $data );
		}
	}

	/**
	 * Ajax endpoint for listing shipping options
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_ajax_get_shipping_options() {
		check_ajax_referer( 'wc-revolut-payment-request-shipping', 'security' );

		try {

			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			$shipping_address = filter_input_array(
				INPUT_POST,
				array(
					'country'   => FILTER_SANITIZE_STRING,
					'state'     => FILTER_SANITIZE_STRING,
					'postcode'  => FILTER_SANITIZE_STRING,
					'city'      => FILTER_SANITIZE_STRING,
					'address'   => FILTER_SANITIZE_STRING,
					'address_2' => FILTER_SANITIZE_STRING,
				)
			);

			$shipping_options = $this->get_shipping_options( $shipping_address );

			if ( count( $shipping_options ) > 0 ) {
				$this->update_shipping_method( $shipping_options[0] );
			}

			$total_amount = $this->update_revolut_order_with_cart_total( $revolut_public_id );

			$data['success']         = true;
			$data['status']          = 'success';
			$data['total']['amount'] = $total_amount;
			$data['shippingOptions'] = $shipping_options;

			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']          = 'fail';
			$data['success']         = false;
			$data['total']['amount'] = 0;
			$data['shippingOptions'] = array();
			wp_send_json( $data );
		}
	}

	/**
	 * Ajax endpoint for updating shipping options
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_ajax_update_shipping_method() {
		check_ajax_referer( 'wc-revolut-update-shipping-method', 'security' );

		try {
			if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
				define( 'WOOCOMMERCE_CART', true );
			}

			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			$shipping_method = filter_input( INPUT_POST, 'shipping_method', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
			$this->update_shipping_method( $shipping_method );

			WC()->cart->calculate_totals();

			$total_amount = $this->update_revolut_order_with_cart_total( $revolut_public_id );

			$data['success']         = true;
			$data['status']          = 'success';
			$data['total']['amount'] = $total_amount;
			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']          = 'fail';
			$data['success']         = false;
			$data['total']['amount'] = 0;
			wp_send_json( $data );
		}
	}

	/**
	 * Ajax endpoint for updating shipping options
	 *
	 * @throws Exception Exception.
	 */
	public function revolut_payment_request_update_revolut_order_with_cart_total() {
		check_ajax_referer( 'wc-revolut-update-order-total', 'security' );

		try {
			$revolut_public_id = $this->get_post_request_data( 'revolut_public_id' );

			if ( empty( $revolut_public_id ) ) {
				throw new Exception( 'Can not get required parameter' );
			}

			WC()->cart->calculate_totals();

			$total_amount = $this->update_revolut_order_with_cart_total( $revolut_public_id, true );

			$data['success']         = true;
			$data['status']          = 'success';
			$data['total']['amount'] = $total_amount;
			wp_send_json( $data );
		} catch ( Exception $e ) {
			$this->log_error( $e );
			$data['status']          = 'fail';
			$data['success']         = false;
			$data['total']['amount'] = 0;
			wp_send_json( $data );
		}
	}

	/**
	 * Display Revolut Pay icon
	 */
	public function get_icon() {
		$icons_str = '';

		$icons_str .= '<img src="' . WC_REVOLUT_PLUGIN_URL . '/assets/images/apple-pay-logo.svg" class="revolut-apple-pay-logo" style="max-width:50px;display:none" alt="Apple Pay" />';
		$icons_str .= '<img src="' . WC_REVOLUT_PLUGIN_URL . '/assets/images/g-pay-logo.png" class="revolut-google-pay-logo" style="max-width:50px;display:none" alt="Google Pay" />';

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}

	/**
	 * Add public_id field and logo on card form
	 *
	 * @param String $public_id            Revolut public id.
	 * @param String $merchant_public_key  Revolut public key.
	 * @param String $display_tokenization Available saved card tokens.
	 *
	 * @return string
	 */
	public function generate_inline_revolut_form( $public_id, $merchant_public_key, $display_tokenization ) {
		$total          = WC()->cart->get_total( '' );
		$currency       = get_woocommerce_currency();
		$total          = $this->get_revolut_order_total( $total, $currency );
		$mode           = $this->api_settings->get_option( 'mode' );
		$shipping_total = $this->get_cart_total_shipping();

		return '<div id="woocommerce-revolut-payment-request-element" class="revolut-payment-request" data-mode="' . $mode . '" data-shipping-total="' . $shipping_total . '" data-currency="' . $currency . '" data-total="' . $total . '" data-textcolor="" data-locale="' . $this->get_lang_iso_code() . '" data-public-id="' . $public_id . '"  data-merchant-public-key="' . $merchant_public_key . '"></div>
		<input type="hidden" id="wc_' . $this->id . '_payment_nonce" name="wc_' . $this->id . '_payment_nonce" />';
	}

}
