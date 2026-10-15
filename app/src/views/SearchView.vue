<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { feature } from '../config.js'
import Icon from '../components/Icon.vue'
import SearchResult from '../components/SearchResult.vue'
import { t } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { searchWords } from '../lib/search.js'

/**
 * The results page: /search?q=…&type=…. "All" shows a few of each kind with a
 * way to see more; each other tab lists one kind, more on demand. On phones
 * this is where the search button leads, so it has its own box.
 */
const route = useRoute()
const router = useRouter()
const latest = useLatest()

const TABS = computed(() =>
  [
    { key: 'all', label: t.__('All', 'membuild-community') },
    { key: 'members', label: t.__('Members', 'membuild-community') },
    { key: 'posts', label: t.__('Posts', 'membuild-community') },
    { key: 'comments', label: t.__('Comments', 'membuild-community') },
    feature('classroom') && { key: 'courses', label: t.__('Classroom', 'membuild-community') },
  ].filter(Boolean),
)

const input = ref(null)
const typed = ref(String(route.query.q || ''))
const q = computed(() => String(route.query.q || '').trim())
const type = computed(() => (TABS.value.some((tab) => tab.key === route.query.type) ? route.query.type : 'all'))
const words = computed(() => searchWords(q.value))

const groups = ref(null)
const items = ref([])
const nextPage = ref(null)
const loading = ref(false)

async function load(page = 1) {
  const current = latest()
  if (!words.value.length) {
    groups.value = null
    items.value = []
    return
  }
  loading.value = true
  try {
    const res = await api.search(q.value, type.value, page)
    if (!current()) return
    if (type.value === 'all') {
      groups.value = res.groups
    } else {
      items.value = page === 1 ? res.items : [...items.value, ...res.items]
      nextPage.value = res.next_page
    }
  } finally {
    if (current()) loading.value = false
  }
}

watch([q, type], () => {
  groups.value = null
  items.value = []
  load()
})
watch(q, (value) => (typed.value = value))
onMounted(() => {
  load()
  if (!q.value) nextTick(() => input.value?.focus())
})

let timer = null
watch(typed, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    if (value.trim() !== q.value) router.replace({ query: { ...route.query, q: value.trim() || undefined } })
  }, 300)
})
function submit() {
  clearTimeout(timer)
  router.replace({ query: { ...route.query, q: typed.value.trim() || undefined } })
  input.value?.blur() // closes the phone keyboard
}
const setType = (key) => router.replace({ query: { ...route.query, type: key === 'all' ? undefined : key } })

const shownGroups = computed(() => TABS.value.filter((tab) => tab.key !== 'all' && groups.value?.[tab.key]?.items.length))
const nothing = computed(() => !loading.value && words.value.length && (type.value === 'all' ? groups.value && !shownGroups.value.length : !items.value.length))
const noneLabel = computed(() => t.sprintf(/* translators: %s: the search text */ t.__('Nothing matches “%s”.', 'membuild-community'), q.value))
</script>

<template>
  <div class="search-page">
    <form class="box" role="search" @submit.prevent="submit">
      <label class="field">
        <Icon name="search" :size="18" />
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
      </label>
    </form>

    <div class="tabs" role="tablist">
      <button v-for="tab in TABS" :key="tab.key" type="button" role="tab" :aria-selected="type === tab.key" :class="{ on: type === tab.key }" @click="setType(tab.key)">{{ tab.label }}</button>
    </div>

    <p v-if="!words.length" class="note">{{ t.__('Search for members, posts, comments and lessons. Private messages aren’t searched.', 'membuild-community') }}</p>
    <p v-else-if="nothing" class="note">{{ noneLabel }}</p>

    <template v-if="type === 'all' && groups">
      <section v-for="g in shownGroups" :key="g.key" class="card group">
        <h2>{{ g.label }}</h2>
        <SearchResult v-for="item in groups[g.key].items" :key="item.kind + item.id" :item="item" :words="words" />
        <button v-if="groups[g.key].more" type="button" class="more" @click="setType(g.key)">{{ t.__('See more', 'membuild-community') }}</button>
      </section>
    </template>

    <section v-else-if="items.length" class="card group">
      <SearchResult v-for="item in items" :key="item.kind + item.id" :item="item" :words="words" />
      <button v-if="nextPage" type="button" class="more" :disabled="loading" @click="load(nextPage)">{{ t.__('Show more', 'membuild-community') }}</button>
    </section>

    <p v-if="loading && !groups && !items.length" class="note">{{ t.__('Searching…', 'membuild-community') }}</p>
  </div>
</template>

<style scoped>
.search-page {
  max-width: 680px;
  margin: 0 auto;
  padding: 20px 16px 40px;
}

.box {
  display: none;
  margin-bottom: 12px;
}

.field {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 44px;
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
  font-size: 16px; /* 16px stops iPhones zooming in on focus */
  outline: none;
  box-shadow: none;
}

.tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  margin-bottom: 14px;
  scrollbar-width: none;
}

.tabs button {
  flex: none;
  padding: 7px 14px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
  font-weight: 600;
}

.tabs button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.group {
  padding: 8px;
  margin-bottom: 14px;
}

.group h2 {
  margin: 8px 12px 6px;
  font-size: 16px;
}

.more {
  width: 100%;
  margin-top: 4px;
  padding: 10px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--text);
  font-weight: 600;
}

.note {
  color: var(--text-2);
  text-align: center;
  margin: 28px 0;
}

/* The header's box is hidden on phones, so the page brings its own. */
@media (max-width: 767px) {
  .box {
    display: block;
  }

  .search-page {
    padding-top: 12px;
  }
}
</style>
