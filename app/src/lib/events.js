import { locale, t } from './i18n.js'

/**
 * Events as members read them: times in their own time zone, "Live now",
 * and the add-to-calendar links.
 */

const dayFmt = new Intl.DateTimeFormat(locale, { weekday: 'short', month: 'short', day: 'numeric' })
const longDayFmt = new Intl.DateTimeFormat(locale, { weekday: 'long', month: 'long', day: 'numeric' })
const timeFmt = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' })
const monthFmt = new Intl.DateTimeFormat(locale, { month: 'short' })

export const startOf = (e) => new Date(e.at)
export const endOf = (e) => new Date(new Date(e.at).getTime() + e.minutes * 60000)

/** "Thu, Oct 22 · 7:00 PM" */
export const whenShort = (e) => `${dayFmt.format(startOf(e))} · ${timeFmt.format(startOf(e))}`

/** "Thursday, October 22 · 7:00 – 7:45 PM" */
export const whenLong = (e) => `${longDayFmt.format(startOf(e))} · ${timeFmt.format(startOf(e))} – ${timeFmt.format(endOf(e))}`

/** The member's own time zone, short: "GMT+8", "PDT". */
export function zoneName() {
  const part = new Intl.DateTimeFormat(locale, { timeZoneName: 'short' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName')
  return part?.value || ''
}

// The day, date and month follow the time shown: the place's own when it's held in another zone.
const zoneOf = (e) => (otherZone(e) ? e.tz : undefined)
export const dateBadge = (e) => {
  const tz = zoneOf(e)
  const d = startOf(e)
  return { month: new Intl.DateTimeFormat(locale, { month: 'short', timeZone: tz }).format(d), day: Number(new Intl.DateTimeFormat('en-US', { day: 'numeric', timeZone: tz }).format(d)) }
}

/** 'cancelled' | 'past' | 'live' (from 15 minutes before, until the end) | 'soon' (today) | 'upcoming' */
export function stateOf(e, now = Date.now()) {
  if (e.status === 'cancelled') return 'cancelled'
  const start = startOf(e).getTime()
  const end = endOf(e).getTime()
  if (now > end) return 'past'
  if (now >= start - 15 * 60000) return 'live'
  if (end - now < 24 * 3600000) return 'soon'
  return 'upcoming'
}

/** "in 2 hours", "in 3 days", "tomorrow" */
export function untilLabel(e, now = Date.now()) {
  const mins = Math.round((startOf(e).getTime() - now) / 60000)
  if (mins < 60) return t.sprintf(/* translators: %d: minutes */ t._n('in %d minute', 'in %d minutes', Math.max(1, mins), 'membuild-community'), Math.max(1, mins))
  const hours = Math.round(mins / 60)
  if (hours < 24) return t.sprintf(/* translators: %d: hours */ t._n('in %d hour', 'in %d hours', hours, 'membuild-community'), hours)
  const days = Math.round(hours / 24)
  if (days === 1) return t.__('tomorrow', 'membuild-community')
  return t.sprintf(/* translators: %d: days */ t.__('in %d days', 'membuild-community'), days)
}

/** Where a calendar entry says it is: the place and address, or the link. */
const whereOf = (e, pageUrl) => (e.place?.name ? [e.place.name, e.place.address].filter(Boolean).join(', ') : e.url || pageUrl)

const stamp = (d) => d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')

/** Links that add the event to the member's own calendar. Apple and Outlook's desktop apps take the .ics file. */
export function calendarLinks(e, pageUrl) {
  const details = [e.description?.trim(), e.url, pageUrl].filter(Boolean).join('\n\n')
  const dates = `${stamp(startOf(e))}/${stamp(endOf(e))}`
  const where = whereOf(e, pageUrl)
  return {
    google: `https://calendar.google.com/calendar/render?${new URLSearchParams({ action: 'TEMPLATE', text: e.title, dates, details, location: where })}`,
    outlook: `https://outlook.live.com/calendar/0/deeplink/compose?${new URLSearchParams({ subject: e.title, startdt: startOf(e).toISOString(), enddt: endOf(e).toISOString(), body: details, location: where, path: '/calendar/action/compose', rru: 'addevent' })}`,
  }
}

/** Downloads an .ics file (Apple Calendar, Outlook's app, and most others). */
export function downloadIcs(e, pageUrl, siteName) {
  const esc = (s) => String(s || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n')
  const lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    `PRODID:-//${esc(siteName)}//MemBuild Community//EN`,
    'BEGIN:VEVENT',
    `UID:mbcom-event-${e.id}@${location.host}`,
    `DTSTAMP:${stamp(new Date())}`,
    `DTSTART:${stamp(startOf(e))}`,
    `DTEND:${stamp(endOf(e))}`,
    `SUMMARY:${esc(e.title)}`,
    `DESCRIPTION:${esc([e.description?.trim(), e.url, pageUrl].filter(Boolean).join('\n\n'))}`,
    `URL:${e.url || pageUrl}`,
    `LOCATION:${esc(whereOf(e, pageUrl))}`,
    'END:VEVENT',
    'END:VCALENDAR',
  ]
  const blob = new Blob([lines.join('\r\n')], { type: 'text/calendar;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `${e.title.replace(/[^\w\s-]/g, '').trim() || 'event'}.ics`
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

/** The player address for a stream that shows inside the community, or null for a link that opens elsewhere. */
export function playerUrl(url, kind) {
  if (!url) return null
  if (kind === 'youtube') {
    const m = url.match(/(?:youtube\.com\/(?:shorts\/|embed\/|live\/|watch\?(?:.*&)?v=)|youtu\.be\/)([A-Za-z0-9_-]{11})/)
    return m ? `https://www.youtube-nocookie.com/embed/${m[1]}?autoplay=1&rel=0` : null
  }
  if (kind === 'vimeo') {
    const m = url.match(/vimeo\.com\/(?:video\/|event\/)?(\d+)(?:\/([a-f0-9]{6,}))?/)
    return m ? `https://player.vimeo.com/video/${m[1]}?autoplay=1${m[2] ? `&h=${m[2]}` : ''}` : null
  }
  if (kind === 'twitch') {
    const m = url.match(/twitch\.tv\/([A-Za-z0-9_]+)/)
    return m ? `https://player.twitch.tv/?channel=${m[1]}&parent=${location.hostname}&autoplay=true` : null
  }
  return null
}

/** "Zoom", "Google Meet", "YouTube"…: what the Join button opens. */
export function serviceName(kind) {
  return { zoom: 'Zoom', meet: 'Google Meet', youtube: 'YouTube', vimeo: 'Vimeo', twitch: 'Twitch' }[kind] || ''
}

/** "1:00 PM – 2:30 PM GMT+8": the time, in the member's own zone. */
export const timeRange = (e) => `${timeFmt.format(startOf(e))} – ${timeFmt.format(endOf(e))} ${zoneName()}`

/** "Sun, Oct 25" */
export const dayShort = (e) => new Intl.DateTimeFormat(locale, { weekday: 'short', month: 'short', day: 'numeric', timeZone: zoneOf(e) }).format(startOf(e))

/** "October": the month a list groups under. */
const monthLongFmt = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' })
const thisYear = new Date().getFullYear()
export const monthOf = (e) => {
  const d = startOf(e)
  const tz = zoneOf(e)
  const year = Number(new Intl.DateTimeFormat('en-US', { year: 'numeric', timeZone: tz }).format(d))
  return year === thisYear ? new Intl.DateTimeFormat(locale, { month: 'long', timeZone: tz }).format(d) : new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: tz }).format(d)
}

/** An event without a photo: an abstract cover made from the community's main colour. */
export const plainCover = () => ({
  background:
    'radial-gradient(circle at 82% 22%, rgb(255 255 255 / 24%) 0 21%, transparent 21.5%), radial-gradient(circle at 22% 115%, rgb(255 255 255 / 18%) 0 38%, transparent 38.5%), linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 72%, #000))',
})

/** "In 2 days" with a capital, for the small pill. */
export const untilPill = (e, now = Date.now()) => {
  const s = untilLabel(e, now)
  return s.charAt(0).toUpperCase() + s.slice(1)
}

/** "1:00 PM GMT+8": just the start, for phones (the place's time when it's held elsewhere). */
export const startTime = (e) => (otherZone(e) ? `${new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit', timeZone: e.tz }).format(startOf(e))} ${shortZone(e.tz, startOf(e))}` : `${timeFmt.format(startOf(e))} ${zoneName()}`)

/* ---------- Time zones (in-person events are held in the place's own time) ---------- */

/** Minutes a time zone is ahead of UTC at a moment. */
function offsetMin(date, tz) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }).formatToParts(date).map((p) => [p.type, p.value]))
  const asUtc = Date.UTC(+parts.year, +parts.month - 1, +parts.day, +parts.hour % 24, +parts.minute, +parts.second)
  return Math.round((asUtc - date.getTime()) / 60000)
}

/** "2026-11-21" + "10:00" in "America/Chicago" → the moment, as a Date. */
export function zonedToDate(date, time, tz) {
  const [y, m, d] = date.split('-').map(Number)
  const [hh, mm] = time.split(':').map(Number)
  const guess = Date.UTC(y, m - 1, d, hh, mm)
  let at = guess - offsetMin(new Date(guess), tz) * 60000
  at = guess - offsetMin(new Date(at), tz) * 60000 // once more, for the day summer time changes
  return new Date(at)
}

/** A moment as { date, time } on the wall clock in `tz`. */
export function dateInZone(iso, tz) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).formatToParts(new Date(iso)).map((x) => [x.type, x.value]))
  return { date: `${p.year}-${p.month}-${p.day}`, time: `${p.hour}:${p.minute}` }
}

/** "Austin time" / "Eastern Time": a zone, the way people say it. */
export function zoneLabel(tz, city = '') {
  if (city) return t.sprintf(/* translators: %s: a city, e.g. "Austin" */ t.__('%s time', 'membuild-community'), city)
  const long = new Intl.DateTimeFormat(locale, { timeZone: tz, timeZoneName: 'long' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName')?.value
  return long || tz
}

/** The short zone name ("CT", "GMT+8") in `tz`. */
export const shortZone = (tz, at = new Date()) => new Intl.DateTimeFormat(locale, { timeZone: tz, timeZoneName: 'short' }).formatToParts(at).find((p) => p.type === 'timeZoneName')?.value || ''

/** The city of an address: "Riverside Dr, Austin, TX" → "Austin". */
export function cityOfAddress(address) {
  const parts = String(address || '').split(',').map((x) => x.trim()).filter(Boolean)
  return parts.length >= 2 ? parts[parts.length - 2].replace(/\d+/g, '').trim() : parts[0] || ''
}

/** "10:00 AM – 12:00 PM CT": the time where the event is held. */
export function timeRangeIn(e) {
  const f = new Intl.DateTimeFormat(locale, { timeZone: e.tz, hour: 'numeric', minute: '2-digit' })
  return `${f.format(startOf(e))} – ${f.format(endOf(e))} ${shortZone(e.tz, startOf(e))}`
}

/** Held somewhere else in the world than the member is? */
export const otherZone = (e) => !!e.tz && e.where !== 'online' && offsetMin(startOf(e), e.tz) !== -startOf(e).getTimezoneOffset()
