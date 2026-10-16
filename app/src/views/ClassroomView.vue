<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import { accessTag, lockHint, lockLabel } from '../lib/access.js'
import { t } from '../lib/i18n.js'

const router = useRouter()
const courses = ref([])
const canManage = ref(false)
const loading = ref(true)
const error = ref('')
const creating = ref(false)

// A course without a cover gets a gradient from its ID, so the grid never looks empty.
const fallback = (id) => `linear-gradient(135deg, hsl(${(id * 67) % 360} 65% 55%), hsl(${(id * 67 + 50) % 360} 60% 38%))`

onMounted(async () => {
  try {
    const res = await api.courses()
    courses.value = res.items
    canManage.value = res.can_manage
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
})

async function newCourse() {
  if (creating.value) return
  creating.value = true
  try {
    const course = await api.createCourse({ title: t.__('Untitled course', 'membuild-community') })
    router.push({ name: 'course', params: { courseId: course.id }, query: { edit: 1 } })
  } finally {
    creating.value = false
  }
}
/**
 * Each card says where the member stands, in words: Start, Continue (and
 * which lesson), Completed, or why it's locked.
 */
const stateOf = (c) => (c.lock ? 'locked' : c.lessons_count && c.progress >= 100 ? 'done' : c.completed_count ? 'going' : 'new')
const counts = computed(() => ({
  going: courses.value.filter((c) => stateOf(c) === 'going').length,
  done: courses.value.filter((c) => stateOf(c) === 'done').length,
}))

// "Continue where you left off": the course they were in most recently, still unfinished.
const resume = computed(() =>
  courses.value
    .filter((c) => stateOf(c) === 'going' && c.next_lesson)
    .sort((a, b) => (b.last_activity || '').localeCompare(a.last_activity || ''))[0] || null,
)

const filter = ref('all')
const FILTERS = computed(() => [
  { key: 'all', label: t.__('All', 'membuild-community') },
  { key: 'going', label: t.__('In progress', 'membuild-community'), n: counts.value.going },
  { key: 'done', label: t.__('Completed', 'membuild-community'), n: counts.value.done },
])
const shown = computed(() => (filter.value === 'all' ? courses.value : courses.value.filter((c) => stateOf(c) === filter.value)))

// Part-way through: straight to the next lesson. New, finished or locked: the course overview.
const linkTo = (c) =>
  c.next_lesson && stateOf(c) === 'going' ? { name: 'course', params: { courseId: c.id, lessonId: c.next_lesson.id } } : { name: 'course', params: { courseId: c.id } }
const lessonOf = (c) => t.sprintf(/* translators: 1: lesson number, 2: number of lessons */ t.__('Lesson %1$d of %2$d', 'membuild-community'), c.next_lesson.number, c.lessons_count)
const lessonsLabel = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)
// "Frank and 12 others are taking this": the others don't count the member themselves.
const taking = (c) => {
  if (!c.faces?.length) return ''
  const others = c.learners - 1 - (c.completed_count ? 1 : 0)
  const first = c.faces[0].name.split(' ')[0]
  return others > 0
    ? t.sprintf(/* translators: 1: a member's first name, 2: how many other members */ t._n('%1$s and %2$d other are taking this', '%1$s and %2$d others are taking this', others, 'membuild-community'), first, others)
    : t.sprintf(/* translators: %s: a member's first name */ t.__('%s is taking this', 'membuild-community'), first)
}
const talk = (c) =>
  c.posts_week
    ? t.sprintf(/* translators: 1: number of posts, 2: posts this week */ t._n('%1$d post · %2$d this week', '%1$d posts · %2$d this week', c.posts_count, 'membuild-community'), c.posts_count, c.posts_week)
    : t.sprintf(/* translators: %d: number of posts */ t._n('%d post', '%d posts', c.posts_count, 'membuild-community'), c.posts_count)
const percentDone = (pct) => t.sprintf(/* translators: %d: percent of the course completed */ t.__('%d%% complete', 'membuild-community'), pct)
</script>

<template>
  <div class="classroom">
    <header class="head">
      <h1>{{ t.__('Classroom', 'membuild-community') }}</h1>
      <div v-if="counts.going || counts.done" class="filters" role="radiogroup" :aria-label="t.__('Show courses', 'membuild-community')">
        <button v-for="f in FILTERS" :key="f.key" role="radio" :aria-checked="filter === f.key" :class="{ on: filter === f.key }" @click="filter = f.key">
          {{ f.label }}<span v-if="f.n" class="n">{{ f.n }}</span>
        </button>
      </div>
    </header>

    <RouterLink v-if="resume && filter === 'all'" class="card resume" :to="linkTo(resume)">
      <div class="resume-cover" :style="resume.cover ? null : { background: fallback(resume.id) }">
        <img v-if="resume.cover" :src="resume.cover" alt="" />
        <span class="play"><Icon name="play" :size="22" /></span>
      </div>
      <div class="resume-body">
        <small>{{ t.__('Continue where you left off', 'membuild-community') }}</small>
        <strong>{{ resume.title }}</strong>
        <span class="next">{{ lessonOf(resume) }} · {{ resume.next_lesson.title }}</span>
        <div class="progress">
          <div class="track"><i :style="{ width: `${resume.progress}%` }" /></div>
          <span>{{ resume.progress }}%</span>
        </div>
      </div>
      <span class="btn btn-primary resume-btn">{{ t.__('Continue', 'membuild-community') }}</span>
    </RouterLink>

    <div class="grid">
      <template v-if="loading">
        <div v-for="n in 3" :key="n" class="card course">
          <div class="skeleton cover-sk" />
          <div class="body">
            <div class="skeleton" style="height: 16px; width: 60%" />
            <div class="skeleton" style="height: 12px; width: 90%; margin-top: 10px" />
          </div>
        </div>
      </template>

      <RouterLink
        v-for="c in shown"
        :key="c.id"
        class="card course"
        :class="{ locked: c.lock }"
        :to="linkTo(c)"
      >
        <div class="cover" :style="c.cover ? null : { background: fallback(c.id) }">
          <img v-if="c.cover" :src="c.cover" alt="" loading="lazy" decoding="async" />
          <span v-else class="cover-title">{{ c.title }}</span>
          <span v-if="c.status === 'draft'" class="draft">{{ t._x('Draft', 'course status', 'membuild-community') }}</span>
          <span v-if="c.lock" class="lock">
            <Icon name="lock" :size="13" />
            {{ lockLabel(c.lock) }}
          </span>
          <span v-else-if="stateOf(c) === 'done'" class="done-badge"><Icon name="check" :size="13" /> {{ t.__('Completed', 'membuild-community') }}</span>
          <span v-else-if="canManage && accessTag(c)" class="draft level-tag"><Icon name="lock" :size="12" /> {{ accessTag(c) }}<template v-if="c.hide_locked"> · {{ t.__('hidden', 'membuild-community') }}</template></span>
        </div>
        <div class="body">
          <h2>{{ c.title }}</h2>
          <p class="desc">{{ c.description }}</p>
          <div v-if="c.faces?.length || c.posts_count" class="alive">
            <span v-if="c.faces?.length" class="faces">
              <Avatar v-for="u in c.faces" :key="u.id" :user="u" :size="22" />
            </span>
            <span class="alive-text">
              <span v-if="taking(c)">{{ taking(c) }}</span>
              <span v-if="c.posts_count"><Icon name="comment" :size="13" /> {{ talk(c) }}</span>
            </span>
          </div>

          <p v-if="c.lock" class="hint">{{ lockHint(c.lock) || lessonsLabel(c.lessons_count) }}</p>
          <div v-else-if="stateOf(c) !== 'new'" class="progress" :aria-label="percentDone(c.progress)">
            <div class="track"><i :style="{ width: `${c.progress}%` }" /></div>
            <span>{{ c.progress }}%</span>
          </div>

          <div class="foot">
            <span v-if="c.lock" class="state locked-state"><Icon name="lock" :size="14" /> {{ c.lock.cta ? c.lock.cta.label : t.__('Locked', 'membuild-community') }}</span>
            <span v-else-if="stateOf(c) === 'done'" class="state done-state"><Icon name="check" :size="14" /> {{ t.__('Completed · review', 'membuild-community') }}</span>
            <span v-else-if="stateOf(c) === 'going' && c.next_lesson" class="state go">{{ t.__('Continue', 'membuild-community') }} · {{ lessonOf(c) }}</span>
            <span v-else-if="c.lessons_count" class="state go">{{ t.__('Start course', 'membuild-community') }} · {{ lessonsLabel(c.lessons_count) }}</span>
            <span v-else class="state">{{ t.__('Coming soon', 'membuild-community') }}</span>
          </div>
        </div>
      </RouterLink>

      <button v-if="canManage" class="card course new" :disabled="creating" @click="newCourse">
        <span class="plus"><Icon name="plus" :size="28" /></span>
        <strong>{{ creating ? t.__('Creating…', 'membuild-community') : t.__('New course', 'membuild-community') }}</strong>
        <span class="hint">{{ t.__('Add modules, lessons, videos and files', 'membuild-community') }}</span>
      </button>
    </div>

    <p v-if="!loading && filter !== 'all' && !shown.length" class="status">{{ t.__('No courses here yet.', 'membuild-community') }}</p>
    <p v-if="error" class="status">{{ error }}</p>
    <p v-else-if="!loading && !courses.length && !canManage" class="status">{{ t.__('No courses yet. Check back soon.', 'membuild-community') }}</p>
  </div>
</template>

<style scoped>
.classroom {
  max-width: 1080px;
  margin: 0 auto;
  padding: 20px 16px;
}

.head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
}

.head h1 {
  margin: 0;
  font-size: 24px;
}

.filters {
  display: flex;
  gap: 6px;
  margin-left: auto;
}

.filters button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
  font-weight: 650;
  font-size: 14px;
}

.filters button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.filters .n {
  min-width: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--surface);
  font-size: 12px;
  text-align: center;
}

/* Continue where you left off */
.resume {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 18px;
  padding: 12px;
  color: var(--text);
  transition: box-shadow 0.15s ease;
}

.resume:hover {
  box-shadow: 0 10px 28px rgb(0 0 0 / 18%);
}

.resume-cover {
  position: relative;
  flex: none;
  width: 180px;
  aspect-ratio: 16 / 9;
  border-radius: 10px;
  overflow: hidden;
}

.resume-cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.play {
  position: absolute;
  inset: 0;
  margin: auto;
  display: grid;
  place-items: center;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: rgb(0 0 0 / 55%);
  color: #fff;
}

.resume-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.resume-body small {
  color: var(--accent-text);
  font-size: 12px;
  font-weight: 750;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.resume-body strong {
  font-size: 18px;
}

.next {
  color: var(--text-2);
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.resume-body .progress {
  margin-top: 6px;
  max-width: 360px;
}

.resume-btn {
  flex: none;
  margin-right: 6px;
}

@media (max-width: 640px) {
  .resume {
    flex-wrap: wrap;
  }

  .resume-cover {
    width: 120px;
  }

  .resume-btn {
    width: 100%;
    justify-content: center;
    margin: 0;
  }

  .filters {
    margin-left: 0;
  }
}

.done-badge {
  position: absolute;
  top: 10px;
  right: 10px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px 4px 8px;
  border-radius: 999px;
  background: #2fb36b;
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
}

/* Signs of life: who's taking it, and how lively its discussion is. */
.alive {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: -4px 0 12px;
}

.faces {
  display: flex;
  flex: none;
}

.faces > * + * {
  margin-left: -7px;
}

.faces :deep(img),
.faces :deep(.avatar) {
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.alive-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  font-size: 12.5px;
  color: var(--text-2);
  line-height: 1.35;
}

.alive-text span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.hint {
  margin: 0 0 4px;
  font-size: 13px;
  color: var(--text-2);
}

.foot {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.state {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 14px;
  color: var(--text-2);
}

.state.go {
  color: var(--accent-text);
}

.done-state {
  color: #2fb36b;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.course {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  color: var(--text);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.course:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px rgb(0 0 0 / 18%);
}

.cover,
.cover-sk {
  position: relative;
  aspect-ratio: 16 / 9;
  overflow: hidden;
  display: grid;
  place-items: center;
}

.cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover-title {
  padding: 16px;
  color: #fff;
  font-size: 22px;
  font-weight: 800;
  text-align: center;
  text-shadow: 0 1px 3px rgb(0 0 0 / 25%);
}

.draft {
  position: absolute;
  top: 10px;
  left: 10px;
  padding: 3px 10px;
  border-radius: 999px;
  background: rgb(0 0 0 / 65%);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
}

.course.locked .cover img,
.course.locked .cover-title {
  filter: grayscale(0.6) brightness(0.55);
}

/* A badge in the corner, not over the middle: covers often carry their own title. */
.lock {
  position: absolute;
  top: 10px;
  right: 10px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  max-width: calc(100% - 20px);
  padding: 4px 10px 4px 8px;
  border-radius: 999px;
  background: rgb(0 0 0 / 70%);
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  backdrop-filter: blur(6px);
}

.level-tag {
  left: auto;
  right: 10px;
}

.body {
  display: flex;
  flex-direction: column;
  flex: 1;
  padding: 14px 16px 16px;
}

.body h2 {
  margin: 0;
  font-size: 17px;
}

.desc {
  flex: 1;
  margin: 6px 0 14px;
  color: var(--text-2);
  font-size: 14px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.progress {
  display: flex;
  align-items: center;
  gap: 10px;
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
  border-radius: inherit;
  background: #2fb36b;
  transition: width 0.4s ease;
}

.new {
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 260px;
  border-style: dashed;
  background: transparent;
  box-shadow: none;
  color: var(--text-2);
}

.new .plus {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.new strong {
  color: var(--text);
  font-size: 16px;
}

.hint {
  font-size: 13px;
}

.status {
  color: var(--text-3);
  text-align: center;
  margin: 24px 0;
}
</style>
