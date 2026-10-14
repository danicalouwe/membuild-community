import { reactive } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { uploadBlob } from './upload.js'
import { inkOn } from './theme.js'

/**
 * The installable app: registers the small service worker, remembers the
 * browser's install offer (Chrome, Edge, Android), and knows when we're
 * already running from the home screen.
 */
export const pwa = reactive({
  canPrompt: false, // the browser offered its own install dialog
  standalone: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
  ios: /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
})

let offer = null

export function startApp() {
  if ('serviceWorker' in navigator && cfg.app?.worker) {
    navigator.serviceWorker.register(cfg.app.worker, { scope: `${cfg.basePath}/` }).catch(() => {})
  }
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault() // we show our own "Install app" button instead of the browser's mini bar
    offer = e
    pwa.canPrompt = true
  })
  window.addEventListener('appinstalled', () => {
    pwa.canPrompt = false
    pwa.standalone = true
  })
  if (cfg.app?.drawIcon) drawIcon(cfg.app.initials, cfg.community.accent).catch(() => {})
}

/** Opens the browser's install dialog. Returns false when the browser has none (then show instructions). */
export async function install() {
  if (!offer) return false
  offer.prompt()
  const { outcome } = await offer.userChoice
  offer = null
  pwa.canPrompt = false
  if (outcome === 'accepted') pwa.standalone = true
  return true
}

/**
 * Without a logo, the home-screen icon is the community's initials on its
 * color, drawn here (the browser has good fonts) and saved for everyone.
 */
export async function drawIcon(initials, accent) {
  const size = 512
  const canvas = document.createElement('canvas')
  canvas.width = canvas.height = size
  const ctx = canvas.getContext('2d')
  const color = /^#[0-9a-f]{6}$/i.test(accent || '') ? accent : '#3b5bfd'
  ctx.fillStyle = color
  ctx.fillRect(0, 0, size, size) // full square: phones round the corners themselves
  ctx.fillStyle = inkOn(color) // dark letters on light colors (like neon), white on dark ones
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.font = `800 ${initials.length > 2 ? 150 : 210}px ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`
  ctx.fillText(initials, size / 2, size / 2 + 8)
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
  const media = await uploadBlob(blob, { name: 'app-icon.png', meta: { width: size, height: size } })
  await api.saveAppIcon(media.id)
  if (cfg.app) cfg.app.drawIcon = false
}
