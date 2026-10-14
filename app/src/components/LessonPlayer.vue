<script setup>
import { isProcessing } from '../lib/stream.js'
import { computed } from 'vue'

// Plays a lesson's video: an upload, or YouTube / Vimeo in the provider's own player.
const props = defineProps({ media: { type: Object, required: true } })
defineEmits(['ended'])

const embedSrc = computed(() => {
  const e = props.media.embed
  if (!e) return null
  if (e.provider === 'vimeo') return `${props.media.url}${props.media.url.includes('?') ? '&' : '?'}dnt=1&title=0&byline=0`
  return `https://www.youtube-nocookie.com/embed/${e.id}?rel=0&playsinline=1`
})
const vertical = computed(() => props.media.embed?.vertical || (props.media.height > props.media.width && props.media.width > 0))
</script>

<template>
  <div class="player" :class="{ vertical }">
    <iframe
      v-if="embedSrc"
      :src="embedSrc"
      :title="media.embed.title || t.__('Lesson video', 'membuild-community')"
      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
      allowfullscreen
      loading="lazy"
      referrerpolicy="strict-origin-when-cross-origin"
    />
    <div v-else-if="isProcessing(media)" class="lesson-processing" :style="media.poster ? { backgroundImage: `url(${media.poster})` } : null">
      <span>{{ t.__('This video is still processing. Check back in a few minutes.', 'membuild-community') }}</span>
    </div>
    <video v-else v-stream="media" :poster="media.poster || undefined" controls playsinline preload="metadata" @ended="$emit('ended')" />
  </div>
  <p v-if="media.embed?.author" class="credit">
    {{ media.embed.provider === 'vimeo' ? 'Vimeo' : 'YouTube' }} ·
    <a :href="media.embed.source_url" target="_blank" rel="noopener">{{ media.embed.author }}</a>
  </p>
</template>

<style scoped>
.lesson-processing {
  display: grid;
  place-items: center;
  aspect-ratio: 16 / 9;
  background: #111 center / cover;
}

.lesson-processing span {
  padding: 10px 16px;
  border-radius: 999px;
  background: rgb(0 0 0 / 65%);
  color: #fff;
  font-weight: 600;
}

.player {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: 12px;
  overflow: hidden;
  background: #000;
}

.player.vertical {
  width: auto;
  height: min(70vh, 640px);
  aspect-ratio: 9 / 16;
  margin: 0 auto;
}

.player iframe,
.player video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.credit {
  margin: 8px 0 0;
  font-size: 13px;
  color: var(--text-3);
}

.credit a {
  color: var(--text-2);
  font-weight: 600;
}
</style>
