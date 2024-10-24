import { useRef, useEffect } from '@wordpress/element'
import { revolutSettings, PAYMENT_METHODS } from '../utils'

export const useRevolutPay = ({ paymentOptions, onSuccess, onError, onCancel }, deps) => {
  const revolutPayRef = useRef(null)
  const destroyRef = useRef()
  const settings = revolutSettings(PAYMENT_METHODS.REVOLUT_PAY)

  useEffect(() => {
    const initRevolutPayWidget = async () => {
      const { revolutPay, destroy } = await RevolutCheckout.payments({
        publicToken: settings.merchant_public_key,
        locale: settings.locale,
      })

      destroyRef.current = destroy

      if (revolutPayRef.current) {
        revolutPay.mount(revolutPayRef.current, paymentOptions)
      }

      revolutPay.on('payment', event => {
        switch (event.type) {
          case 'cancel': {
            onCancel()
            break
          }

          case 'success': {
            onSuccess()
            break
          }

          case 'error': {
            onError(event.error.message)
            break
          }
          default:
            break
        }
      })
    }

    initRevolutPayWidget()

    return () => {
      destroyRef.current()
    }
  }, deps)

  return { revolutPayRef, destroyRef }
}
