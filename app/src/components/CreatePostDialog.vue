<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { insertPost, removePost, replacePost, updatePost } from '../stores/feed.js'
import { startAttachment, startGif } from '../lib/attachments.js'
import { giphyOn } from '../lib/giphy.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import { BACKGROUNDS, BACKGROUND_MAX, FEELINGS } from '../lib/postStyle.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import AttachmentPreview from './AttachmentPreview.vue'
import ReactionPicker from './ReactionPicker.vue'
const GifPicker = defineAsyncComponent(() => import('./GifPicker.vue'))
import FeedEmbed from './FeedEmbed.vue'
import { t } from '../lib/i18n.js'
import { useDialog } from '../lib/useDialog.js'

/**
 * "Create post": write, add photos/video, pick a colored background for short
 * text, add a feeling, insert emoji. Opened from the feed bar.
 */
const props = defineProps({
  start: { type: String, default: '' }, // '' | 'feeling'
  files: { type: Array, default: () => [] }, // picked from the feed bar before opening
  draft: { type: Object, default: null }, // restored after a failed post
  edit: { type: Object, default: null }, // an existing post: change its words, feeling and background
  // Posting inside a course: { course: { id, title }, lesson: { id, title } | null }. Only members of the course see it.
  place: { type: Object, default: null },
})
const emit = defineEmits(['close', 'posted'])

const view = ref(props.start === 'feeling' ? 'feeling' : 'main')
const source = props.edit ? { text: props.edit.content || '', background: props.edit.background || '', feeling: props.edit.feeling || '' } : props.draft
const text = ref(source?.text || '')
const attachments = ref(props.draft?.attachments || [])
const background = ref(source?.background || '')
const feeling = ref(source?.feeling || '')
const saving = ref(false)
// When editing, the post's photos and videos stay as they are.
const keptMedia = props.edit?.media.length || 0

/* A pasted YouTube or Vimeo link shows the video right away, like Facebook's link preview. */
const VIDEO_LINK = /https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?[^\s]*v=|shorts\/|live\/|embed\/)|youtu\.be\/|vimeo\.com\/)[^\s]+/i
const link = ref(null) // { url, media, loading }
const dismissed = ref('') // the link whose preview the author closed
let linkTimer = null
watch(text, (value) => {
  if (props.edit || attachments.value.length) return
  const url = value.match(VIDEO_LINK)?.[0] || ''
  if (url === (link.value?.url || '')) return
  clearTimeout(linkTimer)
  if (!url || url === dismissed.value) return (link.value = null)
  linkTimer = setTimeout(async () => {
    link.value = { url, media: null, loading: true }
    const media = await api.embed(url).catch(() => null)
    if (link.value?.url === url) link.value = media ? { url, media, loading: false } : null
  }, 350)
})
function closeLink() {
  dismissed.value = link.value?.url || ''
  link.value = null
}
const error = ref(props.draft?.error || '')
const showBackgrounds = ref(false)
const showEmoji = ref(false)
// The emoji button opens the floating emoji panel (search, categories), anchored to it.
const emojiBtn = ref(null)
function toggleEmoji(e) {
  emojiBtn.value = e.currentTarget
  showEmoji.value = !showEmoji.value
}
const feelingQuery = ref('')
const dragging = ref(false)
const textarea = ref(null)
const fileInput = ref(null)
let localKey = 0

const firstName = cfg.user.name.split(' ')[0]
// Spoken names for the background swatches (the keys stay as they are for the server).
const BACKGROUND_NAMES = {
  sunset: t._x('sunset', 'post background', 'membuild-community'),
  ocean: t._x('ocean', 'post background', 'membuild-community'),
  forest: t._x('forest', 'post background', 'membuild-community'),
  grape: t._x('grape', 'post background', 'membuild-community'),
  peach: t._x('peach', 'post background', 'membuild-community'),
  night: t._x('night', 'post background', 'membuild-community'),
  candy: t._x('candy', 'post background', 'membuild-community'),
  lime: t._x('lime', 'post background', 'membuild-community'),
}
const placeholder = props.place
  ? t.__('Ask a question, share a win or add a tip…', 'membuild-community')
  : t.sprintf(/* translators: %s: the person's first name */ t.__("What's on your mind, %s?", 'membuild-community'), firstName)
const placeLabel = props.place?.lesson
  ? props.place.lesson.title
  : props.place && t.sprintf(/* translators: %s: course title */ t.__('%s · Discussion', 'membuild-community'), props.place.course.title)

/**
 * "<name> is feeling <emoji> <feeling>." as one translatable sentence, split
 * around its placeholders so the name and the feeling can be styled:
 * [{ text }, { slot: 1 }, …] where slot 1 is the name, 2 the emoji, 3 the feeling.
 */
const whoParts = computed(() => {
  if (!feeling.value) return [{ slot: 1 }]
  const sentence = /* translators: 1: the person's name, 2: an emoji, 3: a feeling, e.g. "happy" */ t.__('%1$s is feeling %2$s %3$s.', 'membuild-community')
  return sentence
    .split(/(%\d\$s)/)
    .filter(Boolean)
    .map((p) => (/^%\d\$s$/.test(p) ? { slot: Number(p[1]) } : { text: p }))
})
const hasVideo = computed(() => attachments.value.some((a) => a.kind === 'video'))
const failed = computed(() => attachments.value.some((a) => a.status === 'error'))
const canPost = computed(
  () => (text.value.trim() || attachments.value.length || keptMedia || feeling.value) && !failed.value && !saving.value && !link.value?.loading,
)
// Backgrounds only fit short, text-only posts.
const bgAllowed = computed(() => !attachments.value.length && !keptMedia && !link.value && text.value.length <= BACKGROUND_MAX)
const activeBg = computed(() => (bgAllowed.value && background.value ? BACKGROUNDS[background.value] : null))
const bigText = computed(() => !attachments.value.length && !keptMedia && text.value.length < 85)
const feelingList = computed(() =>
  Object.entries(FEELINGS).filter(([, f]) => f.label.includes(feelingQuery.value.trim().toLowerCase())),
)
const dirty = computed(() =>
  props.edit
    ? text.value !== source.text || feeling.value !== source.feeling || background.value !== source.background
    : !!(text.value.trim() || attachments.value.length || feeling.value),
)

function addFiles(files) {
  if (props.edit) return
  error.value = ''
  for (const file of files) {
    if (!/^(image|video)\//.test(file.type)) continue
    const isVideo = file.type.startsWith('video/')
    // Keep posts simple: up to 10 photos, or one video on its own.
    if ((isVideo && attachments.value.length) || hasVideo.value) {
      error.value = t.__('A video has to be posted on its own.', 'membuild-community')
      continue
    }
    if (attachments.value.length >= 10) {
      error.value = t.__('Up to 10 photos per post.', 'membuild-community')
      break
    }
    attachments.value.push(startAttachment(file))
  }
  if (attachments.value.length) showBackgrounds.value = false
}

const showGifs = ref(false)
function addGif(gif) {
  error.value = ''
  if (hasVideo.value) return (error.value = t.__('A video has to be posted on its own.', 'membuild-community'))
  if (attachments.value.length >= 10) return (error.value = t.__('Up to 10 photos per post.', 'membuild-community'))
  attachments.value.push(startGif(gif))
}

function pickFiles(e) {
  addFiles([...e.target.files])
  e.target.value = ''
}

function onDrop(e) {
  dragging.value = false
  addFiles([...(e.dataTransfer?.files || [])])
}

function removeAttachment(item) {
  item.cancel()
  attachments.value = attachments.value.filter((a) => a !== item)
}

function insertEmoji(emoji) {
  const el = textarea.value
  const start = el?.selectionStart ?? text.value.length
  const end = el?.selectionEnd ?? text.value.length
  text.value = text.value.slice(0, start) + emoji + text.value.slice(end)
  nextTick(() => {
    el?.focus()
    el?.setSelectionRange(start + emoji.length, start + emoji.length)
  })
}

function chooseFeeling(key) {
  feeling.value = feeling.value === key ? '' : key
  view.value = 'main'
  nextTick(() => textarea.value?.focus())
}

function close() {
  if (dirty.value && !confirm(props.edit ? t.__('Discard your changes?', 'membuild-community') : t.__('Discard this post? What you wrote will be lost.', 'membuild-community'))) return
  attachments.value.forEach((a) => a.cancel())
  emit('close')
}

/**
 * The post appears at the top of the feed straight away (using local
 * previews); any uploads still running finish in the background, then it saves.
 */
async function submit() {
  if (!canPost.value) return
  if (props.edit) return saveEdit()
  const embed = !attachments.value.length && link.value?.media ? link.value.media : null
  // A post that is just the link shows the video alone, without the bare URL.
  const content = embed && text.value.trim() === link.value.url ? '' : text.value.trim()
  const items = attachments.value
  const chosenBg = activeBg.value ? background.value : ''
  const chosenFeeling = feeling.value
  const key = `local-post-${Date.now()}-${++localKey}`

  const place = props.place
  insertPost({
    id: key,
    localKey: key,
    pending: true,
    type: 'post',
    ...(place ? { course: place.course, lesson: place.lesson, helpful_count: 0, my_helpful: false, best_comment_id: null, pin: null } : {}),
    content,
    background: chosenBg || null,
    feeling: chosenFeeling || null,
    created_at: new Date().toISOString(),
    author: cfg.user,
    media: embed ? [embed] : items.map((a) => ({ key: a.key, kind: a.kind, url: a.previewUrl, poster: a.poster, width: a.width, height: a.height })),
    reactions_count: 0,
    comments_count: 0,
    shares_count: 0,
    my_reaction: null,
    top_reactions: [],
    can_delete: false,
    preview_comment: null,
  })
  emit('posted')
  emit('close')

  try {
    await Promise.all(items.map((a) => a.promise))
    if (items.some((a) => a.status !== 'done')) throw new Error(items.find((a) => a.error)?.error || t.__('Upload failed', 'membuild-community'))
    const saved = await api.createPost({
      content,
      media_ids: embed ? [embed.id] : items.map((a) => a.media.id),
      no_embed: dismissed.value && !embed ? true : undefined,
      background: chosenBg || undefined,
      feeling: chosenFeeling || undefined,
      course_id: place?.course.id,
      lesson_id: place?.lesson?.id,
    })
    replacePost(key, saved)
    items.forEach((a) => a.release()) // the saved media shows now, not the local previews
    pointsMayHaveChanged()
  } catch (err) {
    removePost(key)
    // Reopen with everything restored, so nothing the member wrote is lost.
    window.dispatchEvent(
      new CustomEvent('mbcom:reopen-composer', {
        detail: { text: content, attachments: items, background: chosenBg, feeling: chosenFeeling, error: err.message, place },
      }),
    )
  }
}

/** Editing waits for the server, so a refused change never shows as saved. */
async function saveEdit() {
  saving.value = true
  error.value = ''
  try {
    const saved = await api.updatePost(props.edit.id, {
      content: text.value.trim(),
      feeling: feeling.value || undefined,
      background: activeBg.value ? background.value : undefined,
    })
    updatePost(props.edit.id, saved)
    Object.assign(props.edit, saved) // the copy open in a dialog, if any
    emit('close')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

// Escape steps back first: emoji picker, then the feeling list, then the dialog.
function onEscape() {
  if (showEmoji.value) showEmoji.value = false
  else if (view.value === 'feeling') view.value = 'main'
  else close()
}
const panel = ref(null)
useDialog(panel, { onClose: onEscape })

function onKey(e) {
  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submit()
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  if (props.files.length) addFiles(props.files)
  nextTick(() => view.value === 'main' && textarea.value?.focus())
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
const keptLabel = (n) => t.sprintf(/* translators: %d: number of photos already on the post */ t._n('The %d photo stays as it is.', 'The %d photos stay as they are.', n, 'membuild-community'), n)
</script>

<template>
  <Teleport to="body">
    <div class="backdrop" @mousedown.self="close">
      <div
        ref="panel"
        class="dialog"
        :class="{ dragging }"
        role="dialog"
        aria-modal="true"
        :aria-label="edit ? t.__('Edit post', 'membuild-community') : t.__('Create post', 'membuild-community')"
        @dragover.prevent="dragging = true"
        @dragleave.self="dragging = false"
        @drop.prevent="onDrop"
      >
        <!-- Main view -->
        <template v-if="view === 'main'">
          <header class="top">
            <h2>{{ edit ? t.__('Edit post', 'membuild-community') : t.__('Create post', 'membuild-community') }}</h2>
            <button class="round" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="22" /></button>
          </header>

          <div class="body">
            <div class="author">
              <Avatar :user="cfg.user" :size="44" />
              <div>
                <div class="who">
                  <template v-for="(p, i) in whoParts" :key="i">
                    <strong v-if="p.slot === 1">{{ cfg.user.name }}</strong>
                    <span v-else class="feeling-inline"
                      ><template v-if="p.slot === 2">{{ FEELINGS[feeling].emoji }}</template
                      ><button v-else-if="p.slot === 3" class="link" @click="view = 'feeling'">{{ FEELINGS[feeling].label }}</button
                      ><template v-else>{{ p.text }}</template></span
                    >
                  </template>
                </div>
                <span v-if="placeLabel" class="chip place"><Icon name="cap" :size="15" /> {{ placeLabel }}</span>
                <span v-else class="chip"><Icon name="user" :size="15" /> {{ t.__('Community members', 'membuild-community') }}</span>
              </div>
            </div>

            <div class="write" :class="{ bg: activeBg }" :style="activeBg ? { background: activeBg } : null">
              <textarea
                v-auto-grow
                ref="textarea"
                v-model="text"
                :class="{ big: bigText || activeBg }"
                :rows="activeBg ? 3 : 4"
                :placeholder="placeholder"
                :aria-label="t.__('What\'s on your mind', 'membuild-community')"
              />
            </div>

            <div class="tools">
              <button
                v-if="bgAllowed"
                class="aa"
                :class="{ open: showBackgrounds }"
                :aria-label="t.__('Background colors', 'membuild-community')"
                :aria-expanded="showBackgrounds"
                @click="showBackgrounds = !showBackgrounds"
              >
                <span v-if="!showBackgrounds">{{ t._x('Aa', 'text style button', 'membuild-community') }}</span>
                <Icon v-else name="chevronLeft" :size="20" />
              </button>
              <div v-if="showBackgrounds && bgAllowed" class="swatches" role="radiogroup" :aria-label="t.__('Background', 'membuild-community')">
                <button class="swatch none" :class="{ on: !background }" role="radio" :aria-checked="!background" :aria-label="t.__('No background', 'membuild-community')" @click="background = ''" />
                <button
                  v-for="(css, key) in BACKGROUNDS"
                  :key="key"
                  class="swatch"
                  :class="{ on: background === key }"
                  :style="{ background: css }"
                  role="radio"
                  :aria-checked="background === key"
                  :aria-label="BACKGROUND_NAMES[key] || key"
                  @click="background = key"
                />
              </div>
              <div class="emoji-wrap">
                <button class="icon-only" :aria-label="t.__('Emoji', 'membuild-community')" :aria-expanded="showEmoji" @click="toggleEmoji">
                  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <circle cx="12" cy="12" r="9.5" /><path d="M8 14.5c1 1.4 2.4 2 4 2s3-.6 4-2" stroke-linecap="round" /><circle cx="9" cy="10" r="1" fill="currentColor" stroke="none" /><circle cx="15" cy="10" r="1" fill="currentColor" stroke="none" />
                  </svg>
                </button>
                <ReactionPicker v-if="showEmoji && emojiBtn" mode="emoji" side="center" :anchor="emojiBtn.getBoundingClientRect()" :ignore="emojiBtn" @pick="insertEmoji" @close="showEmoji = false" />
              </div>
            </div>

            <div v-if="link && !attachments.length" class="link-card">
              <div v-if="link.loading" class="link-loading">{{ t.__('Loading video preview…', 'membuild-community') }}</div>
              <FeedEmbed v-else :media="link.media" />
              <button type="button" class="link-x" :aria-label="t.__('Remove video preview', 'membuild-community')" @click="closeLink"><Icon name="close" :size="16" /></button>
            </div>

            <div v-if="attachments.length" class="attachments">
              <AttachmentPreview v-for="a in attachments" :key="a.key" :item="a" @remove="removeAttachment(a)" />
              <button v-if="!hasVideo && attachments.length < 10" class="add-more" @click="fileInput.click()">
                <Icon name="plus" :size="22" /><span>{{ t._x('Add', 'add more photos', 'membuild-community') }}</span>
              </button>
            </div>

            <p v-if="keptMedia" class="kept">{{ keptMedia === 1 ? t.__('The photo or video stays as it is.', 'membuild-community') : keptLabel(keptMedia) }}</p>
            <p v-if="error" class="error">{{ error }}</p>

            <div class="add-box">
              <span>{{ t.__('Add to your post', 'membuild-community') }}</span>
              <div class="add-icons">
                <button v-if="!edit" class="add-icon" :aria-label="t.__('Photo or video', 'membuild-community')" :title="t.__('Photo/video', 'membuild-community')" @click="fileInput.click()">
                  <svg width="28" height="28" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="16" height="14" rx="2.5" fill="#2f9e5b" opacity=".55" /><rect x="2.5" y="6" width="16" height="15" rx="2.5" fill="#45bd62" /><circle cx="8" cy="11" r="1.8" fill="#fff" /><path d="M4.5 19l4.5-4.5 3 3 2.5-2.5 3 3.5" fill="none" stroke="#fff" stroke-width="1.6" stroke-linejoin="round" /></svg>
                </button>
                <button v-if="!edit && giphyOn()" class="add-icon gif" :aria-label="t.__('GIF', 'membuild-community')" :title="t.__('GIF', 'membuild-community')" @click="showGifs = true">
                  <span class="gif-badge" aria-hidden="true">GIF</span>
                </button>
                <button class="add-icon" :class="{ on: feeling }" :aria-label="t.__('Feeling or activity', 'membuild-community')" :title="t.__('Feeling/activity', 'membuild-community')" @click="view = 'feeling'">
                  <svg width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9.5" stroke="#f7932f" stroke-width="2" /><path d="M8 14c1 1.6 2.4 2.4 4 2.4s3-.8 4-2.4" stroke="#f7932f" stroke-width="2" stroke-linecap="round" /><circle cx="9" cy="9.6" r="1.3" fill="#f7932f" /><circle cx="15" cy="9.6" r="1.3" fill="#f7932f" /></svg>
                </button>
              </div>
            </div>
            <input ref="fileInput" class="sr-only" type="file" accept="image/*,video/*" multiple @change="pickFiles" />
            <GifPicker v-if="showGifs" @pick="addGif" @close="showGifs = false" />
          </div>

          <footer class="foot">
            <button class="post-btn" :disabled="!canPost || (edit && !dirty)" @click="submit">{{ edit ? (saving ? t.__('Saving…', 'membuild-community') : t.__('Save', 'membuild-community')) : t._x('Post', 'verb: publish', 'membuild-community') }}</button>
          </footer>
        </template>

        <!-- Feeling view -->
        <template v-else>
          <header class="top">
            <button class="round back" :aria-label="t.__('Back', 'membuild-community')" @click="view = 'main'"><Icon name="chevronLeft" :size="22" /></button>
            <h2>{{ t.__('How are you feeling?', 'membuild-community') }}</h2>
          </header>
          <div class="body">
            <input v-model="feelingQuery" class="search" type="search" :placeholder="t.__('Search', 'membuild-community')" :aria-label="t.__('Search feelings', 'membuild-community')" />
            <div class="feelings">
              <button v-for="[key, f] in feelingList" :key="key" class="feeling" :class="{ on: feeling === key }" @click="chooseFeeling(key)">
                <span class="f-emoji">{{ f.emoji }}</span> {{ f.label }}
              </button>
            </div>
          </div>
        </template>

        <div v-if="dragging" class="drop">{{ t.__('Drop photos or a video to add them', 'membuild-community') }}</div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.link-card {
  position: relative;
  margin-top: 10px;
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
}

.link-loading {
  padding: 22px;
  text-align: center;
  color: var(--text-3);
  font-size: 14px;
}

.link-x {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: rgb(0 0 0 / 65%);
  color: #fff;
}

.kept {
  margin: 8px 0 0;
  font-size: 13px;
  color: var(--text-3);
}

.backdrop {
  position: fixed;
  inset: 0;
  z-index: 85;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(10 11 13 / 72%);
  animation: fade 0.15s ease;
}

@keyframes fade {
  from {
    opacity: 0;
  }
}

.dialog {
  position: relative;
  display: flex;
  flex-direction: column;
  width: min(500px, 100%);
  max-height: calc(100dvh - 48px);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: 0 20px 60px rgb(0 0 0 / 40%);
}

.top {
  position: relative;
  display: grid;
  place-items: center;
  height: 60px;
  flex: none;
  border-bottom: 1px solid var(--border);
}

.top h2 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
}

.round {
  position: absolute;
  right: 14px;
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
}

.round.back {
  right: auto;
  left: 14px;
}

.body {
  padding: 16px;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.author {
  display: flex;
  gap: 10px;
  align-items: center;
}

.who {
  font-size: 15px;
  line-height: 1.3;
}

.feeling-inline {
  color: var(--text-2);
}

.link {
  font-weight: 650;
  color: var(--text);
}

.link:hover {
  text-decoration: underline;
}

.chip.place {
  max-width: 100%;
  color: var(--accent-text);
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 4px;
  padding: 3px 9px;
  border-radius: 6px;
  background: var(--surface-2);
  font-size: 13px;
  font-weight: 600;
  color: var(--text-2);
}

.write {
  margin: 14px 0 0;
  border-radius: 10px;
  transition: background 0.2s ease;
}

.write textarea {
  display: block;
  width: 100%;
  border: 0;
  resize: none;
  background: transparent;
  font-size: 16px;
  min-height: 110px;
  padding: 4px 0;
  field-sizing: content;
}

.write textarea.big {
  font-size: 24px;
  line-height: 1.3;
}

.write textarea:focus {
  outline: none;
}

.write.bg {
  display: grid;
  place-items: center;
  min-height: 300px;
  padding: 24px;
}

.write.bg textarea {
  min-height: 0;
  color: #fff;
  font-weight: 700;
  font-size: 28px;
  text-align: center;
  text-shadow: 0 1px 2px rgb(0 0 0 / 15%);
}

.write.bg textarea::placeholder {
  color: rgb(255 255 255 / 80%);
}

.tools {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  min-height: 40px;
}

.aa {
  display: grid;
  place-items: center;
  flex: none;
  width: 38px;
  height: 38px;
  border-radius: 9px;
  font-weight: 800;
  font-size: 16px;
  color: #fff;
  background: conic-gradient(from 200deg, #ff5f6d, #ffc371, #47e891, #3b9cff, #a35cff, #ff5f6d);
  box-shadow: inset 0 0 0 2px rgb(255 255 255 / 85%);
}

.aa.open {
  background: var(--surface-2);
  color: var(--text-2);
  box-shadow: none;
}

.swatches {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  scrollbar-width: none;
}

.swatch {
  flex: none;
  width: 32px;
  height: 32px;
  border-radius: 8px;
}

.swatch.none {
  background: var(--surface-2);
  box-shadow: inset 0 0 0 1px var(--border);
}

.swatch.on {
  box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--accent);
}

.emoji-wrap {
  position: relative;
  margin-left: auto;
}

.icon-only {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  color: var(--text-2);
}

.icon-only:hover {
  background: var(--surface-2);
}

.emoji-pop {
  position: absolute;
  right: 0;
  bottom: calc(100% + 6px);
  z-index: 5;
}

.attachments {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.add-more {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  width: 110px;
  height: 110px;
  border-radius: var(--radius-sm);
  border: 1.5px dashed var(--border);
  color: var(--text-2);
  font-size: 13px;
  font-weight: 600;
}

.error {
  margin: 10px 0 0;
  color: var(--danger);
  font-size: 13px;
}

.add-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 16px;
  padding: 10px 12px 10px 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  font-weight: 650;
}

.add-icons {
  display: flex;
  gap: 4px;
}

.add-icon {
  display: grid;
  place-items: center;
  width: 42px;
  height: 42px;
  border-radius: 50%;
}

.add-icon:hover,
.add-icon.on {
  background: var(--surface-2);
}

.foot {
  padding: 0 16px 16px;
  flex: none;
}

.post-btn {
  width: 100%;
  height: 44px;
  border-radius: 8px;
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 700;
  font-size: 16px;
}

.post-btn:disabled {
  background: var(--surface-2);
  color: var(--text-3);
  cursor: default;
}

.search {
  width: 100%;
  height: 38px;
  padding: 0 14px;
  border: 0;
  border-radius: 999px;
  background: var(--surface-2);
}

.feelings {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
  margin-top: 12px;
}

.feeling {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border-radius: 10px;
  text-align: left;
  font-weight: 600;
  text-transform: capitalize;
}

.feeling:hover,
.feeling.on {
  background: var(--surface-2);
}

.f-emoji {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--surface-2);
  font-size: 20px;
}

.drop {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  border-radius: 12px;
  border: 2px dashed var(--accent);
  background: color-mix(in srgb, var(--surface) 85%, transparent);
  font-weight: 700;
  pointer-events: none;
}

@media (max-width: 767px) {
  .backdrop {
    padding: 0;
  }

  .dialog {
    width: 100%;
    height: 100dvh;
    max-height: none;
    border-radius: 0;
    border: 0;
  }

  .body {
    flex: 1;
  }
}
</style>
