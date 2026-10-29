<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import { MESSAGE_REACTIONS } from '../lib/reactions.js'
import { t } from '../lib/i18n.js'
import { pushLayer } from '../lib/layers.js'

/**
 * Messenger's reaction picker: the quick six and a "+", floating above the
 * page (outside the chat window, so nothing clips it or adds scrollbars).
 * "+" swaps it for Facebook's panel: a search box, "Your reactions", then every
 * emoji under its category in one scrolling list, with category tabs below.
 * The full list (names and keywords, from emojibase) loads only then. It sits above the message, from the message's own side,
 * kept inside the screen, and opens underneath when there's no room above.
 * Closes on a pick, Escape, a click elsewhere, scrolling or resizing.
 *
 * mode="emoji" is the same panel for typing emoji into a post, comment or
 * chat (opened by a composer's emoji button): straight to the full list, no
 * reactions row, and it stays open for several picks, like Facebook's.
 */
const props = defineProps({
  anchor: { type: Object, required: true }, // the message row's DOMRect
  side: { type: String, default: 'start' }, // start: others' messages (left) · end: yours (right) · center: over a button
  current: { type: String, default: null }, // my reaction, highlighted
  mode: { type: String, default: 'reaction' }, // reaction | emoji
  ignore: { type: Object, default: null }, // the button that opened it: it toggles, so clicks there aren't "elsewhere"
})
const emit = defineEmits(['pick', 'close'])

const el = ref(null)
const all = ref(props.mode === 'emoji')
const pos = ref({ left: 0, top: 0 })
const caret = ref(null) // { x, up } — the little pointer at the button that opened it (composers), as on Facebook
const GAP = 8
const EDGE = 8

// Phones: the full panel is a bottom sheet across the screen, like Facebook's app.
const sheet = ref(false)

function place() {
  const box = el.value?.getBoundingClientRect()
  if (!box) return
  sheet.value = all.value && window.innerWidth < 560
  if (sheet.value) {
    caret.value = null
    pos.value = { left: EDGE, top: Math.max(EDGE, window.innerHeight - box.height - EDGE) }
    return
  }
  const a = props.anchor
  const gap = props.side === 'center' ? GAP + 4 : GAP // room for the pointer
  let left = props.side === 'end' ? a.right - box.width : props.side === 'center' ? a.left + a.width / 2 - box.width / 2 : a.left
  left = Math.max(EDGE, Math.min(left, window.innerWidth - box.width - EDGE))
  let top = a.top - box.height - gap
  const below = top < EDGE
  if (below) top = Math.min(a.bottom + gap, window.innerHeight - box.height - EDGE) // no room above: underneath
  pos.value = { left, top: Math.max(EDGE, top) }
  caret.value = props.side === 'center' ? { x: Math.max(16, Math.min(box.width - 16, a.left + a.width / 2 - left)), up: below } : null
}

// The full list: [emoji, name, keywords, category], ~1,900 of them.
const data = ref([])
const query = ref('')
const search = ref(null)
const list = ref(null)
const CATEGORIES = [
  { icon: '😀', label: t.__('Smileys & emotion', 'membuild-community') },
  { icon: '👋', label: t.__('People & body', 'membuild-community') },
  { icon: '🐻', label: t.__('Animals & nature', 'membuild-community') },
  { icon: '🍔', label: t.__('Food & drink', 'membuild-community') },
  { icon: '✈️', label: t.__('Travel & places', 'membuild-community') },
  { icon: '⚽', label: t.__('Activities', 'membuild-community') },
  { icon: '💡', label: t.__('Objects', 'membuild-community') },
  { icon: '💕', label: t.__('Symbols', 'membuild-community') },
  { icon: '🏳️', label: t.__('Flags', 'membuild-community') },
]
const sections = computed(() => CATEGORIES.map((c, i) => ({ ...c, items: data.value.filter((e) => e[3] === i) })).filter((c) => c.items.length))
const results = computed(() => {
  const words = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean)
  if (!words.length) return null
  // Names that start with the words first, then names that contain them, then keyword matches.
  const rank = (e) => {
    const name = e[1].toLowerCase()
    return words.every((w) => name.split(/\s+/).some((part) => part.startsWith(w))) ? 0 : words.every((w) => name.includes(w)) ? 1 : 2
  }
  return data.value
    .filter((e) => words.every((w) => e[1].toLowerCase().includes(w) || e[2].includes(w)))
    .map((e, i) => [rank(e), i, e])
    .sort((a, b) => a[0] - b[0] || a[1] - b[1])
    .map((x) => x[2])
    .slice(0, 200)
})

async function more() {
  all.value = true
  await nextTick()
  place()
  search.value?.focus()
  data.value = (await import('../lib/emoji-data.json')).default
}

function jump(i) {
  query.value = ''
  nextTick(() => list.value?.querySelector(`[data-cat="${i}"]`)?.scrollIntoView({ block: 'start' }))
}

// "Recently used", on this device only (a convenience, so it lives in the browser).
const RECENT_KEY = 'mbcom-emoji-recent'
function readRecent() {
  try {
    const list = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]')
    return Array.isArray(list) ? list.filter((e) => typeof e === 'string').slice(0, 16) : []
  } catch {
    return []
  }
}
const recent = ref(readRecent())
function choose(emoji) {
  recent.value = [emoji, ...recent.value.filter((e) => e !== emoji)].slice(0, 16)
  try {
    localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value))
  } catch {}
  emit('pick', emoji)
}

// The category tab for whatever is at the top of the list, highlighted as you scroll.
const activeCat = ref(0)
function onListScroll() {
  const box = list.value
  if (!box) return
  let current = 0
  for (const section of box.querySelectorAll('section[data-cat]')) {
    if (section.offsetTop - box.offsetTop - box.scrollTop <= 24) current = Number(section.dataset.cat)
  }
  activeCat.value = current
}

const close = () => emit('close')
function onDown(e) {
  if (el.value && !el.value.contains(e.target) && !props.ignore?.contains?.(e.target)) close()
}
// The panel is the top layer while open: Escape closes it, not a dialog behind it.
let removeLayer = null
function onScroll(e) {
  if (!el.value?.contains(e.target)) close() // the page or chat moved under it
}

let sized = null
onMounted(async () => {
  removeLayer = pushLayer({ root: () => el.value, close, trap: false })
  // Re-place whenever its size changes (the full list arriving, the switch from pill to panel).
  sized = new ResizeObserver(() => place())
  sized.observe(el.value)
  await nextTick()
  place()
  if (all.value) {
    search.value?.focus()
    data.value = (await import('../lib/emoji-data.json')).default
  }
  // A tick later, so the click that opened it doesn't close it again.
  setTimeout(() => {
    document.addEventListener('pointerdown', onDown, true)
    window.addEventListener('scroll', onScroll, true)
  })
  window.addEventListener('resize', close)
})
onBeforeUnmount(() => {
  sized?.disconnect()
  removeLayer?.()
  document.removeEventListener('pointerdown', onDown, true)
  window.removeEventListener('scroll', onScroll, true)
  window.removeEventListener('resize', close)
})
</script>

<template>
  <Teleport to="body">
    <div ref="el" class="reaction-picker" :class="{ all, sheet }" :style="{ left: `${pos.left}px`, top: `${pos.top}px` }" role="dialog" :aria-label="t.__('React', 'membuild-community')" @click.stop>
      <template v-if="!all">
        <button v-for="r in MESSAGE_REACTIONS" :key="r.type" type="button" class="quick" :class="{ on: current === r.type }" :aria-label="r.label" @click="emit('pick', r.type)">{{ r.emoji }}</button>
        <button type="button" class="plus" :aria-label="t.__('More emoji', 'membuild-community')" @click="more"><Icon name="plus" :size="18" /></button>
      </template>
      <template v-else>
        <label class="section-search search">
          <Icon name="search" :size="16" />
          <input ref="search" v-model="query" type="search" :placeholder="t.__('Search emoji', 'membuild-community')" :aria-label="t.__('Search emoji', 'membuild-community')" />
        </label>
        <div ref="list" class="list" @scroll.passive="onListScroll">
          <template v-if="results">
            <div v-if="results.length" class="grid">
              <button v-for="e in results" :key="e[0]" type="button" class="emoji" :class="{ on: current === e[0] }" :title="e[1]" :aria-label="e[1]" @click="choose(e[0])">{{ e[0] }}</button>
            </div>
            <p v-else class="none">{{ t.__('No emoji found', 'membuild-community') }}</p>
          </template>
          <template v-else>
            <template v-if="mode === 'reaction'">
            <p class="heading">{{ t.__('Your reactions', 'membuild-community') }}</p>
            <div class="row">
              <button v-for="r in MESSAGE_REACTIONS" :key="r.type" type="button" class="quick" :class="{ on: current === r.type }" :aria-label="r.label" @click="emit('pick', r.type)">{{ r.emoji }}</button>
            </div>
            </template>
            <template v-if="mode === 'emoji' && recent.length">
              <p class="heading">{{ t.__('Recently used', 'membuild-community') }}</p>
              <div class="grid">
                <button v-for="e in recent.slice(0, 8)" :key="`r${e}`" type="button" class="emoji" :aria-label="e" @click="choose(e)">{{ e }}</button>
              </div>
            </template>
            <p v-if="!data.length" class="none">{{ t.__('Loading…', 'membuild-community') }}</p>
            <section v-for="(c, i) in sections" :key="c.label" :data-cat="CATEGORIES.indexOf(CATEGORIES.find((x) => x.label === c.label))">
              <p class="heading">{{ c.label }}</p>
              <div class="grid">
                <button v-for="e in c.items" :key="e[0]" type="button" class="emoji" :class="{ on: current === e[0] }" :title="e[1]" :aria-label="e[1]" @click="choose(e[0])">{{ e[0] }}</button>
              </div>
            </section>
          </template>
        </div>
        <nav class="tabs" :aria-label="t.__('Emoji categories', 'membuild-community')">
          <button v-for="(c, i) in CATEGORIES" :key="c.label" type="button" :class="{ on: !results && activeCat === i }" :title="c.label" :aria-label="c.label" :aria-current="!results && activeCat === i" @click="jump(i)">{{ c.icon }}</button>
        </nav>
      </template>
      <span v-if="caret" class="caret" :class="{ up: caret.up }" :style="{ left: `${caret.x}px` }" aria-hidden="true" />
    </div>
  </Teleport>
</template>

<style scoped>
.search {
  margin: 0 2px 8px;
}

.reaction-picker {
  position: fixed;
  z-index: 200;
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 6px;
  border-radius: var(--radius-pill);
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-pop);
  animation: pop-in 0.14s ease-out;
}

.reaction-picker.all {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  width: 330px;
  max-width: calc(100vw - 16px);
  padding: 10px 8px 0;
  border-radius: var(--radius);
}

.reaction-picker.sheet {
  width: calc(100vw - 16px);
  max-width: none;
}

/* One scrolling list (vertical only), like Facebook's panel. */
.list {
  height: 250px;
  overflow-x: hidden;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 0 2px;
}

.list section {
  content-visibility: auto; /* draws a category only when it scrolls into view */
  contain-intrinsic-size: auto 300px;
}

.none {
  margin: 24px 0;
  text-align: center;
  color: var(--text-2);
}

.tabs {
  display: flex;
  justify-content: space-between;
  margin: 4px -8px 0;
  padding: 4px 8px;
  border-top: 1px solid var(--border);
}

.tabs button {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-xs);
  font-size: var(--fs-2xl);
  filter: grayscale(1);
  opacity: 0.75;
}

.tabs button:hover {
  filter: none;
  opacity: 1;
  background: var(--surface-2);
}

/* The category in view: in colour, with a bar under it, like Facebook's tabs. */
.tabs button.on {
  position: relative;
  filter: none;
  opacity: 1;
}

.tabs button.on::after {
  content: '';
  position: absolute;
  left: 4px;
  right: 4px;
  bottom: -4px;
  height: 3px;
  border-radius: 2px;
  background: var(--accent);
}

/* The pointer at the button it came from. */
.caret {
  position: absolute;
  bottom: -7px;
  width: 14px;
  height: 14px;
  margin-left: -7px;
  background: var(--surface);
  border-right: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  transform: rotate(45deg);
}

.caret.up {
  top: -7px;
  bottom: auto;
  transform: rotate(225deg);
}

@keyframes pop-in {
  from {
    opacity: 0;
    transform: translateY(6px) scale(0.94);
  }
}

.quick {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  border-radius: 50%;
  font-size: var(--fs-5xl);
  line-height: 1;
  transition: transform 0.12s ease;
}

.quick:hover {
  transform: scale(1.2);
}

.quick.on,
.emoji.on {
  background: var(--surface-2);
}

.plus {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  margin-left: 2px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
}

.plus:hover {
  color: var(--text);
}

.heading {
  margin: 4px 4px 6px;
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 650;
}

.row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 10px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(8, 1fr);
  margin-bottom: 8px;
}

.emoji {
  display: grid;
  place-items: center;
  height: 38px;
  border-radius: var(--radius-xs);
  font-size: var(--fs-4xl);
  line-height: 1;
}

.emoji:hover {
  background: var(--surface-2);
}
</style>
