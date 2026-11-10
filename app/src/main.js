/**
 * MemBuild - Community · Copyright (C) 2026 Danica Louwe (MemBuild, https://membuild.com) · GPL-2.0-or-later
 */
import * as Vue from 'vue'
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
import { onLoadFailure, watchForUpdates } from './lib/freshness.js'
import { CONTRACT, SLOTS, STYLED_CLASSES, addToSlot, replaceSlot } from './lib/slots.js'
import { MEMBER_FIELDS, member } from './lib/member.js'
import { DATA_FIELDS } from './lib/data.js'
import { addons, data, registerAddon, request } from './lib/addons.js'
import { makeWords } from './lib/words.js'
import ThemeSlot from './components/ThemeSlot.vue'
import Icon from './components/Icon.vue'
import Avatar from './components/Avatar.vue'
import HeaderActions from './components/HeaderActions.vue'
import SearchBox from './components/SearchBox.vue'
import BaseDialog from './components/BaseDialog.vue'
import { askConfirm } from './lib/confirm.js'

applyAccent(cfg.community.accent)

// After a plugin update, a page left open still points at the old app files, which are gone.
// When one fails to load, reload once to pick up the new version; coming back to the page
// after a while away checks for an update too (lib/freshness.js).
window.addEventListener('vite:preloadError', onLoadFailure)
watchForUpdates()

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

// Community themes (theme contract 1): window.MBCommunity.ready((mb) => …) hands over a
// theme's pieces for the slots in lib/slots.js. The page prints a small queue before the app
// loads (templates/portal.php), so a theme's script can run first; it's emptied here, before
// the first paint, and from then on ready() runs straight away.
const mb = Object.freeze({
  contract: CONTRACT,
  slots: SLOTS,
  styledClasses: STYLED_CLASSES,
  member, // the signed-in member's streak, level and points, read-only and live (lib/member.js)
  memberFields: MEMBER_FIELDS,
  replaceSlot,
  addToSlot,
  data, // load and save for a theme's own pages, permissions checked by the server (lib/data.js)
  dataFields: DATA_FIELDS,
  addons, // the add-ons that said they're here: { id: { name, version, slots } }, read-only and live
  registerAddon, // for add-ons (lib/addons.js)
  request, // the community's own request, for add-ons' routes under /addons/ only
  vue: Vue, // the app's own Vue 3: build pieces with it, don't bundle another
  router,
  t,
  words: makeWords(cfg.words), // a theme's renamed words, for a theme's or add-on's own text (lib/words.js)
  components: { Icon, Avatar, HeaderActions, SearchBox, ThemeSlot, BaseDialog }, // the community's own parts, to reuse inside pieces; add-ons draw their slots with ThemeSlot
  askConfirm, // our own “Are you sure?” box (lib/confirm.js), for add-ons' settings
  ready: (fn) => run(fn),
})
function run(fn) {
  try {
    fn(mb)
  } catch (err) {
    reportProblem(err, 'theme', 0, 'ready')
  }
}
const queued = window.MBCommunity?.queue || []
window.MBCommunity = mb
queued.forEach(run)
app.mount('#mbcom-app')
