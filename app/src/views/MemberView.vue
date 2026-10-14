<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg, feature, levelLabel } from '../config.js'
import { me, refreshStats } from '../stores/me.js'
import { loadMore, memberList } from '../stores/feed.js'
import { canDock, openChat } from '../stores/chatDock.js'
import { timeAgo } from '../lib/time.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import PostCard from '../components/PostCard.vue'
const MemberGroupsDialog = defineAsyncComponent(() => import('../components/MemberGroupsDialog.vue'))
import { around, t, locale } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { useMenu } from '../lib/useMenu.js'

const EditProfileDialog = defineAsyncComponent(() => import('../components/EditProfileDialog.vue'))

// A member's profile: who they are, their level, and their posts.
// Your own profile adds your streak, how to earn points and recent points.
const props = defineProps({ id: { type: Number, required: true } })
const router = useRouter()

const member = ref(null)
const error = ref('')
const editing = ref(false)
const messaging = ref(false)
const list = computed(() => memberList(props.id))
const isMe = computed(() => props.id === cfg.user.id)

const joined = computed(() =>
  member.value ? new Date(member.value.joined_at).toLocaleDateString(locale, { month: 'long', year: 'numeric' }) : '',
)
const progress = computed(() => Math.max(3, Math.min(100, member.value?.level_progress ?? 0)))

const latest = useLatest()
async function load() {
  const current = latest() // hopping between profiles: a slower, older one never shows up
  error.value = ''
  member.value = null
  try {
    const profile = await api.member(props.id)
    if (current()) member.value = profile
  } catch (err) {
    if (current()) error.value = err.message
  }
  if (current() && !list.value.loaded) loadMore(list.value)
}

watch(() => props.id, load)
onMounted(() => {
  load()
  if (isMe.value) refreshStats()
})

function saved(profile) {
  member.value = profile
  // My name and photo appear all over the app; update them everywhere at once.
  Object.assign(cfg.user, { name: profile.name, avatar: profile.avatar })
  editing.value = false
}

const restrictionText = computed(() => {
  const r = member.value?.restriction
  if (!r) return ''
  if (!r.until) return r.status === 'banned' ? t.__('Banned', 'membuild-community') : t.__('Muted', 'membuild-community')
  const when = new Date(r.until).toLocaleString(locale, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
  return r.status === 'banned'
    ? /* translators: %s: date and time the ban ends */
      t.sprintf(t.__('Banned until %s', 'membuild-community'), when)
    : /* translators: %s: date and time the mute ends */
      t.sprintf(t.__('Muted until %s', 'membuild-community'), when)
})

/* "12 points to Level 4", split around the number so it can be bold: [before, after]. */
const toNext = computed(() => {
  const m = member.value
  if (!m?.next_level) return ['', '']
  const text = /* translators: 1: number of points still needed, 2: the next level, e.g. "Level 4" */ t._n('%1$s point to %2$s', '%1$s points to %2$s', m.to_next, 'membuild-community')
  return around(text.replace('%2$s', levelLabel(m.next_level)))
})

const joinedText = computed(() => t.sprintf(/* translators: %s: when the member joined, e.g. "March 2026" */ t.__('Joined %s', 'membuild-community'), joined.value))
const postsText = computed(() => t.sprintf(/* translators: %d: number of posts */ t._n('%d post', '%d posts', member.value?.posts_count ?? 0, 'membuild-community'), member.value?.posts_count ?? 0))
const pointsText = computed(() => t.sprintf(/* translators: %s: number of points */ t._n('%s point', '%s points', member.value?.points ?? 0, 'membuild-community'), (member.value?.points ?? 0).toLocaleString(locale)))

async function restrict(status, days = 0) {
  const name = member.value.name
  let ask
  if (status === 'banned') {
    ask = t.sprintf(/* translators: %s: member's name */ t.__("Ban %s? They won't be able to open the community.", 'membuild-community'), name)
  } else if (status === 'muted' && days === 1) {
    ask = t.sprintf(/* translators: %s: member's name */ t.__('Mute %s for 24 hours?', 'membuild-community'), name)
  } else if (status === 'muted' && days) {
    ask = t.sprintf(/* translators: 1: member's name, 2: number of days */ t._n('Mute %1$s for %2$d day?', 'Mute %1$s for %2$d days?', days, 'membuild-community'), name, days)
  } else if (status === 'muted') {
    ask = t.sprintf(/* translators: %s: member's name */ t.__('Mute %s until you lift it?', 'membuild-community'), name)
  } else {
    ask = t.sprintf(/* translators: %s: member's name */ t.__('Lift the restriction on %s?', 'membuild-community'), name)
  }
  if (!confirm(ask)) return
  closeModMenu()
  try {
    const res = await api.restrict(props.id, { status, days })
    member.value.restriction = res.restriction
  } catch (err) {
    alert(err.message)
  }
}
const { open: modMenu, root: modMenuRoot, trigger: modMenuTrigger, list: modMenuList, toggle: toggleModMenu, close: closeModMenu, onKeydown: onModMenuKey } = useMenu()

// The "…" menu on someone else's profile: block or unblock them, or report the profile.
const { open: moreMenu, root: moreRoot, trigger: moreTrigger, list: moreList, toggle: toggleMore, close: closeMore, onKeydown: onMoreKey } = useMenu()
const ReportDialog = defineAsyncComponent(() => import('../components/ReportDialog.vue'))
const reporting = ref(false)

async function setBlocked(on) {
  closeMore()
  const name = member.value.name
  if (on && !confirm(t.sprintf(/* translators: %s: member's name */ t.__('Block %s? You won’t see each other’s posts, comments or stories, and you can’t message each other. They aren’t told.', 'membuild-community'), name))) return
  try {
    await (on ? api.block(member.value.id) : api.unblock(member.value.id))
    member.value.blocked = on
    member.value.can_message = !on && member.value.can_message
    if (!on) load()
  } catch (err) {
    alert(err.message)
  }
}
const showGroups = ref(false) // admins: the member's access groups

async function message() {
  if (messaging.value) return
  messaging.value = true
  try {
    const chat = await api.startChat([props.id])
    if (canDock()) openChat(chat)
    else router.push({ name: 'messages', params: { threadId: chat.id } })
  } catch (err) {
    alert(err.message)
  } finally {
    messaging.value = false
  }
}
const streakLabel = (days) => t.sprintf(/* translators: %d: number of days in a row the member visited */ t._n('%d-day streak', '%d-day streak', days, 'membuild-community'), days)
const bestStreak = (days) => t.sprintf(/* translators: %d: the member's longest streak, in days */ t.__('Best: %d days', 'membuild-community'), days)
const rankLabel = (rank) => t.sprintf(/* translators: %d: the member's position on the leaderboard */ t._x('#%d', 'leaderboard rank', 'membuild-community'), rank)
const dailyCap = (n) => t.sprintf(/* translators: %d: how many times a day this action earns points */ t.__('up to %d× a day', 'membuild-community'), n)
</script>

<template>
  <div class="profile">
    <p v-if="error" class="card state">{{ error }}</p>

    <section v-else class="card hero">
      <div class="cover" />
      <div class="pic">
        <Avatar v-if="member" :user="{ ...member, avatar: member.avatar_large }" :size="128" />
        <span v-else class="skeleton" style="width: 128px; height: 128px; border-radius: 50%" />
      </div>

      <template v-if="member">
        <h1>
          {{ member.name }}
          <span v-if="member.role" class="role">{{ member.role === 'admin' ? t.__('Admin', 'membuild-community') : t.__('Moderator', 'membuild-community') }}</span>
        </h1>
        <p class="lvl">{{ levelLabel(member.level) }}</p>
        <p v-if="restrictionText" class="restricted-tag">{{ restrictionText }}</p>
        <p v-if="member.bio" class="bio">{{ member.bio }}</p>
        <p v-else-if="isMe" class="bio empty">{{ t.__('Add a short bio so people know who you are.', 'membuild-community') }}</p>
        <p class="meta">
          {{ joinedText }} · {{ postsText }} · {{ pointsText }}
        </p>

        <div class="actions">
          <button v-if="member.is_me" class="btn" @click="editing = true"><Icon name="compose" :size="16" /> {{ t.__('Edit profile', 'membuild-community') }}</button>
          <button v-if="!member.is_me && member.can_message" class="btn btn-primary" :disabled="messaging" @click="message"><Icon name="messenger" :size="16" /> {{ t.__('Message', 'membuild-community') }}</button>
          <button v-if="cfg.community.canManage && !member.is_me && feature('classroom')" class="btn" @click="showGroups = true"><Icon name="users" :size="16" /> {{ t.__('Groups', 'membuild-community') }}</button>
          <span v-if="!member.is_me && member.can_block" :ref="moreRoot" class="mod-wrap">
            <button :ref="moreTrigger" class="btn icon-only" :aria-label="t.__('More options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="moreMenu" @click="toggleMore"><Icon name="more" :size="18" /></button>
            <span v-if="moreMenu" :ref="moreList" class="mod-menu" role="menu" @keydown="onMoreKey">
              <button v-if="member.can_block" role="menuitem" @click="setBlocked(!member.blocked)">{{ member.blocked ? t.__('Unblock', 'membuild-community') : t.__('Block', 'membuild-community') }}</button>
              <button role="menuitem" @click="closeMore(); reporting = true">{{ t.__('Report profile', 'membuild-community') }}</button>
            </span>
          </span>
          <span v-if="member.can_restrict" :ref="modMenuRoot" class="mod-wrap">
            <button :ref="modMenuTrigger" class="btn" :aria-label="t.__('Moderation options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="modMenu" @click="toggleModMenu"><Icon name="shield" :size="16" /> {{ t.__('Moderate', 'membuild-community') }}</button>
            <span v-if="modMenu" :ref="modMenuList" class="mod-menu" role="menu" @keydown="onModMenuKey">
              <template v-if="!member.restriction">
                <button role="menuitem" @click="restrict('muted', 1)">{{ t.__('Mute for 24 hours', 'membuild-community') }}</button>
                <button role="menuitem" @click="restrict('muted', 7)">{{ t.__('Mute for 7 days', 'membuild-community') }}</button>
                <button role="menuitem" @click="restrict('muted', 30)">{{ t.__('Mute for 30 days', 'membuild-community') }}</button>
                <button role="menuitem" @click="restrict('muted', 0)">{{ t.__('Mute until I lift it', 'membuild-community') }}</button>
                <button role="menuitem" class="danger" @click="restrict('banned', 0)">{{ t.__('Ban from the community', 'membuild-community') }}</button>
              </template>
              <button v-else role="menuitem" @click="restrict('none')">{{ member.restriction.status === 'banned' ? t.__('Unban', 'membuild-community') : t.__('Unmute', 'membuild-community') }}</button>
            </span>
          </span>
        </div>

        <div class="next">
          <div class="track"><i :style="{ width: `${progress}%` }" /></div>
          <p v-if="member.next_level">
            {{ toNext[0] }}<strong>{{ member.to_next }}</strong>{{ toNext[1] }}
          </p>
          <p v-else>{{ t.__('Top level reached. Legend! 🏆', 'membuild-community') }}</p>
        </div>

        <div v-if="isMe && me.stats" class="tiles">
          <div class="tile streak">
            <strong><Icon name="flame" :size="18" /> {{ streakLabel(me.stats.streak) }}</strong>
            <span>{{ bestStreak(me.stats.best_streak) }}</span>
          </div>
          <component :is="feature('leaderboard') ? 'RouterLink' : 'div'" to="/leaderboard" class="tile" :class="{ link: feature('leaderboard') }">
            <strong>{{ me.stats.rank_30 ? rankLabel(me.stats.rank_30) : '—' }}</strong>
            <span>{{ t.__('rank this month', 'membuild-community') }}</span>
          </component>
        </div>
      </template>
    </section>

    <MemberGroupsDialog v-if="showGroups" :member="member" @close="showGroups = false" />
    <ReportDialog v-if="reporting" type="member" :id="member.id" @close="reporting = false" />


    <section v-if="isMe && me.stats" class="card block">
      <h2>{{ t.__('How to earn points', 'membuild-community') }}</h2>
      <ul class="rules">
        <li v-for="r in me.stats.rules" :key="r.reason">
          <span>{{ r.label }}</span>
          <span v-if="r.daily_cap" class="muted">{{ dailyCap(r.daily_cap) }}</span>
          <strong>+{{ r.points }}</strong>
        </li>
      </ul>
    </section>

    <section v-if="isMe && me.stats?.recent.length" class="card block">
      <h2>{{ t.__('Recent points', 'membuild-community') }}</h2>
      <ul class="recent">
        <li v-for="(r, i) in me.stats.recent" :key="i">
          <span>{{ r.label }}</span>
          <span class="muted">{{ timeAgo(r.at) }}</span>
          <strong :class="{ neg: r.points < 0 }">{{ r.points > 0 ? '+' : '' }}{{ r.points }}</strong>
        </li>
      </ul>
    </section>

    <h2 class="posts-title">{{ t.__('Posts', 'membuild-community') }}</h2>
    <PostCard v-for="p in list.items" :key="p.id" :post="p" />
    <p v-if="list.loaded && !list.items.length" class="card state">{{ isMe ? t.__("You haven't posted yet.", 'membuild-community') : t.__('No posts yet.', 'membuild-community') }}</p>
    <button v-if="list.hasMore && list.loaded" class="btn more" :disabled="list.loading" @click="loadMore(list)">
      {{ list.loading ? t.__('Loading…', 'membuild-community') : t.__('See more posts', 'membuild-community') }}
    </button>

    <EditProfileDialog v-if="editing && member" :member="member" @close="editing = false" @saved="saved" />
  </div>
</template>

<style scoped>
.restricted-tag {
  margin: 8px 0 0;
  padding: 3px 10px;
  border-radius: 999px;
  background: #fff3d6;
  color: #8a5a00;
  font-size: 13px;
  font-weight: 700;
}

.btn.icon-only {
  justify-content: center;
  width: 40px;
  padding: 0;
}

.mod-wrap {
  position: relative;
}

.mod-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 50%;
  z-index: 15;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  min-width: 220px;
  padding: 6px;
  border-radius: 10px;
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: 0 8px 24px rgb(0 0 0 / 20%);
  text-align: left;
}

.mod-menu button {
  padding: 9px 10px;
  border-radius: 8px;
  font-weight: 600;
  text-align: left;
}

.mod-menu button:hover {
  background: var(--surface-2);
}

.mod-menu .danger {
  color: var(--danger);
}

.badge {
  margin-left: auto;
  min-width: 22px;
  height: 22px;
  padding: 0 7px;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: var(--danger);
  color: #fff;
  font-size: 12px;
  font-weight: 800;
}

.profile {
  max-width: 680px;
  margin: 0 auto;
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.state {
  padding: 28px;
  text-align: center;
  color: var(--text-3);
}

.hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0 20px 20px;
  text-align: center;
  /* No overflow clipping here: the Moderate menu has to be able to extend past the card. */
}

.cover {
  align-self: stretch;
  height: 120px;
  margin: 0 -20px;
  border-radius: calc(var(--radius) - 1px) calc(var(--radius) - 1px) 0 0;
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 45%, #ff8fb1));
}

.pic {
  margin-top: -64px;
  border-radius: 50%;
  box-shadow: 0 0 0 4px var(--surface);
  line-height: 0;
}

.hero h1 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 12px 0 0;
  font-size: 24px;
}

.role {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: 12px;
  font-weight: 700;
}

.lvl {
  margin: 2px 0 0;
  color: var(--accent-text);
  font-weight: 700;
}

.bio {
  max-width: 460px;
  margin: 10px 0 0;
  white-space: pre-line;
}

.bio.empty {
  color: var(--text-3);
}

.meta {
  margin: 8px 0 0;
  font-size: 14px;
  color: var(--text-3);
}

.actions {
  display: flex;
  gap: 8px;
  margin-top: 14px;
}

.actions:empty {
  display: none;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 16px;
  border-radius: 10px;
  font-weight: 650;
}

.btn:not(.btn-primary) {
  background: var(--surface-2);
}

.next {
  width: min(360px, 100%);
  margin-top: 16px;
}

.next p {
  margin: 8px 0 0;
  font-size: 14px;
  color: var(--text-2);
}

.track {
  height: 10px;
  border-radius: 999px;
  background: var(--surface-2);
  overflow: hidden;
}

.track i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: linear-gradient(90deg, var(--accent), #8c6bff);
  transition: width 0.5s ease;
}

.tiles {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  width: 100%;
  margin-top: 18px;
}

.tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 12px 8px;
  border-radius: 12px;
  background: var(--surface-2);
  color: var(--text);
}

.tile strong {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 20px;
}

.tile span {
  font-size: 12px;
  color: var(--text-3);
}

.streak strong {
  color: #ff7a2f;
}

.tile.link:hover {
  background: var(--border);
}

.block {
  padding: 16px 18px;
}

.block h2 {
  margin: 0 0 10px;
  font-size: 16px;
}

.rules,
.recent {
  list-style: none;
  margin: 0;
  padding: 0;
}

.rules li,
.recent li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  border-top: 1px solid var(--border);
  font-size: 14px;
}

.rules li span:first-child,
.recent li span:first-child {
  flex: 1;
}

.rules strong,
.recent strong {
  color: #2fb36b;
  min-width: 34px;
  text-align: right;
}

.recent strong.neg {
  color: var(--danger);
}

.muted {
  color: var(--text-3);
  font-size: 12px;
}

.posts-title {
  margin: 8px 4px 0;
  font-size: 18px;
}

.more {
  align-self: center;
}

@media (max-width: 767px) {
  .cover {
    border-radius: 0;
  }

  .profile {
    padding: 0 0 16px;
  }

  .hero,
  .block,
  .state {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }

  .posts-title {
    margin: 8px 12px 0;
  }
}
</style>
