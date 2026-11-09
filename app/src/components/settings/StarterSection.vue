<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api.js'
import { askConfirm } from '../../lib/confirm.js'
import Icon from '../Icon.vue'
import ColorPick from '../ColorPick.vue'
import { t } from '../../lib/i18n.js'

/**
 * Main website: ready-made pages for the rest of the site (Home, Pricing, About…), made of
 * WordPress blocks in the community's look. Nothing is made until the owner presses the
 * button, and Remove puts the old front page, header and footer back.
 * Its own action, not part of the Save bar.
 */
const state = ref(null)
const picked = ref([])
const front = ref(true)
const parts = ref(true)
const busy = ref(false)
const error = ref('')
// The three questions: written into every page. Skipped, the pages get neutral example words.
const answers = ref({ topic: '', who: '', gets: '' })
// The main colour is the community's own (Settings › General); the starter pages' lime when none is picked.
const LIME = '#c8f542'
const accent = ref('')
const accentChanged = ref(false)
const swatches = computed(() => [LIME, ...(state.value?.accents || []).filter((h) => h !== LIME)].slice(0, 7))
const colour = computed({
  get: () => accent.value || LIME,
  set: (v) => {
    accent.value = v || LIME
    accentChanged.value = true
  },
})
const logoName = ref('auto')
const logoShowsName = computed(() => (logoName.value === 'auto' ? !!state.value?.logoWide : logoName.value === 'yes'))
const refundDays = ref(14)

onMounted(async () => {
  state.value = await api.starterPages().catch(() => null)
  // All ticked, except a page the site already has.
  picked.value = (state.value?.pages || []).filter((p) => !p.have).map((p) => p.slug)
  takeLook()
  if (state.value?.made) loadSoon()
})
function takeLook() {
  if (!state.value) return
  accent.value = state.value.accent || ''
  logoName.value = state.value.logoName || 'auto'
  refundDays.value = state.value.refundDays ?? 14
}

const made = computed(() => state.value?.pages.filter((p) => p.id) || [])
// Pages added in an update (Events, Blog…), not made yet: they can be added on their own.
const notMade = computed(() => state.value?.pages.filter((p) => !p.id) || [])
const pickedNew = computed(() => picked.value.filter((s) => notMade.value.some((p) => p.slug === s)))
const toggle = (slug) => (picked.value = picked.value.includes(slug) ? picked.value.filter((s) => s !== slug) : [...picked.value, slug])

async function create(example = false) {
  busy.value = true
  error.value = ''
  const body = { pages: picked.value, front: front.value && picked.value.includes('home'), parts: parts.value }
  if (!state.value.made) {
    if (!example) body.answers = answers.value
    if (accentChanged.value) body.accent = colour.value
    body.logoName = logoName.value
  }
  try {
    state.value = await api.makeStarterPages(body)
    picked.value = state.value.pages.filter((p) => !p.id && !p.have).map((p) => p.slug)
    takeLook()
    loadSoon()
  } catch (e) {
    error.value = e.message || t.__('The pages weren’t made. Try again in a moment.', 'membuild-community')
  }
  busy.value = false
}

// After: the look, saved as it changes.
let lookTimer = 0
function saveLook(body) {
  clearTimeout(lookTimer)
  lookTimer = setTimeout(async () => {
    error.value = ''
    try {
      state.value = await api.starterLook(body)
    } catch (e) {
      error.value = e.message || t.__('That wasn’t saved. Try again in a moment.', 'membuild-community')
    }
  }, 500)
}
function pickColour(v) {
  colour.value = v
  saveLook({ accent: colour.value })
}
function setLogoName(on) {
  logoName.value = on ? 'yes' : 'no'
  saveLook({ logoName: logoName.value })
}
function setRefund(v) {
  refundDays.value = Math.max(0, Math.min(90, parseInt(v, 10) || 0))
  saveLook({ refundDays: refundDays.value })
}
async function putPartsBack() {
  busy.value = true
  error.value = ''
  try {
    state.value = await api.starterParts()
  } catch (e) {
    error.value = e.message || t.__('The header and footer weren’t put back. Try again in a moment.', 'membuild-community')
  }
  busy.value = false
}

/* ---------- Coming soon: a waiting list before the doors open ---------- */
const soon = ref(null)
async function loadSoon() {
  soon.value = await api.comingSoon().catch(() => null)
}
async function saveSoon(body) {
  error.value = ''
  try {
    soon.value = await api.saveComingSoon(body)
  } catch (e) {
    error.value = e.message || t.__('That wasn’t saved. Try again in a moment.', 'membuild-community')
  }
}
async function downloadList() {
  const rows = await api.comingSoonList().catch(() => null)
  if (!rows) return
  const cell = (v) => '"' + String(v).replace(/"/g, '""') + '"'
  const csv = ['email,joined', ...rows.map((r) => cell(r.email) + ',' + cell(r.joined))].join('\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
  a.download = 'waiting-list.csv'
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}
async function openDoors() {
  const ok = await askConfirm({
    title: t.__('Open the doors now?', 'membuild-community'),
    text: soon.value.email ? t.__('Coming soon goes off, everyone sees the whole site, and the people waiting get your “We’re open” email.', 'membuild-community') : t.__('Coming soon goes off and everyone sees the whole site.', 'membuild-community'),
    confirm: t.__('Open the doors', 'membuild-community'),
    cancel: t.__('Not yet', 'membuild-community'),
  })
  if (!ok) return
  busy.value = true
  try {
    soon.value = await api.openDoors()
  } catch (e) {
    error.value = e.message || t.__('That didn’t work. Try again in a moment.', 'membuild-community')
  }
  busy.value = false
}
const peopleOnList = (n) => t.sprintf(/* translators: %d: number of people */ t._n('%d person on the list', '%d people on the list', n, 'membuild-community'), n)

async function remove() {
  const n = made.value.length
  const ok = await askConfirm({
    title: t.__('Remove the starter pages?', 'membuild-community'),
    text: t.sprintf(/* translators: %d: number of pages */ t._n('The %d page moves to the bin, and your old front page, header, footer and layouts come back.', 'The %d pages move to the bin, and your old front page, header, footer and layouts come back.', n, 'membuild-community'), n),
    confirm: t.__('Remove', 'membuild-community'),
    cancel: t.__('Keep them', 'membuild-community'),
  })
  if (!ok) return
  busy.value = true
  error.value = ''
  try {
    state.value = await api.removeStarterPages()
    picked.value = state.value.pages.filter((p) => !p.have).map((p) => p.slug)
    takeLook()
    soon.value = null
  } catch (e) {
    error.value = e.message || t.__('The pages weren’t removed. Try again in a moment.', 'membuild-community')
  }
  busy.value = false
}
</script>

<template>
  <section id="s-website" class="card group">
    <h2>{{ t.__('Main website', 'membuild-community') }}</h2>

    <template v-if="!state">
      <p class="intro">{{ t.__('Loading…', 'membuild-community') }}</p>
    </template>

    <!-- After: the pages, each with Edit and View, and the undo. -->
    <template v-else-if="state.made">
      <p class="sp-done" role="status">
        <Icon name="check" :size="18" />
        <span>{{ state.front ? t.sprintf(t._n('%d page made, and Home is your front page.', '%d pages made, and Home is your front page.', made.length, 'membuild-community'), made.length) : t.sprintf(t._n('%d page made.', '%d pages made.', made.length, 'membuild-community'), made.length) }}</span>
      </p>
      <ul class="sp-made">
        <li v-for="p in made" :key="p.slug">
          <span class="sp-words">
            <strong>{{ p.title }}</strong>
            <small v-if="p.kind === 'layout'">{{ t.__('A layout, edited in Appearance › Editor', 'membuild-community') }}</small>
            <small v-else-if="p.note">{{ p.note }}</small>
          </span>
          <a :href="p.edit" class="sp-link">{{ t.__('Edit', 'membuild-community') }}</a>
          <a :href="p.view" class="sp-link" target="_blank" rel="noopener">{{ t.__('View', 'membuild-community') }}</a>
        </li>
      </ul>
      <template v-if="notMade.length">
        <hr class="rule" />
        <h3 class="sp-sub">{{ t.__('New pages you can add', 'membuild-community') }}</h3>
        <div class="sp-pages">
          <label v-for="p in notMade" :key="p.slug" class="sp-tick">
            <input type="checkbox" :checked="picked.includes(p.slug)" @change="toggle(p.slug)" />
            <span class="sp-mark tick-box"><Icon name="check" :size="14" /></span>
            <span class="sp-words">
              <strong>{{ p.title }}</strong>
              <small v-if="p.have">{{ p.kind === 'layout' ? t.__('You already have your own.', 'membuild-community') : t.__('You already have one.', 'membuild-community') }} <a :href="p.have" target="_blank" rel="noopener">{{ t.__('See it', 'membuild-community') }}</a></small>
              <small v-else-if="p.note">{{ p.note }}</small>
            </span>
          </label>
        </div>
        <div class="sp-actions">
          <button type="button" class="btn btn-lg" :disabled="busy || !pickedNew.length" @click="create">{{ busy ? t.__('Making your pages…', 'membuild-community') : t.__('Add these pages', 'membuild-community') }}</button>
          <small class="sp-note">{{ t.__('The pages you already have stay as they are. New pages join the menu after Courses.', 'membuild-community') }}</small>
        </div>
        <hr class="rule" />
      </template>
      <p v-if="state.partsGone" class="sp-warn sp-parts">
        <span>{{ t.__('Changed your theme? Your new theme has its own header and footer.', 'membuild-community') }}</span>
        <button type="button" class="btn" :disabled="busy" @click="putPartsBack">{{ t.__('Put the header and footer back', 'membuild-community') }}</button>
      </p>

      <!-- The look: the main colour, the logo, the money-back promise. -->
      <h3 class="sp-sub">{{ t.__('Color and logo', 'membuild-community') }}</h3>
      <ColorPick :model-value="colour === swatches[0] ? '' : colour" :accents="swatches" :label="t.__('Main color', 'membuild-community')" @update:model-value="pickColour" />
      <small class="sp-note">{{ t.__('Also your community’s main color (Settings › Your community). Buttons get dark or white words, whichever reads best.', 'membuild-community') }}</small>
      <label v-if="state.logo" class="toggle-row sp-logo">
        <img :src="state.logo" alt="" class="sp-logo-img" />
        <span>
          <strong>{{ t.__('My logo already shows the name', 'membuild-community') }}</strong>
          <small>{{ t.__('We guess from the logo’s shape; this fixes a wrong guess. On, the header shows the logo alone.', 'membuild-community') }}</small>
        </span>
        <input type="checkbox" class="switch" role="switch" :checked="logoShowsName" @change="setLogoName($event.target.checked)" />
      </label>
      <label class="field sp-refund">
        <span class="label">{{ t.__('Money-back promise', 'membuild-community') }}</span>
        <span class="sp-days"><input type="number" min="0" max="90" inputmode="numeric" :value="refundDays" @change="setRefund($event.target.value)" /> {{ t.__('days', 'membuild-community') }}</span>
        <small>{{ refundDays ? t.sprintf(/* translators: %d: number of days */ t.__('Your plans, questions, Refunds page and checkout all say “%d-day money-back”.', 'membuild-community'), refundDays) : t.__('0: no promise is shown, and the Refunds page says payments aren’t refunded.', 'membuild-community') }}</small>
      </label>
      <hr class="rule" />

      <!-- Coming soon: visitors see one page with an email box until the doors open. -->
      <h3 class="sp-sub">{{ t.__('Coming soon', 'membuild-community') }}</h3>
      <p v-if="!soon" class="sp-note">{{ t.__('Loading…', 'membuild-community') }}</p>
      <p v-else-if="!soon.page" class="sp-note">{{ t.__('Add the Coming soon page above to collect emails before you open.', 'membuild-community') }}</p>
      <template v-else>
        <label class="toggle-row">
          <span>
            <strong>{{ t.__('Show “Coming soon” to visitors', 'membuild-community') }}</strong>
            <small>{{ t.__('You and your team still see the whole site. Log in keeps working.', 'membuild-community') }}</small>
          </span>
          <input type="checkbox" class="switch" role="switch" :checked="soon.on" @change="saveSoon({ on: $event.target.checked })" />
        </label>
        <label class="field">
          <span class="label">{{ t.__('Opening day (optional)', 'membuild-community') }}</span>
          <input type="date" class="sp-date" :value="soon.opens" @change="saveSoon({ opens: $event.target.value })" />
        </label>
        <div class="sp-list">
          <span class="sp-words">
            <strong>{{ peopleOnList(soon.count) }}</strong>
            <small v-if="soon.newest">{{ t.sprintf(/* translators: 1: an email address, 2: how long ago, like "2 hours ago" */ t.__('Newest: %1$s, %2$s', 'membuild-community'), soon.newest.email, soon.newest.ago) }}</small>
            <small v-else>{{ t.__('Nobody yet. Emails stay in your community; nothing is sent anywhere else.', 'membuild-community') }}</small>
          </span>
          <button type="button" class="btn" :disabled="!soon.count" @click="downloadList">{{ t.__('Download CSV', 'membuild-community') }}</button>
        </div>
        <fieldset class="field">
          <legend class="label">{{ t.__('On opening day', 'membuild-community') }}</legend>
          <label class="sp-tick">
            <input type="checkbox" :checked="soon.email" @change="saveSoon({ email: $event.target.checked })" />
            <span class="sp-mark tick-box"><Icon name="check" :size="14" /></span>
            <span class="sp-words"><strong>{{ t.__('Email everyone “We’re open” with a Join link', 'membuild-community') }}</strong></span>
          </label>
          <label class="sp-tick">
            <input type="checkbox" :checked="soon.autoOff" @change="saveSoon({ autoOff: $event.target.checked })" />
            <span class="sp-mark tick-box"><Icon name="check" :size="14" /></span>
            <span class="sp-words"><strong>{{ t.__('Turn Coming soon off by itself', 'membuild-community') }}</strong></span>
          </label>
        </fieldset>
        <div class="sp-actions">
          <button type="button" class="btn btn-primary btn-lg" :disabled="busy || !soon.on" @click="openDoors">{{ t.__('Open the doors now', 'membuild-community') }}</button>
          <a :href="soon.page.view" class="btn btn-lg" target="_blank" rel="noopener">{{ t.__('Preview the page', 'membuild-community') }}</a>
        </div>
      </template>
      <hr class="rule" />

      <div class="sp-actions">
        <a :href="state.site" class="btn btn-lg" target="_blank" rel="noopener">{{ t.__('See your website', 'membuild-community') }} <Icon name="external" :size="15" /></a>
        <button type="button" class="sp-undo" :disabled="busy" @click="remove">{{ t.__('Remove the starter pages', 'membuild-community') }}</button>
      </div>
      <small class="sp-note">{{ t.__('Removing puts back your old front page, header and footer. Pages you’ve edited move to the bin, so nothing is lost.', 'membuild-community') }}</small>
    </template>

    <!-- Before: pick the pages, then one button. -->
    <template v-else>
      <p class="intro">{{ t.__('Ready-made pages for the rest of your website, in your community’s look. They’re made of WordPress blocks, so you change any word or photo in the page editor. Nothing else to install.', 'membuild-community') }}</p>
      <div class="sp-pages">
        <label v-for="p in state.pages" :key="p.slug" class="sp-tick">
          <input type="checkbox" :checked="picked.includes(p.slug)" @change="toggle(p.slug)" />
          <span class="sp-mark tick-box"><Icon name="check" :size="14" /></span>
          <span class="sp-words">
            <strong>{{ p.title }}</strong>
            <small v-if="p.have">{{ p.kind === 'layout' ? t.__('You already have your own.', 'membuild-community') : t.__('You already have one.', 'membuild-community') }} <a :href="p.have" target="_blank" rel="noopener">{{ t.__('See it', 'membuild-community') }}</a></small>
            <small v-else-if="p.note">{{ p.note }}</small>
          </span>
        </label>
      </div>
      <hr class="rule" />
      <label class="sp-tick" :class="{ off: !picked.includes('home') }">
        <input v-model="front" type="checkbox" :disabled="!picked.includes('home')" />
        <span class="sp-mark tick-box"><Icon name="check" :size="14" /></span>
        <span class="sp-words"><strong>{{ t.__('Show Home as the front page of the site', 'membuild-community') }}</strong></span>
      </label>
      <label v-if="state.blockTheme" class="sp-tick">
        <input v-model="parts" type="checkbox" />
        <span class="sp-mark tick-box"><Icon name="check" :size="14" /></span>
        <span class="sp-words"><strong>{{ t.__('Use the matching header and footer', 'membuild-community') }}</strong></span>
      </label>
      <p v-else class="sp-warn">{{ t.__('Your theme isn’t a block theme, so the pages are made but your theme keeps its own header and footer. Twenty Twenty-Five (free) shows them as designed.', 'membuild-community') }}</p>
      <hr class="rule" />
      <h3 class="sp-sub">{{ t.__('Make my starter pages', 'membuild-community') }}</h3>
      <p class="sp-note">{{ t.__('Three questions. We write them into every page, and you can change any word later.', 'membuild-community') }}</p>
      <label class="field">
        <span class="label">{{ t.__('What is your community about?', 'membuild-community') }}</span>
        <input v-model="answers.topic" type="text" maxlength="120" :placeholder="t.__('Yoga for beginners and busy people', 'membuild-community')" />
      </label>
      <label class="field">
        <span class="label">{{ t.__('Who is it for?', 'membuild-community') }}</span>
        <input v-model="answers.who" type="text" maxlength="120" :placeholder="t.__('Anyone who wants a calmer, regular practice', 'membuild-community')" />
      </label>
      <label class="field">
        <span class="label">{{ t.__('What do members get?', 'membuild-community') }}</span>
        <input v-model="answers.gets" type="text" maxlength="120" :placeholder="t.__('Weekly live classes, short courses, a kind group', 'membuild-community')" />
      </label>
      <div class="field">
        <span class="label">{{ t.__('Color and logo', 'membuild-community') }}</span>
        <ColorPick :model-value="colour === swatches[0] ? '' : colour" :accents="swatches" :label="t.__('Main color', 'membuild-community')" @update:model-value="(v) => (colour = v || swatches[0])" />
        <small>{{ t.__('Pick one, or + for any color, the same control as Settings › Your community. Buttons get dark or white words, whichever reads best. Logo from Site Logo.', 'membuild-community') }}</small>
      </div>
      <label v-if="state.logo" class="toggle-row sp-logo">
        <img :src="state.logo" alt="" class="sp-logo-img" />
        <span>
          <strong>{{ t.__('My logo already shows the name', 'membuild-community') }}</strong>
          <small>{{ t.__('We guess from the logo’s shape; this switch fixes a wrong guess. A logo on white gets a small rounded frame so it doesn’t look pasted on.', 'membuild-community') }}</small>
        </span>
        <input type="checkbox" class="switch" role="switch" :checked="logoShowsName" @change="logoName = $event.target.checked ? 'yes' : 'no'" />
      </label>
      <div class="sp-actions">
        <button type="button" class="btn btn-primary btn-lg" :disabled="busy || !picked.length" @click="create(false)">{{ busy ? t.__('Making your pages…', 'membuild-community') : t.__('Make my pages', 'membuild-community') }}</button>
        <button type="button" class="btn btn-lg" :disabled="busy || !picked.length" @click="create(true)">{{ t.__('Use the example words', 'membuild-community') }}</button>
      </div>
      <small class="sp-note">{{ t.__('Takes a few seconds. You can undo it.', 'membuild-community') }}</small>
    </template>

    <p v-if="error" class="error-text" role="alert">{{ error }}</p>
  </section>
</template>

<style scoped>
.sp-pages {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 24px;
}

.sp-tick {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 10px 0;
  cursor: pointer;
}

.sp-tick.off {
  opacity: 0.5;
  cursor: default;
}

.sp-tick input {
  position: absolute;
  opacity: 0;
}

.sp-words {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: var(--fs-base);
}

.sp-words strong {
  font-weight: 600;
}

.sp-words small,
.sp-note {
  color: var(--text-2);
  font-size: var(--fs-sm);
}

.sp-words small a {
  color: var(--text);
  font-weight: 700;
}

.sp-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
  margin-top: 18px;
}

.sp-done,
.sp-warn {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 8px;
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-weight: 700;
}

.sp-done {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.sp-warn {
  background: var(--warn-soft);
  color: var(--warn-text);
  font-weight: 500;
  line-height: 1.5;
}

.sp-made {
  margin: 0;
  padding: 0;
  list-style: none;
}

.sp-made li {
  display: flex;
  align-items: center;
  gap: 18px;
  min-height: 52px;
  border-top: 1px solid var(--border);
}

.sp-made .sp-words {
  flex: 1;
  padding: 8px 0;
}

.sp-sub {
  margin: 0 0 4px;
  font-size: var(--fs-lg);
  font-weight: 700;
}

.sp-link {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  color: var(--text-2);
  font-weight: 700;
}

.sp-undo {
  min-height: 52px;
  padding: 0 6px;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}

.sp-undo:disabled {
  opacity: 0.5;
}

.sp-note {
  display: block;
  line-height: 1.5;
}


.sp-parts {
  flex-wrap: wrap;
  justify-content: space-between;
  margin-bottom: 16px;
}

.sp-logo {
  margin-top: 14px;
}

.sp-logo-img {
  flex: none;
  width: 56px;
  height: 40px;
  object-fit: contain;
}

.sp-refund {
  margin-top: 18px;
}

.sp-days {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 600;
}

.sp-refund .sp-days input[type='number'] {
  width: 96px;
}

.sp-date {
  max-width: 220px;
}

.sp-list {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin: 4px 0 18px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
}

.sp-list .sp-words {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}

@media (max-width: 767px) {
  .sp-pages {
    grid-template-columns: 1fr;
  }
}
</style>
