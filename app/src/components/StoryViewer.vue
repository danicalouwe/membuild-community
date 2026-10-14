<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import { stories, markSeen, removeStory } from '../stores/stories.js'
import { timeAgo } from '../lib/time.js'
import { BACKGROUNDS } from '../lib/postStyle.js'
import { REACTIONS } from '../lib/reactions.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import ReactionBadge from './ReactionBadge.vue'
import { t } from '../lib/i18n.js'
import { hasLayers } from '../lib/layers.js'

/**
 * Full-screen story viewer. Tap right = next, tap left = previous, hold = pause.
 * Runs through each person's stories, then moves on to the next person.
 */
const props = defineProps({ startGroup: { type: Number, default: 0 } })
const emit = defineEmits(['close'])

const gi = ref(props.startGroup)
const si = ref(0)
const paused = ref(false)
const ready = ref(false)
const muted = ref(false)
const showViewers = ref(false)
const viewers = ref(null)
const burst = ref(null)
const menu = ref(false)
const bars = [] // progress bar elements, written directly each frame (not reactive)
const replyText = ref('')
const replying = ref(false) // the reply box has focus: pause until it's sent or left
const sent = ref(false)
const video = ref(null)

const group = computed(() => stories.groups[gi.value])
const story = computed(() => group.value?.stories[si.value])
const isVideo = computed(() => story.value?.kind === 'video')
const duration = computed(() => (story.value?.duration || 5) * 1000)

let raf = 0
let lastTs = 0
let elapsed = 0
let holdTimer = null
let holding = false

/* ---------- Navigation ---------- */

function firstUnseen(g) {
  const i = g.stories.findIndex((s) => !s.seen)
  return i === -1 ? 0 : i
}

function show(g, s) {
  gi.value = g
  si.value = s
  elapsed = 0
  ready.value = story.value?.kind === 'text'
  paintProgress(0)
  menu.value = false
  if (story.value) markSeen(group.value, story.value)
  preloadNext()
}

function next() {
  if (!group.value) return emit('close')
  if (si.value < group.value.stories.length - 1) show(gi.value, si.value + 1)
  else if (gi.value < stories.groups.length - 1) show(gi.value + 1, firstUnseen(stories.groups[gi.value + 1]))
  else emit('close')
}

function prev() {
  if (si.value > 0) show(gi.value, si.value - 1)
  else if (gi.value > 0) show(gi.value - 1, stories.groups[gi.value - 1].stories.length - 1)
  else show(gi.value, 0) // restart the first story
}

function nextGroup(delta) {
  const target = gi.value + delta
  if (target < 0) return
  if (target >= stories.groups.length) return emit('close')
  show(target, delta > 0 ? firstUnseen(stories.groups[target]) : 0)
}

function preloadNext() {
  const g = group.value
  const upcoming = g?.stories[si.value + 1] || stories.groups[gi.value + 1]?.stories[0]
  if (upcoming?.kind === 'photo' && upcoming.url) new Image().src = upcoming.url
  if (upcoming?.kind === 'video' && upcoming.poster) new Image().src = upcoming.poster
}

/* ---------- Timing ---------- */

// The current bar is written directly each frame, so progress is smooth
// without re-rendering Vue 60 times a second.
function paintProgress(p) {
  const el = bars[si.value]
  if (el) el.style.transform = `scaleX(${Math.min(1, p)})`
}

function tick(ts) {
  const dt = lastTs ? ts - lastTs : 0
  lastTs = ts
  const running = !paused.value && ready.value && !showViewers.value && !replying.value && !document.hidden
  if (story.value && running) {
    if (isVideo.value && video.value?.duration) {
      paintProgress(video.value.currentTime / Math.min(video.value.duration, story.value.duration || video.value.duration))
    } else if (!isVideo.value) {
      elapsed += dt
      paintProgress(elapsed / duration.value)
      if (elapsed >= duration.value) next()
    }
  }
  raf = requestAnimationFrame(tick)
}

watch([paused, showViewers, ready, replying], () => {
  const v = video.value
  if (!v || !isVideo.value) return
  if (paused.value || showViewers.value || replying.value) v.pause()
  else if (ready.value) v.play().catch(() => {})
})

function onVideoCanPlay() {
  const v = video.value
  v.muted = muted.value
  v.play()
    .then(() => (ready.value = true))
    .catch(() => {
      // Autoplay with sound was refused: fall back to muted, which is always allowed.
      muted.value = true
      v.muted = true
      v.play().then(() => (ready.value = true)).catch(() => {})
    })
}

function onVideoTime() {
  const v = video.value
  if (v && story.value && v.currentTime >= Math.min(v.duration || Infinity, story.value.duration || Infinity) - 0.05) next()
}

/* ---------- Gestures ---------- */

function onPointerDown() {
  holding = false
  holdTimer = setTimeout(() => {
    holding = true
    paused.value = true
  }, 200)
}

function onPointerUp(e) {
  clearTimeout(holdTimer)
  if (holding) {
    holding = false
    paused.value = false
    return
  }
  const rect = e.currentTarget.getBoundingClientRect()
  if (e.clientX - rect.left < rect.width * 0.3) prev()
  else next()
}

function onKey(e) {
  if (hasLayers()) return // a dialog or menu on top handles its own keys
  if (replying.value && e.key !== 'Escape') return // typing a reply: arrows and space belong to the text box
  if (e.key === 'Escape') showViewers.value ? (showViewers.value = false) : emit('close')
  else if (e.key === 'ArrowRight') next()
  else if (e.key === 'ArrowLeft') prev()
  else if (e.key === ' ') {
    e.preventDefault()
    paused.value = !paused.value
  }
}

/* ---------- Actions ---------- */

async function react(type) {
  const s = story.value
  s.my_reaction = type
  burst.value = { type, key: Date.now() }
  setTimeout(() => (burst.value = null), 900)
  navigator.vibrate?.(8)
  api.reactStory(s.id, type).catch(() => {})
}

/** A private reply goes to the story's author in Messages, with a thumbnail of the story. */
async function sendReply() {
  const text = replyText.value.trim()
  if (!text) return
  replyText.value = ''
  replying.value = false
  sent.value = true
  setTimeout(() => (sent.value = false), 1800)
  api.storyReply(story.value.id, text).catch(() => alert(t.__('Your reply could not be sent.', 'membuild-community')))
}

async function openViewers() {
  showViewers.value = true
  viewers.value = null
  viewers.value = await api.storyViewers(story.value.id).catch(() => ({ count: 0, items: [] }))
}

function remove() {
  menu.value = false
  if (!confirm(t.__('Delete this story?', 'membuild-community'))) return
  const g = group.value
  const s = story.value
  const wasLast = g.stories.length === 1
  removeStory(g, s)
  if (wasLast) {
    if (!stories.groups.length || gi.value >= stories.groups.length) return emit('close')
    show(gi.value, firstUnseen(stories.groups[gi.value]))
  } else show(gi.value, Math.min(si.value, g.stories.length - 1))
}

onMounted(() => {
  show(gi.value, group.value ? firstUnseen(group.value) : 0)
  raf = requestAnimationFrame(tick)
  window.addEventListener('keydown', onKey)
  document.documentElement.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  clearTimeout(holdTimer)
  window.removeEventListener('keydown', onKey)
  document.documentElement.style.overflow = ''
})
const storyLabel = (name) => t.sprintf(/* translators: %s: the name of the person who posted the story */ t.__('%s\'s story', 'membuild-community'), name)
const viewerCount = (n) => t.sprintf(/* translators: %d: number of people who viewed the story */ t._n('%d viewer', '%d viewers', n, 'membuild-community'), n)
</script>

<template>
  <Teleport to="body">
    <div class="viewer" role="dialog" aria-modal="true" :aria-label="storyLabel(group?.author.name)">
      <button class="close-x" :aria-label="t.__('Close stories', 'membuild-community')" @click="emit('close')"><Icon name="close" :size="26" /></button>
      <button v-if="gi > 0" class="side left" :aria-label="t.__('Previous person', 'membuild-community')" @click="nextGroup(-1)"><Icon name="chevronLeft" :size="28" /></button>
      <button v-if="gi < stories.groups.length - 1" class="side right" :aria-label="t.__('Next person', 'membuild-community')" @click="nextGroup(1)"><Icon name="chevronRight" :size="28" /></button>

      <div v-if="story" class="frame">
        <!-- Media -->
        <div class="media" :style="story.kind === 'text' ? { background: BACKGROUNDS[story.background] || BACKGROUNDS.grape } : null">
          <template v-if="story.kind === 'photo'">
            <img class="blur" :src="story.url" alt="" aria-hidden="true" />
            <img :key="story.id" class="photo" :src="story.url" alt="" @load="ready = true" @error="ready = true" />
          </template>
          <template v-else-if="story.kind === 'video'">
            <img v-if="story.poster" class="blur" :src="story.poster" alt="" aria-hidden="true" />
            <video
              :key="story.id"
              ref="video"
              class="photo"
              :src="story.url"
              :poster="story.poster || undefined"
              playsinline
              preload="auto"
              @canplay="onVideoCanPlay"
              @timeupdate="onVideoTime"
              @ended="next"
            />
          </template>
          <p v-if="story.kind === 'text'" class="text-story">{{ story.text }}</p>
          <p v-else-if="story.text" class="caption">{{ story.text }}</p>
        </div>

        <!-- Tap zones: left 30% previous, the rest next; hold anywhere to pause -->
        <div class="tap" @pointerdown="onPointerDown" @pointerup="onPointerUp" @pointercancel="paused = false" @contextmenu.prevent />

        <!-- Top: progress, author, controls -->
        <div class="top">
          <div class="bars">
            <span v-for="(s, i) in group.stories" :key="s.id" class="bar">
              <i :ref="(el) => (bars[i] = el)" :style="{ transform: i < si ? 'scaleX(1)' : 'scaleX(0)' }" />
            </span>
          </div>
          <div class="head">
            <Avatar :user="group.author" :size="38" />
            <strong>{{ group.is_me ? t.__('Your story', 'membuild-community') : group.author.name }}</strong>
            <span class="time">{{ timeAgo(story.created_at) }}</span>
            <div class="controls">
              <button :aria-label="paused ? t.__('Play', 'membuild-community') : t.__('Pause', 'membuild-community')" @click="paused = !paused">
                <Icon :name="paused ? 'play' : 'pause'" :size="22" />
              </button>
              <button v-if="isVideo" :aria-label="muted ? t.__('Turn sound on', 'membuild-community') : t.__('Mute', 'membuild-community')" @click="muted = !muted; video && (video.muted = muted)">
                <Icon :name="muted ? 'mute' : 'volume'" :size="22" />
              </button>
              <div v-if="group.is_me" class="menu-wrap">
                <button :aria-label="t.__('Story options', 'membuild-community')" @click="menu = !menu; paused = menu"><Icon name="more" :size="22" /></button>
                <div v-if="menu" class="menu"><button @click="remove"><Icon name="trash" :size="18" /> {{ t.__('Delete story', 'membuild-community') }}</button></div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="!ready" class="spinner" :aria-label="t.__('Loading', 'membuild-community')" />
        <span v-if="burst" :key="burst.key" class="burst"><ReactionBadge :type="burst.type" :size="88" /></span>

        <!-- Bottom: viewers for your own story, quick reactions for others -->
        <div class="bottom">
          <button v-if="group.is_me" class="viewers-btn" @click="openViewers">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
            {{ viewerCount(story.views_count || 0) }}
          </button>
          <div v-else class="others">
            <form class="reply" @submit.prevent="sendReply">
              <input
                v-model="replyText"
                maxlength="1000"
                :placeholder="t.sprintf(t.__('Reply to %s…', 'membuild-community'), group.author.name.split(' ')[0])"
                :aria-label="t.__('Reply privately', 'membuild-community')"
                @focus="replying = true"
                @blur="replying = !!replyText.trim()"
              />
              <button v-if="replyText.trim()" type="submit" :aria-label="t.__('Send reply', 'membuild-community')"><Icon name="send" :size="20" /></button>
            </form>
            <span v-if="sent" class="sent">{{ t.__('Sent ✓', 'membuild-community') }}</span>
            <div class="quick" role="group" :aria-label="t.__('React to story', 'membuild-community')">
              <button v-for="r in REACTIONS" :key="r.type" :class="{ on: story.my_reaction === r.type }" :aria-label="r.label" @click="react(r.type)">
                <ReactionBadge :type="r.type" :size="30" />
              </button>
            </div>
          </div>
        </div>

        <!-- Viewers sheet -->
        <Transition name="sheet">
          <div v-if="showViewers" class="sheet-backdrop" @click.self="showViewers = false">
            <div class="sheet">
              <header>
                <strong>{{ t.__('Story details', 'membuild-community') }}</strong>
                <button :aria-label="t.__('Close', 'membuild-community')" @click="showViewers = false"><Icon name="close" :size="20" /></button>
              </header>
              <p class="sheet-count">{{ viewers ? viewerCount(viewers.count) : t.__('Loading…', 'membuild-community') }}</p>
              <div v-for="v in viewers?.items || []" :key="v.user.id" class="viewer-row">
                <span class="v-avatar">
                  <Avatar :user="v.user" :size="40" />
                  <ReactionBadge v-if="v.reaction" class="v-badge" :type="v.reaction" :size="18" />
                </span>
                <span class="v-name">{{ v.user.name }}</span>
                <span class="v-time">{{ timeAgo(v.viewed_at) }}</span>
              </div>
              <p v-if="viewers && !viewers.items.length" class="sheet-empty">{{ t.__('No one has seen this yet.', 'membuild-community') }}</p>
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.viewer {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: grid;
  place-items: center;
  background: #0b0b0d;
  color: #fff;
  user-select: none;
  -webkit-user-select: none;
}

.frame {
  position: relative;
  height: min(100dvh - 32px, 920px);
  aspect-ratio: 9 / 16;
  max-width: 100vw;
  border-radius: 12px;
  overflow: hidden;
  background: #111;
}

.media {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
}

.blur {
  position: absolute;
  inset: -40px;
  width: calc(100% + 80px);
  height: calc(100% + 80px);
  object-fit: cover;
  filter: blur(30px) brightness(0.6);
}

.photo {
  position: relative;
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.text-story {
  margin: 0;
  padding: 0 28px;
  text-align: center;
  font-size: 30px;
  font-weight: 750;
  line-height: 1.3;
  text-shadow: 0 1px 3px rgb(0 0 0 / 20%);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.caption {
  position: absolute;
  left: 16px;
  right: 16px;
  bottom: 90px;
  margin: 0;
  padding: 8px 12px;
  text-align: center;
  font-size: 17px;
  font-weight: 650;
  background: rgb(0 0 0 / 45%);
  border-radius: 10px;
}

.tap {
  position: absolute;
  inset: 0;
  cursor: pointer;
}

.top {
  position: absolute;
  inset: 0 0 auto;
  padding: 10px 10px 30px;
  background: linear-gradient(rgb(0 0 0 / 55%), transparent);
  pointer-events: none;
}

.bars {
  display: flex;
  gap: 4px;
}

.bar {
  flex: 1;
  height: 3px;
  border-radius: 2px;
  background: rgb(255 255 255 / 35%);
  overflow: hidden;
}

.bar i {
  display: block;
  height: 100%;
  background: #fff;
  transform-origin: left;
}

.head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  pointer-events: auto;
}

.head strong {
  font-size: 15px;
}

.time {
  color: rgb(255 255 255 / 75%);
  font-size: 14px;
}

.controls {
  display: flex;
  gap: 2px;
  margin-left: auto;
}

.controls button {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  color: #fff;
}

.controls button:hover {
  background: rgb(255 255 255 / 15%);
}

.controls :deep(path) {
  filter: drop-shadow(0 1px 2px rgb(0 0 0 / 40%));
}

.menu-wrap {
  position: relative;
}

.menu {
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 3;
  min-width: 170px;
  padding: 6px;
  border-radius: 10px;
  background: #242528;
  box-shadow: 0 8px 24px rgb(0 0 0 / 40%);
}

.menu button {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  height: auto;
  padding: 8px 10px;
  border-radius: 8px;
  color: #ff7676;
  font-weight: 600;
}

.spinner {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 40px;
  height: 40px;
  margin: -20px 0 0 -20px;
  border: 3px solid rgb(255 255 255 / 25%);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  pointer-events: none;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.burst {
  position: absolute;
  top: 50%;
  left: 50%;
  pointer-events: none;
  animation: burst 0.9s cubic-bezier(0.2, 1.4, 0.4, 1) forwards;
}

@keyframes burst {
  0% {
    transform: translate(-50%, -30%) scale(0.3);
    opacity: 0;
  }
  25% {
    transform: translate(-50%, -50%) scale(1.1);
    opacity: 1;
  }
  100% {
    transform: translate(-50%, -150%) scale(0.9);
    opacity: 0;
  }
}

.bottom {
  position: absolute;
  inset: auto 0 0;
  display: flex;
  justify-content: center;
  padding: 40px 12px 16px;
  background: linear-gradient(transparent, rgb(0 0 0 / 50%));
}

.others {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  width: 100%;
}

.reply {
  display: flex;
  align-items: center;
  width: 100%;
  border: 1.5px solid rgb(255 255 255 / 70%);
  border-radius: 999px;
  background: rgb(0 0 0 / 25%);
  backdrop-filter: blur(6px);
}

/* The field inside has no outline of its own, so the pill shows focus. */
.reply:focus-within {
  border-color: #fff;
}

.reply input {
  flex: 1;
  min-width: 0;
  height: 42px;
  padding: 0 16px;
  border: 0;
  background: transparent;
  color: #fff;
}

.reply input::placeholder {
  color: rgb(255 255 255 / 80%);
}

.reply input:focus {
  outline: none;
}

.reply button {
  display: grid;
  place-items: center;
  width: 40px;
  color: #fff;
}

.sent {
  font-size: 13px;
  font-weight: 700;
}

.quick {
  display: flex;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  background: rgb(0 0 0 / 35%);
  backdrop-filter: blur(8px);
}

.quick button {
  display: grid;
  place-items: center;
  border-radius: 50%;
  transition: transform 0.15s ease;
}

.quick button:hover,
.quick button.on {
  transform: scale(1.18);
}

.viewers-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  border-radius: 999px;
  color: #fff;
  font-weight: 650;
  background: rgb(0 0 0 / 40%);
  backdrop-filter: blur(8px);
}

.close-x,
.side {
  position: absolute;
  z-index: 2;
  display: grid;
  place-items: center;
  border-radius: 50%;
  color: #fff;
  background: rgb(255 255 255 / 12%);
}

.close-x {
  top: 16px;
  right: 16px;
  width: 44px;
  height: 44px;
}

.side {
  top: 50%;
  width: 48px;
  height: 48px;
  margin-top: -24px;
}

.side.left {
  left: calc(50% - min(100dvh - 32px, 920px) * 9 / 32 - 72px);
}

.side.right {
  right: calc(50% - min(100dvh - 32px, 920px) * 9 / 32 - 72px);
}

.close-x:hover,
.side:hover {
  background: rgb(255 255 255 / 22%);
}

.sheet-backdrop {
  position: absolute;
  inset: 0;
  z-index: 4;
  display: flex;
  align-items: flex-end;
  background: rgb(0 0 0 / 40%);
}

.sheet {
  width: 100%;
  max-height: 70%;
  overflow-y: auto;
  padding: 0 16px 16px;
  border-radius: 16px 16px 0 0;
  background: #1c1c1f;
}

.sheet header {
  position: sticky;
  top: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 0;
  background: #1c1c1f;
}

.sheet header button {
  color: #fff;
}

.sheet-count,
.sheet-empty {
  margin: 0 0 10px;
  color: #a9aeb8;
  font-size: 14px;
  font-weight: 600;
}

.viewer-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 7px 0;
}

.v-avatar {
  position: relative;
  display: inline-flex;
}

.v-badge {
  position: absolute;
  right: -3px;
  bottom: -3px;
  --ring: #1c1c1f;
}

.v-name {
  font-weight: 600;
}

.v-time {
  margin-left: auto;
  color: #8e8e96;
  font-size: 13px;
}

.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.2s ease;
}

.sheet-enter-active .sheet,
.sheet-leave-active .sheet {
  transition: transform 0.25s cubic-bezier(0.2, 0.9, 0.3, 1);
}

.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}

.sheet-enter-from .sheet,
.sheet-leave-to .sheet {
  transform: translateY(100%);
}

@media (max-width: 767px) {
  .frame {
    width: 100vw;
    height: 100dvh;
    aspect-ratio: auto;
    border-radius: 0;
  }

  .side {
    display: none;
  }

  .close-x {
    top: calc(64px + env(safe-area-inset-top));
    right: 8px;
    width: 38px;
    height: 38px;
    background: transparent;
  }

  .top {
    padding-top: calc(10px + env(safe-area-inset-top));
  }

  .bottom {
    padding-bottom: calc(16px + env(safe-area-inset-bottom));
  }
}
</style>
