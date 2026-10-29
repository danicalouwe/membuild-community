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
import { locale, t } from '../lib/i18n.js'
import { sharedIn } from '../lib/links.js'

const props = defineProps({ threadId: { type: Number, default: null } })
const route = useRoute()
const router = useRouter()

const threads = ref([])
const loaded = ref(false)
const query = ref('')
const composing = ref(false)
const filter = ref('all')
// The chat that was unread when you opened it: it stays under Unread while you read it.
const wasUnread = ref(null)
const FILTERS = [
  { key: 'all', label: t.__('All', 'membuild-community') },
  { key: 'unread', label: t.__('Unread', 'membuild-community') },
  { key: 'groups', label: t.__('Groups', 'membuild-community') },
]

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return threads.value.filter(
    (th) =>
      (!q || nameOf(th).toLowerCase().includes(q)) &&
      (filter.value !== 'unread' || th.unread || th.id === wasUnread.value) && // a chat you opened from Unread stays while you read it
      (filter.value !== 'groups' || th.type === 'group'),
  )
})

const nameOf = (th) => th.title || th.others.map((o) => o.name).join(', ') || t.__('Just you', 'membuild-community')

// The server answers the tab and the search, 30 chats at a time; more load as you scroll.
const hasMore = ref(false)
const loadingMore = ref(false)
let asked = 0
const params = () => ({ filter: filter.value === 'all' ? undefined : filter.value, search: query.value.trim() || undefined })
async function loadInbox() {
  const mine = ++asked
  const res = await api.inbox(params()).catch(() => null)
  if (!res || mine !== asked) return
  // A chat opened from Unread stays in the list while you read it.
  const keep = filter.value === 'unread' && wasUnread.value && !res.items.some((th) => th.id === wasUnread.value) ? threads.value.find((th) => th.id === wasUnread.value) : null
  threads.value = keep ? [keep, ...res.items] : res.items
  hasMore.value = res.has_more
  messages.unread = res.unread
  loaded.value = true
}
async function loadMore() {
  if (!hasMore.value || loadingMore.value) return
  const last = threads.value[threads.value.length - 1]
  if (!last?.last_at) return
  loadingMore.value = true
  const mine = asked
  try {
    const res = await api.inbox({ ...params(), before: last.last_at, before_id: last.id })
    if (mine !== asked) return
    const have = new Set(threads.value.map((th) => th.id))
    threads.value.push(...res.items.filter((th) => !have.has(th.id)))
    hasMore.value = res.has_more
  } catch {
    /* try again on the next scroll */
  } finally {
    loadingMore.value = false
  }
}
function onListScroll(e) {
  const el = e.target
  if (el.scrollTop + el.clientHeight > el.scrollHeight - 300) loadMore()
}
let searchTimer = 0
watch(filter, () => loadInbox())
watch(query, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadInbox, 300)
})

// Any new message in any of my chats bumps inbox_version: refresh the newest chats then,
// keeping the older ones already loaded further down (so a deep scroll isn't lost).
async function refreshTop() {
  const mine = ++asked
  const res = await api.inbox(params()).catch(() => null)
  if (!res || mine !== asked) return
  const fresh = new Set(res.items.map((th) => th.id))
  const oldest = res.items[res.items.length - 1]
  const below = res.has_more && oldest ? threads.value.filter((th) => !fresh.has(th.id) && (th.last_at < oldest.last_at || (th.last_at === oldest.last_at && th.id < oldest.id))) : []
  threads.value = [...res.items, ...below]
  if (!below.length) hasMore.value = res.has_more
  messages.unread = res.unread
}
watch(() => messages.inboxVersion, (v, old) => old !== undefined && v !== old && refreshTop())
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
  wasUnread.value = threads.value.find((th) => th.id === id)?.unread ? id : null
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

// A message that was a shared link reads as "Shared a post" in the list, not as the URL.
function words(lm) {
  const shared = sharedIn(lm.preview)
  if (!shared) return lm.preview
  const rest = lm.preview.replace(shared.href, '').trim()
  const what = shared.comment ? t.__('Shared a comment', 'membuild-community') : t.__('Shared a post', 'membuild-community')
  return rest ? `${what} · ${rest}` : what
}
function preview(th) {
  const lm = th.last_message
  if (!lm) return t.__('No messages yet', 'membuild-community')
  if (lm.user_id === cfg.user.id) return t.sprintf(/* translators: %s: the latest message */ t.__('You: %s', 'membuild-community'), words(lm))
  if (th.type !== 'group') return words(lm)
  const name = th.others.find((o) => o.id === lm.user_id)?.name.split(' ')[0] || t.__('Someone', 'membuild-community')
  return t.sprintf(/* translators: 1: sender's first name or file name, 2: the latest message or error message */ t.__('%1$s: %2$s', 'membuild-community'), name, words(lm))
}
// The list's time, as in the drawing: today's time, a weekday this week, else the date.
const timeFmt = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' })
const dayFmt = new Intl.DateTimeFormat(locale, { weekday: 'short' })
const dateFmt = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric' })
function whenOf(iso) {
  const d = new Date(iso)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  if (d >= today) return timeFmt.format(d)
  if (today - d < 6 * 86400000) return dayFmt.format(d)
  return dateFmt.format(d)
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
      <input v-if="!loaded || threads.length || query || filter !== 'all'" v-model="query" class="search" type="search" :placeholder="t.__('Search chats', 'membuild-community')" :aria-label="t.__('Search chats', 'membuild-community')" />

      <div v-if="!loaded || threads.length || query || filter !== 'all'" class="filters" role="tablist" :aria-label="t.__('Show chats', 'membuild-community')">
        <button v-for="f in FILTERS" :key="f.key" role="tab" :aria-selected="filter === f.key" :class="{ on: filter === f.key }" @click="filter = f.key">{{ f.label }}</button>
      </div>

      <ul class="list" @scroll.passive="onListScroll">
        <li v-for="th in filtered" :key="th.id">
          <button class="row" :class="{ active: th.id === threadId, unread: th.unread }" @click="open(th.id)">
            <span class="faces" :class="{ group: th.type === 'group' }">
              <Avatar v-for="o in th.others.slice(0, 2)" :key="o.id" :user="o" :size="th.type === 'group' ? 36 : 52" :online="th.type !== 'group'" />
            </span>
            <span class="text">
              <strong>{{ nameOf(th) }}</strong>
              <span class="p">{{ preview(th) }}</span>
            </span>
            <span class="side">
              <span v-if="th.last_message" class="when">{{ whenOf(th.last_message.created_at) }}</span>
              <span class="side-row">
                <Icon v-if="th.muted" class="muted-icon" name="bellOff" :size="16" :aria-label="t.__('Muted', 'membuild-community')" />
                <span v-if="th.unread" class="dot" :class="{ quiet: th.muted }" :aria-label="unreadLabel(th.unread)">{{ th.unread > 9 ? '9+' : th.unread }}</span>
              </span>
            </span>
          </button>
        </li>
        <li v-if="loadingMore" class="none-here">{{ t.__('Loading…', 'membuild-community') }}</li>
        <li v-if="loaded && !filtered.length && (threads.length || query || filter !== 'all')" class="none-here">{{ query ? t.__('No chats found.', 'membuild-community') : filter === 'unread' ? t.__('No unread chats.', 'membuild-community') : t.__('No group chats yet.', 'membuild-community') }}</li>
        <li v-if="loaded && !threads.length && !query && filter === 'all'" class="empty">
          <span class="empty-badge"><Icon name="comment" :size="30" /></span>
          <h2>{{ t.__('No messages yet', 'membuild-community') }}</h2>
          <p>{{ t.__('Say hello to someone you met here. Messages are private between you.', 'membuild-community') }}</p>
          <RouterLink class="btn btn-primary find" :to="{ name: 'members' }">{{ t.__('Find someone in Members', 'membuild-community') }}</RouterLink>
        </li>
      </ul>
    </aside>

    <main class="pane card">
      <ChatView v-if="threadId" :key="threadId" :thread-id="threadId" @back="router.push({ name: 'messages' })" @left="router.push({ name: 'messages' }); loadInbox()" @read="onRead" @renamed="(c) => { const th = threads.find((x) => x.id === threadId); if (th) th.title = c.title }" @muted="(on) => { const th = threads.find((x) => x.id === threadId); if (th) th.muted = on }" />
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

.filters {
  display: flex;
  gap: 8px;
  padding: 4px 12px 10px;
}

.filters button {
  min-height: 40px;
  padding: 0 18px;
  border-radius: 999px;
  background: var(--surface-2);
  font-size: 16px;
  font-weight: 650;
  color: var(--text-2);
}

.filters button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.text strong {
  font-size: 17px;
}

.p {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 15px;
  color: var(--text-2);
}

.side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
  flex: none;
}

.side-row {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 22px;
}

.when {
  font-size: 13px;
  color: var(--text-3);
}

.none-here {
  padding: 24px 12px;
  text-align: center;
  color: var(--text-3);
}

.unread .text strong,
.unread .p {
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
  background: var(--danger, #e02424);
  color: #fff;
  font-size: 12px;
  font-weight: 800;
}

.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 48px 20px;
  text-align: center;
  color: var(--text-2);
}

.empty-badge {
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  margin-bottom: 8px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.empty h2 {
  margin: 0;
  font-size: 20px;
  color: var(--text);
}

.empty p {
  margin: 0 0 12px;
  max-width: 340px;
  font-size: 16px;
  line-height: 1.45;
}

.empty .find {
  min-height: 52px;
  padding: 0 26px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  font-size: 16px;
}

.muted-icon {
  flex: none;
  color: var(--text-3);
}

.dot.quiet {
  background: var(--surface-3, #d5d8dd);
  color: var(--text-2);
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

  /* An open chat has no bottom bar (‹ goes back), so it takes that space too. */
  .messages.has-chat {
    height: calc(100dvh - 50px - env(safe-area-inset-top) - env(safe-area-inset-bottom));
  }

  .has-chat .inbox {
    display: none;
  }

  .has-chat .pane {
    display: flex;
  }
}
</style>
