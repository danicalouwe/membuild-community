<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import BaseDialog from './BaseDialog.vue'
import DockWindow from './DockWindow.vue'
import { useLatest } from '../lib/useLatest.js'
import { t } from '../lib/i18n.js'

// Pick one person for a direct chat, or several for a group (with an optional name).
// `docked`: a chat window in the bottom-right dock (computers, like Facebook) instead of
// a box in the middle of the screen.
defineProps({ docked: { type: Boolean, default: false } })
const emit = defineEmits(['close', 'started'])

const query = ref('')
const results = ref([])
const picked = ref([])
const title = ref('')
const starting = ref(false)
const error = ref('')
const input = ref(null)
let searchTimer = null

const isGroup = computed(() => picked.value.length > 1)

const latest = useLatest()
async function search() {
  const current = latest() // a slow, older search never replaces a newer one
  const res = await api.people(query.value.trim()).catch(() => ({ items: [] }))
  if (current()) results.value = res.items
}
watch(query, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(search, 200)
})

function toggle(person) {
  const i = picked.value.findIndex((p) => p.id === person.id)
  if (i === -1) picked.value.push(person)
  else picked.value.splice(i, 1)
  query.value = ''
  nextTick(() => input.value?.focus())
}

async function start() {
  if (!picked.value.length || starting.value) return
  starting.value = true
  error.value = ''
  try {
    const chat = await api.startChat(picked.value.map((p) => p.id), isGroup.value ? title.value.trim() : undefined)
    emit('started', chat)
  } catch (err) {
    error.value = err.message
    starting.value = false
  }
}

onMounted(() => {
  search()
  nextTick(() => input.value?.focus())
})
const removeLabel = (name) => t.sprintf(/* translators: %s: member's name */ t.__('Remove %s', 'membuild-community'), name)
const startGroup = (n) => t.sprintf(/* translators: %d: number of people in the new group chat, including you */ t.__('Start group (%d)', 'membuild-community'), n)
</script>

<template>
  <component :is="docked ? DockWindow : BaseDialog" :title="t.__('New message', 'membuild-community')" v-bind="docked ? {} : { width: 480, maxHeight: '640px', phone: 'full' }" @close="emit('close')">
    <div class="to">
      <span class="label">{{ t.__('To:', 'membuild-community') }}</span>
      <span v-for="p in picked" :key="p.id" class="chip">
        {{ p.name }}
        <button :aria-label="removeLabel(p.name)" @click="toggle(p)"><Icon name="close" :size="12" /></button>
      </span>
      <input ref="input" v-model="query" :placeholder="t.__('Search members', 'membuild-community')" :aria-label="t.__('Search members', 'membuild-community')" @keydown.enter.prevent="results[0] && toggle(results[0])" />
    </div>

    <input v-if="isGroup" v-model="title" class="title" maxlength="100" :placeholder="t.__('Group name (optional)', 'membuild-community')" :aria-label="t.__('Group name', 'membuild-community')" />

    <ul class="people">
      <li v-for="p in results" :key="p.id">
        <button :class="{ on: picked.some((x) => x.id === p.id) }" @click="toggle(p)">
          <Avatar :user="p" :size="40" badge />
          <span>{{ p.name }}</span>
          <span class="check" aria-hidden="true">✓</span>
    </button>
  </li>
  <li v-if="!results.length" class="empty">{{ t.__('No members found.', 'membuild-community') }}</li>
    </ul>

    <p v-if="error" class="error-text">{{ error }}</p>
    <template #footer>
      <button class="btn btn-primary start" :disabled="!picked.length || starting" @click="start">
        {{ starting ? t.__('Opening…', 'membuild-community') : isGroup ? startGroup(picked.length + 1) : t.__('Chat', 'membuild-community') }}
      </button>
    </template>
  </component>
</template>

<style scoped>
.to {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
}

.label {
  color: var(--text-2);
  font-weight: 650;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px 4px 10px;
  border-radius: 999px;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-weight: 650;
  font-size: 13px;
}

.chip button {
  display: grid;
  color: inherit;
}

.to input {
  flex: 1;
  min-width: 140px;
  border: 0;
  background: transparent;
  height: 32px;
}

.to input:focus {
  outline: none;
}

/* The field has no box of its own, so the row shows where focus is. */
.to:focus-within {
  box-shadow: inset 0 -2px 0 var(--accent);
}

.title {
  margin: 10px 14px 0;
  height: 38px;
  padding: 0 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface-2);
}

.people {
  list-style: none;
  margin: 0;
  padding: 8px;
}

.people button {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 8px;
  border-radius: 10px;
  text-align: left;
  font-weight: 600;
}

.people button:hover {
  background: var(--surface-2);
}

.people button span:nth-child(2) {
  flex: 1;
}

.check {
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid var(--border);
  color: transparent;
  font-size: 12px;
}

.on .check {
  background: var(--accent);
  border-color: var(--accent-text);
  color: var(--accent-ink);
}

.empty {
  padding: 16px;
  text-align: center;
  color: var(--text-3);
}

.error-text {
  margin: 0 14px;
  font-size: 13px;
}

.start {
  width: 100%;
  height: 42px;
}
</style>
