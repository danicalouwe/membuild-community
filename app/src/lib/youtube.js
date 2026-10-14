import { t } from './i18n.js'

// Loads YouTube's IFrame Player API once, on first use.
let loading = null

export function loadYouTube() {
  if (window.YT?.Player) return Promise.resolve(window.YT)
  if (loading) return loading
  loading = new Promise((resolve, reject) => {
    const previous = window.onYouTubeIframeAPIReady
    window.onYouTubeIframeAPIReady = () => {
      previous?.()
      resolve(window.YT)
    }
    const script = document.createElement('script')
    script.src = 'https://www.youtube.com/iframe_api'
    script.async = true
    script.onerror = () => {
      loading = null
      reject(new Error(t.__('YouTube could not be reached.', 'membuild-community')))
    }
    document.head.appendChild(script)
  })
  return loading
}
