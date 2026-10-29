<script setup>
import { useSettings } from './context.js'
import { t } from '../../lib/i18n.js'

const FEATURES = [
  { key: 'classroom', label: t.__('Classroom', 'membuild-community'), hint: t.__('Courses and lessons.', 'membuild-community') },
  { key: 'messages', label: t.__('Messages', 'membuild-community'), hint: t.__('Private chats between members.', 'membuild-community') },
  { key: 'online', sub: 'messages', label: t.__('Show who’s online', 'membuild-community'), hint: t.__('A green dot and “Active now” on members who are using the community right now. Members can still hide themselves in their Privacy settings. Needs Messages.', 'membuild-community') },
  { key: 'stories', label: t.__('Stories', 'membuild-community'), hint: t.__('Photos and text that disappear after 24 hours, above the feed.', 'membuild-community') },
  { key: 'reels', label: t.__('Reels', 'membuild-community'), hint: t.__('Short vertical videos, in their own tab and inside the feed.', 'membuild-community') },
  { key: 'leaderboard', label: t.__('Leaderboard', 'membuild-community'), hint: t.__('The ranking of members. Points and levels keep working when it is off.', 'membuild-community') },
  { key: 'events', label: t.__('Events', 'membuild-community'), hint: t.__('A calendar of live calls, Q&As and workshops, with RSVPs, reminders and a link to Zoom, Meet or a live stream. Admins and moderators add events.', 'membuild-community') },
  { key: 'places', label: t.__('Places', 'membuild-community'), hint: t.__('A map of meetup spots, partners with member perks, and venues. Admins add places; members can suggest them.', 'membuild-community') },
  { key: 'search', label: t.__('Search', 'membuild-community'), hint: t.__('Find members, posts, comments and lessons from the box at the top. Private messages are never searched.', 'membuild-community') },
]

// Settings › Features › Courses. The lesson list, progress, Mark Complete and locks are always on.
const COURSE_PARTS = [
  { key: 'questions', label: t.__('Questions under lessons', 'membuild-community'), hint: t.__('Members can ask and answer questions under each lesson. You can also turn this off for one course in its settings.', 'membuild-community') },
  { key: 'helpful', sub: true, label: t.__('Helpful votes', 'membuild-community'), hint: t.__('Members tap Helpful on questions, and the most helpful ones move to the top. Needs Questions under lessons.', 'membuild-community') },
  { key: 'saying', sub: true, label: t.__('“What people are saying” on the course page', 'membuild-community'), hint: t.__('Shows the newest and most helpful questions on a course’s Overview. Needs Questions under lessons.', 'membuild-community') },
  { key: 'times', label: t.__('Time for each lesson', 'membuild-community'), hint: t.__('Shows “7 min” on lessons and “About 22 min” on the course. Turn it off if your videos don’t report their length.', 'membuild-community') },
  { key: 'instructor', label: t.__('Instructor on the course page', 'membuild-community'), hint: t.__('Shows “By” and the course creator’s photo under the course name.', 'membuild-community') },
  { key: 'confetti', label: t.__('Confetti when a course is finished', 'membuild-community'), hint: t.__('The “You finished the course!” message always shows; this adds the confetti.', 'membuild-community') },
  { key: 'suggest', label: t.__('“Next you might like”', 'membuild-community'), hint: t.__('Suggests another course when a member finishes one.', 'membuild-community') },
]

const { form } = useSettings()
</script>

<template>
  <section id="s-features" class="card group">
    <h2>{{ t.__('Features', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__("Turn off what your community doesn't need. Nothing is deleted; turning a feature back on brings everything back.", 'membuild-community') }}</p>
    <label v-for="f in FEATURES" :key="f.key" class="toggle-row" :class="{ nested: f.sub }">
      <span>
        <strong>{{ f.label }}</strong>
        <small>{{ f.hint }}</small>
      </span>
      <input v-model="form.features[f.key]" type="checkbox" class="switch" role="switch" :disabled="f.sub && !form.features[f.sub]" />
    </label>

    <template v-if="form.courses">
      <h3 class="sub-head">{{ t.__('Classroom', 'membuild-community') }}</h3>
      <label class="toggle-row">
        <span>
          <strong>{{ t.__('Members and posts on course cards', 'membuild-community') }}</strong>
          <small>{{ t.__('Shows the faces of members taking each course, how many there are, and how many posts it has. Turn it off for a quieter grid.', 'membuild-community') }}</small>
        </span>
        <input v-model="form.courses.activity" type="checkbox" class="switch" role="switch" />
      </label>

      <h3 class="sub-head">{{ t.__('Members directory', 'membuild-community') }}</h3>
      <label class="toggle-row">
        <span>
          <strong>{{ t.__('Cover photos on member cards', 'membuild-community') }}</strong>
          <small>{{ t.__('Shows each member’s cover photo across the top of their card. Turn it off for a simpler directory. Profiles always show the cover.', 'membuild-community') }}</small>
        </span>
        <input v-model="form.member_covers" type="checkbox" class="switch" role="switch" />
      </label>

      <h3 class="sub-head">{{ t.__('Courses', 'membuild-community') }}</h3>
      <p class="sub-intro">{{ t.__('The lesson list, progress, Mark Complete and course locks are always on.', 'membuild-community') }}</p>
      <label v-for="p in COURSE_PARTS" :key="p.key" class="toggle-row" :class="{ nested: p.sub }">
        <span>
          <strong>{{ p.label }}</strong>
          <small>{{ p.hint }}</small>
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
</style>
