import { t, locale } from './i18n.js'
import { cfg } from '../config.js'

const fmtDate = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric' })

export function timeAgo(iso) {
  const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000)
  if (seconds < 60) return t._x('now', 'time ago, short', 'membuild-community')
  if (seconds < 3600) return t.sprintf(/* translators: %d: minutes ago, short form */ t._x('%dm', 'minutes ago, short', 'membuild-community'), Math.floor(seconds / 60))
  if (seconds < 86400) return t.sprintf(/* translators: %d: hours ago, short form */ t._x('%dh', 'hours ago, short', 'membuild-community'), Math.floor(seconds / 3600))
  if (seconds < 7 * 86400) return t.sprintf(/* translators: %d: days ago, short form */ t._x('%dd', 'days ago, short', 'membuild-community'), Math.floor(seconds / 86400))
  return fmtDate.format(new Date(iso))
}

export function compactNumber(n) {
  if (n < 1000) return String(n)
  if (n < 1e6) return t.sprintf(/* translators: %s: a number in thousands, e.g. "1.2" for 1,200 */ t._x('%sK', 'thousands, short', 'membuild-community'), (n / 1000).toFixed(n < 10000 ? 1 : 0).replace(/\.0$/, ''))
  return t.sprintf(/* translators: %s: a number in millions, e.g. "1.5" for 1,500,000 */ t._x('%sM', 'millions, short', 'membuild-community'), (n / 1e6).toFixed(1).replace(/\.0$/, ''))
}

/** A moment as the site's calendar day (when a lesson opens), so every member sees the same date. */
/** A real Y-m-d day between 2000 and 2100 (not a year still being typed, like 0026). */
export const realDay = (v) => /^(\d{4})-\d{2}-\d{2}$/.test(v || '') && +v.slice(0, 4) >= 2000 && +v.slice(0, 4) <= 2100 && new Date(`${v}T00:00:00Z`).toISOString().slice(0, 10) === v

/** Today as Y-m-d on this device's calendar. */
export function localToday() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function siteDay(iso, opts) {
  try {
    if (!cfg.siteZone && cfg.siteOffset != null) {
      // a fixed offset like +05:30: shift the time, then read the day as UTC
      return new Date(Date.parse(iso) + cfg.siteOffset * 60000).toLocaleDateString(undefined, { ...opts, timeZone: 'UTC' })
    }
    return new Date(iso).toLocaleDateString(undefined, { ...opts, timeZone: cfg.siteZone || undefined })
  } catch {
    return new Date(iso).toLocaleDateString(undefined, opts)
  }
}
