<script setup>
import { onMounted, ref, watch } from 'vue'
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

async function load(reset = true) {
  loading.value = true
  try {
    const res = await api.notifications(filter.value, reset ? 1 : nextPage.value)
    items.value = reset ? res.items : [...items.value, ...res.items]
    nextPage.value = res.next_page
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

async function open(n) {
  if (!n.read) {
    n.read = true
    api.readNotification(n.id).catch(() => {})
  }
  emit('navigate')
  router.push(n.link)
}

async function readAll() {
  items.value.forEach((n) => (n.read = true))
  await api.readAllNotifications().catch(() => {})
  messages.notifications = 0
}
const reachedLevel = (level) => t.sprintf(/* translators: %d: level number */ t.__('You reached Level %d!', 'membuild-community'), level)
const unlockedCourses = (courses) => t.sprintf(/* translators: %s: course titles, e.g. "Basics and Advanced" */ t.__('You unlocked %s.', 'membuild-community'), listNames(courses))
</script>

<template>
  <div class="panel" :class="{ page }">
    <header class="head">
      <h2>{{ t.__('Notifications', 'membuild-community') }}</h2>
      <button v-if="items.some((n) => !n.read)" class="link" @click="readAll">{{ t.__('Mark all as read', 'membuild-community') }}</button>
    </header>
    <div class="filters" role="tablist">
      <button role="tab" :aria-selected="filter === 'all'" :class="{ on: filter === 'all' }" @click="filter = 'all'">{{ t.__('All', 'membuild-community') }}</button>
      <button role="tab" :aria-selected="filter === 'unread'" :class="{ on: filter === 'unread' }" @click="filter = 'unread'">{{ t.__('Unread', 'membuild-community') }}</button>
    </div>

    <ul class="list">
      <li v-for="n in items" :key="n.id">
        <button class="item" :class="{ unread: !n.read }" @click="open(n)">
          <span class="pic">
            <span v-if="n.type === 'level_up'" class="level">{{ n.data.level }}</span>
            <span v-else-if="n.type === 'event_reminder' || !n.actors.length" class="level cal"><Icon name="calendar" :size="26" /></span>
            <Avatar v-else :user="n.actors[0]" :size="56" />
            <span class="type">
              <ReactionBadge v-if="n.data.reaction" :type="n.data.reaction" :size="26" />
              <span v-else-if="n.type === 'level_up'" class="tbadge star">🏆</span>
              <span v-else-if="isEvent(n)" class="tbadge cal">📅</span>
              <span v-else class="tbadge comment"><Icon name="comment" :size="14" /></span>
            </span>
          </span>
          <span class="text">
            <span v-if="n.type === 'level_up'" class="sentence">
              <strong>{{ reachedLevel(n.data.level) }}</strong>
              <!-- The space lives inside the text: Vue drops a lone leading space. -->
              <template v-if="n.data.courses?.length">{{ ' ' + unlockedCourses(n.data.courses) }}</template>
              <template v-else>{{ ' ' + t.__('Keep it up 🎉', 'membuild-community') }}</template>
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
            <span class="when">{{ timeAgo(n.updated_at) }}</span>
          </span>
          <span v-if="!n.read" class="dot" :aria-label="t.__('Unread', 'membuild-community')" />
        </button>
      </li>
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

.filters button {
  padding: 6px 12px;
  border-radius: 999px;
  font-weight: 650;
  font-size: 14px;
  color: var(--text-2);
}

.filters button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
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
