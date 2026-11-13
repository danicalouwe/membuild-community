import qrcode from 'qrcode-generator'

/**
 * A QR code for a link, drawn here with a small bundled library, so the link never goes to an
 * outside service. Returns the number of squares per side and the dark squares as runs along each
 * row ({ r, c, len }), which both the screen (an SVG path) and the PDF (rectangles) draw from.
 * Medium error correction: still reads with a smudge or a fold on a printed certificate.
 */
export function qrRuns(text) {
  const q = qrcode(0, 'M')
  q.addData(plainLink(text))
  q.make()
  const n = q.getModuleCount()
  const runs = []
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) {
      if (!q.isDark(r, c)) continue
      let len = 1
      while (c + len < n && q.isDark(r, c + len)) len++
      runs.push({ r, c, len })
      c += len - 1
    }
  }
  return { n, runs }
}

/**
 * A link written the way every scanner reads it: a non-English domain in its xn-- form and the
 * rest percent-encoded (the library would cut each letter to one byte). Other text goes as UTF-8.
 */
function plainLink(text) {
  try {
    return new URL(String(text)).href
  } catch {
    return unescape(encodeURIComponent(String(text)))
  }
}

/** The dark squares as one SVG path, in squares (viewBox "0 0 n n"). */
export function qrPath(text) {
  const { n, runs } = qrRuns(text)
  return { n, d: runs.map(({ r, c, len }) => `M${c} ${r}h${len}v1h-${len}z`).join('') }
}
