<script setup>
import { ref, watch } from 'vue'
import { api } from '../api.js'
import { cfg, levelLabel } from '../config.js'
import { compactNumber } from '../lib/time.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import { t } from '../lib/i18n.js'

const PERIODS = [
  { key: '7', label: t._x('7-day', 'leaderboard period', 'membuild-community') },
  { key: '30', label: t._x('30-day', 'leaderboard period', 'membuild-community') },
  { key: 'all', label: t._x('All-time', 'leaderboard period', 'membuild-community') },
]
const period = ref('30')
const data = ref(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    data.value = await api.leaderboard(period.value)
  } finally {
    loading.value = false
  }
}
watch(period, load, { immediate: true })

const medal = (rank) => ['🥇', '🥈', '🥉'][rank - 1]
const pointCount = (n) => t.sprintf(/* translators: %s: number of points */ t._n('%s point', '%s points', n, 'membuild-community'), compactNumber(n))
const unlocks = (courses) => t.sprintf(/* translators: %s: course titles, comma-separated */ t.__('unlocks %s', 'membuild-community'), courses.join(', '))
const shareOfMembers = (pct) => t.sprintf(/* translators: %s: percent of members at this level */ t.__('%s%% of members', 'membuild-community'), pct)
const rankLabel = (rank) => t.sprintf(/* translators: %d: your position on the leaderboard */ t.__('#%d', 'membuild-community'), rank)
</script>

<template>
  <div class="board">
    <section class="levels card" :aria-label="t.__('Levels', 'membuild-community')">
      <header>
        <Avatar :user="cfg.user" :size="56" badge />
        <div>
          <strong>{{ cfg.user.name }}</strong>
          <span>{{ levelLabel(cfg.user.level || 1) }}</span>
        </div>
      </header>
      <ol>
        <li v-for="l in data?.levels || []" :key="l.level" :class="{ mine: l.level === (cfg.user.level || 1), locked: l.level > (cfg.user.level || 1) }">
          <span class="lnum">
            <Icon v-if="l.level > (cfg.user.level || 1)" name="lock" :size="13" />
            <template v-else>{{ l.level }}</template>
          </span>
          <span class="ltext">
            <strong>{{ levelLabel(l.level) }}</strong>
            <small>{{ pointCount(l.min) }}<template v-if="l.courses.length"> · {{ unlocks(l.courses) }}</template></small>
          </span>
          <span class="pct">{{ shareOfMembers(l.percent) }}</span>
        </li>
      </ol>
    </section>

    <section class="ranks card">
      <header class="rank-head">
        <h1>{{ t.__('Leaderboard', 'membuild-community') }}</h1>
        <div class="periods" role="tablist" :aria-label="t.__('Period', 'membuild-community')">
          <button v-for="p in PERIODS" :key="p.key" role="tab" :aria-selected="period === p.key" :class="{ on: period === p.key }" @click="period = p.key">
            {{ p.label }}
          </button>
        </div>
      </header>

      <ol class="list" :class="{ dim: loading }">
        <li v-for="item in data?.items || []" :key="item.user.id" :class="{ me: item.user.id === cfg.user.id, top: item.rank <= 3 }">
          <span class="rank">{{ medal(item.rank) || item.rank }}</span>
          <Avatar :user="item.user" :size="40" badge link />
          <RouterLink :to="{ name: 'member', params: { id: item.user.id } }" class="name">{{ item.user.name }}</RouterLink>
          <span class="pts">{{ period === 'all' ? '' : '+' }}{{ compactNumber(item.points) }}</span>
        </li>
        <li v-if="data && !data.items.length" class="empty">{{ t.__('No points earned in this period yet. Post something to get on the board!', 'membuild-community') }}</li>
      </ol>

      <footer v-if="data" class="mine-row">
        <span>{{ t.__('Your rank', 'membuild-community') }}</span>
        <strong>{{ data.me.rank ? rankLabel(data.me.rank) : '—' }}</strong>
        <span class="mine-pts">{{ pointCount(data.me.points) }}</span>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.board {
  max-width: 1000px;
  margin: 0 auto;
  padding: 20px 16px;
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 16px;
  align-items: start;
}

.levels {
  padding: 18px;
}

.levels header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}

.levels header strong {
  display: block;
  font-size: 17px;
}

.levels header span {
  color: var(--accent-text);
  font-weight: 650;
  font-size: 14px;
}

.levels ol,
.list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.levels li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 6px;
  border-radius: 10px;
}

.levels li.mine {
  background: var(--accent-soft);
}

.levels li.locked {
  opacity: 0.65;
}

.lnum {
  display: grid;
  place-items: center;
  flex: none;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--surface-2);
  font-weight: 800;
  font-size: 13px;
}

.mine .lnum {
  background: var(--accent);
  color: var(--accent-ink);
}

.ltext {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

.ltext strong {
  font-size: 14px;
}

.ltext small {
  font-size: 12px;
  color: var(--text-3);
}

.pct {
  font-size: 12px;
  color: var(--text-3);
  white-space: nowrap;
}

.ranks {
  overflow: hidden;
}

.rank-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 16px 18px;
  border-bottom: 1px solid var(--border);
}

.rank-head h1 {
  margin: 0;
  font-size: 20px;
}

.periods {
  display: flex;
  gap: 4px;
  padding: 3px;
  border-radius: 999px;
  background: var(--surface-2);
}

.periods button {
  padding: 6px 14px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 650;
  color: var(--text-2);
}

.periods button.on {
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--shadow);
}

.list {
  padding: 6px 8px;
  transition: opacity 0.15s ease;
}

.list.dim {
  opacity: 0.5;
}

.list li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border-radius: 10px;
}

.list li.me {
  background: var(--accent-soft);
}

.rank {
  width: 28px;
  text-align: center;
  font-weight: 800;
  color: var(--text-2);
}

.top .rank {
  font-size: 20px;
}

.name {
  color: var(--text);
  flex: 1;
  min-width: 0;
  font-weight: 650;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pts {
  font-weight: 750;
  color: #2fb36b;
}

.empty {
  justify-content: center;
  color: var(--text-3);
  text-align: center;
}

.mine-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 18px;
  border-top: 1px solid var(--border);
  background: var(--surface-2);
  font-size: 14px;
}

.mine-pts {
  margin-left: auto;
  color: var(--text-2);
}

@media (max-width: 799px) {
  .board {
    grid-template-columns: 1fr;
    padding: 10px 0;
  }

  .levels {
    order: 2;
  }

  .board .card {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }
}
</style>
