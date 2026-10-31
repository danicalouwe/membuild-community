<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api.js'
import { askConfirm } from '../../lib/confirm.js'
import Icon from '../Icon.vue'
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

onMounted(async () => {
  state.value = await api.starterPages().catch(() => null)
  // All ticked, except a page the site already has.
  picked.value = (state.value?.pages || []).filter((p) => !p.have).map((p) => p.slug)
})

const made = computed(() => state.value?.pages.filter((p) => p.id) || [])
const toggle = (slug) => (picked.value = picked.value.includes(slug) ? picked.value.filter((s) => s !== slug) : [...picked.value, slug])

async function create() {
  busy.value = true
  error.value = ''
  try {
    state.value = await api.makeStarterPages({ pages: picked.value, front: front.value && picked.value.includes('home'), parts: parts.value })
  } catch (e) {
    error.value = e.message || t.__('The pages weren’t made. Try again in a moment.', 'membuild-community')
  }
  busy.value = false
}

async function remove() {
  const n = made.value.length
  const ok = await askConfirm({
    title: t.__('Remove the starter pages?', 'membuild-community'),
    text: t.sprintf(/* translators: %d: number of pages */ t._n('The %d page moves to the bin, and your old front page, header and footer come back.', 'The %d pages move to the bin, and your old front page, header and footer come back.', n, 'membuild-community'), n),
    confirm: t.__('Remove', 'membuild-community'),
    cancel: t.__('Keep them', 'membuild-community'),
  })
  if (!ok) return
  busy.value = true
  error.value = ''
  try {
    state.value = await api.removeStarterPages()
    picked.value = state.value.pages.filter((p) => !p.have).map((p) => p.slug)
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
          <strong>{{ p.title }}</strong>
          <a :href="p.edit" class="sp-link">{{ t.__('Edit', 'membuild-community') }}</a>
          <a :href="p.view" class="sp-link" target="_blank" rel="noopener">{{ t.__('View', 'membuild-community') }}</a>
        </li>
      </ul>
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
          <span class="sp-mark"><Icon name="check" :size="14" /></span>
          <span class="sp-words">
            <strong>{{ p.title }}</strong>
            <small v-if="p.have">{{ t.__('You already have one.', 'membuild-community') }} <a :href="p.have" target="_blank" rel="noopener">{{ t.__('See it', 'membuild-community') }}</a></small>
          </span>
        </label>
      </div>
      <hr class="rule" />
      <label class="sp-tick" :class="{ off: !picked.includes('home') }">
        <input v-model="front" type="checkbox" :disabled="!picked.includes('home')" />
        <span class="sp-mark"><Icon name="check" :size="14" /></span>
        <span class="sp-words"><strong>{{ t.__('Show Home as the front page of the site', 'membuild-community') }}</strong></span>
      </label>
      <label v-if="state.blockTheme" class="sp-tick">
        <input v-model="parts" type="checkbox" />
        <span class="sp-mark"><Icon name="check" :size="14" /></span>
        <span class="sp-words"><strong>{{ t.__('Use the matching header and footer', 'membuild-community') }}</strong></span>
      </label>
      <p v-else class="sp-warn">{{ t.__('Your theme isn’t a block theme, so the pages are made but your theme keeps its own header and footer. Twenty Twenty-Five (free) shows them as designed.', 'membuild-community') }}</p>
      <div class="sp-actions">
        <button type="button" class="btn btn-primary btn-lg" :disabled="busy || !picked.length" @click="create">{{ busy ? t.__('Making your pages…', 'membuild-community') : t.__('Create my starter pages', 'membuild-community') }}</button>
        <small class="sp-note">{{ t.__('Takes a few seconds. You can undo it.', 'membuild-community') }}</small>
      </div>
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

.sp-mark {
  flex: none;
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border: 2px solid var(--border-strong);
  border-radius: var(--radius-xs);
  color: transparent;
}

.sp-tick input:checked + .sp-mark {
  border-color: var(--text);
  background: var(--text);
  color: var(--surface);
}

.sp-tick input:focus-visible + .sp-mark {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
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

.sp-made strong {
  flex: 1;
  font-weight: 600;
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

@media (max-width: 767px) {
  .sp-pages {
    grid-template-columns: 1fr;
  }
}
</style>
