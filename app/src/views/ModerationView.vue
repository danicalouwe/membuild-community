<script setup>
import { onMounted, ref, watch } from 'vue'
import { askConfirm } from '../lib/confirm.js'
import { api } from '../api.js'
import { messages } from '../stores/messages.js'
import { removePost } from '../stores/feed.js'
import { timeAgo } from '../lib/time.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import { locale, t } from '../lib/i18n.js'

// Reports waiting for a decision, what was decided, and who is muted or banned.
const TABS = [
  { key: 'open', label: t.__('To review', 'membuild-community') },
  { key: 'resolved', label: t.__('Resolved', 'membuild-community') },
  { key: 'members', label: t.__('Muted & banned', 'membuild-community') },
]
const tab = ref('open')
const items = ref([])
const nextPage = ref(null)
const people = ref([])
const loading = ref(true)
const busy = ref('') // key of the card being acted on
const error = ref('')

const keyOf = (it) => `${it.object_type}-${it.object_id}`
const KINDS = {
  post: t.__('Post', 'membuild-community'),
  comment: t.__('Comment', 'membuild-community'),
  member: t.__('Profile', 'membuild-community'),
}
const kindLabel = (type) => KINDS[type] || KINDS.post
// One formatter for day and time, so each language orders and joins them its own way.
const fmtUntil = new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
const until = (iso) => (iso ? fmtUntil.format(new Date(iso)) : null)

/** "until Mar 3, 4:00 PM" or "until lifted". */
const untilText = (iso) =>
  iso
    ? /* translators: %s: date and time the restriction ends */
      t.sprintf(t.__('until %s', 'membuild-community'), until(iso))
    : t.__('until lifted', 'membuild-community')

/** The author's restriction tag: "Banned", "Muted" or "Muted until …". */
function restrictionTag(r) {
  if (r.status === 'banned') return t.__('Banned', 'membuild-community')
  if (!r.until) return t.__('Muted', 'membuild-community')
  return t.sprintf(/* translators: %s: date and time the mute ends */ t.__('Muted until %s', 'membuild-community'), until(r.until))
}

/** "Removed by Maya" / "Kept by a moderator". */
function resolvedBy(res) {
  const name = res.by?.name
  if (res.action === 'removed') {
    return name ? t.sprintf(/* translators: %s: name of the moderator */ t.__('Removed by %s', 'membuild-community'), name) : t.__('Removed by a moderator', 'membuild-community')
  }
  return name ? t.sprintf(/* translators: %s: name of the moderator */ t.__('Kept by %s', 'membuild-community'), name) : t.__('Kept by a moderator', 'membuild-community')
}

async function load(reset = true) {
  loading.value = true
  error.value = ''
  try {
    if (tab.value === 'members') {
      people.value = (await api.restricted()).items
    } else {
      const res = await api.modReports(tab.value, reset ? 1 : nextPage.value)
      items.value = reset ? res.items : [...items.value, ...res.items]
      nextPage.value = res.next_page
      messages.reports = res.open
    }
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(tab, () => load())

async function decide(it, action) {
  const ask = it.object_type === 'post' ? t.__('Remove this post for everyone?', 'membuild-community') : t.__('Remove this comment for everyone?', 'membuild-community')
  if (action === 'remove' && !(await askConfirm({ title: ask, confirm: t.__('Remove', 'membuild-community'), danger: true }))) return
  busy.value = keyOf(it)
  try {
    const res = await api.resolveReport(it.object_type, it.object_id, action)
    messages.reports = res.open
    items.value = items.value.filter((x) => x !== it)
    if (action === 'remove' && it.object_type === 'post') removePost(it.object_id)
  } catch (err) {
    alert(err.message)
  } finally {
    busy.value = ''
  }
}

async function restrict(user, status, days = 0, it = null) {
  let what
  if (status === 'banned') {
    what = t.sprintf(/* translators: %s: member's name */ t.__("Ban %s? They won't be able to open the community.", 'membuild-community'), user.name)
  } else if (status === 'muted' && days === 1) {
    what = t.sprintf(/* translators: %s: member's name */ t.__('Mute %s for 24 hours?', 'membuild-community'), user.name)
  } else if (status === 'muted' && days) {
    what = t.sprintf(/* translators: 1: member's name, 2: number of days */ t._n('Mute %1$s for %2$d day?', 'Mute %1$s for %2$d days?', days, 'membuild-community'), user.name, days)
  } else if (status === 'muted') {
    what = t.sprintf(/* translators: %s: member's name */ t.__('Mute %s?', 'membuild-community'), user.name)
  } else {
    what = t.sprintf(/* translators: %s: member's name */ t.__('Lift the restriction on %s?', 'membuild-community'), user.name)
  }
  if (!(await askConfirm({ title: what, confirm: status === 'none' ? t.__('Lift it', 'membuild-community') : status === 'banned' ? t.__('Ban', 'membuild-community') : t.__('Mute', 'membuild-community'), danger: status !== 'none' }))) return
  try {
    const res = await api.restrict(user.id, { status, days })
    if (it) it.author_restriction = res.restriction ? { status: res.restriction.status, until: res.restriction.until } : null
    if (tab.value === 'members') load()
  } catch (err) {
    alert(err.message)
  }
}
const byName = (name) => t.sprintf(/* translators: %s: name of the moderator */ t.__('by %s', 'membuild-community'), name)
const reportCount = (n) => t.sprintf(/* translators: %d: number of reports */ t._n('%d report', '%d reports', n, 'membuild-community'), n)
</script>

<template>
  <div class="mod">
    <header class="head">
      <h1><Icon name="shield" :size="24" /> {{ t.__('Moderation', 'membuild-community') }}</h1>
      <div class="tabs" role="tablist">
        <button v-for="tb in TABS" :key="tb.key" role="tab" :aria-selected="tab === tb.key" :class="{ on: tab === tb.key }" @click="tab = tb.key">
          {{ tb.label }}
          <span v-if="tb.key === 'open' && messages.reports" class="count">{{ messages.reports }}</span>
        </button>
      </div>
    </header>

    <p v-if="error" class="card state">{{ error }}</p>

    <!-- Muted and banned members -->
    <template v-else-if="tab === 'members'">
      <p v-if="!loading && !people.length" class="card state">{{ t.__('Nobody is muted or banned.', 'membuild-community') }}</p>
      <ul v-else class="people card">
        <li v-for="p in people" :key="p.user.id">
          <Avatar :user="p.user" :size="40" link />
          <span class="who">
            <RouterLink :to="{ name: 'member', params: { id: p.user.id } }"><strong>{{ p.user.name }}</strong></RouterLink>
            <small>
              <span class="tag" :class="p.status">{{ p.status === 'banned' ? t.__('Banned', 'membuild-community') : t.__('Muted', 'membuild-community') }}</span>
              {{ untilText(p.until) }}
              <template v-if="p.by"> · {{ byName(p.by.name) }}</template>
            </small>
          </span>
          <button class="btn" @click="restrict(p.user, 'none')">{{ t.__('Lift', 'membuild-community') }}</button>
        </li>
      </ul>
    </template>

    <!-- Reports -->
    <template v-else>
      <p v-if="!loading && !items.length" class="card state">
        {{ tab === 'open' ? t.__('No reports to review.', 'membuild-community') : t.__('No decisions yet.', 'membuild-community') }}
      </p>

      <article v-for="it in items" :key="keyOf(it)" class="card report" :class="{ busy: busy === keyOf(it) }">
        <header class="r-head">
          <span class="kind">{{ kindLabel(it.object_type) }}</span>
          <span class="n">{{ reportCount(it.reports) }}</span>
          <span v-if="it.preview.hidden" class="tag hidden">{{ t.__('Hidden until reviewed', 'membuild-community') }}</span>
          <span v-if="it.preview.gone" class="tag gone">{{ t.__('Already removed', 'membuild-community') }}</span>
          <span class="when">{{ timeAgo(it.last_at) }}</span>
        </header>

        <div class="reasons">
          <span v-for="r in it.reasons" :key="r.reason" class="reason">{{ r.label }}<b v-if="r.count > 1"> ×{{ r.count }}</b></span>
        </div>

        <div class="content">
          <div class="by">
            <Avatar :user="it.author" :size="32" link />
            <RouterLink v-if="it.author" :to="{ name: 'member', params: { id: it.author.id } }"><strong>{{ it.author.name }}</strong></RouterLink>
            <span v-if="it.author_restriction" class="tag" :class="it.author_restriction.status">
              {{ restrictionTag(it.author_restriction) }}
            </span>
          </div>
          <p v-if="it.preview.content" class="text">{{ it.preview.content }}</p>
          <img v-if="it.preview.thumb" :src="it.preview.thumb" alt="" class="thumb" loading="lazy" />
          <RouterLink v-if="it.object_type === 'member'" :to="{ name: 'member', params: { id: it.object_id } }" class="view">{{ t.__('View profile', 'membuild-community') }} <Icon name="chevronRight" :size="14" /></RouterLink>
          <RouterLink v-else-if="!it.preview.gone && !it.preview.hidden" :to="{ query: { post: it.post_id } }" class="view">{{ it.object_type === 'post' ? t.__('View post', 'membuild-community') : t.__('View in post', 'membuild-community') }} <Icon name="chevronRight" :size="14" /></RouterLink>
        </div>

        <ul v-if="it.notes.length" class="notes">
          <li v-for="(n, i) in it.notes" :key="i">“{{ n.note }}” <small>— {{ n.by?.name || t.__('A member', 'membuild-community') }}</small></li>
        </ul>

        <footer v-if="tab === 'open'" class="actions">
          <button class="btn" :disabled="!!busy" @click="decide(it, 'keep')">{{ it.object_type === 'member' ? t.__('Dismiss', 'membuild-community') : t.__('Keep', 'membuild-community') }}</button>
          <button v-if="!it.preview.gone && it.object_type !== 'member'" class="btn danger" :disabled="!!busy" @click="decide(it, 'remove')"><Icon name="trash" :size="16" /> {{ t.__('Remove', 'membuild-community') }}</button>
          <span class="spacer" />
          <template v-if="it.author && !it.author_is_staff">
            <template v-if="!it.author_restriction">
              <button class="btn ghost" @click="restrict(it.author, 'muted', 1, it)">{{ t.__('Mute 24h', 'membuild-community') }}</button>
              <button class="btn ghost" @click="restrict(it.author, 'muted', 7, it)">{{ t.__('Mute 7 days', 'membuild-community') }}</button>
              <button class="btn ghost danger" @click="restrict(it.author, 'banned', 0, it)">{{ t.__('Ban', 'membuild-community') }}</button>
            </template>
            <button v-else class="btn ghost" @click="restrict(it.author, 'none', 0, it)">{{ it.author_restriction.status === 'banned' ? t.__('Unban', 'membuild-community') : t.__('Unmute', 'membuild-community') }}</button>
          </template>
        </footer>
        <footer v-else-if="it.resolution" class="resolved">
          {{ resolvedBy(it.resolution) }} · {{ timeAgo(it.resolution.at) }}
        </footer>
      </article>

      <button v-if="nextPage" class="btn more" :disabled="loading" @click="load(false)">{{ t.__('Show more', 'membuild-community') }}</button>
    </template>
  </div>
</template>

<style scoped>
.mod {
  max-width: 720px;
  margin: 0 auto;
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.head h1 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 10px;
  font-size: 24px;
}

.tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.tabs button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border-radius: 999px;
  font-weight: 650;
  font-size: 14px;
  color: var(--text-2);
}

.tabs button.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.count {
  display: inline-grid;
  place-items: center;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--danger);
  color: #fff;
  font-size: 11px;
  font-weight: 800;
}

.state {
  padding: 28px;
  text-align: center;
  color: var(--text-3);
}

.report {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 16px;
  transition: opacity 0.15s;
}

.report.busy {
  opacity: 0.5;
}

.r-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 13px;
}

.kind {
  font-weight: 750;
}

.n {
  color: var(--danger);
  font-weight: 700;
}

.when {
  margin-left: auto;
  color: var(--text-3);
}

.tag {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--surface-2);
  font-size: 12px;
  font-weight: 700;
  color: var(--text-2);
}

.tag.hidden,
.tag.muted {
  background: #fff3d6;
  color: #8a5a00;
}

.tag.banned {
  background: #fde2e2;
  color: #b42318;
}

.reasons {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.reason {
  padding: 4px 10px;
  border-radius: 999px;
  border: 1px solid var(--border);
  font-size: 13px;
  font-weight: 600;
}

.content {
  padding: 12px;
  border-radius: 10px;
  background: var(--surface-2);
}

.by {
  display: flex;
  align-items: center;
  gap: 8px;
}

.by a {
  color: var(--text);
}

.text {
  margin: 8px 0 0;
  white-space: pre-line;
  word-break: break-word;
}

.thumb {
  display: block;
  max-width: 220px;
  max-height: 160px;
  margin-top: 8px;
  border-radius: 8px;
  object-fit: cover;
}

.view {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin-top: 8px;
  font-size: 14px;
  font-weight: 650;
}

.notes {
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 14px;
  color: var(--text-2);
}

.notes li + li {
  margin-top: 4px;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.spacer {
  flex: 1;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 10px;
  background: var(--surface-2);
  font-weight: 650;
  font-size: 14px;
}

.btn.danger {
  color: var(--danger);
}

.btn.ghost {
  background: transparent;
  border: 1px solid var(--border);
}

.resolved {
  font-size: 13px;
  color: var(--text-3);
}

.people {
  list-style: none;
  margin: 0;
  padding: 6px;
}

.people li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px;
}

.who {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.who a {
  color: var(--text);
}

.who small {
  color: var(--text-3);
}

.more {
  align-self: center;
}

@media (prefers-color-scheme: dark) {
  .tag.hidden,
  .tag.muted {
    background: #3a2c0b;
    color: #f5c451;
  }

  .tag.banned {
    background: #3d1414;
    color: #ff8a80;
  }
}

@media (max-width: 767px) {
  .mod {
    padding: 12px 0;
  }

  .head {
    padding: 0 12px;
  }

  .report,
  .state,
  .people {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }
}
</style>
