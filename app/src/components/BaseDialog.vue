<script setup>
import { ref, useId } from 'vue'
import { useDialog } from '../lib/useDialog.js'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * The shared modal shell: backdrop, centered panel, a titled header with a
 * close button, a scrolling body and an optional pinned footer. Escape,
 * focus and scroll locking come from useDialog.
 *
 * `close` is emitted with why: 'escape' | 'backdrop' | 'button', so a dialog
 * with sub-views can treat Escape as "back". `scroll` is the body's, for
 * infinite lists. A custom #header slot gets `close` to wire its own button.
 */
defineOptions({ inheritAttrs: false })
const props = defineProps({
  title: { type: String, default: '' },
  label: { type: String, default: '' }, // accessible name when the heading isn't enough (or there is none)
  width: { type: Number, default: 500 },
  maxHeight: { type: String, default: '' }, // e.g. '640px'; always capped to the screen
  z: { type: Number, default: 90 },
  phone: { type: String, default: 'center' }, // how it sits on phones: center | full | sheet
})
const emit = defineEmits(['close', 'scroll'])

const panel = ref(null)
useDialog(panel, { onClose: () => emit('close', 'escape') })

const headingId = useId()
const panelStyle = () => ({
  width: `min(${props.width}px, 100%)`,
  maxHeight: props.maxHeight ? `min(${props.maxHeight}, calc(100dvh - 48px))` : null,
})
</script>

<template>
  <Teleport to="body">
    <div class="backdrop" :class="`phone-${phone}`" :style="{ zIndex: z }" @mousedown.self="emit('close', 'backdrop')">
      <div
        ref="panel"
        class="dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="label || !title ? null : headingId"
        :aria-label="label || null"
        :style="panelStyle()"
        v-bind="$attrs"
      >
        <slot name="header" :close="() => emit('close', 'button')">
          <header class="top">
            <h2 :id="headingId">{{ title }}</h2>
            <button class="round round-btn" :aria-label="t.__('Close', 'membuild-community')" @click="emit('close', 'button')"><Icon name="close" :size="20" /></button>
          </header>
        </slot>
        <div class="content" @scroll.passive="emit('scroll', $event)"><slot /></div>
        <footer v-if="$slots.footer" class="foot"><slot name="footer" /></footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 24px;
  background: rgb(10 11 13 / 72%);
  animation: fade 0.15s ease;
}

@keyframes fade {
  from {
    opacity: 0;
  }
}

.dialog {
  position: relative;
  display: flex;
  flex-direction: column;
  max-height: calc(100dvh - 48px);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: 0 20px 60px rgb(0 0 0 / 40%);
  overflow: hidden;
  outline: none;
}

.top {
  position: relative;
  display: grid;
  place-items: center;
  height: 60px;
  flex: none;
  padding: 0 60px;
  border-bottom: 1px solid var(--border);
}

.top h2 {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  text-align: center;
  text-transform: none;
}

.round {
  position: absolute;
  right: 14px;
  top: 50%;
  translate: 0 -50%;
}

.content {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.foot {
  flex: none;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 12px 18px;
  border-top: 1px solid var(--border);
}

@media (max-width: 767px) {
  .phone-full,
  .phone-sheet {
    padding: 0;
  }

  .phone-full .dialog {
    width: 100% !important;
    height: 100dvh;
    max-height: none !important;
    border: 0;
    border-radius: 0;
  }

  .phone-sheet {
    place-items: end stretch;
  }

  .phone-sheet .dialog {
    width: 100% !important;
    max-height: 85dvh !important;
    border-radius: 16px 16px 0 0;
  }
}
</style>
