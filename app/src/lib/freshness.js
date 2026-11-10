/**
 * After a plugin update, a page left open (above all the Home Screen app, which phones keep
 * running in the background) still points at the old app files, which are gone from the server.
 * When the member comes back to it, check that this page's own file is still there; if it isn't,
 * the community was updated, so reload to get the new version. If something is typed on the page,
 * wait and load the next page they tap fresh instead, so nothing typed is lost.
 */
import { router } from '../router.js'

const AWAY = 60 * 1000 // only check after a minute away: one small request, not one per glance
const KEY = 'mbcom-reloaded-at' // shared with main.js and the page's own load check (templates/portal.php)

let hiddenAt = 0
let stale = false
let checking = false

/** Reloads, but not again within 10 seconds, so a server that's down can't cause a reload loop. */
export function reloadOnce(url) {
  try {
    const last = Number(sessionStorage.getItem(KEY)) || 0
    if (Date.now() - last < 10000) return false
    sessionStorage.setItem(KEY, String(Date.now()))
  } catch {
    return false // no storage to remember the attempt, so don't risk a loop
  }
  if (url) window.location.assign(url)
  else window.location.reload()
  return true
}

/** Whether the member has typed or changed something on the page that a reload would throw away. */
function somethingTyped() {
  // Pages with unsaved changes (Settings' switches and pickers) already ask before
  // the page is left: ask them the same way.
  const leaving = new Event('beforeunload', { cancelable: true })
  window.dispatchEvent(leaving)
  if (leaving.defaultPrevented) return true
  const fields = document.querySelectorAll('textarea, input:not([type]), input[type=text], input[type=search], input[type=url], input[type=email], input[type=number], [contenteditable="true"]')
  return [...fields].some((el) => (el.isContentEditable ? el.textContent : el.value)?.trim())
}

async function check() {
  if (stale || checking || !navigator.onLine) return
  checking = true
  try {
    // Some hosts send a missing file on to the home page, so a redirect counts as gone too.
    // The time limit keeps a request the phone paused from blocking every later check.
    const res = await fetch(import.meta.url, { method: 'HEAD', cache: 'no-store', redirect: 'manual', signal: AbortSignal.timeout?.(8000) }) // older iPhones have no timeout: still check
    if (res.type === 'opaqueredirect') {
      // Some hosts send build files on (to a CDN): gone only if where it ends up isn't the script.
      const end = await fetch(import.meta.url, { method: 'HEAD', cache: 'no-store', signal: AbortSignal.timeout?.(8000) })
      if (end.ok && /javascript/i.test(end.headers.get('content-type') || '')) return
    } else if (res.status !== 404 && res.status !== 410) return // there, or a server hiccup: carry on
    updated()
  } catch {
    // offline or blocked: nothing to decide
  } finally {
    checking = false
  }
}

/** The community was updated: reload now, or with something typed, on the next page they open. */
function updated() {
  stale = true
  return !somethingTyped() && reloadOnce()
}

/** A part of the app that loads on demand failed (vite:preloadError): most likely an update. */
export function onLoadFailure(e) {
  if (updated()) e.preventDefault()
}

export function watchForUpdates() {
  window.mbcomStarted = true // from here on, the page's own load check (templates/portal.php) leaves failures to us
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) hiddenAt = Date.now()
    else if (hiddenAt && Date.now() - hiddenAt >= AWAY) check()
  })
  // Safari can bring a page back from its memory without it ever being hidden first.
  window.addEventListener('pageshow', (e) => e.persisted && check())

  // Updated while something was typed: the next page they open loads fresh.
  router.beforeEach((to, from) => {
    if (!stale || !from.matched.length) return
    if (reloadOnce(router.resolve(to).href)) return false
  })
}
