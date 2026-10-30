/**
 * The topics this member can see (Home's filters, Create post's “# Add a topic”, Settings),
 * starting from the page's config and refreshed when an owner changes them.
 */
import { computed, reactive } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'

export const topics = reactive({
  items: cfg.community.topics || [],
  onHome: cfg.community.topicsOnHome !== false,
})

/** Shown on Home: there are topics and the owner hasn't hidden them. */
export const topicsShown = computed(() => topics.onHome && topics.items.length > 0)

export const topicById = (id) => topics.items.find((x) => x.id === Number(id)) || null

/** The topics this member may file a post in. */
export const postableTopics = computed(() => topics.items.filter((x) => x.can_post))

export function setTopics(res) {
  if (!res) return
  topics.items = res.items || []
  if ('on_home' in res) topics.onHome = !!res.on_home
}

export async function refreshTopics() {
  setTopics(await api.topics().catch(() => null))
}
