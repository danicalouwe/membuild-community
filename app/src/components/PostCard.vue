<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { api } from '../api.js'
import { removePost, setCoursePin, setPinned, takePost } from '../stores/feed.js'
import { cfg } from '../config.js'
import { timeAgo } from '../lib/time.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import RichText from './RichText.vue'
import MediaGrid from './MediaGrid.vue'
import PostActions from './PostActions.vue'
import CommentItem from './CommentItem.vue'
import EventCard from './EventCard.vue'
import CourseLinkCard from './CourseLinkCard.vue'
import { useCourseLink } from '../lib/courseCards.js'
const PostDialog = defineAsyncComponent(() => import('./PostDialog.vue'))
const CreatePostDialog = defineAsyncComponent(() => import('./CreatePostDialog.vue'))
const ReportDialog = defineAsyncComponent(() => import('./ReportDialog.vue'))
import { BACKGROUNDS, FEELINGS } from '../lib/postStyle.js'
import { t } from '../lib/i18n.js'
import { useMenu } from '../lib/useMenu.js'

const props = defineProps({
  post: { type: Object, required: true },
  showPlace: { type: Boolean, default: false }, // a course post outside its lesson: say which lesson
  courseHeader: { type: Boolean, default: false }, // Home: lead with the course, author on its corner
})

// A course without a cover gets the same gradient the Classroom grid gives it.
const coverFallback = (id) => `linear-gradient(135deg, hsl(${(id * 67) % 360} 65% 55%), hsl(${(id * 67 + 50) % 360} 60% 38%))`

// A link to a course shows as a course card under the text, and the link leaves the
// text; the course's name in it is bold. A course this member can't see keeps the link.
const { course: linkedCourse, text: shownText } = useCourseLink(() => props.post.content)

const { open: menu, root: menuRoot, trigger: menuTrigger, list: menuList, toggle: toggleMenu, close: closeMenu, onKeydown: onMenuKey } = useMenu()
const editing = ref(false)
const reporting = ref(false)
const isMine = computed(() => props.post.author?.id === cfg.user.id)
const canPin = computed(() => cfg.community.isStaff && props.post.type === 'post' && !props.post.pending)
const inCourse = computed(() => !!props.post.course)
const pinLabel = computed(() =>
  props.post.pin === 'lesson' ? t.__('Pinned to lesson', 'membuild-community') : props.post.pin === 'course' ? t.__('Pinned to course', 'membuild-community') : t.__('Pinned', 'membuild-community'),
)
const staffLabel = computed(() =>
  props.post.staff_role === 'instructor' ? t.__('Instructor', 'membuild-community') : props.post.staff_role === 'moderator' ? t.__('Moderator', 'membuild-community') : '',
)

// Course posts pin to their lesson or to the whole course, never to the feed.
async function pinInCourse(scope) {
  closeMenu()
  try {
    const res = scope ? await api.pinPost(props.post.id, scope) : await api.unpinPost(props.post.id)
    setCoursePin(props.post, scope ? res.pin : null)
  } catch (err) {
    alert(err.message)
  }
}

async function togglePin() {
  closeMenu()
  const pin = !props.post.pinned
  try {
    await (pin ? api.pinPost(props.post.id) : api.unpinPost(props.post.id))
    setPinned(props.post, pin)
  } catch (err) {
    alert(err.message)
  }
}

function reported(res) {
  if (res.hidden) removePost(props.post.id) // hidden for review: it leaves the feed now
}
const feeling = computed(() => FEELINGS[props.post.feeling] || null)
/**
 * "<name> is feeling <emoji> <feeling>." as one translatable sentence, split
 * around its placeholders so the name and the feeling can be styled:
 * [{ text }, { slot: 1 }, …] where slot 1 is the name, 2 the emoji, 3 the feeling.
 */
const nameParts = computed(() => {
  if (!feeling.value) return [{ slot: 1 }]
  const sentence = /* translators: 1: the person's name, 2: an emoji, 3: a feeling, e.g. "happy" */ t.__('%1$s is feeling %2$s %3$s.', 'membuild-community')
  return sentence
    .split(/(%\d\$s)/)
    .filter(Boolean)
    .map((p) => (/^%\d\$s$/.test(p) ? { slot: Number(p[1]) } : { text: p }))
})
const background = computed(() => (props.post.background && !props.post.media.length ? BACKGROUNDS[props.post.background] : null))
const dialog = ref(null) // null | { focusComment: boolean }

// Facebook-style preview under the post: the top comment and its first reply.
const preview = computed(() => props.post.preview_comment)
const previewCount = computed(() => (preview.value ? 1 + preview.value.replies.length : 0))
const moreComments = computed(() => props.post.comments_count > previewCount.value)

const open = (focusComment = false) => {
  if (!props.post.pending) dialog.value = { focusComment }
}

async function remove() {
  closeMenu()
  if (!(await askConfirm({ title: t.__('Delete this post?', 'membuild-community'), text: t.__('It’s gone for everyone, with its comments and reactions. This can’t be undone.', 'membuild-community'), confirm: t.__('Delete post', 'membuild-community'), danger: true }))) return
  const restore = takePost(props.post.id)
  try {
    await api.deletePost(props.post.id)
  } catch (err) {
    restore() // the server kept it, so it comes back where it was
    alert(err.message)
  }
}
</script>

<template>
  <article class="post card" :class="{ pending: post.pending }" :data-post-id="post.id">
    <!-- In Home, a course question leads with the course (its cover as the picture, the author
         tucked on its corner), the way a group post reads in a Facebook home feed. -->
    <header class="head">
      <template v-if="courseHeader && post.course">
      <RouterLink class="course-pic" :to="{ name: 'course', params: { courseId: post.course.id } }" :aria-label="post.course.title">
        <img v-if="post.course.cover" :src="post.course.cover" alt="" />
        <span v-else class="course-fallback" :style="{ background: coverFallback(post.course.id) }"><Icon name="cap" :size="18" /></span>
        <Avatar :user="post.author" :size="22" class="mini" />
      </RouterLink>
      <div class="who">
        <div class="name-line">
          <RouterLink class="name" :to="{ name: 'course', params: { courseId: post.course.id } }">{{ post.course.title }}</RouterLink>
        </div>
        <span class="byline">
          <template v-for="(p, i) in nameParts" :key="i">
            <template v-if="p.slot === 1">
              <RouterLink v-if="post.author" :to="{ name: 'member', params: { id: post.author.id } }" class="by">{{ post.author.name }}</RouterLink>
            </template>
            <span v-else class="feeling"
              ><template v-if="p.slot === 2">{{ feeling.emoji }}</template
              ><strong v-else-if="p.slot === 3">{{ feeling.label }}</strong
              ><template v-else>{{ p.text }}</template></span
            >
          </template>
          <em v-if="post.author?.level" class="lvl" :aria-label="t.sprintf(t.__('Level %d', 'membuild-community'), post.author.level)">{{ post.author.level }}</em>
          <em v-if="staffLabel" class="staff">{{ staffLabel }}</em>
          · {{ post.pending ? t.__('Posting…', 'membuild-community') : timeAgo(post.created_at) }}<template v-if="post.edited"> · {{ t.__('Edited', 'membuild-community') }}</template>
          <template v-if="post.lesson">
            · {{ t.__('in', 'membuild-community') }}
            <RouterLink class="in-lesson" :to="{ name: 'course', params: { courseId: post.course.id, lessonId: post.lesson.id } }">{{ post.lesson.title }}</RouterLink>
          </template>
        </span>
      </div>
      </template>
      <template v-else>
      <Avatar :user="post.author" :size="40" badge link />
      <div class="who">
        <div class="name-line">
          <template v-for="(p, i) in nameParts" :key="i">
            <template v-if="p.slot === 1">
              <RouterLink v-if="post.author" :to="{ name: 'member', params: { id: post.author.id } }" class="name">{{ post.author.name }}</RouterLink>
            </template>
            <span v-else class="feeling"
              ><template v-if="p.slot === 2">{{ feeling.emoji }}</template
              ><strong v-else-if="p.slot === 3">{{ feeling.label }}</strong
              ><template v-else>{{ p.text }}</template></span
            >
          </template>
        </div>
        <span>
          <em v-if="staffLabel" class="staff">{{ staffLabel }}</em>
          {{ post.pending ? t.__('Posting…', 'membuild-community') : timeAgo(post.created_at) }}<template v-if="post.edited"> · {{ t.__('Edited', 'membuild-community') }}</template>
        </span>
      </div>
      </template>
      <span v-if="post.pinned" class="pin" :title="t.__('Pinned by an admin', 'membuild-community')"><Icon name="pin" :size="14" /> {{ pinLabel }}</span>
      <div v-if="!post.pending" :ref="menuRoot" class="menu-wrap">
        <button :ref="menuTrigger" class="icon-btn" :aria-label="t.__('Post options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="menu" @click="toggleMenu"><Icon name="more" /></button>
        <div v-if="menu" :ref="menuList" class="menu" role="menu" @keydown="onMenuKey">
          <template v-if="canPin && inCourse">
            <button v-if="post.lesson && post.pin !== 'lesson'" role="menuitem" class="plain" @click="pinInCourse('lesson')"><Icon name="pin" :size="18" /> {{ t.__('Pin to lesson', 'membuild-community') }}</button>
            <button v-if="post.pin !== 'course'" role="menuitem" class="plain" @click="pinInCourse('course')"><Icon name="pin" :size="18" /> {{ t.__('Pin to course', 'membuild-community') }}</button>
            <button v-if="post.pin" role="menuitem" class="plain" @click="pinInCourse(null)"><Icon name="pin" :size="18" /> {{ t.__('Unpin post', 'membuild-community') }}</button>
          </template>
          <button v-else-if="canPin" role="menuitem" class="plain" @click="togglePin"><Icon name="pin" :size="18" /> {{ post.pinned ? t.__('Unpin post', 'membuild-community') : t.__('Pin to top', 'membuild-community') }}</button>
          <button v-if="post.can_edit" role="menuitem" class="plain" @click="closeMenu(); editing = true"><Icon name="compose" :size="18" /> {{ t.__('Edit post', 'membuild-community') }}</button>
          <button v-if="post.can_delete" role="menuitem" @click="remove"><Icon name="trash" :size="18" /> {{ t.__('Delete post', 'membuild-community') }}</button>
          <button v-if="!isMine" role="menuitem" class="plain" @click="closeMenu(); reporting = true"><Icon name="flag" :size="18" /> {{ t.__('Report post', 'membuild-community') }}</button>
        </div>
      </div>
    </header>

    <!-- In a course's All posts: which lesson the post is about, so it can be opened there. -->
    <RouterLink v-if="showPlace && post.lesson" class="place" :to="{ name: 'course', params: { courseId: post.course.id, lessonId: post.lesson.id } }">
      <Icon name="video" :size="15" /> {{ t.sprintf(/* translators: %s: lesson title */ t.__('In: %s', 'membuild-community'), post.lesson.title) }}
    </RouterLink>
    <RouterLink v-else-if="showPlace && post.course" class="place" :to="{ name: 'course', params: { courseId: post.course.id } }">
      <Icon name="cap" :size="15" /> {{ t.sprintf(/* translators: %s: course title */ t.__('In: %s', 'membuild-community'), post.course.title) }}
    </RouterLink>

    <EventCard v-if="post.event" :post="post" compact />
    <div v-if="background" class="bg-post" :style="{ background }">
      <p>{{ post.content }}</p>
    </div>
    <RichText v-else-if="shownText" class="text" :class="{ big: !post.media.length && !linkedCourse && post.content.length < 90 }" :text="shownText" :strong="linkedCourse?.title" :clamp="320" />
    <CourseLinkCard v-if="linkedCourse && !background" :course="linkedCourse" />

    <MediaGrid v-if="post.media.length && !post.event" class="media" :media="post.media" :reel-link="post.type === 'reel' ? { name: 'reels', query: { id: post.id } } : null" />

    <PostActions :post="post" @comment="open(true)" />

    <div v-if="preview" class="preview">
      <button v-if="moreComments" class="more" @click="open(false)">{{ t.__('View more comments', 'membuild-community') }}</button>
      <CommentItem :comment="preview" :in-course="inCourse" @reply="open(true)" />
      <div v-if="preview.replies.length" class="preview-replies">
        <CommentItem v-for="r in preview.replies" :key="r.id" :comment="r" reply :in-course="inCourse" @reply="open(true)" />
      </div>
    </div>

    <ReportDialog v-if="reporting" type="post" :id="post.id" @close="reporting = false" @reported="reported" />
    <CreatePostDialog v-if="editing" :edit="post" @close="editing = false" />
    <PostDialog v-if="dialog" :post="post" :focus-comment="dialog.focusComment" @close="dialog = null" />
  </article>
</template>

<style scoped>
.staff {
  margin-right: 4px;
  padding: 1px 7px;
  border-radius: 999px;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-style: normal;
  font-size: 12px;
  font-weight: 700;
}

.place {
  display: flex;
  width: fit-content;
  align-items: center;
  gap: 6px;
  max-width: calc(100% - 28px);
  margin: 2px 14px 12px;
  padding: 4px 12px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text);
  font-size: 14px;
  font-weight: 650;
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.place:hover {
  background: var(--border);
}

/* Home: the course is the picture, the author sits on its corner. */
.course-pic {
  position: relative;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 10px;
  overflow: visible;
}

.course-pic img,
.course-fallback {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 10px;
  object-fit: cover;
  color: #fff;
}

.course-pic .mini {
  position: absolute;
  right: -6px;
  bottom: -5px;
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 999px;
}

.byline {
  display: block;
  font-size: 13px;
  color: var(--text-3);
  line-height: 1.35;
}

.byline .by {
  color: var(--text);
  font-weight: 650;
}

.byline .in-lesson {
  color: var(--text-2);
  font-weight: 650;
}

.byline .in-lesson:hover,
.byline .by:hover {
  text-decoration: underline;
}

.byline .lvl {
  display: inline-grid;
  place-items: center;
  min-width: 16px;
  height: 16px;
  margin: 0 2px 0 4px;
  padding: 0 4px;
  border-radius: 999px;
  background: var(--accent);
  color: var(--accent-ink);
  font-style: normal;
  font-size: 10.5px;
  font-weight: 800;
  vertical-align: 1px;
}

.byline .staff {
  margin: 0 2px;
}

.pin {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-left: auto;
  font-size: 13px;
  font-weight: 650;
  color: var(--text-3);
  white-space: nowrap;
}

.pin + .menu-wrap {
  margin-left: 0;
}

.name {
  font-weight: 700;
  color: var(--text);
}

.name:hover {
  text-decoration: underline;
}

.post {
  /* Off-screen posts skip layout and paint, which keeps long feeds smooth. */
  content-visibility: auto;
  contain-intrinsic-size: auto 520px;
}

.post.pending {
  opacity: 0.75;
}

.head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px 8px;
}

.who {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.25;
}

.who span {
  font-size: 13px;
  color: var(--text-3);
}

.menu-wrap {
  position: relative;
  margin-left: auto;
}

.icon-btn {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  color: var(--text-2);
}

.icon-btn:hover {
  background: var(--surface-2);
}

.menu {
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 5;
  min-width: 180px;
  padding: 6px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  box-shadow: 0 8px 24px rgb(0 0 0 / 14%);
}

.menu button {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 10px;
  border-radius: 8px;
  color: var(--danger);
  font-weight: 600;
}

.menu button.plain {
  color: var(--text);
}

.menu button:hover {
  background: var(--surface-2);
}

.name-line {
  line-height: 1.3;
}

.feeling {
  color: var(--text-2);
}

.feeling strong {
  color: var(--text);
  font-weight: 650;
}

.text {
  padding: 0 14px 10px;
}

/* Short text on a colored background, big and centered. */
.bg-post {
  display: grid;
  place-items: center;
  min-height: 340px;
  padding: 32px 28px;
  text-align: center;
}

.bg-post p {
  margin: 0;
  color: #fff;
  font-size: 28px;
  font-weight: 700;
  line-height: 1.3;
  text-shadow: 0 1px 2px rgb(0 0 0 / 15%);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.text.big {
  font-size: 20px;
  line-height: 1.35;
}

.media {
  overflow: hidden;
}

.preview {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 4px 14px 14px;
}

.more {
  align-self: flex-start;
  font-weight: 650;
  font-size: 15px;
  color: var(--text-2);
}

.more:hover {
  text-decoration: underline;
}

.preview-replies {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-left: 46px;
}
</style>
