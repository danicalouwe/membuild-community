<script setup>
import ReactionBadge from './ReactionBadge.vue'

// Overlapping reaction badges, most-used first, with an optional count.
// Clickable when the parent listens for `open` (shows who reacted).
const props = defineProps({
  types: { type: Array, default: () => [] },
  count: { type: [Number, String], default: null },
  size: { type: Number, default: 22 },
  clickable: { type: Boolean, default: false },
})
defineEmits(['open'])
</script>

<template>
  <component
    :is="clickable ? 'button' : 'span'"
    v-if="types.length"
    class="stack"
    :class="{ clickable }"
    :style="{ '--s': `${size}px` }"
    :aria-label="clickable ? t.__('See who reacted', 'membuild-community') : undefined"
    @click="clickable && $emit('open')"
  >
    <span class="badges">
      <ReactionBadge v-for="(type, i) in types.slice(0, 3)" :key="type" :type="type" :size="size" class="b" :style="{ zIndex: 3 - i }" />
    </span>
    <span v-if="count" class="n">{{ count }}</span>
  </component>
</template>

<style scoped>
.stack {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--text-2);
  font-size: 14px;
}

.stack.clickable {
  cursor: pointer;
  border-radius: 999px;
  padding: 2px;
}

.stack.clickable:hover .n {
  text-decoration: underline;
}

.badges {
  display: inline-flex;
  /* The overlap order (z-index) stays inside this group, so the badges never
     paint over things like the pinned comment box when they scroll under it. */
  isolation: isolate;
}

/* Tight overlap with a ring in the card color, so the badges read as one group. */
.b {
  position: relative;
  --ring: var(--surface);
}

.b + .b {
  margin-left: calc(var(--s) * -0.18);
}
</style>
