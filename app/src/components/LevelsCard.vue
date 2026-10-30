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
 * Each level: points · % on one line, Unlocks on the next. `stacked` (the phone's Levels
 * & points page) drops the card around it.
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

    <div class="progress">
      <div class="bar" role="progressbar" :aria-label="t.__('Progress to the next level', 'membuild-community')" :aria-valuenow="progress?.percent || 0" aria-valuemin="0" aria-valuemax="100"><i :style="{ width: `${Math.max(progress?.percent || 0, 3)}%` }" /></div>
      <p class="to-next">{{ toNext }}</p>
    </div>

    <!-- A ladder: one line joins the levels, filled up to yours. -->
    <ol>
      <li v-for="l in levels" :key="l.level" :class="{ reached: l.level < mine, mine: l.level === mine, locked: l.level > mine }">
        <span class="lnum"><Icon v-if="l.level > mine" name="lock" :size="13" /><template v-else>{{ l.level }}</template></span>
        <span class="ltext">
          <strong>{{ levelLabel(l.level) }}</strong>
          <small>{{ pointCount(l.min) }}<template v-if="l.percent"> · {{ share(l.percent) }}</template></small>
          <small v-for="c in l.courses" :key="c" class="unlock"><Icon name="book" :size="14" /> {{ t.sprintf(/* translators: %s: a course */ t.__('Unlocks %s', 'membuild-community'), c) }}</small>
        </span>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.levels {
  padding: 24px;
  border-radius: var(--radius-lg);
}

header {
  display: flex;
  align-items: center;
  gap: 14px;
}

header strong {
  display: block;
  font-size: var(--fs-2xl);
}

header span {
  color: var(--accent-text);
  font-size: var(--fs-md);
  font-weight: 700;
}

/* How far to the next level, in its own soft panel. */
.progress {
  margin: 20px 0 22px;
  padding: 16px;
  border-radius: var(--radius);
  background: var(--surface-2);
}

.bar {
  height: 8px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: color-mix(in srgb, var(--text) 10%, transparent);
}

.bar i {
  display: block;
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--accent);
}

.to-next {
  margin: 10px 0 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}

ol {
  display: flex;
  flex-direction: column;
  gap: 4px;
  /* Rows reach a little past the edge so your level's tint has room; the circles line up with the photo. */
  margin: 0 -12px;
  padding: 0;
  list-style: none;
}

li {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 12px;
  border-radius: var(--radius);
}

/* The line down to the next level's circle. */
li:not(:last-child)::before {
  content: '';
  position: absolute;
  top: 50px;
  bottom: -12px;
  left: 27px;
  width: 2px;
  border-radius: 2px;
  background: var(--border);
}

li.reached::before {
  background: var(--accent);
}

li.mine {
  background: color-mix(in srgb, var(--accent) 12%, transparent);
}

.lnum {
  position: relative;
  z-index: 1;
  display: grid;
  flex: none;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 800;
}

.reached .lnum {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.mine .lnum {
  background: var(--accent);
  color: var(--accent-ink);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 25%, transparent);
}

.ltext {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding-top: 5px;
}

.ltext strong {
  font-size: var(--fs-lg);
  line-height: 1.35;
}

.ltext small {
  color: var(--text-2);
  font-size: var(--fs-sm);
  line-height: 1.45;
}

.locked .ltext strong {
  color: var(--text-2);
}

.locked .ltext small {
  color: var(--text-3);
}

.unlock {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
  color: var(--text) !important;
  font-weight: 600;
}

.locked .unlock {
  color: var(--text-2) !important;
}

.stacked {
  border: 0;
  border-radius: 0;
  box-shadow: none;
  background: transparent;
}
</style>
