import { useRef, useEffect } from '@wordpress/element'

const REVOLUT_CHECKOUT_RETRY_ERROR_TYPES = [
  'error.3ds-failed',
  'error.email-is-not-specified',
  'error.invalid-postcode',
  'error.invalid-email',
  'error.incorrect-cvv-code',
  'error.expired-card',
  'error.do-not-honour',
  'error.insufficient-funds',
]

export const useCardField = ({ onMsg, publicId, locale }, deps) => {
  const onMsgRef = useRef(onMsg)
  const cardInputRef = useRef(null)
  const rcRef = useRef(null)
  useEffect(() => {
    let isCancelled = false

    if (rcRef.current) {
      rcRef.current.destroy()
      onMsgRef.current({ type: 'instance_destroyed' })
    }

    RevolutCheckout(publicId).then(RC => {
      if (isCancelled || !cardInputRef.current) {
        return
      }

      rcRef.current = RC.createCardField({
        locale,
        target: cardInputRef.current,
        onSuccess() {
          onMsgRef.current({ type: 'payment_successful' })
        },
        onError(error) {
          if (REVOLUT_CHECKOUT_RETRY_ERROR_TYPES.includes(error.type)) {
            onMsgRef.current({
              type: 'fields_errors_changed',
              errors: [error],
            })
          } else {
            onMsgRef.current({ type: 'payment_failed', error })
          }
        },
        onValidation: errors =>
          onMsgRef.current({
            type: 'fields_errors_changed',
            errors,
          }),
        onStatusChange: status => {
          onMsgRef.current({
            type: 'fields_status_changed',
            status,
          })
        },
        onCancel() {
          onMsgRef.current({ type: 'payment_cancelled' })
        },
      })

      onMsgRef.current({ type: 'instance_mounted', instance: rcRef.current })
    })

    const cleanup = () => {
      isCancelled = true

      if (rcRef.current) {
        rcRef.current.destroy()
        rcRef.current = null
        onMsgRef.current({ type: 'instance_destroyed' })
      }
    }

    return cleanup
  }, [publicId, onMsgRef, locale, ...deps])

  return cardInputRef
}
