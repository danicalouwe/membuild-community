<script setup>
import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { cfg, feature } from '../config.js'
import { lists, loadMore, refresh, topicList } from '../stores/feed.js'
import { topicById, topics, topicsShown } from '../lib/topics.js'
import { useRoute } from 'vue-router'
import { messages, pulseNow, watchFeed } from '../stores/messages.js'
import Composer from '../components/Composer.vue'
import PostSkeleton from '../components/PostSkeleton.vue'
import PostCard from '../components/PostCard.vue'
import ReelsTray from '../components/ReelsTray.vue'
import StoriesStrip from '../components/StoriesStrip.vue'
import Icon from '../components/Icon.vue'
import DayOne from '../components/DayOne.vue'
import ForYou from '../components/ForYou.vue'
import ThemeSlot from '../components/ThemeSlot.vue'
import { api } from '../api.js'
import { showToast } from '../lib/toast.js'
import { useRouter } from 'vue-router'
import { t } from '../lib/i18n.js'
import { PULL_MARK, usePullToRefresh } from '../lib/pullRefresh.js'

const sort = ref(sessionStorage.getItem('mbcom-sort') || 'latest')
// Home filtered to a topic (?topic=3): from the pills, or a post's topic label.
const route = useRoute()
const topic = computed(() => (topicsShown.value && route.name === 'feed' && topicById(route.query.topic) ? Number(route.query.topic) : 0))
const current = computed(() => (topic.value ? topicById(topic.value) : null))
const list = computed(() => (topic.value ? topicList(topic.value, sort.value) : lists[sort.value]))
const sortOpen = ref(false)
const setTopic = (id) => {
  router.replace({ query: { ...route.query, topic: id || undefined } })
  window.scrollTo({ top: 0 })
}
const setSort = (s) => {
  sort.value = s
  sortOpen.value = false
}
watch(list, (l) => {
  if (!l.loaded) loadMore(l)
})
const closeSort = (e) => {
  if (sortOpen.value && !e.target.closest?.('.sort-wrap')) sortOpen.value = false
}
onMounted(() => document.addEventListener('click', closeSort))
onBeforeUnmount(() => document.removeEventListener('click', closeSort))
const postsIn = (n) => t.sprintf(/* translators: %d: number of posts */ t._n('%d post', '%d posts', n, 'membuild-community'), n)
const noneIn = (name) => t.sprintf(/* translators: %s: a topic's name */ t.__('No posts in %s yet. Be the first.', 'membuild-community'), name)
const sentinel = ref(null)
let observer
let stopFeed = null

watch(sort, (s) => {
  sessionStorage.setItem('mbcom-sort', s)
  window.scrollTo({ top: 0 })
  if (!list.value.loaded) loadMore(list.value)
})

// "For you" beside the feed (the next event, your course, new members), loaded once.
const forYou = ref(null)
onMounted(async () => {
  forYou.value = await api.forYou().catch(() => null)
})

onMounted(() => {
  // Start fetching the next page roughly three screens before the end.
  observer = new IntersectionObserver(
    ([entry]) => entry.isIntersecting && loadMore(list.value),
    { rootMargin: `0px 0px ${window.innerHeight * 3}px 0px` },
  )
  observer.observe(sentinel.value)
  if (!list.value.loaded) loadMore(list.value)
})

/**
 * "New posts": the messages pulse counts posts newer than the newest one
 * either feed list has shown (Latest is the reference, since Hot isn't in
 * date order), only while the feed is on screen.
 */
const newestId = () => {
  let max = 0
  for (const l of [lists.latest, list.value]) {
    for (const p of [...l.items, ...l.pinned]) if (typeof p.id === 'number' && p.id > max) max = p.id
  }
  return max
}
const newCount = computed(() => messages.newPosts)

function startWatching() {
  stopFeed?.()
  stopFeed = watchFeed(newestId)
}
function stopWatching() {
  stopFeed?.()
  stopFeed = null
}

function scrollTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function showNew() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
  // Refresh what's on screen, and Latest too when it isn't, since the count is measured against it.
  const views = list.value === lists.latest || !lists.latest.loaded ? [list.value] : [list.value, lists.latest]
  const done = await Promise.all(views.map(refresh))
  // Only a refresh that really ran clears the pill; otherwise the next pulse decides.
  if (done.every(Boolean)) messages.newPosts = 0
  pulseNow()
}

// Phones: pull down at the top of Home to get the newest posts (lib/pullRefresh.js).
const { pull, refreshing } = usePullToRefresh(async () => {
  const views = list.value === lists.latest || !lists.latest.loaded ? [list.value] : [list.value, lists.latest]
  const done = await Promise.all(views.map(refresh))
  if (done.every(Boolean)) messages.newPosts = 0
  pulseNow()
})

onActivated(startWatching)
onDeactivated(stopWatching)
onBeforeUnmount(() => {
  stopWatching()
  observer?.disconnect()
})
/* "Confirm your email": a few days after joining, while the welcome email is unanswered. */
const confirm = computed(() => cfg.confirmEmail)
const sending = ref(false)
async function sendAgain() {
  sending.value = true
  try {
    const res = await api.resendWelcome()
    if (res.confirmed) cfg.confirmEmail = null
    else showToast(t.sprintf(/* translators: %s: an email address */ t.__('Sent again to %s.', 'membuild-community'), res.to))
  } catch (err) {
    showToast(err.message)
  } finally {
    sending.value = false
  }
}

/* An empty Home for the owner: ask for the welcome post. */
const router = useRouter()
const ownerEmpty = computed(() => !topic.value && list.value.loaded && !list.value.items.length && !list.value.pinned.length && cfg.community.canManage && sort.value === 'latest')
const writeWelcome = () => router.replace({ query: { write: 1 } })

const newPosts = (n) => t.sprintf(/* translators: %d: number of new posts waiting to be shown */ t._n('%d new post', '%d new posts', n, 'membuild-community'), n)
</script>

<template>
  <div class="feed">
    <h1 class="sr-only">{{ t.__('Home', 'membuild-community') }}</h1>
    <div v-if="pull || refreshing" class="pull" :class="{ ready: pull >= PULL_MARK, spinning: refreshing }" :style="{ '--pull': `${refreshing ? PULL_MARK : pull}px`, '--turn': `${Math.min(1, pull / PULL_MARK) * 180}deg` }" role="status" :aria-label="refreshing ? t.__('Getting the newest posts', 'membuild-community') : ''">
      <Icon name="arrowDown" :size="20" />
    </div>
    <Transition name="pill">
      <button v-if="newCount > 0" class="new-pill" @click="showNew">
        <Icon name="arrowUp" :size="16" /> {{ newPosts(newCount) }}
      </button>
    </Transition>

    <div v-if="confirm" class="confirm" role="status">
      <Icon name="mail" :size="22" />
      <span class="c-words"><strong>{{ t.__('Confirm your email to get updates', 'membuild-community') }}</strong><span>{{ t.sprintf(/* translators: %s: an email address */ t.__('We sent a link to %s.', 'membuild-community'), confirm.email) }}</span></span>
      <button type="button" class="c-send" :disabled="sending" @click="sendAgain">{{ t.__('Send it again', 'membuild-community') }}</button>
      <a class="c-wrong" :href="confirm.profileUrl">{{ t.__('Wrong email?', 'membuild-community') }}</a>
    </div>

    <aside class="day-one-slot" :aria-label="t.__('For you', 'membuild-community')">
      <DayOne />
      <ThemeSlot name="home.for-you" :data="{ data: forYou }"><ForYou :data="forYou" /></ThemeSlot>
    </aside>

    <Composer v-if="cfg.community.canPost && !cfg.community.restriction" :topic="topic" @posted="scrollTop" />
    <StoriesStrip v-if="feature('stories')" />

    <ThemeSlot name="home.before-feed" :data="{ sort }" />

    <!-- With topics: All plus a pill per topic, and Latest / Popular in a small Sort menu. -->
    <div v-if="topicsShown" class="topic-row">
      <div class="topic-pills" role="radiogroup" :aria-label="t.__('Topics', 'membuild-community')">
        <button class="filter-pill" role="radio" :aria-checked="!topic" :class="{ on: !topic }" @click="setTopic(0)">{{ t.__('All', 'membuild-community') }}</button>
        <button v-for="x in topics.items" :key="x.id" class="filter-pill" role="radio" :aria-checked="topic === x.id" :class="{ on: topic === x.id }" @click="setTopic(x.id)"><span class="topic-dot" :style="{ background: x.color }" />{{ x.name }}</button>
      </div>
      <span class="sort-wrap">
        <button class="filter-pill sort-btn" aria-haspopup="menu" :aria-expanded="sortOpen" :aria-label="t.__('Sort posts', 'membuild-community')" @click="sortOpen = !sortOpen"><Icon name="sort" :size="16" /><span class="sort-word">{{ sort === 'hot' ? t.__('Popular', 'membuild-community') : t.__('Latest', 'membuild-community') }}</span></button>
        <span v-if="sortOpen" class="sort-menu card" role="menu">
          <span class="sort-title">{{ t.__('Sort posts', 'membuild-community') }}</span>
          <button role="menuitemradio" :aria-checked="sort === 'latest'" :class="{ on: sort === 'latest' }" @click="setSort('latest')">{{ t.__('Latest', 'membuild-community') }}<Icon v-if="sort === 'latest'" name="check" :size="18" /></button>
          <button role="menuitemradio" :aria-checked="sort === 'hot'" :class="{ on: sort === 'hot' }" @click="setSort('hot')">{{ t.__('Popular', 'membuild-community') }}<Icon v-if="sort === 'hot'" name="check" :size="18" /></button>
        </span>
      </span>
    </div>
    <section v-if="current" class="card topic-card">
      <strong><span class="topic-dot" :style="{ background: current.color }" />{{ current.name }}</strong>
      <span>{{ [current.description, postsIn(current.posts)].filter(Boolean).join(' · ') }}</span>
    </section>

    <div v-else-if="!topicsShown" class="sort" role="tablist" :aria-label="t.__('Sort posts', 'membuild-community')">
      <button class="filter-pill" role="tab" :aria-selected="sort === 'latest'" :class="{ on: sort === 'latest' }" @click="sort = 'latest'">{{ t.__('Latest', 'membuild-community') }}</button>
      <button class="filter-pill" role="tab" :aria-selected="sort === 'hot'" :class="{ on: sort === 'hot' }" @click="sort = 'hot'"><Icon name="flame" :size="15" /> {{ t.__('Popular', 'membuild-community') }}</button>
    </div>

    <template v-for="post in list.pinned" :key="`pin-${post.id}`">
      <ThemeSlot name="home.post-card" :data="{ post, pinned: true }"><PostCard :post="post" /></ThemeSlot>
    </template>
    <template v-for="(post, i) in list.items" :key="post.localKey || post.id">
      <ThemeSlot name="home.post-card" :data="{ post, pinned: false }"><PostCard :post="post" course-header /></ThemeSlot>
      <!-- Reels are grouped into one tray in the feed, after the third post; a topic shows only its own posts. -->
      <ReelsTray v-if="feature('reels') && !topic && i === Math.min(2, list.items.length - 1)" />
    </template>

    <template v-if="!list.loaded"><PostSkeleton v-for="n in 3" :key="n" /></template>

    <p v-if="list.error" class="status">
      {{ t.__("Couldn't load posts.", 'membuild-community') }} <button class="retry" @click="loadMore(list)">{{ t.__('Try again', 'membuild-community') }}</button>
    </p>
    <section v-else-if="ownerEmpty" class="card first-post">
      <span class="wave"><Icon name="hand" :size="30" /></span>
      <strong>{{ t.__('Your community is ready for its first post', 'membuild-community') }}</strong>
      <p>{{ t.__('New members see the newest post first. A short hello from you, and what they can expect here, makes them feel welcome.', 'membuild-community') }}</p>
      <button type="button" class="btn-main" @click="writeWelcome">{{ t.__('Write a welcome post', 'membuild-community') }}</button>
    </section>
    <p v-else-if="list.loaded && !list.items.length" class="status">{{ current ? noneIn(current.name) : t.__('No posts yet. Be the first to say hi 👋', 'membuild-community') }}</p>
    <p v-else-if="list.loaded && !list.hasMore" class="status">{{ t.__("You're all caught up ✨", 'membuild-community') }}</p>

    <div ref="sentinel" class="sentinel" aria-hidden="true" />
  </div>
</template>

<style scoped>
/* Day one: in the right column on wide screens, at the top of the feed otherwise. */
.day-one-slot {
  order: -1;
}

@media (min-width: 1200px) {
  .day-one-slot {
    position: fixed;
    top: calc(var(--header-h) + 16px);
    right: 16px;
    z-index: 5;
    width: min(296px, calc((100vw - 640px) / 2 - 32px));
    max-height: calc(100dvh - var(--header-h) - 32px);
    overflow-y: auto;
  }
}

.confirm {
  order: -2;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
  padding: 16px 20px;
  border-radius: var(--radius);
  color: var(--warn-text);
  background: var(--warn-soft);
}

.c-words {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: min(200px, 100%);
  font-size: var(--fs-base);
  overflow-wrap: anywhere; /* a very long email address breaks instead of running off */
}

.c-words strong {
  font-size: var(--fs-lg);
}

.c-send {
  min-height: 44px;
  padding: 0 20px;
  border-radius: var(--radius-pill);
  color: var(--warn-soft);
  background: var(--warn-text);
  font-size: var(--fs-lg);
  font-weight: 700;
}

.c-wrong {
  color: var(--warn-text);
  font-size: var(--fs-lg);
  font-weight: 700;
  text-decoration: underline;
}

.first-post {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 44px 28px;
  text-align: center;
}

.wave {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  color: var(--accent-text);
  background: var(--accent-soft);
}

.first-post strong {
  font-size: var(--fs-4xl);
}

.first-post p {
  max-width: 440px;
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-lg);
  line-height: 1.6;
}

.btn-main {
  min-height: 52px;
  margin-top: 6px;
  padding: 0 26px;
  border-radius: var(--radius-pill);
  color: var(--accent-ink);
  background: var(--accent);
  font-size: var(--fs-xl);
  font-weight: 700;
}

.feed {
  max-width: 640px;
  margin: 0 auto;
  padding: 16px 12px 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.sort {
  display: flex;
  gap: 6px;
}

/* Topics: the pills scroll sideways with a fade at the end; Sort stays put. */
.topic-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.topic-pills {
  flex: 1;
  min-width: 0;
  display: flex;
  gap: 6px;
  overflow-x: auto;
  scrollbar-width: none;
  -webkit-mask-image: linear-gradient(90deg, #000 calc(100% - 40px), transparent); /* exempt: mask shape, not a colour */
  mask-image: linear-gradient(90deg, #000 calc(100% - 40px), transparent); /* exempt: mask shape, not a colour */
  padding-right: 32px;
}

.topic-pills::-webkit-scrollbar {
  display: none;
}

.topic-pills .filter-pill {
  flex: none;
}

.topic-dot {
  flex: none;
  width: 10px;
  height: 10px;
  margin-right: 6px;
  border-radius: var(--radius-pill);
}

.sort-wrap {
  position: relative;
  flex: none;
}

.sort-btn {
  gap: 6px;
}

.sort-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  z-index: 20;
  width: 250px;
  padding: 8px;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-pop);
}

.sort-title {
  padding: 8px 10px;
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.sort-menu button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 48px;
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-lg);
  font-weight: 600;
  text-align: left;
}

.sort-menu button:hover,
.sort-menu button.on {
  background: var(--surface-2);
}

.topic-card {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 12px 16px;
}

.topic-card strong {
  display: flex;
  align-items: center;
  font-size: var(--fs-lg);
}

.topic-card span {
  color: var(--text-2);
  font-size: var(--fs-md);
}

@media (max-width: 600px) {
  .sort-word {
    display: none; /* phones: a round Sort button */
  }

  .sort-btn {
    width: 36px;
    padding: 0;
    justify-content: center;
  }
}

.status {
  text-align: center;
  color: var(--text-3);
  padding: 12px 0 24px;
  margin: 0;
}

.retry {
  color: var(--accent-text);
  font-weight: 600;
}

.sentinel {
  height: 1px;
}

.new-pill {
  position: fixed;
  top: calc(var(--header-h) + 12px);
  left: 50%;
  z-index: 25;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: var(--radius-pill);
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 650;
  box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 35%, transparent);
}

.pill-enter-active,
.pill-leave-active {
  transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.3, 1.3, 0.5, 1);
}

.pill-enter-from,
.pill-leave-to {
  opacity: 0;
  transform: translate(-50%, -12px);
}

@media (max-width: 767px) {
  /* "What's on your mind?" sits right under the top bar, joined to it, as on Facebook. */
  .feed {
    padding: 0;
    gap: 8px;
  }

  /* Pull to refresh: a round button that follows the finger down, turns when it's far enough, spins while loading. */
  .pull {
    position: fixed;
    top: calc(50px + env(safe-area-inset-top) - 36px); /* starts tucked under the phone's top bar */
    left: 50%;
    z-index: 19;
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--surface);
    color: var(--text-2);
    box-shadow: var(--shadow-sm);
    transform: translate(-50%, var(--pull)) rotate(var(--turn)); /* the arrow turns up when letting go will refresh */
  }

  .pull.ready {
    color: var(--accent-text);
  }

  .pull.spinning :deep(svg) {
    animation: pull-spin 0.8s linear infinite;
  }

  @keyframes pull-spin {
    to {
      transform: rotate(360deg);
    }
  }

  /* "For you" isn't shown on phones: with nothing else in it, its box mustn't add a gap above the composer. */
  .day-one-slot:not(:has(> :not(.for-you))) {
    display: none;
  }

  .feed :deep(.card) {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  /* The composer joins the top bar: no line between them. */
  .feed > :deep(.bar.card) {
    border-top: 0;
  }

  .sort {
    padding: 0 12px;
  }

  /* Below the top bar, or just below the clock when the bars have slid away. */
  .new-pill {
    top: calc(env(safe-area-inset-top) + 62px);
    transition: top 0.25s ease;
  }

  :global(.bars-hidden) .new-pill {
    top: calc(env(safe-area-inset-top) + 12px);
  }
}
</style>
