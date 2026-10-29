<script setup>
import { ref, watch } from 'vue'
import { api } from '../../api.js'
import { cfg, levelLabel } from '../../config.js'
import { askConfirm } from '../../lib/confirm.js'
import { useLatest } from '../../lib/useLatest.js'
import Avatar from '../Avatar.vue'
import BaseDialog from '../BaseDialog.vue'
import Icon from '../Icon.vue'
import { t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// Who helps run the community (moderators), and who can post and message.
const { form, meta } = useSettings()

/* + Add a moderator: type a name, pick one person, then make them a moderator. */
const adding = ref(false)
const search = ref('')
const found = ref([])
const pick = ref(null)
const latest = useLatest()
let searchTimer = null
watch(search, (q) => {
  clearTimeout(searchTimer)
  latest() // a search still on its way no longer counts
  pick.value = null
  if (!q.trim()) return (found.value = [])
  searchTimer = setTimeout(async () => {
    const current = latest()
    const res = await api.people(q.trim()).catch(() => ({ items: [] }))
    if (!current()) return
    const taken = new Set([...form.value.moderators.map((m) => m.id), ...meta.value.built_in_staff.map((m) => m.id)])
    found.value = res.items.filter((u) => !taken.has(u.id)).slice(0, 6)
    pick.value = found.value[0] || null
  }, 250)
})
function openAdd() {
  search.value = ''
  found.value = []
  pick.value = null
  adding.value = true
}
function addModerator() {
  if (!pick.value) return
  form.value.moderators.push(pick.value)
  adding.value = false
}
const first = (u) => (u?.name || '').split(' ')[0]

/* Remove: our own box, saying what changes for them. */
async function remove(i) {
  const u = form.value.moderators[i]
  const yes = await askConfirm({
    title: t.sprintf(/* translators: %s: a member's name */ t.__('Remove %s as a moderator?', 'membuild-community'), u.name),
    text: t.__('They stay a member and keep everything they’ve posted. They can’t review reports, take posts down or mute people any more. You can make them a moderator again any time.', 'membuild-community'),
    confirm: t.__('Remove as moderator', 'membuild-community'),
    danger: true,
  })
  if (yes) form.value.moderators.splice(i, 1)
}

const joined = (u) => (u.joined_at ? new Intl.DateTimeFormat(undefined, { month: 'short', year: 'numeric' }).format(new Date(u.joined_at)) : '')
</script>

<template>
  <section id="s-permissions" class="card group">
    <h2>{{ t.__('Who can do what', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Who helps you run the community, and who can post and message.', 'membuild-community') }}</p>

    <div class="field">
      <span class="label">{{ t.__('Moderators', 'membuild-community') }}</span>
      <small>{{ t.__('They review reports, remove posts and comments, pin posts, and mute or ban members.', 'membuild-community') }}</small>
      <ul class="mods">
        <li v-for="u in meta.built_in_staff" :key="`b${u.id}`">
          <Avatar :user="u" :size="40" />
          <span class="who"><strong>{{ u.name }}</strong><small>{{ u.id === cfg.user.id ? t.__('You · Admin', 'membuild-community') : t.__('Admin or editor', 'membuild-community') }}</small></span>
        </li>
        <li v-for="(u, i) in form.moderators" :key="u.id">
          <Avatar :user="u" :size="40" />
          <span class="who"><strong>{{ u.name }}</strong><small>{{ t.__('Moderator', 'membuild-community') }}</small></span>
          <button type="button" class="btn" @click="remove(i)">{{ t.__('Remove', 'membuild-community') }}</button>
        </li>
      </ul>
      <button type="button" class="btn add" @click="openAdd"><Icon name="plus" :size="18" /> {{ t.__('Add a moderator', 'membuild-community') }}</button>
    </div>

    <hr class="rule" />

    <fieldset class="field">
      <legend class="label">{{ t.__('Who can post in the feed', 'membuild-community') }}</legend>
      <div class="choices cols-2">
        <label class="choice" :class="{ on: form.posting === 'everyone' }"><input v-model="form.posting" type="radio" name="posting" value="everyone" /><span class="mark" /><strong>{{ t.__('All members', 'membuild-community') }}</strong></label>
        <label class="choice" :class="{ on: form.posting === 'staff' }"><input v-model="form.posting" type="radio" name="posting" value="staff" /><span class="mark" /><strong>{{ t.__('Only admins and moderators', 'membuild-community') }}</strong></label>
      </div>
      <small>{{ t.__('Members can still comment and react.', 'membuild-community') }}</small>
    </fieldset>

    <hr class="rule" />

    <fieldset class="field" :disabled="!form.features.messages">
      <legend class="label">{{ t.__('Who members can message', 'membuild-community') }}</legend>
      <div class="choices cols-2">
        <label class="choice" :class="{ on: form.messaging === 'everyone' }"><input v-model="form.messaging" type="radio" name="messaging" value="everyone" /><span class="mark" /><strong>{{ t.__('Anyone in the community', 'membuild-community') }}</strong></label>
        <label class="choice" :class="{ on: form.messaging === 'staff' }"><input v-model="form.messaging" type="radio" name="messaging" value="staff" /><span class="mark" /><strong>{{ t.__('Only admins and moderators', 'membuild-community') }}</strong></label>
      </div>
      <small>{{ t.__('Admins and moderators can always message anyone.', 'membuild-community') }}</small>
    </fieldset>

    <BaseDialog v-if="adding" :label="t.__('Add a moderator', 'membuild-community')" :width="480" @close="adding = false">
      <template #header><h2 class="dlg-title">{{ t.__('Add a moderator', 'membuild-community') }}</h2></template>
      <div class="dlg">
        <p class="dlg-text">{{ t.__('Moderators review reports, take posts down and mute or ban members. Pick someone you trust.', 'membuild-community') }}</p>
        <label class="dlg-search"><Icon name="search" :size="18" /><input v-model="search" type="search" :placeholder="t.__('Type a name', 'membuild-community')" :aria-label="t.__('Search members', 'membuild-community')" autofocus /></label>
        <div v-if="found.length" class="people" role="radiogroup">
          <label v-for="u in found" :key="u.id" class="person" :class="{ on: pick?.id === u.id }">
            <input v-model="pick" type="radio" name="mod" :value="u" />
            <Avatar :user="u" :size="40" />
            <span class="who"><strong>{{ u.name }}</strong><small>{{ [levelLabel(u.level || 1), joined(u) && t.sprintf(/* translators: %s: month and year */ t.__('joined %s', 'membuild-community'), joined(u))].filter(Boolean).join(' · ') }}</small></span>
            <span class="dot" />
          </label>
        </div>
        <p v-else-if="search.trim()" class="dlg-text">{{ t.__('Nobody by that name yet.', 'membuild-community') }}</p>
        <div class="dlg-act">
          <button type="button" class="btn" @click="adding = false">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button type="button" class="btn btn-primary" :disabled="!pick" @click="addModerator">{{ pick ? t.sprintf(/* translators: %s: a first name */ t.__('Make %s a moderator', 'membuild-community'), first(pick)) : t.__('Make them a moderator', 'membuild-community') }}</button>
        </div>
      </div>
    </BaseDialog>
  </section>
</template>

<style scoped>
.mods {
  margin: 8px 0 14px;
  padding: 0;
  list-style: none;
}

.mods li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid var(--border);
}

.who {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.who strong {
  font-size: var(--fs-lg);
}

.add {
  align-self: flex-start;
}

.dlg-title {
  margin: 0;
  padding: 26px 26px 0;
  font-size: var(--fs-4xl);
}

.dlg {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 10px 26px 24px;
}

.dlg-text {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.5;
}

.dlg-search {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 52px;
  padding: 0 14px;
  border: 2px solid var(--accent);
  border-radius: var(--radius-md);
}

.dlg-search input {
  flex: 1;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
  outline: none;
}

.people {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.person {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  cursor: pointer;
}

.person input {
  position: absolute;
  opacity: 0;
}

.person.on {
  border: 2px solid var(--accent);
  padding: 9px 11px;
  background: var(--accent-soft);
}

.dot {
  width: 20px;
  height: 20px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
}

.person.on .dot {
  border-color: var(--text);
  background: radial-gradient(circle, var(--surface) 0 28%, var(--text) 31%);
}

.dlg-act {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 4px;
}

.dlg-act .btn {
  min-height: 52px;
  padding: 0 22px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--text);
  font-size: var(--fs-lg);
  font-weight: 700;
}

.dlg-act .btn-primary {
  background: var(--accent);
  color: var(--accent-ink);
}
</style>
