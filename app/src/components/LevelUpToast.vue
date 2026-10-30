<script setup>
import { levelLabel } from '../config.js'
import { onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { me } from '../stores/me.js'
import { levelShown } from '../lib/popups.js'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * Celebrates a new level, and names any courses it just unlocked. The same card as
 * "Lesson done" (size, badge, words, buttons), in the same place, and only once
 * that card is gone (lib/popups.js), so the two never cover each other.
 */
const router = useRouter()
let timer = null
watch(
  levelShown,
  (v) => {
    clearTimeout(timer)
    if (v) timer = setTimeout(close, 6000) // the time starts when it shows, not when it was earned
  },
  { immediate: true },
)
onBeforeUnmount(() => clearTimeout(timer))
function close() {
  me.levelUp = null
}
function seeLevels() {
  close()
  router.push({ name: 'leaderboard' })
}
const title = (level) => t.sprintf(/* translators: %s: the new level, e.g. "Level 3 · Builder" */ t.__('%s! 🎉', 'membuild-community'), levelLabel(level))
const unlocked = (items) => t.sprintf(/* translators: %s: course titles, comma-separated */ t.__('Unlocked: %s', 'membuild-community'), items.map((u) => u.title).join(', '))
</script>

<template>
  <Transition name="pop-card">
    <div v-if="levelShown" :key="me.levelUp.level" class="pop-card lu" role="status" aria-live="polite">
      <span class="confetti" aria-hidden="true"><i v-for="n in 14" :key="n" :style="{ '--i': n }" /></span>
      <span class="badge">{{ me.levelUp.level }}</span>
      <div class="text">
        <strong>{{ title(me.levelUp.level) }}</strong>
        <p class="line">{{ me.levelUp.unlocked.length ? unlocked(me.levelUp.unlocked) : t.__('You reached a new level. Keep posting and learning.', 'membuild-community') }}</p>
      </div>
      <div class="actions">
        <button type="button" class="btn btn-lg btn-ghost soft" @click="seeLevels">{{ t.__('See levels', 'membuild-community') }}</button>
        <button type="button" class="btn btn-lg btn-primary" @click="close">{{ t.__('Nice!', 'membuild-community') }}</button>
      </div>
      <button type="button" class="close" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="20" /></button>
    </div>
  </Transition>
</template>

<style scoped>
.lu {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
}

.badge {
  display: grid;
  place-items: center;
  flex: none;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: var(--fs-5xl);
  font-weight: 800;
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
  padding-right: 36px;
}

.text strong {
  display: block;
  overflow: hidden;
  font-size: var(--fs-2xl);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.line {
  margin: 3px 0 0;
  overflow: hidden;
  font-size: var(--fs-lg);
  color: var(--text-2);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.actions {
  display: flex;
  flex: 1 1 100%;
  justify-content: flex-end;
  gap: 8px;
}

.soft {
  background: var(--surface-2);
  color: var(--text);
}

.close {
  position: absolute;
  top: 10px;
  right: 10px;
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
  top: -8px;
  left: calc(var(--i) * 7%);
  width: 7px;
  height: 11px;
  border-radius: var(--radius-xs);
  background: hsl(calc(var(--i) * 47) 85% 60%); /* exempt: confetti colours */
  animation: fall 1.4s ease-in forwards;
  animation-delay: calc(var(--i) * 40ms);
}

@keyframes fall {
  to {
    transform: translateY(150px) rotate(calc(var(--i) * 60deg));
    opacity: 0;
  }
}

/* Small phones: the same smaller sizes as "Lesson done". */
@media (max-width: 600px) {
  .lu {
    gap: 14px 12px;
    padding: 14px;
  }

  .badge {
    width: 44px;
    height: 44px;
    font-size: var(--fs-3xl);
  }

  .text strong {
    font-size: var(--fs-xl);
  }

  .actions .btn {
    flex: 1;
  }
}
</style>
