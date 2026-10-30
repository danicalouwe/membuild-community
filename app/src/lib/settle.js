/**
 * iPhone, once the keyboard closes, can leave a page that doesn't scroll (Reels, Alerts, a
 * short page under the menu) pushed up by the keyboard's top bar, so the bottom bar floats
 * with a blank strip under it. Pages that scroll put themselves back; these get put back here.
 */
const keyboardOpen = () => !!window.visualViewport && window.visualViewport.height < window.innerHeight - 80

export function settlePage() {
  if (!window.scrollY || keyboardOpen()) return
  const el = document.scrollingElement || document.documentElement
  const cantScroll = el.scrollHeight <= window.innerHeight + 1 || getComputedStyle(document.documentElement).overflow === 'hidden'
  if (cantScroll) window.scrollTo(0, 0)
}

export function watchSettle(router) {
  window.visualViewport?.addEventListener('resize', () => setTimeout(settlePage, 60))
  document.addEventListener('focusout', () => setTimeout(settlePage, 350)) // the keyboard closing
  router.afterEach(() => setTimeout(settlePage, 0))
}
