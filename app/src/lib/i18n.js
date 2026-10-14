/**
 * Translations, through WordPress's own library (wp.i18n), which the page
 * loads before the app along with the community's language.
 *
 * Always call these as methods with the text domain spelled out, e.g.
 *   t.__('Messages', 'membuild-community')
 *   t.sprintf(t._n('%d comment', '%d comments', n, 'membuild-community'), n)
 * WordPress finds translatable text by scanning for exactly these calls, and
 * only the "t.__(…)" form survives minification.
 * In templates, `t` is available everywhere: {{ t.__('Save', 'membuild-community') }}
 */
const wpI18n = window.wp?.i18n

function format(text, args) {
  let i = 0
  return text.replace(/%(?:(\d+)\$)?([sd%])/g, (m, pos, type) => {
    if (type === '%') return '%'
    const v = args[pos ? Number(pos) - 1 : i++]
    return type === 'd' ? String(parseInt(v, 10)) : String(v)
  })
}

export const t = {
  __: (text, domain) => (wpI18n ? wpI18n.__(text, domain) : text),
  _x: (text, context, domain) => (wpI18n ? wpI18n._x(text, context, domain) : text),
  _n: (single, plural, n, domain) => (wpI18n ? wpI18n._n(single, plural, n, domain) : n === 1 ? single : plural),
  sprintf: (text, ...args) => (wpI18n ? wpI18n.sprintf(text, ...args) : format(text, args)),
}

/** The community's language for dates and numbers, e.g. "de-DE". */
export const locale = (document.documentElement.lang || 'en-US').replace('_', '-')

/**
 * Splits a translated sentence at its placeholders, so a template can style
 * what goes in them: "Tap %1$s, then %2$s." → ['Tap ', 1, ', then ', 2, '.'].
 * Numbers are the 1-based placeholder positions (plain %s counts up in order).
 */
export function pieces(text) {
  let n = 0
  return text.split(/%(?:(\d+)\$)?s/).map((p, i) => (i % 2 ? (p ? Number(p) : ++n) : p))
}

/** The text before and after a sentence's single placeholder: "Replying to %s" → ['Replying to ', '']. */
export function around(text) {
  const [before, , ...rest] = pieces(text)
  return [before, rest.filter((p) => typeof p === 'string').join('')]
}

/** "Maya, Leo and Sam" in the community's language. */
export function listNames(names) {
  try {
    return new Intl.ListFormat(locale, { type: 'conjunction' }).format(names)
  } catch {
    return names.join(', ')
  }
}
