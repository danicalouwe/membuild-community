import { request } from '../api.js'

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/**
 * Uploads a Blob in small chunks, retrying each chunk on flaky connections.
 *
 * @param {Blob} blob
 * @param {{name: string, meta?: object, onProgress?: (fraction: number) => void, signal?: AbortSignal}} opts
 * @returns {Promise<object>} the stored media row
 */
export async function uploadBlob(blob, { name, meta = {}, onProgress, signal } = {}) {
  const session = await request('/media/uploads', {
    method: 'POST',
    body: { mime: blob.type, size: blob.size, name },
    signal,
  })

  const size = session.chunk_bytes
  for (let index = 0; index < session.chunks; index++) {
    const chunk = blob.slice(index * size, (index + 1) * size)
    for (let attempt = 0; ; attempt++) {
      try {
        await request(`/media/uploads/${session.upload_id}/chunks/${index}`, { method: 'POST', raw: chunk, signal })
        break
      } catch (err) {
        if (signal?.aborted || attempt >= 3 || (err.status && err.status < 500 && err.status !== 409)) throw err
        await sleep(500 * 2 ** attempt)
      }
    }
    onProgress?.((index + 1) / session.chunks)
  }

  return request(`/media/uploads/${session.upload_id}/complete`, { method: 'POST', body: meta, signal })
}
