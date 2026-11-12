<script>
// Shared by every comment on the page.
let editFormats = null
</script>

<script setup>
import { computed, defineAsyncComponent, nextTick, ref, onBeforeUnmount, onMounted } from 'vue'
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
import ReactionPicker from './ReactionPicker.vue'
import { api } from '../api.js'
import { updatePost } from '../stores/feed.js'
import { around, locale, t } from '../lib/i18n.js'
import { useMenu } from '../lib/useMenu.js'
import { useRouter } from 'vue-router'
import { setShareDraft } from '../stores/messages.js'
import { feature } from '../config.js'
import { askConfirm } from '../lib/confirm.js'
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

// Edit comment: only your own, and only the words (a photo or GIF stays as it is).
// The comment turns into its edit box right where it is; Cancel or Escape puts it back.
const canEdit = computed(() => isMine.value && !cfg.community.restriction && c.value.can_edit !== false)
const editing = ref(false)
const editText = ref('')
const editInput = ref(null)
const saving = ref(false)
const editError = ref('')
const showEmoji = ref(false)
const emojiBtn = ref(null)
const changed = computed(() => editText.value.trim() !== (c.value.content || '').trim())
const canSave = computed(() => changed.value && (editText.value.trim() || c.value.image) && !saving.value)

function startEdit() {
  editText.value = c.value.content || ''
  editError.value = ''
  editing.value = true
  nextTick(() => {
    const el = editInput.value
    if (!el) return
    el.focus()
    el.setSelectionRange(el.value.length, el.value.length)
  })
}
// Back on the ⋯ button afterwards, so the keyboard carries on from the comment.
function endEdit() {
  editing.value = false
  showEmoji.value = false
  nextTick(() => menuButton()?.focus())
}
// Changed words are only thrown away when the owner says so, the same ask as editing a post.
let asking = false
async function cancelEdit() {
  if (saving.value || asking) return
  if (changed.value) {
    asking = true
    const discard = await askConfirm({ title: t.__('Discard your changes?', 'membuild-community'), confirm: t.__('Discard', 'membuild-community'), cancel: t.__('Keep editing', 'membuild-community'), danger: true })
    asking = false
    if (!discard) return editInput.value?.focus()
  }
  if (editing.value) endEdit()
}
// Escape in the edit box cancels just the edit. Like "cancel reply", the field claims Escape
// first (layers.js), so the post or panel underneath stays open.
function onEditEscape(e) {
  e.preventDefault()
  e.stopPropagation()
  if (showEmoji.value) showEmoji.value = false // the emoji panel first
  else cancelEdit()
}
const menuButton = () => el.value?.querySelector(':scope > .main .more')
// The same comment can show in two places at once (a post's preview in Home and the opened post).
function shareEdit(saved) {
  updatePost(c.value.post_id, (p) => {
    const pc = p.preview_comment
    for (const x of pc ? [pc, ...(pc.replies || [])] : []) if (x.id === saved.id) Object.assign(x, { content: saved.content, edited_at: saved.edited_at })
    return {}
  })
}
async function saveEdit() {
  if (!canSave.value) return
  saving.value = true
  editError.value = ''
  try {
    const saved = await api.updateComment(c.value.id, editText.value.trim())
    Object.assign(c.value, { content: saved.content, edited_at: saved.edited_at })
    shareEdit(saved)
    saving.value = false
    endEdit()
  } catch (err) {
    saving.value = false
    editError.value = err.message
  }
}
function onEditKey(e) {
  if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
    e.preventDefault()
    saveEdit()
  }
}
function toggleEmoji(e) {
  emojiBtn.value = e.currentTarget
  showEmoji.value = !showEmoji.value
}
function insertEmoji(em) {
  const el = editInput.value
  const at = el ? el.selectionStart : editText.value.length
  editText.value = editText.value.slice(0, at) + em + editText.value.slice(el ? el.selectionEnd : at)
  nextTick(() => {
    el?.focus()
    el?.setSelectionRange(at + em.length, at + em.length)
  })
}

// "Edited" after the date; pointing at it (or a long press on a phone) shows when.
// The formatters are made once, the first time any comment shows it was edited.
const fmtWhen = (iso) => {
  editFormats ??= [new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric' }), new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' })]
  const d = new Date(iso)
  return t.sprintf(/* translators: 1: a date like "Nov 11", 2: a time like "10:42 AM" */ t.__('Edited %1$s at %2$s', 'membuild-community'), editFormats[0].format(d), editFormats[1].format(d))
}
const editedWhen = computed(() => (c.value.edited_at ? fmtWhen(c.value.edited_at) : ''))
const showWhen = ref(false)
let pressTimer = 0
let hideTimer = 0
function pressEdited(e) {
  if (e.pointerType === 'mouse') return
  clearTimeout(pressTimer)
  pressTimer = setTimeout(() => {
    showWhen.value = true
    clearTimeout(hideTimer)
    hideTimer = setTimeout(() => (showWhen.value = false), 3000)
  }, 450)
}
const releaseEdited = () => clearTimeout(pressTimer)
onBeforeUnmount(() => {
  clearTimeout(pressTimer)
  clearTimeout(hideTimer)
})
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
        <template v-if="c.edited_at && !pendingOrFailed">
          <span class="dot">·</span>
          <span class="edited" :title="editedWhen" @pointerdown="pressEdited" @pointerup="releaseEdited" @pointercancel="releaseEdited" @pointerleave="releaseEdited" @contextmenu.prevent>{{ showWhen ? editedWhen : t.__('Edited', 'membuild-community') }}</span>
        </template>
        <template v-if="c.best">
          <span class="dot">·</span>
          <span class="best"><Icon name="check" :size="13" /> {{ t.__('Best answer', 'membuild-community') }}</span>
        </template>
        <template v-if="c.liked_by_author">
          <span class="dot">·</span>
          <span class="by-author">{{ byAuthor[0] }}<span class="mini">{{ emojiFor(c.liked_by_author) }}</span>{{ byAuthor[1] }}</span>
        </template>
      </div>

      <form v-if="editing" class="edit" @submit.prevent="saveEdit" @keydown.esc="onEditEscape">
        <div class="edit-field">
          <textarea
            ref="editInput"
            v-model="editText"
            v-auto-grow
            rows="1"
            maxlength="2000"
            :aria-label="t.__('Edit your comment', 'membuild-community')"
            @keydown="onEditKey"
          />
          <div class="edit-tools">
            <button ref="emojiBtn" type="button" class="tool" :class="{ on: showEmoji }" :aria-label="t.__('Insert an emoji', 'membuild-community')" :title="t.__('Emoji', 'membuild-community')" :aria-expanded="showEmoji" @click="toggleEmoji">
              <Icon name="smile" :size="20" />
            </button>
            <ReactionPicker v-if="showEmoji && emojiBtn" mode="emoji" side="center" :anchor="emojiBtn.getBoundingClientRect()" :ignore="emojiBtn" @pick="insertEmoji" @close="showEmoji = false" />
          </div>
        </div>
        <p v-if="editError" class="error-text" role="alert">{{ editError }}</p>
        <div class="edit-buttons">
          <button type="button" class="btn btn-ghost" :disabled="saving" @click="cancelEdit">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button type="submit" class="btn btn-primary" :disabled="!canSave">{{ saving ? t.__('Saving…', 'membuild-community') : t.__('Save', 'membuild-community') }}</button>
        </div>
      </form>
      <div v-else-if="c.content || c.reply_to" class="text">
        <RouterLink v-if="c.reply_to" class="mention" :to="{ name: 'member', params: { id: c.reply_to.id } }">{{ c.reply_to.name }}</RouterLink>
        <RichText :text="c.content" class="inline" />
      </div>
      <a v-if="c.image" class="pic" :class="{ gif: c.image.mp4 }" :style="c.image.mp4 ? gifBox(c.image) : null" :href="c.image.url" target="_blank" rel="noopener">
        <GifMedia v-if="c.image.mp4" :src="c.image.url" :mp4="c.image.mp4" :width="c.image.width || 0" :height="c.image.height || 0" />
        <img v-else :src="c.image.url" alt="" loading="lazy" :width="c.image.width || null" :height="c.image.height || null" />
      </a>
      <p v-if="c.failed" class="failed">{{ c.failed }}</p>

      <div v-if="!editing" class="actions">
        <ReactionButton :current="c.my_reaction" :size="20" @react="react" />
        <button class="act text-btn tap-44" @click="$emit('reply', c)">{{ t._x('Reply', 'verb: reply to a comment', 'membuild-community') }}</button>
        <button v-if="canChooseBest && !reply && !pendingOrFailed" class="act text-btn" :class="{ on: c.best }" :aria-pressed="!!c.best" @click="$emit('best', c)">
          {{ c.best ? t.__('Unmark best', 'membuild-community') : t.__('Best answer', 'membuild-community') }}
        </button>
        <span v-if="copied" class="act text-btn subtle" role="status">{{ t.__('Link copied', 'membuild-community') }}</span>
        <!-- Like a group's comment on Facebook: Like · Reply, and everything else behind ⋯. -->
        <span v-if="!pendingOrFailed" :ref="menuRoot" class="more-wrap">
          <button :ref="menuTrigger" class="act more tap-44" :aria-label="t.__('Comment options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="menu" @click="toggleMenu"><Icon name="more" :size="18" /></button>
          <span v-if="menu" :ref="menuList" class="menu" :class="{ up }" role="menu" @keydown="onMenuKey">
            <button role="menuitem" @click="closeMenu(); copyLink()"><Icon name="link" :size="16" /> {{ t.__('Copy link', 'membuild-community') }}</button>
            <button v-if="feature('messages')" role="menuitem" @click="closeMenu(); sendInMessage()"><Icon name="messenger" :size="16" /> {{ t.__('Send in a message', 'membuild-community') }}</button>
            <button v-if="canSheet" role="menuitem" @click="closeMenu(); shareSheet()"><Icon name="share" :size="16" /> {{ t.__('Share…', 'membuild-community') }}</button>
            <button v-if="!isMine" role="menuitem" @click="closeMenu(); reporting = true"><Icon name="flag" :size="16" /> {{ t.__('Report comment', 'membuild-community') }}</button>
            <button v-if="canEdit" role="menuitem" @click="closeMenu(); startEdit()"><Icon name="pencil" :size="16" /> {{ t.__('Edit comment', 'membuild-community') }}</button>
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
/* Edit comment: the comment's words in a box like the comment box, Cancel and Save under it. */
.edit {
  margin-top: 4px;
}

.edit-field {
  display: flex;
  flex-direction: column;
  background: var(--surface-2);
  border-radius: var(--radius-xl);
  padding: 2px 6px 4px 14px;
}

.edit-field:focus-within {
  box-shadow: 0 0 0 2px var(--accent);
}

.edit-field textarea {
  width: 100%;
  resize: none;
  border: 0;
  background: transparent;
  min-height: 36px;
  max-height: 220px;
  padding: 9px 8px 2px 0;
  font-size: var(--fs-base);
  line-height: 1.4;
  field-sizing: content;
}

.edit-field textarea:focus {
  outline: none;
}

.edit-tools {
  position: relative;
  display: flex;
  align-items: center;
  margin-left: -8px;
}

.tool {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  color: var(--text-3);
}

.tool:hover,
.tool.on {
  background: color-mix(in srgb, var(--text) 8%, transparent);
  color: var(--text-2);
}

.edit .error-text {
  margin: 6px 0 0;
}

.edit-buttons {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

.edited {
  cursor: default;
  -webkit-touch-callout: none;
  user-select: none;
}

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
  background: var(--ok-strong); /* white words on it: 5:1 */
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
  display: inline-flex;
  align-items: center;
  min-height: 24px; /* tall enough to tap */
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
  font-size: var(--fs-base);
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
