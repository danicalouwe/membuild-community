<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { messages } from '../stores/messages.js'
import { timeAgo } from '../lib/time.js'
import { canDock, dock, openChat } from '../stores/chatDock.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

// The Messenger dropdown: recent chats. A chat opens as a docked window
// (like Facebook) on desktop, or the Messages page on phones.
const emit = defineEmits(['navigate'])
const router = useRouter()

const threads = ref([])
const loaded = ref(false)
const query = ref('')
const filter = ref('all')
const FILTERS = [
  { key: 'all', label: t.__('All', 'membuild-community') },
  { key: 'unread', label: t.__('Unread', 'membuild-community') },
  { key: 'groups', label: t.__('Groups', 'membuild-community') },
]

const nameOf = (th) => th.title || th.others.map((o) => o.name).join(', ') || t.__('Just you', 'membuild-community')
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return threads.value.filter(
    (th) =>
      (!q || nameOf(th).toLowerCase().includes(q)) &&
      (filter.value !== 'unread' || th.unread) &&
      (filter.value !== 'groups' || th.type === 'group'),
  )
})

async function load() {
  const res = await api.inbox().catch(() => null)
  if (!res) return
  threads.value = res.items
  messages.unread = res.unread
  loaded.value = true
}
onMounted(load)
watch(() => messages.inboxVersion, (v, old) => old !== undefined && v !== old && load())

function preview(th) {
  const lm = th.last_message
  if (!lm) return t.__('No messages yet', 'membuild-community')
  if (lm.user_id === cfg.user.id) return t.sprintf(/* translators: %s: the latest message */ t.__('You: %s', 'membuild-community'), lm.preview)
  if (th.type !== 'group') return lm.preview
  const name = th.others.find((o) => o.id === lm.user_id)?.name.split(' ')[0] || t.__('Someone', 'membuild-community')
  return t.sprintf(/* translators: 1: sender's first name or file name, 2: the latest message or error message */ t.__('%1$s: %2$s', 'membuild-community'), name, lm.preview)
}

function go(to) {
  emit('navigate')
  router.push(to)
}

function openThread(th) {
  if (!canDock() || router.currentRoute.value.name === 'messages') return go({ name: 'messages', params: { threadId: th.id } })
  emit('navigate')
  th.unread = 0
  openChat(th)
}

function compose() {
  if (!canDock() || router.currentRoute.value.name === 'messages') return go({ name: 'messages', query: { new: 1 } })
  emit('navigate')
  dock.composing = true
}
</script>

<template>
  <div class="panel">
    <header class="head">
      <h2>{{ t.__('Chats', 'membuild-community') }}</h2>
      <span class="tools">
        <button class="round" :aria-label="t.__('See all in Messages', 'membuild-community')" :title="t.__('See all in Messages', 'membuild-community')" @click="go({ name: 'messages' })"><Icon name="expand" :size="18" /></button>
        <button class="round" :aria-label="t.__('New message', 'membuild-community')" :title="t.__('New message', 'membuild-community')" @click="compose"><Icon name="compose" :size="18" /></button>
      </span>
    </header>
    <input v-model="query" class="search" type="search" :placeholder="t.__('Search chats', 'membuild-community')" :aria-label="t.__('Search chats', 'membuild-community')" />
    <div class="filters" role="tablist">
      <button v-for="f in FILTERS" :key="f.key" role="tab" :aria-selected="filter === f.key" :class="{ on: filter === f.key }" @click="filter = f.key">{{ f.label }}</button>
    </div>

    <ul class="list">
      <li v-for="th in filtered" :key="th.id">
        <button class="row" :class="{ unread: th.unread }" @click="openThread(th)">
          <span class="faces" :class="{ group: th.type === 'group' }">
            <Avatar v-for="o in th.others.slice(0, 2)" :key="o.id" :user="o" :size="th.type === 'group' ? 34 : 48" />
          </span>
          <span class="text">
            <strong>{{ nameOf(th) }}</strong>
            <span class="preview">
              <span class="p">{{ preview(th) }}</span>
              <span v-if="th.last_message" class="when">· {{ timeAgo(th.last_message.created_at) }}</span>
            </span>
          </span>
          <span v-if="th.unread" class="dot" :aria-label="t.__('Unread', 'membuild-community')" />
        </button>
      </li>
      <li v-if="!loaded" class="state">{{ t.__('Loading…', 'membuild-community') }}</li>
      <li v-else-if="!filtered.length" class="state">{{ query ? t.__('No chats found.', 'membuild-community') : filter === 'unread' ? t.__('No unread chats.', 'membuild-community') : filter === 'groups' ? t.__('No group chats yet.', 'membuild-community') : t.__('No conversations yet.', 'membuild-community') }}</li>
    </ul>

    <button class="all" @click="go({ name: 'messages' })">{{ t.__('See all in Messages', 'membuild-community') }}</button>
  </div>
</template>

<style scoped>
.panel {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 6px;
}

.head h2 {
  margin: 0;
  font-size: 22px;
}

.tools {
  display: flex;
  gap: 4px;
}

.filters {
  display: flex;
  gap: 6px;
  padding: 0 12px 6px;
}

.filters button {
  padding: 6px 12px;
  border-radius: 999px;
  font-weight: 650;
  font-size: 14px;
  color: var(--text-2);
}

.filters button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.round {
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
}

.round:hover {
  background: var(--surface-2);
}

.search {
  margin: 4px 12px 8px;
  height: 36px;
  padding: 0 14px;
  border: 0;
  border-radius: 999px;
  background: var(--surface-2);
}

.list {
  list-style: none;
  margin: 0;
  padding: 0 6px;
  overflow-y: auto;
}

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 8px;
  border-radius: 10px;
  text-align: left;
}

.row:hover {
  background: var(--surface-2);
}

.faces {
  display: flex;
  flex: none;
  width: 48px;
}

.faces.group {
  position: relative;
  height: 48px;
}

.faces.group :deep(.avatar-wrap) {
  position: absolute;
}

.faces.group :deep(.avatar-wrap:first-child) {
  top: 0;
  right: 0;
}

.faces.group :deep(.avatar-wrap:nth-child(2)) {
  bottom: 0;
  left: 0;
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.text strong {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.preview {
  display: flex;
  gap: 4px;
  font-size: 13px;
  color: var(--text-3);
}

.p {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.when {
  flex: none;
}

.unread .text strong,
.unread .preview {
  font-weight: 750;
  color: var(--text);
}

.dot {
  flex: none;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: var(--accent);
}

.state {
  padding: 24px 12px;
  text-align: center;
  color: var(--text-3);
}

.all {
  margin: 6px 12px 12px;
  padding: 10px;
  border-top: 1px solid var(--border);
  color: var(--accent-text);
  font-weight: 650;
}
</style>
