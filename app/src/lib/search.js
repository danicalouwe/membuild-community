/**
 * The words a search looks for, split the same way as the server's
 * Search::words(): lower case, at least 2 characters, up to 6.
 * Used to highlight matches and to skip searching for a single letter.
 */
export function searchWords(q) {
  const words = String(q || '')
    .toLowerCase()
    .split(/[\s,.;:!?()"'“”‘’]+/u)
    .filter((w) => [...w].length >= 2)
  return [...new Set(words)].slice(0, 6)
}
