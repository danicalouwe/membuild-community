<script setup>
import { computed, ref } from 'vue'
import { api } from '../../api.js'
import Icon from '../Icon.vue'
import { askConfirm } from '../../lib/confirm.js'
import { t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

/**
 * Two-step login. The switch adds the free Two Factor plugin from WordPress.org (or turns on the
 * copy already there) right away, not with the Save bar. "Who has to use it?" saves with the form.
 */
const { form, meta } = useSettings()
const WHO = [
  { key: 'anyone', label: t.__('Anyone who wants it', 'membuild-community'), hint: t.__('Members turn it on in their account. (Easiest)', 'membuild-community') },
  { key: 'team', label: t.__('Required for you and your team', 'membuild-community'), hint: t.__('Owners and moderators set it up at their next log in.', 'membuild-community') },
  { key: 'everyone', label: t.__('Required for everyone', 'membuild-community'), hint: t.__('Every member sets it up at their next log in.', 'membuild-community') },
]

const status = ref(meta.value?.two_step || null) // { state: on | off | missing | broken, version, can_add, plugins_url, needs_login_page }
const working = ref(false)
const blocked = ref('') // the host's answer when it won't add plugins
const on = computed(() => status.value?.state === 'on')
// Kept on the shared meta too, so coming back to this page (and the phone list's line) shows the
// switch as it is now, even when the answer comes after the owner has gone to another page.
function setStatus(v) {
  status.value = v
  if (meta.value && v) meta.value.two_step = v
}

async function flip(ev) {
  const want = ev.target.checked
  ev.target.checked = on.value // the switch moves once the server says so
  if (!want) {
    const yes = await askConfirm({
      title: t.__('Turn off two-step login?', 'membuild-community'),
      text: t.__('Everyone logs in with just their password again.', 'membuild-community'),
      note: t.__('Members’ setups are kept, so it works again if you turn it back on.', 'membuild-community'),
      confirm: t.__('Turn off', 'membuild-community'),
      cancel: t.__('Keep it on', 'membuild-community'),
      danger: true,
    })
    if (!yes) return
  }
  await change(want)
}
async function change(want) {
  working.value = true
  blocked.value = ''
  try {
    setStatus(await api.twoStepSwitch(want))
  } catch (e) {
    // A copy of Two Factor that's installed but never loads: say so in place, not as "couldn't be added".
    if (e?.code === 'mbcom_two_step_broken') setStatus({ ...(status.value || {}), state: 'broken' })
    else blocked.value = e.message || t.__('That didn’t work. Try again.', 'membuild-community')
  } finally {
    working.value = false
  }
}
async function checkAgain() {
  working.value = true
  try {
    setStatus(await api.twoStepStatus())
    if (status.value.state === 'on') blocked.value = ''
    else if (status.value.state === 'off') await change(true)
  } catch (e) {
    blocked.value = e.message
  } finally {
    working.value = false
  }
}
</script>

<template>
  <section id="s-security" class="card group">
    <h2>{{ t.__('Security', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Keep accounts safe, even when a password gets out.', 'membuild-community') }}</p>

    <label class="toggle-row first">
      <span>
        <strong>{{ t.__('Two-step login', 'membuild-community') }}</strong>
        <small>{{ t.__('After their password, people type a code from their phone or email.', 'membuild-community') }}</small>
      </span>
      <span v-if="working" class="status-tag lg">{{ on ? t.__('Turning off…', 'membuild-community') : status?.state === 'missing' ? t.__('Installing…', 'membuild-community') : t.__('Turning on…', 'membuild-community') }}</span>
      <input v-else type="checkbox" class="switch" role="switch" :checked="on" :disabled="!status" @change="flip" />
    </label>

    <p v-if="working && !on && status?.state === 'missing'" class="info-note"><Icon name="download" :size="20" /><span>{{ t.__('Adding the free Two Factor plugin from WordPress.org. This takes a few seconds.', 'membuild-community') }}</span></p>

    <div v-else-if="blocked" class="info-note warn" role="alert">
      <Icon name="info" :size="20" />
      <span>
        <strong>{{ t.__('Two Factor couldn’t be added', 'membuild-community') }}</strong>
        {{ blocked }}
        <ol>
          <li>{{ t.__('Open Plugins, then Add New.', 'membuild-community') }}</li>
          <li>{{ t.__('Search for “Two Factor” (by WordPress.org Contributors).', 'membuild-community') }}</li>
          <li>{{ t.__('Choose Install Now, then Activate.', 'membuild-community') }}</li>
        </ol>
        <span class="btns">
          <a v-if="status?.plugins_url" :href="status.plugins_url" class="btn btn-lg btn-ghost" target="_blank" rel="noopener">{{ t.__('Open Plugins', 'membuild-community') }} <Icon name="external" :size="14" /></a>
          <button class="btn btn-lg btn-ghost" type="button" @click="checkAgain">{{ t.__('Check again', 'membuild-community') }}</button>
        </span>
      </span>
    </div>

    <template v-else-if="on">
      <p class="small on-line"><Icon name="check" :size="16" /> {{ status.version ? t.sprintf(t.__('On. Uses the free Two Factor plugin (version %s).', 'membuild-community'), status.version) : t.__('On. Uses the free Two Factor plugin.', 'membuild-community') }}</p>
      <p v-if="status.needs_login_page" class="info-note"><Icon name="info" :size="20" /><span>{{ t.__('Turn on the community login page (Joining & logging in) so codes are asked in your community’s look.', 'membuild-community') }}</span></p>

      <hr class="rule" />

      <fieldset class="field">
        <legend class="label">{{ t.__('Who has to use it?', 'membuild-community') }}</legend>
        <div class="choices">
          <label v-for="w in WHO" :key="w.key" class="choice pick-card" :class="{ on: form.two_step_who === w.key }">
            <input v-model="form.two_step_who" type="radio" name="two_step_who" :value="w.key" />
            <span class="mark pick-dot" /><strong>{{ w.label }}</strong><small>{{ w.hint }}</small>
          </label>
        </div>
      </fieldset>
    </template>

    <p v-else-if="status?.state === 'broken' && !blocked" class="info-note"><Icon name="info" :size="20" /><span>{{ t.__('Two Factor is installed but isn’t working, so nobody would be asked for a code. Delete it in Plugins, then turn this on again to add a fresh copy.', 'membuild-community') }}</span></p>
    <p v-else-if="status?.state === 'off'" class="small">{{ t.__('Two Factor is installed but switched off. Turning this on switches it on.', 'membuild-community') }}</p>
    <p v-else-if="status" class="small">{{ t.__('Turning this on adds the free Two Factor plugin from WordPress.org.', 'membuild-community') }}</p>
  </section>
</template>

<style scoped>
.small {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.on-line {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--ok-text);
}

.info-note strong {
  display: block;
  margin-bottom: 2px;
}

.info-note ol {
  margin: 8px 0;
  padding-left: 20px;
}

.btns {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.btns .btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.choices {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
</style>
