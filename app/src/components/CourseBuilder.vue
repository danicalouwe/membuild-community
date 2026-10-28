<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { startAttachment } from '../lib/attachments.js'
import { levelLabel } from '../config.js'
import BaseDialog from './BaseDialog.vue'
import BuilderLessonEditor from './BuilderLessonEditor.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import CourseMap from './CourseMap.vue'
import RichEditor from './RichEditor.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
import { offerButton } from '../lib/offer.js'

/**
 * The course builder (Version 2), for owners: Lessons (sections and lessons,
 * one Edit button each, Change the order) and Course settings (name, who can
 * take it, cover, draft or published, Danger zone). Everything saves by itself.
 */
const props = defineProps({
  course: { type: Object, required: true },
  lessonId: { type: Number, default: null },
})
const emit = defineEmits(['update:course', 'exit', 'preview'])

const route = useRoute()
const router = useRouter()
const part = computed(() => (['settings', 'map'].includes(route.query.part) ? route.query.part : 'lessons'))
const setPart = (p) => router.replace({ name: 'course', params: { courseId: props.course.id }, query: { edit: 1, part: p === 'lessons' ? undefined : p } })

const modules = computed(() => props.course.modules)
const allLessons = computed(() => modules.value.flatMap((m) => m.lessons))
const numberOf = (l) => allLessons.value.indexOf(l) + 1
const busy = ref(false)
const error = ref('')

async function run(fn) {
  busy.value = true
  error.value = ''
  try {
    const outline = await fn()
    if (outline?.modules) emit('update:course', outline)
    return outline
  } catch (err) {
    error.value = err.message
  } finally {
    busy.value = false
  }
}
const reload = async () => emit('update:course', await api.course(props.course.id))

/* ---------- Naming boxes: a new section, a new lesson, a renamed section ---------- */
const naming = ref(null) // { kind: 'section' | 'lesson' | 'rename', module? }
const nameValue = ref('')
const nameInput = ref(null)
function ask(kind, module = null) {
  naming.value = { kind, module }
  nameValue.value = kind === 'rename' ? module.title : ''
  nextTick(() => nameInput.value?.focus())
}
const nameTitle = computed(() => ({ section: t.__('Add a section', 'membuild-community'), lesson: t.__('Add a lesson', 'membuild-community'), rename: t.__('Rename section', 'membuild-community') })[naming.value?.kind])
const nameLabel = computed(() => (naming.value?.kind === 'lesson' ? t.__('Lesson name', 'membuild-community') : t.__('Section name', 'membuild-community')))
const nameButton = computed(() => ({ section: t.__('Add section', 'membuild-community'), lesson: t.__('Add lesson', 'membuild-community'), rename: t.__('Save name', 'membuild-community') })[naming.value?.kind])
async function submitName() {
  const name = nameValue.value.trim()
  if (!name || busy.value) return
  const { kind, module } = naming.value
  if (kind === 'section') {
    await run(() => api.createModule(props.course.id, name))
  } else if (kind === 'rename') {
    await run(() => api.updateModule(module.id, name))
  } else {
    busy.value = true
    try {
      // The very first lesson: a course needs a section to hold it.
      const target = module || modules.value[0] || (await api.createModule(props.course.id, t.__('Getting started', 'membuild-community'))).modules.at(-1)
      const created = await api.createLesson(target.id, name)
      await reload()
      editLesson(created.id)
    } catch (err) {
      error.value = err.message
    } finally {
      busy.value = false
    }
  }
  naming.value = null
}

/* ---------- Section menu and deleting a section ---------- */
const menuFor = ref(null)
const removing = ref(null) // a section about to be deleted
function closeMenus() {
  menuFor.value = null
  moveFor.value = null
}
window.addEventListener('click', closeMenus)
onBeforeUnmount(() => window.removeEventListener('click', closeMenus))
async function removeSection() {
  const m = removing.value
  await run(() => api.deleteModule(m.id))
  removing.value = null
}

/* ---------- Changing the order ---------- */
const ordering = ref(false)
const moveFor = ref(null) // 'm<id>' or 'l<id>'
const orderOf = () => modules.value.map((m) => ({ id: m.id, lessons: m.lessons.map((l) => l.id) }))
const saveOrder = () => run(() => api.updateStructure(props.course.id, orderOf()))
// Each move saves at once; Cancel puts back the order from when you started and saves that.
let orderAtStart = null
function startOrdering() {
  orderAtStart = orderOf()
  ordering.value = true
}
function cancelOrdering() {
  ordering.value = false
  moveFor.value = null
  if (!orderAtStart || JSON.stringify(orderAtStart) === JSON.stringify(orderOf())) return
  const byId = new Map(modules.value.map((m) => [m.id, m]))
  const lessons = new Map(modules.value.flatMap((m) => m.lessons).map((l) => [l.id, l]))
  const restored = orderAtStart.map((o) => byId.get(o.id)).filter(Boolean)
  restored.forEach((m, i) => (m.lessons = orderAtStart[i].lessons.map((id) => lessons.get(id)).filter(Boolean)))
  modules.value.splice(0, modules.value.length, ...restored)
  saveOrder()
}
function moveSection(i, delta) {
  const list = modules.value
  const j = i + delta
  if (j < 0 || j >= list.length) return
  ;[list[i], list[j]] = [list[j], list[i]]
  moveFor.value = null
  saveOrder()
}
function moveLesson(m, i, delta) {
  const j = i + delta
  if (j < 0 || j >= m.lessons.length) return
  ;[m.lessons[i], m.lessons[j]] = [m.lessons[j], m.lessons[i]]
  moveFor.value = null
  saveOrder()
}
function moveTo(from, i, to) {
  const [l] = from.lessons.splice(i, 1)
  to.lessons.push(l)
  moveFor.value = null
  saveOrder()
}
// Dragging the dots does the same, for those who like to drag.
let dragging = null // { type: 'm', i } | { type: 'l', m, i }
function dragStart(e, d) {
  dragging = d
  e.dataTransfer.effectAllowed = 'move'
}
function dropOn(target) {
  const d = dragging
  dragging = null
  if (!d) return
  if (d.type === 'm' && target.type === 'm' && d.i !== target.i) {
    const [m] = modules.value.splice(d.i, 1)
    modules.value.splice(target.i, 0, m)
  } else if (d.type === 'l') {
    const [l] = d.m.lessons.splice(d.i, 1)
    if (target.type === 'l') target.m.lessons.splice(target.i, 0, l)
    else modules.value[target.i].lessons.push(l)
  } else return
  saveOrder()
}

/* ---------- One lesson in the editor ---------- */
const lesson = ref(null)
const lessonError = ref('')
const editor = ref(null)
async function loadLesson(id) {
  lesson.value = null
  lessonError.value = ''
  if (!id) return
  try {
    lesson.value = await api.lesson(id)
  } catch (err) {
    lessonError.value = err.message
  }
}
watch(() => props.lessonId, loadLesson, { immediate: true })
function editLesson(id) {
  router.push({ name: 'course', params: { courseId: props.course.id, lessonId: id }, query: { edit: 1 } })
}
async function backToList() {
  if (editor.value && !(await editor.value.flush())) return
  router.push({ name: 'course', params: { courseId: props.course.id }, query: { edit: 1 } })
}
function onSaved(updated) {
  lesson.value = updated
  const l = allLessons.value.find((x) => x.id === updated.id)
  if (l) Object.assign(l, { title: updated.title, has_video: !!updated.media, drip_days: updated.drip_days, open_on: updated.open_on, files: updated.resources?.length || 0 })
}
function onDeleted(outline) {
  emit('update:course', outline)
  router.replace({ name: 'course', params: { courseId: props.course.id }, query: { edit: 1 } })
}

/* ---------- Course settings ---------- */
const settingsStatus = ref('saved')
const form = ref({})
function fill() {
  const c = props.course
  form.value = { title: c.title, description: c.description || '', about: c.about || '' }
}
watch(() => props.course.id, fill, { immediate: true })
let settingsTimer = null
let pendingPatch = {}
function saveCourse(patch, wait = 0) {
  Object.assign(pendingPatch, patch)
  settingsStatus.value = 'saving'
  clearTimeout(settingsTimer)
  settingsTimer = setTimeout(async () => {
    const body = pendingPatch
    pendingPatch = {}
    try {
      emit('update:course', await api.updateCourse(props.course.id, body))
      settingsStatus.value = 'saved'
    } catch (err) {
      settingsStatus.value = 'error'
      error.value = err.message
      Object.assign(pendingPatch, body)
    }
  }, wait)
}
const retrySettings = () => saveCourse({}, 0)
watch(() => form.value.title, (v, old) => old !== undefined && v.trim() && v !== props.course.title && saveCourse({ title: v.trim() }, 900))
watch(() => form.value.description, (v, old) => old !== undefined && v !== props.course.description && saveCourse({ description: v }, 900))
watch(() => form.value.about, (v, old) => old !== undefined && v !== (props.course.about || '') && saveCourse({ about: v }, 1200))

const groups = ref(null)
async function loadGroups() {
  if (groups.value) return
  try {
    groups.value = (await api.groups()).items
  } catch (err) {
    error.value = err.message
    groups.value = []
  }
}
watch(
  () => [part.value, props.course.access],
  () => part.value === 'settings' && props.course.access === 'groups' && loadGroups(),
  { immediate: true },
)
function setAccess(mode) {
  const patch = { access: mode }
  if (mode === 'level' && !(props.course.unlock_level > 1)) patch.unlock_level = 2
  if (mode === 'groups') loadGroups()
  saveCourse(patch)
}
function toggleGroup(id, on) {
  const ids = new Set(props.course.access_groups || [])
  on ? ids.add(id) : ids.delete(id)
  groupPicker.value = false
  saveCourse({ access_groups: [...ids] })
}
// The membership whose button everyone else sees: the first chosen one that has a button
// (the server picks the same way). Its price and link are set once, in Memberships.
const groupPicker = ref(false)
const chosenGroups = computed(() => (groups.value || []).filter((g) => (props.course.access_groups || []).includes(g.id)))
const shown = computed(() => chosenGroups.value.find((g) => g.offer) || chosenGroups.value[0] || null)
const alsoOpens = computed(() => (shown.value?.courses || []).filter((c) => c.id !== props.course.id).map((c) => c.title))

const coverInput = ref(null)
const coverUploading = ref(false)
function pickCover(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const item = startAttachment(file)
  coverUploading.value = true
  settingsStatus.value = 'saving'
  item.promise
    .then(() => (item.media ? saveCourse({ cover_media_id: item.media.id }) : ((settingsStatus.value = 'error'), (error.value = item.error))))
    .finally(() => (coverUploading.value = false))
}

const publishing = ref(false)
function setReady(ready) {
  if (ready && props.course.status !== 'published') publishing.value = true
  else if (!ready && props.course.status === 'published') saveCourse({ status: 'draft' })
}
function publish() {
  publishing.value = false
  saveCourse({ status: 'published' })
}

const deletingCourse = ref(false)
const deleteBusy = ref(false)
async function deleteCourse() {
  deleteBusy.value = true
  try {
    await api.deleteCourse(props.course.id)
    router.push({ name: 'classroom' })
  } catch (err) {
    error.value = err.message
    deleteBusy.value = false
  }
}

/* ---------- Words ---------- */
const lessonsLabel = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)
const overview = computed(() =>
  t.sprintf(/* translators: 1: "10 lessons", 2: number of sections */ t._n('%1$s in %2$d section', '%1$s in %2$d sections', modules.value.length, 'membuild-community'), lessonsLabel(allLessons.value.length), modules.value.length),
)
const onDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
const opensLabel = (l) => (l.open_on ? t.sprintf(/* translators: %s: a date */ t.__('On %s', 'membuild-community'), onDate(l.open_on)) : l.drip_days > 0 ? t.sprintf(/* translators: %d: days */ t._n('After %d day', 'After %d days', l.drip_days, 'membuild-community'), l.drip_days) : t.__('Right away', 'membuild-community'))
const filesLabel = (n) => t.sprintf(/* translators: %d: number of files */ t._n('%d file', '%d files', n, 'membuild-community'), n)
const allNow = (m) => m.lessons.length && m.lessons.every((l) => !(l.drip_days > 0) && !l.open_on)
const moveToLabel = (m) => t.sprintf(/* translators: %s: section name */ t.__('Move to “%s”', 'membuild-community'), m.title)
const publishTitle = computed(() => t.sprintf(/* translators: %s: course name */ t.__('Publish “%s”?', 'membuild-community'), props.course.title))
const deleteTitle = computed(() => t.sprintf(/* translators: %s: course name */ t.__('Delete “%s” for good?', 'membuild-community'), props.course.title))
const deleteSectionTitle = computed(() => (removing.value ? t.sprintf(/* translators: %s: section name */ t.__('Delete the section “%s”?', 'membuild-community'), removing.value.title) : ''))
const deleteBody = computed(() => t.sprintf(/* translators: %s: "10 lessons" */ t.__('This removes all %s, their files, every post and question in the course, and every member’s progress.', 'membuild-community'), lessonsLabel(allLessons.value.length)))
const sectionBody = computed(() => (removing.value ? t.sprintf(/* translators: %s: "3 lessons" */ t.__('Its %s go too, with their files, questions and every member’s progress on them.', 'membuild-community'), lessonsLabel(removing.value.lessons.length)) : ''))
const counter = computed(() => `${form.value.description.length} / 160`)
</script>

<template>
  <div class="cb">
    <RouterLink to="/classroom" class="cb-back"><Icon name="chevronLeft" :size="18" /> {{ t.__('Classroom', 'membuild-community') }}</RouterLink>
    <div class="cb-head">
      <span class="cb-status" :class="{ live: course.status === 'published' }">{{ course.status === 'published' ? t.__('Published: members can see it', 'membuild-community') : t.__('Draft: only you can see it', 'membuild-community') }}</span>
      <h1>{{ course.title }}</h1>
      <button class="btn btn-lg btn-ghost" @click="emit('preview')"><Icon name="eye" :size="20" /> {{ t.__('See it as a member', 'membuild-community') }}</button>
    </div>

    <nav class="cb-tabs" role="tablist" :aria-label="t.__('Course builder', 'membuild-community')">
      <button role="tab" :aria-selected="part === 'map'" :class="{ on: part === 'map' }" @click="setPart('map')">{{ t.__('Course map', 'membuild-community') }}</button>
      <button role="tab" :aria-selected="part === 'lessons'" :class="{ on: part === 'lessons' }" @click="part === 'lessons' && lessonId ? backToList() : setPart('lessons')">
        {{ t.__('Lessons', 'membuild-community') }} <span v-if="allLessons.length" class="n">{{ allLessons.length }}</span>
      </button>
      <button role="tab" :aria-selected="part === 'settings'" :class="{ on: part === 'settings' }" @click="setPart('settings')">{{ t.__('Course settings', 'membuild-community') }}</button>
    </nav>

    <p v-if="error" class="cb-error" role="alert"><Icon name="flag" :size="18" /> {{ error }}</p>

    <!-- Course map -->
    <CourseMap v-if="part === 'map'" :course="course" @edit="editLesson" />

    <!-- Lessons -->
    <template v-else-if="part === 'lessons'">
      <template v-if="lessonId">
        <BuilderLessonEditor v-if="lesson" ref="editor" :lesson="lesson" :course="course" @saved="onSaved" @deleted="onDeleted" @go="editLesson" @done="backToList" />
        <p v-else-if="lessonError" class="cb-error">{{ lessonError }}</p>
        <div v-else class="card cb-loading"><div class="skeleton" style="height: 24px; width: 50%" /></div>
      </template>

      <div v-else-if="!allLessons.length" class="card cb-empty">
        <h2>{{ t.__('Your lessons will appear here', 'membuild-community') }}</h2>
        <p>{{ t.__('Each lesson can have a video, some text to read, and files to download. Add the first one, then keep going.', 'membuild-community') }}</p>
        <button class="btn btn-lg btn-primary" :disabled="busy" @click="ask('lesson', modules[0] || null)"><Icon name="plus" :size="20" /> {{ t.__('Add your first lesson', 'membuild-community') }}</button>
      </div>

      <template v-else>
        <div v-if="ordering" class="cb-ordering">
          <span>
            <strong>{{ t.__('Changing the order', 'membuild-community') }}</strong>
            <small>{{ t.__('Drag the dots to move a section or lesson, or press them for Move up and Move down.', 'membuild-community') }}</small>
          </span>
          <span class="cb-ordering-btns">
            <button class="btn btn-lg btn-ghost" @click="cancelOrdering">{{ t.__('Cancel', 'membuild-community') }}</button>
            <button class="btn btn-lg btn-primary" @click="ordering = false"><Icon name="check" :size="18" /> {{ t.__('Done', 'membuild-community') }}</button>
          </span>
        </div>
        <div v-else class="cb-listhead">
          <strong>{{ overview }}</strong>
          <button class="btn btn-lg btn-ghost" @click="startOrdering"><Icon name="list" :size="18" /> {{ t.__('Change the order', 'membuild-community') }}</button>
        </div>

        <section v-for="(m, mi) in modules" :key="m.id" class="cb-section card" :class="{ ordering }" @dragover.prevent @drop.stop="dropOn({ type: 'm', i: mi })">
          <header>
            <span v-if="ordering" class="handle-wrap" @click.stop>
              <button class="handle" draggable="true" :aria-label="t.__('Move this section', 'membuild-community')" @dragstart="dragStart($event, { type: 'm', i: mi })" @click="moveFor = moveFor === `m${m.id}` ? null : `m${m.id}`"><Icon name="more" :size="20" class="dots" /></button>
              <span v-if="moveFor === `m${m.id}`" class="menu card">
                <button :disabled="mi === 0" @click="moveSection(mi, -1)">{{ t.__('Move up', 'membuild-community') }}</button>
                <button :disabled="mi === modules.length - 1" @click="moveSection(mi, 1)">{{ t.__('Move down', 'membuild-community') }}</button>
              </span>
            </span>
            <h2>{{ m.title }}</h2>
            <span class="meta">· {{ lessonsLabel(m.lessons.length) }}<template v-if="allNow(m)"> · {{ t.__('open right away', 'membuild-community') }}</template></span>
            <span v-if="!ordering" class="menu-wrap" @click.stop>
              <button class="more" :aria-label="t.__('Section options', 'membuild-community')" @click="menuFor = menuFor === m.id ? null : m.id"><Icon name="more" :size="20" /></button>
              <span v-if="menuFor === m.id" class="menu card">
                <button @click="menuFor = null; ask('rename', m)"><Icon name="pencil" :size="18" /> {{ t.__('Rename section', 'membuild-community') }}</button>
                <button @click="menuFor = null; ask('lesson', m)"><Icon name="plus" :size="18" /> {{ t.__('Add a lesson', 'membuild-community') }}</button>
                <button class="red" @click="menuFor = null; removing = m"><Icon name="trash" :size="18" /> {{ t.__('Delete section…', 'membuild-community') }}</button>
              </span>
            </span>
          </header>

          <div v-for="(l, li) in m.lessons" :key="l.id" class="cb-row" @dragover.prevent @drop.stop="dropOn({ type: 'l', m, i: li })">
            <span v-if="ordering" class="handle-wrap" @click.stop>
              <button class="handle" draggable="true" :aria-label="t.__('Move this lesson', 'membuild-community')" @dragstart="dragStart($event, { type: 'l', m, i: li })" @click="moveFor = moveFor === `l${l.id}` ? null : `l${l.id}`"><Icon name="more" :size="20" class="dots" /></button>
              <span v-if="moveFor === `l${l.id}`" class="menu card">
                <button :disabled="li === 0" @click="moveLesson(m, li, -1)">{{ t.__('Move up', 'membuild-community') }}</button>
                <button :disabled="li === m.lessons.length - 1" @click="moveLesson(m, li, 1)">{{ t.__('Move down', 'membuild-community') }}</button>
                <button v-for="other in modules.filter((x) => x.id !== m.id)" :key="other.id" @click="moveTo(m, li, other)">{{ moveToLabel(other) }}</button>
              </span>
            </span>
            <span class="num">{{ numberOf(l) }}</span>
            <Icon :name="l.has_video ? 'play' : 'file'" :size="18" class="kind" />
            <span class="ltitle">{{ l.title }}</span>
            <span class="chip"><Icon name="clock" :size="14" /> {{ opensLabel(l) }}</span>
            <span v-if="l.files" class="chip"><Icon name="paperclip" :size="14" /> {{ filesLabel(l.files) }}</span>
            <button v-if="!ordering" class="btn btn-lg btn-ghost edit" @click="editLesson(l.id)"><Icon name="pencil" :size="18" /> {{ t.__('Edit', 'membuild-community') }}</button>
          </div>
          <p v-if="!m.lessons.length" class="cb-none">{{ t.__('No lessons in this section yet.', 'membuild-community') }}</p>
          <div v-if="!ordering" class="cb-add">
            <button class="btn btn-lg btn-ghost" :disabled="busy" @click="ask('lesson', m)"><Icon name="plus" :size="18" /> {{ t.__('Add a lesson', 'membuild-community') }}</button>
          </div>
        </section>

        <button v-if="!ordering" class="btn btn-lg btn-ghost cb-addsection" :disabled="busy" @click="ask('section')"><Icon name="plus" :size="18" /> {{ t.__('Add a section', 'membuild-community') }}</button>
        <!-- Leaving the builder; hidden while changing the order, which has its own Cancel and Done. -->
        <div v-if="!ordering" class="cb-done">
          <button class="btn btn-lg btn-primary" @click="emit('exit')">{{ t.__('Done', 'membuild-community') }}</button>
        </div>
      </template>
    </template>

    <!-- Course settings -->
    <template v-else>
      <div class="cb-savedrow">
        <span class="saved" :class="settingsStatus">
          <template v-if="settingsStatus === 'saving'">{{ t.__('Saving…', 'membuild-community') }}</template>
          <template v-else-if="settingsStatus === 'error'"><Icon name="flag" :size="15" /> {{ t.__('Couldn’t save', 'membuild-community') }} <button class="retry" @click="retrySettings">{{ t.__('Try again', 'membuild-community') }}</button></template>
          <template v-else><Icon name="check" :size="15" /> {{ t.__('All changes saved', 'membuild-community') }}</template>
        </span>
      </div>
      <div class="card cb-settings">
        <label class="field">
          <span>{{ t.__('Course name', 'membuild-community') }}</span>
          <input v-model="form.title" maxlength="120" />
        </label>
        <label class="field">
          <span class="row">{{ t.__('Short summary', 'membuild-community') }} <small>{{ counter }}</small></span>
          <textarea v-model="form.description" rows="3" maxlength="160" />
          <small>{{ t.__('Shown next to the cover on the course page, and on the course card in the Classroom.', 'membuild-community') }}</small>
        </label>
        <div class="field">
          <span>{{ t.__('About this course', 'membuild-community') }} <em class="opt">{{ t.__('(optional)', 'membuild-community') }}</em></span>
          <RichEditor v-model="form.about" :pictures="false" :label="t.__('About this course', 'membuild-community')" :placeholder="t.__('What they’ll learn, who it’s for…', 'membuild-community')" />
          <small>{{ t.__('Shown below the cover on the course page. Leave it empty if the short summary says enough.', 'membuild-community') }}</small>
        </div>

        <fieldset class="field">
          <legend>{{ t.__('Who can take this course?', 'membuild-community') }}</legend>
          <label class="choice" :class="{ on: course.access === 'everyone' }">
            <input type="radio" :checked="course.access === 'everyone'" @change="setAccess('everyone')" />
            <span><strong>{{ t.__('Everyone in the community', 'membuild-community') }}</strong><small>{{ t.__('Any member can open it.', 'membuild-community') }}</small></span>
          </label>
          <label class="choice" :class="{ on: course.access === 'level' }">
            <input type="radio" :checked="course.access === 'level'" @change="setAccess('level')" />
            <span>
              <strong>{{ t.__('Members who reach a level', 'membuild-community') }}</strong><small>{{ t.__('Rewards active members.', 'membuild-community') }}</small>
              <select v-if="course.access === 'level'" :value="Math.max(2, course.unlock_level)" :aria-label="t.__('Level needed', 'membuild-community')" @change="saveCourse({ unlock_level: Number($event.target.value) })">
                <option v-for="n in 8" :key="n" :value="n + 1">{{ levelLabel(n + 1) }}</option>
              </select>
            </span>
          </label>
          <label class="choice" :class="{ on: course.access === 'groups' }">
            <input type="radio" :checked="course.access === 'groups'" @change="setAccess('groups')" />
            <span><strong>{{ t.__('Members with a membership', 'membuild-community') }}</strong><small>{{ t.__('For paid or invited members.', 'membuild-community') }}</small></span>
          </label>
          <div v-if="course.access === 'groups'" class="sub-box">
            <strong>{{ t.__('Which memberships open it', 'membuild-community') }}</strong>
            <p v-if="!groups">{{ t.__('Loading…', 'membuild-community') }}</p>
            <template v-else>
              <div class="ms-pick">
                <span v-for="g in chosenGroups" :key="g.id" class="ms-chip">{{ g.name }}<button type="button" :aria-label="t.sprintf(t.__('Remove %s', 'membuild-community'), g.name)" @click="toggleGroup(g.id, false)"><Icon name="close" :size="14" /></button></span>
                <span v-if="groups.some((g) => !(course.access_groups || []).includes(g.id))" class="ms-more">
                  <button type="button" class="ms-add" :aria-expanded="groupPicker" @click="groupPicker = !groupPicker">+ {{ t.__('Add a membership', 'membuild-community') }}</button>
                  <span v-if="groupPicker" class="ms-menu card" role="menu">
                    <button v-for="g in groups.filter((x) => !(course.access_groups || []).includes(x.id))" :key="g.id" type="button" role="menuitem" @click="toggleGroup(g.id, true)">{{ g.name }}</button>
                  </span>
                </span>
              </div>
              <small class="ms-hint">{{ t.__('A membership can follow a WordPress role, so WP Fusion, FluentCRM or your membership plugin can give access for you.', 'membuild-community') }}</small>
              <p v-if="!groups.length">{{ t.__('There are no memberships yet.', 'membuild-community') }}</p>
              <p v-else-if="!chosenGroups.length" class="warn-text">{{ t.__('Pick at least one. Until then only admins and moderators can open this course.', 'membuild-community') }}</p>
              <div v-if="shown" class="ms-sees">
                <strong>{{ t.sprintf(/* translators: %s: membership name */ t.__('Everyone else sees the %s’s button', 'membuild-community'), shown.name) }}</strong>
                <p v-if="shown.offer" class="ms-row">{{ t.__('Members see:', 'membuild-community') }} <span class="ms-btn"><Icon name="lock" :size="15" /> {{ offerButton(shown.offer) }}</span></p>
                <p v-else class="ms-row">{{ t.__('It’s invite only, so there’s no button. Members see “Ask an admin for access”.', 'membuild-community') }}</p>
                <p v-if="alsoOpens.length" class="ms-also">
                  {{ t.sprintf(/* translators: 1: membership name, 2: other course names */ t.__('%1$s also opens %2$s. Its price and link are set once for all of them.', 'membuild-community'), shown.name, alsoOpens.join(', ')) }}
                </p>
                <RouterLink :to="{ name: 'settings', hash: '#s-access' }" class="link">{{ t.__('Change the price or link in Memberships', 'membuild-community') }} ›</RouterLink>
              </div>
              <RouterLink v-else :to="{ name: 'settings', hash: '#s-access' }" class="link">{{ groups.length ? t.__('Manage memberships', 'membuild-community') : t.__('Create a membership', 'membuild-community') }} ›</RouterLink>
            </template>
          </div>
        </fieldset>

        <fieldset v-if="course.access !== 'everyone'" class="field">
          <legend>{{ t.__('When someone can’t open it yet', 'membuild-community') }}</legend>
          <label class="choice" :class="{ on: !course.hide_locked }">
            <input type="radio" :checked="!course.hide_locked" @change="saveCourse({ hide_locked: false })" />
            <span><strong>{{ t.__('Show it with a lock (recommended)', 'membuild-community') }}</strong><small>{{ t.__('They can see what’s inside, which makes them want it.', 'membuild-community') }}</small></span>
          </label>
          <label class="choice" :class="{ on: course.hide_locked }">
            <input type="radio" :checked="course.hide_locked" @change="saveCourse({ hide_locked: true })" />
            <span><strong>{{ t.__('Hide it from the Classroom', 'membuild-community') }}</strong><small>{{ t.__('Only people who can open it will see it.', 'membuild-community') }}</small></span>
          </label>
        </fieldset>

        <fieldset class="field">
          <legend>{{ t.__('Questions under lessons', 'membuild-community') }}</legend>
          <label class="choice" :class="{ on: course.questions !== 'off' }">
            <input type="radio" :checked="course.questions !== 'off'" @change="saveCourse({ questions: 'on' })" />
            <span><strong>{{ t.__('Same as the rest of the community (on)', 'membuild-community') }}</strong><small>{{ t.__('Members can ask and answer questions under each lesson.', 'membuild-community') }}</small></span>
          </label>
          <label class="choice" :class="{ on: course.questions === 'off' }">
            <input type="radio" :checked="course.questions === 'off'" @change="saveCourse({ questions: 'off' })" />
            <span><strong>{{ t.__('Off for this course', 'membuild-community') }}</strong><small>{{ t.__('No questions under its lessons, and no All posts tab. Good for a quiet, self-paced course.', 'membuild-community') }}</small></span>
          </label>
        </fieldset>

        <div class="field">
          <span>{{ t.__('Cover picture', 'membuild-community') }}</span>
          <div class="cover-row">
            <span class="cover" :style="course.cover ? { backgroundImage: `url(${course.cover})` } : null">{{ course.cover ? '' : course.title }}</span>
            <button class="btn btn-lg btn-ghost" :disabled="coverUploading" @click="coverInput.click()">{{ coverUploading ? t.__('Uploading…', 'membuild-community') : course.cover ? t.__('Change picture', 'membuild-community') : t.__('Add a picture', 'membuild-community') }}</button>
            <input ref="coverInput" class="sr-only" type="file" accept="image/*" @change="pickCover" />
          </div>
          <small>{{ t.__('A wide picture works best.', 'membuild-community') }}</small>
        </div>

        <fieldset class="field">
          <legend>{{ t.__('Is it ready for members?', 'membuild-community') }}</legend>
          <label class="choice" :class="{ on: course.status !== 'published' }">
            <input type="radio" :checked="course.status !== 'published'" @change="setReady(false)" />
            <span><strong>{{ t.__('Not yet (draft)', 'membuild-community') }}</strong><small>{{ t.__('Only you can see it while you build it.', 'membuild-community') }}</small></span>
          </label>
          <label class="choice" :class="{ on: course.status === 'published' }">
            <input type="radio" :checked="course.status === 'published'" @change="setReady(true)" />
            <span><strong>{{ t.__('Yes, publish it', 'membuild-community') }}</strong><small>{{ t.__('Members see it in the Classroom and can start it.', 'membuild-community') }}</small></span>
          </label>
        </fieldset>
      </div>
      <div class="cb-done">
        <button class="btn btn-lg btn-primary" @click="emit('exit')">{{ t.__('Done', 'membuild-community') }}</button>
      </div>
      <section class="danger">
        <span>
          <strong>{{ t.__('Danger zone', 'membuild-community') }}</strong>
          <small>{{ t.__('Deleting removes this course, its lessons, files and posts for everyone. It can’t be undone.', 'membuild-community') }}</small>
        </span>
        <button class="btn btn-lg danger-btn" @click="deletingCourse = true">{{ t.__('Delete this course…', 'membuild-community') }}</button>
      </section>
    </template>

    <BaseDialog v-if="naming" :title="nameTitle" :width="480" @close="naming = null">
      <form class="name-form" @submit.prevent="submitName">
        <p v-if="naming.kind === 'section'" class="hint">{{ t.__('Sections group lessons, like chapters in a book.', 'membuild-community') }}</p>
        <label :for="'cb-name'">{{ nameLabel }}</label>
        <input id="cb-name" ref="nameInput" v-model="nameValue" maxlength="200" />
        <p class="hint">{{ t.__('You can change it later.', 'membuild-community') }}</p>
        <div class="name-actions">
          <button type="button" class="btn btn-lg btn-ghost" @click="naming = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button type="submit" class="btn btn-lg btn-primary" :disabled="!nameValue.trim() || busy">{{ nameButton }}</button>
        </div>
      </form>
    </BaseDialog>

    <ConfirmDialog v-if="publishing" :title="publishTitle" :confirm="t.__('Publish course', 'membuild-community')" :cancel="t.__('Not yet', 'membuild-community')" @confirm="publish" @close="publishing = false">
      <p>{{ t.__('Members will see it in the Classroom right away.', 'membuild-community') }}</p>
    </ConfirmDialog>
    <ConfirmDialog v-if="removing" danger :title="deleteSectionTitle" :confirm="t.__('Delete section', 'membuild-community')" :cancel="t.__('Keep it', 'membuild-community')" :busy="busy" @confirm="removeSection" @close="removing = null">
      <p>{{ sectionBody }}</p>
      <p class="note"><strong>{{ t.__('It can’t be restored.', 'membuild-community') }}</strong></p>
    </ConfirmDialog>
    <ConfirmDialog v-if="deletingCourse" danger :title="deleteTitle" :confirm="t.__('Delete forever', 'membuild-community')" :cancel="t.__('Keep the course', 'membuild-community')" :tick="t.__('I understand this can’t be undone', 'membuild-community')" :busy="deleteBusy" @confirm="deleteCourse" @close="deletingCourse = false">
      <p>{{ deleteBody }}</p>
      <p class="note"><strong>{{ t.__('It can’t be restored.', 'membuild-community') }}</strong> {{ t.__('The only way to get it back is from a backup of your website, so make sure you have one.', 'membuild-community') }}</p>
    </ConfirmDialog>
  </div>
</template>

<style scoped>
.ms-pick {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
}

.ms-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 36px;
  padding: 0 6px 0 14px;
  border-radius: 999px;
  background: var(--surface-2);
  font-weight: 650;
}

.ms-chip button {
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border: 0;
  border-radius: 50%;
  background: none;
  color: var(--text-2);
  cursor: pointer;
}

.ms-more {
  position: relative;
}

.ms-add {
  min-height: 36px;
  padding: 0 10px;
  border: 0;
  background: none;
  color: var(--text-2);
  font: inherit;
  font-weight: 650;
  cursor: pointer;
}

.ms-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  z-index: 5;
  display: flex;
  flex-direction: column;
  min-width: 220px;
  padding: 6px;
}

.ms-menu button {
  min-height: 44px;
  padding: 0 12px;
  border: 0;
  border-radius: 8px;
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.ms-menu button:hover {
  background: var(--surface-2);
}

.ms-hint {
  color: var(--text-2);
}

.ms-sees {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}

.ms-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin: 0;
  color: var(--text-2);
}

.ms-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 40px;
  padding: 0 16px;
  border-radius: 999px;
  background: var(--text);
  color: var(--surface);
  font-weight: 700;
}

.ms-also {
  margin: 0;
  color: var(--text-2);
  font-size: 14px;
}

.cb {
  max-width: 900px;
  margin: 0 auto;
  padding: 14px 16px 60px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.cb-back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  align-self: flex-start;
  font-size: 17px;
  font-weight: 650;
  color: var(--text-2);
}

.cb-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
}

.cb-status {
  flex: 1 1 100%;
  align-self: flex-start;
  font-size: 15px;
  font-weight: 700;
  color: var(--warn-text);
}

.cb-status.live {
  color: var(--ok-text);
}

.cb-head h1 {
  flex: 1;
  margin: 0;
  font-size: 30px;
  line-height: 1.2;
}

.cb-tabs {
  display: flex;
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--surface);
}

.cb-tabs button {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 48px;
  border-radius: 10px;
  font-size: 17px;
  font-weight: 650;
  color: var(--text-2);
}

.cb-tabs button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.cb-tabs .n {
  min-width: 24px;
  padding: 0 7px;
  border-radius: 999px;
  background: var(--surface);
  font-size: 14px;
}

.cb-error {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  padding: 12px 16px;
  border-radius: 12px;
  background: var(--danger-soft);
  color: var(--danger-ink);
  font-size: 16px;
}

.cb-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 48px 24px;
  text-align: center;
}

.cb-empty h2 {
  margin: 0;
  font-size: 22px;
}

.cb-empty p {
  max-width: 460px;
  margin: 0 0 6px;
  font-size: 17px;
  line-height: 1.55;
  color: var(--text-2);
}

.cb-loading {
  padding: 24px;
}

.cb-listhead,
.cb-ordering {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 17px;
}

.cb-ordering {
  padding: 12px 12px 12px 18px;
  border: 2px solid var(--accent);
  border-radius: 14px;
  background: var(--accent-soft);
}

.cb-ordering-btns {
  display: flex;
  flex: none;
  gap: 10px;
}

.cb-ordering-btns .btn-ghost {
  background: var(--surface);
}

.cb-ordering strong {
  display: block;
  font-size: 17px;
}

.cb-ordering small {
  display: block;
  font-size: 15px;
  color: var(--text-2);
}

.cb-section {
  overflow: visible;
}

.cb-section header {
  position: relative;
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 60px;
  padding: 8px 10px 8px 18px;
  border-bottom: 1px solid var(--border);
  background: var(--surface-2);
  border-radius: var(--radius) var(--radius) 0 0;
}

.cb-section h2 {
  margin: 0;
  font-size: 18px;
}

.meta {
  flex: 1;
  font-size: 15px;
  color: var(--text-2);
}

.menu-wrap,
.handle-wrap {
  position: relative;
}

.more,
.handle {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text-2);
}

.handle {
  cursor: grab;
}

.dots {
  transform: rotate(90deg);
}

.menu {
  position: absolute;
  top: calc(100% + 4px);
  right: 0;
  z-index: 20;
  display: flex;
  flex-direction: column;
  min-width: 220px;
  padding: 6px;
}

.handle-wrap .menu {
  right: auto;
  left: 0;
}

.menu button {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 48px;
  padding: 0 12px;
  border-radius: 10px;
  font-size: 16px;
  font-weight: 650;
  text-align: left;
  color: var(--text);
}

.menu button:hover:not(:disabled) {
  background: var(--surface-2);
}

.menu button:disabled {
  opacity: 0.4;
}

.menu .red {
  color: var(--danger-text);
}

.cb-row {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 64px;
  padding: 8px 12px 8px 18px;
}

.cb-row + .cb-row {
  border-top: 1px solid var(--border);
}

.num {
  flex: none;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--accent-soft);
  font-size: 14.5px;
  font-weight: 700;
}

.kind {
  flex: none;
  color: var(--text-2);
}

.ltitle {
  flex: 1;
  min-width: 0;
  font-size: 17px;
  font-weight: 650;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chip {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--surface-2);
  font-size: 14px;
  font-weight: 650;
  color: var(--text-2);
}

.edit {
  flex: none;
  height: 48px;
}

.cb-none {
  margin: 0;
  padding: 14px 18px;
  color: var(--text-3);
  font-size: 15.5px;
}

.cb-add {
  padding: 10px 12px 14px;
  border-top: 1px solid var(--border);
}

.cb-addsection {
  align-self: flex-start;
}

.cb-done {
  display: flex;
  justify-content: flex-end;
}

.cb-savedrow {
  display: flex;
  justify-content: flex-end;
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

.retry {
  margin-left: 6px;
  text-decoration: underline;
  font-weight: 700;
  color: inherit;
}

.cb-settings {
  display: flex;
  flex-direction: column;
  gap: 22px;
  padding: 22px 24px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  border: 0;
}

.field > span,
.field legend {
  font-size: 17px;
  font-weight: 700;
  margin-bottom: 4px;
}

.field .row {
  display: flex;
  justify-content: space-between;
}

.field .row small {
  font-weight: 500;
}

.opt {
  font-style: normal;
  font-weight: 500;
  color: var(--text-2);
}

.field small {
  font-size: 15px;
  color: var(--text-2);
}

.field input:not([type='radio']):not([type='checkbox']),
.field textarea,
.field select {
  min-height: 52px;
  padding: 12px 14px;
  border: 1px solid var(--border-strong, #c9ced6);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font-size: 17px;
  font-family: inherit;
}

.field textarea {
  resize: vertical;
  line-height: 1.5;
}

.choice {
  display: flex;
  align-items: flex-start;
  gap: 14px;
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

.choice > span {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.choice strong {
  font-size: 17px;
}

.choice select {
  margin-top: 10px;
  max-width: 320px;
}

.sub-box {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-left: 18px;
  padding: 16px 18px;
  border-radius: 14px;
  background: var(--surface-2);
  font-size: 16px;
}

.sub-box p {
  margin: 0;
}

.check {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  font-size: 17px;
}

.check input {
  width: 22px;
  height: 22px;
}

.warn-text {
  color: var(--warn-text);
}

.link {
  font-weight: 700;
  color: var(--text);
}

.cover-row {
  display: flex;
  align-items: center;
  gap: 14px;
}

.cover {
  display: grid;
  place-items: center;
  width: 160px;
  aspect-ratio: 16 / 9;
  border-radius: 12px;
  background: center / cover no-repeat linear-gradient(135deg, var(--accent-soft), var(--surface-2));
  font-size: 14px;
  font-weight: 700;
  text-align: center;
}

.danger {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
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

.name-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 20px 20px;
}

.name-form label {
  font-size: 17px;
  font-weight: 700;
}

.name-form input {
  height: 52px;
  padding: 0 16px;
  border: 1px solid var(--border-strong, #c9ced6);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font-size: 17px;
}

.name-form .hint {
  margin: 0;
  font-size: 15px;
  color: var(--text-2);
}

.name-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 10px;
}

@media (max-width: 700px) {
  .cb {
    padding: 10px 12px 40px;
  }

  .cb-head h1 {
    flex: 1 1 100%;
    font-size: 25px;
  }

  .cb-head .btn {
    width: 100%;
  }

  .cb-row {
    flex-wrap: wrap;
    padding: 10px 12px;
  }

  .ltitle {
    flex: 1 1 60%;
  }

  .cb-listhead,
  .cb-ordering {
    flex-wrap: wrap;
  }

  .cb-settings {
    padding: 18px 16px;
  }

  .sub-box {
    margin-left: 0;
  }
}
</style>
