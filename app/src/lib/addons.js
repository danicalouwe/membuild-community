/**
 * Add-ons (theme contract 1): paid plugins, one per feature (Check-ins, Pathway, Calendar…), that
 * plug into the same app. An add-on prints a script on membuild_community/app_head at priority 5
 * (themes use 10, so add-ons are there first) and says it's here:
 *
 *   window.MBCommunity.ready((mb) => mb.registerAddon('checkins', {
 *     name: 'Check-ins', version: '1.0.0', contract: 1,
 *     data: { today: () => mb.request('/addons/checkins/today') },
 *     slots: { 'checkins.card': { kind: 'replace', about: 'The check-in card.', props: { day: 'the day' } } },
 *   }))
 *
 * An add-on can also add its own section to Settings (owners only, like all of Settings):
 *
 *   settings: { id: 'crm', label: 'CRM', icon: 'users', after: 'access', summary: 'Tags and contacts', component: CrmSettings }
 *
 * It goes after the core section named in `after` (or after Add-ons), in the side list and the phone
 * list, at /settings/<id> (/settings/crm; old /settings#s-crm links forward there). Register from
 * membuild_community/app_head, before the app starts, so a link straight to the page finds it.
 * Point membuild_community/addon_open_url at the page too. The component draws its own card, saves by
 * itself (not with the Save bar) and should give its outer element id="s-<id>". If it fails, the
 * rest of Settings carries on and the Problems page says which add-on.
 *
 * Then mb.addons.checkins is { name, version } (read-only and live, so a theme's piece that checks
 * it redraws when the add-on arrives), mb.data.checkins holds its functions, and its slots are in
 * mb.slots. Slot names start with the add-on's id, so add-ons never clash with the core or each other.
 *
 * mb.request is the community's own request (log-in check, a fresh one when a tab sat open, plain
 * error messages), for an add-on's own server routes only: membuild-community/v1/addons/<id>/…,
 * which the add-on registers and guards itself. The core's own routes stay private.
 *
 * Contract 1 promise: registerAddon, addons and request are only ever added to.
 */
import { markRaw, reactive, readonly } from 'vue'
import { request as coreRequest } from '../api.js'
import { coreData } from './data.js'
import { CONTRACT, addSlots } from './slots.js'
import { reportProblem } from './problems.js'

const list = reactive({})
export const addons = readonly(list)

// Settings sections that add-ons added, in the order they arrived (SettingsView places them).
const sections = reactive([])
export const addonSections = readonly(sections)
const SECTION_ID = /^[a-z][a-z0-9-]{1,30}$/

// mb.data: the core's namespaces, then each add-on's under its id. None can be swapped out later.
export const data = {}
const keep = (key, fns) => Object.defineProperty(data, key, { value: Object.freeze({ ...fns }), enumerable: true, writable: false, configurable: false })
Object.entries(coreData).forEach(([key, fns]) => keep(key, fns))

const ID = /^[a-z][a-z0-9]{1,30}$/

export function registerAddon(id, info = {}) {
  const name = String(info?.name || id)
  const contract = Number(info?.contract || 1)
  if (!ID.test(String(id || ''))) {
    reportProblem(new Error(`An add-on tried to register as “${id}”. Use short lowercase letters and numbers, starting with a letter (“checkins”).`), 'add-on', 0, 'register')
    return false
  }
  if (contract > CONTRACT) {
    reportProblem(new Error(`${name} is built for theme contract ${contract}; this version of the community knows contract ${CONTRACT}, so it's left out. Update the community plugin.`), `add-on: ${id}`, 0, 'register')
    return false
  }
  if (list[id] || id in data) {
    reportProblem(new Error(`Two add-ons (or an add-on and the community) use the id “${id}”; ${name} is left out.`), `add-on: ${id}`, 0, 'register')
    return false
  }
  const set = info.settings
  if (set && (!set.component || !SECTION_ID.test(String(set.id || id)) || sections.some((x) => x.id === String(set.id || id)))) {
    reportProblem(new Error(`${name} tried to add a Settings section without a component, or with an id that's taken.`), `add-on: ${id}`, 0, 'register')
  } else if (set) {
    sections.push({ id: String(set.id || id), label: String(set.label || name), icon: String(set.icon || 'box'), after: String(set.after || 'addons'), summary: String(set.summary || ''), component: markRaw(set.component), addon: id })
  }
  keep(id, info.data && typeof info.data === 'object' ? info.data : {})
  const slots = addSlots(id, info.slots)
  list[id] = Object.freeze({ name, version: String(info.version || ''), slots: Object.freeze(slots) })
  return true
}

/** The community's request, for add-ons' own routes under /addons/. */
export function request(path, { method = 'GET', params, body } = {}) {
  const p = String(path || '')
  if (!/^\/addons\/[a-z][a-z0-9]{1,30}(\/|$)/.test(p) || p.includes('..')) {
    return Promise.reject(new Error(`mb.request only reaches add-on routes (“/addons/<id>/…”), not “${p}”.`))
  }
  return coreRequest(p, { method: String(method).toUpperCase(), params, body })
}
