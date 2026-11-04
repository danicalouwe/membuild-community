<script setup>
import { computed, ref } from 'vue'
import { setVote } from '../stores/feed.js'
import { siteDay } from '../lib/time.js'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * A poll inside a post: one tap votes, then the totals show. Nobody can see who voted, so
 * the server only ever sends shares of the total, and only once this member may see them.
 */
const props = defineProps({
  post: { type: Object, required: true },
  roomy: { type: Boolean, default: false }, // the opened post: its wider side padding
})

const poll = computed(() => props.post.poll)
const changing = ref(false)
const voting = ref(false)
const failed = ref(null) // the choice that didn't save, to try again

// Choices while they can still pick (or are changing their pick); the bars otherwise.
const picking = computed(() => !poll.value.closed && poll.value.can_vote && (poll.value.my_vote === null || changing.value || voting.value))
// While changing, nothing is ticked until they tap a new choice.
const ticked = (option) => option.id === poll.value.my_vote && (voting.value || !changing.value)
const topPercent = computed(() => Math.max(0, ...poll.value.options.map((o) => o.percent || 0)))

async function vote(option) {
  if (voting.value || props.post.pending) return
  if (option.id === poll.value.my_vote) return (changing.value = false)
  voting.value = true
  failed.value = null
  try {
    await setVote(props.post, option.id)
    changing.value = false
  } catch {
    failed.value = option
  } finally {
    voting.value = false
  }
}

const countWords = computed(() => {
  const n = poll.value.total_votes
  return n ? t.sprintf(/* translators: %d: number of votes */ t._n('%d vote', '%d votes', n, 'membuild-community'), n) : t.__('No votes yet', 'membuild-community')
})
const timeWords = computed(() => {
  if (poll.value.closed) return t.sprintf(/* translators: %s: a date, e.g. Oct 30 */ t.__('Closed %s', 'membuild-community'), siteDay(poll.value.closed_at, { month: 'short', day: 'numeric' }))
  if (!poll.value.closes_at) return ''
  const days = Math.max(1, Math.ceil((new Date(poll.value.closes_at).getTime() - Date.now()) / 86400000))
  return t.sprintf(/* translators: %d: days until the poll closes */ t._n('%d day left', '%d days left', days, 'membuild-community'), days)
})
const footWords = computed(() => [countWords.value, timeWords.value].filter(Boolean).join(' · '))
</script>

<template>
  <div class="poll" :class="{ roomy }">
    <div v-if="picking" class="choices" role="radiogroup" :aria-label="post.content">
      <button v-for="o in poll.options" :key="o.id" type="button" class="choice" role="radio" :aria-checked="ticked(o)" :class="{ on: ticked(o) }" :disabled="post.pending" @click="vote(o)">
        <span class="dot"><Icon v-if="ticked(o)" name="check" :size="14" /></span>
        <span class="words">{{ o.label }}</span>
      </button>
    </div>
    <ul v-else class="bars">
      <li v-for="o in poll.options" :key="o.id" class="bar" :class="{ mine: o.id === poll.my_vote, top: poll.closed && o.percent && o.percent === topPercent }">
        <span class="fill" :style="{ width: `${o.percent || 0}%` }" />
        <span class="words"><Icon v-if="o.id === poll.my_vote" class="tick" name="check" :size="16" />{{ o.label }}</span>
        <span class="percent">{{ o.percent || 0 }}%</span>
      </li>
    </ul>

    <p v-if="failed" class="error-text" role="alert">{{ t.__('Your vote didn’t save.', 'membuild-community') }} <button type="button" class="retry" @click="vote(failed)">{{ t.__('Try again', 'membuild-community') }}</button></p>

    <div class="foot">
      <span v-if="poll.closed" class="status-tag">{{ t.__('Poll closed', 'membuild-community') }}</span>
      <span>{{ footWords }}</span>
      <template v-if="poll.can_vote && poll.my_vote !== null">
        <button v-if="changing" type="button" class="link" @click="changing = false; failed = null">{{ t.__('Keep my vote', 'membuild-community') }}</button>
        <button v-else type="button" class="link" @click="changing = true">{{ t.__('Change my vote', 'membuild-community') }}</button>
      </template>
    </div>
    <p v-if="picking && !changing && !failed" class="note">{{ t.__('No one can see how you voted.', 'membuild-community') }}</p>
    <p v-else-if="!poll.closed && !poll.can_vote" class="note">{{ t.__('You can’t vote right now.', 'membuild-community') }}</p>
  </div>
</template>

<style scoped>
.poll {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 2px 14px 12px;
}

.poll.roomy {
  padding: 2px 16px 12px;
}

.choices,
.bars {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.choice {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 48px;
  padding: 10px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
  font-weight: 600;
  text-align: left;
}

.choice:hover:not(:disabled) {
  background: var(--surface-2);
}

.dot {
  display: grid;
  flex: none;
  place-items: center;
  width: 20px;
  height: 20px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
}

.choice.on .dot {
  border-color: var(--accent-text);
  color: var(--accent-text);
}

.bar {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 48px;
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  overflow: hidden;
  font-size: var(--fs-lg);
  font-weight: 600;
}

.fill {
  position: absolute;
  inset: 0 auto 0 0;
  background: color-mix(in srgb, var(--text) 11%, transparent);
}

.bar.mine .fill {
  background: color-mix(in srgb, var(--accent) 45%, var(--surface));
}

.bar .words,
.percent {
  position: relative;
}

.bar .words {
  min-width: 0;
  overflow-wrap: anywhere;
}

.tick {
  margin-right: 6px;
  color: var(--accent-text);
  vertical-align: -2px;
}

.percent {
  flex: none;
  font-size: var(--fs-md);
  font-weight: 700;
}

.bar.top,
.bar.mine {
  font-weight: 700;
}

.error-text {
  font-size: var(--fs-md);
}

.retry {
  color: inherit;
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
}

.words {
  overflow-wrap: anywhere;
}

.foot {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 10px;
  color: var(--text-2);
  font-size: var(--fs-md);
}

.link {
  margin-left: auto;
  min-height: 32px;
  color: var(--text-2);
  font: inherit;
  font-weight: 600;
  text-decoration: underline;
}

.link:hover {
  color: var(--text);
}

.note {
  margin: 0;
  color: var(--text-3);
  font-size: var(--fs-sm);
}
</style>
