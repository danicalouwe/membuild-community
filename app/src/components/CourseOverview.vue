<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { lockHint, lockLabel } from '../lib/access.js'
import { timeAgo } from '../lib/time.js'
import { t } from '../lib/i18n.js'

/**
 * A course's first page, like Teachable's or Kajabi's: what it is, what's in
 * it, how long it takes, who teaches it, who's taking it, and one button.
 * Shown before a member starts, and for courses they can't open yet.
 */
const props = defineProps({ course: { type: Object, required: true } })
const emit = defineEmits(['open', 'tab'])

const lessons = computed(() => props.course.modules.flatMap((m) => m.lessons))
const next = computed(() => lessons.value.find((l) => !l.completed && !l.locked) || lessons.value.find((l) => !l.locked) || null)
// The course's length only when every lesson's is known (a video without one would make it look shorter).
const minutes = computed(() => (lessons.value.length && lessons.value.every((l) => l.minutes) ? lessons.value.reduce((n, l) => n + l.minutes, 0) : 0))
const started = computed(() => props.course.completed_count > 0)
const done = computed(() => props.course.lessons_count > 0 && props.course.progress >= 100)
const numberOf = (l) => lessons.value.indexOf(l) + 1

// The newest posts in its discussion: proof that people are taking it.
const recent = ref([])
watch(
  () => props.course.id,
  async (id) => {
    recent.value = []
    if (props.course.lock || !props.course.posts_count) return
    try {
      recent.value = (await api.coursePosts(id, { sort: 'newest' })).items.slice(0, 2)
    } catch {
      // the overview works without them
    }
  },
  { immediate: true },
)

const lessonsLabel = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)
const minutesLabel = (n) =>
  n >= 90
    ? t.sprintf(/* translators: %s: hours, e.g. "1.5" */ t.__('about %s hours', 'membuild-community'), (Math.round(n / 30) / 2).toString())
    : t.sprintf(/* translators: %d: minutes */ t._n('about %d min', 'about %d min', n, 'membuild-community'), n)
const filesLabel = (n) => t.sprintf(/* translators: %d: number of files */ t._n('%d file', '%d files', n, 'membuild-community'), n)
const postsLabel = (n) => t.sprintf(/* translators: %d: number of posts */ t._n('%d post', '%d posts', n, 'membuild-community'), n)
const short = (m) => t.sprintf(/* translators: %d: minutes */ t._n('%d min', '%d min', m, 'membuild-community'), m)
const opensOn = (iso) => t.sprintf(/* translators: %s: a date */ t.__('Opens %s', 'membuild-community'), new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))
const continueAt = (l) => t.sprintf(/* translators: %d: lesson number */ t.__('Continue · Lesson %d', 'membuild-community'), numberOf(l))
const others = computed(() => props.course.learners - (started.value ? 1 : 0))
const taking = computed(() => {
  const first = props.course.faces?.[0]?.name.split(' ')[0]
  if (!first || others.value <= 0) return ''
  return others.value > 1
    ? t.sprintf(/* translators: 1: a member's first name, 2: how many other members */ t._n('%1$s and %2$d other are taking this course', '%1$s and %2$d others are taking this course', others.value - 1, 'membuild-community'), first, others.value - 1)
    : t.sprintf(/* translators: %s: a member's first name */ t.__('%s is taking this course', 'membuild-community'), first)
})
const snippet = (text) => (text.length > 140 ? `${text.slice(0, 140).trim()}…` : text)
</script>

<template>
  <article class="overview card">
    <div class="hero" :style="course.cover ? { backgroundImage: `url(${course.cover})` } : null" :class="{ plain: !course.cover }">
      <span v-if="done" class="badge done"><Icon name="check" :size="14" /> {{ t.__('Completed', 'membuild-community') }}</span>
      <span v-else-if="course.lock" class="badge"><Icon name="lock" :size="14" /> {{ lockLabel(course.lock) }}</span>
    </div>

    <div class="body">
      <h1>{{ course.title }}</h1>
      <p v-if="course.description" class="desc">{{ course.description }}</p>

      <ul class="facts">
        <li><Icon name="list" :size="16" /> {{ lessonsLabel(course.lessons_count) }}</li>
        <li v-if="minutes"><Icon name="clock" :size="16" /> {{ minutesLabel(minutes) }}</li>
        <li v-if="course.files_count"><Icon name="paperclip" :size="16" /> {{ filesLabel(course.files_count) }}</li>
        <li v-if="course.posts_count"><Icon name="comment" :size="16" /> {{ postsLabel(course.posts_count) }}</li>
      </ul>

      <div class="people">
        <div v-if="course.instructor" class="person">
          <Avatar :user="course.instructor" :size="40" link />
          <span>
            <small>{{ t.__('Your instructor', 'membuild-community') }}</small>
            <strong>{{ course.instructor.name }}</strong>
          </span>
        </div>
        <div v-if="taking" class="person">
          <span class="faces"><Avatar v-for="u in course.faces" :key="u.id" :user="u" :size="28" /></span>
          <span class="taking">{{ taking }}</span>
        </div>
      </div>

      <!-- One clear next step -->
      <div class="cta">
        <template v-if="course.lock">
          <a v-if="course.lock.cta" :href="course.lock.cta.url" class="btn btn-primary big">{{ course.lock.cta.label }}</a>
          <RouterLink v-else-if="course.lock.reason === 'level'" to="/me" class="btn btn-primary big">{{ t.__('See your progress', 'membuild-community') }}</RouterLink>
          <p v-if="lockHint(course.lock)" class="hint">{{ lockHint(course.lock) }}</p>
        </template>
        <template v-else-if="next">
          <button class="btn btn-primary big" @click="emit('open', next.id)">
            <Icon name="play" :size="18" />
            {{ done ? t.__('Review the course', 'membuild-community') : started ? continueAt(next) : t.__('Start course', 'membuild-community') }}
          </button>
          <div v-if="started && !done" class="progress">
            <div class="track"><i :style="{ width: `${course.progress}%` }" /></div>
            <span>{{ course.progress }}%</span>
          </div>
        </template>
        <p v-else-if="!lessons.length" class="hint">{{ t.__('Lessons are on their way. Check back soon.', 'membuild-community') }}</p>
      </div>
    </div>

    <section class="curriculum">
      <h2>{{ t.__('What’s inside', 'membuild-community') }}</h2>
      <div v-for="m in course.modules" :key="m.id" class="module">
        <h3>{{ m.title }}</h3>
        <button v-for="l in m.lessons" :key="l.id" class="row" @click="emit('open', l.id)">
          <span class="mark" :class="{ done: l.completed, locked: l.locked }">
            <Icon v-if="l.locked" name="lock" :size="13" />
            <Icon v-else-if="l.completed" name="check" :size="14" />
            <template v-else>{{ numberOf(l) }}</template>
          </span>
          <span class="title">{{ l.title }}</span>
          <span class="meta">
            <Icon v-if="l.has_video" name="play" :size="12" />
            <span v-if="l.files"><Icon name="paperclip" :size="12" /> {{ l.files }}</span>
            <span v-if="l.minutes">{{ short(l.minutes) }}</span>
            <span v-if="l.unlock_at && !course.lock" class="opens">{{ opensOn(l.unlock_at) }}</span>
          </span>
        </button>
      </div>
    </section>

    <section v-if="recent.length" class="recent">
      <div class="recent-head">
        <h2>{{ t.__('Latest in the discussion', 'membuild-community') }}</h2>
        <button class="link" @click="emit('tab', 'discussion')">{{ t.__('See all', 'membuild-community') }}</button>
      </div>
      <button v-for="p in recent" :key="p.id" class="post" @click="emit('tab', 'discussion')">
        <Avatar :user="p.author" :size="32" />
        <span class="post-body">
          <span class="post-head"><strong>{{ p.author?.name }}</strong> · {{ timeAgo(p.created_at) }}<template v-if="p.lesson"> · {{ p.lesson.title }}</template></span>
          <span class="post-text">{{ snippet(p.content || '') }}</span>
        </span>
      </button>
    </section>
  </article>
</template>

<style scoped>
.overview {
  overflow: hidden;
}

.hero {
  position: relative;
  aspect-ratio: 16 / 5;
  background: center / cover no-repeat;
}

.hero.plain {
  background: linear-gradient(135deg, var(--accent-soft), var(--surface-2));
}

.badge {
  position: absolute;
  top: 14px;
  right: 14px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 12px 5px 10px;
  border-radius: 999px;
  background: rgb(0 0 0 / 70%);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  backdrop-filter: blur(6px);
}

.badge.done {
  background: #2fb36b;
}

.body {
  padding: 20px 22px 4px;
}

h1 {
  margin: 0;
  font-size: 26px;
  line-height: 1.2;
}

.desc {
  margin: 8px 0 0;
  color: var(--text-2);
  font-size: 16px;
  line-height: 1.5;
}

.facts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
  color: var(--text-2);
  font-size: 14px;
  font-weight: 650;
}

.facts li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.people {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 28px;
  margin-top: 18px;
}

.person {
  display: flex;
  align-items: center;
  gap: 10px;
}

.person small {
  display: block;
  color: var(--text-3);
  font-size: 12px;
  font-weight: 650;
}

.faces {
  display: flex;
}

.faces > * + * {
  margin-left: -8px;
}

.faces :deep(img),
.faces :deep(.avatar) {
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.taking {
  color: var(--text-2);
  font-size: 14px;
}

.cta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px 18px;
  margin: 20px 0 16px;
}

.big {
  min-height: 46px;
  padding: 0 24px;
  font-size: 16px;
  gap: 8px;
}

.hint {
  margin: 0;
  color: var(--text-2);
  font-size: 14px;
}

.progress {
  flex: 1;
  min-width: 160px;
  max-width: 320px;
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
}

.curriculum,
.recent {
  padding: 16px 22px 20px;
  border-top: 1px solid var(--border);
}

h2 {
  margin: 0 0 6px;
  font-size: 18px;
}

.module h3 {
  margin: 14px 0 4px;
  color: var(--text-3);
  font-size: 12px;
  font-weight: 750;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 9px 8px;
  border-radius: 10px;
  text-align: left;
}

.row:hover {
  background: var(--surface-2);
}

.mark {
  display: grid;
  place-items: center;
  flex: none;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  border: 2px solid var(--border);
  color: var(--text-2);
  font-size: 12px;
  font-weight: 750;
}

.mark.done {
  border-color: #2fb36b;
  background: #2fb36b;
  color: #fff;
}

.mark.locked {
  border-color: transparent;
  background: var(--surface-2);
  color: var(--text-3);
}

.title {
  flex: 1;
  min-width: 0;
  font-size: 15px;
  font-weight: 600;
}

.meta {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: none;
  color: var(--text-3);
  font-size: 13px;
}

.opens {
  color: #e8a400;
  font-weight: 650;
}

.meta span {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.recent-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.link {
  color: var(--accent-text);
  font-weight: 650;
}

.post {
  display: flex;
  gap: 10px;
  width: 100%;
  margin-top: 10px;
  padding: 10px;
  border-radius: 12px;
  background: var(--surface-2);
  text-align: left;
}

.post-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.post-head {
  color: var(--text-3);
  font-size: 13px;
}

.post-head strong {
  color: var(--text);
}

.post-text {
  margin-top: 2px;
  font-size: 14.5px;
  color: var(--text);
}

@media (max-width: 899px) {
  .overview {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  .hero {
    aspect-ratio: 16 / 7;
  }

  .body,
  .curriculum,
  .recent {
    padding-left: 16px;
    padding-right: 16px;
  }

  h1 {
    font-size: 22px;
  }

  .big {
    width: 100%;
    justify-content: center;
  }

  .meta span:not(:last-child) {
    display: none;
  }
}
</style>
