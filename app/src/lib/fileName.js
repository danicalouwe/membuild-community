/**
 * A tidy file name for a download, from its parts: "Membership Makers - Certificate - Own your
 * community.pdf". Each part loses what Windows, Macs and phones won't take in a file name
 * (A/B Testing → A-B Testing, Course: Basics → Course - Basics), line breaks and doubled spaces,
 * dots at either end, and runs past 80 letters (cut at a word). Empty parts are left out.
 */
export function fileName(parts, ext = 'pdf') {
  const clean = (s) => {
    let p = String(s ?? '')
      .replace(/[\u0000-\u001f\u007f]+/g, ' ') // line breaks and other control characters
      .replace(/\s*:\s*/g, ' - ')
      .replace(/\s*[\\/|]\s*/g, '-')
      .replace(/[*?"<>]+/g, '')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/^[.\s]+|[.\s]+$/g, '') // dots at either end (a leading dot hides the file on a Mac)
    const chars = [...p]
    if (chars.length > 80) {
      p = chars.slice(0, 80).join('')
      const cut = p.lastIndexOf(' ')
      p = (cut > 40 ? p.slice(0, cut) : p).replace(/[\s.,;-]+$/, '')
    }
    return p
  }
  const name = parts.map(clean).filter(Boolean).join(' - ') || 'download'
  return `${name}.${ext}`
}
