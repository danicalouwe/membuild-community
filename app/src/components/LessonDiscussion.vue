<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { cfg } from '../config.js'
import { discussionList, loadMore, refresh } from '../stores/feed.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import PostCard from './PostCard.vue'
import { t } from '../lib/i18n.js'
const CreatePostDialog = defineAsyncComponent(() => import('./CreatePostDialog.vue'))

/**
 * A course's conversation, as easy as posting on the home feed: under a lesson
 * (most helpful first) or across the whole course in its Discussion tab
 * (newest first, with search). Posts are normal community posts.
 */
const props = defineProps({
  course: { type: Object, required: true }, // { id, title }
  lesson: { type: Object, default: null }, // { id, title }; none: the whole course
  focusPost: { type: Number, default: 0 }, // scroll to this post once it's on the page
})
const emit = defineEmits(['posted'])

const whole = computed(() => !props.lesson)
// Most helpful first, unless Helpful votes are off (Settings › Features › Courses).
const helpfulOn = cfg.community.courses?.helpful !== false
const sort = ref(helpfulOn ? 'top' : 'newest')
const query = ref('')
const typed = ref('')
const place = computed(() => ({ course: props.course.id, lesson: props.lesson?.id || 0 }))
const list = computed(() => discussionList(place.value, { sort: sort.value, q: query.value }))

function load() {
  const l = list.value
  if (!l.loaded) loadMore(l)
  else refresh(l) // seen before: show it at once, then bring it up to date
}
watch(list, load, { immediate: true })

// Search waits for a pause in typing.
let timer = null
watch(typed, (v) => {
  clearTimeout(timer)
  timer = setTimeout(() => (query.value = v.trim().length >= 2 ? v.trim() : ''), 300)
})
onBeforeUnmount(() => clearTimeout(timer))

const dialog = shallowRef(null) // null | { draft }
const composerPlace = computed(() => ({ course: { id: props.course.id, title: props.course.title }, lesson: props.lesson ? { id: props.lesson.id, title: props.lesson.title } : null }))
const open = (draft = null) => (dialog.value = { draft })

// A post that failed to save comes back to the composer it was written in.
function reopen(e) {
  const p = e.detail.place
  if (p && p.course.id === props.course.id && (p.lesson?.id || 0) === (props.lesson?.id || 0)) open(e.detail)
}
onMounted(() => window.addEventListener('mbcom:reopen-composer', reopen))
onBeforeUnmount(() => window.removeEventListener('mbcom:reopen-composer', reopen))

const prompt = computed(() =>
  whole.value ? t.__('Start a conversation about this course…', 'membuild-community') : t.__('Ask a question or share what you learned…', 'membuild-community'),
)
const empty = computed(() =>
  query.value
    ? t.__('Nothing matches that search.', 'membuild-community')
    : whole.value
      ? t.__('No posts yet. Say hi, or ask the first question.', 'membuild-community')
      : t.__('Be the first to post about this lesson.', 'membuild-community'),
)
// Nothing posted here yet (not a search that came back empty): no search or sorts, just an invitation.
const nothing = computed(() => list.value.loaded && !list.value.items.length && !list.value.pinned.length && !query.value)
// A one-time tip under a lesson: what Helpful does. Remembered in this browser once dismissed.
const TIP = 'mbcom_helpful_tip'
const tip = ref(false)
try {
  tip.value = helpfulOn && !whole.value && !localStorage.getItem(TIP)
} catch {
  tip.value = false
}
function hideTip() {
  tip.value = false
  try {
    localStorage.setItem(TIP, '1')
  } catch {
    // the tip simply shows again next time
  }
}
const SORTS = { top: t.__('Most helpful', 'membuild-community'), newest: t.__('Newest', 'membuild-community') }

// Opened from a post elsewhere (the Overview): find it, glide to it, and light it up for a moment.
const root = ref(null)
let focused = 0
async function findFocus() {
  const id = props.focusPost
  if (!id || focused === id || !list.value.loaded) return
  const el = root.value?.querySelector(`[data-post-id="${id}"]`)
  if (el) {
    focused = id
    await new Promise((r) => setTimeout(r, 150))
    el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' })
    el.classList.add('spotlight')
    setTimeout(() => el.classList.remove('spotlight'), 3600)
  } else if (list.value.hasMore && !list.value.loading) {
    loadMore(list.value) // further down the list: bring in the next page and look again
  }
}
watch(() => [props.focusPost, list.value.loaded, list.value.items.length, list.value.loading], () => nextTick(findFocus), { immediate: true })

// Infinite scroll: the next page loads as the end comes into view.
const sentinel = ref(null)
let observer = null
onMounted(() => {
  observer = new IntersectionObserver(([e]) => e.isIntersecting && loadMore(list.value), { rootMargin: '600px' })
  if (sentinel.value) observer.observe(sentinel.value)
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <section ref="root" class="talk" :aria-label="whole ? t.__('Course discussion', 'membuild-community') : t.__('Lesson discussion', 'membuild-community')">
    <div class="talk-head">
      <h3>{{ whole ? t.__('All posts in this course', 'membuild-community') : t.__('Discussion', 'membuild-community') }}</h3>
      <label v-if="!nothing" class="section-search search">
        <Icon name="search" :size="18" />
        <input v-model="typed" type="search" :placeholder="whole ? t.__('Search all posts', 'membuild-community') : t.__('Search this discussion', 'membuild-community')" :aria-label="whole ? t.__('Search all posts', 'membuild-community') : t.__('Search this discussion', 'membuild-community')" />
      </label>
      <div v-if="!nothing && helpfulOn" class="sorts" role="radiogroup" :aria-label="t.__('Sort posts', 'membuild-community')">
        <button class="filter-pill" v-for="(label, key) in SORTS" :key="key" role="radio" :aria-checked="sort === key" :class="{ on: sort === key }" @click="sort = key">{{ label }}</button>
      </div>
    </div>

    <!-- Questions are asked inside the lesson they're about, so the next person finds the answer there. -->
    <p v-if="whole" class="ask-note card"><Icon name="comment" :size="20" /><span><strong>{{ t.__('Have a question?', 'membuild-community') }}</strong> {{ t.__('Open the lesson it’s about and ask there, so the next person finds the answer too.', 'membuild-community') }}</span></p>
    <button v-else-if="!cfg.community.restriction" class="bar card" @click="open()">
      <Avatar :user="cfg.user" :size="40" />
      <span class="pill">{{ prompt }}</span>
    </button>

    <p v-if="tip && (list.items.length || list.pinned.length)" class="tip">
      <span class="tip-icon"><Icon name="arrowUp" :size="18" /></span>
      <span><strong>{{ t.__('Did a post help you?', 'membuild-community') }}</strong> {{ t.__('Tap Helpful. The most helpful posts move to the top for everyone.', 'membuild-community') }}</span>
      <button @click="hideTip">{{ t.__('Got it', 'membuild-community') }}</button>
    </p>

    <div class="posts">
      <PostCard v-for="p in list.pinned" :key="`pin-${p.id}`" :post="p" :show-place="whole" />
      <PostCard v-for="p in list.items" :key="p.localKey || p.id" :post="p" :show-place="whole" />
    </div>

    <div v-if="list.loading && !list.items.length && !list.pinned.length" class="card skeleton-post">
      <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%" />
      <div style="flex: 1">
        <div class="skeleton" style="width: 30%; height: 12px; margin-bottom: 10px" />
        <div class="skeleton" style="width: 80%; height: 14px" />
      </div>
    </div>
    <p v-else-if="list.error && !list.items.length" class="empty">{{ list.error }}</p>
    <div v-else-if="nothing && !whole" class="none">
      <span class="none-icon"><Icon name="comment" :size="22" /></span>
      <strong>{{ t.__('No questions yet', 'membuild-community') }}</strong>
      <p>{{ t.__('Be the first to ask about this lesson. Your question shows up right here, so the next person learns from it too.', 'membuild-community') }}</p>
      <button v-if="!cfg.community.restriction" class="btn btn-lg btn-dark" @click="open()">{{ t.__('Ask a question', 'membuild-community') }}</button>
    </div>
    <p v-else-if="list.loaded && !list.items.length && !list.pinned.length" class="empty">{{ empty }}</p>
    <button v-if="list.loaded && list.hasMore && !list.loading" class="more btn btn-ghost" @click="loadMore(list)">{{ t.__('Show more posts', 'membuild-community') }}</button>
    <div ref="sentinel" class="sentinel" aria-hidden="true" />

    <CreatePostDialog v-if="dialog" :place="composerPlace" :draft="dialog.draft" @posted="emit('posted')" @close="dialog = null" />
  </section>
</template>

<style scoped>
.search {
  flex: 0 1 300px;
  min-width: 200px;
}

.talk :deep(.post.spotlight) {
  animation: spotlight 3.6s ease both;
}

@keyframes spotlight {
  0%,
  60% {
    box-shadow: 0 0 0 3px var(--accent), 0 12px 30px rgb(0 0 0 / 16%);
  }
  100% {
    box-shadow: var(--shadow);
  }
}

.talk {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.talk-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.talk-head h3 {
  margin: 0 auto 0 0;
  font-size: 21px;
}

.ask-note {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0;
  padding: 14px 16px;
  font-size: 16px;
  color: var(--text-2);
}

.ask-note strong {
  color: var(--text);
}

.tip {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0;
  padding: 12px 16px;
  border-radius: 14px;
  background: var(--tip-soft);
  color: var(--tip-text);
  font-size: 16px;
}

.tip span:nth-child(2) {
  flex: 1;
}

.tip-icon {
  flex: none;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: var(--surface);
  color: var(--tip-strong);
}

.tip button {
  flex: none;
  min-height: 44px;
  padding: 0 6px;
  font-weight: 700;
  text-decoration: underline;
  color: inherit;
}

.sorts {
  display: flex;
  gap: 8px;
}

.bar {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 12px 14px;
  text-align: left;
}

.pill {
  flex: 1;
  padding: 10px 14px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-3);
  font-size: 16px;
}

.bar:hover .pill {
  background: color-mix(in srgb, var(--surface-2) 80%, var(--text) 6%);
}

.posts {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.skeleton-post {
  display: flex;
  gap: 10px;
  padding: 14px;
}

.empty {
  margin: 4px 0;
  text-align: center;
  color: var(--text-3);
}

.none {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 40px 24px;
  border: 2px dashed var(--border);
  border-radius: 16px;
  text-align: center;
}

.none-icon {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  margin-bottom: 4px;
  border-radius: 50%;
  background: var(--surface);
}

.none strong {
  font-size: 19px;
}

.none p {
  max-width: 460px;
  margin: 0 0 8px;
  color: var(--text-2);
  line-height: 1.55;
}

.more {
  align-self: center;
  color: var(--text);
}

.sentinel {
  height: 1px;
}

/* Phones: posts run edge to edge like the feed; the controls keep a margin. */
@media (max-width: 899px) {
  .none {
    margin: 0 12px;
    padding: 32px 18px;
  }

  .talk-head,
  .empty {
    padding: 0 12px;
  }

  .talk-head .search {
    flex: 1 1 100%;
    order: 3;
  }

  .tip {
    border-radius: 0;
  }

  .bar,
  .ask-note {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }
}
</style>
