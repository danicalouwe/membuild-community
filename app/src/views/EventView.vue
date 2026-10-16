<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { eventList, removePost, setRsvp, updatePost, watchPost } from '../stores/feed.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import RichText from '../components/RichText.vue'
import PostActions from '../components/PostActions.vue'
import CommentThread from '../components/CommentThread.vue'
import { calendarLinks, downloadIcs, playerUrl, serviceName, stateOf, untilLabel, whenLong, zoneName } from '../lib/events.js'
import { t } from '../lib/i18n.js'
import { useMenu } from '../lib/useMenu.js'
const EventDialog = defineAsyncComponent(() => import('../components/EventDialog.vue'))

/**
 * One event: when, who's going, Going / Interested, add to calendar, the
 * Join button (or the stream itself, playing here), the details and the
 * conversation underneath. Staff edit, cancel or duplicate it from ⋯.
 */
const props = defineProps({ id: { type: Number, required: true } })
const router = useRouter()

const post = ref(null)
const error = ref('')
let unwatch = null
watch(post, (p) => {
  unwatch?.()
  unwatch = p ? watchPost(p) : null
})
onBeforeUnmount(() => unwatch?.())
const now = ref(Date.now())
let tick = null
onMounted(() => (tick = setInterval(() => (now.value = Date.now()), 30000)))
onBeforeUnmount(() => clearInterval(tick))

async function load() {
  error.value = ''
  post.value = null
  try {
    const p = await api.post(props.id)
    if (!p.event) throw Object.assign(new Error(t.__('This event isn’t available.', 'membuild-community')), { status: 404 })
    post.value = p
  } catch (err) {
    error.value = err.status === 404 ? t.__('This event isn’t available.', 'membuild-community') : err.message
  }
}
watch(() => props.id, load, { immediate: true })

const e = computed(() => post.value?.event)
const state = computed(() => (e.value ? stateOf(e.value, now.value) : 'upcoming'))
const cover = computed(() => post.value?.media.find((m) => m.kind === 'image')?.url || null)
const fallback = computed(() => `linear-gradient(135deg, hsl(${(props.id * 67) % 360} 65% 50%), hsl(${(props.id * 67 + 50) % 360} 60% 36%))`)
const pageUrl = computed(() => location.origin + router.resolve({ name: 'event', params: { id: props.id } }).href)
const links = computed(() => (e.value ? calendarLinks({ ...e.value, description: post.value.content }, pageUrl.value) : null))
// The stream plays here from 15 minutes before until it ends; afterwards the recording does (when it's YouTube or Vimeo).
const player = computed(() => {
  if (!e.value) return null
  if (state.value === 'live') return playerUrl(e.value.url, e.value.kind)
  if (state.value === 'past' && e.value.recording) return playerUrl(e.value.recording, /youtu/.test(e.value.recording) ? 'youtube' : /vimeo/.test(e.value.recording) ? 'vimeo' : 'link')
  return null
})
const watching = ref(false) // the member pressed play (streams don't autoplay until asked)
const service = computed(() => serviceName(e.value?.kind))

const { open: menu, root: menuRoot, trigger: menuTrigger, list: menuList, toggle: toggleMenu, close: closeMenu, onKeydown: onMenuKey } = useMenu()
const dialog = ref(null) // null | { copy }
const calOpen = ref(false)

function rsvp(status) {
  setRsvp(post.value, e.value.my_rsvp === status ? null : status).catch((err) => alert(err.message))
}
async function cancel() {
  closeMenu()
  if (!confirm(t.__('Cancel this event? Everyone who said they’re going will be told.', 'membuild-community'))) return
  try {
    const saved = await api.cancelEvent(props.id)
    post.value = saved
    updatePost(props.id, saved)
  } catch (err) {
    alert(err.message)
  }
}
async function remove() {
  closeMenu()
  if (!confirm(t.__('Delete this event and its comments?', 'membuild-community'))) return
  try {
    await api.deletePost(props.id)
    removePost(props.id)
    router.push({ name: 'events' })
  } catch (err) {
    alert(err.message)
  }
}
function saved(p) {
  if (dialog.value?.copy) {
    const l = eventList('upcoming')
    if (l.loaded) l.items = [...l.items, p].sort((a, b) => new Date(a.event.at) - new Date(b.event.at))
    router.push({ name: 'event', params: { id: p.id } })
  } else {
    post.value = p
    updatePost(props.id, p)
  }
}
const goingLabel = (n) => t.sprintf(/* translators: %d: number of members going */ t._n('%d going', '%d going', n, 'membuild-community'), n)
const startsIn = (ev) => t.sprintf(/* translators: %s: e.g. "in 2 hours" */ t.__('Starts %s', 'membuild-community'), untilLabel(ev, now.value))
const joinLabel = computed(() => (service.value ? t.sprintf(/* translators: %s: Zoom, Google Meet… */ t.__('Join on %s', 'membuild-community'), service.value) : t.__('Join', 'membuild-community')))
</script>

<template>
  <div v-if="error" class="state">
    <p>{{ error }}</p>
    <RouterLink :to="{ name: 'events' }" class="btn btn-ghost">{{ t.__('All events', 'membuild-community') }}</RouterLink>
  </div>

  <div v-else-if="post" class="event">
    <RouterLink :to="{ name: 'events' }" class="back"><Icon name="chevronLeft" :size="16" /> {{ t.__('Events', 'membuild-community') }}</RouterLink>

    <article class="card main">
      <!-- The stream or the replay, playing here; otherwise the cover. -->
      <div v-if="player && (watching || state === 'live')" class="player">
        <iframe :src="player" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen :title="e.title" />
      </div>
      <div v-else class="hero" :style="cover ? { backgroundImage: `url(${cover})` } : { background: fallback }">
        <button v-if="player" class="play" :aria-label="t.__('Play the recording', 'membuild-community')" @click="watching = true"><Icon name="play" :size="30" /></button>
        <span v-if="state === 'cancelled'" class="badge off">{{ t.__('Cancelled', 'membuild-community') }}</span>
        <span v-else-if="state === 'past'" class="badge off">{{ t.__('Past event', 'membuild-community') }}</span>
      </div>

      <div class="body">
        <p class="when">
          {{ whenLong(e) }} <span class="zone">({{ zoneName() }})</span>
          <em v-if="state === 'live'" class="now">● {{ t.__('Live now', 'membuild-community') }}</em>
          <em v-else-if="state === 'soon' || state === 'upcoming'" class="until">· {{ startsIn(e) }}</em>
        </p>
        <div class="title-row">
          <h1>{{ e.title }}</h1>
          <div v-if="e.can_edit" :ref="menuRoot" class="menu-wrap">
            <button :ref="menuTrigger" class="icon-btn" :aria-label="t.__('Event options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="menu" @click="toggleMenu"><Icon name="more" /></button>
            <div v-if="menu" :ref="menuList" class="menu" role="menu" @keydown="onMenuKey">
              <button role="menuitem" @click="closeMenu(); dialog = { copy: false }"><Icon name="compose" :size="18" /> {{ t.__('Edit event', 'membuild-community') }}</button>
              <button role="menuitem" @click="closeMenu(); dialog = { copy: true }"><Icon name="plus" :size="18" /> {{ t.__('Duplicate', 'membuild-community') }}</button>
              <button v-if="state !== 'cancelled' && state !== 'past'" role="menuitem" class="danger" @click="cancel"><Icon name="close" :size="18" /> {{ t.__('Cancel event', 'membuild-community') }}</button>
              <button role="menuitem" class="danger" @click="remove"><Icon name="trash" :size="18" /> {{ t.__('Delete', 'membuild-community') }}</button>
            </div>
          </div>
        </div>

        <div class="host">
          <Avatar :user="post.author" :size="32" link />
          <span>{{ t.sprintf(/* translators: %s: the host's name */ t.__('Hosted by %s', 'membuild-community'), post.author?.name) }}<template v-if="e.group"> · {{ e.group.name }}</template></span>
        </div>

        <!-- One row of actions: RSVP, calendar, join. -->
        <div v-if="state !== 'cancelled' && state !== 'past'" class="actions">
          <button class="btn" :class="e.my_rsvp === 'going' ? 'btn-primary' : 'btn-ghost'" :aria-pressed="e.my_rsvp === 'going'" @click="rsvp('going')">
            <Icon v-if="e.my_rsvp === 'going'" name="check" :size="16" /> {{ t.__('Going', 'membuild-community') }}
          </button>
          <button class="btn btn-ghost" :class="{ on: e.my_rsvp === 'interested' }" :aria-pressed="e.my_rsvp === 'interested'" @click="rsvp('interested')">
            <span aria-hidden="true">★</span> {{ t.__('Interested', 'membuild-community') }}
          </button>
          <div class="cal-wrap">
            <button class="btn btn-ghost" aria-haspopup="menu" :aria-expanded="calOpen" @click="calOpen = !calOpen"><Icon name="calendar" :size="16" /> {{ t.__('Add to calendar', 'membuild-community') }}</button>
            <div v-if="calOpen" class="menu cal" role="menu" @click="calOpen = false">
              <a :href="links.google" target="_blank" rel="noopener" role="menuitem">Google Calendar</a>
              <button role="menuitem" @click="downloadIcs({ ...e, description: post.content }, pageUrl, cfg.siteName)">Apple Calendar</button>
              <a :href="links.outlook" target="_blank" rel="noopener" role="menuitem">Outlook</a>
              <button role="menuitem" @click="downloadIcs({ ...e, description: post.content }, pageUrl, cfg.siteName)">{{ t.__('Other (.ics file)', 'membuild-community') }}</button>
            </div>
          </div>
          <a v-if="e.url && state === 'live' && !player" :href="e.url" class="btn btn-primary join" target="_blank" rel="noopener">
            <Icon name="external" :size="16" /> {{ joinLabel }}
          </a>
          <span v-else-if="e.url && state !== 'live'" class="join-note">
            <Icon name="clock" :size="14" />
            {{ player || e.kind === 'youtube' || e.kind === 'vimeo' || e.kind === 'twitch' ? t.__('The stream plays here 15 minutes before the start.', 'membuild-community') : t.sprintf(/* translators: %s: Zoom, Google Meet… or "the link" */ t.__('The %s button appears 15 minutes before the start.', 'membuild-community'), service || t.__('Join', 'membuild-community')) }}
          </span>
        </div>
        <a v-else-if="state === 'past' && e.recording && !player" :href="e.recording" class="btn btn-primary" target="_blank" rel="noopener"><Icon name="play" :size="16" /> {{ t.__('Watch the recording', 'membuild-community') }}</a>
        <p v-else-if="state === 'cancelled'" class="cancelled">{{ t.__('This event was cancelled.', 'membuild-community') }}</p>

        <div v-if="e.going_count || e.interested_count" class="people">
          <span v-if="e.faces.length" class="faces"><Avatar v-for="u in e.faces" :key="u.id" :user="u" :size="28" /></span>
          <span>{{ goingLabel(e.going_count) }}<template v-if="e.interested_count"> · {{ t.sprintf(/* translators: %d: number interested */ t.__('%d interested', 'membuild-community'), e.interested_count) }}</template></span>
        </div>

        <RichText v-if="post.content" class="desc" :text="post.content" />
      </div>

      <PostActions :post="post" class="post-actions" />
      <div class="comments">
        <CommentThread :post="post" />
      </div>
    </article>

    <EventDialog v-if="dialog" :event="post" :copy="dialog.copy" @close="dialog = null" @saved="saved" />
  </div>
</template>

<style scoped>
.event {
  max-width: 760px;
  margin: 0 auto;
  padding: 16px;
}

.back {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--text-2);
}

.main {
  overflow: hidden;
}

.hero,
.player {
  position: relative;
  aspect-ratio: 16 / 7;
  background: #000 center / cover no-repeat;
}

.player {
  aspect-ratio: 16 / 9;
}

.player iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.play {
  position: absolute;
  inset: 0;
  margin: auto;
  display: grid;
  place-items: center;
  width: 68px;
  height: 68px;
  border-radius: 50%;
  background: rgb(0 0 0 / 60%);
  color: #fff;
}

.badge {
  position: absolute;
  top: 12px;
  right: 12px;
  padding: 5px 12px;
  border-radius: 999px;
  background: rgb(0 0 0 / 65%);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
}

.body {
  padding: 18px 20px 6px;
}

.when {
  margin: 0 0 4px;
  color: var(--accent-text);
  font-weight: 700;
  font-size: 14px;
}

.zone {
  color: var(--text-3);
  font-weight: 500;
}

.now {
  margin-left: 6px;
  color: #e5484d;
  font-style: normal;
}

.until {
  color: var(--text-3);
  font-style: normal;
  font-weight: 600;
}

.title-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

h1 {
  flex: 1;
  margin: 0;
  font-size: 24px;
  line-height: 1.2;
}

.menu-wrap,
.cal-wrap {
  position: relative;
}

.icon-btn {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  color: var(--text-2);
}

.icon-btn:hover {
  background: var(--surface-2);
}

.menu {
  position: absolute;
  right: 0;
  top: 100%;
  z-index: 5;
  min-width: 190px;
  padding: 6px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  box-shadow: 0 8px 24px rgb(0 0 0 / 14%);
}

.menu.cal {
  left: 0;
  right: auto;
}

.menu button,
.menu a {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 10px;
  border-radius: 8px;
  color: var(--text);
  font-weight: 600;
  text-align: left;
}

.menu button:hover,
.menu a:hover {
  background: var(--surface-2);
}

.menu .danger {
  color: var(--danger);
}

.host {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  color: var(--text-2);
  font-size: 14px;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 16px;
}

.actions .btn-ghost {
  color: var(--text);
}

.actions .btn-ghost.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.join {
  margin-left: auto;
}

.join-note {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  width: 100%;
  color: var(--text-3);
  font-size: 13px;
}

.cancelled {
  margin: 14px 0 0;
  color: var(--danger);
  font-weight: 650;
}

.people {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 14px;
  color: var(--text-2);
  font-size: 14px;
}

.faces {
  display: flex;
}

.faces > * + * {
  margin-left: -8px;
}

.faces :deep(img),
.faces :deep(.avatar) {
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.desc {
  margin-top: 16px;
  font-size: 15.5px;
}

.post-actions {
  margin-top: 8px;
}

.comments {
  padding: 4px 16px 16px;
}

.state {
  max-width: 480px;
  margin: 60px auto;
  text-align: center;
  color: var(--text-2);
}

@media (max-width: 899px) {
  .event {
    padding: 10px 0;
  }

  .back {
    margin-left: 12px;
  }

  .main {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  .body {
    padding: 16px 16px 6px;
  }

  h1 {
    font-size: 21px;
  }

  .join {
    width: 100%;
    justify-content: center;
    margin-left: 0;
  }
}
</style>
