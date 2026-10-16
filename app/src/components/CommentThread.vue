<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { updatePost } from '../stores/feed.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import CommentItem from './CommentItem.vue'
import EmojiPicker from './EmojiPicker.vue'
const GifPicker = defineAsyncComponent(() => import('./GifPicker.vue'))
import { startAttachment, startGif } from '../lib/attachments.js'
import { giphyOn } from '../lib/giphy.js'
import { around, t } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { useMenu } from '../lib/useMenu.js'

const props = defineProps({
  post: { type: Object, required: true },
  dark: { type: Boolean, default: false },
  autofocus: { type: Boolean, default: false },
})

const SORTS = { relevant: t.__('Most relevant', 'membuild-community'), newest: t.__('Newest', 'membuild-community') }
const sort = ref('relevant')
const { open: sortOpen, root: sortRoot, trigger: sortTrigger, list: sortList, toggle: toggleSort, close: closeSort, onKeydown: onSortKey } = useMenu()
const items = ref([])
const page = ref(1)
const hasMore = ref(false)
const loading = ref(true)
const draft = ref('')
const replyTarget = ref(null) // comment being answered
const input = ref(null)
const photo = ref(null) // one photo per comment, uploading while you type
const photoInput = ref(null)
const showEmoji = ref(false)
const emojiWrap = ref(null)
let tempId = 0

const replyingTo = computed(() => around(/* translators: %s: the name of the person being replied to */ t.__('Replying to %s', 'membuild-community')))
const placeholder = computed(() =>
  replyTarget.value
    ? /* translators: %s: the name of the person being replied to */
      t.sprintf(t.__('Reply to %s…', 'membuild-community'), replyTarget.value.author?.name)
    : /* translators: %s: the name of the person writing the comment */
      t.sprintf(t.__('Comment as %s', 'membuild-community'), cfg.user.name),
)

const canSend = computed(() => (draft.value.trim() || photo.value) && photo.value?.status !== 'working' && photo.value?.status !== 'error')

function pickPhoto(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file?.type.startsWith('image/')) return
  photo.value?.cancel()
  photo.value = startAttachment(file)
  nextTick(() => input.value?.focus())
}

const showGifs = ref(false)
function addGif(gif) {
  photo.value?.cancel()
  photo.value = startGif(gif)
  nextTick(() => input.value?.focus())
}

function removePhoto() {
  photo.value?.cancel()
  photo.value = null
}

// Emoji go in where the cursor is, like typing them.
function insertEmoji(e) {
  const el = input.value
  const at = el ? el.selectionStart : draft.value.length
  draft.value = draft.value.slice(0, at) + e + draft.value.slice(el ? el.selectionEnd : at)
  nextTick(() => {
    el?.focus()
    el?.setSelectionRange(at + e.length, at + e.length)
  })
}

function closeEmoji(e) {
  if (showEmoji.value && !emojiWrap.value?.contains(e.target)) showEmoji.value = false
}
onMounted(() => document.addEventListener('pointerdown', closeEmoji))
onBeforeUnmount(() => document.removeEventListener('pointerdown', closeEmoji))

// Each top-level comment carries its own (collapsed) reply list.
const withThread = (c) => reactive({ ...c, replies: c.replies || [], showReplies: false, repliesLoaded: false })

const latest = useLatest()
const loadError = ref('')
async function load(reset = true) {
  const current = latest() // switching sort mid-load: only the newest answer counts
  loading.value = true
  loadError.value = ''
  try {
    const res = await api.comments(props.post.id, { sort: sort.value, page: reset ? 1 : page.value })
    if (!current()) return
    const fresh = res.items.map(withThread)
    items.value = reset ? fresh : [...items.value, ...fresh]
    page.value = res.next_page || page.value
    hasMore.value = !!res.next_page
  } catch (err) {
    if (current()) loadError.value = err.message
  } finally {
    if (current()) loading.value = false
  }
}

watch(sort, () => {
  closeSort()
  load(true)
})

onMounted(() => {
  load(true)
  if (props.autofocus) nextTick(() => input.value?.focus())
})

async function toggleReplies(c) {
  c.showReplies = !c.showReplies
  if (c.showReplies && !c.repliesLoaded) {
    try {
      const res = await api.replies(c.id)
      // Keep any replies posted in this session that the server list doesn't have yet.
      const local = c.replies.filter((r) => r.pending)
      c.replies = [...res.items, ...local]
      c.repliesLoaded = true
    } catch (err) {
      c.showReplies = false // collapsed again, so "View replies" can be tried once more
      alert(err.message)
    }
  }
}

function startReply(target) {
  replyTarget.value = target
  nextTick(() => input.value?.focus())
}

function topLevelOf(target) {
  return target.parent_id ? items.value.find((c) => c.id === target.parent_id) : target
}

// The comment appears straight away and is swapped for the saved one when the server answers.
async function send() {
  const content = draft.value.trim()
  if (!canSend.value) return
  const pic = photo.value
  const target = replyTarget.value
  const top = target ? topLevelOf(target) : null
  draft.value = ''
  photo.value = null
  showEmoji.value = false
  replyTarget.value = null

  const temp = reactive({
    id: `tmp-${++tempId}`,
    content,
    image: pic ? { url: pic.previewUrl, width: pic.width, height: pic.height } : null,
    author: cfg.user,
    reply_to: target ? target.author : null,
    parent_id: top?.id || 0,
    created_at: new Date().toISOString(),
    reactions_count: 0,
    replies_count: 0,
    top_reactions: [],
    my_reaction: null,
    pending: true,
  })

  if (top) {
    top.replies.push(temp)
    top.showReplies = true
    top.replies_count++
  } else {
    items.value.unshift(withThread(temp))
  }
  updatePost(props.post.id, (p) => ({ comments_count: p.comments_count + 1 }))

  try {
    if (pic) await pic.promise
    if (pic && pic.status !== 'done') throw new Error(pic.error || t.__('The photo failed to upload.', 'membuild-community'))
    const saved = await api.createComment(props.post.id, content, {
      parentId: target?.id,
      replyToUserId: target?.author?.id,
      mediaId: pic?.media.id,
    })
    // The temporary comment may be gone (the list was reloaded meanwhile): then the saved one is added.
    if (top) {
      const at = top.replies.indexOf(temp)
      at === -1 ? top.replies.push(saved) : top.replies.splice(at, 1, saved)
    } else {
      const at = items.value.findIndex((c) => c.id === temp.id)
      at === -1 ? items.value.unshift(withThread(saved)) : items.value.splice(at, 1, withThread(saved))
    }
    pic?.release()
    pointsMayHaveChanged()
  } catch (err) {
    temp.pending = false
    temp.failed = err.message
    updatePost(props.post.id, (p) => ({ comments_count: Math.max(0, p.comments_count - 1) }))
  }
}

async function remove(c) {
  if (!confirm(t.__('Delete this comment?', 'membuild-community'))) return
  const top = topLevelOf(c)
  const isReply = !!(c.parent_id && top)
  // What it looked like before, to put back if the server refuses.
  const before = isReply ? { replies: top.replies, count: top.replies_count } : { at: items.value.indexOf(c) }
  const removed = isReply ? 1 : 1 + c.replies_count
  if (isReply) {
    top.replies = top.replies.filter((r) => r !== c)
    top.replies_count = Math.max(0, top.replies_count - 1)
  } else {
    items.value = items.value.filter((x) => x !== c)
  }
  updatePost(props.post.id, (p) => ({ comments_count: Math.max(0, p.comments_count - removed) }))
  try {
    await api.deleteComment(c.id)
  } catch (err) {
    if (isReply) {
      top.replies = before.replies
      top.replies_count = before.count
    } else if (!items.value.includes(c)) {
      items.value.splice(Math.max(0, Math.min(before.at, items.value.length)), 0, c)
    }
    updatePost(props.post.id, (p) => ({ comments_count: p.comments_count + removed }))
    alert(err.message)
  }
}

function onKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  } else if (e.key === 'Escape' && replyTarget.value) {
    e.stopPropagation()
    replyTarget.value = null
  }
}

defineExpose({ focus: () => input.value?.focus() })
const viewReplies = (n) => t.sprintf(/* translators: %d: number of replies */ t._n('View %d reply', 'View %d replies', n, 'membuild-community'), n)
</script>

<template>
  <div class="thread" :class="{ dark }">
    <div :ref="sortRoot" class="sort">
      <button :ref="sortTrigger" class="sort-btn" aria-haspopup="menu" :aria-expanded="sortOpen" @click="toggleSort">
        {{ SORTS[sort] }} <Icon name="chevronDown" :size="16" />
      </button>
      <div v-if="sortOpen" :ref="sortList" class="sort-menu" role="menu" @keydown="onSortKey">
        <button v-for="(label, key) in SORTS" :key="key" role="menuitemradio" :aria-checked="sort === key" :class="{ on: sort === key }" @click="sort === key ? closeSort() : (sort = key)">
          {{ label }}
        </button>
      </div>
    </div>

    <div v-if="loading && !items.length" class="loading">
      <div v-for="n in 3" :key="n" class="sk-row">
        <div class="skeleton" style="width: 36px; height: 36px; border-radius: 50%" />
        <div style="flex: 1">
          <div class="skeleton" style="width: 35%; height: 12px; margin-bottom: 8px" />
          <div class="skeleton" style="width: 75%; height: 14px" />
        </div>
      </div>
    </div>
    <p v-else-if="loadError && !items.length" class="empty">{{ loadError }} <button class="retry" @click="load(true)">{{ t.__('Try again', 'membuild-community') }}</button></p>
    <p v-else-if="!items.length" class="empty">{{ t.__('No comments yet. Be the first to comment.', 'membuild-community') }}</p>

    <div v-for="c in items" :key="c.id" class="item">
      <CommentItem :comment="c" @reply="startReply" @delete="remove" />

      <div v-if="c.replies_count || c.replies.length" class="replies">
        <button v-if="!c.showReplies" class="view-replies" @click="toggleReplies(c)">
          <Icon name="chevronDown" :size="16" /> {{ viewReplies(c.replies_count) }}
        </button>
        <template v-else>
          <CommentItem v-for="r in c.replies" :key="r.id" :comment="r" reply @reply="startReply" @delete="remove" />
          <button class="view-replies" @click="toggleReplies(c)">{{ t.__('Hide replies', 'membuild-community') }}</button>
        </template>
      </div>
    </div>

    <button v-if="hasMore" class="more" :disabled="loading" @click="load(false)">{{ t.__('View more comments', 'membuild-community') }}</button>

    <p v-if="cfg.community.restriction" class="composer muted-note">{{ t.__("You're muted, so you can't comment right now.", 'membuild-community') }}</p>
    <form v-else class="composer" @submit.prevent="send">
      <div v-if="replyTarget" class="replying">
        {{ replyingTo[0] }}<strong>{{ replyTarget.author?.name }}</strong>{{ replyingTo[1] }}
        <button type="button" :aria-label="t.__('Cancel reply', 'membuild-community')" @click="replyTarget = null"><Icon name="close" :size="14" /></button>
      </div>
      <div class="row">
        <Avatar :user="cfg.user" :size="32" />
        <div class="field">
          <textarea
            v-auto-grow
            ref="input"
            v-model="draft"
            rows="1"
            :placeholder="placeholder"
            :aria-label="t.__('Write a comment', 'membuild-community')"
            @keydown="onKey"
          />
          <div v-if="photo" class="photo">
            <img :src="photo.previewUrl" alt="" />
            <i v-if="photo.status === 'working'" class="bar" :style="{ transform: `scaleX(${photo.progress})` }" />
            <button type="button" :aria-label="t.__('Remove photo', 'membuild-community')" @click="removePhoto"><Icon name="close" :size="14" /></button>
            <small v-if="photo.status === 'error'" class="photo-err">{{ photo.error }}</small>
          </div>
          <div class="tools">
            <div ref="emojiWrap" class="emoji-wrap">
              <button type="button" class="tool" :class="{ on: showEmoji }" :aria-label="t.__('Insert an emoji', 'membuild-community')" :title="t.__('Emoji', 'membuild-community')" :aria-expanded="showEmoji" @click="showEmoji = !showEmoji">
                <Icon name="smile" :size="20" />
              </button>
              <EmojiPicker v-if="showEmoji" class="emoji-pop" @pick="insertEmoji" />
            </div>
            <button type="button" class="tool" :aria-label="t.__('Attach a photo', 'membuild-community')" :title="t.__('Photo', 'membuild-community')" @click="photoInput.click()"><Icon name="camera" :size="20" /></button>
            <input ref="photoInput" class="sr-only" type="file" accept="image/*" @change="pickPhoto" />
            <button v-if="giphyOn()" type="button" class="tool" :aria-label="t.__('Add a GIF', 'membuild-community')" :title="t.__('GIF', 'membuild-community')" @click="showGifs = true"><span class="gif-badge" aria-hidden="true">GIF</span></button>
            <GifPicker v-if="showGifs" @pick="addGif" @close="showGifs = false" />
            <button type="submit" class="send" :disabled="!canSend" :aria-label="t.__('Send', 'membuild-community')"><Icon name="send" :size="18" /></button>
          </div>
        </div>
      </div>
    </form>
  </div>
</template>

<style scoped>
.thread {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.sort {
  position: relative;
  align-self: flex-start;
}

.sort-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-weight: 650;
  color: var(--text-2);
  font-size: 15px;
}

.sort-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  z-index: 10;
  min-width: 180px;
  padding: 6px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  box-shadow: 0 8px 24px rgb(0 0 0 / 22%);
}

.sort-menu button {
  display: block;
  width: 100%;
  text-align: left;
  padding: 8px 10px;
  border-radius: 8px;
  font-weight: 600;
}

.sort-menu button:hover {
  background: var(--surface-2);
}

.sort-menu button.on {
  color: var(--accent-text);
}

.sk-row {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}

.empty {
  margin: 0;
  color: var(--text-3);
}

.replies {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 4px 0 0 46px;
}

.view-replies,
.more {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  font-weight: 650;
  font-size: 15px;
  color: var(--text-2);
}

.view-replies:hover,
.more:hover {
  text-decoration: underline;
}

.composer {
  position: sticky;
  bottom: 0;
  z-index: 5; /* above comments scrolling underneath it */
  margin: 0 -16px -16px;
  padding: 10px 16px 14px;
  background: var(--surface);
  border-top: 1px solid var(--border);
}

.muted-note {
  margin-bottom: -16px;
  color: var(--text-3);
  font-size: 14px;
  text-align: center;
}

.replying {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0 0 8px 42px;
  font-size: 13px;
  color: var(--text-2);
}

.replying button {
  display: grid;
  place-items: center;
  color: var(--text-3);
}

.row {
  display: flex;
  gap: 10px;
  align-items: flex-start;
}

/* Facebook's comment box: the text on top, a row of tools underneath. */
.field {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  background: var(--surface-2);
  border-radius: 18px;
  padding: 2px 6px 4px 14px;
}

.retry {
  color: var(--accent-text);
  font-weight: 650;
}

.field textarea {
  width: 100%;
  resize: none;
  border: 0;
  background: transparent;
  min-height: 36px;
  max-height: 140px;
  padding: 9px 8px 2px 0;
  field-sizing: content;
}

.tools {
  display: flex;
  align-items: center;
  gap: 2px;
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

.emoji-wrap {
  position: relative;
}

.emoji-pop {
  position: absolute;
  left: 0;
  bottom: calc(100% + 6px);
  z-index: 10;
}

.photo {
  position: relative;
  align-self: flex-start;
  margin: 6px 0 4px;
  border-radius: 10px;
  overflow: hidden;
}

.photo img {
  display: block;
  max-width: 160px;
  max-height: 120px;
  object-fit: cover;
}

.photo .bar {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 3px;
  background: var(--accent);
  transform-origin: left;
}

.photo button {
  position: absolute;
  top: 4px;
  right: 4px;
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: rgb(0 0 0 / 60%);
  color: #fff;
}

.photo-err {
  display: block;
  padding: 4px 6px;
  color: var(--danger);
  font-size: 12px;
}

.field textarea:focus {
  outline: none;
}

/* The textarea has no box of its own, so the rounded field shows focus. */
.field:focus-within {
  box-shadow: 0 0 0 1.5px var(--accent);
}

.send {
  margin-left: auto;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  color: var(--accent-text);
}

.send:disabled {
  color: var(--text-3);
}

/* Reels comment sheet */
.dark {
  --surface: #1c1c1f;
  --surface-2: #2a2b2f;
  --border: #2c2c30;
  --text: #f2f2f4;
  --text-2: #b9b9c0;
  --text-3: #8e8e96;
}
</style>
