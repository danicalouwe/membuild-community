<script setup>
import { startAttachment } from '../lib/attachments.js'
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { cfg, feature, levelLabel } from '../config.js'
import { me, refreshStats } from '../stores/me.js'
import { loadMore, memberList } from '../stores/feed.js'
import { canDock, openChat } from '../stores/chatDock.js'
import { timeAgo } from '../lib/time.js'
import Avatar from '../components/Avatar.vue'
import Icon from '../components/Icon.vue'
import PostCard from '../components/PostCard.vue'
import Composer from '../components/Composer.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import BaseDialog from '../components/BaseDialog.vue'
const MemberGroupsDialog = defineAsyncComponent(() => import('../components/MemberGroupsDialog.vue'))
import { around, t, locale } from '../lib/i18n.js'
import { useLatest } from '../lib/useLatest.js'
import { useMenu } from '../lib/useMenu.js'


// A member's profile: who they are, their level, and their posts.
// Your own profile adds your streak, how to earn points and recent points.
const props = defineProps({ id: { type: Number, required: true } })
const router = useRouter()
const route = useRoute()

const member = ref(null)
const error = ref('')
const messaging = ref(false)
const list = computed(() => memberList(props.id))
const isMe = computed(() => props.id === cfg.user.id)
// Edit profile is its own page now; older links (?edit=1, ?edit=emails) still get there.
watch(
  () => route.query.edit,
  (edit) => edit && router.replace({ name: edit === 'emails' ? 'emails' : 'profile-edit' }),
  { immediate: true },
)
const editProfile = () => router.push({ name: 'profile-edit' })

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

// Moderate: the same Mute or ban choices as Moderation, in our own dialog (never a little menu).
const moderating = ref(false)
const modChoice = ref('mute7')
const MOD_CHOICES = {
  mute1: { status: 'muted', days: 1 },
  mute7: { status: 'muted', days: 7 },
  mute: { status: 'muted', days: 0 },
  ban: { status: 'banned', days: 0 },
}
const until7 = computed(() => new Date(Date.now() + 7 * 864e5).toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' }))
const modButton = computed(
  () =>
    ({
      mute1: t.__('Mute for 1 day', 'membuild-community'),
      mute7: t.__('Mute for 7 days', 'membuild-community'),
      mute: t.__('Mute until I unmute', 'membuild-community'),
      ban: t.__('Ban from the community', 'membuild-community'),
    })[modChoice.value],
)
const modBusy = ref(false)
async function restrict(status, days = 0) {
  modBusy.value = true
  try {
    const res = await api.restrict(props.id, { status, days })
    member.value.restriction = res.restriction
    moderating.value = false
  } catch (err) {
    alert(err.message)
  } finally {
    modBusy.value = false
  }
}

// The "…" menu on someone else's profile: block or unblock them, or report the profile.
const { open: moreMenu, root: moreRoot, trigger: moreTrigger, list: moreList, toggle: toggleMore, close: closeMore, onKeydown: onMoreKey } = useMenu()
const ReportDialog = defineAsyncComponent(() => import('../components/ReportDialog.vue'))
const reporting = ref(false)

// Blocking asks first, in our own dialog.
const blocking = ref(false)
async function setBlocked(on) {
  closeMore()
  blocking.value = false
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

// Your own profile: a new cover or photo uploads as soon as it's picked, then goes on the
// profile. Pointing at either shows a camera (on phones the cameras are always there).
const coverUpload = ref(null)
const photoUpload = ref(null)
const coverInput = ref(null)
const photoInput = ref(null)
function pickCover(e) {
  pickPicture(e, 'cover', coverUpload)
}
function pickPhoto(e) {
  pickPicture(e, 'avatar', photoUpload)
}
function pickPicture(e, key, slot) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file?.type.startsWith('image/')) return
  slot.value?.cancel()
  const up = startAttachment(file)
  slot.value = up
  const stop = watch(
    () => up.status,
    async (st) => {
      if (st === 'working') return
      stop()
      if (st === 'done') {
        try {
          const saved = await api.updateProfile({ [key]: { id: up.media.id } })
          member.value = { ...member.value, ...saved }
          if (key === 'avatar') Object.assign(cfg.user, { avatar: saved.avatar })
        } catch (err) {
          alert(err.message)
        }
      } else alert(up.error || t.__('The picture didn’t upload. Try again.', 'membuild-community'))
      up.release()
      if (slot.value === up) slot.value = null
    },
  )
}

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
// A new member's own profile: Finish your profile, until all four are done.
const checklist = computed(() => {
  const m = member.value
  if (!m?.is_me) return []
  return [
    { key: 'photo', done: m.has_photo, label: t.__('Add a photo', 'membuild-community'), hint: t.__('Point at your picture and click the camera', 'membuild-community') },
    { key: 'bio', done: !!m.bio, label: t.__('Write a short bio', 'membuild-community'), hint: t.__('What you do, in a line or two', 'membuild-community') },
    { key: 'cover', done: !!m.cover, label: t.__('Add a cover photo', 'membuild-community'), hint: t.__('Point at the top of your profile and click the camera', 'membuild-community') },
    { key: 'post', done: m.posts_count > 0, label: t.__('Say hello in Home', 'membuild-community'), hint: t.__('Your first post', 'membuild-community') },
  ]
})
const checklistDone = computed(() => checklist.value.filter((c) => c.done).length)
function doStep(key) {
  if (key === 'photo') photoInput.value?.click()
  else if (key === 'bio') editProfile()
  else if (key === 'cover') coverInput.value?.click()
  else router.push({ name: 'feed' })
}
const allRules = ref(false) // How to earn points: the first six, then See all
const firstName = computed(() => (member.value?.name || '').split(' ')[0])
const livesIn = computed(() => around(t.__('Lives in %s', 'membuild-community')))
const bothTaking = computed(() => around(t.__('You’re both taking %s', 'membuild-community')))

const streakLabel = (days) => t.sprintf(/* translators: %d: number of days in a row the member visited */ t._n('%d-day streak', '%d-day streak', days, 'membuild-community'), days)
const bestStreak = (days) => t.sprintf(/* translators: %d: the member's longest streak, in days */ t.__('Best: %d days', 'membuild-community'), days)
const rankLabel = (rank) => t.sprintf(/* translators: %d: the member's position on the leaderboard */ t._x('#%d', 'leaderboard rank', 'membuild-community'), rank)
const dailyCap = (n) => t.sprintf(/* translators: %d: how many times a day this action earns points */ t.__('up to %d× a day', 'membuild-community'), n)
</script>

<template>
  <div class="profile" :class="{ blocked: member?.blocked }">
    <p v-if="error" class="card state">{{ error }}</p>

    <template v-else>
      <!-- The top: a wide cover, the photo over its edge, name and level, bio, 52px buttons. -->
      <section class="hero">
        <div class="hero-in">
          <div class="cover" :style="member?.cover ? { backgroundImage: `url(${member.cover})` } : null">
            <label v-if="member?.is_me" class="cam cover-cam" :title="t.__('Change cover picture', 'membuild-community')">
              <input ref="coverInput" type="file" accept="image/*" hidden @change="pickCover" />
              <Icon name="camera" :size="20" />
              <span class="sr-only">{{ t.__('Change cover picture', 'membuild-community') }}</span>
            </label>
            <span v-if="coverUpload" class="busy">{{ t.__('Uploading…', 'membuild-community') }}</span>
          </div>

          <div class="head">
            <div class="pic">
              <Avatar v-if="member" :user="{ ...member, avatar: member.avatar_large }" :size="168" :online="!member.is_me && !member.blocked" />
              <span v-else class="skeleton" style="width: 168px; height: 168px; border-radius: 50%" />
              <label v-if="member?.is_me" class="cam photo-cam" :title="t.__('Change your photo', 'membuild-community')">
                <input ref="photoInput" type="file" accept="image/*" hidden @change="pickPhoto" />
                <Icon name="camera" :size="24" />
                <span class="sr-only">{{ t.__('Change your photo', 'membuild-community') }}</span>
              </label>
              <span v-if="photoUpload" class="busy photo-busy">{{ t.__('Uploading…', 'membuild-community') }}</span>
            </div>

            <div v-if="member" class="who">
              <h1>
                {{ member.name }}
                <span v-if="member.role && !member.blocked" class="role">{{ member.role === 'admin' ? t.__('Admin', 'membuild-community') : t.__('Moderator', 'membuild-community') }}</span>
              </h1>
              <p class="lvl">{{ levelLabel(member.level) }}</p>
              <template v-if="!member.blocked">
                <p v-if="restrictionText" class="restricted-tag">{{ restrictionText }}</p>
                <p v-if="member.bio" class="bio">{{ member.bio }}</p>
                <p v-else-if="isMe" class="bio empty">{{ t.__('Add a short bio so people know who you are.', 'membuild-community') }}</p>

                <div class="actions">
                  <button v-if="member.is_me" class="btn btn-lg btn-ghost" @click="editProfile"><Icon name="pencil" :size="18" /> {{ t.__('Edit profile', 'membuild-community') }}</button>
                  <button v-if="!member.is_me && member.can_message" class="btn btn-lg btn-primary msg" :disabled="messaging" @click="message"><Icon name="comment" :size="18" /> {{ t.__('Message', 'membuild-community') }}</button>
                  <button v-if="cfg.community.canManage && !member.is_me && feature('classroom')" class="btn btn-lg btn-ghost" @click="showGroups = true"><Icon name="lock" :size="18" /> {{ t.__('Manage membership', 'membuild-community') }}</button>
                  <button v-if="member.can_restrict && !member.restriction" class="btn btn-lg btn-ghost" @click="modChoice = 'mute7'; moderating = true"><Icon name="shield" :size="18" /> {{ t.__('Moderate', 'membuild-community') }}</button>
                  <button v-else-if="member.can_restrict" class="btn btn-lg btn-ghost" :disabled="modBusy" @click="restrict('none')"><Icon name="shield" :size="18" /> {{ member.restriction.status === 'banned' ? t.__('Unban', 'membuild-community') : t.__('Unmute', 'membuild-community') }}</button>
                  <span v-if="!member.is_me && member.can_block" :ref="moreRoot" class="more-wrap">
                    <button :ref="moreTrigger" class="btn btn-lg btn-ghost round" :aria-label="t.__('More options', 'membuild-community')" aria-haspopup="menu" :aria-expanded="moreMenu" @click="toggleMore"><Icon name="more" :size="20" /></button>
                    <span v-if="moreMenu" :ref="moreList" class="more-menu card" role="menu" @keydown="onMoreKey">
                      <button role="menuitem" @click="closeMore(); blocking = true">{{ t.__('Block', 'membuild-community') }}</button>
                      <button role="menuitem" @click="closeMore(); reporting = true">{{ t.__('Report profile', 'membuild-community') }}</button>
                    </span>
                  </span>
                </div>
              </template>
            </div>
          </div>
        </div>
      </section>

      <!-- Someone you blocked: name only, what blocking means, and Unblock. -->
      <section v-if="member?.blocked" class="card blocked-note">
        <h2>{{ t.sprintf(/* translators: %s: member's first name */ t.__('You blocked %s', 'membuild-community'), firstName) }}</h2>
        <p>{{ t.__('You won’t see each other’s posts, comments or stories, and you can’t message each other. They aren’t told.', 'membuild-community') }}</p>
        <button class="btn btn-lg btn-ghost" @click="setBlocked(false)">{{ t.__('Unblock', 'membuild-community') }}</button>
      </section>

      <div v-else-if="member" class="cols">
        <aside class="left">
          <section class="card box">
            <h2>{{ t.__('About', 'membuild-community') }}</h2>
            <ul class="about">
              <li v-if="member.location"><Icon name="mapPin" :size="20" /> <span>{{ livesIn[0] }}<b>{{ member.location }}</b>{{ livesIn[1] }}</span></li>
              <li><Icon name="calendar" :size="20" /> <span>{{ joinedText }}</span></li>
              <li v-if="!member.location && isMe" class="add"><Icon name="mapPin" :size="20" /> <span>{{ t.__('Add where you live', 'membuild-community') }} <button class="link" @click="editProfile">{{ t.__('Add', 'membuild-community') }}</button></span></li>
              <li v-if="member.shared_course"><Icon name="cap" :size="20" /> <span>{{ bothTaking[0] }}<b>{{ member.shared_course }}</b>{{ bothTaking[1] }}</span></li>
            </ul>
          </section>

          <section class="card box">
            <h2>{{ levelLabel(member.level) }}</h2>
            <div class="track"><i :style="{ width: `${progress}%` }" /></div>
            <p class="small">
              {{ pointsText }} ·
              <template v-if="member.next_level">{{ toNext[0] }}{{ member.to_next }}{{ toNext[1] }}</template>
              <template v-else>{{ t.__('Top level reached. Legend! 🏆', 'membuild-community') }}</template>
            </p>
            <div v-if="isMe && me.stats" class="tiles">
              <div class="tile">
                <strong>{{ streakLabel(me.stats.streak) }}</strong>
                <span>{{ bestStreak(me.stats.best_streak) }}</span>
              </div>
              <component :is="feature('leaderboard') ? 'RouterLink' : 'div'" to="/leaderboard" class="tile">
                <strong>{{ me.stats.rank_30 ? rankLabel(me.stats.rank_30) : '—' }}</strong>
                <span>{{ t.__('rank this month', 'membuild-community') }}</span>
              </component>
            </div>
          </section>

          <section v-if="isMe && me.stats" class="card box">
            <h2>{{ t.__('How to earn points', 'membuild-community') }}</h2>
            <ul class="rules">
              <li v-for="r in allRules ? me.stats.rules : me.stats.rules.slice(0, 6)" :key="r.reason">
                <span>{{ r.label }}</span>
                <strong>+{{ r.points }}</strong>
              </li>
            </ul>
            <button v-if="!allRules && me.stats.rules.length > 6" class="link see-all" @click="allRules = true">{{ t.__('See all', 'membuild-community') }}</button>
          </section>
        </aside>

        <div class="right">
          <Composer v-if="isMe && cfg.community.canPost && !cfg.community.restriction" />

          <section v-if="isMe && checklistDone < checklist.length" class="card box check">
            <div class="check-head">
              <h2>{{ t.__('Finish your profile', 'membuild-community') }}</h2>
              <span>{{ t.sprintf(/* translators: 1: steps done, 2: all steps */ t.__('%1$d of %2$d done', 'membuild-community'), checklistDone, checklist.length) }}</span>
            </div>
            <p class="small">{{ t.__('People say hello more to profiles with a photo and a line about them.', 'membuild-community') }}</p>
            <ul class="steps">
              <li v-for="c in checklist" :key="c.key">
                <button class="step" :class="{ done: c.done }" :disabled="c.done" @click="doStep(c.key)">
                  <span class="tick"><Icon v-if="c.done" name="check" :size="16" /></span>
                  <span class="step-text"><strong>{{ c.label }}</strong><small v-if="!c.done">{{ c.hint }}</small></span>
                  <Icon v-if="!c.done" name="chevronRight" :size="18" />
                </button>
              </li>
            </ul>
          </section>

          <section class="card posts-head"><h2>{{ t.__('Posts', 'membuild-community') }}</h2></section>
          <PostCard v-for="p in list.items" :key="p.id" :post="p" />

          <section v-if="list.loaded && !list.items.length" class="card none">
            <span class="none-icon"><Icon name="pencil" :size="26" /></span>
            <template v-if="isMe">
              <strong>{{ t.__('You haven’t posted yet', 'membuild-community') }}</strong>
              <p>{{ t.__('Introduce yourself in Home: what you’re working on and what you’d like help with.', 'membuild-community') }}</p>
              <RouterLink to="/" class="btn btn-lg btn-primary">{{ t.__('Write your first post', 'membuild-community') }}</RouterLink>
            </template>
            <template v-else>
              <strong>{{ t.sprintf(/* translators: %s: member's first name */ t.__('%s hasn’t posted yet', 'membuild-community'), firstName) }}</strong>
              <p>{{ t.__('When they do, their posts show up here. A friendly hello often helps someone get started.', 'membuild-community') }}</p>
              <button v-if="member.can_message" class="btn btn-lg btn-ghost" :disabled="messaging" @click="message">{{ t.__('Send a message', 'membuild-community') }}</button>
            </template>
          </section>
          <button v-if="list.hasMore && list.loaded" class="btn btn-lg btn-ghost more" :disabled="list.loading" @click="loadMore(list)">
            {{ list.loading ? t.__('Loading…', 'membuild-community') : t.__('See more posts', 'membuild-community') }}
          </button>
        </div>
      </div>
    </template>

    <MemberGroupsDialog v-if="showGroups" :member="member" @close="showGroups = false" />
    <ReportDialog v-if="reporting" type="member" :id="member.id" @close="reporting = false" />

    <ConfirmDialog
      v-if="blocking"
      :title="t.sprintf(/* translators: %s: member's name */ t.__('Block %s?', 'membuild-community'), member.name)"
      :confirm="t.__('Block', 'membuild-community')"
      danger
      @confirm="setBlocked(true)"
      @close="blocking = false"
    >
      {{ t.__('You won’t see each other’s posts, comments or stories, and you can’t message each other. They aren’t told.', 'membuild-community') }}
    </ConfirmDialog>

    <!-- Moderate: the same Mute or ban dialog as Moderation. -->
    <BaseDialog v-if="moderating" :label="t.sprintf(t.__('Mute or ban %s', 'membuild-community'), member.name)" :width="580" style="border-radius: 24px" @close="moderating = false">
      <template #header><h2 class="dlg-title">{{ t.sprintf(/* translators: %s: member's name */ t.__('Mute or ban %s', 'membuild-community'), member.name) }}</h2></template>
      <div class="dlg">
        <p class="small">{{ t.__('Nothing they’ve already posted is removed. You can undo this any time in Moderation › Muted & banned.', 'membuild-community') }}</p>
        <label class="choice" :class="{ on: modChoice === 'mute1' }"><input v-model="modChoice" type="radio" value="mute1" /><span><strong>{{ t.__('Mute for 1 day', 'membuild-community') }}</strong><small>{{ t.__('Can read and react, but can’t post, comment or send messages until tomorrow.', 'membuild-community') }}</small></span></label>
        <label class="choice" :class="{ on: modChoice === 'mute7' }"><input v-model="modChoice" type="radio" value="mute7" /><span><strong>{{ t.__('Mute for 7 days', 'membuild-community') }}</strong><small>{{ t.sprintf(/* translators: %s: a date */ t.__('The same, until %s.', 'membuild-community'), until7) }}</small></span></label>
        <label class="choice" :class="{ on: modChoice === 'mute' }"><input v-model="modChoice" type="radio" value="mute" /><span><strong>{{ t.__('Mute until I unmute', 'membuild-community') }}</strong><small>{{ t.__('The same, with no end date.', 'membuild-community') }}</small></span></label>
        <label class="choice" :class="{ on: modChoice === 'ban' }"><input v-model="modChoice" type="radio" value="ban" /><span><strong>{{ t.__('Ban from the community', 'membuild-community') }}</strong><small>{{ t.__('Can’t open the community at all.', 'membuild-community') }}</small></span></label>
        <div class="dlg-actions">
          <button class="btn btn-lg btn-ghost" @click="moderating = false">{{ t.__('Cancel', 'membuild-community') }}</button>
          <button class="btn btn-lg red" :disabled="modBusy" @click="restrict(MOD_CHOICES[modChoice].status, MOD_CHOICES[modChoice].days)">{{ modButton }}</button>
        </div>
      </div>
    </BaseDialog>
  </div>
</template>

<style scoped>
/* ---------- The top band: cover, photo, name, buttons ---------- */
.profile {
  padding: 0 16px 24px;
}

.hero {
  margin: 0 -16px;
  padding-bottom: 22px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}

.hero-in {
  max-width: 1010px;
  margin: 0 auto;
}

.cover {
  position: relative;
  height: 330px;
  border-radius: 0 0 14px 14px;
  background: var(--cover-default);
  background-size: cover;
  background-position: center;
}

.head {
  display: flex;
  align-items: flex-start;
  gap: 22px;
  padding: 0 16px;
}

.pic {
  position: relative;
  flex: none;
  margin-top: -60px;
  padding: 4px;
  border-radius: 50%;
  background: var(--surface);
}

.who {
  flex: 1;
  min-width: 0;
  padding-top: 16px;
}

h1 {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin: 0;
  font-size: 32px;
  line-height: 1.2;
}

.role {
  padding: 2px 10px;
  border-radius: 999px;
  background: var(--text);
  color: var(--surface);
  font-size: 13px;
  font-weight: 700;
}

.lvl {
  margin: 4px 0 0;
  color: var(--accent-text);
  font-size: 17px;
  font-weight: 700;
}

.bio {
  margin: 8px 0 0;
  font-size: 17px;
  line-height: 1.5;
}

.bio.empty {
  color: var(--text-2);
}

.restricted-tag {
  display: inline-block;
  margin: 8px 0 0;
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: 13px;
  font-weight: 700;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 16px;
}

.actions .btn {
  gap: 8px;
  padding: 0 22px;
  border-radius: 999px;
  font-size: 17px;
  font-weight: 700;
}

.actions .round {
  width: 52px;
  padding: 0;
  justify-content: center;
}

.more-wrap {
  position: relative;
}

.more-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  z-index: 20;
  display: flex;
  flex-direction: column;
  min-width: 200px;
  padding: 6px;
}

.more-menu button {
  min-height: 44px;
  padding: 0 12px;
  border: 0;
  border-radius: 8px;
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.more-menu button:hover {
  background: var(--surface-2);
}

/* Cameras on your own profile: show when you point at the cover or photo. */
.cam {
  position: absolute;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: rgb(0 0 0 / 50%);
  color: #fff;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.15s;
}

.cover-cam {
  right: 16px;
  bottom: 16px;
  width: 52px;
  height: 52px;
}

.photo-cam {
  inset: 4px;
}

.cover:hover .cover-cam,
.pic:hover .photo-cam,
.cam:focus-within {
  opacity: 1;
}

.busy {
  position: absolute;
  left: 16px;
  bottom: 16px;
  padding: 4px 12px;
  border-radius: 999px;
  background: rgb(0 0 0 / 55%);
  color: #fff;
  font-size: 14px;
  font-weight: 650;
}

.photo-busy {
  left: 50%;
  bottom: 8px;
  transform: translateX(-50%);
  white-space: nowrap;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}

/* ---------- Below: About and Level on the left, posts on the right ---------- */
.cols {
  display: grid;
  grid-template-columns: 380px minmax(0, 1fr);
  gap: 16px;
  max-width: 976px;
  margin: 16px auto 0;
}

.left,
.right {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

.left {
  align-self: start;
}

.box {
  padding: 20px;
}

.box h2,
.posts-head h2 {
  margin: 0 0 14px;
  font-size: 20px;
}

.posts-head {
  padding: 18px 20px 4px;
}

.about {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 16px;
}

.about li {
  display: flex;
  align-items: center;
  gap: 12px;
}

.about :deep(svg) {
  flex: none;
  color: var(--text-2);
}

.about .add {
  color: var(--text-2);
}

.link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--accent-text);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
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
  background: var(--accent);
}

.small {
  margin: 10px 0 0;
  color: var(--text-2);
  font-size: 14px;
}

.tiles {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 14px;
}

.tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 14px 8px;
  border-radius: 12px;
  background: var(--surface-2);
  color: var(--text);
  text-decoration: none;
}

.tile strong {
  font-size: 19px;
}

.tile span {
  color: var(--text-2);
  font-size: 14px;
}

.rules {
  margin: 0;
  padding: 0;
  list-style: none;
}

.rules li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 0;
  border-top: 1px solid var(--border);
  font-size: 15px;
}

.rules strong {
  color: var(--ok-text);
}

.see-all {
  margin-top: 10px;
}

/* Finish your profile */
.check-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.check-head h2 {
  margin: 0;
}

.check-head span {
  color: var(--text-2);
  font-size: 14px;
}

.steps {
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
}

.steps li {
  border-top: 1px solid var(--border);
}

.step {
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  min-height: 64px;
  padding: 10px 0;
  border: 0;
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.step:disabled {
  cursor: default;
}

.tick {
  display: grid;
  flex: none;
  place-items: center;
  width: 28px;
  height: 28px;
  border: 2px solid var(--border-strong);
  border-radius: 50%;
}

.step.done .tick {
  border-color: #2fb36b;
  background: #2fb36b;
  color: #fff;
}

.step-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
}

.step-text strong {
  font-size: 17px;
}

.step.done strong {
  color: var(--text-2);
  text-decoration: line-through;
}

.step-text small {
  color: var(--text-2);
  font-size: 15px;
}

/* No posts yet */
.none {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 44px 24px;
  text-align: center;
}

.none-icon {
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.none strong {
  font-size: 20px;
}

.none p {
  max-width: 420px;
  margin: 0 0 6px;
  color: var(--text-2);
  font-size: 16px;
  line-height: 1.5;
}

.more {
  align-self: center;
}

.blocked-note {
  max-width: 528px;
  margin: 24px auto 0;
  padding: 28px;
  text-align: center;
}

.blocked-note h2 {
  margin: 0 0 10px;
  font-size: 20px;
}

.blocked-note p {
  margin: 0 0 18px;
  color: var(--text-2);
  font-size: 16px;
  line-height: 1.5;
}

.state {
  padding: 28px;
  text-align: center;
  color: var(--text-2);
}

/* Moderate */
.dlg-title {
  margin: 0;
  padding: 30px 30px 0;
  font-size: 24px;
}

.dlg {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 10px 30px 28px;
}

.dlg > .small {
  margin: 0 0 6px;
  font-size: 16px;
  line-height: 1.5;
}

.choice {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid var(--border);
  border-radius: 12px;
  cursor: pointer;
}

.choice.on {
  border: 2px solid var(--accent);
  background: var(--accent-soft);
}

.choice input {
  width: 22px;
  height: 22px;
  margin: 2px 0 0;
  accent-color: var(--text);
}

.choice span {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.choice strong {
  font-size: 17px;
}

.choice small {
  color: var(--text-2);
  font-size: 15px;
}

.dlg-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 10px;
}

.red {
  background: #c0281f;
  color: #fff;
}

/* ---------- Phones: one column in the same order ---------- */
@media (max-width: 899px) {
  .cover {
    height: 180px;
    border-radius: 0;
  }

  .head {
    flex-direction: column;
    gap: 6px;
  }

  .pic :deep(.avatar-wrap) {
    width: 128px !important;
    height: 128px !important;
  }

  .who {
    width: 100%;
    padding-top: 0;
  }

  h1 {
    font-size: 26px;
  }

  .actions .msg {
    flex: 1;
    justify-content: center;
  }

  /* No hover on phones: the cameras stay. */
  .cam {
    opacity: 1;
  }

  .cover-cam {
    width: 44px;
    height: 44px;
  }

  .photo-cam {
    inset: auto 6px 6px auto;
    width: 40px;
    height: 40px;
    border: 2px solid var(--surface);
  }

  .cols {
    grid-template-columns: 1fr;
    margin-top: 10px;
  }
}
</style>
