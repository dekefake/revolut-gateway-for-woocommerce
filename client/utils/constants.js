export const { CART_STORE_KEY, PAYMENT_STORE_KEY } = window.wc.wcBlocksData
export const PAYMENT_METHODS = {
  REVOLUT_CARD: 'revolut_cc',
  REVOLUT_PAY: 'revolut_pay',
  REVOLUT_PRB: 'revolut_payment_request',
}

export const CHECKOUT_PAYMENT_CONTEXT = 'wc/checkout/payments'
export const isCartPage =
  typeof wc_revolut_payment_request_params !== 'undefined' &&
  wc_revolut_payment_request_params.is_cart_page

export const REVOLUT_PAY_INFORMATIONAL_BANNER_ID = 'revolut-pay-informational-banner'
export const REVOLUT_PAY_INFORMATIONAL_ICON_ID = 'revolut-pay-label-informational-icon'
export const REVOLUT_GATEWAY_UPSELL_BANNER_ID = 'revolut-upsell-banner'
export const REVOLUT_POINTS_BLOCK_NAME = 'revolut-gateway-for-woocommerce/revolut-banner'