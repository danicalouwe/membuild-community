import { inject, onBeforeUnmount } from 'vue'

/**
 * The community settings form, shared by SettingsView and its sections:
 * { form, meta, saved, savedForm, dirty, logoUpload } (all refs), and `drafts`.
 * The view owns it and saves it in one go from its Save bar.
 */
export const SETTINGS = Symbol('settings')

export const useSettings = () => inject(SETTINGS)

/**
 * A box that saves on its own (a new topic, a membership's name) tells Settings while it holds
 * typed words that aren't saved, so leaving the page asks first, like the Save bar's changes.
 * `has` returns true while there's something to lose; `clear` drops it ("Discard changes").
 * Outside Settings it does nothing.
 */
export function useDraft(has, clear) {
  const drafts = inject(SETTINGS, null)?.drafts
  if (!drafts) return
  const draft = { has, clear }
  drafts.add(draft)
  onBeforeUnmount(() => drafts.delete(draft))
}
