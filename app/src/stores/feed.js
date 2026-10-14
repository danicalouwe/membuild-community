import { reactive } from 'vue'
import { api } from '../api.js'

/**
 * One list per feed (latest, hot, reels). Lists live at module level, so
 * switching tabs and coming back shows the same posts instantly.
 */
function createList(params) {
  return reactive({
    params,
    items: [],
    pinned: [], // shown above the feed, from the first page
    cursor: 0,
    page: 1,
    hasMore: true,
    loading: false,
    loaded: false,
    error: null,
  })
}

export const lists = {
  // Like Facebook, reels show in the feed as posts too (as well as in the tray and the Reels tab).
  latest: createList({ sort: 'latest', type: 'all' }),
  hot: createList({ sort: 'hot', type: 'all' }),
  reels: createList({ sort: 'latest', type: 'reel' }),
}

/**
 * One member's posts, for their profile. Kept with the other lists so edits
 * and deletes reach it too. Only the most recently visited profiles stay
 * cached, so browsing many members doesn't keep every list in memory.
 */
const MEMBER_LISTS = 10
const memberKeys = []
export function memberList(userId) {
  const key = `member-${userId}`
  lists[key] ??= createList({ sort: 'latest', type: 'all', author: userId })
  const at = memberKeys.indexOf(key)
  if (at !== -1) memberKeys.splice(at, 1)
  memberKeys.push(key)
  while (memberKeys.length > MEMBER_LISTS) delete lists[memberKeys.shift()]
  return lists[key]
}

export async function loadMore(list) {
  if (list.loading || !list.hasMore) return
  list.loading = true
  list.error = null
  try {
    const res = await api.feed({ ...list.params, cursor: list.cursor || undefined, page: list.page })
    if (res.pinned && !list.cursor && list.page <= 1) list.pinned = res.pinned
    const seen = new Set(list.items.map((p) => p.id))
    list.items.push(...res.items.filter((p) => !seen.has(p.id)))
    list.cursor = res.next_cursor || 0
    list.page = res.next_page || list.page
    list.hasMore = list.params.sort === 'hot' ? !!res.next_page : !!res.next_cursor
    list.loaded = true
  } catch (err) {
    list.error = err.message
  } finally {
    list.loading = false
  }
}

/**
 * Reloads from the top, keeping the old items on screen until the new ones
 * arrive. Resolves true when it actually reloaded (not busy, no error).
 */
export async function refresh(list) {
  if (list.loading) return false
  list.loading = true
  try {
    const res = await api.feed({ ...list.params })
    list.items = res.items
    list.cursor = res.next_cursor || 0
    list.page = res.next_page || 1
    list.hasMore = list.params.sort === 'hot' ? !!res.next_page : !!res.next_cursor
    list.loaded = true
    list.error = null
    return true
  } catch (err) {
    list.error = err.message
    return false
  } finally {
    list.loading = false
  }
}

/** The same post can sit in several lists; apply a change to every copy. */
export function updatePost(id, patch) {
  for (const list of Object.values(lists)) {
    for (const post of [...list.items, ...list.pinned].filter((p) => p.id === id)) {
      Object.assign(post, typeof patch === 'function' ? patch(post) : patch)
    }
  }
}

export function insertPost(post) {
  ;(post.type === 'reel' ? lists.reels : lists.latest).items.unshift(post)
}

export function replacePost(localKey, post) {
  for (const list of Object.values(lists)) {
    const i = list.items.findIndex((p) => p.localKey === localKey)
    if (i !== -1) list.items.splice(i, 1, post)
  }
}

/**
 * Removes a post everywhere at once (an optimistic delete) and returns a
 * function that puts it back in the same spots, should the server refuse.
 */
export function takePost(id) {
  const spots = []
  for (const list of Object.values(lists)) {
    for (const key of ['items', 'pinned']) {
      const i = list[key].findIndex((p) => p.id === id)
      if (i !== -1) spots.push({ list, key, i, post: list[key][i] })
    }
  }
  removePost(id)
  return () => {
    for (const { list, key, i, post } of spots) {
      if (!list[key].some((p) => p.id === id)) list[key].splice(Math.min(i, list[key].length), 0, post)
    }
  }
}

export function removePost(idOrKey) {
  for (const list of Object.values(lists)) {
    list.items = list.items.filter((p) => p.id !== idOrKey && p.localKey !== idOrKey)
    list.pinned = list.pinned.filter((p) => p.id !== idOrKey)
  }
}

/**
 * Tapping a reaction updates the screen immediately. The server's answer is
 * then applied, or the change is rolled back if the request fails.
 */
export async function setReaction(post, type) {
  const before = { my_reaction: post.my_reaction, reactions_count: post.reactions_count, top_reactions: [...post.top_reactions] }
  const removing = type === null
  const optimistic = {
    my_reaction: type,
    reactions_count: post.reactions_count + (removing ? -1 : before.my_reaction ? 0 : 1),
    top_reactions: removing
      ? before.top_reactions
      : [type, ...before.top_reactions.filter((r) => r !== type)].slice(0, 3),
  }
  updatePost(post.id, optimistic)
  try {
    const res = removing ? await api.unreact(post.id) : await api.react(post.id, type)
    updatePost(post.id, res)
  } catch {
    updatePost(post.id, before)
  }
}

/** Pinning moves a post between the timeline and the pinned spot at the top. */
export function setPinned(post, pinned) {
  for (const list of [lists.latest, lists.hot]) {
    const copy = list.items.find((p) => p.id === post.id) || list.pinned.find((p) => p.id === post.id) || post
    copy.pinned = pinned
    if (pinned) {
      list.items = list.items.filter((p) => p.id !== post.id)
      if (!list.pinned.some((p) => p.id === post.id)) list.pinned.unshift(copy)
    } else {
      list.pinned = list.pinned.filter((p) => p.id !== post.id)
    }
  }
  post.pinned = pinned
}
