import { noAnswerError, request } from '../api.js'

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// A stalled connection never fails on its own. These limits only catch a stall: a chunk may take as long
// as 20 KB a second needs. Two chunks go at a time across all uploads, and a chunk's clock starts when
// its turn comes, so photos sharing a slow connection don't run each other out of time.
const CHUNK_LIMIT = (bytes) => Math.max(60000, bytes / 20)
const START_LIMIT = 30000
const FINISH_LIMIT = (bytes) => 120000 + bytes / 1000 // putting the file together on the server takes time too

const AT_ONCE = 2
let running = 0
const waiting = []
async function takeTurn(run) {
  if (running >= AT_ONCE) await new Promise((go) => waiting.push(go))
  else running++
  try {
    return await run()
  } finally {
    const next = waiting.shift()
    if (next) next() // the turn passes straight on
    else running--
  }
}

async function timed(ms, signal, run) {
  const limit = new AbortController()
  const stop = () => limit.abort()
  if (signal?.aborted) stop()
  signal?.addEventListener('abort', stop, { once: true })
  let stalled = false
  const timer = setTimeout(() => {
    stalled = true
    limit.abort()
  }, ms)
  try {
    return await run(limit.signal)
  } catch (err) {
    if (!stalled) throw err
    throw Object.assign(noAnswerError(), { stalled: true })
  } finally {
    clearTimeout(timer)
    signal?.removeEventListener('abort', stop)
  }
}

/**
 * Uploads a Blob in small chunks, retrying each chunk on flaky connections.
 *
 * @param {Blob} blob
 * @param {{name: string, meta?: object, onProgress?: (fraction: number) => void, signal?: AbortSignal}} opts
 * @returns {Promise<object>} the stored media row
 */
export async function uploadBlob(blob, { name, meta = {}, onProgress, signal } = {}) {
  const session = await timed(START_LIMIT, signal, (limit) =>
    request('/media/uploads', {
      method: 'POST',
      body: { mime: blob.type, size: blob.size, name },
      signal: limit,
    }),
  )

  if (!session) throw noAnswerError() // an answer that wasn't JSON, such as a PHP notice
  const size = session.chunk_bytes
  for (let index = 0; index < session.chunks; index++) {
    const chunk = blob.slice(index * size, (index + 1) * size)
    for (let attempt = 0; ; attempt++) {
      try {
        await takeTurn(() => timed(CHUNK_LIMIT(chunk.size), signal, (limit) => request(`/media/uploads/${session.upload_id}/chunks/${index}`, { method: 'POST', raw: chunk, signal: limit })))
        break
      } catch (err) {
        if (signal?.aborted || attempt >= (err.stalled ? 1 : 3) || (err.status && err.status < 500 && err.status !== 409)) throw err
        await sleep(500 * 2 ** attempt)
      }
    }
    onProgress?.((index + 1) / session.chunks)
  }

  const media = await timed(FINISH_LIMIT(blob.size), signal, (limit) => request(`/media/uploads/${session.upload_id}/complete`, { method: 'POST', body: meta, signal: limit }))
  if (!media) throw noAnswerError()
  return media
}
