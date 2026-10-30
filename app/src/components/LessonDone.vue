<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * "Lesson done!": the small celebration after Mark Complete, in the same family
 * as reaching a level (LevelUpToast) and finishing a course (CourseComplete): a
 * round badge that pops in, a sprinkle of confetti, one plain line, and the next step.
 *
 * With a next lesson that's open, it counts down and takes the member there
 * ("Taking you to … in 5"), with Go now and Stay here. Anything that says they're
 * busy here stops it: Stay here, pressing the card, or starting to type on the page.
 */
const props = defineProps({
  done: { type: Object, default: null }, // { key, count, total, course, next: { id, title } | null, opensAt }
})
const emit = defineEmits(['next', 'close'])

const WAIT = 6 // seconds: time to read the card, then on to the next lesson
const left = ref(WAIT)
const counting = ref(false)
let tick = null
let hide = null
function stop() {
  clearInterval(tick)
  counting.value = false
}
watch(
  () => props.done?.key,
  (k) => {
    stop()
    clearTimeout(hide)
    if (!k) return
    if (props.done.next) {
      left.value = WAIT
      counting.value = true
      tick = setInterval(() => {
        left.value -= 1
        if (left.value <= 0) {
          stop()
          emit('next', props.done.next.id)
        }
      }, 1000)
    } else {
      hide = setTimeout(() => emit('close'), 7000) // nothing to go to: long enough to read, then out of the way
    }
  },
  { immediate: true },
)
// Typing a question (or anything) on the page means they're staying.
const onType = (e) => {
  if (counting.value && e.target.closest?.('input, textarea, [contenteditable="true"]')) stop()
}
onMounted(() => document.addEventListener('focusin', onType))
onBeforeUnmount(() => {
  stop()
  clearTimeout(hide)
  document.removeEventListener('focusin', onType)
})
const progressPct = computed(() => `${Math.round((100 * left.value) / WAIT)}%`)

// The same scattered confetti as finishing a course, just less of it.
const COLORS = ['#c8f542', '#ff8a3d', '#e85d9a', '#3b5bfd', '#f5c542', '#2fb36b']
const pieces = Array.from({ length: 18 }, (_, i) => {
  const r = (n) => {
    const x = Math.sin((i + 1) * 12.9898 + n * 78.233) * 43758.5453
    return x - Math.floor(x)
  }
  const round = r(1) < 0.3
  return {
    left: `${(r(3) * 96).toFixed(1)}%`,
    width: `${round ? 6 : 6 + Math.round(r(2) * 3)}px`,
    height: `${round ? 6 : 6 + Math.round(r(4) * 4)}px`,
    background: COLORS[i % COLORS.length],
    borderRadius: round ? '50%' : '2px',
    '--dx': `${Math.round((r(5) - 0.5) * 60)}px`,
    '--rot': `${Math.round((r(6) - 0.5) * 800)}deg`,
    animationDuration: `${(1.6 + r(7) * 0.6).toFixed(2)}s`,
    animationDelay: `${(r(8) * 0.5).toFixed(2)}s`,
  }
})
const progress = (d) => t.sprintf(/* translators: 1: lessons done, 2: lessons in the course */ t.__('%1$d of %2$d lessons', 'membuild-community'), d.count, d.total)
const upNext = (title) => t.sprintf(/* translators: %s: lesson title */ t.__('Next: %s', 'membuild-community'), title)
// The countdown sits in the button, so a long lesson title can never hide it: "Go now (5)".
const goNow = () => t.sprintf(/* translators: %d: seconds until the next lesson opens by itself */ t.__('Go now (%d)', 'membuild-community'), left.value)
const opensOn = (iso) => t.sprintf(/* translators: %s: a date, e.g. "Thu, Oct 29" */ t.__('Next lesson opens %s', 'membuild-community'), new Date(iso).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }))
</script>

<template>
  <Transition name="pop-card">
    <div v-if="done" :key="done.key" class="pop-card ld" :class="{ counting }" role="status" aria-live="polite">
      <span class="confetti" aria-hidden="true"><i v-for="(p, n) in pieces" :key="n" :style="p" /></span>
      <span class="badge"><Icon name="check" :size="28" /></span>
      <div class="text" @pointerdown="stop">
        <strong>{{ t.__('Lesson done! Nice work.', 'membuild-community') }}</strong>
        <!-- One row: how far you are, then what's next (a long title ends in …). -->
        <p class="line">
          <span class="prog">{{ progress(done) }}<span class="dot"> · </span></span>
          <b v-if="done.next" class="next">{{ upNext(done.next.title) }}</b>
          <span v-else-if="done.opensAt" class="next">{{ opensOn(done.opensAt) }}</span>
        </p>
      </div>
      <div class="actions" :class="{ solo: !done.next }">
        <template v-if="done.next">
          <button v-if="counting" class="btn btn-lg btn-ghost stay" @click="stop">{{ t.__('Stay here', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-primary" @click="stop(); emit('next', done.next.id)">{{ counting ? goNow() : t.__('Next lesson', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
        </template>
        <button v-if="!counting" class="close" :aria-label="t.__('Close', 'membuild-community')" @click="emit('close')"><Icon name="close" :size="20" /></button>
      </div>
      <!-- The countdown, as a thin line emptying along the bottom. -->
      <span v-if="counting" class="timer" aria-hidden="true"><i :style="{ width: progressPct }" /></span>
    </div>
  </Transition>
</template>

<style scoped>
.ld {
  display: flex;
  align-items: center;
  gap: 16px; /* place, size and the pop-in are the shared .pop-card */
}

.badge {
  flex: none;
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--ok), var(--ok-strong));
  color: var(--on-color);
  box-shadow: 0 8px 22px color-mix(in srgb, var(--ok) 35%, transparent);
  animation: pop 0.6s cubic-bezier(0.3, 1.6, 0.5, 1);
}

@keyframes pop {
  from {
    transform: scale(0.3) rotate(-20deg);
  }
}

.text {
  flex: 1;
  min-width: 0;
}

.text strong {
  display: block;
  font-size: var(--fs-2xl);
}

.text p {
  margin: 3px 0 0;
  font-size: var(--fs-lg);
  color: var(--text-2);
}

.stay {
  background: var(--surface-2);
  color: var(--text);
}

.timer {
  position: absolute;
  right: 18px;
  bottom: 0;
  left: 18px;
  height: 4px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  overflow: hidden;
}

.timer i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
  transition: width 1s linear;
}

.text .line {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.text .line b {
  color: var(--text);
  font-weight: 600;
}

.ld {
  flex-wrap: wrap; /* the buttons get their own row, so the text keeps the whole width */
  row-gap: 12px;
}

.actions {
  display: flex;
  flex: 1 1 100%;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}

/* Only the ✕ (no next lesson): no empty row under the text. */
.actions.solo {
  display: contents;
}

/* With the buttons below, the ✕ sits in the corner. */
.ld .close {
  position: absolute;
  top: 10px;
  right: 10px;
}

.text {
  padding-right: 36px;
}

.close {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  color: var(--text-2);
}

.close:hover {
  background: var(--surface-2);
}

.confetti {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.confetti i {
  position: absolute;
  top: -14px;
  opacity: 0;
  animation-name: fall;
  animation-timing-function: cubic-bezier(0.2, 0.6, 0.4, 1);
  animation-fill-mode: both;
}

@keyframes fall {
  0% {
    opacity: 0;
    transform: translate(0, 0) rotate(0);
  }
  10% {
    opacity: 1;
  }
  70% {
    opacity: 1;
  }
  100% {
    opacity: 0;
    transform: translate(var(--dx), 150px) rotate(var(--rot));
  }
}

/* Phones: above the lesson's own bottom bar, the button on its own row. */
@media (max-width: 899px) {
  .ld {
    bottom: calc(92px + env(safe-area-inset-bottom));
    flex-wrap: wrap;
  }

  .actions {
    flex: 1 1 100%;
  }

  .actions .btn {
    flex: 1;
  }

  .close {
    position: absolute;
    top: 8px;
    right: 8px;
  }
}

/* Small phones: a smaller tick, the title on one line, "Next:" on its own line, and
   two even buttons that never wrap. The ✕ only shows once the countdown has stopped. */
@media (max-width: 600px) {
  .ld {
    gap: 12px;
    row-gap: 14px;
    padding: 14px;
  }

  .ld.counting {
    padding-bottom: 20px;
  }

  .badge {
    width: 44px;
    height: 44px;
  }

  .badge :deep(svg) {
    width: 22px;
    height: 22px;
  }

  .ld.counting .text {
    padding-right: 0;
  }

  .text strong {
    font-size: var(--fs-xl);
  }

  .text .line {
    white-space: normal;
  }

  .text .line .dot {
    display: none;
  }

  .text .line .prog,
  .text .line .next {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .text .line .next {
    margin-top: 2px;
  }

  .actions .btn :deep(svg) {
    flex: none;
  }

  .actions .btn {
    flex: 1 1 0;
    min-width: 0;
    padding: 0 12px;
    white-space: nowrap;
  }

  .timer {
    right: 14px;
    bottom: 8px;
    left: 14px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .confetti {
    display: none;
  }

  .badge {
    animation: none;
  }
}
</style>
