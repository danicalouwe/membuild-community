<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import Icon from './Icon.vue'
import SearchResult from './SearchResult.vue'
import SearchStart from './SearchStart.vue'
import { rememberSearch } from '../lib/recentSearches.js'
import { pushLayer } from '../lib/layers.js'
import { t } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { searchWords } from '../lib/search.js'

/**
 * The search box in the header. On wide screens it's a box next to the logo;
 * narrower, a button that opens it. Before typing, a panel shows recent searches
 * and Look around; while typing, a few results of each kind with how many there
 * are; Enter, or "See all N results", opens the results page.
 * On phones the button goes straight to the search page, which has its own box.
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
  { key: 'events', label: t.__('Events', 'membuild-community') },
  { key: 'places', label: t.__('Places', 'membuild-community') },
]
// Under the box: up to 3 members and 2 of everything else, so all the kinds fit.
const shown = computed(() => GROUPS.filter((g) => groups.value?.[g.key]?.items.length).map((g) => ({ ...g, items: groups.value[g.key].items.slice(0, g.key === 'members' ? 3 : 2), count: groups.value[g.key].count, max: groups.value[g.key].count_max })))
const total = ref(0)
const totalMax = ref(false) // some kinds have more than 99: the total reads "500+"
const typing = computed(() => words.value.length > 0)
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
    if (current()) {
      groups.value = res.groups
      total.value = res.total || 0
      totalMax.value = !!res.total_max
    }
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
  rememberSearch(q)
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

const seeAllLabel = computed(() => t.sprintf(/* translators: 1: number of results, 2: the search text */ t._n('See %1$s result for “%2$s”', 'See all %1$s results for “%2$s”', total.value, 'membuild-community'), totalMax.value ? `${total.value.toLocaleString()}+` : total.value.toLocaleString(), query.value.trim()))
const heading = (g) => `${g.label} · ${g.max ? '99+' : g.count}`
function pickRecent(r) {
  query.value = r
  nextTick(() => input.value?.focus())
}
// Opening a result counts as a search worth remembering.
function opened() {
  rememberSearch(query.value)
  hide()
}
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

    <div v-if="open" id="mbcom-search-panel" class="panel card" :aria-busy="loading">
      <SearchStart v-if="!typing" @pick="pickRecent" @open="hide" />
      <template v-else>
        <div class="list">
          <template v-if="loading && !groups">
            <div v-for="n in 4" :key="n" class="sk"><span class="skeleton" /><span><span class="skeleton" /><span class="skeleton" /></span></div>
          </template>
          <p v-else-if="empty" class="note">{{ noneLabel }}</p>
          <section v-for="g in shown" :key="g.key">
            <h2>{{ heading(g) }}</h2>
            <SearchResult v-for="item in g.items" :key="item.kind + item.id" :item="item" :words="words" @open="opened" />
          </section>
        </div>
        <div v-if="groups && !empty" class="all-wrap">
          <button type="button" class="all" @click="seeAll"><Icon name="search" :size="18" /> {{ seeAllLabel }} <Icon name="arrowRight" :size="18" /></button>
        </div>
      </template>
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
  border-radius: var(--radius-pill);
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
  font-size: var(--fs-base);
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
  display: flex;
  flex-direction: column;
  width: 580px;
  max-width: calc(100vw - 32px);
  max-height: min(80vh, 800px);
  padding: 10px 0 0;
  overflow: hidden;
  border-radius: var(--radius-lg);
  box-shadow: 0 12px 32px color-mix(in srgb, var(--shadow-color) 16%, transparent);
}

.panel > .start {
  overflow-y: auto;
  padding-bottom: 12px;
}

.list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 0 0 6px;
}

.list h2 {
  margin: 12px 16px 4px;
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.list section:first-of-type h2 {
  margin-top: 4px;
}

.note {
  margin: 16px;
  color: var(--text-2);
  font-size: var(--fs-lg);
}

.sk {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 16px;
}

.sk > .skeleton {
  width: 48px;
  height: 48px;
  border-radius: 50%;
}

.sk > span:last-child {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8px;
}

.sk > span:last-child .skeleton:first-child {
  width: 40%;
  height: 15px;
}

.sk > span:last-child .skeleton:last-child {
  width: 75%;
  height: 12px;
}

/* See all stays pinned at the bottom while the list scrolls. */
.all-wrap {
  padding: 8px 12px 12px;
}

.all {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  min-height: 56px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text);
  font-size: var(--fs-xl);
  font-weight: 700;
}

.all:hover {
  background: var(--border);
}

/* Medium screens: a button in the header, the box drops down under it.
   Below 1280px the full box wouldn't leave room before the tabs. */
@media (max-width: 1279px) {
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
