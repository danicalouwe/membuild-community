import { t } from './i18n.js'
import { offerButton, priceLabel } from './offer.js'

/**
 * Course access, as members and admins read it.
 * `lock` comes from the server: null (open), { reason: 'level', level, points_to_go } or
 * { reason: 'groups', groups: [names] }, either with an optional `cta: { label, url }`.
 */
export function lockLabel(lock) {
  if (!lock) return ''
  if (lock.reason === 'level') return t.sprintf(/* translators: %d: level number */ t.__('Opens at Level %d', 'membuild-community'), lock.level)
  if (lock.membership) return t.sprintf(/* translators: %s: membership name, e.g. "Pro membership" */ t.__('Part of the %s', 'membuild-community'), lock.membership.name)
  return lock.groups.length ? t.sprintf(/* translators: %s: membership names */ t.__('Part of the %s', 'membuild-community'), lock.groups.join(', ')) : t.__('Members with access only', 'membuild-community')
}

/** The line under it: "3 courses for $49/month.", "40 points away.", or "Ask an admin for access." */
export function lockHint(lock) {
  if (!lock) return ''
  if (lock.reason === 'level') {
    return lock.points_to_go > 0
      ? t.sprintf(/* translators: %d: points still needed */ t._n('%d point away.', '%d points away.', lock.points_to_go, 'membuild-community'), lock.points_to_go)
      : t.__('Earn points by posting, commenting and finishing lessons.', 'membuild-community')
  }
  const offer = lock.membership?.offer
  if (!offer) return t.__('Ask an admin for access.', 'membuild-community')
  const price = priceLabel(offer)
  const n = 1 + (lock.membership.more?.length || 0)
  if (!price) return t.sprintf(/* translators: %d: number of courses */ t._n('%d course.', '%d courses.', n, 'membuild-community'), n)
  return t.sprintf(/* translators: 1: number of courses, 2: price, e.g. "$49/month" */ t._n('%1$d course for %2$s.', '%1$d courses for %2$s.', n, 'membuild-community'), n, price)
}

/** The bar's button on a locked course: "Get access for $49/month", "How to unlock", or none (invite only). */
export function unlockButton(lock) {
  if (!lock) return ''
  if (lock.reason === 'level') return t.__('How to unlock', 'membuild-community')
  return lock.membership?.offer ? offerButton(lock.membership.offer) : ''
}

/** The short tag admins see on a course card: who it's open to. */
export function accessTag(course) {
  if (course.access === 'level' && course.unlock_level > 1) return t.sprintf(/* translators: %d: level number */ t.__('Level %d+', 'membuild-community'), course.unlock_level)
  // No group yet means nobody but staff can open it; say that plainly instead of naming a setting.
  if (course.access === 'groups') return course.group_names?.length ? course.group_names.join(', ') : t.__('Hidden from members', 'membuild-community')
  return ''
}
