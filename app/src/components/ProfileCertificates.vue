<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import { askConfirm } from '../lib/confirm.js'
import { shortDay } from '../lib/certificatePdf.js'
import { useMenu } from '../lib/useMenu.js'
import { showToast } from '../lib/toast.js'
import { t } from '../lib/i18n.js'
import CertificateDialog from './CertificateDialog.vue'
import Icon from './Icon.vue'

/**
 * A profile's Certificates: the courses they finished, each with View (the opened certificate).
 * Owners and moderators also get ⋯ with Remove certificate, which asks first; a removed one
 * never comes back on its own and its check link says "Not found or removed".
 * Hidden when there are none, or when certificates aren't shown on profiles (the server decides:
 * a member always sees their own, and staff see everyone's).
 */
const props = defineProps({ member: { type: Object, required: true } })

const items = ref([])
async function load() {
  items.value = []
  try {
    items.value = (await api.memberCertificates(props.member.id)).items || []
  } catch {
    // No certificates shown is fine.
  }
}
watch(() => props.member.id, load, { immediate: true })

const open = ref(null)
const finished = (c) => t.sprintf(/* translators: %s: a date */ t.__('Finished %s', 'membuild-community'), shortDay(c.finished_on))

const menuFor = ref(null)
const { open: menu, root: menuRoot, trigger: menuTrigger, list: menuList, toggle, close: closeMenu, onKeydown } = useMenu()
watch(menu, (o) => !o && (menuFor.value = null))
function toggleFor(e, c) {
  if (menu.value && menuFor.value !== c.id) closeMenu({ focus: false })
  menuFor.value = c.id
  setTimeout(() => toggle(e))
}
const firstName = computed(() => props.member.name)
async function remove(c) {
  closeMenu({ focus: false })
  const ok = await askConfirm({
    title: t.sprintf(/* translators: %s: member's name */ t.__('Remove %s’s certificate?', 'membuild-community'), firstName.value),
    text: t.sprintf(/* translators: %s: course title */ t.__('The certificate for %s goes from their profile, and its check link will say it was removed.', 'membuild-community'), c.course),
    note: t.__('Their lessons and quiz results stay. The certificate won’t come back on its own.', 'membuild-community'),
    plainNote: true,
    confirm: t.__('Remove', 'membuild-community'),
    cancel: t.__('Keep it', 'membuild-community'),
    danger: true,
  })
  if (!ok) return
  try {
    await api.removeCertificate(c.id)
    items.value = items.value.filter((x) => x.id !== c.id)
    showToast(t.__('Certificate removed', 'membuild-community'))
  } catch (err) {
    showToast(err.message)
  }
}
</script>

<template>
  <section v-if="items.length" class="card box pc">
    <h2>{{ t.__('Certificates', 'membuild-community') }} <span class="pc-count">{{ items.length }}</span></h2>
    <ul class="pc-list">
      <li v-for="c in items" :key="c.id" class="pc-row">
        <span class="pc-icon"><Icon name="award" :size="20" /></span>
        <span class="pc-text"><strong>{{ c.course }}</strong><small>{{ finished(c) }}</small></span>
        <button v-if="!c.can_remove" class="btn btn-ghost" type="button" @click="open = c">{{ t.__('View', 'membuild-community') }}</button>
        <span v-else :ref="(el) => menuFor === c.id && menuRoot(el)" class="pc-more">
          <button :ref="(el) => menuFor === c.id && menuTrigger(el)" class="round-btn tap-44" type="button" :aria-label="t.sprintf(t.__('Certificate for %s', 'membuild-community'), c.course)" aria-haspopup="menu" :aria-expanded="menu && menuFor === c.id" @click="toggleFor($event, c)"><Icon name="more" :size="20" /></button>
          <span v-if="menu && menuFor === c.id" :ref="menuList" class="pc-menu" role="menu" @keydown="onKeydown">
            <button role="menuitem" type="button" @click="closeMenu({ focus: false }); open = c"><Icon name="eye" :size="18" /> {{ t.__('View', 'membuild-community') }}</button>
            <button role="menuitem" type="button" class="danger" @click="remove(c)"><Icon name="trash" :size="18" /> {{ t.__('Remove certificate', 'membuild-community') }}</button>
          </span>
        </span>
      </li>
    </ul>
    <CertificateDialog v-if="open" :cert="open" @close="open = null" />
  </section>
</template>

<style scoped>
.pc h2 {
  display: flex;
  margin: 0 0 14px;
  font-size: var(--fs-3xl);
  align-items: center;
  gap: 8px;
}
.pc-count {
  color: var(--text-2);
  font-size: var(--fs-lg);
  font-weight: 650;
}
.pc-list {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
}
.pc-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid var(--border);
}
.pc-row:first-child {
  border-top: 0;
  padding-top: 0;
}
.pc-icon {
  display: grid;
  flex: none;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-sm);
  background: var(--accent-soft);
  color: var(--accent-text);
}
.pc-text {
  flex: 1;
  min-width: 0;
}
.pc-text strong {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pc-text small {
  display: block;
  color: var(--text-2);
  font-size: var(--fs-md);
}
.pc-more {
  position: relative;
  flex: none;
}
.pc-more .round-btn {
  background: none;
}
.pc-more .round-btn:hover {
  background: var(--surface-2);
}
.pc-menu {
  position: absolute;
  top: 100%;
  right: 0;
  z-index: 10;
  display: flex;
  flex-direction: column;
  min-width: 210px;
  padding: 6px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  box-shadow: var(--shadow-pop);
}
.pc-menu button {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 8px 10px;
  border-radius: var(--radius-xs);
  color: var(--text);
  font-weight: 600;
  text-align: left;
  white-space: nowrap;
}
.pc-menu button:hover {
  background: var(--surface-2);
}
.pc-menu .danger {
  color: var(--danger-text);
}
</style>
