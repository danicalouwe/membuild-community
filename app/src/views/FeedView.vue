<script setup>
import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { cfg, feature } from '../config.js'
import { lists, loadMore, refresh } from '../stores/feed.js'
import { messages, pulseNow, watchFeed } from '../stores/messages.js'
import Composer from '../components/Composer.vue'
import PostCard from '../components/PostCard.vue'
import ReelsTray from '../components/ReelsTray.vue'
import StoriesStrip from '../components/StoriesStrip.vue'
import Icon from '../components/Icon.vue'
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
const newPosts = (n) => t.sprintf(/* translators: %d: number of new posts waiting to be shown */ t._n('%d new post', '%d new posts', n, 'membuild-community'), n)
</script>

<template>
  <div class="feed">
    <Transition name="pill">
      <button v-if="newCount > 0" class="new-pill" @click="showNew">
        <Icon name="arrowUp" :size="16" /> {{ newPosts(newCount) }}
      </button>
    </Transition>

    <Composer v-if="cfg.community.canPost && !cfg.community.restriction" @posted="scrollTop" />
    <StoriesStrip v-if="feature('stories')" />

    <div class="sort" role="tablist" :aria-label="t.__('Sort posts', 'membuild-community')">
      <button role="tab" :aria-selected="sort === 'latest'" :class="{ on: sort === 'latest' }" @click="sort = 'latest'">{{ t.__('Latest', 'membuild-community') }}</button>
      <button role="tab" :aria-selected="sort === 'hot'" :class="{ on: sort === 'hot' }" @click="sort = 'hot'"><Icon name="flame" :size="15" /> {{ t.__('Popular', 'membuild-community') }}</button>
    </div>

    <PostCard v-for="post in list.pinned" :key="`pin-${post.id}`" :post="post" />
    <template v-for="(post, i) in list.items" :key="post.localKey || post.id">
      <PostCard :post="post" course-header />
      <!-- Reels are grouped into one tray in the feed, after the third post. -->
      <ReelsTray v-if="feature('reels') && i === Math.min(2, list.items.length - 1)" />
    </template>

    <template v-if="!list.loaded">
      <div v-for="n in 3" :key="n" class="card skeleton-card">
        <div class="row">
          <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%" />
          <div style="flex: 1">
            <div class="skeleton" style="width: 40%; height: 12px; margin-bottom: 8px" />
            <div class="skeleton" style="width: 20%; height: 10px" />
          </div>
        </div>
        <div class="skeleton" style="height: 14px; margin: 14px 0 8px" />
        <div class="skeleton" style="height: 14px; width: 70%" />
        <div class="skeleton" style="height: 220px; margin-top: 14px; border-radius: 12px" />
      </div>
    </template>

    <p v-if="list.error" class="status">
      {{ t.__("Couldn't load posts.", 'membuild-community') }} <button class="retry" @click="loadMore(list)">{{ t.__('Try again', 'membuild-community') }}</button>
    </p>
    <p v-else-if="list.loaded && !list.items.length" class="status">{{ t.__('No posts yet. Be the first to say hi 👋', 'membuild-community') }}</p>
    <p v-else-if="list.loaded && !list.hasMore" class="status">{{ t.__("You're all caught up ✨", 'membuild-community') }}</p>

    <div ref="sentinel" class="sentinel" aria-hidden="true" />
  </div>
</template>

<style scoped>
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

.sort button {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 6px 14px;
  border-radius: 999px;
  font-weight: 600;
  font-size: 14px;
  color: var(--text-2);
}

.sort button.on {
  background: var(--text);
  color: var(--surface);
}

.skeleton-card {
  padding: 14px;
}

.skeleton-card .row {
  display: flex;
  gap: 10px;
  align-items: center;
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
  border-radius: 999px;
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 650;
  box-shadow: 0 6px 18px rgb(59 91 253 / 35%);
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
