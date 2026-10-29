<script setup>
import { computed } from 'vue'
import { locale, t } from '../../lib/i18n.js'
import Icon from '../Icon.vue'
import { useSettings } from './context.js'

// How many levels, their names and points, points per action and daily limits.
const { form, meta } = useSettings()
const nameFor = (level) => t.sprintf(/* translators: %d: level number */ t.__('Name for level %d', 'membuild-community'), level)
const pointsLabel = (level) => t.sprintf(/* translators: %d: level number */ t.__('Points needed for level %d', 'membuild-community'), level)

/* How many levels: 3 to 9. Picking a number fills in suggested points for it. */
const COUNTS = [3, 4, 5, 6, 7, 8, 9]
const count = computed(() => form.value.level_points.length)
const suggested = (n) => [...(meta.value.suggested_levels?.[n] || meta.value.thresholds)]
const setCount = (n) => n !== count.value && (form.value.level_points = suggested(n))
const useSuggested = () => (form.value.level_points = suggested(count.value))
const isSuggested = computed(() => suggested(count.value).join() === form.value.level_points.join())
const setPoints = (i, v) => (form.value.level_points[i] = Math.min(10000000, parseInt(String(v).replace(/\D/g, ''), 10) || 0))
// The first level that doesn't need more than the one before it, if any.
const tooLow = computed(() => form.value.level_points.findIndex((p, i) => i > 0 && p <= form.value.level_points[i - 1]))

/* Courses that unlock at a level that won't exist after saving. */
const orphans = computed(() => (meta.value.level_courses || []).filter((c) => c.level > count.value))
const levelName = (n) => form.value.level_names[n - 1] || t.sprintf(t.__('Level %d', 'membuild-community'), n)
// 0 a day means no limit: the box shows "No limit" instead of a 0.
const setCap = (key, v) => (form.value.points[key].cap = Math.max(0, Math.min(100, parseInt(v, 10) || 0)))
const pointsFor = (action) => t.sprintf(/* translators: %s: an action that earns points, e.g. "Write a post" */ t.__('Points: %s', 'membuild-community'), action)
const capFor = (action) => t.sprintf(/* translators: %s: an action that earns points, e.g. "Write a post" */ t.__('Max per day: %s', 'membuild-community'), action)
</script>

<template>
  <section id="s-points" class="card group">
    <h2>{{ t.__('Points & levels', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Members earn points for taking part and move up levels. Changes count for new activity.', 'membuild-community') }}</p>

    <fieldset class="field">
      <legend class="label">{{ t.__('How many levels', 'membuild-community') }}</legend>
      <div class="counts">
        <button v-for="n in COUNTS" :key="n" type="button" :class="{ on: n === count }" :aria-pressed="n === count" @click="setCount(n)">{{ n }}</button>
      </div>
      <small>{{ t.__('Fewer levels feel easier to climb; more give members something to work towards for longer. Members keep their points; their level is worked out again.', 'membuild-community') }}</small>
    </fieldset>

    <h3>{{ t.__('Levels and the points they need', 'membuild-community') }}</h3>
    <ol class="levels two">
      <li v-for="(min, i) in form.level_points" :key="i">
        <span class="lv">{{ i + 1 }}</span>
        <input v-model="form.level_names[i]" type="text" maxlength="30" :placeholder="t.sprintf(t.__('Level %d', 'membuild-community'), i + 1)" :aria-label="nameFor(i + 1)" />
        <span v-if="i === 0" class="pts first">{{ t.__('0 pts', 'membuild-community') }}</span>
        <span v-else class="pts">
          <input :value="min.toLocaleString(locale)" type="text" inputmode="numeric" :class="{ bad: tooLow === i }" :aria-label="pointsLabel(i + 1)" :aria-invalid="tooLow === i" @change="setPoints(i, $event.target.value); $event.target.value = form.level_points[i].toLocaleString(locale)" />
          {{ t.__('pts', 'membuild-community') }}
        </span>
      </li>
    </ol>
    <div class="after">
      <small v-if="tooLow > 0" class="err" role="alert">{{ t.sprintf(/* translators: 1: level number, 2: the level before it */ t.__('Level %1$d needs more points than Level %2$d.', 'membuild-community'), tooLow + 1, tooLow) }}</small>
      <small v-else>{{ t.__('Each level needs more points than the one before. Level 1 is where everyone starts.', 'membuild-community') }}</small>
      <button v-if="!isSuggested" type="button" class="link" @click="useSuggested">{{ t.__('Use suggested points', 'membuild-community') }}</button>
    </div>
    <div v-if="orphans.length" class="warn-box" role="status">
      <Icon name="alert" :size="18" />
      <span v-if="orphans.length === 1">
        <strong>{{ t.__('1 course unlocks at a level that won’t exist any more.', 'membuild-community') }}</strong>
        {{ t.sprintf(/* translators: 1: course title, 2: its level now, 3: the new top level */ t.__('“%1$s” (Level %2$d) will unlock at Level %3$d, the top level, after you save. You can change it in its course settings.', 'membuild-community'), orphans[0].title, orphans[0].level, count) }}
      </span>
      <span v-else>
        <strong>{{ t.sprintf(/* translators: %d: number of courses */ t.__('%d courses unlock at a level that won’t exist any more.', 'membuild-community'), orphans.length) }}</strong>
        {{ t.sprintf(/* translators: 1: list of course titles, 2: the new top level */ t.__('%1$s will unlock at Level %2$d, the top level, after you save. You can change them in their course settings.', 'membuild-community'), orphans.map((c) => `“${c.title}”`).join(', '), count) }}
      </span>
    </div>

    <h3>{{ t.__('Points for each action', 'membuild-community') }}</h3>
    <table class="rules">
      <thead>
        <tr><th>{{ t.__('When a member…', 'membuild-community') }}</th><th>{{ t.__('Points', 'membuild-community') }}</th><th>{{ t.__('Up to, per day', 'membuild-community') }}</th></tr>
      </thead>
      <tbody>
        <tr v-for="(label, key) in meta.rules" :key="key">
          <td>{{ label }}</td>
          <td><input v-model.number="form.points[key].points" type="number" min="0" max="1000" :aria-label="pointsFor(label)" /></td>
          <td><input :value="form.points[key].cap || ''" type="text" inputmode="numeric" :aria-label="capFor(label)" :placeholder="t.__('No limit', 'membuild-community')" @input="setCap(key, $event.target.value)" /></td>
        </tr>
      </tbody>
    </table>
    <small class="note">{{ t.__('Leave “Up to, per day” empty for no limit.', 'membuild-community') }}</small>
  </section>
</template>

<style scoped>
.counts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.counts button {
  min-width: 52px;
  height: 52px;
  padding: 0 14px;
  border: 1px solid var(--border-strong);
  border-radius: 999px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: 16px;
  font-weight: 700;
  cursor: pointer;
}

.counts button.on {
  border: 2px solid var(--accent-text);
  background: var(--accent-soft);
  color: var(--accent-text);
}

h3 {
  margin-top: 26px;
}

/* Name, then the points it needs. */
#s-points .levels.two li {
  grid-template-columns: 34px 1fr 150px;
}

.pts {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
  color: var(--text-2);
  font-size: 15px;
}


#s-points .pts input {
  width: 104px;
  min-height: 44px;
  padding: 0 12px;
  text-align: right;
}

#s-points .pts input.bad,
#s-points .pts input.bad:focus {
  border-color: var(--danger);
  outline-color: var(--danger);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--danger) 18%, transparent);
}

.after {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 12px;
}

.after small {
  color: var(--text-2);
  font-size: 14px;
}

.after .err {
  color: var(--danger);
}

.link {
  flex: none;
  margin-left: auto;
  min-height: 44px;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-size: 15px;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}

.warn-box {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  margin-top: 14px;
  padding: 14px 16px;
  border-radius: 12px;
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: 15px;
  line-height: 1.5;
}

.warn-box :deep(svg) {
  flex: none;
  margin-top: 2px;
}

/* Level names two to a row on computers. */
.levels.two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  column-gap: 48px;
  row-gap: 10px;
}

.levels.two :deep(small),
.levels.two small {
  white-space: nowrap;
}

.rules td input {
  text-align: center;
}

@media (max-width: 767px) {
  .levels.two {
    grid-template-columns: 1fr;
  }

  #s-points .levels.two li {
    grid-template-columns: 30px 1fr 122px;
  }

  #s-points .pts input {
    width: 88px;
  }

  .after {
    flex-wrap: wrap;
  }

  .link {
    margin-left: 0;
  }
}
</style>
