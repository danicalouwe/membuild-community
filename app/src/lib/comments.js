import { api } from '../api.js'

/**
 * Reacting to a comment updates it on screen immediately, then applies the
 * server's answer (or rolls back on failure). ("down" rows from before 1.8.3,
 * when comments had a dislike, are never counted.)
 */
/** The per-kind counts after one person's reaction moves from `from` to `to` (either may be null or "down"). */
export function shiftCounts(counts, from, to) {
  const next = { ...(counts || {}) }
  if (from && from !== 'down') next[from] = Math.max(0, (next[from] || 0) - 1)
  if (to && to !== 'down') next[to] = (next[to] || 0) + 1
  for (const k of Object.keys(next)) if (!next[k]) delete next[k]
  return next
}

export async function setCommentReaction(comment, type) {
  const before = {
    my_reaction: comment.my_reaction,
    reactions_count: comment.reactions_count,
    top_reactions: [...comment.top_reactions],
    reaction_counts: { ...(comment.reaction_counts || {}) },
  }
  const counts = (kind) => kind && kind !== 'down'
  const delta = (counts(type) ? 1 : 0) - (counts(before.my_reaction) ? 1 : 0)
  Object.assign(comment, {
    my_reaction: type,
    reactions_count: Math.max(0, before.reactions_count + delta),
    top_reactions: counts(type) ? [type, ...before.top_reactions.filter((r) => r !== type)].slice(0, 3) : before.top_reactions,
    reaction_counts: shiftCounts(before.reaction_counts, before.my_reaction, type),
  })
  try {
    const res = type === null ? await api.unreactComment(comment.id) : await api.reactComment(comment.id, type)
    Object.assign(comment, res)
  } catch {
    Object.assign(comment, before)
  }
}
