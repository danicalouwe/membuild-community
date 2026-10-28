<script setup>
import { computed } from 'vue'
import { cfg, levelLabel } from '../config.js'
import { compactNumber } from '../lib/time.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * You, your level and how far to the next, then every level: its points, how many members
 * are there, and the course it unlocks. Levels above yours are greyed with a lock.
 * `stacked` (the phone's Levels & points page): points · % on one line, Unlocks on the next.
 */
const props = defineProps({
  levels: { type: Array, default: () => [] },
  progress: { type: Object, default: null },
  stacked: { type: Boolean, default: false },
})
const mine = computed(() => props.progress?.level || cfg.user.level || 1)
const pointCount = (n) => t.sprintf(/* translators: %s: number of points */ t._n('%s point', '%s points', n, 'membuild-community'), compactNumber(n))
const share = (pct) => t.sprintf(/* translators: %s: percent of members at this level */ t.__('%s%% of members', 'membuild-community'), pct)
const toNext = computed(() =>
  props.progress?.next_level
    ? t.sprintf(/* translators: 1: points, 2: e.g. "Level 7 · Strategist" */ t._n('%1$s more point to %2$s', '%1$s more points to %2$s', props.progress.to_next, 'membuild-community'), props.progress.to_next.toLocaleString(), levelLabel(props.progress.next_level))
    : t.__('You’re at the top level.', 'membuild-community'),
)
</script>

<template>
  <section class="levels card" :class="{ stacked }" :aria-label="t.__('Levels', 'membuild-community')">
    <header>
      <Avatar :user="{ ...cfg.user, level: mine }" :size="56" badge />
      <div>
        <strong>{{ cfg.user.name }}</strong>
        <span>{{ levelLabel(mine) }}</span>
      </div>
    </header>
    <div class="bar" role="progressbar" :aria-valuenow="progress?.percent || 0" aria-valuemin="0" aria-valuemax="100"><i :style="{ width: `${Math.max(progress?.percent || 0, 3)}%` }" /></div>
    <p class="to-next">{{ toNext }}</p>
    <ol>
      <li v-for="l in levels" :key="l.level" :class="{ mine: l.level === mine, locked: l.level > mine }">
        <span class="lnum"><Icon v-if="l.level > mine" name="lock" :size="13" /><template v-else>{{ l.level }}</template></span>
        <span class="ltext">
          <strong>{{ levelLabel(l.level) }}</strong>
          <template v-if="stacked">
            <small>{{ pointCount(l.min) }}<template v-if="l.percent"> · {{ share(l.percent) }}</template></small>
            <small v-for="c in l.courses" :key="c" class="unlock"><Icon name="book" :size="14" /> {{ t.sprintf(/* translators: %s: a course */ t.__('Unlocks %s', 'membuild-community'), c) }}</small>
          </template>
          <small v-else>{{ pointCount(l.min) }}<template v-if="l.courses.length"> · {{ t.sprintf(/* translators: %s: course titles */ t.__('unlocks %s', 'membuild-community'), l.courses.join(', ')) }}</template></small>
        </span>
        <span v-if="!stacked && l.percent" class="pct">{{ share(l.percent) }}</span>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.levels {
  padding: 18px 18px 14px;
  border-radius: 16px;
}

header {
  display: flex;
  align-items: center;
  gap: 12px;
}

header strong {
  display: block;
  font-size: 17px;
}

header span {
  color: var(--accent-text);
  font-size: 14px;
  font-weight: 700;
}

.bar {
  height: 8px;
  margin: 16px 0 8px;
  overflow: hidden;
  border-radius: 999px;
  background: var(--surface-2);
}

.bar i {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: var(--accent);
}

.to-next {
  margin: 0 0 12px;
  color: var(--text-2);
  font-size: 14px;
}

ol {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
}

li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px;
  border-radius: 12px;
}

li.mine {
  background: var(--accent-soft);
}

li.locked {
  opacity: 0.55;
}

.lnum {
  display: grid;
  flex: none;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--surface-2);
  font-size: 13px;
  font-weight: 800;
}

.mine .lnum {
  background: var(--accent);
  color: var(--accent-ink);
}

.ltext {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.ltext strong {
  font-size: 16px;
}

.ltext small {
  color: var(--text-2);
  font-size: 13px;
  line-height: 1.4;
}

.unlock {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
  color: var(--text) !important;
}

.pct {
  flex: none;
  color: var(--text-2);
  font-size: 13px;
}

.stacked {
  border: 0;
  border-radius: 0;
  box-shadow: none;
  background: transparent;
}
</style>
