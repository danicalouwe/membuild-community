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

// `note` shows in yellow (something to know before saying yes); with `plainNote: true` it's the
// grey .info-note instead, for a safe fact ("Their lessons stay.").
// points: short lines in a grey box under the text, each with an icon ({ icon: 'check', text }).
export function askConfirm({ title, text = '', note = '', plainNote = false, points = [], tick = '', confirm, cancel = '', danger = false, safe = false }) {
  confirmState.open?.resolve(false) // a second question replaces the first
  return new Promise((resolve) => {
    confirmState.open = { title, text, note, plainNote, points, tick, confirm, cancel, danger, safe, resolve }
  })
}

export function answerConfirm(yes) {
  const q = confirmState.open
  confirmState.open = null
  q?.resolve(!!yes)
}
