<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { api } from '../api.js'
import { cfg } from '../config.js'
import { setHelpful, setReaction, updatePost } from '../stores/feed.js'
import { compactNumber } from '../lib/time.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'
import ReactionButton from './ReactionButton.vue'
import ReactionStack from './ReactionStack.vue'
const ReactionsDialog = defineAsyncComponent(() => import('./ReactionsDialog.vue'))

// Facebook's layout: a summary line (reactions on the left, comments and shares
// on the right), then Like · Comment · Share as labelled buttons, which read
// more easily than icons alone for members of every age.
const props = defineProps({ post: { type: Object, required: true } })
defineEmits(['comment'])

const copied = ref(false)
// In a course, the third button is "Helpful" (like an upvote), so the best posts rise to the top.
const inCourse = computed(() => !!props.post.course)
const isMine = computed(() => props.post.author?.id === cfg.user.id)
function toggleHelpful() {
  if (!isMine.value) setHelpful(props.post, !props.post.my_helpful).catch((err) => alert(err.message))
}
const helpfulCount = (n) => t.sprintf(/* translators: %s: number of members who found a post helpful */ t._n('%s found this helpful', '%s found this helpful', n, 'membuild-community'), fmt(n))
const showReactions = ref(false)
const fmt = (n) => (n ? compactNumber(n) : null)

async function share() {
  const url = `${location.origin}${location.pathname.replace(/\/$/, '')}?post=${props.post.id}`
  try {
    if (navigator.share) await navigator.share({ url })
    else {
      await navigator.clipboard.writeText(url)
      copied.value = true
      setTimeout(() => (copied.value = false), 1600)
    }
  } catch {
    return // share sheet dismissed: nothing was shared
  }
  updatePost(props.post.id, (p) => ({ shares_count: (p.shares_count || 0) + 1 }))
  api.share(props.post.id).then((res) => updatePost(props.post.id, res)).catch(() => {})
}
const commentCount = (n) => t.sprintf(/* translators: %s: number of comments, e.g. "12" or "1.2K" */ t._n('%s comment', '%s comments', n, 'membuild-community'), fmt(n))
const shareCount = (n) => t.sprintf(/* translators: %s: number of shares, e.g. "12" or "1.2K" */ t._n('%s share', '%s shares', n, 'membuild-community'), fmt(n))
</script>

<template>
  <div class="actions" :class="{ disabled: post.pending }">
    <div v-if="post.reactions_count || post.comments_count || post.shares_count || post.helpful_count" class="summary">
      <button v-if="post.reactions_count" class="who" :aria-label="t.__('See who reacted', 'membuild-community')" @click="showReactions = true">
        <ReactionStack :types="post.top_reactions" :size="18" />
        <span>{{ fmt(post.reactions_count) }}</span>
      </button>
      <span class="counts">
        <span v-if="post.helpful_count" class="helpful-count"><Icon name="arrowUp" :size="14" /> {{ helpfulCount(post.helpful_count) }}</span>
        <button v-if="post.comments_count" class="link" @click="$emit('comment')">
          {{ commentCount(post.comments_count) }}
        </button>
        <span v-if="post.shares_count">{{ shareCount(post.shares_count) }}</span>
      </span>
    </div>
    <div class="bar">
      <ReactionButton class="like" :current="post.my_reaction" labelled @react="(type) => setReaction(post, type)" />
      <button class="act" @click="$emit('comment')">
        <Icon name="comment" :size="20" />
        <span>{{ t._x('Comment', 'verb: write a comment', 'membuild-community') }}</span>
      </button>
      <button
        v-if="inCourse"
        class="act helpful"
        :class="{ on: post.my_helpful }"
        :aria-pressed="post.my_helpful"
        :disabled="isMine"
        :title="isMine ? t.__('Others can mark your post as helpful', 'membuild-community') : null"
        @click="toggleHelpful"
      >
        <Icon name="arrowUp" :size="20" />
        <span>{{ t._x('Helpful', 'button: mark a post as helpful', 'membuild-community') }}</span>
      </button>
      <button v-else class="act" :aria-live="copied ? 'polite' : null" @click="share">
        <Icon name="share" :size="20" />
        <span>{{ copied ? t.__('Link copied', 'membuild-community') : t._x('Share', 'verb', 'membuild-community') }}</span>
      </button>
    </div>
    <ReactionsDialog v-if="showReactions" :post="post" @close="showReactions = false" />
  </div>
</template>

<style scoped>
.actions.disabled {
  pointer-events: none;
  opacity: 0.6;
}

.summary {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 14px 6px;
  color: var(--text-2);
  font-size: 14px;
}

.who {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: inherit;
}

.who:hover span,
.link:hover {
  text-decoration: underline;
}

.counts {
  display: flex;
  gap: 12px;
  margin-left: auto;
}

.link {
  color: inherit;
}

.bar {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
  margin: 0 10px;
  padding: 4px 0;
  border-top: 1px solid var(--border);
}

.like {
  width: 100%;
}

.act,
.bar :deep(.like .trigger) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  width: 100%;
  min-height: 40px;
  padding: 0 8px;
  border-radius: 8px;
  color: var(--text-2);
  font-weight: 650;
  font-size: 15px;
}

.act:hover:not(:disabled),
.bar :deep(.like .trigger:hover) {
  background: var(--surface-2);
}

.act:disabled {
  opacity: 0.5;
  cursor: default;
}

.helpful.on {
  color: #ff6a1a;
}

.helpful.on :deep(svg) {
  stroke-width: 3;
}

.helpful-count {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  color: #ff6a1a;
  font-weight: 650;
}
</style>
