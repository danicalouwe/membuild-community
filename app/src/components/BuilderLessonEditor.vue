<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api.js'
import { startAttachment } from '../lib/attachments.js'
import { extOfName, fileSize, kindName, niceName } from '../lib/files.js'
import FileTile from './FileTile.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import Icon from './Icon.vue'
import LessonPlayer from './LessonPlayer.vue'
import RichEditor from './RichEditor.vue'
import { t } from '../lib/i18n.js'

/**
 * Editing one lesson (Version 2 builder): it saves by itself a moment after
 * each change and always says whether it did. Video, text, files, when it
 * opens, then Previous / Next / Done, and a Danger zone at the bottom.
 */
const props = defineProps({
  lesson: { type: Object, required: true },
  course: { type: Object, required: true },
})
const emit = defineEmits(['saved', 'deleted', 'go', 'done', 'status'])

const title = ref('')
const content = ref('')
const opens = ref('now') // now | days | date
const days = ref(7)
const openOn = ref('') // YYYY-MM-DD
const media = ref(null)
const pendingVideo = ref(null)
const resources = ref([])
const showLink = ref(false)
const link = ref('')
const linkError = ref('')
const checking = ref(false)
const status = ref('saved') // saved | saving | error
const saveError = ref('')
const asking = ref(false) // delete dialog
const deleting = ref(false)
const videoInput = ref(null)
const fileInput = ref(null)
let loading = false

function load(l) {
  loading = true
  title.value = l.title
  content.value = l.content || ''
  opens.value = l.open_on ? 'date' : l.drip_days > 0 ? 'days' : 'now'
  days.value = l.drip_days > 0 ? l.drip_days : 7
  openOn.value = l.open_on || ''
  media.value = l.media
  pendingVideo.value = null
  resources.value = l.resources.map((r) => ({ ...r }))
  showLink.value = false
  link.value = ''
  linkError.value = ''
  status.value = 'saved'
  saveError.value = ''
  setTimeout(() => (loading = false))
}
watch(() => props.lesson.id, () => load(props.lesson), { immediate: true })
watch(status, (s) => emit('status', s), { immediate: true })

const all = computed(() => props.course.modules.flatMap((m) => m.lessons.map((l) => ({ ...l, module: m.title }))))
const index = computed(() => all.value.findIndex((l) => l.id === props.lesson.id))
const here = computed(() => all.value[index.value])
const prev = computed(() => all.value[index.value - 1] || null)
const next = computed(() => all.value[index.value + 1] || null)
const dripDays = computed(() => (opens.value === 'days' ? Math.max(1, Math.min(365, Number(days.value) || 1)) : 0))
// A lesson that opens before the one above it would show up first: say so, and offer the fix.
const earlier = computed(() => (opens.value !== 'date' && prev.value && !prev.value.open_on && prev.value.drip_days > dripDays.value ? prev.value : null))
const todayIso = new Date().toISOString().slice(0, 10)
const dateText = computed(() =>
  openDate.value
    ? t.sprintf(/* translators: %s: a date */ t.__('Everyone can open it on %s, whenever they started the course.', 'membuild-community'), new Date(`${openDate.value}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }))
    : t.__('Pick the day it opens.', 'membuild-community'),
)
const whereLabel = computed(() => (here.value ? t.sprintf(/* translators: 1: lesson number, 2: number of lessons, 3: section name */ t.__('Lesson %1$d of %2$d · %3$s', 'membuild-community'), index.value + 1, all.value.length, here.value.module) : ''))
const uploading = computed(() => pendingVideo.value?.status === 'working' || resources.value.some((r) => r.item?.status === 'working'))

/* ---------- Saving by itself ---------- */
let timer = null
let queued = false
let running = null
function later(ms = 900) {
  if (loading) return
  status.value = 'saving'
  clearTimeout(timer)
  timer = setTimeout(save, ms)
}
const openDate = computed(() => (opens.value === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(openOn.value) ? openOn.value : ''))
watch([title, content, dripDays, openDate], () => later())
watch([media, () => resources.value.length], () => later(200))

async function save() {
  clearTimeout(timer)
  if (running) {
    queued = true
    return running
  }
  running = (async () => {
    status.value = 'saving'
    saveError.value = ''
    try {
      if (pendingVideo.value) {
        await pendingVideo.value.promise
        if (pendingVideo.value.status !== 'done') throw new Error(pendingVideo.value.error || t.__('The video didn’t upload.', 'membuild-community'))
        media.value = pendingVideo.value.media
        pendingVideo.value = null
      }
      await Promise.all(resources.value.filter((r) => r.item).map((r) => r.item.promise))
      const failed = resources.value.find((r) => r.item?.status === 'error')
      if (failed) throw new Error(failed.item.error)
      const updated = await api.updateLesson(props.lesson.id, {
        title: title.value.trim() || t.__('New lesson', 'membuild-community'),
        content: content.value,
        drip_days: dripDays.value,
        open_on: openDate.value,
        media_id: media.value?.id || null,
        resources: resources.value.map((r) => ({ media_id: r.item ? r.item.media.id : r.media_id, name: r.name })),
      })
      resources.value = updated.resources.map((r) => ({ ...r }))
      emit('saved', updated)
      status.value = 'saved'
    } catch (err) {
      status.value = 'error'
      saveError.value = err.message
    } finally {
      running = null
      if (queued) {
        queued = false
        save()
      }
    }
  })()
  return running
}
/** Leaving the lesson: whatever is waiting is saved first. */
async function flush() {
  if (status.value === 'saving' || timer) await save()
  return status.value !== 'error'
}
defineExpose({ flush })
onBeforeUnmount(() => {
  if (status.value === 'saving') save()
})

async function go(l) {
  if (await flush()) emit('go', l.id)
}
async function done() {
  if (await flush()) emit('done')
}

/* ---------- Video and files ---------- */
function pickVideo(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  pendingVideo.value = startAttachment(file, { compress: false })
  status.value = 'saving'
  pendingVideo.value.promise.finally(() => later(100))
}
function dropVideo(e) {
  const file = [...(e.dataTransfer?.files || [])].find((f) => f.type.startsWith('video/'))
  if (file) pickVideo({ target: { files: [file], value: '' } })
}
async function addLink() {
  if (!link.value.trim() || checking.value) return
  checking.value = true
  linkError.value = ''
  try {
    media.value = await api.embed(link.value.trim())
    pendingVideo.value = null
    link.value = ''
    showLink.value = false
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
const extOf = (r) => r.ext || extOfName(r.name)

function pickFiles(e) {
  for (const file of e.target.files) {
    const item = startAttachment(file)
    // Starts as the file's own name, made readable; the owner can change it right here.
    resources.value.push({ name: niceName(file.name) || file.name, ext: extOfName(file.name), item })
    item.promise.finally(() => later(100))
  }
  e.target.value = ''
}

async function remove() {
  deleting.value = true
  try {
    const outline = await api.deleteLesson(props.lesson.id)
    asking.value = false
    emit('deleted', outline)
  } catch (err) {
    saveError.value = err.message
    status.value = 'error'
  } finally {
    deleting.value = false
  }
}

const afterDays = (n) => t.sprintf(/* translators: %d: days */ t._n('%d day', '%d days', n, 'membuild-community'), n)
const uploadingLabel = (p) => t.sprintf(/* translators: %d: percent uploaded */ t.__('Uploading… %d%%', 'membuild-community'), Math.round((p || 0) * 100))
const earlierLabel = computed(() =>
  earlier.value
    ? t.sprintf(/* translators: 1: the lesson above, 2: its days */ t.__('“%1$s” opens after %2$s, so members will see this one first.', 'membuild-community'), earlier.value.title, afterDays(earlier.value.drip_days))
    : '',
)
const makeSame = computed(() => (earlier.value ? t.sprintf(/* translators: %s: e.g. "10 days" */ t.__('Make it %s too', 'membuild-community'), afterDays(earlier.value.drip_days)) : ''))
function useEarlier() {
  opens.value = 'days'
  days.value = earlier.value.drip_days
}
const shortDate = (d) => d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
const exampleText = computed(() => {
  const start = new Date()
  const open = new Date(start.getTime() + dripDays.value * 86400000)
  return t.sprintf(/* translators: 1: a date, 2: a later date */ t.__('Pretend a member named Sam starts this course on %1$s. Sam can open this lesson on %2$s. Every member gets their own date, counted from the day they start.', 'membuild-community'), shortDate(start), shortDate(open))
})
const deleteTitle = computed(() => t.sprintf(/* translators: %s: lesson title */ t.__('Delete “%s”?', 'membuild-community'), props.lesson.title))
</script>

<template>
  <div class="ble">
    <div v-if="status === 'error'" class="save-error" role="alert">
      <Icon name="flag" :size="20" />
      <span><strong>{{ t.__('Not saved.', 'membuild-community') }}</strong> {{ t.__('Check your internet, then try again.', 'membuild-community') }} <small v-if="saveError">({{ saveError }})</small></span>
      <button class="btn btn-lg btn-primary" @click="save">{{ t.__('Try again', 'membuild-community') }}</button>
    </div>

    <div class="where">
      <span>{{ whereLabel }}</span>
      <span class="saved" :class="status" role="status">
        <template v-if="status === 'saving'"><span class="spin" /> {{ t.__('Saving…', 'membuild-community') }}</template>
        <template v-else-if="status === 'error'"><Icon name="flag" :size="15" /> {{ t.__('Couldn’t save', 'membuild-community') }}</template>
        <template v-else><Icon name="check" :size="15" /> {{ t.__('All changes saved', 'membuild-community') }}</template>
      </span>
    </div>

    <input v-model="title" class="title" maxlength="200" :aria-label="t.__('Lesson name', 'membuild-community')" :placeholder="t.__('Lesson name', 'membuild-community')" />

    <section class="box">
      <h3>{{ t.__('Video', 'membuild-community') }}</h3>
      <p class="sub">{{ t.__('Optional. Members watch it at the top of the lesson.', 'membuild-community') }}</p>
      <div v-if="media && !pendingVideo" class="have-video">
        <LessonPlayer :media="media" />
        <button class="btn btn-lg btn-ghost" type="button" @click="removeVideo"><Icon name="trash" :size="18" /> {{ t.__('Remove video', 'membuild-community') }}</button>
      </div>
      <div v-else-if="pendingVideo" class="pending">
        <Icon name="video" :size="20" />
        <span>
          <strong>{{ pendingVideo.name }}</strong>
          <small v-if="pendingVideo.status === 'working'">{{ uploadingLabel(pendingVideo.progress) }} {{ t.__('You can keep writing.', 'membuild-community') }}</small>
          <small v-else-if="pendingVideo.status === 'error'" class="err">{{ pendingVideo.error }}</small>
        </span>
        <button class="btn btn-lg btn-ghost" type="button" @click="removeVideo">{{ t.__('Cancel', 'membuild-community') }}</button>
      </div>
      <div v-else class="drop" @dragover.prevent @drop.prevent="dropVideo">
        <strong>{{ t.__('Drop a video file here', 'membuild-community') }}</strong>
        <div class="drop-btns">
          <button class="btn btn-lg btn-ghost" type="button" @click="videoInput.click()">{{ t.__('Choose a video', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-ghost" type="button" @click="showLink = !showLink">{{ t.__('Paste a YouTube or Vimeo link', 'membuild-community') }}</button>
        </div>
        <form v-if="showLink" class="link" @submit.prevent="addLink">
          <input v-model="link" type="url" placeholder="https://youtu.be/…" :aria-label="t.__('Video link', 'membuild-community')" />
          <button class="btn btn-lg btn-primary" :disabled="!link || checking">{{ checking ? t.__('Checking…', 'membuild-community') : t.__('Add video', 'membuild-community') }}</button>
        </form>
        <p v-if="linkError" class="err">{{ linkError }}</p>
      </div>
      <input ref="videoInput" class="sr-only" type="file" accept="video/*" @change="pickVideo" />
    </section>

    <section class="box">
      <h3>{{ t.__('Text', 'membuild-community') }}</h3>
      <p class="sub">{{ t.__('Optional. Words members read under the video.', 'membuild-community') }}</p>
      <RichEditor v-model="content" :label="t.__('Lesson text', 'membuild-community')" :placeholder="t.__('Write what members should know. Type normally, then use the buttons above to make words bold or add a list.', 'membuild-community')" />
    </section>

    <section class="box">
      <h3>{{ t.__('Files to download', 'membuild-community') }}</h3>
      <p class="sub">{{ t.__('Worksheets, checklists or PDFs.', 'membuild-community') }}</p>
      <ul v-if="resources.length" class="files">
        <li v-for="(r, i) in resources" :key="r.media_id || r.item?.key || i">
          <FileTile :ext="extOf(r)" />
          <span class="fname">
            <!-- The name members see on the lesson. The file's type shows on its own, so no ".pdf". -->
            <input v-model="r.name" type="text" maxlength="120" :aria-label="t.__('File name members see', 'membuild-community')" :placeholder="t.__('Name this file', 'membuild-community')" @blur="r.name = r.name.trim(); later(0)" @input="later(900)" />
            <small v-if="r.item?.status === 'working'">{{ uploadingLabel(r.item.progress) }}</small>
            <small v-else-if="r.item?.status === 'error'" class="err">{{ r.item.error }}</small>
            <small v-else>{{ kindName(extOf(r)) }}<template v-if="r.size"> · {{ fileSize(r.size) }}</template></small>
          </span>
          <button class="btn btn-lg btn-ghost" type="button" @click="resources.splice(i, 1)">{{ t.__('Remove', 'membuild-community') }}</button>
        </li>
      </ul>
      <button class="btn btn-lg btn-ghost" type="button" @click="fileInput.click()"><Icon name="plus" :size="18" /> {{ t.__('Add a file', 'membuild-community') }}</button>
      <input ref="fileInput" class="sr-only" type="file" multiple @change="pickFiles" />
    </section>

    <section class="box">
      <h3>{{ t.__('When does this lesson open?', 'membuild-community') }}</h3>
      <label class="choice" :class="{ on: opens === 'now' }">
        <input v-model="opens" type="radio" value="now" />
        <span>
          <strong>{{ t.__('Right away', 'membuild-community') }}</strong>
          <small>{{ t.__('Members can open it as soon as they start the course.', 'membuild-community') }}</small>
        </span>
      </label>
      <label class="choice" :class="{ on: opens === 'days' }">
        <input v-model="opens" type="radio" value="days" />
        <span>
          <strong>{{ t.__('Some days after they start the course', 'membuild-community') }}</strong>
          <small>{{ t.__('Good for a slow, steady start.', 'membuild-community') }}</small>
          <span v-if="opens === 'days'" class="days">
            {{ t.__('Open it', 'membuild-community') }}
            <input v-model="days" type="number" min="1" max="365" :aria-label="t.__('Days', 'membuild-community')" />
            {{ t.__('days after they start the course.', 'membuild-community') }}
          </span>
          <span v-if="opens === 'days'" class="example">
            <b>{{ t.__('Example only', 'membuild-community') }}</b>
            {{ exampleText }}
          </span>
        </span>
      </label>
      <label class="choice" :class="{ on: opens === 'date' }">
        <input v-model="opens" type="radio" value="date" />
        <span>
          <strong>{{ t.__('On a date you pick', 'membuild-community') }}</strong>
          <small>{{ t.__('Good for a live launch, when everyone gets it on the same day.', 'membuild-community') }}</small>
          <span v-if="opens === 'date'" class="days">
            <input v-model="openOn" type="date" class="date" :min="todayIso" :aria-label="t.__('Opening day', 'membuild-community')" />
          </span>
          <span v-if="opens === 'date'" class="example plain">{{ dateText }}</span>
        </span>
      </label>
      <div v-if="earlier" class="warn">
        <span><strong>{{ t.__('This lesson opens before the one above it.', 'membuild-community') }}</strong> {{ earlierLabel }}</span>
        <button class="btn btn-lg" type="button" @click="useEarlier">{{ makeSame }}</button>
      </div>
    </section>

    <footer class="nav">
      <button class="btn btn-lg btn-ghost" :disabled="!prev || uploading" @click="go(prev)"><Icon name="arrowRight" :size="18" class="flip" /> {{ t.__('Previous lesson', 'membuild-community') }}</button>
      <span class="grow" />
      <button class="btn btn-lg btn-ghost" :disabled="!next || uploading" @click="go(next)">{{ t.__('Next lesson', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
      <button class="btn btn-lg btn-primary" :disabled="uploading" @click="done">{{ t.__('Done', 'membuild-community') }}</button>
    </footer>

    <section class="danger">
      <span>
        <strong>{{ t.__('Danger zone', 'membuild-community') }}</strong>
        <small>{{ t.__('Deleting removes this lesson, its files and its questions for everyone. It can’t be undone.', 'membuild-community') }}</small>
      </span>
      <button class="btn btn-lg danger-btn" @click="asking = true">{{ t.__('Delete this lesson…', 'membuild-community') }}</button>
    </section>

    <ConfirmDialog v-if="asking" danger :title="deleteTitle" :confirm="t.__('Delete forever', 'membuild-community')" :cancel="t.__('Keep the lesson', 'membuild-community')" :busy="deleting" @confirm="remove" @close="asking = false">
      <p>{{ t.__('This removes the lesson, its video and files, the questions under it, and every member’s progress on it.', 'membuild-community') }}</p>
      <p class="note"><strong>{{ t.__('It can’t be restored.', 'membuild-community') }}</strong></p>
    </ConfirmDialog>
  </div>
</template>

<style scoped>
.ble {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.save-error {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 10px 10px 16px;
  border-radius: 14px;
  background: var(--danger-soft);
  color: var(--danger-ink);
  font-size: 16px;
}

.save-error span {
  flex: 1;
}

.where {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 15.5px;
  color: var(--text-2);
}

.saved {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: 999px;
  background: var(--ok-soft);
  color: var(--ok-text);
  font-size: 14.5px;
  font-weight: 700;
}

.saved.saving {
  background: var(--surface-2);
  color: var(--text-2);
}

.saved.error {
  background: var(--danger-soft);
  color: var(--danger-text);
}

.spin {
  width: 12px;
  height: 12px;
  border: 2px solid currentColor;
  border-right-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.title {
  height: 60px;
  padding: 0 16px;
  border: 1px solid var(--border-strong, #c9ced6);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font-size: 22px;
  font-weight: 750;
}

.box {
  padding: 18px 20px 20px;
  border: 1px solid var(--border);
  border-radius: 16px;
  background: var(--surface);
}

.box h3 {
  margin: 0;
  font-size: 19px;
}

.sub {
  margin: 4px 0 14px;
  font-size: 15.5px;
  color: var(--text-2);
}

.drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 22px 16px;
  border: 2px dashed var(--border-strong, #c9ced6);
  border-radius: 14px;
  text-align: center;
  font-size: 17px;
}

.drop-btns,
.link {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
}

.link input {
  min-width: 280px;
  height: 52px;
  padding: 0 14px;
  border: 1px solid var(--border-strong, #c9ced6);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font-size: 17px;
}

.have-video {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
}

.have-video :deep(.player) {
  width: 100%;
  max-width: 560px;
}

.pending,
.files li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
}

.pending span,
.fname {
  flex: 1;
  min-width: 0;
}

.pending strong,
.fname strong {
  display: block;
  font-size: 16.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fname input {
  width: 100%;
  min-height: 44px;
  margin-bottom: 2px;
  padding: 8px 12px;
  border: 1px solid var(--border-strong);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: 16.5px;
  font-weight: 650;
}

.fname input:focus {
  outline: 2px solid var(--accent);
  outline-offset: 1px;
}

.pending small,
.fname small {
  display: block;
  font-size: 14.5px;
  color: var(--text-2);
}

.files {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0 0 12px;
  padding: 0;
  list-style: none;
}

.err {
  color: var(--danger-text) !important;
}

.choice {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin-top: 10px;
  padding: 14px 16px;
  border: 1px solid var(--border);
  border-radius: 14px;
  cursor: pointer;
}

.choice.on {
  border: 2px solid var(--accent);
  background: var(--accent-soft);
}

.choice input {
  width: 22px;
  height: 22px;
  margin-top: 2px;
  accent-color: var(--accent-text);
}

.choice strong {
  display: block;
  font-size: 17px;
}

.choice small {
  display: block;
  margin-top: 2px;
  font-size: 15px;
  color: var(--text-2);
}

.days {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 12px;
  font-size: 16.5px;
}

.days input {
  width: 84px;
  height: 52px;
  padding: 0 10px;
  border: 1px solid var(--border-strong, #c9ced6);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font-size: 18px;
  text-align: center;
}

.example {
  display: block;
  margin-top: 12px;
  padding: 12px 14px;
  border: 1px dashed var(--border-strong, #c9ced6);
  border-radius: 12px;
  background: var(--surface);
  font-size: 15.5px;
  line-height: 1.55;
  color: var(--text);
}

.days input.date {
  width: auto;
  min-width: 200px;
  padding: 0 14px;
  text-align: left;
}

.example.plain {
  border-style: solid;
}

.example b {
  display: inline-block;
  margin-right: 6px;
  padding: 2px 9px;
  border-radius: 999px;
  background: var(--text);
  color: var(--surface);
  font-size: 13px;
}

.warn {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: 12px;
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: 15.5px;
}

.warn span {
  flex: 1;
  min-width: 220px;
}

.warn .btn {
  background: var(--surface);
  color: var(--text);
}

.nav {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding-top: 6px;
}

.grow {
  flex: 1;
}

.flip {
  transform: scaleX(-1);
}

.danger {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 10px;
  padding: 16px 18px;
  border: 1px solid var(--danger-line);
  border-radius: 14px;
  background: var(--danger-soft);
}

.danger span {
  flex: 1;
  min-width: 220px;
}

.danger strong {
  display: block;
  color: var(--danger-text);
  font-size: 17px;
}

.danger small {
  display: block;
  margin-top: 2px;
  color: var(--danger-ink);
  font-size: 15px;
}

.danger-btn {
  border: 2px solid var(--danger-text);
  background: var(--surface);
  color: var(--danger-text);
}
</style>
