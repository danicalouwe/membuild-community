<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { lockHint, lockLabel } from '../lib/access.js'
import { timeAgo } from '../lib/time.js'
import { renderMarkdown } from '../lib/markdown.js'
import { t } from '../lib/i18n.js'

/**
 * The course's front page (Version 2): cover and what it is beside it, every
 * lesson with its type, time, files and state, then what people are saying.
 * The main button (Start course / Continue) sits in the course's own bar above.
 */
const props = defineProps({ course: { type: Object, required: true } })
const emit = defineEmits(['open', 'tab'])

const lessons = computed(() => props.course.modules.flatMap((m) => m.lessons))
const next = computed(() => lessons.value.find((l) => !l.completed && !l.locked) || null)
// The course's length only when every lesson's is known (a video without one would make it look shorter).
const sum = (list) => (list.length && list.every((l) => l.minutes) ? list.reduce((n, l) => n + l.minutes, 0) : 0)
const minutes = computed(() => sum(lessons.value))
const started = computed(() => props.course.completed_count > 0)
const numberOf = (l) => lessons.value.indexOf(l) + 1
const fallback = (id) => `linear-gradient(135deg, hsl(${(id * 67) % 360} 65% 55%), hsl(${(id * 67 + 50) % 360} 60% 38%))`

// A long description shows three lines, then Read more.
const aboutHtml = computed(() => renderMarkdown(props.course.about || ''))
const longAbout = computed(() => (props.course.about || '').length > 600)
const aboutOpen = ref(false)
const longDesc = computed(() => (props.course.description || '').length > 220)
const readMore = ref(false)

// What people are saying: the newest post and the most helpful one.
const newest = ref(null)
const helpful = ref(null)
watch(
  () => props.course.id,
  async (id) => {
    newest.value = helpful.value = null
    if (props.course.lock || !props.course.posts_count || props.course.questions === 'off') return
    try {
      const [a, b] = await Promise.all([api.coursePosts(id, { sort: 'newest' }), api.coursePosts(id, { sort: 'top' })])
      newest.value = a.items[0] || null
      helpful.value = b.items.find((p) => p.id !== newest.value?.id && p.helpful_count > 0) || b.items.find((p) => p.id !== newest.value?.id) || null
    } catch {
      // the overview works without them
    }
  },
  { immediate: true },
)
const saying = computed(() => [newest.value && { post: newest.value, tag: 'new' }, helpful.value && { post: helpful.value, tag: 'helpful' }].filter(Boolean))

const lessonsLabel = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)
const aboutLabel = (n) =>
  n >= 90
    ? t.sprintf(/* translators: %s: hours, e.g. "1.5" */ t.__('About %s hours', 'membuild-community'), (Math.round(n / 30) / 2).toString())
    : t.sprintf(/* translators: %d: minutes */ t._n('About %d min', 'About %d min', n, 'membuild-community'), n)
const minLabel = (n) => t.sprintf(/* translators: %d: minutes */ t._n('%d min', '%d min', n, 'membuild-community'), n)
const filesLabel = (n) => t.sprintf(/* translators: %d: number of files */ t._n('%d file', '%d files', n, 'membuild-community'), n)
const repliesLabel = (n) => t.sprintf(/* translators: %d: number of replies */ t._n('%d reply', '%d replies', n, 'membuild-community'), n)
const helpfulLabel = (n) => t.sprintf(/* translators: %d: members who found the post helpful */ t.__('%d found this helpful', 'membuild-community'), n)
const allPosts = (n) => t.sprintf(/* translators: %d: number of posts */ t._n('See all %d post', 'See all %d posts', n, 'membuild-community'), n)
const completedOf = (done, total) => t.sprintf(/* translators: 1: lessons completed, 2: lessons in the course */ t.__('%1$d/%2$d lessons completed', 'membuild-community'), done, total)
const opensOn = (iso) => t.sprintf(/* translators: %s: a date, e.g. "Thu, Oct 29" */ t.__('Opens %s', 'membuild-community'), new Date(iso).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }))
const afterStart = (n) => t.sprintf(/* translators: %d: days */ t._n('Opens %d day after you start', 'Opens %d days after you start', n, 'membuild-community'), n)
const moduleMeta = (m) => [lessonsLabel(m.lessons.length), sum(m.lessons) ? minLabel(sum(m.lessons)) : ''].filter(Boolean).join(' · ')
const snippet = (text) => (text.length > 160 ? `${text.slice(0, 160).trim()}…` : text)
</script>

<template>
  <div class="ov">
    <article class="ov-hero card">
      <div class="ov-cover" :style="course.cover ? null : { background: fallback(course.id) }">
        <img v-if="course.cover" :src="course.cover" alt="" />
        <span v-else class="ov-cover-title">{{ course.title }}</span>
        <span v-if="course.lock" class="ov-locked"><Icon name="lock" :size="16" /> {{ t.__('Locked', 'membuild-community') }}</span>
      </div>
      <div class="ov-info">
        <h1>{{ course.title }}</h1>
        <p v-if="course.instructor" class="ov-by"><Avatar :user="course.instructor" :size="28" /> {{ t.__('By', 'membuild-community') }} <strong>{{ course.instructor.name }}</strong></p>
        <p v-if="course.description" class="ov-desc" :class="{ clamp: longDesc && !readMore }">{{ course.description }}</p>
        <button v-if="longDesc" class="ov-more" @click="readMore = !readMore">{{ readMore ? t.__('Show less', 'membuild-community') : t.__('Read more', 'membuild-community') }}</button>
        <ul class="ov-facts">
          <li><Icon name="list" :size="18" /> {{ lessonsLabel(course.lessons_count) }}</li>
          <li v-if="minutes"><Icon name="clock" :size="18" /> {{ aboutLabel(minutes) }}</li>
          <li v-if="course.files_count"><Icon name="paperclip" :size="18" /> {{ filesLabel(course.files_count) }}</li>
        </ul>

        <div v-if="course.lock" class="ov-lockbox">
          <strong><Icon name="lock" :size="18" /> {{ lockLabel(course.lock) }}</strong>
          <p v-if="lockHint(course.lock)">{{ lockHint(course.lock) }}</p>
          <a v-if="course.lock.cta" :href="course.lock.cta.url" class="btn btn-lg btn-primary">{{ course.lock.cta.label }}</a>
          <RouterLink v-else-if="course.lock.reason === 'level'" to="/leaderboard" class="btn btn-lg btn-ghost">{{ t.__('How to earn points', 'membuild-community') }}</RouterLink>
        </div>
        <div v-else-if="lessons.length" class="ov-progress">
          <div class="ov-prog"><span>{{ completedOf(course.completed_count, course.lessons_count) }}</span><span>{{ course.progress }}%</span></div>
          <div class="ov-track"><i :style="{ width: `${course.progress}%` }" /></div>
        </div>
        <p v-else class="ov-soon">{{ t.__('Coming soon. Lessons are on their way.', 'membuild-community') }}</p>
      </div>
    </article>

    <section v-if="course.about" class="ov-section">
      <h2>{{ t.__('About this course', 'membuild-community') }}</h2>
      <div class="ov-about card" :class="{ folded: longAbout && !aboutOpen }">
        <div class="md" v-html="aboutHtml" />
      </div>
      <button v-if="longAbout" class="ov-more" @click="aboutOpen = !aboutOpen">{{ aboutOpen ? t.__('Show less', 'membuild-community') : t.__('Show more', 'membuild-community') }}</button>
    </section>

    <section v-if="lessons.length" class="ov-section">
      <h2>{{ t.__('Lessons in this course', 'membuild-community') }}</h2>
      <div v-for="m in course.modules" :key="m.id" class="ov-module card">
        <header>
          <h3>{{ m.title }}</h3>
          <span>{{ moduleMeta(m) }}</span>
        </header>
        <button v-for="l in m.lessons" :key="l.id" class="ov-row" :class="{ next: next && l.id === next.id && !course.lock }" @click="emit('open', l.id)">
          <span class="ov-mark" :class="{ done: l.completed, locked: l.locked || course.lock }">
            <Icon v-if="l.locked || course.lock" name="lock" :size="15" />
            <svg v-else-if="l.completed" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <template v-else>{{ numberOf(l) }}</template>
          </span>
          <span class="ov-ltext">
            <strong>{{ l.title }}</strong>
            <small>
              <span><Icon :name="l.has_video ? 'video' : 'file'" :size="15" /> {{ l.has_video ? t.__('Video', 'membuild-community') : t.__('Reading', 'membuild-community') }}</span>
              <span v-if="l.minutes"><Icon name="clock" :size="15" /> {{ minLabel(l.minutes) }}</span>
              <span v-if="l.files"><Icon name="paperclip" :size="15" /> {{ filesLabel(l.files) }}</span>
            </small>
          </span>
          <span v-if="l.unlock_at && l.locked && !course.lock" class="ov-opens"><Icon name="lock" :size="14" /> {{ started ? opensOn(l.unlock_at) : afterStart(l.drip_days) }}</span>
          <span v-else-if="next && l.id === next.id && !course.lock" class="btn btn-dark ov-go">{{ started ? t.__('Continue', 'membuild-community') : t.__('Start', 'membuild-community') }} <Icon name="arrowRight" :size="16" /></span>
          <Icon v-else-if="!course.lock" name="chevronRight" :size="20" class="ov-chev" />
        </button>
      </div>
    </section>

    <section v-if="saying.length" class="ov-section">
      <div class="ov-saying-head">
        <h2>{{ t.__('What people are saying', 'membuild-community') }}</h2>
        <button class="ov-all" @click="emit('tab', 'posts')">{{ allPosts(course.posts_count) }} <Icon name="chevronRight" :size="18" /></button>
      </div>
      <div class="ov-saying">
        <button v-for="s in saying" :key="s.tag" class="ov-post card" @click="s.post.lesson ? emit('open', s.post.lesson.id) : emit('tab', 'posts')">
          <span class="ov-tag" :class="s.tag">{{ s.tag === 'new' ? t.__('Newest', 'membuild-community') : t.__('Most helpful', 'membuild-community') }}</span>
          <span class="ov-who">
            <Avatar :user="s.post.author" :size="36" />
            <span>
              <strong>{{ s.post.author?.name }}</strong>
              <small>{{ timeAgo(s.post.created_at) }}<template v-if="s.post.lesson"> · {{ s.post.lesson.title }}</template></small>
            </span>
          </span>
          <span class="ov-text">{{ snippet(s.post.content || '') }}</span>
          <span class="ov-foot">
            <span v-if="s.post.helpful_count" class="ov-helpful"><Icon name="arrowUp" :size="15" /> {{ helpfulLabel(s.post.helpful_count) }}</span>
            <span v-if="s.post.best_comment_id" class="ov-answered"><Icon name="check" :size="15" /> {{ t.__('Answered', 'membuild-community') }}</span>
            <span class="ov-replies">{{ repliesLabel(s.post.comments_count) }}</span>
          </span>
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.ov {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.ov-hero {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  overflow: hidden;
}

.ov-cover {
  position: relative;
  display: grid;
  place-items: center;
  min-height: 100%;
  aspect-ratio: 16 / 9;
}

.ov-cover img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.ov-cover-title {
  padding: 20px;
  color: #fff;
  font-size: 30px;
  font-weight: 800;
  text-align: center;
  text-shadow: 0 1px 3px rgb(0 0 0 / 25%);
}

.ov-locked {
  position: absolute;
  top: 14px;
  left: 14px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px 6px 12px;
  border-radius: 999px;
  background: rgb(0 0 0 / 72%);
  color: #fff;
  font-size: 15px;
  font-weight: 700;
}

.ov-info {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 24px 26px;
}

.ov-info h1 {
  margin: 0;
  font-size: 28px;
  line-height: 1.2;
}

.ov-by {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  font-size: 16px;
  color: var(--text-2);
}

.ov-by strong {
  color: var(--text);
}

.ov-desc {
  margin: 0;
  font-size: 17px;
  line-height: 1.55;
  color: var(--text-2);
}

.ov-desc.clamp {
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.ov-more {
  align-self: flex-start;
  min-height: 36px;
  margin-top: -8px;
  font-size: 16px;
  font-weight: 700;
  color: var(--text);
  text-decoration: underline;
}

.ov-facts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
  margin: 0;
  padding: 0 0 14px;
  list-style: none;
  border-bottom: 1px solid var(--border);
  font-size: 16px;
  font-weight: 650;
}

.ov-facts li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.ov-prog {
  display: flex;
  justify-content: space-between;
  margin-bottom: 8px;
  font-size: 15.5px;
  font-weight: 650;
  color: var(--text-2);
}

.ov-track {
  height: 8px;
  border-radius: 999px;
  background: var(--surface-2);
  overflow: hidden;
}

.ov-track i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--text);
}

.ov-lockbox {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
  padding: 16px;
  border-radius: 14px;
  background: var(--surface-2);
}

.ov-lockbox strong {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 17px;
}

.ov-lockbox p,
.ov-soon {
  margin: 0;
  font-size: 16px;
  color: var(--text-2);
}

.ov-section h2 {
  margin: 0 0 14px;
  font-size: 23px;
}

.ov-about {
  position: relative;
  padding: 20px 24px;
  font-size: 17px;
  line-height: 1.6;
}

.ov-about.folded {
  max-height: 260px;
  overflow: hidden;
}

.ov-about.folded::after {
  content: '';
  position: absolute;
  inset: auto 0 0;
  height: 80px;
  background: linear-gradient(transparent, var(--surface));
}

.ov-about + .ov-more {
  margin-top: 10px;
}

.ov-module {
  overflow: hidden;
}

.ov-module + .ov-module {
  margin-top: 14px;
}

.ov-module header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}

.ov-module h3 {
  margin: 0;
  font-size: 19px;
}

.ov-module header span {
  font-size: 15px;
  color: var(--text-2);
  white-space: nowrap;
}

.ov-row {
  display: flex;
  align-items: center;
  gap: 16px;
  width: 100%;
  min-height: 72px;
  padding: 12px 20px;
  text-align: left;
  color: var(--text);
}

.ov-row + .ov-row {
  border-top: 1px solid var(--border);
}

.ov-row:hover {
  background: var(--surface-2);
}

.ov-row.next {
  background: var(--accent-soft);
}

.ov-mark {
  flex: none;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 2px solid var(--border-strong, #c9ced6);
  font-size: 15px;
  font-weight: 700;
}

.ov-row.next .ov-mark {
  border-color: var(--text);
}

.ov-mark.done {
  border-color: #2fb36b;
  background: #2fb36b;
  color: #fff;
}

.ov-mark.locked {
  border-color: transparent;
  background: var(--surface-2);
  color: var(--text-2);
}

.ov-ltext {
  flex: 1;
  min-width: 0;
}

.ov-ltext strong {
  display: block;
  font-size: 17.5px;
}

.ov-ltext small {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  margin-top: 3px;
  font-size: 15px;
  color: var(--text-2);
}

.ov-ltext small span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.ov-go {
  flex: none;
  height: 44px;
  font-size: 16px;
}

.ov-opens {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 40px;
  padding: 0 14px;
  border-radius: 999px;
  background: var(--text);
  color: var(--surface);
  font-size: 15px;
  font-weight: 700;
}

.ov-chev {
  flex: none;
  color: var(--text-3);
}

.ov-saying-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.ov-all {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 44px;
  font-size: 16px;
  font-weight: 700;
  color: var(--text);
}

.ov-saying {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.ov-post {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
  padding: 18px 20px;
  text-align: left;
  color: var(--text);
}

.ov-post:hover {
  box-shadow: 0 10px 28px rgb(0 0 0 / 12%);
}

.ov-tag {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 14px;
  font-weight: 700;
  background: var(--ok-soft);
  color: var(--ok-text);
}

.ov-tag.helpful {
  background: var(--tip-soft);
  color: var(--tip-strong);
}

.ov-who {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ov-who strong {
  display: block;
  font-size: 16px;
}

.ov-who small {
  display: block;
  font-size: 14px;
  color: var(--text-2);
}

.ov-text {
  flex: 1;
  font-size: 16.5px;
  line-height: 1.5;
}

.ov-foot {
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  padding-top: 12px;
  border-top: 1px solid var(--border);
  font-size: 15px;
  font-weight: 650;
}

.ov-helpful {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--tip-strong);
}

.ov-answered {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--ok-text);
}

.ov-replies {
  margin-left: auto;
  color: var(--text-2);
}

@media (max-width: 899px) {
  .ov {
    gap: 22px;
  }

  .ov-hero {
    grid-template-columns: minmax(0, 1fr);
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  .ov-info {
    padding: 18px 16px;
  }

  .ov-section {
    padding: 0 12px;
  }

  .ov-row {
    padding: 12px 14px;
    gap: 12px;
  }

  .ov-go {
    padding: 0 14px;
  }

  .ov-saying {
    grid-template-columns: minmax(0, 1fr);
  }

  .ov-module header {
    padding: 14px;
  }
}
</style>
