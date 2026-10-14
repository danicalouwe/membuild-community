import { api } from '../api.js'

/**
 * Reacting to a comment updates it on screen immediately, then applies the
 * server's answer (or rolls back on failure). "down" is a private downvote:
 * it replaces any reaction but is never counted.
 */
export async function setCommentReaction(comment, type) {
  const before = {
    my_reaction: comment.my_reaction,
    reactions_count: comment.reactions_count,
    top_reactions: [...comment.top_reactions],
  }
  const counts = (kind) => kind && kind !== 'down'
  const delta = (counts(type) ? 1 : 0) - (counts(before.my_reaction) ? 1 : 0)
  Object.assign(comment, {
    my_reaction: type,
    reactions_count: Math.max(0, before.reactions_count + delta),
    top_reactions: counts(type) ? [type, ...before.top_reactions.filter((r) => r !== type)].slice(0, 3) : before.top_reactions,
  })
  try {
    const res = type === null ? await api.unreactComment(comment.id) : await api.reactComment(comment.id, type)
    Object.assign(comment, res)
  } catch {
    Object.assign(comment, before)
  }
}
