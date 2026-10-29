<script setup>
import { computed, ref } from 'vue'
import GifMedia from './GifMedia.vue'
import FeedVideo from './FeedVideo.vue'
import FeedEmbed from './FeedEmbed.vue'
import Icon from './Icon.vue'
import { useDialog } from '../lib/useDialog.js'
import { t } from '../lib/i18n.js'

const props = defineProps({
  media: { type: Array, default: () => [] },
  reelLink: { type: [String, Object], default: null },
})

const viewer = ref(-1)
const viewerEl = ref(null)
useDialog(viewerEl, { open: () => viewer.value >= 0, onClose: () => (viewer.value = -1) })

function onViewerKey(e) {
  if (e.key === 'ArrowLeft' && viewer.value > 0) viewer.value--
  else if (e.key === 'ArrowRight' && viewer.value < images.value.length - 1) viewer.value++
}
const images = computed(() => props.media.filter((m) => m.kind === 'image'))
const video = computed(() => props.media.find((m) => m.kind === 'video'))
const embed = computed(() => props.media.find((m) => m.kind === 'embed'))
const shown = computed(() => images.value.slice(0, 4))
const extra = computed(() => images.value.length - shown.value.length)

// Space is reserved from stored dimensions, so the feed never jumps as images load.
// Single images are clamped like Facebook: no taller than 4:5, no wider than 1.91:1.
const singleRatio = computed(() => {
  const m = images.value[0]
  if (!m?.width || !m?.height) return '4 / 3'
  const r = Math.min(1.91, Math.max(0.8, m.width / m.height))
  return String(r)
})
// A GIF on its own keeps its own shape and size, like Facebook: never taller than
// 360px and never stretched past its real size, so a small GIF doesn't fill the post.
const isGif = (m) => !!(m?.mp4 || m?.via === 'giphy' || /\.gif(\?|$)/i.test(m?.url || ''))
const singleStyle = computed(() => {
  const m = images.value[0]
  if (!isGif(m)) return { aspectRatio: singleRatio.value }
  const w = m.width || 480
  const h = m.height || 360
  const scale = Math.min(1, 360 / h)
  return { aspectRatio: `${w} / ${h}`, width: `${Math.round(w * scale)}px` }
})
const position = (n, total) => t.sprintf(/* translators: 1: number of the photo shown, 2: total number of photos */ t.__('%1$d / %2$d', 'membuild-community'), n, total)
</script>

<template>
  <FeedVideo v-if="video" :media="video" :to="reelLink" />
  <FeedEmbed v-else-if="embed" :media="embed" />

  <button v-else-if="images.length === 1" class="single" :class="{ gif: isGif(images[0]) }" :style="singleStyle" :aria-label="t.__('Open photo', 'membuild-community')" @click="viewer = 0">
    <GifMedia v-if="images[0].mp4" :src="images[0].url" :mp4="images[0].mp4" :still="images[0].poster || ''" :width="images[0].width" :height="images[0].height" />
    <img v-else :src="images[0].url" :width="images[0].width" :height="images[0].height" alt="" loading="lazy" decoding="async" />
    <span v-if="images[0].via === 'giphy'" class="via">GIPHY</span>
  </button>

  <div v-else-if="images.length > 1" class="grid" :class="`n${shown.length}`">
    <button v-for="(m, i) in shown" :key="m.id || m.key" class="cell" @click="viewer = i">
      <GifMedia v-if="m.mp4" :src="m.url" :mp4="m.mp4" :still="m.poster || ''" />
      <img v-else :src="m.url" alt="" loading="lazy" decoding="async" />
      <span v-if="m.via === 'giphy'" class="via">GIPHY</span>
      <span v-if="i === shown.length - 1 && extra > 0" class="extra">+{{ extra }}</span>
    </button>
  </div>

  <Teleport to="body">
    <div v-if="viewer >= 0" ref="viewerEl" class="viewer" role="dialog" aria-modal="true" :aria-label="t.__('Photo', 'membuild-community')" @click.self="viewer = -1" @keydown="onViewerKey">
      <button class="close" :aria-label="t.__('Close', 'membuild-community')" @click="viewer = -1"><Icon name="close" /></button>
      <video v-if="images[viewer]?.mp4" :src="images[viewer].mp4" :poster="images[viewer].poster || images[viewer].url" autoplay muted loop playsinline disablepictureinpicture />
      <img v-else :src="images[viewer]?.url" alt="" />
      <div v-if="images.length > 1" class="nav">
        <button :disabled="viewer === 0" :aria-label="t.__('Previous photo', 'membuild-community')" @click="viewer--">‹</button>
        <span>{{ position(viewer + 1, images.length) }}</span>
        <button :disabled="viewer === images.length - 1" :aria-label="t.__('Next photo', 'membuild-community')" @click="viewer++">›</button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.single {
  position: relative;
  display: block;
  width: 100%;
  background: var(--surface-2);
  cursor: zoom-in;
  overflow: hidden;
}

.single.gif {
  max-width: calc(100% - 28px);
  margin: 4px 14px 8px;
  border-radius: var(--radius-md);
}

.single img,
.cell img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.grid {
  display: grid;
  gap: 3px;
  aspect-ratio: 1;
}

.grid.n2 {
  grid-template-columns: 1fr 1fr;
  aspect-ratio: 2 / 1.2;
}

.grid.n3 {
  grid-template-columns: 2fr 1fr;
  grid-template-rows: 1fr 1fr;
}

.grid.n3 .cell:first-child {
  grid-row: span 2;
}

.grid.n4 {
  grid-template-columns: 1fr 1fr;
  grid-template-rows: 1fr 1fr;
}

.cell {
  position: relative;
  overflow: hidden;
  background: var(--surface-2);
  cursor: zoom-in;
}

.extra {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  font-size: var(--fs-7xl);
  font-weight: 700;
  color: var(--on-color);
  background: color-mix(in srgb, var(--scrim) 45%, transparent);
}

.viewer {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, var(--scrim) 92%, transparent);
}

.viewer video,
.viewer img {
  max-width: 96vw;
  max-height: 88vh;
  object-fit: contain;
}

.close {
  position: absolute;
  top: 14px;
  right: 14px;
  color: var(--on-color);
  padding: 8px;
}

.nav {
  position: absolute;
  bottom: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  color: var(--on-color);
}

.nav button {
  font-size: 32px; /* exempt: the "+3" over a photo, sized to the tile */
  color: var(--on-color);
  padding: 0 12px;
}

.nav button:disabled {
  opacity: 0.3;
}

/* GIFs from GIPHY carry its name, as their terms ask. */
.via {
  position: absolute;
  left: 8px;
  bottom: 8px;
  padding: 2px 6px;
  border-radius: 4px;
  background: color-mix(in srgb, var(--scrim) 55%, transparent);
  color: var(--on-color);
  font-size: var(--fs-3xs);
  font-weight: 900;
  letter-spacing: 0.05em;
  pointer-events: none;
}
</style>
