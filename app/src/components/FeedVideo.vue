<script>
// Only one feed video plays at a time across the whole page.
let playing = null
</script>

<script setup>
import { isProcessing } from '../lib/stream.js'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

const props = defineProps({
  media: { type: Object, required: true },
  // Reel cards in the feed open the full-screen Reels view on tap.
  to: { type: [String, Object], default: null },
})

const el = ref(null)
const wrap = ref(null)
const muted = ref(true)
const loadSrc = ref(false)
let nearObserver
let viewObserver

onMounted(() => {
  // `wrap` is a RouterLink component for reel cards, or a plain div otherwise.
  const target = wrap.value?.$el ?? wrap.value
  // Attach the source once the video is within a screen or so of the viewport…
  nearObserver = new IntersectionObserver(
    ([entry]) => {
      if (!entry.isIntersecting) return
      loadSrc.value = true
      nearObserver.disconnect() // only needed once
    },
    { rootMargin: '600px 0px' },
  )
  // …and play it only while it is mostly on screen. (One observer can't do both:
  // a root margin would make the 60% threshold count the off-screen margin too.)
  viewObserver = new IntersectionObserver(([entry]) => (entry.intersectionRatio >= 0.6 ? play() : pause()), { threshold: [0, 0.6] })
  nearObserver.observe(target)
  viewObserver.observe(target)
})

onBeforeUnmount(() => {
  nearObserver?.disconnect()
  viewObserver?.disconnect()
  if (playing === el.value) playing = null
})

function play() {
  const v = el.value
  if (!v) return
  if (playing && playing !== v) playing.pause()
  playing = v
  v.play().catch(() => {})
}

function pause() {
  el.value?.pause()
}

// Tall videos (reels, phone videos) show in a 4:5 frame, edge to edge, filled like Facebook's feed;
// the whole video is in Reels or full screen.
const tall = computed(() => (props.media.height || 16) > (props.media.width || 9) * 1.3)

function toggleSound(e) {
  e.stopPropagation()
  e.preventDefault()
  muted.value = !muted.value
}
</script>

<template>
  <component :is="to ? 'RouterLink' : 'div'" ref="wrap" :to="to" class="feed-video" :class="{ tall }" :style="tall ? null : { aspectRatio: `${media.width || 9} / ${media.height || 16}` }">
    <video
      ref="el"
      v-stream="loadSrc ? media : null"
      :poster="media.poster || undefined"
      :muted="muted"
      playsinline
      loop
      preload="metadata"
    />
    <span v-if="isProcessing(media)" class="processing">{{ t.__('Processing video…', 'membuild-community') }}</span>
    <button class="sound" :aria-label="muted ? t.__('Turn sound on', 'membuild-community') : t.__('Mute', 'membuild-community')" @click="toggleSound">
      <Icon :name="muted ? 'mute' : 'volume'" :size="18" />
    </button>
    <span v-if="to" class="badge"><Icon name="film" :size="14" /> {{ t.__('Reel', 'membuild-community') }}</span>
  </component>
</template>

<style scoped>
.processing {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  padding: 8px 14px;
  border-radius: var(--radius-pill);
  background: color-mix(in srgb, var(--scrim) 65%, transparent);
  color: var(--on-color);
  font-size: var(--fs-md);
  font-weight: 600;
  white-space: nowrap;
}

.feed-video {
  position: relative;
  display: block;
  max-height: 620px;
  margin: 0 auto;
  background: var(--player-bg);
  overflow: hidden;
}

.feed-video video {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.feed-video.tall {
  aspect-ratio: 4 / 5;
}

.feed-video.tall video {
  object-fit: cover;
}

/* Phones: edge to edge, no bars or gaps at the sides. */
@media (max-width: 767px) {
  .feed-video.tall {
    width: 100%;
    max-height: none;
  }
}

.sound,
.badge {
  position: absolute;
  display: flex;
  align-items: center;
  gap: 4px;
  color: var(--on-color);
  background: color-mix(in srgb, var(--scrim) 55%, transparent);
  border-radius: var(--radius-pill);
}

.sound {
  right: 10px;
  bottom: 10px;
  width: 34px;
  height: 34px;
  justify-content: center;
}

.badge {
  left: 10px;
  top: 10px;
  padding: 4px 9px;
  font-size: var(--fs-xs);
  font-weight: 600;
}
</style>
