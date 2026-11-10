<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api } from '../../api.js'
import { askConfirm } from '../../lib/confirm.js'
import { showToast } from '../../lib/toast.js'
import Icon from '../Icon.vue'
import { t } from '../../lib/i18n.js'
import { priceAmount } from '../../lib/price.js'

/**
 * Settings › Add-ons: paid add-ons made by MemBuild, each its own plugin (CRM Connect first).
 * The list comes from tools.membuild.com, so new add-ons show up without an update here.
 * Paste the key → Check key → Install (a signed zip, checked on the server side of this
 * site before WordPress puts it on) → the key's details. Which site a key may be used on
 * is the server's answer; this shows it. Drawn on the add-ons canvas, boards LIC-*.
 */
const site = ref('') // this site, as the server compares addresses
const products = ref(null)
const error = ref('')
const ui = reactive({}) // per add-on: { input, result, busy, screen, step, url, installError }
const timers = []
const LOST = 'https://membuild.com/add-ons/lost-key'
const PAYPAL = 'https://www.paypal.com/myaccount/autopay/'

const state = (slug) => (ui[slug] ||= { input: '', result: null, busy: false, screen: '', step: 0, url: '', installError: '' })

onMounted(async () => {
  try {
    const res = await api.addons()
    site.value = res.site
    products.value = res.products
    // A saved key the server now turns down stays in the box, ready to try again.
    res.products.forEach((p) => (state(p.slug).input = p.key?.error ? p.key.key : ''))
  } catch (e) {
    error.value = e.message
  }
})
onBeforeUnmount(() => timers.forEach(clearTimeout))

function put(product) {
  if (!product) return
  const i = products.value.findIndex((p) => p.slug === product.slug)
  if (i >= 0) products.value[i] = product
}

/* ---------- Words and dates ---------- */

const day = (ymd) => {
  const [y, m, d] = String(ymd || '').split('-').map(Number)
  return y ? new Date(y, m - 1, d) : null
}
const fmt = (date) => (date ? date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '')
const plusDays = (ymd, n) => {
  const d = day(ymd)
  if (d) d.setDate(d.getDate() + n)
  return d
}
const daysLeft = (ymd) => {
  const d = day(ymd)
  if (!d) return Infinity
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.round((d - today) / 86400000)
}
// Just the amount ("$69 a year" → "$69"), since every line adds its own "a year".
const money = (p) => priceAmount(p.price)
const yearly = (p) => t.sprintf(/* translators: %s: a price, e.g. $69 */ t.__('%s a year', 'membuild-community'), money(p))
// "groundwork.community" → "groundwork.local", for the line about copies on your own computer.
const localName = (addr) => {
  const host = String(addr || '').split('/')[0].split(':')[0]
  const parts = host.split('.')
  return (parts.length > 1 ? parts.slice(0, -1).join('.') : host) + '.local'
}
const checkedWhen = (ts) => {
  if (!ts) return t.__('Not yet', 'membuild-community')
  const d = new Date(ts * 1000)
  const today = new Date()
  if (d.toDateString() === today.toDateString()) return t.__('Checked today', 'membuild-community')
  today.setDate(today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return t.__('Checked yesterday', 'membuild-community')
  return t.sprintf(/* translators: %s: a date */ t.__('Checked %s', 'membuild-community'), fmt(d))
}
const ordinal = (n) => [t.__('This is the first.', 'membuild-community'), t.__('This is the second.', 'membuild-community'), t.__('This is the third.', 'membuild-community')][3 - n] || ''

/* ---------- Where each add-on stands ---------- */

const SITE_ERRORS = ['used', 'test_taken', 'other_site']

/** The key's last answer when it worked, else null. */
const good = (p) => (p.key && p.key.last?.ok && !p.key.error ? p.key.last : null)
/** Why the key can't be used here: the answer just given, or the saved key's last refusal. */
const refusal = (p) => {
  const r = state(p.slug).result
  if (r && !r.ok) return r
  return p.key?.error && !p.key.error.ok ? p.key.error : null
}
/** list | used | test_taken | other_site | ready | installing | blocked | installed */
const screen = (p) => {
  const s = state(p.slug)
  if (s.screen) return s.screen
  const r = refusal(p)
  if (r && SITE_ERRORS.includes(r.code)) return r.code
  if (good(p)) return p.installed ? 'installed' : 'ready'
  return 'list'
}
// "staging.groundwork.community. Added 12 Nov." (the date when the server sends it).
const testLine = (p) => {
  const k = good(p)
  const added = k.test_added ? t.sprintf(/* translators: %s: a date */ t.__('Added %s.', 'membuild-community'), fmt(day(k.test_added))) : ''
  return [`${k.test_site}.`, added].filter(Boolean).join(' ')
}
const role = (p) => good(p)?.role || ''
const ended = (p) => good(p) && good(p).updates === false
// A banner over the add-on, only on the live site once installed.
const banner = (p) => {
  const k = good(p)
  if (!k || !p.installed || role(p) !== 'live') return ''
  if (ended(p)) return 'ended'
  if (k.status === 'payment_failed') return 'payfail'
  if (k.status === 'active' && daysLeft(k.paid_until) <= 14) return 'soon'
  return ''
}
const thisSite = (p) => good(p)?.site || site.value

function updatesLine(p) {
  const k = good(p)
  const until = fmt(day(k.paid_until))
  if (ended(p)) {
    const why = { cancelled: t.__('You cancelled on PayPal.', 'membuild-community'), refunded: t.__('The payment was refunded.', 'membuild-community') }[k.status] || ''
    return { text: [t.sprintf(/* translators: %s: a date */ t.__('Ended %s.', 'membuild-community'), until), why].filter(Boolean).join(' '), tag: t.__('Ended', 'membuild-community'), kind: 'warn' }
  }
  if (k.status === 'payment_failed') {
    return { text: t.sprintf(/* translators: %s: a date */ t.__('PayPal is trying again until %s', 'membuild-community'), fmt(plusDays(k.paid_until, 10))), tag: t.__('Payment failed', 'membuild-community'), kind: 'warn' }
  }
  if (k.status === 'manual') {
    return { text: t.sprintf(/* translators: %s: a date */ t.__('Until %s', 'membuild-community'), until), tag: t.__('Active', 'membuild-community'), kind: 'ok', icon: 'check' }
  }
  const soon = daysLeft(k.paid_until) <= 14
  return {
    text: t.sprintf(/* translators: %s: a date */ t.__('Renews %s with PayPal', 'membuild-community'), until),
    tag: soon ? t.__('Renews soon', 'membuild-community') : t.__('Active', 'membuild-community'),
    kind: soon ? 'warn' : 'ok',
    icon: soon ? '' : 'check',
  }
}

/** The plain sentence under the key box when the key wasn't taken. */
function keyError(p) {
  const r = refusal(p)
  if (!r || SITE_ERRORS.includes(r.code)) return ''
  if (r.code === 'not_found') return t.__('We couldn’t find that key. Check it against the email from MemBuild. Lost it? We can email it to you again.', 'membuild-community')
  return r.message || t.__('That didn’t work. Please try again in a few minutes.', 'membuild-community')
}

// The changelog's first lines, then what to do: "Adds ActiveCampaign and fixes 4 things. Renew to install it."
const lockedLine = (p) => [String(p.update?.changelog || '').split('\n').map((l) => l.replace(/^[\s*•-]+/, '').trim()).filter(Boolean)[0] || '', t.__('Renew to install it.', 'membuild-community')].filter(Boolean).join(' ')

const STEPS = [
  t.__('Getting it from MemBuild', 'membuild-community'),
  t.__('Checking it really comes from MemBuild', 'membuild-community'),
  t.__('Putting it on your site', 'membuild-community'),
  t.__('Switching it on', 'membuild-community'),
]

/* ---------- Doing things ---------- */

async function check(p, extra = {}) {
  const s = state(p.slug)
  const key = extra.key || s.input || p.key?.key || ''
  if (!key.trim() || s.busy) return
  s.busy = true
  s.installError = ''
  try {
    const res = await api.checkAddonKey({ slug: p.slug, key, ...extra })
    put(res.product)
    s.result = res.ok ? null : { ...res, key }
    if (res.ok) s.input = ''
  } catch (e) {
    s.result = { ok: false, code: 'no_answer', message: e.message, key }
  } finally {
    s.busy = false
  }
}

async function move(p) {
  const r = refusal(p)
  const lines = [{ icon: 'check', text: t.__('Updates and help come to this site from now on.', 'membuild-community') }]
  lines.push({ icon: 'clock', text: [t.__('A key can move 3 times a year.', 'membuild-community'), Number.isInteger(r.moves_left) ? ordinal(r.moves_left) : ''].filter(Boolean).join(' ') })
  const yes = await askConfirm({
    title: t.sprintf(/* translators: %s: this site's address */ t.__('Move the key to %s?', 'membuild-community'), site.value),
    text: t.sprintf(/* translators: 1: the old site's address, 2: add-on name */ t.__('%1$s stops getting updates and help. %2$s keeps working there, and its members notice nothing.', 'membuild-community'), r.live_site, p.name),
    points: lines,
    confirm: t.__('Move the key here', 'membuild-community'),
  })
  if (yes) check(p, { key: r.key || p.key?.key, move: true })
}

const useHere = (p) => check(p, { key: refusal(p).key || p.key?.key, use_here: true })
const cancel = (p) => (state(p.slug).result = null)

async function install(p) {
  const s = state(p.slug)
  s.screen = 'installing'
  s.step = 0
  s.installError = ''
  // The server does it in one go; the steps move along while it works and finish when it answers.
  timers.push(setTimeout(() => s.screen === 'installing' && s.step < 1 && (s.step = 1), 1500))
  timers.push(setTimeout(() => s.screen === 'installing' && s.step < 2 && (s.step = 2), 3500))
  try {
    const res = await api.installAddon(p.slug)
    if (res.ok) {
      s.step = 4
      timers.push(setTimeout(() => {
        s.screen = ''
        put(res.product)
      }, 700))
    } else if (res.code === 'host_blocked') {
      s.screen = 'blocked'
      s.url = res.url
    } else {
      s.screen = ''
      s.installError = res.message
    }
  } catch (e) {
    s.screen = ''
    s.installError = e.message
  }
}

async function downloadZip(p) {
  const s = state(p.slug)
  s.busy = true
  try {
    // The link works for 1 hour, so a fresh one each time.
    const res = await api.addonDownload(p.slug)
    if (res.ok) window.location.href = res.url
    else showToast(res.message)
  } catch (e) {
    showToast(e.message)
  } finally {
    s.busy = false
  }
}

async function copyKey(p) {
  try {
    await navigator.clipboard.writeText(p.key.key)
    showToast(t.__('Key copied.', 'membuild-community'))
  } catch {
    showToast(t.__('Couldn’t copy. Select it and copy it yourself.', 'membuild-community'))
  }
}

async function remove(p, which) {
  const k = good(p)
  const yes =
    which === 'test'
      ? await askConfirm({
          title: t.__('Remove the test copy?', 'membuild-community'),
          text: t.sprintf(/* translators: 1: the test copy's address, 2: add-on name */ t.__('%1$s stops getting updates. %2$s keeps working there. Your next test copy can use the key.', 'membuild-community'), k.test_site, p.name),
          confirm: t.__('Remove', 'membuild-community'),
        })
      : await askConfirm({
          title: t.__('Remove the key from this site?', 'membuild-community'),
          text: t.sprintf(/* translators: 1: this site's address, 2: add-on name */ t.__('%1$s stops getting updates and help. %2$s keeps working here until you switch it off. Then paste the key on the new site.', 'membuild-community'), thisSite(p), p.name),
          confirm: t.__('Remove key from this site', 'membuild-community'),
        })
  if (!yes) return
  const s = state(p.slug)
  s.busy = true
  try {
    const res = await api.removeAddonKey(p.slug, which)
    if (res.ok) put(res.product)
    else showToast(res.message)
  } catch (e) {
    showToast(e.message)
  } finally {
    s.busy = false
  }
}

const open = (url) => window.open(url, '_blank', 'noopener')
const noneInstalled = () => !products.value?.some((p) => p.installed)
</script>

<template>
  <section id="s-addons" class="card group ao">
    <h2>{{ t.__('Add-ons', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Extra features made by MemBuild. Each one is its own plugin, paid yearly.', 'membuild-community') }}</p>

    <p v-if="error" class="error-text" role="alert">{{ error }}</p>
    <p v-else-if="!products" class="ao-muted">{{ t.__('Loading…', 'membuild-community') }}</p>

    <div v-else class="ao-list">
    <template v-for="p in products" :key="p.slug">
      <!-- This site, on a test copy, the owner's computer, or a site the key isn't for. -->
      <div v-if="['test', 'local'].includes(role(p)) || ['test_taken', 'other_site'].includes(screen(p))" class="ao-site">
        <Icon name="globe" :size="20" />
        <span class="ao-grow">
          <small>{{ t.__('This site', 'membuild-community') }}</small>
          <strong>{{ site }}</strong>
        </span>
        <span v-if="screen(p) === 'other_site'" class="status-tag warn">{{ t.__('Other site', 'membuild-community') }}</span>
        <span v-else-if="role(p) === 'local'" class="status-tag">{{ t.__('Your computer', 'membuild-community') }}</span>
        <span v-else class="status-tag accent">{{ t.__('Test copy', 'membuild-community') }}</span>
      </div>

      <!-- Renews soon, payment failed, year ended. -->
      <div v-if="banner(p)" class="ao-banner" :class="banner(p) === 'soon' ? 'quiet' : 'warn'">
        <Icon :name="{ soon: 'clock', payfail: 'alert', ended: 'info' }[banner(p)]" :size="20" />
        <span class="ao-grow">
          <template v-if="banner(p) === 'soon'">
            <strong>{{ t.sprintf(/* translators: %d: number of days */ t._n('Renews in %d day', 'Renews in %d days', daysLeft(good(p).paid_until), 'membuild-community'), daysLeft(good(p).paid_until)) }}</strong>
            <span>{{ t.sprintf(/* translators: 1: a price, 2: a date */ t.__('PayPal takes %1$s on %2$s. Nothing to do. We email you a week before.', 'membuild-community'), money(p), fmt(day(good(p).paid_until))) }}</span>
          </template>
          <template v-else-if="banner(p) === 'payfail'">
            <strong>{{ t.__('PayPal couldn’t take the renewal', 'membuild-community') }}</strong>
            <span>{{ t.__('PayPal tries again for 10 days. Check your card in PayPal. Until then, everything carries on as normal.', 'membuild-community') }}</span>
          </template>
          <template v-else>
            <strong>{{ t.sprintf(/* translators: %s: a date */ t.__('Your year ended on %s', 'membuild-community'), fmt(day(good(p).paid_until))) }}</strong>
            <span>{{ t.sprintf(/* translators: %s: add-on name */ t.__('%s keeps working exactly as it is. Renew to get updates and help again.', 'membuild-community'), p.name) }}</span>
          </template>
        </span>
        <button v-if="banner(p) === 'ended'" type="button" class="btn btn-primary" @click="open(p.page_url)"><Icon name="external" :size="18" />{{ t.sprintf(/* translators: %s: a price, e.g. $69 */ t.__('Renew for %s a year', 'membuild-community'), money(p)) }}</button>
        <button v-else type="button" class="btn ao-line" @click="open(PAYPAL)">
          <Icon name="external" :size="18" />{{ banner(p) === 'soon' ? t.__('Manage on PayPal', 'membuild-community') : t.__('Open PayPal', 'membuild-community') }}
        </button>
      </div>

      <div class="ao-box">
        <!-- The add-on: name, price, one line, and what you can do. -->
        <div class="ao-head">
          <span class="ao-tile"><Icon name="server" :size="26" /></span>
          <span class="ao-grow">
            <span class="ao-name">
              <h3>{{ p.name }}</h3>
              <span class="status-tag">{{ yearly(p) }}</span>
            </span>
            <span class="ao-line-text">{{ p.line }}</span>
          </span>
          <span class="ao-right">
            <template v-if="p.installed && screen(p) === 'installed'">
              <span v-if="ended(p)" class="status-tag">{{ t.sprintf(/* translators: %s: version number */ t.__('Installed %s', 'membuild-community'), p.installed.version) }}</span>
              <span v-else class="status-tag ok"><Icon name="check" :size="14" />{{ t.__('Installed', 'membuild-community') }}</span>
              <a v-if="role(p) === 'live' && !banner(p)" class="btn btn-primary" :href="p.open_url">{{ t.sprintf(/* translators: %s: add-on name */ t.__('Open %s', 'membuild-community'), p.name) }}</a>
            </template>
            <button v-else-if="['list'].includes(screen(p)) && p.page_url" type="button" class="btn" @click="open(p.page_url)"><Icon name="external" :size="18" />{{ t.__('See what it does', 'membuild-community') }}</button>
          </span>
        </div>

        <!-- Paste the key. -->
        <form v-if="['list', 'used', 'test_taken', 'other_site'].includes(screen(p))" class="ao-key" @submit.prevent="check(p)">
          <label :for="`ao-key-${p.slug}`">{{ t.__('Already bought it? Paste your key', 'membuild-community') }}</label>
          <div class="ao-key-row">
            <input
              :id="`ao-key-${p.slug}`"
              v-model="state(p.slug).input"
              type="text"
              autocomplete="off"
              spellcheck="false"
              :class="{ bad: !!keyError(p) }"
              :placeholder="t.__('Paste your key, like MBC-XXXX-XXXX-XXXX', 'membuild-community')"
              :aria-describedby="`ao-key-help-${p.slug}`"
            />
            <button type="submit" class="btn btn-primary btn-lg" :disabled="state(p.slug).busy">{{ t.__('Check key', 'membuild-community') }}</button>
          </div>
          <p v-if="keyError(p)" :id="`ao-key-help-${p.slug}`" class="ao-error" role="alert"><Icon name="alert" :size="16" /><span>{{ keyError(p) }}</span></p>
          <p v-else :id="`ao-key-help-${p.slug}`" class="ao-muted">{{ t.__('It’s in the email from MemBuild, and on the Thank you page after you paid.', 'membuild-community') }}</p>
          <a v-if="refusal(p)?.code === 'not_found'" class="ao-link" :href="LOST" target="_blank" rel="noopener"><Icon name="mail" :size="18" />{{ t.__('Email me my key', 'membuild-community') }}</a>
        </form>

        <!-- The key is used on another live site. -->
        <div v-if="screen(p) === 'used'" class="ao-note warn">
          <strong><Icon name="alert" :size="20" />{{ t.sprintf(/* translators: %s: a site's address */ t.__('This key is already used on %s', 'membuild-community'), refusal(p).live_site) }}</strong>
          <span>{{ t.sprintf(/* translators: 1: a site's address, 2: add-on name */ t.__('A key covers 1 site. Move it here and %1$s stops getting updates. %2$s keeps working there until you remove it.', 'membuild-community'), refusal(p).live_site, p.name) }}</span>
          <span class="ao-buttons">
            <button type="button" class="btn btn-primary" :disabled="state(p.slug).busy" @click="move(p)">{{ t.__('Move the key here', 'membuild-community') }}</button>
            <button type="button" class="btn ao-line" @click="open(p.page_url)"><Icon name="external" :size="18" />{{ t.__('Buy another key', 'membuild-community') }}</button>
          </span>
        </div>

        <!-- The key already has a test copy. -->
        <div v-else-if="screen(p) === 'test_taken'" class="ao-note warn">
          <strong><Icon name="alert" :size="20" />{{ t.__('Your key already has a test copy', 'membuild-community') }}</strong>
          <span>{{ t.sprintf(/* translators: %s: the test copy's address */ t.__('It’s %1$s. A key covers 1 test copy. Use it here instead, and %1$s stops getting updates.', 'membuild-community'), refusal(p).test_site) }}</span>
          <span class="ao-buttons">
            <button type="button" class="btn btn-primary" :disabled="state(p.slug).busy" @click="useHere(p)">{{ t.__('Use it here instead', 'membuild-community') }}</button>
            <button type="button" class="btn ao-line" @click="cancel(p)">{{ t.__('Cancel', 'membuild-community') }}</button>
          </span>
        </div>

        <!-- A different site, not a copy of the key's. -->
        <div v-else-if="screen(p) === 'other_site'" class="ao-note warn">
          <strong><Icon name="alert" :size="20" />{{ t.sprintf(/* translators: %s: a site's address */ t.__('This isn’t a copy of %s', 'membuild-community'), refusal(p).live_site) }}</strong>
          <span>{{ t.sprintf(/* translators: 1: the key's site, 2: this site */ t.__('Your key is for %1$s and its copies. %2$s is a different site, so it needs its own key.', 'membuild-community'), refusal(p).live_site, site) }}</span>
          <span class="ao-buttons">
            <button type="button" class="btn btn-primary" @click="open(p.page_url)"><Icon name="external" :size="18" />{{ t.__('Buy a key for this site', 'membuild-community') }}</button>
          </span>
        </div>

        <!-- The key works: install. -->
        <template v-else-if="screen(p) === 'ready'">
          <div class="ao-note ok row">
            <Icon name="check" :size="20" />
            <span class="ao-grow">
              <strong>{{ t.__('Your key works', 'membuild-community') }}</strong>
              <span>{{ t.sprintf(/* translators: %s: a date */ t.__('Updates and help until %s. Used on this site.', 'membuild-community'), fmt(day(good(p).paid_until))) }}</span>
            </span>
            <button type="button" class="btn btn-primary btn-lg" @click="install(p)"><Icon name="download" :size="20" />{{ t.sprintf(/* translators: %s: add-on name */ t.__('Install %s', 'membuild-community'), p.name) }}</button>
          </div>
          <p v-if="state(p.slug).installError" class="error-text ao-after" role="alert">{{ state(p.slug).installError }}</p>
        </template>

        <!-- Installing. -->
        <div v-else-if="screen(p) === 'installing'" class="ao-progress" role="status">
          <span class="ao-progress-top">
            <strong>{{ t.sprintf(/* translators: %s: add-on name */ t.__('Installing %s', 'membuild-community'), p.name) }}</strong>
            <span class="ao-muted">{{ t.__('About 20 seconds', 'membuild-community') }}</span>
          </span>
          <span class="ao-bar"><span :style="{ width: [12, 35, 60, 85, 100][state(p.slug).step] + '%' }" /></span>
          <ul class="ao-rows">
            <li v-for="(label, i) in STEPS" :key="label">
              <span class="ao-grow"><strong>{{ label }}</strong></span>
              <span v-if="i < state(p.slug).step" class="status-tag ok"><Icon name="check" :size="14" />{{ t.__('Done', 'membuild-community') }}</span>
              <span v-else-if="i === state(p.slug).step" class="status-tag accent">{{ t.__('Now', 'membuild-community') }}</span>
              <span v-else class="status-tag">{{ t.__('Next', 'membuild-community') }}</span>
            </li>
          </ul>
          <p class="ao-muted">{{ t.__('You can leave this page. It finishes on its own.', 'membuild-community') }}</p>
        </div>

        <!-- The host won't let WordPress install it: by hand. -->
        <template v-else-if="screen(p) === 'blocked'">
          <div class="ao-note warn row">
            <Icon name="alert" :size="20" />
            <span class="ao-grow">
              <strong>{{ t.__('Your host doesn’t let WordPress install plugins on its own', 'membuild-community') }}</strong>
              <span>{{ t.__('Nothing changed on your site. You can put it on by hand in about a minute.', 'membuild-community') }}</span>
            </span>
          </div>
          <ul class="ao-rows">
            <li>
              <span class="ao-grow"><strong>{{ t.__('1. Download the zip', 'membuild-community') }}</strong><small>{{ t.__('It’s made for your key, and the link works for 1 hour.', 'membuild-community') }}</small></span>
            </li>
            <li>
              <span class="ao-grow"><strong>{{ t.__('2. In WordPress, open Plugins › Add New › Upload Plugin', 'membuild-community') }}</strong><small>{{ t.__('Pick the zip you downloaded, then press Install Now.', 'membuild-community') }}</small></span>
            </li>
            <li>
              <span class="ao-grow"><strong>{{ t.__('3. Press Activate', 'membuild-community') }}</strong><small>{{ t.__('Come back here. Your key is already saved.', 'membuild-community') }}</small></span>
            </li>
          </ul>
          <span class="ao-buttons ao-after">
            <button type="button" class="btn btn-primary btn-lg" :disabled="state(p.slug).busy" @click="downloadZip(p)"><Icon name="download" :size="20" />{{ t.__('Download the zip', 'membuild-community') }}</button>
            <button type="button" class="btn btn-lg" @click="install(p)">{{ t.__('Try again', 'membuild-community') }}</button>
          </span>
        </template>

        <!-- Installed. -->
        <template v-else-if="screen(p) === 'installed'">
          <div v-if="role(p) === 'test'" class="ao-note ok row">
            <Icon name="check" :size="20" />
            <span class="ao-grow">
              <strong>{{ t.sprintf(/* translators: %s: the live site's address */ t.__('This is the test copy for %s', 'membuild-community'), good(p).live_site) }}</strong>
              <span>{{ t.__('It uses the 1 test copy on your key. Updates come here too.', 'membuild-community') }}</span>
            </span>
          </div>
          <div v-else-if="role(p) === 'local'" class="ao-note ok row">
            <Icon name="check" :size="20" />
            <span class="ao-grow">
              <strong>{{ t.__('On your own computer, so it’s free', 'membuild-community') }}</strong>
              <span>{{ t.sprintf(/* translators: %s: a site's address */ t.__('Copies like %s can’t be reached from the internet, so they never count.', 'membuild-community'), site) }}</span>
            </span>
          </div>
          <div v-else-if="ended(p) && p.update?.newer" class="ao-locked">
            <Icon name="lock" :size="18" />
            <span class="ao-grow">
              <strong>{{ t.sprintf(/* translators: %s: version number */ t.__('Version %s is ready', 'membuild-community'), p.update.version) }}</strong>
              <span>{{ lockedLine(p) }}</span>
            </span>
          </div>
          <div v-else-if="!banner(p)" class="ao-stats">
            <span class="ao-stat">
              <b>{{ p.installed.version }}</b>
              <strong>{{ t.__('version', 'membuild-community') }}</strong>
              <small>{{ p.update?.newer && p.update.updates ? t.sprintf(/* translators: %s: version number */ t.__('%s is ready in Updates', 'membuild-community'), p.update.version) : t.__('up to date', 'membuild-community') }}</small>
            </span>
            <span class="ao-stat">
              <b>{{ checkedWhen(p.update?.checked) }}</b>
              <strong>{{ t.__('for updates', 'membuild-community') }}</strong>
              <small>{{ t.__('looks once a day', 'membuild-community') }}</small>
            </span>
          </div>
        </template>

        <!-- The three rules, before buying and before installing. -->
        <ul v-if="['list', 'ready'].includes(screen(p))" class="ao-rules">
          <li><Icon name="globe" :size="16" />{{ t.__('1 site and 1 test copy', 'membuild-community') }}</li>
          <li><Icon name="lock" :size="16" />{{ t.__('Copies on your own computer don’t count', 'membuild-community') }}</li>
          <li><Icon name="check" :size="16" />{{ t.__('Keeps working after the year, without updates', 'membuild-community') }}</li>
        </ul>
      </div>

      <!-- Your key: on the live site, once installed. -->
      <div v-if="screen(p) === 'installed' && role(p) === 'live'" class="ao-box">
        <div class="ao-key-head">
          <span class="ao-grow">
            <h3>{{ t.__('Your key', 'membuild-community') }}</h3>
            <span class="ao-line-text">{{ t.__('One key for this site and one test copy.', 'membuild-community') }}</span>
          </span>
          <button type="button" class="btn" @click="copyKey(p)"><Icon name="copy" :size="18" />{{ t.__('Copy key', 'membuild-community') }}</button>
        </div>
        <ul class="ao-rows">
          <li>
            <span class="ao-grow"><strong>{{ t.__('Key', 'membuild-community') }}</strong></span>
            <code class="ao-mono">{{ p.key.key }}</code>
          </li>
          <li>
            <span class="ao-grow"><strong>{{ t.__('Updates and help', 'membuild-community') }}</strong><small>{{ updatesLine(p).text }}</small></span>
            <span class="status-tag" :class="updatesLine(p).kind"><Icon v-if="updatesLine(p).icon" :name="updatesLine(p).icon" :size="14" />{{ updatesLine(p).tag }}</span>
          </li>
          <li>
            <span class="ao-grow"><strong>{{ t.__('This site', 'membuild-community') }}</strong><small>{{ thisSite(p) }}</small></span>
            <span class="status-tag accent">{{ t.__('Live site', 'membuild-community') }}</span>
          </li>
          <li>
            <span class="ao-grow">
              <strong>{{ t.__('Test copy', 'membuild-community') }}</strong>
              <small v-if="good(p).test_site">{{ testLine(p) }}</small>
              <small v-else>{{ t.sprintf(/* translators: 1: an example address, 2: add-on name */ t.__('None yet. A copy like %1$s uses it when you put %2$s there.', 'membuild-community'), 'staging.' + thisSite(p).split('/')[0], p.name) }}</small>
            </span>
            <button v-if="good(p).test_site" type="button" class="btn" :disabled="state(p.slug).busy" @click="remove(p, 'test')">{{ t.__('Remove', 'membuild-community') }}</button>
            <span v-else class="status-tag">{{ t.__('Free', 'membuild-community') }}</span>
          </li>
          <li>
            <span class="ao-grow">
              <strong>{{ t.__('Copies on your own computer', 'membuild-community') }}</strong>
              <small>{{ t.sprintf(/* translators: %s: an example address */ t.__('Like %s. Always free and never counted.', 'membuild-community'), localName(thisSite(p))) }}</small>
            </span>
            <span class="status-tag">{{ t.__('Free', 'membuild-community') }}</span>
          </li>
          <li>
            <span class="ao-grow">
              <strong>{{ t.__('Moving to another site?', 'membuild-community') }}</strong>
              <small>{{ t.sprintf(/* translators: %s: add-on name */ t.__('Remove the key here, then paste it on the new site. %s keeps working here until you switch it off.', 'membuild-community'), p.name) }}</small>
            </span>
            <button type="button" class="btn ao-line" :disabled="state(p.slug).busy" @click="remove(p, 'this')">{{ t.__('Remove key from this site', 'membuild-community') }}</button>
          </li>
        </ul>
      </div>
    </template>

    <div v-if="noneInstalled() && products.every((p) => ['list', 'ready'].includes(screen(p)))" class="ao-soon">
      <Icon name="sparkle" :size="18" />
      <span class="ao-grow">
        <strong>{{ t.__('More add-ons are on the way', 'membuild-community') }}</strong>
        <span>{{ t.__('They show up here by themselves when they’re ready. Nothing is installed until you pick one.', 'membuild-community') }}</span>
      </span>
    </div>
    </div>
  </section>
</template>

<style scoped>
.ao-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.ao-grow {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.ao-muted {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}

/* One add-on, or the key's details: a quiet box inside the section. */
.ao-box {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 22px 24px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
}

.ao-head,
.ao-key-head {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.ao-tile {
  flex: none;
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: var(--radius-md);
  background: var(--accent);
  color: var(--accent-ink);
}

.ao-name {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 10px;
}

.ao.group .ao-box h3 {
  margin: 0;
  font-size: var(--fs-4xl);
}

.ao.group .ao-key-head h3 {
  font-size: var(--fs-2xl);
}

.ao-line-text {
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.45;
}

.ao-right {
  flex: none;
  display: flex;
  align-items: center;
  gap: 10px;
}

.ao .btn {
  flex: none;
  text-decoration: none;
  white-space: nowrap;
}

/* The outlined button of the boards ("Buy another key", "Remove key from this site"). */
.ao .btn.ao-line {
  border: 1px solid var(--border-strong);
  background: var(--surface);
}

/* Paste your key. */
.ao-key {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ao-key label {
  font-size: var(--fs-lg);
  font-weight: 700;
}

.ao-key-row {
  display: flex;
  gap: 10px;
}

.ao.group .ao-key-row input {
  flex: 1 1 auto;
  min-width: 0;
  height: 52px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font-family: ui-monospace, Menlo, monospace;
  font-size: var(--fs-lg);
}

.ao.group .ao-key-row input.bad {
  border: 2px solid var(--danger);
}

.ao-key-row input:focus {
  outline: 2px solid var(--accent);
  outline-offset: 1px;
}

.ao-error {
  display: flex;
  gap: 8px;
  margin: 0;
  color: var(--danger-text);
  font-size: var(--fs-base);
  line-height: 1.45;
}

.ao-error svg {
  flex: none;
  margin-top: 3px;
}

.ao-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  align-self: flex-start;
  min-height: 44px;
  color: var(--accent-text);
  font-weight: 700;
  text-decoration: none;
}

.ao-link:hover {
  text-decoration: underline;
}

/* A soft box saying how things stand: ok (green), warn (yellow). */
.ao-note {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px 18px;
  border-radius: var(--radius);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.ao-note.row {
  flex-direction: row;
  align-items: center;
  gap: 12px;
}

.ao-note > strong,
.ao-note .ao-grow strong {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: var(--fs-xl);
}

.ao-note.ok {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.ao-note.warn {
  background: var(--warn-soft);
  color: var(--warn-text);
}

.ao-note > svg {
  flex: none;
}

.ao-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.ao-after {
  margin-top: -4px;
}

/* Banners above the add-on: renews soon (grey), payment failed or year ended (yellow). */
.ao-banner {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px 20px;
  border-radius: var(--radius);
  font-size: var(--fs-base);
  line-height: 1.45;
}

.ao-banner strong {
  font-size: var(--fs-xl);
}

.ao-banner > svg {
  flex: none;
}

.ao-banner.quiet {
  background: var(--surface-2);
  color: var(--text);
}

.ao-banner.quiet span span {
  color: var(--text-2);
}

.ao-banner.warn {
  background: var(--warn-soft);
  color: var(--warn-text);
}

/* This site, on a copy. */
.ao-site {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 18px;
  border-radius: var(--radius);
  background: var(--surface-2);
  color: var(--text-2);
}

.ao-site small {
  font-size: var(--fs-md);
  font-weight: 650;
}

.ao-site strong {
  color: var(--text);
  font-size: var(--fs-xl);
  overflow-wrap: anywhere;
}

/* Rows: a label and one short line, the control on the right. */
.ao-rows {
  margin: 0;
  padding: 0;
  list-style: none;
}

.ao-rows li {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 13px 0;
  border-top: 1px solid var(--border);
}

.ao-rows strong {
  font-size: var(--fs-lg);
}

.ao-rows small {
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.45;
  overflow-wrap: anywhere;
}

.ao-mono {
  padding: 12px 14px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--text);
  font-family: ui-monospace, Menlo, monospace;
  font-size: var(--fs-base);
  white-space: nowrap;
}

/* Installing. */
.ao-progress {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ao-progress-top {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 10px;
}

.ao-progress-top strong {
  font-size: var(--fs-2xl);
}

.ao-bar {
  height: 10px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  overflow: hidden;
}

.ao-bar span {
  display: block;
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--accent);
  transition: width 0.6s ease;
}

/* Installed: version and the daily look. */
.ao-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}

.ao-stat {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
}

.ao-stat b {
  font-size: var(--fs-6xl);
  line-height: 1.2;
}

.ao-stat strong {
  font-size: var(--fs-base);
}

.ao-stat small {
  color: var(--text-2);
  font-size: var(--fs-md);
}

/* A newer version that needs the year renewed. */
.ao-locked {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  font-size: var(--fs-base);
}

.ao-locked > svg {
  flex: none;
  color: var(--text-2);
}

.ao-locked strong {
  font-size: var(--fs-lg);
}

.ao-locked span span {
  color: var(--text-2);
}

/* The three rules, under a line. */
.ao-rules {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin: 0;
  padding: 14px 0 0;
  border-top: 1px solid var(--border);
  list-style: none;
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 500;
}

.ao-rules li {
  display: flex;
  align-items: center;
  gap: 6px;
}

.ao-soon {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px 18px;
  border-radius: var(--radius);
  background: var(--surface-2);
  font-size: var(--fs-base);
}

.ao-soon > svg {
  flex: none;
  margin-top: 3px;
  color: var(--text-2);
}

.ao-soon span span {
  color: var(--text-2);
}

@media (max-width: 767px) {
  .ao-box {
    padding: 18px 16px;
  }

  .ao-head,
  .ao-key-head,
  .ao-note.row,
  .ao-banner {
    flex-wrap: wrap;
  }

  /* The tile and the name stay side by side; the buttons go underneath. */
  .ao-head .ao-grow {
    flex-basis: calc(100% - 68px);
  }

  .ao-right {
    width: 100%;
  }

  .ao-key-row {
    flex-direction: column;
  }

  .ao-rows li {
    flex-wrap: wrap;
  }

  .ao-stats {
    grid-template-columns: 1fr;
  }

  .ao-mono {
    white-space: normal;
    overflow-wrap: anywhere;
  }
}
</style>
