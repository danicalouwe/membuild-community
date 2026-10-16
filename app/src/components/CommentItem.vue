<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import GifMedia from './GifMedia.vue'
import { cfg } from '../config.js'
import { timeAgo } from '../lib/time.js'
import { emojiFor } from '../lib/reactions.js'
import { setCommentReaction } from '../lib/comments.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import RichText from './RichText.vue'
import ReactionButton from './ReactionButton.vue'
import ReactionStack from './ReactionStack.vue'
import { around, t } from '../lib/i18n.js'
import { useMenu } from '../lib/useMenu.js'
const ReportDialog = defineAsyncComponent(() => import('./ReportDialog.vue'))

const props = defineProps({
  comment: { type: Object, required: true },
  reply: { type: Boolean, default: false }, // rendered inside a thread
  canChooseBest: { type: Boolean, default: false }, // a course post's author or staff: may mark this as the best answer
  inCourse: { type: Boolean, default: false }, // under a course post: staff show as Instructor / Moderator
})
defineEmits(['reply', 'delete', 'best'])

const c = computed(() => props.comment)
const pendingOrFailed = computed(() => c.value.pending || c.value.failed)
// Your own comments show Delete inline; others' have a ⋯ menu with Report (and Remove for staff).
const isMine = computed(() => c.value.author?.id === cfg.user.id)
const { open: menu, root: menuRoot, trigger: menuTrigger, list: menuList, toggle: toggleMenu, close: closeMenu, onKeydown: onMenuKey } = useMenu()
const byAuthor = around(/* translators: %s: the reaction emoji the post's author gave this comment */ t.__('%s by author', 'membuild-community'))
const reporting = ref(false)

function react(type) {
  if (!pendingOrFailed.value) setCommentReaction(c.value, type)
}
</script>

<template>
  <div class="comment" :class="{ reply, pending: c.pending }">
    <Avatar :user="c.author" :size="reply ? 28 : 36" badge link />
    <div class="main">
      <div class="head">
        <RouterLink v-if="c.author" :to="{ name: 'member', params: { id: c.author.id } }" class="name">{{ c.author.name }}</RouterLink>
        <span v-if="inCourse && c.staff_role" class="staff">{{ c.staff_role === 'instructor' ? t.__('Instructor', 'membuild-community') : t.__('Moderator', 'membuild-community') }}</span>
        <span class="dot">·</span>
        <span class="time">{{ c.failed ? t.__('Not sent', 'membuild-community') : c.pending ? t.__('Posting…', 'membuild-community') : timeAgo(c.created_at) }}</span>
        <template v-if="c.best">
          <span class="dot">·</span>
          <span class="best"><Icon name="check" :size="13" /> {{ t.__('Best answer', 'membuild-community') }}</span>
        </template>
        <template v-if="c.liked_by_author">
          <span class="dot">·</span>
          <span class="by-author">{{ byAuthor[0] }}<span class="mini">{{ emojiFor(c.liked_by_author) }}</span>{{ byAuthor[1] }}</span>
        </template>
      </div>

      <div v-if="c.content || c.reply_to" class="text">
        <RouterLink v-if="c.reply_to" class="mention" :to="{ name: 'member', params: { id: c.reply_to.id } }">{{ c.reply_to.name }}</RouterLink>
        <RichText :text="c.content" class="inline" />
      </div>
      <a v-if="c.image" class="pic" :href="c.image.url" target="_blank" rel="noopener">
        <GifMedia v-if="c.image.mp4" :src="c.image.url" :mp4="c.image.mp4" :width="c.image.width || 0" :height="c.image.height || 0" />
        <img v-else :src="c.image.url" alt="" loading="lazy" :width="c.image.width || null" :height="c.image.height || null" />
      </a>
      <p v-if="c.failed" class="failed">{{ c.failed }}</p>

      <div class="actions">
        <ReactionButton :current="c.my_reaction === 'down' ? null : c.my_reaction" :size="20" @react="react" />
        <button class="act" :class="{ on: c.my_reaction === 'down' }" :aria-label="t.__('Dislike', 'membuild-community')" :aria-pressed="c.my_reaction === 'down'" @click="react(c.my_reaction === 'down' ? null : 'down')">
          <Icon name="thumbDown" :size="20" />
        </button>
        <button class="act text-btn" @click="$emit('reply', c)">{{ t._x('Reply', 'verb: reply to a comment', 'membuild-community') }}</button>
        <button v-if="canChooseBest && !reply && !pendingOrFailed" class="act text-btn" :class="{ on: c.best }" :aria-pressed="!!c.best" @click="$emit('best', c)">
          {{ c.best ? t.__('Unmark best', 'membuild-community') : t.__('Best answer', 'membuild-community') }}
        </button>
        <button v-if="isMine && !c.pending" class="act text-btn subtle" @click="$emit('delete', c)">{{ t.__('Delete', 'membuild-community') }}</button>
        <span v-if="!isMine && !pendingOrFailed" :ref="menuRoot" class="more-wrap">
          <button :ref="menuTrigger" class="act more" :aria-label="t.__('Comment options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="menu" @click="toggleMenu"><Icon name="more" :size="18" /></button>
          <span v-if="menu" :ref="menuList" class="menu" role="menu" @keydown="onMenuKey">
            <button role="menuitem" @click="closeMenu(); reporting = true"><Icon name="flag" :size="16" /> {{ t.__('Report comment', 'membuild-community') }}</button>
            <button v-if="cfg.community.isStaff" role="menuitem" class="danger" @click="closeMenu(); $emit('delete', c)"><Icon name="trash" :size="16" /> {{ t.__('Remove comment', 'membuild-community') }}</button>
          </span>
        </span>
        <ReactionStack v-if="c.reactions_count" class="pill" :types="c.top_reactions" :count="c.reactions_count" :size="20" />
      </div>
    </div>
    <ReportDialog v-if="reporting" type="comment" :id="c.id" @close="reporting = false" />
  </div>
</template>

<style scoped>
.staff {
  padding: 0 7px;
  border-radius: 999px;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: 12px;
  font-weight: 700;
}

.best {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 1px 8px 1px 6px;
  border-radius: 999px;
  background: #2fb36b;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
}

.comment {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}

.comment.pending {
  opacity: 0.6;
}

.main {
  min-width: 0;
  flex: 1;
}

.head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  font-size: 14px;
  line-height: 1.3;
  color: var(--text-3);
}

.name {
  color: var(--text);
  font-weight: 650;
}

.name:hover,
.mention:hover {
  text-decoration: underline;
}

.reply .head {
  font-size: 13.5px;
}

.by-author {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.mini {
  font-size: 12px;
}

.text {
  margin-top: 2px;
  font-size: 15.5px;
  line-height: 1.4;
  color: var(--text);
}

.inline {
  display: inline;
}

.mention {
  color: var(--accent-text);
  font-weight: 600;
  margin-right: 5px;
}

.failed {
  margin: 2px 0 0;
  font-size: 12px;
  color: var(--danger);
}

.actions {
  display: flex;
  align-items: center;
  gap: 2px;
  margin: 2px 0 0 -8px;
}

.act {
  display: inline-flex;
  align-items: center;
  min-height: 34px;
  padding: 0 8px;
  border-radius: 8px;
  color: var(--text-2);
  font-weight: 600;
  font-size: 14px;
}

.act:hover {
  background: var(--surface-2);
}

.act.on {
  color: var(--text);
}

.act.subtle {
  color: var(--text-3);
  font-weight: 500;
}

.pill {
  margin-left: 14px;
}
.pic {
  display: block;
  margin: 6px 0 2px;
}

.pic img {
  display: block;
  max-width: min(240px, 100%);
  max-height: 240px;
  width: auto;
  height: auto;
  border-radius: 14px;
  object-fit: cover;
}
.more-wrap {
  position: relative;
}

.more {
  opacity: 0;
}

.comment:hover .more,
.more[aria-expanded='true'],
.more:focus-visible {
  opacity: 1;
}

@media (hover: none) {
  .more {
    opacity: 1;
  }
}

.menu {
  position: absolute;
  left: 0;
  top: 100%;
  z-index: 6;
  display: flex;
  flex-direction: column;
  min-width: 190px;
  padding: 6px;
  border-radius: 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: 0 8px 24px rgb(0 0 0 / 20%);
}

.menu button {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border-radius: 8px;
  font-weight: 600;
  text-align: left;
  color: var(--text);
}

.menu button:hover {
  background: var(--surface-2);
}

.menu .danger {
  color: var(--danger);
}
</style>
