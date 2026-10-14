import { reactive } from 'vue'
import { api } from '../api.js'

/**
 * Live state, kept fresh by one small "pulse" request: unread messages,
 * notifications, reports, new feed posts, and new messages for open chats.
 *
 * How often: every 3 s while a chat is open, every 15 s otherwise, backing
 * off to 30 s after 2 idle minutes and 60 s after 10 (any pointer, key or
 * scroll resets that), and never while the tab is hidden. Works on any host;
 * no websockets needed.
 */
export const messages = reactive({ unread: 0, inboxVersion: 0, notifications: 0, reports: 0, newPosts: 0 })

const FAST = 3000
const SLOW = 15000
const IDLE = 30000
const AWAY = 60000
const IDLE_AFTER = 2 * 60 * 1000
const AWAY_AFTER = 10 * 60 * 1000

let started = false
let timer = null
let inFlight = false
let again = false // asked for a pulse while one was running
let lastActivity = Date.now()
const watchers = new Map() // threadId -> { afterId(), onPulse(thread) }
let feedSince = null // () => newest post id the feed shows, while the feed is on screen

function delay() {
  const idle = Date.now() - lastActivity
  if (idle > AWAY_AFTER) return AWAY
  if (idle > IDLE_AFTER) return IDLE
  return watchers.size ? FAST : SLOW
}

function schedule() {
  clearTimeout(timer)
  timer = setTimeout(tick, delay())
}

async function tick() {
  clearTimeout(timer)
  if (inFlight) {
    again = true
    return
  }
  if (!document.hidden) {
    inFlight = true
    try {
      const threads = [...watchers].map(([id, w]) => `${id}:${w.afterId()}`).join(',')
      const since = feedSince?.() || undefined
      const res = await api.pulse(threads || undefined, since)
      messages.unread = res.unread
      messages.inboxVersion = res.inbox_version
      messages.notifications = res.notifications
      messages.reports = res.reports || 0
      messages.newPosts = since ? res.new_posts || 0 : 0
      for (const thread of res.threads) watchers.get(thread.id)?.onPulse(thread)
    } catch {
      /* offline: try again next tick */
    } finally {
      inFlight = false
    }
    if (again) {
      again = false
      return tick()
    }
  }
  schedule()
}

// Activity after an idle stretch brings the pulse straight back to its normal pace.
let lastSeen = 0
function onActivity() {
  const now = Date.now()
  if (now - lastSeen < 1000) return // scroll and pointer events come in bursts
  lastSeen = now
  const wasIdle = now - lastActivity > IDLE_AFTER
  lastActivity = now
  if (wasIdle) tick()
}

export function startPulse() {
  if (started) return
  started = true
  document.addEventListener('visibilitychange', () => !document.hidden && tick())
  for (const type of ['pointerdown', 'keydown', 'scroll', 'wheel', 'touchstart']) {
    window.addEventListener(type, onActivity, { passive: true, capture: true })
  }
  tick()
}

/** Each open chat asks for fast pulses that include its new messages. */
export function watchChat(threadId, afterId, onPulse) {
  const w = { afterId, onPulse }
  watchers.set(threadId, w)
  tick()
  return () => {
    if (watchers.get(threadId) !== w) return
    watchers.delete(threadId)
    if (!watchers.size && !inFlight) schedule() // back to the slower pace
  }
}

/**
 * While the feed is on screen, the pulse also counts posts newer than the
 * newest one it shows (messages.newPosts), for the "new posts" pill.
 */
export function watchFeed(newestId) {
  feedSince = newestId
  return () => {
    if (feedSince !== newestId) return
    feedSince = null
    messages.newPosts = 0
  }
}

export function pulseNow() {
  tick()
}
