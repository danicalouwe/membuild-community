<script setup>
import { ref, watch } from 'vue'
import { api } from '../../api.js'
import { useLatest } from '../../lib/useLatest.js'
import Avatar from '../Avatar.vue'
import { t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// Who can post and message, and who moderates.
const { form, meta } = useSettings()
const search = ref('')
const found = ref([])

const latest = useLatest()
let searchTimer = null
watch(search, (q) => {
  clearTimeout(searchTimer)
  latest() // a search still on its way no longer counts
  if (!q.trim()) return (found.value = [])
  searchTimer = setTimeout(async () => {
    const current = latest()
    const res = await api.people(q.trim()).catch(() => ({ items: [] }))
    if (!current()) return
    const taken = new Set([...form.value.moderators.map((m) => m.id), ...meta.value.built_in_staff.map((m) => m.id)])
    found.value = res.items.filter((u) => !taken.has(u.id)).slice(0, 6)
  }, 250)
})

function addModerator(u) {
  form.value.moderators.push(u)
  search.value = ''
}
</script>

<template>
  <section id="s-permissions" class="card group">
    <h2>{{ t.__('Who can do what', 'membuild-community') }}</h2>

    <div class="field">
      <span class="label">{{ t.__('Moderators', 'membuild-community') }}</span>
      <small>{{ t.__('Moderators review reports, remove posts and comments, pin posts, and mute or ban members.', 'membuild-community') }}</small>
      <ul class="people">
        <li v-for="u in meta.built_in_staff" :key="`b${u.id}`">
          <Avatar :user="u" :size="32" />
          <span class="nm">{{ u.name }}</span>
          <em>{{ t.__('Admin or editor', 'membuild-community') }}</em>
        </li>
        <li v-for="(u, i) in form.moderators" :key="u.id">
          <Avatar :user="u" :size="32" />
          <span class="nm">{{ u.name }}</span>
          <button type="button" class="link danger" @click="form.moderators.splice(i, 1)">{{ t.__('Remove', 'membuild-community') }}</button>
        </li>
      </ul>
      <div class="finder">
        <input v-model="search" type="search" :placeholder="t.__('Add a moderator: search members', 'membuild-community')" :aria-label="t.__('Search members', 'membuild-community')" />
        <ul v-if="found.length" class="found card">
          <li v-for="u in found" :key="u.id">
            <button type="button" @click="addModerator(u)"><Avatar :user="u" :size="28" /> {{ u.name }}</button>
          </li>
        </ul>
      </div>
    </div>

    <fieldset class="field">
      <legend class="label">{{ t.__('Who can post in the feed', 'membuild-community') }}</legend>
      <label class="radio"><input v-model="form.posting" type="radio" value="everyone" /> {{ t.__('All members', 'membuild-community') }}</label>
      <label class="radio"><input v-model="form.posting" type="radio" value="staff" /> {{ t.__('Only admins and moderators', 'membuild-community') }} <small>{{ t.__('Members can still comment and react.', 'membuild-community') }}</small></label>
    </fieldset>

    <fieldset class="field" :disabled="!form.features.messages">
      <legend class="label">{{ t.__('Who members can message', 'membuild-community') }}</legend>
      <label class="radio"><input v-model="form.messaging" type="radio" value="everyone" /> {{ t.__('Anyone in the community', 'membuild-community') }}</label>
      <label class="radio"><input v-model="form.messaging" type="radio" value="staff" /> {{ t.__('Only admins and moderators', 'membuild-community') }} <small>{{ t.__('Admins and moderators can still message anyone.', 'membuild-community') }}</small></label>
    </fieldset>
  </section>
</template>
