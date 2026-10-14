<script setup>
import { onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

// Settings → Access groups. Changes save right away (they're not part of the
// settings form): create, rename, link roles, delete, and add or remove members.
const groups = ref([])
const roles = ref([])
const loaded = ref(false)
const error = ref('')
const newName = ref('')
const open = ref(null) // id of the group whose members are shown
const members = ref([])
const search = ref('')
const found = ref([])

async function run(fn) {
  error.value = ''
  try {
    return await fn()
  } catch (e) {
    error.value = e.message
  }
}

async function load() {
  await run(async () => {
    const res = await api.groups()
    groups.value = res.items
    roles.value = res.roles
  })
  loaded.value = true
}
onMounted(load)

function replace(group) {
  const i = groups.value.findIndex((g) => g.id === group.id)
  if (i >= 0) groups.value[i] = group
}

async function create() {
  const name = newName.value.trim()
  if (!name) return
  const group = await run(() => api.createGroup({ name }))
  if (group) {
    groups.value = [...groups.value, group].sort((a, b) => a.name.localeCompare(b.name))
    newName.value = ''
  }
}

async function rename(group, name) {
  if (name.trim() && name.trim() !== group.name) replace(await run(() => api.updateGroup(group.id, { name: name.trim() })) || group)
}

async function toggleRole(group, key, on) {
  const next = on ? [...group.roles, key] : group.roles.filter((r) => r !== key)
  replace((await run(() => api.updateGroup(group.id, { roles: next }))) || group)
}

async function remove(group) {
  const courses = group.course_count
    ? t.sprintf(/* translators: %d: number of courses */ t._n('It opens %d course; members who only had access through this group will lose it.', 'It opens %d courses; members who only had access through this group will lose it.', group.course_count, 'membuild-community'), group.course_count)
    : ''
  if (!confirm(`${t.sprintf(/* translators: %s: group name */ t.__('Delete the member group “%s”?', 'membuild-community'), group.name)} ${courses}`)) return
  if (await run(() => api.deleteGroup(group.id))) {
    groups.value = groups.value.filter((g) => g.id !== group.id)
    if (open.value === group.id) open.value = null
  }
}

async function showMembers(group) {
  if (open.value === group.id) {
    open.value = null
    return
  }
  open.value = group.id
  members.value = []
  search.value = ''
  found.value = []
  members.value = (await run(() => api.groupMembers(group.id)))?.items || []
}

let timer
watch(search, (q) => {
  clearTimeout(timer)
  if (!q.trim()) {
    found.value = []
    return
  }
  timer = setTimeout(async () => {
    const res = await run(() => api.members({ search: q.trim() }))
    const inGroup = new Set(members.value.map((m) => m.id))
    found.value = (res?.items || []).filter((m) => !inGroup.has(m.id)).slice(0, 6)
  }, 250)
})

async function add(group, member) {
  const res = await run(() => api.addToGroup(group.id, [member.id]))
  if (res) {
    members.value = res.items
    found.value = found.value.filter((m) => m.id !== member.id)
    group.member_count = res.items.length
  }
}

async function drop(group, member) {
  const res = await run(() => api.removeFromGroup(group.id, member.id))
  if (res) {
    members.value = res.items
    group.member_count = res.items.length
  }
}

const memberCount = (n) => t.sprintf(/* translators: %d: number of members */ t._n('%d member', '%d members', n, 'membuild-community'), n)
const deleteLabel = (name) => t.sprintf(/* translators: %s: group name */ t.__('Delete %s', 'membuild-community'), name)
const opensCourses = (n) => t.sprintf(/* translators: %d: number of courses */ t._n('Opens %d course', 'Opens %d courses', n, 'membuild-community'), n)
const alsoRoles = (roles) => t.sprintf(/* translators: %s: WordPress role names, e.g. "Subscriber, Customer" */ t.__('Also everyone with the role: %s', 'membuild-community'), roles)
const removeLabel = (name) => t.sprintf(/* translators: %s: member's name */ t.__('Remove %s', 'membuild-community'), name)
</script>

<template>
  <div class="access-groups">
    <p v-if="error" class="err" role="alert">{{ error }}</p>

    <ul v-if="groups.length" class="list">
      <li v-for="g in groups" :key="g.id" class="group">
        <div class="row">
          <input class="name" :value="g.name" maxlength="80" :aria-label="t.__('Group name', 'membuild-community')" @change="rename(g, $event.target.value)" />
          <button class="btn btn-ghost small" :aria-expanded="open === g.id" @click="showMembers(g)">
            <Icon name="users" :size="15" />
            {{ memberCount(g.member_count) }}
          </button>
          <button class="icon-btn" :aria-label="deleteLabel(g.name)" @click="remove(g)"><Icon name="trash" :size="16" /></button>
        </div>
        <p class="meta">
          {{ g.course_count ? opensCourses(g.course_count) : t.__('Not used by any course yet', 'membuild-community') }}
        </p>

        <details class="roles">
          <summary>
            {{ g.role_names.length ? alsoRoles(g.role_names.join(', ')) : t.__('Link a WordPress role (optional)', 'membuild-community') }}
          </summary>
          <p class="hint">{{ t.__('Members with a linked role are in this group automatically, and leave it when the role is removed. Membership and shop plugins usually give buyers a role, so linking it connects them to your classroom.', 'membuild-community') }}</p>
          <label v-for="r in roles" :key="r.key" class="check">
            <input type="checkbox" :checked="g.roles.includes(r.key)" @change="toggleRole(g, r.key, $event.target.checked)" />
            {{ r.name }}
          </label>
        </details>

        <div v-if="open === g.id" class="members">
          <label class="search">
            <Icon name="search" :size="16" />
            <input v-model="search" type="search" :placeholder="t.__('Add a member by name', 'membuild-community')" :aria-label="t.__('Add a member by name', 'membuild-community')" />
          </label>
          <ul v-if="found.length" class="people found">
            <li v-for="m in found" :key="m.id">
              <Avatar :user="m" :size="28" /> <span>{{ m.name }}</span>
              <button class="btn btn-primary small" @click="add(g, m)">{{ t.__('Add', 'membuild-community') }}</button>
            </li>
          </ul>
          <ul v-if="members.length" class="people">
            <li v-for="m in members" :key="m.id">
              <Avatar :user="m" :size="28" /> <span>{{ m.name }}</span>
              <button class="icon-btn" :aria-label="removeLabel(m.name)" @click="drop(g, m)"><Icon name="close" :size="16" /></button>
            </li>
          </ul>
          <p v-else class="hint">{{ g.roles.length ? t.__('Nobody added by hand yet. Members with the linked role are in the group anyway.', 'membuild-community') : t.__('Nobody in this group yet.', 'membuild-community') }}</p>
        </div>
      </li>
    </ul>
    <p v-else-if="loaded" class="hint">{{ t.__('No member groups yet. Create one, add members, then pick it in a course’s “Who can open it”.', 'membuild-community') }}</p>

    <form class="create" @submit.prevent="create">
      <input v-model="newName" maxlength="80" :placeholder="t.__('New group, e.g. VIP or October cohort', 'membuild-community')" :aria-label="t.__('New group name', 'membuild-community')" />
      <button class="btn btn-primary" :disabled="!newName.trim()">{{ t.__('Create group', 'membuild-community') }}</button>
    </form>
  </div>
</template>

<style scoped>
.list,
.people {
  list-style: none;
  margin: 0;
  padding: 0;
}

.group {
  padding: 12px 0;
  border-top: 1px solid var(--border);
}

.row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.name {
  flex: 1;
  min-width: 0;
  height: 36px;
  padding: 0 10px;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: var(--text);
  font: inherit;
  font-weight: 650;
}

.name:hover,
.name:focus {
  border-color: var(--border);
  background: var(--surface-2);
  outline: none;
}

.meta,
.hint {
  margin: 2px 10px;
  font-size: 13px;
  color: var(--text-3);
}

.roles {
  margin: 6px 10px 0;
  font-size: 13px;
}

.roles summary {
  cursor: pointer;
  color: var(--text-2);
  font-weight: 600;
}

.roles .hint {
  margin: 6px 0;
}

.check {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 2px 14px 2px 0;
}

.members {
  margin: 10px 10px 0;
  padding: 10px;
  border-radius: 10px;
  background: var(--surface-2);
}

.search {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 36px;
  padding: 0 12px;
  border-radius: 999px;
  background: var(--surface);
  color: var(--text-3);
}

/* The field inside has no outline of its own, so the pill shows focus. */
.search:focus-within {
  box-shadow: 0 0 0 2px var(--accent);
}

.search input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: transparent;
  color: var(--text);
  font: inherit;
  outline: none;
}

.people li {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 2px;
}

.people span {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.found {
  margin: 6px 0;
  padding-bottom: 6px;
  border-bottom: 1px solid var(--border);
}

.create {
  display: flex;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.create input {
  flex: 1;
  min-width: 0;
  height: 38px;
  padding: 0 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface-2);
  color: var(--text);
  font: inherit;
}

.btn.small {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 10px;
  font-size: 13px;
}

.icon-btn {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  color: var(--text-2);
}

.icon-btn:hover {
  background: var(--surface-2);
}

.err {
  margin: 0 0 8px;
  color: var(--danger);
}
</style>
