// Builds src/lib/emoji-data.json from emojibase-data (MIT): every emoji with its
// English name, search keywords and category, without skin-tone variants.
// Run after updating emojibase-data:  node scripts/emoji-data.mjs
import { readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const all = JSON.parse(readFileSync(require.resolve('emojibase-data/en/compact.json'), 'utf8'))

// The same test the server applies to a message reaction (Helpers::emoji): no
// letters, digits or punctuation, and at least one real pictograph.
const usable = (e) => !/[\p{L}\p{N}\p{P}\p{Z}<>=+`^|~$]/u.test(e) && /[\u{2190}-\u{2BFF}\u{1F000}-\u{1FAFF}]/u.test(e) && [...e].length <= 8

const GROUPS = [0, 1, 3, 4, 5, 6, 7, 8, 9] // emojibase groups; 2 is "component" (skin tones on their own)
const out = all
  .filter((x) => GROUPS.includes(x.group) && usable(x.unicode))
  .sort((a, b) => a.order - b.order)
  .map((x) => [x.unicode, x.label, (x.tags || []).join(' '), GROUPS.indexOf(x.group)])

writeFileSync(new URL('../src/lib/emoji-data.json', import.meta.url), JSON.stringify(out))
console.log(`${out.length} emoji`)
