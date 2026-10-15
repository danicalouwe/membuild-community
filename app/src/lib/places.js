import { t, locale } from './i18n.js'

/** The kinds of place, with the pin's color and emoji. Matches Rest_Places::CATEGORIES. */
export const CATEGORY = {
  meetup: { label: t.__('Meetup spot', 'membuild-community'), plural: t.__('Meetup spots', 'membuild-community'), emoji: '👋', color: '#3b82f6' },
  perk: { label: t.__('Member perk', 'membuild-community'), plural: t.__('Member perks', 'membuild-community'), emoji: '🎁', color: '#10b981' },
  venue: { label: t.__('Venue', 'membuild-community'), plural: t.__('Venues', 'membuild-community'), emoji: '📍', color: '#f97316' },
  other: { label: t.__('Other', 'membuild-community'), plural: t.__('Other', 'membuild-community'), emoji: '⭐', color: '#8b5cf6' },
}

/** Kilometres between two points (haversine). */
export function distanceKm(a, b) {
  const rad = (d) => (d * Math.PI) / 180
  const dLat = rad(b.lat - a.lat)
  const dLng = rad(b.lng - a.lng)
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2
  return 12742 * Math.asin(Math.sqrt(h))
}

// Miles where people use them, kilometres everywhere else.
const miles = /^en-(US|GB|LR|MM)/i.test(locale)

/** "1.2 km" / "0.8 mi" / "350 m". */
export function distanceLabel(km) {
  if (miles) {
    const mi = km / 1.609
    return mi < 10 ? `${mi.toFixed(1)} mi` : `${Math.round(mi).toLocaleString(locale)} mi`
  }
  if (km < 1) return `${Math.round(km * 100) * 10} m`
  return km < 10 ? `${km.toFixed(1)} km` : `${Math.round(km).toLocaleString(locale)} km`
}

/** Directions in the phone's own maps app: Apple Maps on Apple devices, Google Maps elsewhere. */
export function directionsUrl(place) {
  const apple = /iPhone|iPad|Macintosh/.test(navigator.userAgent)
  return apple ? `https://maps.apple.com/?daddr=${place.lat},${place.lng}` : `https://www.google.com/maps/dir/?api=1&destination=${place.lat},${place.lng}`
}
