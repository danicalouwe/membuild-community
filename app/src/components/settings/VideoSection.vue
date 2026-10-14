<script setup>
import { ref } from 'vue'
import { api } from '../../api.js'
import { around, t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// Where videos are stored: this site, or Bunny Stream.
const { form, savedForm, dirty } = useSettings()
const videoTesting = ref(false)
const videoResult = ref(null)
const copiedHook = ref(false)

const bunnyHelp = around(/* translators: %s: the menu path "Stream → your video library → API", shown in bold */ t.__('In Bunny, open %s.', 'membuild-community'))
const hookHelp = around(/* translators: %s: the setting name "Webhook URL", shown in bold */ t.__("Paste it in the library's %s setting so videos show up the moment Bunny finishes encoding.", 'membuild-community'))

async function testVideo() {
  videoTesting.value = true
  videoResult.value = null
  try {
    const res = await api.testVideo()
    videoResult.value = { ok: true, message: t.sprintf(/* translators: %d: number of videos in the Bunny library */ t._n('Connected. Your library has %d video.', 'Connected. Your library has %d videos.', res.videos, 'membuild-community'), res.videos) }
  } catch (err) {
    videoResult.value = { ok: false, message: err.message }
  } finally {
    videoTesting.value = false
  }
}

async function copyHook() {
  await navigator.clipboard.writeText(form.value.video.webhook_url).catch(() => prompt(t.__('Copy this webhook URL', 'membuild-community'), form.value.video.webhook_url))
  copiedHook.value = true
  setTimeout(() => (copiedHook.value = false), 1500)
}
</script>

<template>
  <section id="s-video" class="card group">
    <h2>{{ t.__('Video', 'membuild-community') }}</h2>
    <fieldset class="field">
      <legend class="label">{{ t.__('Where videos are stored', 'membuild-community') }}</legend>
      <div class="delivery two">
        <label class="option" :class="{ on: form.video.provider === 'local' }">
          <input v-model="form.video.provider" type="radio" name="video" value="local" />
          <strong>{{ t.__('This site', 'membuild-community') }}</strong>
          <small>{{ t.__('Videos are compressed in the browser and stored with your other uploads. Fine for small communities.', 'membuild-community') }}</small>
        </label>
        <label class="option" :class="{ on: form.video.provider === 'bunny' }">
          <input v-model="form.video.provider" type="radio" name="video" value="bunny" />
          <strong>Bunny Stream</strong>
          <small>{{ t.__('Videos upload straight to Bunny and stream smoothly at any size, without slowing your host.', 'membuild-community') }}</small>
        </label>
      </div>
    </fieldset>

    <template v-if="form.video.provider === 'bunny'">
      <p class="intro">{{ bunnyHelp[0] }}<strong>{{ t.__('Stream → your video library → API', 'membuild-community') }}</strong>{{ bunnyHelp[1] }} {{ t.__('Copy the details from there. Stories always stay on this site.', 'membuild-community') }}</p>
      <div class="grid2">
        <label class="field">
          <span class="label">{{ t.__('Video library ID', 'membuild-community') }}</span>
          <input v-model="form.video.library_id" type="text" inputmode="numeric" placeholder="123456" autocomplete="off" />
        </label>
        <label class="field">
          <span class="label">{{ t.__('CDN hostname', 'membuild-community') }}</span>
          <input v-model="form.video.cdn_host" type="text" placeholder="vz-1234abcd-56.b-cdn.net" autocomplete="off" />
        </label>
      </div>
      <label class="field">
        <span class="label">{{ t.__('API key', 'membuild-community') }}</span>
        <input v-model="form.video.api_key" type="password" autocomplete="new-password" :placeholder="form.video.has_api_key ? t.__('•••••••••• (saved, leave empty to keep it)', 'membuild-community') : t.__('The library’s API key', 'membuild-community')" />
      </label>
      <div v-if="form.video.webhook_url" class="field">
        <span class="label">{{ t.__('Webhook URL', 'membuild-community') }} <small>{{ t.__('(optional, recommended)', 'membuild-community') }}</small></span>
        <div class="hook">
          <code>{{ form.video.webhook_url }}</code>
          <button type="button" class="btn" @click="copyHook">{{ copiedHook ? t.__('Copied ✓', 'membuild-community') : t.__('Copy', 'membuild-community') }}</button>
        </div>
        <small>{{ hookHelp[0] }}<strong>{{ t.__('Webhook URL', 'membuild-community') }}</strong>{{ hookHelp[1] }}</small>
      </div>
      <div class="test-row">
        <button type="button" class="btn" :disabled="videoTesting || dirty" @click="testVideo">{{ videoTesting ? t.__('Checking…', 'membuild-community') : t.__('Test connection', 'membuild-community') }}</button>
        <small v-if="dirty">{{ t.__('Save your changes first.', 'membuild-community') }}</small>
        <small v-else-if="videoResult" :class="videoResult.ok ? 'ok' : 'err'">{{ videoResult.message }}</small>
      </div>
    </template>
    <p v-else-if="savedForm.video.provider === 'bunny'" class="callout warn">
      {{ t.__('New videos will be stored on this site again. Videos already on Bunny keep playing from Bunny.', 'membuild-community') }}
    </p>
  </section>
</template>
