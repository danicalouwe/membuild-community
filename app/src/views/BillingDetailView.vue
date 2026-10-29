<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { askConfirm } from '../lib/confirm.js'
import { showToast } from '../lib/toast.js'
import Icon from '../components/Icon.vue'
import ReceiptDialog from '../components/ReceiptDialog.vue'
import { locale, t } from '../lib/i18n.js'

// Billing › one membership, inside the community. Cancel, pause and resume go through
// FluentCart's own customer actions (it tells the payment provider); only a new card is
// typed on FluentCart's secure page.
const props = defineProps({ id: { type: String, required: true } })
const s = ref(null)
const error = ref('')
const busy = ref('')
const receipt = ref('') // the payment whose receipt box is open

onMounted(async () => {
  try {
    s.value = await api.billingItem(props.id)
  } catch (e) {
    error.value = e.message
  }
})

const dateFmt = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', year: 'numeric' })
const day = (iso) => (iso ? dateFmt.format(new Date(iso)) : '')
const price = computed(() => {
  if (!s.value) return ''
  const words = { month: t.__('%s a month', 'membuild-community'), year: t.__('%s a year', 'membuild-community'), week: t.__('%s a week', 'membuild-community'), day: t.__('%s a day', 'membuild-community') }[s.value.interval]
  return words ? t.sprintf(/* translators: %s: a price */ words, s.value.amount) : s.value.amount
})
const STATES = {
  active: { label: () => t.__('Active', 'membuild-community'), tone: 'ok' },
  cancelled: { label: () => t.__('Cancelled', 'membuild-community'), tone: 'grey' },
  failed: { label: () => t.__('Payment failed', 'membuild-community'), tone: 'bad' },
  paused: { label: () => t.__('Paused', 'membuild-community'), tone: 'grey' },
  ended: { label: () => t.__('Ended', 'membuild-community'), tone: 'grey' },
}
const PAY = {
  paid: { label: () => t.__('Paid', 'membuild-community'), tone: 'ok' },
  refunded: { label: () => t.__('Refunded', 'membuild-community'), tone: 'grey' },
  failed: { label: () => t.__('Failed', 'membuild-community'), tone: 'bad' },
  pending: { label: () => t.__('Waiting', 'membuild-community'), tone: 'grey' },
}
const opens = computed(() => (s.value?.courses.length ? t.sprintf(/* translators: %s: course names */ t.__('Opens %s.', 'membuild-community'), s.value.courses.join(', ')) : ''))

async function cancel() {
  const until = day(s.value.renews || s.value.pay_by)
  const course = s.value.courses[0]
  const text = until
    ? course
      ? t.sprintf(/* translators: 1: a date, 2: course name */ t.__('You keep everything until %1$s, the end of what you paid for. After that it won’t renew, and %2$s locks again. You can restart any time.', 'membuild-community'), until, course)
      : t.sprintf(/* translators: %s: a date */ t.__('You keep everything until %s, the end of what you paid for. After that it won’t renew. You can restart any time.', 'membuild-community'), until)
    : t.__('It won’t renew. You can restart any time.', 'membuild-community')
  const ok = await askConfirm({
    title: t.sprintf(/* translators: %s: membership name */ t.__('Cancel %s?', 'membuild-community'), s.value.name),
    text,
    confirm: t.__('Cancel membership', 'membuild-community'),
    cancel: t.__('Keep my membership', 'membuild-community'),
    danger: true,
  })
  if (ok) await act('cancel')
}

async function act(what) {
  if (busy.value) return
  busy.value = what
  try {
    const before = s.value
    s.value = await api.billingDo(props.id, what)
    const until = day(s.value.until || before.renews)
    showToast(
      {
        cancel: until ? t.sprintf(/* translators: 1: membership name, 2: a date */ t.__('Cancelled. You keep %1$s until %2$s.', 'membuild-community'), s.value.name, until) : t.__('Cancelled.', 'membuild-community'),
        pause: t.__('Paused. Resume it here whenever you like.', 'membuild-community'),
        resume: t.__('Welcome back. Your membership is active again.', 'membuild-community'),
      }[what],
    )
  } catch (e) {
    showToast(e.message)
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <div class="bd">
    <RouterLink :to="{ name: 'billing' }" class="back"><Icon name="chevronLeft" :size="18" /> {{ t.__('Billing', 'membuild-community') }}</RouterLink>

    <p v-if="error" class="err" role="alert">{{ error }}</p>
    <div v-else-if="!s" class="card box sk" aria-busy="true"><span /><span /><span /><span /></div>

    <template v-else>
      <div class="title">
        <h1>{{ s.name }}</h1>
        <span class="pill" :class="STATES[s.state].tone">{{ STATES[s.state].label() }}</span>
      </div>
      <p v-if="opens" class="sub">{{ opens }}</p>

      <section class="card box">
        <div class="row"><span>{{ t.__('Price', 'membuild-community') }}</span><strong>{{ price }}</strong></div>
        <div v-if="s.state === 'active' && s.renews" class="row"><span>{{ t.__('Next payment', 'membuild-community') }}</span><strong>{{ day(s.renews) }}</strong></div>
        <div v-if="s.state === 'cancelled' && s.until" class="row"><span>{{ t.__('Access until', 'membuild-community') }}</span><strong>{{ day(s.until) }}</strong></div>
        <div v-if="s.state === 'failed' && s.pay_by" class="row bad"><span>{{ t.__('Update your card by', 'membuild-community') }}</span><strong>{{ day(s.pay_by) }}</strong></div>
        <div v-if="s.card" class="row"><span>{{ t.__('Paid with', 'membuild-community') }}</span><strong>{{ s.card }}</strong></div>
        <div v-if="s.since" class="row"><span>{{ t.__('Member since', 'membuild-community') }}</span><strong>{{ day(s.since) }}</strong></div>
        <div class="actions">
          <a v-if="s.can.card || s.state === 'failed'" class="btn btn-lg dark" :href="s.card_url">{{ t.__('Update card', 'membuild-community') }} <Icon name="external" :size="16" /></a>
          <a v-if="s.restart" class="btn btn-lg btn-primary" :href="s.restart">{{ t.__('Restart membership', 'membuild-community') }}</a>
          <button v-if="s.can.resume" class="btn btn-lg btn-primary" :disabled="!!busy" @click="act('resume')">{{ busy === 'resume' ? t.__('Resuming…', 'membuild-community') : t.__('Resume membership', 'membuild-community') }}</button>
          <button v-if="s.can.pause" class="btn btn-lg btn-ghost" :disabled="!!busy" @click="act('pause')">{{ busy === 'pause' ? t.__('Pausing…', 'membuild-community') : t.__('Pause membership', 'membuild-community') }}</button>
          <button v-if="s.can.cancel" class="btn btn-lg red-line" :disabled="!!busy" @click="cancel">{{ busy === 'cancel' ? t.__('Cancelling…', 'membuild-community') : t.__('Cancel membership', 'membuild-community') }}</button>
        </div>
      </section>

      <section id="payments" class="card box">
        <h2>{{ t.__('Payments', 'membuild-community') }}</h2>
        <p v-if="!s.payments.length" class="none">{{ t.__('No payments yet.', 'membuild-community') }}</p>
        <div v-for="p in s.payments" :key="p.id" class="row pay">
          <span>{{ day(p.date) }} · {{ p.amount }} <span class="pill small" :class="PAY[p.state].tone">{{ PAY[p.state].label() }}</span></span>
          <button v-if="p.id" class="link" @click="receipt = p.id">{{ t.__('Receipt', 'membuild-community') }}</button>
        </div>
      </section>
    </template>
    <ReceiptDialog v-if="receipt" :sub="id" :payment="receipt" @close="receipt = ''" />
  </div>
</template>

<style scoped>
.bd {
  max-width: 760px;
  margin: 0 auto;
  padding: 24px 16px 40px;
}

.back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 44px;
  color: var(--text);
  font-weight: 700;
  text-decoration: none;
}

.title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin: 8px 0 4px;
}

h1 {
  margin: 0;
  font-size: 30px;
}

.sub {
  margin: 0 0 20px;
  color: var(--text-2);
  font-size: 16px;
}

.err {
  color: var(--danger);
}

.box {
  margin-bottom: 16px;
  padding: 24px 26px;
}

.box h2 {
  margin: 0 0 4px;
  font-size: 18px;
}

.row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 0;
  border-top: 1px solid var(--border);
  font-size: 15px;
}

.box > .row:first-child {
  padding-top: 0;
  border-top: 0;
}

.row > span:first-child {
  color: var(--text-2);
}

.row.pay > span:first-child {
  color: var(--text);
}

.row.bad strong {
  color: var(--danger-text);
}

.row .link {
  padding: 0;
  color: var(--text);
  font: inherit;
  font-weight: 700;
}

.row .link:hover {
  text-decoration: underline;
}

.row a {
  color: var(--text);
  font-weight: 700;
}

.pill {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
}

.pill.small {
  margin-left: 6px;
  padding: 2px 8px;
  font-size: 12px;
}

.pill.ok {
  background: var(--ok-soft, #e5f4ea);
  color: #1f7a49;
}

.pill.grey {
  background: var(--surface-2);
  color: var(--text-2);
}

.pill.bad {
  background: var(--danger-soft);
  color: var(--danger-text);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 16px;
}

.actions .btn {
  min-height: 52px;
  padding: 0 22px;
  border-radius: 999px;
  white-space: nowrap;
}

.btn.dark {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--text);
  color: var(--surface);
  text-decoration: none;
}

.btn.red-line {
  border: 1px solid var(--danger-line);
  background: var(--surface);
  color: var(--danger-text);
}

.none {
  margin: 8px 0 0;
  color: var(--text-2);
}

.sk span {
  display: block;
  height: 14px;
  margin: 12px 0;
  border-radius: 8px;
  background: var(--surface-2);
}

@media (max-width: 600px) {
  h1 {
    font-size: 26px;
  }

  .box {
    padding: 18px;
  }

  .actions .btn {
    flex: 1 1 100%;
  }
}
</style>
