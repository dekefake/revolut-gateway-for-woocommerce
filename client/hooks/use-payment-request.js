import { useRef, useEffect } from '@wordpress/element'
import { revolutSettings, PAYMENT_METHODS } from '../utils'

export const usePaymentRequest = ({ paymentOptions, onSuccess, onError }, deps) => {
  const revolutPrbRef = useRef(null)
  const destroyRef = useRef()
  const settings = revolutSettings(PAYMENT_METHODS.REVOLUT_PRB)

  useEffect(() => {
    const initPaymentRequestButton = async () => {
      const { paymentRequest, destroy } = await RevolutCheckout.payments({
        publicToken: settings.merchant_public_key,
        locale: settings.locale,
      })

      destroyRef.current = destroy

      if (revolutPrbRef.current) {
        const paymentRequestButton = paymentRequest.mount(revolutPrbRef.current, {
          ...paymentOptions,
          onSuccess() {
            onSuccess()
          },
          onError(error) {
            onError(error.message)
          },
          onCancel() {
              onError('Payment cancelled!')
          },
        })

        paymentRequestButton.canMakePayment().then(method => {
          if (method) {
            paymentRequestButton.render()
          } else {
            paymentRequestButton.destroy()
          }
        })
      }
    }

    initPaymentRequestButton()
    return () => destroyRef.current()
  }, deps)

  return {revolutPrbRef, destroyRef}
}
