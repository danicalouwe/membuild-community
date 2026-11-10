/**
 * The amount from an add-on's price, for lines that add their own words around it
 * ("%s a year", "Renew for %s a year", "PayPal takes %s on …").
 * tools.membuild.com sends the price as written on the add-on page ("$69 a year");
 * older replies and the built-in entry send "$69" or "69". All of them give "$69".
 * Anything without a dollar amount is kept as it is.
 */
export function priceAmount(price) {
  const v = String(price ?? '').trim()
  if (/^\d+(\.\d+)?$/.test(v)) return `$${v}`
  const m = /\$\s?\d[\d.,]*/.exec(v)
  return m ? m[0].replace(/\s/g, '').replace(/[.,]$/, '') : v
}
