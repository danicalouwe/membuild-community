<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { api } from '../api.js'
import { loadStories } from '../stores/stories.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import { startAttachment } from '../lib/attachments.js'
import { STORY_BACKGROUNDS, storyBg } from '../lib/postStyle.js'
import { cfg } from '../config.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
import { useDialog } from '../lib/useDialog.js'

// Create a story, full screen like Facebook: your name and the editor on the left,
// a live Preview on the right. A photo or short video (with an optional caption), or
// text on a coloured background (the community's colour first). Gone after 24 hours.
const props = defineProps({ file: { type: Object, default: null } })
const emit = defineEmits(['close'])

const mode = ref(props.file ? 'media' : 'choose') // choose | media | text
const item = ref(null)
const text = ref('')
const background = ref('community')
const sharing = ref(false)
const error = ref('')
const fileInput = ref(null)
const textInput = ref(null)

// A photo or video can be shared once it has finished uploading.
const canShare = computed(() => (mode.value === 'text' ? !!text.value.trim() : item.value?.status === 'done'))
const isVideo = computed(() => item.value?.kind === 'video')

function useFile(file) {
  if (!file || !/^(image|video)\//.test(file.type)) return
  item.value?.cancel()
  item.value = startAttachment(file, { bunny: false }) // stories last a day, so they stay on the site
  mode.value = 'media'
}

function picked(e) {
  useFile(e.target.files[0])
  e.target.value = ''
}

function startText() {
  mode.value = 'text'
  nextTick(() => textInput.value?.focus())
}

async function close() {
  if ((text.value.trim() || item.value) && !(await askConfirm({ title: t.__('Discard this story?', 'membuild-community'), text: t.__('What you’ve written won’t be saved.', 'membuild-community'), confirm: t.__('Discard', 'membuild-community'), cancel: t.__('Keep editing', 'membuild-community'), danger: true }))) return
  item.value?.cancel()
  emit('close')
}

async function share() {
  if (!canShare.value || sharing.value) return
  sharing.value = true
  error.value = ''
  try {
    if (mode.value === 'text') {
      await api.createStory({ text: text.value.trim(), background: background.value })
    } else {
      await item.value.promise
      if (item.value.status !== 'done') throw new Error(item.value.error || t.__('Upload failed', 'membuild-community'))
      await api.createStory({ media_id: item.value.media.id, text: text.value.trim() || undefined })
    }
    await loadStories()
    pointsMayHaveChanged()
    emit('close')
    item.value?.release()
  } catch (err) {
    error.value = err.message
    sharing.value = false
  }
}

const panel = ref(null)
useDialog(panel, { onClose: close })

onMounted(() => {
  if (props.file) useFile(props.file)
})
</script>

<template>
  <Teleport to="body">
    <div ref="panel" class="creator" role="dialog" aria-modal="true" :aria-label="t.__('Create story', 'membuild-community')">
      <!-- Left: who's posting, the editor, then Discard and Share -->
      <aside class="left">
        <button class="round round-btn lg" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="22" /></button>
        <div class="me">
          <Avatar :user="cfg.user" :size="64" />
          <span><strong>{{ t.__('Your story', 'membuild-community') }}</strong><small>{{ cfg.user.name }}</small></span>
        </div>

        <div class="editor">
          <template v-if="mode === 'text'">
            <label class="label" for="story-text">{{ t.__('Your text', 'membuild-community') }}</label>
            <textarea id="story-text" ref="textInput" v-model="text" v-auto-grow maxlength="250" rows="4" :placeholder="t.__('Start typing', 'membuild-community')" />
            <span class="label">{{ t.__('Background', 'membuild-community') }}</span>
            <div class="swatches" role="radiogroup" :aria-label="t.__('Background', 'membuild-community')">
              <button
                v-for="(key, i) in STORY_BACKGROUNDS"
                :key="key"
                type="button"
                class="swatch"
                :class="{ on: background === key }"
                :style="{ background: storyBg(key).background }"
                role="radio"
                :aria-checked="background === key"
                :aria-label="i === 0 ? t.__('Your community’s colour', 'membuild-community') : t.sprintf(t.__('Colour %d', 'membuild-community'), i + 1)"
                @click="background = key"
              />
            </div>
            <small class="note">{{ t.__('The first one is your community’s colour.', 'membuild-community') }}</small>
          </template>

          <template v-else-if="mode === 'media'">
            <label class="label" for="story-caption">{{ t.__('Caption', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></label>
            <input id="story-caption" v-model="text" maxlength="250" :placeholder="t.__('Add text to your story', 'membuild-community')" />
            <div v-if="item?.status === 'working'" class="upload">
              <span><strong>{{ t.__('Uploading…', 'membuild-community') }}</strong><b>{{ Math.round(item.progress * 100) }}%</b></span>
              <i><i :style="{ transform: `scaleX(${item.progress})` }" /></i>
            </div>
            <p v-if="item?.status === 'error'" class="error">{{ item.error }}</p>
            <button type="button" class="btn-grey" @click="fileInput.click()"><Icon name="image" :size="18" /> {{ isVideo ? t.__('Choose a different video', 'membuild-community') : t.__('Choose a different photo', 'membuild-community') }}</button>
          </template>

          <p class="stays"><Icon name="clock" :size="18" /> {{ mode === 'choose' ? t.__('Stays up for 24 hours, then disappears. Every member can see it.', 'membuild-community') : t.__('Stays up for 24 hours.', 'membuild-community') }}</p>
          <p v-if="error" class="error">{{ error }}</p>
        </div>

        <footer v-if="mode !== 'choose'" class="actions">
          <button type="button" class="btn-grey" @click="close">{{ t.__('Discard', 'membuild-community') }}</button>
          <button type="button" class="btn-share" :disabled="!canShare || sharing" @click="share">{{ sharing ? t.__('Sharing…', 'membuild-community') : t.__('Share to story', 'membuild-community') }}</button>
        </footer>
      </aside>

      <!-- Right: the two choices, then a live Preview -->
      <section class="right">
        <div v-if="mode === 'choose'" class="choices">
          <button type="button" class="pick photo" @click="fileInput.click()">
            <span class="p-icon"><Icon name="image" :size="26" /></span>
            <span class="p-text"><strong>{{ t.__('Photo or video', 'membuild-community') }}</strong><small>{{ t.__('From your phone or computer', 'membuild-community') }}</small></span>
          </button>
          <button type="button" class="pick words" @click="startText">
            <span class="p-icon t">T</span>
            <span class="p-text"><strong>{{ t.__('Write something', 'membuild-community') }}</strong><small>{{ t.__('Words on a coloured background', 'membuild-community') }}</small></span>
          </button>
        </div>

        <section v-else class="preview-card">
          <h2>{{ t.__('Preview', 'membuild-community') }}</h2>
          <div class="stage">
            <div class="story" :style="mode === 'text' ? storyBg(background) : null">
              <template v-if="mode === 'media' && item">
                <img v-if="item.kind === 'image'" :src="item.previewUrl" alt="" />
                <video v-else :src="item.previewUrl" muted playsinline autoplay loop />
                <p v-if="text.trim()" class="caption">{{ text }}</p>
              </template>
              <p v-else-if="mode === 'text'" class="words-preview" :class="{ empty: !text.trim() }">{{ text.trim() || t.__('Start typing', 'membuild-community') }}</p>
            </div>
          </div>
        </section>
      </section>

      <input ref="fileInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*,video/*" @change="picked" />
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
  resize: none;
}

.editor textarea {
  min-height: 120px;
  margin-bottom: 10px;
}

.swatches {
  display: grid;
  grid-template-columns: repeat(6, 44px);
  gap: 12px;
}

.swatch {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--shadow-color) 10%, transparent);
}

.swatch.on {
  box-shadow: 0 0 0 3px var(--surface), 0 0 0 5px var(--text);
}

.note {
  color: var(--text-2);
  font-size: var(--fs-md);
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

.btn-grey,
.btn-share {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 52px;
  padding: 0 20px;
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
  place-items: center;
  min-width: 0;
  min-height: 0;
  padding: 28px;
}

.choices {
  display: flex;
  gap: 24px;
}

.pick {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 260px;
  height: 346px;
  padding: 24px;
  border-radius: var(--radius-lg);
  color: var(--on-color);
  text-align: center;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.pick:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-pop);
}

.pick.photo {
  background: linear-gradient(160deg, #4f7cff 0%, #7b4dff 100%);
}

.pick.words {
  background: linear-gradient(160deg, #ff86b0 0%, #ffa07a 100%);
}

.p-icon {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  margin-bottom: 6px;
  border-radius: 50%;
  color: var(--ink);
  background: color-mix(in srgb, var(--on-color) 95%, transparent);
}

.p-icon.t {
  font-size: var(--fs-8xl);
  font-weight: 500;
}

.p-text {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pick strong {
  font-size: var(--fs-3xl);
}

.pick small {
  font-size: var(--fs-base);
  opacity: 0.92;
}

.preview-card {
  display: flex;
  flex-direction: column;
  align-self: stretch;
  justify-self: stretch;
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

.story {
  position: relative;
  display: grid;
  place-items: center;
  height: 100%;
  max-height: 100%;
  aspect-ratio: 9 / 16;
  overflow: hidden;
  border-radius: var(--radius-md);
  color: var(--on-color);
  background: var(--player-bg);
}

.story img,
.story video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.caption {
  position: absolute;
  left: 16px;
  right: 16px;
  bottom: 24px;
  margin: 0;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--scrim) 45%, transparent);
  font-size: var(--fs-xl);
  font-weight: 650;
  text-align: center;
}

.words-preview {
  margin: 0;
  padding: 0 28px;
  font-size: var(--fs-7xl);
  font-weight: 750;
  line-height: 1.3;
  text-align: center;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.words-preview.empty {
  opacity: 0.6;
}

/* Phones: one column. The preview sits on top, the editor under it. */
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
    top: calc(env(safe-area-inset-top));
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

  .choices {
    flex-direction: column;
    gap: 14px;
    width: 100%;
  }

  .pick {
    flex-direction: row;
    justify-content: flex-start;
    width: 100%;
    height: 120px;
    text-align: left;
  }

  .p-text {
    gap: 4px;
  }

  .p-icon {
    flex: none;
    margin: 0 6px 0 0;
  }

  .preview-card {
    padding: 12px;
  }

  .stage {
    flex: none;
    padding: 12px;
  }

  .story {
    height: 46dvh;
    margin: 0 auto;
  }

  .swatches {
    grid-template-columns: repeat(6, 44px);
    justify-content: space-between;
  }
}
</style>
