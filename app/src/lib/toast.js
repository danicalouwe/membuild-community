import { reactive } from 'vue'

/**
 * One short message at the bottom of the screen ("Nice work! Lesson done."),
 * gone after a few seconds. A new one replaces the old. It can carry an icon
 * ('megaphone') and one action, like "View post" ({ label, post }), which opens
 * that post on top of the page; a message with an action stays a little longer.
 */
export const toast = reactive({ message: '', emoji: '', icon: '', action: null, id: 0 })

let timer = null
export function showToast(message, emoji = '', { icon = '', action = null } = {}) {
  clearTimeout(timer)
  Object.assign(toast, { message, emoji, icon, action, id: toast.id + 1 })
  timer = setTimeout(hideToast, action ? 6000 : 3200)
}

export function hideToast() {
  clearTimeout(timer)
  toast.message = ''
}
