// Sizes a GIF the way Facebook shows one in comments and chats: no more than
// 240px on its longest side, keeping its own shape (square when unknown).
export function gifBox(m, max = 240) {
  const w = m?.width || 1
  const h = m?.height || 1
  const scale = max / Math.max(w, h)
  return { width: `${Math.round(w * scale)}px`, aspectRatio: `${w} / ${h}` }
}
