<?php
/**
 * Revolut Api Settings
 *
 * Provides configuration for API settings
 *
 * @package WooCommerce
 * @category Payment Gateways
 * @author Revolut
 * @since 4.17.6
 */

/**
 * WC_Revolut_Promotional_Settings class.
 */
class WC_Revolut_Promotional_Settings extends WC_Revolut_Settings_API {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id        = 'revolut_promotional_tab';
		$this->tab_title = __( 'Rewards & Promotions', 'revolut-gateway-for-woocommerce' );
		$this->init_form_fields();
		$this->init_settings();
		$this->hooks();
	}

	/**
	 * Add required filters
	 */
	public function hooks() {
		add_action( 'woocommerce_settings_checkout', array( $this, 'admin_options' ) );
		add_filter( 'wc_revolut_settings_nav_tabs', array( $this, 'admin_nav_tab' ), 10 );
		add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'process_admin_options' ) );
	}

		/**
		 * Displays configuration page with tabs
		 */
	public function admin_options() {
		if ( $this->check_is_get_data_submitted( 'page' ) && $this->check_is_get_data_submitted( 'section' ) ) {
			$is_revolut_api_section = 'wc-settings' === $this->get_request_data( 'page' ) && $this->id === $this->get_request_data( 'section' );

			if ( $is_revolut_api_section ) {
				echo wp_kses_post( '<table class="form-table">' );
				$this->generate_settings_html( $this->get_form_fields(), true );
				echo wp_kses_post( '</table>' );
			}
		}
	}

	/**
	 * Initialize Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'title'                         => array(
				'type'  => 'title',
				'title' => __( 'Revolut Gateway - Rewards & Promotions Settings', 'revolut-gateway-for-woocommerce' ),
			),
			'gateway_upsell_banner_enabled' => array(
				'title'       => 'Sign up banner',
				'label'       => __( 'Offer your customers to join Revolut where they will receive exclusive rewards for signing up', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => 'This will allow them to pay via Revolut Pay the next time they visit your store and checkout faster.',
				'default'     => 'yes',
			),
			'revolut_pay_label_icon'        => array(
				'title'       => 'Revolut Pay informational icon',
				'description' => __( 'Displays an icon or a "Learn more" link which opens a pop-up with details on the Revolut Pay payment process and benefits', 'revolut-gateway-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'options'     => array(
					'icon'     => __( 'Small icon', 'revolut-gateway-for-woocommerce' ),
					'link'     => __( 'Learn more', 'revolut-gateway-for-woocommerce' ),
					'cashback' => __( 'Get cashback', 'revolut-gateway-for-woocommerce' ),
					'disabled' => __( 'Disabled', 'revolut-gateway-for-woocommerce' ),

				),
				'default'     => 'link',
			),
		);
	}

	/**
	 * Returns upsell banner availability
	 */
	public function upsell_banner_enabled() {
		return 'yes' === $this->get_option( 'gateway_upsell_banner_enabled' );
	}

	/**
	 * Returns revpoints banner availability
	 */
	public function revpoints_banner_enabled() {
		return false;
	}

	/**
	 * Returns RPay label icon
	 */
	public function revolut_pay_label_icon_variant() {
		$label_variant = $this->get_option( 'revolut_pay_label_icon' );
		if ( 'disabled' === $label_variant ) {
			return false;
		}

		return $label_variant;
	}
}
