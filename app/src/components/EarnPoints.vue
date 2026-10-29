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
        <strong class="pts">+{{ r.rule.points }}</strong>
      </li>
    </ul>
  </section>
</template>

<style scoped>
/* Same rhythm as the levels ladder above it: 24px in, round icons on one left edge, no lines. */
.earn {
  padding: 24px 24px 16px;
  border-radius: 16px;
}

h2 {
  margin: 0;
  font-size: 18px;
}

.intro {
  margin: 6px 0 14px;
  color: var(--text-2);
  font-size: 14px;
  line-height: 1.45;
}

ul {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0 -12px;
  padding: 0;
  list-style: none;
}

li {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 56px;
  padding: 10px 12px;
  border-radius: 14px;
}

.ic {
  display: grid;
  flex: none;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text);
}

.ic :deep(svg) {
  width: 16px;
  height: 16px;
}

.txt {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.35;
}

.txt small {
  color: var(--text-2);
  font-size: 13px;
  font-weight: 400;
}

/* The points as a small green pill, all the same width so they line up. */
.pts {
  flex: none;
  min-width: 54px;
  padding: 5px 8px;
  border-radius: 999px;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: 14px;
  font-weight: 800;
  text-align: center;
}
</style>
