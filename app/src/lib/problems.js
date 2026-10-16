/**
 * Reports an error in the app's own code to the site (POST /problems), where
 * admins see it on Community → Problems. Each distinct error once per page
 * load, five at most, and nothing a member typed: the message, the file and
 * line, the stack, and the route they were on.
 */
import { api } from '../api.js'
import { router } from '../router.js'

const seen = new Set()
let left = 5

export function reportProblem(err, source = '', line = 0, info = '') {
  try {
    const message = String(err?.message || err || '').slice(0, 500)
    if (!message || /^Script error\.?$/.test(message)) return // another origin's script; nothing to act on
    if (err instanceof Error && err.status && err.status < 500) return // the server said no (a 403, a 429); not a bug
    const key = `${message}|${source}|${line}`
    if (seen.has(key) || left <= 0) return
    seen.add(key)
    left--
    const route = router.currentRoute.value?.name ? String(router.currentRoute.value.name) : ''
    api
      .reportProblem({
        message,
        source: source || (err?.stack?.match(/\(?(https?:\/\/[^\s)]+?\.js)/)?.[1] ?? ''),
        line,
        stack: String(err?.stack || '').slice(0, 2000),
        route: info ? `${route} (${info})` : route,
      })
      .catch(() => {})
  } catch {
    // reporting must never throw
  }
}
