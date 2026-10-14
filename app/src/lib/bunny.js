import { request } from '../api.js'
import { t } from './i18n.js'

const CHUNK = 8 * 1024 * 1024 // 8 MB pieces: resumable, and small enough to retry quickly
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const b64 = (s) => btoa(unescape(encodeURIComponent(s)))

/**
 * Uploads a video straight from the browser to Bunny Stream with the "tus"
 * resumable protocol, so it never passes through the web host. The server
 * creates the video and signs this one upload; we send the bytes and report back.
 *
 * @returns {Promise<object>} the media row (status "processing" until Bunny has encoded it)
 */
export async function uploadToBunny(blob, { name, meta = {}, onProgress, signal } = {}) {
  const start = await request('/media/bunny', {
    method: 'POST',
    body: { name, size: blob.size, mime: blob.type || 'video/mp4', ...meta },
    signal,
  })
  const { endpoint, headers, metadata } = start.tus
  const auth = { ...headers, 'Tus-Resumable': '1.0.0' }

  const created = await fetch(endpoint, {
    method: 'POST',
    headers: {
      ...auth,
      'Upload-Length': String(blob.size),
      'Upload-Metadata': `filetype ${b64(metadata.filetype)},title ${b64(metadata.title)}`,
    },
    signal,
  })
  if (!created.ok) throw new Error(t.__('Bunny refused the upload. Check the video settings.', 'membuild-community'))
  const location = new URL(created.headers.get('Location'), endpoint).href

  let offset = 0
  while (offset < blob.size) {
    const piece = blob.slice(offset, offset + CHUNK)
    for (let attempt = 0; ; attempt++) {
      try {
        const res = await fetch(location, {
          method: 'PATCH',
          headers: { ...auth, 'Upload-Offset': String(offset), 'Content-Type': 'application/offset+octet-stream' },
          body: piece,
          signal,
        })
        if (!res.ok) {
          throw Object.assign(new Error(t.sprintf(/* translators: %d: HTTP status code */ t.__('Upload failed (%d)', 'membuild-community'), res.status)), { status: res.status })
        }
        offset = Number(res.headers.get('Upload-Offset')) || offset + piece.size
        break
      } catch (err) {
        if (signal?.aborted || attempt >= 4) throw err
        await sleep(800 * 2 ** attempt)
        // Ask Bunny how much it already has, then carry on from there.
        const head = await fetch(location, { method: 'HEAD', headers: auth, signal }).catch(() => null)
        const have = Number(head?.headers.get('Upload-Offset'))
        if (Number.isFinite(have) && have >= 0) offset = have
      }
    }
    onProgress?.(offset / blob.size)
  }

  return request(`/media/bunny/${start.media.id}/complete`, { method: 'POST', signal })
}
