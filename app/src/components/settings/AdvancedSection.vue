<script setup>
import { useSettings } from './context.js'
import EncryptionKey from './EncryptionKey.vue'
import { t } from '../../lib/i18n.js'

// Settings most communities never need to change, kept at the end on purpose.
const { form, meta, savedForm } = useSettings()
</script>

<template>
  <section id="s-advanced" class="card group">
    <h2>{{ t.__('Advanced', 'membuild-community') }}</h2>

    <label class="field">
      <span class="label">{{ t.__('Community address', 'membuild-community') }}</span>
      <input v-model="form.slug" type="text" maxlength="40" :aria-label="t.__('Community address', 'membuild-community')" />
      <small>{{ meta.home_url }}<strong>{{ form.slug }}</strong></small>
      <small v-if="form.slug !== savedForm.slug" class="warn">{{ t.__('Old links to the community will stop working after you save.', 'membuild-community') }}</small>
    </label>

    <EncryptionKey />

    <label class="toggle-row danger-zone">
      <span>
        <strong>{{ t.__('Delete all community data when the plugin is deleted', 'membuild-community') }}</strong>
        <small>{{ t.__('Off by default, so removing the plugin by mistake never wipes your community. When on, deleting the plugin from the Plugins screen permanently removes posts, comments, messages, courses, points, uploads and settings. Videos on Bunny Stream stay in your Bunny account.', 'membuild-community') }}</small>
      </span>
      <input v-model="form.delete_data" type="checkbox" class="switch" role="switch" />
    </label>
  </section>
</template>

<style scoped>
.danger-zone {
  margin-top: 8px;
  padding: 14px;
  border: 1px solid color-mix(in srgb, var(--danger) 45%, transparent);
  border-radius: 12px;
}
</style>
