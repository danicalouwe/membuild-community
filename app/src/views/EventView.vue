<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { eventList, removePost, setRsvp, updatePost, watchPost } from '../stores/feed.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import RichText from '../components/RichText.vue'
import PostActions from '../components/PostActions.vue'
import CommentThread from '../components/CommentThread.vue'
import { calendarLinks, cityOfAddress, dateBadge, downloadIcs, otherZone, plainCover, playerUrl, serviceName, startOf, stateOf, timeRange, timeRangeIn, untilLabel } from '../lib/events.js'
import { directionsUrl } from '../lib/places.js'
import { useRoute } from 'vue-router'
import BaseDialog from '../components/BaseDialog.vue'
import { locale } from '../lib/i18n.js'
import { t } from '../lib/i18n.js'
import { useMenu } from '../lib/useMenu.js'
const EventForm = defineAsyncComponent(() => import('../components/EventForm.vue'))

/**
 * One event, in two columns (Version 2): the cover (or the stream / replay playing
 * here), how soon, the title, the host, About this event and the questions on the
 * left; a card on the right with the date, time, where, one I'm going button, Join
 * (from 15 minutes before) and Add to calendar. The host also gets a bar with Edit
 * and Duplicate, how many are going (only they see it), Join any time, Cancel, and on
 * a past event a box to add the replay. Phones: one column, I'm going at the bottom.
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
const host = computed(() => !!e.value?.can_edit)
const badge = computed(() => (e.value ? dateBadge(e.value) : null))
// In person somewhere else in the world: the day and time where it's held ("Austin time"), and yours under it.
const here = computed(() => !!e.value && otherZone(e.value))
const placeCity = computed(() => cityOfAddress(e.value?.place?.address))
const longDay = computed(() => (e.value ? new Intl.DateTimeFormat(locale, { weekday: 'long', month: 'long', day: 'numeric', timeZone: here.value ? e.value.tz : undefined }).format(startOf(e.value)) : ''))
const inPerson = computed(() => !!e.value?.place && e.value.where !== 'online')
const mapsUrl = computed(() => (inPerson.value ? (e.value.place.lat !== null ? directionsUrl({ lat: e.value.place.lat, lng: e.value.place.lng, name: e.value.place.name, address: e.value.place.address }) : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent([e.value.place.name, e.value.place.address].filter(Boolean).join(', '))}`) : ''))
async function cancelAll() {
  if (!(await askConfirm({ title: t.__('Cancel every one still to come?', 'membuild-community'), text: t.__('This one and all the later ones in the series are cancelled. Everyone who said they’re going is told.', 'membuild-community'), confirm: t.__('Cancel them all', 'membuild-community'), cancel: t.__('Keep them', 'membuild-community'), danger: true }))) return
  try {
    const saved = await api.cancelEvent(props.id, 'all')
    post.value = saved
    updatePost(props.id, saved)
  } catch (err) {
    alert(err.message)
  }
}
const joinAt = computed(() => (e.value ? new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(new Date(startOf(e.value).getTime() - 15 * 60000)) : ''))
const ago = (days) => (days < 1 ? t.__('Ended today', 'membuild-community') : t.sprintf(/* translators: %d: days */ t._n('Ended %d day ago', 'Ended %d days ago', days, 'membuild-community'), days))
// The pill over the title: how soon, live, replay, ended, cancelled.
const pill = computed(() => {
  if (!e.value) return null
  if (state.value === 'cancelled') return { cls: 'off', text: t.__('Cancelled', 'membuild-community') }
  if (state.value === 'live') return { cls: 'live', text: `● ${t.__('Live now', 'membuild-community')}` }
  if (state.value === 'past') {
    if (e.value.recording) return { cls: 'ok', text: t.__('Replay available', 'membuild-community') }
    return { cls: 'grey', text: ago(Math.floor((now.value - startOf(e.value).getTime()) / 86400000)) }
  }
  const s = untilLabel(e.value, now.value)
  return { cls: '', text: t.sprintf(/* translators: %s: e.g. "in 2 days" */ t.__('Starts %s', 'membuild-community'), s) }
})
const isStream = computed(() => ['youtube', 'vimeo', 'twitch'].includes(e.value?.kind))
const whereTitle = computed(() => {
  if (state.value === 'past') return e.value?.recording ? t.__('Recorded online', 'membuild-community') : service.value ? t.sprintf(/* translators: %s: Zoom, Google Meet… */ t.__('Was online, on %s', 'membuild-community'), service.value) : t.__('Was online', 'membuild-community')
  return service.value ? t.sprintf(/* translators: %s: Zoom, Google Meet… */ t.__('Online, on %s', 'membuild-community'), service.value) : t.__('Online', 'membuild-community')
})
const whereNote = computed(() => {
  if (state.value === 'past') return e.value?.recording ? t.__('Watch any time', 'membuild-community') : t.__('No replay yet', 'membuild-community')
  if (state.value === 'live') return isStream.value ? t.__('Playing on this page', 'membuild-community') : t.__('It’s open now', 'membuild-community')
  return isStream.value ? t.__('The stream plays on this page', 'membuild-community') : t.__('The Join button appears here', 'membuild-community')
})

/* ---------- Host: who's going, the replay ---------- */
const route = useRoute()
const goingOpen = ref(false)
const goingList = ref(null)
async function openGoing() {
  goingOpen.value = true
  goingList.value = null
  try {
    goingList.value = (await api.eventGoing(props.id)).items.filter((r) => r.status === 'going')
  } catch {
    goingList.value = []
  }
}
const replayUrl = ref('')
const replayBusy = ref(false)
const replayError = ref('')
const replayInput = ref(null)
watch(
  () => [post.value, route.query.replay],
  () => route.query.replay && post.value && setTimeout(() => replayInput.value?.focus(), 50),
)
async function saveReplay() {
  const url = replayUrl.value.trim()
  if (!url) return
  replayBusy.value = true
  replayError.value = ''
  try {
    const saved = await api.updateEvent(props.id, { recording: url })
    post.value = saved
    updatePost(props.id, saved)
    replayUrl.value = ''
  } catch (err) {
    replayError.value = err.message
  } finally {
    replayBusy.value = false
  }
}
const pageUrl = computed(() => location.origin + router.resolve({ name: 'event', params: { id: props.id } }).href)
// The event as a calendar entry: its own id (so two events never replace each other), the words trimmed.
const calEvent = computed(() => (e.value ? { ...e.value, id: post.value.id, description: (post.value.content || '').trim() } : null))
const links = computed(() => (calEvent.value ? calendarLinks(calEvent.value, pageUrl.value) : null))
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
  if (!(await askConfirm({ title: t.__('Cancel this event?', 'membuild-community'), text: t.__('Everyone who said they’re going will be told.', 'membuild-community'), confirm: t.__('Cancel event', 'membuild-community'), cancel: t.__('Keep it', 'membuild-community'), danger: true }))) return
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
  if (!(await askConfirm({ title: t.__('Delete this event and its comments?', 'membuild-community'), text: t.__('This can’t be undone.', 'membuild-community'), confirm: t.__('Delete event', 'membuild-community'), danger: true }))) return
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

  <EventForm v-else-if="post && dialog" :event="post" :copy="dialog.copy" @close="dialog = null" @saved="saved" />

  <div v-else-if="post" class="event page-in" :class="{ hosting: host }">
    <RouterLink :to="{ name: 'events' }" class="back-link"><Icon name="chevronLeft" :size="18" /> {{ t.__('Events', 'membuild-community') }}</RouterLink>

    <!-- The host's own bar. -->
    <div v-if="host" class="host-bar card">
      <p><strong>{{ t.__('You’re hosting this event.', 'membuild-community') }}</strong> {{ t.__('Only you see this bar.', 'membuild-community') }}</p>
      <button class="btn btn-lg btn-ghost" @click="dialog = { copy: false }"><Icon name="pencil" :size="18" /> {{ t.__('Edit event', 'membuild-community') }}</button>
      <button class="btn btn-lg btn-ghost" @click="dialog = { copy: true }">{{ t.__('Duplicate', 'membuild-community') }}</button>
    </div>

    <div class="cols">
      <div class="left">
        <!-- The stream or the replay, playing here; otherwise the cover. -->
        <div v-if="player && (watching || state === 'live')" class="media player">
          <iframe :src="player" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen :title="e.title" />
        </div>
        <div v-else class="media hero" :style="cover ? { backgroundImage: `url(${cover})` } : plainCover()">
          <button v-if="player" class="play" :aria-label="t.__('Watch the replay', 'membuild-community')" @click="watching = true"><Icon name="play" :size="30" /></button>
        </div>

        <span v-if="pill" class="status-tag lg page-status" :class="{ off: 'bad' }[pill.cls] || pill.cls || 'accent'">{{ pill.text }}</span>
        <h1>{{ e.title }}</h1>
        <div class="hosted">
          <Avatar :user="post.author" :size="48" link />
          <span><small>{{ t.__('Hosted by', 'membuild-community') }}</small><strong>{{ post.author?.name }}</strong></span>
        </div>

        <section v-if="post.content" class="card box">
          <h2>{{ t.__('About this event', 'membuild-community') }}</h2>
          <RichText class="desc" :text="post.content" />
        </section>

        <section class="card box talk">
          <h2>{{ state === 'past' ? t.__('Comments', 'membuild-community') : t.__('Questions before the event?', 'membuild-community') }}</h2>
          <CommentThread :post="post" />
        </section>
      </div>

      <!-- The card: when, where, and what to do. -->
      <aside class="side card">
        <div class="when">
          <span class="tile"><small>{{ badge.month }}</small><strong>{{ badge.day }}</strong></span>
          <span>
            <strong>{{ longDay }}</strong>
            <span>{{ here ? timeRangeIn(e) : timeRange(e) }}<template v-if="here && placeCity"> ({{ t.sprintf(/* translators: %s: a city */ t.__('%s time', 'membuild-community'), placeCity) }})</template></span>
            <small v-if="here" class="yours">{{ t.sprintf(/* translators: %s: a time range */ t.__('Your time: %s', 'membuild-community'), timeRange(e)) }}</small>
          </span>
        </div>

        <div v-if="inPerson" class="row">
          <span class="ic"><Icon name="mapPin" :size="20" /></span>
          <span>
            <RouterLink v-if="e.place.id" :to="{ name: 'places', params: { placeId: e.place.id } }" class="place-link">{{ e.place.name }} <Icon name="chevronRight" :size="16" /></RouterLink>
            <strong v-else>{{ e.place.name }}</strong>
            <small v-if="e.place.address">{{ e.place.address }}</small>
            <a :href="mapsUrl" target="_blank" rel="noopener" class="maps">{{ t.__('Open in Maps', 'membuild-community') }} <Icon name="external" :size="14" /></a>
          </span>
        </div>

        <div v-if="e.where !== 'person'" class="row">
          <span class="ic"><Icon name="video" :size="20" /></span>
          <span><strong>{{ whereTitle }}</strong><small>{{ whereNote }}</small></span>
        </div>

        <div v-if="host && state !== 'cancelled'" class="row">
          <span class="ic"><Icon name="users" :size="20" /></span>
          <span>
            <strong>{{ state === 'past' ? t.sprintf(/* translators: %d: members who went */ t._n('%d went', '%d went', e.going_count, 'membuild-community'), e.going_count) : goingLabel(e.going_count) }}</strong>
            <small><button v-if="e.going_count" class="link" @click="openGoing">{{ state === 'past' ? t.__('See who came', 'membuild-community') : t.__('See who’s going', 'membuild-community') }}</button><template v-if="e.going_count"> · </template>{{ t.__('only you see this', 'membuild-community') }}</small>
          </span>
        </div>

        <!-- Upcoming / live -->
        <template v-if="state !== 'past' && state !== 'cancelled'">
          <a v-if="e.url && !player && (state === 'live' || host)" :href="e.url" class="btn btn-lg btn-primary main" target="_blank" rel="noopener"><Icon name="video" :size="18" /> {{ joinLabel }}</a>
          <button v-if="!host" class="btn btn-lg main rsvp" :class="e.my_rsvp === 'going' ? 'btn-ghost going' : 'btn-primary'" :aria-pressed="e.my_rsvp === 'going'" @click="rsvp('going')">
            <template v-if="e.my_rsvp === 'going'"><Icon :key="'going'" class="pop-once" name="check" :size="18" /> {{ t.__('You’re going', 'membuild-community') }}</template>
            <template v-else>{{ t.__('I’m going', 'membuild-community') }}</template>
          </button>
          <p v-if="host && e.url && !isStream" class="note">{{ t.sprintf(/* translators: %s: a time, e.g. "12:45 PM ET" */ t.__('You can open it any time. Members’ button turns on at %s.', 'membuild-community'), joinAt) }}</p>
          <p v-else-if="e.url && state !== 'live'" class="note">
            <template v-if="isStream">{{ t.__('The stream plays on this page 15 minutes before it starts.', 'membuild-community') }}</template>
            <template v-else>{{ t.sprintf(/* translators: 1: "Join on Google Meet", 2: a time */ t.__('%1$s turns on at %2$s, 15 minutes before.', 'membuild-community'), joinLabel, joinAt) }}</template>
          </p>
          <div class="cal-wrap">
            <button class="cal" aria-haspopup="menu" :aria-expanded="calOpen" @click="calOpen = !calOpen"><Icon name="calendar" :size="18" /> {{ t.__('Add to calendar', 'membuild-community') }}</button>
            <div v-if="calOpen" class="menu cal-menu" role="menu" @click="calOpen = false">
              <a :href="links.google" target="_blank" rel="noopener" role="menuitem">Google Calendar</a>
              <button role="menuitem" @click="downloadIcs(calEvent, pageUrl, cfg.siteName)">Apple Calendar</button>
              <a :href="links.outlook" target="_blank" rel="noopener" role="menuitem">Outlook</a>
              <button role="menuitem" @click="downloadIcs(calEvent, pageUrl, cfg.siteName)">{{ t.__('Other (.ics file)', 'membuild-community') }}</button>
            </div>
          </div>
          <template v-if="host">
            <hr />
            <button class="link danger" @click="cancel">{{ t.__('Cancel this event…', 'membuild-community') }}</button>
            <button v-if="e.series" class="link danger" @click="cancelAll">{{ t.__('Cancel all still to come…', 'membuild-community') }}</button>
          </template>
        </template>

        <!-- Past -->
        <template v-else-if="state === 'past'">
          <template v-if="e.recording">
            <button v-if="player" class="btn btn-lg btn-primary main" @click="watching = true"><Icon name="play" :size="18" class="play-ic" /> {{ t.__('Watch the replay', 'membuild-community') }}</button>
            <a v-else :href="e.recording" class="btn btn-lg btn-primary main" target="_blank" rel="noopener"><Icon name="play" :size="18" class="play-ic" /> {{ t.__('Watch the replay', 'membuild-community') }}</a>
            <p class="note">{{ t.sprintf(/* translators: %s: a day, e.g. "Monday, October 12" */ t.__('Recorded %s', 'membuild-community'), longDay) }}</p>
          </template>
          <form v-else-if="host" class="replay" @submit.prevent="saveReplay">
            <label for="replay-url">{{ t.__('Add the replay', 'membuild-community') }}</label>
            <input id="replay-url" ref="replayInput" v-model="replayUrl" type="url" inputmode="url" :placeholder="t.__('Paste the video link (YouTube, Vimeo…)', 'membuild-community')" />
            <button class="btn btn-lg btn-primary main" :disabled="!replayUrl.trim() || replayBusy">{{ replayBusy ? t.__('Saving…', 'membuild-community') : t.__('Save the replay', 'membuild-community') }}</button>
            <p v-if="replayError" class="error-text">{{ replayError }}</p>
            <p class="note">{{ t.__('It shows on this page and in Past events. YouTube and Vimeo play right here.', 'membuild-community') }}</p>
          </form>
          <p v-else class="note">{{ t.__('There’s no replay for this one.', 'membuild-community') }}</p>
        </template>

        <p v-else class="note">{{ t.__('This event was cancelled.', 'membuild-community') }}</p>

        <template v-if="host && (state === 'past' || state === 'cancelled')">
          <hr />
          <button class="link danger" @click="remove">{{ t.__('Delete this event…', 'membuild-community') }}</button>
        </template>
      </aside>
    </div>

    <BaseDialog v-if="goingOpen" :title="state === 'past' ? t.__('Who came', 'membuild-community') : t.__('Who’s going', 'membuild-community')" :width="440" max-height="560px" @close="goingOpen = false">
      <p v-if="!goingList" class="going-empty">{{ t.__('Loading…', 'membuild-community') }}</p>
      <ul v-else class="going-list">
        <li v-for="r in goingList" :key="r.user.id"><Avatar :user="r.user" :size="40" link /><strong>{{ r.user.name }}</strong></li>
        <li v-if="!goingList.length" class="going-empty">{{ t.__('Nobody yet.', 'membuild-community') }}</li>
      </ul>
    </BaseDialog>

  </div>

  <!-- Loading: the page's shape, so nothing jumps when it arrives. -->
  <div v-else class="event ev-loading" aria-busy="true">
    <div class="skeleton" style="width: 90px; height: 18px; margin: 12px 0 18px" />
    <div class="ev-sk-grid">
      <div>
        <div class="skeleton" style="aspect-ratio: 16 / 9; border-radius: 16px" />
        <div class="skeleton" style="width: 60%; height: 30px; margin-top: 22px" />
        <div class="skeleton" style="width: 35%; height: 16px; margin-top: 12px" />
      </div>
      <div class="skeleton" style="height: 260px; border-radius: 16px" />
    </div>
  </div>
</template>

<style scoped>
.page-status {
  margin-top: 20px;
}

.ev-sk-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 24px;
}

@media (max-width: 767px) {
  .ev-sk-grid {
    grid-template-columns: 1fr;
  }
}

.event {
  max-width: 1120px;
  margin: 0 auto;
  padding: 20px 16px 48px;
}

.state {
  padding: 60px 16px;
  text-align: center;
}

.back-link {
  margin-bottom: 8px;
}

.host-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
  padding: 10px 12px 10px 18px;
  border-radius: 16px;
}

.host-bar p {
  flex: 1;
  margin: 0;
  color: var(--text-2);
}

.host-bar strong {
  color: var(--text);
}

.cols {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 32px;
  align-items: start;
}

.media {
  position: relative;
  aspect-ratio: 16 / 9;
  overflow: hidden;
  border-radius: 16px;
  background: #000 center / cover no-repeat;
}

.player iframe {
  width: 100%;
  height: 100%;
  border: 0;
}

.play {
  position: absolute;
  top: 50%;
  left: 50%;
  display: grid;
  place-items: center;
  width: 84px;
  height: 84px;
  border-radius: 50%;
  background: rgb(255 255 255 / 94%);
  color: #16181d;
  transform: translate(-50%, -50%);
  box-shadow: 0 4px 18px rgb(0 0 0 / 25%);
}

.play :deep(path),
.side .play-ic :deep(path) {
  fill: currentColor;
}

h1 {
  margin: 14px 0 18px;
  font-size: 34px;
  line-height: 1.2;
}

.hosted {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 26px;
}

.hosted span {
  display: flex;
  flex-direction: column;
}

.hosted small {
  color: var(--text-2);
  font-size: 14px;
}

.hosted strong {
  font-size: 17px;
}

.box {
  margin-bottom: 24px;
  padding: 22px 20px;
  border-radius: 16px;
}

.box h2 {
  margin: 0 0 12px;
  font-size: 20px;
}

/* On this page the comment box sits after the questions, not stuck to the bottom. */
.talk :deep(.composer) {
  position: static;
  margin: 4px -20px -22px;
  padding: 14px 20px 18px;
  border-radius: 0 0 16px 16px;
}

.desc {
  font-size: 16px;
  line-height: 1.6;
}

/* The card on the right stays in view while the page scrolls. */
.side {
  position: sticky;
  top: calc(var(--header-h) + 16px);
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 22px;
  border-radius: 18px;
}

.when {
  display: flex;
  align-items: center;
  gap: 16px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--border);
}

.tile {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 78px;
  border-radius: 14px;
  background: var(--surface-2);
  line-height: 1.05;
}

.tile small {
  color: #e5484d;
  font-size: 13px;
  font-weight: 800;
  text-transform: uppercase;
}

.tile strong {
  font-size: 30px;
}

.when > span:last-child,
.row > span:last-child {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.when strong {
  font-size: 18px;
}

.row {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}

.row .ic {
  display: grid;
  flex: none;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: var(--surface-2);
}

.row strong {
  font-size: 17px;
}

.row small {
  color: var(--text-2);
  font-size: 15px;
}

.yours {
  color: var(--text-2);
  font-size: 14px;
}

.place-link {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  color: var(--text);
  font-size: 17px;
  font-weight: 700;
  text-decoration: none;
}

.place-link:hover {
  text-decoration: underline;
}

.maps {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
  color: var(--accent-text);
  font-weight: 700;
}

.main {
  width: 100%;
  justify-content: center;
  text-decoration: none;
}

.main.going {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.note {
  margin: -6px 0 0;
  color: var(--text-2);
  font-size: 14px;
  line-height: 1.5;
  text-align: center;
}

.cal-wrap {
  position: relative;
  align-self: center;
}

.cal {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 40px;
  color: var(--text);
  font-size: 16px;
  font-weight: 700;
}

.cal-menu {
  position: absolute;
  top: 100%;
  left: 50%;
  z-index: 5;
  display: flex;
  flex-direction: column;
  min-width: 220px;
  padding: 6px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
  box-shadow: 0 8px 24px rgb(0 0 0 / 16%);
  transform: translateX(-50%);
}

.cal-menu a,
.cal-menu button {
  padding: 10px 12px;
  border-radius: 8px;
  color: var(--text);
  font: inherit;
  font-weight: 600;
  text-align: left;
  text-decoration: none;
}

.cal-menu a:hover,
.cal-menu button:hover {
  background: var(--surface-2);
}

.side hr {
  width: 100%;
  margin: 0;
  border: 0;
  border-top: 1px solid var(--border);
}

.link {
  padding: 0;
  color: var(--text);
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
}

.link.danger {
  align-self: center;
  color: var(--danger);
  font-size: 16px;
}

.replay {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.replay label {
  font-weight: 700;
}

.replay input {
  min-height: 48px;
  padding: 10px 14px;
  border: 1px solid var(--border-strong);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
}

.replay .note {
  margin: 0;
}

.going-list {
  margin: 0;
  padding: 8px 16px 16px;
  list-style: none;
}

.going-list li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
}

.going-empty {
  padding: 16px;
  color: var(--text-2);
  text-align: center;
}

/* Phones: one column; I'm going stays at the bottom, above the tab bar. */
@media (max-width: 900px) {
  .cols {
    grid-template-columns: 1fr;
    gap: 0;
  }

  .side {
    position: static;
    order: 2;
    margin-bottom: 20px;
  }

  .left {
    display: contents;
  }

  .media {
    order: 0;
  }

  .pill,
  h1 {
    order: 1;
  }

  .hosted,
  .box {
    order: 3;
  }

}

@media (max-width: 600px) {
  h1 {
    font-size: 26px;
  }

  .host-bar {
    flex-wrap: wrap;
  }

  .host-bar p {
    flex: 1 1 100%;
  }

  .host-bar .btn {
    flex: 1;
  }

  .side .rsvp:not(.going) {
    position: fixed;
    right: 16px;
    bottom: calc(var(--tabbar-h) + 12px + env(safe-area-inset-bottom));
    left: 16px;
    z-index: 15;
    width: auto;
    box-shadow: 0 6px 18px rgb(0 0 0 / 20%);
  }

  .event {
    padding-bottom: 110px;
  }
}
</style>
