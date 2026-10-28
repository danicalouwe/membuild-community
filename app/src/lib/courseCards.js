import { computed, reactive, toValue } from 'vue'
import { courseIn } from './links.js'
import { api } from '../api.js'

/**
 * The courses this member can see, by id, for the course cards in posts. Loaded once,
 * the first time a post links to a course. A course that isn't here (hidden or deleted)
 * keeps the plain link.
 */
export const courseCards = reactive({ loaded: false, byId: {} })
let loading = null

export function loadCourseCards() {
  if (!loading) {
    loading = api
      .courses()
      .then((res) => {
        for (const c of res.items || []) courseCards.byId[c.id] = c
      })
      .catch(() => {})
      .finally(() => (courseCards.loaded = true))
  }
  return loading
}

/**
 * Progress changed on the course page (a lesson marked done or undone): the cards say
 * Completed / In progress from the same numbers, so they change with it.
 */
export function syncCourseCard(course) {
  const card = course && courseCards.byId[course.id]
  if (!card) return
  Object.assign(card, { progress: course.progress, completed_count: course.completed_count, lessons_count: course.lessons_count })
}

/**
 * For a post's words: the course its link points at (once the courses have loaded) and
 * the words to show, with that link taken out and the course's name unquoted (it shows
 * in bold). A course this member can't see keeps its plain link.
 */
export function useCourseLink(content) {
  const found = computed(() => courseIn(toValue(content)))
  const course = computed(() => {
    if (!found.value) return null
    loadCourseCards()
    return courseCards.byId[found.value.id] || null
  })
  const text = computed(() => {
    const words = toValue(content) || ''
    const c = course.value
    if (!c) return words
    return words.replace(found.value.href, '').replace(`“${c.title}”`, c.title).replace(/[ \t]+$/gm, '').trim()
  })
  return { course, text }
}
