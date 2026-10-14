import { nextTick, onBeforeUnmount, ref } from 'vue'
import { pushLayer } from './layers.js'

/**
 * A small dropdown menu: a click outside or Escape closes it (Escape returns
 * focus to the trigger), arrow keys move between its items, and it opens
 * with focus on the first item when opened from the keyboard.
 *
 *   const { open: menu, root: menuRoot, trigger: menuTrigger, list: menuList, toggle: toggleMenu, close: closeMenu, onKeydown: onMenuKey } = useMenu()
 *   <div :ref="menuRoot"> <button :ref="menuTrigger" :aria-expanded="menu" aria-haspopup="menu" @click="toggleMenu">
 *   <div v-if="menu" :ref="menuList" role="menu" @keydown="onMenuKey"> <button role="menuitem" …>
 *
 * Listeners exist only while it is open, so a feed of cards with menus costs nothing.
 */
export function useMenu() {
  const open = ref(false)
  let rootEl = null
  let triggerEl = null
  let listEl = null
  let remove = null

  const items = () => (listEl ? [...listEl.querySelectorAll('[role="menuitem"]:not([disabled]), button:not([disabled]), a[href]')] : [])

  function onDoc(e) {
    if (rootEl && !rootEl.contains(e.target)) close({ focus: false })
  }

  function show(focusFirst = false) {
    if (open.value) return
    open.value = true
    document.addEventListener('pointerdown', onDoc)
    remove = pushLayer({ root: () => listEl, close, trap: false })
    if (focusFirst) nextTick(() => items()[0]?.focus())
  }

  /** Closes the menu. Focus goes back to the trigger if it was inside the menu (or on Escape). */
  function close({ focus = true } = {}) {
    if (!open.value) return
    const hadFocus = listEl?.contains(document.activeElement)
    open.value = false
    document.removeEventListener('pointerdown', onDoc)
    remove?.()
    remove = null
    if (focus || hadFocus) triggerEl?.focus({ preventScroll: true })
  }

  // A click with detail 0 came from the keyboard (Enter or Space on the trigger).
  const toggle = (e) => (open.value ? close() : show(e?.detail === 0))

  function onKeydown(e) {
    const list = items()
    const i = list.indexOf(document.activeElement)
    let next = null
    if (e.key === 'ArrowDown') next = list[(i + 1) % list.length]
    else if (e.key === 'ArrowUp') next = list[(i - 1 + list.length) % list.length]
    else if (e.key === 'Home') next = list[0]
    else if (e.key === 'End') next = list.at(-1)
    if (next) {
      e.preventDefault()
      next.focus()
    }
  }

  onBeforeUnmount(() => close({ focus: false }))

  return {
    open,
    // Function refs, so they work inside v-if and from destructured objects.
    root: (el) => (rootEl = el),
    trigger: (el) => (triggerEl = el),
    list: (el) => (listEl = el),
    toggle,
    show,
    close,
    onKeydown,
  }
}
