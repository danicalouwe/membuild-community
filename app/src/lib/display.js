import { ref } from 'vue'
import { cfg } from '../config.js'

/**
 * Light, Dark or Auto (follow the device), picked in the profile menu. Kept in this
 * browser only; until a member picks, the community's default look (Settings) applies. The choice sits on <html data-theme>, which the
 * app's styles read alongside the device setting (see themeSwitch in vite.config.js).
 */
const KEY = 'mbcom_display'
const CHOICES = ['light', 'dark', 'auto']

const communityDefault = () => (CHOICES.includes(cfg.community?.display) ? cfg.community.display : 'auto')
function stored() {
  try {
    const v = localStorage.getItem(KEY)
    return CHOICES.includes(v) ? v : communityDefault()
  } catch {
    return communityDefault()
  }
}

export const display = ref(stored())

/**
 * The same choice in a cookie, so the pages the server draws on their own (log in, join,
 * checkout, thank you) can follow it: they're light unless the member picked Dark (or Auto).
 * Only a member's own pick is remembered, never the community's default.
 */
function remember(v) {
  document.cookie = `mbcom_display=${v}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`
}

function apply(v) {
  const root = document.documentElement
  if (v === 'auto') delete root.dataset.theme
  else root.dataset.theme = v
}

export function setDisplay(v) {
  if (!CHOICES.includes(v)) return
  display.value = v
  apply(v)
  try {
    localStorage.setItem(KEY, v) // kept even for Auto, so it wins over the community's default
  } catch {
    // the choice still applies until the page reloads
  }
  remember(v)
}

/** Settings: show a look on this page while the owner picks the community's default (nothing is stored). */
export function previewDisplay(v) {
  if (CHOICES.includes(v)) apply(v)
}

/** Back to this member's own look (after a preview). */
export function restoreDisplay() {
  apply(display.value)
// A pick made before the cookie existed: copy it over once.
try {
  const own = localStorage.getItem(KEY)
  if (CHOICES.includes(own) && !document.cookie.includes(`mbcom_display=${own}`)) remember(own)
} catch {
  // no storage: nothing to copy
}
}

apply(display.value)
// A pick made before the cookie existed: copy it over once.
try {
  const own = localStorage.getItem(KEY)
  if (CHOICES.includes(own) && !document.cookie.includes(`mbcom_display=${own}`)) remember(own)
} catch {
  // no storage: nothing to copy
}
