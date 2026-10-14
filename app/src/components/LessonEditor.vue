<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import { startAttachment } from '../lib/attachments.js'
import { renderMarkdown } from '../lib/markdown.js'
import Icon from './Icon.vue'
import LessonPlayer from './LessonPlayer.vue'
import { t } from '../lib/i18n.js'

// Inline lesson editing for admins: title, video, text, downloads, drip.
const props = defineProps({ lesson: { type: Object, required: true } })
const emit = defineEmits(['saved', 'deleted'])

const title = ref('')
const content = ref('')
const dripDays = ref(0)
const media = ref(null) // current saved/attached media object
const pendingVideo = ref(null) // upload in progress
const resources = ref([]) // { media_id, name, url } | { pending item }
const link = ref('')
const linkError = ref('')
const checking = ref(false)
const preview = ref(false)
const saving = ref(false)
const saved = ref(false)
const error = ref('')
const videoInput = ref(null)
const fileInput = ref(null)

function load(l) {
  title.value = l.title
  content.value = l.content
  dripDays.value = l.drip_days
  media.value = l.media
  pendingVideo.value = null
  resources.value = l.resources.map((r) => ({ ...r }))
  link.value = ''
  linkError.value = ''
  error.value = ''
}
watch(() => props.lesson.id, () => load(props.lesson), { immediate: true })

const rendered = computed(() => renderMarkdown(content.value))
const uploading = computed(() => pendingVideo.value?.status === 'working' || resources.value.some((r) => r.item?.status === 'working'))

function pickVideo(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  pendingVideo.value = startAttachment(file, { compress: false })
}

async function addLink() {
  if (!link.value.trim() || checking.value) return
  checking.value = true
  linkError.value = ''
  try {
    media.value = await api.embed(link.value.trim())
    pendingVideo.value = null
    link.value = ''
  } catch (err) {
    linkError.value = err.message
  } finally {
    checking.value = false
  }
}

function removeVideo() {
  pendingVideo.value?.cancel()
  pendingVideo.value = null
  media.value = null
}

function pickFiles(e) {
  for (const file of e.target.files) resources.value.push({ name: file.name, item: startAttachment(file) })
  e.target.value = ''
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    if (pendingVideo.value) {
      await pendingVideo.value.promise
      if (pendingVideo.value.status !== 'done') throw new Error(pendingVideo.value.error || t.__('Video upload failed', 'membuild-community'))
      media.value = pendingVideo.value.media
      pendingVideo.value = null
    }
    await Promise.all(resources.value.filter((r) => r.item).map((r) => r.item.promise))
    const failed = resources.value.find((r) => r.item?.status === 'error')
    if (failed) throw new Error(t.sprintf(/* translators: 1: sender's first name or file name, 2: the latest message or error message */ t.__('%1$s: %2$s', 'membuild-community'), failed.name, failed.item.error))

    const updated = await api.updateLesson(props.lesson.id, {
      title: title.value,
      content: content.value,
      drip_days: Number(dripDays.value) || 0,
      media_id: media.value?.id || null,
      resources: resources.value.map((r) => ({ media_id: r.item ? r.item.media.id : r.media_id, name: r.name })),
    })
    emit('saved', updated)
    saved.value = true
    setTimeout(() => (saved.value = false), 1600)
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!confirm(t.sprintf(/* translators: %s: lesson title */ t.__('Delete the lesson "%s"? Members\' progress on it is removed too.', 'membuild-community'), props.lesson.title))) return
  const outline = await api.deleteLesson(props.lesson.id)
  emit('deleted', outline)
}
const uploadingLabel = (progress) => t.sprintf(/* translators: %d: percent uploaded */ t.__('uploading %d%%', 'membuild-community'), Math.round(progress * 100))
</script>

<template>
  <div class="editor">
    <label class="field">
      <span>{{ t.__('Lesson title', 'membuild-community') }}</span>
      <input v-model="title" maxlength="200" />
    </label>

    <section class="field">
      <span>{{ t.__('Video', 'membuild-community') }}</span>
      <div v-if="pendingVideo" class="pending">
        <Icon name="video" :size="18" /> {{ pendingVideo.name }} —
        <template v-if="pendingVideo.status === 'working'">{{ uploadingLabel(pendingVideo.progress) }}</template>
        <template v-else-if="pendingVideo.status === 'done'">{{ t.__('ready, save to apply', 'membuild-community') }}</template>
        <span v-else class="err">{{ pendingVideo.error }}</span>
      </div>
      <LessonPlayer v-else-if="media" :media="media" />
      <div class="row">
        <button class="btn btn-ghost" type="button" @click="videoInput.click()"><Icon name="video" :size="16" /> {{ t.__('Upload video', 'membuild-community') }}</button>
        <form class="link" @submit.prevent="addLink">
          <input v-model="link" type="url" :placeholder="t.__('…or paste a YouTube / Vimeo link', 'membuild-community')" :aria-label="t.__('Video link', 'membuild-community')" />
          <button class="btn btn-ghost" :disabled="!link || checking">{{ checking ? t.__('Checking…', 'membuild-community') : t.__('Add', 'membuild-community') }}</button>
        </form>
        <button v-if="media || pendingVideo" class="btn btn-ghost danger" type="button" @click="removeVideo">{{ t.__('Remove', 'membuild-community') }}</button>
      </div>
      <p v-if="linkError" class="err">{{ linkError }}</p>
      <input ref="videoInput" class="sr-only" type="file" accept="video/*" @change="pickVideo" />
    </section>

    <section class="field">
      <div class="label-row">
        <span>{{ t.__('Lesson text', 'membuild-community') }}</span>
        <button type="button" class="link-btn" @click="preview = !preview">{{ preview ? t.__('Edit', 'membuild-community') : t.__('Preview', 'membuild-community') }}</button>
      </div>
      <div v-if="preview" class="md preview-box" v-html="rendered" />
      <textarea v-else v-model="content" rows="10" :placeholder="t.__('Write the lesson. Use ## for headings, **bold**, - for lists, and paste links.', 'membuild-community')" />
    </section>

    <section class="field">
      <span>{{ t.__('Downloads', 'membuild-community') }}</span>
      <ul v-if="resources.length" class="files">
        <li v-for="(r, i) in resources" :key="i">
          <Icon name="share" :size="16" />
          <span class="fname">{{ r.name }}</span>
          <span v-if="r.item?.status === 'working'" class="muted">{{ Math.round(r.item.progress * 100) }}%</span>
          <span v-else-if="r.item?.status === 'error'" class="err">{{ r.item.error }}</span>
          <button type="button" :aria-label="t.__('Remove file', 'membuild-community')" @click="resources.splice(i, 1)"><Icon name="close" :size="14" /></button>
        </li>
      </ul>
      <button class="btn btn-ghost" type="button" @click="fileInput.click()"><Icon name="plus" :size="16" /> {{ t.__('Add file', 'membuild-community') }}</button>
      <input ref="fileInput" class="sr-only" type="file" multiple accept=".pdf,.zip,.txt,.docx,.xlsx,.pptx,image/*" @change="pickFiles" />
    </section>

    <label class="field drip">
      <span>{{ t._x('Opens', 'lesson drip setting label', 'membuild-community') }}</span>
      <div class="row">
        <input v-model.number="dripDays" type="number" min="0" max="3650" :aria-label="t.__('Days after someone joins', 'membuild-community')" />
        <span class="muted">{{ t.__('days after someone joins. Leave at 0 to open right away.', 'membuild-community') }}</span>
      </div>
    </label>

    <p v-if="error" class="err">{{ error }}</p>
    <div class="actions">
      <button class="btn btn-ghost danger" type="button" @click="remove"><Icon name="trash" :size="16" /> {{ t.__('Delete lesson', 'membuild-community') }}</button>
      <button class="btn btn-primary" type="button" :disabled="saving" @click="save">
        {{ saving ? (uploading ? t.__('Uploading…', 'membuild-community') : t.__('Saving…', 'membuild-community')) : saved ? t.__('Saved ✓', 'membuild-community') : t.__('Save lesson', 'membuild-community') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.editor {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field > span,
.label-row > span {
  font-weight: 650;
  font-size: 14px;
  color: var(--text-2);
}

.label-row {
  display: flex;
  justify-content: space-between;
}

input,
textarea {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface-2);
}

textarea {
  resize: vertical;
  font-family: inherit;
  line-height: 1.5;
}

.row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.link {
  display: flex;
  flex: 1;
  min-width: 240px;
  gap: 8px;
}

.link input {
  height: 36px;
  padding: 0 12px;
}

.drip input {
  width: 90px;
}

.pending {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 12px;
  border-radius: 10px;
  background: var(--surface-2);
  font-size: 14px;
}

.files {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.files li {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border-radius: 8px;
  background: var(--surface-2);
  font-size: 14px;
}

.fname {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.files button {
  color: var(--text-3);
}

.preview-box {
  padding: 12px 14px;
  border: 1px dashed var(--border);
  border-radius: 10px;
}

.link-btn {
  color: var(--accent-text);
  font-weight: 600;
  font-size: 14px;
}

.muted {
  color: var(--text-3);
  font-size: 13px;
}

.err {
  margin: 0;
  color: var(--danger);
  font-size: 13px;
}

.btn {
  color: var(--text);
}

.btn.btn-primary {
  color: var(--accent-ink);
}

.danger {
  color: var(--danger);
}

.actions {
  display: flex;
  justify-content: space-between;
  padding-top: 8px;
  border-top: 1px solid var(--border);
}
</style>
