<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import BaseDialog from './BaseDialog.vue'
import PopFrame from './PopFrame.vue'
import Icon from './Icon.vue'
import { gifs } from '../lib/giphy.js'
import { t } from '../lib/i18n.js'

/**
 * Pick a GIF: trending ones when it opens, search as you type, more as you
 * scroll. Emits `pick` with the GIF (see lib/giphy.js). GIPHY's terms: results
 * in their order, untouched, and "Powered by GIPHY" in view.
 */
// `pop`: a small box above the button that opened it (chats on a computer), not a dialog.
const props = defineProps({ pop: { type: Boolean, default: false } })
const emit = defineEmits(['pick', 'close'])

const query = ref('')
const items = ref([])
const next = ref(0)
const loading = ref(false)
const error = ref('')
const input = ref(null)
const sentinel = ref(null)
let controller = null
let observer = null

async function load(reset) {
  if (loading.value && !reset) return
  if (!reset && next.value === null) return
  controller?.abort()
  controller = new AbortController()
  const mine = controller
  loading.value = true
  error.value = ''
  try {
    const page = await gifs(query.value.trim(), reset ? 0 : next.value, mine.signal)
    if (mine !== controller) return
    items.value = reset ? page.items : [...items.value, ...page.items]
    next.value = page.next
  } catch (err) {
    if (err.name === 'AbortError') return
    error.value = err.message === 'busy' ? t.__('GIPHY is busy right now. Try again in a few minutes.', 'membuild-community') : t.__('GIFs couldn’t load. Check your connection and try again.', 'membuild-community')
  } finally {
    if (mine === controller) loading.value = false
  }
}

let timer = null
watch(query, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(true), 350)
})

onMounted(() => {
  load(true)
  nextTick(() => input.value?.focus())
  // More as the end of the grid comes into view.
  observer = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && items.value.length && load(false), { rootMargin: '200px' })
  watch(sentinel, (el) => el && observer.observe(el), { immediate: true })
})

function pick(gif) {
  emit('pick', gif)
  emit('close')
}
</script>

<template>
  <component :is="pop ? PopFrame : BaseDialog" :title="t.__('Choose a GIF', 'membuild-community')" v-bind="pop ? {} : { width: 520, maxHeight: '680px', phone: 'sheet', z: 120 }" @close="emit('close')">
    <div class="gif-picker">
      <label class="search">
        <Icon name="search" :size="18" />
        <input ref="input" v-model="query" type="search" enterkeyhint="search" :placeholder="t.__('Search GIPHY', 'membuild-community')" :aria-label="t.__('Search GIPHY', 'membuild-community')" />
      </label>

      <div class="grid" role="list">
        <button v-for="g in items" :key="g.id" type="button" class="gif" role="listitem" :title="g.title" :aria-label="g.title || t.__('GIF', 'membuild-community')" :style="{ aspectRatio: `${g.width} / ${g.height}` }" @click="pick(g)">
          <img :src="g.preview" alt="" loading="lazy" decoding="async" />
        </button>
        <template v-if="loading && !items.length">
          <span v-for="n in 9" :key="`s${n}`" class="gif skeleton" :style="{ aspectRatio: n % 3 ? '4 / 3' : '1 / 1' }" />
        </template>
      </div>
      <div ref="sentinel" class="sentinel" />

      <p v-if="error" class="note err" role="status">{{ error }}</p>
      <p v-else-if="!loading && !items.length" class="note">{{ t.__('No GIFs found. Try another word.', 'membuild-community') }}</p>
    </div>
    <template #footer>
      <p class="powered">
        <span>{{ t.__('Powered by', 'membuild-community') }}</span>
        <strong>GIPHY</strong>
      </p>
    </template>
  </component>
</template>

<style scoped>
.gif-picker {
  padding: 12px 14px 4px;
}

/* In the small pop-up box: a tighter fit, the search stays in view. */
.pop .gif-picker {
  padding: 8px 8px 2px;
}

.pop .search {
  position: sticky;
  top: 0;
  z-index: 1;
  margin-bottom: 8px;
}

.search {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  padding: 0 14px;
  border-radius: 999px;
  background: var(--surface-2);
  color: var(--text-2);
  margin-bottom: 12px;
}

.search input {
  flex: 1;
  min-width: 0;
  border: 0;
  padding: 0;
  background: transparent;
  color: var(--text);
  outline: none;
  box-shadow: none;
  font-size: 16px;
}

/* Two columns that fill top to bottom, like GIPHY's own grid; order stays GIPHY's. */
.grid {
  columns: 2;
  column-gap: 8px;
}

.gif {
  display: block;
  width: 100%;
  margin: 0 0 8px;
  padding: 0;
  border: 0;
  border-radius: 10px;
  overflow: hidden;
  background: var(--surface-2);
  break-inside: avoid;
  cursor: pointer;
}

.gif img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.gif:hover,
.gif:focus-visible {
  outline: 3px solid var(--accent);
  outline-offset: -3px;
}

.sentinel {
  height: 1px;
}

.note {
  margin: 16px 0;
  text-align: center;
  color: var(--text-2);
}

.err {
  color: var(--danger);
}

.powered {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  margin: 0;
  color: var(--text-2);
  font-size: 13px;
}

.powered strong {
  color: var(--text);
  font-weight: 900;
  letter-spacing: 0.04em;
}
</style>
