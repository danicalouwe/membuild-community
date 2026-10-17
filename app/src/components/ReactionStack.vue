<script setup>
import { computed } from 'vue'
import ReactionBadge from './ReactionBadge.vue'
import { reactionFor } from '../lib/reactions.js'
import { locale } from '../lib/i18n.js'

// Overlapping reaction badges, most-used first, with an optional count.
// Clickable when the parent listens for `open` (shows who reacted). With
// `counts` ({like: 3, haha: 1}), hovering or focusing shows each kind's number.
const props = defineProps({
  types: { type: Array, default: () => [] },
  count: { type: [Number, String], default: null },
  counts: { type: Object, default: null },
  size: { type: Number, default: 22 },
  clickable: { type: Boolean, default: false },
})
defineEmits(['open'])

const breakdown = computed(() =>
  Object.entries(props.counts || {})
    .filter(([, n]) => n > 0)
    .sort((a, b) => b[1] - a[1])
    .map(([type, n]) => ({ type, n, label: reactionFor(type).label })),
)
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
    <span v-if="breakdown.length" class="tip" role="tooltip">
      <span v-for="b in breakdown" :key="b.type" class="row">
        <ReactionBadge :type="b.type" :size="18" />
        <span class="label">{{ b.label }}</span>
        <span class="num">{{ b.n.toLocaleString(locale) }}</span>
      </span>
    </span>
  </component>
</template>

<style scoped>
.stack {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--text-2);
  font-size: 14px;
}

/* Each kind's count, Facebook-style, above the pill while it's hovered or focused. */
.tip {
  position: absolute;
  left: 0;
  bottom: calc(100% + 6px);
  z-index: 5;
  display: none;
  flex-direction: column;
  gap: 6px;
  min-width: 120px;
  padding: 8px 10px;
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.22), 0 0 0 1px var(--border);
  font-size: 13px;
  white-space: nowrap;
  pointer-events: none;
}

.stack:hover .tip,
.stack:focus-visible .tip,
.stack:focus-within .tip {
  display: flex;
}

@media (hover: none) {
  .stack:hover .tip {
    display: none; /* phones: the dialog (where there is one) shows the numbers */
  }
}

.tip .row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tip .label {
  flex: 1;
  color: var(--text-2);
}

.tip .num {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
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
