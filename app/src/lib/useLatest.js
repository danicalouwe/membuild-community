import { getCurrentScope, onScopeDispose } from 'vue'

/**
 * Guards against stale responses: when a newer request starts (or the
 * component unmounts), the older one's result is ignored.
 *
 *   const latest = useLatest()
 *   async function load() {
 *     const current = latest()
 *     const res = await api.thing()
 *     if (!current()) return
 *     …
 *   }
 */
export function useLatest() {
  let ticket = 0
  if (getCurrentScope()) onScopeDispose(() => ticket++)
  return function begin() {
    const mine = ++ticket
    return () => mine === ticket
  }
}
