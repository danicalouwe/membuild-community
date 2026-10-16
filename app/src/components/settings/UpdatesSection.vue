<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api.js'
import { t } from '../../lib/i18n.js'
import UsageSharing from './UsageSharing.vue'

/**
 * "Keep me updated" (an email for new-feature news) and, below it, the usage
 * sharing switch. Both talk to MemBuild (see the plugin's Usage class) and
 * save as soon as something changes, outside the Save bar. One status object
 * serves both parts, so subscribing updates the "what's sent" preview too.
 */
const status = ref(null) // { email, subscribed, subscribed_at, admin_email, site_url, allowed, … }
const email = ref('')
const busy = ref(false)
const result = ref(null) // { ok, message }

onMounted(async () => {
  status.value = await api.usage().catch(() => null)
  email.value = status.value?.email || status.value?.admin_email || ''
})

const since = computed(() =>
  status.value?.subscribed_at
    ? t.sprintf(/* translators: %s: a date */ t.__('Since %s', 'membuild-community'), new Date(status.value.subscribed_at).toLocaleDateString(undefined, { dateStyle: 'medium' }))
    : '',
)

async function run(fn, success) {
  busy.value = true
  result.value = null
  try {
    status.value = (await fn()) || status.value
    if (success) result.value = { ok: true, message: success }
  } catch (err) {
    result.value = { ok: false, message: err.message }
  } finally {
    busy.value = false
  }
}

const subscribe = () => run(() => api.subscribeUpdates({ email: email.value }), t.__('Done. You’ll hear from us when something new ships.', 'membuild-community'))
const stop = () => run(() => api.stopUpdates(), t.__('Stopped. MemBuild no longer has your email.', 'membuild-community'))
</script>

<template>
  <section id="s-updates" class="card group">
    <h2>{{ t.__('Updates & usage', 'membuild-community') }}</h2>

    <div v-if="status" class="field updates">
      <template v-if="status.subscribed">
        <p class="lead">
          <strong>{{ t.sprintf(/* translators: %s: an email address */ t.__('You’re subscribed as %s.', 'membuild-community'), status.email) }}</strong>
          <small>{{ t.__('An email when something new ships in MemBuild - Community, and the odd question about what to build next. No newsletters, nothing for sale.', 'membuild-community') }}</small>
        </p>
        <div class="actions">
          <small class="muted">{{ since }}</small>
          <button type="button" class="btn" :disabled="busy" @click="stop">{{ t.__('Stop', 'membuild-community') }}</button>
        </div>
      </template>

      <template v-else>
        <p class="lead">
          <strong>{{ t.__('Get the next features before anyone else', 'membuild-community') }}</strong>
          <small>{{ t.__('An email when something new ships in MemBuild - Community, and the odd question about what to build next. No newsletters, nothing for sale.', 'membuild-community') }}</small>
        </p>
        <form class="row" @submit.prevent="subscribe">
          <input v-model="email" type="email" required :aria-label="t.__('Your email', 'membuild-community')" :disabled="busy" />
          <button type="submit" class="btn btn-primary" :disabled="busy || !status.allowed">{{ t.__('Keep me updated', 'membuild-community') }}</button>
        </form>
        <p class="muted">
          {{ t.sprintf(/* translators: %s: the site's address */ t.__('We get your email, your site’s address (%s) and name, and the plugin, WordPress and PHP versions. If usage sharing below is on, your community’s numbers come with it. Stop any time here.', 'membuild-community'), status.site_url) }}
          <template v-if="!status.allowed"> {{ t.__('Reports to MemBuild are switched off on this site by code, so this can’t be sent.', 'membuild-community') }}</template>
        </p>
      </template>

      <small v-if="result" :class="result.ok ? 'ok' : 'err'" role="status">{{ result.message }}</small>
    </div>

    <UsageSharing v-model:status="status" />
  </section>
</template>

<style scoped>
.updates {
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: 12px;
}

.lead {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0 0 12px;
}

.lead small {
  color: var(--text-2);
}

.row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.row input {
  flex: 1 1 240px;
  min-width: 0;
}

.muted {
  color: var(--text-2);
  font-size: 14px;
  margin: 10px 0 0;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.btn:disabled {
  opacity: 0.5;
}
</style>
