<script setup>
import { onMounted, ref } from 'vue'
import { api } from '../api.js'
import BaseDialog from './BaseDialog.vue'
import { t } from '../lib/i18n.js'

// Admins, on a member's profile: a switch for each membership. Turning one on opens
// its courses for them right away; off closes them again (progress is kept).
// A membership their WordPress role gives them stays on: the role decides.
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
const first = () => props.member.name.split(' ')[0]
const title = () => t.sprintf(/* translators: %s: member's name */ t.__('%s’s memberships', 'membuild-community'), props.member.name)
const intro = () => t.sprintf(/* translators: %s: member's first name */ t.__('Turn one on to open its courses for %s right away. Turning it off closes them again; their progress is kept.', 'membuild-community'), first())
const coursesOf = (n) => t.sprintf(/* translators: %d: number of courses */ t._n('%d course', '%d courses', n, 'membuild-community'), n)
const month = (iso) => new Date(iso).toLocaleDateString(undefined, { month: 'short', year: 'numeric' })
function line(g) {
  const parts = [coursesOf(g.courses || 0)]
  if (g.via_role) parts.push(t.sprintf(/* translators: %s: WordPress role name */ t.__('through the %s role', 'membuild-community'), g.via_role))
  else if (g.manual && g.since) parts.push(t.sprintf(/* translators: 1: first name, 2: month and year */ t.__('%1$s has had it since %2$s', 'membuild-community'), first(), month(g.since)))
  return parts.join(' · ')
}
</script>

<template>
  <BaseDialog :label="title()" :width="580" style="border-radius: 24px" @close="emit('close')">
    <template #header><h2 class="title">{{ title() }}</h2></template>
    <form class="body" @submit.prevent="save">
      <p class="intro">{{ intro() }}</p>
      <p v-if="!groups && !error" class="intro">{{ t.__('Loading…', 'membuild-community') }}</p>
      <template v-else-if="groups">
        <ul v-if="groups.length" class="rows">
          <li v-for="g in groups" :key="g.id">
            <label class="row">
              <span class="what">
                <strong>{{ g.name }}</strong>
                <small>{{ line(g) }}</small>
              </span>
              <input type="checkbox" class="switch" role="switch" :checked="picked.has(g.id) || !!g.via_role" :disabled="!!g.via_role" @change="toggle(g.id, $event.target.checked)" />
            </label>
          </li>
        </ul>
        <p v-else class="intro">
          {{ t.__('There are no memberships yet.', 'membuild-community') }}
          <RouterLink :to="{ name: 'settings', hash: '#s-access' }" @click="emit('close')">{{ t.__('Make one in Settings', 'membuild-community') }}</RouterLink>
        </p>
      </template>
      <p v-if="error" class="error-text">{{ error }}</p>
      <footer class="foot">
        <button type="button" class="btn btn-lg btn-ghost" @click="emit('close')">{{ t.__('Cancel', 'membuild-community') }}</button>
        <button type="submit" class="btn btn-lg btn-primary" :disabled="saving || !groups?.length">{{ saving ? t.__('Saving…', 'membuild-community') : t.__('Save changes', 'membuild-community') }}</button>
      </footer>
    </form>
  </BaseDialog>
</template>

<style scoped>
.title {
  margin: 0;
  padding: 32px 32px 0;
  font-size: 24px;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 14px 32px 28px;
}

.intro {
  margin: 0 0 8px;
  color: var(--text-2);
  font-size: 16px;
  line-height: 1.55;
}

.intro a {
  color: var(--accent-text);
  font-weight: 650;
}

.rows {
  margin: 0;
  padding: 0;
  list-style: none;
}

.rows li {
  border-top: 1px solid var(--border);
}

.row {
  display: flex;
  align-items: center;
  gap: 16px;
  min-height: 80px;
  cursor: pointer;
}

.what {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 4px;
}

.what strong {
  font-size: 17px;
}

.what small {
  color: var(--text-2);
  font-size: 15px;
}

.switch {
  appearance: none;
  flex: none;
  position: relative;
  width: 52px;
  height: 30px;
  margin: 0;
  border-radius: 999px;
  background: var(--border-strong);
  cursor: pointer;
  transition: background 0.15s;
}

.switch::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgb(0 0 0 / 25%);
  transition: transform 0.15s;
}

.switch:checked {
  background: var(--accent);
}

.switch:checked::after {
  transform: translateX(22px);
}

.switch:disabled {
  opacity: 0.6;
  cursor: default;
}

.switch:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

.foot {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
}

@media (max-width: 600px) {
  .title {
    padding: 24px 20px 0;
  }

  .body {
    padding: 12px 20px 22px;
  }
}
</style>
