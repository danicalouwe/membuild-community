<script setup>
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * Auto · Light · Dark as one rounded bar: three equal parts, an icon and one word each,
 * the picked one white and raised. The same bar in Settings (the community's default) and
 * in the profile menu (a member's own choice), so the two always look alike.
 */
defineProps({
  value: { type: String, default: 'auto' },
  label: { type: String, default: '' }, // what the group is, for screen readers
  icons: { type: Boolean, default: false }, // icons only (the profile menu); the word stays as the name and tooltip
})
const emit = defineEmits(['pick'])
const LOOKS = [
  { key: 'auto', label: () => t.__('Auto', 'membuild-community') },
  { key: 'light', icon: 'sun', label: () => t.__('Light', 'membuild-community') },
  { key: 'dark', icon: 'moon', label: () => t.__('Dark', 'membuild-community') },
]
</script>

<template>
  <div class="look-bar" :class="{ icons }" role="radiogroup" :aria-label="label || t.__('Light or dark', 'membuild-community')">
    <button v-for="l in LOOKS" :key="l.key" type="button" role="radio" :aria-checked="value === l.key" :class="{ on: value === l.key }" :aria-label="icons ? l.label() : undefined" :title="icons ? l.label() : undefined" @click="emit('pick', l.key)">
      <!-- Auto: a circle, half filled (half light, half dark). -->
      <svg v-if="l.key === 'auto'" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.9" /><path d="M12 3.5a8.5 8.5 0 0 1 0 17z" fill="currentColor" /></svg>
      <Icon v-else :name="l.icon" :size="18" />
      <template v-if="!icons">{{ l.label() }}</template>
    </button>
  </div>
</template>

<style scoped>
.look-bar {
  display: flex;
  gap: 4px;
  max-width: 420px;
  padding: 4px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}

.look-bar button {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: 0;
  height: 44px;
  border: 0;
  border-radius: var(--radius-pill);
  background: none;
  color: var(--text-2);
  font: inherit;
  font-size: var(--fs-lg);
  font-weight: 700;
  cursor: pointer;
}

.look-bar.icons {
  width: fit-content;
}

.look-bar.icons button {
  flex: none;
  width: 52px;
}

.look-bar button.on {
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--shadow-sm);
}

.look-bar button:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 1px;
}

/* On a dark page the picked part is a little lighter than the bar, so it still looks raised. */
@media (prefers-color-scheme: dark) {
  .look-bar button.on {
    background: color-mix(in srgb, var(--surface-2) 70%, var(--text) 14%);
  }
}
</style>
