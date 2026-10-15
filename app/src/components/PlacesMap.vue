<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import { cfg } from '../config.js'
import { CATEGORY } from '../lib/places.js'

/**
 * The map behind Places: one pin per place, the selected one raised.
 * Pins close together merge into a bubble with a count, the way Google Maps
 * and Facebook do; tapping one zooms in until they come apart.
 * With `editable`, it shows a single draggable pin instead (adding a place).
 * Tiles come from the server's settings (OpenStreetMap unless the site
 * changed them) and their attribution always shows, as the tile terms ask.
 */
const props = defineProps({
  places: { type: Array, default: () => [] },
  selected: { type: Number, default: null },
  editable: { type: Object, default: null }, // { lat, lng } while placing a pin
  me: { type: Object, default: null }, // { lat, lng } after "Near me"
})
const emit = defineEmits(['select', 'move'])

const el = ref(null)
let map = null
let layer = null
let markers = new Map() // place id → marker, so a new selection restyles two pins, not all of them
let selectedId = null
let editPin = null
let mePin = null

const tiles = cfg.community.mapTiles || { url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }

function pin(place) {
  const c = CATEGORY[place.category] || CATEGORY.other
  return L.divIcon({
    className: '',
    html: `<span class="mbcom-pin${place.id === props.selected ? ' on' : ''}${place.status === 'pending' ? ' pending' : ''}" style="--pin:${c.color}"><span>${c.emoji}</span></span>`,
    iconSize: [36, 44],
    iconAnchor: [18, 42],
  })
}

// A bubble for merged pins: bigger as the count grows.
function bubble(cluster) {
  const n = cluster.getChildCount()
  const size = n < 10 ? 36 : n < 100 ? 44 : 52
  return L.divIcon({ className: '', html: `<span class="mbcom-cluster" style="--size:${size}px">${n < 1000 ? n : `${Math.floor(n / 1000)}k`}</span>`, iconSize: [size, size] })
}

function draw(fit = false) {
  if (!map || props.editable) return
  layer.clearLayers()
  markers = new Map()
  selectedId = props.selected
  const list = []
  for (const place of props.places) {
    const marker = L.marker([place.lat, place.lng], { icon: pin(place), title: place.name, zIndexOffset: place.id === props.selected ? 1000 : 0, keyboard: true })
    marker.on('click', () => emit('select', place.id))
    markers.set(place.id, marker)
    list.push(marker)
  }
  layer.addLayers(list) // in one go: much faster than one by one
  if (fit && list.length) map.fitBounds(list.map((m) => m.getLatLng()), { padding: [48, 48], maxZoom: 13 })
}

// Only the pins that change: the one that was selected, and the one that is now.
function restyle() {
  for (const id of [selectedId, props.selected]) {
    const marker = markers.get(id)
    const place = props.places.find((p) => p.id === id)
    if (marker && place) {
      marker.setIcon(pin(place))
      marker.setZIndexOffset(id === props.selected ? 1000 : 0)
    }
  }
  selectedId = props.selected
}

// Never zoom out past the point where the world is shorter than the map (grey bands
// above and below), and never pan past the poles: the way Google Maps behaves.
function fillHeight() {
  if (!map) return
  const height = map.getSize().y
  const min = Math.max(0, Math.ceil(Math.log2(height / 256)))
  map.setMinZoom(min)
  if (map.getZoom() < min) map.setZoom(min, { animate: false })
}

function focus() {
  const place = props.places.find((p) => p.id === props.selected)
  if (place && map) map.flyTo([place.lat, place.lng], Math.max(map.getZoom(), 15), { duration: 0.6 }) // 15: past where pins merge
}

onMounted(async () => {
  // The clustering add-on expects Leaflet as a global, so it loads after it.
  window.L = L
  await import('leaflet.markercluster')
  if (!el.value) return
  map = L.map(el.value, {
    zoomControl: false,
    worldCopyJump: true,
    attributionControl: true,
    maxBounds: [[-85.05, -540], [85.05, 540]], // poles closed; east–west keeps going round
    maxBoundsViscosity: 1,
  }).setView([20, 0], 2)
  map.on('resize', fillHeight)
  map.attributionControl.setPrefix(false)
  L.tileLayer(tiles.url, { attribution: tiles.attribution, maxZoom: 19 }).addTo(map)
  L.control.zoom({ position: 'topright' }).addTo(map) // the bottom corner belongs to the app's compose button
  layer = L.markerClusterGroup({
    showCoverageOnHover: false,
    maxClusterRadius: 48,
    disableClusteringAtZoom: 15,
    spiderfyOnMaxZoom: false,
    chunkedLoading: true, // adds thousands of pins without freezing the page
    iconCreateFunction: bubble,
  }).addTo(map)
  if (props.editable) {
    editPin = L.marker([props.editable.lat, props.editable.lng], { draggable: true, icon: pin({ category: 'meetup' }), autoPan: true }).addTo(map)
    editPin.on('dragend', () => emit('move', editPin.getLatLng()))
    map.setView([props.editable.lat, props.editable.lng], 16)
  } else {
    draw(true)
    if (props.selected) focus()
  }
  // The container gets its real size after layout (and the places may already be in):
  // measure again, then fit, or the first view is worked out for the wrong size.
  setTimeout(() => {
    if (!map) return
    map.invalidateSize()
    fillHeight()
    if (props.editable) return
    if (props.selected) focus()
    else draw(true)
  }, 60)
})

onBeforeUnmount(() => {
  map?.remove()
  map = null
})

watch(
  () => props.places,
  (now, before) => {
    draw(!props.selected)
    // Opened straight from a link: the places arrive after the map, so fly there now.
    if (props.selected && !before?.length) focus()
  },
  { deep: false },
)
watch(() => props.selected, () => {
  restyle()
  focus()
})
watch(
  () => props.editable,
  (at) => {
    if (!at || !editPin) return
    editPin.setLatLng([at.lat, at.lng])
    map.setView([at.lat, at.lng], Math.max(map.getZoom(), 16))
  },
)
watch(
  () => props.me,
  (at) => {
    if (!map) return
    mePin?.remove()
    if (!at) return
    mePin = L.circleMarker([at.lat, at.lng], { radius: 8, color: '#fff', weight: 3, fillColor: '#1a73e8', fillOpacity: 1 }).addTo(map)
    const near = props.places.slice(0, 5).map((p) => [p.lat, p.lng])
    map.fitBounds([[at.lat, at.lng], ...near], { padding: [48, 48], maxZoom: 13 })
  },
)

// After the map was hidden (phones start on the list), it has to measure itself again and refit.
defineExpose({
  resize: () =>
    setTimeout(() => {
      if (!map) return
      map.invalidateSize()
      fillHeight()
      if (props.selected) focus()
      else draw(true)
    }, 30),
})
</script>

<template>
  <div ref="el" class="map" />
</template>

<style scoped>
.map {
  width: 100%;
  height: 100%;
  background: var(--surface-2);
  z-index: 0;
}

/* Leaflet's own controls, in the app's style. */
.map :deep(.leaflet-control-zoom a) {
  width: 36px;
  height: 36px;
  line-height: 36px;
  color: var(--text);
  background: var(--surface);
  border-color: var(--border);
}

.map :deep(.leaflet-control-attribution) {
  font-size: 11px;
  background: color-mix(in srgb, var(--surface) 85%, transparent);
  color: var(--text-2);
}

.map :deep(.leaflet-control-attribution a) {
  color: var(--text-2);
}

/* Dark mode: the light tiles, inverted, so the map doesn't glare. */
@media (prefers-color-scheme: dark) {
  .map :deep(.leaflet-tile-pane) {
    filter: invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.88) saturate(0.6);
  }
}

.map :deep(.mbcom-pin) {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50% 50% 50% 6px;
  transform: rotate(-45deg);
  background: var(--pin);
  border: 3px solid #fff;
  box-shadow: 0 3px 10px rgb(0 0 0 / 30%);
  transition: transform 0.15s;
}

.map :deep(.mbcom-pin > span) {
  transform: rotate(45deg);
  font-size: 16px;
  line-height: 1;
}

.map :deep(.mbcom-pin.on) {
  transform: rotate(-45deg) scale(1.25);
  border-color: var(--text);
}

.map :deep(.mbcom-cluster) {
  display: grid;
  place-items: center;
  width: var(--size);
  height: var(--size);
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 800;
  font-size: 14px;
  border: 3px solid #fff;
  box-shadow: 0 0 0 6px color-mix(in srgb, var(--accent) 30%, transparent), 0 3px 10px rgb(0 0 0 / 30%);
  cursor: pointer;
}

.map :deep(.mbcom-pin.pending) {
  opacity: 0.6;
  border-style: dashed;
}
</style>
