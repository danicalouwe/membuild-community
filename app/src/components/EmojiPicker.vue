<script setup>
import { EMOJIS } from '../lib/postStyle.js'

defineEmits(['pick'])
</script>

<template>
  <div class="picker" role="dialog" :aria-label="t.__('Emoji', 'membuild-community')">
    <button v-for="e in EMOJIS" :key="e" type="button" class="e" :aria-label="e" @click="$emit('pick', e)">{{ e }}</button>
  </div>
</template>

<style scoped>
.picker {
  display: grid;
  grid-template-columns: repeat(8, 36px);
  gap: 2px;
  padding: 8px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: 0 12px 32px rgb(0 0 0 / 30%);
}

.e {
  width: 36px;
  height: 36px;
  font-size: 22px;
  line-height: 1;
  border-radius: 8px;
}

.e:hover {
  background: var(--surface-2);
}
</style>
