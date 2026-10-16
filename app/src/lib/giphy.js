import { cfg } from '../config.js'
import { locale } from './i18n.js'

/**
 * GIPHY search and trending, straight from the member's browser (GIPHY's terms
 * ask for that: no proxying). Results stay in GIPHY's order and nothing is
 * cached or rewritten. Only on when an admin pasted a key in Settings → GIFs.
 */
export const giphyOn = () => !!cfg.community.giphy?.key

const PAGE = 24

/** One page of GIFs: { items: [{ id, title, preview, still, width, height, pick }], next }. */
export async function gifs(query = '', offset = 0, signal) {
  const { key, rating } = cfg.community.giphy
  const params = new URLSearchParams({ api_key: key, limit: String(PAGE), offset: String(offset), rating, bundle: 'messaging_non_clips' })
  if (query) {
    params.set('q', query)
    params.set('lang', (locale || 'en').slice(0, 2))
  }
  const res = await fetch(`https://api.giphy.com/v1/gifs/${query ? 'search' : 'trending'}?${params}`, { signal })
  if (!res.ok) throw new Error(res.status === 429 ? 'busy' : 'failed')
  const json = await res.json()
  const items = (json.data || []).map((g) => {
    const small = g.images?.fixed_width || {}
    return {
      id: g.id,
      title: g.title || '',
      preview: small.webp || small.url, // the picker grid: small and light
      width: Number(small.width) || 200,
      height: Number(small.height) || 200,
      pick: chosen(g.images || {}),
    }
  }).filter((g) => g.preview && g.pick)
  const total = json.pagination?.total_count ?? 0
  return { items, next: offset + PAGE < total ? offset + PAGE : null }
}

/**
 * What goes in the post: a rendition big enough to look good in the feed but
 * not several megabytes. GIPHY's addresses are used exactly as given.
 */
function chosen(images) {
  const original = images.original || {}
  // The video version is what plays (like Facebook); the image is the fallback.
  const mp4 = original.mp4 || images.downsized_small?.mp4 || images.fixed_width?.mp4 || ''
  const still = images.original_still?.url || ''
  if (original.webp && Number(original.webp_size || 0) <= 4 * 1024 * 1024) {
    return { url: original.webp, mp4, still, width: Number(original.width), height: Number(original.height) }
  }
  const medium = images.downsized_medium || images.downsized || images.fixed_width
  return medium?.url ? { url: medium.url, mp4, still, width: Number(medium.width), height: Number(medium.height) } : null
}
