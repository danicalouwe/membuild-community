<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import Icon from '../components/Icon.vue'
import ThemeSlot from '../components/ThemeSlot.vue'
import { locale, t } from '../lib/i18n.js'

// Billing: the memberships a member pays for with FluentCart. Changing the card, cancelling
// and pausing happen on FluentCart's own page for that subscription (it knows each payment
// provider), so this page shows where each one stands and links there.
const items = ref([])
const receipts = ref('')
const loaded = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    const res = await api.billing()
    items.value = res.items
    receipts.value = res.receipts || ''
  } catch (e) {
    error.value = e.message
  }
  loaded.value = true
})

const dateFmt = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', year: 'numeric' })
const day = (iso) => (iso ? dateFmt.format(new Date(iso)) : '')
const price = (s) =>
  ({
    month: t.sprintf(/* translators: %s: a price */ t.__('%s a month', 'membuild-community'), s.amount),
    year: t.sprintf(/* translators: %s: a price */ t.__('%s a year', 'membuild-community'), s.amount),
    week: t.sprintf(/* translators: %s: a price */ t.__('%s a week', 'membuild-community'), s.amount),
    day: t.sprintf(/* translators: %s: a price */ t.__('%s a day', 'membuild-community'), s.amount),
  })[s.interval] || s.amount
const STATES = {
  active: { label: () => t.__('Active', 'membuild-community'), tone: 'ok' },
  cancelled: { label: () => t.__('Cancelled', 'membuild-community'), tone: 'grey' },
  failed: { label: () => t.__('Payment failed', 'membuild-community'), tone: 'bad' },
  paused: { label: () => t.__('Paused', 'membuild-community'), tone: 'grey' },
  ended: { label: () => t.__('Ended', 'membuild-community'), tone: 'grey' },
}
// "Renews on Nov 29, 2026", "You keep access until Mar 3, 2027", … split around the date so it can be bold.
function line(s) {
  const text = {
    active: s.renews && t.__('Renews on %s.', 'membuild-community'),
    cancelled: s.until ? t.__('You keep access until %s. It won’t renew.', 'membuild-community') : t.__('It won’t renew.', 'membuild-community'),
    failed: s.pay_by ? t.__('The last payment didn’t go through. Update your card by %s to keep access.', 'membuild-community') : t.__('The last payment didn’t go through. Update your card to keep access.', 'membuild-community'),
    paused: t.__('Paused for now. You can resume it from Manage billing.', 'membuild-community'),
    ended: t.__('This membership has ended.', 'membuild-community'),
  }[s.state]
  if (!text) return null
  const [before, after = ''] = text.split('%s')
  return { before, date: text.includes('%s') ? day(s.renews || s.until || s.pay_by) : '', after }
}
const opens = (s) => (s.courses.length ? t.sprintf(/* translators: %s: course names */ t.__('Opens %s.', 'membuild-community'), s.courses.join(', ')) : '')
const paidWith = (s) => (s.card ? t.sprintf(/* translators: %s: a card or payment method, e.g. "Visa ending 4242" */ t.__('Paid with %s', 'membuild-community'), s.card) : '')
</script>

<template>
  <div class="billing">
    <RouterLink :to="{ name: 'me' }" class="back-link"><Icon name="chevronLeft" :size="18" /> {{ t.__('Your profile', 'membuild-community') }}</RouterLink>
    <h1 class="page-title">{{ t.__('Billing', 'membuild-community') }}</h1>
    <p v-if="items.length" class="page-intro">{{ t.__('The memberships you pay for. Manage billing shows the details, your payments, and where to change your card, pause or cancel.', 'membuild-community') }}</p>

    <p v-if="error" class="error-text" role="alert">{{ error }}</p>

    <div v-if="!loaded" class="card sub sk" aria-busy="true"><span /><span /><span /></div>

    <section v-for="s in items" :key="s.id" class="card sub">
      <div class="top">
        <div>
          <h2>{{ s.name }}</h2>
          <p class="price">{{ price(s) }}</p>
        </div>
        <span class="status-tag" :class="STATES[s.state].tone">{{ STATES[s.state].label() }}</span>
      </div>
      <p v-if="line(s)" class="line">
        {{ line(s).before }}<strong v-if="line(s).date">{{ line(s).date }}</strong>{{ line(s).after }}
        <template v-if="s.state === 'active' && opens(s)">{{ ' ' + opens(s) }}</template>
      </p>
      <p v-if="paidWith(s)" class="how">{{ paidWith(s) }}</p>
      <div class="actions">
        <RouterLink v-if="s.state === 'failed'" class="btn btn-lg dark" :to="{ name: 'billing-item', params: { id: s.id } }">{{ t.__('Update your card', 'membuild-community') }}</RouterLink>
        <a v-else-if="s.restart" class="btn btn-lg btn-primary" :href="s.restart">{{ t.__('Restart membership', 'membuild-community') }}</a>
        <RouterLink v-else class="btn btn-lg dark" :to="{ name: 'billing-item', params: { id: s.id } }">{{ t.__('Manage billing', 'membuild-community') }}</RouterLink>
        <RouterLink v-if="s.state !== 'failed'" class="btn btn-lg btn-ghost" :to="{ name: 'billing-item', params: { id: s.id }, hash: '#payments' }">{{ t.__('Receipts', 'membuild-community') }}</RouterLink>
      </div>
    </section>

    <ThemeSlot v-if="loaded && !items.length && !error" name="app.empty-state" :data="{ page: 'billing', icon: 'card', title: t.__('Nothing to pay for', 'membuild-community'), text: t.__('When you join a paid membership, it shows here with its renewal date and receipts.', 'membuild-community') }">
    <section class="card empty-state">
      <span class="empty-state-icon" aria-hidden="true"><Icon name="card" :size="28" /></span>
      <h2 class="empty-state-title">{{ t.__('Nothing to pay for', 'membuild-community') }}</h2>
      <p class="empty-state-text">{{ t.__('When you join a paid membership, it shows here with its renewal date and receipts.', 'membuild-community') }}</p>
      <RouterLink class="btn btn-lg btn-ghost" :to="{ name: 'classroom' }">{{ t.__('Browse the Classroom', 'membuild-community') }}</RouterLink>
    </section>
    </ThemeSlot>
  </div>
</template>

<style scoped>
.billing {
  max-width: 760px;
  margin: 0 auto;
  padding: 24px 16px 40px;
}

.sub {
  margin-bottom: 16px;
  padding: 22px 24px;
}

.top {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.sub h2 {
  margin: 0;
  font-size: var(--fs-3xl);
}

.price {
  margin: 2px 0 0;
  color: var(--text-2);
  font-size: var(--fs-base);
}

.line {
  margin: 14px 0 0;
  font-size: var(--fs-base);
  line-height: 1.5;
}

.how {
  margin: 4px 0 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
}

.actions .btn {
  min-height: 52px;
  padding: 0 22px;
  border-radius: var(--radius-pill);
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

.sk span {
  display: block;
  height: 14px;
  margin: 10px 0;
  border-radius: var(--radius-xs);
  background: var(--surface-2);
}

.sk span:first-child {
  width: 40%;
  height: 20px;
}

@media (max-width: 600px) {
  .sub {
    padding: 18px;
  }

  .actions .btn {
    flex: 1;
    padding: 0 14px;
    font-size: var(--fs-base);
  }
}
</style>
