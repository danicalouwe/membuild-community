import { t } from './i18n.js'

/**
 * Plays a media item in a <video>: a plain file for videos stored on the site,
 * or an adaptive stream for Bunny Stream videos. Safari plays those streams
 * natively; other browsers get hls.js, which only downloads when such a video
 * is actually shown.
 *
 * Use as <video v-stream="media" />. A Bunny video that is still encoding
 * gets no source yet; show its poster and "Processing…" (see isProcessing).
 */
export const isProcessing = (media) => !!media?.stream && !['ready', 'failed'].includes(media.stream.status)

/** Why a Bunny video won't play, in words for the post, reel or lesson (null: it isn't broken). */
export function videoFailure(media) {
  if (media?.stream?.status !== 'failed') return null
  return media.stream.reason === 'too_big'
    ? { title: t.__('This video is too big', 'membuild-community'), text: t.__('It’s over 2 GB, so it couldn’t be kept. Try a shorter one.', 'membuild-community') }
    : { title: t.__('This video couldn’t be processed.', 'membuild-community'), text: t.__('Try uploading it again.', 'membuild-community') }
}

let HlsClass = null
const loadHls = () => (HlsClass ??= import('hls.js/light').then((m) => m.default))

function detach(el) {
  el._mbcHls?.destroy()
  el._mbcHls = null
}

async function attach(el, media) {
  const url = media && !isProcessing(media) && !videoFailure(media) ? media.url : null
  if (el._mbcUrl === url) return
  el._mbcUrl = url
  detach(el)
  if (!url) {
    el.removeAttribute('src')
    return
  }
  if (media.stream?.type !== 'hls' || el.canPlayType('application/vnd.apple.mpegurl')) {
    el.src = url
    return
  }
  const Hls = await loadHls()
  if (el._mbcUrl !== url) return // the video changed while hls.js was loading
  if (!Hls.isSupported()) {
    el.src = url
    return
  }
  const hls = new Hls({ capLevelToPlayerSize: true, maxBufferLength: 20 })
  hls.loadSource(url)
  hls.attachMedia(el)
  el._mbcHls = hls
}

export const vStream = {
  mounted: (el, { value }) => attach(el, value),
  updated: (el, { value }) => attach(el, value),
  beforeUnmount: (el) => {
    detach(el)
    el._mbcUrl = null
  },
}
