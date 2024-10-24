<?php
/**
 * Revolut CC Woo blocks checkout handler
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
 * WC_Gateway_Revolut_CC_Blocks_Support class.
 */
class WC_Gateway_Revolut_CC_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Gateway.
	 *
	 * @var WC_Gateway_Revolut_CC
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
		$this->settings = get_option( 'woocommerce_revolut_cc_settings', array() );
	}

	/**
	 * Fetches gateway status
	 */
	public function is_active() {
		try {
			return $this->gateway->is_available();
		} catch ( Exception $e ) {
			$this->gateway->log_error( 'cc-block-is_active: ' . $e->getMessage() );
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
	 * Prepares gateway data to be available in FE
	 */
	public function get_payment_method_data() {

		try {

			if ( ! is_checkout() ) {
				return;
			}
			$order_descriptor = new WC_Revolut_Order_Descriptor( WC()->cart->get_total( '' ), get_woocommerce_currency(), null );
			return array_merge(
				$this->settings,
				array(
					'payment_method_name'                => $this->name,
					'locale'                             => $this->gateway->get_lang_iso_code(),
					'can_make_payment'                   => $this->gateway->is_available(),
					'wc_revolut_plugin_url'              => WC_REVOLUT_PLUGIN_URL,
					'available_card_brands'              => $this->gateway->get_available_card_brands( $order_descriptor->amount, $order_descriptor->currency ),
					'order_total_amount'                 => $order_descriptor->amount,
					'update_order_total_amount_nonce'    => wp_create_nonce( 'wc-revolut-update-order-total' ),
					'create_revolut_order_nonce'         => wp_create_nonce( 'wc-revolut-create-order' ),
					'create_revolut_order_endpoint'      => get_site_url() . '/?wc-ajax=wc_revolut_create_order',
					'update_order_total_amount_endpoint' => get_site_url() . '/?wc-ajax=wc_revolut_update_order_total',
					'process_order_endpoint'             => get_site_url() . '/?wc-ajax=wc_revolut_process_payment_result',
					'is_save_payment_method_mandatory'   => $this->gateway->cart_contains_subscription(),
					'card_holder_name_field_enabled'     => 'yes' === $this->gateway->get_option( 'enable_cardholder_name', 'yes' ),
					'merchant_public_token'              => $this->gateway->get_merchant_public_api_key(),
					'mode'                               => $this->gateway->get_mode(),
					'promotional_banner_enabled'         => $this->gateway->promotional_settings->upsell_banner_enabled(),
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
