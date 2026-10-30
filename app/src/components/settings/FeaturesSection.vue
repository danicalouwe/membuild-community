<script setup>
import { useSettings } from './context.js'
import { t } from '../../lib/i18n.js'

const FEATURES = [
  { key: 'classroom', label: t.__('Classroom', 'membuild-community'), hint: t.__('Courses and lessons.', 'membuild-community') },
  { key: 'messages', label: t.__('Messages', 'membuild-community'), hint: t.__('Private chats between members.', 'membuild-community') },
  { key: 'online', sub: 'messages', label: t.__('Show who’s online', 'membuild-community'), hint: t.__('A green dot on members who are here right now. Members can hide theirs.', 'membuild-community') },
  { key: 'stories', label: t.__('Stories', 'membuild-community'), hint: t.__('Photos and words that disappear after a day.', 'membuild-community') },
  { key: 'reels', label: t.__('Reels', 'membuild-community'), hint: t.__('Short upright videos, in their own tab.', 'membuild-community') },
  { key: 'leaderboard', label: t.__('Leaderboard', 'membuild-community'), hint: t.__('A list of the most active members. Points still count when it’s off.', 'membuild-community') },
  { key: 'events', label: t.__('Events', 'membuild-community'), hint: t.__('A calendar of live calls and meetups. Members can say they’re going.', 'membuild-community') },
  { key: 'places', label: t.__('Places', 'membuild-community'), hint: t.__('A map of places to meet. Members can suggest new ones.', 'membuild-community') },
  { key: 'search', label: t.__('Search', 'membuild-community'), hint: t.__('A search box at the top. Private messages are never searched.', 'membuild-community') },
]

// Settings › Features › Courses. The lesson list, progress, Mark Complete and locks are always on.
const COURSE_PARTS = [
  { key: 'questions', label: t.__('Questions under lessons', 'membuild-community'), hint: t.__('Members ask and answer questions under each lesson.', 'membuild-community') },
  { key: 'helpful', sub: true, label: t.__('Helpful votes', 'membuild-community'), hint: t.__('Members mark the best answers, and those go to the top.', 'membuild-community') },
  { key: 'saying', sub: true, label: t.__('“What people are saying” on the course page', 'membuild-community'), hint: t.__('Shows recent questions on the course page.', 'membuild-community') },
  { key: 'times', label: t.__('Time for each lesson', 'membuild-community'), hint: t.__('Shows how long each lesson takes, like “7 min”.', 'membuild-community') },
  { key: 'instructor', label: t.__('Instructor on the course page', 'membuild-community'), hint: t.__('Shows who made the course.', 'membuild-community') },
  { key: 'confetti', label: t.__('Confetti when a course is finished', 'membuild-community'), hint: t.__('A little celebration when someone finishes a course.', 'membuild-community') },
  { key: 'suggest', label: t.__('“Next you might like”', 'membuild-community'), hint: t.__('Suggests another course at the end.', 'membuild-community') },
]

const { form } = useSettings()
// A switch that waits for another one says which, in place of the old “Needs …”.
const needs = (label) => t.sprintf(/* translators: %s: another feature, e.g. "Messages" */ t.__('Turn on %s first.', 'membuild-community'), label || '')
</script>

<template>
  <section id="s-features" class="card group">
    <h2>{{ t.__('Features', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Turn parts of your community on or off. Turning one off deletes nothing.', 'membuild-community') }}</p>
    <label v-for="f in FEATURES" :key="f.key" class="toggle-row" :class="{ nested: f.sub }">
      <span>
        <strong>{{ f.label }}</strong>
        <small>{{ f.hint }}</small>
        <small v-if="f.sub && !form.features[f.sub]" class="needs">{{ needs(FEATURES.find((x) => x.key === f.sub)?.label) }}</small>
      </span>
      <input v-model="form.features[f.key]" type="checkbox" class="switch" role="switch" :disabled="f.sub && !form.features[f.sub]" />
    </label>

    <template v-if="form.courses">
      <h3 class="sub-head">{{ t.__('Classroom', 'membuild-community') }}</h3>
      <label class="toggle-row">
        <span>
          <strong>{{ t.__('Members and posts on course cards', 'membuild-community') }}</strong>
          <small>{{ t.__('Shows who’s taking each course.', 'membuild-community') }}</small>
        </span>
        <input v-model="form.courses.activity" type="checkbox" class="switch" role="switch" />
      </label>

      <h3 class="sub-head">{{ t.__('Members directory', 'membuild-community') }}</h3>
      <label class="toggle-row">
        <span>
          <strong>{{ t.__('Cover photos on member cards', 'membuild-community') }}</strong>
          <small>{{ t.__('Shows each member’s cover photo on the Members page.', 'membuild-community') }}</small>
        </span>
        <input v-model="form.member_covers" type="checkbox" class="switch" role="switch" />
      </label>

      <h3 class="sub-head">{{ t.__('Courses', 'membuild-community') }}</h3>
      <p class="sub-intro">{{ t.__('Lessons, progress and Mark Complete are always on.', 'membuild-community') }}</p>
      <label v-for="p in COURSE_PARTS" :key="p.key" class="toggle-row" :class="{ nested: p.sub }">
        <span>
          <strong>{{ p.label }}</strong>
          <small>{{ p.hint }}</small>
          <small v-if="p.sub && !form.courses.questions" class="needs">{{ needs(COURSE_PARTS.find((x) => x.key === 'questions')?.label) }}</small>
        </span>
        <input v-model="form.courses[p.key]" type="checkbox" class="switch" role="switch" :disabled="p.sub && !form.courses.questions" />
      </label>
    </template>
  </section>
</template>

<style scoped>
.sub-head {
  margin: 28px 0 4px;
  font-size: var(--fs-xl);
}

.sub-intro {
  margin: 0 0 10px;
  color: var(--text-2);
}

/* Helpful votes and What people are saying sit under Questions, which they need. */
/* A setting that belongs to the one above it: a soft grey box, set in a little. */
.toggle-row.nested {
  margin: 0 0 8px 24px;
  padding: 14px 20px !important; /* the shared row style would otherwise take the sides away */
  border: 0;
  border-radius: var(--radius);
  background: var(--surface-2);
}

/* Two in a row share one box. */
.toggle-row.nested:has(+ .toggle-row.nested) {
  margin-bottom: 0;
  border-bottom-right-radius: 0;
  border-bottom-left-radius: 0;
}

.toggle-row.nested + .toggle-row.nested {
  border-top: 1px solid var(--border);
  border-top-right-radius: 0;
  border-top-left-radius: 0;
}

/* The line under the parent stops at the box. */
.toggle-row:has(+ .toggle-row.nested) {
  padding-bottom: 12px;
}

.toggle-row.nested:has(.switch:disabled) {
  opacity: 0.55;
}

/* "Turn on Messages first.": shown under a switch that waits for another one. */
.toggle-row .needs {
  color: var(--warn-text);
  font-weight: 600;
}
</style>
