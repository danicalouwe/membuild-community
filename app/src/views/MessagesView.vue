<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { messages } from '../stores/messages.js'
import { timeAgo } from '../lib/time.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import ChatView from '../components/ChatView.vue'
import NewChatDialog from '../components/NewChatDialog.vue'
import { t } from '../lib/i18n.js'

const props = defineProps({ threadId: { type: Number, default: null } })
const route = useRoute()
const router = useRouter()

const threads = ref([])
const loaded = ref(false)
const query = ref('')
const composing = ref(false)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return threads.value
  return threads.value.filter((th) => nameOf(th).toLowerCase().includes(q))
})

const nameOf = (th) => th.title || th.others.map((o) => o.name).join(', ') || t.__('Just you', 'membuild-community')

async function loadInbox() {
  const res = await api.inbox().catch(() => null)
  if (!res) return
  threads.value = res.items
  messages.unread = res.unread
  loaded.value = true
}

// Any new message in any of my chats bumps inbox_version: refresh the list then.
watch(() => messages.inboxVersion, (v, old) => old !== undefined && v !== old && loadInbox())
onMounted(loadInbox)

// The Messenger dropdown's pencil links here with ?new=1.
watch(
  () => route.query.new,
  (v) => {
    if (!v) return
    composing.value = true
    router.replace({ query: {} })
  },
  { immediate: true },
)

function open(id) {
  router.push({ name: 'messages', params: { threadId: id } })
}

function onRead(unread) {
  messages.unread = unread
  const th = threads.value.find((x) => x.id === props.threadId)
  if (th) th.unread = 0
}

function started(chat) {
  composing.value = false
  if (!threads.value.some((th) => th.id === chat.id)) threads.value.unshift({ ...chat, last_message: null, unread: 0 })
  open(chat.id)
}

function preview(th) {
  const lm = th.last_message
  if (!lm) return t.__('No messages yet', 'membuild-community')
  if (lm.user_id === cfg.user.id) return t.sprintf(/* translators: %s: the latest message */ t.__('You: %s', 'membuild-community'), lm.preview)
  if (th.type !== 'group') return lm.preview
  const name = th.others.find((o) => o.id === lm.user_id)?.name.split(' ')[0] || t.__('Someone', 'membuild-community')
  return t.sprintf(/* translators: 1: sender's first name or file name, 2: the latest message or error message */ t.__('%1$s: %2$s', 'membuild-community'), name, lm.preview)
}
const unreadLabel = (n) => t.sprintf(/* translators: %d: number of unread messages */ t._n('%d unread', '%d unread', n, 'membuild-community'), n)
</script>

<template>
  <div class="messages" :class="{ 'has-chat': threadId }">
    <aside class="inbox card">
      <header class="inbox-head">
        <h1>{{ t.__('Chats', 'membuild-community') }}</h1>
        <button class="new" :aria-label="t.__('New message', 'membuild-community')" @click="composing = true"><Icon name="plus" :size="20" /></button>
      </header>
      <input v-model="query" class="search" type="search" :placeholder="t.__('Search chats', 'membuild-community')" :aria-label="t.__('Search chats', 'membuild-community')" />

      <ul class="list">
        <li v-for="th in filtered" :key="th.id">
          <button class="row" :class="{ active: th.id === threadId, unread: th.unread }" @click="open(th.id)">
            <span class="faces" :class="{ group: th.type === 'group' }">
              <Avatar v-for="o in th.others.slice(0, 2)" :key="o.id" :user="o" :size="th.type === 'group' ? 36 : 52" />
            </span>
            <span class="text">
              <strong>{{ nameOf(th) }}</strong>
              <span class="preview">
                <span class="p">{{ preview(th) }}</span>
                <span v-if="th.last_message" class="when">· {{ timeAgo(th.last_message.created_at) }}</span>
              </span>
            </span>
            <span v-if="th.unread" class="dot" :aria-label="unreadLabel(th.unread)">{{ th.unread > 9 ? '9+' : th.unread }}</span>
          </button>
        </li>
        <li v-if="loaded && !threads.length" class="empty">
          <p>{{ t.__('No conversations yet.', 'membuild-community') }}</p>
          <button class="btn btn-primary" @click="composing = true">{{ t.__('Send a message', 'membuild-community') }}</button>
        </li>
      </ul>
    </aside>

    <main class="pane card">
      <ChatView v-if="threadId" :key="threadId" :thread-id="threadId" @back="router.push({ name: 'messages' })" @left="router.push({ name: 'messages' }); loadInbox()" @read="onRead" />
      <div v-else class="pick">
        <span class="badge"><Icon name="chat" :size="30" /></span>
        <h2>{{ t.__('Your messages', 'membuild-community') }}</h2>
        <p>{{ t.__('Chat one-on-one or start a small group.', 'membuild-community') }}</p>
        <button class="btn btn-primary" @click="composing = true"><Icon name="plus" :size="16" /> {{ t.__('New message', 'membuild-community') }}</button>
      </div>
    </main>

    <NewChatDialog v-if="composing" @close="composing = false" @started="started" />
  </div>
</template>

<style scoped>
.messages {
  max-width: 1180px;
  height: calc(100dvh - var(--header-h) - 32px);
  margin: 16px auto 0;
  padding: 0 16px;
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 16px;
}

.inbox,
.pane {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}

.inbox-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 8px;
}

.inbox-head h1 {
  margin: 0;
  font-size: 22px;
}

.new {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--surface-2);
}

.search {
  margin: 0 12px 8px;
  height: 36px;
  padding: 0 14px;
  border: 0;
  border-radius: 999px;
  background: var(--surface-2);
}

.list {
  list-style: none;
  margin: 0;
  padding: 0 6px 8px;
  overflow-y: auto;
  flex: 1;
}

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 8px 10px;
  border-radius: 10px;
  text-align: left;
}

.row:hover {
  background: var(--surface-2);
}

.row.active {
  background: var(--accent-soft);
}

.faces {
  display: flex;
  flex: none;
  width: 52px;
}

.faces.group {
  position: relative;
  height: 52px;
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
  display: grid;
  place-items: center;
  flex: none;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: 11px;
  font-weight: 800;
}

.empty {
  padding: 24px 12px;
  text-align: center;
  color: var(--text-3);
}

.pick {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 100%;
  text-align: center;
  padding: 24px;
}

.pick .badge {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 20px;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.pick h2 {
  margin: 10px 0 0;
}

.pick p {
  margin: 0 0 12px;
  color: var(--text-2);
}

@media (max-width: 767px) {
  .messages {
    display: block;
    height: calc(100dvh - 50px - env(safe-area-inset-top) - var(--tabbar-h) - env(safe-area-inset-bottom));
    margin: 0;
    padding: 0;
  }

  .inbox,
  .pane {
    height: 100%;
    border-radius: 0;
    border: 0;
  }

  .pane {
    display: none;
  }

  .has-chat .inbox {
    display: none;
  }

  .has-chat .pane {
    display: flex;
  }
}
</style>
