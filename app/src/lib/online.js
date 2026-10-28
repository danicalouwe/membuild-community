import { t } from './i18n.js'

// Who's around right now. The server sends `active_at` on a member only when the
// viewer may see it (the owner's switch, both people's Privacy choice); otherwise null.
const NOW = 5 * 60 * 1000

/** Active in the last 5 minutes: the green dot. */
export function isOnline(user) {
  return !!user?.active_at && Date.now() - new Date(user.active_at).getTime() < NOW
}

/** "Active now", "Active 15 min ago", "Active 3 h ago", or '' (never "Offline"). */
export function activeLabel(user) {
  if (!user?.active_at) return ''
  const mins = Math.floor((Date.now() - new Date(user.active_at).getTime()) / 60000)
  if (mins < 5) return t.__('Active now', 'membuild-community')
  if (mins < 60) return t.sprintf(/* translators: %d: minutes */ t.__('Active %d min ago', 'membuild-community'), mins)
  const hours = Math.floor(mins / 60)
  return hours < 24 ? t.sprintf(/* translators: %d: hours */ t.__('Active %d h ago', 'membuild-community'), hours) : ''
}
