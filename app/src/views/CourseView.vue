<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { renderMarkdown } from '../lib/markdown.js'
import { startAttachment } from '../lib/attachments.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import Icon from '../components/Icon.vue'
import LessonPlayer from '../components/LessonPlayer.vue'
import LessonEditor from '../components/LessonEditor.vue'
import CourseAccess from '../components/CourseAccess.vue'
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
const emptyHint = computed(() => around(/* translators: %s: the "Edit course" button label, shown in bold */ t.__('Switch on %s and add your first lesson.', 'membuild-community')))

async function loadCourse() {
  loadError.value = ''
  try {
    course.value = await api.course(props.courseId)
    editing.value = course.value.can_manage && !!route.query.edit
    if (!props.lessonId) {
      // Resume where the member left off: the first open lesson not yet done.
      const resume = allLessons.value.find((l) => !l.completed && !l.locked) || allLessons.value[0]
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

function open(id) {
  router.push({ name: 'course', params: { courseId: props.courseId, lessonId: id }, query: route.query })
}

function applyProgress(res) {
  Object.assign(course.value, { progress: res.progress, completed_count: res.completed_count })
}

async function toggleComplete() {
  const l = lesson.value
  const done = !l.completed
  l.completed = done
  if (current.value) current.value.completed = done
  try {
    applyProgress(done ? await api.completeLesson(l.id) : await api.uncompleteLesson(l.id))
    pointsMayHaveChanged()
  } catch {
    l.completed = !done
    if (current.value) current.value.completed = !done
  }
}

// An uploaded lesson video counts as done when it plays to the end.
function onVideoEnded() {
  if (lesson.value && !lesson.value.completed) toggleComplete()
}

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
  if (l) Object.assign(l, { title: updated.title, has_video: !!updated.media, drip_days: updated.drip_days })
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
    <aside class="outline card" :class="{ open: outlineOpen }">
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
                <span class="ltitle">{{ l.title }}</span>
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

      <div v-if="lessonError" class="card locked">
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
        <h2 class="ltitle-main">{{ lesson.title }}</h2>
        <LessonPlayer v-if="lesson.media" :media="lesson.media" @ended="onVideoEnded" />
        <div v-if="lesson.content" class="md" v-html="html" />

        <section v-if="lesson.resources.length" class="downloads">
          <h3>{{ t.__('Downloads', 'membuild-community') }}</h3>
          <a v-for="r in lesson.resources" :key="r.media_id" :href="r.url" class="download" target="_blank" rel="noopener" download>
            <Icon name="share" :size="16" class="dl-icon" /> {{ r.name }}
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

      <div v-else-if="!allLessons.length" class="card pad empty-course">
        <h2>{{ t.__('No lessons yet', 'membuild-community') }}</h2>
        <p v-if="course.can_manage">{{ emptyHint[0] }}<strong>{{ t.__('Edit course', 'membuild-community') }}</strong>{{ emptyHint[1] }}</p>
        <p v-else>{{ t.__('Lessons are on their way. Check back soon.', 'membuild-community') }}</p>
      </div>
    </main>
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
  margin: 0 0 8px;
  font-size: 15px;
}

.download {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  margin-bottom: 6px;
  border-radius: 10px;
  background: var(--surface-2);
  color: var(--text);
  font-weight: 600;
  font-size: 14px;
}

.dl-icon {
  transform: rotate(90deg);
  color: var(--accent-text);
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

.locked,
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

.locked h2 {
  margin: 14px 0 6px;
}

.locked p,
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
