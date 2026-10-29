<script setup>
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

defineProps({ item: { type: Object, required: true } })
defineEmits(['remove'])

const progressLabel = (item) => t.sprintf(/* translators: 1: upload stage, e.g. "Uploading", 2: percent done */ t.__('%1$s %2$d%%', 'membuild-community'), item.stage, Math.round(item.progress * 100))
</script>

<template>
  <div class="att" :class="item.status">
    <img v-if="item.kind === 'image'" :src="item.previewUrl" alt="" />
    <video v-else :src="item.previewUrl" :poster="item.poster || undefined" muted playsinline preload="metadata" />
    <div v-if="item.status === 'working'" class="progress">
      <span>{{ progressLabel(item) }}</span>
      <i :style="{ transform: `scaleX(${item.progress})` }" />
    </div>
    <div v-else-if="item.status === 'error'" class="progress error">{{ item.error }}</div>
    <span v-if="item.kind === 'video' && item.status === 'done'" class="tag"><Icon name="video" :size="14" /></span>
    <button class="remove" :aria-label="t.__('Remove', 'membuild-community')" @click="$emit('remove')"><Icon name="close" :size="16" /></button>
  </div>
</template>

<style scoped>
.att {
  position: relative;
  width: 110px;
  height: 110px;
  border-radius: var(--radius-sm);
  overflow: hidden;
  background: var(--surface-2);
  flex: none;
}

.att img,
.att video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.progress {
  position: absolute;
  inset: auto 0 0;
  padding: 6px 8px;
  font-size: var(--fs-2xs);
  font-weight: 600;
  color: var(--on-color);
  background: linear-gradient(transparent, color-mix(in srgb, var(--scrim) 70%, transparent));
}

.progress i {
  display: block;
  height: 3px;
  margin-top: 4px;
  background: var(--on-color);
  border-radius: 2px;
  transform-origin: left;
  transition: transform 0.2s linear;
}

.progress.error {
  background: var(--danger);
}

.remove,
.tag {
  position: absolute;
  top: 6px;
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  color: var(--on-color);
  background: color-mix(in srgb, var(--scrim) 60%, transparent);
}

.remove {
  right: 6px;
}

.tag {
  left: 6px;
}
</style>
