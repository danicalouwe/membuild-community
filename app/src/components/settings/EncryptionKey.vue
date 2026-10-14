<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api.js'
import { t } from '../../lib/i18n.js'

/**
 * The key private messages are encrypted with (see the plugin's Vault class).
 * The owner keeps a copy here; after a move or restore that lost the key file,
 * they paste it back, or knowingly start over with a new one.
 */
const status = ref(null) // { state, source, saved_at, file }
const busy = ref(false)
const result = ref(null) // { ok, message }
const pasted = ref('')
const confirmingStartOver = ref(false)

const working = computed(() => status.value?.state === 'ok')
// Built here, not in the template: translator notes don't survive template compilation.
const storedIn = computed(() => t.sprintf(/* translators: %s: file path, such as wp-content/mbcom-key.php */ t.__('It’s stored in %s. Make sure your backups include that file.', 'membuild-community'), status.value?.file))
const savedOn = computed(() =>
  status.value?.saved_at
    ? t.sprintf(/* translators: %s: a date */ t.__('Copy saved on %s', 'membuild-community'), new Date(status.value.saved_at).toLocaleDateString(undefined, { dateStyle: 'medium' }))
    : '',
)

onMounted(async () => {
  status.value = await api.encryption().catch(() => null)
})

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

// The key is fetched only when it's copied or downloaded, never kept on the page.
const copyKey = () =>
  run(async () => {
    await navigator.clipboard.writeText((await api.encryptionKey()).key)
  }, t.__('Copied. Paste it into your password manager.', 'membuild-community'))

const downloadKey = () =>
  run(async () => {
    const { key } = await api.encryptionKey()
    const text = [
      t.__('MemBuild - Community encryption key', 'membuild-community'),
      location.host,
      '',
      key,
      '',
      t.__('Private messages on this site are encrypted with this key. If the site is moved or restored and messages can’t be read, paste it into Community settings → Advanced → Encryption key.', 'membuild-community'),
      t.__('Keep it private, like a password.', 'membuild-community'),
    ].join('\n')
    const link = document.createElement('a')
    link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
    link.download = `community-key-${location.host.replace(/[^a-z0-9.-]/gi, '')}.txt`
    link.click()
    URL.revokeObjectURL(link.href)
  }, t.__('Downloaded. Keep the file somewhere safe, away from the website.', 'membuild-community'))

const markSaved = () => run(() => api.encryptionAction('saved'))
const restore = () =>
  run(async () => {
    const next = await api.encryptionAction('restore', pasted.value.trim())
    pasted.value = ''
    return next
  }, t.__('The key is back. Messages can be read and sent again.', 'membuild-community'))
const startOver = () =>
  run(async () => {
    const next = await api.encryptionAction('start_over')
    confirmingStartOver.value = false
    return next
  }, t.__('A new key is in place. Messages from now on are encrypted with it; save a copy below.', 'membuild-community'))
</script>

<template>
  <div v-if="status" class="field key" :class="{ problem: !working }">
    <span class="label">{{ t.__('Encryption key', 'membuild-community') }}</span>

    <template v-if="working">
      <p>{{ t.__('Private messages are encrypted with a key kept on your server. Keep a copy somewhere safe, like a password manager. If the site is ever moved or restored without it, members’ messages can’t be read.', 'membuild-community') }}</p>
      <p v-if="status.source === 'constant'" class="muted">{{ t.__('The key is set in wp-config.php (MEMBUILD_COMMUNITY_KEY).', 'membuild-community') }}</p>
      <p v-else class="muted">{{ storedIn }}</p>
      <div class="actions">
        <button type="button" class="btn" :disabled="busy" @click="copyKey">{{ t.__('Copy key', 'membuild-community') }}</button>
        <button type="button" class="btn" :disabled="busy" @click="downloadKey">{{ t.__('Download key', 'membuild-community') }}</button>
        <button v-if="!status.saved_at && status.source !== 'constant'" type="button" class="btn primary" :disabled="busy" @click="markSaved">{{ t.__('I’ve saved it', 'membuild-community') }}</button>
        <small v-else-if="savedOn" class="ok">{{ savedOn }}</small>
      </div>
    </template>

    <template v-else>
      <p class="err">
        <strong>{{ t.__('Messages are paused.', 'membuild-community') }}</strong>
        {{ status.state === 'wrong'
          ? t.__('The key on this server isn’t the one messages were encrypted with, which happens when a site is moved. Paste the key you saved from this community.', 'membuild-community')
          : t.__('The key file is missing, which happens when a site is moved or restored without it. Paste the key you saved, or put the file back.', 'membuild-community') }}
      </p>
      <div class="actions">
        <input v-model="pasted" type="text" spellcheck="false" autocomplete="off" :placeholder="t.__('Paste the saved key (64 letters and numbers)', 'membuild-community')" :aria-label="t.__('Saved encryption key', 'membuild-community')" />
        <button type="button" class="btn primary" :disabled="busy || !pasted.trim()" @click="restore">{{ t.__('Restore key', 'membuild-community') }}</button>
      </div>
      <details class="start-over">
        <summary>{{ t.__('The key is lost for good', 'membuild-community') }}</summary>
        <p>{{ t.__('You can start over with a new key. Messages sent from now on work normally, but every earlier message will show as “This message can’t be shown”. This can’t be undone, even if the old key turns up later.', 'membuild-community') }}</p>
        <label class="confirm"><input v-model="confirmingStartOver" type="checkbox" /> {{ t.__('I understand earlier messages will be lost', 'membuild-community') }}</label>
        <button type="button" class="btn danger" :disabled="busy || !confirmingStartOver" @click="startOver">{{ t.__('Start over with a new key', 'membuild-community') }}</button>
      </details>
    </template>

    <small v-if="result" :class="result.ok ? 'ok' : 'err'" role="status">{{ result.message }}</small>
  </div>
</template>

<style scoped>
.key {
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: 12px;
}

.key.problem {
  border-color: color-mix(in srgb, var(--danger) 55%, transparent);
  background: color-mix(in srgb, var(--danger) 7%, transparent);
}

.key p {
  margin: 4px 0 8px;
}

.muted {
  color: var(--text-2);
  font-size: 14px;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
}

.actions input {
  flex: 1 1 260px;
  min-width: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.btn.primary {
  background: var(--accent);
  color: var(--accent-ink);
}

.btn.danger {
  background: var(--danger);
  color: #fff;
}

.btn:disabled {
  opacity: 0.5;
}

.start-over {
  margin-top: 14px;
}

.start-over summary {
  cursor: pointer;
  font-weight: 600;
}

.confirm {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 8px 0;
}
</style>
