<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { addAnchor, playVideo, removeAnchor, video } from '../lib/videoDock.js'
import { t } from '../lib/i18n.js'

/**
 * A YouTube or Vimeo video in a post, like Facebook's link card: the
 * thumbnail and title first; nothing loads from YouTube until play. The
 * player itself is VideoDock's, drawn over this frame (or in the corner
 * once the post scrolls away), so it keeps playing across pages without
 * restarting.
 */
const props = defineProps({ media: { type: Object, required: true } })

const frame = ref(null)
const e = computed(() => props.media.embed || {})
const vertical = computed(() => !!e.value.vertical)
const playing = computed(() => video.media?.id === props.media.id)
const site = computed(() => (e.value.provider === 'vimeo' ? 'VIMEO.COM' : 'YOUTUBE.COM'))

onMounted(() => addAnchor(props.media.id, frame.value))
onBeforeUnmount(() => removeAnchor(props.media.id, frame.value))
const playLabel = (title) => t.sprintf(/* translators: %s: video title */ t.__('Play %s', 'membuild-community'), title)
</script>

<template>
  <div class="embed">
    <div ref="frame" class="frame" :class="{ vertical }">
      <button class="poster" :aria-label="e.title ? playLabel(e.title) : t.__('Play video', 'membuild-community')" @click="playVideo(media)">
        <img v-if="media.poster" :src="media.poster" alt="" loading="lazy" decoding="async" />
        <!-- Only seen while the video plays in the corner; over the post, the player covers it. -->
        <span v-if="playing" class="elsewhere">{{ t.__('Playing in the corner · tap to watch here', 'membuild-community') }}</span>
        <span v-else class="play" aria-hidden="true">
          <svg width="68" height="48" viewBox="0 0 68 48"><path d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.3.3 34 .3 34 .3s-21.3 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6C12.7 47.7 34 47.7 34 47.7s21.3 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z" fill="#f00" /><path d="M45 24 27 14v20z" fill="#fff" /></svg>
        </span>
      </button>
    </div>
    <a class="meta" :href="e.source_url || media.url" target="_blank" rel="noopener nofollow">
      <small>{{ site }}</small>
      <strong>{{ e.title || t.__('Watch video', 'membuild-community') }}</strong>
      <span v-if="e.author">{{ e.author }}</span>
    </a>
  </div>
</template>

<style scoped>
.embed {
  background: var(--surface-2);
}

.frame {
  position: relative;
  aspect-ratio: 16 / 9;
  background: #000;
}

/* Shorts are tall; keep them a sensible size in the feed. */
.frame.vertical {
  aspect-ratio: 9 / 16;
  max-height: 560px;
  margin: 0 auto;
}

.poster {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.poster {
  display: grid;
  place-items: center;
  padding: 0;
  cursor: pointer;
}

.poster img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.play {
  position: relative;
  filter: drop-shadow(0 2px 8px rgb(0 0 0 / 35%));
  transition: transform 0.15s;
}

.elsewhere {
  position: relative;
  padding: 8px 14px;
  border-radius: 999px;
  background: rgb(0 0 0 / 70%);
  color: #fff;
  font-size: 14px;
  font-weight: 650;
}

.poster:hover .play {
  transform: scale(1.06);
}

.meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 10px 14px 12px;
  color: var(--text);
}

.meta small {
  font-size: 12px;
  color: var(--text-3);
  letter-spacing: 0.02em;
}

.meta strong {
  font-size: 16px;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.meta span {
  font-size: 13px;
  color: var(--text-3);
}

.meta:hover strong {
  text-decoration: underline;
}
</style>
