<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import Icon from './Icon.vue'
import { CATEGORY } from '../lib/places.js'
import { startAttachment } from '../lib/attachments.js'
import { t } from '../lib/i18n.js'
import { cityOfAddress, zoneLabel } from '../lib/events.js'
import { COUNTRIES, DIAL, countryName, countryOfNumber, guessCountry } from '../lib/phone.js'

const PlacesMap = defineAsyncComponent(() => import('./PlacesMap.vue'))

/**
 * Add a place (staff), suggest one (members), or edit one (staff).
 * Three steps on one screen: what it is, where it is (type the address,
 * pick the match, drag the pin if it's a little off), and the optional details.
 */
const props = defineProps({
  place: { type: Object, default: null }, // editing
  canEdit: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'saved'])

const p = props.place
const form = ref({
  name: p?.name || '',
  category: p?.category || 'meetup',
  perk: p?.perk || '',
  address: p?.address || '',
  lat: p?.lat ?? null,
  lng: p?.lng ?? null,
  website: p?.website || '',
  phone: p?.phone || '',
  phone_country: p?.phone_country || '',
  hours: p?.hours || '',
  description: p?.description || '',
  tz: p?.tz || '',
})
// Owners: the time zone it's in, so events held here show the right time.
const zones = (() => {
  try {
    return Intl.supportedValuesOf('timeZone')
  } catch {
    return []
  }
})()

/* The phone's country code: from the address once found, else this place's, else the browser's. */
const ccPicked = ref(false)
const ccFrom = ref(p?.phone_country ? 'saved' : '')
if (!form.value.phone_country) form.value.phone_country = countryOfNumber(form.value.phone) || guessCountry()
const ccLabel = computed(() => `${form.value.phone_country} +${DIAL[form.value.phone_country] || ''}`)
const phoneHint = computed(() => {
  const city = cityOfAddress(form.value.address)
  if (ccFrom.value === 'address' && city) return t.sprintf(/* translators: %s: a city */ t.__('Picked from the address (%s). Type it the way you’d dial it there; tapping it on the place page calls it from any country.', 'membuild-community'), city)
  return t.sprintf(/* translators: %s: a country */ t.__('Type it the way you’d dial it in %s; tapping it on the place page calls it from any country.', 'membuild-community'), countryName(form.value.phone_country))
})

/* Who can see it (owners): everyone, or one membership, like an event's Who can come? */
const groups = ref([])
const who = ref(p?.access?.id ? 'members' : 'everyone')
const accessGroup = ref(p?.access?.id || 0)
onMounted(async () => {
  if (props.canEdit) groups.value = (await api.groups().catch(() => ({ items: [] }))).items || []
})

/* When is it open? Day buttons, then from–to times for each open day. */
const DAYS = [
  ['mon', t.__('Mon', 'membuild-community'), t.__('Monday', 'membuild-community')],
  ['tue', t.__('Tue', 'membuild-community'), t.__('Tuesday', 'membuild-community')],
  ['wed', t.__('Wed', 'membuild-community'), t.__('Wednesday', 'membuild-community')],
  ['thu', t.__('Thu', 'membuild-community'), t.__('Thursday', 'membuild-community')],
  ['fri', t.__('Fri', 'membuild-community'), t.__('Friday', 'membuild-community')],
  ['sat', t.__('Sat', 'membuild-community'), t.__('Saturday', 'membuild-community')],
  ['sun', t.__('Sun', 'membuild-community'), t.__('Sunday', 'membuild-community')],
]
const week = ref(Object.fromEntries(DAYS.map(([d]) => [d, (p?.week?.[d] || []).map((r) => [...r])])))
const openDays = computed(() => DAYS.filter(([d]) => week.value[d].length))
const sameTimes = ref(false)
function toggleDay(d) {
  if (week.value[d].length) return (week.value[d] = [])
  const first = openDays.value[0]
  week.value[d] = first ? week.value[first[0]].map((r) => [...r]) : [['09:00', '17:00']]
}
const addTime = (d) => week.value[d].push(['13:00', '17:00'])
const dropTime = (d, i) => week.value[d].splice(i, 1)
// Same times every open day: the first open day's times, copied to the others as you change them.
watch(
  [sameTimes, () => JSON.stringify(week.value[openDays.value[0]?.[0]] || [])],
  () => {
    if (!sameTimes.value || !openDays.value.length) return
    const src = JSON.stringify(week.value[openDays.value[0][0]])
    for (const [d] of openDays.value.slice(1)) if (JSON.stringify(week.value[d]) !== src) week.value[d] = JSON.parse(src)
  },
)

const zoneCity = computed(() => (form.value.tz ? zoneLabel(form.value.tz, cityOfAddress(form.value.address)) : ''))
const cover = ref(p?.cover || null)
const coverId = ref(undefined) // undefined: unchanged · null: removed · number: new
const coverBusy = ref(false)
const matches = ref([])
const looking = ref(false)
const saving = ref(false)
const error = ref('')
const news = ref('')
const today = new Date().toISOString().slice(0, 10)
const newsEnds = ref(new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10))
const located = computed(() => form.value.lat !== null && form.value.lng !== null)
const pinAt = computed(() => (located.value ? { lat: form.value.lat, lng: form.value.lng } : null))

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
  // The phone's country follows the address, unless it was picked by hand.
  if (m.country && DIAL[m.country] && !ccPicked.value) {
    form.value.phone_country = m.country
    ccFrom.value = 'address'
  }
}
const moved = (at) => {
  form.value.lat = Math.round(at.lat * 1e6) / 1e6
  form.value.lng = Math.round(at.lng * 1e6) / 1e6
}

function pickCover(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  coverBusy.value = true
  const item = startAttachment(file)
  cover.value = URL.createObjectURL(file)
  item.promise
    .then(() => {
      if (item.media) coverId.value = item.media.id
      else error.value = item.error
    })
    .finally(() => (coverBusy.value = false))
}
function removeCover() {
  cover.value = null
  coverId.value = null
}

async function save() {
  error.value = ''
  if (!form.value.name.trim()) return (error.value = t.__('Give the place a name.', 'membuild-community'))
  if (!located.value) return (error.value = t.__('Find the address on the map first.', 'membuild-community'))
  saving.value = true
  try {
    const body = { ...form.value, perk: form.value.category === 'perk' ? form.value.perk : '' }
    if (!props.canEdit || !body.tz) delete body.tz // members don't set it; empty keeps it as it is
    if (props.canEdit) {
      body.access_group = who.value === 'members' ? Number(accessGroup.value) || 0 : 0
      body.week = Object.fromEntries(openDays.value.map(([d]) => [d, week.value[d]]))
    }
    if (coverId.value !== undefined) body.cover_media_id = coverId.value
    const saved = p ? await api.updatePlace(p.id, body) : await api.createPlace(body)
    // Anything new there: a What's new on the new place (checked with it).
    if (!p && news.value.trim()) await api.addPlaceNews(saved.id, { text: news.value.trim(), ends_on: newsEnds.value }).catch(() => {})
    emit('saved', saved)
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

const formId = `mbcom-place-${Math.random().toString(36).slice(2, 8)}`
const title = p ? t.__('Edit place', 'membuild-community') : props.canEdit ? t.__('Add a place', 'membuild-community') : t.__('Suggest a place', 'membuild-community')
</script>

<template>
  <!-- Suggest / Add / Edit a place: its own page (Version 2), in two cards. -->
  <div class="page-in pf-page">
    <button type="button" class="back-link" @click="emit('close')"><Icon name="chevronLeft" :size="18" /> {{ t.__('Places', 'membuild-community') }}</button>
    <h1>{{ title }}</h1>
    <p v-if="!p" class="page-intro">{{ canEdit ? t.__('A spot for members: somewhere to meet up, a partner with a member perk, or a venue for your events.', 'membuild-community') : t.__('A spot you’d recommend to other members: somewhere to meet up, train, or get a member deal. An admin or moderator checks it first.', 'membuild-community') }}</p>

    <form :id="formId" class="place-form" @submit.prevent="save">
      <section class="card box">
        <h2>{{ t.__('Where is it?', 'membuild-community') }}</h2>
        <label class="field">
          <span class="label">{{ t.__('Name', 'membuild-community') }}</span>
          <input v-model="form.name" type="text" maxlength="120" required :placeholder="t.__('Riverside Coffee', 'membuild-community')" />
        </label>

        <div class="field">
          <span class="label">{{ t.__('Address', 'membuild-community') }}</span>
          <div class="find">
            <input v-model="form.address" type="text" maxlength="255" autocomplete="street-address" :placeholder="t.__('Street, city', 'membuild-community')" @keydown.enter.prevent="find" />
            <button type="button" class="btn btn-lg btn-ghost" :disabled="looking" @click="find"><Icon name="search" :size="18" /> {{ looking ? t.__('Finding…', 'membuild-community') : t.__('Find', 'membuild-community') }}</button>
          </div>
          <ul v-if="matches.length > 1" class="matches">
            <li v-for="(m, n) in matches" :key="n"><button type="button" @click="choose(m)"><Icon name="mapPin" :size="14" /> {{ m.label }}</button></li>
          </ul>
          <div v-if="located" class="mini">
            <PlacesMap :editable="pinAt" @move="moved" />
          </div>
          <small v-if="located" class="hint">{{ t.__('Found it. If the pin isn’t quite right, drag it to the door.', 'membuild-community') }}</small>
        </div>
        <label v-if="canEdit" class="field">
          <span class="label">{{ t.__('Time zone', 'membuild-community') }}</span>
          <select v-model="form.tz">
            <option value="">{{ t.__('Not set yet', 'membuild-community') }}</option>
            <option v-for="z in zones" :key="z" :value="z">{{ z.replace(/_/g, ' ') }}</option>
          </select>
          <small>{{ form.tz ? t.sprintf(/* translators: %s: e.g. "Austin time" */ t.__('Events here use this time: %s. Changing it moves the events still to come here.', 'membuild-community'), zoneCity) : t.__('Filled in by the first event held here, or pick it now.', 'membuild-community') }}</small>
        </label>
      </section>

      <section class="card box">
        <h2>{{ t.__('Tell members about it', 'membuild-community') }}</h2>
        <label class="field">
          <span class="label">{{ canEdit ? t.__('About this place', 'membuild-community') : t.__('Why do you recommend it?', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
          <textarea v-model="form.description" rows="3" maxlength="1000" />
        </label>
        <div class="field">
          <span class="label">{{ t.__('Photo', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
          <div v-if="cover" class="cover">
            <img :src="cover" alt="" />
            <label class="btn btn-ghost"><Icon name="image" :size="16" /> {{ t.__('Change photo', 'membuild-community') }}<input class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickCover" /></label>
            <button type="button" class="btn btn-ghost" @click="removeCover">{{ t.__('Remove', 'membuild-community') }}</button>
          </div>
          <label v-else class="drop-photo"><Icon name="image" :size="22" /> {{ t.__('Add a photo', 'membuild-community') }}<input class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickCover" /></label>
        </div>
        <template v-if="canEdit || p">
        <div class="field">
          <span class="label">{{ t.__('What is it?', 'membuild-community') }}</span>
          <div class="kinds" role="radiogroup">
            <button v-for="(c, key) in CATEGORY" :key="key" type="button" role="radio" :aria-checked="form.category === key" :class="{ on: form.category === key }" @click="form.category = key">
              <Icon :name="({ perk: 'gift', meetup: 'users', venue: 'mapPin' })[key] || 'star'" :size="16" /> {{ c.label }}
            </button>
          </div>
        </div>

        <label v-if="form.category === 'perk'" class="field">
          <span class="label">{{ t.__('The perk', 'membuild-community') }}</span>
          <input v-model="form.perk" type="text" maxlength="120" :placeholder="t.__('10% off for members', 'membuild-community')" />
        </label>

          <label class="field">
            <span class="label">{{ t.__('Website', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
            <input v-model="form.website" type="url" inputmode="url" maxlength="300" placeholder="https://" />
          </label>
          <div class="field">
            <span class="label">{{ t.__('Phone', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
            <div class="phone">
              <!-- The country code: shows "PH +63", opens the full list of countries. -->
              <label class="cc">
                <span>{{ ccLabel }}</span> <Icon name="chevronDown" :size="14" />
                <select v-model="form.phone_country" :aria-label="t.__('Country code', 'membuild-community')" @change="ccPicked = true; ccFrom = 'picked'">
                  <option v-for="c in COUNTRIES" :key="c.cc" :value="c.cc">{{ c.name }} (+{{ c.dial }})</option>
                </select>
              </label>
              <input v-model="form.phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="40" :aria-label="t.__('Phone number', 'membuild-community')" />
            </div>
            <small v-if="form.phone.trim()" class="hint">{{ phoneHint }}</small>
          </div>
          <!-- When is it open? Day buttons, then times for each open day. -->
          <div class="field">
            <span class="label">{{ t.__('When is it open?', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
            <div class="days" role="group" :aria-label="t.__('Open days', 'membuild-community')">
              <button v-for="[d, short] in DAYS" :key="d" type="button" :aria-pressed="!!week[d].length" :class="{ on: week[d].length }" @click="toggleDay(d)">{{ short }}</button>
            </div>
            <small v-if="form.hours && !openDays.length" class="hint">{{ t.sprintf(/* translators: %s: the hours as typed before, e.g. "Thursdays 9–11am" */ t.__('Shown now: “%s”. Pick the days it’s open to replace it.', 'membuild-community'), form.hours) }}</small>
            <small v-else class="hint">{{ t.__('Pick the days it’s open, then the times. Two times on one day work too (9–12 and 2–5).', 'membuild-community') }}</small>
            <div v-for="[d, , long] in openDays" :key="d" class="day-row">
              <strong>{{ long }}</strong>
              <div class="times">
                <span v-for="(r, i) in week[d]" :key="i" class="time">
                  <input v-model="r[0]" type="time" :aria-label="t.sprintf(t.__('%s, opens', 'membuild-community'), long)" />
                  <span>{{ t.__('to', 'membuild-community') }}</span>
                  <input v-model="r[1]" type="time" :aria-label="t.sprintf(t.__('%s, closes', 'membuild-community'), long)" />
                  <button v-if="week[d].length > 1" type="button" class="drop-time" :aria-label="t.__('Remove this time', 'membuild-community')" @click="dropTime(d, i)"><Icon name="close" :size="16" /></button>
                </span>
              </div>
              <button v-if="week[d].length < 3" type="button" class="another" @click="addTime(d)">+ {{ t.__('Another time', 'membuild-community') }}</button>
            </div>
            <label v-if="openDays.length > 1" class="same"><input v-model="sameTimes" type="checkbox" /> {{ t.__('Same times every open day', 'membuild-community') }}</label>
          </div>
        </template>
        <div v-if="!p" class="field">
          <span class="label">{{ t.__('Anything new there?', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
          <input v-model="news" type="text" maxlength="200" :placeholder="t.__('For example, “Grand opening Saturday, free class”', 'membuild-community')" />
          <label v-if="news.trim()" class="ends">{{ t.__('Until', 'membuild-community') }} <input v-model="newsEnds" type="date" :min="today" /></label>
          <small>{{ t.__('Shows as a What’s new tag until the date you pick, then disappears.', 'membuild-community') }}</small>
        </div>
      </section>

      <!-- Who can see it? (owners and moderators) -->
      <section v-if="canEdit" class="card box">
        <h2>{{ t.__('Who can see it?', 'membuild-community') }}</h2>
        <div class="cards2" role="radiogroup">
          <button type="button" role="radio" class="pick" :class="{ on: who === 'everyone' }" :aria-checked="who === 'everyone'" @click="who = 'everyone'">
            <span class="pick-ic"><Icon name="users" :size="20" /></span><span class="dot" />
            <strong>{{ t.__('Everyone', 'membuild-community') }}</strong><small>{{ t.__('All members of the community.', 'membuild-community') }}</small>
          </button>
          <button type="button" role="radio" class="pick" :class="{ on: who === 'members' }" :aria-checked="who === 'members'" :disabled="!groups.length" @click="who = 'members'; accessGroup = accessGroup || groups[0]?.id || 0">
            <span class="pick-ic"><Icon name="lock" :size="20" /></span><span class="dot" />
            <strong>{{ t.__('A membership', 'membuild-community') }}</strong><small>{{ groups.length ? t.__('Only members with it.', 'membuild-community') : t.__('Make a membership in Settings first.', 'membuild-community') }}</small>
          </button>
        </div>
        <select v-if="who === 'members' && groups.length" v-model.number="accessGroup" :aria-label="t.__('Membership', 'membuild-community')">
          <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>
        <p v-if="who === 'members'" class="access-note"><Icon name="lock" :size="18" /> {{ t.sprintf(/* translators: %s: a membership */ t.__('Members without %s don’t see this place anywhere: not on the map, in the list or in search, and not on its own page. Events held here still follow their own Who can come?', 'membuild-community'), groups.find((g) => g.id === Number(accessGroup))?.name || '') }}</p>
      </section>

      <p v-if="error" class="error-text" role="alert">{{ error }}</p>
      <div class="actions">
        <button type="button" class="btn btn-lg btn-ghost" @click="emit('close')">{{ t.__('Cancel', 'membuild-community') }}</button>
        <button type="submit" class="btn btn-lg btn-primary" :disabled="saving || coverBusy">
          {{ saving ? t.__('Saving…', 'membuild-community') : p ? t.__('Save', 'membuild-community') : canEdit ? t.__('Add place', 'membuild-community') : t.__('Send suggestion', 'membuild-community') }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.pf-page {
  max-width: 760px;
  margin: 0 auto;
  padding: 20px 16px 48px;
}

.pf-page h1 {
  margin: 6px 0 6px;
  font-size: var(--fs-8xl);
}

.place-form {
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

.label em {
  color: var(--text-2);
  font-style: normal;
  font-weight: 400;
}

.drop-photo {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 110px;
  border: 2px dashed var(--border-strong);
  border-radius: var(--radius);
  color: var(--text-2);
  font-weight: 700;
  cursor: pointer;
}

.drop-photo:hover {
  border-color: var(--accent-text);
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

@media (max-width: 600px) {
  .box {
    padding: 20px 16px;
  }
}

/* Inputs like the other dialogs (Edit profile). */
.place-form input:not([type='file']),
.place-form select,
.place-form textarea {
  width: 100%;
  min-height: 52px;
  padding: 12px 16px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  font-size: var(--fs-xl);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: 400;
  resize: vertical;
}

.place-form input:focus,
.place-form select:focus,
.place-form textarea:focus {
  outline: 2px solid var(--accent);
  outline-offset: -1px;
}

.place-form .btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: none;
}

.find .btn,
.cover .btn:not(.btn-ghost) {
  background: var(--surface-2);
  color: var(--text);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.label {
  font-weight: 700;
  font-size: var(--fs-lg);
}

.field small {
  color: var(--text-2);
  font-size: var(--fs-md);
}

.ends {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 600;
}

.ends input {
  width: auto !important;
}

.kinds {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.kinds button {
  padding: 8px 12px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
  font-weight: 600;
}

.kinds button.on {
  border-color: var(--accent);
  background: var(--accent-soft);
  color: var(--accent-text);
}

.find {
  display: flex;
  gap: 8px;
}

.find input {
  flex: 1;
  min-width: 0;
}

.matches {
  list-style: none;
  margin: 0;
  padding: 4px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}

.matches button {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  width: 100%;
  padding: 8px;
  border-radius: var(--radius-xs);
  background: none;
  color: var(--text);
  text-align: left;
  font-size: var(--fs-md);
}

.matches button:hover {
  background: var(--surface-2);
}

.mini {
  height: 200px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  border: 1px solid var(--border);
}

.hint,
.note {
  color: var(--text-2);
  font-size: var(--fs-sm);
  margin: 0;
}

.more summary {
  cursor: pointer;
  font-weight: 600;
  color: var(--text-2);
}

.more[open] summary {
  margin-bottom: 14px;
}

.more > .field,
.more > .row {
  margin-bottom: 14px;
}

.row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.cover {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.cover img {
  width: 72px;
  height: 52px;
  object-fit: cover;
  border-radius: var(--radius-xs);
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  width: 100%;
}

@media (max-width: 480px) {
  .row {
    grid-template-columns: 1fr;
  }
}

/* Who can see it? Same cards as an event's Who can come? */
.cards2 {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
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

.access-note {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 4px 0 0;
  padding: 14px 18px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.access-note :deep(svg) {
  flex: none;
  margin-top: 3px;
}

/* When is it open? */
.days {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.days button {
  min-width: 58px;
  height: 44px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-base);
  font-weight: 700;
}

.days button.on {
  border: 2px solid var(--accent-text);
  background: var(--accent-soft);
  color: var(--accent-text);
}

.day-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
}

.day-row > strong {
  width: 110px;
  font-size: var(--fs-lg);
}

.times {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.time {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--text-2);
}

.place-form .time input {
  width: 150px;
  min-height: 48px;
}

.drop-time,
.another {
  border: 0;
  background: none;
  color: var(--text-2);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.another {
  margin-left: auto;
}

.same {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
  font-size: var(--fs-base);
}

.place-form .same input {
  width: 20px;
  min-height: 0;
  height: 20px;
  accent-color: var(--accent-text);
}

@media (max-width: 600px) {
  .day-row > strong {
    width: 100%;
  }

  .place-form .time input {
    width: 128px;
  }

  .another {
    margin-left: 0;
  }
}

/* Phone: the country code button beside the number. */
.phone {
  display: flex;
  gap: 10px;
}

.cc {
  position: relative;
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 8px;
  min-height: 52px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  font-size: var(--fs-xl);
  font-weight: 600;
  cursor: pointer;
}

.place-form .cc select {
  position: absolute;
  inset: 0;
  width: 100%;
  min-height: 0;
  opacity: 0;
  cursor: pointer;
}

.cc:focus-within {
  outline: 2px solid var(--accent);
  outline-offset: -1px;
}
</style>
