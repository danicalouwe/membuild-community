<script setup>
import { ref, watch } from 'vue'
import { api } from '../../api.js'
import { around, t } from '../../lib/i18n.js'
import Icon from '../Icon.vue'
import { useSettings } from './context.js'

const DELIVERY = [
  { key: 'default', icon: 'server', label: t.__('Your web host', 'membuild-community'), hint: t.__('Nothing to set up.', 'membuild-community') },
  { key: 'resend', icon: 'send', label: 'Resend', hint: t.__('Reliable, free for small communities.', 'membuild-community'), tag: t.__('Recommended', 'membuild-community') },
  { key: 'smtp', icon: 'mail', label: t.__('Another service', 'membuild-community'), hint: t.__('Brevo, Postmark, SendGrid, Gmail…', 'membuild-community') },
]

const { form, meta, dirty } = useSettings()
const testing = ref(false)
const testResult = ref(null) // { ok, message }
// A fresh form (saved or discarded) makes an old test result meaningless.
watch(form, () => (testResult.value = null))

const resendHelp = around(/* translators: %s: the link "resend.com/api-keys" */ t.__('Create one at %s with “Sending access”.', 'membuild-community'))

async function sendTest() {
  testing.value = true
  testResult.value = null
  try {
    const res = await api.testEmail()
    const at = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date())
    testResult.value = { ok: true, to: res.to, at }
  } catch (err) {
    testResult.value = { ok: false, message: err.message }
  } finally {
    testing.value = false
  }
}
const goesThrough = (plugin) => t.sprintf(/* translators: %s: name of the SMTP plugin, e.g. "WP Mail SMTP" */ t.__('Emails will go through %s.', 'membuild-community'), plugin)
</script>

<template>
  <section id="s-email" class="card group">
    <h2>{{ t.__('Email', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Set this up before you invite members, so their password and sign-up emails arrive.', 'membuild-community') }}</p>

    <fieldset class="field">
      <legend class="label">{{ t.__('How emails are sent', 'membuild-community') }}</legend>
      <div class="choices cols-3">
        <label v-for="d in DELIVERY" :key="d.key" class="choice tile" :class="{ on: form.email.mode === d.key }">
          <input v-model="form.email.mode" type="radio" name="delivery" :value="d.key" />
          <span class="tile-ic"><Icon :name="d.icon" :size="18" /></span><span class="mark" />
          <strong>{{ d.label }}</strong>
          <small>{{ d.hint }}</small>
          <span v-if="d.tag" class="tag">{{ d.tag }}</span>
        </label>
      </div>

      <div v-if="form.email.mode === 'default'" class="callout" :class="meta.smtp_plugin ? 'info' : 'warn'">
        <template v-if="meta.smtp_plugin">
          <strong>{{ goesThrough(meta.smtp_plugin) }}</strong>
          {{ t.__("It's already installed on this site, so delivery follows its settings.", 'membuild-community') }}
        </template>
        <template v-else>
          <Icon name="alert" :size="16" /> <strong>{{ t.__('Web host email often lands in spam.', 'membuild-community') }}</strong>
          {{ t.__('For emails members actually get, choose Resend or another service.', 'membuild-community') }}
        </template>
      </div>

      <template v-else-if="form.email.mode === 'resend'">
        <label class="field">
          <span class="label">{{ t.__('Resend API key', 'membuild-community') }}</span>
          <input v-model="form.email.password" type="password" autocomplete="new-password" :placeholder="form.email.has_password ? t.__('•••••••••• (saved, leave empty to keep it)', 'membuild-community') : 're_…'" />
          <small>{{ resendHelp[0] }}<a href="https://resend.com/api-keys" target="_blank" rel="noopener">resend.com/api-keys</a>{{ resendHelp[1] }} {{ t.__('Verify your domain in Resend first, then use an address on that domain below.', 'membuild-community') }}</small>
        </label>
      </template>

      <template v-else>
        <div class="grid2">
          <label class="field">
            <span class="label">{{ t.__('SMTP server', 'membuild-community') }}</span>
            <input v-model="form.email.host" type="text" placeholder="smtp.example.com" autocomplete="off" />
          </label>
          <label class="field">
            <span class="label">{{ t.__('Port', 'membuild-community') }}</span>
            <input v-model.number="form.email.port" type="number" min="1" max="65535" />
          </label>
        </div>
        <label class="field">
          <span class="label">{{ t.__('Encryption', 'membuild-community') }}</span>
          <select v-model="form.email.secure">
            <option value="tls">{{ t.__('TLS (usually port 587)', 'membuild-community') }}</option>
            <option value="ssl">{{ t.__('SSL (usually port 465)', 'membuild-community') }}</option>
            <option value="none">{{ t.__('None', 'membuild-community') }}</option>
          </select>
        </label>
        <div class="grid2">
          <label class="field">
            <span class="label">{{ t.__('Username', 'membuild-community') }}</span>
            <input v-model="form.email.username" type="text" autocomplete="off" />
          </label>
          <label class="field">
            <span class="label">{{ t.__('Password', 'membuild-community') }}</span>
            <input v-model="form.email.password" type="password" autocomplete="new-password" :placeholder="form.email.has_password ? t.__('•••••••• (saved)', 'membuild-community') : ''" />
          </label>
        </div>
      </template>

      <div class="grid2">
        <label class="field">
          <span class="label">{{ t.__('From name', 'membuild-community') }}</span>
          <input v-model="form.email.from_name" type="text" maxlength="120" :placeholder="form.name || meta.site_name" />
        </label>
        <label class="field">
          <span class="label">{{ t.__('From address', 'membuild-community') }}</span>
          <input v-model="form.email.from_email" type="email" :placeholder="meta.admin_email" />
        </label>
      </div>

      <div class="test-row">
        <button type="button" class="btn" :disabled="testing || dirty" @click="sendTest">{{ testing ? t.__('Sending…', 'membuild-community') : t.__('Send a test email', 'membuild-community') }}</button>
        <small v-if="dirty">{{ t.__('Save your changes first.', 'membuild-community') }}</small>
        <small v-else>{{ t.sprintf(/* translators: %s: email address */ t.__('Goes to %s.', 'membuild-community'), meta.admin_email) }}</small>
      </div>
      <!-- What happened, in plain words: where it went, or why it didn't and the one fix. -->
      <p v-if="testResult?.ok" class="result ok-box" role="status">
        <Icon name="check" :size="18" />
        <span><strong>{{ t.sprintf(/* translators: 1: email address, 2: a time */ t.__('Sent to %1$s at %2$s.', 'membuild-community'), testResult.to, testResult.at) }}</strong> {{ t.__('Check your inbox. If it isn’t there in a few minutes, look in spam; if it’s there, choose Resend above so members’ emails arrive.', 'membuild-community') }}</span>
      </p>
      <p v-else-if="testResult" class="result bad-box" role="alert">
        <Icon name="alert" :size="18" />
        <span><strong>{{ t.__('The email didn’t send.', 'membuild-community') }}</strong> {{ testResult.message }} <template v-if="form.email.mode === 'default'">{{ t.__('Choose Resend above (free for small communities, about 5 minutes to set up), then press Send a test email again.', 'membuild-community') }} <a href="https://resend.com/docs/introduction" target="_blank" rel="noopener">{{ t.__('How to set up Resend', 'membuild-community') }}</a></template></span>
      </p>
    </fieldset>

    <label class="toggle-row">
      <span>
        <strong>{{ t.__('Email notifications', 'membuild-community') }}</strong>
        <small>{{ t.__('Members get one short email about new messages, comments on their posts and replies to their comments. They can choose daily summaries or turn emails off in their profile.', 'membuild-community') }}</small>
      </span>
      <input v-model="form.email.enabled" type="checkbox" class="switch" role="switch" />
    </label>
  </section>
</template>

<style scoped>
.result {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 14px 0 0;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.result :deep(svg) {
  flex: none;
  margin-top: 3px;
}

.ok-box {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.bad-box {
  background: var(--danger-soft, #fdecec);
  color: var(--danger-text);
}

.bad-box a {
  color: inherit;
  font-weight: 700;
}
</style>
