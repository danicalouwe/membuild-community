<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { api } from '../api.js'
import BaseDialog from '../components/BaseDialog.vue'
import Icon from '../components/Icon.vue'
import { askConfirm } from '../lib/confirm.js'
import { t } from '../lib/i18n.js'

/**
 * Two-step login, in the member's own account (never wp-admin). After their password they type
 * a code from an app or their email; recovery codes are for when they can't get either.
 * The free Two Factor plugin keeps the secrets and checks the codes (see Two_Step on the server).
 * Changing anything once it's on needs a code typed lately, so a borrowed session can't turn it off.
 */
const info = ref(null) // { available, on, app, app_since, email, codes, address, required, confirmed }
const error = ref('')
const busy = ref('')

onMounted(load)
async function load() {
  try {
    info.value = await api.twoStep()
  } catch (e) {
    error.value = e.message
  }
}

// Every change goes through here: the answer is the page's new state.
async function act(action, extra = {}) {
  busy.value = action
  error.value = ''
  try {
    const res = await api.twoStepDo({ action, ...extra })
    if (res && 'available' in res) info.value = res
    return res
  } catch (e) {
    if (e.code === 'mbcom_two_step_confirm') info.value = { ...info.value, confirmed: false }
    throw e
  } finally {
    busy.value = ''
  }
}
const say = (e) => (error.value = e.message)

const locked = computed(() => info.value?.on && !info.value?.confirmed)
const since = computed(() => (info.value?.app_since ? new Date(info.value.app_since).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : ''))
const otherWays = (way) => (way === 'app' ? info.value.email : info.value.app)

/* ---------- Confirm it's you ---------- */
const confirmCode = ref('')
const confirmSent = ref(false)
async function confirmIt() {
  try {
    await act('confirm', { code: confirmCode.value })
    confirmCode.value = ''
  } catch (e) {
    say(e)
  }
}
async function sendConfirm() {
  try {
    await act('confirm_send')
    confirmSent.value = true
  } catch (e) {
    say(e)
  }
}

/* ---------- Authenticator app ---------- */
const app = ref(null) // { key, uri } while setting it up
const appCode = ref('')
const appError = ref('')
const qrBox = ref(null)
let qrLoaded = null
function loadQr(src) {
  qrLoaded ??= new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src = src
    s.onload = resolve
    s.onerror = (e) => {
      qrLoaded = null // so the next try loads it again
      s.remove()
      reject(e)
    }
    document.head.appendChild(s)
  })
  return qrLoaded
}
async function startApp() {
  try {
    const res = await act('app_start')
    app.value = res
    appCode.value = ''
    appError.value = ''
    await loadQr(res.qr_script)
    await nextTick()
    const qr = window.qrcode(0, 'M')
    if (window.qrcode.stringToBytesFuncs?.['UTF-8']) window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8']
    qr.addData(res.uri)
    qr.make()
    if (qrBox.value) qrBox.value.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true })
  } catch (e) {
    if (app.value) appError.value = e.message
    else say(e)
  }
}
const spaced = (key) => key.replace(/(.{4})/g, '$1 ').trim()
const copied = ref(false)
async function copyKey() {
  try {
    await navigator.clipboard.writeText(app.value.key)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    /* the key stays on screen to type */
  }
}
async function finishApp() {
  const first = !info.value.on
  try {
    await act('app_confirm', { code: appCode.value })
    app.value = null
    if (first) await makeCodes()
  } catch (e) {
    appError.value = e.message
  }
}
async function removeApp() {
  const last = !info.value.email
  const yes = await askConfirm(
    last
      ? { title: t.__('Turn off two-step login?', 'membuild-community'), text: t.__('The app is your only way to get a code, so you’ll log in with just your password.', 'membuild-community'), note: t.__('Your recovery codes stop working too.', 'membuild-community'), confirm: t.__('Turn off', 'membuild-community'), cancel: t.__('Keep it on', 'membuild-community'), danger: true }
      : { title: t.__('Remove the authenticator app?', 'membuild-community'), text: t.__('You’ll get your codes by email instead.', 'membuild-community'), confirm: t.__('Remove', 'membuild-community'), danger: true },
  )
  if (yes) act('app_remove').catch(say)
}

/* ---------- Email codes ---------- */
const emailStep = ref(false) // a code was sent to turn them on
const emailCode = ref('')
async function toggleEmail(on, ev) {
  if (on) {
    ev.target.checked = false // on only once the code is typed
    try {
      await act('email_send')
      emailStep.value = true
      emailCode.value = ''
    } catch (e) {
      say(e)
    }
    return
  }
  ev.target.checked = true
  const last = !info.value.app
  const yes = await askConfirm(
    last
      ? { title: t.__('Turn off two-step login?', 'membuild-community'), text: t.__('Email is your only way to get a code, so you’ll log in with just your password.', 'membuild-community'), note: t.__('Your recovery codes stop working too.', 'membuild-community'), confirm: t.__('Turn off', 'membuild-community'), cancel: t.__('Keep it on', 'membuild-community'), danger: true }
      : { title: t.__('Stop email codes?', 'membuild-community'), text: t.__('You’ll use your app for codes.', 'membuild-community'), confirm: t.__('Stop email codes', 'membuild-community'), danger: true },
  )
  if (yes) act('email_off').catch(say)
}
async function finishEmail() {
  const first = !info.value.on
  try {
    await act('email_on', { code: emailCode.value })
    emailStep.value = false
    if (first) await makeCodes()
  } catch (e) {
    say(e)
  }
}

/* ---------- Recovery codes (shown once) ---------- */
const codes = ref(null)
async function makeCodes() {
  try {
    const res = await act('codes')
    codes.value = res.new_codes
  } catch (e) {
    say(e)
  }
}
async function newCodes() {
  if (info.value.codes !== null) {
    const yes = await askConfirm({ title: t.__('Make new recovery codes?', 'membuild-community'), text: t.__('Your old recovery codes stop working.', 'membuild-community'), confirm: t.__('Make new codes', 'membuild-community') })
    if (!yes) return
  }
  makeCodes()
}
const codesText = () => codes.value.join('\n')
function downloadCodes() {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([t.__('Recovery codes. Each one works once.', 'membuild-community') + '\n\n' + codesText() + '\n'], { type: 'text/plain' }))
  a.download = 'recovery-codes.txt'
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}
function printCodes() {
  const w = window.open('', '_blank', 'width=480,height=640')
  if (!w) return
  const pre = w.document.createElement('pre')
  pre.textContent = t.__('Recovery codes. Each one works once.', 'membuild-community') + '\n\n' + codesText()
  pre.style.cssText = 'font: 18px/1.8 ui-monospace, Menlo, monospace; padding: 24px'
  w.document.body.appendChild(pre)
  w.print()
}

/* ---------- Turn it all off ---------- */
async function turnOff() {
  const yes = await askConfirm({
    title: t.__('Turn off two-step login?', 'membuild-community'),
    text: t.__('You’ll log in with just your password.', 'membuild-community'),
    note: t.__('Your app, email codes and recovery codes stop working. To turn it on again, you set it up from the start.', 'membuild-community'),
    confirm: t.__('Turn off', 'membuild-community'),
    cancel: t.__('Keep it on', 'membuild-community'),
    danger: true,
  })
  if (yes) act('off').catch(say)
}
</script>

<template>
  <div class="two-step">
    <RouterLink :to="{ name: 'profile-edit' }" class="back-link"><Icon name="chevronLeft" :size="18" /> {{ t.__('Edit profile', 'membuild-community') }}</RouterLink>
    <div class="head">
      <h1 class="page-title">{{ t.__('Two-step login', 'membuild-community') }}</h1>
      <template v-if="info?.available">
        <span v-if="info.on" class="status-tag lg ok"><Icon name="check" :size="16" /> {{ t.__('On', 'membuild-community') }}</span>
        <span v-else class="status-tag lg">{{ t.__('Off', 'membuild-community') }}</span>
      </template>
    </div>
    <p class="page-intro">{{ t.__('After your password, you type a code from your phone or email. Someone who learns your password still can’t get in.', 'membuild-community') }}</p>

    <p v-if="error" class="error-text" role="alert">{{ error }}</p>

    <p v-if="info && !info.available" class="card box">{{ t.__('Two-step login is off for this community.', 'membuild-community') }}</p>

    <template v-else-if="info">
      <section v-if="locked" class="card box">
        <h2>{{ t.__('Confirm it’s you', 'membuild-community') }}</h2>
        <p class="small">{{ info.app ? t.__('To change these, type a code from your app, or one of your recovery codes.', 'membuild-community') : t.__('To change these, type a code we email you, or one of your recovery codes.', 'membuild-community') }}</p>
        <form class="code-row" @submit.prevent="confirmIt">
          <input v-model="confirmCode" class="code" type="text" inputmode="numeric" autocomplete="one-time-code" :aria-label="t.__('Code', 'membuild-community')" placeholder="123 456" />
          <button class="btn btn-lg btn-primary" type="submit" :disabled="!confirmCode || busy === 'confirm'">{{ t.__('Confirm', 'membuild-community') }}</button>
        </form>
        <p v-if="info.email && !info.app" class="small">
          <button class="link" type="button" :disabled="busy === 'confirm_send'" @click="sendConfirm">{{ confirmSent ? t.__('Send it again', 'membuild-community') : t.__('Email me a code', 'membuild-community') }}</button>
          <span v-if="confirmSent"> · {{ t.sprintf(t.__('Sent to %s.', 'membuild-community'), info.address) }}</span>
        </p>
      </section>

      <section class="card box" :class="{ dim: locked }" :inert="locked || null">
        <h2>{{ t.__('Ways to get your code', 'membuild-community') }}</h2>
        <div class="row">
          <span>
            <strong>{{ t.__('Authenticator app', 'membuild-community') }}</strong>
            <small v-if="info.app">{{ since ? t.sprintf(t.__('On. Codes from the app on your phone. Set up %s.', 'membuild-community'), since) : t.__('On. Codes from the app on your phone.', 'membuild-community') }}</small>
            <small v-else>{{ t.__('Codes from an app on your phone, like Google Authenticator. Works without signal.', 'membuild-community') }}</small>
          </span>
          <button v-if="info.app" class="btn btn-lg btn-ghost" type="button" :disabled="!!busy" @click="removeApp">{{ t.__('Remove', 'membuild-community') }}</button>
          <button v-else class="btn btn-lg btn-ghost" type="button" :disabled="!!busy" @click="startApp">{{ t.__('Set up', 'membuild-community') }}</button>
        </div>
        <label class="row">
          <span>
            <strong>{{ t.__('Email codes', 'membuild-community') }}</strong>
            <small>{{ t.sprintf(t.__('We email a code to %s each time you log in.', 'membuild-community'), info.address) }}</small>
          </span>
          <input type="checkbox" class="switch" role="switch" :checked="info.email" :disabled="!!busy || emailStep" @change="toggleEmail($event.target.checked, $event)" />
        </label>
        <form v-if="emailStep" class="step" @submit.prevent="finishEmail">
          <p class="small">{{ t.sprintf(t.__('We sent a code to %s. Type it to turn this on.', 'membuild-community'), info.address) }}</p>
          <div class="code-row">
            <input v-model="emailCode" class="code" type="text" inputmode="numeric" autocomplete="one-time-code" :aria-label="t.__('Code from the email', 'membuild-community')" placeholder="12345678" />
            <button class="btn btn-lg btn-primary" type="submit" :disabled="!emailCode || busy === 'email_on'">{{ t.__('Turn on', 'membuild-community') }}</button>
            <button class="btn btn-lg btn-ghost" type="button" @click="emailStep = false">{{ t.__('Cancel', 'membuild-community') }}</button>
          </div>
        </form>
        <div v-if="info.on" class="row">
          <span>
            <strong>{{ t.__('Recovery codes', 'membuild-community') }}</strong>
            <small v-if="info.codes === null">{{ t.__('For when you can’t get the others. Each one works once.', 'membuild-community') }}</small>
            <small v-else :class="{ low: info.codes <= 2 }">{{ t.sprintf(t.__('%d of 10 left. Each one works once, for when you can’t get the others.', 'membuild-community'), info.codes) }}</small>
          </span>
          <button class="btn btn-lg btn-ghost" type="button" :disabled="!!busy" @click="newCodes">{{ info.codes === null ? t.__('Make codes', 'membuild-community') : t.__('Make new codes', 'membuild-community') }}</button>
        </div>
        <p v-if="!info.on" class="info-note"><Icon name="info" :size="20" /><span>{{ t.__('Pick one to turn two-step login on. Then make recovery codes, in case you lose your phone.', 'membuild-community') }}</span></p>
        <p v-else-if="info.required" class="info-note"><Icon name="info" :size="20" /><span>{{ t.__('Your community asks you to keep two-step login on.', 'membuild-community') }}</span></p>
      </section>

      <section v-if="info.on && !info.required" class="card box" :class="{ dim: locked }" :inert="locked || null">
        <h2>{{ t.__('Turn it off', 'membuild-community') }}</h2>
        <div class="row">
          <span>
            <strong>{{ t.__('Log in with just your password', 'membuild-community') }}</strong>
            <small>{{ t.__('You can turn it on again any time.', 'membuild-community') }}</small>
          </span>
          <button class="btn btn-lg btn-ghost" type="button" :disabled="!!busy" @click="turnOff">{{ t.__('Turn off', 'membuild-community') }}</button>
        </div>
      </section>
    </template>
    <p v-else-if="!error" class="card box">{{ t.__('Loading…', 'membuild-community') }}</p>

    <BaseDialog v-if="app" :title="t.__('Set up your authenticator app', 'membuild-community')" :width="560" @close="app = null">
      <form class="dlg" @submit.prevent="finishApp">
        <p class="small">{{ t.__('Use any authenticator app, like Google Authenticator, Microsoft Authenticator or 1Password.', 'membuild-community') }}</p>
        <p class="step-title"><span>1</span>{{ t.__('Scan this with the app', 'membuild-community') }}</p>
        <div class="qr-row">
          <span ref="qrBox" class="qr" role="img" :aria-label="t.__('QR code to scan with your app', 'membuild-community')" />
          <span class="key-box">
            {{ t.__('Can’t scan? Type this key instead:', 'membuild-community') }}
            <code>{{ spaced(app.key) }}</code>
            <button class="btn btn-lg btn-ghost" type="button" @click="copyKey">{{ copied ? t.__('Copied', 'membuild-community') : t.__('Copy key', 'membuild-community') }}</button>
          </span>
        </div>
        <p class="step-title"><span>2</span>{{ t.__('Type the 6-digit code the app shows', 'membuild-community') }}</p>
        <input v-model="appCode" class="code wide" type="text" inputmode="numeric" autocomplete="one-time-code" :aria-label="t.__('6-digit code', 'membuild-community')" placeholder="123 456" />
        <p v-if="appError" class="error-text" role="alert">{{ appError }}</p>
        <div class="dlg-actions">
          <button class="btn btn-lg btn-ghost" type="button" @click="app = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-primary" type="submit" :disabled="!appCode || busy === 'app_confirm'">{{ t.__('Turn on', 'membuild-community') }}</button>
        </div>
      </form>
    </BaseDialog>

    <BaseDialog v-if="codes" :title="t.__('Your recovery codes', 'membuild-community')" :width="560" @close="codes = null">
      <div class="dlg">
        <p class="small">{{ t.__('If you lose your phone, type one of these instead of a code. Each one works once.', 'membuild-community') }}</p>
        <div class="codes">
          <span v-for="c in codes" :key="c">{{ c }}</span>
        </div>
        <p class="info-note warn flush"><Icon name="info" :size="20" /><span>{{ t.__('Save them now. You won’t see them again. Making new ones stops these from working.', 'membuild-community') }}</span></p>
        <div class="code-row">
          <button class="btn btn-lg btn-ghost" type="button" @click="downloadCodes">{{ t.__('Download', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-ghost" type="button" @click="printCodes">{{ t.__('Print', 'membuild-community') }}</button>
        </div>
        <div class="dlg-actions">
          <button class="btn btn-lg btn-primary" type="button" @click="codes = null">{{ t.__('I saved them', 'membuild-community') }}</button>
        </div>
      </div>
    </BaseDialog>
  </div>
</template>

<style scoped>
.two-step {
  max-width: 760px;
  margin: 0 auto;
  padding: 24px 16px 40px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.page-intro {
  margin-top: -12px;
}

.box {
  padding: 28px 30px;
  border-radius: var(--radius-lg);
}

.box.dim {
  opacity: 0.55;
}

h2 {
  margin: 0 0 6px;
  font-size: var(--fs-3xl);
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 16px 0;
  border-top: 1px solid var(--border);
}

h2 + .row {
  margin-top: 10px;
}

label.row {
  cursor: pointer;
}

.row > span {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.row strong {
  font-size: var(--fs-xl);
}

.row small,
.small {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.row small.low {
  color: var(--warn-text);
}

.row .btn {
  flex: none;
}

.step {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 0 0 16px;
}

.code-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 12px;
}

.code {
  flex: 1;
  min-width: 160px;
  height: 52px;
  padding: 0 16px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-4xl);
  font-weight: 700;
  letter-spacing: 4px;
}

.code.wide {
  flex: none;
  width: 100%;
  box-sizing: border-box;
}

.link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}

.info-note.flush {
  margin: 0;
}

.dlg {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 4px 28px 24px;
}

.step-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
  font-size: var(--fs-xl);
  font-weight: 700;
}

.step-title span {
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--surface-2);
  font-size: var(--fs-md);
}

.qr-row {
  display: flex;
  align-items: center;
  gap: 20px;
}

.qr {
  flex: none;
  display: block;
  box-sizing: border-box;
  width: 160px;
  height: 160px;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--paper);
}

.qr :deep(svg) {
  display: block;
  width: 100%;
  height: 100%;
}

.key-box {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.key-box code {
  color: var(--text);
  font-family: ui-monospace, Menlo, monospace;
  font-size: var(--fs-xl);
  font-weight: 700;
  letter-spacing: 1px;
  user-select: all;
}

.codes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 24px;
  padding: 18px 22px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  font-family: ui-monospace, Menlo, monospace;
  font-size: var(--fs-2xl);
  font-weight: 700;
  letter-spacing: 1px;
  user-select: all;
}

.dlg-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

@media (max-width: 600px) {
  .box {
    padding: 20px 18px;
  }

  .row {
    align-items: flex-start;
  }

  .qr-row {
    flex-direction: column;
    align-items: flex-start;
  }

  .dlg {
    padding: 4px 18px 20px;
  }
}
</style>
