import { computed, reactive } from 'vue'
import { me } from '../stores/me.js'

/**
 * Pop-ups take turns, in one place (bottom-right on computers, above the tabs on
 * phones): "Lesson done" first, then a level-up, then small notes ("Saved"). So two
 * never cover each other. The lesson page says when its card is up; the rest wait.
 */
export const popups = reactive({ lesson: false })

/** The level-up shows once nothing is ahead of it. */
export const levelShown = computed(() => !!me.levelUp && !popups.lesson)

/** Anything bigger than a small note on screen: notes wait for it. */
export const celebrating = computed(() => popups.lesson || levelShown.value)
