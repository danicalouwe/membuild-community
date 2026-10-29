import { createRouter, createWebHistory } from 'vue-router'
import { cfg, feature } from './config.js'
import FeedView from './views/FeedView.vue'
import { t } from './lib/i18n.js'

// The sections, in the order members see them. `short` is the label under each phone tab.
// Courses and people come before the extras: they're why most communities exist.
export const tabs = [
  { name: 'feed', path: '/', label: t._x('Home', 'tab', 'membuild-community'), short: t._x('Home', 'phone tab', 'membuild-community'), icon: 'home' },
  { name: 'classroom', path: '/classroom', label: t._x('Classroom', 'tab', 'membuild-community'), short: t._x('Classroom', 'phone tab', 'membuild-community'), icon: 'cap', feature: 'classroom' },
  { name: 'members', path: '/members', label: t._x('Members', 'tab', 'membuild-community'), icon: 'users', desktopOnly: true },
  { name: 'reels', path: '/reels', label: t._x('Reels', 'tab', 'membuild-community'), short: t._x('Reels', 'phone tab', 'membuild-community'), icon: 'film', feature: 'reels' },
  { name: 'events', path: '/events', label: t._x('Events', 'tab', 'membuild-community'), icon: 'calendar', desktopOnly: true, feature: 'events' },
  { name: 'places', path: '/places', label: t._x('Places', 'tab', 'membuild-community'), icon: 'mapPin', desktopOnly: true, feature: 'places' },
  { name: 'leaderboard', path: '/leaderboard', label: t._x('Leaderboard', 'tab', 'membuild-community'), icon: 'trophy', desktopOnly: true, feature: 'leaderboard' },
  // Messages and notifications are header icons; on phones the bell is a tab.
  { name: 'notifications', path: '/notifications', label: t._x('Notifications', 'tab', 'membuild-community'), short: t._x('Alerts', 'phone tab', 'membuild-community'), icon: 'bell', mobileOnly: true },
  { name: 'me', path: '/me', label: t._x('Profile', 'tab', 'membuild-community'), short: t._x('Me', 'phone tab', 'membuild-community'), icon: 'user' },
]

export const router = createRouter({
  history: createWebHistory(cfg.basePath),
  routes: [
    { path: '/', name: 'feed', component: FeedView },
    { path: '/reels', name: 'reels', component: () => import('./views/ReelsView.vue') },
    { path: '/classroom', name: 'classroom', component: () => import('./views/ClassroomView.vue') },
    { path: '/events', name: 'events', component: () => import('./views/EventsView.vue') },
    { path: '/events/:id(\\d+)', name: 'event', component: () => import('./views/EventView.vue'), props: (route) => ({ id: Number(route.params.id) }) },
    {
      path: '/classroom/:courseId(\\d+)/:lessonId(\\d+)?',
      name: 'course',
      component: () => import('./views/CourseView.vue'),
      props: (route) => ({ courseId: Number(route.params.courseId), lessonId: Number(route.params.lessonId) || null }),
    },
    {
      path: '/messages/:threadId(\\d+)?',
      name: 'messages',
      component: () => import('./views/MessagesView.vue'),
      props: (route) => ({ threadId: Number(route.params.threadId) || null }),
    },
    { path: '/notifications', name: 'notifications', component: () => import('./views/NotificationsView.vue') },
    { path: '/leaderboard', name: 'leaderboard', component: () => import('./views/LeaderboardView.vue') },
    { path: '/leaderboard/levels', name: 'levels', component: () => import('./views/LevelsView.vue') },
    { path: '/moderation', name: 'moderation', component: () => import('./views/ModerationView.vue') },
    { path: '/settings', name: 'settings', component: () => import('./views/SettingsView.vue') },
    { path: '/members', name: 'members', component: () => import('./views/MembersView.vue') },
    { path: '/search', name: 'search', component: () => import('./views/SearchView.vue') },
    {
      // One route for the list and a place, so opening one keeps the map where it is.
      path: '/places/:placeId(\\d+)?',
      name: 'places',
      component: () => import('./views/PlacesView.vue'),
      props: (route) => ({ placeId: Number(route.params.placeId) || null }),
    },
    {
      path: '/members/:id(\\d+)',
      name: 'member',
      component: () => import('./views/MemberView.vue'),
      props: (route) => ({ id: Number(route.params.id) }),
    },
    { path: '/me/emails', name: 'emails', component: () => import('./views/EmailsView.vue') },
    { path: '/me/edit', name: 'profile-edit', component: () => import('./views/EditProfileView.vue') },
    { path: '/me/privacy', name: 'privacy', component: () => import('./views/PrivacyView.vue') },
    { path: '/me/billing', name: 'billing', component: () => import('./views/BillingView.vue') },
    { path: '/me/billing/:id([A-Za-z0-9]+)', name: 'billing-item', component: () => import('./views/BillingDetailView.vue'), props: true },
    { path: '/me', name: 'me', component: () => import('./views/MemberView.vue'), props: () => ({ id: cfg.user.id }) },
    { path: '/:rest(.*)*', redirect: '/' },
  ],
  // The feed is kept alive, so returning to it can restore the exact scroll position.
  scrollBehavior: (to, from, saved) => saved || { top: 0 },
})

// Parts the admin switched off, and admin-only pages, send people back to the feed.
const routeFeature = { levels: 'leaderboard', reels: 'reels', classroom: 'classroom', course: 'classroom', leaderboard: 'leaderboard', messages: 'messages', events: 'events', event: 'events' }
router.beforeEach((to) => {
  if (to.name === 'member' && Number(to.params.id) === cfg.user.id) return { name: 'me' } // your own profile lives at /me
  if (routeFeature[to.name] && !feature(routeFeature[to.name])) return { name: 'feed' }
  if (to.name === 'settings' && !cfg.community.canManage) return { name: 'feed' }
  if (to.name === 'moderation' && !cfg.community.isStaff) return { name: 'feed' }
})
