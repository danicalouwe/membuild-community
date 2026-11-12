<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, noteCourseSave } from '../api.js'
import { startAttachment } from '../lib/attachments.js'
import { cfg, levelLabel } from '../config.js'
import BaseDialog from './BaseDialog.vue'
import BuilderLessonEditor from './BuilderLessonEditor.vue'
import QuizEditor from './QuizEditor.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import CourseMap from './CourseMap.vue'
import RichEditor from './RichEditor.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
import { localToday, realDay } from '../lib/time.js'
import { offerButton } from '../lib/offer.js'
import { showToast } from '../lib/toast.js'
import { syncCourseSaved } from '../lib/courseCards.js'

/**
 * The course builder (Version 2), for owners: Lessons (sections and lessons,
 * one Edit button each, Change the order) and Course settings (name, who can
 * take it, cover, draft or published, Danger zone). Everything saves by itself.
 */
const props = defineProps({
  course: { type: Object, required: true },
  lessonId: { type: Number, default: null },
  // Told about a course save that answers after the builder has closed (the page shows that course again).
  afterClose: { type: Function, default: null },
})
const emit = defineEmits(['update:course', 'exit', 'preview'])

const route = useRoute()
const router = useRouter()
const part = computed(() => (['settings', 'map'].includes(route.query.part) ? route.query.part : 'lessons'))
// "Change it in Course settings": open Settings at the question itself.
async function toOpens() {
  await setPart('settings')
  await nextTick()
  // after the router's own scroll to the top
  await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)))
  const el = document.getElementById('cb-opens')
  el?.scrollIntoView({ block: 'center' })
  el?.closest('fieldset')?.querySelector('input:checked')?.focus({ preventScroll: true })
}
// "What's the difference?": a panel under the link (a sheet on phones). Esc or a click outside closes it.
const helpOpen = ref(false)
const helpBtn = ref(null)
const helpPanel = ref(null)
async function toggleHelp() {
  helpOpen.value = !helpOpen.value
  if (helpOpen.value) {
    await nextTick()
    helpPanel.value?.querySelector('button')?.focus()
  }
}
function closeHelp() {
  if (!helpOpen.value) return
  helpOpen.value = false
  helpBtn.value?.focus()
}
function helpKey(e) {
  if (e.key === 'Escape' && helpOpen.value) {
    e.stopPropagation()
    closeHelp()
  }
}
function helpOutside(e) {
  if (helpOpen.value && !helpPanel.value?.contains(e.target) && !helpBtn.value?.contains(e.target)) helpOpen.value = false
}
document.addEventListener('keydown', helpKey, true)
document.addEventListener('pointerdown', helpOutside, true)
onBeforeUnmount(() => {
  document.removeEventListener('keydown', helpKey, true)
  document.removeEventListener('pointerdown', helpOutside, true)
})
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
// The lesson editor's Quiz card: open the quiz editor, or the quiz was removed.
async function onLessonQuiz(action) {
  if (action === 'open') {
    if (editor.value && !(await editor.value.flush())) return
    router.push({ name: 'course', params: { courseId: props.course.id, lessonId: props.lessonId }, query: { edit: 1, quiz: 1 } })
  } else if (action === 'removed') {
    onQuizChanged({ quiz: null })
  }
}
function onDeleted(outline) {
  emit('update:course', outline)
  router.replace({ name: 'course', params: { courseId: props.course.id }, query: { edit: 1 } })
}

/* ---------- Course settings ---------- */
const settingsStatus = ref('saved')
// The open lesson's saving, sent up by the lesson editor, shown in the same spot as the settings'.
const lessonStatus = ref('saved')
// The course as members see it, in a new tab, so the editor stays open here.
const courseHref = computed(() => router.resolve({ name: 'course', params: { courseId: props.course.id } }).href)
const saveState = computed(() => (part.value === 'settings' ? settingsStatus.value : lessonStatus.value))
const form = ref({})
function fill() {
  const c = props.course
  form.value = { title: c.title, description: c.description || '', about: c.about || '' }
  for (const key in asked) delete asked[key]
}
// The newest words of each box sent to the server or waiting to go, so typing back to the
// saved words still saves them when a newer version is on its way (it would win otherwise).
const asked = {}
const differs = (key, v) => v !== (key in asked ? asked[key] : props.course[key] || '')
// Settings save a moment after the last change, one save at a time, always to the course they
// were made in: opening another course sends the waiting changes to their own course at once,
// and a later answer never overwrites a newer change.
let settingsTimer = null
let pendingPatch = {}
let pendingFor = null // the course the waiting changes belong to
let sending = Promise.resolve() // saves go one after another, in the order they were made
let unsent = 0 // saves made but not answered yet
const coverUploading = ref(false)
// Saves that failed and can't be sent again from here (the owner had opened another course, or
// come back to it, by then), by course: { message, keys }. That course says Couldn't save, with
// the reason, until a later save of the same boxes works.
const failedFor = {}
const retryable = ref(false) // the failed change is still waiting here, so Try again sends it
let closed = false // the builder has closed; answers go to the page and failures to a toast
let settingsError = false // the error showing is a settings save's, so a later good save clears it
let deletedId = null // the course just deleted here: nothing more is sent to it
const titles = {} // each course's name, for a toast about a save that failed after closing
watch(
  () => props.course.id,
  (id, oldId) => {
    titles[id] = props.course.title
    if (oldId && pendingFor === oldId) sendSettings()
    if (oldId) {
      retryable.value = false
      error.value = failedFor[id]?.message || ''
      settingsError = false
      settingsStatus.value = coverUploading.value ? 'saving' : failedFor[id] ? 'error' : 'saved' // this course has nothing waiting yet
    }
    fill()
  },
  { immediate: true },
)
onBeforeUnmount(() => {
  closed = true
  sendSettings() // leaving the builder sends what's waiting straight away
})
// Saved once nothing for this course is waiting or on its way, and nothing of it failed.
function settled() {
  if (unsent || settingsTimer || coverUploading.value || Object.keys(pendingPatch).length) return
  settingsStatus.value = failedFor[props.course.id] ? 'error' : 'saved'
}
function saveCourse(patch, wait = 0) {
  if (pendingFor !== null && pendingFor !== props.course.id) sendSettings() // changes left for another course go there first
  pendingFor = props.course.id
  Object.assign(pendingPatch, patch)
  Object.assign(asked, patch)
  settingsStatus.value = 'saving'
  clearTimeout(settingsTimer)
  settingsTimer = setTimeout(sendSettings, wait)
}
function sendSettings() {
  clearTimeout(settingsTimer)
  settingsTimer = null
  if (!Object.keys(pendingPatch).length) return settingsStatus.value === 'saving' && settled()
  const id = pendingFor
  const body = pendingPatch
  pendingPatch = {}
  pendingFor = null
  send(id, body)
}
function send(id, body) {
  if (id === deletedId) return
  unsent++
  sending = sending.then(() =>
    api
      .updateCourse(id, body)
      .then((course) => {
        unsent--
        const failed = failedFor[id]
        if (failed) {
          failed.keys = failed.keys.filter((key) => !(key in body))
          if (!failed.keys.length) delete failedFor[id]
        }
        titles[id] = course.title
        syncCourseSaved(course) // the course cards in posts show the new name and cover too
        if (closed) return props.afterClose?.(course)
        if (id !== props.course.id) return settled() // an earlier course's answer
        if (!failedFor[id] && (failed || settingsError)) {
          error.value = ''
          settingsError = false
        }
        emit('update:course', course)
        settled() // also clears an earlier Couldn't save once nothing is left over from it
      })
      .catch((err) => {
        unsent--
        if (id === deletedId) return settled()
        if (closed) {
          const name = body.title || titles[id] || props.course.title
          return showToast(t.sprintf(/* translators: 1: course name, 2: the reason */ t.__('Couldn’t save your last change to %1$s. %2$s', 'membuild-community'), name, err.message))
        }
        // Kept for Try again while the owner is still on this course, unless something newer
        // was asked for since (it's on its way).
        const here = id === props.course.id
        const lost = []
        for (const key in body) {
          if (here && asked[key] === body[key]) {
            if (!(key in pendingPatch)) pendingPatch[key] = body[key]
          } else if (!(key in asked) || !here) lost.push(key) // nothing newer for it: this change is gone
        }
        if (lost.length) failedFor[id] = { message: err.message, keys: [...new Set([...(failedFor[id]?.keys || []), ...lost])] }
        if (!here) return settled()
        error.value = err.message
        settingsError = true
        if (Object.keys(pendingPatch).length) {
          pendingFor = id
          retryable.value = true
          settingsStatus.value = 'error'
        } else settled()
      }),
  )
  noteCourseSave(id, sending)
}
function retrySettings() {
  retryable.value = false
  saveCourse({}, 0)
}
// The start date saves when the owner leaves the box (or presses Enter), once it's a real day:
// saving while they type would cut the year short.
const startDraft = ref('')
watch(() => props.course.starts_on, (v) => (startDraft.value = v || ''), { immediate: true })
function saveStart() {
  if (!realDay(startDraft.value)) return (startDraft.value = props.course.starts_on || '')
  if (startDraft.value !== props.course.starts_on) saveCourse({ starts_on: startDraft.value })
}
const todayIso = localToday()
const startPassed = computed(() => props.course.opens === 'date' && !!props.course.starts_on && props.course.starts_on < todayIso)
watch(() => form.value.title, (v, old) => old !== undefined && v.trim() && differs('title', v.trim()) && saveCourse({ title: v.trim() }, 900))
watch(() => form.value.description, (v, old) => old !== undefined && differs('description', v) && saveCourse({ description: v }, 900))
watch(() => form.value.about, (v, old) => old !== undefined && differs('about', v) && saveCourse({ about: v }, 1200))

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
function pickCover(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const id = props.course.id // the picture belongs to this course, even if another opens meanwhile
  const item = startAttachment(file)
  coverUploading.value = true
  settingsStatus.value = 'saving'
  item.promise
    .catch(() => null)
    .then(() => {
      coverUploading.value = false
      if (!item.media) {
        error.value = item.error // nothing to send again: the owner picks the picture again
        return settled()
      }
      if (id === props.course.id) saveCourse({ cover_media_id: item.media.id })
      else send(id, { cover_media_id: item.media.id })
    })
}

const publishing = ref(false) // the publish confirm is open
// Draft or Published as the owner last picked it, until the server has it: Published while the
// confirm is open, and whichever they picked while a save is waiting or failed (Try again sends it).
// "Not yet" goes back to the saved one. Both radios follow it, so they never both look picked.
const wanted = ref(null)
const shownStatus = computed(() => wanted.value || props.course.status)
function setReady(ready) {
  const status = ready ? 'published' : 'draft'
  if (status === shownStatus.value) return
  wanted.value = status === props.course.status ? null : status // back to the saved one: nothing to wait for
  if (ready && props.course.status !== 'published') publishing.value = true
  else saveCourse({ status })
}
function publish() {
  publishing.value = false
  saveCourse({ status: 'published' })
}
function notYet() {
  publishing.value = false
  wanted.value = null
}
watch(() => props.course.status, (status) => status === wanted.value && (wanted.value = null))
watch(() => props.course.id, () => (wanted.value = null))

const deletingCourse = ref(false)
const deleteBusy = ref(false)
async function deleteCourse() {
  deleteBusy.value = true
  try {
    await api.deleteCourse(props.course.id)
    deletedId = props.course.id
    if (pendingFor === deletedId) {
      clearTimeout(settingsTimer)
      settingsTimer = null
      pendingPatch = {}
      pendingFor = null
    }
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
const startPlus = (n) => {
  const d = new Date(`${props.course.starts_on}T00:00:00`)
  d.setDate(d.getDate() + n)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const onDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
// In-order courses: a lesson with no wait of its own opens after the one before (as the Course map says).
// One at a time comes first (as the Course map says): a lesson with no wait of its own opens after the one before.
const opensLabel = (l) => (props.course.in_order && numberOf(l) > 1 && (props.course.opens === 'all' || (!l.open_on && !(l.drip_days > 0))) ? t.sprintf(/* translators: %d: lesson number */ t.__('After lesson %d', 'membuild-community'), numberOf(l) - 1) : props.course.opens === 'all' ? t.__('Right away', 'membuild-community') : props.course.opens === 'date' && !l.open_on ? (l.drip_days > 0 ? t.sprintf(/* translators: %s: a date */ t.__('On %s', 'membuild-community'), onDate(startPlus(l.drip_days))) : t.__('On the start day', 'membuild-community')) : props.course.in_order && numberOf(l) > 1 && !l.open_on && !(l.drip_days > 0) ? t.sprintf(/* translators: %d: lesson number */ t.__('After lesson %d', 'membuild-community'), numberOf(l) - 1) : l.open_on ? t.sprintf(/* translators: %s: a date */ t.__('On %s', 'membuild-community'), onDate(l.open_on)) : l.drip_days > 0 ? t.sprintf(/* translators: %d: days */ t._n('After %d day', 'After %d days', l.drip_days, 'membuild-community'), l.drip_days) : t.__('Right away', 'membuild-community'))
const quizLabel = (n) => t.sprintf(/* translators: %d: number of questions */ t._n('Quiz · %d question', 'Quiz · %d questions', n, 'membuild-community'), n)
// The quiz editor (?quiz=1 on a lesson) fills the builder's place; its back link returns to the lesson.
const quizOpen = computed(() => !!props.lessonId && !!route.query.quiz)
function onQuizChanged(v) {
  const l = allLessons.value.find((x) => x.id === props.lessonId)
  if (l) l.quiz = v.quiz?.questions?.length || 0
  if (lesson.value?.id === props.lessonId) lesson.value = { ...lesson.value, quiz: v.quiz ? { ...(lesson.value.quiz || {}), id: v.quiz.id, questions: v.quiz.questions.length, pass_mark: v.quiz.pass_mark, on_fail: v.quiz.on_fail } : null }
}
const filesLabel = (n) => t.sprintf(/* translators: %d: number of files */ t._n('%d file', '%d files', n, 'membuild-community'), n)
const allNow = (m) => m.lessons.length && (props.course.opens === 'all' || (props.course.opens !== 'date' && m.lessons.every((l) => !(l.drip_days > 0) && !l.open_on)))
const moveToLabel = (m) => t.sprintf(/* translators: %s: section name */ t.__('Move to “%s”', 'membuild-community'), m.title)
const publishTitle = computed(() => t.sprintf(/* translators: %s: course name */ t.__('Publish “%s”?', 'membuild-community'), props.course.title))
const deleteTitle = computed(() => t.sprintf(/* translators: %s: course name */ t.__('Delete “%s” for good?', 'membuild-community'), props.course.title))
const deleteSectionTitle = computed(() => (removing.value ? t.sprintf(/* translators: %s: section name */ t.__('Delete the section “%s”?', 'membuild-community'), removing.value.title) : ''))
const deleteBody = computed(() =>
  allLessons.value.length === 1
    ? t.__('This removes the lesson, its files, every post and question in the course, and every member’s progress.', 'membuild-community')
    : t.sprintf(/* translators: %s: "10 lessons" */ t.__('This removes all %s, their files, every post and question in the course, and every member’s progress.', 'membuild-community'), lessonsLabel(allLessons.value.length)),
)
const sectionBody = computed(() => (removing.value ? t.sprintf(/* translators: %s: "3 lessons" */ t.__('Its %s go too, with their files, questions and every member’s progress on them.', 'membuild-community'), lessonsLabel(removing.value.lessons.length)) : ''))
const counter = computed(() => `${form.value.description.length} / 160`)
</script>

<template>
  <div v-if="quizOpen" class="cb">
    <QuizEditor :lesson-id="lessonId" :course-id="course.id" @changed="onQuizChanged" />
  </div>
  <div v-else class="cb">
    <RouterLink to="/classroom" class="cb-back"><Icon name="chevronLeft" :size="18" /> {{ t.__('Classroom', 'membuild-community') }}</RouterLink>
    <!-- The name, labels and tabs stay pinned under the top bar while you scroll. -->
    <div class="cb-top">
    <div class="cb-head">
      <h1>{{ course.title }}</h1>
      <!-- Two labels you read (Published or Draft, then Saved), then the course in a new tab.
           Everything saves as you type, so there's no Done to press. -->
      <div class="cb-actions">
        <span class="cb-status" :class="{ live: course.status === 'published' }">{{ course.status === 'published' ? t.__('Published', 'membuild-community') : t.__('Draft', 'membuild-community') }}</span>
        <span v-if="part === 'settings' || (part === 'lessons' && lessonId)" class="saved" :class="saveState" role="status">
          <template v-if="saveState === 'saving'"><span class="spin" /> {{ t.__('Saving…', 'membuild-community') }}</template>
          <template v-else-if="saveState === 'error'"><Icon name="flag" :size="18" /> {{ t.__('Couldn’t save', 'membuild-community') }} <button v-if="part === 'settings' && retryable" class="retry" @click="retrySettings">{{ t.__('Try again', 'membuild-community') }}</button></template>
          <template v-else><Icon name="check" :size="18" /> {{ t.__('Saved', 'membuild-community') }}</template>
        </span>
        <a class="btn btn-lg btn-ghost cb-exit" :href="courseHref" target="_blank" rel="noopener" :aria-label="t.__('See the course (opens in a new tab)', 'membuild-community')" :title="t.__('See the course in a new tab', 'membuild-community')"><Icon name="external" :size="20" /></a>
      </div>
    </div>

    <nav class="cb-tabs" role="tablist" :aria-label="t.__('Course builder', 'membuild-community')">
      <button role="tab" :aria-selected="part === 'map'" :class="{ on: part === 'map' }" @click="setPart('map')"><span class="long">{{ t.__('Course map', 'membuild-community') }}</span><span class="short">{{ t.__('Map', 'membuild-community') }}</span></button>
      <button role="tab" :aria-selected="part === 'lessons'" :class="{ on: part === 'lessons' }" @click="part === 'lessons' && lessonId ? backToList() : setPart('lessons')">
        {{ t.__('Lessons', 'membuild-community') }} <span v-if="allLessons.length" class="n">{{ allLessons.length }}</span>
      </button>
      <button role="tab" :aria-selected="part === 'settings'" :class="{ on: part === 'settings' }" @click="setPart('settings')"><span class="long">{{ t.__('Course settings', 'membuild-community') }}</span><span class="short">{{ t.__('Settings', 'membuild-community') }}</span></button>
    </nav>
    </div>

    <p v-if="error" class="cb-error" role="alert"><Icon name="flag" :size="18" /> {{ error }}</p>

    <!-- Course map -->
    <CourseMap v-if="part === 'map'" :course="course" @edit="editLesson" />

    <!-- Lessons -->
    <template v-else-if="part === 'lessons'">
      <template v-if="lessonId">
        <BuilderLessonEditor v-if="lesson" ref="editor" :lesson="lesson" :course="course" @quiz="onLessonQuiz" @saved="onSaved" @deleted="onDeleted" @go="editLesson" @status="(s) => (lessonStatus = s)" @settings="toOpens" />
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
            <span v-if="l.quiz" class="chip"><Icon name="quiz" :size="14" /> {{ quizLabel(l.quiz) }}</span>
            <button v-if="!ordering" class="btn btn-ghost edit" @click="editLesson(l.id)"><Icon name="pencil" :size="18" /> {{ t.__('Edit', 'membuild-community') }}</button>
          </div>
          <p v-if="!m.lessons.length" class="cb-none">{{ t.__('No lessons in this section yet.', 'membuild-community') }}</p>
          <div v-if="!ordering" class="cb-add">
            <button class="btn btn-ghost" :disabled="busy" @click="ask('lesson', m)"><Icon name="plus" :size="18" /> {{ t.__('Add a lesson', 'membuild-community') }}</button>
          </div>
        </section>

        <button v-if="!ordering" class="btn btn-lg btn-ghost cb-addsection" :disabled="busy" @click="ask('section')"><Icon name="plus" :size="18" /> {{ t.__('Add a section', 'membuild-community') }}</button>
      </template>
    </template>

    <!-- Course settings -->
    <template v-else>
      <!-- Four groups that read top to bottom, each heading a plain question (canvas: Course settings, simpler). -->
      <section class="card cb-settings cb-group">
        <div class="cb-ghead"><span class="cb-gnum">1</span><span><strong>{{ t.__('The basics', 'membuild-community') }}</strong><small>{{ t.__('What members see first.', 'membuild-community') }}</small></span></div>
        <label class="field">
          <span>{{ t.__('Course name', 'membuild-community') }}</span>
          <input v-model="form.title" maxlength="120" />
        </label>
        <label class="field">
          <span class="row">{{ t.__('A short line about it', 'membuild-community') }} <small>{{ counter }}</small></span>
          <textarea v-model="form.description" rows="2" maxlength="160" />
          <small>{{ t.__('One or two sentences. It shows on the course card.', 'membuild-community') }}</small>
        </label>
        <div class="field">
          <span>{{ t.__('Cover picture', 'membuild-community') }}</span>
          <div class="cover-row">
            <span class="cover" :style="course.cover ? { backgroundImage: `url(${course.cover})` } : null">{{ course.cover ? '' : course.title }}</span>
            <button class="btn btn-lg btn-ghost" :disabled="coverUploading" @click="coverInput.click()">{{ coverUploading ? t.__('Uploading…', 'membuild-community') : course.cover ? t.__('Change picture', 'membuild-community') : t.__('Add a picture', 'membuild-community') }}</button>
            <input ref="coverInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickCover" />
          </div>
          <small>{{ t.__('A wide picture works best.', 'membuild-community') }}</small>
        </div>
        <div class="field">
          <span>{{ t.__('More about it', 'membuild-community') }} <em class="opt">{{ t.__('(optional)', 'membuild-community') }}</em></span>
          <RichEditor v-model="form.about" :pictures="false" :label="t.__('More about it', 'membuild-community')" :placeholder="t.__('What they’ll learn, who it’s for…', 'membuild-community')" />
          <small>{{ t.__('What they will learn and who it is for. You can leave this empty.', 'membuild-community') }}</small>
        </div>
      </section>

      <section class="card cb-settings cb-group">
        <div class="cb-ghead"><span class="cb-gnum">2</span><span><strong>{{ t.__('Who can open it', 'membuild-community') }}</strong><small>{{ t.__('Choose who gets in.', 'membuild-community') }}</small></span></div>
        <fieldset class="field">
          <legend>{{ t.__('Who can open this course?', 'membuild-community') }}</legend>
          <div class="pick-row three">
            <label class="choice pick-card" :class="{ on: course.access === 'everyone' }">
              <input type="radio" :checked="course.access === 'everyone'" @change="setAccess('everyone')" />
              <span><strong>{{ t.__('Everyone', 'membuild-community') }}</strong><small>{{ t.__('All members can open it.', 'membuild-community') }}</small></span>
            </label>
            <label class="choice pick-card" :class="{ on: course.access === 'level' }">
              <input type="radio" :checked="course.access === 'level'" @change="setAccess('level')" />
              <span>
                <strong>{{ t.__('Active members', 'membuild-community') }}</strong><small>{{ t.__('They open it when they reach a level.', 'membuild-community') }}</small>
                <select v-if="course.access === 'level'" :value="Math.max(2, course.unlock_level)" :aria-label="t.__('Level needed', 'membuild-community')" @change="saveCourse({ unlock_level: Number($event.target.value) })">
                  <option v-for="n in (cfg.community.levelNames.length || 9) - 1" :key="n" :value="n + 1">{{ levelLabel(n + 1) }}</option>
                </select>
              </span>
            </label>
            <label class="choice pick-card" :class="{ on: course.access === 'groups' }">
              <input type="radio" :checked="course.access === 'groups'" @change="setAccess('groups')" />
              <span><strong>{{ t.__('Only some members', 'membuild-community') }}</strong><small>{{ t.__('People with a membership, like paid members.', 'membuild-community') }}</small></span>
            </label>
          </div>
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
              <small class="ms-hint">{{ t.__('Your shop or membership plugin can give people a membership for you.', 'membuild-community') }}</small>
              <p v-if="!groups.length">{{ t.__('There are no memberships yet.', 'membuild-community') }}</p>
              <p v-else-if="!chosenGroups.length" class="warn-text">{{ t.__('Pick at least one. Until you do, only you and your moderators can open it.', 'membuild-community') }}</p>
              <div v-if="shown" class="ms-sees">
                <strong>{{ t.sprintf(/* translators: %s: membership name */ t.__('Everyone else sees the button for %s', 'membuild-community'), shown.name) }}</strong>
                <p v-if="shown.offer" class="ms-row">{{ t.__('Members see:', 'membuild-community') }} <span class="ms-btn"><Icon name="lock" :size="15" /> {{ offerButton(shown.offer) }}</span></p>
                <p v-else class="ms-row">{{ t.__('It’s invite only, so there’s no button. Members see “Ask an admin for access”.', 'membuild-community') }}</p>
                <p v-if="alsoOpens.length" class="ms-also">
                  {{ t.sprintf(/* translators: 1: membership name, 2: other course names */ t.__('%1$s also opens %2$s. Its price and link are set once for all of them.', 'membuild-community'), shown.name, alsoOpens.join(', ')) }}
                </p>
                <RouterLink :to="{ name: 'settings', params: { section: 'memberships' } }" class="link">{{ t.__('Change the price or link in Memberships', 'membuild-community') }} ›</RouterLink>
              </div>
              <RouterLink v-else :to="{ name: 'settings', params: { section: 'memberships' } }" class="link">{{ groups.length ? t.__('Manage memberships', 'membuild-community') : t.__('Create a membership', 'membuild-community') }} ›</RouterLink>
            </template>
          </div>
        </fieldset>
        <fieldset v-if="course.access !== 'everyone'" class="field">
          <legend>{{ t.__('What do the others see?', 'membuild-community') }}</legend>
          <div class="pick-row">
            <label class="choice pick-card" :class="{ on: !course.hide_locked }">
              <input type="radio" :checked="!course.hide_locked" @change="saveCourse({ hide_locked: false })" />
              <span><strong>{{ t.__('The course, with a lock', 'membuild-community') }}</strong><small>{{ t.__('They can see what is inside. (Best)', 'membuild-community') }}</small></span>
            </label>
            <label class="choice pick-card" :class="{ on: course.hide_locked }">
              <input type="radio" :checked="course.hide_locked" @change="saveCourse({ hide_locked: true })" />
              <span><strong>{{ t.__('Nothing', 'membuild-community') }}</strong><small>{{ t.__('The course is hidden from them.', 'membuild-community') }}</small></span>
            </label>
          </div>
        </fieldset>
      </section>

      <section class="card cb-settings cb-group">
        <div class="cb-ghead"><span class="cb-gnum">3</span><span><strong>{{ t.__('How it works', 'membuild-community') }}</strong><small>{{ t.__('How members go through it.', 'membuild-community') }}</small></span></div>
        <fieldset class="field opens">
          <legend id="cb-opens">
            {{ t.__('How does this course open?', 'membuild-community') }}
            <button ref="helpBtn" type="button" class="help-link" :aria-expanded="helpOpen" aria-controls="cb-opens-help" @click="toggleHelp"><Icon name="info" :size="18" /> {{ t.__('What’s the difference?', 'membuild-community') }}</button>
          </legend>
          <div v-if="helpOpen" class="help-veil" aria-hidden="true" />
          <div v-if="helpOpen" id="cb-opens-help" ref="helpPanel" class="help-pop" role="dialog" aria-labelledby="cb-opens-help-title">
            <header>
              <strong id="cb-opens-help-title">{{ t.__('How a course can open', 'membuild-community') }}</strong>
              <button type="button" class="round-btn" :aria-label="t.__('Close', 'membuild-community')" @click="closeHelp"><Icon name="close" :size="20" /></button>
            </header>
            <section>
              <strong>{{ t.__('All at once', 'membuild-community') }}</strong>
              <p>{{ t.__('Members get every lesson as soon as they join.', 'membuild-community') }}</p>
              <p class="eg">{{ t.__('Good for a recorded course people watch at their own speed.', 'membuild-community') }}</p>
            </section>
            <section>
              <strong>{{ t.__('From when they join', 'membuild-community') }}</strong>
              <p>{{ t.__('Each member gets lessons on their own timeline. You choose how many days each lesson waits.', 'membuild-community') }}</p>
              <p class="eg">{{ t.__('Example: Lesson 2 waits 3 days. Sam joins on Monday, so Sam gets Lesson 2 on Thursday.', 'membuild-community') }}</p>
            </section>
            <section>
              <strong>{{ t.__('On a start date', 'membuild-community') }}</strong>
              <p>{{ t.__('Everyone starts on the same day and gets each lesson at the same time.', 'membuild-community') }}</p>
              <p class="eg">{{ t.__('Example: The course starts on November 16. Lesson 2 waits 3 days, so everyone gets it on November 19.', 'membuild-community') }}</p>
            </section>
            <p class="help-foot">{{ t.__('You can change this any time. Lessons people already finished stay open.', 'membuild-community') }}</p>
          </div>
          <div class="pick-row three">
            <label class="choice mid pick-card" :class="{ on: course.opens === 'all' }">
              <input type="radio" name="cb-opens" :checked="course.opens === 'all'" @change="saveCourse({ opens: 'all' })" />
              <span><strong>{{ t.__('All at once', 'membuild-community') }}</strong><small>{{ t.__('Every lesson open.', 'membuild-community') }}</small></span>
            </label>
            <label class="choice mid pick-card" :class="{ on: course.opens === 'join' }">
              <input type="radio" name="cb-opens" :checked="course.opens === 'join'" @change="saveCourse({ opens: 'join' })" />
              <span><strong>{{ t.__('From when they join', 'membuild-community') }}</strong><small>{{ t.__('Timed per member.', 'membuild-community') }}</small></span>
            </label>
            <label class="choice mid pick-card" :class="{ on: course.opens === 'date' }">
              <input type="radio" name="cb-opens" :checked="course.opens === 'date'" @change="saveCourse({ opens: 'date' })" />
              <span><strong>{{ t.__('On a start date', 'membuild-community') }}</strong><small>{{ t.__('Everyone together.', 'membuild-community') }}</small></span>
            </label>
          </div>
          <div v-if="course.opens === 'date'" class="start-date">
            <label for="cb-start">{{ t.__('Start date', 'membuild-community') }}</label>
            <input id="cb-start" v-model="startDraft" type="date" min="2000-01-01" max="2100-12-31" aria-describedby="cb-start-hint" @blur="saveStart" @keydown.enter.prevent="saveStart" />
            <small id="cb-start-hint">{{ startPassed ? t.__('This day has passed, so the lessons up to today are open.', 'membuild-community') : t.__('Lesson timing starts here.', 'membuild-community') }}</small>
          </div>
        </fieldset>
        <fieldset class="field">
          <legend>{{ t.__('Can members skip ahead?', 'membuild-community') }}</legend>
          <div class="pick-row">
            <label class="choice pick-card" :class="{ on: !course.in_order }">
              <input type="radio" :checked="!course.in_order" @change="saveCourse({ in_order: false })" />
              <span><strong>{{ t.__('Yes, any order', 'membuild-community') }}</strong><small>{{ t.__('They can open any lesson.', 'membuild-community') }}</small></span>
            </label>
            <label class="choice pick-card" :class="{ on: course.in_order }">
              <input type="radio" :checked="course.in_order" @change="saveCourse({ in_order: true })" />
              <span><strong>{{ t.__('No, one at a time', 'membuild-community') }}</strong><small>{{ t.__('Finish one to open the next.', 'membuild-community') }}</small></span>
            </label>
          </div>
          <small>{{ t.__('You and your moderators can always open every lesson.', 'membuild-community') }}</small>
        </fieldset>
        <fieldset class="field">
          <legend>{{ t.__('Can members ask questions under the lessons?', 'membuild-community') }}</legend>
          <div class="pick-row">
            <label class="choice pick-card" :class="{ on: course.questions !== 'off' }">
              <input type="radio" :checked="course.questions !== 'off'" @change="saveCourse({ questions: 'on' })" />
              <span><strong>{{ t.__('Yes', 'membuild-community') }}</strong><small>{{ t.__('They can ask and answer under each lesson.', 'membuild-community') }}</small></span>
            </label>
            <label class="choice pick-card" :class="{ on: course.questions === 'off' }">
              <input type="radio" :checked="course.questions === 'off'" @change="saveCourse({ questions: 'off' })" />
              <span><strong>{{ t.__('No', 'membuild-community') }}</strong><small>{{ t.__('No questions in this course.', 'membuild-community') }}</small></span>
            </label>
          </div>
        </fieldset>
      </section>

      <section class="card cb-settings cb-group">
        <div class="cb-ghead"><span class="cb-gnum">4</span><span><strong>{{ t.__('Ready?', 'membuild-community') }}</strong><small>{{ t.__('Members only see it once you say yes.', 'membuild-community') }}</small></span></div>
        <fieldset class="field">
          <legend>{{ t.__('Can members see it?', 'membuild-community') }}</legend>
          <div class="pick-row">
            <label class="choice pick-card" :class="{ on: shownStatus !== 'published' }">
              <input type="radio" name="cb-status" :checked="shownStatus !== 'published'" @change="setReady(false)" />
              <span><strong>{{ t.__('Draft', 'membuild-community') }}</strong></span>
            </label>
            <label class="choice pick-card" :class="{ on: shownStatus === 'published' }">
              <input type="radio" name="cb-status" :checked="shownStatus === 'published'" @change="setReady(true)" />
              <span><strong>{{ t.__('Published', 'membuild-community') }}</strong></span>
            </label>
          </div>
        </fieldset>
      </section>
      <section class="delete-row">
        <span class="delete-row-text">
          <strong class="delete-row-title">{{ t.__('Delete this course', 'membuild-community') }}</strong>
          <small class="delete-row-line">{{ t.__('It removes the course and everything in it, for everyone. You can’t undo it.', 'membuild-community') }}</small>
        </span>
        <button class="btn btn-quiet-danger" @click="deletingCourse = true">{{ t.__('Delete…', 'membuild-community') }}</button>
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

    <ConfirmDialog v-if="publishing" :title="publishTitle" :confirm="t.__('Publish course', 'membuild-community')" :cancel="t.__('Not yet', 'membuild-community')" @confirm="publish" @close="notYet">
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
  border-radius: var(--radius-md);
  background: var(--surface);
}

.ms-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  padding: 0 8px 0 14px;
  border-radius: var(--radius-pill);
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
  min-height: 44px;
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
  border-radius: var(--radius-xs);
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
  border-radius: var(--radius-pill);
  background: var(--text);
  color: var(--surface);
  font-weight: 700;
}

.ms-also {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
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
  font-size: var(--fs-xl);
  font-weight: 650;
  color: var(--text-2);
}

.cb-top {
  position: sticky;
  top: calc(var(--header-h) + env(safe-area-inset-top));
  z-index: 10;
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin: 0 -16px;
  padding: 10px 16px 12px;
  background: var(--bg);
  transition: top 0.25s ease;
}

.cb-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 16px;
}

/* Published or Draft: a label, the same size as Saved beside it (publishing is in Course settings). */
.cb-status {
  display: inline-flex;
  align-items: center;
  height: 52px;
  padding: 0 24px;
  border-radius: var(--radius-pill);
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: var(--fs-xl);
  font-weight: 700;
  white-space: nowrap;
}

.cb-status.live {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.cb-head h1 {
  flex: 1;
  margin: 0;
  font-size: var(--fs-8xl);
  line-height: 1.2;
}

.cb-tabs {
  display: flex;
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
}

.cb-tabs button {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 44px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-xl);
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
  border-radius: var(--radius-pill);
  background: var(--surface);
  font-size: var(--fs-md);
}

.cb-error {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  padding: 12px 16px;
  border-radius: var(--radius-md);
  background: var(--danger-soft);
  color: var(--danger-ink);
  font-size: var(--fs-lg);
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
  font-size: var(--fs-4xl);
}

.cb-empty p {
  max-width: 460px;
  margin: 0 0 6px;
  font-size: var(--fs-xl);
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
  font-size: var(--fs-xl);
}

.cb-ordering {
  padding: 12px 12px 12px 18px;
  border: 2px solid var(--accent);
  border-radius: var(--radius);
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
  font-size: var(--fs-xl);
}

.cb-ordering small {
  display: block;
  font-size: var(--fs-base);
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
  font-size: var(--fs-2xl);
}

.meta {
  flex: 1;
  font-size: var(--fs-base);
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
  border-radius: var(--radius-md);
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
  border-radius: var(--radius-sm);
  font-size: var(--fs-lg);
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
  font-size: var(--fs-base);
  font-weight: 700;
}

.kind {
  flex: none;
  color: var(--text-2);
}

.ltitle {
  flex: 1;
  min-width: 0;
  font-size: var(--fs-xl);
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
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  font-size: var(--fs-md);
  font-weight: 650;
  color: var(--text-2);
}

.edit {
  flex: none;
}

.cb-none {
  margin: 0;
  padding: 14px 18px;
  color: var(--text-3);
  font-size: var(--fs-lg);
}

.cb-add {
  padding: 10px 12px 14px;
  border-top: 1px solid var(--border);
}

.cb-addsection {
  align-self: flex-start;
}

.cb-exit {
  flex: none;
  width: 52px;
  padding: 0;
}

.cb-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.saved {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 52px;
  padding: 0 24px;
  border-radius: var(--radius-pill);
  background: var(--ok-soft);
  color: var(--ok-text);
  font-size: var(--fs-xl);
  font-weight: 700;
  white-space: nowrap;
}

.spin {
  width: 14px;
  height: 14px;
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
  font-size: var(--fs-xl);
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
  font-size: var(--fs-base);
  color: var(--text-2);
}

.field input:not([type='radio']):not([type='checkbox']),
.field textarea,
.field select {
  min-height: 52px;
  padding: 12px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font-size: var(--fs-xl);
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
  border-radius: var(--radius);
}

/* Settings in four numbered groups; choices side by side, one column on phones. */
.cb-group + .cb-group {
  margin-top: 16px;
}

.cb-ghead {
  display: flex;
  align-items: center;
  gap: 14px;
}

.cb-ghead > span:last-child {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.cb-ghead strong {
  font-size: var(--fs-3xl);
}

.cb-ghead small {
  color: var(--text-2);
  font-size: var(--fs-base);
}

.cb-gnum {
  flex: none;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-pill);
  background: var(--text);
  color: var(--surface);
  font-weight: 800;
}

.pick-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.pick-row.three {
  grid-template-columns: repeat(3, 1fr);
}

@media (max-width: 760px) {
  .pick-row,
  .pick-row.three {
    grid-template-columns: 1fr;
  }
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
  font-size: var(--fs-xl);
}

.choice.mid {
  align-items: center;
}

.field.opens {
  position: relative;
}

.field.opens legend {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  column-gap: 12px;
}

.help-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  padding: 0 4px;
  border: 0;
  background: none;
  color: var(--text-2);
  font: inherit;
  font-size: var(--fs-md);
  font-weight: 500;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}

.help-link:hover {
  color: var(--text);
}

.help-pop {
  position: absolute;
  top: 52px;
  left: 0;
  z-index: 40;
  width: min(460px, 100%);
  max-height: min(640px, calc(100dvh - 120px));
  overflow: auto;
  padding: 16px 20px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--shadow-pop);
}

.help-pop header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 8px;
  font-size: var(--fs-xl);
}

.help-pop section {
  padding: 14px 0;
  border-top: 1px solid var(--border);
}

.help-pop section p {
  margin: 4px 0 0;
  line-height: 1.45;
}

.help-pop .eg {
  margin-top: 8px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-md);
}

.help-pop .help-foot {
  margin: 0;
  padding-top: 12px;
  border-top: 1px solid var(--border);
  color: var(--text-2);
  font-size: var(--fs-md);
}

.help-veil {
  display: none;
}

@media (max-width: 599px) {
  .help-veil {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 89;
    background: color-mix(in srgb, var(--backdrop) 60%, transparent);
  }

  .help-pop {
    position: fixed;
    top: auto;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 90;
    width: 100%;
    max-height: 85dvh;
    padding-bottom: calc(16px + env(safe-area-inset-bottom));
    border: 0;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  }
}

.choice.mid input {
  margin-top: 0;
}

.start-date {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 20px;
  font-weight: 700;
}

.start-date input {
  max-width: 360px;
  height: 52px;
  padding: 0 16px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: 400;
}

.start-date small {
  font-weight: 400;
  color: var(--text-2);
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
  border-radius: var(--radius);
  background: var(--surface-2);
  font-size: var(--fs-lg);
}

.sub-box p {
  margin: 0;
}

.check {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  font-size: var(--fs-xl);
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
  border-radius: var(--radius-md);
  background: center / cover no-repeat linear-gradient(135deg, var(--accent-soft), var(--surface-2));
  font-size: var(--fs-md);
  font-weight: 700;
  text-align: center;
}


.name-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 20px 20px;
}

.name-form label {
  font-size: var(--fs-xl);
  font-weight: 700;
}

.name-form input {
  height: 52px;
  padding: 0 16px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font-size: var(--fs-xl);
}

.name-form .hint {
  margin: 0;
  font-size: var(--fs-base);
  color: var(--text-2);
}

.name-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 10px;
}

/* Phones: the top bar slides away when scrolling down, so the pinned block moves up with it. */
@media (max-width: 767px) {
  /* All of it in :global(): Vue drops the part after a lone :global(…). */
  :global(.bars-hidden .cb-top) {
    top: env(safe-area-inset-top);
  }
}

/* Phones: the tabs drop the word "Course", so all three fit on one line. */
.cb-tabs .short {
  display: none;
}

@media (max-width: 700px) {
  .cb-tabs .long {
    display: none;
  }

  .cb-tabs .short {
    display: inline;
  }

  .cb {
    padding: 10px 12px 40px;
  }

  .cb-top {
    margin: 0 -12px;
    padding: 8px 12px 10px;
  }

  .cb-head h1 {
    flex: 1 1 100%;
    font-size: var(--fs-6xl);
  }

  /* One row on phones: the button takes the room, "Saved" stays short beside it. */
  .cb-actions {
    width: 100%;
    flex-wrap: nowrap;
  }

  .cb-actions {
    justify-content: flex-end;
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
