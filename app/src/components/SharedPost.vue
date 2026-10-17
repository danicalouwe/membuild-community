<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
import { timeAgo } from '../lib/time.js'

/**
 * A preview of a post or comment someone linked to (from a comment's Share):
 * who wrote it, the start of it, its picture. Tapping opens it on top of the
 * current page. The API decides what this member may see, so a link to
 * something they can't open shows nothing (the bare link stays beside it).
 */
const props = defineProps({
  post: { type: Number, required: true },
  comment: { type: Number, default: 0 },
})
const emit = defineEmits(['ready']) // true when there's something to show

const route = useRoute()
const router = useRouter()
const item = ref(null) // the post, or the comment
const parent = ref(null) // the post a comment is on
const failed = ref(false)

// Fetched posts are remembered per page load (module scope), so a chat full of the same link asks once.
const cache = new Map()
async function fetchOnce(key, fn) {
  if (!cache.has(key)) cache.set(key, fn().catch(() => null))
  return cache.get(key)
}

onMounted(async () => {
  if (props.comment) {
    item.value = await fetchOnce(`c${props.comment}`, () => api.comment(props.comment))
    if (item.value) parent.value = await fetchOnce(`p${props.post}`, () => api.post(props.post))
  } else {
    item.value = await fetchOnce(`p${props.post}`, () => api.post(props.post))
  }
  failed.value = !item.value
  emit('ready', !!item.value)
})

const kind = computed(() => {
  if (!item.value) return ''
  if (props.comment) {
    const who = parent.value?.author?.name?.split(' ')[0]
    return who ? t.sprintf(/* translators: %s: the post author's first name */ t.__('Comment on %s’s post', 'membuild-community'), who) : t.__('Comment', 'membuild-community')
  }
  if (item.value.event) return t.__('Event', 'membuild-community')
  if (item.value.type === 'reel') return t.__('Reel', 'membuild-community')
  return t.__('Post', 'membuild-community')
})
const text = computed(() => {
  const i = item.value
  if (!i) return ''
  const s = (i.event?.title ? `${i.event.title} · ` : '') + String(i.content || '').replace(/\s+/g, ' ').trim()
  return s.length > 160 ? s.slice(0, 160).replace(/\s+\S*$/, '') + '…' : s
})
const picture = computed(() => {
  const i = item.value
  if (!i) return null
  if (props.comment) return i.image?.url || null
  const m = i.media?.[0]
  return m ? m.poster || (m.kind === 'image' ? m.url : null) : null
})

function open() {
  router.push({ path: route.path, query: { ...route.query, post: props.post, comment: props.comment || undefined } })
}
</script>

<template>
  <button v-if="item" type="button" class="shared" @click="open">
    <img v-if="picture" :src="picture" alt="" class="pic" />
    <span class="body">
      <span class="kind"><Icon name="link" :size="12" /> {{ kind }}</span>
      <span class="who"><Avatar :user="item.author" :size="20" /> <strong>{{ item.author?.name }}</strong> <span class="when">· {{ timeAgo(item.created_at) }}</span></span>
      <span v-if="text" class="text">{{ text }}</span>
    </span>
  </button>
</template>

<style scoped>
.shared {
  display: flex;
  gap: 10px;
  width: 100%;
  max-width: 360px;
  padding: 10px;
  border-radius: 14px;
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  text-align: left;
  cursor: pointer;
}

.shared:hover {
  background: var(--surface-2);
}

.pic {
  flex: none;
  width: 64px;
  height: 64px;
  border-radius: 10px;
  object-fit: cover;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.kind {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-2);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.who {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14px;
}

.when {
  color: var(--text-2);
}

.text {
  font-size: 14px;
  line-height: 1.4;
  color: var(--text);
  overflow-wrap: anywhere;
}
</style>
