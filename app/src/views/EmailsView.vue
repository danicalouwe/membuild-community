<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { feature } from '../config.js'
import Icon from '../components/Icon.vue'
import { t } from '../lib/i18n.js'

// Emails you get: its own page (from the profile menu, Edit profile and every email's
// footer). How often as three choices, then what to email about. Saves by itself.
const prefs = ref(null)
const status = ref('') // '' | 'saving' | 'saved' | 'error'
const error = ref('')

onMounted(async () => {
  try {
    prefs.value = await api.emailPrefs()
  } catch (e) {
    error.value = e.message
  }
})

async function save(patch) {
  Object.assign(prefs.value, patch)
  status.value = 'saving'
  try {
    const { frequency, messages, activity, events } = prefs.value
    prefs.value = await api.saveEmailPrefs({ frequency, messages, activity, events })
    status.value = 'saved'
    error.value = ''
  } catch (e) {
    status.value = 'error'
    error.value = e.message
  }
}

const OFTEN = [
  { key: 'instant', label: t.__('As it happens', 'membuild-community'), hint: t.__('An email soon after something happens. Never while you’re using the community.', 'membuild-community') },
  { key: 'daily', label: t.__('Once a day', 'membuild-community'), hint: t.__('One short summary each day, only on days something happened.', 'membuild-community') },
  { key: 'off', label: t.__('No emails', 'membuild-community'), hint: t.__('You’ll still see everything under the bell in the community.', 'membuild-community') },
]
const ABOUT = [
  { key: 'messages', label: t.__('Messages', 'membuild-community'), hint: t.__('When someone sends you a private message.', 'membuild-community'), feature: 'messages' },
  { key: 'activity', label: t.__('Comments and replies', 'membuild-community'), hint: t.__('When someone comments on your post or replies to your comment.', 'membuild-community') },
  { key: 'events', label: t.__('Event reminders', 'membuild-community'), hint: t.__('A reminder the day before and an hour before events you said you’re going to.', 'membuild-community'), feature: 'events' },
].filter((a) => !a.feature || feature(a.feature))
</script>

<template>
  <div class="emails">
    <RouterLink :to="{ name: 'profile-edit' }" class="back"><Icon name="chevronLeft" :size="18" /> {{ t.__('Edit profile', 'membuild-community') }}</RouterLink>
    <div class="head">
      <h1>{{ t.__('Emails you get', 'membuild-community') }}</h1>
      <span v-if="status === 'saved'" class="pill ok"><Icon name="check" :size="16" /> {{ t.__('Saved', 'membuild-community') }}</span>
      <span v-else-if="status === 'saving'" class="pill">{{ t.__('Saving…', 'membuild-community') }}</span>
    </div>
    <p v-if="prefs" class="intro">{{ t.sprintf(/* translators: %s: email address */ t.__('Sent to %s. Changes save by themselves.', 'membuild-community'), prefs.email) }}</p>
    <p v-if="error" class="err" role="alert">{{ error }}</p>

    <template v-if="prefs">
      <p v-if="!prefs.enabled" class="note warn"><Icon name="bell" :size="18" /> {{ t.__('This community doesn’t send emails right now. Your choices are kept for when it does.', 'membuild-community') }}</p>

      <section class="card box">
        <h2>{{ t.__('How often', 'membuild-community') }}</h2>
        <label v-for="o in OFTEN" :key="o.key" class="choice" :class="{ on: prefs.frequency === o.key }">
          <input type="radio" name="often" :value="o.key" :checked="prefs.frequency === o.key" @change="save({ frequency: o.key })" />
          <span><strong>{{ o.label }}</strong><small>{{ o.hint }}</small></span>
        </label>
      </section>

      <section class="card box">
        <h2>{{ t.__('What to email you about', 'membuild-community') }}</h2>
        <label v-for="a in ABOUT" :key="a.key" class="toggle" :class="{ dim: prefs.frequency === 'off' }">
          <span><strong>{{ a.label }}</strong><small>{{ a.hint }}</small></span>
          <input type="checkbox" class="switch" role="switch" :checked="prefs.frequency !== 'off' && prefs[a.key]" :disabled="prefs.frequency === 'off'" @change="save({ [a.key]: $event.target.checked })" />
        </label>
        <p v-if="prefs.frequency === 'off'" class="note warn"><Icon name="bell" :size="18" /> {{ t.__('Emails are off. Pick “As it happens” or “Once a day” to choose these again.', 'membuild-community') }}</p>
      </section>

      <p class="note"><Icon name="info" :size="18" /> {{ t.__('Password resets and sign-in emails always arrive, even with emails off. Every email also has a link at the bottom to change this.', 'membuild-community') }}</p>
    </template>
  </div>
</template>

<style scoped>
.emails {
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

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

h1 {
  margin: 8px 0 4px;
  font-size: 30px;
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 999px;
  background: var(--surface-2);
  font-weight: 700;
}

.pill.ok {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.intro {
  margin: 0 0 22px;
  color: var(--text-2);
  font-size: 17px;
}

.err {
  color: var(--danger-text);
}

.box {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 20px;
  padding: 28px 30px;
  border-radius: 16px;
}

h2 {
  margin: 0 0 8px;
  font-size: 20px;
}

.choice {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px 18px;
  border: 1px solid var(--border);
  border-radius: 12px;
  cursor: pointer;
}

.choice.on {
  border: 2px solid var(--accent);
  background: var(--accent-soft);
}

.choice input {
  width: 22px;
  height: 22px;
  margin: 2px 0 0;
  accent-color: var(--text);
}

.choice span,
.toggle span {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.choice strong,
.toggle strong {
  font-size: 17px;
}

.choice small,
.toggle small {
  color: var(--text-2);
  font-size: 15px;
}

.toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 14px 0;
  border-top: 1px solid var(--border);
  cursor: pointer;
}

.toggle:first-of-type {
  border-top: 0;
}

.toggle.dim {
  opacity: 0.5;
  cursor: default;
}

.switch {
  appearance: none;
  flex: none;
  position: relative;
  width: 52px;
  height: 30px;
  margin: 0;
  border-radius: 999px;
  background: var(--border-strong);
  cursor: pointer;
  transition: background 0.15s;
}

.switch::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgb(0 0 0 / 25%);
  transition: transform 0.15s;
}

.switch:checked {
  background: var(--accent);
}

.switch:checked::after {
  transform: translateX(22px);
}

.switch:disabled {
  cursor: default;
}

.note {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin: 0;
  padding: 14px 18px;
  border-radius: 12px;
  background: var(--surface-2);
  color: var(--text-2);
  line-height: 1.5;
}

.note :deep(svg) {
  flex: none;
  margin-top: 2px;
}

.note.warn {
  margin: 6px 0 0;
  background: var(--warn-soft);
  color: var(--warn-text);
}

.emails > .note.warn {
  margin-bottom: 20px;
}

@media (max-width: 600px) {
  .box {
    padding: 22px 18px;
  }
}
</style>
