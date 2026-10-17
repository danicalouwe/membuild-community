<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { REACTIONS } from '../lib/reactions.js'
import Icon from './Icon.vue'
import ReactionBadge from './ReactionBadge.vue'
import { t } from '../lib/i18n.js'
import { pushLayer } from '../lib/layers.js'

/**
 * Tap toggles a like. Long-press (touch) or hover (mouse) opens the picker.
 * From the keyboard: Enter/Space likes, holding it (or ArrowUp) opens the
 * picker, arrows move, Enter picks, Escape closes.
 * Used for posts (with a count) and comments (icon only).
 */
const props = defineProps({
  current: { type: String, default: null }, // my reaction, or null
  count: { type: [Number, String], default: null }, // shown next to the icon when set, e.g. "7.9K"
  labelled: { type: Boolean, default: false }, // show "Like" (or your reaction's name, in its color) beside the icon
  size: { type: Number, default: 22 },
})
const emit = defineEmits(['react']) // (type | null)

const open = ref(false)
const root = ref(null)
const tray = ref(null)
const trigger = ref(null)
let pressTimer = null
let hoverTimer = null
let keyTimer = null
let longPressed = false
let keyHeld = false
let swallowKeyup = false // the held key is released over the tray: that release must not pick
let removeLayer = null

const choices = () => (tray.value ? [...tray.value.querySelectorAll('.choice')] : [])

// While open: a tap anywhere else closes it, and Escape/Tab go through the shared layer stack.
function onDoc(e) {
  if (root.value && !root.value.contains(e.target)) open.value = false
}
watch(open, (on) => {
  if (on && !removeLayer) {
    document.addEventListener('pointerdown', onDoc)
    removeLayer = pushLayer({ root: () => tray.value, close: closeTray, trap: false })
  } else if (!on && removeLayer) {
    document.removeEventListener('pointerdown', onDoc)
    removeLayer()
    removeLayer = null
  }
})

function closeTray({ focus = true } = {}) {
  const hadFocus = tray.value?.contains(document.activeElement)
  open.value = false
  if (focus || hadFocus) trigger.value?.focus({ preventScroll: true })
}

function openTray(focusFirst) {
  clearTimeout(hoverTimer)
  open.value = true
  if (focusFirst) nextTick(() => choices()[0]?.focus())
}

const active = computed(() => REACTIONS.find((r) => r.type === props.current))
const label = computed(() =>
  active.value
    ? /* translators: %s: the reaction you gave, e.g. "Love" */
      t.sprintf(t.__('%s (tap to remove)', 'membuild-community'), active.value.label)
    : t._x('Like', 'reaction', 'membuild-community'),
)

function onPointerDown(e) {
  longPressed = false
  if (e.pointerType === 'mouse') return
  pressTimer = setTimeout(() => {
    longPressed = true
    open.value = true
    navigator.vibrate?.(10)
  }, 400)
}
function onPointerUp() {
  clearTimeout(pressTimer)
}
function onClick() {
  if (longPressed) return
  open.value = false
  emit('react', props.current ? null : 'like')
}
function onEnter(e) {
  if (e.pointerType !== 'mouse') return
  clearTimeout(hoverTimer)
  hoverTimer = setTimeout(() => (open.value = true), 500)
}
function keepOpen() {
  clearTimeout(hoverTimer)
}
function onLeave(e) {
  // Touch and pen fire pointerleave as the finger lifts, which would close a long-press tray at once.
  if (e.pointerType !== 'mouse') return
  clearTimeout(hoverTimer)
  hoverTimer = setTimeout(() => (open.value = false), 250)
}
function pick(type) {
  const hadFocus = tray.value?.contains(document.activeElement)
  open.value = false
  if (hadFocus) trigger.value?.focus({ preventScroll: true })
  emit('react', props.current === type ? null : type)
}

/* Keyboard on the button: a quick press likes, holding it opens the tray. */
function onTriggerKeydown(e) {
  if (e.key === 'ArrowUp') {
    e.preventDefault()
    openTray(true)
  } else if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault() // the button's own click would like straight away
    if (e.repeat || keyHeld) return
    keyHeld = true
    longPressed = false
    keyTimer = setTimeout(() => {
      longPressed = true
      keyHeld = false
      swallowKeyup = true
      openTray(true)
    }, 400)
  }
}
function onTriggerKeyup(e) {
  if (e.key !== 'Enter' && e.key !== ' ') return
  e.preventDefault()
  clearTimeout(keyTimer)
  const wasLong = longPressed
  keyHeld = false
  longPressed = false
  if (!wasLong) {
    open.value = false
    emit('react', props.current ? null : 'like')
  }
}

/* Keyboard in the tray: arrows move between reactions. */
function onTrayKeydown(e) {
  if (e.repeat && (e.key === 'Enter' || e.key === ' ')) return e.preventDefault() // still holding the key that opened it
  const list = choices()
  const i = list.indexOf(document.activeElement)
  let next = null
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = list[(i + 1) % list.length]
  else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = list[(i - 1 + list.length) % list.length]
  else if (e.key === 'Home') next = list[0]
  else if (e.key === 'End') next = list.at(-1)
  if (next) {
    e.preventDefault()
    next.focus()
  }
}

function onTrayKeyup(e) {
  if (swallowKeyup && (e.key === 'Enter' || e.key === ' ')) e.preventDefault()
  swallowKeyup = false
}

onBeforeUnmount(() => {
  clearTimeout(pressTimer)
  clearTimeout(hoverTimer)
  clearTimeout(keyTimer)
  open.value = false
  document.removeEventListener('pointerdown', onDoc)
  removeLayer?.()
})
</script>

<template>
  <div ref="root" class="react" @pointerenter="onEnter" @pointerleave="onLeave">
    <!-- Facebook-style tray: the reactions spring up one after another, each keeps
         moving in its own way, and the one under the pointer grows and shows its name. -->
    <Transition name="tray">
      <div v-if="open" ref="tray" class="picker" role="menu" :aria-label="t.__('Reactions', 'membuild-community')" @pointerenter="keepOpen" @keydown="onTrayKeydown" @keyup="onTrayKeyup">
        <button
          v-for="(r, i) in REACTIONS"
          :key="r.type"
          class="choice"
          :class="{ chosen: current === r.type }"
          :style="{ '--i': i }"
          :aria-label="r.label"
          role="menuitem"
          @click.stop="pick(r.type)"
        >
          <span class="name" aria-hidden="true">{{ r.label }}</span>
          <span class="lift">
            <span class="face" :class="`move-${r.type}`"><ReactionBadge :type="r.type" :size="40" /></span>
          </span>
        </button>
      </div>
    </Transition>

    <button
      ref="trigger"
      class="trigger"
      :class="[{ on: active }, current]"
      :aria-pressed="!!active"
      :aria-label="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      @keydown="onTriggerKeydown"
      @keyup="onTriggerKeyup"
      @pointerdown="onPointerDown"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @contextmenu.prevent
      @click="onClick"
    >
      <!-- A plain outline thumb until you react; then your reaction's face (a filled blue thumb for Like),
           and on posts the word "Like" or your reaction's name in its color, like Facebook. -->
      <span class="icon"><ReactionBadge v-if="active" :type="current" :size="size" /><Icon v-else name="thumb" :size="size" /></span>
      <span v-if="labelled" class="word" :style="active ? { color: active.color } : null">{{ active ? active.label : t._x('Like', 'reaction', 'membuild-community') }}</span>
      <span v-else-if="count" class="count">{{ count }}</span>
    </button>
  </div>
</template>

<style scoped>
.react {
  position: relative;
  display: inline-flex;
}

.trigger {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  min-height: 34px;
  padding: 0 8px;
  border-radius: 8px;
  color: var(--text-2);
  font-weight: 600;
  user-select: none;
  -webkit-touch-callout: none;
}

.trigger:hover {
  background: var(--surface-2);
}

.icon {
  display: inline-flex;
}




.picker {
  position: absolute;
  bottom: calc(100% + 6px);
  left: -6px;
  z-index: 10;
  display: flex;
  align-items: flex-end;
  gap: 4px;
  padding: 6px 8px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 999px;
  box-shadow: 0 2px 6px rgb(0 0 0 / 10%), 0 10px 28px rgb(0 0 0 / 22%);
}

.choice {
  position: relative;
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  padding: 0;
  border-radius: 50%;
  /* Each reaction springs up a moment after the one before it. */
  animation: rise 0.36s cubic-bezier(0.3, 1.6, 0.5, 1) both;
  animation-delay: calc(var(--i) * 35ms);
}

.lift {
  display: grid;
  transition: transform 0.18s cubic-bezier(0.3, 1.6, 0.5, 1);
  transform-origin: 50% 100%;
}

.choice:hover .lift,
.choice:focus-visible .lift {
  transform: translateY(-10px) scale(1.42);
}

.picker:hover .choice:not(:hover) .lift {
  transform: scale(0.94);
}

.choice:focus-visible {
  outline: none;
}

.choice.chosen::after {
  content: '';
  position: absolute;
  bottom: -3px;
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: var(--text-3);
}

/* The name bubble, as on Facebook. */
.name {
  position: absolute;
  bottom: calc(100% + 18px);
  left: 50%;
  padding: 3px 9px;
  border-radius: 999px;
  background: rgb(0 0 0 / 78%);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transform: translate(-50%, 4px);
  transition: opacity 0.12s ease, transform 0.12s ease;
}

.choice:hover .name,
.choice:focus-visible .name {
  opacity: 1;
  transform: translate(-50%, 0);
}

.face {
  display: grid;
  animation-duration: 1.6s;
  animation-iteration-count: infinite;
  animation-timing-function: ease-in-out;
  animation-delay: calc(0.35s + var(--i) * 0.12s);
}

/* Each reaction's own little movement. */
.move-like { animation-name: like; animation-duration: 1.8s; transform-origin: 30% 80%; }
.move-love { animation-name: love; animation-duration: 1.3s; }
.move-care { animation-name: care; animation-duration: 2s; }
.move-haha { animation-name: haha; animation-duration: 1.2s; }
.move-wow { animation-name: wow; animation-duration: 2s; }
.move-sad { animation-name: sad; animation-duration: 2.4s; transform-origin: 50% 0; }
.move-angry { animation-name: angry; animation-duration: 1.6s; }

@keyframes rise {
  0% { opacity: 0; transform: translateY(16px) scale(0.3); }
  100% { opacity: 1; transform: none; }
}

@keyframes like {
  0%, 55%, 100% { transform: none; }
  15% { transform: rotate(-18deg) translateY(-3px); }
  30% { transform: rotate(8deg); }
  42% { transform: rotate(-3deg); }
}

@keyframes love {
  0%, 60%, 100% { transform: scale(1); }
  15% { transform: scale(1.16); }
  30% { transform: scale(0.98); }
  45% { transform: scale(1.1); }
}

@keyframes care {
  0%, 100% { transform: rotate(0) scale(1); }
  25% { transform: rotate(-9deg) scale(1.06, 0.95); }
  50% { transform: rotate(0) scale(1); }
  75% { transform: rotate(9deg) scale(1.06, 0.95); }
}

@keyframes haha {
  0%, 60%, 100% { transform: none; }
  10%, 30%, 50% { transform: translateY(-4px) rotate(-4deg); }
  20%, 40% { transform: translateY(1px) rotate(4deg); }
}

@keyframes wow {
  0%, 60%, 100% { transform: scale(1); }
  20% { transform: translateY(-3px) scale(1.14, 1.18); }
  35% { transform: scale(0.96); }
  48% { transform: scale(1.04); }
}

@keyframes sad {
  0%, 100% { transform: none; }
  35% { transform: translateY(3px) rotate(-7deg); }
  65% { transform: translateY(2px) rotate(5deg); }
}

@keyframes angry {
  0%, 55%, 100% { transform: none; filter: none; }
  8%, 24%, 40% { transform: translateX(-2px) rotate(-5deg); filter: saturate(1.4); }
  16%, 32%, 48% { transform: translateX(2px) rotate(5deg); filter: saturate(1.4); }
}

.tray-enter-active {
  transition: opacity 0.15s ease, transform 0.22s cubic-bezier(0.3, 1.5, 0.5, 1);
}

.tray-leave-active {
  transition: opacity 0.12s ease, transform 0.12s ease;
}

.tray-enter-from,
.tray-leave-to {
  opacity: 0;
  transform: translateY(8px) scale(0.92);
}

/* Phones: seven reactions still fit next to an indented comment. */
@media (max-width: 480px) {
  .picker {
    gap: 0;
    padding: 4px 6px;
  }

  .choice {
    width: 40px;
    height: 40px;
  }

  .face :deep(.badge) {
    width: 34px !important;
    height: 34px !important;
  }
}

/* People who turned off motion get the tray without the movement. */
@media (prefers-reduced-motion: reduce) {
  .choice,
  .face {
    animation: none;
  }
}
</style>
