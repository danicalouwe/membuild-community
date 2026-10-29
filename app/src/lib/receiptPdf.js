import { t } from './i18n.js'

/**
 * A receipt as a real PDF file, saved straight to the member's computer
 * ("Membership Makers – Receipt INV-5.pdf"). jsPDF is loaded only when someone
 * presses the button, so it costs nothing on any other page.
 *
 * r: the receipt from GET /me/billing/{id}/receipt/{payment}; when, every: the
 * date and "$49.00 a month" as shown in the box; logo: the community's logo URL.
 */
export async function saveReceiptPdf(r, { when, every, logo, accent, stateLabel }) {
  const { jsPDF } = await import('jspdf')
  const doc = new jsPDF({ unit: 'pt', format: 'a4' })
  const W = doc.internal.pageSize.getWidth()
  const L = 56
  const R = W - 56
  let y = 64

  // The built-in PDF font knows Western letters only: other characters would print as boxes.
  const clean = (s) => String(s ?? '').replace(/[−‒–—]/g, '-').replace(/[‘’]/g, "'").replace(/[“”]/g, '"')

  // Logo (or the initials in the community's colour), then the name.
  const img = logo ? await toDataUrl(logo).catch(() => null) : null
  if (img) {
    doc.addImage(img, 'PNG', L, y - 20, 32, 32)
  } else {
    doc.setFillColor(accent || '#c8f53e')
    doc.roundedRect(L, y - 20, 32, 32, 7, 7, 'F')
    doc.setFont('helvetica', 'bold')
    doc.setFontSize(12)
    doc.setTextColor(22, 24, 29)
    doc.text(initials(r.store), L + 16, y, { align: 'center' })
  }
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(14)
  doc.setTextColor(22, 24, 29)
  doc.text(clean(r.store), L + 44, y)
  y += 24
  line(doc, L, R, y)

  y += 40
  doc.setFontSize(24)
  doc.text(clean(t.__('Receipt', 'membuild-community')), L, y)
  doc.setFontSize(11)
  doc.setTextColor(...({ paid: [31, 122, 73], failed: [180, 35, 24] }[r.state] || [93, 100, 112]))
  doc.text(clean(stateLabel), R, y, { align: 'right' })
  y += 20
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(11)
  doc.setTextColor(93, 100, 112)
  doc.text(clean(`${r.number} · ${when}`), L, y)

  y += 32
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(9)
  doc.text(clean(t.__('Billed to', 'membuild-community')).toUpperCase(), L, y)
  y += 16
  doc.setFont('helvetica', 'normal')
  doc.setFontSize(11)
  doc.setTextColor(22, 24, 29)
  doc.text(clean(`${r.name} · ${r.email}`), L, y)

  y += 26
  for (const l of r.lines) {
    line(doc, L, R, y)
    y += 22
    doc.setTextColor(22, 24, 29)
    doc.text(clean(l.title), L, y)
    doc.setFont('helvetica', 'bold')
    doc.text(clean(l.amount), R, y, { align: 'right' })
    doc.setFont('helvetica', 'normal')
    const sub = l.detail || every
    if (sub) {
      y += 15
      doc.setFontSize(10)
      doc.setTextColor(93, 100, 112)
      doc.text(clean(sub), L, y)
      doc.setFontSize(11)
    }
    y += 16
  }
  const row = (label, value, bold = false) => {
    line(doc, L, R, y)
    y += 22
    doc.setFont('helvetica', bold ? 'bold' : 'normal')
    doc.setFontSize(bold ? 12 : 11)
    doc.setTextColor(bold ? 22 : 93, bold ? 24 : 100, bold ? 29 : 112)
    doc.text(clean(label), L, y)
    doc.setTextColor(22, 24, 29)
    doc.setFontSize(bold ? 14 : 11)
    doc.text(clean(value), R, y, { align: 'right' })
    y += 14
  }
  row(t.__('Subtotal', 'membuild-community'), r.subtotal)
  if (r.discount) row(t.__('Discount', 'membuild-community'), `-${r.discount}`)
  if (r.tax) row(t.__('Tax', 'membuild-community'), r.tax)
  row(r.state === 'refunded' ? t.__('Refunded', 'membuild-community') : t.__('Total paid', 'membuild-community'), r.total, true)
  row(t.__('Paid with', 'membuild-community'), r.paid)

  const file = `${r.store} - ${t.__('Receipt', 'membuild-community')} ${r.number}.pdf`.replace(/[\\/:*?"<>|]+/g, '')
  doc.save(file)
  return file
}

function line(doc, a, b, y) {
  doc.setDrawColor(227, 229, 232)
  doc.setLineWidth(0.8)
  doc.line(a, y, b, y)
}

const initials = (name) =>
  String(name || 'MB')
    .split(/\s+/)
    .map((w) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()

// The logo as a PNG data URL (it's on the community's own site, so the canvas may read it).
function toDataUrl(src) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => {
      try {
        const c = document.createElement('canvas')
        c.width = 128
        c.height = 128
        const ctx = c.getContext('2d')
        // Rounded like on screen.
        ctx.beginPath()
        ctx.roundRect(0, 0, 128, 128, 28)
        ctx.clip()
        ctx.drawImage(img, 0, 0, 128, 128)
        resolve(c.toDataURL('image/png'))
      } catch (e) {
        reject(e)
      }
    }
    img.onerror = reject
    img.src = src
  })
}
