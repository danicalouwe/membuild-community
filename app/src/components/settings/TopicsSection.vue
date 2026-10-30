<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../../api.js'
import { setTopics, topics } from '../../lib/topics.js'
import BaseDialog from '../BaseDialog.vue'
import Icon from '../Icon.vue'
import { t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// Settings › Topics: the list (saved as you go, like Memberships), and "On Home" (with the form).
const { form } = useSettings()
const colors = ref(['#c92a2a', '#2f6be0', '#087a66', '#c2410c', '#b4338a', '#6c47ff', '#646973'])
const groups = ref([])
const error = ref('')
const busy = ref(false)

async function run(fn) {
  error.value = ''
  busy.value = true
  try {
    const res = await fn()
    setTopics(res)
    return res
  } catch (e) {
    error.value = e.message
    return null
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  const res = await run(() => api.topics())
  if (res?.colors) colors.value = res.colors
  groups.value = (await api.groups().catch(() => ({ items: [] }))).items || []
})

/* ---------- New and Edit (the same box) ---------- */
const editing = ref(null) // { id?, name, color, description, members, group_id, staff_only }
function openNew() {
  editing.value = { name: '', color: colors.value[topics.items.length % colors.value.length], description: '', members: false, group_id: 0, staff_only: false }
}
function openEdit(x) {
  editing.value = { id: x.id, name: x.name, color: x.color, description: x.description, members: !!x.group_id, group_id: x.group_id, staff_only: x.staff_only }
}
const canSave = computed(() => editing.value?.name.trim() && (!editing.value.members || editing.value.group_id))
async function saveTopic() {
  const e = editing.value
  const body = { name: e.name.trim(), color: e.color, description: e.description.trim(), group_id: e.members ? Number(e.group_id) || 0 : 0, staff_only: e.staff_only }
  const res = await run(() => (e.id ? api.updateTopic(e.id, body) : api.createTopic(body)))
  if (res) editing.value = null
}

/* ---------- Order: drag on a computer; Move up / Move down inside a topic ---------- */
const dragging = ref(0)
function dropOn(target) {
  const ids = topics.items.map((x) => x.id)
  const from = ids.indexOf(dragging.value)
  const to = ids.indexOf(target.id)
  dragging.value = 0
  if (from < 0 || to < 0 || from === to) return
  ids.splice(to, 0, ids.splice(from, 1)[0])
  saveOrder(ids)
}
function move(x, by) {
  const ids = topics.items.map((t) => t.id)
  const i = ids.indexOf(x.id)
  const j = i + by
  if (j < 0 || j >= ids.length) return
  ;[ids[i], ids[j]] = [ids[j], ids[i]]
  saveOrder(ids)
}
const saveOrder = (ids) => run(() => api.orderTopics(ids))
const place = (x) => topics.items.findIndex((t) => t.id === x.id)

/* ---------- Delete: the posts stay ---------- */
const deleting = ref(null) // { topic, moveTo }
async function remove() {
  const d = deleting.value
  const res = await run(() => api.deleteTopic(d.topic.id, d.moving ? Number(d.moveTo) || 0 : 0))
  if (res) {
    deleting.value = null
    editing.value = null
  }
}

const postsOf = (n) => t.sprintf(/* translators: %d: number of posts */ t._n('%d post', '%d posts', n, 'membuild-community'), n)
const groupName = (id) => groups.value.find((g) => g.id === id)?.name || t.__('A membership', 'membuild-community')
const deleteTitle = (name) => t.sprintf(/* translators: %s: a topic's name */ t.__('Delete “%s”?', 'membuild-community'), name)
const keptText = (n) => (!n ? t.__('It has no posts yet, so nothing else changes.', 'membuild-community') : t.sprintf(/* translators: %s: "12 posts" */ t.__('Its %s stay where they are, in Home, just without a topic. Nobody is told.', 'membuild-community'), postsOf(n)))
const colorLabel = (i) => t.sprintf(/* translators: %d: colour number */ t.__('Colour %d', 'membuild-community'), i + 1)
</script>

<template>
  <section id="s-topics" class="card group">
    <h2>{{ t.__('Topics', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Topics sort posts into subjects members can filter by on Home, like Introductions or Wins. A post can have one, or none.', 'membuild-community') }}</p>

    <p v-if="error" class="error-text" role="alert">{{ error }}</p>

    <div class="field">
      <div class="tp-head">
        <span>
          <span class="label">{{ topics.items.length ? t.sprintf(/* translators: %d: number of topics */ t._n('%d topic', '%d topics', topics.items.length, 'membuild-community'), topics.items.length) : t.__('No topics yet', 'membuild-community') }}</span>
          <small>{{ topics.items.length ? t.__('Drag to change the order on Home. Members pick one when they post, if they like.', 'membuild-community') : t.__('Add a few, like Introductions, Questions or Wins. Members can still post without one.', 'membuild-community') }}</small>
        </span>
        <button type="button" class="btn btn-lg btn-primary" :disabled="busy" @click="openNew"><Icon name="plus" :size="18" /> {{ t.__('New topic', 'membuild-community') }}</button>
      </div>

      <ul v-if="topics.items.length" class="tp-list">
        <li
          v-for="x in topics.items"
          :key="x.id"
          :class="{ dragging: dragging === x.id }"
          draggable="true"
          @dragstart="dragging = x.id"
          @dragend="dragging = 0"
          @dragover.prevent
          @drop.prevent="dropOn(x)"
        >
          <span class="tp-grip" aria-hidden="true"><Icon name="grip" :size="18" /></span>
          <span class="tp-dot" :style="{ background: x.color }" />
          <span class="tp-words">
            <span class="tp-name">
              <strong>{{ x.name }}</strong>
              <span v-if="x.staff_only" class="status-tag">{{ t.__('Only the team posts', 'membuild-community') }}</span>
              <span v-if="x.group_id" class="status-tag accent"><Icon name="lock" :size="12" /> {{ groupName(x.group_id) }}</span>
            </span>
            <small>{{ postsOf(x.posts) }}<template v-if="x.description"> · {{ x.description }}</template></small>
          </span>
          <button type="button" class="btn" @click="openEdit(x)"><Icon name="pencil" :size="16" /> {{ t.__('Edit', 'membuild-community') }}</button>
        </li>
      </ul>
    </div>

    <hr class="rule" />

    <fieldset class="field">
      <legend class="label">{{ t.__('On Home', 'membuild-community') }}</legend>
      <div class="choices">
        <label class="choice" :class="{ on: form.topics_on_home }"><input v-model="form.topics_on_home" type="radio" name="topics_on_home" :value="true" /><span class="mark" /><strong>{{ t.__('Show the topics above the posts', 'membuild-community') }}</strong><small>{{ t.__('All, then each topic as a filter. Latest and Popular move into a small Sort menu.', 'membuild-community') }}</small></label>
        <label class="choice" :class="{ on: !form.topics_on_home }"><input v-model="form.topics_on_home" type="radio" name="topics_on_home" :value="false" /><span class="mark" /><strong>{{ t.__('Hide them', 'membuild-community') }}</strong><small>{{ t.__('Posts still keep their topic; Home shows Latest and Popular as now.', 'membuild-community') }}</small></label>
      </div>
    </fieldset>

    <BaseDialog v-if="editing" :label="editing.id ? t.__('Edit topic', 'membuild-community') : t.__('New topic', 'membuild-community')" :width="560" phone="full" @close="editing = null">
      <template #header><h2 class="dlg-title">{{ editing.id ? t.__('Edit topic', 'membuild-community') : t.__('New topic', 'membuild-community') }}</h2></template>
      <form class="dlg" @submit.prevent="canSave && saveTopic()">
        <label class="field">
          <span class="label">{{ t.__('Name', 'membuild-community') }}</span>
          <input v-model="editing.name" type="text" maxlength="40" :placeholder="t.__('For example: Introductions', 'membuild-community')" data-autofocus />
        </label>
        <fieldset class="field">
          <legend class="label">{{ t.__('Colour', 'membuild-community') }}</legend>
          <div class="tp-colors">
            <label v-for="(c, i) in colors" :key="c" class="tp-swatch" :class="{ on: editing.color === c }" :style="{ background: c }">
              <input v-model="editing.color" type="radio" name="topic-color" :value="c" :aria-label="colorLabel(i)" />
            </label>
          </div>
        </fieldset>
        <label class="field">
          <span class="label">{{ t.__('Short description (optional)', 'membuild-community') }}</span>
          <input v-model="editing.description" type="text" maxlength="120" :placeholder="t.__('Ask about prices, tiers and offers', 'membuild-community')" />
          <small>{{ t.__('Shown when someone filters to it.', 'membuild-community') }}</small>
        </label>
        <fieldset class="field">
          <legend class="label">{{ t.__('Who can see it', 'membuild-community') }}</legend>
          <div class="choices">
            <label class="choice" :class="{ on: !editing.members }"><input v-model="editing.members" type="radio" name="topic-see" :value="false" /><span class="mark" /><strong>{{ t.__('Everyone in the community', 'membuild-community') }}</strong></label>
            <label class="choice" :class="{ on: editing.members }"><input v-model="editing.members" type="radio" name="topic-see" :value="true" :disabled="!groups.length" /><span class="mark" /><strong>{{ t.__('Only a membership', 'membuild-community') }}</strong><small>{{ groups.length ? t.__('Its posts are seen only by its members, and only they see the topic.', 'membuild-community') : t.__('Make a membership first, in Memberships.', 'membuild-community') }}</small></label>
          </div>
          <select v-if="editing.members" v-model="editing.group_id" class="tp-select" :aria-label="t.__('Which membership', 'membuild-community')">
            <option :value="0" disabled>{{ t.__('Choose a membership', 'membuild-community') }}</option>
            <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
          </select>
        </fieldset>
        <fieldset class="field">
          <legend class="label">{{ t.__('Who can post in it', 'membuild-community') }}</legend>
          <div class="choices">
            <label class="choice" :class="{ on: !editing.staff_only }"><input v-model="editing.staff_only" type="radio" name="topic-post" :value="false" /><span class="mark" /><strong>{{ t.__('Everyone who can see it', 'membuild-community') }}</strong></label>
            <label class="choice" :class="{ on: editing.staff_only }"><input v-model="editing.staff_only" type="radio" name="topic-post" :value="true" /><span class="mark" /><strong>{{ t.__('Only admins and moderators', 'membuild-community') }}</strong><small>{{ t.__('For announcements: members can read and comment.', 'membuild-community') }}</small></label>
          </div>
        </fieldset>
        <div v-if="editing.id && topics.items.length > 1" class="tp-order">
          <span class="label">{{ t.__('Order on Home', 'membuild-community') }}</span>
          <span class="tp-order-btns">
            <button type="button" class="btn" :disabled="busy || place(editing) === 0" @click="move(editing, -1)"><Icon name="arrowUp" :size="16" /> {{ t.__('Move up', 'membuild-community') }}</button>
            <button type="button" class="btn" :disabled="busy || place(editing) === topics.items.length - 1" @click="move(editing, 1)"><Icon name="arrowDown" :size="16" /> {{ t.__('Move down', 'membuild-community') }}</button>
          </span>
        </div>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        <div class="dlg-act">
          <button v-if="editing.id" type="button" class="btn tp-delete" @click="deleting = { topic: topics.items.find((x) => x.id === editing.id), moving: false, moveTo: 0 }">{{ t.__('Delete', 'membuild-community') }}</button>
          <button type="button" class="btn" @click="editing = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button type="submit" class="btn btn-primary" :disabled="!canSave || busy">{{ editing.id ? t.__('Save', 'membuild-community') : t.__('Create topic', 'membuild-community') }}</button>
        </div>
      </form>
    </BaseDialog>

    <BaseDialog v-if="deleting" :label="deleteTitle(deleting.topic.name)" :width="500" :z="95" @close="deleting = null">
      <template #header><h2 class="dlg-title">{{ deleteTitle(deleting.topic.name) }}</h2></template>
      <div class="dlg">
        <p class="dlg-text">{{ keptText(deleting.topic.posts) }}</p>
        <div v-if="topics.items.length > 1 && deleting.topic.posts" class="choices">
          <label class="choice" :class="{ on: !deleting.moving }"><input v-model="deleting.moving" type="radio" name="topic-del" :value="false" /><span class="mark" /><strong>{{ t.__('Keep the posts without a topic', 'membuild-community') }}</strong></label>
          <label class="choice" :class="{ on: deleting.moving }"><input v-model="deleting.moving" type="radio" name="topic-del" :value="true" /><span class="mark" /><strong>{{ t.__('Move them to another topic', 'membuild-community') }}</strong></label>
        </div>
        <select v-if="deleting.moving && deleting.topic.posts" v-model="deleting.moveTo" class="tp-select" :aria-label="t.__('Move them to', 'membuild-community')">
          <option :value="0" disabled>{{ t.__('Choose a topic', 'membuild-community') }}</option>
          <option v-for="x in topics.items.filter((x) => x.id !== deleting.topic.id)" :key="x.id" :value="x.id">{{ x.name }}</option>
        </select>
        <div class="dlg-act">
          <button type="button" class="btn" @click="deleting = null">{{ t.__('Keep it', 'membuild-community') }}</button>
          <button type="button" class="btn btn-danger" :disabled="busy || (deleting.moving && !deleting.moveTo)" @click="remove">{{ t.__('Delete topic', 'membuild-community') }}</button>
        </div>
      </div>
    </BaseDialog>
  </section>
</template>

<style scoped>
.tp-head {
  display: flex;
  align-items: center;
  gap: 16px;
}

.tp-head > span {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tp-list {
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
}

.tp-list li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
}

.tp-list li.dragging {
  opacity: 0.5;
}

.tp-grip {
  display: grid;
  color: var(--text-3);
  cursor: grab;
}

.tp-dot {
  flex: none;
  width: 14px;
  height: 14px;
  border-radius: var(--radius-pill);
}

.tp-words {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.tp-name {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.tp-name strong {
  font-size: var(--fs-lg);
}

.tp-words small {
  color: var(--text-2);
  font-size: var(--fs-md);
}

.tp-colors {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.tp-swatch {
  position: relative;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-pill);
  cursor: pointer;
}

.tp-swatch input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.tp-swatch.on {
  box-shadow: 0 0 0 3px var(--surface), 0 0 0 5px var(--text);
}

.tp-swatch:focus-within {
  outline: 3px solid var(--accent-text);
  outline-offset: 4px;
}

.tp-select {
  margin-top: 10px;
}

.tp-order {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.tp-order-btns {
  display: flex;
  gap: 8px;
}

.tp-delete {
  margin-right: auto;
  color: var(--danger-text);
}

/* The dialogs open outside the settings page, so its form styles don't reach them; these match them. */
.dlg-title {
  margin: 0;
  padding: 28px 22px 4px;
  font-size: var(--fs-5xl);
}

.dlg {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 12px 22px 22px;
}

.dlg .field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}

.dlg .label {
  padding: 0;
  font-size: var(--fs-lg);
  font-weight: 700;
}

.dlg small,
.dlg-text {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.45;
}

.dlg input[type='text'],
.dlg select {
  width: 100%;
  min-height: 48px;
  padding: 11px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
}

.dlg input[type='text']:focus,
.dlg select:focus {
  outline: 2px solid var(--accent);
  outline-offset: -1px;
}

.dlg .choices {
  display: grid;
  gap: 10px;
}

.dlg .choice {
  position: relative;
  display: grid;
  grid-template-columns: 22px 1fr;
  align-items: start;
  gap: 4px 12px;
  padding: 16px 18px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  cursor: pointer;
}

.dlg .choice input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.dlg .choice .mark {
  grid-row: span 2;
  width: 20px;
  height: 20px;
  margin-top: 1px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
}

.dlg .choice strong {
  font-size: var(--fs-lg);
}

.dlg .choice small {
  grid-column: 2;
}

.dlg .choice.on {
  border: 2px solid var(--accent);
  padding: 15px 17px;
  background: var(--accent-soft);
}

.dlg .choice.on small {
  color: var(--text);
}

.dlg .choice.on .mark {
  border-color: var(--text);
  background: radial-gradient(circle, var(--surface) 0 28%, var(--text) 31%);
}

.dlg .choice:has(input:focus-visible) {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

.dlg .choice:has(input:disabled) {
  cursor: default;
  opacity: 0.6;
}

.dlg-act {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 4px;
}

.dlg-act .btn {
  min-height: 52px;
  padding: 0 22px;
}

@media (max-width: 767px) {
  .tp-head {
    flex-direction: column;
    align-items: stretch;
  }

  .tp-grip {
    display: none; /* phones reorder with Move up / Move down inside a topic */
  }
}
</style>
