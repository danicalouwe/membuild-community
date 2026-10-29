<script setup>
import { computed, defineAsyncComponent, ref, onMounted } from 'vue'
import GifMedia from './GifMedia.vue'
import { gifBox } from '../lib/gifBox.js'
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
import { useRouter } from 'vue-router'
import { setShareDraft } from '../stores/messages.js'
import { feature } from '../config.js'
const ReportDialog = defineAsyncComponent(() => import('./ReportDialog.vue'))

const props = defineProps({
  comment: { type: Object, required: true },
  reply: { type: Boolean, default: false }, // rendered inside a thread
  canChooseBest: { type: Boolean, default: false }, // a course post's author or staff: may mark this as the best answer
  inCourse: { type: Boolean, default: false }, // under a course post: staff show as Instructor / Moderator
  highlight: { type: Boolean, default: false }, // flashed once, when opened from a shared link
})
defineEmits(['reply', 'delete', 'best'])

const c = computed(() => props.comment)
const pendingOrFailed = computed(() => c.value.pending || c.value.failed)
// Your own comments show Delete inline; others' have a ⋯ menu with Report (and Remove for staff).
const isMine = computed(() => c.value.author?.id === cfg.user.id)
const { open: menu, root: menuRoot, trigger: menuTrigger, list: menuList, toggle: toggleMenu, close: closeMenu, onKeydown: onMenuKey } = useMenu()
const byAuthor = around(/* translators: %s: the reaction emoji the post's author gave this comment */ t.__('%s by author', 'membuild-community'))
const reporting = ref(false)
// In a feed card the comments sit at the card's bottom edge and the card clips what
// spills past it (content-visibility), so menus there open upward.
const el = ref(null)
const up = ref(false)
onMounted(() => (up.value = !!el.value?.closest('.preview')))

// In the ⋯ menu: copy the comment's link, send it in a message, or the phone's share sheet.
// The link opens the post with this comment scrolled into view.
const router = useRouter()
const canSheet = typeof navigator !== 'undefined' && !!navigator.share
const copied = ref(false)
const shareUrl = () => `${location.origin}${location.pathname.replace(/\/$/, '')}?post=${c.value.post_id}&comment=${c.value.id}`
function sendInMessage() {
  setShareDraft(shareUrl())
  router.push({ name: 'messages', query: { new: 1 } })
}
async function shareSheet() {
  try {
    await navigator.share({ url: shareUrl() })
  } catch {
    /* dismissed */
  }
}
async function copyLink() {
  const url = shareUrl()
  try {
    await navigator.clipboard.writeText(url)
  } catch {
    // No clipboard API (plain http) or no permission: the old way, which works everywhere.
    try {
      const ta = Object.assign(document.createElement('textarea'), { value: url, readOnly: true })
      ta.style.cssText = 'position:fixed;top:-100px;opacity:0'
      document.body.append(ta)
      ta.select()
      const ok = document.execCommand('copy')
      ta.remove()
      if (!ok) throw new Error('copy failed')
    } catch {
      alert(url) // last resort: at least the link is on screen to copy by hand
      return
    }
  }
  copied.value = true
  setTimeout(() => (copied.value = false), 1600)
}

function react(type) {
  if (!pendingOrFailed.value) setCommentReaction(c.value, type)
}
</script>

<template>
  <div :id="`c-${c.id}`" ref="el" class="comment" :class="{ reply, pending: c.pending, highlight }">
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
      <a v-if="c.image" class="pic" :class="{ gif: c.image.mp4 }" :style="c.image.mp4 ? gifBox(c.image) : null" :href="c.image.url" target="_blank" rel="noopener">
        <GifMedia v-if="c.image.mp4" :src="c.image.url" :mp4="c.image.mp4" :width="c.image.width || 0" :height="c.image.height || 0" />
        <img v-else :src="c.image.url" alt="" loading="lazy" :width="c.image.width || null" :height="c.image.height || null" />
      </a>
      <p v-if="c.failed" class="failed">{{ c.failed }}</p>

      <div class="actions">
        <ReactionButton :current="c.my_reaction" :size="20" @react="react" />
        <button class="act text-btn" @click="$emit('reply', c)">{{ t._x('Reply', 'verb: reply to a comment', 'membuild-community') }}</button>
        <button v-if="canChooseBest && !reply && !pendingOrFailed" class="act text-btn" :class="{ on: c.best }" :aria-pressed="!!c.best" @click="$emit('best', c)">
          {{ c.best ? t.__('Unmark best', 'membuild-community') : t.__('Best answer', 'membuild-community') }}
        </button>
        <span v-if="copied" class="act text-btn subtle" role="status">{{ t.__('Link copied', 'membuild-community') }}</span>
        <!-- Like a group's comment on Facebook: Like · Reply, and everything else behind ⋯. -->
        <span v-if="!pendingOrFailed" :ref="menuRoot" class="more-wrap">
          <button :ref="menuTrigger" class="act more" :aria-label="t.__('Comment options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="menu" @click="toggleMenu"><Icon name="more" :size="18" /></button>
          <span v-if="menu" :ref="menuList" class="menu" :class="{ up }" role="menu" @keydown="onMenuKey">
            <button role="menuitem" @click="closeMenu(); copyLink()"><Icon name="link" :size="16" /> {{ t.__('Copy link', 'membuild-community') }}</button>
            <button v-if="feature('messages')" role="menuitem" @click="closeMenu(); sendInMessage()"><Icon name="messenger" :size="16" /> {{ t.__('Send in a message', 'membuild-community') }}</button>
            <button v-if="canSheet" role="menuitem" @click="closeMenu(); shareSheet()"><Icon name="share" :size="16" /> {{ t.__('Share…', 'membuild-community') }}</button>
            <button v-if="!isMine" role="menuitem" @click="closeMenu(); reporting = true"><Icon name="flag" :size="16" /> {{ t.__('Report comment', 'membuild-community') }}</button>
            <button v-if="isMine" role="menuitem" class="danger" @click="closeMenu(); $emit('delete', c)"><Icon name="trash" :size="16" /> {{ t.__('Delete comment', 'membuild-community') }}</button>
            <button v-else-if="cfg.community.isStaff" role="menuitem" class="danger" @click="closeMenu(); $emit('delete', c)"><Icon name="trash" :size="16" /> {{ t.__('Remove comment', 'membuild-community') }}</button>
          </span>
        </span>
        <ReactionStack v-if="c.reactions_count" class="pill" :types="c.top_reactions" :counts="c.reaction_counts" :count="c.reactions_count" :size="20" tabindex="0" />
      </div>
    </div>
    <ReportDialog v-if="reporting" type="comment" :id="c.id" @close="reporting = false" />
  </div>
</template>

<style scoped>
/* A shared link lands here: a short glow that fades. */
.comment.highlight {
  animation: flash 2.5s ease-out;
  border-radius: var(--radius-md);
}

@keyframes flash {
  0%,
  40% {
    background: var(--accent-soft);
    box-shadow: 0 0 0 6px var(--accent-soft);
  }
  100% {
    background: transparent;
    box-shadow: 0 0 0 6px transparent;
  }
}

.staff {
  padding: 0 7px;
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--fs-xs);
  font-weight: 700;
}

.best {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 1px 8px 1px 6px;
  border-radius: var(--radius-pill);
  background: var(--ok);
  color: var(--on-color);
  font-size: var(--fs-xs);
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
  font-size: var(--fs-md);
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
  font-size: var(--fs-md);
}

.by-author {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.mini {
  font-size: var(--fs-xs);
}

.text {
  margin-top: 2px;
  font-size: var(--fs-lg);
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
  font-size: var(--fs-xs);
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
  border-radius: var(--radius-xs);
  color: var(--text-2);
  font-weight: 600;
  font-size: var(--fs-md);
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

/* A GIF sits at the same size as a comment photo, like a sticker in Facebook comments. */
.pic.gif {
  max-width: 100%;
  border-radius: var(--radius);
  overflow: hidden;
}

.pic img {
  display: block;
  max-width: min(240px, 100%);
  max-height: 240px;
  width: auto;
  height: auto;
  border-radius: var(--radius);
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
  border-radius: var(--radius-sm);
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-pop);
}

.menu.up {
  top: auto;
  bottom: 100%;
}

.menu button {
  display: flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  padding: 8px 10px;
  border-radius: var(--radius-xs);
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
