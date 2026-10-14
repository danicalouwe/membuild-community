import { shallowRef } from 'vue'

// A video picked from the feed's "Reel" button, handed to the Reels view
// so it can open its upload sheet without asking the member to pick again.
export const pendingReelFile = shallowRef(null)
