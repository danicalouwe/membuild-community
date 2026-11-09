import { t } from './i18n.js'

/**
 * The pages of community Settings, in menu order, under short group headings.
 * Each page has its own address: /settings/<slug>.
 *
 * - `id`: the section's element id (s-<id>) and its old anchor (#s-<id>, ?s=<id>), kept so old
 *   links forward to the page.
 * - `slug`: the page's address. Readable words, never renamed once released.
 * - `find`: setting names on the page that Search settings looks for, in the owner's words.
 *
 * A new section: add it here (and its component in SettingsView's COMPONENTS).
 */
export const SETTINGS_GROUPS = [
  { id: 'basics', label: t.__('Basics', 'membuild-community') },
  { id: 'members', label: t.__('Members and access', 'membuild-community') },
  { id: 'content', label: t.__('Content', 'membuild-community') },
  { id: 'safety', label: t.__('Safety', 'membuild-community') },
  { id: 'connections', label: t.__('Connections', 'membuild-community') },
  { id: 'about', label: t.__('About', 'membuild-community') },
]

export const SETTINGS_PAGES = [
  {
    id: 'general', slug: 'community', group: 'basics', icon: 'home', label: t.__('Your community', 'membuild-community'),
    find: [t.__('Community name', 'membuild-community'), t.__('Description', 'membuild-community'), t.__('Support email', 'membuild-community'), t.__('Logo', 'membuild-community'), t.__('Main color', 'membuild-community'), t.__('Light or dark, to start with?', 'membuild-community')],
  },
  {
    id: 'website', slug: 'main-website', group: 'basics', icon: 'globe', label: t.__('Main website', 'membuild-community'),
    find: [t.__('New pages you can add', 'membuild-community'), t.__('Color and logo', 'membuild-community'), t.__('Money-back promise', 'membuild-community'), t.__('Coming soon', 'membuild-community')],
  },
  {
    id: 'joining', slug: 'joining', group: 'basics', icon: 'key', label: t.__('Joining & logging in', 'membuild-community'),
    find: [t.__('How people join', 'membuild-community'), t.__('Where the join button goes', 'membuild-community'), t.__('Minimum age', 'membuild-community'), t.__('Terms page', 'membuild-community'), t.__('How your login and join pages look', 'membuild-community'), t.__('Join page headline', 'membuild-community'), t.__('Where members land', 'membuild-community')],
  },
  {
    id: 'email', slug: 'email', group: 'basics', icon: 'mail', label: t.__('Email', 'membuild-community'),
    find: [t.__('How emails are sent', 'membuild-community'), t.__('SMTP server', 'membuild-community'), t.__('From name', 'membuild-community'), t.__('From address', 'membuild-community')],
  },
  {
    id: 'features', slug: 'features', group: 'basics', icon: 'grid', label: t.__('Features', 'membuild-community'),
    find: [t.__('Classroom', 'membuild-community'), t.__('Messages', 'membuild-community'), t.__('Stories', 'membuild-community'), t.__('Polls', 'membuild-community'), t.__('Reels', 'membuild-community'), t.__('Leaderboard', 'membuild-community'), t.__('Events', 'membuild-community'), t.__('Places', 'membuild-community'), t.__('Members directory', 'membuild-community')],
  },
  { id: 'topics', slug: 'topics', group: 'basics', icon: 'hash', label: t.__('Topics', 'membuild-community'), find: [t.__('Order on Home', 'membuild-community')] },
  {
    id: 'permissions', slug: 'permissions', group: 'members', icon: 'shield', label: t.__('Who can do what', 'membuild-community'),
    find: [t.__('Moderators', 'membuild-community'), t.__('Who can write posts on Home?', 'membuild-community'), t.__('Who can members send messages to?', 'membuild-community')],
  },
  { id: 'access', slug: 'memberships', group: 'members', icon: 'lock', label: t.__('Memberships', 'membuild-community'), find: [] },
  {
    id: 'points', slug: 'points', group: 'members', icon: 'trophy', label: t.__('Points & levels', 'membuild-community'),
    find: [t.__('How many levels', 'membuild-community'), t.__('Levels and the points they need', 'membuild-community'), t.__('Points for each thing members do', 'membuild-community')],
  },
  { id: 'video', slug: 'video', group: 'content', icon: 'video', label: t.__('Video', 'membuild-community'), find: ['Bunny Stream', t.__('Protect video links', 'membuild-community')] },
  { id: 'gifs', slug: 'gifs', group: 'content', icon: 'gif', label: t.__('GIFs', 'membuild-community'), find: [t.__('GIPHY key', 'membuild-community'), t.__('Which GIFs can show', 'membuild-community')] },
  { id: 'security', slug: 'security', group: 'safety', icon: 'shieldCheck', label: t.__('Security', 'membuild-community'), find: [t.__('Two-step login', 'membuild-community')] },
  { id: 'import', slug: 'import', group: 'connections', icon: 'download', label: t.__('Import', 'membuild-community'), find: [t.__('Past imports', 'membuild-community')] },
  { id: 'updates', slug: 'news', group: 'about', icon: 'bell', label: t.__('News from MemBuild', 'membuild-community'), find: [] },
  {
    id: 'advanced', slug: 'address', group: 'about', icon: 'settings', label: t.__('Address & safety key', 'membuild-community'),
    find: [t.__('Community address', 'membuild-community'), t.__('Safety key', 'membuild-community'), t.__('When the plugin is removed', 'membuild-community')],
  },
]

// Old anchors whose page has another name, including sections that live in add-ons (they land on
// their page once it's there, and on Settings until then). `key` is a place on the address page.
const RENAMED = { general: 'community', website: 'main-website', access: 'memberships', updates: 'news', advanced: 'address', key: 'address', addons: 'add-ons' }

/** The address word for an old section id (or an address word), known or not. */
export const settingsSlug = (id) => SETTINGS_PAGES.find((p) => p.id === id || p.slug === id)?.slug || RENAMED[id] || id

/**
 * Where an old Settings link goes: /settings#s-access and /settings?s=access both become
 * /settings/memberships. Null when the link is already new. #s-key keeps its anchor: the safety
 * key box rings when you arrive.
 */
export function forwardOldSettingsLink(to) {
  const section = to.params.section
  // Addresses are matched whatever the case; /settings/Email shows as /settings/email.
  if (typeof section === 'string' && section !== section.toLowerCase()) return { ...to, params: { section: section.toLowerCase() }, replace: true }
  if (section) return null
  const fromQuery = typeof to.query.s === 'string' ? to.query.s : ''
  const fromHash = /^#s-[\w-]+$/.test(to.hash) ? to.hash.slice(3) : ''
  const old = (fromQuery || fromHash).toLowerCase()
  if (!old) return null
  return { name: 'settings', params: { section: settingsSlug(old) }, hash: old === 'key' ? '#s-key' : '', replace: true }
}

// The page you were last on, so Settings opens there on a computer. This browser only.
const LAST = 'mbcom-settings-last'
export const lastSettingsPage = () => {
  try {
    return localStorage.getItem(LAST) || ''
  } catch {
    return ''
  }
}
export const rememberSettingsPage = (slug) => {
  try {
    localStorage.setItem(LAST, slug)
  } catch {
    /* private window: fine, Settings opens on the first page */
  }
}
