<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import PlacesMap from '../components/PlacesMap.vue'
import MapThumb from '../components/MapThumb.vue'
import { CATEGORY, directionsUrl, distanceKm, distanceLabel } from '../lib/places.js'
import { t } from '../lib/i18n.js'

const PlaceForm = defineAsyncComponent(() => import('../components/PlaceForm.vue'))

/**
 * Places: a list and a map side by side (on phones, the list with a Map
 * button). Choosing a place opens it in the list column while the map flies
 * to it, so nobody loses their place. /places/:id links straight to one.
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

async function load() {
  try {
    const res = await api.places()
    places.value = res.items
    canEdit.value = res.can_edit
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}
onMounted(load)

const pending = computed(() => places.value.filter((p) => p.status === 'pending'))
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
  if (!confirm(t.sprintf(/* translators: %s: place name */ t.__('Remove “%s” from Places?', 'membuild-community'), place.name))) return
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
  <div class="places" :class="{ 'map-mode': showMap, 'has-detail': !!detail }">
    <section class="side">
      <!-- One place, opened from the list or the map. -->
      <article v-if="detail" class="detail">
        <button class="back" @click="back"><Icon name="chevronLeft" :size="20" /> {{ t.__('All places', 'membuild-community') }}</button>
        <div class="hero">
          <img v-if="detail.cover" :src="detail.cover" alt="" />
          <MapThumb v-else :lat="detail.lat" :lng="detail.lng" :zoom="16" :color="CATEGORY[detail.category]?.color" :emoji="CATEGORY[detail.category]?.emoji" />
        </div>
        <h1>{{ detail.name }}</h1>
        <p class="meta">
          {{ CATEGORY[detail.category]?.label }}<template v-if="me"> · {{ distanceLabel(distanceKm(me, detail)) }}</template>
          <span v-if="detail.status === 'pending'" class="badge wait">{{ t.__('Waiting for review', 'membuild-community') }}</span>
        </p>
        <p v-if="detail.perk" class="perk"><span aria-hidden="true">🎁</span> {{ detail.perk }}</p>

        <div class="buttons">
          <button v-if="detail.status === 'published'" class="btn" :class="detail.going ? 'btn-primary' : 'go'" :disabled="busy" :aria-pressed="detail.going" @click="toggleGoing(detail)">
            <Icon :name="detail.going ? 'check' : 'mapPin'" :size="16" /> {{ detail.going ? t.__('You go here', 'membuild-community') : t.__('I go here', 'membuild-community') }}
          </button>
          <a class="btn" :href="directionsUrl(detail)" target="_blank" rel="noopener"><Icon name="send" :size="16" /> {{ t.__('Directions', 'membuild-community') }}</a>
          <a v-if="detail.website" class="btn" :href="detail.website" target="_blank" rel="noopener"><Icon name="external" :size="16" /> {{ t.__('Website', 'membuild-community') }}</a>
          <a v-if="detail.phone" class="btn" :href="`tel:${detail.phone.replace(/[^\d+]/g, '')}`"><Icon name="chat" :size="16" /> {{ t.__('Call', 'membuild-community') }}</a>
        </div>

        <dl class="facts">
          <template v-if="detail.address"><dt><Icon name="mapPin" :size="16" /></dt><dd>{{ detail.address }}</dd></template>
          <template v-if="detail.hours"><dt><Icon name="clock" :size="16" /></dt><dd>{{ detail.hours }}</dd></template>
          <template v-if="detail.website"><dt><Icon name="external" :size="16" /></dt><dd><a :href="detail.website" target="_blank" rel="noopener">{{ host(detail.website) }}</a></dd></template>
        </dl>
        <p v-if="detail.description" class="about">{{ detail.description }}</p>

        <section v-if="detail.members?.length" class="who">
          <h2>{{ goingLine(detail) }}</h2>
          <div class="faces">
            <RouterLink v-for="u in detail.members" :key="u.id" :to="{ name: 'member', params: { id: u.id } }" :title="u.name"><Avatar :user="u" :size="40" /></RouterLink>
          </div>
        </section>

        <div v-if="canEdit" class="staff">
          <button v-if="detail.status === 'pending'" class="btn btn-primary" @click="approve(detail)">{{ t.__('Approve', 'membuild-community') }}</button>
          <button class="btn btn-ghost" @click="form = { place: detail }"><Icon name="compose" :size="16" /> {{ t.__('Edit', 'membuild-community') }}</button>
          <button class="btn btn-ghost danger" @click="remove(detail)"><Icon name="trash" :size="16" /> {{ t.__('Remove', 'membuild-community') }}</button>
        </div>
        <div v-else-if="detail.mine && detail.status === 'pending'" class="staff">
          <button class="btn btn-ghost danger" @click="remove(detail)">{{ t.__('Withdraw suggestion', 'membuild-community') }}</button>
        </div>
      </article>

      <!-- All places. -->
      <template v-else>
        <header class="head">
          <h1>{{ t.__('Places', 'membuild-community') }}</h1>
          <button class="btn btn-primary add" @click="form = { place: null }"><Icon name="plus" :size="16" /> {{ canEdit ? t.__('Add', 'membuild-community') : t.__('Suggest', 'membuild-community') }}</button>
        </header>
        <label class="search">
          <Icon name="search" :size="18" />
          <input v-model="query" type="search" :placeholder="t.__('Search places', 'membuild-community')" :aria-label="t.__('Search places', 'membuild-community')" />
        </label>
        <div class="chips-wrap" :class="{ 'more-left': more.left, 'more-right': more.right }">
        <button v-if="more.left" type="button" class="chips-arrow left" :aria-label="t.__('Scroll left', 'membuild-community')" tabindex="-1" @click="scrollChips(-1)"><Icon name="chevronLeft" :size="18" /></button>
        <div ref="chipsEl" class="chips" role="tablist" @scroll.passive="measureChips">
          <button :class="{ on: !!me }" :aria-pressed="!!me" :disabled="locating" @click="nearMe"><Icon name="send" :size="14" /> {{ locating ? t.__('Locating…', 'membuild-community') : t.__('Near me', 'membuild-community') }}</button>
          <button v-for="k in kinds" :key="k" role="tab" :aria-selected="kind === k" :class="{ on: kind === k }" @click="kind = k">
            <template v-if="k === 'all'">{{ t.__('All', 'membuild-community') }}</template>
            <template v-else><span aria-hidden="true">{{ CATEGORY[k].emoji }}</span> {{ CATEGORY[k].plural }}</template>
          </button>
        </div>
        <button v-if="more.right" type="button" class="chips-arrow right" :aria-label="t.__('Scroll right', 'membuild-community')" tabindex="-1" @click="scrollChips(1)"><Icon name="chevronRight" :size="18" /></button>
        </div>

        <p v-if="canEdit && pending.length" class="review">
          <Icon name="flag" :size="16" />
          {{ t.sprintf(/* translators: %d: number of suggested places */ t._n('%d suggestion to review', '%d suggestions to review', pending.length, 'membuild-community'), pending.length) }}
        </p>

        <ul class="list">
          <template v-if="loading">
            <li v-for="n in 4" :key="n" class="item"><span class="thumb skeleton" /><span class="text"><span class="skeleton" style="height: 14px; width: 60%" /><span class="skeleton" style="height: 12px; width: 40%; margin-top: 8px" /></span></li>
          </template>
          <li v-for="p in shown" :key="p.id">
            <button class="item" :class="{ pending: p.status === 'pending' }" @click="open(p.id)">
              <span class="thumb">
                <img v-if="p.cover" :src="p.cover" alt="" loading="lazy" />
                <MapThumb v-else :lat="p.lat" :lng="p.lng" :color="CATEGORY[p.category]?.color" :emoji="CATEGORY[p.category]?.emoji" />
              </span>
              <span class="text">
                <strong>{{ p.name }}</strong>
                <span class="sub">{{ CATEGORY[p.category]?.label }}<template v-if="p.km !== undefined"> · {{ distanceLabel(p.km) }}</template><template v-else-if="p.address"> · {{ p.address }}</template></span>
                <span v-if="p.perk" class="perk small"><span aria-hidden="true">🎁</span> {{ p.perk }}</span>
                <span v-if="p.status === 'pending'" class="sub wait">{{ suggestedBy(p) }} · {{ t.__('waiting for review', 'membuild-community') }}</span>
                <span v-else-if="p.going_count" class="going">
                  <span class="stack"><Avatar v-for="u in p.faces" :key="u.id" :user="u" :size="20" /></span>
                  {{ goingLine(p) }}
                </span>
              </span>
            </button>
          </li>
        </ul>

        <div v-if="!loading && !shown.length" class="empty">
          <span aria-hidden="true">🗺️</span>
          <p v-if="places.length">{{ t.__('No places match.', 'membuild-community') }}</p>
          <template v-else>
            <p><strong>{{ t.__('No places yet', 'membuild-community') }}</strong></p>
            <p>{{ canEdit ? t.__('Add the spots where members meet, partners with a member perk, or venues for your events.', 'membuild-community') : t.__('Know a good spot for members? Suggest it.', 'membuild-community') }}</p>
          </template>
        </div>
        <p v-if="error" class="err" role="status">{{ error }}</p>
      </template>
    </section>

    <section class="map-pane">
      <PlacesMap ref="mapRef" :places="shown" :selected="placeId" :me="me" @select="open" />
      <!-- Phones, map mode: the tapped place as a card over the map. -->
      <button v-if="tapped" class="peek card" @click="showMap = false">
        <strong>{{ tapped.name }}</strong>
        <span>{{ CATEGORY[tapped.category]?.label }}<template v-if="tapped.perk"> · 🎁 {{ tapped.perk }}</template></span>
        <span class="open">{{ t.__('Open', 'membuild-community') }} <Icon name="chevronRight" :size="16" /></span>
      </button>
    </section>

    <button class="map-toggle" @click="toggleMap">
      <Icon :name="showMap ? 'list' : 'mapPin'" :size="18" /> {{ showMap ? t.__('List', 'membuild-community') : t.__('Map', 'membuild-community') }}
    </button>

    <PlaceForm v-if="form" :place="form.place" :can-edit="canEdit" @close="form = null" @saved="saved" />
  </div>
</template>

<style scoped>
.places {
  display: grid;
  grid-template-columns: 400px minmax(0, 1fr);
  height: calc(100dvh - var(--header-h) - env(safe-area-inset-top));
}

.side {
  overflow-y: auto;
  padding: 16px;
  border-right: 1px solid var(--border);
  background: var(--surface);
}

.map-pane {
  position: relative;
  min-width: 0;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.head h1,
.detail h1 {
  margin: 0;
  font-size: 24px;
}

.add {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.search {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  margin: 12px 0 10px;
  padding: 0 14px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
}

.search input {
  flex: 1;
  min-width: 0;
  border: 0;
  padding: 0;
  background: transparent;
  color: var(--text);
  outline: none;
  box-shadow: none;
  font-size: 15px;
}

.chips-wrap {
  position: relative;
  margin-bottom: 8px;
}

.chips {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  scrollbar-width: none;
  scroll-snap-type: x proximity;
}

.chips::-webkit-scrollbar {
  display: none;
}

.chips > button {
  scroll-snap-align: start;
}

/* Fade the edge where more chips are hiding. */
.more-right .chips {
  mask-image: linear-gradient(to right, #000 calc(100% - 56px), transparent);
}

.more-left .chips {
  mask-image: linear-gradient(to left, #000 calc(100% - 56px), transparent);
}

.more-left.more-right .chips {
  mask-image: linear-gradient(to right, transparent, #000 56px, #000 calc(100% - 56px), transparent);
}

.chips-arrow {
  display: none;
}

/* Computers (a mouse can't swipe): a round arrow over the faded edge. */
@media (hover: hover) and (pointer: fine) {
  .chips-arrow {
    position: absolute;
    top: 50%;
    z-index: 1;
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    transform: translateY(-50%);
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
    box-shadow: 0 2px 8px rgb(0 0 0 / 18%);
  }

  .chips-arrow.left {
    left: -4px;
  }

  .chips-arrow.right {
    right: -4px;
  }

  .chips-arrow:hover {
    background: var(--surface-2);
  }
}

.chips button {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 7px 12px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text);
  font-weight: 600;
  font-size: 14px;
}

.chips button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.review {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 8px 0 4px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, #f59e0b 14%, transparent);
  font-weight: 600;
  font-size: 14px;
}

.list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.item {
  display: flex;
  gap: 12px;
  width: 100%;
  padding: 10px 8px;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--text);
  text-align: left;
}

.item:hover,
.item:focus-visible {
  background: var(--surface-2);
}

.item.pending {
  opacity: 0.75;
}

.thumb {
  flex: none;
  width: 64px;
  height: 64px;
  border-radius: 12px;
  display: grid;
  place-items: center;
  overflow: hidden;
  font-size: 26px;
}

.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.text strong {
  font-size: 16px;
}

.sub {
  color: var(--text-2);
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.wait {
  color: #b45309;
}

.perk {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  padding: 6px 12px;
  border-radius: 999px;
  background: color-mix(in srgb, #10b981 16%, transparent);
  color: #047857;
  font-weight: 600;
}

.perk.small {
  padding: 2px 8px;
  font-size: 13px;
}

@media (prefers-color-scheme: dark) {
  .perk {
    color: #6ee7b7;
  }
  .wait {
    color: #fbbf24;
  }
}

.going {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--text-2);
  font-size: 13px;
  margin-top: 2px;
}

.stack {
  display: inline-flex;
}

.stack > :not(:first-child) {
  margin-left: -6px;
}

.stack :deep(img) {
  box-shadow: 0 0 0 2px var(--surface);
}

.empty {
  text-align: center;
  color: var(--text-2);
  padding: 32px 12px;
}

.empty > span {
  font-size: 40px;
}

.empty p {
  margin: 6px 0;
}

.err {
  color: var(--danger);
}

/* One place */
.back {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin: -4px 0 12px -6px;
  padding: 6px;
  border-radius: 8px;
  background: none;
  color: var(--text-2);
  font-weight: 600;
}

.back:hover {
  background: var(--surface-2);
}

.hero {
  height: 180px;
  border-radius: var(--radius);
  overflow: hidden;
  display: grid;
  place-items: center;
  margin-bottom: 14px;
}

.hero img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.hero-emoji {
  font-size: 64px;
}

.meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin: 4px 0 10px;
  color: var(--text-2);
}

.badge.wait {
  padding: 2px 8px;
  border-radius: 999px;
  background: color-mix(in srgb, #f59e0b 16%, transparent);
  font-size: 13px;
  font-weight: 600;
}

.buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 14px 0;
}

.buttons .btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 14px;
  border-radius: 10px;
  background: var(--surface-2);
  color: var(--text);
  font-weight: 650;
  text-decoration: none;
}

.buttons .btn:hover {
  background: color-mix(in srgb, var(--text) 10%, var(--surface-2));
}

/* "I go here" is the main action; once tapped it turns into the accent color. */
.buttons .btn.go {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.buttons .btn.btn-primary {
  background: var(--accent);
  color: var(--accent-ink);
}

.facts {
  display: grid;
  grid-template-columns: 20px 1fr;
  gap: 10px 10px;
  margin: 0 0 12px;
  padding: 14px 0;
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}

.facts dt {
  color: var(--text-2);
  padding-top: 2px;
}

.facts dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.about {
  white-space: pre-line;
  line-height: 1.55;
}

.who h2 {
  font-size: 16px;
  margin: 16px 0 10px;
}

.faces {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.staff {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 20px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}

.staff .btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.danger {
  color: var(--danger);
}

.map-toggle,
.peek {
  display: none;
}

/* Tablets: a slimmer list, so the map keeps room to breathe. */
@media (max-width: 1100px) and (min-width: 768px) {
  .places {
    grid-template-columns: 320px minmax(0, 1fr);
  }
}

/* Phones: the list, or the map with a card for the tapped pin. */
@media (max-width: 767px) {
  .places {
    display: block;
    height: auto;
    min-height: calc(100dvh - 50px - var(--tabbar-h));
  }

  .side {
    border-right: 0;
    min-height: inherit;
  }

  .map-pane {
    display: none;
  }

  .map-mode .side {
    display: none;
  }

  .map-mode .map-pane {
    display: block;
    position: fixed;
    inset: calc(50px + env(safe-area-inset-top)) 0 calc(var(--tabbar-h) + env(safe-area-inset-bottom));
    z-index: 5;
  }

  .map-toggle {
    position: fixed;
    left: 50%;
    bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom) + 16px);
    transform: translateX(-50%);
    z-index: 10;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 12px 20px;
    border-radius: 999px;
    background: var(--text);
    color: var(--surface);
    font-weight: 700;
    box-shadow: 0 6px 20px rgb(0 0 0 / 25%);
  }

  .has-detail:not(.map-mode) .map-toggle {
    display: none;
  }

  .peek {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 76px;
    z-index: 500;
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 14px 16px;
    text-align: left;
    color: var(--text);
  }

  .peek span {
    color: var(--text-2);
    font-size: 14px;
  }

  .peek .open {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    color: var(--accent-text);
    font-weight: 700;
  }
}
</style>
