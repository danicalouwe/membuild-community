<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { feature } from '../config.js'
import { forgetSearch, recentSearches } from '../lib/recentSearches.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'

// Before typing: your recent searches, and a way into each part of the community.
const emit = defineEmits(['pick', 'open'])

const around = ref(null)
onMounted(async () => {
  around.value = await api.searchAround().catch(() => ({}))
})

const count = (key, one, many) => {
  const n = around.value?.[key]
  return n == null ? '' : t.sprintf(t._n(one, many, n, 'membuild-community'), n.toLocaleString())
}
const TILES = [
  { key: 'members', to: { name: 'members' }, icon: 'users', label: t.__('Members', 'membuild-community'), tone: 'blue', text: () => count('members', '%s person', '%s people') },
  feature('classroom') && { key: 'courses', to: { name: 'classroom' }, icon: 'cap', label: t.__('Courses', 'membuild-community'), tone: 'green', text: () => count('courses', '%s course', '%s courses') },
  feature('events') && { key: 'events', to: { name: 'events' }, icon: 'calendar', label: t.__('Events', 'membuild-community'), tone: 'orange', text: () => count('events', '%s coming up', '%s coming up') },
  feature('places') && { key: 'places', to: { name: 'places' }, icon: 'mapPin', label: t.__('Places', 'membuild-community'), tone: 'red', text: () => count('places', '%s place', '%s places') },
].filter(Boolean)
</script>

<template>
  <div class="start">
    <template v-if="recentSearches.length">
      <h2>{{ t.__('Recent', 'membuild-community') }}</h2>
      <ul class="recent">
        <li v-for="r in recentSearches" :key="r">
          <button type="button" class="pick" @click="emit('pick', r)"><span class="clock"><Icon name="clock" :size="18" /></span>{{ r }}</button>
          <button type="button" class="forget" :aria-label="t.sprintf(t.__('Remove “%s” from recent searches', 'membuild-community'), r)" @click="forgetSearch(r)"><Icon name="close" :size="18" /></button>
        </li>
      </ul>
    </template>

    <h2>{{ t.__('Look around', 'membuild-community') }}</h2>
    <div class="tiles">
      <RouterLink v-for="tile in TILES" :key="tile.key" :to="tile.to" class="tile" @click="emit('open')">
        <span class="t-icon" :class="tile.tone"><Icon :name="tile.icon" :size="20" /></span>
        <span class="t-text"><strong>{{ tile.label }}</strong><small>{{ tile.text() || ' ' }}</small></span>
      </RouterLink>
    </div>
    <p class="private"><Icon name="lock" :size="14" /> {{ t.__('Private messages are never searched.', 'membuild-community') }}</p>
  </div>
</template>

<style scoped>
.start {
  padding: 6px 2px 2px;
}

h2 {
  margin: 10px 16px 8px;
  color: var(--text-2);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.recent {
  margin: 0 0 6px;
  padding: 0;
  list-style: none;
}

.recent li {
  display: flex;
  align-items: center;
}

.pick {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 14px;
  min-width: 0;
  min-height: 56px;
  padding: 0 16px;
  border-radius: 12px;
  color: var(--text);
  font-size: 17px;
  text-align: left;
}

.pick:hover,
.pick:focus-visible {
  background: var(--surface-2);
}

.clock {
  display: grid;
  flex: none;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  color: var(--text-2);
  background: var(--surface-2);
}

.forget {
  display: grid;
  flex: none;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  color: var(--text-2);
}

.forget:hover {
  background: var(--surface-2);
  color: var(--text);
}

.tiles {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding: 0 16px;
}

.tile {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 72px;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--text);
}

.tile:hover,
.tile:focus-visible {
  background: var(--surface-2);
}

.t-icon {
  display: grid;
  flex: none;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 10px;
}

.t-icon.blue {
  color: #3b5bdb;
  background: color-mix(in srgb, #3b5bdb 13%, transparent);
}

.t-icon.green {
  color: #1f8a4c;
  background: color-mix(in srgb, #1f8a4c 13%, transparent);
}

.t-icon.orange {
  color: #d9480f;
  background: color-mix(in srgb, #d9480f 13%, transparent);
}

.t-icon.red {
  color: #c92a2a;
  background: color-mix(in srgb, #c92a2a 13%, transparent);
}

.t-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.t-text strong {
  font-size: 17px;
}

.t-text small {
  color: var(--text-2);
  font-size: 14px;
}

.private {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 16px 16px 6px;
  color: var(--text-2);
  font-size: 14px;
}

@media (max-width: 767px) {
  .tiles {
    gap: 8px;
  }

  .tile {
    gap: 10px;
    padding: 10px;
  }
}
</style>
