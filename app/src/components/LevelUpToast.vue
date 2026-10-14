<script setup>
import { levelLabel } from '../config.js'
import { watch } from 'vue'
import { me } from '../stores/me.js'
import { t } from '../lib/i18n.js'

// Celebrates a new level, and names any courses it just unlocked.
let timer = null
watch(
  () => me.levelUp,
  (v) => {
    clearTimeout(timer)
    if (v) timer = setTimeout(() => (me.levelUp = null), 6000)
  },
)
const reached = (level) => t.sprintf(/* translators: %s: the new level, e.g. "Level 3 · Builder" */ t.__('You reached %s! 🎉', 'membuild-community'), levelLabel(level))
const unlocked = (items) => t.sprintf(/* translators: %s: course titles, comma-separated */ t.__('Unlocked: %s', 'membuild-community'), items.map((u) => u.title).join(', '))
</script>

<template>
  <Transition name="toast">
    <div v-if="me.levelUp" class="toast" role="status" aria-live="polite" @click="me.levelUp = null">
      <span class="confetti" aria-hidden="true"><i v-for="n in 14" :key="n" :style="{ '--i': n }" /></span>
      <span class="badge">{{ me.levelUp.level }}</span>
      <div>
        <strong>{{ reached(me.levelUp.level) }}</strong>
        <p v-if="me.levelUp.unlocked.length">{{ unlocked(me.levelUp.unlocked) }}</p>
        <p v-else>{{ t.__('Keep posting, commenting and learning to level up.', 'membuild-community') }}</p>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.toast {
  position: fixed;
  left: 50%;
  bottom: calc(var(--tabbar-h) + 24px + env(safe-area-inset-bottom));
  z-index: 120;
  display: flex;
  align-items: center;
  gap: 14px;
  width: min(420px, calc(100vw - 24px));
  padding: 14px 18px;
  border-radius: 16px;
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: 0 16px 40px rgb(0 0 0 / 35%);
  transform: translateX(-50%);
  cursor: pointer;
  overflow: hidden;
}

@media (min-width: 768px) {
  .toast {
    bottom: 28px;
  }
}

.badge {
  display: grid;
  place-items: center;
  flex: none;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: 22px;
  font-weight: 800;
  animation: pop 0.6s cubic-bezier(0.3, 1.6, 0.5, 1);
}

@keyframes pop {
  from {
    transform: scale(0.3) rotate(-20deg);
  }
}

strong {
  display: block;
  font-size: 16px;
}

p {
  margin: 2px 0 0;
  font-size: 13px;
  color: var(--text-2);
}

.confetti i {
  position: absolute;
  top: -8px;
  left: calc(var(--i) * 7%);
  width: 7px;
  height: 11px;
  border-radius: 2px;
  background: hsl(calc(var(--i) * 47) 85% 60%);
  animation: fall 1.4s ease-in forwards;
  animation-delay: calc(var(--i) * 40ms);
}

@keyframes fall {
  to {
    transform: translateY(110px) rotate(calc(var(--i) * 60deg));
    opacity: 0;
  }
}

.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.2, 1.2, 0.3, 1);
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translate(-50%, 24px);
}
</style>
