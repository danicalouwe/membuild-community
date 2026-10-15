<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import Icon from './Icon.vue'
import SearchResult from './SearchResult.vue'
import { pushLayer } from '../lib/layers.js'
import { t } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { searchWords } from '../lib/search.js'

/**
 * The search box in the header. On wide screens it's a box next to the logo;
 * narrower, a button that opens it. While typing, a few results of each kind
 * show underneath; Enter, or "See all results", opens the results page.
 * On phones the button goes straight to the results page, which has its own box.
 */
const route = useRoute()
const router = useRouter()
const latest = useLatest()

const root = ref(null)
const input = ref(null)
const query = ref('')
const open = ref(false) // the box (on narrow screens) or the results under it
const groups = ref(null)
const loading = ref(false)
const words = computed(() => searchWords(query.value))

const GROUPS = [
  { key: 'members', label: t.__('Members', 'membuild-community') },
  { key: 'posts', label: t.__('Posts', 'membuild-community') },
  { key: 'comments', label: t.__('Comments', 'membuild-community') },
  { key: 'courses', label: t.__('Classroom', 'membuild-community') },
]
const shown = computed(() => GROUPS.filter((g) => groups.value?.[g.key]?.items.length))
const empty = computed(() => groups.value && !loading.value && !shown.value.length)

let timer = null
watch(query, (q) => {
  clearTimeout(timer)
  if (!searchWords(q).length) {
    groups.value = null
    return
  }
  timer = setTimeout(load, 220)
})

async function load() {
  const current = latest() // an older, slower answer never replaces a newer one
  loading.value = true
  try {
    const res = await api.search(query.value.trim())
    if (current()) groups.value = res.groups
  } catch {
    if (current()) groups.value = {}
  } finally {
    if (current()) loading.value = false
  }
}

let removeLayer = null
const phone = window.matchMedia('(max-width: 767px)')
function show() {
  if (phone.matches) return router.push({ name: 'search' })
  if (open.value) return
  open.value = true
  document.addEventListener('pointerdown', onDoc)
  removeLayer = pushLayer({ root: () => root.value, close: hide, trap: false })
  nextTick(() => input.value?.focus())
}
function hide() {
  if (!open.value) return
  open.value = false
  document.removeEventListener('pointerdown', onDoc)
  removeLayer?.()
  removeLayer = null
}
function onDoc(e) {
  if (root.value && !root.value.contains(e.target)) hide()
}
onBeforeUnmount(hide)

function seeAll() {
  const q = query.value.trim()
  if (!searchWords(q).length) return
  hide()
  input.value?.blur()
  router.push({ name: 'search', query: { q } })
}

// Arrow keys move through the results; the first ArrowDown leaves the box.
function onKey(e) {
  if (!['ArrowDown', 'ArrowUp'].includes(e.key)) return
  const links = [...(root.value?.querySelectorAll('.panel a') || [])]
  if (!links.length) return
  e.preventDefault()
  const at = links.indexOf(document.activeElement)
  const next = e.key === 'ArrowDown' ? Math.min(links.length - 1, at + 1) : at - 1
  if (next < 0) input.value?.focus()
  else links[next].focus()
}

// Leaving for a result: close, and start fresh next time.
watch(
  () => route.fullPath,
  () => {
    hide()
    if (route.name !== 'search') query.value = ''
  },
)

const seeAllLabel = computed(() => t.sprintf(/* translators: %s: the search text */ t.__('See all results for “%s”', 'membuild-community'), query.value.trim()))
const noneLabel = computed(() => t.sprintf(/* translators: %s: the search text */ t.__('Nothing matches “%s”.', 'membuild-community'), query.value.trim()))
</script>

<template>
  <div ref="root" class="search" :class="{ open }" @keydown="onKey">
    <button type="button" class="opener" :aria-label="t.__('Search', 'membuild-community')" :title="t.__('Search', 'membuild-community')" @click="show">
      <Icon name="search" :size="20" />
    </button>

    <form class="box" role="search" @submit.prevent="seeAll">
      <label class="field">
        <Icon name="search" :size="18" />
        <input
          ref="input"
          v-model="query"
          type="search"
          enterkeyhint="search"
          autocomplete="off"
          maxlength="100"
          :placeholder="t.__('Search the community', 'membuild-community')"
          :aria-label="t.__('Search the community', 'membuild-community')"
          :aria-expanded="open && !!groups"
          aria-controls="mbcom-search-panel"
          @focus="show"
        />
      </label>
    </form>

    <div v-if="open && (groups || loading)" id="mbcom-search-panel" class="panel card" :aria-busy="loading">
      <p v-if="loading && !groups" class="note">{{ t.__('Searching…', 'membuild-community') }}</p>
      <p v-else-if="empty" class="note">{{ noneLabel }}</p>
      <section v-for="g in shown" :key="g.key">
        <h2>{{ g.label }}</h2>
        <SearchResult v-for="item in groups[g.key].items" :key="item.kind + item.id" :item="item" :words="words" @open="hide" />
      </section>
      <button v-if="groups && !empty" type="button" class="all" @click="seeAll"><Icon name="search" :size="16" /> {{ seeAllLabel }}</button>
    </div>
  </div>
</template>

<style scoped>
.search {
  position: relative;
  min-width: 0;
}

.opener {
  display: none;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text);
  place-items: center;
}

.box {
  display: flex;
  align-items: center;
  gap: 4px;
}

.field {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 240px;
  height: 40px;
  padding: 0 14px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
}

.field:focus-within {
  box-shadow: 0 0 0 2px var(--accent);
}

.field input {
  flex: 1;
  min-width: 0;
  height: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--text);
  font-size: 15px;
  outline: none;
  box-shadow: none;
}

.field input::-webkit-search-cancel-button {
  cursor: pointer;
}

.panel {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  z-index: 40;
  width: 380px;
  max-height: min(70vh, 560px);
  overflow-y: auto;
  padding: 8px;
  box-shadow: 0 12px 32px rgb(16 24 40 / 16%);
}

.panel h2 {
  margin: 10px 12px 4px;
  color: var(--text-3);
  font-size: 13px;
  font-weight: 600;
}

.panel section:first-of-type h2 {
  margin-top: 4px;
}

.note {
  margin: 12px;
  color: var(--text-2);
}

.all {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  margin-top: 6px;
  padding: 12px;
  border-top: 1px solid var(--border);
  background: none;
  color: var(--accent-text);
  font-weight: 600;
  text-align: left;
}

/* Medium screens: a button in the header, the box drops down under it. */
@media (max-width: 1099px) {
  .opener {
    display: grid;
  }

  .box {
    display: none;
  }

  .open .box {
    display: flex;
    position: absolute;
    top: -4px;
    left: 0;
    z-index: 41;
  }

  .open .opener {
    visibility: hidden;
  }

  .panel {
    top: 48px;
  }
}

/* Phones: just the button; it opens the results page. */
@media (max-width: 767px) {
  .search .box,
  .search .panel {
    display: none;
  }
}
</style>
