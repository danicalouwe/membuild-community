<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { canDock, openChat } from '../stores/chatDock.js'
import { api } from '../api.js'
import { cfg, levelLabel } from '../config.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import { t, locale } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { activeLabel, isOnline } from '../lib/online.js'

// The member directory: most active, newest, or who's online now. Ranking by level
// lives on the Leaderboard.
// No search box here: the search at the top of the app finds members too.
const SORTS = [
  { key: 'active', label: t.__('Most active', 'membuild-community') },
  { key: 'newest', label: t.__('Newest', 'membuild-community') },
]
const sort = ref('active')
const items = ref([])
const total = ref(0)
const online = ref(null) // how many are online now; null when the viewer doesn't see it
const nextPage = ref(null)
const loading = ref(true)
const refreshing = ref(false) // a new filter: the old list dims until the new one comes
const latest = useLatest()

async function load(reset = true) {
  const current = latest() // a slower, older load never overwrites a newer one
  loading.value = true
  refreshing.value = reset
  try {
    // "Online now" is a filter on the most active list.
    const onlineOnly = sort.value === 'online'
    const res = await api.members({ sort: onlineOnly ? 'active' : sort.value, online: onlineOnly ? 1 : undefined, page: reset ? 1 : nextPage.value })
    if (!current()) return
    items.value = reset ? res.items : [...items.value, ...res.items]
    nextPage.value = res.next_page
    total.value = res.total
    online.value = res.online ?? null
    if (online.value === null && onlineOnly) sort.value = 'active'
  } finally {
    if (current()) loading.value = refreshing.value = false
  }
  await nextTick()
  watchEnd() // a tall screen may still show the end of the list: keep going
}

// No pages to click: as you scroll near the end, the next members load, until there are none.
const end = ref(null)
let observer = null
function watchEnd() {
  if (!observer || !end.value) return
  observer.unobserve(end.value)
  observer.observe(end.value)
}
function more() {
  if (!loading.value && nextPage.value) load(false)
}

onMounted(() => {
  observer = new IntersectionObserver(([e]) => e.isIntersecting && more(), { rootMargin: '800px' })
  load()
})
onBeforeUnmount(() => observer?.disconnect())
watch(sort, () => load())
const router = useRouter()
const covers = cfg.community.memberCovers !== false // Settings › Features › Members directory
const openProfile = (m) => router.push(m.is_me ? { name: 'me' } : { name: 'member', params: { id: m.id } })
// Message from the card: the chat opens docked on a computer, as its own page on a phone.
const messaging = ref(0)
async function message(m) {
  if (messaging.value) return
  messaging.value = m.id
  try {
    const chat = await api.startChat([m.id])
    if (canDock()) openChat(chat)
    else router.push({ name: 'messages', params: { threadId: chat.id } })
  } catch (err) {
    alert(err.message)
  } finally {
    messaging.value = 0
  }
}
const joined = (iso) => t.sprintf(/* translators: %s: month and year */ t.__('Joined %s', 'membuild-community'), new Date(iso).toLocaleDateString(locale, { month: 'short', year: 'numeric' }))
const roleLabel = (role) => (role === 'admin' ? t.__('Admin', 'membuild-community') : t.__('Moderator', 'membuild-community'))
</script>

<template>
  <div class="members">
    <header class="head">
      <h1>{{ t.__('Members', 'membuild-community') }} <span v-if="total">{{ total.toLocaleString(locale) }}</span></h1>
      <div class="sorts" role="tablist">
        <button v-for="s in SORTS" :key="s.key" role="tab" :aria-selected="sort === s.key" :class="{ on: sort === s.key }" @click="sort = s.key">{{ s.label }}</button>
        <button v-if="online !== null" role="tab" class="online-tab" :aria-selected="sort === 'online'" :class="{ on: sort === 'online' }" @click="sort = 'online'">
          <i class="green" aria-hidden="true" /> {{ t.__('Online now', 'membuild-community') }} <span class="count">{{ online }}</span>
        </button>
      </div>
    </header>

    <ul class="grid" :class="{ dim: refreshing && items.length }">
      <li v-for="m in items" :key="m.id">
        <!-- A computer: the card with its cover; a phone: one row (photo, name, level, Active now). -->
        <article class="card person" :class="{ covered: covers }" @click="openProfile(m)">
          <!-- Their cover picture, or the default: a gradient from the community's main colour. -->
          <div v-if="covers" class="cover" :style="m.cover ? { backgroundImage: `url(${m.cover})` } : null" />
          <div class="who">
            <span class="pic">
              <Avatar :user="m" :size="72" online="top" />
              <span v-if="m.role" class="role">{{ roleLabel(m.role) }}</span>
            </span>
            <span class="names">
              <RouterLink :to="m.is_me ? { name: 'me' } : { name: 'member', params: { id: m.id } }" class="name" @click.stop>{{ m.name }}</RouterLink>
              <span class="lvl">{{ levelLabel(m.level) }}</span>
              <span v-if="!isOnline(m) && activeLabel(m)" class="active phone-only">{{ activeLabel(m) }}</span>
            </span>
            <Icon name="chevronRight" :size="20" class="chev phone-only" />
          </div>
          <p v-if="m.bio" class="bio">{{ m.bio }}</p>
          <p class="facts">
            <span v-if="m.location"><Icon name="mapPin" :size="15" /> {{ m.location }}</span>
            <span><Icon name="calendar" :size="15" /> {{ joined(m.joined_at) }}</span>
          </p>
          <footer class="foot">
            <!-- Online now: the green dot on the photo says it. Earlier: "Active 2 h ago". -->
            <span v-if="!isOnline(m)" class="active">{{ activeLabel(m) }}</span>
            <RouterLink v-if="m.is_me" :to="{ name: 'me', query: { edit: 1 } }" class="btn btn-lg btn-ghost" @click.stop>{{ t.__('Edit profile', 'membuild-community') }}</RouterLink>
            <button v-else-if="m.can_message" class="btn btn-lg btn-ghost" :disabled="messaging === m.id" @click.stop="message(m)"><Icon name="messenger" :size="18" /> {{ t.__('Message', 'membuild-community') }}</button>
          </footer>
        </article>
      </li>
      <!-- Loading (first page or the next): member cards in grey, the shape of what's coming. -->
      <template v-if="loading && (!refreshing || !items.length)">
        <li v-for="n in items.length ? 3 : 9" :key="`sk${n}`" aria-hidden="true">
          <div class="card sk-person">
            <div v-if="covers" class="skeleton sk-cover" />
            <div class="skeleton sk-pic" />
            <div class="sk-lines">
              <div class="skeleton" style="width: 55%; height: 16px" />
              <div class="skeleton" style="width: 35%; height: 12px" />
              <div class="skeleton sk-wide" style="width: 85%; height: 12px" />
            </div>
            <div class="skeleton sk-btn" />
          </div>
        </li>
      </template>
    </ul>
    <div v-if="!loading && !items.length && sort === 'online'" class="card nobody">
      <span class="nobody-icon"><Icon name="users" :size="30" /></span>
      <strong>{{ t.__('Nobody’s online right now', 'membuild-community') }}</strong>
      <p>{{ t.__('Members show up here while they’re using the community. You can still send anyone a message; they’ll see it next time they visit.', 'membuild-community') }}</p>
      <button class="btn btn-lg btn-ghost" @click="sort = 'active'">{{ t.__('See everyone', 'membuild-community') }}</button>
    </div>
    <p v-else-if="!loading && !items.length" class="card state">{{ t.__('No members yet.', 'membuild-community') }}</p>
    <p v-if="loading" class="sr-only" role="status">{{ t.__('Loading members', 'membuild-community') }}</p>
    <div ref="end" class="end" aria-hidden="true" />
  </div>
</template>

<style scoped>
/* Who's around right now (Settings › Features › Show who's online). */
.green {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #1f9d55;
}

.online-tab .count {
  display: inline-grid;
  place-items: center;
  min-width: 22px;
  height: 22px;
  margin-left: 4px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--surface);
  font-size: 13px;
}

.active {
  color: var(--text-2);
  font-size: 14px;
}

.active.now {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--ok-soft);
  color: var(--ok-text);
  font-weight: 700;
}

.nobody {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 48px 24px;
  text-align: center;
}

.nobody-icon {
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.nobody strong {
  font-size: 19px;
}

.nobody p {
  max-width: 420px;
  margin: 0 0 6px;
  color: var(--text-2);
}

.members {
  max-width: 1000px;
  margin: 0 auto;
  padding: 16px;
}

.head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.head h1 {
  margin: 0 auto 0 0;
  font-size: 24px;
}

.head h1 span {
  color: var(--text-3);
  font-weight: 600;
  font-size: 18px;
}

.sorts {
  display: flex;
  gap: 8px;
  flex-basis: 100%;
}

.sorts button {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 6px;
  min-height: 40px;
  padding: 0 16px;
  border-radius: 999px;
  background: var(--surface-2);
  font-weight: 650;
  font-size: 15px;
  color: var(--text-2);
  white-space: nowrap;
}

.sorts button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.grid {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
  gap: 16px;
  transition: opacity 0.15s;
}

.grid.dim {
  opacity: 0.6;
}

/* The member card: cover (when they have one), photo, name, level, bio, where and when, then
   Active now and Message. */
.person {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  padding: 24px 18px 18px;
  border: 1px solid var(--border);
  border-radius: 16px;
  text-align: center;
  color: var(--text);
  overflow: hidden;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.person:hover {
  border-color: var(--accent);
  box-shadow: 0 6px 20px rgb(0 0 0 / 8%);
}

.cover {
  position: absolute;
  inset: 0 0 auto;
  height: 88px;
  background: var(--cover-default);
  background-position: center;
  background-size: cover;
  background-repeat: no-repeat;
}

.person.covered {
  padding-top: 48px;
}

.who {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.pic {
  position: relative;
  display: inline-flex;
  padding: 4px;
  border-radius: 50%;
  background: var(--surface);
}

.role {
  position: absolute;
  left: 50%;
  bottom: -6px;
  transform: translateX(-50%);
  padding: 1px 8px;
  border-radius: 999px;
  background: var(--text);
  color: var(--surface);
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}

.names {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  margin-top: 8px;
}

.name {
  color: var(--text);
  font-size: 18px;
  font-weight: 700;
  text-decoration: none;
}

.lvl {
  font-size: 15px;
  color: var(--accent-text);
  font-weight: 700;
}

.bio {
  margin: 12px 0 0;
  font-size: 15px;
  line-height: 1.5;
  color: var(--text-2);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.facts {
  display: flex;
  flex-wrap: wrap;
  white-space: nowrap;
  justify-content: center;
  gap: 4px 16px;
  margin: auto 0 0;
  padding-top: 12px;
  color: var(--text-2);
  font-size: 14px;
}

.facts span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}

.foot .btn {
  flex: none;
  margin-left: auto;
  gap: 8px;
  padding: 0 22px;
  border-radius: 999px;
  color: var(--text);
  font-size: 17px;
  font-weight: 700;
  text-decoration: none;
}

.person .phone-only {
  display: none;
}

.state {
  padding: 28px;
  text-align: center;
  color: var(--text-3);
}

.btn {
  padding: 9px 16px;
  border-radius: 10px;
  background: var(--surface-2);
  font-weight: 650;
}

.end {
  height: 1px;
}

@media (max-width: 767px) {
  .members {
    padding: 12px;
  }

  /* Phones: one list. Photo with the dot, name, level, Active now; tap for the profile. */
  .grid {
    display: block;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--surface);
    overflow: hidden;
  }

  .grid li + li {
    border-top: 1px solid var(--border);
  }

  .person,
  .person.covered {
    flex-direction: row;
    padding: 12px 14px;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    text-align: left;
  }

  .person:hover {
    box-shadow: none;
  }

  .cover,
  .bio,
  .facts,
  .foot,
  .role {
    display: none;
  }

  .who {
    flex-direction: row;
    gap: 14px;
    width: 100%;
  }

  .pic {
    padding: 0;
  }

  .pic :deep(.avatar-wrap) {
    width: 56px !important;
    height: 56px !important;
  }

  .pic :deep(.dot) {
    top: auto !important;
    bottom: 0 !important;
    width: 14px !important;
    height: 14px !important;
  }

  .names {
    flex: 1;
    align-items: flex-start;
    margin: 0;
  }

  .name {
    font-size: 17px;
  }

  .person .phone-only {
    display: inline;
  }

  .sorts {
    overflow-x: auto;
    scrollbar-width: none;
  }

  .active.phone-only.now {
    padding: 0;
    background: none;
    color: #1f7a49;
    font-size: 15px;
  }

  .chev {
    align-self: center;
    color: var(--text-3);
  }
}

/* Loading cards: the member card's shape in grey. */
.sk-person {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  height: 100%;
  min-height: 250px;
  padding: 24px 18px 18px;
  border-radius: 16px;
  overflow: hidden;
  position: relative;
}

.sk-cover {
  position: absolute;
  top: 0;
  right: 0;
  left: 0;
  height: 64px;
  border-radius: 0;
}

.sk-pic {
  position: relative;
  width: 72px;
  height: 72px;
  border: 3px solid var(--surface);
  border-radius: 50%;
}

.sk-lines {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  width: 100%;
}

.sk-btn {
  width: 120px;
  height: 44px;
  margin-top: auto;
  border-radius: 999px;
}

@media (max-width: 767px) {
  .sk-person {
    flex-direction: row;
    min-height: 0;
    padding: 14px 16px;
  }

  .sk-cover,
  .sk-btn,
  .sk-wide {
    display: none;
  }

  .sk-pic {
    flex: none;
    width: 56px;
    height: 56px;
  }

  .sk-lines {
    align-items: flex-start;
  }
}
</style>
