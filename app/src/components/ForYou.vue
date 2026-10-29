<script setup>
import { computed, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { dateBadge, startOf, zoneName } from '../lib/events.js'
import { locale, t } from '../lib/i18n.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'

// "For you" beside the Home feed on computers: the next event, the course
// you're in the middle of, and who joined this week. Hidden when it's all empty.
const data = ref(null)
onMounted(async () => {
  data.value = await api.forYou().catch(() => null)
})
const any = computed(() => data.value && (data.value.event || data.value.course || data.value.members))

const timeFmt = new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' })
const dayFmt = new Intl.DateTimeFormat(locale, { weekday: 'long' })
const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()

/** "Live Q&A tomorrow": the day only when it's today, tomorrow or this week. */
function eventDay(e) {
  const days = Math.round((startOfDay(startOf(e)) - startOfDay(new Date())) / 86400000)
  if (days <= 0) return t.__('today', 'membuild-community')
  if (days === 1) return t.__('tomorrow', 'membuild-community')
  if (days < 7) return dayFmt.format(startOf(e))
  return ''
}
const eventTitle = (e) => {
  const day = eventDay(e)
  return day ? t.sprintf(/* translators: 1: event name, 2: "today", "tomorrow" or a weekday */ t.__('%1$s %2$s', 'membuild-community'), e.title, day) : e.title
}
const eventTime = (e) => `${timeFmt.format(startOf(e))} ${zoneName()}`.trim()
const nextLine = (c) => t.sprintf(/* translators: %s: lesson title */ t.__('Next: %s', 'membuild-community'), c.next_lesson.title)
const newLine = (n) => t.sprintf(/* translators: %d: number of people */ t._n('%d new member this week', '%d new members this week', n, 'membuild-community'), n)
</script>

<template>
  <section v-if="any" class="for-you" :aria-label="t.__('For you', 'membuild-community')">
    <h2>{{ t.__('For you', 'membuild-community') }}</h2>

    <RouterLink v-if="data.event" class="row" :to="{ name: 'event', params: { id: data.event.id } }">
      <span class="tile">
        <small>{{ dateBadge(data.event).month }}</small>
        <strong>{{ dateBadge(data.event).day }}</strong>
      </span>
      <span class="words"><strong>{{ eventTitle(data.event) }}</strong><small>{{ eventTime(data.event) }}</small></span>
      <Icon name="chevronRight" :size="18" class="chev" />
    </RouterLink>

    <RouterLink v-if="data.course" class="row" :to="{ name: 'course', params: { courseId: data.course.id, lessonId: data.course.next_lesson.id } }">
      <span class="cover" :style="{ '--p': data.course.progress }">
        <img v-if="data.course.cover" :src="data.course.cover" alt="" />
        <Icon v-else name="cap" :size="20" />
      </span>
      <span class="words"><strong>{{ data.course.title }}</strong><small>{{ nextLine(data.course) }}</small></span>
      <Icon name="chevronRight" :size="18" class="chev" />
    </RouterLink>

    <RouterLink v-if="data.members" class="row" :to="{ name: 'members', query: { sort: 'newest' } }">
      <span class="faces">
        <Avatar v-for="u in data.members.faces.slice(0, 3)" :key="u.id" :user="u" :size="26" />
      </span>
      <span class="words"><strong>{{ newLine(data.members.count) }}</strong><small>{{ t.__('Say hi in their intro posts', 'membuild-community') }}</small></span>
      <Icon name="chevronRight" :size="18" class="chev" />
    </RouterLink>
  </section>
</template>

<style scoped>
.for-you {
  display: none;
}

@media (min-width: 1200px) {
  .for-you {
    display: block;
    margin-top: 16px;
  }
}

h2 {
  margin: 0 0 8px;
  padding: 0 8px;
  font-size: 13px;
  font-weight: 750;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-3);
}

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 56px;
  padding: 8px;
  border-radius: 10px;
  color: inherit;
  text-decoration: none;
}

.row:hover {
  background: var(--surface-2);
}

.tile,
.cover,
.faces {
  flex: none;
  width: 44px;
  height: 44px;
}

/* A little calendar page, as drawn: the month on a grey strip, the day on white under it. */
.tile {
  display: grid;
  grid-template-rows: auto 1fr;
  overflow: hidden;
  text-align: center;
  border-radius: 10px;
  background: var(--surface);
  box-shadow: inset 0 0 0 1px var(--border);
  line-height: 1;
}

.tile small {
  padding: 4px 0 3px;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: 10px;
  font-weight: 750;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text-2);
}

.tile strong {
  display: grid;
  place-items: center;
  font-size: 18px;
}

/* The course cover inside a ring that fills with progress. */
.cover {
  position: relative;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: conic-gradient(var(--accent) calc(var(--p) * 1%), var(--surface-2) 0);
  color: var(--text-2);
}

.cover::before {
  content: '';
  position: absolute;
  inset: 4px;
  border-radius: 50%;
  background: var(--surface-2);
}

.cover img,
.cover :deep(svg) {
  position: relative;
}

.cover img {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  object-fit: cover;
}

.faces {
  position: relative;
}

.faces > :deep(*) {
  position: absolute;
  box-shadow: 0 0 0 2px var(--bg, #fff);
  border-radius: 50%;
}

.faces > :deep(:nth-child(1)) { left: 0; top: 0; }
.faces > :deep(:nth-child(2)) { right: 0; top: 4px; }
.faces > :deep(:nth-child(3)) { left: 8px; bottom: 0; }

.words {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.words strong {
  font-size: 15px;
  line-height: 1.3;
}

.words small {
  font-size: 13px;
  color: var(--text-2);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chev {
  flex: none;
  color: var(--text-3);
}
</style>
