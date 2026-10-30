import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Pull down to refresh, as on Facebook (phones, at the very top of the page). Pull past the
 * mark and let go: `refresh` runs while a spinner turns. Sideways swipes (the stories strip,
 * the topic pills) and pages with a window open are left alone.
 */
export const PULL_MARK = 64 // how far (after the drag's resistance) before letting go refreshes

export function usePullToRefresh(refresh) {
  const pull = ref(0)
  const refreshing = ref(false)
  const phone = window.matchMedia('(max-width: 767px)')
  let start = null

  function onStart(e) {
    start = null
    if (!phone.matches || refreshing.value || window.scrollY > 0 || e.touches.length !== 1) return
    if (document.documentElement.style.overflow === 'hidden') return // a window is open
    start = { x: e.touches[0].clientX, y: e.touches[0].clientY, sideways: null }
  }
  function onMove(e) {
    if (!start) return
    const dx = e.touches[0].clientX - start.x
    const dy = e.touches[0].clientY - start.y
    if (start.sideways === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) start.sideways = Math.abs(dx) > Math.abs(dy)
    if (start.sideways || dy <= 0 || window.scrollY > 0) {
      pull.value = 0
      return
    }
    pull.value = Math.min(110, dy * 0.5) // a little resistance, like a rubber band
  }
  async function onEnd() {
    const far = pull.value >= PULL_MARK
    start = null
    pull.value = 0
    if (!far) return
    refreshing.value = true
    navigator.vibrate?.(8)
    try {
      await Promise.all([refresh(), new Promise((r) => setTimeout(r, 600))]) // long enough to see it turn
    } finally {
      refreshing.value = false
    }
  }

  onMounted(() => {
    window.addEventListener('touchstart', onStart, { passive: true })
    window.addEventListener('touchmove', onMove, { passive: true })
    window.addEventListener('touchend', onEnd)
    window.addEventListener('touchcancel', onEnd)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('touchstart', onStart)
    window.removeEventListener('touchmove', onMove)
    window.removeEventListener('touchend', onEnd)
    window.removeEventListener('touchcancel', onEnd)
  })
  return { pull, refreshing }
}
