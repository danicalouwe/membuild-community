/**
 * MemBuild - Community · Copyright (C) 2026 Danica Louwe (MemBuild, https://membuild.com) · GPL-2.0-or-later
 */
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.js'
import './styles.css'
import { cfg } from './config.js'
import { applyAccent } from './lib/theme.js'
import './lib/display.js' // Light / Dark / Auto, before the first paint
import { vStream } from './lib/stream.js'
import { vAutoGrow } from './lib/autoGrow.js'
import { t } from './lib/i18n.js'
import { reportProblem } from './lib/problems.js'

applyAccent(cfg.community.accent)

// After a plugin update, a page left open still points at the old app files, which are gone.
// When one fails to load, reload once to pick up the new version.
window.addEventListener('vite:preloadError', (e) => {
  try {
    const last = Number(sessionStorage.getItem('mbcom-reloaded-at')) || 0
    if (Date.now() - last < 10000) return // already tried just now: don't loop
    sessionStorage.setItem('mbcom-reloaded-at', String(Date.now()))
  } catch {
    return // no storage to remember the attempt, so don't risk a reload loop
  }
  e.preventDefault()
  window.location.reload()
})

// Errors in the app's own code go to the site's Problems page (admins only see
// it; see the plugin's Problems class). A few per page load, each once, and
// never anything typed by the member: only the message, where, and the stack.
window.addEventListener('error', (e) => reportProblem(e.error || e.message, e.filename, e.lineno))
window.addEventListener('unhandledrejection', (e) => reportProblem(e.reason))

const app = createApp(App).use(router).directive('stream', vStream).directive('auto-grow', vAutoGrow)
app.config.errorHandler = (err, _instance, info) => {
  reportProblem(err, '', 0, info)
  console.error(err)
}
app.config.globalProperties.t = t // {{ t.__('…', 'membuild-community') }} in any template
app.mount('#mbcom-app')
