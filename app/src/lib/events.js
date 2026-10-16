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

export const dateBadge = (e) => ({ month: monthFmt.format(startOf(e)), day: startOf(e).getDate() })

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

const stamp = (d) => d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')

/** Links that add the event to the member's own calendar. Apple and Outlook's desktop apps take the .ics file. */
export function calendarLinks(e, pageUrl) {
  const details = [e.description, e.url, pageUrl].filter(Boolean).join('\n\n')
  const dates = `${stamp(startOf(e))}/${stamp(endOf(e))}`
  return {
    google: `https://calendar.google.com/calendar/render?${new URLSearchParams({ action: 'TEMPLATE', text: e.title, dates, details, location: e.url || pageUrl })}`,
    outlook: `https://outlook.live.com/calendar/0/deeplink/compose?${new URLSearchParams({ subject: e.title, startdt: startOf(e).toISOString(), enddt: endOf(e).toISOString(), body: details, location: e.url || pageUrl, path: '/calendar/action/compose', rru: 'addevent' })}`,
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
    `DESCRIPTION:${esc([e.description, e.url, pageUrl].filter(Boolean).join('\n\n'))}`,
    `URL:${e.url || pageUrl}`,
    `LOCATION:${esc(e.url || pageUrl)}`,
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
