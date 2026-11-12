<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { renderMarkdown } from '../lib/markdown.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import Icon from '../components/Icon.vue'
import ThemeSlot from '../components/ThemeSlot.vue'
import LessonPlayer from '../components/LessonPlayer.vue'
import LessonDiscussion from '../components/LessonDiscussion.vue'
import CourseComplete from '../components/CourseComplete.vue'
import LessonDone from '../components/LessonDone.vue'
import { popups } from '../lib/popups.js'
import CourseOverview from '../components/CourseOverview.vue'
import CourseBuilder from '../components/CourseBuilder.vue'
import QuizTake from '../components/QuizTake.vue'
import QuizAnswers from '../components/QuizAnswers.vue'
import CourseFinished from '../components/CourseFinished.vue'
import { fileSize, kindName, previewUrl } from '../lib/files.js'
import FileTile from '../components/FileTile.vue'
import { syncCourseCard } from '../lib/courseCards.js'
const FilePreview = defineAsyncComponent(() => import('../components/FilePreview.vue'))
import { showToast } from '../lib/toast.js'
import { lockHint, lockLabel, unlockButton } from '../lib/access.js'
import UnlockDialog from '../components/UnlockDialog.vue'
import { asMember } from '../lib/preview.js'
import { t } from '../lib/i18n.js'
import { siteDay } from '../lib/time.js'
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

// A lesson title cut off in the outline shows in full in a small tooltip (ours: the browser's is slow or missing).
const tip = ref(null) // { text, x, y }
function showTip(e, text) {
  const title = e.currentTarget.querySelector('.lv-ltitle')
  if (!title || title.scrollWidth <= title.clientWidth) return
  const r = e.currentTarget.getBoundingClientRect()
  // Never past the screen's edges: on a narrow screen it starts 12px in and wraps.
  const width = Math.min(320, window.innerWidth - 24)
  tip.value = { text, x: Math.max(12, Math.min(r.left + 10, window.innerWidth - width - 12)), y: r.top - 8 }
}
watch(() => props.lessonId, () => (tip.value = null)) // a tap opens the lesson; don't leave the tooltip behind
// The course outline sits beside a lesson and beside All posts (the Overview lists the lessons itself).
const withOutline = computed(() => tab.value === 'lesson' || tab.value === 'posts')
// The owner's view, unless they chose See it as a member.
const ownerView = computed(() => !!course.value?.can_manage && !asMember.value)
const allLessons = computed(() => course.value?.modules.flatMap((m) => m.lessons) || [])
const current = computed(() => allLessons.value.find((l) => l.id === lesson.value?.id))
const nextLocked = computed(() => allLessons.value.find((l) => l.id === lesson.value?.next_id)?.locked)
const html = computed(() => renderMarkdown(lesson.value?.content || ''))
// Overview · Lesson · Discussion · Resources, kept in the address so a link can open a tab.
// Without a lesson in the address, the course shows its overview.
const tab = computed(() => {
  const asked = route.query.tab === 'discussion' ? 'posts' : route.query.tab // old links said "discussion"
  if (asked === 'resources') return props.lessonId ? 'lesson' : 'overview' // files now sit in their lessons
  if (asked === 'posts' && course.value?.questions === 'off') return props.lessonId ? 'lesson' : 'overview'
  return ['overview', 'posts'].includes(asked) ? asked : props.lessonId ? 'lesson' : 'overview'
})
const openNext = () => allLessons.value.find((l) => !l.completed && !l.locked)
// Caught up with every open lesson: the last one they finished, not lesson 1.
const resumeLesson = () => openNext() || allLessons.value.findLast((l) => l.completed) || allLessons.value[0]
function setTab(name) {
  if (name === 'lesson' && !props.lessonId) {
    const resume = resumeLesson()
    if (resume) open(resume.id)
    return
  }
  router.replace({ query: { ...route.query, tab: name === 'lesson' || (name === 'overview' && !props.lessonId) ? undefined : name } })
}
const moduleDone = (m) => m.lessons.filter((l) => l.completed).length
// On a start date, seeing the course counts them in; that isn't starting it.
const started = computed(() => (course.value?.completed_count || 0) > 0 || (!!course.value?.started_on && course.value?.opens !== 'date'))
// On a start date and before it: the main button shows the day instead.
const startsOn = computed(() => {
  const c = course.value
  if (c?.opens !== 'date' || !c.starts_on || c.lock || c.can_manage || !allLessons.value.length || !allLessons.value.every((l) => l.locked && l.unlock_at)) return null
  return new Date(`${c.starts_on}T00:00:00`)
})
const startsLabel = (long) => t.sprintf(/* translators: %s: a date */ t.__('Starts %s', 'membuild-community'), startsOn.value.toLocaleDateString(undefined, long ? { weekday: 'long', month: 'long', day: 'numeric' } : { weekday: 'short', month: 'short', day: 'numeric' }))
const nextOpen = computed(() => !!lesson.value?.next_id && !nextLocked.value)
// A lesson that isn't open for them yet (dripped or dated), with the lessons either side of it.
const waiting = computed(() => {
  if (!lessonError.value || course.value?.lock) return null
  const i = allLessons.value.findIndex((l) => l.id === Number(props.lessonId))
  const l = allLessons.value[i]
  if (!l?.unlock_at) return null
  return { ...l, prev: allLessons.value[i - 1] || null, next: allLessons.value[i + 1] || null }
})
// A course in order: this lesson waits for the one before it.
const orderWait = computed(() => {
  if (!lessonError.value || course.value?.lock) return null
  const l = allLessons.value.find((x) => x.id === Number(props.lessonId))
  return l?.after ? l : null
})
/** "This course goes in order. Finish [lesson] first, and this lesson opens.", split around the title so it can be bold.
    After a lesson with a quiz: "Pass the quiz in [Lesson 4: …] first" (or "Finish the quiz" when members may move on anyway). */
const orderText = (l) =>
  (l?.after?.quiz === 'retry'
    ? t.__('This course goes in order. Pass the quiz in %s first, and this lesson opens.', 'membuild-community')
    : l?.after?.quiz
      ? t.__('This course goes in order. Finish the quiz in %s first, and this lesson opens.', 'membuild-community')
      : t.__('This course goes in order. Finish %s first, and this lesson opens.', 'membuild-community')
  ).split('%s')
const lessonName = (a) => (a.number ? t.sprintf(/* translators: 1: lesson number, 2: lesson title */ t.__('Lesson %1$d: %2$s', 'membuild-community'), a.number, a.title) : a.title)
// Under a locked lesson in the outline: why it waits.
const waitsLine = (l) =>
  l.after?.quiz === 'retry'
    ? t.sprintf(/* translators: %d: lesson number */ t.__('Pass the quiz in Lesson %d first', 'membuild-community'), l.after.number)
    : l.after?.quiz
      ? t.sprintf(/* translators: %d: lesson number */ t.__('Finish the quiz in Lesson %d first', 'membuild-community'), l.after.number)
      : ''
const opensDay = (iso) => siteDay(iso, { weekday: 'long', month: 'long', day: 'numeric' })
const opensShort = (iso) => t.sprintf(/* translators: %s: a short date, e.g. "Thu, Oct 29" */ t.__('Opens %s', 'membuild-community'), siteDay(iso, { weekday: 'short', month: 'short', day: 'numeric' }))
/** "This lesson opens on [date], 7 days after you started the course. …", split around the date so it can be bold. */
function waitText(l) {
  const days = l.drip_days
  const text = l.open_on || course.value.opens === 'date'
    ? /* translators: 1: the day it opens, e.g. "Thursday, October 29" */ t.__('This lesson opens on %1$s. We’ll send you a notification when it’s ready.', 'membuild-community')
    : course.value.started_on
      ? /* translators: 1: the day it opens, 2: number of days */ t._n('This lesson opens on %1$s, %2$d day after you started the course. We’ll send you a notification when it’s ready.', 'This lesson opens on %1$s, %2$d days after you started the course. We’ll send you a notification when it’s ready.', days, 'membuild-community')
      : /* translators: 1: the day it opens, 2: number of days */ t._n('This lesson opens on %1$s, %2$d day after you start the course. We’ll send you a notification when it’s ready.', 'This lesson opens on %1$s, %2$d days after you start the course. We’ll send you a notification when it’s ready.', days, 'membuild-community')
  return text.replace('%2$d', days).split('%1$s')
}
// A course without a cover gets the same gradient as its Classroom card.
const fallback = (id) => `linear-gradient(135deg, hsl(${(id * 67) % 360} 65% 55%), hsl(${(id * 67 + 50) % 360} 60% 38%))`
const lessonsOf = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)
const completedOf = (done, total) => t.sprintf(/* translators: 1: lessons completed, 2: lessons in the course */ t.__('%1$d/%2$d lessons completed', 'membuild-community'), done, total)
const kindOf = kindName

/* ---------- Previewing a lesson file ---------- */
const previewing = ref(null)
const canPreview = (r) => !!r.url && (r.mime === 'application/pdf' || (r.mime || '').startsWith('image/'))
// Phones open a PDF in their own viewer (better zoom and scrolling); pictures and desktop use the lightbox.
const phoneViewer = () => window.matchMedia('(max-width: 767px), (pointer: coarse)').matches
function preview(r) {
  if (r.mime === 'application/pdf' && phoneViewer()) return window.open(previewUrl(r), '_blank', 'noopener')
  previewing.value = r
}

/* ---------- Quizzes (canvas: Quizzes · Member) ---------- */
// The lesson's quiz as this member stands with it; only for members (owners edit it in the builder).
const lessonQuiz = computed(() => (lesson.value?.quiz?.questions > 0 && !ownerView.value ? lesson.value.quiz : null))
// ?quiz=take or ?quiz=answers on a lesson: the quiz's own page, without the lesson bar.
const quizMode = computed(() => (['take', 'answers'].includes(route.query.quiz) ? route.query.quiz : null))
const certPage = computed(() => !!route.query.certificate)
const quizPassedLabel = (q) => t.sprintf(/* translators: 1: right answers, 2: number of questions */ t.__('Passed %1$d of %2$d', 'membuild-community'), q.last?.passed ? q.last.score : q.pass_mark, q.questions)
const quizDoneLabel = (q) => t.sprintf(/* translators: 1: right answers, 2: number of questions */ t.__('Done %1$d of %2$d', 'membuild-community'), q.last?.score ?? 0, q.questions)
const quizCardTitle = (q) => t.sprintf(/* translators: %d: number of questions */ t._n('Quiz · %d question', 'Quiz · %d questions', q.questions, 'membuild-community'), q.questions)
const quizNeedLine = (q) =>
  q.on_fail === 'move_on'
    ? t.sprintf(/* translators: 1: pass mark, 2: number of questions */ t.__('You need %1$d of %2$d to pass. If you don’t, you can still move on.', 'membuild-community'), q.pass_mark, q.questions)
    : t.sprintf(/* translators: 1: pass mark, 2: number of questions */ t.__('You need %1$d of %2$d to pass. You can try again as many times as you like.', 'membuild-community'), q.pass_mark, q.questions)
const quizAtLine = (q) => t.sprintf(/* translators: 1: question number, 2: number of questions */ t.__('You’re on question %1$d of %2$d', 'membuild-community'), q.at || 1, q.questions)
const carryOnLabel = (q) => t.sprintf(/* translators: 1: question number, 2: number of questions */ t.__('Carry on (question %1$d of %2$d)', 'membuild-community'), q.at || 1, q.questions)
const quizScoreLine = (q) => t.sprintf(/* translators: 1: right answers, 2: number of questions, 3: pass mark */ t.__('%1$d of %2$d. You need %3$d to pass.', 'membuild-community'), q.last?.score ?? 0, q.questions, q.pass_mark)
function openQuiz(mode = 'take', id = props.lessonId) {
  router.push({ name: 'course', params: { courseId: props.courseId, lessonId: id }, query: { quiz: mode } })
}
function closeQuiz() {
  if (courseDone.value) return showCourseDone()
  router.push({ name: 'course', params: { courseId: props.courseId, lessonId: props.lessonId }, query: {} })
}
// Back on the lesson after the quiz page: its card and button show where they stand now.
watch(
  () => route.query.quiz,
  async (now, before) => {
    if (!before || now || !lesson.value || lesson.value.id !== Number(props.lessonId)) return
    try {
      const fresh = await api.lesson(lesson.value.id)
      if (lesson.value?.id === fresh.id) lesson.value.quiz = fresh.quiz
    } catch {
      // The card keeps what it had.
    }
  },
)
/** The last answer of a try: the course's progress moves when it counts (passed, or finished when members may move on). */
function onQuizFinished(res) {
  const l = lesson.value
  if (res.quiz && l) l.quiz = res.quiz
  pointsMayHaveChanged()
  if (!res.progress || !l) return
  const before = course.value.progress
  applyProgress(res.progress)
  l.completed = true
  if (current.value) current.value.completed = true
  if (course.value.in_order) {
    const nx = allLessons.value.find((x) => x.id === l.next_id)
    if (nx && !nx.unlock_at && !nx.completed) {
      nx.after = null
      nx.locked = false
    }
  }
  // The course is finished, but the member first sees the last question's Why and their result:
  // the result's button (or leaving the quiz) opens Course finished.
  if (res.progress.progress === 100 && before < 100) courseDone.value = { cert: res.progress.certificate || null }
}
const finishedCert = ref(null)
const courseDone = ref(null)
function showCourseDone() {
  const done = courseDone.value
  courseDone.value = null
  if (!done) return false
  if (done.cert) {
    finishedCert.value = done.cert
    router.push({ name: 'course', params: { courseId: props.courseId }, query: { certificate: 1 } })
  } else {
    closeQuiz()
    finished.value = true
  }
  return true
}

// Mark Complete keeps you on the lesson: the button turns into Next lesson (or Finish course).
// "Lesson done!": the same kind of celebration as a level or a finished course, smaller.
const doneCard = ref(null)
async function markComplete() {
  const wasDone = lesson.value.completed
  doneCard.value = null
  await toggleComplete(false)
  if (!wasDone && lesson.value?.completed && !finished.value) {
    const after = allLessons.value.find((x) => x.id === lesson.value.next_id)
    const next = nextOpen.value ? after : null
    // An open next lesson: the card counts down and goes there. A waiting one: it says when it opens.
    doneCard.value = { key: Date.now(), lesson: lesson.value?.title || '', count: course.value.completed_count, total: course.value.lessons_count, course: course.value.title, next: next ? { id: next.id, title: next.title } : null, opensAt: !next && after?.unlock_at ? after.unlock_at : null }
  }
}
function goNext(id) {
  doneCard.value = null
  open(id)
}
// While "Lesson done" is up, a level-up or a small note waits its turn.
watch(doneCard, (v) => (popups.lesson = !!v))
onBeforeUnmount(() => (popups.lesson = false))
watch(() => props.lessonId, () => (doneCard.value = null))
// Course cards in posts show where this member stands; keep them in step.
watch(() => course.value && [course.value.id, course.value.completed_count, course.value.progress], () => syncCourseCard(course.value))
function continueCourse() {
  const l = resumeLesson()
  if (l) open(l.id)
}
const finished = ref(false) // the "You finished" moment
const upNext = ref(null) // after a video ends: { id, title, seconds }
let upTimer = null

async function loadCourse() {
  loadError.value = ''
  try {
    course.value = await api.course(props.courseId)
    editing.value = course.value.can_manage && !!route.query.edit
    // A course the member is part-way through picks up where they left off; a new,
    // finished or locked one opens on its overview (admins editing go straight to a lesson).
    const c = course.value
    const midway = c.completed_count > 0 && c.progress < 100 && !c.lock
    if (!props.lessonId && !route.query.tab && midway && !editing.value) {
      const resume = openNext() // caught up and waiting for the next lesson: the overview shows when it opens
      if (resume) router.replace({ name: 'course', params: { courseId: props.courseId, lessonId: resume.id }, query: route.query })
    }
  } catch (err) {
    loadError.value = err.status === 404 ? t.__('This course isn’t available.', 'membuild-community') : err.message
  }
}

// A locked course: "Get access for $49/month" or "How to unlock" opens what it takes.
const unlocking = ref(false)
const rechecking = ref(false)
const notPaidYet = ref(false) // "Check again" found no payment: the dialog says so
async function recheck() {
  rechecking.value = true
  notPaidYet.value = false
  await loadCourse()
  rechecking.value = false
  if (!course.value?.lock) {
    unlocking.value = false
    showToast(t.__('You’re in. The course is open.', 'membuild-community'), '🎉')
    if (props.lessonId) loadLesson(Number(props.lessonId))
  } else notPaidYet.value = true
}

// The words for a locked course (the dripped and in-order cards build their own from the outline).
const lockedMessage = () => {
  const lock = course.value?.lock
  if (lock?.reason === 'level') return t.sprintf(/* translators: %d: level number */ t.__('Reach Level %d to unlock this course.', 'membuild-community'), lock.level)
  if (lock) return t.__('This course is for members of a group.', 'membuild-community')
  return t.__('This lesson is not available.', 'membuild-community')
}

const latestLesson = useLatest()
async function loadLesson(id) {
  const current = latestLesson() // clicking through lessons quickly: only the last one opens
  lessonError.value = null
  if (!id) {
    lesson.value = null
    return
  }
  // The outline already knows a lesson is locked: show why without asking the server
  // (which would refuse with a 403 that lands in the browser console).
  await courseReady
  if (!current()) return
  const outlineLesson = allLessons.value.find((l) => l.id === id)
  if (course.value?.lock || outlineLesson?.locked) {
    lesson.value = null
    lessonError.value = { message: lockedMessage(), title: outlineLesson?.title }
    outlineOpen.value = false
    window.scrollTo({ top: 0 })
    return
  }
  try {
    const loaded = await api.lesson(id)
    if (!current()) return
    lesson.value = loaded
  } catch (err) {
    if (!current()) return
    lesson.value = null
    lessonError.value = { message: err.message, title: outlineLesson?.title }
  }
  outlineOpen.value = false
  window.scrollTo({ top: 0 })
}

// Set before the lesson watcher runs, so a lesson opened by link waits for its outline.
let courseReady = Promise.resolve()
watch(() => props.courseId, () => (courseReady = loadCourse()), { immediate: true })
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

// A post picked on the Overview opens its lesson and scrolls to it (?at=; ?post= would open it in a box).
function open(id, postId = 0) {
  router.push({ name: 'course', params: { courseId: props.courseId, lessonId: id }, query: { ...route.query, tab: undefined, at: postId || undefined } })
}
const focusPost = computed(() => Number(route.query.at) || 0)

function applyProgress(res) {
  // points_awarded: what finishing really gave (0 the second time), so the finish card never claims points twice.
  Object.assign(course.value, { progress: res.progress, completed_count: res.completed_count, points_awarded: res.points_awarded })
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
    if (course.value.in_order) {
      // In order: finishing this opens the next one; undoing it closes it again (unless that one's done too).
      const nx = allLessons.value.find((x) => x.id === l.next_id)
      if (nx && !nx.unlock_at && !nx.completed) {
        nx.after = done ? null : { id: l.id, title: l.title }
        nx.locked = !done
      }
    }
    pointsMayHaveChanged()
    if (!done) return
    if (res.progress === 100 && before < 100) {
      if (res.certificate) {
        finishedCert.value = res.certificate
        router.push({ name: 'course', params: { courseId: props.courseId }, query: { certificate: 1 } })
      } else finished.value = true
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
  if (lessonQuiz.value && !lesson.value.completed) return // a quiz lesson is done by taking the quiz
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

function toggleModule(id) {
  const s = new Set(collapsed.value)
  s.has(id) ? s.delete(id) : s.add(id)
  collapsed.value = s
}

/* ---------- Editing (admins) ---------- */

// `part` opens the builder on one of its tabs: 'settings', 'map', or the lessons (default).
function setEditing(on, part) {
  editing.value = on
  router.replace({ query: on ? { ...route.query, edit: 1, part: part || undefined } : {} })
}

const contentsCount = (done, total) => t.sprintf(/* translators: 1: lessons completed, 2: total lessons */ t.__('Course contents · %1$d/%2$d', 'membuild-community'), done, total)
</script>

<template>
  <div v-if="loadError" class="state">
    <p>{{ loadError }}</p>
    <RouterLink to="/classroom" class="btn btn-ghost">{{ t.__('Back to Classroom', 'membuild-community') }}</RouterLink>
  </div>

  <!-- Loading: the course page's shape in grey. -->
  <div v-else-if="!course" class="lv lv-loading" aria-busy="true">
    <div class="skeleton" style="width: 110px; height: 18px; margin: 12px 0 18px" />
    <div class="lv-sk sk-wait">
      <div class="card lv-sk-side sk-wait"><div class="skeleton" style="aspect-ratio: 16 / 9; border-radius: 12px" /><div class="skeleton" style="width: 70%; height: 20px; margin-top: 16px" /><div v-for="n in 5" :key="n" class="skeleton" style="height: 14px; margin-top: 14px" :style="{ width: `${90 - n * 8}%` }" /></div>
      <div><div class="skeleton" style="height: 52px; border-radius: 14px" /><div class="skeleton" style="height: 340px; margin-top: 16px; border-radius: 16px" /><div class="skeleton" style="width: 50%; height: 22px; margin-top: 20px" /><div class="skeleton" style="width: 80%; height: 14px; margin-top: 12px" /></div>
    </div>
  </div>

  <!-- Editing (owners): the Version 2 course builder -->
  <CourseBuilder v-else-if="course && editing" :course="course" :lesson-id="lessonId" @update:course="(c) => (course = c)" :after-close="(c) => course?.id === c.id && (course = c)" @exit="setEditing(false)" @preview="asMember = true; setEditing(false)" />

  <!-- Course finished, with the certificate (?certificate=1). -->
  <CourseFinished v-else-if="course && certPage" :course="course" :cert="finishedCert" @none="router.replace({ query: {} }); finished = course.progress >= 100" />

  <!-- A lesson's quiz, or Your answers (?quiz=take|answers): their own page, without the lesson bar. -->
  <template v-else-if="course && quizMode && lessonId && !lessonError && (!lesson || lesson.quiz)">
    <div v-if="!lesson || lesson.id !== lessonId" class="lv-quiz-wait"><div class="skeleton" style="height: 340px; border-radius: 16px" /></div>
    <QuizTake v-else-if="quizMode === 'take'" :lesson="lesson" :in-order="!!course.in_order" @back="closeQuiz" @answers="openQuiz('answers')" @next="open(lesson.next_id)" @finished="onQuizFinished" :course-done="courseDone" @done="showCourseDone" />
    <QuizAnswers v-else :lesson="lesson" @back="closeQuiz" @retry="openQuiz('take')" />
    <ThemeSlot v-if="finished" name="course.finished" :data="{ course, onClose: () => (finished = false) }"><CourseComplete :course="course" @close="finished = false" /></ThemeSlot>
  </template>

  <!-- Taking the course: Overview · Lesson · All posts, the course as its own space. -->
  <div v-else-if="course" class="lv" :class="`on-${tab}`">
    <!-- Back to Classroom; owners get Course settings and All lessons on the same line (the old owner bar is gone:
         the See it as a member switch shows the members' view). -->
    <div class="lv-top">
      <RouterLink to="/classroom" class="lv-back"><Icon name="chevronLeft" :size="18" /> {{ t.__('Classroom', 'membuild-community') }}</RouterLink>
      <span v-if="ownerView" class="owner-links">
        <button :aria-label="t.__('Course settings', 'membuild-community')" @click="setEditing(true, 'settings')"><Icon name="settings" :size="18" /> <span>{{ t.__('Course settings', 'membuild-community') }}</span></button>
        <button :aria-label="t.__('All lessons', 'membuild-community')" @click="setEditing(true)"><Icon name="list" :size="18" /> <span>{{ t.__('All lessons', 'membuild-community') }}</span></button>
      </span>
    </div>

    <div v-if="course.can_manage && asMember" class="preview-bar">
      <Icon name="eye" :size="20" />
      <span class="pv-long">{{ t.__('You’re seeing this course the way members see it. Marking lessons done here counts for you.', 'membuild-community') }}</span>
      <span class="pv-short">{{ t.__('Member view', 'membuild-community') }}</span>
      <button class="btn btn-lg btn-ghost" @click="asMember = false">{{ t.__('Back to your view', 'membuild-community') }}</button>
    </div>
    <p v-if="course.status === 'draft'" class="lv-draft">{{ t.__('Draft: only you can see this course.', 'membuild-community') }}</p>

    <h1 class="sr-only">{{ course.title }}</h1>
    <div class="lv-grid">
      <!-- The outline, beside a lesson -->
      <aside v-if="withOutline" ref="outlineEl" class="lv-outline card" :class="{ open: outlineOpen }" :aria-label="t.__('Course outline', 'membuild-community')">
        <ThemeSlot name="course.outline" :data="{ course, lessonId, open }">
        <div class="lv-cover" :style="course.cover ? null : { background: fallback(course.id) }">
          <img v-if="course.cover" :src="course.cover" alt="" />
        </div>
        <div class="lv-ohead">
          <h2 class="lv-otitle">{{ course.title }}</h2>
          <div class="lv-prog">
            <span>{{ completedOf(course.completed_count, course.lessons_count) }}</span><span>{{ course.progress }}%</span>
          </div>
          <div class="lv-track"><i :style="{ width: `${course.progress}%` }" /></div>
          <p v-if="course.in_order" class="lv-inorder"><Icon name="lock" :size="13" /> {{ t.__('In order: each lesson opens after the one before', 'membuild-community') }}</p>
        </div>
        <nav class="lv-modules" :aria-label="t.__('Course contents', 'membuild-community')">
          <section v-for="m in course.modules" :key="m.id" class="lv-module">
            <button class="lv-mhead" :aria-expanded="!collapsed.has(m.id)" @click="toggleModule(m.id)">
              <span>
                <strong>{{ m.title }}</strong>
                <small>{{ moduleDone(m) === m.lessons.length && m.lessons.length ? t.__('All done', 'membuild-community') : lessonsOf(m.lessons.length) }}</small>
              </span>
              <Icon name="chevronDown" :size="18" :class="{ flip: !collapsed.has(m.id) }" />
            </button>
            <ul v-show="!collapsed.has(m.id)">
              <li v-for="l in m.lessons" :key="l.id" :class="{ active: l.id === lessonId }">
                <button :aria-label="l.title" @mouseenter="showTip($event, l.title)" @mouseleave="tip = null" @focus="showTip($event, l.title)" @blur="tip = null" @click="open(l.id)">
                  <span class="lv-mark" :class="{ done: l.completed, locked: l.locked }">
                    <Icon v-if="l.locked" name="lock" :size="13" />
                    <svg v-else-if="l.completed" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  </span>
                  <span class="lv-lwrap">
                    <span class="lv-ltitle">{{ l.title }}</span>
                    <small v-if="l.locked && l.after?.quiz" class="lv-lsub">{{ waitsLine(l) }}</small>
                    <small v-else-if="l.quiz" class="lv-lsub"><Icon name="quiz" :size="13" /> {{ t.__('Quiz', 'membuild-community') }}</small>
                  </span>
                </button>
              </li>
              <li v-if="!m.lessons.length" class="lv-none">{{ t.__('No lessons yet', 'membuild-community') }}</li>
            </ul>
          </section>
        </nav>
        </ThemeSlot>
        <ThemeSlot name="course.lesson-side" :data="{ course, lesson }" />
      </aside>

      <div class="lv-main">
        <!-- The bar: tabs, then previous / next and the one main button. Stays at the top while you scroll. -->
        <div class="lv-bar">
          <ThemeSlot name="course.lesson-bar" :off="ownerView" :data="{ course, lesson, tab, setTab, open, markComplete, continueCourse, quiz: lessonQuiz, openQuiz }">
          <button v-if="withOutline" class="lv-contents" @click="outlineOpen = !outlineOpen">
            <Icon name="cap" :size="18" /> {{ contentsCount(course.completed_count, course.lessons_count) }}
          </button>
          <nav class="lv-tabs" role="tablist" :aria-label="t.__('Course sections', 'membuild-community')">
            <button role="tab" :aria-selected="tab === 'overview'" :class="{ on: tab === 'overview' }" @click="setTab('overview')">{{ t.__('Overview', 'membuild-community') }}</button>
            <button role="tab" :aria-selected="tab === 'lesson'" :class="{ on: tab === 'lesson' }" :disabled="!allLessons.length" @click="setTab('lesson')">{{ t.__('Lesson', 'membuild-community') }}</button>
            <button v-if="course.questions !== 'off'" role="tab" :aria-selected="tab === 'posts'" :class="{ on: tab === 'posts' }" @click="setTab('posts')">{{ t.__('All posts', 'membuild-community') }}</button>
          </nav>

          <div class="lv-actions">
            <template v-if="tab === 'lesson' && lesson">
              <button class="lv-round" :title="t.__('Previous lesson', 'membuild-community')" :aria-label="t.__('Previous lesson', 'membuild-community')" :disabled="!lesson.prev_id" @click="open(lesson.prev_id)"><Icon name="chevronLeft" :size="22" /></button>
              <button class="lv-round" :title="nextLocked ? t.__('The next lesson isn’t open yet', 'membuild-community') : t.__('Next lesson', 'membuild-community')" :aria-label="t.__('Next lesson', 'membuild-community')" :disabled="!nextOpen" @click="open(lesson.next_id)"><Icon name="chevronRight" :size="22" /></button>
              <button v-if="ownerView" class="btn btn-lg btn-dark lv-main-btn" @click="setEditing(true)"><Icon name="pencil" :size="18" /> {{ t.__('Edit this lesson', 'membuild-community') }}</button>
              <!-- A quiz lesson: Take the quiz where Mark Complete is; it counts as done once passed (rule 1). -->
              <template v-else-if="lessonQuiz && lessonQuiz.state !== 'new' || lessonQuiz && !lesson.completed">
                <!-- Passed (or finished) but the lesson was un-marked: the usual Mark Complete marks it done again. -->
                <button v-if="(lessonQuiz.state === 'passed' || lessonQuiz.state === 'done') && !lesson.completed" class="btn btn-lg btn-dark lv-main-btn" @click="toggleComplete()"><span class="lv-ring" /> {{ t.__('Mark Complete', 'membuild-community') }}</button>
                <button v-else-if="lessonQuiz.state === 'passed'" class="btn btn-lg lv-done" @click="openQuiz('answers')"><Icon name="check" :size="18" /> {{ quizPassedLabel(lessonQuiz) }}</button>
                <button v-else-if="lessonQuiz.state === 'done'" class="btn btn-lg lv-done" @click="openQuiz('answers')"><Icon name="check" :size="18" /> {{ quizDoneLabel(lessonQuiz) }}</button>
                <button v-else-if="lessonQuiz.state === 'open'" class="btn btn-lg btn-primary lv-main-btn" @click="openQuiz()">{{ t.__('Carry on where you left off', 'membuild-community') }}</button>
                <button v-else class="btn btn-lg btn-primary lv-main-btn" @click="openQuiz()"><Icon name="quiz" :size="18" /> {{ t.__('Take the quiz', 'membuild-community') }}</button>
              </template>
              <template v-else-if="lesson.completed">
                <!-- Done: one green Completed (press it to undo); the › arrow or the countdown moves on. -->
                <button class="btn btn-lg lv-done" :title="t.__('Mark as not done', 'membuild-community')" @click="markComplete"><Icon name="check" :size="18" /> {{ t.__('Completed', 'membuild-community') }}</button>
              </template>
              <button v-else class="btn btn-lg btn-dark lv-main-btn" @click="markComplete"><span class="lv-ring" /> {{ t.__('Mark Complete', 'membuild-community') }}</button>
            </template>
            <template v-else-if="tab === 'lesson' && waiting">
              <button class="lv-round" :title="t.__('Previous lesson', 'membuild-community')" :aria-label="t.__('Previous lesson', 'membuild-community')" :disabled="!waiting.prev" @click="open(waiting.prev.id)"><Icon name="chevronLeft" :size="22" /></button>
              <button class="lv-round" :title="t.__('Next lesson', 'membuild-community')" :aria-label="t.__('Next lesson', 'membuild-community')" :disabled="!waiting.next || waiting.next.locked" @click="open(waiting.next.id)"><Icon name="chevronRight" :size="22" /></button>
            </template>
            <button v-else-if="course.lock && unlockButton(course.lock)" class="btn btn-lg btn-dark lv-main-btn" @click="unlocking = true"><Icon name="lock" :size="18" /> {{ unlockButton(course.lock) }}</button>
            <button v-else-if="tab !== 'lesson' && startsOn" class="btn btn-lg lv-main-btn lv-starts" disabled>{{ startsLabel(false) }}</button>
            <button v-else-if="tab !== 'lesson' && !course.lock && allLessons.length" class="btn btn-lg btn-dark lv-main-btn" @click="continueCourse">
              {{ course.progress >= 100 ? t.__('Review the course', 'membuild-community') : started ? t.__('Continue', 'membuild-community') : t.__('Start course', 'membuild-community') }} <Icon name="arrowRight" :size="18" />
            </button>
          </div>
          </ThemeSlot>
        </div>

        <ThemeSlot v-if="tab === 'overview'" name="course.front" :data="{ course, onOpen: open, onTab: setTab }"><CourseOverview :course="course" @open="open" @tab="setTab" /></ThemeSlot>

        <template v-else-if="tab === 'posts' && course.questions !== 'off'">
          <div v-if="course.lock" class="card locked">
            <span class="lock-badge"><Icon name="lock" :size="28" /></span>
            <h2>{{ lockLabel(course.lock) }}</h2>
            <p>{{ t.__('The posts open with the course.', 'membuild-community') }}</p>
          </div>
          <LessonDiscussion v-else :course="course" @posted="onPosted" @open-lesson="open" />
        </template>

        <div v-else-if="waiting" class="card locked lv-wait">
          <span class="lock-badge"><Icon name="lock" :size="28" /></span>
          <h2>{{ waiting.title }}</h2>
          <p>{{ waitText(waiting)[0] }}<b>{{ opensDay(waiting.unlock_at) }}</b>{{ waitText(waiting)[1] }}</p>
          <span class="lv-opens"><Icon name="lock" :size="14" /> {{ opensShort(waiting.unlock_at) }}</span>
          <button v-if="waiting.prev" class="btn btn-lg btn-dark cta" @click="open(waiting.prev.id)"><Icon name="chevronLeft" :size="18" /> {{ t.sprintf(/* translators: %s: lesson title */ t.__('Back to %s', 'membuild-community'), waiting.prev.title) }}</button>
          <button v-else class="btn btn-lg btn-dark cta" @click="setTab('overview')"><Icon name="chevronLeft" :size="18" /> {{ t.__('Back to the course', 'membuild-community') }}</button>
        </div>

        <div v-else-if="orderWait" class="card locked lv-wait">
          <span class="lock-badge"><Icon name="lock" :size="28" /></span>
          <h2>{{ orderWait.title }}</h2>
          <p>{{ orderText(orderWait)[0] }}<b>{{ orderWait.after.quiz ? lessonName(orderWait.after) : orderWait.after.title }}</b>{{ orderText(orderWait)[1] }}</p>
          <span v-if="orderWait.after.quiz" class="lv-wait-btns">
            <button class="btn btn-lg btn-primary" @click="openQuiz('take', orderWait.after.id)">{{ t.__('Go to the quiz', 'membuild-community') }}</button>
            <button class="btn btn-lg btn-ghost" @click="open(orderWait.after.id)"><Icon name="chevronLeft" :size="18" /> {{ t.sprintf(/* translators: %d: lesson number */ t.__('Back to Lesson %d', 'membuild-community'), orderWait.after.number) }}</button>
          </span>
          <button v-else class="btn btn-lg btn-primary cta" @click="open(orderWait.after.id)">{{ t.sprintf(/* translators: %s: lesson title */ t.__('Go to %s', 'membuild-community'), orderWait.after.title) }} <Icon name="arrowRight" :size="18" /></button>
        </div>

        <div v-else-if="lessonError" class="card locked">
          <span class="lock-badge"><Icon name="lock" :size="28" /></span>
          <h2>{{ course.lock ? lockLabel(course.lock) : lessonError.title || t.__('Locked lesson', 'membuild-community') }}</h2>
          <p>{{ lessonError.message }}</p>
          <p v-if="course.lock && lockHint(course.lock)" class="how">
            {{ lockHint(course.lock) }}
            <RouterLink v-if="course.lock.reason === 'level'" to="/me">{{ t.__('See your progress', 'membuild-community') }}</RouterLink>
          </p>
          <button v-if="course.lock && unlockButton(course.lock)" class="btn btn-lg btn-dark cta" @click="unlocking = true"><Icon name="lock" :size="18" /> {{ unlockButton(course.lock) }}</button>
          <button v-else class="btn btn-lg btn-ghost cta" @click="setTab('overview')">{{ t.__('Back to the course', 'membuild-community') }}</button>
        </div>

        <article v-else-if="lesson" class="card lv-lesson">
          <div v-if="lesson.media" class="lv-player">
            <LessonPlayer :media="lesson.media" @ended="onVideoEnded" />
            <div v-if="upNext" class="up-next" role="status">
              <small>{{ t.__('Up next', 'membuild-community') }}</small>
              <strong>{{ upNext.title }}</strong>
              <div class="up-actions">
                <button class="btn btn-lg btn-primary" @click="playNext"><Icon name="play" :size="16" /> {{ playNow(upNext.seconds) }}</button>
                <button class="btn btn-lg btn-ghost" @click="cancelNext">{{ t.__('Stay here', 'membuild-community') }}</button>
              </div>
            </div>
          </div>
          <div class="lv-body">
            <h2 class="lv-title">{{ lesson.title }}</h2>
            <div v-if="lesson.content" class="md lv-md" v-html="html" />

            <section v-if="lesson.resources.length" class="lv-files">
              <h3>{{ t.__('Files for this lesson', 'membuild-community') }} <span>({{ lesson.resources.length }})</span></h3>
              <div v-for="r in lesson.resources" :key="r.media_id" class="lv-file">
                <FileTile :ext="r.ext" />
                <span class="lv-fname">
                  <strong>{{ r.name }}</strong>
                  <small>{{ kindOf(r.ext) }}<template v-if="r.size"> · {{ fileSize(r.size) }}</template></small>
                </span>
                <!-- PDFs and pictures can be previewed; Office files only download (browsers can't show them,
                     and an outside viewer would need a public link). -->
                <span class="lv-fbtns">
                  <button v-if="canPreview(r)" class="btn btn-lg btn-ghost" @click="preview(r)"><Icon name="eye" :size="18" /> {{ t.__('Preview', 'membuild-community') }}</button>
                  <a :href="r.url" class="btn btn-lg btn-ghost" target="_blank" rel="noopener" download><Icon name="download" :size="18" /> {{ t.__('Download', 'membuild-community') }}</a>
                </span>
              </div>
            </section>

            <!-- The quiz at the end of the lesson (rule 14), as this member stands with it. -->
            <section v-if="lessonQuiz" class="lv-quiz" :aria-label="t.__('Quiz', 'membuild-community')">
              <template v-if="lessonQuiz.state === 'passed'">
                <span class="lv-quiz-icon ok"><Icon name="check" :size="20" /></span>
                <!-- Passed, then un-marked: Mark Complete shows, so the card doesn't say the lesson is done. -->
                <span v-if="!lesson.completed" class="lv-quiz-text"><strong>{{ t.__('You passed.', 'membuild-community') }}</strong><small>{{ t.__('Mark it complete to carry on.', 'membuild-community') }}</small></span>
                <span v-else class="lv-quiz-text"><strong>{{ t.sprintf(t.__('You passed: %1$d of %2$d', 'membuild-community'), lessonQuiz.last?.passed ? lessonQuiz.last.score : lessonQuiz.pass_mark, lessonQuiz.questions) }}</strong><small>{{ lesson.next_id ? t.__('This lesson is done. The next lesson is open.', 'membuild-community') : t.__('This lesson is done.', 'membuild-community') }}</small></span>
                <span class="lv-quiz-btns">
                  <button v-if="lesson.next_id && nextOpen" class="btn btn-lg btn-primary" @click="open(lesson.next_id)">{{ t.__('Next lesson', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
                  <button class="btn btn-lg btn-ghost" @click="openQuiz('answers')">{{ t.__('See your answers', 'membuild-community') }}</button>
                </span>
              </template>
              <template v-else-if="lessonQuiz.state === 'open'">
                <span class="lv-quiz-icon"><Icon name="quiz" :size="20" /></span>
                <span class="lv-quiz-text"><strong>{{ quizAtLine(lessonQuiz) }}</strong><small>{{ t.__('Your answers so far are saved, so you can carry on where you left off.', 'membuild-community') }}</small></span>
                <span class="lv-quiz-btns"><button class="btn btn-lg btn-primary" @click="openQuiz()">{{ carryOnLabel(lessonQuiz) }}</button></span>
              </template>
              <template v-else-if="lessonQuiz.state === 'failed'">
                <span class="lv-quiz-icon warn"><Icon name="retry" :size="20" /></span>
                <span class="lv-quiz-text"><strong>{{ quizScoreLine(lessonQuiz) }}</strong><small>{{ quizNeedLine(lessonQuiz) }}</small></span>
                <span class="lv-quiz-btns">
                  <button class="btn btn-lg btn-primary" @click="openQuiz()"><Icon name="retry" :size="18" /> {{ t.__('Try again', 'membuild-community') }}</button>
                  <button class="btn btn-lg btn-ghost" @click="openQuiz('answers')">{{ t.__('See your answers', 'membuild-community') }}</button>
                </span>
              </template>
              <template v-else-if="lessonQuiz.state === 'done'">
                <span class="lv-quiz-icon"><Icon name="check" :size="20" /></span>
                <span class="lv-quiz-text"><strong>{{ t.sprintf(t.__('%1$d of %2$d. Saved.', 'membuild-community'), lessonQuiz.last?.score ?? 0, lessonQuiz.questions) }}</strong><small>{{ t.__('You can move on. Try again any time if you want a better score.', 'membuild-community') }}</small></span>
                <span class="lv-quiz-btns">
                  <button v-if="lesson.next_id && nextOpen" class="btn btn-lg btn-primary" @click="open(lesson.next_id)">{{ t.__('Next lesson', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
                  <button class="btn btn-lg btn-ghost" @click="openQuiz()"><Icon name="retry" :size="18" /> {{ t.__('Try again', 'membuild-community') }}</button>
                  <button class="btn btn-lg btn-ghost" @click="openQuiz('answers')">{{ t.__('See your answers', 'membuild-community') }}</button>
                </span>
              </template>
              <template v-else>
                <span class="lv-quiz-icon"><Icon name="quiz" :size="20" /></span>
                <span class="lv-quiz-text"><strong>{{ quizCardTitle(lessonQuiz) }}</strong><small>{{ quizNeedLine(lessonQuiz) }}</small></span>
                <span class="lv-quiz-btns"><button class="btn btn-lg btn-primary" @click="openQuiz()">{{ t.__('Take the quiz', 'membuild-community') }}</button></span>
              </template>
            </section>
          </div>
        </article>
        <LessonDiscussion v-if="lesson && tab === 'lesson' && !lessonError && course.questions !== 'off'" :key="lesson.id" class="lesson-talk" :course="course" :lesson="lesson" :focus-post="focusPost" @posted="onPosted" />

        <div v-else-if="tab === 'lesson' && !allLessons.length" class="card pad empty-course">
          <h2>{{ t.__('No lessons yet', 'membuild-community') }}</h2>
          <p>{{ t.__('Lessons are on their way. Check back soon.', 'membuild-community') }}</p>
        </div>
      </div>
    </div>

    <!-- Phones: the lesson's own bottom bar, in place of the app's tabs. No Next arrow:
         Mark Complete takes you on, and the outline is a tap away. -->
    <div class="lv-dock">
      <template v-if="tab === 'lesson' && lesson && !ownerView">
        <button class="lv-round" :aria-label="t.__('Previous lesson', 'membuild-community')" :disabled="!lesson.prev_id" @click="open(lesson.prev_id)"><Icon name="chevronLeft" :size="22" /></button>
        <template v-if="lessonQuiz && lessonQuiz.state !== 'new' || lessonQuiz && !lesson.completed">
          <button v-if="(lessonQuiz.state === 'passed' || lessonQuiz.state === 'done') && !lesson.completed" class="btn btn-lg btn-dark lv-grow" @click="toggleComplete()"><span class="lv-ring" /> {{ t.__('Mark Complete', 'membuild-community') }}</button>
          <button v-else-if="lessonQuiz.state === 'passed'" class="btn btn-lg lv-done lv-grow" @click="openQuiz('answers')"><Icon name="check" :size="18" /> {{ quizPassedLabel(lessonQuiz) }}</button>
          <button v-else-if="lessonQuiz.state === 'done'" class="btn btn-lg lv-done lv-grow" @click="openQuiz('answers')"><Icon name="check" :size="18" /> {{ quizDoneLabel(lessonQuiz) }}</button>
          <button v-else-if="lessonQuiz.state === 'open'" class="btn btn-lg btn-primary lv-grow" @click="openQuiz()">{{ carryOnLabel(lessonQuiz) }}</button>
          <button v-else class="btn btn-lg btn-primary lv-grow" @click="openQuiz()">{{ t.__('Take the quiz', 'membuild-community') }}</button>
        </template>
        <template v-else-if="lesson.completed">
          <button class="btn btn-lg lv-done lv-grow" :title="t.__('Mark as not done', 'membuild-community')" @click="markComplete"><Icon name="check" :size="18" /> {{ t.__('Completed', 'membuild-community') }}</button>
        </template>
        <template v-else>
          <button class="btn btn-lg btn-dark lv-grow" @click="markComplete"><span class="lv-ring" /> {{ t.__('Mark Complete', 'membuild-community') }}</button>
        </template>
      </template>
      <template v-else-if="tab === 'lesson' && lesson">
        <button class="lv-round" :aria-label="t.__('Previous lesson', 'membuild-community')" :disabled="!lesson.prev_id" @click="open(lesson.prev_id)"><Icon name="chevronLeft" :size="22" /></button>
        <button class="btn btn-lg btn-dark lv-grow" @click="setEditing(true)"><Icon name="pencil" :size="18" /> {{ t.__('Edit this lesson', 'membuild-community') }}</button>
      </template>
      <button v-else-if="course.lock && unlockButton(course.lock)" class="btn btn-lg btn-dark lv-grow" @click="unlocking = true"><Icon name="lock" :size="18" /> {{ unlockButton(course.lock) }}</button>
      <button v-else-if="startsOn" class="btn btn-lg lv-grow lv-starts" disabled>{{ startsLabel(true) }}</button>
      <button v-else-if="!course.lock && allLessons.length" class="btn btn-lg btn-dark lv-grow" @click="continueCourse">
        {{ course.progress >= 100 ? t.__('Review the course', 'membuild-community') : started ? t.__('Continue', 'membuild-community') : t.__('Start course', 'membuild-community') }} <Icon name="arrowRight" :size="18" />
      </button>
      <RouterLink v-else to="/classroom" class="btn btn-lg btn-ghost lv-grow">{{ t.__('Back to Classroom', 'membuild-community') }}</RouterLink>
    </div>
    <span v-if="tip" class="lv-tip" role="tooltip" :style="{ left: tip.x + 'px', top: tip.y + 'px' }">{{ tip.text }}</span>
    <ThemeSlot name="app.lesson-done" :data="{ done: doneCard, onNext: goNext, onClose: () => (doneCard = null) }"><LessonDone :done="doneCard" @next="goNext" @close="doneCard = null" /></ThemeSlot>
    <FilePreview v-if="previewing" :file="previewing" :place="course && lesson ? `${course.title} › ${lesson.title}` : ''" @close="previewing = null" />
    <UnlockDialog v-if="unlocking && course?.lock" :course="course" :checking="rechecking" :not-yet="notPaidYet" @recheck="recheck" @close="unlocking = false; notPaidYet = false" />
    <ThemeSlot v-if="finished" name="course.finished" :data="{ course, onClose: () => (finished = false) }"><CourseComplete :course="course" @close="finished = false" /></ThemeSlot>
  </div>
</template>

<style scoped>
/* Phones: the member-view bar is one short row. */
.preview-bar .pv-short {
  display: none;
}

@media (max-width: 767px) {
  /* One line on phones: eye, "Member view", the button. */
  .preview-bar.preview-bar {
    flex-wrap: nowrap;
    gap: 10px;
    margin: 8px 12px;
    padding: 6px 6px 6px 14px;
    font-size: var(--fs-base);
  }

  .preview-bar.preview-bar .pv-short {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .preview-bar.preview-bar svg {
    flex: none;
  }

  .preview-bar.preview-bar .btn {
    flex: none;
    padding: 0 16px;
    font-size: var(--fs-base);
  }

  .preview-bar .pv-long {
    display: none;
  }

  .preview-bar .pv-short {
    display: inline;
    font-weight: 700;
    white-space: nowrap;
  }

  .preview-bar .btn {
    margin-left: auto;
    white-space: nowrap;
  }

  .preview-bar .btn {
    min-height: 44px;
  }
}

/* The smallest phones: the eye goes so the words and the button still fit. */
@media (max-width: 360px) {
  .preview-bar.preview-bar svg {
    display: none;
  }

  .preview-bar.preview-bar {
    gap: 8px;
    padding-left: 14px;
  }

  .preview-bar.preview-bar .btn {
    padding: 0 12px;
  }
}

.lv-tip {
  position: fixed;
  z-index: 130;
  max-width: min(320px, calc(100vw - 24px));
  padding: 8px 12px;
  overflow-wrap: anywhere;
  border-radius: var(--radius-sm);
  background: var(--text);
  color: var(--surface);
  font-size: var(--fs-md);
  font-weight: 600;
  line-height: 1.4;
  transform: translateY(-100%);
  pointer-events: none;
  box-shadow: var(--shadow-pop);
}

/* A long lesson title stays on one line in the outline (…); pointing at it shows the full title. */
.lv-ltitle {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* See it as a member: the same bar as in the Classroom. */
.preview-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  padding: 10px 10px 10px 18px;
  border-radius: var(--radius-lg);
  background: var(--accent-soft);
  color: var(--text);
  font-size: var(--fs-lg);
  font-weight: 600;
}

.preview-bar span {
  flex: 1;
}

.preview-bar .btn {
  background: var(--surface);
}

.how {
  margin-top: 10px !important;
  font-size: var(--fs-md);
}

.cta {
  display: inline-flex;
  margin-top: 16px;
}

.pad {
  padding: 20px 22px;
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
  border-radius: var(--radius-md);
  background: color-mix(in srgb, var(--scrim) 78%, transparent);
  color: var(--on-color);
  text-align: center;
}

.up-next small {
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-weight: 700;
  opacity: 0.8;
}

.up-next strong {
  font-size: var(--fs-3xl);
}

.up-actions {
  display: flex;
  gap: 8px;
  margin-top: 8px;
}

.up-actions .btn-ghost {
  color: var(--on-color);
}

.lesson-talk {
  margin-top: 36px; /* a clear gap between the lesson and its discussion (24px on phones) */
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

/* "Not open yet": the day it opens, then back to the lesson before it. */
.lv-wait {
  padding: 56px 32px;
}

.lv-wait h2 {
  font-size: var(--fs-6xl);
}

.lv-inorder {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 10px 0 0;
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 650;
}

.card.locked.lv-wait p {
  max-width: 520px;
  margin: 0 auto;
  font-size: var(--fs-2xl);
  line-height: 1.6;
}

.lv-wait p b {
  color: var(--text);
}

.lv-opens {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 18px;
  padding: 6px 16px;
  border-radius: var(--radius-pill);
  background: var(--text);
  color: var(--surface);
  font-size: var(--fs-base);
  font-weight: 700;
}

.lv-wait .cta {
  display: flex;
  width: fit-content;
  margin: 28px auto 0;
}

.card.locked p,
.empty-course p {
  margin: 0;
  color: var(--text-2);
}

.state {
  max-width: 480px;
  margin: 60px auto;
  text-align: center;
  color: var(--text-2);
}

/* ---------- Taking a course (Version 2) ---------- */
.lv {
  max-width: 1180px;
  margin: 0 auto;
  padding: 14px 16px 40px;
}

.lv-back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  font-size: var(--fs-xl);
  font-weight: 650;
  color: var(--text-2);
}

.lv-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.owner-links {
  display: flex;
  gap: 4px;
}

.lv-sk {
  display: grid;
  grid-template-columns: 320px minmax(0, 1fr);
  gap: 16px;
}

.lv-sk-side {
  padding: 14px;
}

@media (max-width: 767px) {
  .lv-sk {
    grid-template-columns: 1fr;
  }

  .lv-sk-side {
    display: none;
  }

  /* Phones: just the icons beside Classroom. */
  .owner-links button span {
    display: none;
  }
}

.owner-links button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 44px;
  padding: 0 12px;
  border-radius: var(--radius-pill);
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--text);
}

.owner-links button:hover {
  background: color-mix(in srgb, var(--text) 8%, transparent);
}

.lv-draft {
  margin: 0 0 12px;
  font-size: var(--fs-base);
  font-weight: 650;
  color: var(--warn-text);
}

.lv-grid {
  display: grid;
  grid-template-columns: 320px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.lv.on-overview .lv-grid {
  grid-template-columns: minmax(0, 1fr);
}

/* Outline */
.lv-outline {
  position: sticky;
  top: calc(var(--header-h) + 14px);
  max-height: calc(100dvh - var(--header-h) - 28px);
  overflow-y: auto;
  padding: 16px;
}

.lv-cover {
  aspect-ratio: 16 / 9;
  border-radius: var(--radius-md);
  overflow: hidden;
}

.lv-cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.lv-ohead {
  padding: 16px 4px 14px;
}

.lv-ohead .lv-otitle {
  margin: 0 0 12px;
  font-size: var(--fs-4xl);
  line-height: 1.25;
}

.lv-prog {
  display: flex;
  justify-content: space-between;
  margin-bottom: 8px;
  font-size: var(--fs-base);
  font-weight: 650;
  color: var(--text-2);
}

.lv-track {
  height: 8px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  overflow: hidden;
}

.lv-track i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--text);
  transition: width 0.4s ease;
}

.lv-modules {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.lv-module {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 4px 6px 6px;
}

.lv-mhead {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 56px;
  padding: 8px 10px;
  text-align: left;
}

.lv-mhead strong {
  display: block;
  font-size: var(--fs-xl);
}

.lv-mhead small {
  display: block;
  margin-top: 2px;
  font-size: var(--fs-md);
  color: var(--text-2);
}

.lv-mhead :deep(svg) {
  transition: transform 0.2s ease;
  color: var(--text-2);
}

.lv-mhead .flip {
  transform: rotate(180deg);
}

.lv-module ul {
  margin: 0;
  padding: 0;
  list-style: none;
}

.lv-module li button {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 48px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  text-align: left;
  font-size: var(--fs-lg);
  font-weight: 600;
  color: var(--text);
}

.lv-module li:not(.active) button:hover {
  background: var(--surface-2);
}

.lv-module li.active button {
  background: var(--accent-soft);
}

.lv-none {
  padding: 8px 10px;
  color: var(--text-3);
  font-size: var(--fs-base);
}

.lv-mark {
  flex: none;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid var(--border-strong);
  color: var(--text-3);
}

.lv-mark.done {
  border-color: var(--ok);
  background: var(--ok);
  color: var(--on-color);
}

.lv-mark.locked {
  border-color: transparent;
  background: var(--surface-2);
  color: var(--text-2); /* also when the lesson was done before the course locked: a grey lock, never white on grey */
}

/* The bar */
.lv-bar {
  position: sticky;
  top: var(--header-h);
  z-index: 8;
  display: flex;
  align-items: center;
  gap: 10px;
  margin: -14px -2px 12px;
  padding: 14px 2px 10px;
  background: var(--bg);
}

.lv-tabs {
  flex: 1;
  display: flex;
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
}

.lv-tabs button {
  flex: 1;
  height: 44px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-xl);
  font-weight: 650;
  color: var(--text-2);
}

.lv-tabs button:not(.on):not(:disabled):hover {
  background: var(--surface-2);
  color: var(--text);
}

.lv-tabs button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.lv-tabs button:disabled {
  opacity: 0.45;
}

.lv-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.lv-round {
  flex: none;
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
}

.lv-round:disabled {
  opacity: 0.4;
  cursor: default;
}

.lv-round:not(:disabled):hover {
  background: var(--surface-2);
}

.lv-starts:disabled {
  background: var(--surface-2);
  color: var(--text);
  opacity: 1;
  cursor: default;
}

.lv-main-btn {
  white-space: nowrap;
}

.lv-ring {
  width: 18px;
  height: 18px;
  border: 2px solid currentColor;
  border-radius: 50%;
}

.lv-done {
  background: var(--ok-strong); /* solid green, white text: this lesson is done */
  color: var(--on-color);
  white-space: nowrap;
}

.lv-done:hover {
  background: color-mix(in srgb, var(--ok-strong) 86%, var(--scrim));
}

.lv-contents,
.lv-dock {
  display: none;
}

/* The quiz at the end of a lesson */
.lv-quiz {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 14px;
  margin-top: 22px;
  padding: 18px 20px 20px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}
.lv-quiz-icon {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-sm);
  background: var(--accent-soft);
  color: var(--accent-text);
}
.lv-quiz-icon.ok {
  background: var(--accent);
  color: var(--accent-ink);
}
.lv-quiz-icon.warn {
  background: var(--warn-soft);
  color: var(--warn-text);
}
.lv-quiz-text strong {
  display: block;
  font-size: var(--fs-lg);
}
.lv-quiz-text small {
  display: block;
  margin-top: 2px;
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.45;
}
.lv-quiz-btns {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 12px;
}
.lv-quiz-wait {
  width: 100%;
  max-width: 680px;
  margin: 0 auto;
  padding: 60px 16px;
}
.lv-wait-btns {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
  margin-top: 14px;
}
.lv-lwrap {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.lv-lsub {
  display: flex;
  align-items: center;
  gap: 4px;
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 500;
}

/* The lesson */
.lv-lesson {
  overflow: hidden;
}

.lv-player {
  position: relative;
  background: var(--player-bg);
}

.lv-player :deep(.credit) {
  margin: 0;
  padding: 8px 16px;
  background: var(--surface);
  color: var(--text-2);
  font-size: var(--fs-md);
}

.lv-body {
  padding: 22px 24px 26px;
}

.lv-title {
  margin: 0 0 14px;
  font-size: var(--fs-7xl);
  line-height: 1.2;
}

.lv-md {
  font-size: var(--fs-2xl);
  line-height: 1.6;
}

.lv-files {
  margin-top: 24px;
  padding-top: 22px;
  border-top: 1px solid var(--border);
}

.lv-files h3 {
  margin: 0 0 14px;
  font-size: var(--fs-3xl);
}

.lv-files h3 span {
  color: var(--text-3);
  font-weight: 500;
}

.lv-file {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
}

.lv-fbtns {
  display: flex;
  flex: none;
  gap: 8px;
}

.lv-file + .lv-file {
  margin-top: 10px;
}

.lv-fname {
  flex: 1;
  min-width: 0;
}

.lv-fname strong {
  display: block;
  font-size: var(--fs-xl);
}

.lv-fname small {
  display: block;
  margin-top: 2px;
  font-size: var(--fs-base);
  color: var(--text-2);
}

@media (max-width: 899px) {
  .lv {
    padding: 0 0 20px;
  }

  .lv-back,
  .lv-draft {
    display: none;
  }

  .lv-top {
    justify-content: flex-end;
    margin: 0;
    padding: 6px 12px 0;
  }

  .lv-grid,
  .lv.on-lesson .lv-grid {
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
  }

  .lv-outline {
    display: none;
    position: static;
    max-height: none;
    margin: 0 12px 10px;
    order: 2;
  }

  .lv-outline.open {
    display: block;
  }

  .lv-main {
    display: contents;
  }

  .lv-bar {
    flex-wrap: wrap;
    margin: 0 0 8px;
    padding: 10px 12px;
    order: 1;
  }

  .lv-contents {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    height: 52px;
    padding: 0 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    font-size: var(--fs-lg);
    font-weight: 650;
  }

  .lv-actions {
    display: none;
  }

  .lv-tabs button {
    font-size: var(--fs-lg);
  }

  .lv-main > :not(.lv-bar) {
    order: 3;
  }

  .lesson-talk {
    margin-top: 24px;
  }

  .lv-lesson,
  .lesson-talk,
  .lv .card.locked {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  .lv-body {
    padding: 18px 16px 22px;
  }

  .lv-title {
    font-size: var(--fs-5xl);
  }

  .lv-file {
    flex-wrap: wrap;
  }

  /* Preview and Download side by side across the card; Download alone fills it. */
  .lv-fbtns {
    flex: 1 1 100%;
  }

  .lv-fbtns .btn {
    flex: 1 1 0;
    justify-content: center;
  }

  .lv-dock {
    position: fixed;
    inset: auto 0 0;
    z-index: 30;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px calc(12px + env(safe-area-inset-bottom));
    background: var(--surface);
    border-top: 1px solid var(--border);
    box-shadow: 0 -6px 20px color-mix(in srgb, var(--shadow-color) 8%, transparent);
  }

  .lv-dock .btn {
    flex: 1;
  }

  .lv-dock .lv-grow {
    flex: 1;
  }
}
</style>
