<script setup>
import { computed, nextTick, ref } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * The Poll box in "Create post". `poll` is { options: string[], closes } and belongs to the
 * dialog, so it comes back filled in if posting fails. Same two-choice rule on the server.
 */
const props = defineProps({
  poll: { type: Object, required: true },
  sameChoices: { type: Boolean, default: false },
})
const emit = defineEmits(['remove'])

const MAX = 6
const CLOSES = computed(() => [
  { key: '1d', label: t.__('In 1 day', 'membuild-community') },
  { key: '3d', label: t.__('In 3 days', 'membuild-community') },
  { key: '1w', label: t.__('In 1 week', 'membuild-community') },
  { key: '1m', label: t.__('In 1 month', 'membuild-community') },
  { key: 'none', label: t.__('No end date', 'membuild-community') },
])

const list = ref(null)
const full = computed(() => props.poll.options.length >= MAX)
async function addChoice() {
  props.poll.options.push('')
  await nextTick()
  list.value?.querySelector('.choice:last-child input')?.focus()
}
const label = (i) => t.sprintf(/* translators: %d: the choice's number */ t.__('Choice %d', 'membuild-community'), i + 1)
</script>

<template>
  <section class="poll-box" :aria-label="t.__('Poll', 'membuild-community')">
    <header class="poll-top">
      <Icon name="poll" :size="20" />
      <strong>{{ t.__('Poll', 'membuild-community') }}</strong>
      <button type="button" class="x" :aria-label="t.__('Remove the poll', 'membuild-community')" @click="emit('remove')"><Icon name="close" :size="18" /></button>
    </header>
    <div ref="list" class="choices">
      <div v-for="(_, i) in poll.options" :key="i" class="choice">
        <input v-model="poll.options[i]" class="input" type="text" maxlength="200" :placeholder="label(i)" :aria-label="label(i)" />
        <button v-if="i >= 2" type="button" class="x" :aria-label="t.__('Remove this choice', 'membuild-community')" @click="poll.options.splice(i, 1)"><Icon name="close" :size="18" /></button>
      </div>
    </div>
    <p v-if="sameChoices" class="error-text" role="alert">{{ t.__('Two choices are the same. Change one of them.', 'membuild-community') }}</p>
    <p v-if="full" class="most">{{ t.__('6 choices is the most.', 'membuild-community') }}</p>
    <button v-else type="button" class="add" @click="addChoice"><Icon name="plus" :size="18" /> {{ t.__('Add a choice', 'membuild-community') }}</button>
    <label class="closes">
      <span>{{ t.__('Poll closes', 'membuild-community') }}</span>
      <select v-model="poll.closes" class="input">
        <option v-for="c in CLOSES" :key="c.key" :value="c.key">{{ c.label }}</option>
      </select>
    </label>
  </section>
</template>

<style scoped>
.poll-box {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 12px;
  padding: 12px 14px 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
}

.poll-top {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--info-text);
}

.poll-top strong {
  color: var(--text);
}

.poll-top .x {
  margin-left: auto;
}

.choices {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.choice {
  display: flex;
  align-items: center;
  gap: 6px;
}

.input {
  width: 100%;
  min-height: 48px;
  padding: 10px 12px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
}

.x {
  display: grid;
  flex: none;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  color: var(--text-2);
}

.x:hover {
  background: var(--surface-2);
  color: var(--text);
}

.add {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  width: fit-content;
  min-height: 44px;
  color: var(--text);
  font: inherit;
  font-weight: 650;
}

.most {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}

.closes {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-top: 10px;
  border-top: 1px solid var(--border);
  font-weight: 650;
}

.closes .input {
  width: auto;
  font-weight: 400;
}
</style>
