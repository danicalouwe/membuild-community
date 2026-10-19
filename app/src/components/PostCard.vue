<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
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
const PostDialog = defineAsyncComponent(() => import('./PostDialog.vue'))
const CreatePostDialog = defineAsyncComponent(() => import('./CreatePostDialog.vue'))
const ReportDialog = defineAsyncComponent(() => import('./ReportDialog.vue'))
import { BACKGROUNDS, FEELINGS } from '../lib/postStyle.js'
import { t } from '../lib/i18n.js'
import { useMenu } from '../lib/useMenu.js'

const props = defineProps({
  post: { type: Object, required: true },
  showPlace: { type: Boolean, default: false }, // a course post outside its lesson: say which lesson
})

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
  if (!confirm(t.__('Delete this post?', 'membuild-community'))) return
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
  <article class="post card" :class="{ pending: post.pending }">
    <header class="head">
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
        <RouterLink v-if="showPlace && post.lesson" class="place" :to="{ name: 'course', params: { courseId: post.course.id, lessonId: post.lesson.id } }">
          <Icon name="cap" :size="13" /> {{ post.lesson.title }}
        </RouterLink>
        <RouterLink v-else-if="showPlace && post.course" class="place" :to="{ name: 'course', params: { courseId: post.course.id } }">
          <Icon name="cap" :size="13" /> {{ post.course.title }}
        </RouterLink>
      </div>
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
          <button v-if="post.can_edit" role="menuitem" @click="closeMenu(); editing = true"><Icon name="compose" :size="18" /> {{ t.__('Edit post', 'membuild-community') }}</button>
          <button v-if="post.can_delete" role="menuitem" @click="remove"><Icon name="trash" :size="18" /> {{ t.__('Delete post', 'membuild-community') }}</button>
          <button v-if="!isMine" role="menuitem" @click="closeMenu(); reporting = true"><Icon name="flag" :size="18" /> {{ t.__('Report post', 'membuild-community') }}</button>
        </div>
      </div>
    </header>

    <EventCard v-if="post.event" :post="post" compact />
    <div v-if="background" class="bg-post" :style="{ background }">
      <p>{{ post.content }}</p>
    </div>
    <RichText v-else-if="post.content" class="text" :class="{ big: !post.media.length && post.content.length < 90 }" :text="post.content" :clamp="320" />

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
  display: inline-flex;
  align-items: center;
  gap: 4px;
  align-self: flex-start;
  max-width: 100%;
  margin-top: 3px;
  padding: 2px 8px;
  border-radius: 6px;
  background: var(--surface-2);
  color: var(--text-2);
  font-size: 12.5px;
  font-weight: 650;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.place:hover {
  color: var(--text);
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
