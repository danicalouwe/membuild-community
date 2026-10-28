<script setup>
import { ref } from 'vue'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * Our own "are you sure?" box, never the browser's. One plain question, what
 * happens, and two big buttons: the safe one first. A `tick` asks the owner to
 * confirm they understand before a permanent delete.
 */
const props = defineProps({
  title: { type: String, required: true },
  confirm: { type: String, required: true },
  cancel: { type: String, default: '' },
  danger: { type: Boolean, default: false },
  tick: { type: String, default: '' }, // e.g. "I understand this can't be undone"
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['confirm', 'close'])
const ticked = ref(false)
</script>

<template>
  <BaseDialog :label="title" :width="520" @close="emit('close')">
    <template #header>
      <span />
    </template>
    <div class="cd">
      <span v-if="danger" class="warn"><Icon name="flag" :size="24" /></span>
      <h2>{{ title }}</h2>
      <div class="text"><slot /></div>
      <label v-if="tick" class="tick">
        <input v-model="ticked" type="checkbox" />
        <span>{{ tick }}</span>
      </label>
      <div class="actions">
        <button type="button" class="btn btn-lg btn-ghost" @click="emit('close')">{{ cancel || t.__('Cancel', 'membuild-community') }}</button>
        <button type="button" class="btn btn-lg" :class="danger ? 'red' : 'btn-primary'" :disabled="busy || (!!tick && !ticked)" @click="emit('confirm')">{{ confirm }}</button>
      </div>
    </div>
  </BaseDialog>
</template>

<style scoped>
.cd {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 28px 28px 24px;
}

.warn {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: var(--danger-soft);
  color: var(--danger-text);
}

h2 {
  margin: 0;
  font-size: 24px;
  line-height: 1.25;
}

.text {
  font-size: 17px;
  line-height: 1.55;
  color: var(--text-2);
}

.text :deep(p) {
  margin: 0 0 10px;
}

.text :deep(.note) {
  padding: 12px 14px;
  border-radius: 12px;
  background: var(--danger-soft);
  color: var(--danger-ink);
}

.tick {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  font-size: 17px;
  font-weight: 600;
}

.tick input {
  width: 22px;
  height: 22px;
}

.actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 4px;
}

.red {
  background: #b42318;
  color: #fff;
}
</style>
