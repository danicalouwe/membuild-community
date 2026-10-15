<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cfg, feature, levelLabel } from '../config.js'
import { messages } from '../stores/messages.js'
import { install, pwa } from '../lib/pwa.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'

const MessengerPanel = defineAsyncComponent(() => import('./MessengerPanel.vue'))
const InstallSheet = defineAsyncComponent(() => import('./InstallSheet.vue'))
const showInstall = ref(false)
async function installApp() {
  open.value = null
  if (!(await install())) showInstall.value = true
}
const NotificationsPanel = defineAsyncComponent(() => import('./NotificationsPanel.vue'))

// Facebook's top-right circles: the shortcuts grid, Messenger, notifications and the account menu.
// On phones the bell is a tab and Messenger opens the Messages page directly.
const route = useRoute()
const router = useRouter()
const open = ref(null) // 'shortcuts' | 'messenger' | 'notifications' | 'account' | null

// Links the admin picked in Appearance → Menus. Admins see the button even when
// the menu is empty, so they can find where to set it up.
const shortcuts = cfg.shortcuts?.items || []
const editShortcuts = cfg.shortcuts?.editUrl || ''
const initial = (title) => (title.trim()[0] || '•').toUpperCase()
const root = ref(null)
const phone = window.matchMedia('(max-width: 767px)')
const isPhone = ref(phone.matches)
phone.addEventListener?.('change', (e) => (isPhone.value = e.matches))

/*
 * The account links, described once: the avatar menu on computers, and the
 * grid "Menu" on phones (where there's no avatar menu), in the same order.
 * Places come first, then the site's own links, then account and admin tools.
 */
const places = computed(() =>
  [
    isPhone.value && { to: '/members', icon: 'users', label: t.__('Members', 'membuild-community') },
    feature('leaderboard') && { to: '/leaderboard', icon: 'trophy', label: t.__('Leaderboard', 'membuild-community') },
    isPhone.value && feature('places') && { to: '/places', icon: 'mapPin', label: t.__('Places', 'membuild-community') },
    feature('messages') && { to: '/messages', icon: 'messenger', label: t.__('Messages', 'membuild-community') },
  ].filter(Boolean),
)
const tools = computed(() =>
  [
    !pwa.standalone && { action: installApp, icon: 'download', label: t.__('Install app', 'membuild-community') },
    cfg.community.canManage && { to: '/settings', icon: 'settings', label: t.__('Community settings', 'membuild-community') },
    cfg.community.isStaff && { to: '/moderation', icon: 'shield', label: t.__('Moderation', 'membuild-community'), badge: messages.reports },
    cfg.adminUrl && { href: cfg.adminUrl, icon: 'dashboard', label: t.__('WordPress dashboard', 'membuild-community') },
    // The site's own home page (the community may have taken it over); its address says which site, whatever it's called.
    { href: cfg.siteUrl, icon: 'home', label: t.__('Main website', 'membuild-community'), note: siteHost },
    { href: cfg.logoutUrl, icon: 'logout', label: t.__('Log out', 'membuild-community') },
  ].filter(Boolean),
)

const siteHost = (() => {
  try {
    return new URL(cfg.siteUrl).host.replace(/^www\./, '')
  } catch {
    return ''
  }
})()

const cap = (n, max) => (n > max ? `${max}+` : n)

function toggle(which) {
  if (which === 'messenger' && phone.matches) {
    router.push({ name: 'messages' })
    return
  }
  open.value = open.value === which ? null : which
}

function onDoc(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = null
}
function onKey(e) {
  if (e.key !== 'Escape' || !open.value) return
  // Back to the button that opened the panel, so keyboard users don't lose their place.
  const trigger = root.value?.querySelector('[aria-expanded="true"]')
  open.value = null
  trigger?.focus({ preventScroll: true })
}

// Arrow keys move between the links of the shortcuts and account menus.
function onMenuKey(e) {
  const items = [...e.currentTarget.querySelectorAll('a[href], button')]
  const i = items.indexOf(document.activeElement)
  let next = null
  if (e.key === 'ArrowDown') next = items[(i + 1) % items.length]
  else if (e.key === 'ArrowUp') next = items[(i - 1 + items.length) % items.length]
  else if (e.key === 'Home') next = items[0]
  else if (e.key === 'End') next = items.at(-1)
  if (next) {
    e.preventDefault()
    next.focus()
  }
}
onMounted(() => {
  document.addEventListener('pointerdown', onDoc)
  document.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDoc)
  document.removeEventListener('keydown', onKey)
})
watch(() => route.fullPath, () => (open.value = null))

const reportsLabel = (n) => t.sprintf(/* translators: %d: number of reports waiting for a moderator */ t._n('%d report to review', '%d reports to review', n, 'membuild-community'), n)

// Badge counts belong in the buttons' names, so screen readers hear them too.
const withCount = (name, n) => (n ? t.sprintf(/* translators: 1: a button's name, e.g. "Messenger" or "Notifications", 2: how many unread items */ t.__('%1$s (%2$d unread)', 'membuild-community'), name, n) : name)
const accountLabel = () => {
  const name = t.__('Your account', 'membuild-community')
  return cfg.community.isStaff && messages.reports ? t.sprintf(/* translators: 1: "Your account", 2: e.g. "3 reports to review" */ t.__('%1$s, %2$s', 'membuild-community'), name, reportsLabel(messages.reports)) : name
}
const profileHint = () => t.sprintf(/* translators: %s: the member's level, e.g. "Level 3 · Builder" */ t.__('%s · See your profile', 'membuild-community'), levelLabel(cfg.user.level))
</script>

<template>
  <div ref="root" class="actions">

    <div v-if="isPhone || shortcuts.length || editShortcuts" class="slot">
      <button
        class="circle"
        :class="{ on: open === 'shortcuts' }"
        :aria-label="t.__('Menu', 'membuild-community')"
        :title="t.__('Menu', 'membuild-community')"
        :aria-expanded="open === 'shortcuts'"
        @click="toggle('shortcuts')"
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <rect v-for="(xy, i) in [[3,3],[10,3],[17,3],[3,10],[10,10],[17,10],[3,17],[10,17],[17,17]]" :key="i" :x="xy[0]" :y="xy[1]" width="4.5" height="4.5" rx="1.2" />
        </svg>
      </button>
      <div v-if="open === 'shortcuts'" class="menu card shortcuts" @keydown="onMenuKey">
        <h2>{{ t.__('Menu', 'membuild-community') }}</h2>
        <!-- Phones have no avatar menu, so the grid holds everything. -->
        <template v-if="isPhone">
          <RouterLink to="/me" class="who">
            <Avatar :user="cfg.user" :size="40" />
            <span>
              <strong>{{ cfg.user.name }}</strong>
              <small>{{ profileHint() }}</small>
            </span>
          </RouterLink>
          <component :is="i.to ? 'RouterLink' : 'a'" v-for="i in places" :key="i.label" :to="i.to" :href="i.href" class="item"><span class="ic"><Icon :name="i.icon" :size="18" /></span>
            <span v-if="i.note" class="text"><span>{{ i.label }}</span><small>{{ i.note }}</small></span>
            <template v-else>{{ i.label }}</template></component>
          <hr v-if="shortcuts.length" />
        </template>
        <template v-for="link in shortcuts" :key="link.id">
          <a :href="link.url" class="item" :target="link.newTab ? '_blank' : null" :rel="link.newTab ? 'noopener' : null">
            <span class="ic letter">{{ initial(link.title) }}</span>
            <span class="text">
              <span>{{ link.title }}</span>
              <small v-if="link.note">{{ link.note }}</small>
            </span>
            <Icon v-if="link.newTab" name="external" :size="14" class="out" />
          </a>
          <a v-for="sub in link.children" :key="sub.id" :href="sub.url" class="item sub" :target="sub.newTab ? '_blank' : null" :rel="sub.newTab ? 'noopener' : null">
            <span class="text">
              <span>{{ sub.title }}</span>
              <small v-if="sub.note">{{ sub.note }}</small>
            </span>
          </a>
        </template>
        <p v-if="!shortcuts.length" class="empty">{{ t.__('Add links to pages on your site, and they show up here for every member.', 'membuild-community') }}</p>
        <template v-if="isPhone">
          <hr />
          <component :is="i.action ? 'button' : i.to ? 'RouterLink' : 'a'" v-for="i in tools" :key="i.label" :to="i.to" :href="i.href" class="item" @click="i.action?.()">
            <span class="ic"><Icon :name="i.icon" :size="18" /></span>
            <span v-if="i.note" class="text"><span>{{ i.label }}</span><small>{{ i.note }}</small></span>
            <template v-else>{{ i.label }}</template>
            <span v-if="i.badge" class="badge">{{ i.badge }}</span>
          </component>
        </template>
        <template v-if="editShortcuts">
          <hr />
          <a :href="editShortcuts" class="item edit"><span class="ic"><Icon name="settings" :size="18" /></span>{{ shortcuts.length ? t.__('Edit this menu', 'membuild-community') : t.__('Set up this menu', 'membuild-community') }}</a>
        </template>
      </div>
    </div>

    <div v-if="feature('messages')" class="slot">
      <button
        class="circle"
        :class="{ on: open === 'messenger' || route.name === 'messages' }"
        :aria-label="withCount(t.__('Messenger', 'membuild-community'), messages.unread)"
        :title="t.__('Messenger', 'membuild-community')"
        :aria-expanded="open === 'messenger'"
        @click="toggle('messenger')"
      >
        <Icon name="messenger" :size="20" />
        <span v-if="messages.unread" class="count" aria-hidden="true">{{ cap(messages.unread, 99) }}</span>
      </button>
      <div v-if="open === 'messenger'" class="menu wide card">
        <MessengerPanel @navigate="open = null" />
      </div>
    </div>

    <div class="slot bell">
      <button
        class="circle"
        :class="{ on: open === 'notifications' }"
        :aria-label="withCount(t.__('Notifications', 'membuild-community'), messages.notifications)"
        :title="t.__('Notifications', 'membuild-community')"
        :aria-expanded="open === 'notifications'"
        @click="toggle('notifications')"
      >
        <Icon name="bell" :size="20" />
        <span v-if="messages.notifications" class="count" aria-hidden="true">{{ cap(messages.notifications, 99) }}</span>
      </button>
      <div v-if="open === 'notifications'" class="menu wide card">
        <NotificationsPanel @navigate="open = null" />
      </div>
    </div>

    <div class="slot account">
      <button class="me" :aria-label="accountLabel()" :aria-expanded="open === 'account'" @click="toggle('account')">
        <Avatar :user="cfg.user" :size="40" badge />
        <span v-if="cfg.community.isStaff && messages.reports" class="count report-dot" aria-hidden="true">{{ cap(messages.reports, 99) }}</span>
        <span class="chev"><Icon name="chevronDown" :size="12" /></span>
      </button>
      <div v-if="open === 'account'" class="menu card account-menu" @keydown="onMenuKey">
        <RouterLink to="/me" class="who">
          <Avatar :user="cfg.user" :size="40" />
          <span>
            <strong>{{ cfg.user.name }}</strong>
            <small>{{ profileHint() }}</small>
          </span>
        </RouterLink>
        <hr />
        <component :is="i.action ? 'button' : i.to ? 'RouterLink' : 'a'" v-for="i in [...places, ...tools]" :key="i.label" :to="i.to" :href="i.href" class="item" @click="i.action?.()">
          <span class="ic"><Icon :name="i.icon" :size="18" /></span>
            <span v-if="i.note" class="text"><span>{{ i.label }}</span><small>{{ i.note }}</small></span>
            <template v-else>{{ i.label }}</template>
          <span v-if="i.badge" class="badge">{{ i.badge }}</span>
        </component>
      </div>
    </div>
    <InstallSheet v-if="showInstall" @close="showInstall = false" />
  </div>
</template>

<style scoped>
.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.slot {
  position: relative;
}

.circle {
  position: relative;
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text);
}

.circle:hover {
  filter: brightness(0.96);
}

.circle.on {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.count {
  position: absolute;
  top: -5px;
  right: -6px;
  display: grid;
  place-items: center;
  min-width: 19px;
  height: 19px;
  padding: 0 5px;
  border-radius: 999px;
  background: var(--danger);
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  box-shadow: 0 0 0 2px var(--surface);
}

.me {
  position: relative;
  display: flex;
}

.chev {
  position: absolute;
  right: -2px;
  bottom: -2px;
  display: grid;
  place-items: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text);
  box-shadow: 0 0 0 2px var(--surface);
}

.menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 40;
  display: flex;
  flex-direction: column;
  max-height: min(78vh, 680px);
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.18), 0 2px 4px rgba(0, 0, 0, 0.08);
  overflow: hidden;
}

.wide {
  width: 360px;
}

.account-menu {
  width: 300px;
  padding: 8px;
}

.shortcuts {
  width: 320px;
  padding: 8px;
  overflow-y: auto;
}

.shortcuts h2 {
  margin: 4px 8px 6px;
  font-size: 20px;
}

.letter {
  background: var(--accent-soft);
  color: var(--accent-text);
  font-weight: 800;
}

.text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.text small {
  color: var(--text-3);
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.item.sub {
  padding: 6px 8px 6px 56px;
  font-weight: 550;
}

.out {
  margin-left: auto;
  color: var(--text-3);
}

.empty {
  margin: 0 8px 6px;
  color: var(--text-2);
  font-size: 14px;
}

.edit {
  color: var(--text-2);
}

.who {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px;
  border-radius: 10px;
  color: var(--text);
}

.who span {
  display: flex;
  flex-direction: column;
}

.who small {
  color: var(--text-3);
}

hr {
  width: 100%;
  margin: 6px 0;
  border: 0;
  border-top: 1px solid var(--border);
}

.item {
  width: 100%;
  text-align: left;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px;
  border-radius: 10px;
  font-weight: 600;
  color: var(--text);
}

.who:hover,
.item:hover {
  background: var(--surface-2);
}

.ic {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--surface-2);
}

/* The leaderboard sits in the tab row on desktop. */
@media (max-width: 767px) {
  .bell,
  .account {
    display: none;
  }

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

.report-dot {
  top: -4px;
  right: -4px;
}
</style>
