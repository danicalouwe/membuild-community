<script setup>
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import Icon from './Icon.vue'
import { startAttachment } from '../lib/attachments.js'
import { cityOfAddress, dateInZone, plainCover, zoneLabel, zonedToDate } from '../lib/events.js'
import { t } from '../lib/i18n.js'

const PlacesMap = defineAsyncComponent(() => import('./PlacesMap.vue'))

/**
 * New event, Edit event or Duplicate (owners), as a full page (Version 2):
 * What is it? · When? (with Repeats) · Where is it? (Online, In person, Both) ·
 * Who can come? Then Cancel / Publish event in a bar at the bottom. An in-person
 * event is held in its place's time zone, so times are typed in that zone.
 */
const props = defineProps({
  event: { type: Object, default: null }, // the post being edited (with .event)
  copy: { type: Boolean, default: false }, // "Duplicate": a new event from an old one
})
const emit = defineEmits(['close', 'saved'])

const e = props.event?.event || null
const myZone = Intl.DateTimeFormat().resolvedOptions().timeZone
const editing = computed(() => !!props.event && !props.copy)
const pad = (n) => String(n).padStart(2, '0')
function startOf(iso, tz) {
  if (iso && !props.copy) return dateInZone(iso, tz)
  const d = new Date(Date.now() + 86400000)
  return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: '13:00' }
}
const tz0 = e?.tz || myZone
const s0 = startOf(e?.at, tz0)
const form = ref({
  title: e?.title || '',
  description: props.event?.content || '',
  date: s0.date,
  time: s0.time,
  minutes: e?.minutes || 60,
  repeat: '',
  where: e?.where || 'online',
  url: e?.url || '',
  placeId: e?.place?.id || 0,
  venue: e?.place?.name || '',
  address: e?.place?.address || '',
  lat: e?.place?.lat ?? null,
  lng: e?.place?.lng ?? null,
  tz: tz0,
  group: e?.group?.id || 0,
  recording: props.copy ? '' : e?.recording || '',
})
const who = ref(form.value.group ? 'members' : 'everyone')

const cover = ref(props.event?.media?.find((m) => m.kind === 'image')?.url || null)
const coverId = ref(undefined) // undefined: unchanged · null: removed · number: new
const coverBusy = ref(false)
const saving = ref(false)
const error = ref('')
const groups = ref([])
const places = ref([])
const matches = ref([])
const looking = ref(false)

onMounted(async () => {
  if (cfg.community.canManage) groups.value = (await api.groups().catch(() => ({ items: [] }))).items || []
  places.value = ((await api.places().catch(() => ({ items: [] }))).items || []).filter((p) => p.status === 'published')
})

const DURATIONS = [15, 30, 45, 60, 90, 120, 180, 240]
const REPEATS = [
  { key: '', label: t.__('Just once', 'membuild-community') },
  { key: 'week', label: t.__('Every week', 'membuild-community') },
  { key: '2weeks', label: t.__('Every 2 weeks', 'membuild-community') },
  { key: 'month', label: t.__('Every month', 'membuild-community') },
]
const zones = (() => {
  try {
    return Intl.supportedValuesOf('timeZone')
  } catch {
    return [myZone]
  }
})()
const inPerson = computed(() => form.value.where !== 'online')
const online = computed(() => form.value.where !== 'person')
const place = computed(() => places.value.find((p) => p.id === Number(form.value.placeId)) || null)
const pinAt = computed(() => (form.value.lat !== null && form.value.lng !== null ? { lat: form.value.lat, lng: form.value.lng } : null))
const zoneName = computed(() => zoneLabel(form.value.tz, inPerson.value && form.value.tz !== myZone ? cityOfAddress(place.value?.address || form.value.address) : ''))
const whenNote = computed(() =>
  inPerson.value && form.value.tz !== myZone
    ? t.sprintf(/* translators: %s: e.g. "Austin time" */ t.__('In %s, where it’s held. Each member also sees it in their own time zone.', 'membuild-community'), zoneName.value)
    : t.sprintf(/* translators: %s: e.g. "Eastern Time" */ t.__('In your time (%s). Each member sees it in their own time zone.', 'membuild-community'), zoneName.value),
)
// Under the time zone: filled in from the place, or (the first event there) pick it once.
const zoneHint = computed(() => {
  const p = inPerson.value ? place.value : null
  if (!p) return null
  if (!p.tz) return { first: true, text: t.sprintf(/* translators: %s: place name */ t.__('The first event at %s: pick the time zone it’s in. We’ll remember it for the place, so next time it’s filled in.', 'membuild-community'), p.name) }
  if (form.value.tz !== p.tz) return null
  return { first: false, text: t.sprintf(/* translators: 1: place name, 2: e.g. "Austin time" */ t.__('Filled in from %1$s: %2$s. Change it only if this one is somewhere else.', 'membuild-community'), p.name, zoneName.value) }
})
const weekday = computed(() => new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(new Date(`${form.value.date}T12:00`)))
const repeatNote = computed(() => {
  const r = form.value.repeat
  if (!r) return ''
  const at = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(`${form.value.date}T${form.value.time}`))
  const head =
    r === 'week'
      ? t.sprintf(/* translators: 1: a weekday, 2: a time */ t.__('Every %1$s at %2$s, for the next 3 months.', 'membuild-community'), weekday.value, at)
      : r === '2weeks'
        ? t.sprintf(/* translators: 1: a weekday, 2: a time */ t.__('Every other %1$s at %2$s, for the next 3 months.', 'membuild-community'), weekday.value, at)
        : t.sprintf(/* translators: %s: a time */ t.__('Once a month on this date at %s, for the next 3 months.', 'membuild-community'), at)
  return `${head} ${t.__('Each one gets its own page, reminders and replay. You can cancel one, or all of them.', 'membuild-community')}`
})

function pickPlace() {
  const p = place.value
  if (!p) return
  form.value.venue = p.name
  form.value.address = p.address
  form.value.lat = p.lat
  form.value.lng = p.lng
  form.value.tz = p.tz || myZone // the place remembers where it is; a new one starts from yours
}
async function find() {
  error.value = ''
  matches.value = []
  if (form.value.address.trim().length < 3) return (error.value = t.__('Type the address first.', 'membuild-community'))
  looking.value = true
  try {
    const res = await api.geocode(form.value.address.trim())
    matches.value = res.items
    if (!res.items.length) error.value = t.__('No match. Try adding the city, or a nearby street.', 'membuild-community')
    else if (res.items.length === 1) choose(res.items[0])
  } catch (err) {
    error.value = err.message
  } finally {
    looking.value = false
  }
}
function choose(m) {
  form.value.lat = m.lat
  form.value.lng = m.lng
  matches.value = []
}
const moved = (at) => {
  form.value.lat = Math.round(at.lat * 1e6) / 1e6
  form.value.lng = Math.round(at.lng * 1e6) / 1e6
}

function pickCover(ev) {
  const file = ev.target.files[0]
  ev.target.value = ''
  if (!file?.type.startsWith('image/')) return
  const item = startAttachment(file)
  cover.value = item.previewUrl
  coverBusy.value = true
  item.promise
    .then(() => {
      if (item.media) coverId.value = item.media.id
      else {
        error.value = item.error
        cover.value = null
      }
    })
    .finally(() => (coverBusy.value = false))
}

async function save() {
  error.value = ''
  const f = form.value
  if (!f.title.trim()) return (error.value = t.__('Give the event a title.', 'membuild-community'))
  const tz = inPerson.value ? f.tz : myZone
  const at = zonedToDate(f.date, f.time, tz)
  if (Number.isNaN(at.getTime())) return (error.value = t.__('Pick a date and time.', 'membuild-community'))
  if (inPerson.value && !f.placeId && !f.venue.trim()) return (error.value = t.__('Say where it is: the name of the place.', 'membuild-community'))
  saving.value = true
  const body = {
    title: f.title.trim(),
    description: f.description.trim(),
    at: at.toISOString(),
    minutes: Number(f.minutes),
    where: f.where,
    url: online.value ? f.url.trim() : '',
    place_id: inPerson.value ? Number(f.placeId) || 0 : 0,
    venue: inPerson.value ? f.venue.trim() : '',
    address: inPerson.value ? f.address.trim() : '',
    lat: inPerson.value ? f.lat : null,
    lng: inPerson.value ? f.lng : null,
    tz,
    group: cfg.community.canManage ? (who.value === 'members' ? Number(f.group) : 0) : undefined,
    recording: editing.value ? f.recording.trim() : undefined,
    repeat: editing.value ? undefined : f.repeat || undefined,
    cover_media_id: coverId.value,
  }
  try {
    const saved = editing.value ? await api.updateEvent(props.event.id, body) : await api.createEvent(body)
    emit('saved', saved)
    emit('close')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}
const minutesLabel = (m) => (m < 60 ? t.sprintf(/* translators: %d: minutes */ t.__('%d min', 'membuild-community'), m) : m === 90 ? t.__('1½ hours', 'membuild-community') : t.sprintf(/* translators: %s: hours */ t._n('%s hour', '%s hours', m / 60, 'membuild-community'), String(m / 60)))
const heading = computed(() => (editing.value ? t.__('Edit event', 'membuild-community') : t.__('New event', 'membuild-community')))
</script>

<template>
  <div class="page-in ef">
    <button type="button" class="back-link" @click="emit('close')"><Icon name="chevronLeft" :size="18" /> {{ t.__('Events', 'membuild-community') }}</button>
    <h1 class="page-title">{{ heading }}</h1>
    <p class="page-intro">{{ t.__('Members see it in Events, and the ones who say they’re going get reminders.', 'membuild-community') }}</p>

    <form class="ef-form" @submit.prevent="save">
      <!-- What is it? -->
      <section class="card box">
        <h2>{{ t.__('What is it?', 'membuild-community') }}</h2>
        <label class="field">
          <span class="lbl">{{ t.__('Title', 'membuild-community') }}</span>
          <input v-model="form.title" type="text" maxlength="200" required :placeholder="t.__('Workshop: your first 100 members', 'membuild-community')" />
        </label>
        <div class="field">
          <span class="lbl">{{ t.__('Cover photo', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
          <div class="cover-row">
            <span class="cover" :style="cover ? { backgroundImage: `url(${cover})` } : plainCover()"><small v-if="!cover">{{ t.__('Until you add a photo', 'membuild-community') }}</small></span>
            <span class="cover-act">
              <label class="btn btn-lg btn-ghost"><Icon name="image" :size="18" /> {{ cover ? t.__('Change photo', 'membuild-community') : t.__('Upload a photo', 'membuild-community') }}<input class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickCover" /></label>
              <button v-if="cover" type="button" class="link" @click="cover = null; coverId = null">{{ t.__('Remove', 'membuild-community') }}</button>
              <small>{{ t.__('16:9, at least 1600 × 900. No photo? Members see this cover made from your main color.', 'membuild-community') }}</small>
            </span>
          </div>
        </div>
        <label class="field">
          <span class="lbl">{{ t.__('Details', 'membuild-community') }}</span>
          <textarea v-model="form.description" rows="4" maxlength="5000" :placeholder="t.__('What it’s about, and what to bring.', 'membuild-community')" />
        </label>
        <label v-if="editing" class="field">
          <span class="lbl">{{ t.__('Replay link', 'membuild-community') }} <em>{{ t.__('(after it’s happened)', 'membuild-community') }}</em></span>
          <input v-model="form.recording" type="url" inputmode="url" maxlength="500" placeholder="https://youtube.com/…" />
        </label>
      </section>

      <!-- When? -->
      <section class="card box">
        <h2>{{ t.__('When?', 'membuild-community') }}</h2>
        <p class="sub">{{ whenNote }}</p>
        <div class="row3">
          <label class="field"><span class="lbl">{{ t.__('Date', 'membuild-community') }}</span><input v-model="form.date" type="date" required /></label>
          <label class="field"><span class="lbl">{{ t.__('Starts at', 'membuild-community') }}</span><input v-model="form.time" type="time" required /></label>
          <label class="field">
            <span class="lbl">{{ t.__('How long', 'membuild-community') }}</span>
            <select v-model.number="form.minutes"><option v-for="m in DURATIONS" :key="m" :value="m">{{ minutesLabel(m) }}</option></select>
          </label>
        </div>
        <div v-if="!editing" class="field">
          <span class="lbl">{{ t.__('Repeats', 'membuild-community') }}</span>
          <div class="chips">
            <button v-for="r in REPEATS" :key="r.key" type="button" :class="{ on: form.repeat === r.key }" :aria-pressed="form.repeat === r.key" @click="form.repeat = r.key">{{ r.label }}</button>
          </div>
          <small v-if="repeatNote">{{ repeatNote }}</small>
        </div>
      </section>

      <!-- Where is it? -->
      <section class="card box">
        <h2>{{ t.__('Where is it?', 'membuild-community') }}</h2>
        <div class="cards3" role="radiogroup">
          <button v-for="w in [{ k: 'online', i: 'video', l: t.__('Online', 'membuild-community'), d: t.__('Zoom, Google Meet or a live stream.', 'membuild-community') }, { k: 'person', i: 'mapPin', l: t.__('In person', 'membuild-community'), d: t.__('A place people come to.', 'membuild-community') }, { k: 'both', i: 'users', l: t.__('Both', 'membuild-community'), d: t.__('A place, plus a link for everyone else.', 'membuild-community') }]" :key="w.k" type="button" role="radio" class="pick" :class="{ on: form.where === w.k }" :aria-checked="form.where === w.k" @click="form.where = w.k">
            <span class="pick-ic"><Icon :name="w.i" :size="20" /></span>
            <span class="dot" />
            <strong>{{ w.l }}</strong>
            <small>{{ w.d }}</small>
          </button>
        </div>

        <template v-if="inPerson">
          <label v-if="places.length" class="field">
            <span class="lbl">{{ t.__('One of your Places?', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
            <select v-model.number="form.placeId" @change="pickPlace">
              <option :value="0">{{ t.__('No, somewhere else', 'membuild-community') }}</option>
              <option v-for="p in places" :key="p.id" :value="p.id">{{ p.name }} · {{ p.address }}</option>
            </select>
            <small>{{ t.__('Then it shows under Coming up here on that place.', 'membuild-community') }}</small>
          </label>
          <template v-if="!form.placeId">
            <label class="field">
              <span class="lbl">{{ t.__('Place name', 'membuild-community') }}</span>
              <input v-model="form.venue" type="text" maxlength="200" :placeholder="t.__('Riverside Community Hall', 'membuild-community')" />
            </label>
            <div class="field">
              <span class="lbl">{{ t.__('Address', 'membuild-community') }}</span>
              <div class="find">
                <input v-model="form.address" type="text" maxlength="255" autocomplete="street-address" :placeholder="t.__('Street, city', 'membuild-community')" @keydown.enter.prevent="find" />
                <button type="button" class="btn btn-lg btn-ghost" :disabled="looking" @click="find"><Icon name="search" :size="18" /> {{ looking ? t.__('Finding…', 'membuild-community') : t.__('Find', 'membuild-community') }}</button>
              </div>
              <ul v-if="matches.length > 1" class="matches">
                <li v-for="(m, n) in matches" :key="n"><button type="button" @click="choose(m)"><Icon name="mapPin" :size="14" /> {{ m.label }}</button></li>
              </ul>
              <div v-if="pinAt" class="mini"><PlacesMap :editable="pinAt" @move="moved" /></div>
              <small v-if="pinAt">{{ t.__('Found it. If the pin isn’t quite right, drag it to the door.', 'membuild-community') }}</small>
            </div>
          </template>
          <label class="field">
            <span class="lbl">{{ t.__('Time zone where it’s held', 'membuild-community') }}</span>
            <select v-model="form.tz" :class="{ ask: zoneHint?.first }"><option v-for="z in zones" :key="z" :value="z">{{ z.replace(/_/g, ' ') }}</option></select>
            <small v-if="zoneHint" class="zone-hint" :class="zoneHint.first ? 'first' : 'ok'"><Icon :name="zoneHint.first ? 'info' : 'check'" :size="16" /> <span>{{ zoneHint.text }}</span></small>
          </label>
        </template>

        <label v-if="online" class="field">
          <span class="lbl">{{ t.__('Link', 'membuild-community') }}</span>
          <input v-model="form.url" type="url" inputmode="url" maxlength="500" placeholder="https://meet.google.com/…" />
          <small>{{ t.__('Members get a Join button on the event page 15 minutes before it starts. A YouTube, Vimeo or Twitch live stream plays right on the page and becomes the replay.', 'membuild-community') }}</small>
        </label>
      </section>

      <!-- Who can come? -->
      <section v-if="cfg.community.canManage" class="card box">
        <h2>{{ t.__('Who can come?', 'membuild-community') }}</h2>
        <div class="cards2" role="radiogroup">
          <button type="button" role="radio" class="pick" :class="{ on: who === 'everyone' }" :aria-checked="who === 'everyone'" @click="who = 'everyone'">
            <span class="pick-ic"><Icon name="users" :size="20" /></span><span class="dot" />
            <strong>{{ t.__('Everyone', 'membuild-community') }}</strong><small>{{ t.__('All members of the community.', 'membuild-community') }}</small>
          </button>
          <button type="button" role="radio" class="pick" :class="{ on: who === 'members' }" :aria-checked="who === 'members'" :disabled="!groups.length" @click="who = 'members'; form.group = form.group || groups[0]?.id || 0">
            <span class="pick-ic"><Icon name="lock" :size="20" /></span><span class="dot" />
            <strong>{{ t.__('A membership', 'membuild-community') }}</strong><small>{{ groups.length ? t.__('Only members with it.', 'membuild-community') : t.__('Make a membership in Settings first.', 'membuild-community') }}</small>
          </button>
        </div>
        <select v-if="who === 'members' && groups.length" v-model.number="form.group" :aria-label="t.__('Membership', 'membuild-community')">
          <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>
        <p class="remind"><Icon name="bell" :size="18" /> {{ t.__('Members who say they’re going get a reminder email the day before and an hour before. You don’t need to do anything.', 'membuild-community') }}</p>
      </section>

      <p v-if="error" class="error-text" role="alert">{{ error }}</p>

      <div class="bar card">
        <span>{{ editing ? t.__('Changes show as soon as you save.', 'membuild-community') : t.__('Nothing is published until you press Publish event.', 'membuild-community') }}</span>
        <button type="button" class="btn btn-lg btn-ghost" @click="emit('close')">{{ t.__('Cancel', 'membuild-community') }}</button>
        <button type="submit" class="btn btn-lg btn-primary" :disabled="saving || coverBusy">{{ saving ? t.__('Saving…', 'membuild-community') : editing ? t.__('Save changes', 'membuild-community') : t.__('Publish event', 'membuild-community') }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.ef {
  max-width: 808px;
  margin: 0 auto;
  padding: 20px 16px 48px;
}

h1 {
  margin: 6px 0;
}

.ef-form {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.box {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 26px 28px;
  border-radius: var(--radius-lg);
}

.box h2 {
  margin: 0;
  font-size: var(--fs-3xl);
}

.sub {
  margin: -10px 0 0;
  color: var(--text-2);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}

.lbl {
  font-weight: 700;
}

.lbl em {
  color: var(--text-2);
  font-style: normal;
  font-weight: 400;
}

.zone-hint {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 14px;
  border-radius: var(--radius-md);
}

.field .zone-hint.ok {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.field .zone-hint.first {
  background: var(--warn-soft);
  color: var(--warn-text);
}

.zone-hint :deep(svg) {
  flex: none;
  margin-top: 2px;
}

select.ask {
  border-color: var(--accent-text);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 35%, transparent);
}

.field small,
.cover-act small {
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.5;
}

input:not([type='file']),
textarea,
select {
  width: 100%;
  min-height: 48px;
  padding: 10px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
}

textarea {
  resize: vertical;
}

.cover-row {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}

.cover {
  position: relative;
  flex: none;
  width: 220px;
  aspect-ratio: 16 / 9;
  border-radius: var(--radius-md);
  background: center / cover no-repeat;
}

.cover small {
  position: absolute;
  bottom: 10px;
  left: 10px;
  padding: 2px 10px;
  border-radius: var(--radius-pill);
  background: color-mix(in srgb, var(--scrim) 55%, transparent);
  color: var(--on-color);
  font-size: var(--fs-xs);
  font-weight: 700;
}

.cover-act {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
}

.link {
  color: var(--danger);
  font-weight: 700;
  text-decoration: underline;
}

.row3 {
  display: grid;
  grid-template-columns: 1.2fr 1fr 1fr;
  gap: 12px;
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.chips button {
  height: 44px;
  padding: 0 18px;
  border: 1px solid transparent;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--text);
  font-weight: 650;
}

.chips button.on {
  border-color: var(--accent-text);
  background: var(--accent-soft);
  color: var(--accent-text);
}

.cards3,
.cards2 {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.cards2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.pick {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  color: var(--text);
  text-align: left;
}

.pick.on {
  border: 2px solid var(--text);
  padding: 15px;
}

.pick:disabled {
  opacity: 0.55;
}

.pick-ic {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  margin-bottom: 8px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
}

.pick strong {
  font-size: var(--fs-xl);
}

.pick small {
  color: var(--text-2);
  font-size: var(--fs-md);
}

.dot {
  position: absolute;
  top: 14px;
  right: 14px;
  width: 20px;
  height: 20px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
}

.pick.on .dot {
  border-color: var(--text);
  background: radial-gradient(circle, var(--surface) 0 30%, var(--text) 32%);
}

.find {
  display: flex;
  gap: 10px;
}

.matches {
  margin: 0;
  padding: 0;
  list-style: none;
}

.matches button {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  text-align: left;
}

.matches button:hover {
  background: var(--surface-2);
}

.mini {
  height: 220px;
  overflow: hidden;
  border-radius: var(--radius-md);
}

.remind {
  display: flex;
  gap: 10px;
  margin: 0;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  line-height: 1.5;
}

.remind :deep(svg) {
  flex: none;
  margin-top: 2px;
}

.bar {
  position: sticky;
  bottom: 12px;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 12px 12px 20px;
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-pop);
}

.bar span {
  flex: 1;
  color: var(--text-2);
}

@media (max-width: 600px) {
  .box {
    padding: 20px 16px;
  }

  .row3 {
    grid-template-columns: 1fr 1fr;
  }

  .row3 .field:first-child {
    grid-column: 1 / -1;
  }

  .cards3,
  .cards2 {
    grid-template-columns: 1fr;
  }

  .cover-row {
    flex-direction: column;
  }

  .cover {
    width: 100%;
  }

  .bar {
    bottom: calc(var(--tabbar-h) + 8px);
    flex-wrap: wrap;
  }

  .bar span {
    flex: 1 1 100%;
  }

  .bar .btn {
    flex: 1;
  }
}
</style>
