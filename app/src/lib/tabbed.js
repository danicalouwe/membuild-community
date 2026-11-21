/**
 * Did the keyboard's place get where it is with Tab, or with a click or a tap?
 * Reels and stories use Space to pause. Space should press a button only when someone
 * walked to it with the keyboard; after a click on Like, Space still pauses.
 */
let tabbed = false
if (typeof window !== 'undefined') {
  // Tab, also with Shift or Option (Safari on a Mac walks the buttons with Option+Tab).
  // Ctrl or Cmd with Tab switches browser tabs or windows.
  window.addEventListener('keydown', (e) => { if (e.key === 'Tab' && !e.ctrlKey && !e.metaKey) tabbed = true }, true)
  window.addEventListener('pointerdown', () => { tabbed = false }, true)
}

/** True when the key was pressed on a button someone walked to with Tab, so Space is that button's. */
export const onTabbedButton = (target) => tabbed && target instanceof Element && !!target.closest('button, [role="button"], [role="menuitem"]')
