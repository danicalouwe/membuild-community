/**
 * Plays a media item in a <video>: a plain file for videos stored on the site,
 * or an adaptive stream for Bunny Stream videos. Safari plays those streams
 * natively; other browsers get hls.js, which only downloads when such a video
 * is actually shown.
 *
 * Use as <video v-stream="media" />. A Bunny video that is still encoding
 * gets no source yet; show its poster and "Processing…" (see isProcessing).
 */
export const isProcessing = (media) => !!media?.stream && media.stream.status !== 'ready'

let HlsClass = null
const loadHls = () => (HlsClass ??= import('hls.js/light').then((m) => m.default))

function detach(el) {
  el._mbcHls?.destroy()
  el._mbcHls = null
}

async function attach(el, media) {
  const url = media && !isProcessing(media) ? media.url : null
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
