<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../../api.js'
import { askConfirm } from '../../lib/confirm.js'
import { showToast } from '../../lib/toast.js'
import { refreshTopics } from '../../lib/topics.js'
import Icon from '../Icon.vue'
import { t } from '../../lib/i18n.js'

/**
 * Settings › Import: bring a community over from BuddyBoss, BuddyPress, PeepSo or
 * FluentCommunity (found on this site, even switched off), or members from a spreadsheet.
 * Choose → Check (a dry run) → Import (in the background, with Pause) → Done (Undo).
 * The work happens on the server; this asks, shows and follows along.
 */
const router = useRouter()
const state = ref(null) // { found, job, vault, pro, admin, plugins }
const error = ref('')
const busy = ref(false)
const screen = ref('start') // start | where | copy | hosted | choose | check | progress | done | csv | csvcheck
const source = ref(null) // the found source being imported
const bring = ref([])
const groupsTo = ref('topics')
const report = ref(null)
const file = ref(null) // { file, name, rows, columns, memberships }
const map = ref([])
const plans = ref({})
const welcome = ref(false)
const platform = ref('mightynetworks')
const dragging = ref(false)
const picker = ref(null)
let poll = null

const n = (v) => Number(v || 0).toLocaleString()
const job = computed(() => state.value?.job || null)

async function load() {
  try {
    state.value = await api.importState()
    const j = state.value.job
    if (j && ['running', 'paused'].includes(j.status)) screen.value = 'progress'
    else if (j && ['done', 'undone'].includes(j.status)) screen.value = 'done'
    else if (!['choose', 'check', 'csv', 'csvcheck', 'copy', 'hosted'].includes(screen.value)) screen.value = state.value.found.length ? 'start' : 'where'
    follow()
  } catch (e) {
    error.value = e.message
  }
}
onMounted(load)
onBeforeUnmount(() => clearInterval(poll))

/** While it runs, ask every few seconds (the asking also moves it along on hosts without cron). */
function follow() {
  clearInterval(poll)
  const running = job.value?.status === 'running' || (job.value?.emailing && !job.value.emailing.finished)
  if (running) poll = setInterval(async () => {
    const was = job.value?.status
    state.value = await api.importState().catch(() => state.value)
    if (was === 'running' && job.value?.status === 'done') {
      screen.value = 'done'
      refreshTopics()
    }
    if (!(job.value?.status === 'running' || (job.value?.emailing && !job.value.emailing.finished))) clearInterval(poll)
  }, 3000)
}

const scrollUp = () => document.getElementById('s-import')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
watch(screen, scrollUp)

/* ---------- Choose ---------- */

const isFluent = computed(() => source.value?.key === 'fluentcommunity')
const groupWord = computed(() => (isFluent.value ? t.__('spaces', 'membuild-community') : t.__('groups', 'membuild-community')))
const options = computed(() => {
  const s = source.value
  if (!s) return []
  return [
    { key: 'profiles', title: t.__('Members’ profiles', 'membuild-community'), count: n(s.members), text: t.__('Photo, cover, bio and location. Everyone keeps their own login.', 'membuild-community') },
    { key: 'posts', title: t.__('Posts and comments', 'membuild-community'), count: t.sprintf(/* translators: 1: posts, 2: comments */ t.__('%1$s posts · %2$s comments', 'membuild-community'), n(s.posts), n(s.comments)), text: t.__('With their photos, dates and authors. Replies to replies join the same comment, as they do here.', 'membuild-community') },
    s.reactions ? { key: 'reactions', title: t.__('Reactions', 'membuild-community'), count: n(s.reactions), text: isFluent.value ? t.__('Kept as they are when they match; others become Like.', 'membuild-community') : t.__('Love, haha, wow, sad and angry stay as they are; others become Like.', 'membuild-community') } : null,
    s.courses ? { key: 'courses', title: t.__('Courses', 'membuild-community'), count: t.sprintf(/* translators: 1: courses, 2: lessons */ t._n('%1$s course · %2$s lessons', '%1$s courses · %2$s lessons', s.courses, 'membuild-community'), n(s.courses), n(s.lessons)), text: t.__('Into the Classroom, with their sections, lessons and videos, and each member’s progress. Private courses become a membership with the same people.', 'membuild-community') } : null,
    s.messages ? { key: 'messages', title: t.__('Messages', 'membuild-community'), count: t.sprintf(/* translators: %s: number of chats */ t.__('%s chats', 'membuild-community'), n(s.messages)), text: t.__('Private and group chats, encrypted here like all messages.', 'membuild-community') } : null,
    { key: 'points', title: t.__('Points', 'membuild-community'), count: t.__('worked out from the above', 'membuild-community'), text: t.__('So long-time members keep their place instead of starting at Level 1.', 'membuild-community') },
  ].filter(Boolean)
})

function choose(s) {
  source.value = s
  bring.value = ['profiles', 'posts', 'reactions', 'courses', 'messages', 'points'].filter((k) => k === 'profiles' || k === 'posts' || k === 'points' || s[k])
  groupsTo.value = 'topics'
  report.value = null
  screen.value = 'choose'
}
const toggle = (key) => (bring.value = bring.value.includes(key) ? bring.value.filter((k) => k !== key) : [...bring.value, key])

async function check() {
  busy.value = true
  error.value = ''
  try {
    report.value = await api.importCheck({ source: source.value.key, bring: bring.value, groups: groupsTo.value })
    screen.value = 'check'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

async function start() {
  busy.value = true
  error.value = ''
  try {
    const body = source.value.key === 'csv' ? csvBody() : { source: source.value.key, bring: bring.value, groups: groupsTo.value }
    state.value.job = await api.importStart(body)
    screen.value = 'progress'
    follow()
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}

/* ---------- Importing ---------- */

const STEP_LABELS = {
  members: () => t.__('Members’ profiles', 'membuild-community'),
  topics: () => t.__('Groups into topics', 'membuild-community'),
  posts: () => t.__('Posts', 'membuild-community'),
  comments: () => t.__('Comments', 'membuild-community'),
  reactions: () => t.__('Reactions', 'membuild-community'),
  courses: () => t.__('Courses', 'membuild-community'),
  messages: () => t.__('Messages', 'membuild-community'),
  points: () => t.__('Points', 'membuild-community'),
}
const TOTAL_KEY = { members: 'members', posts: 'posts', comments: 'comments', reactions: 'reactions', courses: 'courses', messages: 'messages', topics: 'groups' }
const rows = computed(() => {
  const j = job.value
  if (!j) return []
  const steps = j.steps.filter((s) => s !== 'finish')
  const at = steps.indexOf(j.step)
  return steps.map((s, i) => {
    const total = j.total?.[TOTAL_KEY[s]] || 0
    const doneStep = j.status === 'done' || (at === -1 ? j.step === 'finish' : i < at)
    const count = j.counts?.[s] || 0
    const pct = doneStep ? 100 : i === at && total ? Math.min(98, Math.round((count / total) * 100)) : 0
    let label = t.__('Waiting', 'membuild-community')
    if (doneStep) label = total ? '✓ ' + t.sprintf(/* translators: 1: done, 2: total */ t.__('%1$s of %2$s', 'membuild-community'), n(count), n(total)) : '✓'
    else if (i === at) label = total ? t.sprintf(t.__('%1$s of %2$s', 'membuild-community'), n(count), n(total)) : t.__('Working…', 'membuild-community')
    return { key: s, label: STEP_LABELS[s]?.() || s, pct, text: label, done: doneStep }
  })
})
const overall = computed(() => (rows.value.length ? Math.round(rows.value.reduce((a, r) => a + r.pct, 0) / rows.value.length) : 0))
const clock = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '')
const minutesLeft = computed(() => {
  const j = job.value
  if (!j?.started || overall.value < 3) return null
  const spent = (Date.now() - new Date(j.started).getTime()) / 60000
  return Math.max(1, Math.round((spent / overall.value) * (100 - overall.value)))
})
const progressTitle = computed(() => {
  if (job.value?.status === 'paused') return t.__('Paused', 'membuild-community')
  return minutesLeft.value
    ? t.sprintf(/* translators: %d: minutes */ t._n('Importing… about %d minute left', 'Importing… about %d minutes left', minutesLeft.value, 'membuild-community'), minutesLeft.value)
    : t.__('Importing…', 'membuild-community')
})

async function pause() {
  state.value.job = job.value.status === 'running' ? await api.importPause() : await api.importResume()
  follow()
}

/* ---------- Done ---------- */

const doneTiles = computed(() => {
  const c = job.value?.counts || {}
  return [
    { v: c.members, l: t.__('members', 'membuild-community') },
    { v: c.posts, l: t.__('posts', 'membuild-community') },
    { v: c.comments, l: t.__('comments', 'membuild-community') },
    { v: c.reactions, l: t.__('reactions', 'membuild-community') },
    { v: c.courses, l: t.__('courses', 'membuild-community') },
    { v: c.messages, l: t.__('chats', 'membuild-community') },
  ].filter((x) => x.v)
})
const took = computed(() => {
  const j = job.value
  if (!j?.started || !j?.finished) return ''
  const mins = Math.max(1, Math.round((new Date(j.finished) - new Date(j.started)) / 60000))
  return t.sprintf(/* translators: 1: a time, 2: minutes */ t._n('Finished %1$s, in %2$d minute.', 'Finished %1$s, in %2$d minutes.', mins, 'membuild-community'), clock(j.finished), mins)
})

async function emailEveryone() {
  const total = (job.value.counts?.members || 0) + (job.value.counts?.updated || 0)
  const ok = await askConfirm({
    title: t.__('Email everyone?', 'membuild-community'),
    text: t.sprintf(/* translators: %s: number of members */ t.__('One email from you to %s members, with a link to log in. It goes out slowly, about 200 an hour, so it isn’t marked as spam.', 'membuild-community'), n(total || job.value.total?.members)),
    confirm: t.__('Email everyone', 'membuild-community'),
  })
  if (!ok) return
  state.value.job = await api.importEmail()
  follow()
}

async function undo() {
  const ok = await askConfirm({
    title: t.__('Undo this import?', 'membuild-community'),
    text: t.__('This removes exactly what it brought: posts, comments, reactions, chats, topics and memberships it made. Members and their profiles stay.', 'membuild-community'),
    confirm: t.__('Undo the import', 'membuild-community'),
    danger: true,
  })
  if (!ok) return
  busy.value = true
  try {
    state.value.job = await api.importUndo()
    refreshTopics()
    showToast(t.__('The import was undone.', 'membuild-community'))
  } catch (e) {
    showToast(e.message)
  } finally {
    busy.value = false
  }
}

/* ---------- Past imports ---------- */

const day = (iso, time = false) => (iso ? new Date(iso).toLocaleString([], time ? { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' } : { day: 'numeric', month: 'short' }) : '')
const pastTitle = (p) => (p.source === 'csv' ? t.sprintf(/* translators: %s: file name */ t.__('Spreadsheet: %s', 'membuild-community'), p.name || 'CSV') : p.label) + ' · ' + day(p.finished, true)
function pastLine(p) {
  const c = p.counts || {}
  if (p.status === 'undone') return t.__('Nothing from it is left.', 'membuild-community')
  const parts =
    p.source === 'csv'
      ? [c.members && t.sprintf(t._n('%s new member', '%s new members', c.members, 'membuild-community'), n(c.members)), c.updated && t.sprintf(/* translators: %s: number */ t.__('%s updated', 'membuild-community'), n(c.updated))]
      : [
          c.members && t.sprintf(t._n('%s member', '%s members', c.members, 'membuild-community'), n(c.members)),
          c.posts && t.sprintf(t._n('%s post', '%s posts', c.posts, 'membuild-community'), n(c.posts)),
          c.comments && t.sprintf(t._n('%s comment', '%s comments', c.comments, 'membuild-community'), n(c.comments)),
          c.reactions && t.sprintf(t._n('%s reaction', '%s reactions', c.reactions, 'membuild-community'), n(c.reactions)),
          c.messages && t.sprintf(t._n('%s chat', '%s chats', c.messages, 'membuild-community'), n(c.messages)),
          c.courses && t.sprintf(t._n('%s course', '%s courses', c.courses, 'membuild-community'), n(c.courses)),
          c.topics && t.sprintf(t._n('%s topic', '%s topics', c.topics, 'membuild-community'), n(c.topics)),
        ]
  return parts.filter(Boolean).join(' · ') || t.__('Everything was already here.', 'membuild-community')
}
const undoingId = ref(0)
async function undoPast(p) {
  undoingId.value = p.id
  try {
    const { brought: b, since: s } = await api.importImpact(p.id)
    const what = [
      b.posts && t.sprintf(t._n('%s post', '%s posts', b.posts, 'membuild-community'), n(b.posts)),
      b.comments && t.sprintf(t._n('%s comment', '%s comments', b.comments, 'membuild-community'), n(b.comments)),
      b.reactions && t.sprintf(t._n('%s reaction', '%s reactions', b.reactions, 'membuild-community'), n(b.reactions)),
      b.chats && t.sprintf(t._n('%s chat', '%s chats', b.chats, 'membuild-community'), n(b.chats)),
      b.courses && t.sprintf(t._n('%s course', '%s courses', b.courses, 'membuild-community'), n(b.courses)),
      b.topics && t.sprintf(t._n('%s topic', '%s topics', b.topics, 'membuild-community'), n(b.topics)),
      b.memberships && t.sprintf(t._n('%s membership', '%s memberships', b.memberships, 'membuild-community'), n(b.memberships)),
    ].filter(Boolean)
    const later = [
      s.comments && t.sprintf(t._n('%s comment', '%s comments', s.comments, 'membuild-community'), n(s.comments)),
      s.reactions && t.sprintf(t._n('%s reaction', '%s reactions', s.reactions, 'membuild-community'), n(s.reactions)),
      s.messages && t.sprintf(t._n('%s message', '%s messages', s.messages, 'membuild-community'), n(s.messages)),
    ].filter(Boolean)
    const ok = await askConfirm({
      title: t.sprintf(/* translators: 1: where from, 2: a date */ t.__('Undo the %1$s import from %2$s?', 'membuild-community'), p.source === 'csv' ? t.__('spreadsheet', 'membuild-community') : p.label, day(p.finished)),
      text: (what.length ? t.sprintf(/* translators: %s: a list, e.g. "320 posts, 1,140 comments" */ t.__('This removes what it brought: %s.', 'membuild-community'), what.join(', ')) : t.__('This removes what it brought.', 'membuild-community')) + ' ' + (p.source === 'csv' ? t.__('The accounts it made stay, so nobody is locked out.', 'membuild-community') : t.__('Members and their profiles stay.', 'membuild-community')),
      note: later.length ? t.sprintf(/* translators: %s: e.g. "46 comments and 210 reactions" */ t.__('Since then, members added %s to what it brought. They go with it.', 'membuild-community'), later.join(', ')) : '',
      confirm: t.__('Undo the import', 'membuild-community'),
      danger: true,
    })
    if (!ok) return
    const r = await api.importUndo(p.id)
    state.value = await api.importState()
    refreshTopics()
    showToast(t.__('The import was undone.', 'membuild-community'))
    void r
  } catch (e) {
    showToast(e.message)
  } finally {
    undoingId.value = 0
  }
}

/* ---------- Pro, coming soon ---------- */

const updates = ref(null) // Keep me updated: { subscribed, email, admin_email, allowed }
const proBusy = ref(false)
onMounted(() => api.usage().then((s) => (updates.value = s)).catch(() => {}))
async function tellMe() {
  proBusy.value = true
  try {
    const email = updates.value.admin_email
    await api.subscribeUpdates({ email })
    updates.value = { ...updates.value, subscribed: true, email }
  } catch (e) {
    showToast(e.message)
  } finally {
    proBusy.value = false
  }
}

async function again() {
  state.value = await api.importDismiss()
  source.value = null
  screen.value = state.value.found.length ? 'start' : 'where'
}

/* ---------- Spreadsheet ---------- */

const FIELD_LABELS = computed(() => ({
  email: t.__('Email (needed)', 'membuild-community'),
  first_name: t.__('First name', 'membuild-community'),
  last_name: t.__('Last name', 'membuild-community'),
  name: t.__('Full name', 'membuild-community'),
  joined: t.__('Joined date', 'membuild-community'),
  membership: t.__('Membership', 'membuild-community'),
  bio: t.__('Bio', 'membuild-community'),
  location: t.__('Location', 'membuild-community'),
  photo: t.__('Photo (a web address)', 'membuild-community'),
  skip: t.__('Don’t bring', 'membuild-community'),
}))
const allGuessed = computed(() => file.value?.columns.every((c, i) => map.value[i] === c.guess) && map.value.includes('email'))

function pick() {
  picker.value?.click()
}
async function upload(f) {
  if (!f) return
  busy.value = true
  error.value = ''
  try {
    file.value = await api.importFile(f)
    map.value = file.value.columns.map((c) => c.guess)
    plans.value = {}
    welcome.value = false
    source.value = { key: 'csv', label: f.name }
    screen.value = 'csv'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
    if (picker.value) picker.value.value = ''
  }
}
const onDrop = (e) => {
  dragging.value = false
  upload(e.dataTransfer?.files?.[0])
}

function setField(i, field) {
  // One column per field: choosing it here takes it from another column.
  map.value = map.value.map((f, j) => (j === i ? field : f === field && field !== 'skip' ? 'skip' : f))
}
const csvBody = () => ({ source: 'csv', file: file.value.file, name: file.value.name, map: map.value, plans: plans.value, welcome: welcome.value })

async function csvCheck() {
  if (!map.value.includes('email')) {
    error.value = t.__('Choose which column has the email addresses.', 'membuild-community')
    return
  }
  busy.value = true
  error.value = ''
  try {
    report.value = await api.importCheck(csvBody())
    for (const p of report.value.plans || []) if (!(p.value in plans.value)) plans.value[p.value] = p.group_id || 0
    screen.value = 'csvcheck'
  } catch (e) {
    error.value = e.message
  } finally {
    busy.value = false
  }
}
async function recheck() {
  report.value = await api.importCheck(csvBody()).catch(() => report.value)
}

/** The rows left out, as a CSV to fix and add later. */
function downloadBad() {
  const cells = (r) => r.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')
  const head = file.value.columns.map((c) => c.name)
  const body = [cells(head), ...report.value.invalid.map((r) => cells(r.values))].join('\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([body], { type: 'text/csv' }))
  a.download = 'rows-to-fix.csv'
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}
function downloadSample() {
  const body = 'email,first_name,last_name,joined,plan\nana@example.com,Ana,Ruiz,2024-03-02,Gold\n'
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([body], { type: 'text/csv' }))
  a.download = 'members-sample.csv'
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

/* ---------- Other platforms ---------- */

const PLATFORMS = computed(() => ({
  kajabi: {
    name: 'Kajabi',
    steps: [t.__('In Kajabi, go to <b>People</b>.', 'membuild-community'), t.__('Tick everyone, then choose <b>Bulk actions › Export</b>.', 'membuild-community'), t.__('Kajabi emails you a CSV file. Save it.', 'membuild-community')],
    comes: t.__('Names, emails and when they joined. Their offers can match a membership here.', 'membuild-community'),
    stays: t.__('Posts, comments and courses: Kajabi doesn’t let you download them.', 'membuild-community'),
  },
  mightynetworks: {
    name: 'Mighty Networks',
    steps: [t.__('In Mighty Networks, go to <b>Admin › Members › Member List</b>.', 'membuild-community'), t.__('Click <b>Download</b> and agree to their terms.', 'membuild-community'), t.__('Open the email they send you and save the file (an Excel .xlsx file is fine).', 'membuild-community')],
    comes: t.__('Names, emails, bio, location, photo, when they joined, and their plan (to match a membership here).', 'membuild-community'),
    stays: t.__('Posts, comments and courses: Mighty Networks doesn’t let you download them.', 'membuild-community'),
  },
  circle: {
    name: 'Circle',
    steps: [t.__('In Circle, open <b>Manage audience</b>.', 'membuild-community'), t.__('On the <b>Members</b> tab, click <b>Export CSV</b>.', 'membuild-community'), t.__('Save the file Circle gives you.', 'membuild-community')],
    comes: t.__('Names, emails and when they joined.', 'membuild-community'),
    stays: t.__('Posts and comments: Circle only gives those to you through their support team, after you cancel.', 'membuild-community'),
  },
  skool: {
    name: 'Skool',
    steps: [t.__('In your Skool group, open <b>Members</b>.', 'membuild-community'), t.__('Use the <b>Export</b> option there to download your members as a CSV.', 'membuild-community'), t.__('Save the file.', 'membuild-community')],
    comes: t.__('Names, emails and when they joined. Members who joined through Skool’s Discovery may have no email in the file; they’re left out, and the check shows how many.', 'membuild-community'),
    stays: t.__('Posts, comments, the leaderboard and courses: Skool doesn’t let you download them.', 'membuild-community'),
  },
  facebook: {
    name: t.__('Facebook group', 'membuild-community'),
    steps: [t.__('Facebook doesn’t share members’ emails with group admins, so there’s no file to bring.', 'membuild-community'), t.__('Instead, post in your group with a link to join here, and pin it.', 'membuild-community')],
    comes: '',
    stays: t.__('Members, posts and comments: Facebook doesn’t let you download them.', 'membuild-community'),
  },
}))
const plat = computed(() => PLATFORMS.value[platform.value])

const TAGS = {
  ready: ['ok', () => t.__('Ready', 'membuild-community')],
  merged: ['', () => t.__('Merged', 'membuild-community')],
  updated: ['', () => t.__('Updated', 'membuild-community')],
  brought: ['', () => t.__('Brought', 'membuild-community')],
  linked: ['warn', () => t.__('Linked', 'membuild-community')],
  left_out: ['warn', () => t.__('Left out', 'membuild-community')],
  skipped: ['bad', () => t.__('Skipped', 'membuild-community')],
  memberships: ['accent', () => t.__('Memberships', 'membuild-community')],
}
const tagClass = (k) => TAGS[k]?.[0] || ''
const tagText = (k) => TAGS[k]?.[1]() || ''

const ago = (iso) => {
  if (!iso) return ''
  const days = Math.round((Date.now() - new Date(iso).getTime()) / 86400000)
  return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(-days, 'day')
}
const foundLine = (s) =>
  s.active
    ? t.sprintf(/* translators: %s: "3 days ago" */ t.__('Switched on. Last activity %s.', 'membuild-community'), ago(s.last))
    : t.sprintf(/* translators: %s: "3 days ago" */ t.__('Switched off, but its data is still here. Last activity %s.', 'membuild-community'), ago(s.last))
const foundTiles = (s) =>
  [
    { v: s.members, l: t.__('members', 'membuild-community') },
    { v: s.posts, l: t.__('posts', 'membuild-community') },
    { v: s.comments, l: t.__('comments', 'membuild-community') },
    { v: s.messages, l: t.__('chats', 'membuild-community') },
    { v: s.groups, l: s.key === 'fluentcommunity' ? t.__('spaces', 'membuild-community') : t.__('groups', 'membuild-community') },
    { v: s.courses, l: t.__('courses', 'membuild-community') },
  ].filter((x) => x.v)
const stepNo = computed(() => ({ choose: 1, check: 2, progress: 3, done: 4 })[screen.value] || 0)
</script>

<template>
  <section id="s-import" class="card group im">
    <input ref="picker" type="file" accept=".csv,.xlsx,.txt,text/csv" hidden @change="upload($event.target.files[0])" />

    <p v-if="!state && error" class="error-text">{{ error }}</p>
    <p v-else-if="!state" class="im-loading">{{ t.__('Loading…', 'membuild-community') }}</p>

    <div v-else class="im-body">
      <!-- The four steps, once something is chosen. -->
      <ol v-if="stepNo" class="im-steps" :aria-label="t.__('Steps', 'membuild-community')">
        <li v-for="(s, i) in [t.__('Choose', 'membuild-community'), t.__('Check', 'membuild-community'), t.__('Import', 'membuild-community'), t.__('Done', 'membuild-community')]" :key="s" :class="{ done: i + 1 < stepNo || (i === 3 && job?.status === 'done'), on: i + 1 === stepNo }" :aria-current="i + 1 === stepNo ? 'step' : undefined">
          <span class="im-step-no"><Icon v-if="i + 1 < stepNo" name="check" :size="14" /><template v-else>{{ i + 1 }}</template></span>{{ s }}
        </li>
      </ol>

      <!-- Start: what's on this site, and a spreadsheet. -->
      <template v-if="screen === 'start'">
        <h2 class="im-top">{{ t.__('Import', 'membuild-community') }}</h2>
        <p class="intro">{{ t.__('Bring members and their history from another community plugin, or add members from a spreadsheet. Nothing in the old plugin is changed or deleted.', 'membuild-community') }}</p>
        <div v-for="s in state.found" :key="s.key" class="im-box">
          <div class="im-found">
            <span class="im-ic accent"><Icon name="box" :size="20" /></span>
            <span class="im-words"><strong>{{ t.sprintf(/* translators: %s: plugin name */ t.__('%s, found on this site', 'membuild-community'), s.label) }}</strong><small>{{ foundLine(s) }}</small></span>
            <button type="button" class="btn btn-lg btn-primary" @click="choose(s)">{{ t.__('Import', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
          </div>
          <div class="im-tiles">
            <span v-for="x in foundTiles(s)" :key="x.l" class="im-tile"><strong>{{ n(x.v) }}</strong>{{ x.l }}</span>
          </div>
        </div>
        <div class="im-box im-found">
          <span class="im-ic"><Icon name="file" :size="20" /></span>
          <span class="im-words"><strong>{{ t.__('A list of members (spreadsheet)', 'membuild-community') }}</strong><small>{{ t.__('From Kajabi, Skool, Circle, Mighty Networks or anywhere that exports a CSV: names and emails, and anything else you have.', 'membuild-community') }}</small></span>
          <button type="button" class="btn btn-lg" :disabled="busy" @click="screen = 'hosted'">{{ t.__('Choose a file', 'membuild-community') }}</button>
        </div>
        <div class="im-box im-note">
          <strong>{{ t.__('Is this your live site?', 'membuild-community') }}</strong>
          <p>
            {{ t.__('Try it on a copy of your site first (staging), then run it here.', 'membuild-community') }}
            <button type="button" class="im-link" @click="screen = 'copy'">{{ t.__('How to make a copy', 'membuild-community') }}</button>.
            {{ t.__('BuddyBoss, BuddyPress, PeepSo and FluentCommunity show up here the same way when they’re on this site.', 'membuild-community') }}
          </p>
        </div>
      </template>

      <!-- Where is your community now? (nothing found on this site) -->
      <template v-else-if="screen === 'where'">
        <h2 class="im-top">{{ t.__('Import', 'membuild-community') }}</h2>
        <p class="intro">{{ t.__('Where is your community now? Pick one and we’ll show you exactly what to do.', 'membuild-community') }}</p>
        <button type="button" class="im-box im-choice" @click="screen = 'copy'">
          <span class="im-ic"><Icon name="box" :size="20" /></span>
          <span class="im-words"><strong>{{ t.__('A WordPress community plugin', 'membuild-community') }}</strong><small>{{ t.__('Members, posts, comments, reactions, messages, groups or spaces, and courses from FluentCommunity.', 'membuild-community') }}</small><small class="im-names">BuddyBoss · BuddyPress · PeepSo · FluentCommunity</small></span>
          <Icon name="arrowRight" :size="18" />
        </button>
        <button type="button" class="im-box im-choice" @click="screen = 'hosted'">
          <span class="im-ic"><Icon name="globe" :size="20" /></span>
          <span class="im-words"><strong>{{ t.__('Another platform', 'membuild-community') }}</strong><small>{{ t.__('Your members, from the file the platform lets you download. Their posts can’t come along: these platforms don’t export them.', 'membuild-community') }}</small><small class="im-names">Kajabi · Mighty Networks · Circle · Skool</small></span>
          <Icon name="arrowRight" :size="18" />
        </button>
        <button type="button" class="im-box im-choice" :disabled="busy" @click="pick">
          <span class="im-ic"><Icon name="file" :size="20" /></span>
          <span class="im-words"><strong>{{ t.__('Just a list of people', 'membuild-community') }}</strong><small>{{ t.__('A spreadsheet with names and emails.', 'membuild-community') }} <span class="im-link" role="link" tabindex="0" @click.stop="downloadSample" @keydown.enter.stop="downloadSample">{{ t.__('Download our sample', 'membuild-community') }}</span> {{ t.__('if you’re starting from scratch.', 'membuild-community') }}</small><small class="im-names">{{ t.__('Any spreadsheet saved as CSV', 'membuild-community') }}</small></span>
          <Icon name="arrowRight" :size="18" />
        </button>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
      </template>

      <!-- Try it on a copy first. -->
      <template v-else-if="screen === 'copy'">
        <h2>{{ t.__('Try it on a copy of your site first', 'membuild-community') }}</h2>
        <p class="intro">{{ t.__('A few minutes of setup that make the import worry-free. Here’s the safe way, step by step.', 'membuild-community') }}</p>
        <ol class="im-box im-guide">
          <li><span class="im-num">1</span><span><strong>{{ t.__('Back up your site', 'membuild-community') }}</strong><small>{{ t.__('Your host’s backup, or a backup plugin. Just in case.', 'membuild-community') }}</small></span></li>
          <li><span class="im-num">2</span><span><strong>{{ t.__('Make a copy of your site (staging)', 'membuild-community') }}</strong><small>{{ t.__('Most hosts do it in one click: look for “Staging” in your hosting dashboard (SiteGround, Kinsta, WP Engine, Cloudways, Hostinger, RunCloud and others). No staging? The free plugin WP Staging makes one.', 'membuild-community') }}</small></span></li>
          <li><span class="im-num">3</span><span><strong>{{ t.__('On the copy: install MemBuild Community, then Import', 'membuild-community') }}</strong><small>{{ t.__('Look around: posts, profiles, chats, courses. Nothing on your real site changes.', 'membuild-community') }}</small></span></li>
          <li><span class="im-num">4</span><span><strong>{{ t.__('Happy? Run Import on your live site', 'membuild-community') }}</strong><small>{{ t.__('It takes minutes and brings everything up to today. Don’t copy the staging site over the live one: posts written on the live site while you were testing would be lost.', 'membuild-community') }}</small></span></li>
        </ol>
        <p v-if="!state.found.length" class="im-hint">{{ t.__('No community plugin’s data is on this site yet. BuddyBoss, BuddyPress, PeepSo and FluentCommunity show up here when their data is, even switched off.', 'membuild-community') }}</p>
        <div class="im-act">
          <button type="button" class="btn btn-lg" @click="screen = state.found.length ? 'start' : 'where'">{{ t.__('Back', 'membuild-community') }}</button>
          <button v-if="state.found.length" type="button" class="btn btn-lg btn-primary" @click="screen = 'start'">{{ t.__('I’m on the copy: continue', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
        </div>
      </template>

      <!-- Another platform: where its members download is. -->
      <template v-else-if="screen === 'hosted'">
        <h2>{{ t.__('Bring your members from another platform', 'membuild-community') }}</h2>
        <p class="intro">{{ t.__('Pick where they are now. Each platform hides its download in a different place, so here are its steps.', 'membuild-community') }}</p>
        <div class="im-box">
          <div class="filter-pills" role="tablist">
            <button v-for="(p, key) in PLATFORMS" :key="key" type="button" role="tab" class="filter-pill" :class="{ on: platform === key }" :aria-selected="platform === key" @click="platform = key">{{ p.name }}</button>
          </div>
          <h3>{{ platform === 'facebook' ? t.__('A Facebook group', 'membuild-community') : t.sprintf(/* translators: %s: platform name */ t.__('Download your members from %s', 'membuild-community'), plat.name) }}</h3>
          <!-- eslint-disable-next-line vue/no-v-html -- our own translated strings with <b> only -->
          <ol class="im-howto"><li v-for="(s, i) in plat.steps" :key="i" v-html="s" /></ol>
          <div class="im-two">
            <div v-if="plat.comes" class="im-comes"><strong>{{ t.__('What comes along', 'membuild-community') }}</strong>{{ plat.comes }}</div>
            <div class="im-stays"><strong>{{ t.__('What stays behind', 'membuild-community') }}</strong>{{ plat.stays }}</div>
          </div>
        </div>
        <div v-if="platform !== 'facebook'" class="im-box im-drop" :class="{ over: dragging }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop">
          <Icon name="file" :size="20" /> <strong>{{ t.__('Drop the file here', 'membuild-community') }}</strong> {{ t.__('or', 'membuild-community') }}
          <button type="button" class="btn btn-lg" :disabled="busy" @click="pick">{{ busy ? t.__('Reading…', 'membuild-community') : t.__('Choose a file', 'membuild-community') }}</button>
        </div>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        <div class="im-act">
          <button type="button" class="btn btn-lg" @click="screen = state.found.length ? 'start' : 'where'">{{ t.__('Back', 'membuild-community') }}</button>
        </div>
      </template>

      <!-- 1. Choose what to bring. -->
      <template v-else-if="screen === 'choose'">
        <h2>{{ t.sprintf(/* translators: %s: plugin name */ t.__('Import from %s', 'membuild-community'), source.label) }}</h2>
        <p class="intro">{{ t.__('Tick what to bring. Nothing happens until you check it on the next step.', 'membuild-community') }}</p>
        <div class="im-box">
          <h3>{{ t.sprintf(t.__('What to bring from %s', 'membuild-community'), source.label) }}</h3>
          <label v-for="o in options" :key="o.key" class="im-tick">
            <input type="checkbox" :checked="bring.includes(o.key)" @change="toggle(o.key)" />
            <span class="im-box-mark"><Icon name="check" :size="14" /></span>
            <span class="im-words"><strong>{{ o.title }} <span class="im-count">· {{ o.count }}</span></strong><small>{{ o.text }}</small></span>
          </label>
        </div>
        <div v-if="source.groups && bring.includes('posts')" class="im-box">
          <h3>{{ t.sprintf(/* translators: 1: number, 2: "groups" or "spaces" */ t.__('The %1$s %2$s become topics', 'membuild-community'), n(source.groups), groupWord) }}</h3>
          <p class="im-p">{{ t.__('Each one becomes a topic of the same name, and its posts come over in it, so members can filter Home by it. Private ones become members-only topics: a membership is made with the same people, and only they see those posts.', 'membuild-community') }}</p>
          <div v-if="state.pro" class="choices">
            <label class="choice" :class="{ on: groupsTo === 'topics' }"><input v-model="groupsTo" type="radio" value="topics" /><span class="mark" /><strong>{{ t.__('Topics', 'membuild-community') }}</strong></label>
            <label class="choice" :class="{ on: groupsTo === 'spaces' }"><input v-model="groupsTo" type="radio" value="spaces" /><span class="mark" /><strong>{{ t.__('Spaces (Pro)', 'membuild-community') }}</strong><small>{{ t.__('Each with its own members, feed and settings.', 'membuild-community') }}</small></label>
          </div>
          <!-- Pro, while it's coming: one button that signs the owner up to hear when it launches. -->
          <div v-else class="im-pro">
            <span class="im-pro-ic"><Icon name="star" :size="22" /></span>
            <div class="im-pro-body">
              <div class="im-pro-head">
                <strong>{{ t.sprintf(/* translators: "group" or "space" */ t.__('Want each %s as its own space?', 'membuild-community'), isFluent ? t.__('space', 'membuild-community') : t.__('group', 'membuild-community')) }}</strong>
                <span class="im-pro-badge">{{ t.__('PRO', 'membuild-community') }}</span>
                <span class="status-tag">{{ t.__('Coming soon', 'membuild-community') }}</span>
              </div>
              <ul class="im-pro-list">
                <li><Icon name="check" :size="13" /><span><strong>{{ t.__('Each group becomes a space', 'membuild-community') }}</strong>{{ t.__(', with its own members, feed and settings', 'membuild-community') }}</span></li>
                <li><Icon name="check" :size="13" /><span><strong>{{ t.__('Private stays private', 'membuild-community') }}</strong>{{ t.__(': only a space’s members see it', 'membuild-community') }}</span></li>
                <li><Icon name="check" :size="13" /><span><strong>{{ t.__('Members choose', 'membuild-community') }}</strong>{{ t.__(' which spaces show in their Home', 'membuild-community') }}</span></li>
              </ul>
              <p class="im-pro-more">{{ t.__('And so much more…', 'membuild-community') }}</p>
              <div v-if="updates?.subscribed" class="im-pro-done" role="status">
                <span class="im-pro-ok"><Icon name="check" :size="16" /></span>
                <span><strong>{{ t.__('You’ll hear when Pro is ready.', 'membuild-community') }}</strong> {{ t.sprintf(/* translators: %s: an email address */ t.__('Emails go to %s. Stop any time in Updates & usage.', 'membuild-community'), updates.email) }}</span>
              </div>
              <div v-else class="im-pro-act">
                <button type="button" class="btn btn-lg im-pro-btn" :disabled="proBusy || !updates?.allowed" @click="tellMe">{{ proBusy ? t.__('Signing you up…', 'membuild-community') : t.__('Tell me when it’s ready', 'membuild-community') }}</button>
                <small>{{ t.__('MemBuild Community Pro is a paid add-on. This uses Keep me updated: an email when Pro and other new things ship.', 'membuild-community') }}</small>
              </div>
            </div>
          </div>
        </div>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        <div class="im-act">
          <button type="button" class="btn btn-lg" @click="screen = 'start'">{{ t.__('Back', 'membuild-community') }}</button>
          <button type="button" class="btn btn-lg btn-primary" :disabled="busy || !bring.length" @click="check">{{ busy ? t.__('Checking…', 'membuild-community') : t.__('Check first', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
        </div>
      </template>

      <!-- 2. The dry run. -->
      <template v-else-if="screen === 'check'">
        <h2>{{ t.__('Before anything moves', 'membuild-community') }}</h2>
        <p class="intro">{{ t.sprintf(/* translators: %d: seconds */ t._n('This was a dry run: nothing has changed yet. It took %d second.', 'This was a dry run: nothing has changed yet. It took %d seconds.', report.seconds, 'membuild-community'), report.seconds) }}</p>
        <div class="im-box">
          <h3>{{ t.__('What will happen', 'membuild-community') }}</h3>
          <div v-for="(it, i) in report.items" :key="i" class="im-line">
            <span class="im-words"><strong>{{ it.title }}</strong><small v-if="it.sub">{{ it.sub }}</small></span>
            <span class="status-tag" :class="tagClass(it.tag)">{{ tagText(it.tag) }}</span>
          </div>
        </div>
        <div v-if="report.notes.length" class="im-box">
          <h3><Icon name="alert" :size="18" /> {{ t.__('Worth knowing', 'membuild-community') }}</h3>
          <div v-for="(it, i) in report.notes" :key="i" class="im-line">
            <span class="im-words"><strong>{{ it.title }}</strong><small v-if="it.sub">{{ it.sub }}</small></span>
            <span class="status-tag" :class="tagClass(it.tag)">{{ tagText(it.tag) }}</span>
          </div>
        </div>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        <div class="im-act">
          <button type="button" class="btn btn-lg" @click="screen = 'choose'">{{ t.__('Back', 'membuild-community') }}</button>
          <button type="button" class="btn btn-lg btn-primary" :disabled="busy" @click="start">{{ t.__('Start importing', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
        </div>
      </template>

      <!-- 3. Importing. -->
      <template v-else-if="screen === 'progress' && job">
        <h2>{{ job.source === 'csv' ? t.__('Adding members', 'membuild-community') : t.sprintf(/* translators: %s: plugin name */ t.__('Importing from %s', 'membuild-community'), job.label) }}</h2>
        <p class="intro">{{ job.source === 'csv' ? t.__('Members can use the community while it runs.', 'membuild-community') : t.__('Please keep the old plugin’s data where it is until this is done.', 'membuild-community') }}</p>
        <div class="im-box">
          <div class="im-run">
            <span class="im-words">
              <strong class="im-run-title">{{ progressTitle }}</strong>
              <small v-if="job.error" class="error-text">{{ t.sprintf(/* translators: %s: the problem */ t.__('It stopped: %s Press Resume to try again.', 'membuild-community'), job.error) }}</small>
              <small v-else>{{ t.sprintf(/* translators: 1: a time, 2: an email address */ t.__('Started %1$s. You can close this page; we’ll email you at %2$s when it’s done.', 'membuild-community'), clock(job.started), state.admin) }}</small>
            </span>
            <button type="button" class="btn btn-lg" @click="pause"><Icon :name="job.status === 'running' ? 'pause' : 'play'" :size="16" /> {{ job.status === 'running' ? t.__('Pause', 'membuild-community') : t.__('Resume', 'membuild-community') }}</button>
          </div>
          <div class="im-bar big" role="progressbar" :aria-valuenow="overall" aria-valuemin="0" aria-valuemax="100" :aria-label="t.__('Overall', 'membuild-community')"><i :style="{ width: overall + '%' }" /></div>
          <div v-for="r in rows" :key="r.key" class="im-row">
            <strong>{{ r.label }}</strong>
            <span class="im-bar" :class="{ done: r.done }"><i :style="{ width: r.pct + '%' }" /></span>
            <small>{{ r.text }}</small>
          </div>
        </div>
        <p v-if="job.source !== 'csv'" class="im-hint">{{ t.__('Members can use the community while it runs. Posts appear in Home in their original order, under their original dates, so nobody’s feed fills up with old posts at the top.', 'membuild-community') }}</p>
      </template>

      <!-- 4. Done (or undone). -->
      <template v-else-if="screen === 'done' && job">
        <h2>{{ job.status === 'undone' ? t.__('Undone', 'membuild-community') : t.__('Done', 'membuild-community') }}</h2>
        <div v-if="job.status === 'undone'" class="im-box">
          <p class="im-p">{{ t.__('Everything this import brought is gone again. Members and their profiles stayed. You can import again any time.', 'membuild-community') }}</p>
          <div class="im-act left"><button type="button" class="btn btn-lg btn-primary" @click="again">{{ t.__('Back to Import', 'membuild-community') }}</button></div>
        </div>
        <template v-else>
          <div class="im-box">
            <div class="im-found">
              <span class="im-ic ok"><Icon name="check" :size="20" /></span>
              <span class="im-words"><strong class="im-run-title">{{ job.source === 'csv' ? t.__('Your members are here', 'membuild-community') : t.__('Your community is here', 'membuild-community') }}</strong><small>{{ took }}</small></span>
            </div>
            <div v-if="doneTiles.length" class="im-tiles">
              <span v-for="x in doneTiles" :key="x.l" class="im-tile"><strong>{{ n(x.v) }}</strong>{{ x.l }}</span>
            </div>
            <div class="im-act left">
              <button type="button" class="btn btn-lg btn-primary" @click="router.push({ name: 'feed' })">{{ t.__('See your community', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
              <button type="button" class="btn btn-lg" @click="router.push({ name: 'feed', query: { write: 1 } })">{{ t.__('Write a welcome post', 'membuild-community') }}</button>
            </div>
          </div>
          <div class="im-box">
            <h3>{{ t.__('Next, when you’re ready', 'membuild-community') }}</h3>
            <div v-if="!(job.source === 'csv' && job.welcome)" class="im-line">
              <span class="im-words"><strong>{{ t.__('Tell your members', 'membuild-community') }}</strong><small>{{ job.source === 'csv' ? t.__('One email from you, with a link to set their password.', 'membuild-community') : t.__('One email from you, with a link to log in. Their passwords haven’t changed.', 'membuild-community') }}</small></span>
              <span v-if="job.emailing" class="status-tag" :class="{ ok: job.emailing.finished }">{{ job.emailing.finished ? t.sprintf(t.__('Sent to %s', 'membuild-community'), n(job.emailing.sent)) : t.sprintf(/* translators: 1: sent, 2: total */ t.__('Sending: %1$s of %2$s', 'membuild-community'), n(job.emailing.sent), n(job.emailing.total)) }}</span>
              <button v-else type="button" class="btn" @click="emailEveryone"><Icon name="mail" :size="16" /> {{ t.__('Email everyone', 'membuild-community') }}</button>
            </div>
            <div v-else-if="job.emailing" class="im-line">
              <span class="im-words"><strong>{{ t.__('Welcome emails', 'membuild-community') }}</strong><small>{{ t.__('Sent slowly, about 200 an hour, so they don’t look like spam.', 'membuild-community') }}</small></span>
              <span class="status-tag" :class="{ ok: job.emailing.finished }">{{ job.emailing.finished ? t.sprintf(/* translators: %s: number */ t.__('Sent to %s', 'membuild-community'), n(job.emailing.sent)) : t.sprintf(t.__('Sending: %1$s of %2$s', 'membuild-community'), n(job.emailing.sent), n(job.emailing.total)) }}</span>
            </div>
            <div v-if="job.source !== 'csv'" class="im-line">
              <span class="im-words"><strong>{{ t.sprintf(/* translators: %s: plugin name */ t.__('Switch %s off', 'membuild-community'), job.label) }}</strong><small>{{ t.__('Its data stays in the database. Import again any time: it only brings what’s new.', 'membuild-community') }}</small></span>
              <a class="btn" :href="state.plugins">{{ t.__('Go to Plugins', 'membuild-community') }}</a>
            </div>
            <div class="im-line">
              <span class="im-words"><strong>{{ t.__('Undo this import', 'membuild-community') }}</strong><small>{{ job.source === 'csv' ? t.__('Removes the memberships it gave. The accounts it made stay, so nobody is locked out.', 'membuild-community') : t.__('Removes exactly what this import brought in (posts, comments, reactions, chats). Members and their profiles stay.', 'membuild-community') }}</small></span>
              <button type="button" class="btn" :disabled="busy" @click="undo"><Icon name="undo" :size="16" /> {{ t.__('Undo', 'membuild-community') }}</button>
            </div>
            <div class="im-line">
              <span class="im-words"><strong>{{ t.__('Import something else', 'membuild-community') }}</strong><small>{{ t.__('Another plugin, or a spreadsheet of members.', 'membuild-community') }}</small></span>
              <button type="button" class="btn" @click="again">{{ t.__('Start again', 'membuild-community') }}</button>
            </div>
          </div>
        </template>
      </template>

      <!-- Spreadsheet: match the columns. -->
      <template v-else-if="screen === 'csv' && file">
        <h2>{{ t.__('Add members from a spreadsheet', 'membuild-community') }}</h2>
        <p class="intro">{{ t.__('Tell us what each column is. We guessed from the column names; change anything that’s wrong.', 'membuild-community') }}</p>
        <div class="im-box">
          <div class="im-run">
            <strong class="im-file">{{ file.name }} · {{ t.sprintf(/* translators: %s: number of rows */ t._n('%s row', '%s rows', file.rows, 'membuild-community'), n(file.rows)) }}</strong>
            <span v-if="allGuessed" class="status-tag ok">{{ t.__('Columns matched for you', 'membuild-community') }}</span>
          </div>
          <div class="im-table" role="table">
            <div class="im-tr head" role="row"><span role="columnheader">{{ t.__('In your file', 'membuild-community') }}</span><span role="columnheader">{{ t.__('First row', 'membuild-community') }}</span><span role="columnheader">{{ t.__('Becomes', 'membuild-community') }}</span></div>
            <div v-for="(c, i) in file.columns" :key="i" class="im-tr" role="row">
              <strong role="cell">{{ c.name }}</strong>
              <span role="cell" class="im-first">{{ c.first }}</span>
              <span role="cell">
                <select class="im-select" :class="{ off: map[i] === 'skip' }" :value="map[i]" :aria-label="t.sprintf(/* translators: %s: a column name */ t.__('What “%s” becomes', 'membuild-community'), c.name)" @change="setField(i, $event.target.value)">
                  <option v-for="(label, key) in FIELD_LABELS" :key="key" :value="key">{{ label }}</option>
                </select>
              </span>
            </div>
          </div>
        </div>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        <div class="im-act">
          <button type="button" class="btn btn-lg" @click="screen = state.found.length ? 'start' : 'where'">{{ t.__('Back', 'membuild-community') }}</button>
          <button type="button" class="btn btn-lg btn-primary" :disabled="busy" @click="csvCheck">{{ busy ? t.__('Checking…', 'membuild-community') : t.__('Check first', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
        </div>
      </template>

      <!-- Spreadsheet: the dry run. -->
      <template v-else-if="screen === 'csvcheck' && report">
        <h2>{{ t.__('Before anyone is added', 'membuild-community') }}</h2>
        <p class="intro">{{ t.__('A dry run: nothing has changed yet.', 'membuild-community') }}</p>
        <div class="im-box">
          <h3>{{ t.__('What will happen', 'membuild-community') }}</h3>
          <div v-for="(it, i) in report.items" :key="i" class="im-line">
            <span class="im-words">
              <strong>{{ it.title }}</strong>
              <small v-if="it.sub">{{ it.sub }} <button v-if="it.download" type="button" class="im-link" @click="downloadBad">{{ t._n('Download the row', 'Download the rows', report.invalid.length, 'membuild-community') }}</button></small>
            </span>
            <span class="status-tag" :class="tagClass(it.tag)">{{ tagText(it.tag) }}</span>
          </div>
          <div v-if="report.plans?.length && file.memberships.length" class="im-plans">
            <div v-for="p in report.plans" :key="p.value" class="im-plan">
              <span><strong>{{ p.value }}</strong> <small>· {{ t.sprintf(t._n('%s member', '%s members', p.count, 'membuild-community'), n(p.count)) }}</small></span>
              <select v-model.number="plans[p.value]" class="im-select" :aria-label="t.sprintf(/* translators: %s: a plan name */ t.__('Membership for “%s”', 'membuild-community'), p.value)" @change="recheck">
                <option :value="0">{{ t.__('No membership', 'membuild-community') }}</option>
                <option v-for="g in file.memberships" :key="g.id" :value="g.id">{{ g.name }}</option>
              </select>
            </div>
          </div>
        </div>
        <div class="im-box" role="radiogroup" :aria-label="t.__('Letting them know', 'membuild-community')">
          <h3>{{ t.__('Letting them know', 'membuild-community') }}</h3>
          <div class="choices im-choices">
            <label class="choice" :class="{ on: !welcome }"><input v-model="welcome" type="radio" :value="false" /><span class="mark" /><strong>{{ t.__('Don’t email anyone yet', 'membuild-community') }}</strong><small>{{ t.__('They can log in any time with “Forgot password?”. You can email everyone from here later.', 'membuild-community') }}</small></label>
            <label class="choice" :class="{ on: welcome }"><input v-model="welcome" type="radio" :value="true" /><span class="mark" /><strong>{{ t.__('Send each new member a welcome email', 'membuild-community') }}</strong><small>{{ t.__('From you, with a link to set their password. Sent slowly (about 200 an hour) so it doesn’t look like spam.', 'membuild-community') }}</small></label>
          </div>
        </div>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        <div class="im-act">
          <button type="button" class="btn btn-lg" @click="screen = 'csv'">{{ t.__('Back', 'membuild-community') }}</button>
          <button type="button" class="btn btn-lg btn-primary" :disabled="busy || !(report.new || report.here)" @click="start">{{ report.new ? t.sprintf(/* translators: %s: number */ t._n('Add %s member', 'Add %s members', report.new, 'membuild-community'), n(report.new)) : t.__('Update members', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
        </div>
      </template>

      <!-- Past imports: each undoes on its own, newest first. -->
      <div v-if="['start', 'where'].includes(screen) && state.past?.length" class="im-box">
        <h3 class="im-past-head">{{ t.__('Past imports', 'membuild-community') }}</h3>
        <p class="im-p im-past-intro">{{ t.__('Newest first. Each one undoes on its own: only what that import brought goes. Members and their profiles stay.', 'membuild-community') }}</p>
        <div v-for="p in state.past" :key="p.id" class="im-line">
          <span class="im-words"><strong>{{ pastTitle(p) }}</strong><small>{{ pastLine(p) }}</small></span>
          <span v-if="p.status === 'undone'" class="status-tag">{{ t.sprintf(/* translators: %s: a date */ t.__('Undone %s', 'membuild-community'), day(p.undone_at)) }}</span>
          <button v-else type="button" class="btn" :disabled="!!undoingId || ['running', 'paused'].includes(job?.status)" @click="undoPast(p)"><Icon name="undo" :size="16" /> {{ t.__('Undo', 'membuild-community') }}</button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.im-loading {
  margin: 0;
  color: var(--text-3);
}

.im-steps {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 12px;
  margin: 0 0 18px;
  padding: 0;
  list-style: none;
  color: var(--text-2);
  font-weight: 650;
}

.im-steps li {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.im-steps li + li::before {
  content: '';
  width: 28px;
  height: 2px;
  margin-right: 4px;
  background: var(--border);
}

.im-steps li.on,
.im-steps li.done {
  color: var(--text);
}

.im-step-no {
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  font-size: var(--fs-sm);
}

.im-steps li.on .im-step-no {
  background: var(--accent);
  color: var(--accent-ink);
}

.im-steps li.done .im-step-no {
  background: var(--ok-strong);
  color: var(--on-color);
}

.im-box {
  display: block;
  width: 100%;
  margin: 0 0 14px;
  padding: 20px 22px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface);
  color: var(--text);
  text-align: left;
  font: inherit;
  min-width: 0;
}

.group.im .im-box h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 6px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--border);
  font-size: var(--fs-xl);
}

.im-found,
.im-run {
  display: flex;
  align-items: center;
  gap: 14px;
}

.im-ic {
  flex: none;
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
}

.im-ic.accent {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.im-ic.ok {
  border-radius: var(--radius-pill);
  background: var(--ok-soft);
  color: var(--ok-text);
}

.im-words {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.im-words strong {
  font-size: var(--fs-lg);
}

.im-words .im-run-title {
  font-size: var(--fs-2xl);
}

.im-names {
  color: var(--text-2);
  font-weight: 600;
}

.im-tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 10px;
  margin-top: 16px;
}

.im-tile {
  display: flex;
  flex-direction: column;
  padding: 12px 14px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-md);
}

.im-tile strong {
  color: var(--text);
  font-size: var(--fs-4xl);
}

.im-note p,
.im-p {
  margin: 8px 0 0;
  color: var(--text-2);
  line-height: 1.5;
}

.im-link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--accent-text);
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
}

.im-choice {
  display: flex;
  align-items: center;
  gap: 14px;
  cursor: pointer;
}

.im-choice:hover {
  border-color: var(--accent);
  background: var(--accent-soft);
}

.im-choice:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

.im-guide {
  padding: 4px 22px;
  list-style: none;
}

.im-guide li {
  display: flex;
  gap: 14px;
  padding: 18px 0;
}

.im-guide li + li {
  border-top: 1px solid var(--border);
}

.im-guide li > span:last-child {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.im-num {
  flex: none;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-pill);
  background: var(--text);
  color: var(--surface);
  font-weight: 700;
}

.group.im .im-box h3.im-past-head {
  padding-bottom: 0;
  border-bottom: 0;
}

.im-p.im-past-intro {
  margin: 0 0 6px;
}

.im-hint {
  margin: 0 0 14px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  line-height: 1.5;
}

.im-act {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 4px;
}

.im-act.left {
  justify-content: flex-start;
  margin-top: 18px;
}

.im-howto {
  margin: 12px 0 16px;
  padding-left: 22px;
  line-height: 1.8;
}

.im-two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.im-comes,
.im-stays {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  line-height: 1.5;
}

.im-comes {
  background: var(--ok-soft);
}

.im-comes strong {
  color: var(--ok-text);
}

.im-stays strong {
  color: var(--text);
}

.im-drop {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 28px;
  border: 2px dashed var(--border-strong);
}

.im-drop.over {
  border-color: var(--accent);
  background: var(--accent-soft);
}

.im-tick {
  position: relative;
  display: flex;
  gap: 14px;
  padding: 16px 0;
  cursor: pointer;
}

.im-tick + .im-tick {
  border-top: 1px solid var(--border);
}

.im-tick input {
  position: absolute;
  opacity: 0;
}

.im-box-mark {
  flex: none;
  display: grid;
  place-items: center;
  width: 24px;
  height: 24px;
  border: 2px solid var(--border-strong);
  border-radius: var(--radius-xs);
  color: transparent;
}

.im-tick input:checked + .im-box-mark {
  border-color: var(--text);
  background: var(--text);
  color: var(--surface);
}

.im-tick input:focus-visible + .im-box-mark {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

.im-count {
  color: var(--text-2);
  font-weight: 600;
}

.im-pro {
  display: flex;
  align-items: flex-start;
  gap: 20px;
  margin-top: 16px;
  padding: 22px 24px;
  border: 1px solid color-mix(in srgb, var(--accent) 40%, var(--surface));
  border-radius: var(--radius-lg);
  background: linear-gradient(135deg, var(--accent-soft) 0%, var(--surface) 70%);
}

.im-pro-ic {
  flex: none;
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: var(--radius-md);
  background: var(--ink);
  color: var(--accent);
}

.im-pro-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.im-pro-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.im-pro-head strong {
  font-size: var(--fs-2xl);
}

.im-pro-badge {
  padding: 4px 10px;
  border-radius: var(--radius-pill);
  background: var(--ink);
  color: var(--accent);
  font-size: var(--fs-xs);
  font-weight: 800;
  letter-spacing: 0.06em;
}

.im-pro-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.im-pro-list li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  line-height: 1.45;
}

.im-pro-list li > svg {
  flex: none;
  box-sizing: content-box;
  padding: 4px;
  border-radius: var(--radius-pill);
  background: var(--accent);
  color: var(--accent-ink);
}

.im-pro-more {
  margin: -2px 0 0 32px;
  color: var(--text-2);
  font-weight: 600;
}

.im-pro-act {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
  margin-top: 2px;
}

.group.im .im-pro-btn {
  background: var(--ink);
  color: var(--paper);
}

/* On a dark page the dark button would vanish into the box: the main green there. */
@media (prefers-color-scheme: dark) {
  .group.im .im-pro-btn {
    background: var(--accent);
    color: var(--accent-ink);
  }
}

.im-pro-act small {
  color: var(--text-2);
  line-height: 1.45;
}

.im-pro-done {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--accent-soft);
  line-height: 1.45;
}

.im-pro-ok {
  flex: none;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-pill);
  background: var(--ok-strong);
  color: var(--on-color);
}

.im-line {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 0;
}

.im-line + .im-line {
  border-top: 1px solid var(--border);
}

.im-line > .btn {
  flex: none;
}

.im-bar {
  display: block;
  height: 10px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}

.im-bar.big {
  margin: 16px 0 6px;
}

.im-bar i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
  transition: width 0.4s;
}

.im-bar.done i {
  background: var(--ok-strong);
}

.im-row {
  display: grid;
  grid-template-columns: 200px 1fr 170px;
  align-items: center;
  gap: 16px;
  padding: 14px 0;
  border-top: 1px solid var(--border);
}

.im-row small {
  color: var(--text-2);
  text-align: right;
}

.im-file {
  flex: 1;
  font-size: var(--fs-xl);
}

.im-table {
  margin-top: 12px;
}

.im-tr {
  display: grid;
  grid-template-columns: 1fr 1fr 240px;
  align-items: center;
  gap: 16px;
  padding: 10px 0;
  border-top: 1px solid var(--border);
}

.im-tr.head {
  color: var(--text-2);
  font-weight: 650;
}

.im-first {
  overflow: hidden;
  color: var(--text-2);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.im-select {
  width: 100%;
  min-height: 44px;
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
}

.im-select.off {
  color: var(--text-2);
}

.im-plans {
  display: grid;
  gap: 10px;
  margin-top: 4px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
}

.im-plan {
  display: grid;
  grid-template-columns: 1fr 240px;
  align-items: center;
  gap: 12px;
}

.im-choices {
  margin-top: 14px;
}

@media (max-width: 767px) {
  .im-top {
    display: none; /* the phone page's own heading already says Import */
  }

  .im-box {
    padding: 16px;
  }

  .im-pro {
    flex-direction: column;
  }

  .im-found {
    flex-wrap: wrap;
  }

  .im-found > .btn {
    width: 100%;
  }

  .im-two,
  .im-plan {
    grid-template-columns: 1fr;
  }

  .im-row {
    grid-template-columns: 1fr auto;
    gap: 8px;
  }

  .im-row .im-bar {
    grid-column: 1 / -1;
    grid-row: 2;
  }

  .im-tr {
    grid-template-columns: 1fr;
    gap: 6px;
  }

  .im-tr.head {
    display: none;
  }

  .im-line {
    flex-wrap: wrap;
  }

  .im-line > .btn {
    width: 100%;
  }

  .im-act .btn {
    flex: 1;
  }

  .im-steps li + li::before {
    width: 12px;
  }
}
</style>
