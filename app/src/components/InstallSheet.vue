<script setup>
import { ref } from 'vue'
import { cfg } from '../config.js'
import { pwa } from '../lib/pwa.js'
import Icon from './Icon.vue'
import { pieces, t } from '../lib/i18n.js'
import { useDialog } from '../lib/useDialog.js'

// How to add the community to the home screen when the browser has no install button of its own.
const emit = defineEmits(['close'])
const sheet = ref(null)
useDialog(sheet, { onClose: () => emit('close') })

// Each step is one sentence; the bold words (and the Share icon) fill its placeholders.
const iosSteps = [
  {
    parts: pieces(/* translators: 1: the Share button's name, in bold, 2: the Share icon */ t.__("Tap the %1$s button %2$s in Safari's toolbar.", 'membuild-community')),
    strong: [t.__('Share', 'membuild-community')],
    icon: 2,
  },
  { parts: pieces(/* translators: %s: Safari's menu item, in bold */ t.__('Scroll down and tap %s.', 'membuild-community')), strong: [t.__('Add to Home Screen', 'membuild-community')] },
  { parts: pieces(/* translators: %s: the button's name, in bold */ t.__('Tap %s. The icon appears on your home screen.', 'membuild-community')), strong: [t.__('Add', 'membuild-community')] },
]
const otherSteps = [
  { parts: pieces(/* translators: %s: the menu symbol ⋮, in bold */ t.__("Open your browser's menu %s (or the install icon in the address bar).", 'membuild-community')), strong: ['⋮'] },
  {
    parts: pieces(/* translators: 1: a browser menu item, in bold, 2: another browser menu item, in bold */ t.__('Choose %1$s or %2$s.', 'membuild-community')),
    strong: [t.__('Install app', 'membuild-community'), t.__('Add to Home screen', 'membuild-community')],
  },
  { parts: [t.__('Confirm. The app opens in its own window.', 'membuild-community')], strong: [] },
]
const heading = t.sprintf(/* translators: %s: the site's name */ t.__('Get the %s app', 'membuild-community'), cfg.siteName)
</script>

<template>
  <Teleport to="body">
    <div class="backdrop" @mousedown.self="emit('close')">
      <div ref="sheet" class="sheet" role="dialog" aria-modal="true" :aria-label="t.__('Add to your phone', 'membuild-community')">
        <header>
          <h2>{{ heading }}</h2>
          <button class="round round-btn" :aria-label="t.__('Close', 'membuild-community')" @click="emit('close')"><Icon name="close" :size="20" /></button>
        </header>
        <p class="lead">{{ t.__('Open the community from your home screen, full screen, like any other app.', 'membuild-community') }}</p>

        <ol class="steps">
          <li v-for="(step, s) in pwa.ios ? iosSteps : otherSteps" :key="s">
            <template v-for="(p, i) in step.parts" :key="i">
              <template v-if="typeof p === 'string'">{{ p }}</template>
              <svg v-else-if="p === step.icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :aria-label="t.__('Share icon', 'membuild-community')"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6" /></svg>
              <strong v-else>{{ step.strong[p - 1] }}</strong>
            </template>
          </li>
        </ol>

        <button class="btn btn-primary done" @click="emit('close')">{{ t.__('Got it', 'membuild-community') }}</button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.backdrop {
  position: fixed;
  inset: 0;
  z-index: 90;
  display: grid;
  place-items: end center;
  background: color-mix(in srgb, var(--backdrop) 60%, transparent);
}

@media (min-width: 768px) {
  .backdrop {
    place-items: center;
  }
}

.sheet {
  width: min(440px, 100%);
  padding: 18px 20px calc(20px + env(safe-area-inset-bottom));
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  background: var(--surface);
  box-shadow: var(--shadow-pop);
}

@media (min-width: 768px) {
  .sheet {
    border-radius: var(--radius-lg);
  }
}

header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

h2 {
  margin: 0;
  font-size: var(--fs-3xl);
}


.lead {
  margin: 8px 0 14px;
  color: var(--text-2);
}

.steps {
  margin: 0;
  padding-left: 22px;
  display: grid;
  gap: 10px;
}

.steps svg {
  vertical-align: -3px;
  color: var(--accent-text);
}

.done {
  width: 100%;
  margin-top: 18px;
  padding: 11px;
  border-radius: var(--radius-sm);
  font-weight: 700;
}
</style>
