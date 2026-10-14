<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { loadStories } from '../stores/stories.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import { startAttachment } from '../lib/attachments.js'
import { BACKGROUNDS } from '../lib/postStyle.js'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
import { useDialog } from '../lib/useDialog.js'

// Create a story: a photo or short video (with an optional caption), or text
// on a colored background. Stories disappear after 24 hours.
const props = defineProps({ file: { type: Object, default: null } })
const emit = defineEmits(['close'])

const mode = ref(props.file ? 'media' : 'choose') // choose | media | text
const item = ref(null)
const text = ref('')
const background = ref('grape')
const sharing = ref(false)
const error = ref('')
const fileInput = ref(null)
const textInput = ref(null)

const canShare = computed(() =>
  mode.value === 'text' ? !!text.value.trim() : item.value && item.value.status !== 'error',
)

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

function close() {
  if ((text.value.trim() || item.value) && !confirm(t.__('Discard this story?', 'membuild-community'))) return
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
    <div class="backdrop" @mousedown.self="close">
      <div ref="panel" class="dialog" role="dialog" aria-modal="true" :aria-label="t.__('Create story', 'membuild-community')">
        <header class="top">
          <button v-if="mode !== 'choose' && !props.file" class="round back" :aria-label="t.__('Back', 'membuild-community')" @click="mode = 'choose'"><Icon name="chevronLeft" :size="22" /></button>
          <h2>{{ t.__('Create story', 'membuild-community') }}</h2>
          <button class="round" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="22" /></button>
        </header>

        <!-- Choose a type -->
        <div v-if="mode === 'choose'" class="choose">
          <button class="option photo" @click="fileInput.click()">
            <span class="o-icon"><Icon name="image" :size="28" /></span>
            {{ t.__('Create a photo or video story', 'membuild-community') }}
          </button>
          <button class="option text" @click="startText">
            <span class="o-icon">Aa</span>
            {{ t.__('Create a text story', 'membuild-community') }}
          </button>
        </div>

        <!-- Preview -->
        <div v-else class="compose">
          <div class="preview" :style="mode === 'text' ? { background: BACKGROUNDS[background] } : null">
            <template v-if="mode === 'media' && item">
              <img v-if="item.kind === 'image'" :src="item.previewUrl" alt="" />
              <video v-else :src="item.previewUrl" muted playsinline autoplay loop />
              <p v-if="text.trim()" class="caption">{{ text }}</p>
              <div v-if="item.status === 'working'" class="progress">
                {{ item.stage }} {{ Math.round(item.progress * 100) }}%
                <i :style="{ transform: `scaleX(${item.progress})` }" />
              </div>
            </template>
            <textarea
              v-else
              v-auto-grow
              ref="textInput"
              v-model="text"
              class="text-input"
              maxlength="250"
              rows="4"
              :placeholder="t.__('Start typing', 'membuild-community')"
              :aria-label="t.__('Story text', 'membuild-community')"
            />
          </div>

          <div class="side">
            <template v-if="mode === 'text'">
              <span class="label">{{ t.__('Background', 'membuild-community') }}</span>
              <div class="swatches" role="radiogroup" :aria-label="t.__('Background', 'membuild-community')">
                <button
                  v-for="(css, key) in BACKGROUNDS"
                  :key="key"
                  class="swatch"
                  :class="{ on: background === key }"
                  :style="{ background: css }"
                  role="radio"
                  :aria-checked="background === key"
                  :aria-label="key"
                  @click="background = key"
                />
              </div>
            </template>
            <template v-else>
              <label class="label" for="story-caption">{{ t.__('Caption (optional)', 'membuild-community') }}</label>
              <input id="story-caption" v-model="text" class="caption-input" maxlength="250" :placeholder="t.__('Add text to your story', 'membuild-community')" />
              <button class="btn btn-ghost" @click="fileInput.click()"><Icon name="image" :size="18" /> {{ t.__('Choose another', 'membuild-community') }}</button>
              <p v-if="item?.status === 'error'" class="error">{{ item.error }}</p>
            </template>
            <p class="hint">{{ t.__('Your story is visible to community members for 24 hours.', 'membuild-community') }}</p>
            <p v-if="error" class="error">{{ error }}</p>
            <button class="share" :disabled="!canShare || sharing" @click="share">
              {{ sharing ? (item?.status === 'working' ? t.__('Finishing upload…', 'membuild-community') : t.__('Sharing…', 'membuild-community')) : t.__('Share to story', 'membuild-community') }}
            </button>
          </div>
        </div>

        <input ref="fileInput" class="sr-only" type="file" accept="image/*,video/*" @change="picked" />
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: 95;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(10 11 13 / 72%);
}

.dialog {
  width: min(720px, 100%);
  max-height: calc(100dvh - 48px);
  overflow-y: auto;
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
  border-bottom: 1px solid var(--border);
}

.top h2 {
  margin: 0;
  font-size: 20px;
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

.choose {
  display: flex;
  justify-content: center;
  gap: 16px;
  padding: 32px 20px;
}

.option {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
  width: 220px;
  aspect-ratio: 220 / 330;
  border-radius: 12px;
  color: #fff;
  font-weight: 700;
  font-size: 15px;
  padding: 16px;
  text-align: center;
  transition: transform 0.15s ease, filter 0.15s ease;
}

.option:hover {
  filter: brightness(1.08);
}

.option:active {
  transform: scale(0.98);
}

.option.photo {
  background: linear-gradient(160deg, #4f8cff, #7a4dff);
}

.option.text {
  background: linear-gradient(160deg, #ff6f91, #ff9671);
}

.o-icon {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: #fff;
  color: #1c1d21;
  font-weight: 800;
  font-size: 18px;
  box-shadow: 0 4px 12px rgb(0 0 0 / 20%);
}

.compose {
  display: flex;
  gap: 20px;
  padding: 20px;
}

.preview {
  position: relative;
  flex: none;
  width: 270px;
  aspect-ratio: 9 / 16;
  border-radius: 12px;
  overflow: hidden;
  background: #000;
  display: grid;
  place-items: center;
}

.preview img,
.preview video {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.text-input {
  width: 100%;
  padding: 0 20px;
  border: 0;
  resize: none;
  background: transparent;
  color: #fff;
  text-align: center;
  font-size: 24px;
  font-weight: 750;
  field-sizing: content;
}

.text-input::placeholder {
  color: rgb(255 255 255 / 75%);
}

.text-input:focus {
  outline: none;
}

.caption {
  position: absolute;
  left: 12px;
  right: 12px;
  bottom: 40px;
  margin: 0;
  padding: 6px 10px;
  border-radius: 8px;
  background: rgb(0 0 0 / 45%);
  color: #fff;
  font-weight: 650;
  text-align: center;
}

.progress {
  position: absolute;
  inset: auto 0 0;
  padding: 8px 12px;
  color: #fff;
  font-size: 12px;
  font-weight: 650;
  background: linear-gradient(transparent, rgb(0 0 0 / 70%));
}

.progress i {
  display: block;
  height: 3px;
  margin-top: 4px;
  border-radius: 2px;
  background: #fff;
  transform-origin: left;
  transition: transform 0.2s linear;
}

.side {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.label {
  font-weight: 650;
  font-size: 14px;
  color: var(--text-2);
}

.swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.swatch {
  width: 36px;
  height: 36px;
  border-radius: 50%;
}

.swatch.on {
  box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--accent);
}

.caption-input {
  height: 40px;
  padding: 0 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface-2);
}

.btn {
  align-self: flex-start;
  color: var(--text);
}

.hint {
  margin: auto 0 0;
  font-size: 13px;
  color: var(--text-3);
}

.error {
  margin: 0;
  color: var(--danger);
  font-size: 13px;
}

.share {
  height: 44px;
  border-radius: 8px;
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 700;
  font-size: 16px;
}

.share:disabled {
  background: var(--surface-2);
  color: var(--text-3);
  cursor: default;
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

  .choose {
    flex-direction: column;
    align-items: center;
  }

  .option {
    width: min(280px, 80vw);
    aspect-ratio: auto;
    height: 160px;
  }

  .compose {
    flex-direction: column;
    align-items: center;
  }

  .preview {
    width: min(260px, 70vw);
  }

  .side {
    width: 100%;
  }
}
</style>
