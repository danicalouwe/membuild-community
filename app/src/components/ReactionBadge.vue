<script setup>
import { computed } from 'vue'
import { reactionFor } from '../lib/reactions.js'

// A round reaction badge: Like and Love are drawn as white glyphs on a colored
// circle; the face reactions use their emoji, which are already round.
// Set --ring (e.g. to the card color) on a parent to separate the badge from
// what's behind it: a round ring for the drawn circles, and for emoji an
// outline that follows the face itself, so there's no gap on any device's emoji.
const props = defineProps({
  type: { type: String, required: true },
  size: { type: Number, default: 20 },
})
const r = computed(() => reactionFor(props.type))
</script>

<template>
  <span class="badge" :class="type" :style="{ width: `${size}px`, height: `${size}px`, '--c': r.color }" role="img" :aria-label="r.label">
    <svg v-if="type === 'like'" viewBox="0 0 16 16" aria-hidden="true">
      <path fill="#fff" d="M4.6 7.2H3a.7.7 0 0 0-.7.7v4.4c0 .4.3.7.7.7h1.6zM5.6 7.2l1.9-3.6c.2-.4.6-.6 1-.5.6.1 1 .7.9 1.3l-.3 1.9h2.6c.8 0 1.4.7 1.2 1.5l-.7 3.4c-.1.6-.7 1-1.3 1H5.6z" />
    </svg>
    <svg v-else-if="type === 'love'" viewBox="0 0 16 16" aria-hidden="true">
      <path fill="#fff" d="M8 12.6s-4.3-2.6-4.3-5.6A2.3 2.3 0 0 1 8 5.6a2.3 2.3 0 0 1 4.3 1.4c0 3-4.3 5.6-4.3 5.6z" />
    </svg>
    <span v-else class="emoji" :style="{ fontSize: `${size * 1.08}px` }">{{ r.emoji }}</span>
  </span>
</template>

<style scoped>
.badge {
  display: inline-grid;
  place-items: center;
  flex: none;
  border-radius: 50%;
  line-height: 1;
}

.badge.like {
  background: linear-gradient(#4d9bff, var(--c));
}

.badge.like,
.badge.love {
  box-shadow: 0 0 0 2px var(--ring, transparent);
}

.badge.love {
  background: linear-gradient(#ff6680, var(--c));
}

.badge svg {
  width: 72%;
  height: 72%;
}

.badge:not(.like):not(.love) {
  overflow: visible;
}

/* Emoji glyphs carry built-in padding; scale them up so faces match the drawn circles. */
.emoji {
  display: block;
  line-height: 1;
  /* The ring for emoji: four stacked 1.5px shadows trace the face's own edge. */
  filter: drop-shadow(1.5px 0 0 var(--ring, transparent)) drop-shadow(-1.5px 0 0 var(--ring, transparent)) drop-shadow(0 1.5px 0 var(--ring, transparent)) drop-shadow(0 -1.5px 0 var(--ring, transparent));
}
</style>
