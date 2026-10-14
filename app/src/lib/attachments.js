import { reactive } from 'vue'
import { request } from '../api.js'
import { cfg } from '../config.js'
import { prepareImage } from './image.js'
import { uploadBlob } from './upload.js'
import { t } from './i18n.js'

let localId = 0

/**
 * Starts processing and uploading a picked file right away, so by the time
 * the member taps "Post" the upload is usually already done.
 *
 * Returns a reactive attachment: { kind, previewUrl, width, height, status,
 * stage, progress, media, error, cancel(), release() }.
 *
 * The local previews are object URLs that hold the file in memory: cancel()
 * (removed or discarded) and release() (the saved media now shows instead)
 * let them go. cancel() also deletes whatever already reached the server, so a
 * photo or video the member didn't post doesn't stay behind.
 */
export function startAttachment(file, { compress = true, bunny = true } = {}) {
  const kind = file.type.startsWith('video/') ? 'video' : file.type.startsWith('image/') ? 'image' : 'file'
  const controller = new AbortController()
  const item = reactive({
    key: `local-${++localId}`,
    kind,
    previewUrl: URL.createObjectURL(file),
    poster: null,
    width: 0,
    height: 0,
    status: 'working', // working | done | error
    stage: kind === 'video' && compress ? t._x('Preparing', 'upload stage', 'membuild-community') : t._x('Uploading', 'upload stage', 'membuild-community'),
    name: file.name,
    progress: 0,
    media: null,
    posterId: null, // a video's poster frame, uploaded before the video itself
    error: null,
    cancel: () => {
      controller.abort()
      release()
      // The server only deletes the member's own uploads that nothing uses yet.
      for (const id of [item.media?.id, item.media ? null : item.posterId]) {
        if (id) request(`/media/${id}`, { method: 'DELETE' }).catch(() => {}) // left over, the hourly cleanup removes it
      }
    },
    release: () => release(),
  })
  function release() {
    for (const key of ['previewUrl', 'poster']) {
      if (item[key]?.startsWith('blob:')) URL.revokeObjectURL(item[key])
    }
  }
  item.promise = run(item, file, controller.signal, compress, bunny).catch((err) => {
    if (controller.signal.aborted) return
    item.status = 'error'
    item.error = err.message || t.__('Upload failed', 'membuild-community')
  })
  return item
}

/** Videos go to Bunny Stream when the admin turned it on (stories excepted); everything else to the site. */
async function uploadVideo(blob, opts, bunny) {
  if (bunny && cfg.community.video === 'bunny') {
    const { uploadToBunny } = await import('./bunny.js')
    return uploadToBunny(blob, opts)
  }
  return uploadBlob(blob, opts)
}

async function run(item, file, signal, compress, bunny) {
  if (item.kind === 'file') {
    item.media = await uploadBlob(file, { name: file.name, signal, onProgress: (p) => (item.progress = p) })
  } else if (item.kind === 'video' && !compress) {
    // Lesson videos can be long: upload the original, with a poster frame for the player.
    const { capturePoster } = await import('./poster.js')
    const poster = await capturePoster(file).catch(() => null)
    if (poster) item.poster = URL.createObjectURL(poster.blob)
    const posterMedia = poster ? await uploadBlob(poster.blob, { name: 'poster.jpg', meta: { width: poster.width, height: poster.height }, signal }) : null
    item.posterId = posterMedia?.id ?? null
    item.media = await uploadVideo(
      file,
      {
        name: file.name,
        meta: { width: poster?.width || 0, height: poster?.height || 0, duration: poster?.duration || 0, poster_media_id: posterMedia?.id },
        signal,
        onProgress: (p) => (item.progress = p),
      },
      bunny,
    )
  } else if (item.kind === 'image') {
    const { blob, width, height } = await prepareImage(file)
    Object.assign(item, { width, height })
    item.media = await uploadBlob(blob, {
      name: file.name,
      meta: { width, height },
      signal,
      onProgress: (p) => (item.progress = p),
    })
  } else {
    item.stage = t._x('Compressing', 'upload stage', 'membuild-community')
    // The video encoder is large, so it only downloads when someone actually picks a video.
    const [{ compressVideo }, { capturePoster }] = await Promise.all([import('./video.js'), import('./poster.js')])
    const video = await compressVideo(file, {
      maxSeconds: cfg.limits.reelSeconds,
      signal,
      onProgress: (p) => (item.progress = p * 0.5),
    })
    const poster = await capturePoster(video.blob)
    Object.assign(item, { width: video.width, height: video.height })
    item.poster = URL.createObjectURL(poster.blob)

    item.stage = t._x('Uploading', 'upload stage', 'membuild-community')
    const posterMedia = await uploadBlob(poster.blob, {
      name: 'poster.jpg',
      meta: { width: poster.width, height: poster.height },
      signal,
    })
    item.posterId = posterMedia.id
    item.media = await uploadVideo(
      video.blob,
      {
        name: file.name.replace(/\.[^.]+$/, '') + '.mp4',
        meta: { width: video.width, height: video.height, duration: video.duration, poster_media_id: posterMedia.id },
        signal,
        onProgress: (p) => (item.progress = 0.5 + p * 0.5),
      },
      bunny,
    )
  }
  item.progress = 1
  item.status = 'done'
  item.stage = t._x('Ready', 'upload stage', 'membuild-community')
}
