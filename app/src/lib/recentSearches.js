import { ref } from 'vue'
import { cfg } from '../config.js'

/**
 * The last few things this member searched for, kept in this browser only
 * (never sent anywhere), newest first. Shown before typing; ✕ removes one.
 */
const KEY = `mbcom-recent-search-${cfg.user?.id || 0}`
const MAX = 5

function read() {
  try {
    const list = JSON.parse(localStorage.getItem(KEY) || '[]')
    return Array.isArray(list) ? list.filter((s) => typeof s === 'string').slice(0, MAX) : []
  } catch {
    return []
  }
}

export const recentSearches = ref(read())

function save() {
  try {
    localStorage.setItem(KEY, JSON.stringify(recentSearches.value))
  } catch {
    // private window or storage full: the list just isn't kept
  }
}

export function rememberSearch(q) {
  const text = String(q || '').trim()
  if (!text) return
  recentSearches.value = [text, ...recentSearches.value.filter((s) => s.toLowerCase() !== text.toLowerCase())].slice(0, MAX)
  save()
}

export function forgetSearch(q) {
  recentSearches.value = recentSearches.value.filter((s) => s !== q)
  save()
}
