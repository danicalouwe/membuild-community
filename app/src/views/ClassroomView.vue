<script setup>
import { cfg } from '../config.js'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import Avatar from '../components/Avatar.vue'
import BaseDialog from '../components/BaseDialog.vue'
import Icon from '../components/Icon.vue'
import { accessTag, lockLabel } from '../lib/access.js'
import { t } from '../lib/i18n.js'
import { offerButton } from '../lib/offer.js'
import { asMember } from '../lib/preview.js'

const router = useRouter()
const courses = ref([])
const canManage = ref(false)
const loading = ref(true)
const error = ref('')

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

// "See it as a member": owners check the Classroom without their tools and drafts.
const owner = computed(() => canManage.value && !asMember.value)
const visible = computed(() => (owner.value ? courses.value : courses.value.filter((c) => c.status !== 'draft' && !(c.lock && c.hide_locked))))

/* ---------- New course: name it first ---------- */
const naming = ref(false)
const newTitle = ref('')
const creating = ref(false)
const createError = ref('')
const nameInput = ref(null)
function startNew() {
  newTitle.value = ''
  createError.value = ''
  naming.value = true
  nextTick(() => nameInput.value?.focus())
}
async function createCourse() {
  const title = newTitle.value.trim()
  if (!title || creating.value) return
  creating.value = true
  createError.value = ''
  try {
    const course = await api.createCourse({ title })
    router.push({ name: 'course', params: { courseId: course.id }, query: { edit: 1 } })
  } catch (err) {
    createError.value = err.message
    creating.value = false
  }
}

/**
 * Each card says where the member stands, in words: Start, Continue,
 * Completed, or what unlocks it.
 */
const stateOf = (c) => (c.lock ? 'locked' : c.lessons_count && c.progress >= 100 ? 'done' : c.completed_count ? 'going' : 'new')
const counts = computed(() => ({
  going: visible.value.filter((c) => stateOf(c) === 'going').length,
  done: visible.value.filter((c) => stateOf(c) === 'done').length,
}))

// "Continue where you left off": the course they were in most recently, still unfinished.
const resume = computed(() =>
  visible.value
    .filter((c) => stateOf(c) === 'going' && c.next_lesson)
    .sort((a, b) => (b.last_activity || '').localeCompare(a.last_activity || ''))[0] || null,
)

const filter = ref('all')
const FILTERS = computed(() => [
  { key: 'all', label: t.__('All', 'membuild-community') },
  { key: 'going', label: t.__('In progress', 'membuild-community'), n: counts.value.going },
  { key: 'done', label: t.__('Completed', 'membuild-community'), n: counts.value.done },
])
const filtered = computed(() => (filter.value === 'all' ? visible.value : visible.value.filter((c) => stateOf(c) === filter.value)))

// Twelve at first (four rows of three), then Show more.
const PAGE = 12
const showAll = ref(false)
const shown = computed(() => (showAll.value ? filtered.value : filtered.value.slice(0, PAGE)))

// Part-way through: straight to the next lesson. New, finished or locked: the course overview.
const linkTo = (c) =>
  c.next_lesson && stateOf(c) === 'going' ? { name: 'course', params: { courseId: c.id, lessonId: c.next_lesson.id } } : { name: 'course', params: { courseId: c.id } }
const editCourse = (c) => router.push({ name: 'course', params: { courseId: c.id }, query: { edit: 1 } })
const lessonOf = (c) => t.sprintf(/* translators: 1: lesson number, 2: number of lessons */ t.__('Lesson %1$d of %2$d', 'membuild-community'), c.next_lesson.number, c.lessons_count)
const lessonsLabel = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)
const doneOf = (c) => t.sprintf(/* translators: 1: lessons finished, 2: lessons in the course */ t.__('%1$d of %2$d lessons', 'membuild-community'), c.completed_count, c.lessons_count)
const membersLabel = (n) => t.sprintf(/* translators: %d: members taking the course */ t._n('%d member', '%d members', n, 'membuild-community'), n)
const postsLabel = (n) => t.sprintf(/* translators: %d: number of posts */ t._n('%d post', '%d posts', n, 'membuild-community'), n)
const percentDone = (pct) => t.sprintf(/* translators: %d: percent of the course completed */ t.__('%d%% complete', 'membuild-community'), pct)

// The short pill on a locked cover, and what the card's foot says unlocks it.
// Settings › Features › Classroom: members and posts on course cards.
const cardActivity = cfg.community.courses?.activity !== false
const lockPill = (lock) => (lock.reason === 'level' ? t.sprintf(/* translators: %d: level number */ t.__('Level %d+', 'membuild-community'), lock.level) : lock.membership?.name || lock.groups.join(', ') || t.__('Members only', 'membuild-community'))
const lockFoot = (lock) => (lock.reason === 'level' ? t.sprintf(/* translators: %d: level number */ t.__('Reach Level %d', 'membuild-community'), lock.level) : lock.membership?.offer ? offerButton(lock.membership.offer) : lockLabel(lock))
</script>
<template>
  <div class="classroom">
    <div v-if="canManage && asMember" class="preview-bar">
      <Icon name="eye" :size="20" />
      <span class="pv-long">{{ t.__('You’re seeing the Classroom the way members see it. Drafts are hidden.', 'membuild-community') }}</span>
      <span class="pv-short">{{ t.__('Member view', 'membuild-community') }}</span>
      <button class="btn btn-lg btn-ghost" @click="asMember = false">{{ t.__('Back to your view', 'membuild-community') }}</button>
    </div>

    <header class="head">
      <h1>{{ t.__('Classroom', 'membuild-community') }}</h1>
      <div v-if="owner" class="owner-actions">
        <button class="btn btn-lg btn-primary" @click="startNew"><Icon name="plus" :size="20" /> {{ t.__('New course', 'membuild-community') }}</button>
      </div>
      <div v-else-if="counts.going || counts.done" class="filters" role="radiogroup" :aria-label="t.__('Show courses', 'membuild-community')">
        <button v-for="f in FILTERS" :key="f.key" role="radio" :aria-checked="filter === f.key" :class="{ on: filter === f.key }" @click="filter = f.key">
          {{ f.label }}<span v-if="f.n" class="n">{{ f.n }}</span>
        </button>
      </div>
    </header>

    <template v-if="resume && filter === 'all' && !owner">
      <h2 class="section">{{ t.__('Continue where you left off', 'membuild-community') }}</h2>
      <RouterLink class="card resume" :to="linkTo(resume)">
        <div class="resume-cover" :style="resume.cover ? null : { background: fallback(resume.id) }">
          <img v-if="resume.cover" :src="resume.cover" alt="" />
          <span class="play"><Icon name="play" :size="22" /></span>
        </div>
        <div class="resume-body">
          <strong>{{ resume.title }}</strong>
          <span class="next">{{ lessonOf(resume) }} · {{ resume.next_lesson.title }}</span>
          <div class="progress" :aria-label="percentDone(resume.progress)">
            <div class="track"><i :style="{ width: `${resume.progress}%` }" /></div>
            <span>{{ resume.progress }}%</span>
          </div>
        </div>
        <span class="btn btn-lg btn-primary resume-btn">{{ t.__('Continue', 'membuild-community') }}</span>
      </RouterLink>
      <h2 class="section">{{ t.__('All courses', 'membuild-community') }}</h2>
    </template>

    <div v-if="loading || shown.length || owner" class="grid">
      <template v-if="loading">
        <div v-for="n in 3" :key="n" class="card course">
          <div class="skeleton cover-sk" />
          <div class="body">
            <div class="skeleton" style="height: 16px; width: 60%" />
            <div class="skeleton" style="height: 12px; width: 90%; margin-top: 10px" />
          </div>
        </div>
      </template>

      <button v-else-if="owner && courses.length" class="card course new" @click="startNew">
        <span class="new-top"><span class="plus"><Icon name="plus" :size="28" /></span></span>
        <span class="body">
          <strong>{{ t.__('New course', 'membuild-community') }}</strong>
          <span class="desc">{{ t.__('Give it a name, add a few lessons, and publish when you’re ready.', 'membuild-community') }}</span>
          <span class="foot">
            <span class="foot-left">{{ lessonsLabel(0) }}</span>
            <span class="state go">{{ t.__('Create course', 'membuild-community') }} <Icon name="arrowRight" :size="16" /></span>
          </span>
        </span>
      </button>

      <RouterLink v-for="c in shown" :key="c.id" class="card course" :class="{ locked: c.lock }" :to="linkTo(c)">
        <div class="cover" :style="c.cover ? null : { background: fallback(c.id) }">
          <img v-if="c.cover" :src="c.cover" alt="" loading="lazy" decoding="async" />
          <span v-else class="cover-title">{{ c.title }}</span>
          <span v-if="c.status === 'draft'" class="draft">{{ t._x('Draft', 'course status', 'membuild-community') }}</span>
          <span v-if="c.lock" class="pill"><Icon name="lock" :size="13" /> {{ lockPill(c.lock) }}</span>
          <span v-else-if="stateOf(c) === 'done'" class="done-badge" :title="t.__('Completed', 'membuild-community')"><Icon name="check" :size="18" /></span>
          <span v-else-if="owner && accessTag(c)" class="pill"><Icon name="lock" :size="13" /> {{ accessTag(c) }}<template v-if="c.hide_locked"> · {{ t.__('hidden', 'membuild-community') }}</template></span>
        </div>
        <div class="body">
          <h2>{{ c.title }}</h2>
          <p class="desc">{{ c.description }}</p>
          <div v-if="cardActivity && (c.faces?.length || c.posts_count)" class="alive">
            <span v-if="c.faces?.length" class="people">
              <span class="faces"><Avatar v-for="u in c.faces" :key="u.id" :user="u" :size="24" /></span>
              {{ membersLabel(c.learners) }}
            </span>
            <span v-if="c.posts_count" class="posts"><Icon name="comment" :size="15" /> {{ postsLabel(c.posts_count) }}</span>
          </div>

          <div v-if="!owner && !c.lock && stateOf(c) !== 'new'" class="progress" :aria-label="percentDone(c.progress)">
            <div class="track"><i :style="{ width: `${c.progress}%` }" /></div>
            <span>{{ c.progress }}%</span>
          </div>

          <div class="foot">
            <template v-if="owner">
              <span v-if="c.status === 'draft'" class="status-line"><i class="dot draft-dot" /> {{ t.__('Draft: only you can see it', 'membuild-community') }}</span>
              <span v-else class="status-line"><i class="dot" /> {{ t.__('Published', 'membuild-community') }}</span>
              <span class="btn btn-lg btn-ghost edit" role="button" tabindex="0" @click.prevent.stop="editCourse(c)" @keydown.enter.prevent.stop="editCourse(c)"><Icon name="pencil" :size="18" /> {{ t.__('Edit', 'membuild-community') }}</span>
            </template>
            <template v-else>
              <span class="foot-left">{{ !c.lock && stateOf(c) !== 'new' ? doneOf(c) : c.lessons_count ? lessonsLabel(c.lessons_count) : '' }}</span>
              <span v-if="c.lock" class="state locked-state"><Icon name="lock" :size="15" /> {{ lockFoot(c.lock) }}</span>
              <span v-else-if="stateOf(c) === 'done'" class="state done-state"><Icon name="check" :size="16" /> {{ t.__('Completed', 'membuild-community') }}</span>
              <span v-else-if="stateOf(c) === 'going'" class="state go">{{ t.__('Continue', 'membuild-community') }} <Icon name="arrowRight" :size="16" /></span>
              <span v-else-if="c.lessons_count" class="state go">{{ t.__('Start course', 'membuild-community') }} <Icon name="arrowRight" :size="16" /></span>
              <span v-else class="state">{{ t.__('Coming soon', 'membuild-community') }}</span>
            </template>
          </div>
        </div>
      </RouterLink>
    </div>

    <div v-if="!showAll && filtered.length > PAGE" class="more">
      <button class="btn btn-lg btn-ghost" @click="showAll = true">{{ t.__('Show more', 'membuild-community') }}</button>
    </div>

    <p v-if="error" class="status">{{ error }}</p>
    <p v-else-if="!loading && filter !== 'all' && !filtered.length" class="status">{{ t.__('No courses here yet.', 'membuild-community') }}</p>

    <div v-else-if="!loading && !courses.length && canManage" class="empty card">
      <span class="empty-icon accent"><Icon name="cap" :size="30" /></span>
      <h2>{{ t.__('Create your first course', 'membuild-community') }}</h2>
      <p>{{ t.__('Give it a name, add a few lessons, and publish when you’re ready. Until then, only you can see it.', 'membuild-community') }}</p>
      <button class="btn btn-lg btn-primary" @click="startNew"><Icon name="plus" :size="20" /> {{ t.__('New course', 'membuild-community') }}</button>
    </div>
    <div v-else-if="!loading && !visible.length" class="empty card">
      <span class="empty-icon"><Icon name="cap" :size="30" /></span>
      <h2>{{ t.__('No courses yet', 'membuild-community') }}</h2>
      <p>{{ t.__('When a course is added, it will show up here. We’ll let you know.', 'membuild-community') }}</p>
      <RouterLink class="btn btn-lg btn-ghost" :to="{ name: 'feed' }">{{ t.__('Back to Home', 'membuild-community') }}</RouterLink>
    </div>

    <BaseDialog v-if="naming" :title="t.__('New course', 'membuild-community')" :width="480" @close="naming = false">
      <form class="name-form" @submit.prevent="createCourse">
        <label for="mbcom-new-course">{{ t.__('What’s the course called?', 'membuild-community') }}</label>
        <input id="mbcom-new-course" ref="nameInput" v-model="newTitle" type="text" maxlength="120" :placeholder="t.__('For example: Garden Basics', 'membuild-community')" />
        <p class="hint">{{ t.__('You can change it later. Only you can see the course until you publish it.', 'membuild-community') }}</p>
        <p v-if="createError" class="error">{{ createError }}</p>
        <div class="name-actions">
          <button type="button" class="btn btn-lg btn-ghost" @click="naming = false">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button type="submit" class="btn btn-lg btn-primary" :disabled="!newTitle.trim() || creating">{{ creating ? t.__('Creating…', 'membuild-community') : t.__('Create course', 'membuild-community') }}</button>
        </div>
      </form>
    </BaseDialog>
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
    margin: 8px 0 14px;
    padding: 6px 6px 6px 14px;
    font-size: 15px;
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
    font-size: 15px;
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
  font-size: 28px;
}

.owner-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-left: auto;
}

.section {
  margin: 4px 0 12px;
  font-size: 20px;
}

.preview-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
  padding: 10px 10px 10px 18px;
  border-radius: 16px;
  background: var(--accent-soft);
  color: var(--text);
  font-size: 16px;
  font-weight: 600;
}

.preview-bar span {
  flex: 1;
}

.preview-bar .btn {
  background: var(--surface);
}

.filters {
  display: flex;
  gap: 6px;
  margin-left: auto;
}

.filters button {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 52px;
  padding: 0 20px;
  border-radius: 999px;
  background: var(--fill-on-bg, var(--surface-2));
  color: var(--text-2);
  font-weight: 700;
  font-size: 17px;
}

.filters button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.filters .n {
  min-width: 24px;
  padding: 0 7px;
  border-radius: 999px;
  background: var(--surface);
  font-size: 14px;
  text-align: center;
}

/* Continue where you left off */
.resume {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 28px;
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

.resume-body strong {
  font-size: 19px;
}

.next {
  color: var(--text-2);
  font-size: 15px;
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

  .filters,
  .owner-actions {
    margin-left: 0;
  }

  .owner-actions {
    width: 100%;
    flex-direction: column-reverse;
  }

  .owner-actions .btn {
    width: 100%;
  }

  /* The New course button is right above; the card would say it twice. */
  .grid .new {
    display: none;
  }

}

@media (max-width: 400px) {
  .filters button {
    padding: 0 14px;
    font-size: 16px;
  }
}

.done-badge {
  position: absolute;
  top: 10px;
  right: 10px;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #2fb36b;
  color: #fff;
  box-shadow: 0 2px 6px rgb(0 0 0 / 20%);
}

/* Signs of life: how many are taking it, and how lively its discussion is. */
.alive {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin: -4px 0 14px;
  font-size: 15px;
  color: var(--text-2);
}

.people,
.posts {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.faces {
  display: flex;
  flex: none;
}

.faces > * + * {
  margin-left: -8px;
}

.faces :deep(img),
.faces :deep(.avatar) {
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 40px;
  margin-top: auto;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.foot-left {
  font-size: 15px;
  color: var(--text-2);
}

.state {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  font-size: 16px;
  color: var(--text-2);
}

.locked-state {
  color: var(--text);
}

.status-line {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 650;
}

.dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: #2fb36b;
}

.draft-dot {
  background: var(--text-3);
}

.foot .edit {
  height: 48px;
  padding: 0 20px;
  font-size: 16px;
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

/* A pill in the corner, not over the middle: covers often carry their own title. */
.pill {
  position: absolute;
  top: 10px;
  right: 10px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  max-width: calc(100% - 20px);
  padding: 5px 11px 5px 9px;
  border-radius: 999px;
  background: rgb(0 0 0 / 70%);
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  backdrop-filter: blur(6px);
}

.body {
  display: flex;
  flex-direction: column;
  flex: 1;
  padding: 14px 16px 16px;
}

.body h2 {
  margin: 0;
  font-size: 19px;
}

.desc {
  flex: none;
  max-height: calc(2 * 1.45em);
  margin: 6px 0 14px;
  color: var(--text-2);
  font-size: 15.5px;
  line-height: 1.45;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.progress {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
  font-size: 14px;
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
  align-items: stretch;
  padding: 0;
  cursor: pointer;
  border-style: dashed;
  background: transparent;
  box-shadow: none;
  color: var(--text-2);
  text-align: left;
  font: inherit;
}

.new-top {
  display: grid;
  width: 100%;
  place-items: center;
  aspect-ratio: 16 / 9;
  background: var(--surface-2);
}

.new .plus {
  display: grid;
  place-items: center;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
}

.new .body {
  width: 100%;
}

.new .desc {
  display: block;
}

.new strong {
  color: var(--text);
  font-size: 19px;
}

.more {
  display: flex;
  justify-content: center;
  margin-top: 22px;
}

.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 56px 24px;
  border-style: dashed;
  text-align: center;
}

.empty h2 {
  margin: 4px 0 0;
  font-size: 22px;
}

.empty p {
  max-width: 460px;
  margin: 0 0 8px;
  color: var(--text-2);
  font-size: 17px;
  line-height: 1.55;
}

.empty-icon {
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
}

.empty-icon.accent {
  background: var(--accent-soft);
  color: var(--accent-text);
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

.name-form .error {
  margin: 0;
  color: var(--danger-text);
  font-size: 15px;
}

.name-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 10px;
}

.status {
  color: var(--text-3);
  text-align: center;
  margin: 24px 0;
}
</style>
