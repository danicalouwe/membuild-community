<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import BaseDialog from './BaseDialog.vue'
import { t } from '../lib/i18n.js'

// Admins, on a member's profile: which access groups they're in. Groups a role
// already puts them in are shown ticked and locked, since the role decides.
const props = defineProps({ member: { type: Object, required: true } })
const emit = defineEmits(['close'])

const groups = ref(null)
const picked = ref(new Set())
const saving = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    groups.value = (await api.memberGroups(props.member.id)).items
    picked.value = new Set(groups.value.filter((g) => g.manual).map((g) => g.id))
  } catch (e) {
    error.value = e.message
  }
})

function toggle(id, on) {
  const next = new Set(picked.value)
  on ? next.add(id) : next.delete(id)
  picked.value = next
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    await api.setMemberGroups(props.member.id, [...picked.value])
    emit('close')
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}
const intro = (name) => t.sprintf(/* translators: %s: member's name */ t.__('Courses for these groups open for %s.', 'membuild-community'), name)
const viaRole = (role) => t.sprintf(/* translators: %s: WordPress role name */ t.__('through the %s role', 'membuild-community'), role)
</script>

<template>
  <BaseDialog :title="t.__('Member groups', 'membuild-community')" :width="440" @close="emit('close')">
    <form class="body" @submit.prevent="save">
      <p class="intro">{{ intro(member.name) }}</p>
      <p v-if="!groups && !error" class="intro">{{ t.__('Loading…', 'membuild-community') }}</p>
      <template v-else-if="groups">
        <label v-for="g in groups" :key="g.id" class="row" :class="{ on: picked.has(g.id) || g.via_role }">
          <input type="checkbox" :checked="picked.has(g.id) || !!g.via_role" :disabled="!!g.via_role" @change="toggle(g.id, $event.target.checked)" />
          <span class="name">{{ g.name }}</span>
          <small v-if="g.via_role">{{ viaRole(g.via_role) }}</small>
        </label>
        <p v-if="!groups.length" class="intro">
          {{ t.__('There are no member groups yet.', 'membuild-community') }}
          <RouterLink :to="{ name: 'settings', hash: '#s-access' }" @click="emit('close')">{{ t.__('Create one in Settings', 'membuild-community') }}</RouterLink>
        </p>
      </template>
      <p v-if="error" class="err">{{ error }}</p>
      <footer class="foot">
        <button type="button" class="btn" @click="emit('close')">{{ t.__('Cancel', 'membuild-community') }}</button>
        <button type="submit" class="btn btn-primary" :disabled="saving || !groups?.length">{{ saving ? t.__('Saving…', 'membuild-community') : t.__('Save', 'membuild-community') }}</button>
      </footer>
    </form>
  </BaseDialog>
</template>

<style scoped>
.body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px 18px 18px;
}

.intro {
  margin: 0 0 6px;
  color: var(--text-2);
}

.intro a {
  color: var(--accent-text);
  font-weight: 650;
}

.row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 10px;
  cursor: pointer;
}

.row:hover,
.row.on {
  background: var(--surface-2);
}

.name {
  font-weight: 650;
}

.row small {
  margin-left: auto;
  color: var(--text-3);
}

.err {
  margin: 0;
  color: var(--danger);
}

.foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}
</style>
