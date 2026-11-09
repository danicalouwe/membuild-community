/**
 * Everything that floats above the page (dialogs, sheets, menus) joins one
 * stack, so Escape closes only the topmost layer and Tab stays inside the
 * topmost dialog.
 */
const stack = []

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), video[controls]'

export function focusables(root) {
  if (!root) return []
  // What Tab skips (tabindex -1, like the hidden file pickers buttons open) can't be the first or last stop either.
  return [...root.querySelectorAll(FOCUSABLE)].filter((el) => el.getAttribute('tabindex') !== '-1' && (el.offsetParent !== null || el === document.activeElement))
}

// The <select> whose list is open, as far as the page can tell: opened by a click or by
// Alt+Down / Alt+Up / F4 / Space, closed by choosing, leaving it, or the Escape it takes.
let listOpen = null
if (typeof document !== 'undefined') {
  const selectOf = (e) => (e.target instanceof Element ? e.target.closest('select') : null)
  document.addEventListener('pointerdown', (e) => {
    const s = selectOf(e)
    listOpen = s && listOpen !== s ? s : null
  }, true)
  document.addEventListener('keydown', (e) => {
    const s = selectOf(e)
    if (s && (e.key === 'F4' || e.key === ' ' || (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp')))) listOpen = s
  }, true)
  document.addEventListener('change', (e) => { if (selectOf(e) === listOpen) listOpen = null }, true)
  document.addEventListener('focusout', (e) => { if (selectOf(e) === listOpen) listOpen = null }, true)
}

// Listens on window in the bubble phase, so a field inside a dialog can still
// claim Escape first (e.g. "cancel reply") by stopping it or preventing its default.
function onKeydown(e) {
  const top = stack.at(-1)
  if (!top) return
  if (e.key === 'Escape') {
    if (e.defaultPrevented) return
    // Escape while a dropdown's list is open only closes the list (it asked "Discard this post?"
    // over "Poll closes"). A dropdown that is just focused still lets Escape close the dialog.
    if (listOpen && e.target === listOpen) {
      listOpen = null
      return
    }
    e.preventDefault()
    top.close()
  } else if (e.key === 'Tab') {
    if (!top.trap) {
      top.close({ focus: false }) // tabbing away from a menu closes it, like a native one
      return
    }
    const root = top.root()
    const items = focusables(root)
    if (!items.length) {
      e.preventDefault()
      root?.focus()
      return
    }
    const first = items[0]
    const last = items.at(-1)
    const inside = root.contains(document.activeElement)
    if (e.shiftKey && (document.activeElement === first || !inside)) {
      e.preventDefault()
      last.focus()
    } else if (!e.shiftKey && (document.activeElement === last || !inside)) {
      e.preventDefault()
      first.focus()
    }
  }
}

/** Adds a layer: { root(): Element, close({ focus }), trap: boolean }. Returns its removal. */
export function pushLayer(layer) {
  if (!stack.length) window.addEventListener('keydown', onKeydown)
  stack.push(layer)
  return () => {
    const i = stack.indexOf(layer)
    if (i !== -1) stack.splice(i, 1)
    if (!stack.length) window.removeEventListener('keydown', onKeydown)
  }
}

export const isTopLayer = (layer) => stack.at(-1) === layer
export const hasLayers = () => stack.length > 0
