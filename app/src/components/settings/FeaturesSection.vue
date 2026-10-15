<script setup>
import { useSettings } from './context.js'
import { t } from '../../lib/i18n.js'

const FEATURES = [
  { key: 'classroom', label: t.__('Classroom', 'membuild-community'), hint: t.__('Courses and lessons.', 'membuild-community') },
  { key: 'messages', label: t.__('Messages', 'membuild-community'), hint: t.__('Private chats between members.', 'membuild-community') },
  { key: 'stories', label: t.__('Stories', 'membuild-community'), hint: t.__('Photos and text that disappear after 24 hours, above the feed.', 'membuild-community') },
  { key: 'reels', label: t.__('Reels', 'membuild-community'), hint: t.__('Short vertical videos, in their own tab and inside the feed.', 'membuild-community') },
  { key: 'leaderboard', label: t.__('Leaderboard', 'membuild-community'), hint: t.__('The ranking of members. Points and levels keep working when it is off.', 'membuild-community') },
  { key: 'search', label: t.__('Search', 'membuild-community'), hint: t.__('Find members, posts, comments and lessons from the box at the top. Private messages are never searched.', 'membuild-community') },
]

const { form } = useSettings()
</script>

<template>
  <section id="s-features" class="card group">
    <h2>{{ t.__('Features', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__("Turn off what your community doesn't need. Nothing is deleted; turning a feature back on brings everything back.", 'membuild-community') }}</p>
    <label v-for="f in FEATURES" :key="f.key" class="toggle-row">
      <span>
        <strong>{{ f.label }}</strong>
        <small>{{ f.hint }}</small>
      </span>
      <input v-model="form.features[f.key]" type="checkbox" class="switch" role="switch" />
    </label>
  </section>
</template>
