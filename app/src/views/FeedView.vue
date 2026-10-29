<script setup>
import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { cfg, feature } from '../config.js'
import { lists, loadMore, refresh } from '../stores/feed.js'
import { messages, pulseNow, watchFeed } from '../stores/messages.js'
import Composer from '../components/Composer.vue'
import PostSkeleton from '../components/PostSkeleton.vue'
import PostCard from '../components/PostCard.vue'
import ReelsTray from '../components/ReelsTray.vue'
import StoriesStrip from '../components/StoriesStrip.vue'
import Icon from '../components/Icon.vue'
import DayOne from '../components/DayOne.vue'
import ForYou from '../components/ForYou.vue'
import { api } from '../api.js'
import { showToast } from '../lib/toast.js'
import { useRouter } from 'vue-router'
import { t } from '../lib/i18n.js'

const sort = ref(sessionStorage.getItem('mbcom-sort') || 'latest')
const list = computed(() => lists[sort.value])
const sentinel = ref(null)
let observer
let stopFeed = null

watch(sort, (s) => {
  sessionStorage.setItem('mbcom-sort', s)
  window.scrollTo({ top: 0 })
  if (!list.value.loaded) loadMore(list.value)
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
const ownerEmpty = computed(() => list.value.loaded && !list.value.items.length && !list.value.pinned.length && cfg.community.canManage && sort.value === 'latest')
const writeWelcome = () => router.replace({ query: { write: 1 } })

const newPosts = (n) => t.sprintf(/* translators: %d: number of new posts waiting to be shown */ t._n('%d new post', '%d new posts', n, 'membuild-community'), n)
</script>

<template>
  <div class="feed">
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

    <aside class="day-one-slot">
      <DayOne />
      <ForYou />
    </aside>

    <Composer v-if="cfg.community.canPost && !cfg.community.restriction" @posted="scrollTop" />
    <StoriesStrip v-if="feature('stories')" />

    <div class="sort" role="tablist" :aria-label="t.__('Sort posts', 'membuild-community')">
      <button class="filter-pill" role="tab" :aria-selected="sort === 'latest'" :class="{ on: sort === 'latest' }" @click="sort = 'latest'">{{ t.__('Latest', 'membuild-community') }}</button>
      <button class="filter-pill" role="tab" :aria-selected="sort === 'hot'" :class="{ on: sort === 'hot' }" @click="sort = 'hot'"><Icon name="flame" :size="15" /> {{ t.__('Popular', 'membuild-community') }}</button>
    </div>

    <PostCard v-for="post in list.pinned" :key="`pin-${post.id}`" :post="post" />
    <template v-for="(post, i) in list.items" :key="post.localKey || post.id">
      <PostCard :post="post" course-header />
      <!-- Reels are grouped into one tray in the feed, after the third post. -->
      <ReelsTray v-if="feature('reels') && i === Math.min(2, list.items.length - 1)" />
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
    <p v-else-if="list.loaded && !list.items.length" class="status">{{ t.__('No posts yet. Be the first to say hi 👋', 'membuild-community') }}</p>
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
  .feed {
    padding: 8px 0 0;
    gap: 8px;
  }

  .feed :deep(.card) {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  .sort {
    padding: 0 12px;
  }

  .new-pill {
    top: 62px;
  }
}
</style>
