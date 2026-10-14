import { inject } from 'vue'

/**
 * The community settings form, shared by SettingsView and its sections:
 * { form, meta, saved, savedForm, dirty, logoUpload } (all refs).
 * The view owns it and saves it in one go from its Save bar.
 */
export const SETTINGS = Symbol('settings')

export const useSettings = () => inject(SETTINGS)
