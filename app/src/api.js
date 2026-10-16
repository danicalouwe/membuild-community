import { cfg } from './config.js'
import { t } from './lib/i18n.js'

// Works with pretty permalinks (/wp-json/...) and plain ones (?rest_route=...).
function url(path, params) {
  const base = cfg.restUrl.replace(/\/$/, '') + path
  const query = params
    ? Object.entries(params)
        .filter(([, v]) => v !== undefined && v !== null && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
        .join('&')
    : ''
  if (!query) return base
  return base + (base.includes('?') ? '&' : '?') + query
}

export class ApiError extends Error {
  constructor(message, status, code) {
    super(message)
    this.status = status
    this.code = code
  }
}

async function send(path, { method = 'GET', params, body, raw, signal } = {}) {
  const headers = { 'X-WP-Nonce': cfg.nonce }
  let payload
  if (raw !== undefined) {
    headers['Content-Type'] = 'application/octet-stream'
    payload = raw
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  const res = await fetch(url(path, params), { method, headers, body: payload, signal, credentials: 'same-origin' })
  // The server sends a fresh nonce with every response, so a tab left open
  // for days keeps a valid one as long as it keeps talking to the server.
  const fresh = res.headers.get('X-WP-Nonce')
  if (fresh) cfg.nonce = fresh
  const data = await res.json().catch(() => null)
  return { res, data }
}

// One shared refresh, so a burst of expired requests asks for a nonce once.
let refreshing = null
function refreshNonce() {
  refreshing ??= fetch(cfg.ajaxUrl, {
    method: 'POST',
    credentials: 'same-origin',
    body: new URLSearchParams({ action: 'mbcom_nonce' }),
  })
    .then((r) => r.json())
    .then((json) => {
      if (!json?.success || !json.data?.nonce) throw new Error('nonce')
      cfg.nonce = json.data.nonce
    })
    .finally(() => {
      refreshing = null
    })
  return refreshing
}

const expired = (res, data) => res.status === 403 && data?.code === 'rest_cookie_invalid_nonce'

function failure(res, data) {
  const generic = /* translators: %d: HTTP status code, e.g. 500 */ t.__('Something went wrong (error %d). Please try again.', 'membuild-community')
  return new ApiError(data?.message || t.sprintf(generic, res.status), res.status, data?.code)
}

export async function request(path, options = {}) {
  let { res, data } = await send(path, options)
  if (expired(res, data)) {
    // The page sat open past the nonce's lifetime: get a new one and retry once.
    try {
      if (!cfg.ajaxUrl) throw new Error('no ajax url')
      await refreshNonce()
      ;({ res, data } = await send(path, options))
    } catch (e) {
      if (e?.name === 'AbortError') throw e
    }
    if (expired(res, data)) {
      throw new ApiError(t.__('Your session expired. Reload the page to continue.', 'membuild-community'), res.status, data.code)
    }
  }
  if (!res.ok) throw failure(res, data)
  return data
}

export const api = {
  feed: (params, signal) => request('/feed', { params, signal }),
  post: (id) => request(`/posts/${id}`),
  createPost: (body) => request('/posts', { method: 'POST', body }),
  deletePost: (id) => request(`/posts/${id}`, { method: 'DELETE' }),
  report: (body) => request('/reports', { method: 'POST', body }),
  modReports: (status = 'open', page = 1) => request('/moderation/reports', { params: { status, page } }),
  resolveReport: (type, id, action) => request(`/moderation/reports/${type}/${id}`, { method: 'POST', body: { action } }),
  restricted: () => request('/moderation/members'),
  restrict: (userId, body) => request(`/members/${userId}/restriction`, { method: 'POST', body }),
  blocks: () => request('/blocks'),
  block: (userId) => request(`/members/${userId}/block`, { method: 'PUT' }),
  unblock: (userId) => request(`/members/${userId}/block`, { method: 'DELETE' }),
  pinPost: (id, scope) => request(`/posts/${id}/pin`, { method: 'PUT', body: scope ? { scope } : undefined }), // scope: 'lesson' | 'course' for course posts
  unpinPost: (id) => request(`/posts/${id}/pin`, { method: 'DELETE' }),
  updatePost: (id, body) => request(`/posts/${id}`, { method: 'PUT', body }),
  members: (params) => request('/members', { params }),
  member: (id) => request(`/members/${id}`),
  updateProfile: (body) => request('/me/profile', { method: 'PUT', body }),
  react: (id, type) => request(`/posts/${id}/reaction`, { method: 'PUT', body: { type } }),
  unreact: (id) => request(`/posts/${id}/reaction`, { method: 'DELETE' }),
  postReactions: (id, { type = '', page = 1 } = {}) => request(`/posts/${id}/reactions`, { params: { type, page } }),
  share: (id) => request(`/posts/${id}/share`, { method: 'POST' }),
  embed: (url) => request('/media/embed', { method: 'POST', body: { url } }),
  // Access groups: who can open which course (admins).
  groups: () => request('/groups'),
  createGroup: (body) => request('/groups', { method: 'POST', body }),
  updateGroup: (id, body) => request(`/groups/${id}`, { method: 'PUT', body }),
  deleteGroup: (id) => request(`/groups/${id}`, { method: 'DELETE' }),
  groupMembers: (id) => request(`/groups/${id}/members`),
  addToGroup: (id, userIds) => request(`/groups/${id}/members`, { method: 'POST', body: { user_ids: userIds } }),
  removeFromGroup: (id, userId) => request(`/groups/${id}/members/${userId}`, { method: 'DELETE' }),
  memberGroups: (userId) => request(`/members/${userId}/groups`),
  setMemberGroups: (userId, groupIds) => request(`/members/${userId}/groups`, { method: 'PUT', body: { group_ids: groupIds } }),

  courses: () => request('/courses'),
  course: (id) => request(`/courses/${id}`),
  createCourse: (body) => request('/courses', { method: 'POST', body }),
  updateCourse: (id, body) => request(`/courses/${id}`, { method: 'PUT', body }),
  deleteCourse: (id) => request(`/courses/${id}`, { method: 'DELETE' }),
  updateStructure: (id, modules) => request(`/courses/${id}/structure`, { method: 'PUT', body: { modules } }),
  createModule: (courseId, title) => request(`/courses/${courseId}/modules`, { method: 'POST', body: { title } }),
  updateModule: (id, title) => request(`/modules/${id}`, { method: 'PUT', body: { title } }),
  deleteModule: (id) => request(`/modules/${id}`, { method: 'DELETE' }),
  createLesson: (moduleId, title) => request(`/modules/${moduleId}/lessons`, { method: 'POST', body: { title } }),
  lesson: (id) => request(`/lessons/${id}`),
  updateLesson: (id, body) => request(`/lessons/${id}`, { method: 'PUT', body }),
  deleteLesson: (id) => request(`/lessons/${id}`, { method: 'DELETE' }),
  completeLesson: (id) => request(`/lessons/${id}/complete`, { method: 'POST' }),
  uncompleteLesson: (id) => request(`/lessons/${id}/complete`, { method: 'DELETE' }),
  courseResources: (id) => request(`/courses/${id}/resources`),
  // Events: a live call, a Q&A, a workshop. Each one is also a post.
  events: (params) => request('/events', { params }),
  createEvent: (body) => request('/events', { method: 'POST', body }),
  updateEvent: (id, body) => request(`/events/${id}`, { method: 'PUT', body }),
  cancelEvent: (id) => request(`/events/${id}/cancel`, { method: 'POST' }),
  rsvp: (id, status) => request(`/events/${id}/rsvp`, { method: 'PUT', body: { status } }),
  unrsvp: (id) => request(`/events/${id}/rsvp`, { method: 'DELETE' }),
  eventGoing: (id) => request(`/events/${id}/going`),
  // Course discussions: posts under a lesson, or across the whole course.
  lessonPosts: (id, params) => request(`/lessons/${id}/posts`, { params }),
  coursePosts: (id, params, signal) => request(`/courses/${id}/posts`, { params, signal }),
  helpful: (id) => request(`/posts/${id}/helpful`, { method: 'PUT' }),
  unhelpful: (id) => request(`/posts/${id}/helpful`, { method: 'DELETE' }),
  chooseBest: (postId, commentId) => request(`/posts/${postId}/best`, { method: 'PUT', body: { comment_id: commentId } }),
  clearBest: (postId) => request(`/posts/${postId}/best`, { method: 'DELETE' }),
  comments: (postId, { sort = 'relevant', page = 1 } = {}) => request(`/posts/${postId}/comments`, { params: { sort, page } }),
  replies: (commentId) => request(`/comments/${commentId}/replies`),
  createComment: (postId, content, { parentId, replyToUserId, mediaId } = {}) =>
    request(`/posts/${postId}/comments`, {
      method: 'POST',
      body: { content, parent_id: parentId || undefined, reply_to_user_id: replyToUserId || undefined, media_id: mediaId || undefined },
    }),
  deleteComment: (id) => request(`/comments/${id}`, { method: 'DELETE' }),
  reactComment: (id, type) => request(`/comments/${id}/reaction`, { method: 'PUT', body: { type } }),
  unreactComment: (id) => request(`/comments/${id}/reaction`, { method: 'DELETE' }),
  meStats: () => request('/me/stats'),
  notifications: (filter = 'all', page = 1) => request('/notifications', { params: { filter, page } }),
  notificationsSeen: () => request('/notifications/seen', { method: 'POST' }),
  readNotification: (id) => request(`/notifications/${id}/read`, { method: 'POST' }),
  readAllNotifications: () => request('/notifications/read-all', { method: 'POST' }),
  inbox: (before) => request('/messages/threads', { params: { before } }),
  startChat: (userIds, title) => request('/messages/threads', { method: 'POST', body: { user_ids: userIds, title } }),
  chat: (id) => request(`/messages/threads/${id}`),
  renameChat: (id, title) => request(`/messages/threads/${id}`, { method: 'PUT', body: { title } }),
  leaveChat: (id) => request(`/messages/threads/${id}/leave`, { method: 'POST' }),
  chatMessages: (id, before) => request(`/messages/threads/${id}/messages`, { params: { before } }),
  sendMessage: (id, body) => request(`/messages/threads/${id}/messages`, { method: 'POST', body }),
  markRead: (id, messageId) => request(`/messages/threads/${id}/read`, { method: 'POST', body: { message_id: messageId } }),
  typing: (id) => request(`/messages/threads/${id}/typing`, { method: 'POST' }),
  unsend: (id) => request(`/messages/${id}`, { method: 'DELETE' }),
  reactMessage: (id, type) => request(`/messages/${id}/reaction`, { method: 'PUT', body: { type } }),
  unreactMessage: (id) => request(`/messages/${id}/reaction`, { method: 'DELETE' }),
  pulse: (threads, feedSince) => request('/messages/pulse', { params: { threads, feed_since: feedSince } }),
  settings: () => request('/settings'),
  saveAppIcon: (mediaId) => request('/settings/app-icon', { method: 'PUT', body: { media_id: mediaId } }),
  testVideo: () => request('/settings/test-video', { method: 'POST' }),
  testEmail: () => request('/settings/test-email', { method: 'POST' }),
  encryption: () => request('/settings/encryption'),
  encryptionKey: () => request('/settings/encryption/key'),
  encryptionAction: (action, key) => request('/settings/encryption', { method: 'POST', body: { action, key } }),
  usage: () => request('/settings/usage'),
  saveUsage: (body) => request('/settings/usage', { method: 'PUT', body }),
  forgetUsage: () => request('/settings/usage/forget', { method: 'POST' }),
  subscribeUpdates: (body) => request('/settings/updates', { method: 'POST', body }),
  stopUpdates: () => request('/settings/updates', { method: 'DELETE' }),
  emailPrefs: () => request('/me/email'),
  saveEmailPrefs: (body) => request('/me/email', { method: 'PUT', body }),
  saveSettings: (body) => request('/settings', { method: 'PUT', body }),
  people: (search) => request('/messages/people', { params: { search } }),
  search: (q, type = 'all', page = 1) => request('/search', { params: { q, type, page } }),
  places: () => request('/places'),
  place: (id) => request(`/places/${id}`),
  createPlace: (body) => request('/places', { method: 'POST', body }),
  updatePlace: (id, body) => request(`/places/${id}`, { method: 'PUT', body }),
  deletePlace: (id) => request(`/places/${id}`, { method: 'DELETE' }),
  going: (id) => request(`/places/${id}/going`, { method: 'PUT' }),
  notGoing: (id) => request(`/places/${id}/going`, { method: 'DELETE' }),
  geocode: (q) => request('/places/geocode', { params: { q } }),
  storyReply: (storyId, content) => request(`/stories/${storyId}/reply`, { method: 'POST', body: { content } }),
  leaderboard: (period = '30') => request('/leaderboard', { params: { period } }),
  stories: () => request('/stories'),
  createStory: (body) => request('/stories', { method: 'POST', body }),
  viewStory: (id) => request(`/stories/${id}/view`, { method: 'POST' }),
  reactStory: (id, type) => request(`/stories/${id}/reaction`, { method: 'PUT', body: { type } }),
  storyViewers: (id) => request(`/stories/${id}/viewers`),
  deleteStory: (id) => request(`/stories/${id}`, { method: 'DELETE' }),
}
