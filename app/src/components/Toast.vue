<script setup>
import { toast } from '../lib/toast.js'
</script>

<template>
  <Transition name="toast">
    <div v-if="toast.message" :key="toast.id" class="toast" role="status" aria-live="polite" @click="toast.message = ''">
      <span v-if="toast.emoji" class="emoji" aria-hidden="true">{{ toast.emoji }}</span>
      <span>{{ toast.message }}</span>
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
  border-radius: 999px;
  background: var(--text);
  color: var(--surface);
  font-weight: 650;
  font-size: 15px;
  box-shadow: 0 12px 32px rgb(0 0 0 / 30%);
  transform: translateX(-50%);
  cursor: pointer;
}

@media (min-width: 768px) {
  .toast {
    bottom: 28px;
  }
}

.emoji {
  font-size: 20px;
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
