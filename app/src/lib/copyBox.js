import { reactive } from 'vue'

/**
 * When the app can't copy a link by itself (a plain http site, an old browser), the link shows in
 * our own box, already selected, never the browser's prompt box (#147). Shown by CopyLinkHost
 * (in App.vue), in the same look as every "are you sure?" box.
 *
 *   showCopyBox(url, t.__('Copy this link', 'membuild-community'))
 */
export const copyBox = reactive({ open: null })

export function showCopyBox(url, title) {
  copyBox.open = { url, title, id: (copyBox.open?.id || 0) + 1 }
}

export function closeCopyBox() {
  copyBox.open = null
}
