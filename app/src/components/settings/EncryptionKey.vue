<script setup>
import Icon from '../Icon.vue'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '../../api.js'
import { cfg } from '../../config.js'
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
const kept = ref(false) // step 1 done in this visit: step 2 lights up
const confirmingStartOver = ref(false)

const working = computed(() => status.value?.state === 'ok')
// Built here, not in the template: translator notes don't survive template compilation.
const storedIn = computed(() => t.sprintf(/* translators: %s: file path, such as wp-content/mbcom-key.php */ t.__('If someone else looks after your website, tell them the key is in the file %s, so it’s included in backups.', 'membuild-community'), status.value?.file))
const savedOn = computed(() =>
  status.value?.saved_at
    ? t.sprintf(/* translators: %s: a date */ t.__('Copy saved on %s', 'membuild-community'), new Date(status.value.saved_at).toLocaleDateString(undefined, { dateStyle: 'medium' }))
    : '',
)

// Arriving from the setup guide (settings#s-key): bring the box to the middle, ring it, and
// put the cursor on Copy key, so the next thing to do is right there.
const route = useRoute()
const box = ref(null)
const spot = ref(false)
onMounted(async () => {
  status.value = await api.encryption().catch(() => null)
  if (route.hash !== '#s-key') return
  await nextTick()
  box.value?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  spot.value = true
  setTimeout(() => (spot.value = false), 2600)
  setTimeout(() => box.value?.querySelector('.actions .btn')?.focus({ preventScroll: true }), 500)
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
  }, t.__('Copied. Paste it into your password manager.', 'membuild-community')).then(() => result.value?.ok && (kept.value = true))

const downloadKey = () =>
  run(async () => {
    const { key } = await api.encryptionKey()
    const text = [
      t.__('MemBuild Community safety key', 'membuild-community'),
      location.host,
      '',
      key,
      '',
      t.__('This key keeps your members’ private messages secret. If your website moves to a new web host and old messages can’t be read, paste it into Community settings → Advanced → Safety key.', 'membuild-community'),
      t.__('Keep it private, like a password.', 'membuild-community'),
    ].join('\n')
    const link = document.createElement('a')
    link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
    // "Membership Makers - safety key (MemBuild Community) 2026-10-29.txt": easy to find again in Downloads or a password manager.
    const name = (cfg.siteName || location.host).replace(/[\\/:*?"<>|]+/g, '').trim() || 'Community'
    link.download = `${name} - ${t.__('safety key', 'membuild-community')} (MemBuild Community) ${new Date().toISOString().slice(0, 10)}.txt`
    link.click()
    URL.revokeObjectURL(link.href)
  }, t.__('Downloaded. Keep the file somewhere safe, away from the website.', 'membuild-community')).then(() => result.value?.ok && (kept.value = true))

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
  <div v-if="status" id="s-key" ref="box" class="field key" :class="{ problem: !working, spot }">
    <span class="label">{{ t.__('Safety key', 'membuild-community') }}</span>

    <template v-if="working">
      <p class="keep"><Icon name="key" :size="18" /> <span><strong>{{ t.__('This key keeps private messages private. Keep a copy somewhere safe, like a password manager.', 'membuild-community') }}</strong></span></p>
      <p v-if="status.source === 'constant'" class="muted">{{ t.__('The key is set in wp-config.php (MEMBUILD_COMMUNITY_KEY).', 'membuild-community') }}</p>
      <!-- Two steps: keep a copy, then say so. The key itself is never shown on the page. -->
      <ol v-if="status.source !== 'constant'" class="steps">
        <li :class="{ done: kept || status.saved_at }">
          <span class="n"><Icon v-if="kept || status.saved_at" name="check" :size="15" /><template v-else>1</template></span>
          <div class="step">
            <strong>{{ t.__('Keep a copy', 'membuild-community') }}</strong>
            <small>{{ t.__('Copy it into your password manager, or download it as a file. Either one is enough.', 'membuild-community') }}</small>
            <div class="actions">
              <button type="button" class="btn" :disabled="busy" @click="copyKey"><Icon name="copy" :size="16" /> {{ t.__('Copy key', 'membuild-community') }}</button>
              <span class="or">{{ t.__('or', 'membuild-community') }}</span>
              <button type="button" class="btn" :disabled="busy" @click="downloadKey"><Icon name="download" :size="16" /> {{ t.__('Download key', 'membuild-community') }}</button>
            </div>
            <small v-if="result" :class="result.ok ? 'ok' : 'err'" role="status">{{ result.message }}</small>
          </div>
        </li>
        <li :class="{ done: status.saved_at, next: kept && !status.saved_at }">
          <span class="n"><Icon v-if="status.saved_at" name="check" :size="15" /><template v-else>2</template></span>
          <div class="step">
            <strong>{{ t.__('Tell us you’ve saved it', 'membuild-community') }}</strong>
            <template v-if="status.saved_at">
              <small class="ok saved"><Icon name="check" :size="15" /> {{ savedOn }}</small>
            </template>
            <template v-else>
              <small>{{ t.__('So we stop reminding you.', 'membuild-community') }}</small>
              <div class="actions">
                <button type="button" class="btn primary" :disabled="busy" @click="markSaved">{{ t.__('Done, I’ve saved it', 'membuild-community') }}</button>
              </div>
            </template>
          </div>
        </li>
      </ol>
      <p v-if="status.source !== 'constant'" class="muted after">{{ storedIn }}</p>
      <div v-else class="actions">
        <button type="button" class="btn" :disabled="busy" @click="copyKey"><Icon name="copy" :size="16" /> {{ t.__('Copy key', 'membuild-community') }}</button>
        <button type="button" class="btn" :disabled="busy" @click="downloadKey"><Icon name="download" :size="16" /> {{ t.__('Download key', 'membuild-community') }}</button>
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

    <small v-if="result && (!working || status.source === 'constant')" :class="result.ok ? 'ok' : 'err'" role="status">{{ result.message }}</small>
  </div>
</template>

<style scoped>
.key {
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.key.spot {
  animation: spot 2.6s ease-out;
}

@keyframes spot {
  0%,
  60% {
    box-shadow: 0 0 0 4px var(--accent);
  }
  100% {
    box-shadow: 0 0 0 4px transparent;
  }
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
  font-size: var(--fs-md);
}

.muted.after {
  margin-top: 16px;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
}

.steps {
  display: flex;
  flex-direction: column;
  gap: 18px;
  margin: 10px 0 4px;
  padding: 0;
  list-style: none;
}

.steps li {
  display: flex;
  gap: 14px;
}

.n {
  display: grid;
  flex: none;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text);
  font-size: var(--fs-base);
  font-weight: 800;
}

.steps li.done .n {
  background: var(--ok-strong);
  color: var(--on-color);
}

.steps li.next .n {
  background: var(--accent);
  color: var(--accent-ink);
}

.step {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.step strong {
  font-size: var(--fs-lg);
}

.step > small {
  color: var(--text-2);
  font-size: var(--fs-base);
}

.step > small.ok {
  color: var(--ok-text, #1f7a49);
}

.or {
  color: var(--text-2);
  font-size: var(--fs-base);
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
  color: var(--on-color);
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

<style scoped>
.keep {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 4px 0 14px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.keep :deep(svg) {
  flex: none;
  margin-top: 2px;
}

.saved {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-weight: 700;
}

@media (prefers-color-scheme: dark) {
  .keep {
    background: var(--warn-soft);
    color: var(--warn-text);
  }
}
</style>
