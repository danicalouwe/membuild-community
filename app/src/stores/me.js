import { reactive } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'

// My points, level and streak. Loading it also records today's visit.
export const me = reactive({ stats: null, levelUp: null })

let timer = null

export async function refreshStats() {
  try {
    const stats = await api.meStats()
    const before = me.stats?.level ?? cfg.user.level
    me.stats = stats
    cfg.user.level = stats.level
    if (before && stats.level > before) {
      me.levelUp = { level: stats.level, unlocked: stats.unlocks.filter((u) => u.level > before && u.level <= stats.level) }
    }
  } catch {
    /* stats are a nice-to-have; never block the app on them */
  }
}

/** After an action that may earn points: refresh once things have settled. */
export function pointsMayHaveChanged() {
  clearTimeout(timer)
  timer = setTimeout(refreshStats, 800)
}
