<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parseInternal } from '../lib/links.js'

// Turns URLs into links without ever rendering HTML from the server.
const props = defineProps({
  text: { type: String, default: '' },
  clamp: { type: Number, default: 0 },
  strong: { type: String, default: '' }, // shown in bold where it appears (a course card's name)
})

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
  shown.value.split(URL_RE).flatMap((chunk, i) => {
    if (i % 2 === 1) return [{ link: true, text: chunk }]
    const at = props.strong ? chunk.indexOf(props.strong) : -1
    if (at < 0) return [{ text: chunk }]
    return [{ text: chunk.slice(0, at) }, { bold: true, text: props.strong }, { text: chunk.slice(at + props.strong.length) }]
  }),
)
</script>

<template>
  <div class="rich">
    <template v-for="(p, i) in parts" :key="i">
      <a v-if="p.link" :href="p.text" target="_blank" rel="noopener nofollow ugc" @click="follow($event, p.text)">{{ p.text }}</a>
      <strong v-else-if="p.bold">{{ p.text }}</strong>
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
