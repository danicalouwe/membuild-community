import { t } from './i18n.js'

/**
 * A certificate as a real PDF file, landscape (Letter in the US and Canada, A4 elsewhere),
 * saved straight to the member's computer ("Membership Makers – Certificate – Own your community.pdf").
 * The owner's logo and main color, the member's name as it is today, the day they really
 * finished, who signed it, and the check link when there is one. jsPDF loads only when someone
 * presses Download PDF, like the receipts (lib/receiptPdf.js).
 *
 * cert: a certificate from the server (Certificates::format). accent: the main color as a hex
 * string (the server's, or the page's --accent).
 */
export async function saveCertificatePdf(cert, { accent } = {}) {
  const { jsPDF } = await import('jspdf')
  const letter = /^(en-US|en-CA|es-US|fr-CA)$/i.test(navigator.language || '')
  const doc = new jsPDF({ unit: 'pt', format: letter ? 'letter' : 'a4', orientation: 'landscape' })
  const W = doc.internal.pageSize.getWidth()
  const H = doc.internal.pageSize.getHeight()
  const C = W / 2
  const color = hex(cert.accent || accent) || [200, 245, 66]
  const ink = [22, 24, 29]
  const grey = [93, 100, 112]

  // Text, centred and wrapped. Long text gets smaller (down to minSize) to stay within maxLines,
  // then wraps onto more lines. The built-in PDF font knows Western letters only (Latin-1).
  // Anything else (Łukasz, Ольга, Nguyễn, 山田, محمد, ไทย…) is drawn with this device's own fonts
  // onto a sharp picture, so every script the browser can show prints right, including joined
  // and right-to-left letters.
  const maxW = W - 160
  const fit = (s, { size = 12, minSize = size, maxLines = 1, bold = false } = {}) => {
    const str = clean(s)
    if (!str) return { lines: [], size, lh: 0 }
    const western = WESTERN.test(str)
    let lines
    for (;;) {
      lines = western ? splitWestern(doc, str, size, bold, maxW) : splitAny(str, size, bold, maxW)
      if (lines.length <= maxLines || size <= minSize) break
      size = Math.max(minSize, size - 2)
    }
    return { lines, size, lh: size * 1.22, western, bold }
  }
  // Draws fitted text with its first line at y. Returns the y of the last line.
  const draw = (f, y, rgb = ink) => {
    f.lines.forEach((line, i) => {
      const base = y + i * f.lh
      if (f.western) {
        doc.setFont('helvetica', f.bold ? 'bold' : 'normal')
        doc.setFontSize(f.size)
        doc.setTextColor(...rgb)
        doc.text(line, C, base, { align: 'center' })
      } else {
        const pic = lineImage(line, f.size, f.bold, rgb)
        doc.addImage(pic.url, 'PNG', C - pic.w / 2, base - f.size * 0.92, pic.w, pic.h)
      }
    })
    return y + Math.max(0, f.lines.length - 1) * f.lh
  }
  const text = (s, y, opts = {}) => draw(fit(s, opts), y, opts.rgb)

  // A frame in the main color.
  doc.setDrawColor(...color)
  doc.setLineWidth(6)
  doc.rect(24, 24, W - 48, H - 48)
  doc.setDrawColor(227, 229, 232)
  doc.setLineWidth(0.8)
  doc.rect(40, 40, W - 80, H - 80)

  // The logo in its own shape (at most 120 × 44 points), or the initials on the main color.
  let y = 76
  const logo = cert.logo ? await toDataUrl(cert.logo, 240, 88).catch(() => null) : null
  if (logo) {
    const fit = Math.min(120 / logo.w, 44 / logo.h)
    const lw = logo.w * fit
    const lh = logo.h * fit
    doc.addImage(logo.url, 'PNG', C - lw / 2, y + (44 - lh) / 2, lw, lh)
  } else {
    doc.setFillColor(...color)
    doc.roundedRect(C - 22, y, 44, 44, 10, 10, 'F')
    text(cert.initials || '', y + 28, { size: 16, bold: true, rgb: readable(color) })
  }
  y += 66
  y = text(cert.community, y, { size: 13, bold: true, rgb: grey })

  // The middle: title, name, course and day. Two-line title, name and course at full size would
  // run into the signature, so when the day's line would go below the room left above it, lay
  // it out again smaller: the name first, then the title, then the course, then the spaces.
  const middle = [
    { s: cert.title, gap: 42, size: 30, minSize: 18, maxLines: 2, bold: true },
    { s: t.__('This is to say that', 'membuild-community'), gap: 32, size: 13, rgb: grey },
    { s: cert.name, gap: 46, size: 38, minSize: 16, maxLines: 2, bold: true },
    { s: t.__('finished the course', 'membuild-community'), gap: 32, size: 13, rgb: grey },
    { s: cert.course, gap: 26, size: 20, minSize: 11, maxLines: 2, bold: true },
    { s: `${longDay(cert.finished_on)} · ${lessonsOf(cert.lessons)}`, gap: 24, size: 12, rgb: grey },
  ]
  const lowest = H - 184 // the signature picture (up to 50 points) and its line start below this
  // Each step takes 2 points off one of them: the name down to 28, the title to 24, the course
  // to 16, then the same again down to their smallest, then the spaces between get smaller.
  const smaller = []
  for (const [i, floor] of [[2, 28], [0, 24], [4, 16], [2, 16], [0, 18], [4, 11]]) {
    for (let size = middle[i].size - 2; size >= floor; size -= 2) smaller.push([i, size])
  }
  const layOut = (caps, spaces) => {
    let at = y
    return middle.map((b, i) => {
      const size = Math.min(b.size, caps[i] ?? b.size)
      const f = fit(b.s, { ...b, size, minSize: Math.min(b.minSize ?? b.size, size) })
      const top = at + b.gap * spaces
      at = top + Math.max(0, f.lines.length - 1) * f.lh
      return { f, top, bottom: at, rgb: b.rgb }
    })
  }
  let caps = {}
  let spaces = 1
  let placed = layOut(caps, spaces)
  for (let step = 0; placed[placed.length - 1].bottom > lowest; step++) {
    if (step < smaller.length) {
      const [i, size] = smaller[step]
      if ((caps[i] ?? middle[i].size) <= size) continue
      caps = { ...caps, [i]: size }
    } else if (spaces > 0.6) {
      spaces = Math.max(0.6, spaces - 0.1)
    } else {
      break // the smallest it goes: very long text still prints, and may reach the line
    }
    placed = layOut(caps, spaces)
  }
  placed.forEach((p) => draw(p.f, p.top, p.rgb))

  // Signed by: the signature (if uploaded, at most 150 × 50 points) over a line, the name and their role.
  y = H - 120
  const sig = cert.signature ? await toDataUrl(cert.signature, 360, 120).catch(() => null) : null
  if (sig) {
    const fit = Math.min(150 / sig.w, 50 / sig.h, 1)
    doc.addImage(sig.url, 'PNG', C - (sig.w * fit) / 2, y - sig.h * fit - 4, sig.w * fit, sig.h * fit)
  }
  doc.setDrawColor(...ink)
  doc.setLineWidth(0.8)
  doc.line(C - 90, y, C + 90, y)
  text(cert.signed_by, y + 18, { size: 12, minSize: 9, bold: true })
  text(cert.role, y + 33, { size: 10, minSize: 8, rgb: grey })

  if (cert.check_url) {
    text(`${t.__('Check it at', 'membuild-community')} ${cert.check_url.replace(/^https?:\/\//, '')}`, H - 56, { size: 9, minSize: 7, rgb: grey })
  }

  const file = `${cert.community} - ${t.__('Certificate', 'membuild-community')} - ${cert.course}.pdf`.replace(/[\\/:*?"<>|]+/g, '')
  doc.save(file)
  return file
}

// Text the built-in font can print: Latin-1 (after clean() swaps curly quotes and long dashes).
const WESTERN = /^[\x20-\x7E\xA0-\xFF]*$/
const clean = (s) => String(s ?? '').replace(/[−‒–—]/g, '-').replace(/[‘’]/g, "'").replace(/[“”]/g, '"').replace(/\s+/g, ' ').trim()

function splitWestern(doc, str, size, bold, maxW) {
  doc.setFont('helvetica', bold ? 'bold' : 'normal')
  doc.setFontSize(size)
  return doc.splitTextToSize(str, maxW)
}

// Any script, measured with the device's fonts: wraps at spaces, and between letters for words
// too long for a line (Chinese and Japanese have no spaces).
const FONT = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Helvetica Neue", Arial, sans-serif'
let measurer = null
function ctxFor(size, bold) {
  measurer = measurer || document.createElement('canvas').getContext('2d')
  measurer.font = `${bold ? 700 : 400} ${size}px ${FONT}`
  return measurer
}
function splitAny(str, size, bold, maxW) {
  const ctx = ctxFor(size, bold)
  const fits = (s) => ctx.measureText(s).width <= maxW
  const lines = []
  let line = ''
  const push = (word) => {
    const next = line ? `${line} ${word}` : word
    if (fits(next)) return (line = next)
    if (line) lines.push(line)
    line = ''
    // A word longer than a line: break it between letters (whole characters, never half a pair).
    let part = ''
    for (const ch of Array.from(word)) {
      if (part && !fits(part + ch)) {
        lines.push(part)
        part = ''
      }
      part += ch
    }
    line = part
  }
  str.split(' ').forEach(push)
  if (line) lines.push(line)
  return lines
}
// One line as a PNG at 4 times the size, so it prints sharp.
function lineImage(line, size, bold, rgb) {
  const scale = 4
  const ctx = ctxFor(size, bold)
  const w = Math.ceil(ctx.measureText(line).width) + 4
  const h = Math.ceil(size * 1.25)
  const c = document.createElement('canvas')
  c.width = w * scale
  c.height = h * scale
  const g = c.getContext('2d')
  g.scale(scale, scale)
  g.font = `${bold ? 700 : 400} ${size}px ${FONT}`
  g.fillStyle = `rgb(${rgb.join(',')})`
  g.textAlign = 'center'
  g.textBaseline = 'alphabetic'
  g.fillText(line, w / 2, size * 0.92)
  return { url: c.toDataURL('image/png'), w, h }
}

export const longDay = (iso) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }) : '')
export const shortDay = (iso) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '')
export const lessonsOf = (n) => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', n, 'membuild-community'), n)

function hex(value) {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(value || '').trim())
  if (!m) return null
  const n = parseInt(m[1], 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
// Dark words on a light color, white on a dark one.
const readable = ([r, g, b]) => (0.299 * r + 0.587 * g + 0.114 * b > 150 ? [22, 24, 29] : [255, 255, 255])

// A picture on the community's own site as a PNG data URL, at most w × h pixels, keeping its shape.
// Returns its size in those pixels; the caller fits it on the page.
function toDataUrl(src, w, h) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => {
      try {
        const scale = Math.min(w / img.naturalWidth, h / img.naturalHeight, 1)
        const cw = Math.max(1, Math.round(img.naturalWidth * scale))
        const ch = Math.max(1, Math.round(img.naturalHeight * scale))
        const c = document.createElement('canvas')
        c.width = cw * 2
        c.height = ch * 2
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height)
        resolve({ url: c.toDataURL('image/png'), w: cw, h: ch })
      } catch (e) {
        reject(e)
      }
    }
    img.onerror = reject
    img.src = src
  })
}
