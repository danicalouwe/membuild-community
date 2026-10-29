<script setup>
import { useRoute, useRouter } from 'vue-router'
import { hideToast, toast } from '../lib/toast.js'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

const route = useRoute()
const router = useRouter()
// "View post": the post opens on top of this page, like a notification does.
function act() {
  const { post } = toast.action || {}
  hideToast()
  if (post) router.push({ path: route.path, query: { ...route.query, post, comment: undefined } })
}
</script>

<template>
  <Transition name="toast">
    <div v-if="toast.message" :key="toast.id" class="toast" :class="{ has: toast.action }" role="status" aria-live="polite" @click="toast.action || hideToast()">
      <!-- A flat megaphone, two lime tones, so it reads on the dark pill. -->
      <svg v-if="toast.icon === 'megaphone'" class="ico" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M3 10.2c0-.8.6-1.4 1.4-1.4H8v6.4H4.4c-.8 0-1.4-.6-1.4-1.4z" fill="#9fcf1f" />
        <path d="M8 8.8l9.6-4.6c.7-.3 1.4.2 1.4.9v13.8c0 .7-.7 1.2-1.4.9L8 15.2z" fill="#c8f542" />
        <path d="M8.6 15.2h3l1.2 4.3c.2.7-.3 1.3-1 1.3h-.9c-.5 0-.9-.3-1-.8z" fill="#9fcf1f" />
        <rect x="20" y="10" width="2.2" height="4" rx="1.1" fill="#c8f542" />
      </svg>
      <span v-else-if="toast.emoji" class="emoji" aria-hidden="true">{{ toast.emoji }}</span>
      <span class="msg">{{ toast.message }}</span>
      <template v-if="toast.action">
        <button class="act" @click.stop="act">{{ toast.action.label }}</button>
        <button class="x" :aria-label="t.__('Close', 'membuild-community')" @click.stop="hideToast"><Icon name="close" :size="18" /></button>
      </template>
    </div>
  </Transition>
</template>

<style scoped>
.toast {
  position: fixed;
  left: 50%;
  bottom: calc(var(--tabbar-h) + 20px + env(safe-area-inset-bottom));
  z-index: 120;
  display: flex;
  align-items: center;
  gap: 10px;
  max-width: calc(100vw - 32px);
  padding: 12px 18px;
  border-radius: var(--radius-pill);
  background: var(--text);
  color: var(--surface);
  font-weight: 650;
  font-size: var(--fs-base);
  box-shadow: var(--shadow-pop);
  transform: translateX(-50%);
  cursor: pointer;
}

@media (min-width: 768px) {
  .toast {
    bottom: 28px;
  }
}

/* On a lesson the phone's lesson bar replaces the tabs; sit just above it. */
@media (max-width: 899px) {
  body:has(.lv-dock) .toast {
    bottom: calc(96px + env(safe-area-inset-bottom));
  }
}

.toast.has {
  gap: 12px;
  padding: 10px 10px 10px 20px;
  white-space: nowrap;
  cursor: default;
}

.ico {
  flex: none;
}

.act {
  display: inline-flex;
  align-items: center;
  height: 44px;
  padding: 0 18px;
  border-radius: var(--radius-pill);
  background: var(--accent);
  color: var(--ink);
  font: inherit;
  font-weight: 700;
}

.x {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  color: inherit;
  opacity: 0.75;
}

.x:hover {
  opacity: 1;
  background: color-mix(in srgb, var(--on-color) 12%, transparent);
}

@media (max-width: 600px) {
  .toast.has {
    gap: 10px;
    padding: 8px 8px 8px 16px;
  }

  .act {
    height: 40px;
    padding: 0 14px;
  }

  .x {
    width: 36px;
    height: 36px;
  }
}

.emoji {
  font-size: var(--fs-3xl);
  animation: pop 0.5s cubic-bezier(0.3, 1.6, 0.5, 1);
}

@keyframes pop {
  from {
    transform: scale(0.3);
  }
}

.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.3s cubic-bezier(0.2, 1.2, 0.3, 1);
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translate(-50%, 16px);
}
</style>
