<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import { messages } from '../stores/messages.js'
import { timeAgo } from '../lib/time.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import ReactionBadge from './ReactionBadge.vue'
import { around, listNames, t } from '../lib/i18n.js'

// The notification list, used by the bell dropdown and the phone page.
defineProps({ page: { type: Boolean, default: false } })
const emit = defineEmits(['navigate'])
const router = useRouter()

const filter = ref('all')
const items = ref([])
const nextPage = ref(null)
const loading = ref(true)
const unread = ref(0)

async function load(reset = true) {
  loading.value = true
  try {
    const res = await api.notifications(filter.value, reset ? 1 : nextPage.value)
    items.value = reset ? res.items : [...items.value, ...res.items]
    nextPage.value = res.next_page
    unread.value = res.unread ?? items.value.filter((n) => !n.read).length
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  // Opening the list clears the bell count; items stay highlighted until clicked.
  if (messages.notifications) {
    messages.notifications = 0
    api.notificationsSeen().catch(() => {})
  }
})
watch(filter, () => load())

/* Facebook-style sentences: "Maya, Leo and 3 others reacted to your post". */
function who(n) {
  const names = n.actors.map((a) => a.name)
  if (n.actors_count <= 1) return names[0] || t.__('Someone', 'membuild-community')
  if (n.actors_count === 2 && names.length >= 2) {
    return t.sprintf(/* translators: 1: a name, 2: another name */ t.__('%1$s and %2$s', 'membuild-community'), names[0], names[1])
  }
  const rest = n.actors_count - Math.min(names.length, 2)
  return names.length >= 2
    ? /* translators: 1: a name, 2: another name, 3: how many more people */
      t.sprintf(t._n('%1$s, %2$s and %3$d other', '%1$s, %2$s and %3$d others', rest, 'membuild-community'), names[0], names[1], rest)
    : /* translators: 1: a name, 2: how many more people */
      t.sprintf(t._n('%1$s and %2$d other', '%1$s and %2$d others', rest, 'membuild-community'), names[0], rest)
}

// Each sentence keeps the people's names as one placeholder, so languages can put them anywhere.
const ACTIONS = {
  post_reaction: () => /* translators: %s: who reacted, e.g. "Maya and 2 others" */ t.__('%s reacted to your post', 'membuild-community'),
  comment_reaction: () => /* translators: %s: who reacted */ t.__('%s reacted to your comment', 'membuild-community'),
  post_comment: () => /* translators: %s: who commented */ t.__('%s commented on your post', 'membuild-community'),
  comment_reply: () => /* translators: %s: who replied */ t.__('%s replied to your comment', 'membuild-community'),
  story_reaction: () => /* translators: %s: who reacted */ t.__('%s reacted to your story', 'membuild-community'),
  event_new: () => /* translators: %s: who scheduled it */ t.__('%s scheduled an event', 'membuild-community'),
  event_changed: () => /* translators: %s: who changed it */ t.__('%s changed the time of an event', 'membuild-community'),
  event_cancelled: () => /* translators: %s: who cancelled it */ t.__('%s cancelled an event', 'membuild-community'),
}
const isEvent = (n) => n.type.startsWith('event_')
const eventLine = (n) => (n.type === 'event_reminder' ? (n.data.soon ? t.__('Starts in about an hour', 'membuild-community') : t.__('Tomorrow', 'membuild-community')) : '')
/** The sentence split around the names, so the names can be bold: [before, after]. */
const sentence = (n) => around(ACTIONS[n.type]?.() || '%s')

/* Today / This week / Earlier, with every level-up in a group folded into one row:
   "You reached Level 4 · 3 new levels this week". */
const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
function bucket(iso) {
  const today = startOfDay(new Date())
  const at = new Date(iso).getTime()
  if (at >= today) return 'today'
  if (at >= today - 6 * 86400000) return 'week'
  return 'earlier'
}
const GROUP_LABELS = {
  today: () => t.__('Today', 'membuild-community'),
  week: () => t.__('This week', 'membuild-community'),
  earlier: () => t.__('Earlier', 'membuild-community'),
}
const groups = computed(() => {
  const out = []
  for (const n of items.value) {
    const key = bucket(n.updated_at)
    let g = out[out.length - 1]
    if (!g || g.key !== key) out.push((g = { key, rows: [] }))
    if (n.type !== 'level_up') {
      g.rows.push(n)
      continue
    }
    const first = g.rows.find((r) => r.type === 'level_up')
    if (!first) {
      g.rows.push({ ...n, merged: [n], data: { ...n.data, courses: [...(n.data.courses || [])] } })
      continue
    }
    first.merged.push(n)
    first.read = first.read && n.read
    first.data.level = Math.max(first.data.level, n.data.level) // the highest one reached (levels can drop when the owner has fewer)
    for (const c of n.data.courses || []) if (!first.data.courses.includes(c)) first.data.courses.push(c)
  }
  return out
})
const fmtDay = new Intl.DateTimeFormat(undefined, { weekday: 'long' })
const fmtEarlier = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' })
function when(n) {
  const key = bucket(n.updated_at)
  if (key === 'today') return timeAgo(n.updated_at)
  return (key === 'week' ? fmtDay : fmtEarlier).format(new Date(n.updated_at))
}
const levelsLine = (n) => t.sprintf(/* translators: %d: how many levels were reached */ t._n('%d new level', '%d new levels', n.merged.length, 'membuild-community'), n.merged.length)
const groupLevelsLine = (key, n) => (key === 'week' ? t.sprintf(/* translators: %s: e.g. "3 new levels" */ t.__('%s this week.', 'membuild-community'), levelsLine(n)) : key === 'today' ? t.sprintf(/* translators: %s: e.g. "3 new levels" */ t.__('%s today.', 'membuild-community'), levelsLine(n)) : levelsLine(n) + '.')

async function open(n) {
  for (const one of n.merged || [n]) {
    const real = items.value.find((i) => i.id === one.id)
    if (real && !real.read) {
      real.read = true
      unread.value = Math.max(0, unread.value - 1)
      api.readNotification(real.id).catch(() => {})
    }
  }
  emit('navigate')
  router.push(n.link)
}

async function readAll() {
  items.value.forEach((n) => (n.read = true))
  unread.value = 0
  await api.readAllNotifications().catch(() => {})
  messages.notifications = 0
}
const reachedLevel = (level) => t.sprintf(/* translators: %d: level number */ t.__('You reached Level %d!', 'membuild-community'), level)
const reachedLevelPlain = (level) => t.sprintf(/* translators: %d: level number */ t.__('You reached Level %d', 'membuild-community'), level)
const unlockedCourses = (courses) => t.sprintf(/* translators: %s: course titles, e.g. "Basics and Advanced" */ t.__('You unlocked %s.', 'membuild-community'), listNames(courses))
</script>

<template>
  <div class="panel" :class="{ page }">
    <header class="head">
      <h2>{{ t.__('Alerts', 'membuild-community') }}</h2>
      <button v-if="unread || items.some((n) => !n.read)" class="link" @click="readAll">{{ t.__('Mark all as read', 'membuild-community') }}</button>
    </header>
    <div class="filters" role="tablist">
      <button class="filter-pill" role="tab" :aria-selected="filter === 'all'" :class="{ on: filter === 'all' }" @click="filter = 'all'">{{ t.__('All', 'membuild-community') }}</button>
      <button class="filter-pill" role="tab" :aria-selected="filter === 'unread'" :class="{ on: filter === 'unread' }" @click="filter = 'unread'">{{ unread ? t.sprintf(/* translators: %d: how many unread alerts */ t.__('Unread · %d', 'membuild-community'), unread) : t.__('Unread', 'membuild-community') }}</button>
    </div>

    <ul class="list">
      <template v-for="g in groups" :key="g.key">
      <li class="group-label">{{ GROUP_LABELS[g.key]() }}</li>
      <li v-for="n in g.rows" :key="n.id">
        <button class="item" :class="{ unread: !n.read }" @click="open(n)">
          <span class="pic">
            <span v-if="n.type === 'level_up'" class="level">{{ n.data.level }}</span>
            <span v-else-if="n.type === 'lesson_open'" class="level cal"><Icon name="cap" :size="26" /></span>
            <span v-else-if="n.type === 'event_reminder' || !n.actors.length" class="level cal"><Icon name="calendar" :size="26" /></span>
            <Avatar v-else :user="n.actors[0]" :size="56" />
            <span class="type">
              <ReactionBadge v-if="n.data.reaction" :type="n.data.reaction" :size="26" />
              <span v-else-if="n.type === 'level_up'" class="tbadge star">🏆</span>
              <span v-else-if="isEvent(n)" class="tbadge cal">📅</span>
              <span v-else-if="n.type === 'lesson_open'" class="tbadge cal">🔓</span>
              <span v-else class="tbadge comment"><Icon name="comment" :size="14" /></span>
            </span>
          </span>
          <span class="text">
            <span v-if="n.type === 'level_up' && n.merged.length > 1" class="sentence">
              <strong>{{ reachedLevelPlain(n.data.level) }}</strong>
              <span class="course">{{ groupLevelsLine(g.key, n) }}<template v-if="n.data.courses?.length">{{ ' ' + unlockedCourses(n.data.courses) }}</template></span>
            </span>
            <span v-else-if="n.type === 'level_up'" class="sentence">
              <strong>{{ reachedLevel(n.data.level) }}</strong>
              <!-- The space lives inside the text: Vue drops a lone leading space. -->
              <template v-if="n.data.courses?.length">{{ ' ' + unlockedCourses(n.data.courses) }}</template>
              <template v-else>{{ ' ' + t.__('Keep it up 🎉', 'membuild-community') }}</template>
            </span>
            <span v-else-if="n.type === 'lesson_open'" class="sentence">
              <strong>{{ n.data.title }}</strong>{{ ' ' + t.__('is open now', 'membuild-community') }}
              <span class="course">{{ n.data.course }}</span>
            </span>
            <span v-else-if="n.type === 'event_reminder'" class="sentence">
              <strong>{{ n.data.title }}</strong>{{ ' · ' + eventLine(n) }}
            </span>
            <span v-else-if="isEvent(n)" class="sentence">
              {{ sentence(n)[0] }}<strong>{{ who(n) }}</strong>{{ sentence(n)[1] }}: <strong>{{ n.data.title }}</strong>
            </span>
            <span v-else class="sentence">
              {{ sentence(n)[0] }}<strong>{{ who(n) }}</strong>{{ sentence(n)[1] }}<template v-if="n.data.snippet">: “{{ n.data.snippet }}”</template>
            </span>
            <span class="when">{{ when(n) }}</span>
          </span>
          <span v-if="!n.read" class="dot" :aria-label="t.__('Unread', 'membuild-community')" />
        </button>
      </li>
      </template>
      <li v-if="loading && !items.length" class="state">{{ t.__('Loading…', 'membuild-community') }}</li>
      <li v-else-if="!items.length" class="state">
        <Icon name="bell" :size="34" />
        <p>{{ filter === 'unread' ? t.__('You’re all caught up.', 'membuild-community') : t.__('No notifications yet.', 'membuild-community') }}</p>
      </li>
    </ul>
    <button v-if="nextPage" class="more" :disabled="loading" @click="load(false)">{{ t.__('See previous notifications', 'membuild-community') }}</button>
  </div>
</template>

<style scoped>
.sentence .course {
  display: block;
  color: var(--text-2);
  font-size: 14px;
}

.panel {
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px 6px;
}

.head h2 {
  margin: 0;
  font-size: 22px;
}

.link {
  color: var(--accent-text);
  font-weight: 600;
  font-size: 14px;
}

.filters {
  display: flex;
  gap: 6px;
  padding: 4px 12px 8px;
}

.list {
  list-style: none;
  margin: 0;
  padding: 0 6px 6px;
  overflow-y: auto;
}

.item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 8px;
  border-radius: 10px;
  text-align: left;
}

.item:hover {
  background: var(--surface-2);
}

.item.unread {
  background: var(--accent-soft);
}

.item.unread:hover {
  background: color-mix(in srgb, var(--accent-soft) 80%, var(--accent) 20%);
}

.list li + li:not(.group-label) .item.unread {
  margin-top: 2px;
}

.group-label {
  padding: 14px 8px 6px;
  font-size: 13px;
  font-weight: 750;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-3);
}

.pic {
  position: relative;
  flex: none;
  width: 56px;
  height: 56px;
}

.level.cal {
  background: var(--surface-2);
  color: var(--text-2);
}

.tbadge.cal {
  font-size: 13px;
}

.level {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--accent), #8c6bff);
  color: #fff;
  font-size: 24px;
  font-weight: 800;
}

.type {
  position: absolute;
  right: -4px;
  bottom: -4px;
  display: flex;
  --ring: var(--surface); /* reaction badges draw their own ring */
}

.type .tbadge {
  box-shadow: 0 0 0 2px var(--surface);
}

.tbadge {
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  color: #fff;
  font-size: 14px;
}

.comment {
  background: #2fb36b;
}

.star {
  background: #f7b125;
}

.text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.sentence {
  font-size: 15px;
  line-height: 1.3;
  color: var(--text-2);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.sentence strong {
  color: var(--text);
}

.unread .sentence {
  color: var(--text);
}

.when {
  font-size: 13px;
  color: var(--text-3);
}

.unread .when {
  color: var(--accent-text);
  font-weight: 650;
}

.dot {
  flex: none;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: var(--accent);
}

.state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 28px 12px;
  color: var(--text-3);
}

.state p {
  margin: 0;
}

.more {
  margin: 4px 12px 12px;
  height: 38px;
  border-radius: 8px;
  background: var(--surface-2);
  font-weight: 650;
}
</style>
