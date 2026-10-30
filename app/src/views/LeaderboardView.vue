<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg, levelLabel } from '../config.js'
import { compactNumber } from '../lib/time.js'
import { canDock, openChat } from '../stores/chatDock.js'
import { showToast } from '../lib/toast.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import LevelsCard from '../components/LevelsCard.vue'
import EarnPoints from '../components/EarnPoints.vue'
import ThemeSlot from '../components/ThemeSlot.vue'
import PodiumConfetti from '../components/PodiumConfetti.vue'
import { t } from '../lib/i18n.js'

/**
 * Leaderboard (V2): This week / This month / All time; the top three on a podium (confetti once
 * when the page opens), then the rest of the top 10, then more as you scroll; your own row pinned under a gap when you're further
 * down. Beside it (computers): your level and every level, then How to earn points. Phones: one
 * column, your level in a card linking to Levels & points on its own page.
 */
const router = useRouter()
const PERIODS = [
  { key: '7', label: t.__('This week', 'membuild-community') },
  { key: '30', label: t.__('This month', 'membuild-community') },
  { key: 'all', label: t.__('All time', 'membuild-community') },
]
const period = ref('30')
const data = ref(null)
const loading = ref(true)
const shown = ref(10)

// Switching periods quickly: only the answer for the period picked last is shown.
let asked = 0
async function load() {
  const mine = ++asked
  loading.value = true
  try {
    const res = await api.leaderboard(period.value)
    if (mine !== asked) return
    data.value = res
    shown.value = 10
  } catch (e) {
    if (mine === asked) showToast(e.message)
  } finally {
    if (mine === asked) loading.value = false
  }
}
watch(period, load, { immediate: true })

const items = computed(() => data.value?.items || [])
const me = computed(() => data.value?.me || {})
const visible = computed(() => items.value.slice(0, shown.value))
// You're further down than the rows on screen: your own row pinned under a gap.
const pinned = computed(() => me.value.rank && !visible.value.some((i) => i.user.id === cfg.user.id))
// The top three stand on a podium (2nd, 1st, 3rd), once there are three; the list goes on from 4th.
const podium = computed(() => (items.value.length >= 3 ? [items.value[1], items.value[0], items.value[2]] : []))
const rows = computed(() => (podium.value.length ? visible.value.slice(3) : visible.value))
// Confetti on the podium: a burst the first time it shows after the page opens (not on every
// period switch), then soft pieces drifting behind the cards.
const burst = ref(true)
watch(
  () => podium.value.length,
  (n) => n && burst.value && setTimeout(() => (burst.value = false), 3200),
)
const PLACE = { 1: () => t.__('1st place', 'membuild-community'), 2: () => t.__('2nd place', 'membuild-community'), 3: () => t.__('3rd place', 'membuild-community') }
const pts = (n) => `${period.value === 'all' ? '' : '+'}${compactNumber(n)}`
const emptyTitle = computed(() =>
  ({ 7: t.__('No points yet this week', 'membuild-community'), 30: t.__('No points yet this month', 'membuild-community') })[period.value] || t.__('No points yet', 'membuild-community'),
)

// Hover a row (computers): Message them, right there.
const messaging = ref(0)
async function message(u) {
  if (messaging.value) return
  messaging.value = u.id
  try {
    const chat = await api.startChat([u.id])
    if (canDock()) openChat(chat)
    else router.push({ name: 'messages', params: { threadId: chat.id } })
  } catch (e) {
    showToast(e.message)
  } finally {
    messaging.value = 0
  }
}
// Infinite scroll: a marker under the list; when it comes near the screen, 10 more rows.
const moreSpot = ref(null)
let io = null
watch(moreSpot, (el) => {
  io?.disconnect()
  if (!el) return
  io = new IntersectionObserver(([e]) => e.isIntersecting && (shown.value += 10), { rootMargin: '400px' })
  io.observe(el)
})
onBeforeUnmount(() => io?.disconnect())

// Your row is "stuck" while it rides at the bottom (or, scrolled past, the top) of the screen: it
// then gets a frosted band around it so the rows behind don't compete. Once you scroll to your
// own place it sits in the list like any other row.
const stuck = ref(false)
let ticking = false
let dockWatch = null
function checkStuck() {
  ticking = false
  const row = document.querySelector('.list .row.me')
  const prev = row?.previousElementSibling
  if (!row || !prev) return (stuck.value = false)
  // In its place it sits 4px under the row before it; riding at the bottom it's lifted above
  // that, riding at the top (scrolled past) it's pushed below it.
  stuck.value = Math.abs(row.getBoundingClientRect().top - prev.getBoundingClientRect().bottom - 4) > 2
  clearDock(row)
}

// Computers: the chat dock's round buttons (New message, minimised chats, See it as a member)
// sit at the bottom right of the screen. Where they'd cover your pinned row, it rides just
// above the lowest one in its way; elsewhere 16px from the bottom. Measured, not guessed,
// because where they land depends on the window and its scrollbar.
function clearDock(row) {
  if (window.innerWidth < 768) return row.style.removeProperty('--me-bottom')
  const r = row.getBoundingClientRect()
  let top = Infinity
  for (const el of document.querySelectorAll('[data-chat-dock] .rail > *, .mvt-wrap')) {
    const b = el.getBoundingClientRect()
    if (b.width && b.bottom > window.innerHeight - 200 && b.left < r.right && b.right > r.left) top = Math.min(top, b.top)
  }
  row.style.setProperty('--me-bottom', top === Infinity ? '16px' : `${Math.round(window.innerHeight - top + 12)}px`)
}
const onScroll = () => {
  if (!ticking) {
    ticking = true
    requestAnimationFrame(checkStuck)
  }
}
onMounted(() => {
  // Capture: the page may scroll inside the app's frame rather than the window.
  document.addEventListener('scroll', onScroll, { passive: true, capture: true })
  window.addEventListener('resize', onScroll)
  // The dock's buttons come and go (a chat minimised, closed): measure again.
  const dockEl = document.querySelector('[data-chat-dock]')
  if (dockEl) {
    dockWatch = new MutationObserver(onScroll)
    dockWatch.observe(dockEl, { childList: true, subtree: true })
  }
})
onBeforeUnmount(() => {
  document.removeEventListener('scroll', onScroll, { capture: true })
  window.removeEventListener('resize', onScroll)
  dockWatch?.disconnect()
})
watch([rows, pinned], () => nextTick(checkStuck))
const writePost = () => router.push({ name: 'feed', query: { write: 1 } })
</script>

<template>
  <div class="board page-in">
    <!-- Computers: your level and every level, then how points are earned. -->
    <aside class="side" :aria-label="t.__('Levels and points', 'membuild-community')">
      <ThemeSlot name="leaderboard.levels" :data="{ levels: data?.levels || [], progress: data?.progress }"><LevelsCard :levels="data?.levels || []" :progress="data?.progress" /></ThemeSlot>
      <ThemeSlot name="leaderboard.earn-points" :data="{ rules: data?.rules || [] }"><EarnPoints :rules="data?.rules || []" /></ThemeSlot>
    </aside>

    <section class="ranks card">
      <header class="rank-head">
        <h1>{{ t.__('Leaderboard', 'membuild-community') }}</h1>
        <div class="periods" role="tablist" :aria-label="t.__('Period', 'membuild-community')">
          <button class="filter-pill" v-for="x in PERIODS" :key="x.key" role="tab" :aria-selected="period === x.key" :class="{ on: period === x.key }" @click="period = x.key">{{ x.label }}</button>
        </div>
      </header>

      <!-- Phones: your level and the way to Levels & points (computers have them beside the board). -->
      <div v-if="data" class="spot phone-only">
        <RouterLink :to="{ name: 'levels' }" class="spot-level phone-only">
          <strong>{{ t.sprintf(/* translators: %d: level number */ t.__('Level %d', 'membuild-community'), data?.progress?.level || cfg.user.level || 1) }}</strong>
          <span class="mini-bar"><i :style="{ width: `${Math.max(data?.progress?.percent || 0, 4)}%` }" /></span>
          <span class="go">{{ t.__('Levels & points', 'membuild-community') }} <Icon name="chevronRight" :size="18" /></span>
        </RouterLink>
      </div>

      <!-- Loading: rows in grey. -->
      <ol v-if="loading && !data" class="list" aria-hidden="true">
        <li v-for="n in 8" :key="n" class="row sk"><span class="skeleton" style="width: 18px; height: 14px" /><span class="skeleton" style="width: 44px; height: 44px; border-radius: 50%" /><span class="skeleton" style="flex: 1; max-width: 200px; height: 16px" /><span class="skeleton" style="width: 40px; height: 16px; margin-left: auto" /></li>
      </ol>

      <!-- Nobody has points in this period yet. -->
      <ThemeSlot v-else-if="data && !items.length" name="app.empty-state" :data="{ page: 'leaderboard', icon: 'star', title: emptyTitle, text: t.__('Post or comment and you’ll be the first one on the board.', 'membuild-community') }">
      <div class="empty-state">
        <span class="empty-state-icon" aria-hidden="true"><Icon name="star" :size="28" /></span>
        <strong class="empty-state-title">{{ emptyTitle }}</strong>
        <p class="empty-state-text">{{ t.__('Post or comment and you’ll be the first one on the board.', 'membuild-community') }}</p>
        <button class="btn btn-lg btn-primary" @click="writePost">{{ t.__('Write a post', 'membuild-community') }}</button>
      </div>
      </ThemeSlot>

      <template v-else>
        <ThemeSlot v-if="podium.length" name="leaderboard.podium" :data="{ podium, me: cfg.user, burst }">
        <ol class="podium" :class="{ dim: loading }" :aria-label="t.__('Top three', 'membuild-community')">
          <PodiumConfetti :burst="burst" />
          <li v-for="item in podium" :key="item.user.id" class="place rise-in" :class="[`p${item.rank}`, { me: item.user.id === cfg.user.id }]" :style="{ '--i': item.rank - 1 }">
            <span class="ring"><Avatar :user="item.user" :size="item.rank === 1 ? 76 : 60" link /><span class="chip" :aria-label="PLACE[item.rank]?.()">{{ item.rank }}</span></span>
            <span class="who">
              <RouterLink :to="{ name: 'member', params: { id: item.user.id } }" class="pname">{{ item.user.name }}</RouterLink>
              <small v-if="item.user.level">{{ t.sprintf(/* translators: %d: level number */ t.__('Level %d', 'membuild-community'), item.user.level) }}</small>
            </span>
            <span class="pts">{{ pts(item.points) }}</span>
          </li>
        </ol>
        </ThemeSlot>
        <ol class="list" :class="{ dim: loading }">
          <li v-for="item in rows" :key="item.user.id" class="row rise-in" :class="{ me: item.user.id === cfg.user.id, stuck: stuck && item.user.id === cfg.user.id }" :style="{ '--i': item.rank - 1 }">
            <span class="rank">{{ item.rank }}</span>
            <Avatar :user="item.user" :size="44" badge link online="top" />
            <RouterLink :to="{ name: 'member', params: { id: item.user.id } }" class="name">{{ item.user.name }}</RouterLink>
            <button v-if="item.user.id !== cfg.user.id" class="btn btn-ghost msg" :disabled="messaging === item.user.id" @click="message(item.user)"><Icon name="messenger" :size="16" /> {{ t.__('Message', 'membuild-community') }}</button>
            <span class="pts">{{ pts(item.points) }}</span>
          </li>
          <template v-if="pinned">
            <li class="gap" aria-hidden="true">· · ·</li>
            <li class="row me" :class="{ stuck }">
              <span class="rank">{{ me.rank }}</span>
              <Avatar :user="me.user || cfg.user" :size="44" badge />
              <RouterLink :to="{ name: 'me' }" class="name">{{ (me.user || cfg.user).name }}</RouterLink>
              <span class="pts">{{ pts(me.points) }}</span>
            </li>
          </template>
        </ol>
        <!-- More rows as you near the end (infinite scroll), 10 at a time. -->
        <span v-if="items.length > shown" :key="shown" :ref="(el) => (moreSpot = el)" class="more-spot" aria-hidden="true" />
      </template>
    </section>
  </div>
</template>

<style scoped>
.board {
  /* The page fade-in ends with nothing left over: a held opacity would stop your pinned row's
     frosted band from blurring the rows behind it. */
  animation-fill-mode: backwards;
  display: grid;
  grid-template-columns: 360px minmax(0, 1fr);
  gap: 24px;
  align-items: start;
  max-width: 1040px;
  margin: 0 auto;
  padding: 28px 16px 48px;
}

.side {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.ranks {
  /* The confetti reaches a little past the podium; never past the board's edges. */
  overflow-x: clip;
  padding: 0 0 20px;
  border-radius: var(--radius-lg);
}

.rank-head {
  position: relative;
  z-index: 1; /* above the podium's drifting confetti */
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
}

h1 {
  margin: 0;
  font-size: var(--fs-7xl);
}

.periods {
  display: flex;
  gap: 8px;
}

/* Your level (phones) */
.spot {
  margin: 20px 24px 12px;
  border-radius: var(--radius);
  background: var(--accent-soft);
}

.phone-only {
  display: none;
}

/* The list: rows line up with the heading and the spot card (24px in), with room between them. */
.list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0;
  padding: 0 12px;
  list-style: none;
  transition: opacity 0.15s;
}

.list.dim {
  opacity: 0.5;
}

.row {
  display: flex;
  align-items: center;
  gap: 16px;
  min-height: 68px;
  padding: 10px 12px;
  border-radius: var(--radius-md);
}

.row.me {
  background: var(--accent-soft);
}

/* Your own row stays in sight while you scroll (at the bottom until you reach it, then at the top), with a soft glow
   sweeping across it now and then, so you always know which one is you. The green and the glow
   are drawn by ::after (so the frosted band, ::before, can sit outside and behind it). */
.list .row.me {
  position: sticky;
  top: calc(var(--header-h) + env(safe-area-inset-top) + 12px);
  bottom: var(--me-bottom, 16px);
  z-index: 3;
  background: none;
  /* No rise-in fade here: an animated opacity would stop the frosted band seeing the rows behind. */
  animation: none;
}

.list .row.me::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 0;
  border-radius: var(--radius-md);
  background:
    linear-gradient(100deg, transparent 35%, color-mix(in srgb, var(--accent) 30%, transparent) 50%, transparent 65%) no-repeat,
    var(--accent-soft);
  background-size: 300% 100%, auto;
  background-position: 150% 0, 0 0;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 45%, transparent);
  animation: me-glow 4.5s ease-in-out 1s infinite;
  pointer-events: none;
}

.list .row.me.stuck::after {
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 45%, transparent), 0 10px 28px color-mix(in srgb, var(--shadow-color) 28%, transparent);
}

/* While it rides at the top or bottom: a frosted band around it. */
.list .row.me::before {
  content: '';
  position: absolute;
  /* (No fading mask: Chrome drops the blur from an element that has one.) */
  inset: -18px -8px;
  z-index: -1;
  border-radius: var(--radius-2xl);
  background: color-mix(in srgb, var(--surface) 70%, transparent);
  -webkit-backdrop-filter: blur(12px);
  backdrop-filter: blur(12px);
  opacity: 0;
  transition: opacity 0.25s;
  pointer-events: none;
}

.list .row.me.stuck::before {
  opacity: 1;
}

.list .row.me > * {
  position: relative;
  z-index: 1;
}

@keyframes me-glow {
  0% {
    background-position: 150% 0, 0 0;
  }

  45%,
  100% {
    background-position: -50% 0, 0 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .list .row.me::after {
    animation: none;
  }
}

.rank {
  display: grid;
  flex: none;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 800;
}

.row.me .rank {
  background: var(--accent);
  color: var(--accent-ink);
}

/* The podium: 2nd, 1st (raised), 3rd, each ringed in its medal colour. */
.podium {
  --gold: #f2c14e;
  --silver: #c3c9d2;
  --bronze: #d38b55;
  position: relative;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  align-items: end;
  gap: 12px;
  margin: 20px 24px 16px;
  padding: 0;
  list-style: none;
  transition: opacity 0.15s;
}

.podium.dim {
  opacity: 0.5;
}

.place {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  min-width: 0;
  padding: 22px 12px 18px;
  border-radius: var(--radius-xl);
  background: var(--surface-2);
  text-align: center;
}

.place.p1 {
  padding-top: 30px;
  padding-bottom: 26px;
  background: linear-gradient(180deg, color-mix(in srgb, var(--gold) 18%, var(--surface-2)), var(--surface-2));
}

.place.me {
  box-shadow: inset 0 0 0 2px var(--accent);
}

.ring {
  position: relative;
  display: grid;
  margin: 6px 0 4px;
  padding: 3px;
  border-radius: 50%;
  background: var(--silver);
}

.p1 .ring {
  background: var(--gold);
}

.p3 .ring {
  background: var(--bronze);
}

.ring :deep(.avatar-wrap) {
  border: 3px solid var(--surface-2);
  border-radius: 50%;
  box-sizing: content-box;
}

.chip {
  position: absolute;
  top: -12px;
  left: 50%;
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border: 3px solid var(--surface-2);
  border-radius: 50%;
  background: var(--silver);
  color: var(--ink);
  font-size: var(--fs-sm);
  font-weight: 800;
  transform: translateX(-50%);
}

.p1 .chip {
  background: var(--gold);
}

.p3 .chip {
  background: var(--bronze);
}

.pname {
  /* Up to two lines, so narrow screens show the whole name. */
  display: -webkit-box;
  max-width: 100%;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  color: var(--text);
  font-size: var(--fs-lg);
  font-weight: 700;
  line-height: 1.25;
  overflow-wrap: anywhere;
  text-decoration: none;
}

.p1 .pname {
  font-size: var(--fs-xl);
}

.pname:hover {
  text-decoration: underline;
}

.who {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  max-width: 100%;
  min-width: 0;
}

.who small {
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 600;
}

.name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  color: var(--text);
  font-size: var(--fs-xl);
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-decoration: none;
}

/* Points as a green pill, like How to earn points. */
.pts {
  flex: none;
  min-width: 60px;
  padding: 6px 12px;
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--fs-base);
  font-weight: 800;
  text-align: center;
}

/* Hovering a row (computers): the name underlines and Message shows. */
.msg {
  display: none;
  height: 40px;
  padding: 0 14px;
  font-size: var(--fs-base);
}

@media (hover: hover) {
  .row:hover {
    background: var(--surface-2);
  }

  .row.me:hover {
    background: var(--accent-soft);
  }

  .row:hover .name {
    text-decoration: underline;
  }

  .row:hover .msg {
    display: inline-flex;
  }
}

.gap {
  padding: 4px 0;
  color: var(--text-2);
  font-size: var(--fs-2xl);
  font-weight: 800;
  text-align: center;
  letter-spacing: 4px;
}

.more-spot {
  display: block;
  height: 1px;
}

.sk {
  pointer-events: none;
}

/* Tablets and phones: one column (no room for the levels beside it); your level in its own
   card above the board, linking to Levels & points. */
@media (max-width: 999px) {
  .board {
    display: block;
    max-width: 680px;
  }

  .side {
    display: none;
  }

  .phone-only {
    display: flex;
  }

  .spot-level {
    align-items: center;
    gap: 12px;
    min-height: 54px;
    padding: 0 16px 0 18px;
    flex: 1;
    color: var(--text);
    text-decoration: none;
  }

  .spot-level strong {
    flex: none;
    font-size: var(--fs-lg);
  }

  .mini-bar {
    flex: 1;
    height: 8px;
    max-width: 120px;
    overflow: hidden;
    border-radius: var(--radius-pill);
    background: var(--surface);
  }

  .mini-bar i {
    display: block;
    height: 100%;
    background: var(--accent-text);
  }

  .go {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-left: auto;
    color: var(--accent-text);
    font-size: var(--fs-lg);
    font-weight: 700;
  }
}

/* Phones: edge to edge, the filters split the width. */
@media (max-width: 767px) {
  .board {
    padding: 12px 0 24px;
  }

  .ranks {
    border: 0;
    border-radius: 0;
    box-shadow: none;
    background: transparent;
  }

  .rank-head {
    flex-direction: column;
    align-items: stretch;
    padding: 4px 16px 0;
    border: 0;
  }

  h1 {
    font-size: var(--fs-6xl);
  }

  .periods .filter-pill {
    flex: 1;
  }

  .spot {
    margin: 14px 16px 6px;
  }

  .list {
    padding: 0 4px;
  }

  /* Under the smaller header, just above the tab bar. */
  .list .row.me {
    top: calc(50px + env(safe-area-inset-top) + 10px);
    bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom) + 10px);
  }

  .podium {
    gap: 8px;
    margin: 18px 16px 12px;
  }

  .place {
    padding: 18px 6px 14px;
  }

  .place.p1 {
    padding-top: 24px;
    padding-bottom: 20px;
  }

  .pname,
  .p1 .pname {
    font-size: var(--fs-base);
  }

  .place .pts {
    min-width: 0;
    padding: 5px 10px;
    font-size: var(--fs-md);
  }
}

</style>
