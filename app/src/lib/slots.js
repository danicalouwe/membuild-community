/**
 * Slots (theme contract, layer 2): named places in the app that a Community theme can
 * replace, or add to. A theme is a small WordPress plugin that prints a script on the
 * community page (the membuild_community/app_head or app_footer hooks) and hands over its
 * pieces when the app is ready:
 *
 *   window.MBCommunity.ready((mb) => {
 *     mb.replaceSlot('classroom.course-list', MyCourseList, { theme: 'My theme', contract: 1 })
 *     mb.addToSlot('home.before-feed', MyBanner, { theme: 'My theme', contract: 1 })
 *   })
 *
 * A piece is a Vue 3 component (build it with mb.vue, the app's own Vue). mb.member has the
 * signed-in member's streak, level and points (lib/member.js), live. A piece gets the same
 * data the default uses, as props, and the default itself as its default slot, so it can
 * show the default and add around it. A slot a theme leaves alone stays as it is. If a piece
 * fails, the default shows instead and the site's Problems page says which theme and slot.
 *
 * Join and Log in are pages printed by PHP, not the app: their slot is the WordPress filter
 * membuild_community/join_picture (see templates/login.php), and their styles go on
 * membuild_community/login_head.
 *
 * Contract 1 promise: slot names and the props listed in SLOTS are only ever added to,
 * never renamed or removed. A theme built for a newer contract than this app knows is left
 * out (with a note on the Problems page) rather than half working.
 */
import { markRaw, reactive } from 'vue'
import { reportProblem } from './problems.js'

export const CONTRACT = 1

// Class names a theme's stylesheet may style (contract 1): kept on the same kinds of elements in
// every update. Anything else in the community's markup can change; reach it through a slot.
export const STYLED_CLASSES = ['btn', 'btn-lg', 'btn-primary', 'btn-ghost', 'btn-dark', 'card', 'filter-pill', 'section-search', 'status-tag', 'empty-state', 'round-btn', 'back-link', 'page-title', 'page-intro', 'error-text']

// Every slot, with the props its piece gets. The public list for theme makers.
export const SLOTS = {
  // Everywhere
  'app.top-bar': { kind: 'replace', about: 'Inside the top bar on computers (logo, search, section tabs, the buttons on the right). Its frame and height stay.', props: { tabs: 'the sections: { name, path, icon, label }', isActive: 'tab → is it the page you’re on', user: 'the signed-in member', logo: 'the logo’s address or ""', siteName: 'the site’s name', home: 'where the logo goes' } },
  'app.top-bar-end': { kind: 'add', about: 'At the start of the top bar’s right side, before the community’s own buttons (computers and phones).', props: { isPhone: 'is it the phone layout' } },
  'app.left-menu': { kind: 'replace', about: 'Inside the left menu on computers.', props: { tabs: 'the sections (as above)', tools: 'owner and moderator links: { to, icon, label, badge }', isActive: 'tab → is it the page you’re on', user: 'the signed-in member' } },
  'app.tab-bar': { kind: 'replace', about: 'Inside the tab bar at the bottom on phones. The “me” tab opens the menu: call openMenu().', props: { tabs: 'the sections (as above, plus short)', isActive: 'tab → is it the page you’re on', user: 'the signed-in member', notifications: 'unread alerts', openMenu: 'opens the phone menu', menuOpen: 'is it open' } },
  'app.empty-state': { kind: 'replace', about: 'A page with nothing to show yet (Billing, Classroom, Events, Leaderboard, Messages, the chats panel, Reels). The default holds its one button.', props: { page: '“billing”, “classroom”, “events”, “leaderboard”, “messages”, “chats” or “reels”', icon: 'the icon’s name', title: 'the short title', text: 'the line under it' } },
  'app.level-up': { kind: 'replace', about: 'The celebration when a member reaches a new level. Show it while levelUp is set; call dismiss() when done (the default closes itself after 6 seconds).', props: { levelUp: '{ level, unlocked: [{ id, title }] } or null', dismiss: 'closes it' } },
  'app.lesson-done': { kind: 'replace', about: 'The card after a lesson is marked complete. Emit “next” to go on, “close” to stay.', props: { done: '{ lesson, course, count, total, next: { id, title } or null, opensAt } or null; the points it earned arrive in MBCommunity.member.recent', onNext: '(or emit “next”)', onClose: '(or emit “close”)' } },
  // Home
  'home.before-feed': { kind: 'add', about: 'Above the posts, under the composer and stories.', props: { sort: '“latest” or “hot”' } },
  'home.post-card': { kind: 'replace', about: 'One post in the Home feed.', props: { post: 'the post', pinned: 'is it pinned to the top' } },
  'home.for-you': { kind: 'replace', about: 'For you, beside the feed on computers.', props: { data: '{ event, course, members } or null while loading' } },
  // A course
  'course.front': { kind: 'replace', about: 'A course’s Overview tab. Emit “open” with a lesson id, or “tab” with a tab name.', props: { course: 'the course with modules and lessons', onOpen: '(or emit “open”)', onTab: '(or emit “tab”)' } },
  'course.outline': { kind: 'replace', about: 'Inside the outline beside a lesson (cover, progress, modules and lessons).', props: { course: 'the course with modules and lessons', lessonId: 'the lesson open now', open: 'lesson id → opens it' } },
  'course.lesson-side': { kind: 'add', about: 'Under the outline beside a lesson.', props: { course: 'the course', lesson: 'the lesson open now, or null' } },
  'course.lesson-bar': { kind: 'replace', about: 'Inside the bar at the top of a course (tabs, previous / next, the main button). The owner’s own view keeps the default (Edit this lesson).', props: { course: 'the course', lesson: 'the lesson open now, or null', tab: '“overview”, “lesson” or “posts”', setTab: 'tab name → switches', open: 'lesson id → opens it', markComplete: 'marks the open lesson done (or not done)', continueCourse: 'opens the next lesson to do' } },
  'course.finished': { kind: 'replace', about: 'The celebration when the last lesson is done. Emit “close” when done.', props: { course: 'the course', onClose: '(or emit “close”)' } },
  // Leaderboard
  'leaderboard.podium': { kind: 'replace', about: 'The top three.', props: { podium: '[{ rank, points, user }], 1st to 3rd', me: 'the signed-in member', burst: 'confetti on this visit' } },
  'leaderboard.levels': { kind: 'replace', about: 'The levels card.', props: { levels: '[{ level, name, points, … }]', progress: 'the member’s progress' } },
  'leaderboard.earn-points': { kind: 'replace', about: 'How to earn points.', props: { rules: '[{ reason, label, points }]' } },
  // Profile (the buttons, Block and Report always stay the community’s own)
  'profile.cover': { kind: 'replace', about: 'The cover picture at the top of someone else’s profile. Your own keeps the default (it has the camera).', props: { member: 'the member' } },
  'profile.badges': { kind: 'add', about: 'Under the name and level on a profile.', props: { member: 'the member' } },
  'profile.about': { kind: 'replace', about: 'The About card on someone else’s profile.', props: { member: 'the member' } },
  // Events, Places
  'events.row': { kind: 'replace', about: 'One event in the Events list, for members (owners keep the default: Add replay).', props: { post: 'the event’s post (post.event has the details)', live: 'is it on now' } },
  'events.side-card': { kind: 'replace', about: 'Inside the card beside an event’s page, for members (the host keeps the default). Call rsvp(“going”) to answer.', props: { event: 'the event', state: '“upcoming”, “live”, “past” or “cancelled”', badge: '{ month, day }', post: 'the event’s post', rsvp: 'status → answers' } },
  'places.card': { kind: 'replace', about: 'One place in the Places list.', props: { place: 'the place', category: '{ label, color, emoji }', city: 'its city', open: 'shows it on the map' } },
  // Classroom
  'classroom.continue': {
    kind: 'replace',
    about: 'The “Continue where you left off” card, for a member with a course in progress.',
    props: { course: 'the course (id, title, description, cover, progress 0–100, next_lesson)', to: 'where it opens (a route, for RouterLink)', nextLabel: '“Module 2 · Lesson title”' },
  },
  'classroom.course-list': {
    kind: 'replace',
    about: 'The courses a member sees, a grid by default. The owner’s own view keeps the default (it holds New course and Edit).',
    props: { courses: 'the courses shown, in order', filter: '“all”, “going” or “done”', to: 'course → where it opens (a route)', state: 'course → “new”, “going”, “done” or “locked”', resume: 'the course to continue (as in classroom.continue), or null' },
  },
  'classroom.course-card': {
    kind: 'replace',
    about: 'One course in that list, for members.',
    props: { course: 'the course (as above, plus lessons_count, completed_count, lock, faces, learners, posts_count)', to: 'where it opens (a route)', state: '“new”, “going”, “done” or “locked”' },
  },
}

const replaced = reactive({}) // slot name → { piece, theme }
const added = reactive({}) // slot name → [{ piece, theme, order }]

function accept(kind, name, piece, opts = {}) {
  const theme = String(opts.theme || 'A Community theme')
  const contract = Number(opts.contract || 1)
  if (contract > CONTRACT) {
    reportProblem(new Error(`${theme} is built for theme contract ${contract}; this version of the community knows contract ${CONTRACT}, so its piece for “${name}” is left out. Update the community plugin.`), 'theme', 0, 'slot')
    return false
  }
  if (!SLOTS[name]) console.warn(`[MemBuild Community] “${name}” isn’t a slot this version knows; ${theme}’s piece won’t show.`)
  else if (SLOTS[name].kind !== kind) console.warn(`[MemBuild Community] “${name}” is a ${SLOTS[name].kind} slot; use ${SLOTS[name].kind === 'add' ? 'addToSlot' : 'replaceSlot'}.`)
  return !!piece && SLOTS[name]?.kind === kind
}

export function replaceSlot(name, piece, opts = {}) {
  if (accept('replace', name, piece, opts)) replaced[name] = { piece: markRaw(piece), theme: String(opts.theme || 'A Community theme') }
}

export function addToSlot(name, piece, opts = {}) {
  if (!accept('add', name, piece, opts)) return
  const list = added[name] || (added[name] = [])
  list.push({ piece: markRaw(piece), theme: String(opts.theme || 'A Community theme'), order: Number(opts.order) || 10 })
  list.sort((a, b) => a.order - b.order)
}

export const replacement = (name) => replaced[name] || null
export const additions = (name) => added[name] || []

// A piece that failed is dropped for the rest of the visit, so the default stays put.
export function dropPiece(name, entry, err) {
  reportProblem(err, `theme: ${entry.theme}, slot: ${name}`, 0, 'slot')
  if (replaced[name]?.piece === entry.piece) delete replaced[name]
  const list = added[name]
  const at = list ? list.findIndex((x) => x.piece === entry.piece) : -1
  if (at >= 0) list.splice(at, 1)
}
