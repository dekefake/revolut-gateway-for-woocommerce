import {
  REVOLUT_PAY_INFORMATIONAL_ICON_ID,
  REVOLUT_GATEWAY_UPSELL_BANNER_ID,
  REVOLUT_PAY_INFORMATIONAL_BANNER_ID,
} from '.'

const {
  revPointsBannerEnabled,
  gatewayUpsellBannerEnabled,
  revolutPayIconVariant,
  amount,
  locale,
  publicToken,
  currency,
} = wc_revolut.informational_banner_data

const RevolutUpsellInstance = typeof RevolutUpsell !== 'undefined' ? RevolutUpsell({ locale, publicToken }) : null;

const __metadata = { channel: 'woocommerce-blocks' }

export const mountCardGatewayBanner = orderToken => {
  const target = document.getElementById(REVOLUT_GATEWAY_UPSELL_BANNER_ID)
  if (!RevolutUpsellInstance || !target || !gatewayUpsellBannerEnabled) return
  RevolutUpsellInstance.cardGatewayBanner.mount(target, {
    orderToken,
  })
}

export const mountRevolutPayIcon = () => {
  const target = document.getElementById(REVOLUT_PAY_INFORMATIONAL_ICON_ID)
  if (!RevolutUpsellInstance || !target || !revolutPayIconVariant) return
  RevolutUpsellInstance.promotionalBanner.mount(target, {
    amount,
    variant: revolutPayIconVariant === 'cashback' ? 'link' : revolutPayIconVariant,
    currency,
    style: {
      text: revolutPayIconVariant === 'cashback' ? 'cashback' : null,
      color: 'blue',
    },
    __metadata,
  })
}

export const mountRevPointsBanner = () => {
  const target = document.getElementById(REVOLUT_PAY_INFORMATIONAL_BANNER_ID)
  if (!RevolutUpsellInstance || !target || !revPointsBannerEnabled) return

  RevolutUpsellInstance.promotionalBanner.mount(target, {
    amount,
    variant: 'banner',
    currency,
    __metadata,
  })
}
