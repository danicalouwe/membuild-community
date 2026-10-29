<script setup>
import { defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { cfg } from '../config.js'
import { stories, loadStories } from '../stores/stories.js'
import { storyBg } from '../lib/postStyle.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
// Loaded on first open, so the feed itself stays small.
const StoryViewer = defineAsyncComponent(() => import('./StoryViewer.vue'))
const StoryCreator = defineAsyncComponent(() => import('./StoryCreator.vue'))

// My Day / Stories row under the post form. Each author's photo sits in a
// ring in the community's theme colour, like Facebook's blue story ring.
const track = ref(null)
const canLeft = ref(false)
const canRight = ref(false)
const viewing = ref(null) // group index
const creating = ref(false)

// Like Facebook: on desktop, hovering a video story plays it silently; one at a time.
let playing = null
function hoverPlay(e, on) {
  if (e.pointerType && e.pointerType !== 'mouse') return
  const v = e.currentTarget.querySelector('video')
  if (!v) return
  if (on) {
    playing?.pause()
    playing = v
    v.currentTime = 0
    v.play().catch(() => {})
  } else {
    v.pause()
  }
}

// The tile previews the story the viewer will open on: the first unseen one.
function cover(g) {
  return g.stories.find((s) => !s.seen) || g.stories[g.stories.length - 1]
}

function update() {
  const el = track.value
  if (!el) return
  canLeft.value = el.scrollLeft > 4
  canRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4
}

function page(direction) {
  const el = track.value
  el.scrollBy({ left: direction * (el.clientWidth - 140), behavior: 'smooth' })
}

watch(() => stories.groups.length, () => nextTick(update))

onMounted(async () => {
  if (!stories.loaded) await loadStories().catch(() => {}) // a failed load leaves the strip empty, not broken
  nextTick(update)
  window.addEventListener('resize', update)
})
onBeforeUnmount(() => window.removeEventListener('resize', update))
</script>

<template>
  <section class="strip" :aria-label="t.__('Stories', 'membuild-community')">
    <div ref="track" class="track" @scroll.passive="update">
      <button class="tile create" @click="creating = true">
        <span class="photo"><img :src="cfg.user.avatar" alt="" /></span>
        <span class="plus"><Icon name="plus" :size="24" /></span>
        <span class="label">{{ t.__('Create story', 'membuild-community') }}</span>
      </button>

      <button
        v-for="(g, i) in stories.groups"
        :key="g.author.id"
        class="tile"
        @click="viewing = i"
        @pointerenter="hoverPlay($event, true)"
        @pointerleave="hoverPlay($event, false)"
      >
        <template v-for="s in [cover(g)]" :key="s.id">
          <img v-if="s.kind === 'photo'" class="poster" :src="s.url" alt="" loading="lazy" decoding="async" />
          <img v-else-if="s.kind === 'video' && s.poster" class="poster" :src="s.poster" alt="" loading="lazy" decoding="async" />
          <video v-if="s.kind === 'video'" class="poster hover-video" :src="s.url" muted playsinline loop preload="none" />
          <span v-else-if="s.kind === 'text'" class="poster text" :style="storyBg(s.background)">{{ s.text }}</span>
        </template>
        <span class="ring" :class="{ unseen: g.has_unseen }"><Avatar :user="g.author" :size="36" /></span>
        <span class="name">{{ g.is_me ? t.__('Your story', 'membuild-community') : g.author.name }}</span>
      </button>
    </div>
    <button v-if="canLeft" class="arrow left" :aria-label="t.__('Previous', 'membuild-community')" @click="page(-1)"><Icon name="chevronLeft" :size="24" /></button>
    <button v-if="canRight" class="arrow right" :aria-label="t.__('More stories', 'membuild-community')" @click="page(1)"><Icon name="chevronRight" :size="24" /></button>

    <StoryViewer v-if="viewing !== null" :start-group="viewing" @close="viewing = null" />
    <StoryCreator v-if="creating" @close="creating = false" />
  </section>
</template>

<style scoped>
.strip {
  position: relative;
}

.track {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  scroll-snap-type: x proximity;
  scrollbar-width: none;
  overscroll-behavior-x: contain;
}

.track::-webkit-scrollbar {
  display: none;
}

.tile {
  position: relative;
  flex: none;
  width: 112px;
  aspect-ratio: 112 / 162;
  border-radius: var(--radius-md);
  overflow: hidden;
  background: var(--player-surface);
  scroll-snap-align: start;
  text-align: left;
  transition: transform 0.15s ease;
}

.tile:active {
  transform: scale(0.98);
}

.poster {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.25s ease;
}

.poster.text {
  display: grid;
  place-items: center;
  padding: 12px;
  color: var(--on-color);
  font-size: var(--fs-2xs);
  font-weight: 750;
  text-align: center;
  overflow: hidden;
}

.hover-video {
  opacity: 0;
  transition: opacity 0.2s ease;
}

.tile:hover .hover-video {
  opacity: 1;
}

.tile:hover .poster {
  transform: scale(1.03);
}

/* Shade the edges so the avatar and name stay readable on any story. */
.tile:not(.create)::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(color-mix(in srgb, var(--scrim) 30%, transparent), transparent 30%, transparent 60%, color-mix(in srgb, var(--scrim) 60%, transparent));
  pointer-events: none;
}

.ring {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 1;
  display: flex;
  padding: 3px;
  border-radius: 50%;
  background: var(--accent);
}

.name {
  position: absolute;
  inset: auto 10px 10px;
  z-index: 1;
  color: var(--on-color);
  font-size: var(--fs-sm);
  font-weight: 650;
  line-height: 1.25;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Create story: your photo on top, a plus over the seam, the label underneath. */
.create {
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border: 1px solid var(--border);
}

.photo {
  flex: 0 0 72%;
  overflow: hidden;
  background: var(--surface-2);
}

.photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.plus {
  position: absolute;
  top: 72%;
  left: 50%;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  margin: -18px 0 0 -18px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
  box-shadow: 0 0 0 4px var(--surface);
}

.label {
  margin-top: auto;
  padding: 0 6px 10px;
  text-align: center;
  font-size: var(--fs-sm);
  font-weight: 650;
}

.arrow {
  position: absolute;
  top: 50%;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  margin-top: -22px;
  border-radius: 50%;
  color: var(--on-color);
  background: color-mix(in srgb, var(--player-surface) 92%, transparent);
  box-shadow: 0 2px 10px color-mix(in srgb, var(--shadow-color) 35%, transparent);
}

.arrow.left {
  left: 8px;
}

.arrow.right {
  right: 8px;
}

@media (max-width: 767px) {
  .strip {
    padding: 0 12px;
  }

  .arrow {
    display: none;
  }
}
</style>
