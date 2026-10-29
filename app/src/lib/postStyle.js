import { t } from './i18n.js'

// Keys must match Helpers::BACKGROUNDS / Helpers::FEELINGS on the server.

export const BACKGROUNDS = {
  sunset: 'linear-gradient(135deg, #ff7a59 0%, #ff3d77 100%)',
  ocean: 'linear-gradient(135deg, #2bc0e4 0%, #3b5bfd 100%)',
  forest: 'linear-gradient(135deg, #1f9d6b 0%, #0b5d4b 100%)',
  grape: 'linear-gradient(135deg, #8e54e9 0%, #4776e6 100%)',
  peach: 'linear-gradient(135deg, #ffd3a5 0%, #fd6585 100%)',
  night: 'linear-gradient(135deg, #232526 0%, #414345 100%)',
  candy: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
  lime: 'linear-gradient(135deg, #c6ea8d 0%, #2fa36b 100%)',
}

/**
 * Text story backgrounds, in the order the story creator offers them: the community's own colour
 * first (dark words on it, since it may be light), then the post ones and four more.
 * Keys must match Stories::backgrounds() on the server. Lime stays readable for older stories.
 */
const STORY_EXTRA = {
  sun: 'linear-gradient(135deg, #f7971e 0%, #ffd200 100%)',
  blue: 'linear-gradient(135deg, #2f8cf0 0%, #1565c0 100%)',
  fire: 'linear-gradient(135deg, #f12711 0%, #f5af19 100%)',
  slate: 'linear-gradient(135deg, #5b6472 0%, #374151 100%)',
}
export const STORY_BACKGROUNDS = ['community', 'peach', 'grape', 'forest', 'sun', 'night', 'candy', 'ocean', 'sunset', 'blue', 'fire', 'slate']

/** CSS for a text story's background (and its words' colour). */
export function storyBg(key) {
  if (key === 'community') return { background: 'var(--accent)', color: 'var(--accent-ink)' }
  return { background: STORY_EXTRA[key] || BACKGROUNDS[key] || BACKGROUNDS.grape }
}

/** Backgrounds apply to short, text-only posts (same rule as the server). */
export const BACKGROUND_MAX = 130

export const FEELINGS = {
  happy: { emoji: '😊', label: t._x('happy', 'feeling', 'membuild-community') },
  blessed: { emoji: '😇', label: t._x('blessed', 'feeling', 'membuild-community') },
  loved: { emoji: '🥰', label: t._x('loved', 'feeling', 'membuild-community') },
  excited: { emoji: '🤩', label: t._x('excited', 'feeling', 'membuild-community') },
  grateful: { emoji: '🙏', label: t._x('grateful', 'feeling', 'membuild-community') },
  motivated: { emoji: '💪', label: t._x('motivated', 'feeling', 'membuild-community') },
  proud: { emoji: '😌', label: t._x('proud', 'feeling', 'membuild-community') },
  relaxed: { emoji: '😎', label: t._x('relaxed', 'feeling', 'membuild-community') },
  inspired: { emoji: '✨', label: t._x('inspired', 'feeling', 'membuild-community') },
  determined: { emoji: '😤', label: t._x('determined', 'feeling', 'membuild-community') },
  curious: { emoji: '🤔', label: t._x('curious', 'feeling', 'membuild-community') },
  tired: { emoji: '😴', label: t._x('tired', 'feeling', 'membuild-community') },
  sad: { emoji: '😢', label: t._x('sad', 'feeling', 'membuild-community') },
  celebrating: { emoji: '🥳', label: t._x('celebrating', 'feeling', 'membuild-community') },
}

export const EMOJIS = [
  '😀', '😂', '🥹', '😊', '😍', '🥰', '😘', '😎', '🤩', '🥳', '😇', '🤔', '😴', '😢', '😭', '😤',
  '😡', '🤯', '😱', '🙏', '👏', '🙌', '💪', '👍', '👎', '🤝', '❤️', '🧡', '💛', '💚', '💙', '💜',
  '🔥', '✨', '🎉', '💯', '✅', '⭐', '🌟', '☀️', '🌈', '☕', '🍕', '🎂', '🎁', '📚', '🏆', '🚀',
]
