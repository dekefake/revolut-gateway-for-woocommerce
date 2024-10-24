import { dispatch, sendAjax, getAjaxURL, CART_STORE_KEY, i18n, revolutSettings } from '.'

export const createRevolutExpressOrder = async () => {
  if (typeof wc_revolut_payment_request_params === 'undefined') {
    return Promise.reject(new Error('Unexpected error occurred'))
  }

  const json = await sendAjax({
    endpoint: getAjaxURL('get_express_checkout_params'),
    data: {
      security: wc_revolut_payment_request_params.nonce.get_express_checkout_params,
    },
  })

  if (json?.success) {
    wc_revolut_payment_request_params.revolut_public_id = json.revolut_public_id
    return json.revolut_public_id
  }

  return Promise.reject(new Error('Something went wrong while creating the payment.'))
}

export const createRevolutOrder = async settings => {
  const json = await sendAjax({
    endpoint: settings.create_revolut_order_endpoint,
    data: {
      security: settings.create_revolut_order_nonce,
    },
  })

  if (json?.success) {
    return json
  }

  throw new Error('An unexpected error occurred')
}

export function updateShippingOptions(shippingOption) {
  let shipping_option_data = {
    security: wc_revolut_payment_request_params.nonce.update_shipping,
    shipping_method: [shippingOption.id],
    is_product_page: wc_revolut_payment_request_params.is_product_page,
  }

  return new Promise((resolve, reject) => {
    sendAjax({
      data: shipping_option_data,
      endpoint: getAjaxURL('update_shipping_method'),
    })
      .then(response => {
        resolve(response)
      })
      .catch(error => {
        reject(error)
      })
  })
}

export function getShippingOptions(address) {
  let address_data = {
    security: wc_revolut_payment_request_params.nonce.shipping,
    country: address.country,
    state: address.region,
    postcode: address.postalCode,
    city: address.city,
    address: '',
    address_2: '',
    is_product_page: wc_revolut_payment_request_params.is_product_page,
    require_shipping: wc_revolut_payment_request_params.shipping_required,
  }

  return new Promise((resolve, reject) => {
    sendAjax({
      data: address_data,
      endpoint: getAjaxURL('get_shipping_options'),
    })
      .then(response => {
        resolve(response)
      })
      .catch(error => {
        reject(error)
      })
  })
}

export const loadOrderData = async () => {
  try {
    const json = await sendAjax({
      data: {
        security: wc_revolut_payment_request_params.nonce.load_order_data,
        revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
      },
      endpoint: getAjaxURL('load_order_data'),
    })
    if (json) {
      return json
    }

    throw new Error(
      'Something went wrong while retrieving the billing address. your payment will be cancelled',
    )
  } catch (err) {
    throw new Error(err.message || 'An unexpected error occurred.')
  }
}

export const cancelOrder = async () => {
  const json = await sendAjax({
    data: {
      revolut_public_id: wc_revolut_payment_request_params.revolut_public_id,
      security: wc_revolut_payment_request_params.nonce.cancel_order,
    },
    endpoint: getAjaxURL('cancel_order'),
  })
  return json.success
}

export const handleFailExpressCheckout = async () => {
  try {
    const orderCancelled = await cancelOrder()
    if (orderCancelled) {
      return {
        type: 'error',
        message: i18n('Something went wrong, your order has been cancelled.'),
      }
    }

    throw new Error('Couldn`t cancel the order')
  } catch (err) {
    return {
      type: 'failure',
      message: i18n(
        "Your order has been completed, but we couldn't redirect you to the confirmation page. Please contact us for assistance.",
      ),
    }
  }
}

export const processPayment = async ({
  process_payment_result,
  revolut_public_id,
  shouldSavePayment,
  wc_order_id,
  paymentMethod,
}) => {
  try {
    const settings = revolutSettings(paymentMethod)
    const data = {
      revolut_gateway: paymentMethod,
      security: process_payment_result,
      revolut_public_id: revolut_public_id,
      revolut_payment_error: '',
      wc_order_id: wc_order_id,
      reload_checkout: 0,
      revolut_save_payment_method:
        Number(shouldSavePayment) || Number(settings.is_save_payment_method_mandatory),
    }

    const response = await sendAjax({ data, endpoint: settings.process_order_endpoint })
    if (response?.result === 'fail') {
      throw new Error(
        response?.messages ||
          'Something went wrong while trying to charge your card, please try again',
      )
    }
    if (response?.result === 'success') {
      return response
    }
    throw new Error('Failed to process your order due to server issue')
  } catch (err) {
    throw new Error(err.message || 'An unexpected error occurred')
  }
}

export const onPaymentSuccessHandler = async ({
  response,
  paymentMethod,
  shouldSavePayment,
}) => {
  try {
    const { processingResponse } = response
    const { wc_order_id, revolut_public_id, process_payment_result } =
      processingResponse.paymentDetails

    const processResult = await processPayment({
      wc_order_id,
      revolut_public_id,
      process_payment_result,
      shouldSavePayment,
      paymentMethod,
    })

    if (processResult.redirect) {
      window.location.href = decodeURI(processResult.redirect)
      return {
        type: 'success',
      }
    }

    throw new Error(
      'Could not redirect you to the confirmation page due to an unexpected error. Please contact the merchant',
    )
  } catch (e) {
    return {
      type: 'error',
      message: i18n(e?.message),
      retry: true,
      messageContext: 'wc/checkout/payments',
    }
  }
}

export const submitWoocommerceOrder = async ({ onSubmit }) =>
  new Promise((resolve, reject) =>
    loadOrderData()
      .then(data => {
        const { billingAddress, shippingAddress } = data.address_info
        let firstSpaceIndex = billingAddress.recipient.indexOf(' ')
        let firstName = billingAddress.recipient.substring(0, firstSpaceIndex)
        let lastName = billingAddress.recipient.substring(firstSpaceIndex + 1)

        dispatch(CART_STORE_KEY).setBillingAddress({
          first_name: firstName,
          last_name: lastName,
          address_1: billingAddress.address,
          address_2: billingAddress.address_2,
          city: billingAddress.city,
          state: billingAddress.state,
          postcode: billingAddress.postcode,
          country: billingAddress.country,
          email: data.address_info.email,
          phone: billingAddress.phone,
        })

        dispatch(CART_STORE_KEY).setShippingAddress({
          first_name: firstName,
          last_name: lastName,
          address_1: shippingAddress.address,
          address_2: shippingAddress.address_2,
          city: shippingAddress.city,
          state: shippingAddress.state,
          postcode: shippingAddress.postcode,
          country: shippingAddress.country,
          phone: shippingAddress.phone,
        })
        onSubmit()
        resolve(true)
      })
      .catch(err => reject(err)),
  )
