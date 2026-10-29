<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { api } from '../api.js'
import { startAttachment } from '../lib/attachments.js'
import { cfg } from '../config.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
import { useDialog } from '../lib/useDialog.js'

/**
 * Create a reel, full screen like Facebook: on the left who's posting, then
 * Add a video (or share a YouTube Short underneath); on the right a live
 * Preview with the caption on it; Discard and Share reel at the bottom.
 */
const props = defineProps({ file: { type: Object, default: null } })
const emit = defineEmits(['close', 'posted'])

const panel = ref(null)
const fileInput = ref(null)
const mode = ref('choose') // choose | upload | link
const item = ref(null) // the upload (startAttachment)
const caption = ref('')
const seconds = ref(0) // the picked video's length
const dragging = ref(false)
const error = ref('')
const sharing = ref(false)

// A YouTube Short: the link, what YouTube says about it, and the media row it makes.
const link = ref('')
const found = ref(null) // media with .embed
const looking = ref(false)
const linkError = ref('')

function pick(file) {
  if (!file) return
  if (!file.type.startsWith('video/')) {
    error.value = t.__('Choose a video file.', 'membuild-community')
    return
  }
  error.value = ''
  item.value?.cancel()
  item.value = startAttachment(file)
  seconds.value = 0
  mode.value = 'upload'
  // Its length, for "0:42 long".
  const probe = document.createElement('video')
  probe.preload = 'metadata'
  probe.onloadedmetadata = () => (seconds.value = probe.duration || 0)
  probe.src = item.value.previewUrl
}
function picked(e) {
  pick(e.target.files[0])
  e.target.value = ''
}
function dropped(e) {
  dragging.value = false
  pick(e.dataTransfer?.files?.[0])
}

function useLink() {
  mode.value = 'link'
  error.value = ''
}
function useUpload() {
  link.value = ''
  found.value = null
  linkError.value = ''
  mode.value = 'choose'
}

// Look the link up a moment after it's pasted or typed.
let timer = 0
let asked = 0
watch(link, (url) => {
  clearTimeout(timer)
  found.value = null
  linkError.value = ''
  if (!/youtu\.?be/i.test(url)) {
    if (url.trim()) linkError.value = t.__('Paste a link to a YouTube Short or video.', 'membuild-community')
    return
  }
  timer = setTimeout(async () => {
    const mine = ++asked
    looking.value = true
    try {
      const media = await api.embed(url.trim())
      if (mine === asked) found.value = media
    } catch (err) {
      if (mine === asked) linkError.value = err.message
    } finally {
      if (mine === asked) looking.value = false
    }
  }, 500)
})
onBeforeUnmount(() => clearTimeout(timer))

const canShare = computed(() => (mode.value === 'upload' ? item.value && item.value.status !== 'error' : mode.value === 'link' ? !!found.value : false))
const length = computed(() => {
  const s = Math.round(seconds.value)
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
})
const foundLine = computed(() =>
  found.value
    ? t.sprintf(/* translators: 1: video title, 2: who made it */ t.__('“%1$s” by %2$s. It plays here with their name on it.', 'membuild-community'), found.value.embed.title, found.value.embed.author || 'YouTube')
    : '',
)

async function share() {
  if (!canShare.value || sharing.value) return
  sharing.value = true
  error.value = ''
  try {
    let mediaId
    if (mode.value === 'upload') {
      await item.value.promise
      if (item.value.status !== 'done') throw new Error(item.value.error || t.__('Upload failed', 'membuild-community'))
      mediaId = item.value.media.id
    } else {
      mediaId = found.value.id
    }
    const saved = await api.createPost({ type: 'reel', content: caption.value.trim(), media_ids: [mediaId] })
    item.value?.release()
    emit('posted', saved)
  } catch (err) {
    error.value = err.message
    sharing.value = false
  }
}

async function close() {
  const started = item.value || found.value || caption.value.trim()
  if (started && !(await askConfirm({ title: t.__('Discard this reel?', 'membuild-community'), text: t.__('Your video and caption won’t be saved.', 'membuild-community'), confirm: t.__('Discard', 'membuild-community'), cancel: t.__('Keep editing', 'membuild-community'), danger: true }))) return
  item.value?.cancel()
  emit('close')
}

useDialog(panel, { onClose: close })
onMounted(() => props.file && pick(props.file))
</script>

<template>
  <Teleport to="body">
    <div ref="panel" class="creator" role="dialog" aria-modal="true" :aria-label="t.__('Create a reel', 'membuild-community')">
      <aside class="left">
        <button class="round round-btn lg" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="22" /></button>
        <div class="me">
          <Avatar :user="cfg.user" :size="64" />
          <span><strong>{{ t.__('Create a reel', 'membuild-community') }}</strong><small>{{ cfg.user.name }}</small></span>
        </div>

        <div class="editor">
          <template v-if="mode === 'choose'">
            <div class="drop" :class="{ over: dragging }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="dropped">
              <span class="d-icon"><Icon name="upload" :size="26" /></span>
              <strong>{{ t.__('Add a video', 'membuild-community') }}</strong>
              <small>{{ t.__('Up to 60 seconds · or drag it here', 'membuild-community') }}</small>
              <button type="button" class="btn-share" @click="fileInput.click()">{{ t.__('Choose a video', 'membuild-community') }}</button>
            </div>
            <button type="button" class="text-link" @click="useLink"><Icon name="link" :size="18" /> {{ t.__('Or share a YouTube Short', 'membuild-community') }}</button>
            <p class="stays"><Icon name="film" :size="18" /> {{ t.__('A short video for the community, up to 60 seconds. Tall videos look best.', 'membuild-community') }}</p>
          </template>

          <template v-else-if="mode === 'upload'">
            <label class="label" for="reel-caption">{{ t.__('Caption', 'membuild-community') }}</label>
            <textarea id="reel-caption" v-model="caption" rows="4" maxlength="2200" :placeholder="t.__('Write a caption…', 'membuild-community')" />
            <div v-if="item.status === 'working'" class="upload">
              <span><strong>{{ t.__('Uploading your video…', 'membuild-community') }}</strong><b>{{ Math.round(item.progress * 100) }}%</b></span>
              <i><i :style="{ transform: `scaleX(${item.progress})` }" /></i>
            </div>
            <p v-else-if="item.status === 'done'" class="ready"><Icon name="check" :size="18" /> {{ t.__('Your video is uploaded.', 'membuild-community') }}</p>
            <p v-else class="error">{{ item.error }}</p>
            <small v-if="seconds" class="note">{{ item.status === 'working' ? t.sprintf(/* translators: %s: video length, e.g. 0:42 */ t.__('%s long · you can write your caption while it uploads.', 'membuild-community'), length) : t.sprintf(/* translators: %s: video length, e.g. 0:42 */ t.__('%s long', 'membuild-community'), length) }}</small>
            <button type="button" class="btn-grey" @click="fileInput.click()"><Icon name="film" :size="18" /> {{ t.__('Choose a different video', 'membuild-community') }}</button>
          </template>

          <template v-else>
            <label class="label" for="reel-link">{{ t.__('YouTube Short link', 'membuild-community') }}</label>
            <input id="reel-link" v-model="link" type="url" inputmode="url" autofocus :placeholder="t.__('Paste the link here', 'membuild-community')" />
            <p v-if="looking" class="note">{{ t.__('Looking it up…', 'membuild-community') }}</p>
            <p v-else-if="found" class="found"><Icon name="check" :size="18" /> <span><strong>{{ t.__('Found it:', 'membuild-community') }}</strong> {{ foundLine }}</span></p>
            <p v-else-if="linkError" class="error">{{ linkError }}</p>
            <label class="label" for="reel-link-caption">{{ t.__('Caption', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></label>
            <input id="reel-link-caption" v-model="caption" maxlength="2200" :placeholder="t.__('Why you’re sharing it…', 'membuild-community')" />
            <button type="button" class="text-link left-link" @click="useUpload">{{ t.__('Upload your own video instead', 'membuild-community') }}</button>
          </template>

          <p v-if="error" class="error">{{ error }}</p>
        </div>

        <footer class="actions">
          <button type="button" class="btn-grey" @click="close">{{ t.__('Discard', 'membuild-community') }}</button>
          <button type="button" class="btn-share" :disabled="!canShare || sharing" @click="share">{{ sharing ? (item?.status === 'working' ? t.__('Finishing upload…', 'membuild-community') : t.__('Sharing…', 'membuild-community')) : t.__('Share reel', 'membuild-community') }}</button>
        </footer>
      </aside>

      <main class="right">
        <section class="preview-card">
          <h2>{{ t.__('Preview', 'membuild-community') }}</h2>
          <div class="stage">
            <div class="reel">
              <video v-if="mode === 'upload' && item" :src="item.previewUrl" muted playsinline autoplay loop />
              <img v-else-if="mode === 'link' && found?.poster" :src="found.poster" alt="" />
              <p v-else class="empty">{{ t.__('Your video shows here', 'membuild-community') }}</p>
              <div v-if="(mode === 'upload' && item) || (mode === 'link' && found)" class="shade">
                <strong>{{ cfg.user.name }}</strong>
                <span v-if="caption.trim()">{{ caption }}</span>
                <small v-if="mode === 'link'">{{ t.sprintf(/* translators: %s: who made the video */ t.__('From YouTube · %s', 'membuild-community'), found.embed.author || 'YouTube') }}</small>
              </div>
            </div>
          </div>
        </section>
      </main>

      <input ref="fileInput" class="sr-only" type="file" accept="video/*" @change="picked" />
    </div>
  </Teleport>
</template>

<style scoped>
.creator {
  position: fixed;
  inset: 0;
  z-index: 95;
  display: grid;
  grid-template-columns: 400px 1fr;
  background: var(--bg);
  color: var(--text);
}

.left {
  display: flex;
  flex-direction: column;
  min-height: 0;
  border-right: 1px solid var(--border);
  background: var(--surface);
}

.round {
  margin: 14px 0 0 18px;
}

.me {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px 22px 20px;
  border-bottom: 1px solid var(--border);
}

.me span {
  display: flex;
  flex-direction: column;
}

.me strong {
  font-size: var(--fs-6xl);
  line-height: 1.2;
}

.me small {
  font-size: var(--fs-lg);
}

.editor {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 10px;
  min-height: 0;
  overflow-y: auto;
  padding: 22px;
}

.drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 28px 18px;
  border: 2px dashed var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-2);
  text-align: center;
}

.drop.over {
  border-color: var(--accent-text);
  background: var(--accent-soft);
}

.d-icon {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
}

.drop strong {
  font-size: var(--fs-2xl);
}

.drop small {
  margin-bottom: 8px;
  font-size: var(--fs-base);
  color: var(--text-2);
}

.text-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 44px;
  font-size: var(--fs-lg);
  font-weight: 700;
  color: var(--text);
}

.text-link.left-link {
  justify-content: flex-start;
}

.text-link:hover {
  text-decoration: underline;
}

.label {
  font-size: var(--fs-lg);
  font-weight: 700;
}

.label em {
  color: var(--text-2);
  font-style: normal;
  font-weight: 400;
}

.editor textarea,
.editor input {
  width: 100%;
  min-height: 52px;
  padding: 13px 16px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  color: var(--text);
  background: var(--surface);
  font: inherit;
  font-size: var(--fs-lg);
}

.editor textarea {
  resize: vertical;
}

.editor textarea:focus,
.editor input:focus {
  outline: none;
  border: 2px solid var(--accent-text);
  padding: 12px 15px;
}

.upload span {
  display: flex;
  justify-content: space-between;
  font-size: var(--fs-base);
}

.upload > i {
  display: block;
  height: 10px;
  margin-top: 8px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}

.upload > i > i {
  display: block;
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--accent);
  transform-origin: left;
  transition: transform 0.2s ease;
}

.note {
  margin: 0;
  font-size: var(--fs-md);
  color: var(--text-2);
}

.ready {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 0;
  font-weight: 650;
  color: var(--ok-text);
}

.found {
  display: flex;
  gap: 10px;
  margin: 0;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--ok-soft);
  color: var(--ok-text);
  font-size: var(--fs-base);
  line-height: 1.45;
}

.found :deep(svg) {
  flex: none;
  margin-top: 3px;
}

.btn-grey,
.btn-share {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 52px;
  padding: 0 24px;
  border-radius: var(--radius-pill);
  font-size: var(--fs-lg);
  font-weight: 700;
}

.btn-grey {
  color: var(--text);
  background: var(--surface-2);
}

.btn-grey:hover {
  background: var(--border);
}

.btn-share {
  color: var(--accent-ink);
  background: var(--accent);
}

.btn-share:disabled {
  opacity: 0.45;
  cursor: default;
}

.stays {
  display: flex;
  gap: 8px;
  margin: 14px 0 0;
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.45;
}

.stays :deep(svg) {
  flex: none;
  margin-top: 1px;
}

.error {
  margin: 0;
  color: var(--danger);
}

.actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  padding: 16px 22px calc(16px + env(safe-area-inset-bottom));
  border-top: 1px solid var(--border);
}

.right {
  display: grid;
  min-width: 0;
  min-height: 0;
  padding: 28px;
}

.preview-card {
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: 16px 18px 18px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
}

.preview-card h2 {
  margin: 0 0 14px;
  font-size: var(--fs-lg);
}

.stage {
  display: grid;
  flex: 1;
  place-items: center;
  min-height: 0;
  padding: 20px;
  border-radius: var(--radius-md);
  background: var(--player-surface);
}

.reel {
  position: relative;
  display: grid;
  place-items: center;
  height: 100%;
  max-height: 100%;
  aspect-ratio: 9 / 16;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--on-color) 55%, transparent);
  border-radius: var(--radius-md);
  color: var(--on-color);
  background: var(--player-surface);
}

.reel img,
.reel video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.empty {
  margin: 0;
  font-size: var(--fs-lg);
  font-weight: 650;
  color: color-mix(in srgb, var(--on-color) 55%, transparent);
}

.shade {
  position: absolute;
  inset: auto 0 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 60px 16px 18px;
  background: linear-gradient(transparent, color-mix(in srgb, var(--scrim) 70%, transparent));
  font-size: var(--fs-base);
  line-height: 1.4;
}

.shade strong {
  font-size: var(--fs-lg);
}

.shade span {
  overflow-wrap: anywhere;
}

.shade small {
  font-size: var(--fs-md);
  font-weight: 650;
  opacity: 0.9;
}

/* Phones: one column, the preview on top and the editor under it. */
@media (max-width: 767px) {
  .creator {
    display: flex;
    flex-direction: column;
    overflow-y: auto;
  }

  .left {
    display: contents;
  }

  .round {
    position: absolute;
    top: env(safe-area-inset-top);
    left: 0;
    z-index: 2;
  }

  .me {
    order: 1;
    padding: 70px 16px 16px;
    background: var(--surface);
  }

  .me strong {
    font-size: var(--fs-4xl);
  }

  .right {
    display: block;
    order: 2;
    padding: 16px;
  }

  .editor {
    order: 3;
    overflow: visible;
    padding: 16px;
    background: var(--surface);
  }

  .actions {
    position: sticky;
    bottom: 0;
    order: 4;
    padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
    background: var(--surface);
  }

  .preview-card {
    padding: 12px;
  }

  .stage {
    flex: none;
    padding: 12px;
  }

  .reel {
    height: 46dvh;
    margin: 0 auto;
  }
}
</style>
