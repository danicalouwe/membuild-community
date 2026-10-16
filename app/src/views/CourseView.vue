<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { renderMarkdown } from '../lib/markdown.js'
import { startAttachment } from '../lib/attachments.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import Icon from '../components/Icon.vue'
import LessonPlayer from '../components/LessonPlayer.vue'
import LessonEditor from '../components/LessonEditor.vue'
import CourseAccess from '../components/CourseAccess.vue'
import LessonDiscussion from '../components/LessonDiscussion.vue'
import CourseResources from '../components/CourseResources.vue'
import CourseComplete from '../components/CourseComplete.vue'
import CourseOverview from '../components/CourseOverview.vue'
import { fileSize } from '../lib/files.js'
import { showToast } from '../lib/toast.js'
import { lockHint, lockLabel } from '../lib/access.js'
import { around, t } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'

const props = defineProps({
  courseId: { type: Number, required: true },
  lessonId: { type: Number, default: null },
})

const route = useRoute()
const router = useRouter()

const course = ref(null)
const lesson = ref(null)
const lessonError = ref(null) // { message, unlock_at }
const loadError = ref('')
const editing = ref(false)
const outlineOpen = ref(false) // phones: course contents drawer
const collapsed = ref(new Set())
const busy = ref(false)
const coverInput = ref(null)

const allLessons = computed(() => course.value?.modules.flatMap((m) => m.lessons) || [])
const current = computed(() => allLessons.value.find((l) => l.id === lesson.value?.id))
const nextLocked = computed(() => allLessons.value.find((l) => l.id === lesson.value?.next_id)?.locked)
const html = computed(() => renderMarkdown(lesson.value?.content || ''))
// Overview · Lesson · Discussion · Resources, kept in the address so a link can open a tab.
// Without a lesson in the address, the course shows its overview.
// The Resources tab only exists once a lesson has a file; an empty tab is a dead end.
const hasFiles = computed(() => (course.value?.files_count || 0) > 0)
const tab = computed(() => {
  const asked = route.query.tab
  if (asked === 'resources' && !hasFiles.value) return 'overview'
  return ['overview', 'discussion', 'resources'].includes(asked) ? asked : props.lessonId ? 'lesson' : 'overview'
})
const resumeLesson = () => allLessons.value.find((l) => !l.completed && !l.locked) || allLessons.value[0]
function setTab(name) {
  if (name === 'lesson' && !props.lessonId) {
    const resume = resumeLesson()
    if (resume) open(resume.id)
    return
  }
  router.replace({ query: { ...route.query, tab: name === 'lesson' || (name === 'overview' && !props.lessonId) ? undefined : name } })
}
const moduleDone = (m) => m.lessons.filter((l) => l.completed).length
const finished = ref(false) // the "You finished" moment
const upNext = ref(null) // after a video ends: { id, title, seconds }
let upTimer = null
const emptyHint = computed(() => around(/* translators: %s: the "Edit course" button label, shown in bold */ t.__('Switch on %s and add your first lesson.', 'membuild-community')))

async function loadCourse() {
  loadError.value = ''
  try {
    course.value = await api.course(props.courseId)
    editing.value = course.value.can_manage && !!route.query.edit
    // A course the member is part-way through picks up where they left off; a new,
    // finished or locked one opens on its overview (admins editing go straight to a lesson).
    const c = course.value
    const midway = c.completed_count > 0 && c.progress < 100 && !c.lock
    if (!props.lessonId && !route.query.tab && (midway || editing.value)) {
      const resume = resumeLesson()
      if (resume) router.replace({ name: 'course', params: { courseId: props.courseId, lessonId: resume.id }, query: route.query })
    }
  } catch (err) {
    loadError.value = err.status === 404 ? t.__('This course isn’t available.', 'membuild-community') : err.message
  }
}

const latestLesson = useLatest()
async function loadLesson(id) {
  const current = latestLesson() // clicking through lessons quickly: only the last one opens
  lessonError.value = null
  if (!id) {
    lesson.value = null
    return
  }
  try {
    const loaded = await api.lesson(id)
    if (!current()) return
    lesson.value = loaded
  } catch (err) {
    if (!current()) return
    lesson.value = null
    const outlineLesson = allLessons.value.find((l) => l.id === id)
    lessonError.value = { message: err.message, title: outlineLesson?.title }
  }
  outlineOpen.value = false
  window.scrollTo({ top: 0 })
}

watch(() => props.courseId, loadCourse, { immediate: true })
watch(() => props.lessonId, loadLesson, { immediate: true })

/**
 * Long courses (like Udemy's): modules already finished start folded, and the
 * outline scrolls to the lesson you're on, so "where am I" is never off-screen.
 */
const outlineEl = ref(null)
watch(
  () => course.value?.id,
  () => {
    if (allLessons.value.length > 20) {
      collapsed.value = new Set(course.value.modules.filter((m) => m.lessons.length && m.lessons.every((l) => l.completed) && !m.lessons.some((l) => l.id === props.lessonId)).map((m) => m.id))
    }
  },
)
watch(
  () => [props.lessonId, course.value?.id],
  async ([id]) => {
    if (!id) return
    const m = course.value?.modules.find((x) => x.lessons.some((l) => l.id === id))
    if (m && collapsed.value.has(m.id)) toggleModule(m.id)
    await nextTick()
    const row = outlineEl.value?.querySelector('li.active')
    if (row && outlineEl.value) {
      const box = outlineEl.value.getBoundingClientRect()
      const at = row.getBoundingClientRect()
      if (at.top < box.top || at.bottom > box.bottom) outlineEl.value.scrollTop += at.top - box.top - box.height / 3
    }
  },
  { immediate: true },
)

function open(id) {
  router.push({ name: 'course', params: { courseId: props.courseId, lessonId: id }, query: { ...route.query, tab: undefined } })
}

function applyProgress(res) {
  Object.assign(course.value, { progress: res.progress, completed_count: res.completed_count })
}

/**
 * Done moves the member on, like finishing a level: a short "Nice work",
 * then the next lesson. Finishing the last one is the course-complete moment.
 * `advance` false keeps them on the lesson (a video that just ended counts
 * itself done, then offers the next lesson with a countdown).
 */
async function toggleComplete(advance = true) {
  const l = lesson.value
  const done = !l.completed
  const before = course.value.progress
  l.completed = done
  if (current.value) current.value.completed = done
  try {
    const res = done ? await api.completeLesson(l.id) : await api.uncompleteLesson(l.id)
    applyProgress(res)
    pointsMayHaveChanged()
    if (!done) return
    if (res.progress === 100 && before < 100) {
      finished.value = true
      return
    }
    const next = allLessons.value.find((x) => x.id === l.next_id)
    if (advance && next && !next.locked) {
      showToast(t.__('Nice work! On to the next lesson.', 'membuild-community'), '🎉')
      open(next.id)
    } else if (advance) {
      showToast(t.__('Lesson done. Nice work!', 'membuild-community'), '✅')
    }
  } catch {
    l.completed = !done
    if (current.value) current.value.completed = !done
  }
}

// An uploaded lesson video counts as done when it plays to the end, then
// the next lesson starts in a few seconds unless the member stays.
async function onVideoEnded() {
  if (!lesson.value) return
  const id = lesson.value.id
  if (!lesson.value.completed) await toggleComplete(false)
  const next = allLessons.value.find((x) => x.id === lesson.value?.next_id)
  if (finished.value || !next || next.locked || lesson.value?.id !== id) return
  upNext.value = { id: next.id, title: next.title, seconds: 5 }
  clearInterval(upTimer)
  upTimer = setInterval(() => {
    if (!upNext.value) return clearInterval(upTimer)
    if (--upNext.value.seconds <= 0) playNext()
  }, 1000)
}
function playNext() {
  const id = upNext.value?.id
  cancelNext()
  if (id) open(id)
}
function cancelNext() {
  clearInterval(upTimer)
  upNext.value = null
}
watch(() => props.lessonId, cancelNext)
onBeforeUnmount(cancelNext)

// A new post under the lesson: the counts in the outline and the tab follow.
function onPosted() {
  course.value.posts_count = (course.value.posts_count || 0) + 1
  if (current.value) current.value.posts = (current.value.posts || 0) + 1
}
const playNow = (s) => t.sprintf(/* translators: %d: seconds until the next lesson plays */ t.__('Play now (%d)', 'membuild-community'), s)
const lessonOf = (l) => t.sprintf(/* translators: 1: lesson number, 2: number of lessons in the course */ t.__('Lesson %1$d of %2$d', 'membuild-community'), l.number, l.total)
const minutesLabel = (n) => t.sprintf(/* translators: %d: minutes */ t._n('%d min', '%d min', n, 'membuild-community'), n)
const filesLabel = (n) => t.sprintf(/* translators: %d: number of files */ t._n('%d file', '%d files', n, 'membuild-community'), n)
const postsLabel = (n) => t.sprintf(/* translators: %d: number of posts */ t._n('%d post', '%d posts', n, 'membuild-community'), n)

function toggleModule(id) {
  const s = new Set(collapsed.value)
  s.has(id) ? s.delete(id) : s.add(id)
  collapsed.value = s
}

/* ---------- Editing (admins) ---------- */

function setEditing(on) {
  editing.value = on
  router.replace({ query: on ? { ...route.query, edit: 1 } : {} })
}

async function run(fn) {
  busy.value = true
  try {
    const outline = await fn()
    if (outline?.modules) course.value = outline
    return outline
  } catch (err) {
    alert(err.message)
  } finally {
    busy.value = false
  }
}

const saveCourse = (fields) => run(() => api.updateCourse(props.courseId, fields))

async function togglePublish() {
  await saveCourse({ status: course.value.status === 'published' ? 'draft' : 'published' })
}

function pickCover(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  const item = startAttachment(file)
  busy.value = true
  item.promise.then(() => (item.media ? saveCourse({ cover_media_id: item.media.id }) : alert(item.error))).finally(() => (busy.value = false))
}

async function deleteCourse() {
  if (!confirm(t.sprintf(/* translators: %s: course title */ t.__('Delete "%s" with all its lessons and members\' progress?', 'membuild-community'), course.value.title))) return
  await api.deleteCourse(props.courseId)
  router.push({ name: 'classroom' })
}

const addModule = () => run(() => api.createModule(props.courseId, t.__('New module', 'membuild-community')))
const renameModule = (m, title) => title.trim() && title !== m.title && run(() => api.updateModule(m.id, title.trim()))

async function deleteModule(m) {
  if (!confirm(t.sprintf(/* translators: 1: module title, 2: number of lessons */ t._n('Delete the module "%1$s" and its %2$d lesson?', 'Delete the module "%1$s" and its %2$d lessons?', m.lessons.length, 'membuild-community'), m.title, m.lessons.length))) return
  await run(() => api.deleteModule(m.id))
  if (m.lessons.some((l) => l.id === props.lessonId)) router.replace({ name: 'course', params: { courseId: props.courseId }, query: route.query })
}

async function addLesson(m) {
  busy.value = true
  try {
    const created = await api.createLesson(m.id, t.__('New lesson', 'membuild-community'))
    course.value = await api.course(props.courseId)
    open(created.id)
  } finally {
    busy.value = false
  }
}

// Moves a module or lesson one step, then saves the whole order in one request.
function move(list, index, delta) {
  const target = index + delta
  if (target < 0 || target >= list.length) return
  ;[list[index], list[target]] = [list[target], list[index]]
  run(() => api.updateStructure(props.courseId, course.value.modules.map((m) => ({ id: m.id, lessons: m.lessons.map((l) => l.id) }))))
}

function onLessonSaved(updated) {
  lesson.value = updated
  const l = allLessons.value.find((x) => x.id === updated.id)
  if (l) {
    course.value.files_count += (updated.resources?.length || 0) - (l.files || 0) // the Resources tab appears with the first file
    Object.assign(l, { title: updated.title, has_video: !!updated.media, drip_days: updated.drip_days, files: updated.resources?.length || 0 })
  }
}

function onLessonDeleted(outline) {
  course.value = outline
  router.replace({ name: 'course', params: { courseId: props.courseId }, query: route.query })
}
const contentsCount = (done, total) => t.sprintf(/* translators: 1: lessons completed, 2: total lessons */ t.__('Course contents · %1$d/%2$d', 'membuild-community'), done, total)
</script>

<template>
  <div v-if="loadError" class="state">
    <p>{{ loadError }}</p>
    <RouterLink to="/classroom" class="btn btn-ghost">{{ t.__('Back to Classroom', 'membuild-community') }}</RouterLink>
  </div>

  <div v-else-if="course" class="course">
    <!-- Outline -->
    <aside ref="outlineEl" class="outline card" :class="{ open: outlineOpen }">
      <RouterLink to="/classroom" class="back"><Icon name="chevronLeft" :size="16" /> {{ t.__('Classroom', 'membuild-community') }}</RouterLink>

      <template v-if="editing">
        <input class="title-input" :value="course.title" :aria-label="t.__('Course title', 'membuild-community')" @change="saveCourse({ title: $event.target.value })" />
        <textarea class="desc-input" rows="3" :value="course.description" :placeholder="t.__('Short description', 'membuild-community')" :aria-label="t.__('Course description', 'membuild-community')" @change="saveCourse({ description: $event.target.value })" />
        <CourseAccess :course="course" :busy="busy" @save="saveCourse" />
        <div class="course-tools">
          <button class="btn btn-ghost" :disabled="busy" @click="coverInput.click()"><Icon name="image" :size="16" /> {{ t.__('Cover', 'membuild-community') }}</button>
          <button class="btn" :class="course.status === 'published' ? 'btn-ghost' : 'btn-primary'" :disabled="busy" @click="togglePublish">
            {{ course.status === 'published' ? t.__('Unpublish', 'membuild-community') : t.__('Publish', 'membuild-community') }}
          </button>
          <input ref="coverInput" class="sr-only" type="file" accept="image/*" @change="pickCover" />
        </div>
      </template>
      <template v-else>
        <h1>{{ course.title }}</h1>
        <span v-if="course.status === 'draft'" class="draft">{{ t.__('Draft · only admins can see this', 'membuild-community') }}</span>
        <span v-if="course.lock" class="draft"><Icon name="lock" :size="13" /> {{ lockLabel(course.lock) }}</span>
      </template>

      <div class="progress">
        <div class="track"><i :style="{ width: `${course.progress}%` }" /></div>
        <span>{{ course.progress }}%</span>
      </div>

      <nav class="modules" :aria-label="t.__('Course contents', 'membuild-community')">
        <section v-for="(m, mi) in course.modules" :key="m.id" class="module">
          <div class="module-head">
            <input v-if="editing" class="module-input" :value="m.title" :aria-label="t.__('Module title', 'membuild-community')" @change="renameModule(m, $event.target.value)" />
            <button v-else class="module-toggle" :aria-expanded="!collapsed.has(m.id)" @click="toggleModule(m.id)">
              <span>{{ m.title }}</span>
              <span v-if="m.lessons.length" class="module-count" :class="{ full: moduleDone(m) === m.lessons.length }">
                <Icon v-if="moduleDone(m) === m.lessons.length" name="check" :size="12" />{{ moduleDone(m) }}/{{ m.lessons.length }}
              </span>
              <Icon name="chevronDown" :size="16" :class="{ flip: !collapsed.has(m.id) }" />
            </button>
            <span v-if="editing" class="tools">
              <button :aria-label="t.__('Move module up', 'membuild-community')" :disabled="mi === 0 || busy" @click="move(course.modules, mi, -1)">↑</button>
              <button :aria-label="t.__('Move module down', 'membuild-community')" :disabled="mi === course.modules.length - 1 || busy" @click="move(course.modules, mi, 1)">↓</button>
              <button :aria-label="t.__('Delete module', 'membuild-community')" :disabled="busy" @click="deleteModule(m)"><Icon name="trash" :size="14" /></button>
            </span>
          </div>

          <ul v-show="editing || !collapsed.has(m.id)" class="lessons">
            <li v-for="(l, li) in m.lessons" :key="l.id" :class="{ active: l.id === lessonId }">
              <button class="lesson-btn" @click="open(l.id)">
                <span class="mark" :class="{ done: l.completed, locked: l.locked }">
                  <Icon v-if="l.locked" name="lock" :size="13" />
                  <svg v-else-if="l.completed" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </span>
                <span class="ltitle">
                  {{ l.title }}
                  <span v-if="l.minutes || l.files || l.posts" class="meta">
                    <span v-if="l.minutes">{{ minutesLabel(l.minutes) }}</span>
                    <span v-if="l.files" :aria-label="filesLabel(l.files)"><Icon name="paperclip" :size="12" /> {{ l.files }}</span>
                    <span v-if="l.posts" :aria-label="postsLabel(l.posts)"><Icon name="comment" :size="12" /> {{ l.posts }}</span>
                  </span>
                </span>
                <Icon v-if="l.has_video" name="play" :size="12" class="vid" />
              </button>
              <span v-if="editing" class="tools">
                <button :aria-label="t.__('Move lesson up', 'membuild-community')" :disabled="li === 0 || busy" @click="move(m.lessons, li, -1)">↑</button>
                <button :aria-label="t.__('Move lesson down', 'membuild-community')" :disabled="li === m.lessons.length - 1 || busy" @click="move(m.lessons, li, 1)">↓</button>
              </span>
            </li>
            <li v-if="!m.lessons.length && !editing" class="empty">{{ t.__('No lessons yet', 'membuild-community') }}</li>
          </ul>
          <button v-if="editing" class="add" :disabled="busy" @click="addLesson(m)"><Icon name="plus" :size="14" /> {{ t.__('Add lesson', 'membuild-community') }}</button>
        </section>
        <button v-if="editing" class="add module-add" :disabled="busy" @click="addModule"><Icon name="plus" :size="14" /> {{ t.__('Add module', 'membuild-community') }}</button>
      </nav>

      <div v-if="course.can_manage" class="admin-bar">
        <button class="btn" :class="editing ? 'btn-primary' : 'btn-ghost'" @click="setEditing(!editing)">
          <Icon :name="editing ? 'close' : 'sparkle'" :size="16" /> {{ editing ? t.__('Done editing', 'membuild-community') : t.__('Edit course', 'membuild-community') }}
        </button>
        <button v-if="editing" class="btn btn-ghost danger" @click="deleteCourse"><Icon name="trash" :size="16" /> {{ t.__('Delete', 'membuild-community') }}</button>
      </div>
    </aside>

    <!-- Lesson -->
    <main class="lesson">
      <button class="contents-btn" @click="outlineOpen = !outlineOpen">
        <Icon name="cap" :size="18" /> {{ contentsCount(course.completed_count, course.lessons_count) }}
      </button>

      <nav v-if="!editing && allLessons.length" class="tabs" role="tablist" :aria-label="t.__('Course sections', 'membuild-community')">
        <button role="tab" :aria-selected="tab === 'overview'" :class="{ on: tab === 'overview' }" @click="setTab('overview')">{{ t.__('Overview', 'membuild-community') }}</button>
        <button role="tab" :aria-selected="tab === 'lesson'" :class="{ on: tab === 'lesson' }" @click="setTab('lesson')">{{ t.__('Lesson', 'membuild-community') }}</button>
        <button role="tab" :aria-selected="tab === 'discussion'" :class="{ on: tab === 'discussion' }" @click="setTab('discussion')">
          {{ t.__('Discussion', 'membuild-community') }} <span v-if="course.posts_count" class="count">{{ course.posts_count }}</span>
        </button>
        <button v-if="hasFiles" role="tab" :aria-selected="tab === 'resources'" :class="{ on: tab === 'resources' }" @click="setTab('resources')">
          {{ t.__('Resources', 'membuild-community') }} <span v-if="course.files_count" class="count">{{ course.files_count }}</span>
        </button>
      </nav>

      <CourseOverview v-if="!editing && tab === 'overview'" :course="course" @open="open" @tab="setTab" />

      <template v-else-if="!editing && tab === 'discussion'">
        <div v-if="course.lock" class="card locked">
          <span class="lock-badge"><Icon name="lock" :size="28" /></span>
          <h2>{{ lockLabel(course.lock) }}</h2>
          <p>{{ t.__('The discussion opens with the course.', 'membuild-community') }}</p>
        </div>
        <LessonDiscussion v-else :course="course" @posted="onPosted" />
      </template>

      <CourseResources v-else-if="!editing && tab === 'resources'" :course-id="course.id" @open-lesson="open" />

      <div v-else-if="lessonError" class="card locked">
        <span class="lock-badge"><Icon name="lock" :size="28" /></span>
        <h2>{{ course.lock ? lockLabel(course.lock) : lessonError.title || t.__('Locked lesson', 'membuild-community') }}</h2>
        <p>{{ lessonError.message }}</p>
        <p v-if="course.lock && lockHint(course.lock)" class="how">
          {{ lockHint(course.lock) }}
          <RouterLink v-if="course.lock.reason === 'level'" to="/me">{{ t.__('See your progress', 'membuild-community') }}</RouterLink>
        </p>
        <a v-if="course.lock?.cta" :href="course.lock.cta.url" class="btn btn-primary cta">{{ course.lock.cta.label }}</a>
      </div>

      <div v-else-if="lesson && editing" class="card pad">
        <LessonEditor :lesson="lesson" @saved="onLessonSaved" @deleted="onLessonDeleted" />
      </div>

      <article v-else-if="lesson" class="card pad">
        <p class="where">
          <span v-if="lesson.module_title">{{ lesson.module_title }}</span>
          <span v-if="lesson.number">{{ lessonOf(lesson) }}</span>
          <span v-if="lesson.minutes"><Icon name="clock" :size="13" /> {{ minutesLabel(lesson.minutes) }}</span>
          <span v-if="lesson.completed" class="done-tag"><Icon name="check" :size="13" /> {{ t._x('Done', 'lesson completed', 'membuild-community') }}</span>
        </p>
        <h2 class="ltitle-main">{{ lesson.title }}</h2>
        <div v-if="lesson.media" class="player">
          <LessonPlayer :media="lesson.media" @ended="onVideoEnded" />
          <div v-if="upNext" class="up-next" role="status">
            <small>{{ t.__('Up next', 'membuild-community') }}</small>
            <strong>{{ upNext.title }}</strong>
            <div class="up-actions">
              <button class="btn btn-primary" @click="playNext"><Icon name="play" :size="16" /> {{ playNow(upNext.seconds) }}</button>
              <button class="btn btn-ghost" @click="cancelNext">{{ t.__('Stay here', 'membuild-community') }}</button>
            </div>
          </div>
        </div>
        <div v-if="lesson.content" class="md" v-html="html" />

        <section v-if="lesson.resources.length" class="downloads">
          <h3><Icon name="paperclip" :size="16" /> {{ t.__('Files for this lesson', 'membuild-community') }}</h3>
          <a v-for="r in lesson.resources" :key="r.media_id" :href="r.url" class="download" target="_blank" rel="noopener" download>
            <span class="type" :data-ext="r.ext">{{ r.ext || t.__('File', 'membuild-community') }}</span>
            <span class="dname">{{ r.name }}</span>
            <span v-if="r.size" class="dsize">{{ fileSize(r.size) }}</span>
            <Icon name="download" :size="18" class="dl-icon" />
          </a>
        </section>

        <footer class="nav">
          <button class="btn btn-ghost" :disabled="!lesson.prev_id" @click="open(lesson.prev_id)"><Icon name="chevronLeft" :size="16" /> {{ t.__('Previous', 'membuild-community') }}</button>
          <button class="done-btn" :class="{ on: lesson.completed }" @click="toggleComplete">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
            {{ lesson.completed ? t._x('Done', 'lesson completed', 'membuild-community') : t.__('Mark as done', 'membuild-community') }}
          </button>
          <button class="btn btn-ghost" :disabled="!lesson.next_id || nextLocked" @click="open(lesson.next_id)">
            <Icon v-if="nextLocked" name="lock" :size="14" /> {{ t.__('Next', 'membuild-community') }} <Icon name="chevronRight" :size="16" />
          </button>
        </footer>
      </article>
      <LessonDiscussion v-if="lesson && !editing && tab === 'lesson' && !lessonError" :key="lesson.id" class="lesson-talk" :course="course" :lesson="lesson" @posted="onPosted" />

      <div v-else-if="!allLessons.length" class="card pad empty-course">
        <h2>{{ t.__('No lessons yet', 'membuild-community') }}</h2>
        <p v-if="course.can_manage">{{ emptyHint[0] }}<strong>{{ t.__('Edit course', 'membuild-community') }}</strong>{{ emptyHint[1] }}</p>
        <p v-else>{{ t.__('Lessons are on their way. Check back soon.', 'membuild-community') }}</p>
      </div>
    </main>
    <CourseComplete v-if="finished" :course="course" @close="finished = false" />
  </div>
</template>

<style scoped>
.course {
  max-width: 1180px;
  margin: 0 auto;
  padding: 16px;
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 16px;
  align-items: start;
}

.outline {
  position: sticky;
  top: calc(var(--header-h) + 16px);
  max-height: calc(100dvh - var(--header-h) - 32px);
  overflow-y: auto;
  padding: 16px;
}

.back {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-2);
}

.outline h1 {
  margin: 10px 0 0;
  font-size: 20px;
  line-height: 1.25;
}

.draft {
  display: inline-block;
  margin-top: 6px;
  font-size: 12px;
  font-weight: 650;
  color: #e8a400;
}

.title-input,
.desc-input,
.module-input {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface-2);
  padding: 8px 10px;
}

.title-input {
  margin-top: 10px;
  font-size: 18px;
  font-weight: 700;
}

.desc-input {
  margin-top: 8px;
  resize: vertical;
  font-size: 14px;
}

.how {
  margin-top: 10px !important;
  font-size: 14px;
}

.cta {
  display: inline-flex;
  margin-top: 16px;
}

.course-tools {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}

.course-tools .btn-ghost,
.admin-bar .btn-ghost {
  color: var(--text);
}

.progress {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 14px 0 10px;
  font-size: 13px;
  font-weight: 650;
  color: var(--text-2);
}

.track {
  flex: 1;
  height: 8px;
  border-radius: 999px;
  background: var(--surface-2);
  overflow: hidden;
}

.track i {
  display: block;
  height: 100%;
  background: #2fb36b;
  border-radius: inherit;
  transition: width 0.4s ease;
}

.module {
  padding: 8px 0;
  border-top: 1px solid var(--border);
}

.module-head {
  display: flex;
  align-items: center;
  gap: 6px;
}

.module-toggle {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 4px;
  font-weight: 700;
  font-size: 14px;
  text-align: left;
}

.module-toggle :deep(svg) {
  transition: transform 0.15s ease;
}

.module-toggle .flip {
  transform: rotate(180deg);
}

.lessons {
  list-style: none;
  margin: 4px 0 0;
  padding: 0;
}

.lessons li {
  display: flex;
  align-items: center;
  border-radius: 8px;
}

.lessons li.active {
  background: var(--accent-soft);
}

.lesson-btn {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px;
  text-align: left;
  font-size: 14px;
}

.lessons li:not(.active):hover {
  background: var(--surface-2);
}

.mark {
  display: grid;
  place-items: center;
  flex: none;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 2px solid var(--border);
  color: #fff;
}

.mark.done {
  background: #2fb36b;
  border-color: #2fb36b;
}

.mark.locked {
  border-color: transparent;
  color: var(--text-3);
}

.ltitle {
  flex: 1;
  min-width: 0;
}

.vid {
  color: var(--text-3);
  flex: none;
}

.empty {
  padding: 6px 8px;
  font-size: 13px;
  color: var(--text-3);
}

.tools {
  display: flex;
  gap: 2px;
}

.tools button {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  color: var(--text-2);
  display: grid;
  place-items: center;
}

.tools button:hover:not(:disabled) {
  background: var(--surface-2);
}

.tools button:disabled {
  opacity: 0.3;
}

.add {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin: 4px 0 0 4px;
  font-size: 13px;
  font-weight: 650;
  color: var(--accent-text);
}

.module-add {
  margin-top: 10px;
}

.admin-bar {
  display: flex;
  gap: 8px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.danger {
  color: var(--danger) !important;
}

.lesson {
  min-width: 0;
}

.pad {
  padding: 20px 22px;
}

.ltitle-main {
  margin: 0 0 14px;
  font-size: 22px;
}

.downloads {
  margin-top: 20px;
}

.downloads h3 {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0 0 8px;
  font-size: 15px;
}

.download {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  margin-bottom: 6px;
  border-radius: 10px;
  background: var(--surface-2);
  color: var(--text);
  font-weight: 600;
  font-size: 14px;
}

.download:hover {
  background: color-mix(in srgb, var(--surface-2) 85%, var(--text) 8%);
}

.dname {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}

.dsize {
  flex: none;
  color: var(--text-3);
  font-weight: 500;
  font-size: 13px;
}

.type {
  display: grid;
  place-items: center;
  flex: none;
  min-width: 42px;
  height: 30px;
  padding: 0 6px;
  border-radius: 6px;
  background: #6b7280;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.03em;
}

.type[data-ext='PDF'] {
  background: #e5484d;
}

.type[data-ext='DOC'],
.type[data-ext='DOCX'] {
  background: #2f6fe4;
}

.type[data-ext='XLS'],
.type[data-ext='XLSX'],
.type[data-ext='CSV'] {
  background: #2fb36b;
}

.type[data-ext='PPT'],
.type[data-ext='PPTX'] {
  background: #f07b16;
}

.dl-icon {
  flex: none;
  color: var(--accent-text);
}

/* Where am I: module, lesson number, time. */
.where {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
  margin: 0 0 6px;
  color: var(--text-3);
  font-size: 13px;
  font-weight: 650;
}

.where span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.where span + span::before {
  content: '·';
  margin-right: 8px;
}

.where .done-tag {
  color: #2fb36b;
}

.player {
  position: relative;
}

.up-next {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 16px;
  border-radius: 12px;
  background: rgb(0 0 0 / 78%);
  color: #fff;
  text-align: center;
}

.up-next small {
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  opacity: 0.8;
}

.up-next strong {
  font-size: 20px;
}

.up-actions {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}

.up-actions .btn-ghost {
  color: #fff;
}

.tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 12px;
  padding: 4px;
  border-radius: 12px;
  background: var(--surface);
  border: 1px solid var(--border);
}

.tabs button {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 38px;
  border-radius: 9px;
  font-weight: 650;
  color: var(--text-2);
}

.tabs button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.tabs .count {
  padding: 0 7px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
  font-size: 12px;
}

.lesson-talk {
  margin-top: 16px;
}

.module-count {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin-left: auto;
  margin-right: 6px;
  font-size: 12px;
  font-weight: 650;
  color: var(--text-3);
}

.module-count.full {
  color: #2fb36b;
}

.meta {
  display: flex;
  gap: 10px;
  margin-top: 2px;
  font-size: 12px;
  color: var(--text-3);
}

.meta span {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
}

.nav .btn-ghost {
  color: var(--text);
}

.done-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  padding: 0 18px;
  border-radius: 999px;
  border: 2px solid #2fb36b;
  color: #2fb36b;
  font-weight: 700;
  transition: background 0.15s ease, color 0.15s ease;
}

.done-btn.on {
  background: #2fb36b;
  color: #fff;
}

/* The big locked card (not the lock mark in the outline, which shares the class). */
.card.locked,
.empty-course {
  text-align: center;
  padding: 48px 24px;
}

.lock-badge {
  display: inline-grid;
  place-items: center;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
}

.card.locked h2 {
  margin: 14px 0 6px;
}

.card.locked p,
.empty-course p {
  margin: 0;
  color: var(--text-2);
}

.contents-btn {
  display: none;
}

.state {
  max-width: 480px;
  margin: 60px auto;
  text-align: center;
  color: var(--text-2);
}

@media (max-width: 899px) {
  .course {
    grid-template-columns: 1fr;
    padding: 10px 0;
  }

  .outline {
    display: none;
    position: static;
    max-height: none;
    margin: 0 12px;
  }

  .outline.open {
    display: block;
  }

  .tabs {
    margin: 0 12px 10px;
    gap: 2px;
  }

  .tabs button {
    gap: 4px;
    font-size: 13.5px;
  }

  .contents-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 12px 10px;
    padding: 10px 14px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--border);
    font-weight: 650;
    width: calc(100% - 24px);
  }

  .lesson .card {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  .pad {
    padding: 16px;
  }

  .nav {
    flex-wrap: wrap;
  }

  .done-btn {
    order: -1;
    width: 100%;
    justify-content: center;
  }
}
</style>
