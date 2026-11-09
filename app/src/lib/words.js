/**
 * The words layer (theme contract 1): a theme renames the community's own words everywhere. It sets
 * them in PHP (the membuild_community/words filter, see includes/class-words.php), which prints them
 * in the page's config, because tab names like Home are made before a theme's script runs.
 *
 * The swap happens after a sentence is translated, so sentences and translation files never change.
 * It only runs on English sites: other languages change their word forms too much to guess, and keep
 * their full translation. These rules are the same in class-words.php; tests/words-cases.json runs
 * against both.
 *
 * What people typed goes into a sentence after the swap, so it's never touched.
 */
export const WORD_FORMS = {
  classroom: ['classroom', 'classrooms'],
  course: ['course', 'courses'],
  lesson: ['lesson', 'lessons'],
  module: ['module', 'modules'],
  home: ['home', 'homes'],
  leaderboard: ['leaderboard', 'leaderboards'],
  member: ['member', 'members'],
  point: ['point', 'points'],
}

// Not our word: a phone's home screen, the site's home page, and other platforms' own screens
// (the import how-to says “in Skool, open Members”, and Skool's tab keeps its name).
const KEEP = '\\b(?:home[ -]screens?|home ?pages?|of course)\\b|<b>members</b>|admin › members › member list'

const capital = (s) => s.charAt(0).toUpperCase() + s.slice(1)
const isCaps = (s) => s.length > 1 && s === s.toUpperCase()
const articleFor = (w, word) => w.a || (/^[aeiou]/i.test(word) ? 'an' : 'a')

/** A function that swaps the words in a sentence. `words` is { course: { one, many, a }, … }. */
export function makeSwapper(words) {
  const forms = {}
  for (const [key, w] of Object.entries(words || {})) {
    if (!WORD_FORMS[key]) continue
    forms[WORD_FORMS[key][0]] = { w, word: w.one }
    forms[WORD_FORMS[key][1]] = { w, word: w.many }
  }
  const names = Object.keys(forms).sort((a, b) => b.length - a.length)
  if (!names.length) return (text) => text
  const pattern = new RegExp(`${KEEP}|\\b(?:(an?)(\\s+))?(${names.join('|')})\\b`, 'gi')
  const seen = new Map()
  return (text) => {
    if (!seen.has(text)) {
      seen.set(
        text,
        String(text).replace(pattern, (match, art, gap, found) => {
          if (!found) return match
          const { w, word } = forms[found.toLowerCase()]
          const caps = isCaps(found)
          const out = caps ? word.toUpperCase() : found[0] === found[0].toUpperCase() ? capital(word) : word
          if (!art) return out
          const a = articleFor(w, word)
          return (caps ? a.toUpperCase() : art[0] === art[0].toUpperCase() ? capital(a) : a) + gap + out
        }),
      )
    }
    return seen.get(text)
  }
}

/** A theme's or add-on's own text: the word to use, or "" where the community has none for this language. */
export function makeWords(config) {
  const list = config?.list || {}
  return Object.freeze({
    get(key, n = 1) {
      const set = list[key]
      if (set) return Number(n) === 1 ? set.one : set.many
      return config?.english && WORD_FORMS[key] ? WORD_FORMS[key][Number(n) === 1 ? 0 : 1] : ''
    },
  })
}
