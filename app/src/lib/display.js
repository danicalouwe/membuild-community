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
}

apply(display.value)
