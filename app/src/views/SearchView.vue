<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { feature } from '../config.js'
import Icon from '../components/Icon.vue'
import SearchResult from '../components/SearchResult.vue'
import SearchStart from '../components/SearchStart.vue'
import { rememberSearch } from '../lib/recentSearches.js'
import { t } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { searchWords } from '../lib/search.js'

/**
 * The results page: /search?q=…&type=…. No sidebar. "Results for …", filters with
 * counts (only kinds that found something), then each kind in a card with 3 results
 * and "See all"; a filter lists every match of that kind, more as you scroll.
 * On phones it's the whole search: ‹ back and a 52px box, Recent and Look around
 * before typing, no header or bottom bar.
 */
const route = useRoute()
const router = useRouter()
const latest = useLatest()

const KINDS = [
  { key: 'members', label: t.__('Members', 'membuild-community'), all: (n) => t.sprintf(t._n('See %s member', 'See all %s members', n, 'membuild-community'), n) },
  { key: 'posts', label: t.__('Posts', 'membuild-community'), all: (n) => t.sprintf(t._n('See %s post', 'See all %s posts', n, 'membuild-community'), n) },
  feature('classroom') && { key: 'courses', label: t.__('Classroom', 'membuild-community'), all: (n) => t.sprintf(t.__('See all %s in the Classroom', 'membuild-community'), n) },
  feature('events') && { key: 'events', label: t.__('Events', 'membuild-community'), all: (n) => t.sprintf(t._n('See %s event', 'See all %s events', n, 'membuild-community'), n) },
  feature('places') && { key: 'places', label: t.__('Places', 'membuild-community'), all: (n) => t.sprintf(t._n('See %s place', 'See all %s places', n, 'membuild-community'), n) },
  { key: 'comments', label: t.__('Comments', 'membuild-community'), all: (n) => t.sprintf(t._n('See %s comment', 'See all %s comments', n, 'membuild-community'), n) },
].filter(Boolean)

const input = ref(null)
const typed = ref(String(route.query.q || ''))
const q = computed(() => String(route.query.q || '').trim())
const type = computed(() => (KINDS.some((k) => k.key === route.query.type) ? route.query.type : 'all'))
const words = computed(() => searchWords(q.value))

const groups = ref(null) // counts and the first few of each kind (always loaded, for the filters)
const total = ref(0)
const totalMax = ref(false) // some kinds stopped counting at 99: "500+"
const totalText = computed(() => (totalMax.value ? `${total.value.toLocaleString()}+` : total.value.toLocaleString()))
const items = ref([])
const nextPage = ref(null)
const loading = ref(false)
const loadingMore = ref(false)

async function loadGroups() {
  const current = latest()
  if (!words.value.length) {
    groups.value = null
    return
  }
  loading.value = true
  try {
    const res = await api.search(q.value, 'all')
    if (!current()) return
    groups.value = res.groups
    total.value = res.total || 0
    totalMax.value = !!res.total_max
    rememberSearch(q.value)
  } catch {
    if (current()) groups.value = {}
  } finally {
    if (current()) loading.value = false
  }
}

let asked = 0
async function loadKind(page = 1) {
  if (type.value === 'all' || !words.value.length) return
  const mine = ++asked
  if (page === 1) items.value = []
  loadingMore.value = true
  try {
    const res = await api.search(q.value, type.value, page)
    if (mine !== asked) return
    items.value = page === 1 ? res.items : [...items.value, ...res.items]
    nextPage.value = res.next_page
  } finally {
    if (mine === asked) loadingMore.value = false
  }
}

watch(q, () => {
  groups.value = null
  loadGroups()
  loadKind()
})
watch(type, () => loadKind())
watch(q, (value) => (typed.value = value))

let timer = null
watch(typed, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    if (value.trim() !== q.value) router.replace({ query: { ...route.query, q: value.trim() || undefined, type: undefined } })
  }, 300)
})
function submit() {
  clearTimeout(timer)
  router.replace({ query: { ...route.query, q: typed.value.trim() || undefined } })
  input.value?.blur() // closes the phone keyboard
}
function clear() {
  typed.value = ''
  router.replace({ query: {} })
  nextTick(() => input.value?.focus())
}
function pickRecent(r) {
  typed.value = r
  submit()
}
const setType = (key) => router.replace({ query: { ...route.query, type: key === 'all' ? undefined : key } })
const back = () => (window.history.state?.back ? router.back() : router.push({ name: 'feed' }))

const found = computed(() => KINDS.filter((k) => groups.value?.[k.key]?.count))
const count = (key) => (groups.value?.[key]?.count_max ? '99+' : groups.value?.[key]?.count || 0)
const filters = computed(() => (found.value.length ? [{ key: 'all', label: t.__('All', 'membuild-community'), n: totalText.value }, ...found.value.map((k) => ({ ...k, n: count(k.key) }))] : []))
const current = computed(() => KINDS.find((k) => k.key === type.value))
const nothing = computed(() => !!groups.value && !loading.value && !found.value.length)
const shorter = computed(() => {
  const w = words.value[0] || ''
  return [...w].length > 3 ? [...w].slice(0, 3).join('') : ''
})

/* A filter lists everything: the next page loads as you reach the end. */
const moreSpot = ref(null)
let io = null
watch(moreSpot, (el) => {
  io?.disconnect()
  if (!el) return
  io = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && nextPage.value && !loadingMore.value) loadKind(nextPage.value)
  }, { rootMargin: '400px' })
  io.observe(el)
})
onBeforeUnmount(() => io?.disconnect())

onMounted(() => {
  loadGroups()
  loadKind()
  if (!q.value) nextTick(() => input.value?.focus())
})

const resultsFor = computed(() => t.sprintf(/* translators: %s: the search text */ t.__('Results for “%s”', 'membuild-community'), q.value))
const across = computed(() => t.sprintf(/* translators: %s: number of results */ t._n('%s result across the community', '%s results across the community', total.value, 'membuild-community'), totalText.value))
const noneTitle = computed(() => t.sprintf(/* translators: %s: the search text */ t.__('Nothing matches “%s”', 'membuild-community'), q.value))
const searched = computed(() => {
  const parts = [t.__('members', 'membuild-community'), t.__('posts', 'membuild-community'), t.__('comments', 'membuild-community')]
  if (feature('classroom')) parts.push(t.__('the Classroom', 'membuild-community'))
  if (feature('events')) parts.push(t.__('events', 'membuild-community'))
  if (feature('places')) parts.push(t.__('places', 'membuild-community'))
  const last = parts.pop()
  return t.sprintf(/* translators: 1: a list of parts of the community, 2: the last one */ t.__('Searched %1$s and %2$s.', 'membuild-community'), parts.join(', '), last)
})
</script>

<template>
  <div class="search-page">
    <!-- Phones: ‹ back and the box take the header's place -->
    <div class="phone-bar">
      <button type="button" class="back" :aria-label="t.__('Back', 'membuild-community')" @click="back"><Icon name="chevronLeft" :size="24" /></button>
      <form class="box" role="search" @submit.prevent="submit">
        <label class="field">
          <Icon name="search" :size="20" />
          <input
            ref="input"
            v-model="typed"
            type="search"
            enterkeyhint="search"
            autocomplete="off"
            maxlength="100"
            :placeholder="t.__('Search the community', 'membuild-community')"
            :aria-label="t.__('Search the community', 'membuild-community')"
          />
          <button v-if="typed" type="button" class="clear" :aria-label="t.__('Clear', 'membuild-community')" @click="clear"><Icon name="close" :size="16" /></button>
        </label>
      </form>
    </div>

    <div class="body">
      <!-- Nothing typed yet (phones mostly): Recent and Look around -->
      <section v-if="!words.length" class="card start-card">
        <SearchStart @pick="pickRecent" />
      </section>

      <template v-else>
        <header v-if="!nothing" class="head">
          <h1>{{ resultsFor }}</h1>
          <p v-if="groups">{{ across }}</p>
          <p v-else class="skeleton" style="width: 220px; height: 16px" />
        </header>
        <header v-else class="head">
          <h1>{{ t.__('No results', 'membuild-community') }}</h1>
          <p>{{ searched }}</p>
        </header>

        <div v-if="filters.length" class="filters" role="tablist">
          <button class="filter-pill" v-for="f in filters" :key="f.key" type="button" role="tab" :aria-selected="type === f.key" :class="{ on: type === f.key }" @click="setType(f.key)">
            {{ f.label }} <span class="n">{{ f.n }}</span>
          </button>
        </div>

        <!-- Loading: card shapes -->
        <section v-if="!groups" class="card group">
          <div v-for="n in 4" :key="n" class="sk"><span class="skeleton" /><span><span class="skeleton" /><span class="skeleton" /></span></div>
        </section>

        <!-- Nothing found -->
        <section v-else-if="nothing" class="card none">
          <span class="none-icon"><Icon name="search" :size="34" /></span>
          <strong>{{ noneTitle }}</strong>
          <p>{{ shorter ? t.sprintf(/* translators: %s: a shorter word to try */ t.__('Check the spelling, or try a shorter word like “%s”. Private messages are never searched.', 'membuild-community'), shorter) : t.__('Check the spelling, or try another word. Private messages are never searched.', 'membuild-community') }}</p>
          <RouterLink :to="{ name: 'members' }" class="btn-grey"><Icon name="users" :size="19" /> {{ t.__('Browse members', 'membuild-community') }}</RouterLink>
        </section>

        <!-- All: each kind in a card with 3 results -->
        <template v-else-if="type === 'all'">
          <section v-for="k in found" :key="k.key" class="card group">
            <h2>{{ k.label }} · {{ count(k.key) }}</h2>
            <SearchResult v-for="item in groups[k.key].items" :key="item.kind + item.id" :item="item" :words="words" message />
            <div v-if="groups[k.key].count > groups[k.key].items.length" class="see-all"><button type="button" class="btn-grey" @click="setType(k.key)">{{ k.all(count(k.key)) }}</button></div>
          </section>
        </template>

        <!-- One kind: every match in one list -->
        <section v-else class="card group">
          <h2>{{ current.label }} · {{ count(current.key) }}</h2>
          <SearchResult v-for="item in items" :key="item.kind + item.id" :item="item" :words="words" message />
          <template v-if="loadingMore">
            <div v-for="n in items.length ? 2 : 4" :key="`sk${n}`" class="sk"><span class="skeleton" /><span><span class="skeleton" /><span class="skeleton" /></span></div>
          </template>
          <span v-if="nextPage" ref="moreSpot" class="more-spot" aria-hidden="true" />
        </section>
      </template>
    </div>
  </div>
</template>

<style scoped>
.search-page {
  max-width: 860px;
  margin: 0 auto;
  padding: 36px 16px 48px;
}

.phone-bar {
  display: none;
}

.head h1 {
  margin: 0;
  font-size: 30px;
  line-height: 1.2;
  overflow-wrap: anywhere;
}

.head p {
  margin: 8px 0 0;
  color: var(--text-2);
  font-size: 16px;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 22px 0 16px;
  overflow-x: auto;
  scrollbar-width: none;
}

.filters::-webkit-scrollbar {
  display: none;
}

.n {
  display: inline-grid;
  place-items: center;
  min-width: 26px;
  height: 26px;
  padding: 0 7px;
  border-radius: 999px;
  color: var(--text-2);
  background: var(--surface);
  font-size: 14px;
}

.group {
  margin-bottom: 16px;
  padding: 8px 0;
  overflow: hidden;
}

.head + .group {
  margin-top: 22px;
}

.group h2 {
  margin: 10px 18px 6px;
  color: var(--text-2);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.group :deep(.row + .row) {
  border-top: 1px solid var(--border);
}

.group :deep(.result) {
  padding: 12px 18px;
  border-radius: 0;
}

.see-all {
  display: flex;
  justify-content: center;
  padding: 12px 16px 10px;
  border-top: 1px solid var(--border);
}

.btn-grey {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 52px;
  padding: 0 24px;
  border-radius: 999px;
  color: var(--text);
  background: var(--surface-2);
  font-size: 17px;
  font-weight: 700;
}

.btn-grey:hover {
  background: var(--border);
}

.none {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-top: 22px;
  padding: 48px 24px;
  text-align: center;
}

.none-icon {
  display: grid;
  place-items: center;
  width: 76px;
  height: 76px;
  border-radius: 50%;
  color: var(--text-2);
  background: var(--surface-2);
}

.none strong {
  font-size: 22px;
}

.none p {
  max-width: 440px;
  margin: 0 0 6px;
  color: var(--text-2);
  font-size: 17px;
  line-height: 1.5;
}

.start-card {
  padding: 10px 0 12px;
}

.sk {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 18px;
}

.sk > .skeleton {
  flex: none;
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
  height: 16px;
}

.sk > span:last-child .skeleton:last-child {
  width: 70%;
  height: 13px;
}

.more-spot {
  display: block;
  height: 1px;
}

/* Phones: the whole search, full screen. */
@media (max-width: 767px) {
  .search-page {
    padding: 0;
  }

  .phone-bar {
    position: sticky;
    top: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: calc(10px + env(safe-area-inset-top)) 12px 10px 4px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
  }

  .back {
    display: grid;
    flex: none;
    place-items: center;
    width: 52px;
    height: 52px;
    color: var(--text);
  }

  .box {
    flex: 1;
    min-width: 0;
  }

  .field {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 52px;
    padding: 0 8px 0 16px;
    border-radius: 999px;
    color: var(--text);
    background: var(--surface-2);
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
    font-size: 17px; /* 16px+ stops iPhones zooming in on focus */
    outline: none;
    box-shadow: none;
  }

  .field input::-webkit-search-cancel-button {
    display: none;
  }

  .clear {
    display: grid;
    flex: none;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    color: var(--text);
    background: var(--border);
  }

  .body {
    min-height: calc(100dvh - 73px);
    background: var(--surface);
  }

  /* The box already says what was searched. */
  .head {
    display: none;
  }

  /* Filters scroll sideways, with a fade at the edge so you can see there are more. */
  .filters {
    flex-wrap: nowrap;
    margin: 0;
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    background: var(--bg);
    -webkit-mask-image: linear-gradient(90deg, #000 calc(100% - 40px), transparent);
    mask-image: linear-gradient(90deg, #000 calc(100% - 40px), transparent);
  }

  .group,
  .start-card,
  .none {
    margin: 0;
    border: 0;
    border-radius: 0;
    box-shadow: none;
  }

  .group {
    border-bottom: 8px solid var(--bg);
  }

  .group :deep(.result) {
    padding: 12px 16px;
  }

  .none {
    padding: 48px 24px;
  }
}

</style>
