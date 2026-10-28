<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

// A small box that pops up above what opened it (the GIF button in a chat), like
// Facebook's, with BaseDialog's default and footer slots. Clicking outside or Esc closes it;
// the button that opened it is marked data-pop-trigger so pressing it again closes it.
defineProps({ title: { type: String, required: true } })
const emit = defineEmits(['close'])
const root = ref(null)

function outside(e) {
  if (root.value?.contains(e.target) || e.target.closest?.('[data-pop-trigger]')) return
  emit('close')
}
function onKey(e) {
  if (e.key !== 'Escape') return
  e.stopPropagation()
  emit('close')
}
onMounted(() => {
  document.addEventListener('pointerdown', outside, true)
  document.addEventListener('keydown', onKey, true)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', outside, true)
  document.removeEventListener('keydown', onKey, true)
})
</script>

<template>
  <div ref="root" class="pop" role="dialog" :aria-label="title">
    <div class="pop-body"><slot /></div>
    <div v-if="$slots.footer" class="pop-foot"><slot name="footer" /></div>
  </div>
</template>

<style scoped>
.pop {
  position: absolute;
  right: 8px;
  bottom: calc(100% + 6px);
  left: 8px;
  z-index: 30;
  display: flex;
  flex-direction: column;
  max-height: min(380px, 70vh);
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--surface);
  box-shadow: 0 12px 32px rgb(0 0 0 / 22%);
  overflow: hidden;
}

.pop-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.pop-foot {
  padding: 4px 12px 8px;
}
</style>
