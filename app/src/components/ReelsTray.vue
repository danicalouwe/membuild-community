<script setup>
import { defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { lists, loadMore, takePost } from '../stores/feed.js'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

const ReportDialog = defineAsyncComponent(() => import('./ReportDialog.vue'))

// Reels grouped into one horizontal tray inside the feed, like Facebook: tiles show
// the poster; on desktop, hovering plays the video silently with only a ⋯ button
// on top. Tapping opens Reels at that reel.
const reels = lists.reels
const track = ref(null)
const wrap = ref(null)
const canLeft = ref(false)
const canRight = ref(false)
const menu = ref(null) // { reel, top, left }
const reporting = ref(null) // reel id
const copied = ref(false)
let previewing = null

function update() {
  const el = track.value
  if (!el) return
  canLeft.value = el.scrollLeft > 4
  canRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4
  // Fetch more reels as the tray nears its end.
  if (el.scrollLeft + el.clientWidth > el.scrollWidth - el.clientWidth) loadMore(reels)
  menu.value = null
}

function page(direction) {
  const el = track.value
  el.scrollBy({ left: direction * (el.clientWidth - 120), behavior: 'smooth' })
}

function preview(e, on) {
  if (e.pointerType && e.pointerType !== 'mouse') return
  const v = e.currentTarget.querySelector('video')
  if (!v) return
  if (on) {
    previewing?.pause()
    previewing = v
    v.currentTime = 0
    v.play().catch(() => {})
  } else {
    v.pause()
  }
}

/* The ⋯ menu sits outside the scrolling row so it isn't clipped by the tile. */
function openMenu(e, reel) {
  if (menu.value?.reel === reel) return (menu.value = null)
  const b = e.currentTarget.getBoundingClientRect()
  const w = wrap.value.getBoundingClientRect()
  menu.value = { reel, top: b.bottom - w.top + 6, left: Math.min(b.right - w.left - 200, w.width - 212) }
  copied.value = false
}

async function copyLink(reel) {
  const url = `${location.origin}${cfg.basePath}/reels?id=${reel.id}`
  try {
    await navigator.clipboard.writeText(url)
    copied.value = true
    setTimeout(() => (menu.value = null), 900)
  } catch {
    prompt(t.__('Copy this link', 'membuild-community'), url)
    menu.value = null
  }
}

async function remove(reel) {
  menu.value = null
  if (!confirm(t.__('Delete this reel?', 'membuild-community'))) return
  const restore = takePost(reel.id)
  try {
    await api.deletePost(reel.id)
  } catch (err) {
    restore() // the server kept it, so it comes back where it was
    alert(err.message)
  }
}

function closeMenu(e) {
  if (menu.value && !e.target.closest?.('.reel-menu, .dots')) menu.value = null
}

onMounted(async () => {
  if (!reels.loaded) await loadMore(reels)
  requestAnimationFrame(update)
  window.addEventListener('resize', update)
  document.addEventListener('pointerdown', closeMenu)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', update)
  document.removeEventListener('pointerdown', closeMenu)
})
const optionsLabel = (name) => t.sprintf(/* translators: %s: the name of the person who posted the reel */ t.__('Options for reel by %s', 'membuild-community'), name)
const reelBy = (name) => t.sprintf(/* translators: %s: the name of the person who posted the reel */ t.__('Reel by %s', 'membuild-community'), name)
</script>

<template>
  <section v-if="reels.items.length" class="tray card" :aria-label="t.__('Reels', 'membuild-community')">
    <header>
      <span class="title"><Icon name="reels" :size="22" /> {{ t.__('Reels', 'membuild-community') }}</span>
      <RouterLink to="/reels" class="see-all">{{ t.__('See all', 'membuild-community') }}</RouterLink>
    </header>

    <div ref="wrap" class="wrap">
      <div ref="track" class="track" @scroll.passive="update">
        <RouterLink
          v-for="r in reels.items"
          :key="r.localKey || r.id"
          class="tile"
          :class="{ open: menu?.reel === r }"
          :to="{ name: 'reels', query: { id: r.id } }"
          @pointerenter="preview($event, true)"
          @pointerleave="preview($event, false)"
        >
          <img v-if="r.media[0]?.poster" :src="r.media[0].poster" alt="" loading="lazy" decoding="async" />
          <video v-if="r.media[0]?.kind === 'video'" v-stream="r.media[0]" muted playsinline loop preload="none" />
          <button
            v-if="!r.pending"
            class="dots"
            :aria-label="optionsLabel(r.author?.name)"
            :aria-expanded="menu?.reel === r"
            @click.prevent.stop="openMenu($event, r)"
          >
            <svg width="4" height="18" viewBox="0 0 4 18" aria-hidden="true"><circle cx="2" cy="2" r="2" /><circle cx="2" cy="9" r="2" /><circle cx="2" cy="16" r="2" /></svg>
          </button>
          <span class="sr-only">{{ r.content || reelBy(r.author?.name) }}</span>
        </RouterLink>
      </div>
      <div v-if="menu" class="reel-menu" role="menu" :style="{ top: `${menu.top}px`, left: `${menu.left}px` }">
        <button role="menuitem" @click="copyLink(menu.reel)"><Icon name="share" :size="18" /> {{ copied ? t.__('Link copied ✓', 'membuild-community') : t.__('Copy link', 'membuild-community') }}</button>
        <button v-if="menu.reel.author?.id !== cfg.user.id" role="menuitem" @click="reporting = menu.reel.id; menu = null"><Icon name="flag" :size="18" /> {{ t.__('Report reel', 'membuild-community') }}</button>
        <button v-if="menu.reel.can_delete" role="menuitem" class="danger" @click="remove(menu.reel)"><Icon name="trash" :size="18" /> {{ t.__('Delete reel', 'membuild-community') }}</button>
      </div>
      <button v-if="canLeft" class="arrow left" :aria-label="t.__('Previous reels', 'membuild-community')" @click="page(-1)"><Icon name="chevronLeft" :size="26" /></button>
      <button v-if="canRight" class="arrow right" :aria-label="t.__('More reels', 'membuild-community')" @click="page(1)"><Icon name="chevronRight" :size="26" /></button>
    </div>
    <ReportDialog v-if="reporting" type="post" :id="reporting" @close="reporting = null" />
  </section>
</template>

<style scoped>
.tray {
  padding: 12px 0 12px;
  overflow: hidden;
}

header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 14px 12px;
}

.title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 17px;
  font-weight: 650;
}

.see-all {
  font-weight: 600;
  font-size: 14px;
}

.wrap {
  position: relative;
}

.track {
  display: flex;
  gap: 8px;
  padding: 0 12px;
  overflow-x: auto;
  scroll-snap-type: x proximity;
  scroll-padding: 0 12px;
  scrollbar-width: none;
  overscroll-behavior-x: contain;
}

.track::-webkit-scrollbar {
  display: none;
}

.tile {
  position: relative;
  flex: none;
  width: 192px;
  aspect-ratio: 192 / 343;
  border-radius: 10px;
  overflow: hidden;
  background: #111;
  scroll-snap-align: start;
  transition: transform 0.15s ease;
}

.tile:active {
  transform: scale(0.98);
}

.tile img,
.tile video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.tile video {
  opacity: 0;
  transition: opacity 0.2s ease;
}

.tile:hover video {
  opacity: 1;
}

/* Like Facebook: nothing on the tile but the three dots, and only while hovering. */
.dots {
  position: absolute;
  top: 8px;
  right: 6px;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  color: #fff;
  fill: currentColor;
  filter: drop-shadow(0 1px 2px rgb(0 0 0 / 55%));
  opacity: 0;
  transition: opacity 0.15s ease;
}

.tile:hover .dots,
.tile.open .dots,
.dots:focus-visible {
  opacity: 1;
}

.dots:hover {
  background: rgb(0 0 0 / 25%);
}

.reel-menu {
  position: absolute;
  z-index: 5;
  display: flex;
  flex-direction: column;
  width: 200px;
  padding: 6px;
  border-radius: 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: 0 8px 24px rgb(0 0 0 / 25%);
}

.reel-menu button {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: 8px;
  font-weight: 600;
  text-align: left;
}

.reel-menu button:hover {
  background: var(--surface-2);
}

.reel-menu .danger {
  color: var(--danger);
}

.arrow {
  position: absolute;
  top: 50%;
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  margin-top: -24px;
  border-radius: 50%;
  color: #fff;
  background: rgb(40 41 46 / 92%);
  box-shadow: 0 2px 10px rgb(0 0 0 / 35%);
}

.arrow:hover {
  background: rgb(58 59 64 / 96%);
}

.arrow.left {
  left: 10px;
}

.arrow.right {
  right: 10px;
}

@media (max-width: 767px) {
  .tile {
    width: 132px;
  }

  .arrow {
    display: none;
  }
}
</style>
