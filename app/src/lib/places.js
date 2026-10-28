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

/* ---------- Hours by day ---------- */

const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] // getDay() order
const DAY_ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
const at = (hhmm) => new Date(`2000-01-03T${hhmm === '24:00' ? '23:59' : hhmm}:00`)
const clock = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' })
const dayName = (key) => new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(new Date(2000, 0, 2 + DAY_KEYS.indexOf(key)))

/** "9:00 – 11:00 AM" (the locale's own way of writing a range). */
export const timeSpan = ([from, to]) => (clock.formatRange ? clock.formatRange(at(from), at(to)) : `${clock.format(at(from))} – ${clock.format(at(to))}`)

/** The open days in week order: [{ key, name, spans: ['9:00 – 11:00 AM', …] }]. */
export const weekLines = (week) => DAY_ORDER.filter((d) => week?.[d]?.length).map((d) => ({ key: d, name: dayName(d), spans: week[d].map(timeSpan) }))

/** Today's weekday key and "HH:MM" in the place's time zone (or the member's). */
function nowIn(tz) {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: tz || undefined, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date())
  const get = (type) => parts.find((p) => p.type === type)?.value
  return { day: get('weekday').toLowerCase().slice(0, 3), time: `${get('hour')}:${get('minute')}` }
}

/** "Open now · until 11:00 AM", "Opens today at 2:00 PM", "Opens Thursday at 9:00 AM", or null. */
export function openState(week, tz) {
  if (!week || !Object.keys(week).length) return null
  const now = nowIn(tz)
  const today = DAY_KEYS.indexOf(now.day)
  const spans = (k) => (week[k] || []).slice().sort((a, b) => (a[0] < b[0] ? -1 : 1))
  const open = spans(now.day).find(([f, to]) => f <= now.time && now.time < to)
  if (open) return { open: true, text: t.sprintf(/* translators: %s: a time */ t.__('Open now · until %s', 'membuild-community'), clock.format(at(open[1]))) }
  for (let i = 0; i < 7; i++) {
    const key = DAY_KEYS[(today + i) % 7]
    const next = spans(key).find(([f]) => i > 0 || f > now.time)
    if (!next) continue
    const time = clock.format(at(next[0]))
    if (i === 0) return { open: false, text: t.sprintf(/* translators: %s: a time */ t.__('Opens today at %s', 'membuild-community'), time) }
    if (i === 1) return { open: false, text: t.sprintf(/* translators: %s: a time */ t.__('Opens tomorrow at %s', 'membuild-community'), time) }
    return { open: false, text: t.sprintf(/* translators: 1: a weekday, 2: a time */ t.__('Opens %1$s at %2$s', 'membuild-community'), dayName(key), time) }
  }
  return null
}
