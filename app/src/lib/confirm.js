import { reactive } from 'vue'

/**
 * "Are you sure?" in our own box, never the browser's (some browsers and in-app views
 * close those by themselves, so the answer silently becomes No).
 *
 *   if (!(await askConfirm({ title: 'Delete this post?', confirm: 'Delete', danger: true }))) return
 *
 * Shown by ConfirmHost (in App.vue). Resolves true for the main button, false otherwise.
 */
export const confirmState = reactive({ open: null })

export function askConfirm({ title, text = '', confirm, cancel = '', danger = false, safe = false }) {
  confirmState.open?.resolve(false) // a second question replaces the first
  return new Promise((resolve) => {
    confirmState.open = { title, text, confirm, cancel, danger, safe, resolve }
  })
}

export function answerConfirm(yes) {
  const q = confirmState.open
  confirmState.open = null
  q?.resolve(!!yes)
}
