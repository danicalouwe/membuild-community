import { cfg } from '../config.js'

/**
 * Links into this community (…/community?post=12 or …?post=12&comment=34),
 * as "Share" makes them. Chats show those as a preview card instead of a bare
 * URL, and the app opens them on top of the current page rather than in a tab.
 */
const URL_RE = /https?:\/\/[^\s<]+[^\s<.,:;"')\]!?]/g

/** {post, comment} for a link into this community, or null. */
export function parseInternal(href) {
  try {
    const u = new URL(href, location.origin)
    if (u.origin !== location.origin || !u.pathname.startsWith(cfg.basePath)) return null
    const post = Number(u.searchParams.get('post'))
    if (!post) return null
    return { post, comment: Number(u.searchParams.get('comment')) || 0 }
  } catch {
    return null
  }
}

/** The first community link in a text, with whether the text is nothing but that link. */
export function sharedIn(text) {
  for (const href of String(text || '').match(URL_RE) || []) {
    const ref = parseInternal(href)
    if (ref) return { ...ref, href, only: String(text).trim() === href }
  }
  return null
}

/** The course id a link into this community's Classroom points at (…/classroom/12 or a lesson in it), or 0. */
export function courseIdOf(href) {
  try {
    const u = new URL(href, location.origin)
    if (u.origin !== location.origin || !u.pathname.startsWith(cfg.basePath)) return 0
    const m = u.pathname.slice(cfg.basePath.length).replace(/^\/+/, '').match(/^classroom\/(\d+)(?:\/\d+)?\/?$/)
    return m ? Number(m[1]) : 0
  } catch {
    return 0
  }
}

/** The first course link in a text: { id, href }, or null. */
export function courseIn(text) {
  for (const href of String(text || '').match(URL_RE) || []) {
    const id = courseIdOf(href)
    if (id) return { id, href }
  }
  return null
}
