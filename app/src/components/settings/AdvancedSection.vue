<script setup>
import { askConfirm } from '../../lib/confirm.js'
import { useSettings } from './context.js'
import EncryptionKey from './EncryptionKey.vue'
import { t } from '../../lib/i18n.js'

// Settings most communities never need to change, kept at the end on purpose.
const { form, meta, savedForm } = useSettings()

/** "Delete everything" asks first; "Keep everything" (the safe one) never does. */
async function keepOnRemove(keep) {
  if (keep) {
    form.value.delete_data = false
    return
  }
  const yes = await askConfirm({
    title: t.__('Delete everything when the plugin is removed?', 'membuild-community'),
    text: t.__('If you or anyone removes the plugin, all posts, messages, courses, points and uploads are deleted. There’s no undo.', 'membuild-community'),
    tick: t.__('I understand', 'membuild-community'),
    confirm: t.__('Yes, delete it all', 'membuild-community'),
    cancel: t.__('Keep everything', 'membuild-community'),
    danger: true,
  })
  form.value.delete_data = yes
}
</script>

<template>
  <section id="s-advanced" class="card group">
    <h2>{{ t.__('Address & safety key', 'membuild-community') }}</h2>

    <p class="intro">{{ t.__('Set these once.', 'membuild-community') }}</p>
    <label class="field">
      <span class="label">{{ t.__('Community address', 'membuild-community') }}</span>
      <span class="url"><span class="prefix">{{ meta.home_url.replace(/^https?:\/\//, '') }}</span><input v-model="form.slug" type="text" maxlength="40" :aria-label="t.__('Community address', 'membuild-community')" /></span>
      <small v-if="form.slug !== savedForm.slug" class="warn">{{ t.__('Old links to the community will stop working after you save.', 'membuild-community') }}</small>
      <small v-else>{{ t.__('Change it before you invite members, so links keep working.', 'membuild-community') }}</small>
    </label>

    <hr class="rule" />

    <EncryptionKey />

    <hr class="rule" />

    <!-- Calm by default: the safe choice is picked and looks safe; red only once Delete is chosen. -->
    <fieldset class="field">
      <legend class="label">{{ t.__('When the plugin is removed', 'membuild-community') }}</legend>
      <div class="choices cols-2">
        <label class="choice" :class="{ on: !form.delete_data }">
          <input type="radio" name="delete_data" :checked="!form.delete_data" @change="keepOnRemove(true)" />
          <span class="mark" /><strong>{{ t.__('Keep everything', 'membuild-community') }}</strong>
          <small>{{ t.__('Your community stays in the database. Install the plugin again and it’s all back. (Safe)', 'membuild-community') }}</small>
        </label>
        <label class="choice wipe" :class="{ on: form.delete_data }">
          <input type="radio" name="delete_data" :checked="form.delete_data" @click.prevent="!form.delete_data && keepOnRemove(false)" />
          <span class="mark" /><strong>{{ t.__('Delete everything', 'membuild-community') }}</strong>
          <small>{{ t.__('Posts, messages, courses, points and uploads are deleted for good.', 'membuild-community') }}</small>
        </label>
      </div>
      <small v-if="form.delete_data" class="danger-line" role="status">{{ t.__('On: removing the plugin will delete your whole community. You can’t undo it.', 'membuild-community') }}</small>
    </fieldset>
  </section>
</template>

<style scoped>
.group .choice.wipe.on {
  border-color: var(--danger-strong);
  background: var(--danger-soft);
}

.group .choice.wipe.on strong {
  color: var(--danger-text);
}

.group .choice.wipe.on .mark {
  border-color: var(--danger-strong);
  background: radial-gradient(circle, var(--surface) 0 28%, var(--danger-strong) 31%);
}

.group .danger-line {
  color: var(--danger-text);
  font-weight: 650;
}
</style>
