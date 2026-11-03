<script setup>
import { ref } from 'vue'
import { api } from '../../api.js'
import { askConfirm } from '../../lib/confirm.js'
import { around, t } from '../../lib/i18n.js'
import { useSettings } from './context.js'
import Icon from '../Icon.vue'

// Where videos are stored: this site, or Bunny Stream.
const { form, savedForm, dirty } = useSettings()
const videoTesting = ref(false)
const videoResult = ref(null)
const copiedHook = ref(false)

const bunnyHelp = around(/* translators: %s: the menu path "Stream → your video library → API", shown in bold */ t.__('In Bunny, open %s.', 'membuild-community'))
const tokenHelp = around(/* translators: %s: the Bunny menu path to token authentication, shown in bold */ t.__('In Bunny, open %s, turn it on, and paste the key here.', 'membuild-community'))
const hookHelp = around(/* translators: %s: the setting name "Webhook URL", shown in bold */ t.__("Paste it in the library's %s setting so videos show up the moment Bunny finishes encoding.", 'membuild-community'))

async function testVideo() {
  videoTesting.value = true
  videoResult.value = null
  try {
    const res = await api.testVideo()
    const message = res.protected
      ? /* translators: %d: number of videos in the Bunny library */ t._n('Connected. Your library has %d video, and links are protected.', 'Connected. Your library has %d videos, and links are protected.', res.videos, 'membuild-community')
      : /* translators: %d: number of videos in the Bunny library */ t._n('Connected. Your library has %d video.', 'Connected. Your library has %d videos.', res.videos, 'membuild-community')
    videoResult.value = { ok: true, message: t.sprintf(message, res.videos) }
  } catch (err) {
    videoResult.value = { ok: false, message: err.message }
  } finally {
    videoTesting.value = false
  }
}

// Link protection is off again once saved (the key is cleared on the server).
async function turnOffProtection() {
  const yes = await askConfirm({
    title: t.__('Turn off link protection?', 'membuild-community'),
    text: t.__('Video links will keep working once copied. Turn off token authentication in Bunny too, or videos will stop playing.', 'membuild-community'),
    confirm: t.__('Turn off', 'membuild-community'),
  })
  if (!yes) return
  form.value.video.token_key = ''
  form.value.video.token_key_clear = true
  form.value.video.has_token_key = false
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
    <p class="intro">{{ t.__('Where videos are kept.', 'membuild-community') }}</p>
    <div class="choices cols-2">
      <label class="choice pick-card" :class="{ on: form.video.provider === 'local' }">
        <input v-model="form.video.provider" type="radio" name="video" value="local" />
        <span class="mark pick-dot" /><strong>{{ t.__('This site', 'membuild-community') }}</strong>
        <small>{{ t.__('Stored with your other uploads. Fine for small communities.', 'membuild-community') }}</small>
      </label>
      <label class="choice pick-card" :class="{ on: form.video.provider === 'bunny' }">
        <input v-model="form.video.provider" type="radio" name="video" value="bunny" />
        <span class="mark pick-dot" /><strong>Bunny Stream<span class="tag">{{ t.__('Best for courses', 'membuild-community') }}</span></strong>
        <small>{{ t.__('Videos play smoothly and your site stays fast. A few dollars a month.', 'membuild-community') }}</small>
      </label>
    </div>
    <p v-if="!savedForm.video.has_api_key" class="bunny-signup">
      {{ t.__('No Bunny account yet?', 'membuild-community') }}
      <a href="https://bunny.net?ref=8mrzx2wdqv" target="_blank" rel="sponsored noopener">{{ t.__('Sign up at bunny.net', 'membuild-community') }} <Icon name="external" :size="14" /></a>
    </p>

    <template v-if="form.video.provider === 'bunny'">
      <p class="intro bunny-help">{{ bunnyHelp[0] }}<strong>{{ t.__('Stream → your video library → API', 'membuild-community') }}</strong>{{ bunnyHelp[1] }} {{ t.__('Copy the details from there. Stories always stay on this site.', 'membuild-community') }}</p>
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
      <div class="field">
        <span class="label">{{ t.__('Protect video links', 'membuild-community') }} <small>{{ t.__('(optional, good for paid courses)', 'membuild-community') }}</small></span>
        <div class="key-row">
          <input v-model="form.video.token_key" type="password" autocomplete="new-password" :aria-label="t.__('Protect video links', 'membuild-community')" :placeholder="form.video.has_token_key ? t.__('•••••••••• (saved, leave empty to keep it)', 'membuild-community') : t.__('The token authentication key', 'membuild-community')" />
          <button v-if="form.video.has_token_key" type="button" class="link-off" @click="turnOffProtection">{{ t.__('Turn off', 'membuild-community') }}</button>
        </div>
        <small v-if="form.video.has_token_key" class="ok">✓ {{ t.__('On. Video links stop working after about 6 hours.', 'membuild-community') }}</small>
        <small v-else>{{ tokenHelp[0] }}<strong>{{ t.__('Stream → your video library → API → Pull Zone → Manage → Security → Token authentication', 'membuild-community') }}</strong>{{ tokenHelp[1] }} {{ t.__('A copied video link then stops working after about 6 hours, so it can’t be passed around outside your community.', 'membuild-community') }}</small>
      </div>
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

<style scoped>
.bunny-help {
  margin-top: 18px;
}

.bunny-signup {
  margin: 12px 0 18px;
  color: var(--text-2);
  font-size: var(--fs-sm);
}

.bunny-signup a {
  color: var(--text);
  font-weight: 600;
  text-decoration: underline;
}

.bunny-signup a :deep(svg) {
  vertical-align: -2px;
}

/* Turn off: a quiet underlined word, as drawn, with a full-size tap area. */
.link-off {
  flex: none;
  min-height: 44px;
  padding: 0 6px;
  border: 0;
  background: none;
  color: var(--text);
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}

.key-row {
  display: flex;
  gap: 8px;
}

.key-row input {
  flex: 1;
  min-width: 0;
}
</style>
