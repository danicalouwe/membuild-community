import { reactive } from 'vue'

/**
 * One short message at the bottom of the screen ("Nice work! Lesson done."),
 * gone after a few seconds. A new one replaces the old.
 */
export const toast = reactive({ message: '', emoji: '', id: 0 })

let timer = null
export function showToast(message, emoji = '') {
  clearTimeout(timer)
  Object.assign(toast, { message, emoji, id: toast.id + 1 })
  timer = setTimeout(() => (toast.message = ''), 3200)
}
