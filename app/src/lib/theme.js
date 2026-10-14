/**
 * The community's accent color. One hex from settings becomes the light and
 * dark tokens in styles.css, with readable text on top of it.
 *
 * The server (PHP) runs the identical algorithm for the first paint, so any
 * change here must be mirrored there.
 */
const WHITE = '#ffffff'
const BLACK = '#000000'
const INK_DARK = '#111214'
const DARK_SURFACE = '#18191c'
const AA = 4.5

const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16))
const toHex = (channels) => '#' + channels.map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')

/** WCAG relative luminance of a #rrggbb color. */
export function luminance(hex) {
  const [r, g, b] = rgb(hex).map((v) => {
    const c = v / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/** WCAG contrast ratio between two colors, from 1 to 21. */
export function contrast(a, b) {
  const [la, lb] = [luminance(a), luminance(b)]
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

/** Plain RGB interpolation: `weight` of `a`, the rest of `b` (like color-mix in srgb). */
export function mix(a, b, weight) {
  const [ca, cb] = [rgb(a), rgb(b)]
  return toHex(ca.map((v, i) => v * weight + cb[i] * (1 - weight)))
}

/** White or near-black, whichever reads better on `bg`. */
export function inkOn(bg) {
  return contrast(WHITE, bg) >= contrast(INK_DARK, bg) ? WHITE : INK_DARK
}

/** `hex`, or `hex` mixed toward `toward` in 5% steps until it reaches 4.5:1 on `bg`. */
export function readableOn(hex, bg, toward) {
  for (let step = 0; step <= 20; step++) {
    const c = mix(hex, toward, 1 - step * 0.05)
    if (contrast(c, bg) >= AA) return c
  }
  return toward
}

/** The dark-mode accent: very dark accents (like black) are lifted toward white so they don't vanish. */
export function darkAccent(hex) {
  const l = luminance(hex)
  if (l < 0.08) return mix(hex, WHITE, 0.25)
  if (l > 0.45) return hex // a bright accent already glows on dark backgrounds
  return mix(hex, WHITE, 0.85)
}

/** Every accent token for both color schemes. */
export function accentTokens(hex) {
  const dark = darkAccent(hex)
  return {
    light: { accent: hex, ink: inkOn(hex), text: readableOn(hex, WHITE, BLACK) },
    dark: { accent: dark, ink: inkOn(dark), text: readableOn(dark, DARK_SURFACE, WHITE) },
  }
}

let style = null

export function applyAccent(hex) {
  style ??= document.head.appendChild(document.createElement('style'))
  if (!/^#[0-9a-f]{6}$/i.test(hex || '')) {
    style.textContent = '' // back to the built-in blue
    return
  }
  hex = hex.toLowerCase()
  const { light, dark } = accentTokens(hex)
  style.textContent = `
:root { --accent: ${light.accent}; --accent-ink: ${light.ink}; --accent-text: ${light.text}; --accent-soft: color-mix(in srgb, ${hex} 12%, #ffffff); }
@media (prefers-color-scheme: dark) {
  :root { --accent: ${dark.accent}; --accent-ink: ${dark.ink}; --accent-text: ${dark.text}; --accent-soft: color-mix(in srgb, ${hex} 24%, #18191c); }
}`
}
