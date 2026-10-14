<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { currentAnchor, stopVideo, video } from '../lib/videoDock.js'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * The player for the YouTube or Vimeo video a member started in the feed.
 * While its post is at least half on screen it sits exactly over the post;
 * otherwise (scrolled away, or another page open) it shrinks into a corner
 * player with "go to post" and close, like Facebook. The <iframe> itself is
 * never moved, so the video never restarts.
 */
const route = useRoute()
const router = useRouter()

const box = ref(null) // where the post's video frame is, in page coordinates
const docked = ref(false)
const chatWidth = ref(0) // docked chat windows on the right; the corner player sits beside them
let frame = 0

const vertical = computed(() => !!video.media?.embed?.vertical)
const src = computed(() => {
  const m = video.media
  if (!m) return ''
  const join = m.url.includes('?') ? '&' : '?'
  return `${m.url}${join}autoplay=1&playsinline=1${m.embed?.provider === 'youtube' ? '&rel=0' : ''}`
})

const headerHeight = () => parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-h')) || 56

// One cheap measurement per frame, only while a video is playing.
function track() {
  frame = requestAnimationFrame(track)
  const el = currentAnchor()
  if (el) {
    const r = el.getBoundingClientRect()
    const onScreen = Math.min(r.bottom, window.innerHeight) - Math.max(r.top, headerHeight())
    if (onScreen >= r.height / 2) {
      const next = { top: r.top + window.scrollY, left: r.left + window.scrollX, width: r.width, height: r.height }
      const b = box.value
      if (!b || b.top !== next.top || b.left !== next.left || b.width !== next.width || b.height !== next.height) box.value = next
      docked.value = false
      return
    }
  }
  docked.value = true
  chatWidth.value = document.querySelector('[data-chat-dock]')?.getBoundingClientRect().width || 0
}

watch(
  () => video.media?.id,
  (id) => {
    cancelAnimationFrame(frame)
    box.value = null
    if (id) track()
  },
  { immediate: true },
)
onBeforeUnmount(() => cancelAnimationFrame(frame))

// Reels play their own videos full screen; a corner player would talk over them.
watch(
  () => route.name,
  (name) => name === 'reels' && stopVideo(),
)

const style = computed(() => {
  if (docked.value) return { right: `${chatWidth.value + 16}px` }
  const b = box.value
  return b ? { top: `${b.top}px`, left: `${b.left}px`, width: `${b.width}px`, height: `${b.height}px` } : { visibility: 'hidden' }
})

async function goToPost() {
  if (!currentAnchor()) await router.push({ name: 'feed' })
  requestAnimationFrame(() => currentAnchor()?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="video.media"
      class="video-dock"
      :class="{ docked, vertical }"
      :style="style"
      role="region"
      :aria-label="t.__('Video player', 'membuild-community')"
    >
      <iframe
        :src="src"
        :title="video.title || t.__('Video', 'membuild-community')"
        allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
        allowfullscreen
        referrerpolicy="strict-origin-when-cross-origin"
      />
      <div v-if="docked" class="bar">
        <span class="title">{{ video.title || t.__('Video', 'membuild-community') }}</span>
        <button :aria-label="t.__('Go to the post', 'membuild-community')" :title="t.__('Go to the post', 'membuild-community')" @click="goToPost"><Icon name="external" :size="16" /></button>
        <button :aria-label="t.__('Close video', 'membuild-community')" :title="t.__('Close video', 'membuild-community')" @click="stopVideo"><Icon name="close" :size="18" /></button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.video-dock {
  position: absolute;
  z-index: 10; /* over its post, under the sticky top bar */
  background: #000;
}

.video-dock iframe {
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
}

/* The corner player: the post scrolled away or another page is open. */
.video-dock.docked {
  position: fixed;
  top: auto;
  left: auto;
  bottom: 16px;
  z-index: 30;
  width: 340px;
  height: auto;
  aspect-ratio: 16 / 9;
  overflow: hidden;
  border-radius: 12px;
  box-shadow: 0 12px 32px rgb(0 0 0 / 35%);
  animation: pop 0.18s ease-out;
}

.video-dock.docked.vertical {
  width: 200px;
  aspect-ratio: 9 / 16;
}

.bar {
  position: absolute;
  inset: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 6px 18px 12px;
  background: linear-gradient(rgb(0 0 0 / 65%), transparent);
  color: #fff;
  opacity: 0;
  transition: opacity 0.15s;
}

/* The controls show while hovering, like a video player, and always on touch screens. */
.video-dock.docked:hover .bar,
.video-dock.docked:focus-within .bar {
  opacity: 1;
}

@media (hover: none) {
  .bar {
    opacity: 1;
  }
}

.title {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  font-weight: 650;
}

.bar button {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: rgb(0 0 0 / 45%);
  color: #fff;
}

.bar button:hover,
.bar button:focus-visible {
  background: rgb(255 255 255 / 25%);
}

@keyframes pop {
  from {
    transform: scale(0.92);
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .video-dock.docked {
    animation: none;
  }
}

/* Phones: above the tab bar, a little smaller. */
@media (max-width: 767px) {
  .video-dock.docked {
    right: 12px !important;
    bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom) + 12px);
    width: min(60vw, 260px);
  }

  .video-dock.docked.vertical {
    width: min(36vw, 150px);
  }
}
</style>
