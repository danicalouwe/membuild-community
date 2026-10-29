<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'
import InviteDialog from './InviteDialog.vue'

/**
 * Day one on Home, in the right column (at the top of the feed on smaller screens).
 * The owner sees "Finish setting up" (only they see it); a new member sees "Welcome, Olivia!".
 * Each row is the link to do it; done rows are ticked by the server, not by hand.
 */
const router = useRouter()
const route = useRoute()
const inviting = ref(false)
// The setup guide in wp-admin links here with ?invite=1.
watch(
  () => route.query.invite,
  (v) => {
    if (!v || !cfg.community.canManage) return
    inviting.value = true
    router.replace({ query: { ...route.query, invite: undefined } })
  },
  { immediate: true },
)

const OWNER = [
  { key: 'look', label: t.__('Name, logo and colour', 'membuild-community'), to: { name: 'settings', hash: '#s-general' } },
  { key: 'email', label: t.__('Choose how emails are sent', 'membuild-community'), to: { name: 'settings', hash: '#s-email' } },
  { key: 'key', label: t.__('Save your safety key', 'membuild-community'), to: { name: 'settings', query: { s: 'advanced' }, hash: '#s-key' } },
  { key: 'course', label: t.__('Add your first course', 'membuild-community'), to: { name: 'classroom' }, optional: true },
  { key: 'post', label: t.__('Write a welcome post', 'membuild-community'), to: { name: 'feed', query: { write: 1 } } },
  { key: 'invite', label: t.__('Invite your members', 'membuild-community'), invite: true },
]
const MEMBER = [
  { key: 'joined', label: t.__('Join the community', 'membuild-community'), hint: t.__('You’re in.', 'membuild-community') },
  { key: 'hello', label: t.__('Say hello', 'membuild-community'), hint: t.__('Tell everyone what you’re working on.', 'membuild-community'), to: { name: 'feed', query: { write: 1 } } },
  { key: 'course', label: t.__('Start a course', 'membuild-community'), hint: t.__('The first lesson takes about 10 minutes.', 'membuild-community'), to: { name: 'classroom' } },
  { key: 'photo', label: t.__('Add a photo', 'membuild-community'), hint: t.__('People reply more to a face.', 'membuild-community'), to: { name: 'me' } },
]

const owner = computed(() => cfg.firstRun?.owner)
const member = computed(() => !owner.value && cfg.firstRun?.member)
const steps = computed(() => {
  const done = owner.value || member.value || {}
  return (owner.value ? OWNER : MEMBER).filter((s) => s.key in done).map((s) => ({ ...s, done: !!done[s.key] }))
})
const count = computed(() => steps.value.filter((s) => s.done).length)
const nextStep = computed(() => steps.value.find((s) => !s.done && !s.optional) || steps.value.find((s) => !s.done))
const first = (cfg.user?.name || '').split(' ')[0]

function go(step) {
  if (step.invite) inviting.value = true
  else if (step.to) router.push(step.to)
}
async function hide() {
  cfg.firstRun = { ...cfg.firstRun, member: null }
  api.firstRunHide().catch(() => {})
}
const doneLine = computed(() =>
  owner.value
    ? t.sprintf(/* translators: 1: steps done, 2: all steps */ t.__('%1$d of %2$d done · only you can see this', 'membuild-community'), count.value, steps.value.length)
    : t.sprintf(/* translators: 1: steps done, 2: all steps */ t.__('%1$d of %2$d done · good ways to start', 'membuild-community'), count.value, steps.value.length),
)
</script>

<template>
  <section v-if="owner || member" class="day-one card" :aria-label="owner ? t.__('Finish setting up', 'membuild-community') : t.__('Welcome', 'membuild-community')">
    <header>
      <h2>{{ owner ? t.__('Finish setting up', 'membuild-community') : t.sprintf(t.__('Welcome, %s!', 'membuild-community'), first) }}</h2>
      <button v-if="member" type="button" class="hide" @click="hide">{{ t.__('Hide', 'membuild-community') }}</button>
    </header>
    <p class="sub">{{ doneLine }}</p>
    <div class="bar" role="progressbar" :aria-valuenow="count" aria-valuemin="0" :aria-valuemax="steps.length"><i :style="{ width: `${(count / steps.length) * 100}%` }" /></div>
    <ul>
      <li v-for="s in steps" :key="s.key">
        <button type="button" class="row" :class="{ done: s.done, next: !s.done && s === nextStep, still: !s.to && !s.invite }" :disabled="!s.to && !s.invite" @click="go(s)">
          <span class="dot"><Icon v-if="s.done" name="check" :size="14" /></span>
          <span class="words"><strong>{{ s.label }}</strong><small v-if="s.hint">{{ s.hint }}</small></span>
          <Icon v-if="s.to || s.invite" name="chevronRight" :size="18" class="chev" />
        </button>
      </li>
    </ul>
    <button v-if="owner && nextStep" type="button" class="go" @click="go(nextStep)">{{ t.__('Continue', 'membuild-community') }}</button>
    <InviteDialog v-if="inviting" @close="inviting = false" />
  </section>
</template>

<style scoped>
.day-one {
  padding: 18px 20px 20px;
}

header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
}

h2 {
  margin: 0;
  font-size: var(--fs-2xl);
}

.hide {
  min-height: 32px;
  color: var(--text-2);
  font-size: var(--fs-base);
  font-weight: 700;
}

.sub {
  margin: 4px 0 12px;
  color: var(--text-2);
  font-size: var(--fs-md);
}

.bar {
  height: 8px;
  margin-bottom: 8px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}

.bar i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--ok-strong);
  transition: width 0.4s ease;
}

ul {
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 48px;
  padding: 8px 0;
  color: var(--text);
  text-align: left;
}

.row:not(.still):hover strong {
  text-decoration: underline;
}

.row.still {
  cursor: default;
}

.dot {
  display: grid;
  flex: none;
  place-items: center;
  width: 24px;
  height: 24px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
  color: var(--on-color);
}

.row.next .dot {
  border-color: var(--text);
}

.row.done .dot {
  border-color: var(--ok-strong);
  background: var(--ok-strong);
}

.words {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.words strong {
  font-size: var(--fs-base);
  font-weight: 500;
}

.row.next strong {
  font-weight: 700;
}

.row.done strong {
  color: var(--text-2);
  text-decoration: line-through;
}

.words small {
  color: var(--text-2);
  font-size: var(--fs-md);
}

.chev {
  flex: none;
  color: var(--text-2);
}

.go {
  width: 100%;
  min-height: 52px;
  margin-top: 12px;
  border-radius: var(--radius-pill);
  color: var(--accent-ink);
  background: var(--accent);
  font-size: var(--fs-xl);
  font-weight: 700;
}

/* Member rows carry a second line, so they're a little taller. */
.row:has(small) strong {
  font-size: var(--fs-lg);
  font-weight: 700;
}
</style>
