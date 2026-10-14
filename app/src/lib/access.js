import { t } from './i18n.js'

/**
 * Course access, as members and admins read it.
 * `lock` comes from the server: null (open), { reason: 'level', level, points_to_go } or
 * { reason: 'groups', groups: [names] }, either with an optional `cta: { label, url }`.
 */
export function lockLabel(lock) {
  if (!lock) return ''
  if (lock.reason === 'level') return t.sprintf(/* translators: %d: level number */ t.__('Reach Level %d to unlock', 'membuild-community'), lock.level)
  return lock.groups.length ? t.sprintf(/* translators: %s: access group names, e.g. "VIP, Coaching clients" */ t.__('For %s', 'membuild-community'), lock.groups.join(', ')) : t.__('Members with access only', 'membuild-community')
}

/** What a locked member can do about it. */
export function lockHint(lock) {
  if (!lock) return ''
  if (lock.reason === 'level' && lock.points_to_go > 0) {
    const away = t.sprintf(/* translators: %d: points still needed */ t._n("You're %d point away.", "You're %d points away.", lock.points_to_go, 'membuild-community'), lock.points_to_go)
    return `${away} ${t.__('Earn points by posting, commenting, reacting and finishing lessons.', 'membuild-community')}`
  }
  if (lock.reason === 'level') return t.__('Earn points by posting, commenting, reacting and finishing lessons.', 'membuild-community')
  return lock.cta ? '' : t.__('Ask an admin if you should have access to this course.', 'membuild-community')
}

/** The short tag admins see on a course card: who it's open to. */
export function accessTag(course) {
  if (course.access === 'level' && course.unlock_level > 1) return t.sprintf(/* translators: %d: level number */ t.__('Level %d+', 'membuild-community'), course.unlock_level)
  // No group yet means nobody but staff can open it; say that plainly instead of naming a setting.
  if (course.access === 'groups') return course.group_names?.length ? course.group_names.join(', ') : t.__('Hidden from members', 'membuild-community')
  return ''
}
