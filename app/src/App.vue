<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from './api.js'
import { cfg, feature, initialsOf } from './config.js'
import { startApp } from './lib/pwa.js'
import { tabs } from './router.js'
import Icon from './components/Icon.vue'
import Avatar from './components/Avatar.vue'
import HeaderActions from './components/HeaderActions.vue'
import LevelUpToast from './components/LevelUpToast.vue'
import { refreshStats } from './stores/me.js'
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
  const { post, ...query } = route.query
  router.replace({ query })
}

// Loads my level and records today's visit (daily point and streak).
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
  route.name === tab.name || (tab.name === 'classroom' && route.name === 'course') || (tab.name === 'members' && route.name === 'member')
</script>

<template>
  <div class="shell" :class="{ immersive }">
    <header class="topbar">
      <div class="topbar-inner">
        <RouterLink to="/" class="brand" :aria-label="homeLabel" :title="cfg.siteName">
          <img v-if="cfg.community.logo" :src="cfg.community.logo" alt="" class="logo" />
          <span v-else class="logo mark" aria-hidden="true">{{ initials }}</span>
        </RouterLink>
        <nav class="top-tabs" :aria-label="t.__('Sections', 'membuild-community')">
          <RouterLink v-for="tab in desktopTabs" :key="tab.name" :to="tab.path" class="top-tab" :class="{ active: isActive(tab) }">
            {{ tab.label }}
          </RouterLink>
        </nav>
        <HeaderActions />
      </div>
    </header>

    <div v-if="cfg.community.restriction" class="restricted" role="status">
      <Icon name="shield" :size="18" />
      <span v-if="cfg.community.restriction.status === 'muted'">
        {{ mutedNotice }}
      </span>
    </div>

    <main class="main">
      <RouterView v-slot="{ Component }">
        <KeepAlive include="FeedView">
          <component :is="Component" />
        </KeepAlive>
      </RouterView>
    </main>

    <nav class="tabbar" :aria-label="t.__('Sections', 'membuild-community')">
      <!-- A short word under each icon: easier for everyone than icons alone. -->
      <RouterLink v-for="tab in mobileTabs" :key="tab.name" :to="tab.path" class="tab" :class="{ active: isActive(tab) }" :aria-label="tabLabel(tab)">
        <span class="tab-icon">
          <span v-if="tab.name === 'me'" class="tab-avatar"><Avatar :user="cfg.user" :size="24" /></span>
          <Icon v-else :name="tab.icon" :size="24" />
          <span v-if="tab.name === 'notifications' && messages.notifications" class="count">{{ messages.notifications > 9 ? '9+' : messages.notifications }}</span>
        </span>
        <span class="tab-label" aria-hidden="true">{{ tab.short || tab.label }}</span>
      </RouterLink>
    </nav>

    <PostDialog v-if="linked" :key="linked.id" :post="linked" @close="closeLinked" />
    <ChatDock v-if="showDock" />
    <VideoDock v-if="video.media" />
    <LevelUpToast />
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
  max-width: 1080px;
  height: 100%;
  margin: 0 auto;
  padding: 0 16px;
  /* Equal side columns keep the tabs centred, whatever the logo and icons measure. */
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 24px;
}

.topbar-inner > :last-child {
  justify-self: end;
}

.brand {
  justify-self: start;
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  font-weight: 750;
  font-size: 18px;
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
  border-radius: 10px;
  object-fit: cover;
}

.mark {
  display: grid;
  place-items: center;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: 17px;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.top-tabs {
  display: flex;
  gap: 4px;
}

.top-tab {
  padding: 8px 14px;
  border-radius: 999px;
  color: var(--text-2);
  font-weight: 600;
}

.count {
  display: inline-grid;
  place-items: center;
  min-width: 18px;
  height: 18px;
  margin-left: 4px;
  padding: 0 5px;
  border-radius: 999px;
  background: var(--danger);
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  vertical-align: 1px;
}

.restricted {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 16px;
  background: #fff3d6;
  color: #8a5a00;
  font-size: 14px;
  font-weight: 600;
  text-align: center;
}

@media (prefers-color-scheme: dark) {
  .restricted {
    background: #3a2c0b;
    color: #f5c451;
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
  font-size: 11px;
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
  color: var(--text);
  background: var(--surface-2);
}

.main {
  padding-bottom: 24px;
}

.immersive .main {
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

  .tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-3);
  }

  .tab.active {
    color: var(--text);
  }

  /* Reels take the whole screen, with a dark tab bar over the video. */
  .immersive .topbar {
    display: none;
  }

  .immersive .main {
    padding: 0;
  }

  .immersive .tabbar {
    background: #000;
    border-top-color: #1d1d1f;
  }

  .immersive .tab {
    color: #8e8e93;
  }

  .immersive .tab.active {
    color: #fff;
  }
}
</style>
