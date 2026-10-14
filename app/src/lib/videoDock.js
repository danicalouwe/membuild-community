import { reactive } from 'vue'

/**
 * The one YouTube/Vimeo video that's playing, shared by the whole app.
 *
 * Its player is drawn once, by VideoDock in App.vue, and only ever repositioned:
 * over its post while the post is on screen, or in a small corner player after
 * scrolling away or opening another page. Moving an <iframe> in the page reloads
 * it, which is why the player never lives inside the feed itself.
 */
export const video = reactive({
  media: null, // the playing embed (a media object from the API), or null
  title: '',
})

// Post cards showing this video register their frame, so the player can sit over one.
const anchors = new Map() // media id -> Set of elements

export function addAnchor(mediaId, el) {
  if (!anchors.has(mediaId)) anchors.set(mediaId, new Set())
  anchors.get(mediaId).add(el)
}

export function removeAnchor(mediaId, el) {
  anchors.get(mediaId)?.delete(el)
}

/** The frame on the page the playing video belongs over, if one is showing. */
export function currentAnchor() {
  for (const el of anchors.get(video.media?.id) || []) {
    if (el.isConnected && el.getClientRects().length) return el
  }
  return null
}

export function playVideo(media) {
  video.media = media
  video.title = media.embed?.title || ''
}

export function stopVideo() {
  video.media = null
}
