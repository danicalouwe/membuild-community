<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { api } from '../api.js'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'
import { CATEGORY } from '../lib/places.js'
import { startAttachment } from '../lib/attachments.js'
import { t } from '../lib/i18n.js'

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
  hours: p?.hours || '',
  description: p?.description || '',
})
const cover = ref(p?.cover || null)
const coverId = ref(undefined) // undefined: unchanged · null: removed · number: new
const coverBusy = ref(false)
const matches = ref([])
const looking = ref(false)
const saving = ref(false)
const error = ref('')
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
    if (coverId.value !== undefined) body.cover_media_id = coverId.value
    const saved = p ? await api.updatePlace(p.id, body) : await api.createPlace(body)
    emit('saved', saved)
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

const formId = `mbcom-place-${Math.random().toString(36).slice(2, 8)}` // the Save button sits in the dialog's footer, outside the form
const title = p ? t.__('Edit place', 'membuild-community') : props.canEdit ? t.__('Add a place', 'membuild-community') : t.__('Suggest a place', 'membuild-community')
</script>

<template>
  <BaseDialog :title="title" :width="560" max-height="760px" phone="full" @close="emit('close')">
    <form :id="formId" class="place-form" @submit.prevent="save">
      <label class="field">
        <span class="label">{{ t.__('Name', 'membuild-community') }}</span>
        <input v-model="form.name" type="text" maxlength="120" required :placeholder="t.__('Riverside Coffee', 'membuild-community')" />
      </label>

      <div class="field">
        <span class="label">{{ t.__('What is it?', 'membuild-community') }}</span>
        <div class="kinds" role="radiogroup">
          <button v-for="(c, key) in CATEGORY" :key="key" type="button" role="radio" :aria-checked="form.category === key" :class="{ on: form.category === key }" @click="form.category = key">
            <span aria-hidden="true">{{ c.emoji }}</span> {{ c.label }}
          </button>
        </div>
      </div>

      <label v-if="form.category === 'perk'" class="field">
        <span class="label">{{ t.__('The perk', 'membuild-community') }}</span>
        <input v-model="form.perk" type="text" maxlength="120" :placeholder="t.__('10% off for members', 'membuild-community')" />
      </label>

      <div class="field">
        <span class="label">{{ t.__('Address', 'membuild-community') }}</span>
        <div class="find">
          <input v-model="form.address" type="text" maxlength="255" autocomplete="street-address" :placeholder="t.__('Street, city', 'membuild-community')" @keydown.enter.prevent="find" />
          <button type="button" class="btn" :disabled="looking" @click="find"><Icon name="search" :size="16" /> {{ looking ? t.__('Finding…', 'membuild-community') : t.__('Find', 'membuild-community') }}</button>
        </div>
        <ul v-if="matches.length > 1" class="matches">
          <li v-for="(m, n) in matches" :key="n"><button type="button" @click="choose(m)"><Icon name="mapPin" :size="14" /> {{ m.label }}</button></li>
        </ul>
        <div v-if="located" class="mini">
          <PlacesMap :editable="pinAt" @move="moved" />
        </div>
        <small v-if="located" class="hint">{{ t.__('Drag the pin if it’s not quite right.', 'membuild-community') }}</small>
      </div>

      <details class="more" :open="!!(p && (p.website || p.phone || p.hours || p.description))">
        <summary>{{ t.__('More details (optional)', 'membuild-community') }}</summary>
        <label class="field">
          <span class="label">{{ t.__('Website', 'membuild-community') }}</span>
          <input v-model="form.website" type="url" inputmode="url" maxlength="300" placeholder="https://" />
        </label>
        <div class="row">
          <label class="field">
            <span class="label">{{ t.__('Phone', 'membuild-community') }}</span>
            <input v-model="form.phone" type="tel" maxlength="40" />
          </label>
          <label class="field">
            <span class="label">{{ t.__('Hours', 'membuild-community') }}</span>
            <input v-model="form.hours" type="text" maxlength="200" :placeholder="t.__('Mon–Fri 8–6', 'membuild-community')" />
          </label>
        </div>
        <label class="field">
          <span class="label">{{ t.__('About this place', 'membuild-community') }}</span>
          <textarea v-model="form.description" rows="3" maxlength="1000" />
        </label>
        <div class="field">
          <span class="label">{{ t.__('Photo', 'membuild-community') }}</span>
          <div class="cover">
            <img v-if="cover" :src="cover" alt="" />
            <label class="btn"><Icon name="image" :size="16" /> {{ cover ? t.__('Change photo', 'membuild-community') : t.__('Add a photo', 'membuild-community') }}<input class="sr-only" type="file" accept="image/*" @change="pickCover" /></label>
            <button v-if="cover" type="button" class="btn btn-ghost" @click="removeCover">{{ t.__('Remove', 'membuild-community') }}</button>
          </div>
        </div>
      </details>

      <p v-if="!canEdit && !p" class="note">{{ t.__('An admin checks suggestions before they show for everyone. You’ll see yours on the map in the meantime.', 'membuild-community') }}</p>
      <p v-if="error" class="err" role="alert">{{ error }}</p>
    </form>
    <template #footer>
      <div class="actions">
        <button type="button" class="btn btn-ghost" @click="emit('close')">{{ t.__('Cancel', 'membuild-community') }}</button>
        <button type="submit" :form="formId" class="btn btn-primary" :disabled="saving || coverBusy">
          {{ saving ? t.__('Saving…', 'membuild-community') : p ? t.__('Save', 'membuild-community') : canEdit ? t.__('Add place', 'membuild-community') : t.__('Send suggestion', 'membuild-community') }}
        </button>
      </div>
    </template>
  </BaseDialog>
</template>

<style scoped>
.place-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 18px;
}

/* Inputs like the other dialogs (Edit profile). */
.place-form input:not([type='file']),
.place-form textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: 400;
  resize: vertical;
}

.place-form input:focus,
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
  font-weight: 600;
  font-size: 14px;
}

.kinds {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.kinds button {
  padding: 8px 12px;
  border-radius: 999px;
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
  border-radius: 8px;
  background: none;
  color: var(--text);
  text-align: left;
  font-size: 14px;
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
  font-size: 13px;
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
  border-radius: 8px;
}

.err {
  color: var(--danger);
  margin: 0;
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
</style>
