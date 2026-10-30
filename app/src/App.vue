<script setup>
import { computed, defineAsyncComponent, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from './api.js'
import { cfg, feature, initialsOf } from './config.js'
import { startApp } from './lib/pwa.js'
import { tabs } from './router.js'
import Icon from './components/Icon.vue'
import Avatar from './components/Avatar.vue'
import HeaderActions from './components/HeaderActions.vue'
import SearchBox from './components/SearchBox.vue'
import LevelUpToast from './components/LevelUpToast.vue'
import ThemeSlot from './components/ThemeSlot.vue'
import Toast from './components/Toast.vue'
import ConfirmHost from './components/ConfirmHost.vue'
import MemberViewToggle from './components/MemberViewToggle.vue'
import { canSeeAsMember } from './lib/preview.js'
import { me, refreshStats } from './stores/me.js'
import { levelShown } from './lib/popups.js'
import { openChat } from './stores/chatDock.js'
import { messages, startPulse } from './stores/messages.js'
import { t, locale } from './lib/i18n.js'
import { useLatest } from './lib/useLatest.js'
import { video } from './lib/videoDock.js'

const PostDialog = defineAsyncComponent(() => import('./components/PostDialog.vue'))
// The dock pulls in the whole chat view, so it loads after the first paint.
const ChatDock = defineAsyncComponent(() => import('./components/ChatDock.vue'))
const VideoDock = defineAsyncComponent(() => import('./components/VideoDock.vue'))

const route = useRoute()

// Without an uploaded logo, the header shows the community's initials on the accent color:
// "MemBuild - Community" → "MB" (capitals inside the first word), "Growth Circle" → "GC".
const initials = computed(() => initialsOf(cfg.siteName))
const router = useRouter()
const immersive = computed(() => route.name === 'reels')
// A course being taken (not edited) is its own space, like Skool: no community column,
// and on phones a lesson's own bar (previous, Mark Complete, next) takes the tab bar's place.
const inCourse = computed(() => route.name === 'course' && !route.query.edit)
const building = computed(() => route.name === 'course' && !!route.query.edit)
// A profile spans the page (its cover is wide), like a course: no left column.
// Phones: the last tab (your photo) opens the menu instead of going to your profile.
const phoneMenuOpen = ref(false)
window.addEventListener('mbcom:phone-menu-state', (e) => (phoneMenuOpen.value = e.detail))
const openPhoneMenu = () => window.dispatchEvent(new CustomEvent('mbcom:phone-menu'))
// Pages without the left sidebar: profiles, your settings pages, Messages (its own chat list is the side column), an event (its own two columns), Places and the Leaderboard.
const onProfile = computed(() => ['member', 'me', 'profile-edit', 'privacy', 'billing', 'billing-item', 'emails', 'messages', 'event', 'places', 'leaderboard', 'levels', 'settings', 'search', 'moderation'].includes(route.name))
const inLesson = computed(() => inCourse.value && !!route.params.lessonId && !route.query.tab)
// Single-column pages sit at the exact centre of the window, as on Facebook: the right side
// reserves the same width as the left column. Grids and maps use the full width beside it.
const centred = computed(() => ['feed', 'notifications'].includes(route.name))
// Tabs follow the features switched on in settings (the lists update after a save).
const shown = computed(() => tabs.filter((tab) => !tab.feature || feature(tab.feature)))
const desktopTabs = computed(() => shown.value.filter((tab) => tab.name !== 'me' && !tab.mobileOnly))
// The phone tab bar keeps five tabs; the leaderboard is in the header there.
const mobileTabs = computed(() => shown.value.filter((tab) => !tab.desktopOnly))

// Docked chat windows on desktop; the Messages page already shows the chat itself.
const wide = window.matchMedia('(min-width: 768px)')
const isWide = ref(wide.matches)
wide.addEventListener('change', (e) => (isWide.value = e.matches))
const showDock = computed(() => isWide.value && feature('messages') && route.name !== 'messages')

/*
 * Facebook's desktop layout on wide screens: icons across the top (a label on
 * hover), and the same sections with their words down the left. Narrower
 * windows have no room for the column, so the top tabs keep their words there.
 */
const sideLinks = computed(() =>
  [
    feature('messages') && { to: '/messages', icon: 'messenger', label: t.__('Messages', 'membuild-community'), badge: messages.unread },
    cfg.community.canManage && { to: '/settings', icon: 'settings', label: t.__('Community settings', 'membuild-community') },
    cfg.community.isStaff && { to: '/moderation', icon: 'shield', label: t.__('Moderation', 'membuild-community'), badge: messages.reports },
  ].filter(Boolean),
)

// ?chat=ID opens that chat in the dock once (a welcome message, say). Phones have no
// dock and keep the unread badge instead; either way the address is cleaned up.
watch(
  () => route.query.chat,
  (id) => {
    if (!id) return
    if (showDock.value) openChat({ id: Number(id), title: '', type: 'direct', others: [] })
    const { chat, ...query } = route.query
    router.replace({ query })
  },
  { immediate: true },
)

// Any page can open a post on top of itself with ?post=ID (used by notifications).
const linked = ref(null)
const latestLink = useLatest()
watch(
  () => route.query.post,
  async (id) => {
    const current = latestLink() // following a second link quickly: the first answer is ignored
    if (!id) return (linked.value = null)
    const post = await api.post(Number(id)).catch(() => null)
    if (!current()) return
    linked.value = post
    if (!post) closeLinked()
  },
  { immediate: true },
)
function closeLinked() {
  const { post, comment, ...query } = route.query
  router.replace({ query })
}

// Loads my level and records today's visit (daily point and streak).
// The top tabs' words: if any word spills out of its tab (or the row out of the bar), show icons
// only. Checked again whenever the bar changes size.
// "Skip to content": focus the page's content, so the next Tab starts there.
function skipToMain() {
  document.getElementById('mbcom-main')?.focus()
}

const topTabs = ref(null)
const tight = ref(false)
function fitTabs() {
  const nav = topTabs.value
  if (!nav || !nav.offsetParent) return
  tight.value = false
  requestAnimationFrame(() => {
    const bar = nav.parentElement
    const spills = [...nav.children].some((tab) => tab.scrollWidth > tab.clientWidth + 1)
    tight.value = spills || nav.scrollWidth > nav.clientWidth + 1 || bar.scrollWidth > bar.clientWidth + 1
  })
}
onMounted(() => {
  if (!topTabs.value || !window.ResizeObserver) return
  const ro = new ResizeObserver(fitTabs)
  ro.observe(topTabs.value.parentElement)
  fitTabs()
})
watch(() => desktopTabs.value.length, () => nextTick(fitTabs))

onMounted(() => {
  refreshStats()
  startPulse() // unread badges on the Messenger and bell icons
  startApp() // installable app: worker, install offer, home-screen icon
})
// The muted notice, as one sentence with the end date when there is one.
const mutedNotice = computed(() => {
  const until = cfg.community.restriction?.until
  if (!until) return t.__("You're muted. You can read and react, but not post, comment or send messages.", 'membuild-community')
  const when = new Date(until).toLocaleString(locale, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
  return t.sprintf(/* translators: %s: date and time the mute ends */ t.__("You're muted until %s. You can read and react, but not post, comment or send messages.", 'membuild-community'), when)
})

// The unread count is part of the bell's name, so screen readers hear it too.
const tabLabel = (tab) =>
  tab.name === 'notifications' && messages.notifications
    ? /* translators: 1: a button's name, e.g. "Messenger" or "Notifications", 2: how many unread items */
      t.sprintf(t.__('%1$s (%2$d unread)', 'membuild-community'), tab.label, messages.notifications)
    : tab.label

const homeLabel = t.sprintf(/* translators: %s: the site's name */ t.__('%s home', 'membuild-community'), cfg.siteName)

// A course page belongs to the Classroom tab.
const isActive = (tab) =>
  route.name === tab.name || (tab.name === 'classroom' && route.name === 'course') || (tab.name === 'members' && route.name === 'member') || (tab.name === 'events' && route.name === 'event')
</script>

<template>
  <div class="shell" :class="{ immersive, 'in-course': inCourse, 'in-lesson': inLesson, building, 'on-profile': onProfile, 'no-tabbar': route.name === 'levels' || (route.name === 'settings' && !!route.query.s) || (route.name === 'messages' && !!route.params.threadId), 'phone-bare': route.name === 'search' || (route.name === 'messages' && !!route.params.threadId) }">
    <a class="skip-link" href="#mbcom-main" @click.prevent="skipToMain">{{ t.__('Skip to content', 'membuild-community') }}</a>
    <header class="topbar">
      <ThemeSlot name="app.top-bar" :data="{ tabs: desktopTabs, isActive, user: cfg.user, logo: cfg.community.logo, siteName: cfg.siteName, home: '/' }">
      <div class="topbar-inner">
        <div class="start">
          <RouterLink to="/" class="brand" :aria-label="homeLabel" :title="cfg.siteName">
            <img v-if="cfg.community.logo" :src="cfg.community.logo" alt="" class="logo" />
            <span v-else class="logo mark" aria-hidden="true">{{ initials }}</span>
          </RouterLink>
          <SearchBox v-if="feature('search')" />
        </div>
        <nav ref="topTabs" class="top-tabs" :class="{ tight }" :aria-label="t.__('Main sections', 'membuild-community')">
          <RouterLink v-for="tab in desktopTabs" :key="tab.name" :to="tab.path" class="top-tab" :class="{ active: isActive(tab) }" :title="tab.label" :aria-label="tab.label">
            <Icon :name="tab.icon" :size="24" />
            <span class="top-label">{{ tab.label }}</span>
          </RouterLink>
        </nav>
        <HeaderActions />
      </div>
      </ThemeSlot>
    </header>

    <div v-if="cfg.community.restriction" class="restricted" role="status">
      <Icon name="shield" :size="18" />
      <span v-if="cfg.community.restriction.status === 'muted'">
        {{ mutedNotice }}
      </span>
    </div>

    <aside class="side" :aria-label="t.__('Menu', 'membuild-community')">
      <ThemeSlot name="app.left-menu" :data="{ tabs: desktopTabs, tools: sideLinks, isActive, user: cfg.user }">
      <RouterLink to="/me" class="side-me">
        <Avatar :user="cfg.user" :size="36" decorative />
        <strong>{{ cfg.user.name }}</strong>
      </RouterLink>
      <nav class="side-nav" :aria-label="t.__('Sections', 'membuild-community')">
        <RouterLink v-for="tab in desktopTabs" :key="tab.name" :to="tab.path" class="side-link" :class="{ active: isActive(tab) }">
          <span class="side-ic"><Icon :name="tab.icon" :size="22" /></span>
          <span>{{ tab.label }}</span>
        </RouterLink>
      </nav>
      <nav v-if="sideLinks.length" class="side-nav tools" :aria-label="t.__('Running the community', 'membuild-community')">
        <RouterLink v-for="l in sideLinks" :key="l.to" :to="l.to" class="side-link" :class="{ active: route.path.startsWith(l.to) }">
          <span class="side-ic"><Icon :name="l.icon" :size="22" /></span>
          <span>{{ l.label }}</span>
          <span v-if="l.badge" class="side-badge">{{ l.badge > 9 ? '9+' : l.badge }}</span>
        </RouterLink>
      </nav>
      </ThemeSlot>
    </aside>

    <main id="mbcom-main" class="main" tabindex="-1" :class="{ flush: route.name === 'places', centred }">
      <RouterView v-slot="{ Component }">
        <KeepAlive :include="['FeedView', 'PlacesView']">
          <component :is="Component" />
        </KeepAlive>
      </RouterView>
    </main>

    <nav class="tabbar" :aria-label="t.__('Sections', 'membuild-community')">
      <ThemeSlot name="app.tab-bar" :data="{ tabs: mobileTabs, isActive, user: cfg.user, notifications: messages.notifications, openMenu: openPhoneMenu, menuOpen: phoneMenuOpen }">
      <!-- A short word under each icon: easier for everyone than icons alone. -->
      <template v-for="tab in mobileTabs" :key="tab.name">
        <!-- Your photo opens the menu (your profile, settings, display, log out are in it). -->
        <button v-if="tab.name === 'me'" type="button" data-phone-menu class="tab" :class="{ active: phoneMenuOpen || isActive(tab) }" :aria-label="t.__('Menu', 'membuild-community')" :aria-expanded="phoneMenuOpen" @click="openPhoneMenu">
          <span class="tab-icon"><span class="tab-avatar"><Avatar :user="cfg.user" :size="24" /></span></span>
          <span class="tab-label" aria-hidden="true">{{ t._x('Menu', 'phone tab', 'membuild-community') }}</span>
        </button>
        <RouterLink v-else :to="tab.path" class="tab" :class="{ active: isActive(tab) }" :aria-label="tabLabel(tab)">
          <span class="tab-icon">
            <Icon :name="tab.icon" :size="24" />
            <span v-if="tab.name === 'notifications' && messages.notifications" class="count">{{ messages.notifications > 9 ? '9+' : messages.notifications }}</span>
          </span>
          <span class="tab-label" aria-hidden="true">{{ tab.short || tab.label }}</span>
        </RouterLink>
      </template>
      </ThemeSlot>
    </nav>

    <PostDialog v-if="linked" :key="linked.id" :post="linked" :highlight="Number(route.query.comment) || 0" @close="closeLinked" />
    <ChatDock v-if="showDock" />
    <MemberViewToggle v-else-if="canSeeAsMember && !(!isWide && route.name === 'messages' && route.params.threadId)" /><!-- not over an open chat on a phone -->
    <VideoDock v-if="video.media" />
    <ThemeSlot name="app.level-up" :data="{ levelUp: levelShown ? me.levelUp : null, dismiss: () => (me.levelUp = null) }"><LevelUpToast /></ThemeSlot>
    <Toast />
    <ConfirmHost />
  </div>
</template>

<style scoped>
.topbar {
  position: sticky;
  top: 0;
  z-index: 20;
  /* Installed on an iPhone, the page runs under the status bar: keep the header clear of it. */
  height: calc(var(--header-h) + env(safe-area-inset-top));
  padding-top: env(safe-area-inset-top);
  background: color-mix(in srgb, var(--surface) 88%, transparent);
  backdrop-filter: saturate(1.6) blur(14px);
  border-bottom: 1px solid var(--border);
}

.topbar-inner {
  /* Full width, like Facebook: logo and search at the left edge, icons at the right. */
  height: 100%;
  padding: 0 16px;
  /* Equal side columns keep the tabs centred, whatever the logo and icons measure;
     minmax(0, …) lets a side shrink instead of pushing into the tabs. */
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: center;
  gap: 24px;
}

.topbar-inner > :last-child {
  justify-self: end;
}

.start {
  justify-self: start;
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  font-weight: 750;
  font-size: var(--fs-2xl);
  letter-spacing: -0.01em;
  color: var(--text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.logo {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-sm);
  object-fit: cover;
}

.mark {
  display: grid;
  place-items: center;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: var(--fs-xl);
  font-weight: 800;
  letter-spacing: -0.02em;
}

.top-tabs {
  display: flex;
  gap: 4px;
}

.top-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 8px 14px;
  border-radius: var(--radius-pill);
  color: var(--text-2);
  font-weight: 600;
}

/* Tablets and small laptops (no left column): seven words don't fit in a row, so each tab is an
   icon with its word underneath. Where even that's too tight (a long translation, extra tabs),
   the words step aside and the icons stay (.tight, measured below). */
@media (min-width: 768px) and (max-width: 1099px) {
  /* Tablets: every tab the same width, so the row reads as an even strip rather
     than words of different lengths jammed together. */
  .top-tabs {
    gap: 4px;
  }

  .top-tab {
    flex: 1 1 0;
    flex-direction: column;
    justify-content: center;
    gap: 3px;
    min-width: 44px;
    max-width: 78px;
    min-height: 50px;
    padding: 5px 4px;
    border-radius: var(--radius-md);
    font-size: var(--fs-2xs);
    line-height: 1.1;
    white-space: nowrap;
  }

  .topbar-inner {
    gap: 10px;
    padding: 0 10px;
    /* The sides keep just what their logo and icons need; the tabs get all the room between
       them (centred in it) and only drop their words if even that's too tight. */
    grid-template-columns: max-content minmax(0, 1fr) max-content;
  }

  .top-tabs {
    justify-content: center;
    min-width: 0;
  }

  .top-tab :deep(svg) {
    display: block;
    width: 22px;
    height: 22px;
  }
}

/* Measured too tight for words: icons only, the words still read out and shown on hover. */
.top-tabs.tight .top-tab {
  justify-content: center;
  min-height: 44px;
  padding: 8px 10px;
}

.top-tabs.tight .top-label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}

/* Wide screens: icons only up top (the words are in the left column), on a wider, taller target. */
@media (min-width: 1100px) {
  .top-tab {
    padding: 8px 22px;
  }

  .top-label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }
}

/* The left column: the same sections, with their words, plus account tools. */
.side {
  display: none;
}

@media (min-width: 1100px) {
  .side {
    position: fixed;
    top: calc(var(--header-h) + env(safe-area-inset-top));
    bottom: 0;
    left: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 272px;
    padding: 14px 10px 16px;
    overflow-y: auto;
  }

  .main {
    padding-left: 272px;
  }

  .main.centred {
    padding-right: 272px;
  }

  /* Reels too: the video gets the whole width; the top bar still leads everywhere. */
  .in-course .side,
  .building .side,
  .on-profile .side,
  .immersive .side {
    display: none;
  }

  .in-course .main,
  .building .main,
  .on-profile .main,
  .immersive .main {
    padding-left: 0;
  }
}

.side-me {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: var(--fs-base);
}

.side-me:hover,
.side-link:hover {
  background: var(--surface-2);
}

.side-nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.side-nav.tools {
  margin-top: 4px;
  padding-top: 10px;
  border-top: 1px solid var(--border);
}

.side-link {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 48px;
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: var(--fs-base);
  font-weight: 600;
}

.side-ic {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text);
}

.side-link.active {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.side-link.active .side-ic {
  background: var(--accent);
  color: var(--accent-ink);
}

.side-badge {
  margin-left: auto;
  min-width: 20px;
  padding: 0 6px;
  border-radius: var(--radius-pill);
  background: var(--danger);
  color: var(--on-color);
  font-size: var(--fs-xs);
  font-weight: 800;
  text-align: center;
  line-height: 20px;
}

.count {
  display: inline-grid;
  place-items: center;
  min-width: 18px;
  height: 18px;
  margin-left: 4px;
  padding: 0 5px;
  border-radius: var(--radius-pill);
  background: var(--danger);
  color: var(--on-color);
  font-size: var(--fs-2xs);
  font-weight: 800;
  vertical-align: 1px;
}

.restricted {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 16px;
  background: var(--warn-soft);
  color: var(--warn-text);
  font-size: var(--fs-md);
  font-weight: 600;
  text-align: center;
}

@media (prefers-color-scheme: dark) {
  .restricted {
    background: var(--warn-soft);
    color: var(--warn-text);
  }
}

/* Your own photo stands in for the Profile icon, with a ring when that tab is open. */
.tab-avatar {
  display: flex;
  border-radius: 50%;
  box-shadow: 0 0 0 2px transparent;
}

.tab.active .tab-avatar {
  box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text);
}

.tab-icon {
  position: relative;
  display: inline-flex;
}

.tab-label {
  margin-top: 3px;
  font-size: var(--fs-2xs);
  font-weight: 650;
  line-height: 1;
}

.tab-icon .count {
  position: absolute;
  top: -5px;
  right: -10px;
  margin: 0;
}

.top-tab:hover {
  background: var(--surface-2);
}

.top-tab.active {
  color: var(--accent-text);
  background: var(--accent-soft);
}

.main {
  padding-bottom: 24px;
}

/* The content takes focus from "Skip to content"; the ring belongs on what's inside. */
.main:focus {
  outline: none;
}

.immersive .main,
.main.flush {
  padding-bottom: 0;
}

.tabbar {
  display: none;
}

@media (max-width: 767px) {
  .top-tabs {
    display: none;
  }

  .topbar-inner {
    grid-template-columns: 1fr auto;
  }

  .topbar {
    height: calc(50px + env(safe-area-inset-top));
  }

  .main {
    padding-bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom) + 12px);
  }

  .tabbar {
    position: fixed;
    inset: auto 0 0;
    z-index: 30;
    display: grid;
    grid-auto-columns: 1fr;
    grid-auto-flow: column;
    height: calc(var(--tabbar-h) + env(safe-area-inset-bottom));
    padding-bottom: env(safe-area-inset-bottom);
    background: color-mix(in srgb, var(--surface) 92%, transparent);
    backdrop-filter: saturate(1.6) blur(14px);
    border-top: 1px solid var(--border);
  }

  /* Levels & points, a settings section and an open chat: their own page with a back arrow, no bottom bar. */
  .no-tabbar .tabbar {
    display: none;
  }

  .no-tabbar .main {
    padding-bottom: env(safe-area-inset-bottom);
  }

  /* Search, and an open chat, are their own full screen on a phone: ‹ back at the top, no header or bottom bar. */
  .phone-bare .topbar,
  .phone-bare .tabbar {
    display: none;
  }

  .phone-bare .main {
    padding-bottom: env(safe-area-inset-bottom);
  }

  .tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 0;
    background: none;
    color: var(--text-3);
    font: inherit;
    cursor: pointer;
  }

  .tab.active {
    color: var(--text);
  }

  /* In a course the page brings its own bottom bar (the lesson's, or Continue). */
  .in-course .tabbar {
    display: none;
  }

  .in-course .main {
    padding-bottom: calc(84px + env(safe-area-inset-bottom));
  }

  /* Reels take the whole screen, with a dark tab bar over the video. */
  .immersive .topbar {
    display: none;
  }

  .immersive .main {
    padding: 0;
  }

  .immersive .tabbar {
    background: var(--player-bg);
    border-top-color: var(--player-line);
  }

  .immersive .tab {
    color: var(--player-muted);
  }

  .immersive .tab.active {
    color: var(--on-color);
  }
}
</style>
