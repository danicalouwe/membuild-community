import { toBlob } from './image.js'
import { t } from './i18n.js'

// Kept apart from video.js so grabbing a poster never loads the video encoder.
export const SHORT_SIDE = 720
export const even = (n) => Math.max(2, Math.round(n / 2) * 2)

/**
 * Grabs a JPEG poster frame shortly after the start, so the reel shows a real
 * image instantly while the video loads.
 *
 * @param {Blob} blob
 * @returns {Promise<{blob: Blob, width: number, height: number, duration: number}>}
 */
export function capturePoster(blob) {
  return new Promise((resolve, reject) => {
    const video = document.createElement('video')
    const src = URL.createObjectURL(blob)
    const done = (fn, value) => {
      URL.revokeObjectURL(src)
      video.removeAttribute('src')
      video.load()
      fn(value)
    }
    video.muted = true
    video.playsInline = true
    video.preload = 'auto'
    video.onloadeddata = () => {
      video.currentTime = Math.min(0.5, (video.duration || 1) / 3)
    }
    video.onseeked = async () => {
      const scale = Math.min(1, SHORT_SIDE / Math.min(video.videoWidth, video.videoHeight))
      const canvas = document.createElement('canvas')
      canvas.width = even(video.videoWidth * scale)
      canvas.height = even(video.videoHeight * scale)
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
      const poster = await toBlob(canvas, 'image/jpeg', 0.8)
      done(resolve, { blob: poster, width: canvas.width, height: canvas.height, duration: video.duration })
    }
    video.onerror = () => done(reject, new Error(t.__('This video could not be read.', 'membuild-community')))
    video.src = src
  })
}
