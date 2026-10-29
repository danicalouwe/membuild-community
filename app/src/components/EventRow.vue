<script setup>
import { computed } from 'vue'
import Icon from './Icon.vue'
import { cityOfAddress, dateBadge, dayShort, otherZone, plainCover, startTime, stateOf, timeRange, timeRangeIn, untilLabel, untilPill, whenShort, zoneName } from '../lib/events.js'
import { t } from '../lib/i18n.js'

/**
 * An event in the Events list: the 16:9 cover with its date, then the day, title, time
 * (with "In 2 days"), where, and where you stand: ✓ You're going for members, how many
 * are going for owners (only they see it). Past ones: the replay, or that there isn't one.
 * The whole row opens the event.
 */
const props = defineProps({
  post: { type: Object, required: true },
  owner: { type: Boolean, default: false },
})
const emit = defineEmits(['add-replay'])
const e = computed(() => props.post.event)
const state = computed(() => stateOf(e.value))
const past = computed(() => state.value === 'past' || state.value === 'cancelled')
const badge = computed(() => dateBadge(e.value))
const cover = computed(() => props.post.media.find((m) => m.kind === 'image')?.url || null)
// In person somewhere in another time zone: its own time, "(Austin time)".
const here = computed(() => otherZone(e.value))
const city = computed(() => cityOfAddress(e.value.place?.address))
const placeLine = computed(() => [e.value.place?.name, e.value.place?.address?.split(',').slice(-2).join(',').trim()].filter(Boolean).join(' · '))
const people = (n) =>
  past.value
    ? t.sprintf(/* translators: %d: number of members who went */ t._n('%d went', '%d went', n, 'membuild-community'), n)
    : t.sprintf(/* translators: %d: number of members going */ t._n('%d going', '%d going', n, 'membuild-community'), n)
</script>

<template>
  <RouterLink :to="{ name: 'event', params: { id: post.id } }" class="er card" :class="{ past, cancelled: state === 'cancelled' }">
    <span class="cover" :style="cover ? { backgroundImage: `url(${cover})` } : plainCover()">
      <span class="date"><small>{{ badge.month }}</small><strong>{{ badge.day }}</strong></span>
      <span v-if="state === 'live'" class="live">● {{ t.__('Live now', 'membuild-community') }}</span>
      <span v-if="state === 'past' && e.recording" class="play" aria-hidden="true"><Icon name="play" :size="20" /></span>
    </span>
    <span class="info">
      <template v-if="past">
        <span class="when-past">{{ whenShort(e) }} {{ zoneName() }}</span>
        <strong class="title">{{ e.title }}</strong>
        <span v-if="state === 'cancelled'" class="status-tag bad when-tag">{{ t.__('Cancelled', 'membuild-community') }}</span>
        <span v-else-if="e.recording" class="replay"><Icon name="play" :size="14" /> {{ t.__('Watch the replay', 'membuild-community') }}</span>
        <button v-else-if="owner" class="btn add" @click.prevent.stop="emit('add-replay', post)"><Icon name="plus" :size="16" /> {{ t.__('Add the replay', 'membuild-community') }}</button>
        <span v-else class="muted">{{ t.__('No replay for this one', 'membuild-community') }}</span>
      </template>
      <template v-else>
        <span class="day">{{ dayShort(e) }}<span v-if="state === 'upcoming' || state === 'soon'" class="day-until"> · {{ untilPill(e) }}</span></span>
        <strong class="title">{{ e.title }}</strong>
        <span class="line"><Icon name="clock" :size="16" /> <span class="t-full">{{ here ? timeRangeIn(e) : timeRange(e) }}<small v-if="here && city"> ({{ t.sprintf(/* translators: %s: a city */ t.__('%s time', 'membuild-community'), city) }})</small></span><span class="t-start">{{ startTime(e) }}</span> <span v-if="state === 'upcoming' || state === 'soon'" class="status-tag soon-tag">{{ untilPill(e) }}</span></span>
        <span v-if="e.where !== 'online' && e.place" class="line"><Icon name="mapPin" :size="16" /> {{ placeLine }}<template v-if="e.where === 'both'"> · {{ t.__('and online', 'membuild-community') }}</template></span>
        <span v-else class="line"><Icon name="video" :size="16" /> {{ t.__('Online', 'membuild-community') }}</span>
        <span v-if="!owner && e.my_rsvp === 'going'" class="status-tag ok when-tag"><Icon name="check" :size="13" /> {{ t.__('You’re going', 'membuild-community') }}</span>
      </template>
      <span v-if="owner && state !== 'cancelled'" class="line count"><Icon name="users" :size="16" /> {{ people(e.going_count) }} <small>· {{ t.__('only you see this', 'membuild-community') }}</small></span>
    </span>
    <Icon name="chevronRight" :size="20" class="go" />
  </RouterLink>
</template>

<style scoped>
.when-tag {
  margin-top: 2px;
}

.er {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 14px 18px 14px 14px;
  border-radius: var(--radius-lg);
  color: var(--text);
  text-decoration: none;
  transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}

/* Lifts under the mouse and presses in when tapped, like the place cards. */
@media (hover: hover) {
  .er:hover {
    border-color: var(--border-strong);
    box-shadow: var(--shadow-pop);
    transform: translateY(-2px);
  }
}

.er:active {
  transform: scale(0.985);
}

.cover {
  position: relative;
  flex: none;
  width: 208px;
  aspect-ratio: 16 / 9;
  border-radius: var(--radius-sm);
  background: center / cover no-repeat;
}

.date {
  position: absolute;
  top: 10px;
  left: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 46px;
  padding: 5px 8px;
  border-radius: var(--radius-sm);
  background: var(--paper);
  color: var(--ink);
  line-height: 1.1;
  box-shadow: var(--shadow-sm);
}

.date small {
  color: var(--live);
  font-size: var(--fs-2xs);
  font-weight: 800;
  text-transform: uppercase;
}

.date strong {
  font-size: var(--fs-4xl);
}

.live {
  position: absolute;
  right: 10px;
  bottom: 10px;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  background: var(--live);
  color: var(--on-color);
  font-size: var(--fs-xs);
  font-weight: 700;
}

.play {
  position: absolute;
  top: 50%;
  left: 50%;
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: color-mix(in srgb, var(--on-color) 92%, transparent);
  color: var(--ink);
  transform: translate(-50%, -50%);
  box-shadow: 0 2px 10px color-mix(in srgb, var(--shadow-color) 20%, transparent);
}

.play :deep(path),
.replay :deep(path) {
  fill: currentColor;
}

.info {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: flex-start;
  gap: 5px;
  min-width: 0;
}

.day {
  color: var(--accent-text);
  font-size: var(--fs-md);
  font-weight: 700;
}

.when-past {
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 650;
}

.title {
  font-size: var(--fs-2xl);
  line-height: 1.3;
}

.cancelled .title {
  color: var(--text-2);
}

.line {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--text-2);
  font-size: var(--fs-base);
}

.line:first-of-type {
  color: var(--text);
}

.line small {
  color: var(--text-3);
  font-size: var(--fs-md);
}

.replay {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--accent-text);
  font-size: var(--fs-base);
  font-weight: 700;
}

.muted {
  color: var(--text-2);
  font-size: var(--fs-base);
}

.add {
  height: 38px;
  padding: 0 16px;
  background: var(--accent);
  color: var(--accent-ink, #16181d);
  font-size: var(--fs-base);
  font-weight: 700;
}

.go {
  flex: none;
  color: var(--text-2);
}

/* Computers: the whole time and an "In 2 days" pill; phones: the start, and "In 2 days" by the day. */
.day-until,
.t-start {
  display: none;
}

.count {
  flex-wrap: wrap;
  row-gap: 0;
}

@media (max-width: 600px) {
  .er {
    align-items: flex-start;
    gap: 12px;
    padding: 12px;
  }

  .cover {
    width: 128px;
  }

  .date {
    top: 6px;
    left: 6px;
    min-width: 36px;
    padding: 3px 6px;
  }

  .date small {
    font-size: var(--fs-3xs);
  }

  .date strong {
    font-size: var(--fs-lg);
  }

  .play {
    width: 34px;
    height: 34px;
  }

  .title {
    font-size: var(--fs-lg);
  }

  .go {
    display: none;
  }

  .day-until,
  .t-start {
    display: inline;
  }

  .t-full,
  .soon-tag {
    display: none;
  }

  .line {
    font-size: var(--fs-base);
  }
}
</style>
