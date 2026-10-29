/**
 * The signed-in member's progress for Community themes (theme contract 1): window.MBCommunity.member.
 * Read-only and live: it updates when they earn points or reach a level, so a theme's piece that
 * reads it redraws by itself. Comes from /me/stats (stores/me.js), the same numbers the community
 * shows on profiles and the Leaderboard. Before those load, `loaded` is false and the numbers are 0.
 *
 * Contract 1 promise: these fields are only ever added to, never renamed or removed.
 */
import { reactive, readonly, watchEffect } from 'vue'
import { cfg, levelLabel } from '../config.js'
import { me } from '../stores/me.js'

export const MEMBER_FIELDS = {
  loaded: 'have the numbers arrived yet',
  points: 'all-time points',
  level: 'level number',
  levelLabel: '“Level 4 · Launcher” (or “Level 4” when levels have no names)',
  levelName: 'the level’s own name, or ""',
  levelProgress: 'how far through this level, 0–100',
  nextLevel: 'the next level’s number, or null at the top',
  nextLevelLabel: 'the next level, as above, or ""',
  toNext: 'points still needed for the next level',
  streak: 'days in a row they’ve been active, today included',
  bestStreak: 'their longest streak',
  rankThisMonth: 'their place on the Leaderboard over the last 30 days, or null',
  recent: 'the latest points earned, newest first: [{ points, label, at }]',
}

const state = reactive({ loaded: false, points: 0, level: cfg.user?.level || 1, levelLabel: '', levelName: '', levelProgress: 0, nextLevel: null, nextLevelLabel: '', toNext: 0, streak: 0, bestStreak: 0, rankThisMonth: null, recent: [] })

watchEffect(() => {
  const s = me.stats
  const level = s?.level ?? cfg.user?.level ?? 1
  Object.assign(state, {
    loaded: !!s,
    points: s?.points ?? 0,
    level,
    levelLabel: levelLabel(level),
    levelName: cfg.community.levelNames?.[level - 1] || '',
    levelProgress: s?.level_progress ?? 0,
    nextLevel: s?.next_level ?? null,
    nextLevelLabel: s?.next_level ? levelLabel(s.next_level) : '',
    toNext: s?.to_next ?? 0,
    streak: s?.streak ?? 0,
    bestStreak: s?.best_streak ?? 0,
    rankThisMonth: s?.rank_30 ?? null,
    recent: (s?.recent || []).map((r) => ({ points: r.points, label: r.label, at: r.at })),
  })
})

export const member = readonly(state)
