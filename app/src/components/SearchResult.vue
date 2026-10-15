<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { levelLabel } from '../config.js'
import { t, locale } from '../lib/i18n.js'
import { timeAgo } from '../lib/time.js'
import { CATEGORY } from '../lib/places.js'

/**
 * One search result: a member, post, reel, comment, course or lesson, as the
 * server's Search class returns it. Matched words are highlighted by splitting
 * the text (never as HTML), so nothing in a post can inject markup.
 */
const props = defineProps({
  item: { type: Object, required: true },
  words: { type: Array, default: () => [] },
})
const emit = defineEmits(['open'])
const route = useRoute()

// Posts and comments open on top of the current page (?post=ID), like notifications do.
const to = computed(() => (props.item.link.name ? props.item.link : { path: route.path, query: { ...route.query, ...props.item.link.query } }))

function parts(text) {
  if (!text || !props.words.length) return [{ text: text || '', hit: false }]
  const escaped = props.words.map((w) => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
  return String(text)
    .split(new RegExp(`(${escaped.join('|')})`, 'giu'))
    .filter(Boolean)
    .map((piece) => ({ text: piece, hit: props.words.includes(piece.toLowerCase()) }))
}

const title = computed(() => {
  const i = props.item
  if (i.kind === 'member') return i.user.name
  if (i.kind === 'course' || i.kind === 'lesson' || i.kind === 'place') return i.title
  return i.snippet
})

const detail = computed(() => {
  const i = props.item
  switch (i.kind) {
    case 'member':
      return i.snippet || levelLabel(i.user.level)
    case 'post':
    case 'reel': {
      const bits = [i.user.name, timeAgo(i.created_at)]
      if (i.kind === 'reel') bits.unshift(t.__('Reel', 'membuild-community'))
      if (i.comments) bits.push(t.sprintf(/* translators: %s: number of comments */ t._n('%s comment', '%s comments', i.comments, 'membuild-community'), i.comments.toLocaleString(locale)))
      return bits.join(' · ')
    }
    case 'comment':
      return i.on
        ? t.sprintf(/* translators: 1: who wrote the comment, 2: the start of the post it's on */ t.__('%1$s on “%2$s”', 'membuild-community'), i.user.name, i.on)
        : t.sprintf(/* translators: %s: who wrote the comment */ t.__('Comment by %s', 'membuild-community'), i.user.name)
    case 'course':
      return [t.__('Course', 'membuild-community'), t.sprintf(/* translators: %s: number of lessons */ t._n('%s lesson', '%s lessons', i.lessons, 'membuild-community'), i.lessons), lockText(i)].filter(Boolean).join(' · ')
    case 'place':
      return [CATEGORY[i.category]?.label, i.perk ? `🎁 ${i.perk}` : i.address].filter(Boolean).join(' · ')
    case 'lesson':
      return [t.sprintf(/* translators: %s: course title */ t.__('Lesson in %s', 'membuild-community'), i.course), lockText(i)].filter(Boolean).join(' · ')
  }
  return ''
})

function lockText(i) {
  if (i.draft) return t.__('Draft', 'membuild-community')
  if (i.lock?.reason === 'level') return t.sprintf(/* translators: %d: level number */ t.__('Unlocks at level %d', 'membuild-community'), i.lock.level)
  if (i.lock?.reason === 'groups') return i.lock.groups?.length ? t.sprintf(/* translators: %s: group names */ t.__('For %s', 'membuild-community'), i.lock.groups.join(', ')) : t.__('For a member group', 'membuild-community')
  if (i.unlock_at) return t.sprintf(/* translators: %s: a date */ t.__('Unlocks %s', 'membuild-community'), new Date(i.unlock_at).toLocaleDateString(locale, { month: 'short', day: 'numeric' }))
  return ''
}

const locked = computed(() => !!(props.item.lock || props.item.unlock_at))
// For a course or lesson found by its text, the quote goes under the title.
const quote = computed(() => (['course', 'lesson'].includes(props.item.kind) ? props.item.snippet : ''))
</script>

<template>
  <RouterLink :to="to" class="result" @click="emit('open')">
    <Avatar v-if="item.user && item.kind === 'member'" :user="item.user" :size="36" badge />
    <Avatar v-else-if="item.user" :user="item.user" :size="36" />
    <span v-else class="thumb" :class="{ locked }">
      <img v-if="item.cover && !locked" :src="item.cover" alt="" />
      <Icon v-else :name="locked ? 'lock' : item.kind === 'place' ? 'mapPin' : 'cap'" :size="18" />
    </span>
    <span class="text">
      <span class="title"><template v-for="(p, n) in parts(title)" :key="n"><mark v-if="p.hit">{{ p.text }}</mark><template v-else>{{ p.text }}</template></template></span>
      <span v-if="quote" class="quote"><template v-for="(p, n) in parts(quote)" :key="n"><mark v-if="p.hit">{{ p.text }}</mark><template v-else>{{ p.text }}</template></template></span>
      <span class="detail" :class="{ lock: locked }"><Icon v-if="locked" name="lock" :size="12" /><template v-for="(p, n) in parts(item.kind === 'member' ? detail : '')" :key="n"><mark v-if="p.hit">{{ p.text }}</mark><template v-else>{{ p.text }}</template></template><template v-if="item.kind !== 'member'">{{ detail }}</template></span>
    </span>
  </RouterLink>
</template>

<style scoped>
.result {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  color: var(--text);
}

.result:hover,
.result:focus-visible {
  background: var(--surface-2);
  outline: none;
}

.thumb {
  flex: none;
  width: 36px;
  height: 36px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  overflow: hidden;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.thumb.locked {
  background: var(--surface-2);
  color: var(--text-2);
}

.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 1px;
}

.title {
  font-weight: 600;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  overflow-wrap: anywhere;
}

.quote {
  color: var(--text-2);
  font-size: 14px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.detail {
  color: var(--text-3);
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detail.lock {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

mark {
  background: color-mix(in srgb, #ffd43b 45%, transparent);
  color: inherit;
  border-radius: 3px;
  padding: 0 1px;
}
</style>
