<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../api.js'
import { compactNumber } from '../lib/time.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import ReactionBadge from './ReactionBadge.vue'
import BaseDialog from './BaseDialog.vue'

// "Who reacted": tabs for All and each reaction (most-used first), then
// the people, each with their reaction badge on the avatar.
const props = defineProps({ post: { type: Object, required: true } })
const emit = defineEmits(['close'])
// Opening someone's profile from the list closes it.
const route = useRoute()
watch(() => route.path, () => emit('close'))

const tab = ref('') // '' = All
const counts = ref({})
const total = ref(0)
const items = ref([])
const nextPage = ref(null)
const loading = ref(false)

const tabs = computed(() => Object.entries(counts.value).sort((a, b) => b[1] - a[1]))

async function load(reset = true) {
  loading.value = true
  try {
    const res = await api.postReactions(props.post.id, { type: tab.value, page: reset ? 1 : nextPage.value })
    counts.value = res.counts
    total.value = res.total
    items.value = reset ? res.items : [...items.value, ...res.items]
    nextPage.value = res.next_page
  } finally {
    loading.value = false
  }
}

function select(type) {
  if (tab.value === type) return
  tab.value = type
  items.value = []
  load(true)
}

function onScroll(e) {
  const el = e.target
  if (nextPage.value && !loading.value && el.scrollTop + el.clientHeight > el.scrollHeight - 200) load(false)
}

onMounted(() => load(true))
</script>

<template>
  <BaseDialog :label="t.__('Reactions', 'membuild-community')" :width="560" max-height="640px" phone="sheet" @close="emit('close')" @scroll="onScroll">
    <template #header>
      <header class="tabs" role="tablist">
        <button role="tab" class="tab" :class="{ on: tab === '' }" :aria-selected="tab === ''" @click="select('')">{{ t.__('All', 'membuild-community') }}</button>
        <button
          v-for="[type, n] in tabs"
          :key="type"
          role="tab"
          class="tab"
          :class="{ on: tab === type }"
          :aria-selected="tab === type"
          @click="select(type)"
        >
          <ReactionBadge :type="type" :size="24" />
          <span>{{ compactNumber(n) }}</span>
        </button>
        <button class="close" :aria-label="t.__('Close', 'membuild-community')" @click="emit('close')"><Icon name="close" :size="22" /></button>
      </header>
    </template>

    <div class="list">
      <div v-for="(it, i) in items" :key="`${it.user.id}-${i}`" class="person">
        <span class="avatar">
          <Avatar :user="it.user" :size="48" link />
          <ReactionBadge class="corner" :type="it.type" :size="20" />
        </span>
        <RouterLink :to="{ name: 'member', params: { id: it.user.id } }" class="name">{{ it.user.name }}</RouterLink>
      </div>
      <div v-if="loading" class="person">
        <div class="skeleton" style="width: 48px; height: 48px; border-radius: 50%" />
        <div class="skeleton" style="width: 40%; height: 14px" />
      </div>
      <p v-if="!loading && !items.length" class="empty">{{ t.__('No reactions yet.', 'membuild-community') }}</p>
    </div>
  </BaseDialog>
</template>

<style scoped>
.tabs {
  display: flex;
  align-items: stretch;
  gap: 4px;
  padding: 8px 12px 0;
  border-bottom: 1px solid var(--border);
  overflow-x: auto;
  scrollbar-width: none;
  flex: none;
}

.tab {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 12px 14px 14px;
  font-size: 15px;
  font-weight: 650;
  color: var(--text-2);
  white-space: nowrap;
  border-radius: 8px 8px 0 0;
}

.tab:hover {
  background: var(--surface-2);
}

.tab.on {
  color: var(--accent-text);
}

.tab.on::after {
  content: '';
  position: absolute;
  left: 8px;
  right: 8px;
  bottom: -1px;
  height: 3px;
  border-radius: 3px 3px 0 0;
  background: var(--accent);
}

.close {
  margin-left: auto;
  align-self: center;
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
}

.list {
  padding: 8px 12px 12px;
}

.person {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 8px 4px;
}

.avatar {
  position: relative;
  display: inline-flex;
  flex: none;
}

.corner {
  position: absolute;
  right: -3px;
  bottom: -3px;
  --ring: var(--surface);
}

.name {
  color: var(--text);
  font-weight: 600;
  font-size: 16px;
}

.empty {
  margin: 16px 4px;
  color: var(--text-3);
}
</style>
