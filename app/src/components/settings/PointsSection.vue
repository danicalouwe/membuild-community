<script setup>
import { locale, t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// Points per action, daily limits and the names of the levels.
const { form, meta } = useSettings()
const nameFor = (level) => t.sprintf(/* translators: %d: level number */ t.__('Name for level %d', 'membuild-community'), level)
const pointsFrom = (min) => t.sprintf(/* translators: %s: the points a member needs to reach this level */ t.__('%s+ points', 'membuild-community'), min.toLocaleString(locale))
const pointsFor = (action) => t.sprintf(/* translators: %s: an action that earns points, e.g. "Write a post" */ t.__('Points: %s', 'membuild-community'), action)
const capFor = (action) => t.sprintf(/* translators: %s: an action that earns points, e.g. "Write a post" */ t.__('Max per day: %s', 'membuild-community'), action)
</script>

<template>
  <section id="s-points" class="card group">
    <h2>{{ t.__('Points & levels', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Members earn points for taking part and level up as they go. Changes apply to new activity.', 'membuild-community') }}</p>

    <h3>{{ t.__('Level names', 'membuild-community') }}</h3>
    <ol class="levels">
      <li v-for="(min, i) in meta.thresholds" :key="i">
        <span class="lv">{{ i + 1 }}</span>
        <input v-model="form.level_names[i]" type="text" maxlength="30" :placeholder="t.sprintf(t.__('Level %d', 'membuild-community'), i + 1)" :aria-label="nameFor(i + 1)" />
        <small>{{ pointsFrom(min) }}</small>
      </li>
    </ol>

    <h3>{{ t.__('Points for each action', 'membuild-community') }}</h3>
    <table class="rules">
      <thead>
        <tr><th>{{ t.__('Action', 'membuild-community') }}</th><th>{{ t.__('Points', 'membuild-community') }}</th><th>{{ t.__('Max per day', 'membuild-community') }}</th></tr>
      </thead>
      <tbody>
        <tr v-for="(label, key) in meta.rules" :key="key">
          <td>{{ label }}</td>
          <td><input v-model.number="form.points[key].points" type="number" min="0" max="1000" :aria-label="pointsFor(label)" /></td>
          <td><input v-model.number="form.points[key].cap" type="number" min="0" max="100" :aria-label="capFor(label)" placeholder="0" /></td>
        </tr>
      </tbody>
    </table>
    <small class="note">{{ t.__('0 means no limit.', 'membuild-community') }}</small>
  </section>
</template>
