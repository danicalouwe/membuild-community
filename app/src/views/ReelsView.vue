<script setup>
import { isProcessing } from '../lib/stream.js'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { lists, loadMore, insertPost, setReaction } from '../stores/feed.js'
import { startAttachment } from '../lib/attachments.js'
import { pendingReelFile } from '../lib/pending.js'
import { compactNumber } from '../lib/time.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import RichText from '../components/RichText.vue'
import CommentThread from '../components/CommentThread.vue'
import YouTubeReel from '../components/YouTubeReel.vue'
import { t } from '../lib/i18n.js'
import { useDialog } from '../lib/useDialog.js'

const route = useRoute()
const router = useRouter()
const list = lists.reels

const scroller = ref(null)
const active = ref(0)
const muted = ref(sessionStorage.getItem('mbcom-muted') !== '0')
const paused = ref(false)
const buffering = ref(false)
const bar = ref(null)
const commentsFor = ref(null)
const hearts = ref([])

const videos = new Map() // index -> <video>, at most 3 exist at once
let raf
let tapTimer = null
let heartId = 0

const current = computed(() => list.items[active.value])
const isEmbed = (post) => post?.media[0]?.kind === 'embed'
const activeIsEmbed = computed(() => isEmbed(current.value))

// Like Facebook on desktop, each reel's frame takes its video's shape: a 16:9 video fills
// the width, a vertical one stays a tall column. Kept between 9:16 and a little wider than 21:9.
function ratioOf(post) {
  const m = post?.media?.[0]
  const r = m?.width && m?.height ? m.width / m.height : 9 / 16
  return Math.min(2.4, Math.max(9 / 16, r))
}
const activeRatio = computed(() => (activeIsEmbed.value ? 9 / 16 : ratioOf(current.value)))

// Only the previous, current and next reels get a <video>. Everything else
// is just a poster image, which keeps memory low and avoids iOS limits on
// how many videos a page may hold.
const inWindow = (i) => Math.abs(i - active.value) <= 1
// Posters further away than this aren't rendered at all, so a long list stays light.
const nearby = (i) => Math.abs(i - active.value) <= 5

function setVideo(i, el) {
  if (el) videos.set(i, el)
  else videos.delete(i)
}

// Every reel is exactly one screen tall, so the current reel follows directly
// from the scroll position. Reading it on scroll (once per frame) is simpler
// and more reliable than per-slide observers.
let scrollRaf = 0
function onScroll() {
  if (scrollRaf) return
  scrollRaf = requestAnimationFrame(() => {
    scrollRaf = 0
    const el = scroller.value
    if (!el || !el.clientHeight) return
    const i = Math.round(el.scrollTop / el.clientHeight)
    if (i !== active.value && i >= 0 && i < list.items.length) active.value = i
  })
}

// Pause when the tab is hidden; resume when the member comes back.
function onVisibility() {
  if (document.hidden) videos.forEach((v) => v.pause())
  else playCurrent()
}

watch(active, async (i, prev) => {
  paused.value = false
  buffering.value = false
  const old = videos.get(prev)
  if (old) {
    old.pause()
    old.currentTime = 0
  }
  await nextTick()
  playCurrent()
  if (i >= list.items.length - 3) loadMore(list)
  router.replace({ query: current.value && typeof current.value.id === 'number' ? { id: current.value.id } : {} })
})

watch(muted, (m) => {
  sessionStorage.setItem('mbcom-muted', m ? '1' : '0')
  videos.forEach((v) => (v.muted = m))
})

function playCurrent() {
  videos.forEach((v, i) => i !== active.value && v.pause())
  const v = videos.get(active.value)
  if (!v || paused.value) return
  v.muted = muted.value
  v.play().catch(() => {
    // Autoplay with sound was blocked: fall back to muted, which is always allowed.
    muted.value = true
    v.muted = true
    v.play().catch(() => {})
  })
}

// Progress bar runs on requestAnimationFrame and writes the style directly,
// so it moves smoothly without re-rendering Vue every frame.
function tick() {
  const v = videos.get(active.value)
  if (bar.value && activeIsEmbed.value) bar.value.style.transform = 'scaleX(0)'
  else if (v && bar.value && v.duration) bar.value.style.transform = `scaleX(${v.currentTime / v.duration})`
  raf = requestAnimationFrame(tick)
}

function onTap(e) {
  if (tapTimer) {
    clearTimeout(tapTimer)
    tapTimer = null
    doubleTap(e)
    return
  }
  tapTimer = setTimeout(() => {
    tapTimer = null
    togglePause()
  }, 240)
}

function togglePause() {
  const v = videos.get(active.value)
  if (!v) return
  paused.value = !paused.value
  if (paused.value) v.pause()
  else playCurrent()
}

function doubleTap(e) {
  const rect = e.currentTarget.getBoundingClientRect()
  const heart = { id: ++heartId, x: e.clientX - rect.left, y: e.clientY - rect.top }
  hearts.value.push(heart)
  setTimeout(() => (hearts.value = hearts.value.filter((h) => h !== heart)), 800)
  navigator.vibrate?.(8)
  if (current.value && !current.value.my_reaction) setReaction(current.value, 'love')
}

function toggleLike() {
  const post = current.value
  if (!post) return
  setReaction(post, post.my_reaction ? null : 'love')
}

function go(delta) {
  const el = scroller.value
  if (!el) return
  const next = Math.min(list.items.length - 1, Math.max(0, active.value + delta))
  el.scrollTo({ top: next * el.clientHeight, behavior: 'smooth' })
}

// Escape for the sheets comes from useDialog; these keys only drive the reels themselves.
function onKey(e) {
  const typing = e.target instanceof Element && e.target.closest('input, textarea, [contenteditable]')
  if ((commentsFor.value && !wide.value) || composer.value || typing) return
  if (e.key === 'ArrowDown' || e.key === 'j') (e.preventDefault(), go(1))
  else if (e.key === 'ArrowUp' || e.key === 'k') (e.preventDefault(), go(-1))
  else if (e.key === ' ') (e.preventDefault(), togglePause())
  else if (e.key === 'm') muted.value = !muted.value
}

async function share() {
  const url = location.href
  try {
    if (navigator.share) await navigator.share({ url })
    else await navigator.clipboard.writeText(url)
  } catch {
    /* dismissed */
  }
}

/* ---------- Upload a reel ---------- */

const fileInput = ref(null)
// Reels are uploaded videos: "New reel" goes straight to picking one.
const composer = ref(null) // { step: 'upload', item, caption, error, posting }

const commentsSheet = ref(null)
// Computers: comments open as a panel on the right and the reel keeps playing; moving to
// another reel shows its comments. Phones: a sheet from the bottom, and the reel waits.
const wideQuery = window.matchMedia('(min-width: 768px)')
const wide = ref(wideQuery.matches)
const onWide = (e) => (wide.value = e.matches)
wideQuery.addEventListener('change', onWide)
onBeforeUnmount(() => wideQuery.removeEventListener('change', onWide))
watch(active, () => {
  if (commentsFor.value && wide.value && current.value) commentsFor.value = current.value
})
const composerSheet = ref(null)
useDialog(commentsSheet, { open: () => !!commentsFor.value, onClose: () => (commentsFor.value = null) })
useDialog(composerSheet, { open: () => !!composer.value, onClose: () => closeComposer() })
let alive = true

function startCreate() {
  fileInput.value?.click()
}

function pickVideo(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  videos.forEach((v) => v.pause())
  composer.value = { step: 'upload', item: startAttachment(file), caption: '', error: '', posting: false }
}

function closeComposer() {
  composer.value?.item?.cancel()
  composer.value = null
  playCurrent()
}

async function postReel() {
  const c = composer.value
  if (!c || c.posting) return
  c.posting = true
  c.error = ''
  try {
    await c.item.promise
    if (c.item.status !== 'done') throw new Error(c.item.error || t.__('Upload failed', 'membuild-community'))
    const saved = await api.createPost({ type: 'reel', content: c.caption.trim(), media_ids: [c.item.media.id] })
    insertPost(saved)
    c.item.release()
    composer.value = null
    await nextTick()
    if (!alive || !scroller.value) return
    scroller.value.scrollTop = 0
    active.value = 0
    router.replace({ query: { id: saved.id } })
    playCurrent()
  } catch (err) {
    c.error = err.message
    c.posting = false
  }
}

/* ---------- Lifecycle ---------- */

onMounted(async () => {
  document.documentElement.style.overflow = 'hidden'
  window.addEventListener('keydown', onKey)
  document.addEventListener('visibilitychange', onVisibility)
  raf = requestAnimationFrame(tick)

  // A video picked from the feed bar's camera button: open the upload sheet with it.
  if (pendingReelFile.value) {
    composer.value = { step: 'upload', item: startAttachment(pendingReelFile.value), caption: '', error: '', posting: false }
    pendingReelFile.value = null
  }

  if (!list.loaded) await loadMore(list)
  if (!alive) return

  // Deep link (?id=123): make sure that reel is in the list, then jump to it.
  const wanted = Number(route.query.id)
  if (wanted) {
    let index = list.items.findIndex((p) => p.id === wanted)
    if (index === -1) {
      const post = await api.post(wanted).catch(() => null)
      if (!alive) return
      if (post?.type === 'reel') {
        list.items.unshift(post)
        index = 0
      }
    }
    if (index > 0) {
      await nextTick()
      if (!alive || !scroller.value) return
      scroller.value.scrollTop = index * scroller.value.clientHeight
      active.value = index
    }
  }

  await nextTick()
  if (alive) playCurrent()
})

onBeforeUnmount(() => {
  alive = false
  document.documentElement.style.overflow = ''
  window.removeEventListener('keydown', onKey)
  document.removeEventListener('visibilitychange', onVisibility)
  cancelAnimationFrame(raf)
  cancelAnimationFrame(scrollRaf)
  videos.forEach((v) => v.pause())
})
const commentCount = (n) => t.sprintf(/* translators: %d: number of comments */ t._n('%d comment', '%d comments', n, 'membuild-community'), n)
</script>

<template>
  <div class="stage" :class="{ 'comments-open': commentsFor && wide, 'on-youtube': activeIsEmbed }" :style="{ '--active-ar': activeRatio }">
    <div ref="scroller" class="reels" @scroll.passive="onScroll">
      <section v-for="(post, i) in list.items" :key="post.localKey || post.id" class="reel" :class="{ embed: isEmbed(post) }" :data-index="i">
        <!-- YouTube: their player on top, our caption and buttons below it (never over it). -->
        <template v-if="isEmbed(post)">
          <div class="yt-area">
            <YouTubeReel
              v-if="inWindow(i)"
              :embed="post.media[0].embed"
              :active="i === active && !composer && !(commentsFor && !wide)"
              :muted="muted"
              @unmuted="muted = false"
            />
            <img v-else-if="nearby(i)" class="poster" :src="post.media[0].poster" alt="" loading="lazy" decoding="async" />
          </div>
          <div class="yt-info">
            <div class="yt-text">
              <div class="author">
                <Avatar :user="post.author" :size="30" />
                <strong>{{ post.author?.name }}</strong>
              </div>
              <RichText v-if="post.content" :text="post.content" :clamp="90" />
              <a class="credit" :href="post.media[0].embed.source_url" target="_blank" rel="noopener">
                <svg width="16" height="12" viewBox="0 0 16 12" aria-hidden="true"><rect width="16" height="12" rx="3" fill="#ff0033" /><path d="M6.4 3.4v5.2L10.8 6z" fill="#fff" /></svg>
                YouTube · {{ post.media[0].embed.author || 'YouTube' }}
              </a>
            </div>
            <div class="rail row">
              <button class="rail-btn" :class="{ liked: post.my_reaction }" :aria-label="t.__('Like', 'membuild-community')" :aria-pressed="!!post.my_reaction" @click="toggleLike">
                <span class="ic"><Icon name="heart" :size="24" /></span><span>{{ compactNumber(post.reactions_count) }}</span>
              </button>
              <button class="rail-btn" :aria-label="t.__('Comments', 'membuild-community')" @click="commentsFor = post">
                <span class="ic"><Icon name="comment" :size="23" /></span><span>{{ compactNumber(post.comments_count) }}</span>
              </button>
              <button class="rail-btn" :aria-label="t._x('Share', 'verb', 'membuild-community')" @click="share"><span class="ic"><Icon name="share" :size="23" /></span></button>
              <!-- Phones only: the round + at the top would sit on YouTube's own buttons. -->
              <button class="rail-btn phone-new" :aria-label="t.__('New reel', 'membuild-community')" @click="startCreate"><span class="ic"><Icon name="plus" :size="23" /></span><span>{{ t.__('New reel', 'membuild-community') }}</span></button>
            </div>
          </div>
        </template>

        <template v-else>
        <div class="box" :style="{ '--ar': ratioOf(post) }">
        <div class="frame" @click="onTap">
          <img v-if="post.media[0]?.poster && nearby(i)" class="poster" :class="{ landscape: post.media[0].width > post.media[0].height }" :src="post.media[0].poster" alt="" loading="lazy" decoding="async" />
          <video
            v-if="inWindow(i) && post.media[0]"
            :ref="(el) => setVideo(i, el)"
            v-stream="post.media[0]"
            :poster="post.media[0].poster || undefined"
            :class="{ landscape: post.media[0].width > post.media[0].height }"
            :preload="i === active ? 'auto' : i === active + 1 ? 'auto' : 'metadata'"
            muted
            playsinline
            loop
            disablepictureinpicture
            @waiting="i === active && (buffering = true)"
            @playing="i === active && (buffering = false)"
          />

          <div v-if="isProcessing(post.media[0])" class="center-icon processing-note">{{ t.__('Processing video…', 'membuild-community') }}</div>
          <div v-if="i === active && paused" class="center-icon"><Icon name="play" :size="56" /></div>
          <div v-if="i === active && buffering && !paused" class="spinner" :aria-label="t.__('Loading', 'membuild-community')" />
          <span v-for="h in i === active ? hearts : []" :key="h.id" class="burst" :style="{ left: `${h.x}px`, top: `${h.y}px` }">❤️</span>
        </div>

        <div class="overlay">
          <div class="caption">
            <div class="author">
              <Avatar :user="post.author" :size="34" />
              <strong>{{ post.author?.name }}</strong>
            </div>
            <RichText v-if="post.content" :text="post.content" :clamp="120" />
          </div>

          <div class="rail">
            <button class="rail-btn" :class="{ liked: post.my_reaction }" :aria-label="t.__('Like', 'membuild-community')" :aria-pressed="!!post.my_reaction" @click="toggleLike">
              <span class="ic"><Icon name="heart" :size="28" /></span>
              <span>{{ compactNumber(post.reactions_count) }}</span>
            </button>
            <button class="rail-btn" :aria-label="t.__('Comments', 'membuild-community')" @click="commentsFor = post">
              <span class="ic"><Icon name="comment" :size="27" /></span>
              <span>{{ compactNumber(post.comments_count) }}</span>
            </button>
            <button class="rail-btn" :aria-label="t._x('Share', 'verb', 'membuild-community')" @click="share">
              <span class="ic"><Icon name="share" :size="26" /></span>
              <span>{{ t._x('Share', 'verb', 'membuild-community') }}</span>
            </button>
            <button class="rail-btn" :aria-label="muted ? t.__('Turn sound on', 'membuild-community') : t.__('Mute', 'membuild-community')" @click="muted = !muted">
              <span class="ic"><Icon :name="muted ? 'mute' : 'volume'" :size="26" /></span>
            </button>
          </div>
        </div>
        </div>
        </template>
      </section>

      <section v-if="list.loaded && !list.items.length" class="reel empty">
        <Icon name="film" :size="48" />
        <h2>{{ t.__('No reels yet', 'membuild-community') }}</h2>
        <p>{{ t.__('Share a short video, up to 60 seconds.', 'membuild-community') }}</p>
        <button class="btn btn-primary" @click="startCreate"><Icon name="plus" :size="18" /> {{ t.__('Create a reel', 'membuild-community') }}</button>
      </section>
      <section v-else-if="!list.loaded" class="reel"><div class="spinner" /></section>
    </div>

    <div class="progress" aria-hidden="true"><i ref="bar" /></div>

    <!-- Computers: previous and next reel, big round buttons at the far right (like Facebook). -->
    <div v-if="list.items.length > 1" class="nav">
      <button :aria-label="t.__('Previous reel', 'membuild-community')" :title="t.__('Previous reel', 'membuild-community')" :disabled="active === 0" @click="go(-1)"><Icon name="chevronUp" :size="30" /></button>
      <button :aria-label="t.__('Next reel', 'membuild-community')" :title="t.__('Next reel', 'membuild-community')" :disabled="active >= list.items.length - 1" @click="go(1)"><Icon name="chevronDown" :size="30" /></button>
    </div>

    <!-- One place to add a reel, the same for every reel: "+ New reel" top right (a round + on phones). -->
    <button class="create btn btn-lg btn-primary" :aria-label="t.__('New reel', 'membuild-community')" @click="startCreate"><Icon name="plus" :size="20" /> <span class="create-label">{{ t.__('New reel', 'membuild-community') }}</span></button>
    <input ref="fileInput" class="sr-only" type="file" accept="video/*" @change="pickVideo" />

    <!-- Comments sheet -->
    <Transition name="sheet">
      <div v-if="commentsFor" class="sheet-backdrop" :class="{ side: wide }" @click.self="commentsFor = null">
        <div ref="commentsSheet" class="sheet" role="dialog" aria-modal="true" :aria-label="t.__('Comments', 'membuild-community')">
          <header>
            <strong>{{ commentCount(commentsFor.comments_count) }}</strong>
            <button :aria-label="t.__('Close', 'membuild-community')" @click="commentsFor = null"><Icon name="close" /></button>
          </header>
          <div class="sheet-body"><CommentThread :key="commentsFor.id" :post="commentsFor" dark autofocus /></div>
        </div>
      </div>
    </Transition>

    <!-- New reel -->
    <Transition name="sheet">
      <div v-if="composer" class="sheet-backdrop" @click.self="closeComposer">
        <div ref="composerSheet" class="sheet new-reel" role="dialog" aria-modal="true" :aria-label="t.__('New reel', 'membuild-community')">
          <header>
            <strong>{{ t.__('New reel', 'membuild-community') }}</strong>
            <button :aria-label="t.__('Close', 'membuild-community')" @click="closeComposer"><Icon name="close" /></button>
          </header>
          <div class="new-body">
            <video :src="composer.item.previewUrl" muted playsinline autoplay loop class="preview" />
            <div class="new-side">
              <textarea v-model="composer.caption" rows="4" maxlength="2200" :placeholder="t.__('Write a caption…', 'membuild-community')" :aria-label="t.__('Caption', 'membuild-community')" />
              <div class="status" :class="composer.item.status">
                <template v-if="composer.item.status === 'working'">
                  {{ composer.item.stage }} {{ Math.round(composer.item.progress * 100) }}%
                  <i :style="{ transform: `scaleX(${composer.item.progress})` }" />
                </template>
                <template v-else-if="composer.item.status === 'done'">{{ t.__('Ready to post ✓', 'membuild-community') }}</template>
                <template v-else>{{ composer.item.error }}</template>
              </div>
              <p v-if="composer.error" class="err">{{ composer.error }}</p>
              <button class="btn btn-primary" :disabled="composer.posting || composer.item.status === 'error'" @click="postReel">
                {{ composer.posting ? (composer.item.status === 'working' ? t.__('Finishing upload…', 'membuild-community') : t.__('Posting…', 'membuild-community')) : t.__('Share reel', 'membuild-community') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.processing-note {
  padding: 10px 16px;
  border-radius: 999px;
  background: rgb(0 0 0 / 60%);
  font-size: 15px;
  font-weight: 600;
}

.stage {
  --reel-h: calc(100dvh - var(--header-h));
  container-type: size; /* lets the progress bar and + button measure the stage (cqw) */
  position: relative;
  height: var(--reel-h);
  background: #000;
  color: #fff;
}

.reels {
  height: 100%;
  margin: 0 auto;
  overflow-y: auto;
  scroll-snap-type: y mandatory;
  overscroll-behavior: contain;
  scrollbar-width: none;
}

.reels::-webkit-scrollbar {
  display: none;
}

.reel {
  position: relative;
  height: 100%;
  scroll-snap-align: start;
  scroll-snap-stop: always;
  overflow: hidden;
  background: #000;
}

.reel:not(.embed):not(.empty) {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 12px 0;
  overflow: visible;
}

.box {
  position: relative;
  width: min(calc(100% - 168px), calc((var(--reel-h) - 24px) * var(--ar)));
  aspect-ratio: var(--ar);
}

.reel.embed {
  max-width: calc(var(--reel-h) * 9 / 16);
  margin: 0 auto;
}

.frame {
  position: absolute;
  inset: 0;
  border-radius: 12px;
  overflow: hidden;
  cursor: pointer;
  user-select: none;
  -webkit-user-select: none;
}

.poster,
.frame video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.frame video.landscape,
.frame .poster.landscape {
  /* A wide video keeps its shape with black bars above and below, like Facebook. */
  object-fit: contain;
  background: #000;
}

.center-icon {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  color: rgb(255 255 255 / 85%);
  pointer-events: none;
  filter: drop-shadow(0 2px 10px rgb(0 0 0 / 40%));
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
  font-size: 90px;
  transform: translate(-50%, -50%);
  pointer-events: none;
  animation: burst 0.8s cubic-bezier(0.2, 1.4, 0.4, 1) forwards;
}

@keyframes burst {
  0% {
    transform: translate(-50%, -50%) scale(0.3) rotate(-12deg);
    opacity: 0;
  }
  20% {
    transform: translate(-50%, -50%) scale(1.15) rotate(0);
    opacity: 1;
  }
  70% {
    transform: translate(-50%, -60%) scale(1);
    opacity: 1;
  }
  100% {
    transform: translate(-50%, -140%) scale(0.8);
    opacity: 0;
  }
}

.overlay {
  position: absolute;
  inset: auto 0 0;
  display: flex;
  align-items: flex-end;
  gap: 12px;
  padding: 80px 12px 22px 16px;
  background: linear-gradient(transparent, rgb(0 0 0 / 55%));
  pointer-events: none;
}

.caption {
  flex: 1;
  min-width: 0;
  pointer-events: auto;
  text-shadow: 0 1px 2px rgb(0 0 0 / 50%);
}

.caption :deep(a) {
  color: #fff;
  text-decoration: underline;
}

.caption :deep(.more) {
  color: rgb(255 255 255 / 80%);
}

.author {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}

.rail {
  display: flex;
  flex-direction: column;
  gap: 16px;
  pointer-events: auto;
}

.box .overlay {
  border-radius: 0 0 12px 12px;
}

/* On desktop the buttons sit just outside the frame, on the right (Facebook style). */
.box .rail {
  position: absolute;
  left: calc(100% + 16px);
  bottom: 18px;
}

.rail-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  color: #fff;
  font-size: 12px;
  font-weight: 650;
  filter: drop-shadow(0 1px 3px rgb(0 0 0 / 45%));
}

.rail-btn .ic {
  display: grid;
  place-items: center;
  transition: transform 0.15s ease;
}

.rail-btn:active .ic {
  transform: scale(0.85);
}

.rail-btn.liked {
  color: var(--love);
}

.rail-btn.liked :deep(path) {
  fill: currentColor;
}

.progress {
  --pw: min(calc(100cqw - 168px), calc((var(--reel-h) - 24px) * var(--active-ar)));
  position: absolute;
  left: 50%;
  /* Sits on the bottom edge of the current frame, which is centred in the stage. */
  bottom: calc((var(--reel-h) - var(--pw) / var(--active-ar)) / 2);
  width: var(--pw);
  height: 2px;
  transform: translateX(-50%);
  background: rgb(255 255 255 / 20%);
  pointer-events: none;
}

.progress i {
  display: block;
  height: 100%;
  background: #fff;
  transform-origin: left;
  transform: scaleX(0);
}

.create {
  position: absolute;
  top: 20px;
  right: 28px;
  z-index: 3;
  gap: 8px;
}

.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  text-align: center;
  padding: 24px;
  color: rgb(255 255 255 / 85%);
}

.empty h2 {
  margin: 8px 0 0;
}

.empty p {
  margin: 0 0 12px;
  color: rgb(255 255 255 / 60%);
}

.sheet-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgb(0 0 0 / 45%);
}

.sheet {
  width: min(100%, 560px);
  max-height: 75dvh;
  overflow-y: auto;
  background: #1c1c1f;
  color: #f2f2f4;
  border-radius: 18px 18px 0 0;
  padding-bottom: env(safe-area-inset-bottom);
}

.sheet header {
  position: sticky;
  top: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  background: #1c1c1f;
  border-bottom: 1px solid #2c2c30;
}

.sheet header button {
  color: #f2f2f4;
}

.sheet :deep(.empty),
.sheet :deep(.meta),
.sheet :deep(.earlier) {
  color: #9a9aa2;
}

/* YouTube reels: player on top, info bar below (nothing drawn over the player). */
.reel.embed {
  display: flex;
  flex-direction: column;
}

.yt-area {
  position: relative;
  flex: 1;
  min-height: 0;
  background: #000;
}

.yt-area .poster {
  object-fit: contain;
}

.yt-info {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  padding: 10px 12px 14px 14px;
  background: #0d0d0f;
  border-top: 1px solid #1d1d20;
}

.yt-text {
  flex: 1;
  min-width: 0;
  font-size: 14px;
}

.yt-text .author {
  margin-bottom: 4px;
}

.credit {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  color: #c9c9cf;
  font-size: 12.5px;
  font-weight: 600;
}

.credit:hover {
  color: #fff;
  text-decoration: underline;
}

.rail.row {
  flex-direction: row;
  gap: 14px;
}












.sheet-body {
  padding: 14px 16px 16px;
}

.new-body {
  display: flex;
  gap: 14px;
  padding: 16px;
}

.preview {
  width: 140px;
  aspect-ratio: 9 / 16;
  object-fit: cover;
  border-radius: 12px;
  background: #000;
  flex: none;
}

.new-side {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.new-side textarea {
  resize: none;
  padding: 10px 12px;
  border: 1px solid #333338;
  border-radius: 12px;
  background: #26262a;
  color: #f2f2f4;
}

.status {
  font-size: 13px;
  font-weight: 600;
  color: #b9b9c0;
}

.status i {
  display: block;
  height: 3px;
  margin-top: 6px;
  border-radius: 2px;
  background: var(--accent);
  transform-origin: left;
  transition: transform 0.2s linear;
}

.status.done {
  color: #57d38c;
}

.status.error,
.err {
  color: #ff7676;
  margin: 0;
}

.new-side .btn {
  margin-top: auto;
}

.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.2s ease;
}

.sheet-enter-active .sheet,
.sheet-leave-active .sheet {
  transition: transform 0.28s cubic-bezier(0.2, 0.9, 0.3, 1);
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
  .stage {
    --reel-h: calc(100dvh - var(--tabbar-h) - env(safe-area-inset-bottom));
  }

  /* Phones: every reel is full screen; wide videos get black bars. */
  .reel:not(.embed):not(.empty) {
    display: block;
    padding: 0;
    overflow: hidden;
  }

  .box {
    position: absolute;
    inset: 0;
    width: auto;
    aspect-ratio: auto;
  }

  .frame {
    border-radius: 0;
  }

  .box .rail {
    position: static;
  }

  .reel.embed {
    max-width: none;
  }

  .create {
    right: 14px;
  }

  .progress {
    width: 100%;
    bottom: 0;
  }

  .box .overlay {
    border-radius: 0;
  }

  /* Phones: a small round + at the top instead of the word. */
  .create {
    right: 14px;
    top: calc(14px + env(safe-area-inset-top));
    width: 42px;
    height: 42px;
    padding: 0;
    border-radius: 50%;
    background: rgb(255 255 255 / 16%);
    color: #fff;
    backdrop-filter: blur(10px);
  }

  .create-label {
    display: none;
  }

  .stage.on-youtube .create {
    display: none;
  }

  .overlay {
    padding-bottom: 18px;
  }

  .new-body {
    flex-direction: column;
    align-items: center;
  }

  .new-side {
    width: 100%;
  }
}
/* ---------- Computers: buttons beside the video, comments on the right, up and down ---------- */
.nav {
  display: none;
}

@media (min-width: 768px) {
  .phone-new {
    display: none;
  }
}

@media (min-width: 768px) {
  /* YouTube reels: Like, Comments, Share and + in a column right of the player, like uploads. */
  .reel.embed {
    overflow: visible;
  }

  .reel.embed .rail.row {
    position: absolute;
    left: calc(100% + 16px);
    bottom: 18px;
    flex-direction: column;
    gap: 18px;
  }

  .reel.embed .rail.row .rail-btn .ic :deep(svg) {
    width: 27px;
    height: 27px;
  }

  .nav {
    position: absolute;
    top: 50%;
    right: 28px;
    z-index: 3;
    display: flex;
    flex-direction: column;
    gap: 18px;
    transform: translateY(-50%);
    transition: right 0.2s;
  }

  .nav button {
    display: grid;
    place-items: center;
    width: 60px;
    height: 60px;
    border: 3px solid #fff;
    border-radius: 50%;
    background: rgb(0 0 0 / 35%);
    color: #fff;
  }

  .nav button:hover:not(:disabled) {
    background: rgb(255 255 255 / 14%);
  }

  .nav button:disabled {
    opacity: 0.35;
    cursor: default;
  }

  /* The comments panel takes the right side; the reel and the arrows move over. */
  .stage {
    transition: padding 0.2s;
  }

  .stage.comments-open {
    padding-right: 440px;
  }

  .stage.comments-open .nav {
    right: 468px;
  }

  .stage.comments-open .create {
    display: none;
  }

  .sheet-backdrop.side {
    top: var(--header-h);
    left: auto;
    width: 440px;
    align-items: stretch;
    background: none;
  }

  .sheet-backdrop.side .sheet {
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    max-height: none;
    border-left: 1px solid #2c2c30;
    border-radius: 0;
  }

  .sheet-backdrop.side .sheet-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    overflow-y: auto;
  }

  /* The comment box stays at the bottom of the panel. */
  .sheet-backdrop.side .sheet-body :deep(.thread) {
    display: flex;
    flex: 1;
    flex-direction: column;
  }

  .sheet-backdrop.side .sheet-body :deep(.composer) {
    margin-top: auto;
  }

  .sheet-enter-from.side .sheet,
  .sheet-leave-to.side .sheet {
    transform: translateX(100%);
  }
}
</style>
