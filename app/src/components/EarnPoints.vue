<script setup>
import { computed } from 'vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/** How to earn points, from the community's real point rules (and their daily caps). */
const props = defineProps({ rules: { type: Array, default: () => [] } })
const SHOWN = [
  { reason: 'post', icon: 'pencil', label: t.__('Write a post', 'membuild-community') },
  { reason: 'comment', icon: 'comment', label: t.__('Comment on a post', 'membuild-community') },
  { reason: 'reaction_received', icon: 'heart', label: t.__('Someone likes your post or comment', 'membuild-community') },
  { reason: 'story', icon: 'clock', label: t.__('Share a story', 'membuild-community') },
  { reason: 'lesson', icon: 'book', label: t.__('Finish a lesson', 'membuild-community') },
  { reason: 'course', icon: 'cap', label: t.__('Finish a course', 'membuild-community') },
  { reason: 'daily_visit', icon: 'sun', label: t.__('Visit the community', 'membuild-community') },
]
const rows = computed(() =>
  SHOWN.map((s) => ({ ...s, rule: props.rules.find((r) => r.reason === s.reason) }))
    .filter((s) => s.rule && s.rule.points > 0)
    .map((s) => ({
      ...s,
      cap:
        s.reason === 'daily_visit'
          ? t.__('Once a day', 'membuild-community')
          : s.rule.daily_cap
            ? t.sprintf(/* translators: %d: how many times a day it counts */ t.__('Up to %d a day', 'membuild-community'), s.rule.daily_cap)
            : '',
    })),
)
</script>

<template>
  <section class="earn card">
    <h2>{{ t.__('How to earn points', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Points move you up a level and onto the board.', 'membuild-community') }}</p>
    <ul>
      <li v-for="r in rows" :key="r.reason">
        <span class="ic"><Icon :name="r.icon" :size="18" /></span>
        <span class="txt"><span>{{ r.label }}</span><small v-if="r.cap">{{ r.cap }}</small></span>
        <strong>+{{ r.rule.points }}</strong>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.earn {
  padding: 20px 18px 8px;
  border-radius: 16px;
}

h2 {
  margin: 0;
  font-size: 20px;
}

.intro {
  margin: 4px 0 10px;
  color: var(--text-2);
  font-size: 14px;
}

ul {
  margin: 0;
  padding: 0;
  list-style: none;
}

li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
}

li:first-child {
  border-top: 0;
}

.ic {
  display: grid;
  flex: none;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: var(--surface-2);
}

.txt {
  display: flex;
  flex: 1;
  flex-direction: column;
  font-size: 16px;
}

.txt small {
  color: var(--text-2);
  font-size: 13px;
}

li strong {
  font-size: 16px;
}
</style>
