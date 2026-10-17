<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parseInternal } from '../lib/links.js'

// Turns URLs into links without ever rendering HTML from the server.
const props = defineProps({ text: { type: String, default: '' }, clamp: { type: Number, default: 0 } })

const expanded = ref(false)
const route = useRoute()
const router = useRouter()
// A link into this community (…?post=12&comment=34) opens on top of this page, like a notification does.
function follow(e, href) {
  const ref = parseInternal(href)
  if (!ref || e.metaKey || e.ctrlKey) return
  e.preventDefault()
  router.push({ path: route.path, query: { ...route.query, post: ref.post, comment: ref.comment || undefined } })
}
const URL_RE = /(https?:\/\/[^\s<]+[^\s<.,:;"')\]!?])/g

const tooLong = computed(() => props.clamp > 0 && props.text.length > props.clamp)
const shown = computed(() =>
  tooLong.value && !expanded.value ? props.text.slice(0, props.clamp).replace(/\s+\S*$/, '') + '…' : props.text,
)
const parts = computed(() =>
  shown.value.split(URL_RE).map((chunk, i) => ({ link: i % 2 === 1, text: chunk })),
)
</script>

<template>
  <div class="rich">
    <template v-for="(p, i) in parts" :key="i">
      <a v-if="p.link" :href="p.text" target="_blank" rel="noopener nofollow ugc" @click="follow($event, p.text)">{{ p.text }}</a>
      <template v-else>{{ p.text }}</template>
    </template>
    <button v-if="tooLong && !expanded" class="more" @click="expanded = true">{{ t.__('See more', 'membuild-community') }}</button>
  </div>
</template>

<style scoped>
.rich {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.more {
  margin-left: 4px;
  font-weight: 600;
  color: var(--text-2);
}
</style>
