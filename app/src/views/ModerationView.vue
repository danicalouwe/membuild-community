<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import { messages } from '../stores/messages.js'
import { removePost } from '../stores/feed.js'
import { timeAgo } from '../lib/time.js'
import { showToast } from '../lib/toast.js'
import Avatar from '../components/Avatar.vue'
import BaseDialog from '../components/BaseDialog.vue'
import Icon from '../components/Icon.vue'
import { locale, t } from '../lib/i18n.js'

/**
 * Moderation, no sidebar. Needs you: one card per reported thing, saying who, why and
 * what members said, with two clear choices (Take it down / Leave it up; a profile: It's fine)
 * and one Mute or ban button. Done: what was decided, by whom and when. Muted & banned:
 * what each one means, when it ends, Unmute now / Unban.
 */
const TABS = [
  { key: 'open', label: t.__('Needs you', 'membuild-community') },
  { key: 'resolved', label: t.__('Done', 'membuild-community') },
  { key: 'members', label: t.__('Muted & banned', 'membuild-community') },
]
const tab = ref('open')
const items = ref([])
const nextPage = ref(null)
const people = ref([])
const peopleNext = ref(null)
const loading = ref(true)
const busy = ref('')
const error = ref('')

const keyOf = (it) => `${it.object_type}-${it.object_id}`
// A long note is cut so the card stays short and the name after it still shows.
const short = (text) => ([...text].length > 220 ? `${[...text].slice(0, 210).join('').trimEnd()}…` : text)
const first = (u) => (u?.name || '').split(' ')[0]
const day = (iso) => new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(iso))
// "2h ago", or "just now" in the first minute.
// Older than a week it's a date ("Oct 14"), which needs no "ago".
function ago(iso) {
  const age = Date.now() - new Date(iso).getTime()
  if (age < 60000) return t.__('just now', 'membuild-community')
  if (age >= 7 * 86400000) return timeAgo(iso)
  return t.sprintf(/* translators: %s: a short time, e.g. 2h */ t.__('%s ago', 'membuild-community'), timeAgo(iso))
}
const inDays = (n) => new Date(Date.now() + n * 86400000).toISOString()

/* ---------- Loading ---------- */

const counts = ref({ open: 0, resolved: 0, members: 0 })
const countOf = (key) => (key === 'open' ? messages.reports : counts.value[key] || 0)
function takeCounts(res) {
  if (res?.counts) counts.value = res.counts
  if (typeof res?.open === 'number') messages.reports = res.open
}

async function load(reset = true) {
  loading.value = true
  error.value = ''
  try {
    if (tab.value === 'members') {
      const res = await api.restricted(reset ? 1 : peopleNext.value)
      people.value = reset ? res.items : [...people.value, ...res.items]
      peopleNext.value = res.next_page
      takeCounts(res)
    } else {
      const res = await api.modReports(tab.value, reset ? 1 : nextPage.value)
      items.value = reset ? res.items : [...items.value, ...res.items]
      nextPage.value = res.next_page
      takeCounts(res)
    }
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(tab, () => {
  items.value = []
  people.value = []
  load()
})

/* ---------- The words on a card ---------- */

function title(it) {
  const name = it.author?.name || t.__('A member', 'membuild-community')
  if (it.object_type === 'member') return t.sprintf(/* translators: %s: a member's name */ t.__('Profile of %s', 'membuild-community'), name)
  if (it.object_type === 'comment') return t.sprintf(/* translators: %s: a member's name */ t.__('Comment by %s', 'membuild-community'), name)
  return t.sprintf(/* translators: %s: a member's name */ t.__('Post by %s', 'membuild-community'), name)
}
function subtitle(it) {
  const p = it.preview
  if (it.object_type === 'member') return p.created_at ? t.sprintf(/* translators: %s: month and year */ t.__('Joined in %s', 'membuild-community'), new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(new Date(p.created_at))) : ''
  const when = p.created_at ? ago(p.created_at) : ''
  if (it.object_type === 'comment') return p.on ? t.sprintf(/* translators: 1: how long ago ("2h ago"), 2: whose post */ t.__('%1$s, on %2$s’s post', 'membuild-community'), when, p.on) : when
  return t.sprintf(/* translators: 1: how long ago ("2h ago"), 2: where (Home or a course) */ t.__('Posted %1$s in %2$s', 'membuild-community'), when, p.course || t.__('Home', 'membuild-community'))
}
function reportedBy(it) {
  return t.sprintf(/* translators: %d: number of members */ t._n('Reported by %d member:', 'Reported by %d members:', it.reports, 'membuild-community'), it.reports)
}
const reasonsText = (it) => it.reasons.map((r) => (it.reports > 1 ? `${r.label} (${r.count})` : r.label)).join(', ') + '.'

/* ---------- Deciding ---------- */

async function leave(it) {
  busy.value = keyOf(it)
  try {
    takeCounts(await api.resolveReport(it.object_type, it.object_id, 'keep'))
    items.value = items.value.filter((x) => x !== it)
    showToast(it.object_type === 'member' ? t.__('Marked as fine.', 'membuild-community') : t.__('Left up. It’s in Done.', 'membuild-community'))
  } catch (err) {
    showToast(err.message)
  } finally {
    busy.value = ''
  }
}

/* Take it down: one box that can also mute or ban the person. */
const takeDown = ref(null) // { it, also: 'none' | 'mute7' | 'ban' }
const canRestrict = (it) => it.author && !it.author_is_staff && !it.author_restriction
function askTakeDown(it) {
  takeDown.value = { it, also: canRestrict(it) ? 'mute7' : 'none' }
}
const takeDownLabel = computed(() => {
  const a = takeDown.value?.also
  if (a === 'mute7') return t.__('Take it down and mute', 'membuild-community')
  if (a === 'ban') return t.__('Take it down and ban', 'membuild-community')
  return t.__('Take it down', 'membuild-community')
})
async function confirmTakeDown() {
  const { it, also } = takeDown.value
  busy.value = keyOf(it)
  try {
    takeCounts(await api.resolveReport(it.object_type, it.object_id, 'remove'))
    if (it.object_type === 'post') removePost(it.object_id)
    if (also !== 'none') takeCounts(await api.restrict(it.author.id, { status: also === 'ban' ? 'banned' : 'muted', days: also === 'ban' ? 0 : 7 }))
    items.value = items.value.filter((x) => x !== it)
    takeDown.value = null
    showToast(also === 'ban' ? t.sprintf(t.__('Taken down, and %s is banned.', 'membuild-community'), first(it.author)) : also === 'mute7' ? t.sprintf(t.__('Taken down, and %s is muted for 7 days.', 'membuild-community'), first(it.author)) : t.__('Taken down. It’s in Done.', 'membuild-community'))
  } catch (err) {
    showToast(err.message)
  } finally {
    busy.value = ''
  }
}

/* Mute or ban: choice cards that say what each one means. */
const muting = ref(null) // { user, it, pick }
const MUTES = computed(() => {
  const name = first(muting.value?.user)
  return [
    { key: 'mute1', status: 'muted', days: 1, label: t.__('Mute for 1 day', 'membuild-community'), hint: t.__('Can read and react, but can’t post, comment or send messages until tomorrow.', 'membuild-community') },
    { key: 'mute7', status: 'muted', days: 7, label: t.__('Mute for 7 days', 'membuild-community'), hint: t.sprintf(/* translators: %s: a date */ t.__('The same, until %s.', 'membuild-community'), day(inDays(7))) },
    { key: 'mute0', status: 'muted', days: 0, label: t.__('Mute until I unmute', 'membuild-community'), hint: t.__('The same, with no end date.', 'membuild-community') },
    { key: 'ban', status: 'banned', days: 0, label: t.__('Ban from the community', 'membuild-community'), hint: name ? t.sprintf(/* translators: %s: a first name */ t.__('%s can’t open the community at all.', 'membuild-community'), name) : t.__('Can’t open the community at all.', 'membuild-community') },
  ]
})
function askMute(user, it = null) {
  muting.value = { user, it, pick: 'mute7' }
}
const mutePick = computed(() => MUTES.value.find((m) => m.key === muting.value?.pick))
async function confirmMute() {
  const { user, it } = muting.value
  const m = mutePick.value
  try {
    const res = await api.restrict(user.id, { status: m.status, days: m.days })
    takeCounts(res)
    if (it) {
      it.author_restriction = res.restriction ? { status: res.restriction.status, until: res.restriction.until } : null
      if (it.object_type === 'member') items.value = items.value.filter((x) => x !== it) // a profile report is settled by this
    }
    muting.value = null
    showToast(m.status === 'banned' ? t.sprintf(t.__('%s is banned.', 'membuild-community'), user.name) : t.sprintf(t.__('%s is muted.', 'membuild-community'), user.name))
  } catch (err) {
    showToast(err.message)
  }
}

async function lift(user, it = null) {
  try {
    const res = await api.restrict(user.id, { status: 'none' })
    takeCounts(res)
    if (it) it.author_restriction = null
    if (tab.value === 'members') people.value = people.value.filter((p) => p.user.id !== user.id)
    showToast(t.sprintf(/* translators: %s: a member's name */ t.__('%s can take part again.', 'membuild-community'), user.name))
  } catch (err) {
    showToast(err.message)
  }
}

/* ---------- Done and Muted & banned ---------- */

const OUTCOME = {
  kept: { label: t.__('Left up', 'membuild-community'), tone: 'green' },
  removed: { label: t.__('Taken down', 'membuild-community'), tone: 'grey' },
  muted: { label: t.__('Muted', 'membuild-community'), tone: 'yellow' },
  banned: { label: t.__('Banned', 'membuild-community'), tone: 'red' },
}
const outcome = (it) => (it.object_type === 'member' && it.resolution.action === 'kept' ? { label: t.__('Fine', 'membuild-community'), tone: 'green' } : OUTCOME[it.resolution.action] || OUTCOME.kept)
function decidedLine(it) {
  const why = t.sprintf(/* translators: %s: report reasons */ t.__('Reported for %s', 'membuild-community'), it.reasons.map((r) => r.label).join(', '))
  const by = it.resolution.by?.name ? t.sprintf(/* translators: 1: a name, 2: how long ago ("2h ago") */ t.__('decided by %1$s %2$s', 'membuild-community'), it.resolution.by.name, ago(it.resolution.at)) : t.sprintf(t.__('decided %s', 'membuild-community'), ago(it.resolution.at))
  return `${why} · ${by}`
}
function personTag(p) {
  if (p.status === 'banned') return t.__('Banned', 'membuild-community')
  return p.until ? t.sprintf(/* translators: %s: a date */ t.__('Muted until %s', 'membuild-community'), day(p.until)) : t.__('Muted until you unmute', 'membuild-community')
}
function personLine(p) {
  const by = p.by?.name ? (p.at ? t.sprintf(/* translators: 1: Muted or Banned, 2: a name, 3: how long ago ("2h ago") */ t.__('%1$s by %2$s %3$s.', 'membuild-community'), p.status === 'banned' ? t.__('Banned', 'membuild-community') : t.__('Muted', 'membuild-community'), p.by.name, ago(p.at)) : '') : ''
  const what = p.status === 'banned' ? t.__('Can’t open the community.', 'membuild-community') : t.__('Can read and react, but can’t post, comment or send messages.', 'membuild-community')
  return [what, by].filter(Boolean).join(' ')
}
</script>

<template>
  <div class="mod">
    <header class="head">
      <h1>{{ t.__('Moderation', 'membuild-community') }}</h1>
      <div class="tabs" role="tablist">
        <button class="filter-pill" v-for="tb in TABS" :key="tb.key" type="button" role="tab" :aria-selected="tab === tb.key" :class="{ on: tab === tb.key }" @click="tab = tb.key">
          {{ tb.label }}
          <span v-if="countOf(tb.key)" class="count" :class="{ red: tb.key === 'open' }">{{ countOf(tb.key) }}</span>
        </button>
      </div>
      <p>{{ t.__('Members can report a post, a comment or a profile. You decide what stays up.', 'membuild-community') }}</p>
    </header>

    <p v-if="error" class="card state">{{ error }}</p>

    <!-- Loading -->
    <div v-else-if="loading && !items.length && !people.length" class="card sk-card" aria-hidden="true">
      <div v-for="n in 3" :key="n" class="sk"><span class="skeleton" /><span><span class="skeleton" /><span class="skeleton" /></span></div>
    </div>

    <!-- Muted & banned -->
    <template v-else-if="tab === 'members'">
      <section v-if="!people.length" class="card state">{{ t.__('Nobody is muted or banned.', 'membuild-community') }}</section>
      <ul v-else class="card list">
        <li v-for="p in people" :key="p.user.id" class="person">
          <Avatar :user="p.user" :size="48" link />
          <span class="who">
            <span class="name-row">
              <RouterLink :to="{ name: 'member', params: { id: p.user.id } }"><strong>{{ p.user.name }}</strong></RouterLink>
              <span class="tag" :class="p.status === 'banned' ? 'red' : 'yellow'">{{ personTag(p) }}</span>
            </span>
            <small>{{ personLine(p) }}</small>
          </span>
          <button type="button" class="btn-grey" @click="lift(p.user)">{{ p.status === 'banned' ? t.__('Unban', 'membuild-community') : t.__('Unmute now', 'membuild-community') }}</button>
        </li>
      </ul>
      <button v-if="peopleNext" type="button" class="btn-grey more" :disabled="loading" @click="load(false)">{{ t.__('Show more', 'membuild-community') }}</button>
      <p class="note"><Icon name="info" :size="18" /> {{ t.__('Mutes end by themselves on the date shown. Admins and moderators can’t be muted or banned.', 'membuild-community') }}</p>
    </template>

    <!-- Done -->
    <template v-else-if="tab === 'resolved'">
      <section v-if="!items.length" class="card state">{{ t.__('No decisions yet.', 'membuild-community') }}</section>
      <ul v-else class="card list">
        <li v-for="it in items" :key="keyOf(it)" class="done">
          <Avatar :user="it.author" :size="48" link />
          <span class="who">
            <strong>{{ title(it) }}</strong>
            <span v-if="it.preview.content" class="said">{{ it.preview.content }}</span>
            <small>{{ decidedLine(it) }}</small>
          </span>
          <span class="tag" :class="outcome(it).tone">{{ outcome(it).label }}</span>
        </li>
      </ul>
      <button v-if="nextPage" type="button" class="btn-grey more" :disabled="loading" @click="load(false)">{{ t.__('Show more', 'membuild-community') }}</button>
      <p class="note"><Icon name="info" :size="18" /> {{ t.__('Decisions stay here for your records. The person reported is never told who reported them.', 'membuild-community') }}</p>
    </template>

    <!-- Needs you -->
    <template v-else>
      <section v-if="!items.length" class="card clear">
        <span class="tick"><Icon name="check" :size="26" /></span>
        <strong>{{ t.__('All clear', 'membuild-community') }}</strong>
        <p>{{ t.__('Nothing needs you right now. When a member reports something, it shows up here and you get a notification. A post reported by 3 members is hidden until you decide.', 'membuild-community') }}</p>
      </section>

      <article v-for="it in items" :key="keyOf(it)" class="card report" :class="{ busy: busy === keyOf(it) }">
        <header class="r-head">
          <Avatar :user="it.author" :size="44" link />
          <span class="who">
            <span class="name-row">
              <strong>{{ title(it) }}</strong>
              <span v-if="it.author_restriction" class="tag" :class="it.author_restriction.status === 'banned' ? 'red' : 'yellow'">{{ personTag(it.author_restriction) }}</span>
            </span>
            <small>{{ subtitle(it) }}</small>
          </span>
          <span v-if="it.preview.hidden" class="hidden-tag"><Icon name="eyeOff" :size="16" /> {{ t.__('Hidden from members until you decide', 'membuild-community') }}</span>
          <span v-else-if="it.preview.gone" class="tag grey">{{ t.__('Already removed', 'membuild-community') }}</span>
        </header>

        <p class="why"><strong>{{ reportedBy(it) }}</strong> {{ reasonsText(it) }}{{ it.preview.hidden ? ' ' + t.__('A post reported by 3 members hides by itself.', 'membuild-community') : '' }}</p>

        <div v-if="it.preview.content || it.preview.thumb" class="quote">
          <p v-if="it.preview.content">{{ it.preview.content }}</p>
          <img v-if="it.preview.thumb" :src="it.preview.thumb" alt="" loading="lazy" />
        </div>
        <RouterLink v-if="it.object_type === 'member'" :to="{ name: 'member', params: { id: it.object_id } }" class="open">{{ t.__('Open the profile', 'membuild-community') }} <Icon name="chevronRight" :size="16" /></RouterLink>
        <RouterLink v-else-if="!it.preview.gone" :to="{ query: { post: it.post_id } }" class="open">{{ t.__('Open the post', 'membuild-community') }} <Icon name="chevronRight" :size="16" /></RouterLink>

        <div v-if="it.notes.length" class="said-list">
          <span class="label">{{ t.__('What members said', 'membuild-community') }}</span>
          <p v-for="(n, i) in it.notes" :key="i">“{{ short(n.note) }}” <small>{{ n.by?.name || t.__('A member', 'membuild-community') }}</small></p>
        </div>

        <footer class="actions">
          <template v-if="it.object_type === 'member'">
            <button type="button" class="btn-grey" :disabled="!!busy" @click="leave(it)">{{ t.__('It’s fine', 'membuild-community') }}</button>
          </template>
          <template v-else>
            <button v-if="!it.preview.gone" type="button" class="btn-red" :disabled="!!busy" @click="askTakeDown(it)">{{ t.__('Take it down', 'membuild-community') }}</button>
            <button type="button" class="btn-grey" :disabled="!!busy" @click="leave(it)">{{ it.preview.gone ? t.__('Close the report', 'membuild-community') : t.__('Leave it up', 'membuild-community') }}</button>
          </template>
          <span class="spacer" />
          <template v-if="it.author && !it.author_is_staff">
            <button v-if="!it.author_restriction" type="button" class="btn-line" @click="askMute(it.author, it)">{{ t.__('Mute or ban', 'membuild-community') }} <Icon name="chevronDown" :size="18" /></button>
            <button v-else type="button" class="btn-line" @click="lift(it.author, it)">{{ it.author_restriction.status === 'banned' ? t.__('Unban', 'membuild-community') : t.__('Unmute', 'membuild-community') }}</button>
          </template>
        </footer>
      </article>

      <button v-if="nextPage" type="button" class="btn-grey more" :disabled="loading" @click="load(false)">{{ t.__('Show more', 'membuild-community') }}</button>
    </template>

    <!-- Take it down, and maybe mute or ban in the same step -->
    <BaseDialog v-if="takeDown" :label="takeDownLabel" :width="580" phone="sheet" @close="takeDown = null">
      <template #header>
        <h2 class="dlg-title">{{ takeDown.it.object_type === 'comment' ? t.sprintf(t.__('Take %s’s comment down?', 'membuild-community'), takeDown.it.author?.name) : t.sprintf(t.__('Take %s’s post down?', 'membuild-community'), takeDown.it.author?.name) }}</h2>
      </template>
      <div class="dlg">
        <p class="dlg-text">{{ t.sprintf(/* translators: %s: a first name */ t.__('It disappears for everyone. %s isn’t told who reported it.', 'membuild-community'), first(takeDown.it.author)) }}</p>
        <template v-if="canRestrict(takeDown.it)">
          <strong class="dlg-q">{{ t.sprintf(/* translators: %s: a first name */ t.__('Also stop %s from posting?', 'membuild-community'), first(takeDown.it.author)) }}</strong>
          <div class="cards">
            <label class="pick" :class="{ on: takeDown.also === 'none' }"><input v-model="takeDown.also" type="radio" value="none" /><span class="dot" /><span><strong>{{ t.__('No, just take it down', 'membuild-community') }}</strong><small>{{ t.sprintf(t.__('%s can keep posting as normal.', 'membuild-community'), first(takeDown.it.author)) }}</small></span></label>
            <label class="pick" :class="{ on: takeDown.also === 'mute7' }"><input v-model="takeDown.also" type="radio" value="mute7" /><span class="dot" /><span><strong>{{ t.__('Mute for 7 days', 'membuild-community') }}</strong><small>{{ t.sprintf(/* translators: 1: a first name, 2: a date */ t.__('%1$s can read and react, but can’t post, comment or send messages until %2$s.', 'membuild-community'), first(takeDown.it.author), day(inDays(7))) }}</small></span></label>
            <label class="pick" :class="{ on: takeDown.also === 'ban' }"><input v-model="takeDown.also" type="radio" value="ban" /><span class="dot" /><span><strong>{{ t.__('Ban from the community', 'membuild-community') }}</strong><small>{{ t.sprintf(t.__('%s can’t open the community at all until you unban them.', 'membuild-community'), first(takeDown.it.author)) }}</small></span></label>
          </div>
        </template>
        <div class="dlg-act">
          <button type="button" class="btn-grey" @click="takeDown = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button type="button" class="btn-red" :disabled="!!busy" @click="confirmTakeDown">{{ takeDownLabel }}</button>
        </div>
      </div>
    </BaseDialog>

    <!-- Mute or ban -->
    <BaseDialog v-if="muting" :label="t.sprintf(t.__('Mute or ban %s', 'membuild-community'), muting.user.name)" :width="580" phone="sheet" @close="muting = null">
      <template #header>
        <h2 class="dlg-title">{{ t.sprintf(/* translators: %s: a member's name */ t.__('Mute or ban %s', 'membuild-community'), muting.user.name) }}</h2>
      </template>
      <div class="dlg">
        <p class="dlg-text">{{ t.__('Nothing they’ve already posted is removed. You can undo this any time in Muted & banned.', 'membuild-community') }}</p>
        <div class="cards">
          <label v-for="m in MUTES" :key="m.key" class="pick" :class="{ on: muting.pick === m.key }"><input v-model="muting.pick" type="radio" :value="m.key" /><span class="dot" /><span><strong>{{ m.label }}</strong><small>{{ m.hint }}</small></span></label>
        </div>
        <div class="dlg-act">
          <button type="button" class="btn-grey" @click="muting = null">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button type="button" class="btn-red" @click="confirmMute">{{ mutePick?.label }}</button>
        </div>
      </div>
    </BaseDialog>
  </div>
</template>

<style scoped>
.mod {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-width: 860px;
  margin: 0 auto;
  padding: 32px 16px 48px;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px 16px;
  flex-wrap: wrap;
}

.head p {
  flex-basis: 100%;
}

.head h1 {
  margin: 0;
  font-size: 30px;
}

.head p {
  margin: 0;
  color: var(--text-2);
  font-size: 16px;
}

.tabs {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.count {
  display: inline-grid;
  place-items: center;
  min-width: 24px;
  height: 24px;
  padding: 0 7px;
  border-radius: 999px;
  color: var(--text-2);
  background: var(--surface);
  font-size: 13px;
}

.count.red {
  color: #fff;
  background: var(--danger);
}

.state {
  margin: 0;
  padding: 28px;
  color: var(--text-2);
  font-size: 16px;
  text-align: center;
}

/* A report */
.report {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 24px 28px;
  transition: opacity 0.2s ease;
}

.report.busy {
  opacity: 0.55;
}

.r-head {
  display: flex;
  align-items: center;
  gap: 12px;
}

.who {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.who strong {
  font-size: 17px;
}

.who small {
  color: var(--text-2);
  font-size: 15px;
}

.name-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.name-row a {
  color: var(--text);
}

.hidden-tag {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: 999px;
  color: var(--warn-text);
  background: var(--warn-soft);
  font-size: 14px;
  font-weight: 700;
}

.why {
  margin: 0;
  font-size: 16px;
  line-height: 1.5;
}

.quote {
  padding: 16px 18px;
  border-radius: 12px;
  background: var(--surface-2);
}

.quote p {
  margin: 0;
  font-size: 16px;
  line-height: 1.55;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.quote img {
  display: block;
  max-width: 220px;
  max-height: 160px;
  margin-top: 10px;
  border-radius: 10px;
  object-fit: cover;
}

.open {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  align-self: flex-start;
  color: var(--text);
  font-size: 15px;
  font-weight: 700;
  text-decoration: underline;
}

.said-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.said-list .label {
  color: var(--text-2);
  font-size: 15px;
  font-weight: 600;
}

.said-list p {
  margin: 0;
  font-size: 16px;
  overflow-wrap: anywhere;
}

.said-list small {
  color: var(--text-2);
  font-size: 14px;
}

.actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding-top: 18px;
  border-top: 1px solid var(--border);
}

.spacer {
  flex: 1;
}

.btn-red,
.btn-grey,
.btn-line {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 52px;
  padding: 0 24px;
  border-radius: 999px;
  font-size: 17px;
  font-weight: 700;
}

.btn-red {
  color: #fff;
  background: #b42318;
}

.btn-red:hover {
  background: #93190f;
}

.btn-grey {
  color: var(--text);
  background: var(--surface-2);
}

.btn-grey:hover {
  background: var(--border);
}

.btn-line {
  border: 1px solid var(--border-strong);
  color: var(--text);
  background: var(--surface);
}

.btn-line:hover {
  background: var(--surface-2);
}

.more {
  align-self: center;
}

/* Done and Muted & banned */
.list {
  margin: 0;
  padding: 6px 28px;
  list-style: none;
}

.list li {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 20px 0;
}

.list li + li {
  border-top: 1px solid var(--border);
}

.person {
  align-items: center !important;
}

.said {
  margin: 4px 0;
  font-size: 16px;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.tag {
  flex: none;
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 14px;
  font-weight: 700;
}

.tag.green {
  color: #157347;
  background: color-mix(in srgb, #1f9d55 16%, transparent);
}

.tag.grey {
  color: var(--text-2);
  background: var(--surface-2);
}

.tag.yellow {
  color: var(--warn-text);
  background: var(--warn-soft);
}

.tag.red {
  color: #b42318;
  background: color-mix(in srgb, #d92d20 14%, transparent);
}

.note {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
  padding: 16px 18px;
  border-radius: 12px;
  color: var(--text-2);
  background: var(--surface-2);
  font-size: 15px;
}

.note :deep(svg) {
  flex: none;
}

/* Nothing to review */
.clear {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 52px 24px;
  text-align: center;
}

.tick {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 50%;
  color: #157347;
  background: color-mix(in srgb, #1f9d55 16%, transparent);
}

.clear strong {
  font-size: 22px;
}

.clear p {
  max-width: 520px;
  margin: 0;
  color: var(--text-2);
  font-size: 16px;
  line-height: 1.6;
}

/* Loading */
.sk-card {
  padding: 12px 28px;
}

.sk {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 0;
}

.sk > .skeleton {
  flex: none;
  width: 48px;
  height: 48px;
  border-radius: 50%;
}

.sk > span:last-child {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8px;
}

.sk > span:last-child .skeleton:first-child {
  width: 35%;
  height: 16px;
}

.sk > span:last-child .skeleton:last-child {
  width: 75%;
  height: 13px;
}

/* Dialogs */
.dlg-title {
  margin: 0;
  padding: 28px 30px 0;
  font-size: 24px;
}

.dlg {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 12px 30px 28px;
}

.dlg-text {
  margin: 0;
  color: var(--text-2);
  font-size: 16px;
  line-height: 1.5;
}

.dlg-q {
  font-size: 16px;
}

.cards {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pick {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 14px 18px;
  border: 1px solid var(--border-strong);
  border-radius: 12px;
  cursor: pointer;
}

.pick input {
  position: absolute;
  opacity: 0;
}

.pick.on {
  padding: 13px 17px;
  border: 2px solid var(--accent);
  background: var(--accent-soft);
}

.pick:focus-within {
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 40%, transparent);
}

.pick .dot {
  flex: none;
  width: 22px;
  height: 22px;
  margin-top: 1px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
}

.pick.on .dot {
  border-color: var(--text);
  background: radial-gradient(circle, var(--surface) 0 28%, var(--text) 31%);
}

.pick > span:last-child {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.pick strong {
  font-size: 16px;
}

.pick small {
  color: var(--text-2);
  font-size: 15px;
  line-height: 1.45;
}

.dlg-act {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 6px;
}

/* Phones */
@media (max-width: 767px) {
  .mod {
    gap: 14px;
    padding: 18px 12px 32px;
  }

  .head h1 {
    font-size: 26px;
  }

  .tabs {
    width: 100%;
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: none;
  }

  .report {
    padding: 18px 16px;
  }

  .r-head {
    flex-wrap: wrap;
  }

  .hidden-tag {
    order: 3;
    width: 100%;
    justify-content: center;
  }

  .actions .btn-red,
  .actions .btn-grey {
    flex: 1;
  }

  .spacer {
    display: none;
  }

  .actions .btn-line {
    width: 100%;
  }

  .list {
    padding: 4px 16px;
  }

  .done {
    flex-wrap: wrap;
  }

  .done .tag {
    margin-left: 62px;
  }

  .person {
    flex-wrap: wrap;
    align-items: flex-start !important;
  }

  .person .btn-grey {
    width: 100%;
  }

  .dlg-act .btn-red {
    flex: 1.6;
  }

  .dlg-title {
    padding: 22px 20px 0;
    font-size: 21px;
  }

  .dlg {
    padding: 10px 20px calc(20px + env(safe-area-inset-bottom));
  }

  .dlg-act .btn-red,
  .dlg-act .btn-grey {
    flex: 1;
    padding: 0 14px;
  }
}

</style>
