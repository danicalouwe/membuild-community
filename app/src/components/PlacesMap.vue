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
  people: { type: Array, default: () => [] }, // members on the map: [{ user, lat, lng }]
  person: { type: Number, default: null }, // the member whose card is open
  picked: { type: Number, default: null }, // the place whose small card is open: on top, outlined
  pageScrolls: { type: Boolean, default: false }, // the page scrolls past the map: a plain wheel scrolls the page
})
const emit = defineEmits(['select', 'move', 'person', 'pan'])
let peopleLayer = null
let peopleMarkers = new Map() // member id → marker
let flying = false

const el = ref(null)
let map = null
let layer = null
let markers = new Map() // place id → marker, so a new selection restyles two pins, not all of them
let selectedId = null
let editPin = null
let mePin = null

const tiles = cfg.community.mapTiles || { url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }

const esc = (v) => String(v || '').replace(/[&<>"']/g, (ch) => `&#${ch.charCodeAt(0)};`)

// A place: a pin in the community's main colour with a map-pin mark. Zoomed in close,
// a small box with the place's photo and name instead (the tail points at the spot).
const CLOSE = 13
const PIN_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>'
function pin(place) {
  const cls = `${place.id === props.selected || place.id === props.picked ? ' on' : ''}${place.status === 'pending' ? ' pending' : ''}`
  const tag = place.news ? '<i class="mbcom-new">New</i>' : ''
  if (map && map.getZoom() >= CLOSE && place.name) {
    const img = place.cover ? `<img src="${esc(place.cover)}" alt="">` : `<span class="mbcom-box-noimg">${PIN_SVG}</span>`
    return L.divIcon({ className: '', html: `<span class="mbcom-box${cls}">${img}<b>${esc(place.name)}</b>${tag}</span>`, iconSize: [150, 118], iconAnchor: [75, 118] })
  }
  return L.divIcon({
    className: '',
    html: `<span class="mbcom-pin${cls}"><span>${PIN_SVG}</span></span>${tag}`,
    iconSize: [36, 44],
    iconAnchor: [18, 42],
  })
}

// A member on the map: their face in a white ring (green dot when online).
function face(p, count = 0) {
  const u = p.user
  const img = u.avatar ? `<img src="${esc(u.avatar)}" alt="">` : `<b>${esc((u.name || '?').slice(0, 1))}</b>`
  const live = u.active_at && Date.now() - new Date(u.active_at).getTime() < 5 * 60000
  // A pointer under the circle marks the spot, with a soft ripple there (staggered, so they don't all beat together).
  const delay = `${((u.id * 7) % 24) / 10}s`
  return L.divIcon({
    className: '',
    html: `<span class="mbcom-person${u.id === props.person ? ' on' : ''}" style="--delay:${delay}"><b class="mbcom-ping"></b><span class="mbcom-face">${img}${live ? '<i></i>' : ''}</span><em class="mbcom-tail"></em>${count ? `<i class="mbcom-count">${count < 1000 ? count : `${Math.floor(count / 1000)}k`}</i>` : ''}</span>`,
    iconSize: [44, 54],
    iconAnchor: [22, 54],
  })
}
function drawPeople() {
  if (!map || !peopleLayer) return
  peopleLayer.clearLayers()
  peopleMarkers = new Map()
  const list = []
  for (const p of props.people) {
    const m = L.marker([p.lat, p.lng], { icon: face(p), title: p.user.name, zIndexOffset: 500, keyboard: true, member: p })
    m.on('click', (ev) => emit('person', p.user.id, ev.target.getElement()?.getBoundingClientRect() || null))
    peopleMarkers.set(p.user.id, m)
    list.push(m)
  }
  peopleLayer.addLayers(list)
}

// Members in the same city: one face (the picked member's, else the first) with how many
// are there. Clicking it zooms in until they separate; at the closest zoom they fan out.
function faceGroup(cluster) {
  const kids = cluster.getAllChildMarkers()
  const lead = kids.find((k) => k.options.member.user.id === props.person) || kids[0]
  return face(lead.options.member, kids.length)
}

// A bubble for merged pins: bigger as the count grows.
function bubble(cluster) {
  const n = cluster.getChildCount()
  const size = n < 10 ? 36 : n < 100 ? 44 : 52
  return L.divIcon({ className: '', html: `<span class="mbcom-cluster" style="--size:${size}px">${n < 1000 ? n : `${Math.floor(n / 1000)}k`}</span>`, iconSize: [size, size] })
}

// A hidden map (a place page on a phone) has no size: moving it then only gives NaN errors.
const hidden = () => !map || !map.getSize().x || !map.getSize().y

function draw(fit = false) {
  if (!map || props.editable) return
  layer.clearLayers()
  markers = new Map()
  selectedId = props.selected
  const list = []
  for (const place of props.places) {
    const marker = L.marker([place.lat, place.lng], { icon: pin(place), title: place.name, zIndexOffset: place.id === props.selected || place.id === props.picked ? 1000 : 0, keyboard: true })
    marker.on('click', () => emit('select', place.id))
    markers.set(place.id, marker)
    list.push(marker)
  }
  layer.addLayers(list) // in one go: much faster than one by one
  if (fit && list.length && !hidden()) map.fitBounds(busiest(list.map((m) => m.getLatLng()).concat(peopleAt())), { padding: [48, 48], maxZoom: 13 })
}

// Where the map opens: the busiest part of the world, not all of it. Every pin and face
// counts its neighbours within about 2,000 km; the one with the most wins, and the map
// shows it with those neighbours (a whole country or region, rarely the planet).
const BUSY_KM = 2000
const peopleAt = () => props.people.map((p) => L.latLng(p.lat, p.lng))
function busiest(points) {
  if (points.length < 3) return points
  const sample = points.length > 400 ? points.filter((_, i) => i % Math.ceil(points.length / 400) === 0) : points
  let best = []
  for (const a of sample) {
    const near = points.filter((b) => a.distanceTo(b) <= BUSY_KM * 1000)
    if (near.length > best.length) best = near
  }
  return best
}

// Only the pins that change: the one that was selected, and the one that is now.
function restyle() {
  for (const id of [selectedId, props.selected, pickedId, props.picked]) {
    const marker = markers.get(id)
    const place = props.places.find((p) => p.id === id)
    if (marker && place) {
      marker.setIcon(pin(place))
      // The open place sits over members' faces (500) at the same spot.
      marker.setZIndexOffset(id === props.selected || id === props.picked ? 1000 : 0)
    }
  }
  selectedId = props.selected
  pickedId = props.picked
}
let pickedId = null
watch(() => props.picked, restyle)

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
  if (!place || !map || hidden()) return
  // Pins added while the map is still flying can be left off by the clustering add-on
  // (a tall window flies further): put them back once it has landed.
  map.once('moveend', () => draw(false))
  map.flyTo([place.lat, place.lng], Math.max(map.getZoom(), 15), { duration: 0.6 }) // 15: past where pins merge
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
  // When the page scrolls past the map, a plain wheel scrolls the page; ⌘/Ctrl + wheel
  // (and a trackpad pinch, which arrives as Ctrl + wheel) zooms, like Google Maps on a page.
  const zoomByWheel = () => (props.pageScrolls ? map.scrollWheelZoom.disable() : map.scrollWheelZoom.enable())
  zoomByWheel()
  watch(() => props.pageScrolls, zoomByWheel)
  el.value.addEventListener(
    'wheel',
    (e) => {
      if (!props.pageScrolls || !(e.ctrlKey || e.metaKey)) return
      e.preventDefault()
      const at = map.mouseEventToLatLng(e)
      if (Math.abs(e.deltaY) >= 4) map.setZoomAround(at, map.getZoom() - Math.sign(e.deltaY))
    },
    { passive: false },
  )
  // Pins turn into photo boxes when zoomed in close, and back.
  let close = map.getZoom() >= CLOSE
  map.on('zoomend', () => {
    const now = map.getZoom() >= CLOSE
    if (now === close) return
    close = now
    for (const [id, marker] of markers) {
      const place = props.places.find((p) => p.id === id)
      if (place) marker.setIcon(pin(place))
    }
  })
  // The member moved the map: a card pinned to a face would point at nothing. (Not when we fly it.)
  map.on('dragstart zoomstart', () => !flying && emit('pan'))
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
  peopleLayer = L.markerClusterGroup({
    showCoverageOnHover: false,
    maxClusterRadius: 44,
    spiderfyOnMaxZoom: true,
    zoomToBoundsOnClick: true,
    iconCreateFunction: faceGroup,
  }).addTo(map)
  drawPeople()
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
watch(() => [props.people, props.person], drawPeople)
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
    if (!hidden()) map.fitBounds([[at.lat, at.lng], ...near], { padding: [48, 48], maxZoom: 13 })
  },
)

// After the map was hidden (phones start on the list), it has to measure itself again and refit.
// Where a pin or face sits on screen, worked out from the map (its tip on the spot), not
// measured: faces are redrawn while the map settles, so a measured one can be out of date.
function boxAt(latlng, w, h) {
  const p = map.latLngToContainerPoint(latlng)
  const r = map.getContainer().getBoundingClientRect()
  const left = r.left + p.x - w / 2
  const top = r.top + p.y - h
  return { left, top, right: left + w, bottom: top + h, width: w, height: h, x: left, y: top }
}

defineExpose({
  // Fly to a place, close enough to show it on its own, then say where it is on screen.
  flyToPlace: (id, bottom = 0) =>
    new Promise((resolve) => {
      const m = markers.get(id)
      if (!map || !m || hidden()) return resolve(null)
      flying = true
      const zoom = Math.max(map.getZoom(), 15)
      const pt = map.project(m.getLatLng(), zoom).add([0, bottom / 2])
      const goal = map.unproject(pt, zoom)
      let tries = 0
      const landed = () => {
        if (tries++ < 4 && map.distance(map.getCenter(), goal) > 2000) return map.once('moveend', landed)
        flying = false
        setTimeout(() => {
          const now = markers.get(id)
          const close = map.getZoom() >= CLOSE
          resolve(now ? boxAt(now.getLatLng(), close ? 150 : 36, close ? 118 : 44) : null)
        }, 250)
      }
      map.once('moveend', landed)
      map.flyTo(goal, zoom, { duration: 0.8 })
    }),
  // Fly to a member's city (keeping clear of the panel at the bottom), then say where their face is.
  flyToPerson: (id, bottom = 0) =>
    new Promise((resolve) => {
      const m = peopleMarkers.get(id)
      if (!map || !m || hidden()) return resolve(null)
      flying = true
      const at = m.getLatLng()
      const zoom = Math.max(map.getZoom(), 10)
      // Aim a little above the middle, so the face lands in the open map above the panel.
      const pt = map.project(at, zoom).add([0, bottom / 2])
      const goal = map.unproject(pt, zoom)
      let tries = 0
      // Another move (the page framing itself) can end first: wait until we're really there.
      const landed = () => {
        if (tries++ < 4 && map.distance(map.getCenter(), goal) > 2000) return map.once('moveend', landed)
        flying = false
        // Faces are redrawn when the selection changes: measure the one there now.
        // In a city with several members it's the group's face (theirs, with the count).
        setTimeout(() => {
          const now = peopleMarkers.get(id)
          const shown = (now && (now.getElement() ? now : peopleLayer.getVisibleParent(now))) || now
          resolve(shown ? boxAt(shown.getLatLng(), 44, 54) : null)
        }, 30)
      }
      map.once('moveend', landed)
      map.flyTo(goal, zoom, { duration: 0.8 })
    }),
  // Show every pin, keeping clear of a panel covering the bottom of the map (Places V2).
  fit: (bottom = 0, top = 0) => {
    const list = busiest([...markers.values()].map((m) => m.getLatLng()).concat(peopleAt()))
    if (map && list.length && !hidden()) map.fitBounds(list, { paddingTopLeft: [48, 48 + top], paddingBottomRight: [48, 48 + bottom], maxZoom: 13 })
  },
  // Back on a kept page: measure again, keeping the view as it was.
  wake: () => setTimeout(() => map?.invalidateSize(), 30),
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
  font-size: var(--fs-2xs);
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
  background: var(--accent);
  color: var(--accent-ink, #16181d);
  border: 3px solid var(--on-color);
  box-shadow: 0 3px 10px color-mix(in srgb, var(--shadow-color) 30%, transparent);
  transition: transform 0.15s;
}

.map :deep(.mbcom-pin > span) {
  display: grid;
  transform: rotate(45deg);
  line-height: 1;
}

/* Zoomed in: the place's photo and name in a little box, its tail on the spot. */
.map :deep(.mbcom-box) {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 150px;
  height: 108px;
  overflow: visible;
  border: 3px solid var(--on-color);
  border-radius: var(--radius-md);
  background: var(--paper);
  box-shadow: 0 4px 14px color-mix(in srgb, var(--shadow-color) 28%, transparent);
  cursor: pointer;
  transition: transform 0.15s;
}

.map :deep(.mbcom-box::after) {
  content: '';
  position: absolute;
  bottom: -10px;
  left: 50%;
  width: 14px;
  height: 14px;
  background: var(--paper);
  transform: translateX(-50%) rotate(45deg);
  box-shadow: var(--shadow-sm);
}

.map :deep(.mbcom-box img),
.map :deep(.mbcom-box-noimg) {
  flex: 1;
  width: 100%;
  min-height: 0;
  border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  object-fit: cover;
}

.map :deep(.mbcom-box-noimg) {
  display: grid;
  place-items: center;
  background: var(--accent);
  color: var(--accent-ink, #16181d);
}

.map :deep(.mbcom-box b) {
  position: relative;
  z-index: 1;
  padding: 5px 8px 4px;
  overflow: hidden;
  background: var(--paper);
  color: var(--ink);
  font-size: var(--fs-sm);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.map :deep(.mbcom-box.on) {
  border-color: var(--accent);
  transform: scale(1.08);
}

.map :deep(.mbcom-box.pending) {
  opacity: 0.7;
}

.map :deep(.mbcom-box .mbcom-new) {
  top: -10px;
  left: auto;
  right: -8px;
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
  font-size: var(--fs-md);
  border: 3px solid var(--on-color);
  box-shadow: 0 0 0 6px color-mix(in srgb, var(--accent) 30%, transparent), 0 3px 10px color-mix(in srgb, var(--shadow-color) 30%, transparent);
  cursor: pointer;
}

.map :deep(.mbcom-face) {
  position: relative;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border: 3px solid var(--on-color);
  border-radius: 50%;
  background: var(--accent-soft);
  box-shadow: 0 2px 8px color-mix(in srgb, var(--shadow-color) 30%, transparent);
  cursor: pointer;
}

.map :deep(.mbcom-face img) {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

/* The face, its pointer and the ripple on the spot. */
.map :deep(.mbcom-person) {
  position: relative;
  display: block;
  width: 44px;
  height: 54px;
  filter: drop-shadow(0 2px 4px color-mix(in srgb, var(--scrim) 28%, transparent));
  transform-origin: 50% 100%;
  transition: transform 0.15s;
}

.map :deep(.mbcom-person .mbcom-face) {
  box-shadow: none; /* the shadow comes from the whole pin, pointer included */
}

.map :deep(.mbcom-tail) {
  position: absolute;
  bottom: 0;
  left: 50%;
  width: 0;
  height: 0;
  margin-left: -8px;
  border-top: 12px solid var(--on-color);
  border-right: 8px solid transparent;
  border-left: 8px solid transparent;
}

.map :deep(.mbcom-ping) {
  position: absolute;
  bottom: -5px;
  left: 50%;
  width: 22px;
  height: 10px;
  margin-left: -11px;
  border-radius: 50%;
  background: var(--accent);
  opacity: 0;
  animation: mbcom-ping 2.4s ease-out infinite;
  animation-delay: var(--delay, 0s);
}

@keyframes mbcom-ping {
  0% { opacity: 0.7; transform: scale(0.3); }
  70%, 100% { opacity: 0; transform: scale(2.4); }
}

/* How many members a grouped face stands for. */
.map :deep(.mbcom-count) {
  position: absolute;
  top: -6px;
  right: -10px;
  min-width: 24px;
  height: 24px;
  padding: 0 6px;
  border: 2px solid var(--on-color);
  border-radius: var(--radius-pill);
  background: var(--accent);
  color: var(--accent-ink);
  font-size: var(--fs-xs);
  font-style: normal;
  font-weight: 800;
  line-height: 20px;
  text-align: center;
}

.map :deep(.mbcom-person.on) {
  transform: scale(1.15);
}

.map :deep(.mbcom-person.on .mbcom-face) {
  border-color: var(--accent);
}

.map :deep(.mbcom-person.on .mbcom-tail) {
  border-top-color: var(--accent);
}

@media (prefers-reduced-motion: reduce) {
  .map :deep(.mbcom-ping) {
    animation: none;
  }
}

.map :deep(.mbcom-face i) {
  position: absolute;
  right: -2px;
  bottom: -2px;
  width: 13px;
  height: 13px;
  border: 2px solid var(--on-color);
  border-radius: 50%;
  background: var(--ok);
}

.map :deep(.mbcom-new) {
  position: absolute;
  top: -10px;
  left: 24px;
  padding: 1px 7px;
  border-radius: var(--radius-pill);
  background: #e8590c;
  color: var(--on-color);
  font-size: var(--fs-2xs);
  font-style: normal;
  font-weight: 800;
  white-space: nowrap;
  box-shadow: var(--shadow-sm);
}

.map :deep(.mbcom-pin.pending) {
  opacity: 0.6;
  border-style: dashed;
}
</style>
