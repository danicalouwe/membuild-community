import { reactive } from 'vue'
import { api } from '../api.js'
import { t } from '../lib/i18n.js'

// Active stories grouped by person, as ordered by the server:
// you first, then people with unseen stories, then the rest.
export const stories = reactive({ groups: [], loaded: false, loading: false, error: '' })

export async function loadStories() {
  if (stories.loading) return
  stories.loading = true
  try {
    stories.groups = (await api.stories()).groups
    stories.loaded = true
    stories.error = ''
  } catch (err) {
    stories.error = err.message // the strip just stays as it was; the next visit tries again
  } finally {
    stories.loading = false
  }
}

/** Marks a story seen on screen at once; the ring turns grey when a group is fully seen. */
export function markSeen(group, story) {
  if (story.seen || group.is_me) return
  story.seen = true
  group.has_unseen = group.stories.some((s) => !s.seen)
  api.viewStory(story.id).catch(() => {})
}

/** Removes a story on screen at once; if the server refuses, it comes back and the member is told. */
export async function removeStory(group, story) {
  const before = { stories: group.stories, groups: stories.groups }
  group.stories = group.stories.filter((s) => s !== story)
  if (!group.stories.length) stories.groups = stories.groups.filter((g) => g !== group)
  try {
    await api.deleteStory(story.id)
  } catch (err) {
    group.stories = before.stories
    if (!stories.groups.includes(group)) stories.groups = before.groups
    alert(err.message || t.__('The story could not be deleted.', 'membuild-community'))
  }
}
