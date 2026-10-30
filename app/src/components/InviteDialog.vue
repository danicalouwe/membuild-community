<script setup>
import { computed, ref } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { showToast } from '../lib/toast.js'
import { t } from '../lib/i18n.js'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'

// Invite your members: the join link to copy, a ready message to paste, and who can join.
// No sending from here on purpose: emails from a new site often land in spam.
const emit = defineEmits(['close'])

const link = computed(() => cfg.firstRun?.joinUrl || `${location.origin}${cfg.basePath}/login`)
const message = ref(
  t.sprintf(
    /* translators: 1: community name, 2: the join link */
    t.__('Hi! I’ve opened a community for us at %1$s. It’s where we’ll share questions, wins and the courses. Join here: %2$s. See you inside!', 'membuild-community'),
    cfg.siteName,
    link.value,
  ),
)
const open = computed(() => !!cfg.firstRun?.joinUrl && cfg.firstRun.joinUrl.includes('/join'))

async function copy(text, what) {
  try {
    await navigator.clipboard.writeText(text)
    showToast(what === 'link' ? t.__('Link copied.', 'membuild-community') : t.__('Message copied.', 'membuild-community'))
  } catch {
    showToast(t.__('Couldn’t copy. Select it and copy it yourself.', 'membuild-community'))
  }
  api
    .firstRunInvited()
    .then((c) => (cfg.firstRun = c))
    .catch(() => {})
}
</script>

<template>
  <BaseDialog :label="t.__('Invite your members', 'membuild-community')" :width="620" phone="sheet" @close="emit('close')">
    <template #header><h2 class="title">{{ t.__('Invite your members', 'membuild-community') }}</h2></template>
    <div class="body">
      <p class="text">{{ t.__('Share this link in your email, newsletter or anywhere your members are. Anyone with it can join.', 'membuild-community') }}</p>
      <span class="label">{{ t.__('Your join link', 'membuild-community') }}</span>
      <div class="row">
        <input :value="link" readonly :aria-label="t.__('Your join link', 'membuild-community')" @focus="$event.target.select()" />
        <button type="button" class="btn-main" @click="copy(link, 'link')"><Icon name="copy" :size="18" /> {{ t.__('Copy link', 'membuild-community') }}</button>
      </div>
      <span class="label">{{ t.__('A message you can paste', 'membuild-community') }} <em>{{ t.__('(change it if you like)', 'membuild-community') }}</em></span>
      <textarea v-model="message" v-auto-grow rows="3" :aria-label="t.__('A message you can paste', 'membuild-community')" />
      <button type="button" class="btn-grey left" @click="copy(message, 'message')"><Icon name="copy" :size="18" /> {{ t.__('Copy message', 'membuild-community') }}</button>
      <p class="note">
        <Icon name="info" :size="18" />
        <span>
          {{ t.__('People who already have an account on your website can log in with it.', 'membuild-community') }}
          {{ open ? t.__('Anyone can join with this link.', 'membuild-community') : t.__('New people join through your own sign-up page.', 'membuild-community') }}
          <RouterLink :to="{ name: 'settings', hash: '#s-joining' }" @click="emit('close')">{{ t.__('Change who can join', 'membuild-community') }}</RouterLink>
        </span>
      </p>
      <div class="act"><button type="button" class="btn-grey" @click="emit('close')">{{ t.__('Done', 'membuild-community') }}</button></div>
    </div>
  </BaseDialog>
</template>

<style scoped>
.title {
  margin: 0;
  padding: 28px 30px 0;
  font-size: var(--fs-5xl);
}

.body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px 30px 28px;
}

.text {
  margin: 0 0 8px;
  color: var(--text-2);
  font-size: var(--fs-lg);
  line-height: 1.5;
}

.label {
  margin-top: 6px;
  font-size: var(--fs-lg);
  font-weight: 700;
}

.label em {
  color: var(--text-2);
  font-style: normal;
  font-weight: 400;
}

.row {
  display: flex;
  gap: 10px;
}

.row input,
textarea {
  flex: 1;
  min-width: 0;
  min-height: 52px;
  padding: 0 16px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  color: var(--text);
  background: var(--surface);
  font: inherit;
  font-size: var(--fs-lg);
}

textarea {
  padding: 13px 16px;
  line-height: 1.5;
  resize: none;
}

.btn-main,
.btn-grey {
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 52px;
  padding: 0 24px;
  border-radius: var(--radius-pill);
  font-size: var(--fs-xl);
  font-weight: 700;
}

.btn-main {
  color: var(--accent-ink);
  background: var(--accent);
}

.btn-grey {
  color: var(--text);
  background: var(--surface-2);
}

.btn-grey:hover {
  background: var(--border);
}

.left {
  align-self: flex-start;
}

.note {
  display: flex;
  gap: 10px;
  margin: 10px 0 0;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  color: var(--text-2);
  background: var(--surface-2);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.note :deep(svg) {
  flex: none;
  margin-top: 2px;
}

.note a {
  color: var(--text);
  font-weight: 700;
}

.act {
  display: flex;
  justify-content: flex-end;
  margin-top: 6px;
}

@media (max-width: 767px) {
  .title {
    padding: 22px 20px 0;
    font-size: var(--fs-4xl);
  }

  .body {
    padding: 10px 20px 20px; /* the sheet itself keeps clear of the home bar */
  }

  .row {
    flex-direction: column;
  }
}
</style>
