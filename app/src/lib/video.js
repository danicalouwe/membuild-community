import {
  BlobSource,
  BufferTarget,
  Conversion,
  Input,
  MP4,
  Mp4OutputFormat,
  Output,
  QTFF,
  Quality,
  WEBM,
  canEncodeVideo,
} from 'mediabunny'

// What phones and cameras record: MP4, QuickTime (.mov from iPhones) and WebM.
// Naming just these (not ALL_FORMATS) keeps the other demuxers out of the bundle.
const FORMATS = [MP4, QTFF, WEBM]

import { even, SHORT_SIDE } from './poster.js'
import { t } from './i18n.js'

const BITRATE = 2_000_000

/**
 * Re-encodes a video in the browser to 720p H.264 MP4 with the index at the
 * front (fast start) and frequent keyframes, trimmed to `maxSeconds`.
 * Falls back to the original file when the browser cannot encode video.
 *
 * @param {File} file
 * @param {{maxSeconds?: number, onProgress?: (fraction: number) => void, signal?: AbortSignal}} opts
 * @returns {Promise<{blob: Blob, width: number, height: number, duration: number, compressed: boolean}>}
 */
export async function compressVideo(file, { maxSeconds = 60, onProgress, signal } = {}) {
  const input = new Input({ source: new BlobSource(file), formats: FORMATS })
  const track = await input.getPrimaryVideoTrack()
  if (!track) throw new Error(t.__('This file has no video.', 'membuild-community'))

  const duration = await input.computeDuration()
  const scale = Math.min(1, SHORT_SIDE / Math.min(track.displayWidth, track.displayHeight))
  const width = even(track.displayWidth * scale)
  const height = even(track.displayHeight * scale)
  const end = Math.min(duration, maxSeconds)

  const fallback = { blob: file, width: track.displayWidth, height: track.displayHeight, duration: end, compressed: false }
  const canEncode =
    typeof VideoEncoder !== 'undefined' && (await canEncodeVideo('avc', { width, height, bitrate: BITRATE }).catch(() => false))
  if (!canEncode) {
    if (duration > maxSeconds + 0.5) {
      throw new Error(t.sprintf(/* translators: %d: the longest clip allowed, in seconds */ t._n('This browser can\'t trim video. Please choose a clip under %d second.', 'This browser can\'t trim video. Please choose a clip under %d seconds.', maxSeconds, 'membuild-community'), maxSeconds))
    }
    return fallback
  }

  const output = new Output({ format: new Mp4OutputFormat({ fastStart: 'in-memory' }), target: new BufferTarget() })
  const conversion = await Conversion.init({
    input,
    output,
    trim: { start: 0, end },
    video: { width, height, fit: 'contain', codec: 'avc', quality: new Quality(BITRATE), keyFrameInterval: 2, forceTranscode: true },
  })
  if (!conversion.isValid) {
    if (duration > maxSeconds + 0.5) {
      throw new Error(t.sprintf(/* translators: %d: the longest clip allowed, in seconds */ t._n('Please choose a clip under %d second.', 'Please choose a clip under %d seconds.', maxSeconds, 'membuild-community'), maxSeconds))
    }
    return fallback
  }

  const abort = () => conversion.cancel()
  signal?.addEventListener('abort', abort, { once: true })
  conversion.onProgress = (p) => onProgress?.(p)
  try {
    await conversion.execute()
  } finally {
    signal?.removeEventListener('abort', abort)
  }

  const blob = new Blob([output.target.buffer], { type: 'video/mp4' })
  // A short, already-small MP4 can come out larger; keep whichever is smaller.
  if (blob.size >= file.size && file.type === 'video/mp4' && duration <= maxSeconds + 0.5) return fallback
  return { blob, width, height, duration: end, compressed: true }
}
