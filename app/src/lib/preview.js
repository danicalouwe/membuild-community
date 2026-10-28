import { ref, watch } from 'vue'
import { cfg } from '../config.js'

/**
 * "See it as a member" (admins): the whole community the way a member sees it, on every
 * page, until Back to your view. The admin picks which member: a membership (or none)
 * and a level, since courses open by either. The server does the work (a session cookie
 * "m{membership}.{level}", see Helpers::as_member_pick), so every page and every answer
 * is that member's version; switching reloads so nothing from the owner view stays.
 */
export const canSeeAsMember = !!cfg.community.canSeeAsMember
export const memberPick = cfg.community.asMember || null // { group, level } while on
export const asMember = ref(!!memberPick)

function setCookie(value) {
  document.cookie = `mbcom_as_member=${value}; path=/; SameSite=Lax${value ? '' : '; max-age=0'}${location.protocol === 'https:' ? '; Secure' : ''}`
  location.reload()
}

/** See it as a member of `group` (0 = no membership) at `level`. */
export function seeAs(group = 0, level = 1) {
  setCookie(`m${group || ''}.${level}`)
}

// Older callers (the course builder's "See it as a member", "Back to your view"): a new member, or back.
watch(asMember, (on) => (on ? seeAs(0, 1) : setCookie('')))
