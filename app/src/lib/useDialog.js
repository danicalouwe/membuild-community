import { nextTick, onActivated, onBeforeUnmount, onDeactivated, onMounted, toValue, watch } from 'vue'
import { focusables, pushLayer } from './layers.js'

let locks = 0
let savedOverflow = ''

function lockScroll() {
  if (locks++ === 0) {
    savedOverflow = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'
    document.documentElement.dataset.dialogOpen = '' // settle.js leaves the page where it is while a dialog is open
  }
}

function unlockScroll() {
  if (--locks === 0) {
    document.documentElement.style.overflow = savedOverflow
    delete document.documentElement.dataset.dialogOpen
  }
}

/**
 * Makes `panel` (a template ref) behave as a modal dialog while it is open:
 * Escape closes it (only the topmost one), Tab cycles inside it, the page
 * behind stops scrolling, focus moves in, and returns to whatever opened it.
 *
 * `open` defaults to "while mounted"; pass a ref or getter for dialogs that
 * stay mounted. Initial focus goes to `[data-autofocus]`, else to whatever the
 * dialog focused itself on mount, else to the panel.
 */
export function useDialog(panel, { onClose, open = true } = {}) {
  let remove = null
  let opener = null

  function activate() {
    if (remove) return
    opener = document.activeElement
    lockScroll()
    remove = pushLayer({ root: () => toValue(panel), close: () => onClose?.(), trap: true })
    nextTick(() => {
      const el = toValue(panel)
      if (!el || el.contains(document.activeElement)) return
      const target = el.querySelector('[data-autofocus]') || el
      if (target === el && !el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1')
      target.focus({ preventScroll: true })
    })
  }

  function deactivate() {
    if (!remove) return
    remove()
    remove = null
    unlockScroll()
    const back = opener
    opener = null
    // Back to the button that opened it, unless something outside already took focus.
    const now = document.activeElement
    if (back?.isConnected && (!now || now === document.body || toValue(panel)?.contains(now))) back.focus({ preventScroll: true })
  }

  onMounted(() => {
    watch(() => toValue(open), (on) => (on ? activate() : deactivate()), { immediate: true })
  })
  onBeforeUnmount(deactivate)

  // On a page kept in memory (App.vue's KeepAlive), going to another page with the dialog open
  // (the browser's Back, a link inside it): the page is put away, but a dialog moved to <body>
  // stays on screen. Hide it with its page and let go of the scroll lock and focus; coming back
  // shows it again, as you left it.
  let hidden = null
  let wasOpen = false
  onDeactivated(() => {
    if (!remove) return
    wasOpen = true
    deactivate()
    let el = toValue(panel)
    if (!el?.isConnected) return // drawn inside the page: put away with it
    while (el.parentElement && el.parentElement !== document.body) el = el.parentElement
    hidden = el
    hidden.style.display = 'none'
  })
  onActivated(() => {
    if (!wasOpen) return
    wasOpen = false
    if (hidden) hidden.style.display = ''
    hidden = null
    if (toValue(open)) activate()
  })

  return { focusables: () => focusables(toValue(panel)) }
}
