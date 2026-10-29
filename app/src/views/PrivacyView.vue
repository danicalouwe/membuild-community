<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { cfg, levelLabel } from '../config.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import { t } from '../lib/i18n.js'

// Privacy: what other members can see about you. One card for now, Online status.
// Hiding is fair both ways: nobody sees you, and you see nobody's dot. Saves by itself.
const show = ref(true)
const available = ref(true)
const loaded = ref(false)
const status = ref('') // '' | 'saving' | 'saved' | 'error'
const error = ref('')

onMounted(async () => {
  try {
    const res = await api.privacy()
    show.value = res.show_online
    available.value = res.available
  } catch (e) {
    error.value = e.message
  }
  loaded.value = true
})

async function toggle(on) {
  show.value = on
  status.value = 'saving'
  try {
    show.value = (await api.savePrivacy({ show_online: on })).show_online
    status.value = 'saved'
  } catch (e) {
    status.value = 'error'
    error.value = e.message
    show.value = !on
  }
}
// The preview: yourself as others see you, active right now.
const me = () => ({ ...cfg.user, active_at: show.value ? new Date().toISOString() : null })
</script>

<template>
  <div class="privacy">
    <RouterLink :to="{ name: 'profile-edit' }" class="back-link"><Icon name="chevronLeft" :size="18" /> {{ t.__('Edit profile', 'membuild-community') }}</RouterLink>
    <div class="head">
      <h1 class="page-title">{{ t.__('Privacy', 'membuild-community') }}</h1>
      <span v-if="status === 'saved'" class="status-tag lg ok"><Icon name="check" :size="16" /> {{ t.__('Saved', 'membuild-community') }}</span>
      <span v-else-if="status === 'saving'" class="status-tag lg">{{ t.__('Saving…', 'membuild-community') }}</span>
    </div>
    <p class="page-intro">{{ t.__('Choose what other members can see about you. Changes save by themselves.', 'membuild-community') }}</p>

    <p v-if="error" class="error-text" role="alert">{{ error }}</p>

    <section v-if="loaded" class="card box">
      <h2>{{ t.__('Online status', 'membuild-community') }}</h2>
      <template v-if="available">
        <label class="row">
          <span>
            <strong>{{ t.__('Show when I’m online', 'membuild-community') }}</strong>
            <small>{{ t.__('While you’re using the community, other members see a green dot on your picture and “Active now” in chats.', 'membuild-community') }}</small>
          </span>
          <input type="checkbox" class="switch" role="switch" :checked="show" :disabled="status === 'saving'" @change="toggle($event.target.checked)" />
        </label>

        <div class="preview">
          <p class="preview-head">{{ t.__('What everyone in the community sees, admins included', 'membuild-community') }}</p>
          <div class="preview-body">
            <Avatar :user="me()" :size="48" online />
            <span>
              <strong>{{ cfg.user.name }}</strong>
              <small v-if="show" class="now">{{ t.__('Active now', 'membuild-community') }}</small>
              <small v-else>{{ levelLabel(cfg.user.level) }}</small>
            </span>
          </div>
        </div>

        <p v-if="show" class="note"><Icon name="info" :size="18" /> {{ t.__('If you turn this off, nobody sees when you’re online, and you won’t see when other members are online either. It’s fair both ways.', 'membuild-community') }}</p>
        <p v-else class="note warn"><Icon name="eyeOff" :size="18" /> {{ t.__('You’re hidden. Nobody sees when you’re online, and you won’t see green dots on other members either. Your posts, comments and messages still show as normal.', 'membuild-community') }}</p>
      </template>
      <p v-else class="page-intro">{{ t.__('This community doesn’t show who’s online, so there’s nothing to hide.', 'membuild-community') }}</p>
    </section>

  </div>
</template>

<style scoped>
.privacy {
  max-width: 760px;
  margin: 0 auto;
  padding: 24px 16px 40px;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.box {
  padding: 28px 30px;
  border-radius: var(--radius-lg);
}

h2 {
  margin: 0 0 18px;
  font-size: var(--fs-3xl);
}

.row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  cursor: pointer;
}

.row span,
.preview-body span {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.row strong {
  font-size: var(--fs-xl);
}

.row small {
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.switch {
  appearance: none;
  flex: none;
  position: relative;
  width: 52px;
  height: 30px;
  margin: 0;
  border-radius: var(--radius-pill);
  background: var(--border-strong);
  cursor: pointer;
  transition: background 0.15s;
}

.switch::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--on-color);
  box-shadow: var(--shadow-sm);
  transition: transform 0.15s;
}

.switch:checked {
  background: var(--accent);
}

.switch:checked::after {
  transform: translateX(22px);
}

.preview {
  margin-top: 26px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.preview-head {
  margin: 0;
  padding: 10px 16px;
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 700;
}

.preview-body {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
}

.preview-body strong {
  font-size: var(--fs-xl);
}

.preview-body small {
  color: var(--text-2);
  font-size: var(--fs-base);
}

.preview-body .now {
  color: var(--ok-text);
  font-weight: 700;
}

.note {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 16px 0 0;
  padding: 14px 18px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  line-height: 1.5;
}

.note :deep(svg) {
  flex: none;
  margin-top: 2px;
}

.note.warn {
  background: var(--warn-soft);
  color: var(--warn-text);
}

@media (max-width: 600px) {
  .box {
    padding: 22px 18px;
  }
}
</style>
