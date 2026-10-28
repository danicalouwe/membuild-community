import { ref } from 'vue'

/**
 * Light, Dark or Auto (follow the device), picked in the profile menu. Kept in this
 * browser only; Auto is the default. The choice sits on <html data-theme>, which the
 * app's styles read alongside the device setting (see themeSwitch in vite.config.js).
 */
const KEY = 'mbcom_display'
const CHOICES = ['light', 'dark', 'auto']

function stored() {
  try {
    const v = localStorage.getItem(KEY)
    return CHOICES.includes(v) ? v : 'auto'
  } catch {
    return 'auto'
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
    if (v === 'auto') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, v)
  } catch {
    // the choice still applies until the page reloads
  }
}

apply(display.value)
