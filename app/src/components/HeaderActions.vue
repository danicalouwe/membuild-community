<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cfg, feature } from '../config.js'
import { messages } from '../stores/messages.js'
import { install, pwa } from '../lib/pwa.js'
import { display, setDisplay } from '../lib/display.js'
import LookBar from './LookBar.vue'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import ThemeSlot from './ThemeSlot.vue'
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
// Phones only: the sections that don't fit in the bottom bar. On computers they're in the top bar.
const places = computed(() =>
  isPhone.value
    ? [
        { to: '/members', icon: 'users', label: t.__('Members', 'membuild-community') },
        feature('events') && { to: '/events', icon: 'calendar', label: t.__('Events', 'membuild-community') },
        feature('leaderboard') && { to: '/leaderboard', icon: 'trophy', label: t.__('Leaderboard', 'membuild-community') },
        feature('places') && { to: '/places', icon: 'mapPin', label: t.__('Places', 'membuild-community') },
        feature('messages') && { to: '/messages', icon: 'messenger', label: t.__('Messages', 'membuild-community') },
      ].filter(Boolean)
    : [],
)
// Version 2 menu: Your community (owners and moderators), then You, then the app, the site and Log out.
const community = computed(() =>
  [
    cfg.community.isStaff && { to: '/moderation', icon: 'shield', label: t.__('Moderation', 'membuild-community'), note: messages.reports ? reportsNeed(messages.reports) : '', badge: messages.reports },
    cfg.community.canManage && { to: '/settings', icon: 'settings', label: t.__('Community settings', 'membuild-community') },
    cfg.adminUrl && { href: cfg.adminUrl, icon: 'wordpress', label: t.__('WordPress dashboard', 'membuild-community') },
  ].filter(Boolean),
)
const you = computed(() => [
  { to: { name: 'profile-edit' }, icon: 'pencil', label: t.__('Edit profile', 'membuild-community') },
  { to: { name: 'emails' }, icon: 'mail', label: t.__('Emails you get', 'membuild-community') },
  // Billing: only while memberships are sold with FluentCart (cfg.billing is null otherwise).
  ...(cfg.billing !== null && cfg.billing !== undefined ? [{ to: { name: 'billing' }, icon: 'card', label: t.__('Billing', 'membuild-community') }] : []),
  // Privacy: whether others see when you're online (only while the owner shows who's online).
  ...(cfg.community.features?.online !== false && cfg.community.features?.messages !== false ? [{ to: { name: 'privacy' }, icon: 'eye', label: t.__('Privacy', 'membuild-community') }] : []),
])
const after = computed(() =>
  [
    !pwa.standalone && { action: installApp, icon: 'download', label: t.__('Add to your phone', 'membuild-community') },
    // The site's own home page (the community may have taken it over).
    { href: cfg.siteUrl, icon: 'home', label: t.__('Main website', 'membuild-community') },
    { href: cfg.logoutUrl, icon: 'logout', label: t.__('Log out', 'membuild-community') },
  ].filter(Boolean),
)
const reportsNeed = (n) => t.sprintf(/* translators: %d: reports waiting */ t._n('%d report needs you', '%d reports need you', n, 'membuild-community'), n)

const cap = (n, max) => (n > max ? `${max}+` : n)

function toggle(which) {
  if (which === 'messenger' && phone.matches) {
    router.push({ name: 'messages' })
    return
  }
  open.value = open.value === which ? null : which
}

function onDoc(e) {
  if (e.target.closest?.('[data-phone-menu]')) return // the phone tab bar's Menu tab toggles it itself
  if (open.value && root.value && !root.value.contains(e.target)) open.value = null
}
// Phones: the Menu tab in the bottom bar opens this menu (there's no grid button up top).
const onPhoneMenu = () => toggle('shortcuts')
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
  window.addEventListener('mbcom:phone-menu', onPhoneMenu)
})
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDoc)
  document.removeEventListener('keydown', onKey)
  window.removeEventListener('mbcom:phone-menu', onPhoneMenu)
})
// Tell the tab bar whether the menu is open, so its Menu tab can show it.
watch(open, (v) => window.dispatchEvent(new CustomEvent('mbcom:phone-menu-state', { detail: v === 'shortcuts' })))
watch(() => route.fullPath, () => (open.value = null))

const reportsLabel = (n) => t.sprintf(/* translators: %d: number of reports waiting for a moderator */ t._n('%d report to review', '%d reports to review', n, 'membuild-community'), n)

// Badge counts belong in the buttons' names, so screen readers hear them too.
const withCount = (name, n) => (n ? t.sprintf(/* translators: 1: a button's name, e.g. "Messenger" or "Notifications", 2: how many unread items */ t.__('%1$s (%2$d unread)', 'membuild-community'), name, n) : name)
const accountLabel = () => {
  const name = t.__('Your account', 'membuild-community')
  return cfg.community.isStaff && messages.reports ? t.sprintf(/* translators: 1: "Your account", 2: e.g. "3 reports to review" */ t.__('%1$s, %2$s', 'membuild-community'), name, reportsLabel(messages.reports)) : name
}
</script>

<template>
  <div ref="root" class="actions">
    <ThemeSlot name="app.top-bar-end" :data="{ isPhone }" />

    <div v-if="isPhone || shortcuts.length || editShortcuts" class="slot">
      <button
        v-if="!isPhone"
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
      <!-- Phones: it opens from the bottom bar, so it lives on the page (the top bar's blur would trap it). -->
      <Teleport to="body" :disabled="!isPhone">
      <div v-if="open === 'shortcuts'" class="menu card shortcuts" :class="{ 'phone-menu': isPhone }" data-phone-menu @keydown="onMenuKey">
        <h2>{{ t.__('Menu', 'membuild-community') }}</h2>
        <!-- Phones have no avatar menu, so the grid holds everything. -->
        <template v-if="isPhone">
          <RouterLink to="/me" class="who">
            <Avatar :user="cfg.user" :size="40" badge />
            <strong>{{ cfg.user.name }}</strong>
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
          <template v-for="group in [{ key: 'community', title: t.__('Your community', 'membuild-community'), items: community }, { key: 'you', title: t.__('You', 'membuild-community'), items: you }]" :key="group.key">
            <template v-if="group.items.length">
              <p class="group">{{ group.title }}</p>
              <component :is="i.action ? 'button' : i.to ? 'RouterLink' : 'a'" v-for="i in group.items" :key="i.label" :to="i.to" :href="i.href" class="item" @click="i.action?.()">
                <span class="ic"><Icon :name="i.icon" :size="18" /></span>
                <span v-if="i.note" class="text"><span>{{ i.label }}</span><small>{{ i.note }}</small></span>
                <template v-else>{{ i.label }}</template>
                <span v-if="i.badge" class="badge">{{ i.badge }}</span>
              </component>
              <div v-if="group.key === 'you'" class="display">
                <div class="item static">
                  <span class="ic"><Icon name="moon" :size="18" /></span>
                  <span class="text"><span>{{ t.__('Display', 'membuild-community') }}</span></span>
                </div>
                <LookBar class="looks" icons :value="display" :label="t.__('Display', 'membuild-community')" @pick="setDisplay" />
              </div>
            </template>
          </template>
          <hr />
          <component :is="i.action ? 'button' : i.to ? 'RouterLink' : 'a'" v-for="i in after" :key="i.label" :to="i.to" :href="i.href" class="item" @click="i.action?.()">
            <span class="ic"><Icon :name="i.icon" :size="18" /></span>
            <span v-if="i.note" class="text"><span>{{ i.label }}</span><small>{{ i.note }}</small></span>
            <template v-else>{{ i.label }}</template>
          </component>
        </template>
        <template v-if="editShortcuts">
          <hr />
          <a :href="editShortcuts" class="item edit"><span class="ic"><Icon name="settings" :size="18" /></span>{{ shortcuts.length ? t.__('Edit this menu', 'membuild-community') : t.__('Set up this menu', 'membuild-community') }}</a>
        </template>
      </div>
      </Teleport>
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
          <Avatar :user="cfg.user" :size="40" badge />
          <strong>{{ cfg.user.name }}</strong>
        </RouterLink>
        <template v-for="group in [{ key: 'community', title: t.__('Your community', 'membuild-community'), items: community }, { key: 'you', title: t.__('You', 'membuild-community'), items: you }]" :key="group.key">
          <template v-if="group.items.length">
            <p class="group">{{ group.title }}</p>
            <component :is="i.action ? 'button' : i.to ? 'RouterLink' : 'a'" v-for="i in group.items" :key="i.label" :to="i.to" :href="i.href" class="item" @click="i.action?.()">
              <span class="ic"><Icon :name="i.icon" :size="18" /></span>
              <span v-if="i.note" class="text"><span>{{ i.label }}</span><small>{{ i.note }}</small></span>
              <template v-else>{{ i.label }}</template>
              <span v-if="i.badge" class="badge">{{ i.badge }}</span>
            </component>
            <div v-if="group.key === 'you'" class="display">
              <div class="item static">
                <span class="ic"><Icon name="moon" :size="18" /></span>
                <span class="text"><span>{{ t.__('Display', 'membuild-community') }}</span></span>
              </div>
              <LookBar class="looks" icons :value="display" :label="t.__('Display', 'membuild-community')" @pick="setDisplay" />
            </div>
          </template>
        </template>
        <hr />
        <component :is="i.action ? 'button' : i.to ? 'RouterLink' : 'a'" v-for="i in after" :key="i.label" :to="i.to" :href="i.href" class="item" @click="i.action?.()">
          <span class="ic"><Icon :name="i.icon" :size="18" /></span>
          <span v-if="i.note" class="text"><span>{{ i.label }}</span><small>{{ i.note }}</small></span>
          <template v-else>{{ i.label }}</template>
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
  border-radius: var(--radius-pill);
  background: var(--danger);
  color: var(--on-color);
  font-size: var(--fs-2xs);
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
  box-shadow: 0 12px 28px color-mix(in srgb, var(--shadow-color) 18%, transparent), 0 2px 4px color-mix(in srgb, var(--shadow-color) 8%, transparent);
  overflow: hidden;
}

.wide {
  width: 360px;
}

.account-menu {
  width: 340px;
  max-height: calc(100dvh - var(--header-h) - 24px);
  overflow-y: auto;
  padding: 8px;
}

.group {
  margin: 10px 8px 2px;
  font-size: var(--fs-sm);
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-2);
}

.item.static {
  cursor: default;
}

.item.static:hover {
  background: none;
}

.looks {
  margin: 2px 8px 8px 56px; /* the shared bar (LookBar), lined up under "Display" */
}

.shortcuts {
  width: 320px;
  padding: 8px;
  overflow-y: auto;
}

.shortcuts h2 {
  margin: 4px 8px 6px;
  font-size: var(--fs-3xl);
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
  font-size: var(--fs-md);
}

.edit {
  color: var(--text-2);
}

.who {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px;
  border-radius: var(--radius-sm);
  color: var(--text);
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
  border-radius: var(--radius-sm);
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

  /* Opened from the bottom bar's Menu tab: it rises above the tab bar. */
  .menu.shortcuts.phone-menu {
    position: fixed;
    top: auto;
    right: 10px;
    left: 10px;
    bottom: calc(var(--tabbar-h) + env(safe-area-inset-bottom) + 10px);
    width: auto;
    max-height: calc(100dvh - var(--header-h) - var(--tabbar-h) - 40px);
    overflow-y: auto;
  }

}
.badge {
  margin-left: auto;
  min-width: 22px;
  height: 22px;
  padding: 0 7px;
  display: grid;
  place-items: center;
  border-radius: var(--radius-pill);
  background: var(--danger);
  color: var(--on-color);
  font-size: var(--fs-xs);
  font-weight: 800;
}

.report-dot {
  top: -4px;
  right: -4px;
}
</style>
