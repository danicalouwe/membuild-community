<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import Avatar from './Avatar.vue'
import BaseDialog from './BaseDialog.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import Icon from './Icon.vue'
import { offerButton } from '../lib/offer.js'
import { t } from '../lib/i18n.js'

/**
 * Settings › Memberships. A membership decides who can open a locked course: the
 * courses it opens, the people the owner added, the WordPress roles that join by
 * themselves, and "How people get it" (one button for all its courses). Everything
 * saves as you go; it isn't part of the settings form.
 */
const groups = ref([])
// Which memberships are open: closed to start (just a name and count each), so a long list
// stays short; with only one, it opens; a new one opens by itself.
const open = ref(new Set())
const isOpen = (g) => open.value.has(g.id)
function toggleOpen(g) {
  const next = new Set(open.value)
  next.has(g.id) ? next.delete(g.id) : next.add(g.id)
  open.value = next
}
const roles = ref([])
const courses = ref([])
const shop = ref(null) // FluentCart's products when it's active: [{ id, label }], else null
const shopLogo = ref('') // FluentCart's own logo, from its plugin
const shopAddress = ref(null) // FluentCart's Checkout fields page, when it still asks for a billing address
const loaded = ref(false)
const error = ref('')

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
    groups.value = res.items.map(withForm)
    if (groups.value.length === 1) open.value = new Set([groups.value[0].id])
    roles.value = res.roles
    courses.value = res.courses
    shop.value = res.fluentcart || null
    shopAddress.value = res.fluentcart_address || null
    shopLogo.value = res.fluentcart_logo || ''
  })
  loaded.value = true
}
onMounted(load)

// Each membership keeps its "How people get it" fields as typed, so clearing the link
// while typing doesn't flip it back to invite only under the owner's hands.
function withForm(g, old) {
  const o = g.offer || {}
  return { ...g, form: old?.form || { label: o.label || t.__('Get access', 'membuild-community'), price: o.price || '', period: o.period || 'once', url: o.url || '' }, selling: old?.selling ?? !!g.offer }
}
function replace(group) {
  if (!group) return
  const i = groups.value.findIndex((g) => g.id === group.id)
  if (i >= 0) groups.value[i] = withForm(group, groups.value[i])
}
const update = async (g, body) => replace(await run(() => api.updateGroup(g.id, body)))

/* ---------- New membership ---------- */
const creating = ref(null) // { name, courses: Set }
const busy = ref(false)
async function create() {
  const name = creating.value.name.trim()
  if (!name) return
  busy.value = true
  const group = await run(() => api.createGroup({ name, course_ids: [...creating.value.courses] }))
  busy.value = false
  if (group) {
    groups.value = [...groups.value, withForm(group)].sort((a, b) => a.name.localeCompare(b.name))
    open.value = new Set([...open.value, group.id])
    creating.value = null
  }
}
function toggleIn(set, id) {
  set.has(id) ? set.delete(id) : set.add(id)
}

/* ---------- Rename ---------- */
const renaming = ref(null) // { group, name }
async function rename() {
  const name = renaming.value.name.trim()
  if (name && name !== renaming.value.group.name) await update(renaming.value.group, { name })
  if (!error.value) renaming.value = null
}

/* ---------- Courses ---------- */
const choosing = ref(null) // { group, courses: Set }
async function saveCourses() {
  await update(choosing.value.group, { course_ids: [...choosing.value.courses] })
  if (!error.value) choosing.value = null
}
const dropCourse = (g, id) => update(g, { course_ids: g.courses.map((c) => c.id).filter((c) => c !== id) })

/* ---------- Roles ---------- */
const rolePicker = ref(0) // id of the membership whose role list is open
const roleName = (key) => roles.value.find((r) => r.key === key)?.name || key
const addRole = (g, key) => {
  rolePicker.value = 0
  return update(g, { roles: [...g.roles, key] })
}
const dropRole = (g, key) => update(g, { roles: g.roles.filter((r) => r !== key) })

/* ---------- People ---------- */
const people = ref(null) // { group, members, search, found }
async function openPeople(g) {
  people.value = { group: g, members: [], search: '', found: [], loading: true }
  const res = await run(() => api.groupMembers(g.id))
  if (people.value) people.value = { ...people.value, members: res?.items || [], loading: false }
}
let timer
watch(
  () => people.value?.search,
  (q) => {
    clearTimeout(timer)
    if (!people.value) return
    if (!q?.trim()) {
      people.value.found = []
      return
    }
    timer = setTimeout(async () => {
      const res = await run(() => api.members({ search: q.trim() }))
      if (!people.value) return
      const inIt = new Set(people.value.members.map((m) => m.id))
      people.value.found = (res?.items || []).filter((m) => !inIt.has(m.id)).slice(0, 6)
    }, 250)
  },
)
async function addPerson(m) {
  const g = people.value.group
  const res = await run(() => api.addToGroup(g.id, [m.id]))
  if (res && people.value) {
    people.value.members = res.items
    people.value.found = people.value.found.filter((x) => x.id !== m.id)
  }
}
async function dropPerson(m) {
  const res = await run(() => api.removeFromGroup(people.value.group.id, m.id))
  if (res && people.value) people.value.members = res.items
}
async function closePeople() {
  people.value = null
  await load() // the counts changed
}

/* ---------- How people get it ---------- */
const saveTimers = {}
function saveOffer(g) {
  clearTimeout(saveTimers[g.id])
  saveTimers[g.id] = setTimeout(() => {
    const f = g.form
    update(g, { cta_label: f.label, price: f.price, period: f.period, cta_url: f.url })
  }, 600)
}
// Sold with FluentCart: picking a product sets the button's link and price on the server.
async function setProduct(g, id) {
  const group = await run(() => api.updateGroup(g.id, { fluentcart: Number(id) || 0 }))
  if (!group) return
  const i = groups.value.findIndex((x) => x.id === group.id)
  if (i >= 0) groups.value[i] = withForm(group) // fresh fields: the product's link and price
}
function setPeriod(g, p) {
  g.form.period = p
  saveOffer(g)
}
const PERIODS = computed(() => [
  ['once', t.__('One-time', 'membuild-community')],
  ['month', t.__('Per month', 'membuild-community')],
  ['year', t.__('Per year', 'membuild-community')],
])

/* ---------- Delete ---------- */
const deleting = ref(null)
async function remove() {
  const g = deleting.value
  busy.value = true
  const ok = await run(() => api.deleteGroup(g.id))
  busy.value = false
  if (ok) groups.value = groups.value.filter((x) => x.id !== g.id)
  deleting.value = null
}

const membersOf = (n) => t.sprintf(/* translators: %d: number of members */ t._n('%d member', '%d members', n, 'membuild-community'), n)
const peopleOf = (n) => t.sprintf(/* translators: %d: number of people */ t._n('%d person', '%d people', n, 'membuild-community'), n)
const removeLabel = (name) => t.sprintf(/* translators: %s: a name */ t.__('Remove %s', 'membuild-community'), name)
</script>

<template>
  <div class="ms">
    <div class="ms-head">
      <div>
        <h2>{{ t.__('Memberships', 'membuild-community') }}</h2>
        <p class="intro">{{ t.__('A membership decides who can open a locked course. People get one in two ways: you add them, or your membership tool gives them a role.', 'membuild-community') }}</p>
      </div>
      <button v-if="groups.length" class="btn btn-lg btn-primary" @click="creating = { name: '', courses: new Set() }"><Icon name="plus" :size="18" /> {{ t.__('New membership', 'membuild-community') }}</button>
    </div>

    <p v-if="error" class="error-text" role="alert">{{ error }}</p>

    <div v-if="loaded && !groups.length" class="none">
      <span class="none-icon"><Icon name="lock" :size="26" /></span>
      <strong>{{ t.__('No memberships yet', 'membuild-community') }}</strong>
      <p>{{ t.__('Make one when some courses are only for paying members or a small group. Until then, every course is open to every member.', 'membuild-community') }}</p>
      <button class="btn btn-lg btn-primary" @click="creating = { name: '', courses: new Set() }"><Icon name="plus" :size="18" /> {{ t.__('New membership', 'membuild-community') }}</button>
    </div>

    <section v-for="g in groups" :key="g.id" class="one" :class="{ shut: !isOpen(g) }">
      <header class="one-head">
        <button type="button" class="opener" :aria-expanded="isOpen(g)" :aria-controls="`ms-${g.id}`" @click="toggleOpen(g)">
          <Icon name="chevronDown" :size="20" class="chev" />
          <h3>{{ g.name }} <span>{{ membersOf(g.total) }}</span></h3>
        </button>
        <button v-if="isOpen(g)" class="btn btn-lg btn-ghost" @click="renaming = { group: g, name: g.name }"><Icon name="pencil" :size="18" /> {{ t.__('Rename', 'membuild-community') }}</button>
      </header>
      <div v-show="isOpen(g)" :id="`ms-${g.id}`" class="one-body">

      <div class="part">
        <span class="part-icon"><Icon name="cap" :size="18" /></span>
        <div class="part-body">
          <strong>{{ t.__('Opens these courses', 'membuild-community') }}</strong>
          <div class="chips">
            <span v-for="c in g.courses" :key="c.id" class="chip">{{ c.title }}<button :aria-label="removeLabel(c.title)" @click="dropCourse(g, c.id)"><Icon name="close" :size="14" /></button></span>
            <button class="chip add" @click="choosing = { group: g, courses: new Set(g.courses.map((c) => c.id)) }">+ {{ t.__('Choose courses', 'membuild-community') }}</button>
          </div>
        </div>
      </div>

      <div class="part">
        <span class="part-icon"><Icon name="users" :size="18" /></span>
        <div class="part-body">
          <strong>{{ t.__('People you added', 'membuild-community') }}</strong>
          <p>{{ peopleOf(g.member_count) }}</p>
          <button class="btn btn-lg btn-ghost" @click="openPeople(g)">{{ t.__('Add or remove people', 'membuild-community') }}</button>
        </div>
      </div>

      <div class="part">
        <span class="part-icon"><Icon name="link" :size="18" /></span>
        <div class="part-body">
          <strong>{{ t.__('Joins automatically with a role', 'membuild-community') }}</strong>
          <p>{{ t.__('Use this when WP Fusion, FluentCRM or your membership plugin gives buyers a WordPress role.', 'membuild-community') }}</p>
          <div class="chips">
            <span v-for="r in g.roles" :key="r" class="chip">{{ roleName(r) }}<button :aria-label="removeLabel(roleName(r))" @click="dropRole(g, r)"><Icon name="close" :size="14" /></button></span>
            <span class="pick">
              <button class="chip add" :aria-expanded="rolePicker === g.id" @click="rolePicker = rolePicker === g.id ? 0 : g.id">+ {{ t.__('Add a role', 'membuild-community') }}</button>
              <span v-if="rolePicker === g.id" class="menu card" role="menu">
                <button v-for="r in roles.filter((x) => !g.roles.includes(x.key))" :key="r.key" role="menuitem" @click="addRole(g, r.key)">{{ r.name }}</button>
              </span>
            </span>
          </div>
        </div>
      </div>

      <div class="part">
        <span class="part-icon"><Icon name="tag" :size="18" /></span>
        <div class="part-body">
          <strong>{{ t.__('How people get it', 'membuild-community') }}</strong>
          <template v-if="g.selling">
            <p>{{ t.__('One button for every course in this membership. Payment happens on your own checkout.', 'membuild-community') }}</p>
            <div class="offer">
              <input v-model="g.form.label" class="field label" maxlength="60" :aria-label="t.__('Button text', 'membuild-community')" @input="saveOffer(g)" />
              <input v-model="g.form.price" class="field price" maxlength="20" placeholder="$49" :aria-label="t.__('Price', 'membuild-community')" @input="saveOffer(g)" />
            </div>
            <div class="periods" role="radiogroup" :aria-label="t.__('How often they pay', 'membuild-community')">
              <button v-for="[key, label] in PERIODS" :key="key" role="radio" :aria-checked="g.form.period === key" :class="{ on: g.form.period === key }" @click="setPeriod(g, key)">{{ label }}</button>
            </div>
            <div v-if="shop" class="shop">
              <div class="shop-head">
                <img v-if="shopLogo" class="shop-logo" :src="shopLogo" alt="" />
                <span v-else class="shop-logo" aria-hidden="true">FC</span>
                <strong>{{ t.__('Sold with FluentCart', 'membuild-community') }}</strong>
                <span class="shop-on">{{ t.__('Connected', 'membuild-community') }}</span>
              </div>
              <label class="shop-label" :for="`shop-${g.id}`">{{ t.__('Product', 'membuild-community') }}</label>
              <select :id="`shop-${g.id}`" class="field" :value="g.fluentcart || 0" @change="setProduct(g, $event.target.value)">
                <option :value="0">{{ t.__('Not sold here: I’ll paste a checkout link', 'membuild-community') }}</option>
                <option v-for="p in shop" :key="p.id" :value="p.id">{{ p.label }}</option>
              </select>
              <p v-if="g.fluentcart">{{ t.sprintf(/* translators: %s: membership name */ t.__('The button opens this product’s checkout. A paid order adds the buyer to %s right away; a refund or a cancelled subscription takes them out.', 'membuild-community'), g.name) }}</p>
              <p v-else-if="!shop.length">{{ t.__('Add a product in FluentCart first, then pick it here.', 'membuild-community') }}</p>
              <div v-if="g.fluentcart && shopAddress" class="shop-tip" role="note">
                <Icon name="info" :size="18" />
                <span>
                  <strong>{{ t.__('Tip: switch off the billing address', 'membuild-community') }}</strong>
                  {{ t.__('Your FluentCart checkout still asks for one. A membership doesn’t need it, and leaving it on can stop the order.', 'membuild-community') }}
                  <a :href="shopAddress" target="_blank" rel="noopener">{{ t.__('Open FluentCart’s Checkout fields', 'membuild-community') }}</a>
                </span>
              </div>
            </div>
            <template v-if="!g.fluentcart">
              <input v-model="g.form.url" class="field url" type="url" placeholder="https://yoursite.com/join" :aria-label="t.__('Your checkout or sign-up page', 'membuild-community')" @input="saveOffer(g)" />
              <p v-if="shop" class="shop-or">{{ t.__('Not using FluentCart? Paste any checkout link instead.', 'membuild-community') }}</p>
            </template>
            <p class="sees">
              {{ t.__('Members see:', 'membuild-community') }}
              <span class="preview"><Icon name="lock" :size="15" /> {{ offerButton(g.form) }}</span>
            </p>
          </template>
          <template v-else>
            <p>{{ t.__('Invite only, so there’s no button. Members see “Ask an admin for access”.', 'membuild-community') }}</p>
            <button class="btn btn-lg btn-ghost" @click="g.selling = true">{{ t.__('Add a Get access button', 'membuild-community') }}</button>
          </template>
        </div>
      </div>

      <div class="danger">
        <div>
          <strong>{{ t.__('Delete this membership', 'membuild-community') }}</strong>
          <p>{{ t.__('Its courses stay. Members lose the courses only this membership opened.', 'membuild-community') }}</p>
        </div>
        <button class="btn btn-lg red-line" @click="deleting = g">{{ t.__('Delete…', 'membuild-community') }}</button>
      </div>
      </div>
    </section>

    <p class="foot">{{ t.__('Admins and moderators can always open every course.', 'membuild-community') }}</p>

    <!-- + New membership: a name and which courses it opens; people and price come next. -->
    <BaseDialog v-if="creating" :label="t.__('New membership', 'membuild-community')" :width="560" @close="creating = null">
      <template #header><h2 class="dlg-title">{{ t.__('New membership', 'membuild-community') }}</h2></template>
      <form class="dlg" @submit.prevent="create">
        <label class="lbl" for="ms-name">{{ t.__('Name', 'membuild-community') }}</label>
        <input id="ms-name" v-model="creating.name" class="field" maxlength="80" autofocus />
        <p class="help">{{ t.__('Members see this name on locked courses, for example “Part of VIP coaching”.', 'membuild-community') }}</p>
        <p class="lbl">{{ t.__('Which courses does it open?', 'membuild-community') }}</p>
        <ul class="ticks">
          <li v-for="c in courses" :key="c.id">
            <label><input type="checkbox" :checked="creating.courses.has(c.id)" @change="toggleIn(creating.courses, c.id)" /> {{ c.title }}</label>
          </li>
        </ul>
        <p class="help">{{ t.__('Next you can add people and choose how they get it.', 'membuild-community') }}</p>
        <div class="actions">
          <button type="button" class="btn btn-lg btn-ghost" @click="creating = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-primary" :disabled="busy || !creating.name.trim()">{{ t.__('Create membership', 'membuild-community') }}</button>
        </div>
      </form>
    </BaseDialog>

    <BaseDialog v-if="renaming" :label="t.__('Rename membership', 'membuild-community')" :width="520" @close="renaming = null">
      <template #header><h2 class="dlg-title">{{ t.__('Rename membership', 'membuild-community') }}</h2></template>
      <form class="dlg" @submit.prevent="rename">
        <label class="lbl" for="ms-rename">{{ t.__('Name', 'membuild-community') }}</label>
        <input id="ms-rename" v-model="renaming.name" class="field" maxlength="80" autofocus />
        <p class="help">{{ t.__('Members see this name on locked courses, for example “Part of VIP coaching”.', 'membuild-community') }}</p>
        <div class="actions">
          <button type="button" class="btn btn-lg btn-ghost" @click="renaming = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-primary" :disabled="!renaming.name.trim()">{{ t.__('Save', 'membuild-community') }}</button>
        </div>
      </form>
    </BaseDialog>

    <BaseDialog v-if="choosing" :label="t.sprintf(/* translators: %s: membership name */ t.__('Courses in %s', 'membuild-community'), choosing.group.name)" :width="560" @close="choosing = null">
      <template #header><h2 class="dlg-title">{{ t.sprintf(/* translators: %s: membership name */ t.__('Courses in %s', 'membuild-community'), choosing.group.name) }}</h2></template>
      <div class="dlg">
        <p class="lbl">{{ t.__('Which courses does it open?', 'membuild-community') }}</p>
        <ul class="ticks">
          <li v-for="c in courses" :key="c.id">
            <label><input type="checkbox" :checked="choosing.courses.has(c.id)" @change="toggleIn(choosing.courses, c.id)" /> {{ c.title }}</label>
          </li>
        </ul>
        <div class="actions">
          <button class="btn btn-lg btn-ghost" @click="choosing = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button class="btn btn-lg btn-primary" @click="saveCourses">{{ t.__('Save', 'membuild-community') }}</button>
        </div>
      </div>
    </BaseDialog>

    <BaseDialog v-if="people" :label="t.sprintf(/* translators: %s: membership name */ t.__('People in %s', 'membuild-community'), people.group.name)" :width="560" @close="closePeople">
      <template #header><h2 class="dlg-title">{{ t.sprintf(/* translators: %s: membership name */ t.__('People in %s', 'membuild-community'), people.group.name) }}</h2></template>
      <div class="dlg">
        <label class="search">
          <Icon name="search" :size="18" />
          <input v-model="people.search" type="search" :placeholder="t.__('Add someone by name', 'membuild-community')" :aria-label="t.__('Add someone by name', 'membuild-community')" />
        </label>
        <ul v-if="people.found.length" class="people">
          <li v-for="m in people.found" :key="m.id">
            <Avatar :user="m" :size="40" /> <span>{{ m.name }}</span>
            <button class="btn btn-lg btn-primary" @click="addPerson(m)">{{ t.__('Add', 'membuild-community') }}</button>
          </li>
        </ul>
        <p class="lbl">{{ peopleOf(people.members.length) }}</p>
        <ul v-if="people.members.length" class="people">
          <li v-for="m in people.members" :key="m.id">
            <Avatar :user="m" :size="40" /> <span>{{ m.name }}</span>
            <button class="btn btn-lg btn-ghost" :aria-label="removeLabel(m.name)" @click="dropPerson(m)">{{ t.__('Remove', 'membuild-community') }}</button>
          </li>
        </ul>
        <p v-else-if="!people.loading" class="help">{{ t.__('Nobody added yet. Search for a name above.', 'membuild-community') }}</p>
        <div class="actions">
          <button class="btn btn-lg btn-primary" @click="closePeople">{{ t.__('Done', 'membuild-community') }}</button>
        </div>
      </div>
    </BaseDialog>

    <ConfirmDialog
      v-if="deleting"
      danger
      :busy="busy"
      :title="t.sprintf(/* translators: %s: membership name */ t.__('Delete %s?', 'membuild-community'), deleting.name)"
      :confirm="t.__('Delete membership', 'membuild-community')"
      @confirm="remove"
      @close="deleting = null"
    >
      {{ t.__('Its courses stay. Members lose the courses only this membership opened. Their progress is kept.', 'membuild-community') }}
    </ConfirmDialog>
  </div>
</template>

<style scoped>
.ms-head > .btn {
  flex: none;
  white-space: nowrap;
}

.dlg-title {
  margin: 0;
  padding: 28px 22px 4px;
  font-size: var(--fs-5xl);
}

.ms-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

h2 {
  margin: 0 0 6px;
  font-size: var(--fs-4xl);
}

.intro,
.part p,
.help,
.foot {
  margin: 0;
  color: var(--text-2);
  line-height: 1.5;
}

.none {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 44px 24px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  text-align: center;
}

.none-icon {
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.none strong {
  font-size: var(--fs-3xl);
}

.none p {
  max-width: 460px;
  margin: 0 0 6px;
  color: var(--text-2);
}

.one {
  margin-bottom: 16px;
  padding: 18px 20px 20px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
}

.one-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 14px;
}

.opener {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 10px;
  min-width: 0;
  min-height: 52px;
  margin: -6px 0;
  color: var(--text);
  text-align: left;
}

.chev {
  flex: none;
  color: var(--text-2);
  transition: transform 0.2s ease;
}

.shut .chev {
  transform: rotate(-90deg);
}

.shut .one-head {
  padding-bottom: 0;
}

.one-head h3 {
  margin: 0;
  font-size: var(--fs-3xl);
}

.one-head h3 span {
  margin-left: 8px;
  color: var(--text-2);
  font-size: var(--fs-base);
  font-weight: 400;
}

.part {
  display: flex;
  gap: 14px;
  padding: 16px 0;
  border-top: 1px solid var(--border);
}

.part-icon {
  display: grid;
  flex: none;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: var(--radius-sm);
  background: var(--surface-2);
}

.part-body {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  min-width: 0;
}

.part-body > strong {
  font-size: var(--fs-lg);
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 38px;
  padding: 0 8px 0 14px;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--text);
  font: inherit;
  font-weight: 650;
}

.chip button {
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border: 0;
  border-radius: 50%;
  background: none;
  color: var(--text-2);
  cursor: pointer;
}

.chip.add {
  padding: 0 14px;
  border: 1px dashed var(--border-strong);
  background: none;
  cursor: pointer;
}

.pick {
  position: relative;
}

.menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  z-index: 5;
  display: flex;
  flex-direction: column;
  min-width: 220px;
  max-height: 300px;
  padding: 6px;
  overflow-y: auto;
}

.menu button {
  min-height: 44px;
  padding: 0 12px;
  border: 0;
  border-radius: var(--radius-xs);
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.menu button:hover {
  background: var(--surface-2);
}

.field {
  width: 100%;
  min-height: 48px;
  padding: 0 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
}

.shop {
  width: 100%;
  max-width: 560px;
  padding: 16px 18px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-2);
}

.shop-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}

.shop-logo {
  flex: none;
  object-fit: contain;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: var(--radius-xs);
  background: var(--info-soft);
  color: var(--info-text);
  font-size: var(--fs-sm);
  font-weight: 800;
}

.shop-on {
  margin-left: auto;
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  background: var(--ok-soft, #e5f4ea);
  color: var(--ok-text);
  font-size: var(--fs-sm);
  font-weight: 700;
}

.shop-tip {
  display: flex;
  gap: 10px;
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: var(--radius-md);
  background: var(--warn-soft, #fff6e0);
  color: var(--text);
  font-size: var(--fs-md);
  line-height: 1.45;
}

.shop-tip :deep(svg) {
  flex: none;
  margin-top: 1px;
}

.shop-tip strong {
  display: block;
}

.shop-tip a {
  display: inline-block;
  margin-top: 4px;
  color: var(--text);
  font-weight: 650;
}

.shop-label {
  display: block;
  margin-bottom: 6px;
  font-weight: 650;
}

.shop p,
.shop-or {
  margin: 8px 0 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}

.offer {
  display: flex;
  gap: 10px;
  width: 100%;
  max-width: 560px;
}

.offer .price {
  width: 120px;
  flex: none;
}

.url {
  max-width: 560px;
}

.periods {
  display: inline-flex;
  gap: 2px;
  padding: 3px;
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
}

.periods button {
  min-height: 36px;
  padding: 0 14px;
  border: 0;
  border-radius: var(--radius-pill);
  background: none;
  color: var(--text-2);
  font: inherit;
  font-weight: 650;
  cursor: pointer;
}

.periods button.on {
  background: var(--text);
  color: var(--surface);
}

.sees {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-top: 4px !important;
}

.preview {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 0 18px;
  border-radius: var(--radius-pill);
  background: var(--text);
  color: var(--surface);
  font-weight: 700;
}

.danger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 32px; /* well apart from the Get access button above */
  padding: 16px 18px;
  border: 1px solid var(--danger-line);
  border-radius: var(--radius-md);
  background: var(--danger-soft);
}

.danger strong {
  color: var(--danger-text);
}

.danger p {
  margin: 4px 0 0;
  color: var(--danger-text);
}

.red-line {
  flex: none;
  border: 2px solid var(--danger-text);
  background: var(--surface);
  color: var(--danger-text);
}

.foot {
  margin-top: 4px;
}

/* Dialogs */
.dlg {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 4px 22px 22px;
}

.lbl {
  margin: 8px 0 0;
  font-weight: 700;
}

.ticks,
.people {
  margin: 0;
  padding: 0;
  list-style: none;
}

.ticks li,
.people li {
  border-top: 1px solid var(--border);
}

.ticks label {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 52px;
  font-size: var(--fs-lg);
  cursor: pointer;
}

.ticks input {
  width: 24px;
  height: 24px;
  accent-color: var(--text);
}

.people li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
}

.people span {
  flex: 1;
  font-weight: 650;
}

.search {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 52px;
  padding: 0 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
}

.search input {
  flex: 1;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
  outline: none;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 12px;
}

@media (max-width: 600px) {
  .ms-head {
    flex-direction: column;
  }

  .one {
    padding: 16px 14px;
  }

  .danger {
    flex-direction: column;
    align-items: flex-start;
  }

  .offer .price {
    width: 96px;
  }
}
</style>
