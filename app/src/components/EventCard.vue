<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { setRsvp } from '../stores/feed.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { dateBadge, stateOf, untilLabel, whenShort } from '../lib/events.js'
import { t } from '../lib/i18n.js'

/**
 * An event, as a card: in the Events tab and inside its post in the feed.
 * The date in a badge, the title, when, who's going, and one Going button.
 */
const props = defineProps({
  post: { type: Object, required: true }, // a post with .event
  compact: { type: Boolean, default: false }, // inside a feed post: no cover
})
const router = useRouter()
const e = computed(() => props.post.event)
const state = computed(() => stateOf(e.value))
const badge = computed(() => dateBadge(e.value))
const cover = computed(() => (props.compact ? null : props.post.media.find((m) => m.kind === 'image')?.url || null))
const fallback = computed(() => `linear-gradient(135deg, hsl(${(props.post.id * 67) % 360} 65% 50%), hsl(${(props.post.id * 67 + 50) % 360} 60% 36%))`)
const open = () => router.push({ name: 'event', params: { id: props.post.id } })

function toggleGoing(ev) {
  ev.stopPropagation()
  setRsvp(props.post, e.value.my_rsvp === 'going' ? null : 'going').catch((err) => alert(err.message))
}
const goingLabel = (n) => t.sprintf(/* translators: %d: number of members going */ t._n('%d going', '%d going', n, 'membuild-community'), n)
</script>

<template>
  <div class="event-card" :class="{ compact, cancelled: state === 'cancelled', past: state === 'past' }" role="link" tabindex="0" @click="open" @keydown.enter="open">
    <div v-if="!compact" class="cover" :style="cover ? { backgroundImage: `url(${cover})` } : { background: fallback }">
      <span class="date"><small>{{ badge.month }}</small><strong>{{ badge.day }}</strong></span>
      <span v-if="state === 'live'" class="live">● {{ t.__('Live now', 'membuild-community') }}</span>
      <span v-else-if="state === 'cancelled'" class="live off">{{ t.__('Cancelled', 'membuild-community') }}</span>
    </div>
    <div class="body">
      <span v-if="compact" class="date inline"><small>{{ badge.month }}</small><strong>{{ badge.day }}</strong></span>
      <div class="text">
        <span class="when">
          {{ whenShort(e) }}
          <em v-if="state === 'live'" class="now">● {{ t.__('Live now', 'membuild-community') }}</em>
          <em v-else-if="state === 'cancelled'" class="now off">{{ t.__('Cancelled', 'membuild-community') }}</em>
          <em v-else-if="state === 'soon' || state === 'upcoming'" class="until">· {{ untilLabel(e) }}</em>
        </span>
        <component :is="compact ? 'h2' : 'h3'" class="title">{{ e.title }}</component>
        <span v-if="e.going_count || e.interested_count" class="people">
          <span v-if="e.faces.length" class="faces"><Avatar v-for="u in e.faces" :key="u.id" :user="u" :size="22" /></span>
          <span>{{ goingLabel(e.going_count) }}<template v-if="e.interested_count"> · {{ t.sprintf(/* translators: %d: number interested */ t.__('%d interested', 'membuild-community'), e.interested_count) }}</template></span>
        </span>
      </div>
      <button v-if="state !== 'past' && state !== 'cancelled'" class="btn going" :class="e.my_rsvp === 'going' ? 'btn-primary' : 'btn-ghost'" @click="toggleGoing">
        <Icon v-if="e.my_rsvp === 'going'" name="check" :size="16" />
        {{ e.my_rsvp === 'going' ? t.__('Going', 'membuild-community') : t._x('Going?', 'button: say you will attend', 'membuild-community') }}
      </button>
      <span v-else-if="state === 'past' && e.recording" class="replay"><Icon name="play" :size="14" /> {{ t.__('Recording', 'membuild-community') }}</span>
    </div>
  </div>
</template>

<style scoped>
.event-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: var(--radius);
  background: var(--surface);
  border: 1px solid var(--border);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.event-card:not(.compact):hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-pop);
}

.event-card.compact {
  margin: 0 14px 10px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  border: 0;
}

.cover {
  position: relative;
  aspect-ratio: 16 / 7;
  background: center / cover no-repeat;
}

.past .cover {
  filter: saturate(0.6);
}

.date {
  position: absolute;
  top: 12px;
  left: 12px;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 48px;
  padding: 5px 8px;
  border-radius: var(--radius-sm);
  background: var(--paper);
  color: var(--ink);
  line-height: 1.1;
}

.date small {
  font-size: var(--fs-2xs);
  font-weight: 700;
  text-transform: uppercase;
  color: var(--live-ink);
}

.date strong {
  font-size: var(--fs-3xl);
}

.date.inline {
  position: static;
  flex: none;
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
}

/* Inside a post the date sits on the card, not on paper: the page's red. */
.date.inline small {
  color: var(--live-text);
}

.live {
  position: absolute;
  top: 12px;
  right: 12px;
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  background: var(--live);
  color: var(--on-color);
  font-size: var(--fs-xs);
  font-weight: 750;
}

.live.off {
  background: color-mix(in srgb, var(--scrim) 65%, transparent);
}

.body {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px 14px;
}

.text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.when {
  display: flex;
  flex-wrap: wrap;
  gap: 2px 6px;
  color: var(--accent-text);
  font-size: var(--fs-sm);
  font-weight: 700;
}

.now {
  color: var(--live-text);
  font-style: normal;
  white-space: nowrap;
}

.now.off {
  color: var(--text-3);
}

.until {
  color: var(--text-3);
  font-style: normal;
  font-weight: 600;
  white-space: nowrap;
}

.title {
  margin: 0;
  font-size: var(--fs-lg);
  line-height: 1.25;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.cancelled .title {
  text-decoration: line-through;
  color: var(--text-3);
}

.people {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
  color: var(--text-2);
  font-size: var(--fs-sm);
}

.faces {
  display: flex;
}

.faces > * + * {
  margin-left: -7px;
}

.faces :deep(img),
.faces :deep(.avatar) {
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.going {
  flex: none;
  gap: 6px;
}

.replay {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  flex: none;
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 650;
}

@media (max-width: 480px) {
  .body {
    flex-wrap: wrap;
  }

  .going {
    width: 100%;
    justify-content: center;
  }
}
</style>
