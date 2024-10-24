import { getSetting } from '@woocommerce/settings'

import { __ } from '@wordpress/i18n'

export { select, dispatch } from '@wordpress/data'

export const revolutSettings = paymentMethod => getSetting(`${paymentMethod}_data`)

export const i18n = msg => __(msg, 'revolut-gateway-for-woocommerce')

export const getAjaxURL = (endpoint, controller = 'revolut_payment_request_') => {
  return wc_revolut_payment_request_params.ajax_url
    .toString()
    .replace('%%wc_revolut_gateway_ajax_endpoint%%', `${controller}${endpoint}`)
}

function buildFormData(formData, data, parentKey) {
  const newFormData = formData
  if (data && typeof data === 'object') {
    Object.keys(data).forEach(key => {
      buildFormData(newFormData, data[key], parentKey ? `${parentKey}[${key}]` : key)
    })
  } else {
    const value = data == null ? '' : data
    newFormData.append(parentKey, value)
  }
  return newFormData
}

export const sendAjax = async ({ data, endpoint }) => {
  const requestData = buildFormData(new FormData(), data)
  const response = await fetch(endpoint, {
    method: 'POST',
    body: requestData,
  })
  if (!response.ok) {
    throw new Error('Failed to process your request due to network issue')
  }
  const json = await response.json()
  return json
}

export const createAddress = address => {
  return {
    countryCode: address.country,
    region: address.state,
    city: address.city,
    streetLine1: address.address_1,
    streetLine2: address.address_2,
    postcode: address.postcode,
  }
}