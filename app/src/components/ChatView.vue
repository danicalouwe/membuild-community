<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import GifMedia from './GifMedia.vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { watchChat, pulseNow, takeShareDraft } from '../stores/messages.js'
import { startAttachment, startGif } from '../lib/attachments.js'
import { giphyOn } from '../lib/giphy.js'
import { emojiFor } from '../lib/reactions.js'
import ReactionPicker from './ReactionPicker.vue'
import { BACKGROUNDS } from '../lib/postStyle.js'
import { sharedIn } from '../lib/links.js'

// The message's text once its community link became a card: the URL comes out, the words stay.
function textBesideCard(m) {
  if (!m.content) return ''
  const shared = sharedIn(m.content)
  if (!shared || !m.sharedOk) return m.content
  return m.content.replace(shared.href, '').replace(/[ \t]+/g, ' ').trim()
}
import SharedPost from './SharedPost.vue'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import RichText from './RichText.vue'
const GifPicker = defineAsyncComponent(() => import('./GifPicker.vue'))
import { listNames, locale, t } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { useMenu } from '../lib/useMenu.js'

/**
 * One conversation: bubbles grouped by sender, time breaks, "Seen" avatars,
 * typing dots, reactions, replies, photos and unsend. New messages arrive
 * through the pulse (every 3 seconds while the chat is open).
 */
const props = defineProps({
  threadId: { type: Number, required: true },
  // A small window docked at the bottom right instead of the Messages page pane.
  docked: { type: Boolean, default: false },
})
const emit = defineEmits(['back', 'left', 'read', 'loaded', 'minimize', 'close', 'expand'])

const chat = ref(null)
const blockedNotice = ref(false) // shown after blocking from this chat
const items = ref([])
const hasMore = ref(false)
const loadingOlder = ref(false)
const typing = ref([])
const draft = ref('')
const replyTo = ref(null)
const photos = ref([])
const showEmoji = ref(false)
// The emoji button opens the floating emoji panel (search, categories), anchored to it.
const emojiBtn = ref(null)
function toggleEmoji(e) {
  emojiBtn.value = e.currentTarget
  showEmoji.value = !showEmoji.value
}
const menuFor = ref(null) // message id with the reaction pill open
const moreFor = ref(null) // message id with the ⋮ menu open
const reactAt = ref(null) // the open picker's anchor: { rect, side }
const { open: headerMenu, root: headerMenuRoot, trigger: headerMenuTrigger, list: headerMenuList, toggle: toggleHeaderMenu, close: closeHeaderMenu, onKeydown: onHeaderMenuKey } = useMenu()
const error = ref('')
const scroller = ref(null)
const input = ref(null)
const photoInput = ref(null)
let stopWatching = null
let lastTyping = 0
let tempId = 0
// Loads finish after awaits: a closed chat (or one switched away from) must not start watching.
let alive = true
const latest = useLatest()

const others = computed(() => chat.value?.members.filter((m) => m.id !== cfg.user.id && !m.left) || [])
const title = computed(() => chat.value?.title || listNames(others.value.map((m) => m.name)) || t.__('Just you', 'membuild-community'))
const isGroup = computed(() => chat.value?.type === 'group')
const userById = computed(() => Object.fromEntries((chat.value?.members || []).concat([cfg.user]).map((u) => [u.id, u])))
const lastRealId = () => [...items.value].reverse().find((m) => typeof m.id === 'number')?.id || 0

/* ---------- Layout helpers ---------- */

const fmtTime = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' })
// One formatter for day and time, so each language orders and joins them its own way.
const fmtDayTime = new Intl.DateTimeFormat(locale, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
function stamp(iso) {
  const d = new Date(iso)
  const today = new Date()
  const sameDay = d.toDateString() === today.toDateString()
  return sameDay ? t.sprintf(/* translators: %s: a time of day, e.g. 3:45 PM */ t.__('Today %s', 'membuild-community'), fmtTime.format(d)) : fmtDayTime.format(d)
}

// Each message knows whether it starts a new time block and whether it is
// the first/last bubble in a run from the same person, for Messenger-style grouping.
const rows = computed(() =>
  items.value.map((m, i) => {
    const prev = items.value[i - 1]
    const next = items.value[i + 1]
    const gap = (a, b) => !a || !b || Math.abs(new Date(b.created_at) - new Date(a.created_at)) > 20 * 60 * 1000
    return {
      m,
      showTime: gap(prev, m),
      first: !prev || prev.user_id !== m.user_id || gap(prev, m),
      last: !next || next.user_id !== m.user_id || gap(m, next),
    }
  }),
)

// "Seen": under my latest message, the people who have read up to it.
const seenBy = computed(() => {
  const mine = [...items.value].reverse().find((m) => m.mine && typeof m.id === 'number')
  if (!mine || !chat.value) return { id: null, users: [] }
  return { id: mine.id, users: others.value.filter((u) => u.last_read_id >= mine.id) }
})

function scrollToBottom(smooth = false) {
  nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }))
}

function nearBottom() {
  const el = scroller.value
  return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 160
}

/* ---------- Loading ---------- */

async function load() {
  error.value = ''
  chat.value = null
  items.value = []
  typing.value = []
  replyTo.value = null
  stopWatching?.()
  stopWatching = null
  const current = latest()
  try {
    const [c, history] = await Promise.all([api.chat(props.threadId), api.chatMessages(props.threadId)])
    if (!alive || !current()) return
    chat.value = c
    emit('loaded', c)
    items.value = history.items
    hasMore.value = history.has_more
    scrollToBottom()
    markRead()
    const shared = takeShareDraft() // a link handed over by "Send in a message"
    if (shared) {
      draft.value = draft.value ? `${draft.value}\n${shared}` : shared
      nextTick(() => input.value?.focus())
    }
    stopWatching = watchChat(props.threadId, lastRealId, onPulse)
    nextTick(() => input.value?.focus())
  } catch (err) {
    if (alive && current()) error.value = err.message
  }
}

async function loadOlder() {
  if (!hasMore.value || loadingOlder.value) return
  loadingOlder.value = true
  const el = scroller.value
  const fromBottom = el.scrollHeight - el.scrollTop
  const thread = props.threadId
  try {
    const res = await api.chatMessages(thread, items.value.find((m) => typeof m.id === 'number')?.id)
    if (!alive || thread !== props.threadId) return
    items.value = [...res.items, ...items.value]
    hasMore.value = res.has_more
    nextTick(() => (el.scrollTop = el.scrollHeight - fromBottom)) // keep the reader's place
  } catch {
    // Scrolling back up tries again; no need to interrupt the chat.
  } finally {
    loadingOlder.value = false
  }
}

function onScroll() {
  if (scroller.value?.scrollTop < 80) loadOlder()
}

function onPulse(thread) {
  const stick = nearBottom()
  const known = new Set(items.value.map((m) => m.id))
  const fresh = thread.messages.filter((m) => !known.has(m.id))
  if (fresh.length) {
    // One of mine arriving while its send is still in flight replaces the pending bubble,
    // so a slow send (a busy host, mail going out in the same request) never shows it twice.
    for (const m of fresh) {
      if (!m.mine) continue
      const i = items.value.findIndex((p) => p.pending && (p.content || null) === (m.content || null) && p.media.length === (m.media || []).length)
      if (i >= 0) items.value.splice(i, 1)
    }
    items.value.push(...fresh)
    if (stick) scrollToBottom(true)
    if (fresh.some((m) => !m.mine)) markRead()
  }
  typing.value = thread.typing
  for (const r of thread.reads) {
    const member = chat.value?.members.find((m) => m.id === r.user_id)
    if (member) member.last_read_id = r.last_read_id
  }
}

let readTimer = null
function markRead() {
  clearTimeout(readTimer)
  readTimer = setTimeout(async () => {
    if (document.hidden || !alive) return
    const res = await api.markRead(props.threadId, lastRealId()).catch(() => null)
    if (res && alive) emit('read', res.unread)
  }, 300)
}

watch(() => props.threadId, load, { immediate: true })
onBeforeUnmount(() => {
  alive = false
  clearTimeout(readTimer)
  stopWatching?.()
  stopWatching = null
})

/* ---------- Sending ---------- */

function onInput() {
  if (Date.now() - lastTyping > 3000) {
    lastTyping = Date.now()
    api.typing(props.threadId).catch(() => {})
  }
}

function pickPhotos(e) {
  for (const file of [...e.target.files].slice(0, 10 - photos.value.length)) {
    if (file.type.startsWith('image/')) photos.value.push(startAttachment(file))
  }
  e.target.value = ''
}

const showGifs = ref(false)
function addGif(gif) {
  if (photos.value.length < 10) photos.value.push(startGif(gif))
  nextTick(() => input.value?.focus())
}

async function send() {
  const content = draft.value.trim()
  const pics = photos.value
  if ((!content && !pics.length) || pics.some((p) => p.status === 'error')) return
  const reply = replyTo.value
  draft.value = ''
  photos.value = []
  replyTo.value = null
  showEmoji.value = false

  const temp = {
    id: `tmp-${++tempId}`,
    user_id: cfg.user.id,
    mine: true,
    content: content || null,
    media: pics.map((p) => ({ url: p.previewUrl })),
    reply_to: reply ? { id: reply.id, user_id: reply.user_id, content: reply.content } : null,
    reactions: [],
    created_at: new Date().toISOString(),
    pending: true,
  }
  items.value.push(temp)
  scrollToBottom(true)

  try {
    await Promise.all(pics.map((p) => p.promise))
    if (pics.some((p) => p.status !== 'done')) throw new Error(t.__('A photo failed to upload.', 'membuild-community'))
    const saved = await api.sendMessage(props.threadId, {
      content,
      media_ids: pics.map((p) => p.media.id),
      reply_to_id: reply?.id,
    })
    // The pulse may have delivered it already; keep exactly one copy.
    const i = items.value.indexOf(temp)
    if (items.value.some((m) => m.id === saved.id)) {
      if (i >= 0) items.value.splice(i, 1)
    } else if (i >= 0) {
      items.value.splice(i, 1, saved)
    } else {
      items.value.push(saved)
    }
    pics.forEach((p) => p.release()) // the saved photos show now, not the local previews
    pulseNow()
  } catch (err) {
    temp.pending = false
    temp.failed = err.message
  }
}

function onKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  } else if (e.key === 'Escape' && replyTo.value) {
    replyTo.value = null
  }
}

function insertEmoji(e) {
  draft.value += e
  nextTick(() => input.value?.focus())
}

/* ---------- Message actions ---------- */

// The picker floats above the page (see ReactionPicker), anchored to the message's bubble.
function openReactions(m, e) {
  moreFor.value = null
  if (menuFor.value === m.id) return closeReactions()
  const bubble = e?.currentTarget?.closest('.bubble-row')?.querySelector('.bubble-wrap')
  reactAt.value = bubble ? { rect: bubble.getBoundingClientRect(), side: m.mine ? 'end' : 'start' } : null
  menuFor.value = reactAt.value ? m.id : null
}
function closeReactions() {
  menuFor.value = null
  reactAt.value = null
}

function openMore(m) {
  menuFor.value = null
  moreFor.value = moreFor.value === m.id ? null : m.id
}

async function react(m, type) {
  closeReactions()
  const removing = m.my_reaction === type
  const before = { reactions: m.reactions, my_reaction: m.my_reaction }
  m.reactions = removing ? m.reactions.filter((r) => r.user_id !== cfg.user.id) : [...m.reactions.filter((r) => r.user_id !== cfg.user.id), { user_id: cfg.user.id, type }]
  m.my_reaction = removing ? null : type
  try {
    Object.assign(m, removing ? await api.unreactMessage(m.id) : await api.reactMessage(m.id, type))
  } catch {
    Object.assign(m, before)
  }
}

function startReply(m) {
  menuFor.value = null
  replyTo.value = m
  nextTick(() => input.value?.focus())
}

async function unsend(m) {
  menuFor.value = null
  moreFor.value = null
  if (!confirm(t.__('Unsend this message for everyone?', 'membuild-community'))) return
  try {
    Object.assign(m, await api.unsend(m.id))
  } catch (err) {
    alert(err.message)
  }
}

const reactionSummary = (m) => {
  const counts = {}
  for (const r of m.reactions) counts[r.type] = (counts[r.type] || 0) + 1
  return Object.entries(counts).sort((a, b) => b[1] - a[1])
}

/* ---------- Group ---------- */

async function rename() {
  closeHeaderMenu()
  const name = prompt(t.__('Group name', 'membuild-community'), chat.value.title || '')
  if (name === null) return
  try {
    chat.value = await api.renameChat(props.threadId, name)
  } catch (err) {
    alert(err.message)
  }
}

// Blocking from a one-on-one chat, as in Messenger: the chat stays readable, but nobody can add to it.
async function blockOther() {
  closeHeaderMenu()
  const person = others.value[0]
  if (!person || !confirm(t.sprintf(/* translators: %s: member's name */ t.__('Block %s? You won’t see each other’s posts, comments or stories, and you can’t message each other. They aren’t told.', 'membuild-community'), person.name))) return
  try {
    await api.block(person.id)
    blockedNotice.value = true
  } catch (err) {
    alert(err.message)
  }
}

async function leave() {
  closeHeaderMenu()
  if (!confirm(t.__('Leave this group? You will stop getting its messages.', 'membuild-community'))) return
  try {
    await api.leaveChat(props.threadId)
    emit('left')
  } catch (err) {
    alert(err.message)
  }
}

const memberCount = (n) => t.sprintf(/* translators: %d: number of members */ t._n('%d member', '%d members', n, 'membuild-community'), n)
const notSent = (reason) => t.sprintf(/* translators: %s: why the message wasn't sent */ t.__('Not sent · %s', 'membuild-community'), reason)
const typingLabel = (people) => t.sprintf(/* translators: %s: the names of the people typing */ t.__('%s typing', 'membuild-community'), listNames(people.map((u) => u.name)))
const replyingTo = (name) => t.sprintf(/* translators: %s: the name of the person being replied to */ t.__('Replying to %s', 'membuild-community'), name)
</script>

<template>
  <section class="chat" :class="{ docked }">
    <header v-if="docked" class="head">
      <template v-if="chat">
        <div :ref="headerMenuRoot" class="menu-wrap name-wrap">
          <button :ref="headerMenuTrigger" class="name-btn" aria-haspopup="menu" :aria-expanded="headerMenu" @click="toggleHeaderMenu">
            <span class="faces" :class="{ group: isGroup }">
              <Avatar v-for="u in others.slice(0, 2)" :key="u.id" :user="u" :size="isGroup ? 26 : 32" />
            </span>
            <strong>{{ title }}</strong>
            <Icon name="chevronDown" :size="16" />
          </button>
          <div v-if="headerMenu" :ref="headerMenuList" class="menu left" role="menu" @keydown="onHeaderMenuKey">
            <button role="menuitem" @click="closeHeaderMenu(); emit('expand')">{{ t.__('Open in Messages', 'membuild-community') }}</button>
            <template v-if="isGroup">
              <button role="menuitem" @click="rename">{{ t.__('Rename group', 'membuild-community') }}</button>
              <button role="menuitem" class="danger" @click="leave">{{ t.__('Leave group', 'membuild-community') }}</button>
            </template>
            <template v-else-if="others[0]">
              <RouterLink role="menuitem" :to="{ name: 'member', params: { id: others[0].id } }" @click="closeHeaderMenu">{{ t.__('View profile', 'membuild-community') }}</RouterLink>
              <button role="menuitem" class="danger" @click="blockOther">{{ t.__('Block', 'membuild-community') }}</button>
            </template>
          </div>
        </div>
      </template>
      <span v-else class="who" />
      <button class="icon small" :aria-label="t.__('Minimize chat', 'membuild-community')" :title="t.__('Minimize chat', 'membuild-community')" @click="emit('minimize')"><Icon name="minus" :size="20" /></button>
      <button class="icon small" :aria-label="t.__('Close chat', 'membuild-community')" :title="t.__('Close chat', 'membuild-community')" @click="emit('close')"><Icon name="close" :size="20" /></button>
    </header>
    <header v-else class="head">
      <button class="icon back" :aria-label="t.__('Back to chats', 'membuild-community')" @click="emit('back')"><Icon name="chevronLeft" :size="22" /></button>
      <template v-if="chat">
        <span class="faces" :class="{ group: isGroup }">
          <Avatar v-for="u in others.slice(0, 2)" :key="u.id" :user="u" :size="isGroup ? 30 : 40" />
        </span>
        <div class="who">
          <strong>{{ title }}</strong>
          <span v-if="isGroup">{{ memberCount(others.length + 1) }}</span>
          <span v-else-if="others[0]">{{ t.sprintf(t.__('Level %d', 'membuild-community'), others[0].level) }}</span>
        </div>
        <div v-if="isGroup || others[0]" :ref="headerMenuRoot" class="menu-wrap">
          <button :ref="headerMenuTrigger" class="icon" :aria-label="t.__('Chat options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="headerMenu" @click="toggleHeaderMenu"><Icon name="more" :size="22" /></button>
          <div v-if="headerMenu" :ref="headerMenuList" class="menu" role="menu" @keydown="onHeaderMenuKey">
            <template v-if="isGroup">
              <button role="menuitem" @click="rename">{{ t.__('Rename group', 'membuild-community') }}</button>
              <button role="menuitem" class="danger" @click="leave">{{ t.__('Leave group', 'membuild-community') }}</button>
            </template>
            <template v-else>
              <RouterLink role="menuitem" :to="{ name: 'member', params: { id: others[0].id } }" @click="closeHeaderMenu">{{ t.__('View profile', 'membuild-community') }}</RouterLink>
              <button role="menuitem" class="danger" @click="blockOther">{{ t.__('Block', 'membuild-community') }}</button>
            </template>
          </div>
        </div>
      </template>
    </header>

    <div ref="scroller" class="scroll" @scroll.passive="onScroll" @click="menuFor = null; moreFor = null">
      <p v-if="error" class="state">{{ error }}</p>
      <div v-else-if="!chat" class="state"><div class="spinner" /></div>
      <template v-else>
        <div v-if="!hasMore" class="intro">
          <span class="faces big"><Avatar v-for="u in others.slice(0, 3)" :key="u.id" :user="u" :size="64" badge /></span>
          <strong>{{ title }}</strong>
          <span>{{ isGroup ? t.__('Group chat', 'membuild-community') : t.__('You’re both members of this community', 'membuild-community') }}</span>
        </div>
        <div v-else-if="loadingOlder" class="state small"><div class="spinner" /></div>

        <template v-for="row in rows" :key="row.m.id">
          <div v-if="row.showTime" class="time">{{ stamp(row.m.created_at) }}</div>
          <div class="msg" :class="{ mine: row.m.mine, first: row.first, last: row.last, pending: row.m.pending }">
            <span class="side">
              <Avatar v-if="!row.m.mine && row.last" :user="userById[row.m.user_id]" :size="28" />
            </span>
            <div class="stack">
              <span v-if="isGroup && !row.m.mine && row.first" class="sender">{{ userById[row.m.user_id]?.name }}</span>

              <div v-if="row.m.reply_to" class="quoted">
                <span>{{ row.m.reply_to.user_id === cfg.user.id ? t.__('You', 'membuild-community') : userById[row.m.reply_to.user_id]?.name }}</span>
                {{ row.m.reply_to.content || t.__('Message unsent', 'membuild-community') }}
              </div>

              <div v-if="row.m.story" class="story-card">
                <span class="story-thumb" :style="row.m.story.thumb ? null : { background: BACKGROUNDS[row.m.story.background] }">
                  <img v-if="row.m.story.thumb" :src="row.m.story.thumb" alt="" />
                  <small v-else>{{ row.m.story.text }}</small>
                </span>
                <span class="story-label">{{ row.m.mine ? t.__('You replied to their story', 'membuild-community') : t.__('Replied to your story', 'membuild-community') }}</span>
              </div>

              <div class="bubble-row">
                <div v-if="row.m.unsent" class="bubble unsent">{{ row.m.mine ? t.__('You unsent a message', 'membuild-community') : t.__('Message unsent', 'membuild-community') }}</div>
                <div v-else class="bubble-wrap">
                  <div v-if="row.m.media.length" class="photos" :class="`n${Math.min(row.m.media.length, 3)}`">
                    <a v-for="(p, i) in row.m.media" :key="i" :href="p.url" target="_blank" rel="noopener"><GifMedia v-if="p.mp4" :src="p.url" :mp4="p.mp4" :width="p.width || 0" :height="p.height || 0" /><img v-else :src="p.url" alt="" loading="lazy" /></a>
                  </div>
                  <!-- A link into this community shows as a preview card in place of the URL; any words around it follow in a bubble. -->
                  <SharedPost v-if="sharedIn(row.m.content)" :post="sharedIn(row.m.content).post" :comment="sharedIn(row.m.content).comment" class="card" @ready="(ok) => (row.m.sharedOk = ok)" />
                  <div v-if="textBesideCard(row.m)" class="bubble"><RichText :text="textBesideCard(row.m)" /></div>
                  <span v-if="row.m.reactions.length" class="reacts">
                    <span v-for="[type, n] in reactionSummary(row.m)" :key="type">{{ emojiFor(type) }}<b v-if="n > 1">{{ n }}</b></span>
                  </span>
                </div>

                <!-- Like Messenger: react, reply, and ⋮ (Unsend) on your own; the smiley sits nearest the bubble. -->
                <div v-if="!row.m.unsent && !row.m.pending && !row.m.failed" class="actions" :class="{ open: menuFor === row.m.id || moreFor === row.m.id }">
                  <button :class="{ on: menuFor === row.m.id }" :aria-label="t.__('React', 'membuild-community')" :aria-expanded="menuFor === row.m.id" @click.stop="openReactions(row.m, $event)"><Icon name="smile" :size="17" /></button>
                  <button :aria-label="t.__('Reply', 'membuild-community')" @click.stop="startReply(row.m)"><Icon name="share" :size="16" class="flip" /></button>
                  <span v-if="row.m.mine" class="more-wrap">
                    <button :class="{ on: moreFor === row.m.id }" :aria-label="t.__('More', 'membuild-community')" :aria-expanded="moreFor === row.m.id" @click.stop="openMore(row.m)"><Icon name="more" :size="18" class="vertical" /></button>
                    <span v-if="moreFor === row.m.id" class="more-menu card" role="menu" @click.stop>
                      <button role="menuitem" @click="unsend(row.m)"><Icon name="trash" :size="16" /> {{ t.__('Unsend', 'membuild-community') }}</button>
                    </span>
                  </span>
                </div>
                <ReactionPicker v-if="menuFor === row.m.id && reactAt" :anchor="reactAt.rect" :side="reactAt.side" :current="row.m.my_reaction" @pick="(type) => react(row.m, type)" @close="closeReactions" />
              </div>

              <span v-if="row.m.failed" class="failed">{{ notSent(row.m.failed) }}</span>
              <span v-else-if="row.m.pending" class="status">{{ t.__('Sending…', 'membuild-community') }}</span>
              <span v-else-if="row.m.id === seenBy.id && seenBy.users.length" class="seen">
                <template v-if="isGroup">
                  <Avatar v-for="u in seenBy.users.slice(0, 5)" :key="u.id" :user="u" :size="14" />
                </template>
                <template v-else>{{ t.__('Seen', 'membuild-community') }}</template>
              </span>
            </div>
          </div>
        </template>

        <div v-if="typing.length" class="msg typing-row">
          <span class="side"><Avatar :user="typing[0]" :size="28" /></span>
          <div class="bubble typing" :aria-label="typingLabel(typing)"><i /><i /><i /></div>
        </div>
      </template>
    </div>

    <p v-if="chat && cfg.community.restriction" class="composer muted-note">{{ t.__("You're muted, so you can't send messages right now.", 'membuild-community') }}</p>
    <p v-else-if="chat && blockedNotice" class="composer muted-note">{{ t.__('You blocked this person. Unblock them from their profile to message again.', 'membuild-community') }}</p>
    <form v-else-if="chat" class="composer" @submit.prevent="send">
      <div v-if="replyTo" class="replying">
        <div>
          <strong>{{ replyTo.mine ? t.__('Replying to yourself', 'membuild-community') : replyingTo(userById[replyTo.user_id]?.name) }}</strong>
          <span>{{ replyTo.content || t.__('Photo', 'membuild-community') }}</span>
        </div>
        <button type="button" :aria-label="t.__('Cancel reply', 'membuild-community')" @click="replyTo = null"><Icon name="close" :size="16" /></button>
      </div>
      <div v-if="photos.length" class="pending-photos">
        <span v-for="(p, i) in photos" :key="p.key" class="pp">
          <img :src="p.previewUrl" alt="" />
          <i v-if="p.status === 'working'" :style="{ transform: `scaleX(${p.progress})` }" />
          <button type="button" :aria-label="t.__('Remove photo', 'membuild-community')" @click="p.cancel(); photos.splice(i, 1)"><Icon name="close" :size="12" /></button>
        </span>
      </div>
      <div class="bar">
        <button type="button" class="icon tool" :aria-label="t.__('Add photo', 'membuild-community')" @click="photoInput.click()"><Icon name="image" :size="22" /></button>
        <input ref="photoInput" class="sr-only" type="file" accept="image/*" multiple @change="pickPhotos" />
        <button v-if="giphyOn()" type="button" class="icon tool" :aria-label="t.__('Add a GIF', 'membuild-community')" @click="showGifs = true"><span class="gif-badge" aria-hidden="true">GIF</span></button>
        <GifPicker v-if="showGifs" @pick="addGif" @close="showGifs = false" />
        <div class="field">
          <textarea ref="input" v-auto-grow v-model="draft" rows="1" :placeholder="t.__('Aa', 'membuild-community')" :aria-label="t.__('Write a message', 'membuild-community')" @input="onInput" @keydown="onKey" />
          <div class="emoji-wrap">
            <button type="button" class="icon" :aria-label="t.__('Emoji', 'membuild-community')" :aria-expanded="showEmoji" @click="toggleEmoji">😊</button>
            <ReactionPicker v-if="showEmoji && emojiBtn" mode="emoji" side="center" :anchor="emojiBtn.getBoundingClientRect()" :ignore="emojiBtn" @pick="insertEmoji" @close="showEmoji = false" />
          </div>
        </div>
        <button v-if="draft.trim() || photos.length" type="submit" class="icon send" :aria-label="t.__('Send', 'membuild-community')"><Icon name="send" :size="22" /></button>
        <button v-else type="button" class="icon send like" :aria-label="t.__('Send a like', 'membuild-community')" @click="draft = '👍'; send()">👍</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.muted-note {
  margin: 0;
  text-align: center;
  color: var(--text-3);
  font-size: 14px;
}

/* Docked window: a compact header and tighter spacing. */
.docked .head {
  gap: 2px;
  padding: 6px 6px 6px 8px;
  box-shadow: 0 1px 2px rgb(0 0 0 / 10%);
}

.name-wrap {
  flex: 1;
  min-width: 0;
}

.name-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 100%;
  padding: 4px 6px 4px 4px;
  border-radius: 8px;
  color: var(--text);
}

.name-btn:hover {
  background: var(--surface-2);
}

.name-btn strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 15px;
}

.icon.small {
  width: 32px;
  height: 32px;
}

.menu.left {
  left: 0;
  right: auto;
}

.docked .scroll {
  padding: 8px 8px 2px;
}

.chat {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: var(--surface);
}

.head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  flex: none;
}

.icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  color: var(--accent-text);
  flex: none;
}

.icon:hover {
  background: var(--surface-2);
}

.back {
  display: none;
}

.faces {
  display: flex;
  flex: none;
}

.faces.group :deep(.avatar-wrap + .avatar-wrap) {
  margin-left: -12px;
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.who {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.25;
  flex: 1;
}

.who strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.who span {
  font-size: 12px;
  color: var(--text-3);
}

.menu-wrap {
  position: relative;
}

.menu {
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 5;
  min-width: 170px;
  padding: 6px;
  border-radius: 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: 0 8px 24px rgb(0 0 0 / 20%);
}

.menu button {
  display: block;
  width: 100%;
  text-align: left;
  padding: 8px 10px;
  border-radius: 8px;
  font-weight: 600;
}

.menu button:hover {
  background: var(--surface-2);
}

.danger {
  color: var(--danger);
}

.scroll {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 12px 12px 4px;
}

.state {
  display: grid;
  place-items: center;
  padding: 40px 0;
  color: var(--text-3);
}

.state.small {
  padding: 8px 0;
}

.spinner {
  width: 28px;
  height: 28px;
  border: 3px solid var(--border);
  border-top-color: var(--accent-text);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.intro {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 24px 0 18px;
  text-align: center;
}

.intro strong {
  margin-top: 8px;
  font-size: 18px;
}

.intro span {
  color: var(--text-3);
  font-size: 13px;
}

.faces.big :deep(.avatar-wrap + .avatar-wrap) {
  margin-left: -18px;
}

.time {
  margin: 14px 0 8px;
  text-align: center;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-3);
}

.msg {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  margin-top: 2px;
}

.msg.first {
  margin-top: 8px;
}

.msg.mine {
  flex-direction: row-reverse;
}

.msg.pending {
  opacity: 0.7;
}

.side {
  width: 28px;
  flex: none;
}

.mine .side {
  display: none;
}

.stack {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  max-width: min(72%, 520px);
  min-width: 0;
}

.mine .stack {
  align-items: flex-end;
}

.sender {
  margin: 0 0 2px 12px;
  font-size: 12px;
  color: var(--text-3);
}

.bubble-row {
  display: flex;
  align-items: center;
  gap: 4px;
}

.mine .bubble-row {
  flex-direction: row-reverse;
}

.bubble-wrap {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: inherit;
  gap: 2px;
}

.mine .bubble-wrap {
  align-items: flex-end;
}

.bubble {
  padding: 8px 12px;
  border-radius: 18px;
  background: var(--surface-2);
  font-size: 15px;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.card {
  margin-top: 4px;
}

.card + .bubble {
  margin-top: 4px;
}

.mine .bubble {
  background: var(--accent);
  color: var(--accent-ink);
}

.mine .bubble :deep(a) {
  color: inherit;
  text-decoration: underline;
}

/* Messenger-style corners: runs of bubbles from one person hug each other. */
.msg:not(.mine):not(.first) .bubble {
  border-top-left-radius: 6px;
}

.msg:not(.mine):not(.last) .bubble {
  border-bottom-left-radius: 6px;
}

.mine:not(.first) .bubble {
  border-top-right-radius: 6px;
}

.mine:not(.last) .bubble {
  border-bottom-right-radius: 6px;
}

.bubble.unsent {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text-3);
  font-style: italic;
}

.photos {
  display: grid;
  gap: 2px;
  border-radius: 16px;
  overflow: hidden;
  max-width: 320px;
}

.photos.n2 {
  grid-template-columns: 1fr 1fr;
}

.photos.n3 {
  grid-template-columns: 1fr 1fr 1fr;
}

.photos img {
  display: block;
  width: 100%;
  max-height: 320px;
  object-fit: cover;
}

.photos.n2 img,
.photos.n3 img {
  aspect-ratio: 1;
}

.reacts {
  position: relative;
  align-self: flex-end; /* on the bubble's bottom-right corner, as in Messenger */
  margin: -8px 6px 0 0;
  z-index: 1;
  display: inline-flex;
  gap: 2px;
  padding: 1px 6px;
  border-radius: 999px;
  background: var(--surface);
  border: 1px solid var(--border);
  font-size: 13px;
}

.reacts b {
  font-size: 11px;
  margin-left: 1px;
  color: var(--text-2);
}

.actions {
  position: relative;
  display: flex;
  opacity: 0;
  transition: opacity 0.1s ease;
}

.bubble-row:hover .actions,
.actions:focus-within {
  opacity: 1;
}

/* Touch screens have no hover, so the actions stay visible there. */
@media (hover: none) {
  .actions {
    opacity: 1;
  }
}

.actions button {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  color: var(--text-3);
}

.actions button:hover {
  background: var(--surface-2);
  color: var(--text);
}

.flip {
  transform: scaleX(-1);
}

.bubble-row {
  position: relative;
}

/* Yours: the smiley nearest the bubble, then reply, then ⋮. */
.mine .actions {
  flex-direction: row-reverse;
}

.actions.open {
  opacity: 1;
}

.actions button.on {
  background: var(--surface-2);
  color: var(--text);
}

.vertical {
  transform: rotate(90deg);
}

.more-wrap {
  position: relative;
}

.more-menu {
  position: absolute;
  bottom: calc(100% + 4px);
  right: 0;
  z-index: 6;
  padding: 4px;
  min-width: 140px;
}

.more-menu button {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  height: auto;
  padding: 8px 10px;
  border-radius: 8px;
  color: var(--text);
  font-weight: 600;
  white-space: nowrap;
}

.mine .more-menu {
  right: auto;
  left: 0;
}

.quoted {
  margin-bottom: -10px;
  padding: 6px 12px 14px;
  border-radius: 14px;
  background: var(--surface-2);
  opacity: 0.7;
  font-size: 13px;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.quoted span {
  display: block;
  font-weight: 650;
  font-size: 11px;
}

.story-card {
  display: flex;
  flex-direction: column;
  align-items: inherit;
  gap: 4px;
  margin-bottom: 4px;
}

.story-thumb {
  display: grid;
  place-items: center;
  width: 96px;
  aspect-ratio: 9 / 16;
  border-radius: 12px;
  overflow: hidden;
  background: #222;
}

.story-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.story-thumb small {
  padding: 6px;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  text-align: center;
}

.story-label {
  font-size: 12px;
  color: var(--text-3);
}

.status,
.failed,
.seen {
  margin-top: 3px;
  font-size: 11px;
  color: var(--text-3);
}

.failed {
  color: var(--danger);
}

.seen {
  display: flex;
  gap: 2px;
}

.typing {
  display: flex;
  gap: 4px;
  padding: 12px 14px;
}

.typing i {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--text-3);
  animation: dot 1.2s infinite ease-in-out;
}

.typing i:nth-child(2) {
  animation-delay: 0.15s;
}

.typing i:nth-child(3) {
  animation-delay: 0.3s;
}

@keyframes dot {
  0%,
  60%,
  100% {
    transform: translateY(0);
    opacity: 0.5;
  }
  30% {
    transform: translateY(-4px);
    opacity: 1;
  }
}

.composer {
  flex: none;
  padding: 8px 10px 10px;
  border-top: 1px solid var(--border);
}

.replying {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px 8px;
  font-size: 13px;
}

.replying div {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.replying span {
  color: var(--text-3);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.replying button {
  color: var(--text-3);
}

.pending-photos {
  display: flex;
  gap: 6px;
  padding: 0 4px 8px;
  overflow-x: auto;
}

.pp {
  position: relative;
  flex: none;
  width: 64px;
  height: 64px;
  border-radius: 10px;
  overflow: hidden;
}

.pp img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pp i {
  position: absolute;
  inset: auto 0 0;
  height: 3px;
  background: var(--accent);
  transform-origin: left;
}

.pp button {
  position: absolute;
  top: 3px;
  right: 3px;
  display: grid;
  place-items: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: rgb(0 0 0 / 60%);
  color: #fff;
}

.bar {
  display: flex;
  align-items: flex-end;
  gap: 4px;
}

.field {
  flex: 1;
  display: flex;
  align-items: flex-end;
  border-radius: 20px;
  background: var(--surface-2);
  padding-left: 14px;
}

.field textarea {
  flex: 1;
  border: 0;
  resize: none;
  background: transparent;
  min-height: 38px;
  max-height: 140px;
  padding: 9px 0;
  field-sizing: content;
}

.field textarea:focus {
  outline: none;
}

/* The textarea has no box of its own, so the rounded field shows focus. */
.field:focus-within {
  box-shadow: 0 0 0 1.5px var(--accent);
}

.emoji-wrap {
  position: relative;
}

.emoji-wrap .icon {
  font-size: 20px;
}

.emoji-pop {
  position: absolute;
  right: 0;
  bottom: calc(100% + 6px);
  z-index: 10;
}

.send.like {
  font-size: 22px;
}

@media (max-width: 767px) {
  .back {
    display: grid;
  }

  .stack {
    max-width: 80%;
  }

  .actions {
    opacity: 1;
  }
}
</style>
