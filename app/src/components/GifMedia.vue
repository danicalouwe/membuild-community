<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '../lib/i18n.js'

/**
 * A GIF the way Facebook shows one: GIPHY's MP4 version as a silent looping
 * video (often a tenth of the GIF's size), playing only while on screen.
 * Members who ask their device for less motion see it paused, with a tap to
 * play. If the video can't play, the image version shows instead.
 */
const props = defineProps({
  src: { type: String, required: true }, // the image version
  mp4: { type: String, default: '' },
  still: { type: String, default: '' }, // first frame, shown before the video starts
  width: { type: Number, default: 0 },
  height: { type: Number, default: 0 },
})

const el = ref(null)
const video = ref(!!props.mp4)
const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
const paused = ref(calm)
let observer = null
let visible = false

function sync() {
  const v = el.value
  if (!v || !video.value) return
  if (visible && !paused.value) v.play?.().catch(() => {})
  else v.pause?.()
}

onMounted(() => {
  if (!video.value || !el.value) return
  observer = new IntersectionObserver(
    ([entry]) => {
      visible = entry.isIntersecting
      sync()
    },
    { threshold: 0.1 },
  )
  observer.observe(el.value)
})
onBeforeUnmount(() => observer?.disconnect())

// A tap on a paused GIF (less-motion setting) plays it; another tap pauses again.
function toggle(e) {
  if (!calm) return
  e.stopPropagation()
  e.preventDefault()
  paused.value = !paused.value
  sync()
}
</script>

<template>
  <span class="gif-media">
    <video
      v-if="video"
      ref="el"
      :src="mp4"
      :poster="still || (calm ? src : undefined)"
      :width="width || null"
      :height="height || null"
      muted
      loop
      playsinline
      disablepictureinpicture
      :preload="still ? 'none' : 'metadata'"
      aria-hidden="true"
      @error="video = false"
      @click="toggle"
    />
    <img v-else :src="src" :width="width || null" :height="height || null" alt="" loading="lazy" decoding="async" />
    <button v-if="video && calm && paused" type="button" class="play" :aria-label="t.__('Play GIF', 'membuild-community')" @click="toggle">GIF</button>
  </span>
</template>

<style scoped>
.gif-media {
  position: relative;
  display: block;
  width: 100%;
  height: 100%;
}

.gif-media video,
.gif-media img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.play {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 52px;
  height: 52px;
  border-radius: 50%;
  border: 2px solid #fff;
  background: rgb(0 0 0 / 55%);
  color: #fff;
  font-weight: 900;
  font-size: 13px;
  letter-spacing: 0.04em;
}
</style>
