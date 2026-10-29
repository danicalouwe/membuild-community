<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'
import { locale, t } from '../lib/i18n.js'
import { saveReceiptPdf } from '../lib/receiptPdf.js'

// One payment's receipt in a box on Billing › membership, so members never leave the page.
// "Save as PDF" downloads it as a real PDF file (jsPDF, loaded only then).
const props = defineProps({ sub: { type: String, required: true }, payment: { type: String, required: true } })
const emit = defineEmits(['close'])
const r = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    r.value = await api.billingReceipt(props.sub, props.payment)
  } catch (e) {
    error.value = e.message
  }
})

const dateFmt = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', year: 'numeric' })
const when = computed(() => (r.value ? dateFmt.format(new Date(r.value.date)) : ''))
const every = computed(() => {
  if (!r.value) return ''
  const words = { month: t.__('%s a month', 'membuild-community'), year: t.__('%s a year', 'membuild-community'), week: t.__('%s a week', 'membuild-community'), day: t.__('%s a day', 'membuild-community') }[r.value.interval]
  return words ? t.sprintf(/* translators: %s: a price */ words, r.value.price) : ''
})
const STATE = { paid: () => t.__('Paid', 'membuild-community'), refunded: () => t.__('Refunded', 'membuild-community'), failed: () => t.__('Failed', 'membuild-community'), pending: () => t.__('Waiting', 'membuild-community') }
const logo = cfg.community.logo || ''
const initials = (cfg.community.name || 'MB').split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase()

// Saves the receipt as a real PDF file (no print window).
const saving = ref(false)
async function save() {
  if (!r.value || saving.value) return
  saving.value = true
  try {
    await saveReceiptPdf(r.value, { when: when.value, every: every.value, logo, accent: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim(), stateLabel: STATE[r.value.state]() })
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseDialog :label="t.__('Receipt', 'membuild-community')" :width="560" phone="sheet" @close="emit('close')">
    <template #header="{ close }">
      <header class="brand-bar">
        <span class="logo"><img v-if="logo" :src="logo" alt="" /><template v-else>{{ initials }}</template></span>
        <strong>{{ r?.store || cfg.community.name }}</strong>
        <button class="round" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="20" /></button>
      </header>
    </template>

    <p v-if="error" class="err" role="alert">{{ error }}</p>
    <div v-else-if="!r" class="sk" aria-busy="true"><span /><span /><span /></div>

    <div v-else class="paper">
      <div class="head">
        <h2>{{ t.__('Receipt', 'membuild-community') }}</h2>
        <span class="pill" :class="r.state">{{ STATE[r.state]() }}</span>
      </div>
      <p class="muted when">{{ r.number }} · {{ when }}</p>
      <div class="lbl">{{ t.__('Billed to', 'membuild-community') }}</div>
      <div class="to">{{ r.name }} · {{ r.email }}</div>
      <div v-for="(l, i) in r.lines" :key="i" class="row first">
        <span>{{ l.title }}<br v-if="every || l.detail" /><span v-if="every || l.detail" class="muted small">{{ l.detail || every }}</span></span>
        <strong>{{ l.amount }}</strong>
      </div>
      <div class="row"><span class="muted">{{ t.__('Subtotal', 'membuild-community') }}</span><span>{{ r.subtotal }}</span></div>
      <div v-if="r.discount" class="row"><span class="muted">{{ t.__('Discount', 'membuild-community') }}</span><span>−{{ r.discount }}</span></div>
      <div v-if="r.tax" class="row"><span class="muted">{{ t.__('Tax', 'membuild-community') }}</span><span>{{ r.tax }}</span></div>
      <div class="row total"><strong>{{ r.state === 'refunded' ? t.__('Refunded', 'membuild-community') : t.__('Total paid', 'membuild-community') }}</strong><strong>{{ r.total }}</strong></div>
      <div class="row"><span class="muted">{{ t.__('Paid with', 'membuild-community') }}</span><span>{{ r.paid }}</span></div>
    </div>

    <template #footer>
      <div class="foot-btns">
        <button class="btn btn-lg btn-primary save" :disabled="!r || saving" @click="save"><Icon name="download" :size="18" /> {{ saving ? t.__('Saving…', 'membuild-community') : t.__('Save as PDF', 'membuild-community') }}</button>
        <button class="btn btn-lg btn-ghost" @click="emit('close')">{{ t.__('Close', 'membuild-community') }}</button>
      </div>
    </template>
  </BaseDialog>
</template>

<style scoped>
.brand-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 22px;
  border-bottom: 1px solid var(--border);
}

.brand-bar strong {
  font-size: 16px;
}

.brand-bar .round {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  margin-left: auto;
  border-radius: 50%;
  background: var(--surface-2);
}

.logo {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  overflow: hidden;
  border-radius: 9px;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: 13px;
  font-weight: 800;
}

.logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.paper {
  padding: 6px 22px 4px;
}

.head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.head h2 {
  margin: 0;
  font-size: 24px;
}

.when {
  margin: 4px 0 18px;
  font-size: 15px;
}

.muted {
  color: var(--text-2);
}

.small {
  font-size: 14px;
}

.lbl {
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--text-2);
}

.to {
  margin: 4px 0 18px;
  font-size: 15px;
  overflow-wrap: anywhere;
}

.row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
  font-size: 15px;
}

.total strong:last-child {
  font-size: 18px;
}

.pill {
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
  font-size: 13px;
  font-weight: 700;
}

.pill.paid {
  background: var(--ok-soft, #e5f4ea);
  color: #1f7a49;
}

.pill.failed {
  background: var(--danger-soft);
  color: var(--danger-text);
}

.foot-btns {
  padding: 0 4px;
  display: flex;
  gap: 10px;
  width: 100%;
}

.foot-btns .btn {
  min-height: 52px;
  border-radius: 999px;
}

.foot-btns .save {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.err {
  padding: 0 22px;
  color: var(--danger);
}

.sk {
  padding: 0 22px;
}

.sk span {
  display: block;
  height: 14px;
  margin: 12px 0;
  border-radius: 8px;
  background: var(--surface-2);
}
</style>
