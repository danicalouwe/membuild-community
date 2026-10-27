<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import { canDock, openChat } from '../stores/chatDock.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { timeAgo } from '../lib/time.js'
import { t } from '../lib/i18n.js'

/**
 * The Course map (owners): the course as a member travels it. The course card,
 * a member starting, a box per section with a box per lesson, a wait wherever
 * days pass, and the finish. Live counts on every step: who is on each lesson
 * now (tap for who, how long, Message), who is waiting for the next one to open.
 * "Show me how it plays out" walks an example member, Sam, through it once.
 */
const props = defineProps({ course: { type: Object, required: true } })
const emit = defineEmits(['edit'])
const router = useRouter()

const live = ref(null)
const error = ref('')
watch(
  () => props.course.id,
  async (id) => {
    live.value = null
    error.value = ''
    try {
      live.value = await api.courseMap(id)
    } catch (err) {
      error.value = err.message
    }
  },
  { immediate: true },
)
const stepOf = (id) => live.value?.lessons.find((s) => s.lesson_id === id) || { on: 0, waiting: 0, people: [], waiters: [] }

// Sam starts today; each lesson's date is today + its days.
const today = new Date()
const dateOf = (days) => new Date(today.getTime() + days * 86400000).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })

const all = computed(() => props.course.modules.flatMap((m) => m.lessons))
const numberOf = (l) => all.value.indexOf(l) + 1
const prevOf = (l) => all.value[all.value.indexOf(l) - 1] || null
// A wait appears only where days pass between one lesson and the next.
const waitBefore = (l) => {
  if (l.open_on || prevOf(l)?.open_on) return 0 // a fixed date isn't a wait counted from the start
  const before = prevOf(l)?.drip_days || 0
  return Math.max(0, (l.drip_days || 0) - before)
}
const sectionRange = (m) => {
  const d = m.lessons.filter((l) => !l.open_on).map((l) => l.drip_days || 0)
  if (!d.length) return ''
  const lo = Math.min(...d)
  const hi = Math.max(...d)
  if (!hi) return t.__('right away', 'membuild-community')
  return lo === hi ? t.sprintf(/* translators: %d: day */ t.__('day %d', 'membuild-community'), lo) : t.sprintf(/* translators: 1: first day, 2: last day */ t.__('days %1$d–%2$d', 'membuild-community'), lo, hi)
}

/* ---------- Who's on this lesson ---------- */
const openFor = ref(null) // { lesson, list, kind }
function showPeople(l, kind = 'on') {
  const s = stepOf(l.id)
  const list = kind === 'on' ? s.people : s.waiters
  const n = kind === 'on' ? s.on : s.waiting
  if (!n) return
  openFor.value = { lesson: l, list, n, kind }
}
function showFinished() {
  if (!live.value?.finished) return
  openFor.value = { lesson: null, list: live.value.finishers, n: live.value.finished, kind: 'done' }
}
const messaging = ref(0)
async function message(u) {
  messaging.value = u.id
  try {
    const chat = await api.startChat([u.id])
    if (canDock()) openChat(chat)
    else router.push({ name: 'messages', params: { threadId: chat.id } })
  } catch (err) {
    error.value = err.message
  } finally {
    messaging.value = 0
  }
}

/* ---------- Show me how it plays out ---------- */
const playing = ref(false)
const lit = ref(-1) // which step Sam is on: 0 = start, 1..n = lessons, n+1 = finish
let timer = null
function play() {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    lit.value = all.value.length + 1
    return
  }
  clearInterval(timer)
  playing.value = true
  lit.value = 0
  // Slow enough for the page to glide to each step before the next one lights.
  const per = Math.max(750, Math.min(1100, 12000 / (all.value.length + 2)))
  timer = setInterval(() => {
    if (lit.value >= all.value.length + 1) {
      clearInterval(timer)
      setTimeout(() => {
        playing.value = false
        lit.value = -1
      }, 3200)
      return
    }
    lit.value++
  }, per)
}
const onKey = (e) => e.key === 'Escape' && (openFor.value = null)
window.addEventListener('keydown', onKey)
onBeforeUnmount(() => {
  clearInterval(timer)
  window.removeEventListener('keydown', onKey)
})
// The page follows Sam: each step glides to the middle of the screen as it lights.
const root = ref(null)
watch(lit, async (i) => {
  if (!playing.value || i < 0) return
  await nextTick()
  const el = root.value?.querySelector('.lit')
  if (!el) return
  const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  el.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'center' })
})
const isLit = (i) => lit.value === i
const isPast = (i) => lit.value > i
// A wait lights as Sam reaches the lesson after it.
const waitLit = (l) => playing.value && lit.value >= numberOf(l)
const done = computed(() => playing.value && lit.value >= all.value.length + 1)

// The finish: a burst of confetti from the Finish box, like the design.
const COLORS = ['#f5c542', '#2fb36b', '#c8f542', '#4f7cff', '#ff6b6b', '#a66cff']
const confetti = Array.from({ length: 28 }, (_, i) => {
  const angle = (i / 28) * Math.PI * 2 + (i % 3) * 0.2
  const reach = 90 + ((i * 37) % 140)
  return {
    '--dx': `${Math.round(Math.cos(angle) * reach * 1.6)}px`,
    '--dy': `${Math.round(Math.sin(angle) * reach * 0.7 - 60)}px`,
    '--rot': `${(i * 97) % 720}deg`,
    background: COLORS[i % COLORS.length],
    width: `${i % 2 ? 10 : 8}px`,
    height: `${i % 3 ? 6 : 10}px`,
    borderRadius: i % 2 ? '2px' : '50%',
    animationDelay: `${(i % 5) * 0.04}s`,
  }
})

const lessonsLabel = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)
const fixed = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
const opensLabel = (l) => (l.open_on ? t.__('On a set date', 'membuild-community') : l.drip_days > 0 ? t.sprintf(/* translators: %d: days */ t._n('After %d day', 'After %d days', l.drip_days, 'membuild-community'), l.drip_days) : t.__('Right away', 'membuild-community'))
const waitLabel = (d, first) =>
  first ? t.sprintf(/* translators: %d: days */ t._n('Wait %d day', 'Wait %d days', d, 'membuild-community'), d) : t.sprintf(/* translators: %d: days */ t._n('Wait %d more day', 'Wait %d more days', d, 'membuild-community'), d)
const onLabel = (u) =>
  [
    t.sprintf(/* translators: %d: days */ t._n('On this lesson %d day', 'On this lesson %d days', u.days, 'membuild-community'), u.days),
    u.stuck ? t.__('may be stuck', 'membuild-community') : '',
    u.last_active ? t.sprintf(/* translators: %s: e.g. "2h ago" */ t.__('active %s', 'membuild-community'), timeAgo(u.last_active)) : '',
  ]
    .filter(Boolean)
    .join(' · ')
const popTitle = computed(() => {
  const o = openFor.value
  if (!o) return ''
  if (o.kind === 'done') return t.sprintf(/* translators: %d: members */ t._n('%d member finished', '%d members finished', o.n, 'membuild-community'), o.n)
  if (o.kind === 'waiting') return t.sprintf(/* translators: %d: members */ t._n('%d member waiting for it to open', '%d members waiting for it to open', o.n, 'membuild-community'), o.n)
  return t.sprintf(/* translators: %d: members */ t._n('%d member on this lesson', '%d members on this lesson', o.n, 'membuild-community'), o.n)
})
const samStarts = computed(() => t.sprintf(/* translators: %s: a date */ t.__('Pretend a member named Sam starts this course on %s.', 'membuild-community'), dateOf(0)))
const isFirstWait = (l) => {
  const i = all.value.indexOf(l)
  return all.value.slice(0, i).every((x) => !waitBefore(x))
}
</script>

<template>
  <div ref="root" class="map card" :class="{ playing }">
    <div class="map-top">
      <button class="btn btn-lg btn-dark" :disabled="playing || !all.length" @click="play"><Icon name="play" :size="18" /> {{ t.__('Show me how it plays out', 'membuild-community') }}</button>
      <p class="legend">
        <span class="chip people"><Icon name="users" :size="15" /> {{ live ? live.lessons.reduce((n, s) => n + s.on, 0) : '…' }}</span> {{ t.__('members on a lesson now', 'membuild-community') }}
        <span class="chip dark"><Icon name="users" :size="15" /> {{ live ? live.started : '…' }}</span> {{ t.__('started', 'membuild-community') }}
      </p>
      <p v-if="error" class="err">{{ error }}</p>
    </div>

    <p class="kicker">{{ t.__('In the Classroom', 'membuild-community') }}</p>
    <div class="mini card">
      <div class="mini-cover" :style="course.cover ? { backgroundImage: `url(${course.cover})` } : null"><span v-if="!course.cover">{{ course.title }}</span></div>
      <div class="mini-body">
        <strong>{{ course.title }}</strong>
        <small v-if="course.description">{{ course.description }}</small>
        <span>{{ t.__('Start course', 'membuild-community') }} · {{ lessonsLabel(all.length) }}</span>
      </div>
    </div>
    <span class="arrow" />

    <div class="step start" :class="{ lit: isLit(0), past: isPast(0) }">
      <span class="badge"><Icon name="user" :size="20" /></span>
      <span class="step-text">
        <small>{{ t.__('Start', 'membuild-community') }}</small>
        <strong>{{ t.__('A member starts the course', 'membuild-community') }}</strong>
      </span>
      <span class="chip dark"><Icon name="users" :size="15" /> {{ live ? live.started : '…' }}</span>
      <div class="example">
        <span class="tag">{{ t.__('Example only', 'membuild-community') }}</span>
        <p>{{ samStarts }} {{ t.__('The black dates on the right show when each lesson would open for Sam. Every member gets their own dates, counted from the day they start.', 'membuild-community') }}</p>
      </div>
    </div>

    <template v-for="m in course.modules" :key="m.id">
      <template v-if="m.lessons.length && waitBefore(m.lessons[0])">
        <span class="arrow" />
        <button class="wait" :class="{ has: stepOf(m.lessons[0].id).waiting, on: waitLit(m.lessons[0]) }" @click="showPeople(m.lessons[0], 'waiting')">
          <Icon name="clock" :size="16" /> {{ waitLabel(waitBefore(m.lessons[0]), isFirstWait(m.lessons[0])) }}
          <span v-if="stepOf(m.lessons[0].id).waiting" class="chip people"><Icon name="users" :size="14" /> {{ stepOf(m.lessons[0].id).waiting }}</span>
        </button>
      </template>
      <template v-else-if="m === course.modules[0]">
        <span class="arrow" />
        <span class="wait none" :class="{ on: playing && lit >= 1 }"><Icon name="clock" :size="16" /> {{ t.__('No wait: opens right away', 'membuild-community') }}</span>
      </template>
      <span class="arrow" />

      <section class="sec">
        <header>
          <span class="badge light"><Icon name="list" :size="18" /></span>
          <strong>{{ m.title }}</strong>
          <small>{{ lessonsLabel(m.lessons.length) }}<template v-if="sectionRange(m)"> · {{ sectionRange(m) }}</template></small>
        </header>
        <template v-for="(l, li) in m.lessons" :key="l.id">
          <button v-if="li > 0 && waitBefore(l)" class="wait inner" :class="{ has: stepOf(l.id).waiting, on: waitLit(l) }" @click="showPeople(l, 'waiting')">
            <Icon name="clock" :size="15" /> {{ waitLabel(waitBefore(l), isFirstWait(l)) }}
            <span v-if="stepOf(l.id).waiting" class="chip people"><Icon name="users" :size="14" /> {{ stepOf(l.id).waiting }}</span>
          </button>
          <div class="row" :class="{ lit: isLit(numberOf(l)), past: isPast(numberOf(l)) }">
            <span class="num">{{ numberOf(l) }}</span>
            <Icon :name="l.has_video ? 'play' : 'file'" :size="16" class="kind" />
            <span class="title">{{ l.title }}</span>
            <span class="chip soft"><Icon name="clock" :size="14" /> {{ opensLabel(l) }}</span>
            <span class="chip dark">{{ l.open_on ? fixed(l.open_on) : dateOf(l.drip_days || 0) }}</span>
            <button class="chip people" :class="{ zero: !stepOf(l.id).on }" :disabled="!stepOf(l.id).on" :title="t.__('Who’s on this lesson', 'membuild-community')" @click="showPeople(l)"><Icon name="users" :size="15" /> {{ live ? stepOf(l.id).on : '…' }}</button>
            <button class="go" :aria-label="t.__('Edit this lesson', 'membuild-community')" @click="emit('edit', l.id)"><Icon name="chevronRight" :size="20" /></button>
          </div>
        </template>
        <p v-if="!m.lessons.length" class="none">{{ t.__('No lessons in this section yet.', 'membuild-community') }}</p>
      </section>
    </template>

    <span class="arrow" />
    <div class="step finish" :class="{ lit: isLit(all.length + 1), won: done }">
      <span v-if="done" class="confetti" aria-hidden="true"><i v-for="(c, i) in confetti" :key="i" :style="c" /></span>
      <span class="badge light"><Icon name="flag" :size="20" /></span>
      <span class="step-text">
        <small>{{ t.__('Finish', 'membuild-community') }}</small>
        <strong>{{ t.__('They finish the course', 'membuild-community') }}</strong>
      </span>
      <button class="chip dark" :disabled="!live?.finished" @click="showFinished"><Icon name="users" :size="15" /> {{ live ? live.finished : '…' }}</button>
      <p class="finish-note">{{ t.__('They get 20 bonus points. The course shows as Completed.', 'membuild-community') }}</p>
    </div>

    <div v-if="openFor" class="pop-back" @click.self="openFor = null">
      <div class="pop card" role="dialog" :aria-label="popTitle">
        <header>
          <span>
            <strong>{{ popTitle }}</strong>
            <small v-if="openFor.lesson">{{ openFor.lesson.title }}</small>
          </span>
          <button class="x" :aria-label="t.__('Close', 'membuild-community')" @click="openFor = null"><Icon name="close" :size="20" /></button>
        </header>
        <div v-for="u in openFor.list" :key="u.id" class="who">
          <Avatar :user="u" :size="44" link />
          <span>
            <strong>{{ u.name }}</strong>
            <small :class="{ stuck: u.stuck && openFor.kind === 'on' }">{{ openFor.kind === 'on' ? onLabel(u) : u.last_active ? t.sprintf(t.__('active %s', 'membuild-community'), timeAgo(u.last_active)) : '' }}</small>
          </span>
          <button class="btn btn-lg btn-ghost" :disabled="messaging === u.id" @click="message(u)"><Icon name="messenger" :size="18" /> {{ t.__('Message', 'membuild-community') }}</button>
        </div>
        <p v-if="openFor.n > openFor.list.length" class="more">{{ t.sprintf(t.__('and %d more', 'membuild-community'), openFor.n - openFor.list.length) }}</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.map {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 26px 20px 30px;
  background: radial-gradient(circle, var(--border) 1px, transparent 1.4px) 0 0 / 18px 18px, var(--surface);
}

.map-top {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 6px 8px;
  margin: 0;
  font-size: 15px;
  color: var(--text-2);
}

.legend .chip + .chip,
.legend .dark {
  margin-left: 10px;
}

.kicker {
  margin: 0 0 8px;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-2);
}

.mini {
  width: min(300px, 100%);
  overflow: hidden;
}

.mini-cover {
  display: grid;
  place-items: center;
  aspect-ratio: 16 / 9;
  background: center / cover no-repeat linear-gradient(135deg, var(--accent-soft), var(--surface-2));
  font-weight: 800;
}

.mini-body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px 14px;
}

.mini-body small {
  font-size: 14px;
  color: var(--text-2);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.mini-body span {
  margin-top: 6px;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--accent-text);
}

.arrow {
  width: 2px;
  height: 28px;
  background: var(--border-strong, #c9ced6);
  position: relative;
}

.arrow::after {
  content: '';
  position: absolute;
  left: -4px;
  bottom: -2px;
  border: 5px solid transparent;
  border-top-color: var(--border-strong, #c9ced6);
}

.step,
.sec {
  width: min(680px, 100%);
  border-radius: 16px;
  transition: box-shadow 0.3s ease, transform 0.3s ease;
}

.step {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 16px 18px;
  background: var(--surface);
  border: 1px solid var(--border);
}

.step.start {
  border: 2px solid var(--accent);
}

.badge {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--accent);
  color: var(--accent-ink);
}

.badge.light {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text);
}

.step-text {
  flex: 1;
}

.step-text small {
  display: block;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-2);
}

.step-text strong {
  font-size: 19px;
}

.example {
  flex: 1 1 100%;
  padding: 12px 14px;
  border: 1px dashed var(--border-strong, #c9ced6);
  border-radius: 12px;
  background: var(--surface-2);
  font-size: 15.5px;
  line-height: 1.55;
}

.example p {
  margin: 6px 0 0;
}

.tag {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--text);
  color: var(--surface);
  font-size: 13px;
  font-weight: 700;
}

.finish-note {
  flex: 1 1 100%;
  margin: 0;
  font-size: 15.5px;
  color: var(--text-2);
}

.wait {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 0 16px;
  border-radius: 999px;
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: 16px;
  font-weight: 700;
}

.wait.none {
  background: var(--accent-soft);
  color: var(--text);
}

.wait.inner {
  align-self: center;
  margin: 2px auto;
  min-height: 38px;
  font-size: 15px;
}

.sec {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border: 1px solid var(--border);
  background: var(--surface-2);
}

.sec header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 6px 6px;
}

.sec header strong {
  flex: 1;
  font-size: 18px;
}

.sec header small {
  font-size: 15px;
  color: var(--text-2);
}

.row {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 60px;
  padding: 8px 8px 8px 14px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: var(--surface);
  transition: box-shadow 0.3s ease, transform 0.3s ease, background 0.3s ease;
}

/* Playing: everything waits in grey, and lights up as Sam gets there. */
.playing .row,
.playing .step,
.playing .wait {
  opacity: 0.22;
  filter: grayscale(1);
  transition: opacity 0.4s ease, filter 0.4s ease, box-shadow 0.4s ease, transform 0.3s ease, background 0.4s ease;
}

.playing .row.lit,
.playing .row.past,
.playing .step.lit,
.playing .step.past,
.playing .wait.on {
  opacity: 1;
  filter: none;
}

.row.lit,
.step.lit {
  animation: lit 0.8s ease both;
  transform: scale(1.02);
}

@keyframes lit {
  0% {
    box-shadow: 0 0 0 0 rgb(200 245 66 / 0%);
  }
  55% {
    box-shadow: 0 0 0 7px rgb(200 245 66 / 75%);
  }
  100% {
    box-shadow: 0 0 0 3px var(--accent), 0 10px 26px rgb(0 0 0 / 14%);
  }
}

.wait.on {
  animation: tick 0.8s ease both;
}

@keyframes tick {
  45% {
    transform: scale(1.1);
  }
  100% {
    transform: scale(1);
  }
}

.row.past {
  background: color-mix(in srgb, var(--accent-soft) 60%, var(--surface));
}

.step.finish {
  position: relative;
  overflow: visible;
}

.step.finish.won {
  animation: fin 0.9s ease both;
  background: var(--accent);
  color: var(--accent-ink);
  border-color: var(--accent);
}

.step.finish.won .step-text small,
.step.finish.won .finish-note {
  color: var(--accent-ink);
}

@keyframes fin {
  0% {
    box-shadow: 0 0 0 0 rgb(200 245 66 / 0%);
  }
  50% {
    box-shadow: 0 0 0 12px rgb(200 245 66 / 55%);
  }
  100% {
    box-shadow: 0 0 0 0 rgb(200 245 66 / 0%);
  }
}

.confetti {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: visible;
}

.confetti i {
  position: absolute;
  left: 50%;
  top: 50%;
  opacity: 0;
  animation: burst 1.8s cubic-bezier(0.2, 0.7, 0.3, 1) 0.3s both;
}

@keyframes burst {
  0% {
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.4);
  }
  15% {
    opacity: 1;
  }
  60% {
    opacity: 1;
    transform: translate(calc(-50% + var(--dx)), calc(-50% + var(--dy))) rotate(var(--rot));
  }
  100% {
    opacity: 0;
    transform: translate(calc(-50% + var(--dx)), calc(-50% + var(--dy) + 120px)) rotate(var(--rot));
  }
}

.num {
  flex: none;
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--accent-soft);
  font-size: 14px;
  font-weight: 700;
}

.kind {
  flex: none;
  color: var(--text-2);
}

.title {
  flex: 1;
  min-width: 0;
  font-size: 16.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chip {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  min-height: 32px;
  padding: 0 11px;
  border-radius: 999px;
  font-size: 14.5px;
  font-weight: 700;
  white-space: nowrap;
}

.chip.soft {
  background: var(--surface-2);
  color: var(--text-2);
}

.chip.dark {
  background: var(--text);
  color: var(--surface);
}

.chip.people {
  background: var(--info-soft);
  color: var(--info-text);
}

.chip.people.zero {
  background: transparent;
  color: var(--text-3);
}

button.chip:not(:disabled) {
  cursor: pointer;
}

button.chip:not(:disabled):hover {
  filter: brightness(0.95);
}

.go {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  color: var(--text-2);
}

.go:hover {
  background: var(--surface-2);
}

.none {
  margin: 0;
  padding: 8px;
  color: var(--text-3);
}

.err {
  margin: 0;
  color: var(--danger-text);
}

.pop-back {
  position: fixed;
  inset: 0;
  z-index: 90;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgb(16 24 40 / 35%);
}

.pop {
  width: min(520px, 100%);
  max-height: min(640px, 90dvh);
  overflow-y: auto;
  padding: 8px 18px 16px;
}

.pop header {
  position: sticky;
  top: 0;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 14px 0 10px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}

.pop header span {
  flex: 1;
}

.pop header strong {
  display: block;
  font-size: 19px;
}

.pop header small {
  display: block;
  font-size: 15px;
  color: var(--text-2);
}

.x {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--surface-2);
}

.who {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
}

.who + .who {
  border-top: 1px solid var(--border);
}

.who span {
  flex: 1;
  min-width: 0;
}

.who strong {
  display: block;
  font-size: 17px;
}

.who small {
  display: block;
  font-size: 14.5px;
  color: var(--text-2);
}

.who small.stuck {
  color: var(--warn-text);
  font-weight: 650;
}

.more {
  margin: 8px 0 0;
  color: var(--text-2);
}

@media (max-width: 700px) {
  .map {
    padding: 18px 10px 22px;
  }

  .row {
    flex-wrap: wrap;
  }

  .title {
    flex: 1 1 60%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .row,
  .step,
  .wait,
  .confetti i {
    transition: none;
    animation: none !important;
  }

  .confetti {
    display: none;
  }
}
</style>
