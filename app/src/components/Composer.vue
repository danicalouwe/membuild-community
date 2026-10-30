<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { cfg, feature } from '../config.js'
import { pendingReelFile } from '../lib/pending.js'
import { topicById } from '../lib/topics.js'
import Avatar from './Avatar.vue'
import { t } from '../lib/i18n.js'
const CreatePostDialog = defineAsyncComponent(() => import('./CreatePostDialog.vue'))

// The feed's "What's on your mind?" bar. Every entry point opens Create post,
// except the camera, which goes straight to making a reel.
const props = defineProps({ topic: { type: Number, default: 0 } }) // Home filtered to a topic: posts start in it
const emit = defineEmits(['posted'])
const router = useRouter()
const route = useRoute()

const dialog = shallowRef(null) // null | { start, files, draft }
const photoInput = ref(null)
const reelInput = ref(null)
const firstName = cfg.user.name.split(' ')[0]
const asked = t.sprintf(/* translators: %s: the person's first name */ t.__("What's on your mind, %s?", 'membuild-community'), firstName)
const here = computed(() => (props.topic ? topicById(props.topic) : null))
const prompt = computed(() => (here.value?.can_post ? t.sprintf(/* translators: %s: a topic's name */ t.__('Post in %s…', 'membuild-community'), here.value.name) : asked))

const open = (opts = {}) => (dialog.value = { start: '', files: [], draft: null, ...opts })
// "Write a post" from elsewhere (the Leaderboard's empty week): Home with ?write=1 opens this.
watch(
  () => route.query.write,
  (w) => {
    if (!w) return
    open()
    router.replace({ query: { ...route.query, write: undefined } })
  },
  { immediate: true },
)

// File pickers must open directly from the click, so the bar owns its own inputs.
function pickedPhotos(e) {
  const files = [...e.target.files]
  e.target.value = ''
  if (files.length) open({ files })
}

function pickedReel(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  pendingReelFile.value = file
  router.push({ name: 'reels' })
}

// A post that failed to save comes back here with everything restored.
const reopen = (e) => !e.detail.place && open({ draft: e.detail }) // course posts reopen in their lesson
onMounted(() => window.addEventListener('mbcom:reopen-composer', reopen))
onBeforeUnmount(() => window.removeEventListener('mbcom:reopen-composer', reopen))
</script>

<template>
  <section class="bar card">
    <Avatar :user="cfg.user" :size="40" />
    <button class="pill" @click="open()">{{ prompt }}</button>

    <button v-if="feature('reels')" class="icon wide-only" :aria-label="t.__('Create a reel', 'membuild-community')" :title="t.__('Reel', 'membuild-community')" @click="reelInput.click()">
      <svg width="30" height="30" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="2" y="6" width="14" height="12" rx="3" fill="#f02849" />
        <path d="M16 10.2l5-3v9.6l-5-3z" fill="#f02849" />
        <path d="M5.2 12c.9-1.6 2.3-2.4 3.8-2.4s2.9.8 3.8 2.4c-.9 1.6-2.3 2.4-3.8 2.4S6.1 13.6 5.2 12z" fill="#fff" />
        <circle cx="9" cy="12" r="1.2" fill="#f02849" />
      </svg>
    </button>
    <button class="icon" :aria-label="t.__('Photo or video', 'membuild-community')" :title="t.__('Photo/video', 'membuild-community')" @click="photoInput.click()">
      <svg width="30" height="30" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="5" y="3" width="16" height="14" rx="2.5" fill="#2f9e5b" opacity=".55" />
        <rect x="2.5" y="6" width="16" height="15" rx="2.5" fill="#45bd62" />
        <circle cx="8" cy="11" r="1.8" fill="#fff" />
        <path d="M4.5 19l4.5-4.5 3 3 2.5-2.5 3 3.5" fill="none" stroke="#fff" stroke-width="1.6" stroke-linejoin="round" />
      </svg>
    </button>
    <button class="icon wide-only" :aria-label="t.__('Feeling or activity', 'membuild-community')" :title="t.__('Feeling/activity', 'membuild-community')" @click="open({ start: 'feeling' })">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle cx="12" cy="12" r="9.5" stroke="#f7932f" stroke-width="2" />
        <path d="M8 14c1 1.6 2.4 2.4 4 2.4s3-.8 4-2.4" stroke="#f7932f" stroke-width="2" stroke-linecap="round" />
        <circle cx="9" cy="9.6" r="1.3" fill="#f7932f" />
        <circle cx="15" cy="9.6" r="1.3" fill="#f7932f" />
      </svg>
    </button>

    <input ref="photoInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*,video/*" multiple @change="pickedPhotos" />
    <input ref="reelInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="video/*" @change="pickedReel" />

    <CreatePostDialog
      v-if="dialog"
      :start="dialog.start"
      :files="dialog.files"
      :draft="dialog.draft"
      :topic="here?.can_post ? here.id : 0"
      @posted="emit('posted')"
      @close="dialog = null"
    />
  </section>
</template>

<style scoped>
.bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 14px;
}

.pill {
  flex: 1;
  min-width: 0;
  height: 42px;
  padding: 0 18px;
  text-align: left;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
  color: var(--text-3);
  font-size: var(--fs-lg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pill:hover {
  background: var(--border);
}

.icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 42px;
  height: 42px;
  border-radius: 50%;
}

.icon:hover {
  background: var(--surface-2);
}

/* Phones, like Facebook: just the photo button, so "What's on your mind?" has room to be read.
   Reels and feelings are a tap away inside Create post. */
@media (max-width: 767px) {
  .wide-only {
    display: none;
  }
}
</style>
