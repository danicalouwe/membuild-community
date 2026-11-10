<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { applyAccent } from '../lib/theme.js'
import { drawIcon } from '../lib/pwa.js'
import { initialsOf } from '../config.js'
import { SETTINGS } from '../components/settings/context.js'
import { SETTINGS_GROUPS, SETTINGS_PAGES, lastSettingsPage, rememberSettingsPage } from '../lib/settings-pages.js'
import GeneralSection from '../components/settings/GeneralSection.vue'
import FeaturesSection from '../components/settings/FeaturesSection.vue'
import TopicsSection from '../components/settings/TopicsSection.vue'
import PermissionsSection from '../components/settings/PermissionsSection.vue'
import AccessSection from '../components/settings/AccessSection.vue'
import PointsSection from '../components/settings/PointsSection.vue'
import EmailSection from '../components/settings/EmailSection.vue'
import VideoSection from '../components/settings/VideoSection.vue'
import GifsSection from '../components/settings/GifsSection.vue'
import JoiningSection from '../components/settings/JoiningSection.vue'
import UpdatesSection from '../components/settings/UpdatesSection.vue'
import AdvancedSection from '../components/settings/AdvancedSection.vue'
import ImportSection from '../components/settings/ImportSection.vue'
import AddonsSection from '../components/settings/AddonsSection.vue'
import StarterSection from '../components/settings/StarterSection.vue'
import SecuritySection from '../components/settings/SecuritySection.vue'
import AddonSettings from '../components/settings/AddonSettings.vue'
import { addonSections } from '../lib/addons.js'
import { t } from '../lib/i18n.js'

/**
 * Community settings for admins: one page per section, at its own address (/settings/features),
 * under a menu grouped by short headings (lib/settings-pages.js sets the order, groups and
 * addresses). The form is loaded once and shared by the sections through provide/inject. You can
 * only leave a page after saving or dropping its changes, so the save bar only ever holds the
 * page you're on.
 * `isolated`: the section styles itself, because the shared section styles would reach into the
 * component it embeds.
 */
const COMPONENTS = {
  general: GeneralSection,
  website: StarterSection,
  joining: JoiningSection,
  security: SecuritySection,
  email: EmailSection,
  features: FeaturesSection,
  topics: TopicsSection,
  permissions: PermissionsSection,
  access: AccessSection,
  points: PointsSection,
  video: VideoSection,
  gifs: GifsSection,
  import: ImportSection,
  addons: AddonsSection,
  updates: UpdatesSection,
  advanced: AdvancedSection,
}
const ISOLATED = ['access']
const CORE_SECTIONS = SETTINGS_PAGES.filter((p) => COMPONENTS[p.id]).map((p) => ({ ...p, component: COMPONENTS[p.id], isolated: ISOLATED.includes(p.id) }))
// An add-on's own page (lib/addons.js) goes after the core page it names, in that page's group,
// or after Add-ons. Its address is its id (/settings/crm). Each is drawn inside AddonSettings, so
// a broken one can't take Settings down with it.
const SECTIONS = computed(() => {
  // On a multisite network, add-ons are installed from the main site only.
  const list = CORE_SECTIONS.filter((s) => s.id !== 'addons' || cfg.community.addonsHere !== false)
  for (const a of addonSections) {
    if (CORE_SECTIONS.some((s) => s.id === a.id || s.slug === a.id) || list.some((s) => s.id === a.id)) continue
    let at = list.findIndex((s) => s.id === a.after)
    if (at < 0) at = list.findIndex((s) => s.id === 'addons')
    // A subsite has no Add-ons page: the end of Connections, else the end.
    if (at < 0) list.forEach((s, i) => s.group === 'connections' && (at = i))
    if (at < 0) at = list.length - 1
    // After any add-on pages already there, so they keep the order they arrived in.
    while (list[at + 1]?.addonSection) at++
    list.splice(at + 1, 0, { ...a, slug: a.id, group: list[at].group, find: [], isolated: true, addonSection: true })
  }
  return list
})
const GROUPS = computed(() => SETTINGS_GROUPS.map((g) => ({ ...g, pages: SECTIONS.value.filter((s) => s.group === g.id) })).filter((g) => g.pages.length))
const sectionPath = (s) => ({ name: 'settings', params: { section: s.slug } })
// Moderation stays its own page; Safety links to it, with how many reports wait.
const showModeration = !!cfg.community.isStaff

const route = useRoute()
const router = useRouter()
// Phones: Settings is the grouped list; each page opens full screen with a back arrow.
const phoneMq = window.matchMedia('(max-width: 767px)')
const onPhone = ref(phoneMq.matches)
const onPhoneChange = (e) => (onPhone.value = e.matches)
phoneMq.addEventListener('change', onPhoneChange)
onBeforeUnmount(() => phoneMq.removeEventListener('change', onPhoneChange))

const page = computed(() => SECTIONS.value.find((s) => s.slug === route.params.section) || null)
const openId = computed(() => (onPhone.value ? page.value?.id || '' : ''))
const shownSections = computed(() => (page.value ? [page.value] : []))
// /settings alone: the list on phones; on a computer, the page you were last on (else the first).
// An address that isn't a page (an add-on's page that isn't installed) goes to Settings.
watch(
  [() => route.params.section, onPhone],
  ([slug, phone]) => {
    if (route.name !== 'settings') return
    if (slug && !page.value) return router.replace({ name: 'settings' })
    if (!slug && !phone) router.replace(sectionPath(SECTIONS.value.find((s) => s.slug === lastSettingsPage()) || SECTIONS.value[0]))
  },
  { immediate: true },
)
watch(page, (p) => p && rememberSettingsPage(p.slug), { immediate: true })

// What's inside each, in a few words, under its name in the phone list.
const summary = (id) => {
  const f = form.value
  if (!f) return ''
  const mods = (meta.value?.built_in_staff?.length || 0) + (f.moderators?.length || 0)
  return {
    general: t.__('Name, logo, color, default look', 'membuild-community'),
    website: t.__('Home, Pricing, About and more', 'membuild-community'),
    joining: t.__('How people join, login page', 'membuild-community'),
    security: meta.value?.two_step?.state === 'on' ? t.__('Two-step login is on', 'membuild-community') : t.__('Two-step login', 'membuild-community'),
    email: [{ default: t.__('Your web host', 'membuild-community'), resend: 'Resend', smtp: t.__('Another service', 'membuild-community') }[f.email?.mode] || '', t.__('test email', 'membuild-community')].filter(Boolean).join(' · '),
    features: t.__('Turn parts on and off', 'membuild-community'),
    topics: t.__('Subjects members filter Home by', 'membuild-community'),
    permissions: t.sprintf(/* translators: %d: number of moderators and admins */ t._n('%d moderator', '%d moderators', mods, 'membuild-community'), mods),
    access: t.__('Who can open locked courses', 'membuild-community'),
    points: t.__('Level names and points', 'membuild-community'),
    video: f.video?.provider === 'bunny' ? 'Bunny Stream' : t.__('This site', 'membuild-community'),
    gifs: f.gifs?.key ? t.__('Set up', 'membuild-community') : t.__('Not set up', 'membuild-community'),
    import: t.__('From another plugin or a spreadsheet', 'membuild-community'),
    addons: t.__('Extra features made by MemBuild', 'membuild-community'),
    updates: t.__('New features, usage', 'membuild-community'),
    advanced: t.__('Address, safety key', 'membuild-community'),
  }[id] ?? (addonSections.find((a) => a.id === id)?.summary || '')
}

const meta = ref(null) // labels, presets and defaults from the server
const form = ref(null)
const saved = ref('')
const saving = ref(false)
const error = ref('')
const notice = ref('')
const logoUpload = ref(null)

const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== saved.value)
const savedForm = computed(() => (saved.value ? JSON.parse(saved.value) : null))
// Boxes that save on their own (Topics, Memberships) add a check here while they hold unsaved words.
const drafts = new Set()
const hasDraft = () => [...drafts].some((d) => d.has())
provide(SETTINGS, { form, meta, saved, savedForm, dirty, logoUpload, drafts })

function take(res) {
  meta.value = res
  form.value = JSON.parse(JSON.stringify(res.settings))
  saved.value = JSON.stringify(form.value)
}

onMounted(async () => {
  try {
    take(await api.settings())
  } catch (err) {
    error.value = err.message
  }
})

// Show the color right away; leaving without saving puts the saved one back.
watch(() => form.value?.accent, (hex) => hex !== undefined && applyAccent(hex))
onBeforeUnmount(() => applyAccent(cfg.community.accent))

/* ---------- Saving ---------- */

async function save() {
  if (saving.value || logoUpload.value?.status === 'working') return
  saving.value = true
  error.value = ''
  notice.value = ''
  const oldBase = cfg.basePath
  try {
    const res = await api.saveSettings(form.value)
    take(res)
    logoUpload.value = null
    cfg.community = { ...cfg.community, ...res.config }
    cfg.siteName = res.config.name
    applyAccent(res.config.accent)
    // No logo: redraw the home-screen icon in case the name or color changed.
    if (!res.config.logo) drawIcon(initialsOf(res.config.name), res.config.accent).catch(() => {})
    if (res.base_path !== oldBase) {
      // The community moved: reload at the new address.
      window.location.href = `${res.base_path}/settings/${page.value?.slug || ''}`
      return
    }
    notice.value = t.__('Saved.', 'membuild-community')
    setTimeout(() => (notice.value = ''), 2500)
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

function discard() {
  form.value = JSON.parse(saved.value)
  logoUpload.value = null
  error.value = ''
  // Half-typed boxes go too, so a move that ends somewhere else (a redirect) doesn't ask twice.
  for (const d of drafts) d.clear?.()
}

function warnUnsaved(e) {
  if (dirty.value || hasDraft()) e.preventDefault()
}
window.addEventListener('beforeunload', warnUnsaved)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnsaved))
// Leaving a page with changes asks first: another Settings page, another part of the community,
// the browser's back button, or the back arrow on a phone. "Discard changes" drops them.
async function mayLeave() {
  if (!dirty.value && !hasDraft()) return true
  const text = dirty.value ? t.__('You changed some settings. If you leave now, those changes are lost.', 'membuild-community') : t.__('You started typing something that isn’t saved yet. If you leave now, it’s lost.', 'membuild-community')
  const ok = await askConfirm({ title: t.__('Leave without saving?', 'membuild-community'), text, confirm: t.__('Discard changes', 'membuild-community'), cancel: t.__('Keep editing', 'membuild-community'), safe: true })
  if (ok) discard()
  return ok
}
onBeforeRouteLeave(mayLeave)
onBeforeRouteUpdate((to, from) => (to.params.section === from.params.section ? true : mayLeave()))
// A new page starts clean: no message left over from the last one.
watch(page, () => {
  error.value = ''
  notice.value = ''
})

// "2 to review" on the Moderation row, while reports wait.
const toReview = computed(() => t.sprintf(/* translators: %d: number of reports waiting */ t._n('%d to review', '%d to review', meta.value?.open_reports || 0, 'membuild-community'), meta.value?.open_reports || 0))

/* ---------- Search settings (computer) ---------- */

const query = ref('')
const groupLabel = Object.fromEntries(SETTINGS_GROUPS.map((g) => [g.id, g.label]))
const results = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return []
  const out = []
  for (const s of SECTIONS.value) {
    if (s.label.toLowerCase().includes(q)) out.push({ key: s.id, label: s.label, where: groupLabel[s.group], section: s })
    for (const name of s.find) if (name.toLowerCase().includes(q)) out.push({ key: `${s.id}:${name}`, label: name, where: `${groupLabel[s.group]} › ${s.label}`, section: s, find: name })
  }
  return out.slice(0, 12)
})
// The matched words, marked inside the result.
const parts = (label) => {
  const q = query.value.trim()
  const i = label.toLowerCase().indexOf(q.toLowerCase())
  return i < 0 ? [label, '', ''] : [label.slice(0, i), label.slice(i, i + q.length), label.slice(i + q.length)]
}
const lookFor = ref('')
async function openResult(r) {
  if (!r) return
  // Another page: the move can be stopped (Keep editing), and then nothing else happens.
  if (r.section !== page.value && (await router.push(sectionPath(r.section)))) return
  query.value = ''
  if (r.find) {
    lookFor.value = r.find
    showFound()
  } else nextTick(() => requestAnimationFrame(focusPage))
}
// After opening a result: bring the setting into view, ring it for a moment and put the keyboard
// there. Sections that load their own data draw a little later, so it looks again for a second.
const FINDABLE = '.settings .body :is(h2, h3, legend, label, .label, strong, b)'
function findSetting(want) {
  const all = [...document.querySelectorAll(FINDABLE)]
  const text = (e) => e.textContent.replace(/\s+/g, ' ').trim().toLowerCase()
  // The name itself first; else the smallest piece that starts with it (a label around a field and its hint).
  return all.find((e) => text(e) === want) || all.filter((e) => text(e).startsWith(want)).sort((a, b) => text(a).length - text(b).length)[0]
}
let searchRun = 0 // a newer search, another page or leaving Settings stops an older one
onBeforeUnmount(() => searchRun++)
function showFound() {
  const want = lookFor.value.toLowerCase()
  lookFor.value = ''
  const started = Date.now()
  const run = ++searchRun
  const section = route.params.section
  let focusAtStart = null
  // Stop looking once the person has moved on: typed somewhere, clicked, or opened another page.
  const movedOn = () => run !== searchRun || route.params.section !== section || document.activeElement !== focusAtStart
  const look = () => {
    if (movedOn()) return
    const el = findSetting(want)
    if (!el) {
      if (Date.now() - started < 1500) return setTimeout(look, 100)
      return focusPage()
    }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    el.classList.add('found')
    setTimeout(() => el.classList.remove('found'), 1800)
    // The setting's own control: inside its label, field or row, never the first one on the page.
    const control = el.matches('label') ? el.control || el.querySelector('input, select, textarea, button') : el.closest('label, .field, fieldset, .row')?.querySelector('input, select, textarea, button')
    if (control) control.focus({ preventScroll: true })
    else focusPage()
  }
  nextTick(() =>
    requestAnimationFrame(() => {
      focusAtStart = document.activeElement
      look()
    }),
  )
}
// The keyboard moves to the page that just opened: its heading.
function focusPage() {
  const h = document.querySelector('.settings .body h2')
  if (!h) return
  h.setAttribute('tabindex', '-1')
  h.focus({ preventScroll: true })
}

// Search with the keyboard: Down goes into the results, Up and Down move, Escape goes back.
const findInput = ref(null)
const resultsBox = ref(null)
function moveIn(by, ev) {
  const items = [...(resultsBox.value?.querySelectorAll('.result') || [])]
  if (!items.length) return
  ev.preventDefault()
  const i = items.indexOf(document.activeElement)
  if (i < 0) return items[by > 0 ? 0 : items.length - 1].focus()
  const j = i + by
  if (j < 0) return findInput.value?.focus()
  items[Math.min(j, items.length - 1)].focus()
}
function backToSearch() {
  query.value = ''
  findInput.value?.focus()
}
const resultsSaid = computed(() => (!query.value.trim() ? '' : results.value.length ? t.sprintf(/* translators: %d: number of search results */ t._n('%d result', '%d results', results.value.length, 'membuild-community'), results.value.length) : t.__('Nothing matches. Try another word.', 'membuild-community')))

// #s-key (the safety key box) is brought into view by EncryptionKey itself.
</script>

<template>
  <!-- Phones: the grouped list of pages. -->
  <div v-if="onPhone && !openId" class="phone-list page-in">
    <h1>{{ t.__('Community settings', 'membuild-community') }}</h1>
    <p class="phone-intro">{{ t.__('Changes here affect the whole community.', 'membuild-community') }}</p>
    <div v-if="!form" class="phone-rows" aria-hidden="true"><div v-for="n in 8" :key="n" class="phone-row"><span class="skeleton" style="width: 40px; height: 40px; border-radius: 12px" /><span style="flex: 1"><span class="skeleton" style="display: block; width: 50%; height: 16px" /><span class="skeleton" style="display: block; width: 70%; height: 12px; margin-top: 8px" /></span></div></div>
    <nav v-else :aria-label="t.__('Settings', 'membuild-community')">
      <template v-for="g in GROUPS" :key="g.id">
        <h2 class="phone-group">{{ g.label }}</h2>
        <div class="phone-rows">
          <RouterLink v-for="s in g.pages" :key="s.id" class="phone-row" :to="sectionPath(s)">
            <span class="ic"><Icon :name="s.icon" :size="20" /></span>
            <span class="txt"><strong>{{ s.label }}</strong><small>{{ summary(s.id) }}</small></span>
            <Icon name="chevronRight" :size="20" />
          </RouterLink>
          <RouterLink v-if="g.id === 'safety' && showModeration" class="phone-row" :to="{ name: 'moderation' }">
            <span class="ic"><Icon name="flag" :size="20" /></span>
            <span class="txt"><strong>{{ t.__('Moderation', 'membuild-community') }}</strong><small>{{ t.__('Reports, mutes and bans', 'membuild-community') }}</small></span>
            <span v-if="meta?.open_reports" class="status-tag">{{ toReview }}</span>
            <Icon name="chevronRight" :size="20" />
          </RouterLink>
        </div>
      </template>
    </nav>
  </div>

  <div v-else class="settings" :class="{ 'one-section': openId }">
    <!-- Phones, one page: one white bar that stays at the top, a round ‹ back to the list and
         the page's name. -->
    <div v-if="openId" class="phone-head">
      <button class="back round-btn lg" :aria-label="t.__('Back to Settings', 'membuild-community')" @click="router.push({ name: 'settings' })"><Icon name="chevronLeft" :size="22" /></button>
      <h1>{{ page?.label }}</h1>
    </div>
    <aside class="nav">
      <h1>{{ t.__('Settings', 'membuild-community') }}</h1>
      <label class="section-search find">
        <Icon name="search" :size="18" />
        <input ref="findInput" v-model="query" type="search" :placeholder="t.__('Search settings', 'membuild-community')" :aria-label="t.__('Search settings', 'membuild-community')" @keydown.enter.prevent="openResult(results[0])" @keydown.esc="query = ''" @keydown.down="moveIn(1, $event)" />
      </label>
      <p class="sr-only" aria-live="polite">{{ resultsSaid }}</p>
      <nav v-if="query.trim()" ref="resultsBox" class="results" :aria-label="t.__('Search results', 'membuild-community')" @keydown.down="moveIn(1, $event)" @keydown.up="moveIn(-1, $event)" @keydown.esc="backToSearch">
        <button v-for="r in results" :key="r.key" class="result" @click="openResult(r)">
          <span>{{ parts(r.label)[0] }}<mark>{{ parts(r.label)[1] }}</mark>{{ parts(r.label)[2] }}</span>
          <small>{{ r.where }}</small>
        </button>
        <p v-if="!results.length" class="none">{{ t.__('Nothing matches. Try another word.', 'membuild-community') }}</p>
      </nav>
      <nav v-else :aria-label="t.__('Settings', 'membuild-community')">
        <template v-for="g in GROUPS" :key="g.id">
          <h2 class="group-label">{{ g.label }}</h2>
          <RouterLink v-for="s in g.pages" :key="s.id" class="item" :class="{ on: page === s }" :to="sectionPath(s)" :aria-current="page === s ? 'page' : undefined">{{ s.label }}</RouterLink>
          <RouterLink v-if="g.id === 'safety' && showModeration" class="item out" :to="{ name: 'moderation' }">
            <span>{{ t.__('Moderation', 'membuild-community') }}</span>
            <span v-if="meta?.open_reports" class="status-tag">{{ toReview }}</span>
            <Icon v-else name="arrowUpRight" :size="16" />
          </RouterLink>
        </template>
      </nav>
    </aside>

    <div class="body">
      <p v-if="!form && error" class="card state">{{ error }}</p>
      <div v-else-if="!form" class="card state">{{ t.__('Loading…', 'membuild-community') }}</div>

      <template v-else>
        <!-- display: contents, so the section still sits directly in the column. -->
        <div v-for="s in shownSections" :key="s.id" class="slot" :class="{ styled: !s.isolated }">
          <AddonSettings v-if="s.addonSection" :section="s" />
          <component :is="s.component" v-else />
        </div>
        <p v-if="page?.id === 'updates'" class="credit">MemBuild - Community · {{ t.__('Built by', 'membuild-community') }} <a href="https://membuild.com/community" target="_blank" rel="noopener">MemBuild</a></p>
      </template>
    </div>

  </div>

  <div v-if="dirty || error || notice" class="savebar card" :class="{ ok: notice && !dirty, solo: openId, listed: onPhone && !openId }">
      <span v-if="error" class="err">{{ error }}</span>
      <span v-else-if="notice && !dirty">{{ notice }}</span>
      <span v-else class="unsaved">{{ onPhone ? t.__('Unsaved changes', 'membuild-community') : t.__('You have unsaved changes', 'membuild-community') }}</span>
      <template v-if="dirty">
        <button class="btn btn-lg" :disabled="saving" @click="discard">{{ t.__('Discard', 'membuild-community') }}</button>
        <button class="btn btn-lg btn-primary" :disabled="saving || logoUpload?.status === 'working'" @click="save">{{ saving ? t.__('Saving…', 'membuild-community') : onPhone ? t.__('Save', 'membuild-community') : t.__('Save changes', 'membuild-community') }}</button>
      </template>
    </div>
</template>

<style scoped>
/* The sections are child components; their shared styles reach them through .styled. */
.slot {
  display: contents;
}

.settings {
  max-width: 1040px;
  margin: 24px auto 0;
  padding: 0 16px 120px;
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 24px;
  align-items: start;
}

.nav {
  position: sticky;
  top: calc(var(--header-h) + 16px);
  display: flex;
  flex-direction: column;
  gap: 2px;
  /* Taller than a short screen: the menu scrolls on its own, apart from the page. */
  max-height: calc(100vh - var(--header-h) - 32px);
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-width: thin;
}

.nav h1 {
  margin: 0 0 14px 12px;
  font-size: var(--fs-6xl);
}

/* The menu: pages under small grey group labels. A thin line and more room above each label than
   below it, so the label clearly sits with the rows under it; it never looks like a row. */
.nav .group-label {
  margin: 10px 0 0;
  padding: 12px 12px 6px;
  border-top: 1px solid var(--border);
  color: var(--text-3);
  font-size: var(--fs-2xs);
  font-weight: 600;
  letter-spacing: 0.07em;
  text-transform: uppercase;
}

.nav .group-label:first-of-type {
  margin-top: 4px;
  padding-top: 4px;
  border-top: 0;
}

.nav .item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 7px 12px;
  border-radius: var(--radius-sm);
  color: var(--text-2);
  font-size: var(--fs-base);
  font-weight: 600;
  text-decoration: none;
}

.nav .item:hover {
  background: var(--surface-2);
  color: var(--text);
}

/* The page you're on. */
.nav .item.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

/* Moderation opens its own page: a small arrow says it leaves Settings. */
.nav .item.out svg {
  flex: none;
  color: var(--text-3);
}

.nav nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

/* Search settings, at the top of the menu (the shared .section-search pill). */
.nav .find {
  margin: 0 0 8px;
}

.nav .result {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1px;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: var(--fs-base);
  font-weight: 600;
  text-align: left;
}

.nav .result:hover,
.nav .result:focus-visible {
  background: var(--surface-2);
}

.nav .result small {
  color: var(--text-3);
  font-size: var(--fs-xs);
  font-weight: 500;
}

.nav .result mark {
  border-radius: 3px;
  background: var(--highlight);
  color: inherit;
}

.nav .none {
  margin: 4px 12px 0;
  color: var(--text-3);
  font-size: var(--fs-sm);
}

/* The setting a search result opened: ringed for a moment. */
.body :deep(.found) {
  border-radius: 4px;
  box-shadow: 0 0 0 2px var(--accent);
  transition: box-shadow 0.3s;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

.state {
  padding: 32px;
  text-align: center;
  color: var(--text-3);
}

.styled :deep(.group) {
  padding: 28px 30px;
  border-radius: var(--radius-xl);
  scroll-margin-top: calc(var(--header-h) + 16px);
}

.styled :deep(.group h2) {
  margin: 0 0 6px;
  font-size: var(--fs-4xl);
}

.styled :deep(.group h3) {
  margin: 26px 0 12px;
  font-size: var(--fs-xl);
}

.styled :deep(.intro) {
  margin: 0 0 22px;
  color: var(--text-2);
  font-size: var(--fs-base);
}

/* A thin line between parts of a section. */
.styled :deep(.rule) {
  height: 1px;
  margin: 24px 0;
  border: 0;
  background: var(--border);
}

/* A grey box that holds what goes with the choice above it. */
.styled :deep(.sub) {
  margin: 14px 0 0;
  padding: 20px;
  border-radius: var(--radius);
  background: var(--surface-2);
}

.styled :deep(.sub .field:last-child) {
  margin-bottom: 0;
}

.styled :deep(.sub input:not([type='radio']):not([type='checkbox'])),
.styled :deep(.sub textarea),
.styled :deep(.sub select) {
  background: var(--surface);
}

/* Choice cards: a round mark, a title and a line under it. The edge, fill, picked look and mark
   come from the shared .pick-card / .pick-dot / .pick-tick (styles.css); here only the layout. */
.styled :deep(.choices) {
  display: grid;
  gap: 10px;
}

.styled :deep(.choices.cols-2) {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.styled :deep(.choices.cols-3) {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.styled :deep(.choices.cols-4) {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.styled :deep(.choice) {
  position: relative;
  display: grid;
  grid-template-columns: 22px 1fr;
  align-items: start;
  gap: 4px 12px;
  padding: 16px 18px;
  border-radius: var(--radius);
}

.styled :deep(.choice input) {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.styled :deep(.choice .mark) {
  grid-row: span 2;
  margin-top: 1px;
}

.styled :deep(.choice strong) {
  font-size: var(--fs-lg);
}

.styled :deep(.choice small) {
  grid-column: 2;
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.45;
}

/* A link in a hint is underlined: told apart from the words around it by more than colour. */
.styled :deep(small a) {
  text-decoration: underline;
}

.styled :deep(.choice.on small) {
  color: var(--text); /* on the tinted, chosen tile the grey is too faint */
}

.styled :deep(.choice:has(input:focus-visible)) {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

.styled :deep(.tag) {
  display: inline-block;
  margin-left: 8px;
  padding: 2px 8px;
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--fs-xs);
  font-weight: 700;
  vertical-align: 2px;
}

/* Tiles: an icon square at the top, the mark at the top right (Email, Who can do what). */
.styled :deep(.choice.tile) {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 16px;
}

.styled :deep(.tile-ic) {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  margin-bottom: 12px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
}

.styled :deep(.choice.tile .mark) {
  position: absolute;
  top: 14px;
  right: 14px;
}

.styled :deep(.choice.tile .tag) {
  align-self: flex-start;
  margin: 8px 0 0;
}

.styled :deep(.choice.on .tag) {
  background: var(--surface);
}

.styled :deep(.field) {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0 0 18px;
  padding: 0;
  border: 0;
  min-width: 0;
}

.styled :deep(.field:last-child) {
  margin-bottom: 0;
}

.styled :deep(.label) {
  font-size: var(--fs-lg);
  font-weight: 700;
  padding: 0;
}

.styled :deep(.label em) {
  color: var(--text-2);
  font-style: normal;
  font-weight: 400;
}

.styled :deep(small) {
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.45;
}

.styled :deep(input[type='text']),
.styled :deep(input[type='search']),
.styled :deep(input[type='number']),
.styled :deep(textarea) {
  width: 100%;
  min-height: 48px;
  padding: 11px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
}

.styled :deep(textarea) {
  resize: vertical;
}

.styled :deep(input:focus),
.styled :deep(textarea:focus) {
  outline: 2px solid var(--accent);
  outline-offset: -1px;
}

.styled :deep(.logo-row) {
  display: flex;
  align-items: center;
  gap: 12px;
}

.styled :deep(.logo-box) {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius);
  background: var(--accent);
  color: var(--accent-ink);
  overflow: hidden;
  flex: none;
}

.styled :deep(.logo-box img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.styled :deep(.initial) {
  font-size: var(--fs-5xl);
  font-weight: 800;
}

.styled :deep(.spin) {
  width: 22px;
  height: 22px;
  border: 3px solid color-mix(in srgb, var(--on-color) 40%, transparent);
  border-top-color: var(--on-color);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

/* Buttons in a settings card are the small 44px ones (the shared .btn height); the save bar's
   two use .btn-lg. */
.btn,
.styled :deep(.btn) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 44px;
  padding: 0 20px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--text);
  font-size: var(--fs-lg);
  font-weight: 700;
}

/* The grey above would otherwise win over the shared primary style, leaving dark text on grey. */
.btn.btn-primary,
.styled :deep(.btn.primary),
.styled :deep(.btn.btn-primary) {
  background: var(--accent);
  color: var(--accent-ink);
}

.btn:disabled,
.styled :deep(.btn:disabled) {
  opacity: 0.55;
  cursor: default;
}

.styled :deep(.link) {
  font-weight: 600;
  color: var(--accent-text);
}

.styled :deep(.danger) {
  color: var(--danger-text);
}

.err,
.styled :deep(.err) {
  color: var(--danger);
}

.styled :deep(.warn) {
  color: var(--warn-text);
}

.styled :deep(.swatches) {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.styled :deep(.swatch) {
  position: relative;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--shadow-color) 12%, transparent);
  cursor: pointer;
}

/* The dark preset would vanish on the dark background without a hairline. */
.styled :deep(.swatch:not(.custom)) {
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--on-color) 14%, transparent);
}

.styled :deep(.swatch.custom) {
  background: var(--surface-2);
  color: var(--text-2);
  border: 1px dashed var(--text-3);
  overflow: hidden;
}

.styled :deep(.swatch.custom.on) {
  border: 0;
  color: var(--on-color);
  filter: drop-shadow(0 0 1px color-mix(in srgb, var(--scrim) 55%, transparent));
}

.styled :deep(.swatch.custom input) {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.styled :deep(.url) {
  display: flex;
  align-items: center;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.styled :deep(.url:focus-within) {
  outline: 2px solid var(--accent);
  outline-offset: -1px;
}

.styled :deep(.prefix) {
  padding: 9px 0 9px 12px;
  color: var(--text-3);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 60%;
  flex-shrink: 0; /* the whole address shows when there's room; only long ones are cut at 60% */
}

.styled :deep(.url input) {
  flex: 1;
  min-width: 0;
  border: 0;
  padding-left: 0;
  outline: none;
}

.styled :deep(.toggle-row) {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 16px 0;
  border-top: 1px solid var(--border);
  cursor: pointer;
}

.styled :deep(.toggle-row strong) {
  font-size: var(--fs-lg);
}

.styled :deep(.toggle-row span) {
  display: flex;
  flex-direction: column;
  gap: 2px;
}






.styled :deep(fieldset:disabled) {
  opacity: 0.5;
}

.styled :deep(.radio) {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px;
  padding: 4px 0;
  cursor: pointer;
}

.styled :deep(.radio small) {
  flex-basis: 100%;
  padding-left: 24px;
}

.styled :deep(.people) {
  list-style: none;
  margin: 4px 0 0;
  padding: 0;
}

.styled :deep(.people li) {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
}

.styled :deep(.people .nm) {
  flex: 1;
  font-weight: 600;
}

.styled :deep(.people em) {
  font-style: normal;
  font-size: var(--fs-sm);
  color: var(--text-3);
}

.styled :deep(.finder) {
  position: relative;
}

.styled :deep(.found) {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  z-index: 5;
  list-style: none;
  margin: 0;
  padding: 6px;
  box-shadow: var(--shadow-pop);
}

.styled :deep(.found button) {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 6px 8px;
  border-radius: var(--radius-xs);
  font-weight: 600;
}

.styled :deep(.found button:hover) {
  background: var(--surface-2);
}

.styled :deep(.rules) {
  width: 100%;
  border-collapse: collapse;
}

.styled :deep(.rules th) {
  text-align: left;
  font-size: var(--fs-sm);
  font-weight: 650;
  color: var(--text-3);
  padding: 0 8px 6px 0;
}

.styled :deep(.rules td) {
  padding: 5px 8px 5px 0;
  border-top: 1px solid var(--border);
}

.styled :deep(.rules td:not(:first-child)),
.styled :deep(.rules th:not(:first-child)) {
  width: 110px;
  text-align: center;
}

.styled :deep(.rules td) {
  padding-top: 8px;
  padding-bottom: 8px;
  font-size: var(--fs-base);
}

.styled :deep(.note) {
  display: block;
  margin-top: 6px;
}

.styled :deep(.levels) {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 8px;
}

.styled :deep(.levels li) {
  display: grid;
  grid-template-columns: 34px 1fr 84px;
  align-items: center;
  gap: 10px;
}

.styled :deep(.lv) {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 800;
}

.styled :deep(.levels small) {
  text-align: right;
}

.savebar {
  position: fixed;
  left: 50%;
  bottom: 20px;
  z-index: 25;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 10px;
  width: min(680px, calc(100% - 32px));
  padding: 10px 12px 10px 20px;
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-pop);
  animation: rise-in 0.22s ease-out;
}

.savebar > span {
  flex: 1;
  font-size: var(--fs-lg);
  font-weight: 700;
}

/* "You have unsaved changes" gets a small orange dot. */
.savebar > span.unsaved::before {
  content: '';
  display: inline-block;
  width: 9px;
  height: 9px;
  margin-right: 10px;
  border-radius: 50%;
  background: var(--warn);
  vertical-align: 1px;
}

.savebar.ok > span {
  color: var(--ok-text);
}

.styled :deep(.toggle-row.first) {
  border-top: 0;
  padding-top: 0;
}

.styled :deep(.delivery) {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.styled :deep(.option) {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  cursor: pointer;
}

.styled :deep(.option input) {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.styled :deep(.option.on) {
  border-color: var(--accent-text);
  box-shadow: inset 0 0 0 1px var(--accent);
  background: var(--accent-soft);
}

.styled :deep(.callout) {
  margin-top: 4px;
  padding: 12px 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-md);
  line-height: 1.45;
}

.styled :deep(.callout.warn) {
  background: var(--warn-soft);
  color: var(--warn-text);
}

.styled :deep(.callout svg) {
  vertical-align: -3px;
}

.styled :deep(.callout.info) {
  background: var(--accent-soft);
  color: var(--text);
}

.styled :deep(.delivery.two) {
  grid-template-columns: repeat(2, 1fr);
}

.styled :deep(.hook) {
  display: flex;
  align-items: center;
  gap: 8px;
}

.styled :deep(.hook code) {
  flex: 1;
  min-width: 0;
  padding: 8px 10px;
  border-radius: var(--radius-xs);
  background: var(--surface-2);
  font-size: var(--fs-xs);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.styled :deep(.grid2) {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.styled :deep(select),
.styled :deep(input[type='date']),
.styled :deep(input[type='email']),
.styled :deep(input[type='url']),
.styled :deep(input[type='password']) {
  width: 100%;
  min-height: 48px;
  padding: 11px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
}

.styled :deep(.test-row) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.styled :deep(.ok) {
  color: var(--ok-text);
}

@media (prefers-color-scheme: dark) {
  .styled :deep(.callout.warn) {
    background: var(--warn-soft);
    color: var(--warn-text);
  }
}

/* Phones: the list of sections, and one section on its own page. */
.phone-list {
  padding: 16px 0 24px;
}

.phone-list h1 {
  margin: 0 16px 4px;
  font-size: var(--fs-7xl);
}

.phone-intro {
  margin: 0 16px 14px;
  color: var(--text-2);
  font-size: var(--fs-lg);
}

.phone-rows {
  display: flex;
  flex-direction: column;
  border-top: 1px solid var(--border);
}

/* The group labels over the phone list's white cards: small grey capitals, more room above. */
.phone-group {
  margin: 24px 16px 8px;
  color: var(--text-3);
  font-size: var(--fs-xs);
  font-weight: 600;
  letter-spacing: 0.07em;
  text-transform: uppercase;
}

.phone-group:first-of-type {
  margin-top: 8px;
}

.phone-row {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 66px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
  text-align: left;
  text-decoration: none;
}

.phone-row .ic {
  display: grid;
  flex: none;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
}

.phone-row .txt {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.phone-row strong {
  font-size: var(--fs-xl);
}

.phone-row small {
  color: var(--text-2);
  font-size: var(--fs-md);
}

.phone-head {
  display: none;
}

@media (max-width: 767px) {
  .one-section .nav {
    display: none;
  }

  /* The section's first card sits right under the white bar: no grey band between them. */
  .one-section {
    row-gap: 0;
  }

  /* One white bar under the app's top bar (50px on phones), like the lesson page's bar. When the
     top bar slides away on scroll (.bars-hidden), it moves up to the top of the screen. */
  .phone-head {
    position: sticky;
    top: calc(50px + env(safe-area-inset-top));
    z-index: 9;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: -12px 0 0;
    padding: 8px 16px 8px 12px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow-sm);
    transition: top 0.25s ease;
  }

  /* All of it in :global(): Vue drops the part after a lone :global(…). */
  :global(.bars-hidden .phone-head) {
    top: env(safe-area-inset-top);
  }

  .phone-head h1 {
    min-width: 0;
    margin: 0;
    overflow: hidden;
    font-size: var(--fs-xl);
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* The page's own title stands in for the section's. */
  .one-section .styled :deep(.group:not(.danger-card) > h2) {
    display: none;
  }

  .one-section .styled :deep(.group > .intro:first-of-type) {
    margin-top: 0;
  }

  /* The save bar sits where the bottom bar was. */
  .savebar {
    left: 0;
    bottom: 0;
    width: 100%;
    transform: none;
    padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
    border-radius: 0;
  }

  .styled :deep(.delivery),
  .styled :deep(.delivery.two),
  .styled :deep(.grid2) {
    grid-template-columns: 1fr;
  }

  .settings {
    grid-template-columns: 1fr;
    margin-top: 0;
    padding: 12px 0 140px;
  }

  .nav {
    position: static;
    flex-direction: row;
    flex-wrap: wrap;
    padding: 0 12px;
  }

  .nav h1 {
    flex-basis: 100%;
  }

  .styled :deep(.group) {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
    padding: 18px 16px;
  }

  .styled :deep(.levels li) {
    grid-template-columns: 30px 1fr;
  }

  .styled :deep(.levels small) {
    grid-column: 2;
    text-align: left;
  }

  .savebar.listed {
    bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom));
  }

  .styled :deep(.choices.cols-2),
  .styled :deep(.choices.cols-3) {
    grid-template-columns: 1fr;
  }

  /* Main color: all nine circles on one line (down to 320 wide: the gap shrinks below 375), each
     with a 44px tap area (.tap-44). Nine 30px circles and eight gaps fit the screen less 32px. */
  .styled :deep(.swatches) {
    gap: clamp(2px, calc((100vw - 304px) / 8), 8px);
  }

  .styled :deep(.swatch) {
    width: 30px;
    height: 30px;
  }

  .styled :deep(.choices.cols-4) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.credit {
  margin: 8px 0 0;
  color: var(--text-3);
  font-size: var(--fs-sm);
  text-align: center;
}

.credit a {
  color: var(--text-2);
  font-weight: 600;
}
</style>
