import { t } from './i18n.js'

export const REACTIONS = [
  { type: 'like', emoji: '👍', label: t._x('Like', 'reaction', 'membuild-community'), color: '#2d88ff' },
  { type: 'love', emoji: '❤️', label: t._x('Love', 'reaction', 'membuild-community'), color: '#f33e58' },
  { type: 'care', emoji: '🥰', label: t._x('Care', 'reaction', 'membuild-community'), color: '#f7b125' },
  { type: 'haha', emoji: '😆', label: t._x('Haha', 'reaction', 'membuild-community'), color: '#f7b125' },
  { type: 'wow', emoji: '😮', label: t._x('Wow', 'reaction', 'membuild-community'), color: '#f7b125' },
  { type: 'sad', emoji: '😢', label: t._x('Sad', 'reaction', 'membuild-community'), color: '#f7b125' },
  { type: 'angry', emoji: '😡', label: t._x('Angry', 'reaction', 'membuild-community'), color: '#e9710f' },
]

export const reactionFor = (type) => REACTIONS.find((r) => r.type === type) || REACTIONS[0]
export const emojiFor = (type) => reactionFor(type).emoji
