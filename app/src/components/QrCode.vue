<script setup>
import { computed } from 'vue'
import { qrPath } from '../lib/qr.js'

/**
 * A QR code for a link: always dark squares on white (with the four-square white border scanners need, as it may sit on a dark card),
 * whatever the main colour or dark mode, so every phone camera reads it. The link itself is
 * shown next to it, so this is hidden from screen readers.
 */
const props = defineProps({
  value: { type: String, required: true },
  size: { type: Number, default: 96 },
})
const qr = computed(() => qrPath(props.value))
</script>

<template>
  <svg class="qr" :width="size" :height="size" :viewBox="`-4 -4 ${qr.n + 8} ${qr.n + 8}`" shape-rendering="crispEdges" aria-hidden="true" focusable="false">
    <rect x="-4" y="-4" :width="qr.n + 8" :height="qr.n + 8" class="qr-bg" />
    <path :d="qr.d" class="qr-ink" />
  </svg>
</template>

<style scoped>
.qr {
  display: block;
  flex: none;
  border-radius: var(--radius-xs);
}
.qr-bg {
  fill: var(--paper);
}
.qr-ink {
  fill: var(--ink);
}
</style>
