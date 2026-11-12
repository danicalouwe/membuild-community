/**
 * The data layer for Community themes and add-ons (theme contract 1): window.MBCommunity.data.
 * A theme's whole page (a page.* slot, lib/slots.js) loads and saves through these, without the
 * community's own screens. They use the same requests and stores as the default pages, so a
 * reaction or an RSVP made on a theme page shows on the default ones too, and points still pop up.
 *
 * Every function returns a Promise. A refusal rejects with an Error whose message is already in
 * plain words for the member, plus `status` (401, 403, 404…). The server decides who can see or do
 * what: a member gets exactly what the default pages would give them.
 *
 * Lists page the same way: { items, next }. Pass `next` back as `after` for the following page;
 * it's null at the end.
 *
 * Contract 1 promise: these functions and the fields in DATA_FIELDS are only ever added to,
 * never renamed or removed. Answers may hold other fields; don't rely on those.
 */
import { api } from '../api.js'
import { stateOf } from './events.js'
import { pointsMayHaveChanged } from '../stores/me.js'
import { insertPost, updatePost } from '../stores/feed.js'

export const DATA_FIELDS = {
  post: 'id, type, content, author { id, name, avatar, level }, media, topic { id, name, color } or null, created_at, reactions_count, top_reactions, my_reaction, comments_count, pinned, edited',
  comment: 'id, content, author { id, name, avatar, level }, created_at, reactions_count, my_reaction, replies_count, edited_at (null until its author changes the words)',
  topic: 'id, name, color',
  course: 'id, title, description, cover, lock (null when it’s open), progress (0–100), lessons_count, completed_count',
  courseOutline: 'the course fields, plus modules: [{ id, title, lessons: [{ id, title, completed, locked, minutes, has_video }] }]',
  lesson: 'id, course_id, module_id, module_title, number, total, title, content, media, resources, completed, prev_id, next_id',
  progress: 'course_id, lessons_count, completed_count, progress (0–100)',
  event: 'a post (as above) with event: { title, at, minutes, tz, where (“online”, “person” or “both”), place, url, recording, status, my_rsvp, going_count, interested_count, faces, state (“upcoming”, “soon”, “live”, “past” or “cancelled”) }',
  rsvp: 'my_rsvp, going_count, interested_count',
  member: 'id, name, avatar, level, bio, location, cover, points, next_level, to_next, level_progress, joined_at, posts_count, is_me',
  leaderboard: 'period, items: [{ rank, points, user }], me { rank, points, user }, progress { points, level, next_level, to_next, percent }, rules: [{ reason, points }], levels',
}

// `next` hides how each list pages: Latest by the last post seen, the rest by page number.
const pageOf = (after) => {
  const m = /^([cp])(\d+)$/.exec(String(after || ''))
  return m ? (m[1] === 'c' ? { cursor: Number(m[2]) } : { page: Number(m[2]) }) : {}
}
const byPage = (res) => ({ items: res.items || [], next: res.next_page ? `p${res.next_page}` : null })
const withState = (post) => (post?.event?.at ? { ...post, event: { ...post.event, state: stateOf(post.event) } } : post)

const feed = Object.freeze({
  /** Home's posts. sort: “latest” or “hot”; topic: a topic id, or 0 for all. */
  async list({ sort = 'latest', topic = 0, after } = {}) {
    const hot = sort === 'hot'
    const res = await api.feed({ sort: hot ? 'hot' : 'latest', type: 'all', topic: Number(topic) || undefined, ...pageOf(after) })
    const next = hot ? (res.next_page ? `p${res.next_page}` : null) : res.next_cursor ? `c${res.next_cursor}` : null
    return { items: res.items || [], pinned: res.pinned || [], next }
  },
  post: (id) => api.post(Number(id)),
  topics: async () => (await api.topics()).items || [],
  /** A new post on Home, words only. topic: a topic id the member may post in, or 0. */
  async create({ content, topic = 0 } = {}) {
    const post = await api.createPost({ content: String(content || ''), media_ids: [], topic_id: Number(topic) || undefined })
    insertPost(post)
    pointsMayHaveChanged()
    return post
  },
  /** type: a reaction (“like”, “love”…), or null to take it back. */
  async react(postId, type) {
    const id = Number(postId)
    const res = type ? await api.react(id, String(type)) : await api.unreact(id)
    updatePost(id, res)
    return res
  },
  comments: async (postId, { after } = {}) => byPage(await api.comments(Number(postId), { page: pageOf(after).page || 1 })),
  /** replyTo: a comment id, to answer under it. */
  async comment(postId, content, { replyTo } = {}) {
    const id = Number(postId)
    const saved = await api.createComment(id, String(content || ''), { parentId: Number(replyTo) || undefined })
    updatePost(id, (p) => ({ comments_count: p.comments_count + 1 }))
    pointsMayHaveChanged()
    return saved
  },
})

const courses = Object.freeze({
  /** The courses this member may see, in the owner's order. */
  list: async () => (await api.courses()).items || [],
  /** A course with its modules and lessons. */
  get: (id) => api.course(Number(id)),
  /** One lesson. A locked one is refused with the reason in plain words. */
  lesson: (id) => api.lesson(Number(id)),
  /** Marks a lesson done (or not done, with false). Returns the course's progress. */
  async complete(lessonId, done = true) {
    const res = done ? await api.completeLesson(Number(lessonId)) : await api.uncompleteLesson(Number(lessonId))
    pointsMayHaveChanged()
    return res
  },
})

const events = Object.freeze({
  /** when: “upcoming” or “past”. */
  async list({ when = 'upcoming', after } = {}) {
    const res = await api.events({ when: when === 'past' ? 'past' : 'upcoming', page: pageOf(after).page || 1 })
    const out = byPage(res)
    return { ...out, items: out.items.map(withState) }
  },
  get: async (id) => withState(await api.post(Number(id))),
  /** status: “going”, “interested”, or null to take it back. */
  async rsvp(id, status) {
    const postId = Number(id)
    const res = status ? await api.rsvp(postId, String(status)) : await api.unrsvp(postId)
    updatePost(postId, (p) => ({ event: { ...p.event, ...res } }))
    return res
  },
})

const members = Object.freeze({
  get: (id) => api.member(Number(id)),
})

const leaderboard = Object.freeze({
  /** period: “7”, “30” or “all”. */
  get: ({ period = '30' } = {}) => api.leaderboard(['7', '30', 'all'].includes(String(period)) ? String(period) : '30'),
})

export const coreData = { feed, courses, events, members, leaderboard }
