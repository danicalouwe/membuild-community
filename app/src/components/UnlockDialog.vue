<script setup>
import { computed } from 'vue'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'
import { periodWords } from '../lib/offer.js'
import { t } from '../lib/i18n.js'

/**
 * What a locked course asks of a member, before anything else happens.
 * A membership: what they get, the price, Continue to checkout (the owner's own page)
 * and "Already paid? Check again". A level: how far they are and how to earn points.
 * A sheet from the bottom on phones.
 */
const props = defineProps({
  course: { type: Object, required: true },
  checking: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'recheck'])

const lock = computed(() => props.course.lock)
const membership = computed(() => lock.value?.membership || null)
const offer = computed(() => membership.value?.offer || null)
const lessons = computed(() => props.course.modules?.flatMap((m) => m.lessons) || [])
const minutes = computed(() => lessons.value.reduce((n, l) => n + (l.minutes || 0), 0))

const gets = computed(() => {
  const out = []
  const n = lessons.value.length || props.course.lessons_count || 0
  if (n) {
    out.push(
      minutes.value
        ? // Two counts, so two plurals: "All 3 lessons, about 1 minute".
          `${t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', 'All %d lessons', n, 'membuild-community'), n)}, ${t.sprintf(/* translators: %d: minutes */ t._n('about %d minute', 'about %d minutes', minutes.value, 'membuild-community'), minutes.value)}`
        : t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', 'All %d lessons', n, 'membuild-community'), n),
    )
  }
  const files = props.course.files_count || 0
  if (files) out.push(t.sprintf(/* translators: %d: number of files */ t._n('%d file to download and keep', '%d files to download and keep', files, 'membuild-community'), files))
  const more = membership.value?.more || []
  if (more.length) {
    const names = more.length > 1 ? `${more.slice(0, -1).join(', ')} ${t.__('and', 'membuild-community')} ${more[more.length - 1]}` : more[0]
    out.push(t.sprintf(/* translators: 1: number of courses, 2: their names */ t._n('%1$d more course: %2$s', '%1$d more courses: %2$s', more.length, 'membuild-community'), more.length, names))
  }
  return out
})

// Levels: "You're Level 2. Earn 40 more points and this course opens by itself."
const EARN = {
  post: () => t.__('Write a post', 'membuild-community'),
  comment: () => t.__('Comment on a post', 'membuild-community'),
  reaction_received: () => t.__('Get a reaction on your post', 'membuild-community'),
  lesson: () => t.__('Finish a lesson', 'membuild-community'),
}
const plus = (n) => t.sprintf(/* translators: %d: points */ t._n('+%d point', '+%d points', n, 'membuild-community'), n)
const progress = computed(() => (lock.value?.need ? Math.min(100, Math.round((100 * lock.value.points) / lock.value.need)) : 0))
const morePoints = computed(() => {
  const s = t.sprintf(/* translators: 1: current level, 2: "40 more points" (bold) */ t.__('You’re Level %1$d. Earn %2$s and this course opens by itself.', 'membuild-community'), lock.value.my_level, '%s')
  return s.split('%s')
})
const pointsWord = computed(() => t.sprintf(/* translators: %d: points still needed */ t._n('%d more point', '%d more points', lock.value.points_to_go, 'membuild-community'), lock.value.points_to_go))
const title = computed(() =>
  lock.value?.reason === 'level'
    ? t.sprintf(/* translators: %d: level number */ t.__('Opens at Level %d', 'membuild-community'), lock.value.level)
    : t.sprintf(/* translators: %s: course title */ t.__('Get %s', 'membuild-community'), props.course.title),
)
const partOf = computed(() =>
  (gets.value.length
    ? /* translators: %s: membership name (bold) */ t.__('Part of the %s. You get:', 'membuild-community')
    : /* translators: %s: membership name (bold) */ t.__('Part of the %s.', 'membuild-community')
  ).split('%s'),
)
</script>

<template>
  <BaseDialog :label="title" :width="520" phone="sheet" style="border-radius: 24px" @close="emit('close')">
    <template #header="{ close }">
      <span class="grab" aria-hidden="true" />
      <button class="x round-btn" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="20" /></button>
    </template>
    <div class="ud">
      <span class="badge"><Icon name="lock" :size="24" /></span>
      <h2>{{ title }}</h2>

      <template v-if="lock.reason === 'level'">
        <p class="say">{{ morePoints[0] }}<b>{{ pointsWord }}</b>{{ morePoints[1] }}</p>
        <div class="bar-labels">
          <span>{{ t.sprintf(t.__('Level %d', 'membuild-community'), lock.my_level) }}</span>
          <span>{{ t.sprintf(/* translators: 1: points now, 2: points needed */ t.__('%1$d / %2$d points', 'membuild-community'), lock.points, lock.need) }}</span>
          <span>{{ t.sprintf(t.__('Level %d', 'membuild-community'), lock.level) }}</span>
        </div>
        <div class="bar"><i :style="{ width: progress + '%' }" /></div>
        <p class="lbl">{{ t.__('How to earn points', 'membuild-community') }}</p>
        <ul class="earn">
          <li v-for="r in lock.earn" :key="r.what"><span>{{ EARN[r.what]?.() || r.what }}</span><b>{{ plus(r.points) }}</b></li>
        </ul>
        <RouterLink to="/" class="btn btn-lg btn-dark wide" @click="emit('close')">{{ t.__('Go to the community', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></RouterLink>
      </template>

      <template v-else>
        <p v-if="membership" class="say">{{ partOf[0] }}<b>{{ membership.name }}</b>{{ partOf[1] }}</p>
        <ul v-if="gets.length" class="gets">
          <li v-for="g in gets" :key="g"><Icon name="check" :size="18" /> {{ g }}</li>
        </ul>
        <template v-if="offer">
          <div v-if="offer.price" class="price"><b>{{ offer.price }}</b> <span>{{ periodWords(offer.period) }}</span></div>
          <a :href="offer.url" target="_blank" rel="noopener" class="btn btn-lg btn-dark wide">{{ t.__('Continue to checkout', 'membuild-community') }} <Icon name="external" :size="18" /></a>
          <button class="again" :disabled="checking" @click="emit('recheck')">{{ checking ? t.__('Checking…', 'membuild-community') : t.__('Already paid? Check again', 'membuild-community') }}</button>
        </template>
        <p v-else class="ask">{{ t.__('Ask an admin for access.', 'membuild-community') }}</p>
      </template>
    </div>
  </BaseDialog>
</template>

<style scoped>
.x {
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 2;
}

.grab {
  display: none;
}

.ud {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 32px 32px 28px;
}

.badge {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--text);
}

h2 {
  margin: 4px 0 0;
  font-size: 26px;
  line-height: 1.25;
}

.say {
  margin: 0;
  color: var(--text-2);
  font-size: 17px;
  line-height: 1.55;
}

.say b {
  color: var(--text);
}

.gets,
.earn {
  margin: 0;
  padding: 0;
  list-style: none;
}

.gets li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 5px 0;
  font-size: 16px;
}

.gets :deep(svg) {
  flex: none;
  margin-top: 2px;
  color: var(--ok-text);
}

.price {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 16px 18px;
  border-radius: 12px;
  background: var(--surface-2);
}

.price b {
  font-size: 28px;
}

.price span {
  color: var(--text-2);
}

.wide {
  justify-content: center;
  width: 100%;
}

.again {
  min-height: 44px;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.ask {
  margin: 0;
  font-weight: 700;
}

.bar-labels {
  display: flex;
  justify-content: space-between;
  margin-top: 4px;
  color: var(--text-2);
  font-size: 15px;
  font-weight: 650;
}

.bar {
  height: 10px;
  border-radius: 999px;
  background: var(--surface-2);
  overflow: hidden;
}

.bar i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--text);
}

.lbl {
  margin: 8px 0 0;
  font-weight: 700;
  font-size: 17px;
}

.earn li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid var(--border);
  font-size: 16px;
}

.earn b {
  color: var(--ok-text);
}

@media (max-width: 767px) {
  .x {
    display: none;
  }

  .grab {
    display: block;
    width: 40px;
    height: 5px;
    margin: 10px auto 0;
    border-radius: 999px;
    background: var(--border-strong);
  }

  .ud {
    padding: 14px 20px 24px;
  }

  .badge {
    display: none;
  }

  h2 {
    font-size: 23px;
  }
}
</style>
