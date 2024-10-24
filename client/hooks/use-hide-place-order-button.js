import { useEffect } from '@wordpress/element'

export const useHidePlacerOrderButton = () => {
  useEffect(() => {
    const placeOrderButton = document.querySelector(
      '.wp-element-button.wc-block-components-checkout-place-order-button',
    )
    if (placeOrderButton) {
      placeOrderButton.disabled = true
      placeOrderButton.style.display = 'none'
    }
    return () => {
      if (placeOrderButton) {
        placeOrderButton.disabled = false
        placeOrderButton.style.display = 'block'
      }
    }
  }, [])
}
