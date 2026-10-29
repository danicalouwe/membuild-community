<script setup>
import { useSettings } from './context.js'
import EncryptionKey from './EncryptionKey.vue'
import Icon from '../Icon.vue'
import { t } from '../../lib/i18n.js'

// Settings most communities never need to change, kept at the end on purpose.
const { form, meta, savedForm } = useSettings()
</script>

<template>
  <section id="s-advanced" class="card group">
    <h2>{{ t.__('Advanced', 'membuild-community') }}</h2>

    <p class="intro">{{ t.__('Things you set once and rarely touch.', 'membuild-community') }}</p>
    <label class="field">
      <span class="label">{{ t.__('Community address', 'membuild-community') }}</span>
      <span class="url"><span class="prefix">{{ meta.home_url.replace(/^https?:\/\//, '') }}</span><input v-model="form.slug" type="text" maxlength="40" :aria-label="t.__('Community address', 'membuild-community')" /></span>
      <small v-if="form.slug !== savedForm.slug" class="warn">{{ t.__('Old links to the community will stop working after you save.', 'membuild-community') }}</small>
      <small v-else>{{ t.__('Change it before you invite members, so links keep working.', 'membuild-community') }}</small>
    </label>

    <hr class="rule" />

    <EncryptionKey />

  </section>

  <!-- Danger: kept apart from everything else, so nobody switches it on by accident. -->
  <section id="s-danger" class="card group danger-card">
    <h2 class="danger-title"><Icon name="alert" :size="22" /> {{ t.__('Danger', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('This can’t be undone. Leave it off unless you’re removing the community for good.', 'membuild-community') }}</p>
    <label class="toggle-row first">
      <span>
        <strong>{{ t.__('Delete everything when the plugin is deleted', 'membuild-community') }}</strong>
        <small>{{ t.__('Off, so removing the plugin by mistake never wipes your community. When on, deleting it removes all posts, messages, courses, points and uploads. Videos on Bunny Stream stay in your Bunny account.', 'membuild-community') }}</small>
      </span>
      <input v-model="form.delete_data" type="checkbox" class="switch" role="switch" />
    </label>
  </section>
</template>

<style scoped>
.danger-card {
  border: 1px solid color-mix(in srgb, var(--danger) 45%, transparent) !important;
  background: var(--danger-soft);
}

.danger-title {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--danger-text);
}
</style>
