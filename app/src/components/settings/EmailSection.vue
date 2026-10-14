<script setup>
import { ref, watch } from 'vue'
import { api } from '../../api.js'
import { around, t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

const DELIVERY = [
  { key: 'default', label: t.__('Your server', 'membuild-community'), hint: t.__('Uses the mail your web host provides. Nothing to set up.', 'membuild-community') },
  { key: 'resend', label: 'Resend', hint: t.__('Reliable delivery with just an API key. Free for small communities.', 'membuild-community') },
  { key: 'smtp', label: t.__('Another email service (SMTP)', 'membuild-community'), hint: t.__('Any email service: Brevo, Postmark, SendGrid, Mailgun, Gmail…', 'membuild-community') },
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
    testResult.value = { ok: true, message: t.sprintf(/* translators: %s: email address */ t.__('Sent to %s. Check your inbox (and spam folder).', 'membuild-community'), res.to) }
  } catch (err) {
    testResult.value = { ok: false, message: err.message }
  } finally {
    testing.value = false
  }
}
const sendsTo = (address) => t.sprintf(/* translators: %s: email address */ t.__('Sends to %s.', 'membuild-community'), address)
const goesThrough = (plugin) => t.sprintf(/* translators: %s: name of the SMTP plugin, e.g. "WP Mail SMTP" */ t.__('Emails will go through %s.', 'membuild-community'), plugin)
</script>

<template>
  <section id="s-email" class="card group">
    <h2>{{ t.__('Email', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Set this up before you invite members, so their password and sign-up emails arrive.', 'membuild-community') }}</p>

    <fieldset class="field">
      <legend class="label">{{ t.__('Sending email', 'membuild-community') }}</legend>
      <div class="delivery">
        <label v-for="d in DELIVERY" :key="d.key" class="option" :class="{ on: form.email.mode === d.key }">
          <input v-model="form.email.mode" type="radio" name="delivery" :value="d.key" />
          <strong>{{ d.label }}</strong>
          <small>{{ d.hint }}</small>
        </label>
      </div>

      <div v-if="form.email.mode === 'default'" class="callout" :class="meta.smtp_plugin ? 'info' : 'warn'">
        <template v-if="meta.smtp_plugin">
          <strong>{{ goesThrough(meta.smtp_plugin) }}</strong>
          {{ t.__("It's already installed on this site, so delivery follows its settings.", 'membuild-community') }}
        </template>
        <template v-else>
          <strong>{{ t.__('Delivery depends on your web host.', 'membuild-community') }}</strong>
          {{ t.__('Many hosts send email slowly, limit how many go out per hour, or land in spam folders, especially on shared hosting.', 'membuild-community') }}
          {{ t.__('For reliable delivery, connect Resend or another email service.', 'membuild-community') }}
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
        <button type="button" class="btn" :disabled="testing || dirty" @click="sendTest">{{ testing ? t.__('Sending…', 'membuild-community') : t.__('Send test email', 'membuild-community') }}</button>
        <small v-if="dirty">{{ t.__('Save your changes first.', 'membuild-community') }}</small>
        <small v-else-if="testResult" :class="testResult.ok ? 'ok' : 'err'">{{ testResult.message }}</small>
        <small v-else>{{ sendsTo(meta.admin_email) }}</small>
      </div>
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
