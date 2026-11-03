<script setup>
import { computed, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * The main colour: a row of swatches, "+" for any colour (the browser's picker), and the colour
 * as a hex code you can type or paste. Used by Settings › General and by Settings › Main website,
 * so the owner sees one control. v-model is '' for the first swatch (the default) or a hex code.
 */
const props = defineProps({
  modelValue: { type: String, default: '' },
  accents: { type: Array, required: true },
  label: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const current = computed(() => props.modelValue || props.accents[0])
const custom = computed(() => props.modelValue && !props.accents.includes(props.modelValue))
const set = (hex) => emit('update:modelValue', hex === props.accents[0] ? '' : hex)

// Typed or pasted ("#5b4bff", "5b4bff", or "#5bf" once you leave the field). The field keeps what's
// typed; the colour changes as soon as it's a full code.
const draft = ref(null)
const shown = computed(() => draft.value ?? current.value)
const normalize = (value, short) => {
  const hex = value.trim().replace(/^#?/, '#').toLowerCase()
  if (short && /^#[0-9a-f]{3}$/.test(hex)) return '#' + [...hex.slice(1)].map((c) => c + c).join('')
  return /^#[0-9a-f]{6}$/.test(hex) ? hex : null
}
function type(value) {
  draft.value = value
  const hex = normalize(value, false)
  if (hex) set(hex)
}
function leave() {
  const hex = draft.value === null ? null : normalize(draft.value, true)
  if (hex) set(hex)
  if (hex || draft.value === null) draft.value = null
}
// Picked a swatch, or pressed Discard: show the colour that's really set.
watch(current, (hex) => {
  if (draft.value !== null && normalize(draft.value, false) !== hex) draft.value = null
})
const valid = computed(() => draft.value === null || /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.test(draft.value.trim()))
defineExpose({ valid })
</script>

<template>
  <div class="color-row">
    <div class="swatches" role="radiogroup" :aria-label="label || t.__('Accent color', 'membuild-community')">
      <button
        v-for="hex in accents"
        :key="hex"
        type="button"
        role="radio"
        class="swatch swatch-pick tap-44"
        :class="{ on: current === hex }"
        :style="{ background: hex }"
        :aria-checked="current === hex"
        :aria-label="hex"
        @click="set(hex); draft = null"
      />
      <label class="swatch swatch-pick tap-44 custom" :class="{ on: custom }" :style="custom ? { background: modelValue } : null" :title="t.__('Pick a custom color', 'membuild-community')">
        <Icon :name="custom ? 'compose' : 'plus'" :size="15" />
        <input type="color" :value="current" :aria-label="t.__('Pick a custom color', 'membuild-community')" @input="set($event.target.value); draft = null" />
      </label>
    </div>
    <label class="hex">
      <span class="hex-input" :class="{ bad: !valid }">
        <i :style="{ background: current }" aria-hidden="true"></i>
        <input
          type="text"
          inputmode="text"
          spellcheck="false"
          autocomplete="off"
          maxlength="7"
          :value="shown"
          :aria-label="t.__('Custom color as a hex code', 'membuild-community')"
          placeholder="#5b4bff"
          @input="type($event.target.value)"
          @blur="leave"
        />
      </span>
    </label>
  </div>
  <small v-if="!valid" class="err">{{ t.__('Type a color code, like #5b4bff.', 'membuild-community') }}</small>
</template>

<style scoped>
.color-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
}

.hex {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 600;
}

/* The settings page's own input style, with the colour shown inside it. */
.hex-input {
  position: relative;
  display: block;
  width: 150px;
}

.hex-input i {
  position: absolute;
  top: 50%;
  left: 12px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  transform: translateY(-50%);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--on-color) 15%, transparent);
  pointer-events: none;
}

/* Specific enough to win over the settings page's input style, which sets the padding. */
.hex .hex-input input[type='text'] {
  width: 100%;
  padding-left: 36px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.hex .hex-input.bad input[type='text'] {
  border-color: var(--danger);
}
</style>
