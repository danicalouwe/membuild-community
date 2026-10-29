<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { cfg } from '../config.js'

/**
 * A small still map around a point, for places without a photo (the way
 * Facebook shows a place with no picture). It measures itself and lays out
 * just enough map tiles to fill its box, placed so the point sits in the
 * middle, with a pin on top. Same tiles as the big map, loaded only when the
 * thumbnail scrolls into view.
 */
const props = defineProps({
  lat: { type: Number, required: true },
  lng: { type: Number, required: true },
  zoom: { type: Number, default: 15 },
  color: { type: String, default: '#3b82f6' },
  emoji: { type: String, default: '' },
})

const SIZE = 128 // each 256px tile drawn at half size: sharp on high-density screens
const url = cfg.community.mapTiles?.url || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'

const el = ref(null)
const box = ref({ w: 64, h: 64 })
let observer = null
onMounted(() => {
  observer = new ResizeObserver(([entry]) => {
    const { width, height } = entry.contentRect
    if (width && height) box.value = { w: width, h: height }
  })
  observer.observe(el.value)
})
onBeforeUnmount(() => observer?.disconnect())

const tiles = computed(() => {
  const n = 2 ** props.zoom
  const rad = (props.lat * Math.PI) / 180
  // The point, in tile units at this zoom.
  const x = ((props.lng + 180) / 360) * n
  const y = ((1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2) * n
  const { w, h } = box.value
  // Enough tiles each way from the point to cover half the box, plus one.
  const across = Math.ceil(w / 2 / SIZE) + 1
  const down = Math.ceil(h / 2 / SIZE) + 1
  const out = []
  for (let row = Math.floor(y) - down; row <= Math.floor(y) + down; row++) {
    if (row < 0 || row >= n) continue
    for (let col = Math.floor(x) - across; col <= Math.floor(x) + across; col++) {
      const left = w / 2 + (col - x) * SIZE
      const top = h / 2 + (row - y) * SIZE
      if (left > w || top > h || left + SIZE < 0 || top + SIZE < 0) continue // outside the box
      const wrapped = ((col % n) + n) % n
      out.push({ key: `${col}:${row}`, left, top, src: url.replace('{z}', props.zoom).replace('{x}', wrapped).replace('{y}', row) })
    }
  }
  return out
})
</script>

<template>
  <span ref="el" class="map-thumb" aria-hidden="true">
    <img v-for="tile in tiles" :key="tile.key" :src="tile.src" alt="" loading="lazy" decoding="async" draggable="false" :style="{ left: `${tile.left}px`, top: `${tile.top}px` }" />
    <span class="pin"><span><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.5" /></svg></span></span>
  </span>
</template>

<style scoped>
.map-thumb {
  position: relative;
  display: block;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: var(--surface-2);
}

.map-thumb img {
  position: absolute;
  width: 128px;
  height: 128px;
  max-width: none;
  user-select: none;
}

@media (prefers-color-scheme: dark) {
  .map-thumb img {
    filter: invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.88) saturate(0.6);
  }
}

.pin {
  position: absolute;
  left: 50%;
  top: 50%;
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  margin: -24px 0 0 -13px; /* the tip of the pin on the point */
  border-radius: 50% 50% 50% 4px;
  transform: rotate(-45deg);
  background: var(--accent);
  color: var(--accent-ink, #16181d);
  border: 2px solid var(--on-color);
  box-shadow: var(--shadow-sm);
}

.pin > span {
  display: grid;
  transform: rotate(45deg);
  line-height: 1;
}
</style>
