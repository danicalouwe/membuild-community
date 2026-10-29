<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { canDock, openChat } from '../stores/chatDock.js'
import { showToast } from '../lib/toast.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { levelLabel } from '../config.js'
import { t, locale } from '../lib/i18n.js'
import { CATEGORY } from '../lib/places.js'

/**
 * One search result: a member, post, reel, comment, course, lesson, event or place, as the
 * server's Search class returns it. Matched words are highlighted by splitting
 * the text (never as HTML), so nothing in a post can inject markup.
 */
const props = defineProps({
  item: { type: Object, required: true },
  words: { type: Array, default: () => [] },
  message: { type: Boolean, default: false }, // the results page offers Message beside members
})
const emit = defineEmits(['open'])
const route = useRoute()
const router = useRouter()

const day = (iso) => new Date(iso).toLocaleDateString(locale, { month: 'short', day: 'numeric' })

/* An event: its day on a little calendar, and when in plain words ("Thursday, 7:00 PM EDT"). */
const eventDay = computed(() => {
  const i = props.item
  if (i.kind !== 'event') return null
  const opts = i.tz ? { timeZone: i.tz } : {}
  const d = new Date(i.at)
  return { month: d.toLocaleDateString(locale, { month: 'short', ...opts }), day: d.toLocaleDateString(locale, { day: 'numeric', ...opts }) }
})
function eventWhen(i) {
  const d = new Date(i.at)
  const soon = d - Date.now() < 6 * 86400000 && d > Date.now()
  return new Intl.DateTimeFormat(locale, { ...(soon ? { weekday: 'long' } : { month: 'short', day: 'numeric' }), hour: 'numeric', minute: '2-digit', timeZoneName: 'short', ...(i.tz ? { timeZone: i.tz } : {}) }).format(d)
}

const messaging = ref(false)
async function startMessage() {
  messaging.value = true
  try {
    const chat = await api.startChat([props.item.user.id])
    if (canDock()) openChat(chat)
    else router.push({ name: 'messages', params: { threadId: chat.id } })
  } catch (err) {
    showToast(err.message)
  } finally {
    messaging.value = false
  }
}

// Posts and comments open on top of the current page (?post=ID), like notifications do.
const to = computed(() => (props.item.link.name ? props.item.link : { path: route.path, query: { ...route.query, ...props.item.link.query } }))

// The whole word that contains a match is marked ("launches" for "launch"), like the boards.
function parts(text) {
  if (!text || !props.words.length) return [{ text: text || '', hit: false }]
  const escaped = props.words.map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
  const any = new RegExp(escaped.join('|'), 'iu')
  return String(text)
    .split(new RegExp(`([\\p{L}\\p{N}]*(?:${escaped.join('|')})[\\p{L}\\p{N}]*)`, 'giu'))
    .filter(Boolean)
    .map((piece) => ({ text: piece, hit: any.test(piece) }))
}

const title = computed(() => {
  const i = props.item
  if (i.kind === 'member') return i.user.name
  if (['course', 'lesson', 'place', 'event'].includes(i.kind)) return i.title
  return i.snippet
})

const detail = computed(() => {
  const i = props.item
  switch (i.kind) {
    case 'member':
      return i.snippet || levelLabel(i.user.level)
    case 'post':
    case 'reel': {
      const by = i.kind === 'reel' ? t.sprintf(/* translators: %s: a name */ t.__('Reel by %s', 'membuild-community'), i.user.name) : t.sprintf(/* translators: %s: a name */ t.__('Post by %s', 'membuild-community'), i.user.name)
      const bits = [by, day(i.created_at)]
      if (i.comments) bits.push(t.sprintf(/* translators: %s: number of comments */ t._n('%s comment', '%s comments', i.comments, 'membuild-community'), i.comments.toLocaleString(locale)))
      return bits.join(' · ')
    }
    case 'comment':
      return i.on
        ? t.sprintf(/* translators: 1: who wrote the comment, 2: the start of the post it's on */ t.__('Comment by %1$s on “%2$s…”', 'membuild-community'), i.user.name, i.on.replace(/…$/, ''))
        : t.sprintf(/* translators: %s: who wrote the comment */ t.__('Comment by %s', 'membuild-community'), i.user.name)
    case 'event': {
      const where = i.where === 'online' ? t.__('Online', 'membuild-community') : i.venue || (i.where === 'both' ? t.__('In person and online', 'membuild-community') : t.__('In person', 'membuild-community'))
      return [i.past ? t.__('Past event', 'membuild-community') : t.__('Event', 'membuild-community'), eventWhen(i), where].join(' · ')
    }
    case 'course':
      return [t.__('Course', 'membuild-community'), t.sprintf(/* translators: %s: number of lessons */ t._n('%s lesson', '%s lessons', i.lessons, 'membuild-community'), i.lessons), lockText(i)].filter(Boolean).join(' · ')
    case 'place':
      return [CATEGORY[i.category]?.label, i.perk ? `🎁 ${i.perk}` : i.address].filter(Boolean).join(' · ')
    case 'lesson':
      return [t.sprintf(/* translators: %s: course title */ t.__('Lesson in %s', 'membuild-community'), i.course), lockText(i)].filter(Boolean).join(' · ')
  }
  return ''
})

function lockText(i) {
  if (i.draft) return t.__('Draft', 'membuild-community')
  if (i.lock?.reason === 'level') return t.sprintf(/* translators: %d: level number */ t.__('Unlocks at level %d', 'membuild-community'), i.lock.level)
  if (i.lock?.reason === 'groups') return i.lock.groups?.length ? t.sprintf(/* translators: %s: group names */ t.__('For %s', 'membuild-community'), i.lock.groups.join(', ')) : t.__('For a member group', 'membuild-community')
  if (i.unlock_at) return t.sprintf(/* translators: %s: a date */ t.__('Unlocks %s', 'membuild-community'), new Date(i.unlock_at).toLocaleDateString(locale, { month: 'short', day: 'numeric' }))
  return ''
}

const locked = computed(() => !!(props.item.lock || props.item.unlock_at))
// For a course or lesson found by its text, the quote goes under the title.
const quote = computed(() => (['course', 'lesson'].includes(props.item.kind) ? props.item.snippet : ''))
</script>

<template>
  <div class="row" :class="{ 'has-msg': message && item.kind === 'member' }">
    <RouterLink :to="to" class="result" @click="emit('open')">
      <Avatar v-if="item.user && item.kind === 'member'" :user="item.user" :size="48" online="bottom" />
      <span v-else-if="eventDay" class="cal" :class="{ past: item.past }"><small>{{ eventDay.month }}</small><b>{{ eventDay.day }}</b></span>
      <!-- A post with a picture shows it; a reel or video post gets a play badge, so it reads as video at a glance. -->
      <span v-else-if="item.thumb" class="thumb" :class="{ tall: item.kind === 'reel', video: item.video }">
        <img :src="item.thumb" alt="" />
        <span v-if="item.video" class="play"><Icon name="play" :size="14" /></span>
        <Avatar v-if="item.user" :user="item.user" :size="20" class="who" />
      </span>
      <Avatar v-else-if="item.user" :user="item.user" :size="48" />
      <span v-else class="thumb" :class="{ locked, icon: !item.cover || locked }">
        <img v-if="item.cover && !locked" :src="item.cover" alt="" />
        <Icon v-else :name="locked ? 'lock' : item.kind === 'place' ? 'mapPin' : item.kind === 'lesson' ? 'book' : 'cap'" :size="20" />
      </span>
      <span class="text">
        <span class="title" :class="{ name: ['member', 'course', 'lesson', 'event', 'place'].includes(item.kind) }"><template v-for="(p, n) in parts(title)" :key="n"><mark v-if="p.hit">{{ p.text }}</mark><template v-else>{{ p.text }}</template></template></span>
        <span v-if="quote" class="quote"><template v-for="(p, n) in parts(quote)" :key="n"><mark v-if="p.hit">{{ p.text }}</mark><template v-else>{{ p.text }}</template></template></span>
        <span v-if="item.kind === 'member'" class="bio"><template v-for="(p, n) in parts(detail)" :key="n"><mark v-if="p.hit">{{ p.text }}</mark><template v-else>{{ p.text }}</template></template></span>
        <span v-else class="detail" :class="{ lock: locked }"><Icon v-if="locked" name="lock" :size="13" />{{ detail }}</span>
      </span>
    </RouterLink>
    <button v-if="message && item.kind === 'member'" type="button" class="msg" :disabled="messaging" :aria-label="t.sprintf(t.__('Message %s', 'membuild-community'), item.user.name)" @click="startMessage"><Icon name="chat" :size="20" /><span>{{ t.__('Message', 'membuild-community') }}</span></button>
  </div>
</template>

<style scoped>
.row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.result {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 14px;
  min-width: 0;
  padding: 10px 16px;
  border-radius: var(--radius-sm);
  color: var(--text);
}

.cal {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 10px;
  color: var(--accent-text);
  background: var(--accent-soft);
  line-height: 1;
}

.cal small {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}

.cal b {
  margin-top: 2px;
  font-size: 19px;
}

.cal.past {
  color: var(--text-2);
  background: var(--surface-2);
}

.msg {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 8px;
  min-height: 52px;
  margin-right: 16px;
  padding: 0 22px;
  border-radius: 999px;
  color: var(--text);
  background: var(--surface-2);
  font-size: 16px;
  font-weight: 700;
}

.msg:hover {
  background: var(--border);
}

.result:hover,
.result:focus-visible {
  background: var(--surface-2);
  outline: none;
}

.thumb {
  flex: none;
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  overflow: hidden;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.thumb.locked {
  background: var(--surface-2);
  color: var(--text-2);
}

.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb.icon:not(.locked) {
  color: var(--text-2);
  background: var(--surface-2);
}

.thumb.tall {
  width: 38px;
  height: 52px;
  border-radius: 8px;
}

.thumb:has(.who),
.thumb.video {
  position: relative;
  overflow: visible;
  background: #000;
}

.thumb:has(.who) img,
.thumb:has(.who) .play {
  border-radius: inherit;
}

/* Who posted it, on the corner of the picture, ringed in the panel colour. */
.thumb .who {
  position: absolute;
  right: -5px;
  bottom: -5px;
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.play {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  color: #fff;
  background: rgba(0, 0, 0, 0.28);
}

.play svg {
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.5));
}

.text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 1px;
}

.title {
  font-size: 16px;
  font-weight: 600;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
}

.title.name {
  font-size: 17px;
  font-weight: 700;
}

.bio {
  color: var(--text);
  font-size: 15px;
  line-height: 1.45;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.quote {
  color: var(--text);
  font-size: 15px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.detail {
  color: var(--text-2);
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detail.lock {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

mark {
  background: color-mix(in srgb, #ffd43b 45%, transparent);
  color: inherit;
  border-radius: 3px;
  padding: 0 1px;
}

/* Phones: Message is a round 52px button, so the bio keeps its room. */
@media (max-width: 767px) {
  .result {
    padding: 10px 16px;
  }

  .msg {
    width: 52px;
    margin-right: 16px;
    padding: 0;
    justify-content: center;
  }

  .msg span {
    display: none;
  }
}
</style>
