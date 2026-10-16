<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'
import { startAttachment } from '../lib/attachments.js'
import { t } from '../lib/i18n.js'

/**
 * New event, or edit one (staff). One screen: what, when, where (a link),
 * and who can see it. Times are typed in the member's own time zone and
 * saved with it, so everyone sees them in theirs.
 */
const props = defineProps({
  event: { type: Object, default: null }, // the post being edited (with .event)
  copy: { type: Boolean, default: false }, // "Duplicate": a new event from an old one
})
const emit = defineEmits(['close', 'saved'])

const e = props.event?.event || null
const pad = (n) => String(n).padStart(2, '0')
const local = (iso) => {
  const d = iso ? new Date(iso) : new Date(Date.now() + 86400000)
  if (!iso) d.setMinutes(0, 0, 0)
  return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(d.getHours())}:${pad(d.getMinutes())}` }
}
const start = local(props.copy ? null : e?.at)
const form = ref({
  title: e?.title || '',
  date: start.date,
  time: start.time,
  minutes: e?.minutes || 60,
  url: e?.url || '',
  description: props.event?.content || '',
  group: e?.group?.id || 0,
  recording: props.copy ? '' : e?.recording || '',
})
const cover = ref(props.event?.media?.find((m) => m.kind === 'image')?.url || null)
const coverId = ref(undefined) // undefined: unchanged · null: removed · number: new
const coverBusy = ref(false)
const coverInput = ref(null)
const saving = ref(false)
const error = ref('')
const groups = ref([])
const editing = computed(() => !!props.event && !props.copy)
const title = computed(() => (editing.value ? t.__('Edit event', 'membuild-community') : t.__('New event', 'membuild-community')))
const formId = 'event-form'
const DURATIONS = [15, 30, 45, 60, 90, 120, 180]
const zone = Intl.DateTimeFormat().resolvedOptions().timeZone

onMounted(async () => {
  if (cfg.community.canManage) groups.value = (await api.groups().catch(() => ({ items: [] }))).items || []
})

function pickCover(ev) {
  const file = ev.target.files[0]
  ev.target.value = ''
  if (!file?.type.startsWith('image/')) return
  const item = startAttachment(file)
  cover.value = item.previewUrl
  coverBusy.value = true
  item.promise.then(() => {
    if (item.media) coverId.value = item.media.id
    else {
      error.value = item.error
      cover.value = null
    }
  }).finally(() => (coverBusy.value = false))
}

async function save() {
  error.value = ''
  const at = new Date(`${form.value.date}T${form.value.time}`)
  if (Number.isNaN(at.getTime())) return (error.value = t.__('Pick a date and time.', 'membuild-community'))
  saving.value = true
  const body = {
    title: form.value.title.trim(),
    at: at.toISOString(),
    minutes: Number(form.value.minutes),
    url: form.value.url.trim(),
    description: form.value.description.trim(),
    group: cfg.community.canManage ? Number(form.value.group) : undefined,
    recording: editing.value ? form.value.recording.trim() : undefined,
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
const minutesLabel = (m) => (m < 60 ? t.sprintf(/* translators: %d: minutes */ t.__('%d min', 'membuild-community'), m) : t.sprintf(/* translators: %s: hours, e.g. "1.5" */ t._n('%s hour', '%s hours', m / 60, 'membuild-community'), String(m / 60)))
</script>

<template>
  <BaseDialog :title="title" :width="560" max-height="760px" phone="full" @close="emit('close')">
    <form :id="formId" class="event-form" @submit.prevent="save">
      <label class="field">
        <span class="label">{{ t.__('Title', 'membuild-community') }}</span>
        <input v-model="form.title" type="text" maxlength="200" required :placeholder="t.__('Thursday Q&A', 'membuild-community')" />
      </label>

      <div class="row">
        <label class="field">
          <span class="label">{{ t.__('Date', 'membuild-community') }}</span>
          <input v-model="form.date" type="date" required />
        </label>
        <label class="field">
          <span class="label">{{ t.__('Time', 'membuild-community') }}</span>
          <input v-model="form.time" type="time" required />
        </label>
        <label class="field">
          <span class="label">{{ t.__('Length', 'membuild-community') }}</span>
          <select v-model="form.minutes">
            <option v-for="m in DURATIONS" :key="m" :value="m">{{ minutesLabel(m) }}</option>
          </select>
        </label>
      </div>
      <small class="hint">{{ t.sprintf(/* translators: %s: a time zone name */ t.__('Your time (%s). Members see it in theirs.', 'membuild-community'), zone) }}</small>

      <label class="field">
        <span class="label">{{ t.__('Link', 'membuild-community') }}</span>
        <input v-model="form.url" type="url" inputmode="url" maxlength="500" placeholder="https://zoom.us/j/… · https://youtube.com/live/…" />
        <small>{{ t.__('Zoom, Google Meet or any link opens in a new tab. A YouTube, Vimeo or Twitch live stream plays inside the community, and becomes the replay afterwards.', 'membuild-community') }}</small>
      </label>

      <label class="field">
        <span class="label">{{ t.__('Details', 'membuild-community') }}</span>
        <textarea v-model="form.description" rows="4" maxlength="5000" :placeholder="t.__('What it’s about, and what to bring.', 'membuild-community')" />
      </label>

      <div class="field">
        <span class="label">{{ t.__('Cover (optional)', 'membuild-community') }}</span>
        <div class="cover-row">
          <div class="cover-preview" :style="cover ? { backgroundImage: `url(${cover})` } : null"><Icon v-if="!cover" name="image" :size="22" /></div>
          <button type="button" class="btn btn-ghost" :disabled="coverBusy" @click="coverInput.click()">{{ coverBusy ? t.__('Uploading…', 'membuild-community') : cover ? t.__('Change', 'membuild-community') : t.__('Add a photo', 'membuild-community') }}</button>
          <button v-if="cover" type="button" class="btn btn-ghost" @click="cover = null; coverId = null">{{ t.__('Remove', 'membuild-community') }}</button>
          <input ref="coverInput" class="sr-only" type="file" accept="image/*" @change="pickCover" />
        </div>
      </div>

      <label v-if="groups.length" class="field">
        <span class="label">{{ t.__('Who can see it', 'membuild-community') }}</span>
        <select v-model="form.group">
          <option :value="0">{{ t.__('Everyone', 'membuild-community') }}</option>
          <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>
      </label>

      <label v-if="editing" class="field">
        <span class="label">{{ t.__('Recording (after the event)', 'membuild-community') }}</span>
        <input v-model="form.recording" type="url" inputmode="url" maxlength="500" placeholder="https://" />
        <small>{{ t.__('Paste the replay link. YouTube and Vimeo play on the event page; other links open in a new tab.', 'membuild-community') }}</small>
      </label>

      <p v-if="error" class="error">{{ error }}</p>
    </form>
    <template #footer>
      <button type="button" class="btn btn-ghost" @click="emit('close')">{{ t.__('Cancel', 'membuild-community') }}</button>
      <button type="submit" :form="formId" class="btn btn-primary" :disabled="saving || coverBusy">{{ saving ? t.__('Saving…', 'membuild-community') : editing ? t.__('Save changes', 'membuild-community') : t.__('Publish event', 'membuild-community') }}</button>
    </template>
  </BaseDialog>
</template>

<style scoped>
.event-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px 20px 20px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.label {
  font-size: 13px;
  font-weight: 700;
  color: var(--text-2);
}

.field input,
.field select,
.field textarea {
  width: 100%;
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--surface-2);
  color: var(--text);
  font-size: 15px;
}

.field textarea {
  resize: vertical;
}

.field small,
.hint {
  color: var(--text-3);
  font-size: 12.5px;
}

.hint {
  margin-top: -8px;
}

.row {
  display: grid;
  grid-template-columns: 1.4fr 1fr 1fr;
  gap: 10px;
}

.cover-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.cover-preview {
  display: grid;
  place-items: center;
  flex: none;
  width: 96px;
  aspect-ratio: 16 / 9;
  border-radius: 8px;
  background: var(--surface-2) center / cover no-repeat;
  color: var(--text-3);
}

.btn-ghost {
  color: var(--text);
}

.error {
  margin: 0;
  color: var(--danger);
  font-size: 14px;
}

@media (max-width: 480px) {
  .row {
    grid-template-columns: 1fr 1fr;
  }

  .row .field:first-child {
    grid-column: 1 / -1;
  }
}
</style>
