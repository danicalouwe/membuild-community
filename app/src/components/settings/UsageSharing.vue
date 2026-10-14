<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api.js'
import { t } from '../../lib/i18n.js'

/**
 * The weekly anonymous report to MemBuild (see the plugin's Usage class).
 * Saves as soon as something changes, like the encryption key above it,
 * and shows exactly what the next report contains.
 */
const status = ref(null) // { share, niche, niche_other, size, id, enabled, sent_at, report, niches, sizes }
const busy = ref(false)
const result = ref(null) // { ok, message }

onMounted(async () => {
  status.value = await api.usage().catch(() => null)
})

const lastSent = computed(() =>
  status.value?.sent_at
    ? t.sprintf(/* translators: %s: a date */ t.__('Last sent %s', 'membuild-community'), new Date(status.value.sent_at).toLocaleDateString(undefined, { dateStyle: 'medium' }))
    : '',
)
const preview = computed(() => JSON.stringify(status.value?.report ?? {}, null, 2))

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

const save = (body) => run(() => api.saveUsage(body))
const forget = () => run(() => api.forgetUsage(), t.__('Done. MemBuild deleted what it had for this site, and sharing is off.', 'membuild-community'))
</script>

<template>
  <div v-if="status" class="field usage">
    <label class="toggle-row">
      <span>
        <strong>{{ t.__('Share anonymous usage with MemBuild', 'membuild-community') }}</strong>
        <small>{{ t.__('Once a week: what your community is about, which features are on, and rough counts like “51–200 members”. Never names, emails, posts or messages. It helps us decide what to build next.', 'membuild-community') }}</small>
      </span>
      <input :checked="status.share" type="checkbox" class="switch" role="switch" :disabled="busy" @change="save({ share: $event.target.checked })" />
    </label>

    <div v-if="status.share" class="answers">
      <label>
        <span class="label">{{ t.__('What is your community about?', 'membuild-community') }}</span>
        <select :value="status.niche" :disabled="busy" @change="save({ niche: $event.target.value, niche_other: status.niche_other })">
          <option value="">{{ t.__('Choose one', 'membuild-community') }}</option>
          <option v-for="(label, key) in status.niches" :key="key" :value="key">{{ label }}</option>
        </select>
      </label>
      <label v-if="status.niche === 'other'">
        <span class="label">{{ t.__('In a few words', 'membuild-community') }}</span>
        <input :value="status.niche_other" type="text" maxlength="40" :disabled="busy" @change="save({ niche: 'other', niche_other: $event.target.value })" />
      </label>
      <label>
        <span class="label">{{ t.__('How many members do you expect?', 'membuild-community') }}</span>
        <select :value="status.size" :disabled="busy" @change="save({ size: $event.target.value })">
          <option value="">{{ t.__('Choose one', 'membuild-community') }}</option>
          <option v-for="(label, key) in status.sizes" :key="key" :value="key">{{ label }}</option>
        </select>
      </label>
    </div>

    <p class="muted">
      {{ t.__('Even with this off, the plugin tells MemBuild once a week that it’s installed: a random ID made on this site, and the plugin, WordPress and PHP versions. That’s how we count active communities.', 'membuild-community') }}
      <template v-if="!status.enabled"> {{ t.__('This site is set up as a local or development site, so nothing is sent.', 'membuild-community') }}</template>
    </p>

    <details class="preview">
      <summary>{{ t.__('See exactly what’s sent', 'membuild-community') }}</summary>
      <pre>{{ preview }}</pre>
      <div class="actions">
        <small v-if="lastSent" class="muted">{{ lastSent }}</small>
        <button type="button" class="btn" :disabled="busy" @click="forget">{{ t.__('Delete this site’s data at MemBuild', 'membuild-community') }}</button>
      </div>
    </details>

    <small v-if="result" :class="result.ok ? 'ok' : 'err'" role="status">{{ result.message }}</small>
  </div>
</template>

<style scoped>
.usage {
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: 12px;
}

.answers {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 16px;
  margin: 12px 0 4px;
}

.answers label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1 1 220px;
  min-width: 0;
}

.muted {
  color: var(--text-2);
  font-size: 14px;
  margin: 10px 0 0;
}

.preview {
  margin-top: 10px;
}

.preview summary {
  cursor: pointer;
  font-weight: 600;
  font-size: 14px;
}

.preview pre {
  margin: 8px 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: var(--surface-2);
  font-size: 12px;
  line-height: 1.5;
  overflow-x: auto;
  max-height: 260px;
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
