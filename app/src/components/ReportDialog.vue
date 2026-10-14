<script setup>
import { ref } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import BaseDialog from './BaseDialog.vue'
import { t } from '../lib/i18n.js'

// "Report post" / "Report comment" / "Report profile": pick a reason, add a note if you like.
// Only admins and moderators see reports; the author is never told who reported.
const props = defineProps({
  type: { type: String, required: true }, // post | comment | member
  id: { type: Number, required: true },
})
const emit = defineEmits(['close', 'reported'])

const reasons = cfg.community.reportReasons || { spam: t.__('Spam', 'membuild-community'), other: t.__('Something else', 'membuild-community') }

// Whole sentences per type, so each language can word them its own way.
const TITLES = {
  post: t.__('Report post', 'membuild-community'),
  comment: t.__('Report comment', 'membuild-community'),
  member: t.__('Report profile', 'membuild-community'),
}
const INTROS = {
  post: t.__('Why are you reporting this post? Only admins and moderators will see your report.', 'membuild-community'),
  comment: t.__('Why are you reporting this comment? Only admins and moderators will see your report.', 'membuild-community'),
  member: t.__('Why are you reporting this person? Only admins and moderators will see your report.', 'membuild-community'),
}
const title = () => TITLES[props.type] || TITLES.post
const intro = () => INTROS[props.type] || INTROS.post
const reason = ref('')
const note = ref('')
const sending = ref(false)
const done = ref(null) // { hidden }
const error = ref('')

async function send() {
  if (!reason.value || sending.value) return
  sending.value = true
  error.value = ''
  try {
    done.value = await api.report({ object_type: props.type, object_id: props.id, reason: reason.value, note: note.value.trim() || undefined })
    emit('reported', done.value)
  } catch (err) {
    error.value = err.message
  } finally {
    sending.value = false
  }
}

</script>

<template>
  <BaseDialog :title="title()" :width="480" @close="emit('close')">
    <div v-if="done" class="body thanks">
      <span class="ok">✓</span>
      <h3>{{ t.__('Thanks for letting us know', 'membuild-community') }}</h3>
      <p>{{ t.__('An admin or moderator will take a look.', 'membuild-community') }} {{ done.hidden ? t.__('This post is hidden until they do.', 'membuild-community') : '' }}</p>
      <button class="btn btn-primary" @click="emit('close')">{{ t.__('Done', 'membuild-community') }}</button>
    </div>

    <form v-else class="body" @submit.prevent="send">
      <p class="intro">{{ intro() }}</p>
      <div class="reasons" role="radiogroup" :aria-label="t.__('Reason', 'membuild-community')">
        <label v-for="(label, key) in reasons" :key="key" class="reason" :class="{ on: reason === key }">
          <input v-model="reason" type="radio" name="reason" :value="key" />
          {{ label }}
        </label>
      </div>
      <textarea v-if="reason" v-model="note" rows="2" maxlength="500" :placeholder="t.__('Anything else they should know? (optional)', 'membuild-community')" :aria-label="t.__('Note', 'membuild-community')" />
      <p v-if="error" class="err">{{ error }}</p>
      <footer class="foot">
        <button type="button" class="btn" @click="emit('close')">{{ t.__('Cancel', 'membuild-community') }}</button>
        <button type="submit" class="btn btn-primary" :disabled="!reason || sending">{{ sending ? t.__('Sending…', 'membuild-community') : t.__('Report', 'membuild-community') }}</button>
      </footer>
    </form>
  </BaseDialog>
</template>

<style scoped>
.body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px 18px 18px;
}

.intro {
  margin: 0;
  color: var(--text-2);
}

.reasons {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.reason {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
}

.reason:hover,
.reason.on {
  background: var(--surface-2);
}

textarea {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
  resize: vertical;
}

.err {
  margin: 0;
  color: var(--danger);
}

.foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.btn {
  padding: 9px 16px;
  border-radius: 10px;
  font-weight: 650;
}

.btn:not(.btn-primary) {
  background: var(--surface-2);
}

.thanks {
  align-items: center;
  text-align: center;
}

.thanks h3 {
  margin: 4px 0 0;
}

.thanks p {
  margin: 0 0 6px;
  color: var(--text-2);
}

.ok {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  background: #1fa463;
  color: #fff;
  font-size: 26px;
  font-weight: 800;
}
</style>
