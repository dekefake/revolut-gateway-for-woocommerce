<?php
/**
 * Revolut Pay Woo blocks checkout handler
 *
 * @package    Revolut
 * @category   Payment Gateways
 * @author     Revolut
 * @since      4.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once REVOLUT_PATH . 'includes/traits/wc-revolut-logger-trait.php';

/**
 * WC_Gateway_Revolut_Pay_Blocks_Support class.
 */
class WC_Gateway_Revolut_Pay_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	/**
	 * Gateway.
	 *
	 * @var WC_Gateway_Revolut_Pay
	 */
	private $gateway;

	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Constructor
	 *
	 * @param object $gateway Gateway object.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
		$this->name    = $this->gateway->id;
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this->gateway, 'blocks_checkout_processor' ), 10, 2 );
	}

	/**
	 * Initializes the payment gateway
	 */
	public function initialize() {
		$this->settings          = get_option( 'woocommerce_revolut_pay_settings', array() );
		$this->settings['title'] = wp_kses_post( $this->gateway->custom_revolut_pay_label( $this->gateway->title, $this->gateway->id ) );
	}

	/**
	 * Fetches gateway status
	 */
	public function is_active() {
		try {
			if ( ! $this->gateway->is_available() ) {
				return false;
			}

			$payment_method_locations = $this->gateway->get_option( 'revolut_pay_button_locations', array() );

			if ( is_cart() && in_array( 'cart', $payment_method_locations, true ) ) {
				return $this->gateway->is_shipping_required();
			}

			if ( is_checkout() ) {
				return true;
			}
			return false;
		} catch ( Exception $e ) {
			$this->gateway->log_error( 'revolut-pay-block-is_active : ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Registers gateway frontend assets
	 */
	public function get_payment_method_script_handles() {
		$assets = require REVOLUT_PATH . 'client/dist/index.asset.php';
		wp_register_script(
			'wc-revolut-blocks-integration',
			plugin_dir_url( __DIR__ ) . 'client/dist/index.js',
			$assets['dependencies'],
			$assets['version'],
			true
		);

		return array( 'wc-revolut-blocks-integration' );
	}

	/**
	 * Prepares gateway data to be available to FE
	 */
	public function get_payment_method_data() {

		try {
			$descriptor = new WC_Revolut_Order_Descriptor( WC()->cart->get_total( '' ), get_woocommerce_currency(), null );
			return array_merge(
				$this->settings,
				array(
					'payment_method_name'           => $this->name,
					'locale'                        => $this->gateway->get_lang_iso_code(),
					'can_make_payment'              => $this->is_active(),
					'merchant_public_key'           => $this->gateway->get_merchant_public_api_key(),
					'order_currency'                => $descriptor->currency,
					'order_total_amount'            => $descriptor->amount,
					'wc_revolut_plugin_url'         => WC_REVOLUT_PLUGIN_URL,
					'available_card_brands'         => array_merge( array( 'revolut' ), $this->gateway->get_available_card_brands( $descriptor->amount, $descriptor->currency ) ),
					'create_revolut_order_nonce'    => wp_create_nonce( 'wc-revolut-create-order' ),
					'create_revolut_order_endpoint' => get_site_url() . '/?wc-ajax=wc_revolut_create_order',
					'process_order_endpoint'        => get_site_url() . '/?wc-ajax=wc_revolut_process_payment_result',
					'mobile_redirect_url'           => $this->gateway->get_redirect_url(),
				)
			);
		} catch ( Exception $e ) {
			$this->gateway->log_error( $e->getMessage() );
			return array(
				'payment_method_name' => $this->name,
				'can_make_payment'    => false,
			);
		}

	}
}
