<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import { onBeforeRouteLeave } from 'vue-router'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { applyAccent } from '../lib/theme.js'
import { drawIcon } from '../lib/pwa.js'
import { initialsOf } from '../config.js'
import { SETTINGS } from '../components/settings/context.js'
import GeneralSection from '../components/settings/GeneralSection.vue'
import FeaturesSection from '../components/settings/FeaturesSection.vue'
import PermissionsSection from '../components/settings/PermissionsSection.vue'
import AccessSection from '../components/settings/AccessSection.vue'
import PointsSection from '../components/settings/PointsSection.vue'
import EmailSection from '../components/settings/EmailSection.vue'
import VideoSection from '../components/settings/VideoSection.vue'
import GifsSection from '../components/settings/GifsSection.vue'
import JoiningSection from '../components/settings/JoiningSection.vue'
import UpdatesSection from '../components/settings/UpdatesSection.vue'
import AdvancedSection from '../components/settings/AdvancedSection.vue'
import { t } from '../lib/i18n.js'

/**
 * Community settings for admins: one form, saved together from the bar at the bottom.
 * Each section is its own component sharing the form through provide/inject.
 * This list is the one place that sets their order (and the side nav's).
 * `isolated`: the section styles itself, because the shared section styles
 * would reach into the component it embeds.
 */
// In the order a new owner needs them: who you are, how people join, email
// (so password emails arrive before you invite anyone), then the rest.
const SECTIONS = [
  { id: 'general', icon: 'home', label: t.__('Your community', 'membuild-community'), component: GeneralSection },
  { id: 'joining', icon: 'key', label: t.__('Joining & signing in', 'membuild-community'), component: JoiningSection },
  { id: 'email', icon: 'mail', label: t.__('Email', 'membuild-community'), component: EmailSection },
  { id: 'features', icon: 'grid', label: t.__('Features', 'membuild-community'), component: FeaturesSection },
  { id: 'permissions', icon: 'shield', label: t.__('Who can do what', 'membuild-community'), component: PermissionsSection },
  { id: 'access', icon: 'lock', label: t.__('Memberships', 'membuild-community'), component: AccessSection, isolated: true },
  { id: 'points', icon: 'trophy', label: t.__('Points & levels', 'membuild-community'), component: PointsSection },
  { id: 'video', icon: 'video', label: t.__('Video', 'membuild-community'), component: VideoSection },
  { id: 'gifs', icon: 'gif', label: t.__('GIFs', 'membuild-community'), component: GifsSection },
  { id: 'updates', icon: 'bell', label: t.__('Updates & usage', 'membuild-community'), component: UpdatesSection },
  { id: 'advanced', icon: 'settings', label: t.__('Advanced', 'membuild-community'), component: AdvancedSection },
]
// Phones: Settings is a list of sections; each opens on its own page (?s=email).
const phoneMq = window.matchMedia('(max-width: 767px)')
const onPhone = ref(phoneMq.matches)
phoneMq.addEventListener('change', (e) => (onPhone.value = e.matches))
const router = useRouter()
const openId = computed(() => (onPhone.value ? route.query.s || '' : ''))
const shownSections = computed(() => (openId.value ? SECTIONS.filter((s) => s.id === openId.value) : SECTIONS))
const openSection = (id) => router.push({ query: { s: id } })
// What's inside each, in a few words, under its name in the phone list.
const summary = (id) => {
  const f = form.value
  if (!f) return ''
  const mods = (meta.value?.built_in_staff?.length || 0) + (f.moderators?.length || 0)
  return {
    general: t.__('Name, logo, colour, default look', 'membuild-community'),
    joining: t.__('How people join, login page', 'membuild-community'),
    email: [{ default: t.__('Your web host', 'membuild-community'), resend: 'Resend', smtp: t.__('Another service', 'membuild-community') }[f.email?.mode] || '', t.__('test email', 'membuild-community')].filter(Boolean).join(' · '),
    features: t.__('Turn parts on and off', 'membuild-community'),
    permissions: t.sprintf(/* translators: %d: number of moderators and admins */ t._n('%d moderator', '%d moderators', mods, 'membuild-community'), mods),
    access: t.__('Who can open locked courses', 'membuild-community'),
    points: t.__('Level names and points', 'membuild-community'),
    video: f.video?.provider === 'bunny' ? 'Bunny Stream' : t.__('This site', 'membuild-community'),
    gifs: f.gifs?.key ? t.__('Set up', 'membuild-community') : t.__('Not set up', 'membuild-community'),
    updates: t.__('New features, usage', 'membuild-community'),
    advanced: t.__('Address, safety key', 'membuild-community'),
  }[id]
}

const route = useRoute()
const meta = ref(null) // labels, presets and defaults from the server
const form = ref(null)
const saved = ref('')
const saving = ref(false)
const error = ref('')
const notice = ref('')
const logoUpload = ref(null)

const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== saved.value)
const savedForm = computed(() => (saved.value ? JSON.parse(saved.value) : null))
provide(SETTINGS, { form, meta, saved, savedForm, dirty, logoUpload })

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
  // Links like /settings#s-access open at that section once the form is drawn.
  if (route.hash) nextTick(() => document.querySelector(route.hash)?.scrollIntoView({ block: 'start' }))
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
      window.location.href = `${res.base_path}/settings`
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
}

function warnUnsaved(e) {
  if (dirty.value) e.preventDefault()
}
window.addEventListener('beforeunload', warnUnsaved)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnUnsaved))
onBeforeRouteLeave(async () => !dirty.value || (await askConfirm({ title: t.__('Leave without saving?', 'membuild-community'), text: t.__('You changed some settings. If you leave now, those changes are lost.', 'membuild-community'), confirm: t.__('Discard changes', 'membuild-community'), cancel: t.__('Keep editing', 'membuild-community'), safe: true })))

const scrollTo = (id) => document.getElementById(`s-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
// The side list marks the section you're looking at.
const current = ref('general')
let spy = null
watch(form, (f, old) => {
  if (!f || old) return
  nextTick(() => {
    spy = new IntersectionObserver((entries) => {
      const seen = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0]
      if (seen) current.value = seen.target.id.replace('s-', '')
    }, { rootMargin: '-20% 0px -70% 0px' })
    SECTIONS.forEach((s) => { const el = document.getElementById(`s-${s.id}`); if (el) spy.observe(el) })
  })
})
onBeforeUnmount(() => spy?.disconnect())
</script>

<template>
  <!-- Phones: the list of sections. -->
  <div v-if="onPhone && !openId" class="phone-list page-in">
    <h1>{{ t.__('Community settings', 'membuild-community') }}</h1>
    <p class="phone-intro">{{ t.__('Changes here affect the whole community.', 'membuild-community') }}</p>
    <div v-if="!form" class="phone-rows" aria-hidden="true"><div v-for="n in 8" :key="n" class="phone-row"><span class="skeleton" style="width: 40px; height: 40px; border-radius: 12px" /><span style="flex: 1"><span class="skeleton" style="display: block; width: 50%; height: 16px" /><span class="skeleton" style="display: block; width: 70%; height: 12px; margin-top: 8px" /></span></div></div>
    <nav v-else class="phone-rows">
      <button v-for="s in SECTIONS" :key="s.id" class="phone-row" @click="openSection(s.id)">
        <span class="ic"><Icon :name="s.icon" :size="20" /></span>
        <span class="txt"><strong>{{ s.label }}</strong><small>{{ summary(s.id) }}</small></span>
        <Icon name="chevronRight" :size="20" />
      </button>
    </nav>
  </div>

  <div v-else class="settings" :class="{ 'one-section': openId }">
    <!-- Phones, one section: ‹ Settings, then its name. -->
    <div v-if="openId" class="phone-head">
      <button class="back" @click="router.push({ query: {} })"><Icon name="chevronLeft" :size="18" /> {{ t.__('Settings', 'membuild-community') }}</button>
      <h1>{{ SECTIONS.find((s) => s.id === openId)?.label }}</h1>
    </div>
    <aside class="nav">
      <h1>{{ t.__('Settings', 'membuild-community') }}</h1>
      <button v-for="s in SECTIONS" :key="s.id" :class="{ on: current === s.id }" @click="scrollTo(s.id)">{{ s.label }}</button>
    </aside>

    <div class="body">
      <p v-if="!form && error" class="card state">{{ error }}</p>
      <div v-else-if="!form" class="card state">{{ t.__('Loading…', 'membuild-community') }}</div>

      <template v-else>
        <!-- display: contents, so each section still sits directly in the column. -->
        <div v-for="s in shownSections" :key="s.id" class="slot" :class="{ styled: !s.isolated }">
          <component :is="s.component" />
        </div>
        <p v-if="!openId" class="credit">MemBuild - Community · {{ t.__('Built by', 'membuild-community') }} <a href="https://membuild.com/community" target="_blank" rel="noopener">MemBuild</a></p>
      </template>
    </div>

  </div>

  <div v-if="dirty || error || notice" class="savebar card" :class="{ ok: notice && !dirty, solo: openId, listed: onPhone && !openId }">
      <span v-if="error" class="err">{{ error }}</span>
      <span v-else-if="notice && !dirty">{{ notice }}</span>
      <span v-else class="unsaved">{{ onPhone ? t.__('Unsaved changes', 'membuild-community') : t.__('You have unsaved changes', 'membuild-community') }}</span>
      <template v-if="dirty">
        <button class="btn" :disabled="saving" @click="discard">{{ t.__('Discard', 'membuild-community') }}</button>
        <button class="btn btn-primary" :disabled="saving || logoUpload?.status === 'working'" @click="save">{{ saving ? t.__('Saving…', 'membuild-community') : onPhone ? t.__('Save', 'membuild-community') : t.__('Save changes', 'membuild-community') }}</button>
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
}

.nav h1 {
  margin: 0 0 14px 12px;
  font-size: var(--fs-6xl);
}

.nav button {
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  text-align: left;
  font-size: var(--fs-base);
  font-weight: 600;
  color: var(--text-2);
}

.nav button:hover {
  background: var(--surface-2);
  color: var(--text);
}

/* The section in view. */
.nav button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
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

/* Choice cards: a round mark, a title and a line under it. Picked: the main colour's soft fill. */
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
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  cursor: pointer;
}

.styled :deep(.choice input) {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.styled :deep(.choice .mark) {
  grid-row: span 2;
  width: 20px;
  height: 20px;
  margin-top: 1px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
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

.styled :deep(.choice.on) {
  border: 2px solid var(--accent);
  padding: 15px 17px;
  background: var(--accent-soft);
}

.styled :deep(.choice.on .mark) {
  border-color: var(--text);
  background: radial-gradient(circle, var(--surface) 0 28%, var(--text) 31%);
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

.styled :deep(.choice.tile.on) {
  padding: 15px;
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

.styled :deep(.choice.tile.on) {
  border-color: var(--text);
  background: var(--surface);
}

.styled :deep(.choice.tile.on .mark) {
  border-color: var(--text);
  background: var(--text) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12.5l4.5 4.5L19 7.5'/%3E%3C/svg%3E") center / 12px no-repeat;
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

.btn,
.styled :deep(.btn) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 48px;
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
  color: var(--danger);
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

.styled :deep(.swatch.on) {
  box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text);
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
}

.styled :deep(.url input) {
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

.styled :deep(.switch) {
  appearance: none;
  flex: none;
  position: relative;
  width: 52px;
  height: 30px;
  border-radius: var(--radius-pill);
  background: var(--border);
  cursor: pointer;
  transition: background 0.15s;
}

.styled :deep(.switch::after) {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--on-color);
  box-shadow: var(--shadow-sm);
  transition: transform 0.15s;
}

.styled :deep(.switch:checked) {
  background: var(--accent);
}

.styled :deep(.switch:checked::after) {
  transform: translateX(22px);
}

.styled :deep(.switch:focus-visible) {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
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

  .phone-head {
    display: block;
    padding: 0 16px 12px;
  }

  .phone-head .back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 52px;
    color: var(--text);
    font-size: var(--fs-xl);
    font-weight: 700;
  }

  .phone-head h1 {
    margin: 0;
    font-size: var(--fs-7xl);
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
