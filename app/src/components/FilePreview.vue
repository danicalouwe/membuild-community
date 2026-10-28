<script setup>
import { computed, ref } from 'vue'
import FileTile from './FileTile.vue'
import Icon from './Icon.vue'
import { fileSize, kindName, previewUrl } from '../lib/files.js'
import { useDialog } from '../lib/useDialog.js'
import { t } from '../lib/i18n.js'

/**
 * Preview a lesson file full screen, over the lesson, like the photo viewer: a PDF in
 * the browser's own viewer (its zoom and page numbers), a picture fitted to the screen.
 * The top bar says what it is and where it's from, with Download and ✕ (Esc closes).
 * The file comes through the same members-only link as Download.
 */
const props = defineProps({
  file: { type: Object, required: true }, // { name, ext, mime, url, size }
  place: { type: String, default: '' }, // "Course › Lesson"
})
const emit = defineEmits(['close'])

const panel = ref(null)
useDialog(panel, { onClose: () => emit('close') })

const isImage = computed(() => (props.file.mime || '').startsWith('image/'))
// The file's name at the end of the address, so the PDF viewer shows it as the title;
// the thumbnails start closed, so the page gets the room.
const viewUrl = computed(() => previewUrl(props.file) + (isImage.value ? '' : '#navpanes=0'))
const meta = computed(() => [kindName(props.file.ext), fileSize(props.file.size), props.place].filter(Boolean).join(' · '))
</script>

<template>
  <Teleport to="body">
    <div ref="panel" class="fp" role="dialog" aria-modal="true" :aria-label="file.name" tabindex="-1">
      <header class="bar">
        <FileTile :ext="file.ext" :size="44" />
        <div class="what">
          <strong>{{ file.name }}</strong>
          <small>{{ meta }}</small>
        </div>
        <a :href="file.url" class="btn btn-lg dl" download><Icon name="download" :size="18" /> {{ t.__('Download', 'membuild-community') }}</a>
        <button class="x" :aria-label="t.__('Close preview', 'membuild-community')" data-autofocus @click="emit('close')"><Icon name="close" :size="22" /></button>
      </header>
      <div class="stage" @click.self="emit('close')">
        <img v-if="isImage" :src="viewUrl" :alt="file.name" />
        <iframe v-else :src="viewUrl" :title="file.name" />
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.fp {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  flex-direction: column;
  background: rgb(12 13 16 / 94%);
  color: #fff;
}

.fp:focus {
  outline: none;
}

.bar {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 20px;
  border-bottom: 1px solid rgb(255 255 255 / 12%);
}

.what {
  flex: 1;
  min-width: 0;
}

.what strong,
.what small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.what strong {
  font-size: 17px;
}

.what small {
  font-size: 14px;
  opacity: 0.72;
}

.dl {
  flex: none;
  background: var(--accent);
  color: #16181d;
  text-decoration: none;
}

.x {
  display: grid;
  flex: none;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: rgb(255 255 255 / 12%);
  color: #fff;
}

.x:hover {
  background: rgb(255 255 255 / 20%);
}

.stage {
  display: flex;
  flex: 1;
  align-items: center;
  justify-content: center;
  min-height: 0;
  padding: 20px;
}

.stage iframe {
  width: min(980px, 100%);
  height: 100%;
  border: 0;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 10px 40px rgb(0 0 0 / 50%);
}

.stage img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  border-radius: 6px;
}

@media (max-width: 600px) {
  .bar {
    gap: 10px;
    padding: 10px 12px;
  }

  .dl {
    width: 48px;
    padding: 0;
    justify-content: center;
    font-size: 0;
  }

  .dl :deep(svg) {
    margin: 0;
  }

  .stage {
    padding: 10px;
  }
}
</style>
