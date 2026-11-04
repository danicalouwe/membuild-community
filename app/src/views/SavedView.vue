<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '../api.js'
import { lists, loadMore, refresh } from '../stores/feed.js'
import { showToast } from '../lib/toast.js'
import Icon from '../components/Icon.vue'
import PostCard from '../components/PostCard.vue'
import PostSkeleton from '../components/PostSkeleton.vue'
import { t } from '../lib/i18n.js'

// Saved posts: this member's own private list, newest saved first. A post taken off the
// list stays in memory, so "Undo" puts it back in the same spot.
const list = lists.saved
const shown = computed(() => list.items.filter((p) => p.gone || p.saved))

const sentinel = ref(null)
let observer = null
onMounted(() => {
  if (list.loaded) refresh(list)
  else loadMore(list)
  observer = new IntersectionObserver(([e]) => e.isIntersecting && list.loaded && loadMore(list), { rootMargin: '600px' })
  observer.observe(sentinel.value)
})
onBeforeUnmount(() => observer?.disconnect())

// A post that's gone leaves with one tap: nothing is lost, so it doesn't ask first.
async function removeGone(row) {
  const at = list.items.indexOf(row)
  list.items.splice(at, 1)
  try {
    await api.unsavePost(row.id)
  } catch (err) {
    list.items.splice(at, 0, row)
    showToast(err.message)
  }
}
</script>

<template>
  <div class="saved">
    <header class="saved-head">
      <RouterLink class="back-link" :to="{ name: 'feed' }"><Icon name="chevronLeft" :size="20" /> {{ t.__('Home', 'membuild-community') }}</RouterLink>
      <h1 class="page-title">{{ t.__('Saved posts', 'membuild-community') }}</h1>
      <p class="page-intro">{{ t.__('Only you can see this list.', 'membuild-community') }}</p>
    </header>

    <template v-if="!list.loaded && !list.error"><PostSkeleton v-for="n in 3" :key="n" /></template>
    <p v-else-if="list.error && !shown.length" class="card failed" role="alert">
      <span class="error-text">{{ t.__('Couldn’t load your saved posts.', 'membuild-community') }}</span>
      <button class="btn btn-ghost" @click="refresh(list)">{{ t.__('Try again', 'membuild-community') }}</button>
    </p>
    <div v-else-if="!shown.length" class="card empty-state">
      <span class="empty-state-icon"><Icon name="bookmark" :size="28" /></span>
      <h2 class="empty-state-title">{{ t.__('Nothing saved yet', 'membuild-community') }}</h2>
      <p class="empty-state-text">{{ t.__('On any post, tap ⋯ and choose Save post. It will wait for you here.', 'membuild-community') }}</p>
      <RouterLink class="btn btn-lg btn-primary" :to="{ name: 'feed' }">{{ t.__('Go to Home', 'membuild-community') }}</RouterLink>
    </div>

    <template v-for="post in shown" :key="post.id">
      <article v-if="post.gone" class="card gone">
        <span class="gone-icon"><Icon :name="post.gone === 'locked' ? 'lock' : 'bookmark'" :size="20" /></span>
        <div class="gone-words">
          <strong>{{ post.gone === 'locked' ? t.__('You can’t open this post right now', 'membuild-community') : t.__('This post is gone', 'membuild-community') }}</strong>
          <span>{{ post.gone === 'locked' ? t.__('It’s in a course or topic you don’t have now. If you get it back, so does this.', 'membuild-community') : t.__('The writer or a moderator deleted it.', 'membuild-community') }}</span>
        </div>
        <button class="btn btn-ghost gone-remove" @click="removeGone(post)">{{ t.__('Remove', 'membuild-community') }}</button>
      </article>
      <PostCard v-else :post="post" />
    </template>

    <div ref="sentinel" class="sentinel" aria-hidden="true" />
    <PostSkeleton v-if="list.loaded && list.loading && list.hasMore" />
  </div>
</template>

<style scoped>
.saved {
  max-width: 640px;
  margin: 0 auto;
  padding: 16px 12px 24px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.saved-head .page-intro {
  margin-bottom: 8px;
}

.failed {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin: 0;
  padding: 16px;
}

.gone {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
}

.gone-icon {
  display: grid;
  flex: none;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
}

.gone-words {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}

.gone-words strong {
  color: var(--text);
  font-size: var(--fs-lg);
}

.gone-remove {
  flex: none;
}

.sentinel {
  height: 1px;
}

@media (max-width: 767px) {
  .saved {
    padding: 8px 0 24px;
    gap: 8px;
  }

  .saved-head {
    padding: 0 16px;
  }

  .saved :deep(.card) {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
  }
}
</style>
