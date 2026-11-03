<script setup>
import LookBar from '../LookBar.vue'
import { previewDisplay, restoreDisplay } from '../../lib/display.js'
import { onBeforeUnmount, ref } from 'vue'
import { startAttachment } from '../../lib/attachments.js'
import Icon from '../Icon.vue'
import ColorPick from '../ColorPick.vue'
import { initialsOf } from '../../config.js'
import { t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// Who the community is: name, description, logo and color.
const { form, meta, logoUpload } = useSettings()
const logoInput = ref(null)
const colorPick = ref(null)

function pickLogo(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file?.type.startsWith('image/')) return
  logoUpload.value = startAttachment(file)
  logoUpload.value.promise.then(() => {
    const up = logoUpload.value
    if (up?.status === 'done') form.value.logo = { id: up.media.id, url: up.previewUrl }
  })
}


function removeLogo() {
  logoUpload.value = null
  form.value.logo = null
}
// Light or dark?: the community's default. Tapping one shows it on this page at once
// (nothing is stored until Save); leaving Settings puts back the owner's own look.
function pickLook(v) {
  form.value.display = v
  previewDisplay(v)
}
onBeforeUnmount(restoreDisplay)
</script>

<template>
  <section id="s-general" class="card group">
    <h2>{{ t.__('Your community', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('How your community looks to members.', 'membuild-community') }}</p>

    <label class="field">
      <span class="label">{{ t.__('Community name', 'membuild-community') }}</span>
      <input v-model="form.name" type="text" maxlength="60" :placeholder="meta.site_name" />
      <small>{{ t.__('Shown in the top bar and the browser tab.', 'membuild-community') }}</small>
    </label>

    <label class="field">
      <span class="label">{{ t.__('Description', 'membuild-community') }}</span>
      <textarea v-model="form.description" rows="3" maxlength="300" :placeholder="t.__('What this community is about, in a sentence or two.', 'membuild-community')" />
      <small>{{ t.__('Shown on your sign-up page and when someone shares a link to the community.', 'membuild-community') }}</small>
    </label>

    <label class="field">
      <span class="label">{{ t.__('Support email', 'membuild-community') }}</span>
      <input v-model="form.support_email" type="email" maxlength="120" autocomplete="email" :placeholder="t.__('hello@yoursite.com', 'membuild-community')" />
      <small>{{ t.__('Where the Contact form sends messages, and the address shown on your Contact and legal pages, in a way spam robots can’t read. Empty: messages go to the site’s admin email and no address is shown.', 'membuild-community') }}</small>
    </label>

    <div class="field">
      <span class="label">{{ t.__('Logo', 'membuild-community') }}</span>
      <div class="logo-row">
        <span class="logo-box">
          <img v-if="form.logo" :src="form.logo.url" :alt="t.__('Logo', 'membuild-community')" />
          <span v-else-if="logoUpload?.status === 'working'" class="spin" />
          <span v-else class="initial">{{ initialsOf(form.name || meta.site_name) }}</span>
        </span>
        <button type="button" class="btn" :disabled="logoUpload?.status === 'working'" @click="logoInput.click()">
          {{ logoUpload?.status === 'working' ? t.__('Uploading…', 'membuild-community') : form.logo ? t.__('Change logo', 'membuild-community') : t.__('Upload a logo', 'membuild-community') }}
        </button>
        <button v-if="form.logo" type="button" class="link danger" @click="removeLogo">{{ t.__('Remove', 'membuild-community') }}</button>
        <input ref="logoInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickLogo" />
      </div>
      <small v-if="logoUpload?.status === 'error'" class="err">{{ logoUpload.error }}</small>
      <small v-else>{{ t.__('A square image works best.', 'membuild-community') }}</small>
    </div>

    <div class="field">
      <span class="label">{{ t.__('Main color', 'membuild-community') }}</span>
      <ColorPick ref="colorPick" v-model="form.accent" :accents="meta.accents" />
      <!-- How the colour looks on the things it colours. -->
      <div class="preview" aria-hidden="true">
        <span>{{ t.__('Preview', 'membuild-community') }}</span>
        <b class="p-btn">{{ t.__('Button', 'membuild-community') }}</b>
        <b class="p-tab">{{ t.__('Selected tab', 'membuild-community') }}</b>
        <b class="p-link">{{ t.__('A link', 'membuild-community') }}</b>
      </div>
      <small v-if="colorPick?.valid !== false">{{ t.__('The color of buttons and links. Pick one, or type your own.', 'membuild-community') }}</small>
    </div>

    <!-- The look members start with; each member can still pick their own in the menu. -->
    <fieldset class="field">
      <legend class="label">{{ t.__('Light or dark, to start with?', 'membuild-community') }}</legend>
      <LookBar :value="form.display || 'auto'" :label="t.__('Light or dark, to start with?', 'membuild-community')" @pick="pickLook" />
      <small>{{ t.__('What members see until they pick their own in their menu.', 'membuild-community') }}</small>
    </fieldset>

  </section>
</template>

<style scoped>
/* Preview: the colour on a button, a selected tab and a link. */
.preview {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 14px;
  margin-top: 14px;
  padding: 12px 16px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}

.preview span {
  color: var(--text-2);
  font-size: var(--fs-md);
}

.preview b {
  padding: 8px 16px;
  border-radius: var(--radius-pill);
  font-size: var(--fs-base);
}

.p-btn {
  background: var(--accent);
  color: var(--accent-ink);
}

.p-tab {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.p-link {
  color: var(--accent-text);
}

</style>
