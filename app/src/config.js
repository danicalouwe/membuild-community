import { reactive } from 'vue'
import { t } from './lib/i18n.js'

// Server-provided settings, printed by templates/portal.php. Reactive, so a
// level-up (cfg.user.level) updates every avatar badge at once.
export const cfg = reactive(window.membuildCommunity || {
  restUrl: '/wp-json/membuild-community/v1',
  nonce: '',
  ajaxUrl: '/wp-admin/admin-ajax.php',
  basePath: '/community',
  siteName: 'Community',
  logoutUrl: '/wp-login.php?action=logout',
  user: { id: 0, name: '', avatar: '' },
  canModerate: false,
  limits: { reelSeconds: 60, chunkBytes: 1048576 },
  community: {},
})

// Settings an older server might not send yet.
cfg.community = {
  features: { reels: true, stories: true, classroom: true, leaderboard: true, messages: true },
  canPost: true,
  levelNames: [],
  ...cfg.community,
}

/** "MemBuild - Community" → "MB", "Growth Circle" → "GC" (same rule as the server's Settings::initials). */
export function initialsOf(name) {
  const words = String(name || '').split(/[\s\-–—|·]+/).filter(Boolean)
  const caps = (words[0] || '').match(/[A-Z]/g) || []
  if (caps.length >= 2) return caps.slice(0, 2).join('')
  return words.slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '•'
}

export const feature = (key) => cfg.community.features?.[key] !== false

/** "Level 3" or, when the admin named it, "Level 3 · Builder". */
export function levelLabel(level) {
  const name = cfg.community.levelNames?.[level - 1]
  return name
    ? /* translators: 1: level number, 2: the level's name, e.g. "Builder" */
      t.sprintf(t.__('Level %1$d · %2$s', 'membuild-community'), level, name)
    : /* translators: %d: level number */
      t.sprintf(t.__('Level %d', 'membuild-community'), level)
}
