<script setup>
import { computed, defineAsyncComponent, nextTick, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import PlacesMap from '../components/PlacesMap.vue'
import MapThumb from '../components/MapThumb.vue'
import { CATEGORY, directionsUrl, distanceKm, distanceLabel, openState, weekLines } from '../lib/places.js'
import { canDock, openChat } from '../stores/chatDock.js'
import { cfg, levelLabel } from '../config.js'
import BaseDialog from '../components/BaseDialog.vue'
import { cityOfAddress, dateBadge, dayShort, startTime, zoneLabel } from '../lib/events.js'
import { t } from '../lib/i18n.js'
import { telHref } from '../lib/phone.js'
import { showToast } from '../lib/toast.js'

const PlaceForm = defineAsyncComponent(() => import('../components/PlaceForm.vue'))

/**
 * Places (Version 2): the map fills the page. On top, one search (cities and places, as
 * you type) and the Places / Pending toggles (Pending: owners and moderators). Underneath,
 * a panel of place cards (or, with Pending, the suggestions with Approve and Not now).
 * Picking a city flies the map there and lists only what's there. A place opens in a
 * column on the left with the map beside it. Suggest / Add a place is its own page.
 * Members on the map: the ones who chose Show me on the map, as faces at their city;
 * tapping one opens a small card. Members / Places toggles: neither on means both.
 * A place shows What's new (members share it, staff check it) and Coming up here.
 */
const props = defineProps({ placeId: { type: Number, default: null } })
const router = useRouter()

const places = ref([])
const canEdit = ref(false)
const loading = ref(true)
const error = ref('')
const kind = ref('all')
const query = ref('')
const me = ref(null) // { lat, lng } after "Near me"; kept in this page only, never sent anywhere
const locating = ref(false)
const showMap = ref(false) // phones: map instead of the list
const detail = ref(null) // the open place, with who goes there
const form = ref(null) // { place } while adding or editing
const busy = ref(false)
const mapRef = ref(null)

// The chip row scrolls sideways when it doesn't fit: its edges fade where there's more,
// and on computers an arrow button scrolls to it (swiping is natural on phones).
const chipsEl = ref(null)
const more = ref({ left: false, right: false })
function measureChips() {
  const el = chipsEl.value
  if (!el) return
  more.value = { left: el.scrollLeft > 2, right: el.scrollLeft + el.clientWidth < el.scrollWidth - 2 }
}
const scrollChips = (dir) => chipsEl.value?.scrollBy({ left: dir * chipsEl.value.clientWidth * 0.7, behavior: 'smooth' })
let chipsObserver = null
onMounted(() => {
  chipsObserver = new ResizeObserver(measureChips)
  watch(chipsEl, (el) => el && (chipsObserver.observe(el), measureChips()), { immediate: true })
})
onBeforeUnmount(() => chipsObserver?.disconnect())
// The chips arrive with the places, after the row has its size.
watch(() => places.value.length, () => nextTick(measureChips))

/* ---------- Members on the map ---------- */
const people = ref([])
const peopleLoading = ref(true)
const myMap = ref(null) // { lat, lng, city } when I'm on the map
const showMembers = ref(false)
const showPlaces = ref(false)
const membersOn = computed(() => view.value !== 'pending' && (showMembers.value || !showPlaces.value))
const placesOn = computed(() => view.value === 'pending' || showPlaces.value || !showMembers.value)
const personId = ref(null)
// The card opens next to the face that was clicked (on the map or in the panel),
// above it when there's room, else below; always inside the window.
const cardAt = ref(null) // { left, top } in the window
const CARD_W = 340
const CARD_H = 230 // about the card's height, to decide above or below
// Phones: no cards over the map; a member opens their profile, a place its page.
const phone = () => window.matchMedia('(max-width: 767px)').matches
let openedAt = 0
function showPerson(id, rect) {
  if (phone()) return router.push({ name: 'member', params: { id } })
  placeCard.value = null
  personId.value = id
  openedAt = Date.now()
  cardLoose.value = !rect // no face to sit by (members hidden): the card shows in the corner
  if (!rect) return (cardAt.value = null)
  const gap = 12
  const vw = window.innerWidth
  const narrow = vw < 600
  const w = narrow ? vw - 16 : CARD_W
  let left = narrow ? 8 : rect.left + rect.width / 2 - w / 2
  left = Math.max(8, Math.min(left, vw - w - 8))
  // Above the face when there's room (the card's bottom just over it), else below it.
  const above = rect.top - gap - CARD_H > 64
  const top = above ? rect.top - gap : Math.min(rect.bottom + gap, window.innerHeight - CARD_H - 8)
  cardAt.value = { left, top, above }
}
// A face in the panel (or a member in the search): the map flies to their city, then
// their card opens next to their face on the map.
// The second click on the same member or place opens their page.
const toMember = (id) => router.push({ name: 'member', params: { id } })
function onFace(id, rect) {
  if (personId.value === id && cardAt.value) return toMember(id)
  showPerson(id, rect)
}
async function flyToPerson(id) {
  if (personId.value === id && cardAt.value) return toMember(id)
  if (phone()) return toMember(id)
  closePerson()
  personId.value = id
  const rect = await mapRef.value?.flyToPerson(id, panelOpen.value ? panelCover() : 0)
  if (personId.value === id) showPerson(id, rect)
}

// Scrolling the panel or moving the map closes a card pinned to a face, but not the
// little scroll that brings a tapped face into view.
function scrolledAway() {
  if ((cardAt.value || placeCard.value) && Date.now() - openedAt > 500) closePerson()
}
/* A place card in the panel (computers): fly there, then a small card by its pin. */
const placeCard = ref(null) // { place, left, top, above }
async function flyToPlace(p) {
  if (phone() || placeCard.value?.place.id === p.id) return open(p.id)
  closePerson()
  placeCard.value = null
  const rect = await mapRef.value?.flyToPlace(p.id, panelOpen.value ? panelCover() : 0)
  if (!rect) return open(p.id)
  const w = 320
  const left = Math.max(8, Math.min(rect.left + rect.width / 2 - w / 2, window.innerWidth - w - 8))
  const above = rect.top - 12 - 170 > 64
  openedAt = Date.now()
  placeCard.value = { place: p, left, top: above ? rect.top - 12 : rect.bottom + 12, above }
}

// While the map flies, the face is picked but the card waits; it pops up by the face on arrival.
const cardLoose = ref(false)
function closePerson() {
  cardLoose.value = false
  placeCard.value = null
  personId.value = null
  cardAt.value = null
}
const person = computed(() => people.value.find((p) => p.user.id === personId.value) || null)
const sameCity = (a, b) => String(a || '').split(',')[0].trim().toLowerCase() === String(b || '').split(',')[0].trim().toLowerCase()
const peopleHere = computed(() => (city.value ? people.value.filter((p) => sameCity(p.city, city.value)) : people.value))
async function loadPeople() {
  try {
    const res = await api.placeMembers()
    people.value = res.items
    myMap.value = res.me
  } catch {
    people.value = []
  }
  peopleLoading.value = false
}
function toggle(which) {
  view.value = 'places'
  if (which === 'members') showMembers.value = !showMembers.value
  else showPlaces.value = !showPlaces.value
  fitMap()
}
const messaging = ref(false)
async function message(u) {
  if (messaging.value) return
  messaging.value = true
  try {
    const chat = await api.startChat([u.id])
    closePerson() // the chat takes over; the card would only sit on top of it
    if (canDock()) openChat(chat)
    else router.push({ name: 'messages', params: { threadId: chat.id } })
  } catch (err) {
    showToast(err.message)
  } finally {
    messaging.value = false
  }
}

// Show me on the map: type your city, pick it, done. Only the city, never an address.
const meOpen = ref(false)
const meCity = ref('')
const meMatches = ref([])
const meBusy = ref(false)
const meError = ref('')
async function openMe() {
  meOpen.value = true
  meError.value = ''
  meMatches.value = []
  meCity.value = myMap.value?.city || ''
  if (!meCity.value) {
    const m = await api.member(cfg.user.id).catch(() => null)
    meCity.value = m?.location || ''
  }
}
async function findMe() {
  meError.value = ''
  meMatches.value = []
  if (meCity.value.trim().length < 2) return (meError.value = t.__('Type your city first.', 'membuild-community'))
  meBusy.value = true
  try {
    meMatches.value = (await api.geocode(meCity.value.trim())).items
    if (!meMatches.value.length) meError.value = t.__('No match. Try the city and its state or country.', 'membuild-community')
  } catch (err) {
    meError.value = err.message
  } finally {
    meBusy.value = false
  }
}
async function pickMe(m) {
  meBusy.value = true
  try {
    myMap.value = (await api.showMeOnMap({ lat: m.lat, lng: m.lng, city: meCity.value.trim() })).me
    meOpen.value = false
    await loadPeople()
    fitMap()
  } catch (err) {
    meError.value = err.message
  } finally {
    meBusy.value = false
  }
}
async function hideMe() {
  await api.hideMeOnMap()
  myMap.value = null
  meOpen.value = false
  await loadPeople()
}

/* ---------- What's new ---------- */
const news = ref([]) // staff: waiting for a check
const newsOpen = ref(false)
const newsText = ref('')
const newsEnds = ref('')
const newsBusy = ref(false)
const newsError = ref('')
const today = new Date().toISOString().slice(0, 10)
function openNews() {
  newsOpen.value = true
  newsText.value = ''
  newsError.value = ''
  const d = new Date(Date.now() + 14 * 86400000)
  newsEnds.value = d.toISOString().slice(0, 10)
}
async function sendNews() {
  newsBusy.value = true
  newsError.value = ''
  try {
    await api.addPlaceNews(detail.value.id, { text: newsText.value.trim(), ends_on: newsEnds.value })
    newsOpen.value = false
    detail.value = await api.place(detail.value.id)
    load()
  } catch (err) {
    newsError.value = err.message
  } finally {
    newsBusy.value = false
  }
}
async function approveNews(n) {
  await api.approvePlaceNews(n.id)
  news.value = news.value.filter((x) => x.id !== n.id)
  load()
}
async function notNowNews(n) {
  if (!(await askConfirm({ title: t.__('Turn down this What’s new?', 'membuild-community'), text: t.__('It goes away. The member isn’t told.', 'membuild-community'), confirm: t.__('Turn it down', 'membuild-community'), cancel: t.__('Keep it', 'membuild-community') }))) return
  await api.deletePlaceNews(n.id)
  news.value = news.value.filter((x) => x.id !== n.id)
}
async function takeBackNews(n) {
  await api.deletePlaceNews(n.id)
  detail.value = await api.place(detail.value.id)
}
const endsLabel = (d) => new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(new Date(`${d}T12:00`))
const pendingCount = computed(() => pending.value.length + news.value.length)

async function load() {
  try {
    const res = await api.places()
    places.value = res.items
    canEdit.value = res.can_edit
    if (res.can_edit) news.value = (await api.pendingPlaceNews().catch(() => ({ items: [] }))).items
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}
onMounted(() => {
  load()
  loadPeople()
})

const pending = computed(() => places.value.filter((p) => p.status === 'pending'))
const view = ref('places') // 'places' | 'pending'
const city = ref('') // picked from the search: only what's there
// Big communities: the panel draws cards and faces a batch at a time as you scroll,
// so a thousand places don't all land on the page at once (the map still has every pin).
function useMore(step) {
  const n = ref(step)
  const spot = ref(null)
  let io = null
  watch(spot, (el) => {
    io?.disconnect()
    if (!el) return
    io = new IntersectionObserver(([e]) => e.isIntersecting && (n.value += step), { rootMargin: '600px' })
    io.observe(el)
  })
  onBeforeUnmount(() => io?.disconnect())
  return { n, spot }
}
// A place's hours: what's next (open now, or when it opens), in the place's own time zone.
const myZone = Intl.DateTimeFormat().resolvedOptions().timeZone
const opening = computed(() => (detail.value?.week ? openState(detail.value.week, detail.value.tz) : null))
const placeZone = computed(() => (detail.value?.tz && detail.value.tz !== myZone ? zoneLabel(detail.value.tz, cityOfAddress(detail.value.address)) : ''))
// The faces row has no scrollbar: a mouse wheel turned up or down moves it sideways.
function sideways(e) {
  const row = e.currentTarget
  if (row.classList.contains('wrap') || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return
  const max = row.scrollWidth - row.clientWidth
  if (max <= 0 || (e.deltaY < 0 && row.scrollLeft <= 0) || (e.deltaY > 0 && row.scrollLeft >= max)) return // at an end: the panel scrolls
  e.preventDefault()
  row.scrollLeft += e.deltaY
}
// How much of the map the list box covers (for framing pins clear of it).
const panelCover = () => (panelOpen.value ? Math.min(panelEl.value?.offsetHeight || 0, 420) : 0)
// The page is kept while you look at a profile or a post, so Back lands where you were in the
// list. Away: close any card and remember how far down the list you were. Back: put the list
// where it was, measure the map again, and if you were gone a while, quietly fetch what changed.
let leftAt = 0
let panelY = 0 // kept as you scroll: by the time the page is put away, the box has lost it
onDeactivated(() => {
  closePerson()
  searchOpen.value = false
  leftAt = Date.now()
})
onActivated(() => {
  if (!leftAt) return // the first showing is onMounted's
  nextTick(() => panelEl.value && (panelEl.value.scrollTop = panelY))
  mapRef.value?.wake()
  if (Date.now() - leftAt > 60000) {
    load()
    loadPeople()
  }
})
const morePlaces = useMore(24)
const moreFaces = useMore(40)
const searchOpen = ref(false)
// Up and down pick a result, Enter opens it: no mouse needed.
const dropEl = ref(null)
const hl = ref(-1)
watch(query, () => (hl.value = -1))
function moveHl(step) {
  const rows = dropEl.value?.querySelectorAll('button') || []
  if (!rows.length) return
  searchOpen.value = true
  hl.value = (hl.value + step + rows.length + (hl.value < 0 && step < 0 ? 1 : 0)) % rows.length
  rows[hl.value]?.scrollIntoView({ block: 'nearest' })
}
function pickHl() {
  const rows = dropEl.value?.querySelectorAll('button') || []
  rows[hl.value < 0 ? 0 : hl.value]?.click()
}
const panelOpen = ref(true)
// "Queen St W, Toronto, ON" → "Toronto, ON": the city a place is in.
const cityOf = (p) => {
  const parts = String(p.address || '').split(',').map((x) => x.trim()).filter(Boolean)
  return parts.length > 2 ? parts.slice(-2).join(', ') : parts.length === 2 ? parts.join(', ') : parts[0] || ''
}
const published = computed(() => places.value.filter((p) => p.status === 'published' || p.mine))
const inCity = computed(() => (city.value ? published.value.filter((p) => cityOf(p) === city.value) : published.value))
const onMap = computed(() => (view.value === 'pending' ? pending.value : inCity.value))
// As you type: matching cities first, then places.
const results = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (q.length < 2 || city.value) return null
  const cities = new Map()
  for (const p of published.value) {
    const c = cityOf(p)
    if (c && c.toLowerCase().includes(q)) cities.set(c, { places: (cities.get(c)?.places || 0) + 1, members: cities.get(c)?.members || 0 })
  }
  for (const m of people.value) {
    if (!m.city || !m.city.toLowerCase().includes(q)) continue
    const key = [...cities.keys()].find((c) => sameCity(c, m.city)) || m.city
    cities.set(key, { places: cities.get(key)?.places || 0, members: (cities.get(key)?.members || 0) + 1 })
  }
  return {
    cities: [...cities].slice(0, 3).map(([name, n]) => ({ name, ...n })),
    members: people.value.filter((m) => `${m.user.name} ${m.city}`.toLowerCase().includes(q)).slice(0, 4),
    places: published.value.filter((p) => `${p.name} ${p.address}`.toLowerCase().includes(q)).slice(0, 5),
  }
})
// The matching letters, underlined.
function marked(text) {
  const q = query.value.trim()
  const i = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1
  return i < 0 ? [{ t: text }] : [{ t: text.slice(0, i) }, { t: text.slice(i, i + q.length), m: true }, { t: text.slice(i + q.length) }]
}
const panelEl = ref(null)
function fitMap() {
  nextTick(() => mapRef.value?.fit(panelOpen.value && !detail.value ? panelCover() : 0, detail.value ? 0 : 110)) // 110: clear of the search bar, faces stand above their spot
}
function pickCity(c) {
  city.value = c
  query.value = c
  searchOpen.value = false
  view.value = 'places'
  fitMap()
}
function clearSearch() {
  query.value = ''
  city.value = ''
  fitMap()
}
watch(view, fitMap)
// Opening the page: once the places and members are in, frame the busiest part of the map
// clear of the panel (until the member moves the map themselves).
const moved = ref(false)
watch([() => places.value.length, () => people.value.length], () => !moved.value && !detail.value && fitMap())
// The kind of place as a flat icon, like the board (no emoji).
const kindIcon = (k) => ({ perk: 'gift', meetup: 'users', venue: 'mapPin' })[k] || 'star'
const cityCount = (c) =>
  [t.__('A city', 'membuild-community'), c.members ? t.sprintf(/* translators: %d: members */ t._n('%d member', '%d members', c.members, 'membuild-community'), c.members) : '', c.places ? t.sprintf(/* translators: %d: places */ t._n('%d place', '%d places', c.places, 'membuild-community'), c.places) : '']
    .filter(Boolean)
    .join(' · ')
const ago = (iso) => {
  const d = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 86400000))
  return d < 1 ? t.__('today', 'membuild-community') : t.sprintf(/* translators: %d: days */ t._n('%d day ago', '%d days ago', d, 'membuild-community'), d)
}
async function notNow(place) {
  if (!(await askConfirm({ title: t.sprintf(/* translators: %s: place name */ t.__('Turn down “%s”?', 'membuild-community'), place.name), text: t.__('The suggestion goes away. The member isn’t told.', 'membuild-community'), confirm: t.__('Turn it down', 'membuild-community'), cancel: t.__('Keep it', 'membuild-community') }))) return
  await api.deletePlace(place.id)
  places.value = places.value.filter((p) => p.id !== place.id)
}
const shown = computed(() => {
  const q = query.value.trim().toLowerCase()
  let list = places.value.filter((p) => p.status === 'published' || p.mine || canEdit.value)
  if (kind.value !== 'all') list = list.filter((p) => p.category === kind.value)
  if (q) list = list.filter((p) => `${p.name} ${p.address} ${p.perk}`.toLowerCase().includes(q))
  if (me.value) {
    list = list.map((p) => ({ ...p, km: distanceKm(me.value, p) })).sort((a, b) => a.km - b.km)
  }
  return list
})
const kinds = computed(() => ['all', ...Object.keys(CATEGORY).filter((k) => places.value.some((p) => p.category === k))])

// Opening a place from further down the list, then going back: the list is where you left it.
let listY = 0
watch(
  () => props.placeId,
  (id, old) => {
    if (id && !old) listY = panelEl.value?.scrollTop || 0
    else if (!id && old && listY) nextTick(() => requestAnimationFrame(() => panelEl.value && (panelEl.value.scrollTop = listY)))
  },
  { flush: 'pre' },
)

// The open place: from the list right away, then with its members.
watch(
  () => props.placeId,
  async (id) => {
    detail.value = id ? places.value.find((p) => p.id === id) || null : null
    if (!id) return
    try {
      const full = await api.place(id)
      if (props.placeId === id) detail.value = full
    } catch {
      router.replace('/places')
    }
  },
  { immediate: true },
)
const open = (id) => router.push({ name: 'places', params: { placeId: id } })
const back = () => router.push('/places')

function nearMe() {
  if (me.value) return (me.value = null)
  if (!navigator.geolocation) return
  locating.value = true
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      me.value = { lat: pos.coords.latitude, lng: pos.coords.longitude }
      locating.value = false
    },
    () => {
      locating.value = false
      error.value = t.__('Your browser didn’t share a location. You can still browse the map.', 'membuild-community')
    },
    { maximumAge: 10 * 60 * 1000, timeout: 10000 },
  )
}

function replace(place) {
  const n = places.value.findIndex((p) => p.id === place.id)
  if (n >= 0) places.value.splice(n, 1, { ...places.value[n], ...place })
  else places.value.push(place)
  if (detail.value?.id === place.id) detail.value = { ...detail.value, ...place }
}

async function toggleGoing(place) {
  if (busy.value) return
  busy.value = true
  try {
    const res = place.going ? await api.notGoing(place.id) : await api.going(place.id)
    replace(res)
    if (detail.value?.id === place.id) detail.value = await api.place(place.id)
  } finally {
    busy.value = false
  }
}
async function approve(place) {
  replace(await api.updatePlace(place.id, { status: 'published' }))
}
async function remove(place) {
  if (!(await askConfirm({ title: t.sprintf(/* translators: %s: place name */ t.__('Remove “%s” from Places?', 'membuild-community'), place.name), confirm: t.__('Remove', 'membuild-community'), danger: true }))) return
  await api.deletePlace(place.id)
  places.value = places.value.filter((p) => p.id !== place.id)
  if (detail.value?.id === place.id) back()
}
function saved(place) {
  form.value = null
  replace(place)
  open(place.id)
}

function toggleMap() {
  showMap.value = !showMap.value
  mapRef.value?.resize()
}
// The map changes size when a place opens or closes; let it measure again.
watch(() => !!detail.value, () => nextTick(() => mapRef.value?.resize()))

const goingLine = (p) =>
  p.going_count
    ? t.sprintf(/* translators: %s: number of members */ t._n('%s member goes here', '%s members go here', p.going_count, 'membuild-community'), p.going_count.toLocaleString())
    : ''
const host = (url) => {
  try {
    return new URL(url).host.replace(/^www\./, '')
  } catch {
    return url
  }
}
const suggestedBy = (p) => t.sprintf(/* translators: %s: member name */ t.__('Suggested by %s', 'membuild-community'), p.suggested_by?.name || '')
const tapped = computed(() => (showMap.value && props.placeId ? places.value.find((p) => p.id === props.placeId) : null))
</script>

<template>
  <PlaceForm v-if="form" :place="form.place" :can-edit="canEdit" @close="form = null" @saved="saved" />

  <div v-else class="places" :class="{ 'has-detail': !!detail || !!placeId }">
    <!-- One search and the toggles, over the map. -->
    <div v-if="!detail && !placeId" class="bar card">
      <div class="search" :class="{ on: searchOpen && results }">
        <Icon name="search" :size="20" />
        <input v-model="query" type="text" enterkeyhint="search" :placeholder="t.__('Search places or a city', 'membuild-community')" :aria-label="t.__('Search places or a city', 'membuild-community')" @focus="searchOpen = true; closePerson()" @blur="searchOpen = false" @input="city = ''; searchOpen = true; closePerson()" @keydown.esc="searchOpen = false" @keydown.down.prevent="moveHl(1)" @keydown.up.prevent="moveHl(-1)" @keydown.enter.prevent="pickHl" />
        <button v-if="query" class="x" :aria-label="t.__('Clear', 'membuild-community')" @click="clearSearch"><Icon name="close" :size="16" /></button>
        <!-- Results as you type: cities first, then places. -->
        <div v-if="searchOpen && results && (results.cities.length || results.places.length || results.members.length)" ref="dropEl" class="drop card" @mousedown.prevent @mousemove="hl = -1">
          <button v-for="(c, i) in results.cities" :key="c.name" class="city" :class="{ hl: hl === i }" @click="pickCity(c.name)">
            <span class="pin"><Icon name="mapPin" :size="22" /></span>
            <span class="txt"><strong><template v-for="(m, i) in marked(c.name)" :key="i"><u v-if="m.m">{{ m.t }}</u><template v-else>{{ m.t }}</template></template></strong><small>{{ cityCount(c) }}</small></span>
            <span class="show">{{ t.__('Show on map', 'membuild-community') }} <Icon name="chevronRight" :size="16" /></span>
          </button>
          <p v-if="results.members.length" class="group">{{ t.__('Members', 'membuild-community') }}</p>
          <button v-for="(m, i) in results.members" :key="m.user.id" class="row" :class="{ hl: hl === results.cities.length + i }" @click="searchOpen = false; flyToPerson(m.user.id)">
            <Avatar :user="m.user" :size="44" />
            <span class="txt"><strong><template v-for="(x, i) in marked(m.user.name)" :key="i"><u v-if="x.m">{{ x.t }}</u><template v-else>{{ x.t }}</template></template></strong><small>{{ t.sprintf(/* translators: %s: a city */ t.__('Lives in %s', 'membuild-community'), m.city) }}</small></span>
          </button>
          <p v-if="results.places.length" class="group">{{ t.__('Places', 'membuild-community') }}</p>
          <button v-for="(p, i) in results.places" :key="p.id" class="row" :class="{ hl: hl === results.cities.length + results.members.length + i }" @click="searchOpen = false; open(p.id)">
            <span class="thumb"><img v-if="p.cover" :src="p.cover" alt="" /><MapThumb v-else :lat="p.lat" :lng="p.lng" :color="CATEGORY[p.category]?.color" :emoji="CATEGORY[p.category]?.emoji" /></span>
            <span class="txt"><strong><template v-for="(m, i) in marked(p.name)" :key="i"><u v-if="m.m">{{ m.t }}</u><template v-else>{{ m.t }}</template></template></strong><small>{{ cityOf(p) }}</small></span>
          </button>
        </div>
      </div>
      <button class="tog filter-pill" :class="{ on: view === 'places' && showMembers }" :aria-pressed="view === 'places' && showMembers" @click="toggle('members')"><Icon name="users" :size="18" /> {{ t.__('Members', 'membuild-community') }} <Icon v-if="view === 'places' && showMembers" name="check" :size="16" /></button>
      <button class="tog filter-pill" :class="{ on: view === 'places' && showPlaces }" :aria-pressed="view === 'places' && showPlaces" @click="toggle('places')"><Icon name="mapPin" :size="18" /> {{ t.__('Places', 'membuild-community') }} <Icon v-if="view === 'places' && showPlaces" name="check" :size="16" /></button>
      <!-- Pending only shows when something is waiting (and stays while you look at it, so you can switch back). -->
      <button v-if="canEdit && (pendingCount || view === 'pending')" class="tog" :class="{ on: view === 'pending' }" :aria-pressed="view === 'pending'" @click="view = view === 'pending' ? 'places' : 'pending'; fitMap()">{{ t.__('Pending', 'membuild-community') }} <span v-if="pendingCount" class="count">{{ pendingCount }}</span></button>
    </div>
    <section class="map-pane" @pointerdown="moved = true" @wheel.passive="moved = true">
      <PlacesMap ref="mapRef" :picked="placeCard?.place.id || null" :places="detail ? published : placesOn ? onMap : []" :selected="placeId" :me="me" :people="detail || !membersOn ? [] : peopleHere" :person="personId" @select="open" @person="onFace" @pan="scrolledAway" />


    </section>


    <!-- Tapping a member's face: a small card. -->
    <div v-if="person && (cardAt || cardLoose) && !detail" :key="personId" class="pcard card" :class="{ placed: cardAt, above: cardAt?.above }" :style="cardAt ? { left: `${cardAt.left}px`, top: `${cardAt.top}px`, transform: cardAt.above ? 'translateY(-100%)' : null } : null">
      <button class="pcard-x" :aria-label="t.__('Close', 'membuild-community')" @click="closePerson"><Icon name="close" :size="16" /></button>
      <div class="pcard-top">
        <Avatar :user="person.user" :size="60" online />
        <span>
          <strong>{{ person.user.name }}</strong>
          <small v-if="person.city">{{ person.city }}</small>
          <small>{{ levelLabel(person.user.level) }}</small>
        </span>
      </div>
      <p v-if="person.bio" class="pcard-bio">{{ person.bio }}</p>
      <div class="pcard-act">
        <button v-if="person.user.id !== cfg.user.id" class="btn btn-lg btn-primary" :disabled="messaging" @click="message(person.user)"><Icon name="chat" :size="18" /> {{ t.__('Message', 'membuild-community') }}</button>
        <RouterLink :to="{ name: 'member', params: { id: person.user.id } }" class="btn btn-lg btn-ghost">{{ t.__('View profile', 'membuild-community') }}</RouterLink>
      </div>
    </div>

    <!-- A place picked in the panel: a small card by its pin. -->
    <div v-if="placeCard && !detail" :key="placeCard.place.id" class="plcard card" :class="{ above: placeCard.above }" :style="{ left: `${placeCard.left}px`, top: `${placeCard.top}px`, transform: placeCard.above ? 'translateY(-100%)' : null }">
      <button class="pcard-x" :aria-label="t.__('Close', 'membuild-community')" @click="closePerson"><Icon name="close" :size="16" /></button>
      <strong>{{ placeCard.place.name }}</strong>
      <small class="city"><Icon name="mapPin" :size="15" /> {{ placeCard.place.address }}</small>
      <span v-if="placeCard.place.news" class="newtag">✦ {{ placeCard.place.news.text }}</span>
      <div class="pcard-act">
        <button class="btn btn-lg btn-primary" @click="open(placeCard.place.id)">{{ t.__('Open place', 'membuild-community') }}</button>
        <a class="btn btn-lg btn-ghost" :href="directionsUrl(placeCard.place)" target="_blank" rel="noopener"><Icon name="send" :size="18" /> {{ t.__('Directions', 'membuild-community') }}</a>
      </div>
    </div>

    <!-- The panel under the map: place cards, or the suggestions to review. -->
    <section v-if="!detail && !placeId" ref="panelEl" class="panel card" @scroll.passive="scrolledAway(); panelY = $event.target.scrollTop">
      <button class="grab" :aria-label="panelOpen ? t.__('Make the map bigger', 'membuild-community') : t.__('Show the list', 'membuild-community')" @click="panelOpen = !panelOpen; fitMap()"><span /></button>
      <template v-if="view === 'pending'">
        <header class="ph">
          <h2>{{ t.__('Waiting for your review', 'membuild-community') }} · {{ pendingCount }}</h2>
          <span class="note">{{ t.__('Oldest first. Nothing shows to members until it’s approved.', 'membuild-community') }}</span>
        </header>
        <div v-if="panelOpen" class="grid">
          <article v-for="p in [...pending].reverse()" :key="p.id" class="pc">
            <span class="pc-cover"><img v-if="p.cover" :src="p.cover" alt="" /><MapThumb v-else :lat="p.lat" :lng="p.lng" :color="CATEGORY[p.category]?.color" :emoji="CATEGORY[p.category]?.emoji" /><span class="tag">{{ t.__('New place', 'membuild-community') }}</span></span>
            <span class="pc-body">
              <strong>{{ p.name }}</strong>
              <small>{{ [cityOf(p), p.suggested_by?.name, p.created_at ? ago(p.created_at) : ''].filter(Boolean).join(' · ') }}</small>
              <span v-if="p.description" class="quote">“{{ p.description }}”</span>
            </span>
            <span class="pc-act">
              <button class="btn btn-lg btn-primary" @click="approve(p)">{{ t.__('Approve', 'membuild-community') }}</button>
              <button class="btn btn-lg btn-ghost" @click="notNow(p)">{{ t.__('Not now', 'membuild-community') }}</button>
            </span>
          </article>
          <article v-for="n in news" :key="`n${n.id}`" class="pc">
            <span class="pc-cover"><img v-if="n.place?.cover" :src="n.place.cover" alt="" /><MapThumb v-else-if="n.place" :lat="n.place.lat" :lng="n.place.lng" :color="CATEGORY[n.place.category]?.color" :emoji="CATEGORY[n.place.category]?.emoji" /><span class="tag">{{ t.__('What’s new', 'membuild-community') }}</span></span>
            <span class="pc-body">
              <strong>{{ n.place?.name }}</strong>
              <small>{{ [n.by?.name, t.sprintf(/* translators: %s: a date */ t.__('until %s', 'membuild-community'), endsLabel(n.ends_on))].filter(Boolean).join(' · ') }}</small>
              <span class="quote">“{{ n.text }}”</span>
            </span>
            <span class="pc-act">
              <button class="btn btn-lg btn-primary" @click="approveNews(n)">{{ t.__('Approve', 'membuild-community') }}</button>
              <button class="btn btn-lg btn-ghost" @click="notNowNews(n)">{{ t.__('Not now', 'membuild-community') }}</button>
            </span>
          </article>
          <p v-if="!pendingCount" class="empty-note">{{ t.__('Nothing waiting. Suggestions from members show up here.', 'membuild-community') }}</p>
        </div>
      </template>
      <template v-else>
        <template v-if="membersOn">
          <header class="ph">
            <h2>{{ t.__('Members', 'membuild-community') }}<template v-if="!peopleLoading"> · {{ peopleHere.length }}</template></h2>
            <button class="link" @click="openMe"><span class="lg">{{ myMap ? t.__('You’re on the map · Change', 'membuild-community') : t.__('Show me on the map', 'membuild-community') }}</span><span class="sm">{{ myMap ? t.__('Change', 'membuild-community') : t.__('Show me', 'membuild-community') }}</span></button>
          </header>
          <div v-if="panelOpen" class="faces-row" :class="{ wrap: !placesOn }" @scroll.passive="scrolledAway" @wheel="sideways">
            <button v-for="m in peopleHere.slice(0, moreFaces.n.value)" :key="m.user.id" class="mface" :class="{ on: personId === m.user.id }" @click="flyToPerson(m.user.id)">
              <Avatar :user="m.user" :size="76" online />
              <strong>{{ m.user.name.split(' ')[0] }}</strong>
              <small>{{ m.city.split(',')[0] }}</small>
            </button>
            <span v-if="peopleHere.length > moreFaces.n.value" :key="moreFaces.n.value" :ref="(el) => (moreFaces.spot.value = el)" class="more-spot" aria-hidden="true" />
            <template v-if="peopleLoading"><span v-for="n in 8" :key="`sk${n}`" class="mface mface-sk" aria-hidden="true"><span class="skeleton" /><i class="skeleton" /><i class="skeleton" /></span></template>
            <p v-else-if="!peopleHere.length" class="empty-note">{{ t.__('Nobody’s on the map yet. Be the first: Show me on the map.', 'membuild-community') }}</p>
          </div>
        </template>
        <template v-if="placesOn">
        <header class="ph" :class="{ second: membersOn }">
          <h2 v-if="city">{{ t.sprintf(/* translators: %s: a city */ t.__('In %s', 'membuild-community'), city) }} · {{ inCity.length }}</h2>
          <h2 v-else>{{ t.__('Places', 'membuild-community') }}<template v-if="!loading"> · {{ inCity.length }}</template></h2>
          <button v-if="city" class="link" @click="clearSearch">{{ t.__('Show everywhere', 'membuild-community') }}</button>
          <button v-else class="link" @click="form = { place: null }"><Icon name="plus" :size="16" /> {{ canEdit ? t.__('Add a place', 'membuild-community') : t.__('Suggest a place', 'membuild-community') }}</button>
        </header>
        <div v-if="panelOpen" class="grid">
          <template v-if="loading"><span v-for="n in 6" :key="n" class="pc pc-sk" aria-hidden="true"><span class="skeleton pc-cover" /><span class="pc-body"><i class="skeleton" style="width: 70%" /><i class="skeleton" style="width: 45%" /></span></span></template>
          <!-- Photo (a small map when there's none), name, the city with a pin, What's new. -->
          <button v-for="(p, i) in inCity.slice(0, morePlaces.n.value)" :key="p.id" class="pc lift rise-in" :style="{ '--i': i % 24 }" :class="{ pending: p.status === 'pending', on: placeCard?.place.id === p.id }" @click="flyToPlace(p)">
            <span class="pc-cover"><img v-if="p.cover" :src="p.cover" alt="" loading="lazy" /><MapThumb v-else :lat="p.lat" :lng="p.lng" :color="CATEGORY[p.category]?.color" :emoji="CATEGORY[p.category]?.emoji" /></span>
            <span class="pc-body">
              <strong>{{ p.name }}</strong>
              <small class="city"><Icon name="mapPin" :size="15" /> {{ cityOf(p) }}</small>
              <span v-if="p.news" class="newtag">✦ {{ p.news.text }}</span>
              <span v-if="p.access" class="locktag"><Icon name="lock" :size="13" /> {{ p.access.name }}</span>
              <span v-if="p.status === 'pending'" class="wait">{{ t.__('Waiting for review', 'membuild-community') }}</span>
            </span>
          </button>
          <span v-if="inCity.length > morePlaces.n.value" :key="morePlaces.n.value" :ref="(el) => (morePlaces.spot.value = el)" class="more-spot" aria-hidden="true" />
          <div v-if="!loading && !inCity.length" class="empty-note">
            <template v-if="places.length">{{ t.__('No places here yet.', 'membuild-community') }}</template>
            <template v-else>{{ canEdit ? t.__('Add the spots where members meet, partners with a member perk, or venues for your events.', 'membuild-community') : t.__('Know a good spot for members? Suggest it.', 'membuild-community') }}</template>
          </div>
        </div>
        </template>
      </template>
      <p v-if="error" class="error-text" role="status">{{ error }}</p>
    </section>

    <!-- One place, in a column on the left; the map beside it. -->
    <!-- Opening a place: its column in grey while it loads. -->
    <aside v-if="!detail && placeId" class="detail" aria-hidden="true">
      <div class="skeleton" style="width: 100px; height: 16px; margin: 12px 0 6px" />
      <div class="skeleton" style="width: 100%; height: 200px; border-radius: 14px" />
      <div class="skeleton" style="width: 70%; height: 26px; margin-top: 6px" />
      <div class="skeleton" style="width: 40%; height: 14px" />
      <div class="sk-btns"><div class="skeleton" /><div class="skeleton" /></div>
      <div v-for="n in 4" :key="n" class="skeleton" style="height: 14px" :style="{ width: `${92 - n * 9}%` }" />
    </aside>
    <aside v-if="detail" :key="detail.id" class="detail page-in">
      <button class="back-link" @click="back"><Icon name="chevronLeft" :size="20" /> {{ t.__('All places', 'membuild-community') }}</button>
      <div class="hero">
        <img v-if="detail.cover" :src="detail.cover" alt="" />
        <MapThumb v-else :lat="detail.lat" :lng="detail.lng" :zoom="16" :color="CATEGORY[detail.category]?.color" :emoji="CATEGORY[detail.category]?.emoji" />
      </div>
      <h1>{{ detail.name }}</h1>
      <span v-if="detail.access" class="locktag"><Icon name="lock" :size="13" /> {{ t.sprintf(/* translators: %s: a membership */ t.__('Only %s', 'membuild-community'), detail.access.name) }}</span>
      <!-- The kind of place; a perk's own line already says "for members", so not twice. -->
      <p v-if="!detail.perk" class="kind"><Icon :name="kindIcon(detail.category)" :size="17" /> {{ CATEGORY[detail.category]?.label }}<template v-if="me"> · {{ distanceLabel(distanceKm(me, detail)) }}</template></p>
      <span v-if="detail.status === 'pending'" class="wait-tag">{{ t.__('Waiting for review', 'membuild-community') }}</span>
      <span v-if="detail.perk" class="perk big"><Icon name="gift" :size="16" /> {{ detail.perk }}</span>

      <!-- What's new: members share it, staff check it, it goes after its date. -->
      <div v-for="n in detail.news || []" :key="n.id" class="news">
        <span class="news-h">✦ {{ t.__('What’s new', 'membuild-community') }}<template v-if="n.status === 'pending'"> · {{ t.__('waiting for a check', 'membuild-community') }}</template></span>
        <strong>{{ n.text }}</strong>
        <small>{{ t.sprintf(/* translators: 1: member name, 2: a date */ t.__('Shared by %1$s · disappears after %2$s', 'membuild-community'), n.by?.name || '', endsLabel(n.ends_on)) }}</small>
        <button v-if="n.mine && n.status === 'pending'" class="link danger" @click="takeBackNews(n)">{{ t.__('Take it back', 'membuild-community') }}</button>
      </div>
      <button v-if="detail.status === 'published'" class="share-new" @click="openNews">✦ {{ t.__('Share what’s new here', 'membuild-community') }}</button>

      <div class="buttons">
        <button v-if="detail.status === 'published'" class="btn btn-lg" :class="detail.going ? 'btn-ghost gone' : 'btn-primary'" :disabled="busy" :aria-pressed="detail.going" @click="toggleGoing(detail)">
          <Icon :key="detail.going ? 'on' : 'off'" :class="{ 'pop-once': detail.going }" :name="detail.going ? 'check' : 'mapPin'" :size="18" /> {{ detail.going ? t.__('You go here', 'membuild-community') : t.__('I go here', 'membuild-community') }}
        </button>
        <a class="btn btn-lg btn-ghost" :href="directionsUrl(detail)" target="_blank" rel="noopener"><Icon name="send" :size="18" /> {{ t.__('Directions', 'membuild-community') }}</a>
      </div>

      <ul class="facts">
        <li v-if="detail.address"><Icon name="mapPin" :size="18" /> {{ detail.address }}</li>
        <li v-if="detail.week" class="hours-li">
          <Icon name="clock" :size="18" />
          <span class="hours">
            <strong v-if="opening" :class="{ open: opening.open }">{{ opening.text }}</strong>
            <span class="hours-week">
              <template v-for="d in weekLines(detail.week)" :key="d.key"><span>{{ d.name }}</span><span>{{ d.spans.join(', ') }}</span></template>
              <template v-if="weekLines(detail.week).length < 7"><span class="dim">{{ t.__('Other days', 'membuild-community') }}</span><span class="dim">{{ t.__('Closed', 'membuild-community') }}</span></template>
            </span>
            <small v-if="placeZone">{{ placeZone }}</small>
          </span>
        </li>
        <li v-else-if="detail.hours"><Icon name="clock" :size="18" /> {{ detail.hours }}</li>
        <li v-if="detail.website"><Icon name="external" :size="18" /> <a :href="detail.website" target="_blank" rel="noopener">{{ host(detail.website) }}</a></li>
        <li v-if="detail.phone"><Icon name="chat" :size="18" /> <a :href="telHref(detail.phone, detail.phone_country)">{{ detail.phone }}</a></li>
      </ul>
      <p v-if="detail.description" class="about">{{ detail.description }}</p>

      <section v-if="detail.events?.length" class="who">
        <h2>{{ t.__('Coming up here', 'membuild-community') }}</h2>
        <RouterLink v-for="ev in detail.events" :key="ev.id" :to="{ name: 'event', params: { id: ev.id } }" class="up">
          <span class="up-date"><small>{{ dateBadge(ev.event).month }}</small><strong>{{ dateBadge(ev.event).day }}</strong></span>
          <span class="up-txt"><strong>{{ ev.event.title }}</strong><small>{{ dayShort(ev.event) }} · {{ startTime(ev.event) }} · {{ t.__('an event', 'membuild-community') }}</small></span>
          <Icon name="chevronRight" :size="18" />
        </RouterLink>
      </section>
      <section v-if="detail.members?.length" class="who">
        <h2>{{ t.__('Members who go here', 'membuild-community') }}</h2>
        <div class="faces">
          <RouterLink v-for="u in detail.members" :key="u.id" :to="{ name: 'member', params: { id: u.id } }" :title="u.name"><Avatar :user="u" :size="44" online /></RouterLink>
        </div>
      </section>

      <div v-if="canEdit" class="staff">
        <button v-if="detail.status === 'pending'" class="btn btn-lg btn-primary" @click="approve(detail)">{{ t.__('Approve', 'membuild-community') }}</button>
        <button class="btn btn-lg btn-ghost" @click="form = { place: detail }"><Icon name="pencil" :size="18" /> {{ t.__('Edit', 'membuild-community') }}</button>
        <button class="link danger" @click="remove(detail)">{{ t.__('Remove…', 'membuild-community') }}</button>
      </div>
      <div v-else-if="detail.mine && detail.status === 'pending'" class="staff">
        <button class="link danger" @click="remove(detail)">{{ t.__('Withdraw suggestion', 'membuild-community') }}</button>
      </div>
    </aside>

    <BaseDialog v-if="meOpen" :title="t.__('Show me on the map', 'membuild-community')" :width="480" @close="meOpen = false">
      <div class="dlg">
        <p class="dlg-note">{{ t.__('Other members see your face at your city, never your address. You can take it off any time.', 'membuild-community') }}</p>
        <label class="dlg-field">
          <span>{{ t.__('Your city', 'membuild-community') }}</span>
          <div class="find"><input v-model="meCity" type="text" maxlength="80" :placeholder="t.__('Austin, TX', 'membuild-community')" @keydown.enter.prevent="findMe" /><button class="btn btn-lg btn-ghost" :disabled="meBusy" @click="findMe"><Icon name="search" :size="18" /> {{ t.__('Find', 'membuild-community') }}</button></div>
        </label>
        <ul v-if="meMatches.length" class="dlg-list">
          <li v-for="(m, i) in meMatches" :key="i"><button :disabled="meBusy" @click="pickMe(m)"><Icon name="mapPin" :size="16" /> {{ m.label }}</button></li>
        </ul>
        <p v-if="meError" class="error-text">{{ meError }}</p>
        <div class="dlg-act">
          <button v-if="myMap" class="link danger" @click="hideMe">{{ t.__('Take me off the map', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-ghost" @click="meOpen = false">{{ t.__('Cancel', 'membuild-community') }}</button>
        </div>
      </div>
    </BaseDialog>

    <BaseDialog v-if="newsOpen" :title="t.__('Share what’s new here', 'membuild-community')" :width="480" @close="newsOpen = false">
      <form class="dlg" @submit.prevent="sendNews">
        <p class="dlg-note">{{ canEdit ? t.__('It shows on this place and on the map until the day you pick.', 'membuild-community') : t.__('It shows on this place and on the map until the day you pick. An admin or moderator checks it first.', 'membuild-community') }}</p>
        <label class="dlg-field"><span>{{ t.__('What’s new?', 'membuild-community') }}</span><input v-model="newsText" type="text" maxlength="200" required :placeholder="t.__('20% off for members until Nov 30', 'membuild-community')" /></label>
        <label class="dlg-field"><span>{{ t.__('Ends on', 'membuild-community') }}</span><input v-model="newsEnds" type="date" :min="today" required /></label>
        <p v-if="newsError" class="error-text">{{ newsError }}</p>
        <div class="dlg-act">
          <button type="button" class="btn btn-lg btn-ghost" @click="newsOpen = false">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-primary" :disabled="newsBusy || !newsText.trim()">{{ canEdit ? t.__('Share it', 'membuild-community') : t.__('Send for a check', 'membuild-community') }}</button>
        </div>
      </form>
    </BaseDialog>
  </div>
</template>

<style scoped>
.places {
  --side: 24px; /* the list box's side gap */
  position: relative;
  height: calc(100dvh - var(--header-h) - env(safe-area-inset-top));
  overflow: hidden;
}

.map-pane {
  position: absolute;
  inset: 0;
  z-index: 0; /* the map's own layers stay inside, so the chat dock floats above the panel */
}

.has-detail .map-pane {
  left: 440px;
}

/* The search and toggles float at the top, in the middle. */
.bar {
  position: absolute;
  top: 16px;
  left: 50%;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 8px;
  width: min(720px, calc(100% - 32px));
  padding: 8px;
  border-radius: 999px;
  transform: translateX(-50%);
  box-shadow: 0 8px 28px rgb(0 0 0 / 14%);
}

.search {
  position: static; /* the results drop down across the whole bar, not just the box */
  display: flex;
  flex: 1;
  align-items: center;
  gap: 10px;
  min-width: 0;
  height: 52px;
  padding: 0 16px;
  border: 2px solid transparent;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
}

.search:focus-within {
  border-color: var(--accent-text);
  background: var(--surface);
}

.search input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-size: 17px;
  outline: none;
}

.search .x {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  color: var(--text-2);
}

.count {
  display: inline-grid;
  place-items: center;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 999px;
  background: #e5484d;
  color: #fff;
  font-size: 12px;
  font-weight: 800;
}

.drop {
  animation: drop-in 0.16s ease-out;
  position: absolute;
  top: calc(100% + 10px);
  left: 8px;
  right: 8px;
  z-index: 2;
  display: flex;
  flex-direction: column;
  padding: 8px;
  border-radius: 18px;
  box-shadow: 0 12px 32px rgb(0 0 0 / 16%);
}

.drop .city,
.drop .row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 12px;
  border-radius: 12px;
  color: var(--text);
  text-align: left;
}

.drop .city {
  background: var(--accent-soft);
}

.drop .row:hover,
.drop .city:hover,
.drop .hl {
  filter: brightness(0.97);
  background: var(--surface-2);
}

.drop .pin {
  display: grid;
  flex: none;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: var(--accent);
  color: #16181d;
}

.drop .txt {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.drop .txt strong {
  font-size: 17px;
}

.drop .txt small {
  color: var(--text-2);
  font-size: 14px;
}

.drop .show {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font-weight: 700;
}

.drop .group {
  margin: 10px 12px 4px;
  color: var(--text-2);
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.drop .thumb {
  flex: none;
  width: 44px;
  height: 44px;
  overflow: hidden;
  border-radius: 10px;
}

.drop .thumb > img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* The panel under the map. */
.panel {
  position: absolute;
  right: var(--side);
  bottom: 20px;
  left: var(--side);
  z-index: 2;
  max-height: 48%;
  padding: 6px 20px 18px;
  overflow-y: auto;
  border-radius: 22px;
  box-shadow: 0 -4px 28px rgb(0 0 0 / 12%);
  scrollbar-width: none; /* no bar cutting into the round corners; it still scrolls */
}

.panel::-webkit-scrollbar,
.faces-row::-webkit-scrollbar {
  display: none;
}

.grab {
  display: block;
  width: 100%;
  padding: 8px 0 10px;
}

.grab span {
  display: block;
  width: 44px;
  height: 5px;
  margin: 0 auto;
  border-radius: 999px;
  background: var(--border-strong);
}

.ph {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.ph h2 {
  margin: 0;
  font-size: 18px;
}

.ph .link {
  /* the panel's actions: grey pill buttons, like the other secondary buttons */
  height: 44px;
  padding: 0 18px;
  border-radius: 999px;
  background: var(--surface-2);
  white-space: nowrap;
}

.ph .link .sm {
  display: none;
}

.ph .link:hover {
  background: var(--border);
}

.ph {
  align-items: center;
}

.ph .note {
  color: var(--text-2);
  font-size: 14px;
}

.link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--text);
  font-size: 16px;
  font-weight: 700;
}

.link.danger {
  color: var(--danger);
  text-decoration: underline;
}

.grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr)); /* three to a row on computers, two on phones */
  gap: 14px;
}

.pc {
  display: flex;
  flex-direction: column;
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--surface);
  color: var(--text);
  text-align: left;
}

button.pc:hover {
  border-color: var(--border-strong);
}

.pc-cover {
  position: relative;
  display: block;
  width: 100%;
  height: 104px;
  overflow: hidden;
  background: var(--accent-soft);
}

.pc-cover > img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.tag {
  position: absolute;
  top: 8px;
  left: 8px;
  z-index: 1;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--surface);
  font-size: 12px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.pc-body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  box-sizing: border-box;
  width: 100%;
  min-width: 0;
  overflow: hidden;
  padding: 12px;
}

.pc-body strong {
  font-size: 17px;
}

.pc-body .city {
  display: flex;
  align-items: center;
  gap: 5px;
}

.pc-body .city :deep(svg) {
  flex: none;
}

.tag.plain {
  text-transform: none;
  letter-spacing: 0;
  font-size: 14px;
  font-weight: 700;
  padding: 4px 12px;
}

.newtag {
  display: block;
  margin-top: 6px; /* a little air under the city */
  box-sizing: border-box;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pc-body small {
  color: var(--text-2);
  font-size: 14px;
}

.quote {
  color: var(--text);
  font-size: 15px;
}

.perk {
  align-self: flex-start;
  margin-top: 2px;
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: 13px;
  font-weight: 700;
}

.perk.big {
  padding: 6px 12px;
  font-size: 15px;
}

.wait {
  color: var(--warn-text);
  font-size: 13px;
  font-weight: 700;
}

.going {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--text-2);
  font-size: 13px;
}

.stack {
  display: inline-flex;
}

.stack > * + * {
  margin-left: -6px;
}

.pc-act {
  display: flex;
  gap: 8px;
  margin-top: auto;
  padding: 0 12px 12px;
}

.pc-act .btn {
  flex: 1;
  justify-content: center;
}

.empty-note {
  grid-column: 1 / -1;
  padding: 12px 0;
  color: var(--text-2);
}

/* Members in the panel: big faces with first name and city. */
.faces-row {
  display: flex;
  gap: 18px;
  margin-bottom: 18px;
  overflow-x: auto;
  scrollbar-width: none; /* scrolls by swipe, trackpad or the mouse wheel, without a bar */
}

.faces-row.wrap {
  flex-wrap: wrap;
  overflow: visible;
}

.mface {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  width: 96px;
  padding: 4px 0;
  border-radius: 14px;
  color: var(--text);
}

.mface.on :deep(.avatar) {
  outline: 3px solid var(--accent);
  outline-offset: 2px;
}

.mface strong {
  margin-top: 6px;
  font-size: 15px;
}

.mface small {
  color: var(--text-2);
  font-size: 13px;
}

.ph.second {
  margin-top: 4px;
}

.newtag {
  align-self: flex-start;
  padding: 3px 10px;
  border-radius: 999px;
  background: #fff1e6;
  color: #9a3d06;
  font-size: 13px;
  font-weight: 700;
}

/* A member's card over the map. */
.pcard {
  position: absolute;
  top: 96px;
  left: 24px;
  z-index: 3;
  width: 340px;
  padding: 18px;
  border-radius: 18px;
  box-shadow: 0 12px 32px rgb(0 0 0 / 18%);
}

.pcard.placed {
  position: fixed;
  z-index: 60; /* over the panel and the search too */
  width: 340px;
}

.more-spot {
  width: 1px;
  height: 1px;
}

@keyframes drop-in {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
}

/* Cards grow out of the face or pin they belong to. */
.pcard,
.plcard {
  transform-origin: 50% 0;
  animation: card-pop 0.18s ease-out;
}

.pcard.above,
.plcard.above {
  transform-origin: 50% 100%;
}

@keyframes card-pop {
  from {
    opacity: 0;
    scale: 0.9;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pcard,
  .plcard {
    animation: none;
  }
}

.plcard {
  position: fixed;
  z-index: 60;
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 320px;
  padding: 16px 18px 18px;
  border-radius: 18px;
  box-shadow: 0 12px 32px rgb(0 0 0 / 18%);
}

.plcard strong {
  padding-right: 28px;
  font-size: 18px;
}

.plcard .city {
  display: flex;
  align-items: center;
  gap: 5px;
  color: var(--text-2);
}

.plcard .pcard-act {
  margin-top: 10px;
}

.plcard .pcard-act .btn {
  padding: 0 14px;
  white-space: nowrap;
}

.pc.on {
  border-color: var(--accent-text);
  box-shadow: 0 0 0 2px var(--accent);
}

.pcard-x {
  position: absolute;
  top: 10px;
  right: 10px;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  color: var(--text-2);
}

.pcard-top {
  display: flex;
  align-items: center;
  gap: 14px;
}

.pcard-top span {
  display: flex;
  flex-direction: column;
}

.pcard-top strong {
  font-size: 18px;
}

.pcard-top small {
  color: var(--text-2);
}

.pcard-bio {
  margin: 12px 0 0;
  line-height: 1.5;
}

.pcard-act {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 14px;
}

.pcard-act .btn {
  justify-content: center;
  text-decoration: none;
}

/* What's new on a place. */
.news {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 100%;
  padding: 14px 16px;
  border-radius: 14px;
  background: #fff1e6;
  color: #6b2a03;
}

.news-h {
  color: #9a3d06;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.news strong {
  font-size: 17px;
}

.news small {
  font-size: 14px;
}

.share-new {
  width: 100%;
  min-height: 52px;
  border: 2px dashed #e8a26b;
  border-radius: 999px;
  background: #fff8f2;
  color: #9a3d06;
  font-weight: 700;
}

@media (prefers-color-scheme: dark) {
  .news,
  .newtag,
  .share-new {
    background: #3a2414;
    color: #ffd8b8;
  }

  .news-h {
    color: #ffb27a;
  }
}

.up {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 14px;
  color: var(--text);
  text-decoration: none;
}

.up-date {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  width: 56px;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  line-height: 1.1;
}

/* A little calendar page: the month on a red band, the day big below. */
.up-date small {
  width: 100%;
  padding: 3px 0;
  background: #dc2626;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  text-align: center;
  text-transform: uppercase;
}

.up-date strong {
  padding: 3px 0 5px;
  font-size: 21px;
}

.up-txt {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.up-txt small {
  color: var(--text-2);
}

.who {
  width: 100%;
}

/* Dialogs */
.dlg {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px 20px 20px;
}

.dlg-note {
  margin: 0;
  color: var(--text-2);
  line-height: 1.5;
}

.dlg-field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-weight: 700;
}

.dlg-field input {
  flex: 1;
  min-width: 0;
  min-height: 48px;
  padding: 10px 14px;
  border: 1px solid var(--border-strong);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: 400;
}

.find {
  display: flex;
  gap: 8px;
}

.dlg-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.dlg-list button {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 10px 12px;
  border-radius: 10px;
  color: var(--text);
  text-align: left;
}

.dlg-list button:hover {
  background: var(--surface-2);
}

.dlg-act {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}

.dlg-act .btn {
  white-space: nowrap; /* “Send for a check” stays on one line on small phones */
}

.dlg-act .link {
  margin-right: auto;
}

/* One place: a column on the left. */
.detail {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
  width: 440px;
  padding: 16px 34px 40px;
  overflow-y: auto;
  border-right: 1px solid var(--border);
  background: var(--surface);
}

.detail > * {
  flex-shrink: 0; /* the column scrolls; nothing in it gets squeezed */
}

.hero {
  width: 100%;
  height: 210px;
  overflow: hidden;
  border-radius: 14px;
}

.hero > img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.detail h1 {
  margin: 8px 0 0;
  font-size: 26px;
}

.kind {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  color: var(--text-2);
}

.perk.big {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.wait-tag {
  padding: 4px 12px;
  border-radius: 999px;
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: 14px;
  font-weight: 700;
}

.buttons {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  width: 100%;
}

.buttons .btn {
  justify-content: center;
  text-decoration: none;
}

.gone {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.facts {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin: 4px 0 0;
  padding: 0;
  list-style: none;
}

.facts li {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--text);
}

.facts li :deep(svg) {
  flex: none;
  color: var(--text-2);
}

.facts a {
  color: var(--accent-text);
  font-weight: 700;
}

.about {
  margin: 0;
  line-height: 1.55;
}

.who h2 {
  margin: 12px 0 8px;
  font-size: 18px;
}

.faces {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.staff {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  width: 100%;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
}

/* Computers: the same gap on both sides (the right one clears the chat buttons). */
@media (min-width: 768px) {
  /* Computers: 96px each side (the right one clears the chat buttons), and never wider than
     1100px, so the cards keep their size on big screens. */
  .places {
    --side: max(96px, calc((100% - 1100px) / 2));
  }
}

@media (max-width: 1100px) {
  .grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 767px) {
  .places {
    --side: 8px;
    height: calc(100dvh - var(--header-h) - var(--tabbar-h) - env(safe-area-inset-bottom));
  }

  /* Phones: the search on its own row, the three buttons under it. */
  .bar {
    top: 10px;
    flex-wrap: wrap;
    gap: 6px;
    padding: 6px;
    border-radius: 24px;
  }

  .search {
    flex: 1 1 100%;
  }

  .tog {
    flex: 1;
    justify-content: center;
  }

  .pcard:not(.placed) {
    top: 128px !important;
  }

  .search {
    height: 48px;
  }

  .tog :deep(svg) {
    display: none;
  }

  .panel {
    bottom: 8px;
    max-height: 45%;
    padding: 4px 14px 14px;
  }

  .ph .note {
    display: none;
  }

  /* Phones: smaller panel buttons, short words. */
  .ph .link {
    height: 36px;
    padding: 0 14px;
    font-size: 14px;
  }

  .ph .link .lg {
    display: none;
  }

  .ph .link .sm {
    display: inline;
  }

  .ph h2 {
    font-size: 17px;
  }

  .grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .pcard {
    top: 70px;
    right: 8px;
    left: 8px;
    width: auto;
  }

  .pcard.placed {
    right: auto;
    width: calc(100vw - 16px);
  }

  .mface {
    width: auto;
  }

  /* Members only, on a phone: three to a row, evenly spaced. */
  .faces-row.wrap {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px 8px;
  }

  .faces-row.wrap .mface small,
  .faces-row.wrap .mface strong {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .faces-row:not(.wrap) .mface {
    width: 76px;
  }

  /* A place on a phone: the page, the map hidden behind it. */
  .has-detail .map-pane {
    display: none;
  }

  .detail {
    position: static;
    width: auto;
    height: 100%;
    padding: 12px 16px 32px;
    border-right: 0;
  }
}

/* Members-only places: a small grey lock tag (owners and moderators only). */
.locktag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  margin-top: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text);
  font-size: 13px;
  font-weight: 700;
}

/* Hours by day on the place page. */
.hours-li {
  align-items: flex-start !important;
}

.hours {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 100%;
}

.hours strong {
  color: var(--ok-text);
  font-size: 15px;
}

.hours-week {
  display: grid;
  grid-template-columns: 110px 1fr;
  row-gap: 4px;
}

.hours .dim,
.hours small {
  color: var(--text-2);
}

.hours small {
  font-size: 13px;
}

/* Loading: faces, place cards and a place's column in grey. */
.mface-sk {
  pointer-events: none;
}

.mface-sk .skeleton {
  width: 76px;
  height: 76px;
  border-radius: 50%;
}

.mface-sk i.skeleton {
  width: 54px;
  height: 12px;
  margin-top: 6px;
  border-radius: 6px;
}

.mface-sk i + i.skeleton {
  width: 40px;
  height: 10px;
  margin-top: 4px;
}

.pc-sk {
  pointer-events: none;
}

.pc-sk .pc-cover {
  display: block;
  border-radius: 0;
  background: linear-gradient(90deg, var(--surface-2) 25%, var(--border) 50%, var(--surface-2) 75%);
  background-size: 200% 100%;
}

.pc-sk .pc-body i {
  display: block;
  height: 13px;
  margin-top: 8px;
}

.sk-btns {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  width: 100%;
}

.sk-btns .skeleton {
  height: 52px;
  border-radius: 999px;
}

</style>
