import { t } from './i18n.js'

// A membership's "How people get it": the button members see on its locked courses,
// e.g. "Get access for $49/month". Display only; payment happens on the owner's checkout.

/** "$49/month", "$490/year", "$99", or '' without a price. */
export function priceLabel(offer) {
  const price = (offer?.price || '').trim()
  if (!price) return ''
  if (offer.period === 'month') return t.sprintf(/* translators: %s: a price, e.g. "$49" */ t.__('%s/month', 'membuild-community'), price)
  if (offer.period === 'year') return t.sprintf(/* translators: %s: a price, e.g. "$490" */ t.__('%s/year', 'membuild-community'), price)
  return price
}

/** The whole button text: "Get access for $49/month", or just "Get access" without a price. */
export function offerButton(offer) {
  const label = (offer?.label || '').trim() || t.__('Get access', 'membuild-community')
  const price = priceLabel(offer)
  return price ? t.sprintf(/* translators: 1: button text, e.g. "Get access", 2: price, e.g. "$49/month" */ t.__('%1$s for %2$s', 'membuild-community'), label, price) : label
}

/** "per month", "per year", or "one-time", under a big price. */
export function periodWords(period) {
  if (period === 'month') return t.__('per month', 'membuild-community')
  if (period === 'year') return t.__('per year', 'membuild-community')
  return t.__('one-time', 'membuild-community')
}
