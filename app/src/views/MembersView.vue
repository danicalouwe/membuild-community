<script setup>
import { onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import { levelLabel } from '../config.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import { t, locale } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'

// The member directory: search by name, sorted by activity, newest or level.
const SORTS = [
  { key: 'active', label: t.__('Most active', 'membuild-community') },
  { key: 'newest', label: t.__('Newest', 'membuild-community') },
  { key: 'level', label: t.__('Top level', 'membuild-community') },
]
const sort = ref('active')
const query = ref('')
const items = ref([])
const total = ref(0)
const nextPage = ref(null)
const loading = ref(true)
const latest = useLatest()

async function load(reset = true) {
  const current = latest() // a slower, older search never overwrites a newer one
  loading.value = true
  try {
    const res = await api.members({ sort: sort.value, search: query.value.trim() || undefined, page: reset ? 1 : nextPage.value })
    if (!current()) return
    items.value = reset ? res.items : [...items.value, ...res.items]
    nextPage.value = res.next_page
    total.value = res.total
  } finally {
    if (current()) loading.value = false
  }
}

onMounted(load)
watch(sort, () => load())
let timer = null
watch(query, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 250)
})
const noMatch = (q) => t.sprintf(/* translators: %s: the search text */ t.__('No members match “%s”.', 'membuild-community'), q)
</script>

<template>
  <div class="members">
    <header class="head">
      <h1>{{ t.__('Members', 'membuild-community') }} <span v-if="total">{{ total.toLocaleString(locale) }}</span></h1>
      <label class="search">
        <Icon name="search" :size="18" />
        <input v-model="query" type="search" :placeholder="t.__('Search members', 'membuild-community')" :aria-label="t.__('Search members', 'membuild-community')" />
      </label>
      <div class="sorts" role="tablist">
        <button v-for="s in SORTS" :key="s.key" role="tab" :aria-selected="sort === s.key" :class="{ on: sort === s.key }" @click="sort = s.key">{{ s.label }}</button>
      </div>
    </header>

    <ul class="grid" :class="{ dim: loading && items.length }">
      <li v-for="m in items" :key="m.id">
        <RouterLink :to="{ name: 'member', params: { id: m.id } }" class="card person">
          <Avatar :user="m" :size="72" badge />
          <strong>{{ m.name }}</strong>
          <span v-if="m.role" class="role">{{ m.role === 'admin' ? t.__('Admin', 'membuild-community') : t.__('Moderator', 'membuild-community') }}</span>
          <span class="lvl">{{ levelLabel(m.level) }}</span>
          <p v-if="m.bio" class="bio">{{ m.bio }}</p>
        </RouterLink>
      </li>
    </ul>
    <p v-if="!loading && !items.length" class="card state">{{ query ? noMatch(query) : t.__('No members yet.', 'membuild-community') }}</p>
    <p v-else-if="loading && !items.length" class="card state">{{ t.__('Loading…', 'membuild-community') }}</p>
    <button v-if="nextPage" class="btn more" :disabled="loading" @click="load(false)">{{ loading ? t.__('Loading…', 'membuild-community') : t.__('Show more members', 'membuild-community') }}</button>
  </div>
</template>

<style scoped>
.members {
  max-width: 1000px;
  margin: 0 auto;
  padding: 16px;
}

.head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.head h1 {
  margin: 0 auto 0 0;
  font-size: 24px;
}

.head h1 span {
  color: var(--text-3);
  font-weight: 600;
  font-size: 18px;
}

.search {
  display: flex;
  align-items: center;
  gap: 8px;
  width: min(320px, 100%);
  height: 40px;
  padding: 0 14px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-3);
}

/* The field inside has no outline of its own, so the pill shows focus. */
.search:focus-within {
  box-shadow: 0 0 0 2px var(--accent);
}

.search input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: transparent;
  color: var(--text);
  font: inherit;
  outline: none;
}

.sorts {
  display: flex;
  gap: 6px;
  flex-basis: 100%;
}

.sorts button {
  padding: 7px 14px;
  border-radius: 999px;
  font-weight: 650;
  font-size: 14px;
  color: var(--text-2);
}

.sorts button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.grid {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
  transition: opacity 0.15s;
}

.grid.dim {
  opacity: 0.6;
}

.person {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  height: 100%;
  padding: 18px 14px;
  text-align: center;
  color: var(--text);
}

.person:hover {
  background: var(--surface-2);
}

.person strong {
  margin-top: 8px;
  font-size: 16px;
}

.role {
  padding: 1px 8px;
  border-radius: 999px;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: 12px;
  font-weight: 700;
}

.lvl {
  font-size: 13px;
  color: var(--accent-text);
  font-weight: 650;
}

.bio {
  margin: 6px 0 0;
  font-size: 13px;
  color: var(--text-2);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.state {
  padding: 28px;
  text-align: center;
  color: var(--text-3);
}

.btn {
  padding: 9px 16px;
  border-radius: 10px;
  background: var(--surface-2);
  font-weight: 650;
}

.more {
  display: block;
  margin: 16px auto 0;
}

@media (max-width: 767px) {
  .members {
    padding: 12px;
  }

  .search {
    width: 100%;
  }

  .grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
  }
}
</style>
