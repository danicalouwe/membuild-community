<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
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
import { t } from '../lib/i18n.js'

/**
 * Leaderboard (V2): This week / This month / All time; your spot at the top ("7 more points to
 * pass Emma"); the top 10, then more as you scroll; your own row pinned under a gap when you're further
 * down. Beside it (computers): your level and every level, then How to earn points. Phones: one
 * column, your spot and your level in one card, Levels & points on its own page.
 */
const router = useRouter()
const PERIODS = [
  { key: '7', label: t.__('This week', 'membuild-community'), spot: t.__('this week', 'membuild-community') },
  { key: '30', label: t.__('This month', 'membuild-community'), spot: t.__('this month', 'membuild-community') },
  { key: 'all', label: t.__('All time', 'membuild-community'), spot: t.__('of all time', 'membuild-community') },
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

const p = computed(() => PERIODS.find((x) => x.key === period.value))
const items = computed(() => data.value?.items || [])
const me = computed(() => data.value?.me || {})
const visible = computed(() => items.value.slice(0, shown.value))
// You're further down than the rows on screen: your own row pinned under a gap.
const pinned = computed(() => me.value.rank && !visible.value.some((i) => i.user.id === cfg.user.id))
const medal = (rank) => ['🥇', '🥈', '🥉'][rank - 1]
const spotTitle = computed(() => t.sprintf(/* translators: 1: rank, 2: "this week" / "this month" / "of all time" */ t.__('You’re #%1$d %2$s', 'membuild-community'), me.value.rank, p.value.spot))
const spotLine = computed(() => {
  if (me.value.rank === 1) return t.__('You’re leading. Keep it up!', 'membuild-community')
  if (!me.value.ahead) return ''
  if (!me.value.ahead.name) return t.sprintf(/* translators: %s: points */ t._n('%s more point to move up', '%s more points to move up', me.value.ahead.needed, 'membuild-community'), me.value.ahead.needed)
  const first = me.value.ahead.name.split(' ')[0]
  return t.sprintf(/* translators: 1: points, 2: a first name */ t._n('%1$s more point to pass %2$s', '%1$s more points to pass %2$s', me.value.ahead.needed, 'membuild-community'), me.value.ahead.needed, first)
})
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
const writePost = () => router.push({ name: 'feed', query: { write: 1 } })
</script>

<template>
  <div class="board page-in">
    <!-- Computers: your level and every level, then how points are earned. -->
    <aside class="side">
      <LevelsCard :levels="data?.levels || []" :progress="data?.progress" />
      <EarnPoints :rules="data?.rules || []" />
    </aside>

    <section class="ranks card">
      <header class="rank-head">
        <h1>{{ t.__('Leaderboard', 'membuild-community') }}</h1>
        <div class="periods" role="tablist" :aria-label="t.__('Period', 'membuild-community')">
          <button v-for="x in PERIODS" :key="x.key" role="tab" :aria-selected="period === x.key" :class="{ on: period === x.key }" @click="period = x.key">{{ x.label }}</button>
        </div>
      </header>

      <!-- Your spot (phones: with your level under it). -->
      <div v-if="me.rank || data" class="spot" :class="{ bare: !me.rank }">
        <div v-if="me.rank" class="spot-top">
          <span class="spot-rank">#{{ me.rank }}</span>
          <span><strong>{{ spotTitle }}</strong><small v-if="spotLine">{{ spotLine }}</small></span>
        </div>
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
      <div v-else-if="data && !items.length" class="empty">
        <span class="empty-ic"><Icon name="star" :size="28" /></span>
        <strong>{{ emptyTitle }}</strong>
        <p>{{ t.__('Post or comment and you’ll be the first one on the board.', 'membuild-community') }}</p>
        <button class="btn btn-lg btn-primary" @click="writePost">{{ t.__('Write a post', 'membuild-community') }}</button>
      </div>

      <template v-else>
        <ol class="list" :class="{ dim: loading }">
          <li v-for="item in visible" :key="item.user.id" class="row rise-in" :class="{ me: item.user.id === cfg.user.id }" :style="{ '--i': item.rank - 1 }">
            <span class="rank">{{ medal(item.rank) || item.rank }}</span>
            <Avatar :user="item.user" :size="44" badge link online="top" />
            <RouterLink :to="{ name: 'member', params: { id: item.user.id } }" class="name">{{ item.user.name }}</RouterLink>
            <button v-if="item.user.id !== cfg.user.id" class="btn btn-ghost msg" :disabled="messaging === item.user.id" @click="message(item.user)"><Icon name="messenger" :size="16" /> {{ t.__('Message', 'membuild-community') }}</button>
            <span class="pts">{{ pts(item.points) }}</span>
          </li>
          <template v-if="pinned">
            <li class="gap" aria-hidden="true">· · ·</li>
            <li class="row me">
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
  display: grid;
  grid-template-columns: 360px minmax(0, 1fr);
  gap: 20px;
  align-items: start;
  max-width: 1008px;
  margin: 0 auto;
  padding: 28px 16px 48px;
}

.side {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.ranks {
  padding: 0 0 16px;
  border-radius: 16px;
}

.rank-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 18px;
  border-bottom: 1px solid var(--border);
}

h1 {
  margin: 0;
  font-size: 28px;
}

.periods {
  display: flex;
  gap: 8px;
}

.periods button {
  height: 52px;
  padding: 0 20px;
  white-space: nowrap;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
  font-size: 17px;
  font-weight: 700;
}

.periods button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

/* Your spot */
.spot {
  margin: 16px 16px 6px;
  border-radius: 14px;
  background: var(--accent-soft);
}

.spot.bare {
  display: none;
}

.spot-top {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 18px 20px;
}

.spot-rank {
  font-size: 28px;
  font-weight: 800;
}

.spot-top strong {
  display: block;
  font-size: 18px;
}

.spot-top small {
  color: var(--text-2);
  font-size: 15px;
}

.phone-only {
  display: none;
}

/* The list */
.list {
  margin: 0;
  padding: 6px 8px 0;
  list-style: none;
  transition: opacity 0.15s;
}

.list.dim {
  opacity: 0.5;
}

.row {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 60px;
  padding: 8px 12px;
  border-radius: 12px;
}

.row.me {
  background: var(--accent-soft);
}

.rank {
  flex: none;
  width: 24px;
  color: var(--text-2);
  font-size: 16px;
  font-weight: 700;
  text-align: center;
}

.name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  color: var(--text);
  font-size: 17px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-decoration: none;
}

.pts {
  flex: none;
  color: var(--ok-text);
  font-size: 17px;
  font-weight: 800;
}

/* Hovering a row (computers): the name underlines and Message shows. */
.msg {
  display: none;
  height: 40px;
  padding: 0 14px;
  font-size: 15px;
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
  font-size: 18px;
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

/* Empty period */
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 40px 24px 28px;
  text-align: center;
}

.empty-ic {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  margin-bottom: 6px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.empty strong {
  font-size: 20px;
}

.empty p {
  max-width: 340px;
  margin: 0 0 8px;
  color: var(--text-2);
  font-size: 15px;
  line-height: 1.5;
}

/* Phones: one column, the filters split the width, your level in the spot card. */
@media (max-width: 767px) {
  .board {
    display: block;
    padding: 12px 0 24px;
  }

  .side {
    display: none;
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
    font-size: 26px;
  }

  .periods button {
    flex: 1;
    height: 48px;
    padding: 0;
    font-size: 16px;
  }

  .spot {
    margin: 14px 16px 6px;
  }

  .spot.bare {
    display: block;
  }

  .spot-top {
    padding: 16px 18px 14px;
  }

  .phone-only {
    display: flex;
  }

  .spot-level {
    align-items: center;
    gap: 12px;
    min-height: 54px;
    padding: 0 16px 0 18px;
    border-top: 1px solid color-mix(in srgb, var(--accent) 40%, transparent);
    color: var(--text);
    text-decoration: none;
  }

  .spot.bare .spot-level {
    border-top: 0;
  }

  .spot-level strong {
    flex: none;
    font-size: 16px;
  }

  .mini-bar {
    flex: 1;
    height: 8px;
    max-width: 120px;
    overflow: hidden;
    border-radius: 999px;
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
    font-size: 16px;
    font-weight: 700;
  }

  .list {
    padding: 4px 8px 0;
  }
}
</style>
