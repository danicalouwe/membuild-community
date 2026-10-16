<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
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
  { id: 'general', label: t.__('Your community', 'membuild-community'), component: GeneralSection },
  { id: 'joining', label: t.__('Joining & signing in', 'membuild-community'), component: JoiningSection },
  { id: 'email', label: t.__('Email', 'membuild-community'), component: EmailSection },
  { id: 'features', label: t.__('Features', 'membuild-community'), component: FeaturesSection },
  { id: 'permissions', label: t.__('Who can do what', 'membuild-community'), component: PermissionsSection },
  { id: 'access', label: t.__('Member groups', 'membuild-community'), component: AccessSection, isolated: true },
  { id: 'points', label: t.__('Points & levels', 'membuild-community'), component: PointsSection },
  { id: 'video', label: t.__('Video', 'membuild-community'), component: VideoSection },
  { id: 'gifs', label: t.__('GIFs', 'membuild-community'), component: GifsSection },
  { id: 'updates', label: t.__('Updates & usage', 'membuild-community'), component: UpdatesSection },
  { id: 'advanced', label: t.__('Advanced', 'membuild-community'), component: AdvancedSection },
]

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

const route = useRoute()
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
onBeforeRouteLeave(() => !dirty.value || confirm(t.__('Leave without saving your changes?', 'membuild-community')))

const scrollTo = (id) => document.getElementById(`s-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
</script>

<template>
  <div class="settings">
    <aside class="nav">
      <h1>{{ t.__('Settings', 'membuild-community') }}</h1>
      <button v-for="s in SECTIONS" :key="s.id" @click="scrollTo(s.id)">{{ s.label }}</button>
    </aside>

    <div class="body">
      <p v-if="!form && error" class="card state">{{ error }}</p>
      <div v-else-if="!form" class="card state">{{ t.__('Loading…', 'membuild-community') }}</div>

      <template v-else>
        <!-- display: contents, so each section still sits directly in the column. -->
        <div v-for="s in SECTIONS" :key="s.id" class="slot" :class="{ styled: !s.isolated }">
          <component :is="s.component" />
        </div>
        <p class="credit">MemBuild - Community · {{ t.__('Built by', 'membuild-community') }} <a href="https://membuild.com/community" target="_blank" rel="noopener">MemBuild</a></p>
      </template>
    </div>

    <div v-if="dirty || error || notice" class="savebar card" :class="{ ok: notice && !dirty }">
      <span v-if="error" class="err">{{ error }}</span>
      <span v-else-if="notice && !dirty">{{ notice }}</span>
      <span v-else>{{ t.__('You have unsaved changes.', 'membuild-community') }}</span>
      <template v-if="dirty">
        <button class="btn" :disabled="saving" @click="discard">{{ t.__('Discard', 'membuild-community') }}</button>
        <button class="btn btn-primary" :disabled="saving || logoUpload?.status === 'working'" @click="save">{{ saving ? t.__('Saving…', 'membuild-community') : t.__('Save changes', 'membuild-community') }}</button>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* The sections are child components; their shared styles reach them through .styled. */
.slot {
  display: contents;
}

.settings {
  max-width: 980px;
  margin: 16px auto 0;
  padding: 0 16px 96px;
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 20px;
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
  margin: 0 0 10px 10px;
  font-size: 24px;
}

.nav button {
  padding: 9px 12px;
  border-radius: 10px;
  text-align: left;
  font-weight: 600;
  color: var(--text-2);
}

.nav button:hover {
  background: var(--surface-2);
  color: var(--text);
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
  padding: 20px 22px;
  scroll-margin-top: calc(var(--header-h) + 16px);
}

.styled :deep(.group h2) {
  margin: 0 0 14px;
  font-size: 19px;
}

.styled :deep(.group h3) {
  margin: 22px 0 10px;
  font-size: 16px;
}

.styled :deep(.intro) {
  margin: -6px 0 14px;
  color: var(--text-2);
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
  font-weight: 650;
  padding: 0;
}

.styled :deep(small) {
  color: var(--text-3);
  font-size: 13px;
}

.styled :deep(input[type='text']),
.styled :deep(input[type='search']),
.styled :deep(input[type='number']),
.styled :deep(textarea) {
  width: 100%;
  padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
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
  border-radius: 14px;
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
  font-size: 24px;
  font-weight: 800;
}

.styled :deep(.spin) {
  width: 22px;
  height: 22px;
  border: 3px solid rgb(255 255 255 / 40%);
  border-top-color: #fff;
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
  padding: 8px 14px;
  border-radius: 10px;
  background: var(--surface-2);
  font-weight: 650;
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
  color: #c77700;
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
  box-shadow: inset 0 0 0 1px rgb(0 0 0 / 12%);
  cursor: pointer;
}

/* The dark preset would vanish on the dark background without a hairline. */
.styled :deep(.swatch:not(.custom)) {
  box-shadow: inset 0 0 0 1px rgb(255 255 255 / 14%);
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
  color: #fff;
  filter: drop-shadow(0 0 1px rgb(0 0 0 / 55%));
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
  border-radius: 10px;
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
  gap: 16px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
  cursor: pointer;
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
  width: 44px;
  height: 26px;
  border-radius: 999px;
  background: var(--border);
  cursor: pointer;
  transition: background 0.15s;
}

.styled :deep(.switch::after) {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgb(0 0 0 / 25%);
  transition: transform 0.15s;
}

.styled :deep(.switch:checked) {
  background: var(--accent);
}

.styled :deep(.switch:checked::after) {
  transform: translateX(18px);
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
  font-size: 13px;
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
  box-shadow: 0 8px 24px rgb(0 0 0 / 15%);
}

.styled :deep(.found button) {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 6px 8px;
  border-radius: 8px;
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
  font-size: 13px;
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
  grid-template-columns: 34px 1fr 120px;
  align-items: center;
  gap: 10px;
}

.styled :deep(.lv) {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
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
  width: min(620px, calc(100% - 32px));
  padding: 10px 12px 10px 18px;
  box-shadow: 0 12px 28px rgb(0 0 0 / 18%);
}

.savebar > span {
  flex: 1;
  font-weight: 600;
}

.savebar.ok > span {
  color: #1fa463;
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
  border-radius: 12px;
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
  border-radius: 10px;
  font-size: 14px;
  line-height: 1.45;
}

.styled :deep(.callout.warn) {
  background: #fff3d6;
  color: #6b4700;
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
  border-radius: 8px;
  background: var(--surface-2);
  font-size: 12px;
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
  padding: 9px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
}

.styled :deep(.test-row) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.styled :deep(.ok) {
  color: #1fa463;
}

@media (prefers-color-scheme: dark) {
  .styled :deep(.callout.warn) {
    background: #3a2c0b;
    color: #f5c451;
  }
}

@media (max-width: 767px) {
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

  .savebar {
    bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom) + 10px);
  }
}
.credit {
  margin: 8px 0 0;
  color: var(--text-3);
  font-size: 13px;
  text-align: center;
}

.credit a {
  color: var(--text-2);
  font-weight: 600;
}
</style>
