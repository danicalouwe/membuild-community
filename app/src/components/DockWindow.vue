<script setup>
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

// A chat-window frame for the bottom-right dock (New message), with the same title,
// body and footer slots as BaseDialog so a dialog can live in either. Esc closes it.
defineProps({ title: { type: String, required: true } })
const emit = defineEmits(['close'])
</script>

<template>
  <section class="dw" role="dialog" :aria-label="title" @keydown.esc.stop="emit('close')">
    <header class="dw-head">
      <h2>{{ title }}</h2>
      <button class="dw-x" :aria-label="t.__('Close', 'membuild-community')" @click="emit('close')"><Icon name="close" :size="20" /></button>
    </header>
    <div class="dw-body"><slot /></div>
    <footer v-if="$slots.footer" class="dw-foot"><slot name="footer" /></footer>
  </section>
</template>

<style scoped>
.dw {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--surface);
}

.dw-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 8px 8px 16px;
  border-bottom: 1px solid var(--border);
}

.dw-head h2 {
  margin: 0;
  font-size: var(--fs-lg);
}

.dw-x {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  color: var(--accent-text);
}

.dw-x:hover {
  background: var(--surface-2);
}

.dw-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.dw-foot {
  padding: 10px 12px 12px;
  border-top: 1px solid var(--border);
}
</style>
