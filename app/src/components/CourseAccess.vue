<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import { t } from '../lib/i18n.js'

// The "Who can open it" controls in the course editor: everyone, from a level up,
// or chosen access groups, plus whether locked members see the course at all.
const props = defineProps({
  course: { type: Object, required: true },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['save'])

const groups = ref(null) // loaded once, when "Access groups" is first needed
const loadError = ref('')

async function loadGroups() {
  if (groups.value) return
  try {
    groups.value = (await api.groups()).items
  } catch (e) {
    loadError.value = e.message
  }
}
onMounted(() => props.course.access === 'groups' && loadGroups())

function setMode(mode) {
  if (mode === 'groups') loadGroups()
  const patch = { access: mode }
  if (mode === 'level' && !(props.course.unlock_level > 1)) patch.unlock_level = 2
  emit('save', patch)
}

function toggleGroup(id, on) {
  const ids = new Set(props.course.access_groups || [])
  on ? ids.add(id) : ids.delete(id)
  emit('save', { access_groups: [...ids] })
}
const levelAndUp = (level) => t.sprintf(/* translators: %d: level number */ t.__('Level %d and up', 'membuild-community'), level)
</script>

<template>
  <fieldset class="access" :disabled="busy">
    <legend>{{ t.__('Who can open it', 'membuild-community') }}</legend>
    <select :value="course.access" :aria-label="t.__('Who can open it', 'membuild-community')" @change="setMode($event.target.value)">
      <option value="everyone">{{ t.__('Everyone in the community', 'membuild-community') }}</option>
      <option value="level">{{ t.__('Members from a level up', 'membuild-community') }}</option>
      <option value="groups">{{ t.__('Members of chosen groups', 'membuild-community') }}</option>
    </select>

    <select v-if="course.access === 'level'" :value="Math.max(2, course.unlock_level)" :aria-label="t.__('Level needed', 'membuild-community')" @change="emit('save', { unlock_level: Number($event.target.value) })">
      <option v-for="n in 8" :key="n" :value="n + 1">{{ levelAndUp(n + 1) }}</option>
    </select>

    <div v-if="course.access === 'groups'" class="groups">
      <p v-if="loadError" class="note">{{ loadError }}</p>
      <p v-else-if="!groups" class="note">{{ t.__('Loading…', 'membuild-community') }}</p>
      <template v-else>
        <label v-for="g in groups" :key="g.id" class="check">
          <input type="checkbox" :checked="(course.access_groups || []).includes(g.id)" @change="toggleGroup(g.id, $event.target.checked)" />
          <span>{{ g.name }}</span>
        </label>
        <p v-if="!groups.length" class="note">{{ t.__('No member groups yet.', 'membuild-community') }}</p>
        <p v-else-if="!(course.access_groups || []).length" class="note warn">{{ t.__('Pick at least one group. Until then only admins and moderators can open this course.', 'membuild-community') }}</p>
        <RouterLink :to="{ name: 'settings', hash: '#s-access' }" class="manage">{{ groups.length ? t.__('Manage member groups', 'membuild-community') : t.__('Create a member group', 'membuild-community') }}</RouterLink>
      </template>
    </div>

    <template v-if="course.access === 'groups'">
      <label class="sub">
        <span>{{ t.__('“How to get access” button (optional)', 'membuild-community') }}</span>
        <input :value="course.access_cta?.label" type="text" maxlength="60" :placeholder="t.__('e.g. Join the VIP plan', 'membuild-community')" @change="emit('save', { access_cta_label: $event.target.value })" />
        <input :value="course.access_cta?.url" type="url" placeholder="https://" :aria-label="t.__('Button link', 'membuild-community')" @change="emit('save', { access_cta_url: $event.target.value })" />
        <small>{{ t.__('Shown to members who can’t open the course yet, for example a link to your checkout page.', 'membuild-community') }}</small>
      </label>
    </template>

    <label v-if="course.access !== 'everyone'" class="check hide">
      <input type="checkbox" :checked="course.hide_locked" @change="emit('save', { hide_locked: $event.target.checked })" />
      <span>{{ t.__("Hide it from members who can't open it", 'membuild-community') }}</span>
    </label>
  </fieldset>
</template>

<style scoped>
.access {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 8px 0 0;
  padding: 0;
  border: 0;
  min-width: 0;
}

legend {
  margin-bottom: 4px;
  padding: 0;
  font-size: 13px;
  font-weight: 650;
  color: var(--text-2);
}

select {
  height: 36px;
  padding: 0 10px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface-2);
  color: var(--text);
  font: inherit;
  font-size: 14px;
}

.groups {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 6px 10px;
  border-radius: 8px;
  background: var(--surface-2);
}

.check {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 3px 0;
  font-size: 14px;
  cursor: pointer;
}

.hide {
  color: var(--text-2);
  font-size: 13px;
}

.sub {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
  color: var(--text-2);
}

.sub input {
  height: 36px;
  padding: 0 10px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface-2);
  color: var(--text);
  font: inherit;
  font-size: 14px;
}

.sub small {
  color: var(--text-3);
}

.note {
  margin: 2px 0;
  font-size: 13px;
  color: var(--text-3);
}

.note.warn {
  color: var(--danger);
}

.manage {
  margin-top: 4px;
  font-size: 13px;
  font-weight: 650;
  color: var(--accent-text);
}

fieldset:disabled {
  opacity: 0.6;
}
</style>
