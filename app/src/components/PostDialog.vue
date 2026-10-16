<script setup>
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { timeAgo } from '../lib/time.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import BaseDialog from './BaseDialog.vue'
import RichText from './RichText.vue'
import MediaGrid from './MediaGrid.vue'
import PostActions from './PostActions.vue'
import CommentThread from './CommentThread.vue'
import { t } from '../lib/i18n.js'

// "Name's Post": the post on top, then its full comment thread, with the
// comment box pinned to the bottom. Full-screen on phones.
const props = defineProps({
  post: { type: Object, required: true },
  focusComment: { type: Boolean, default: false },
})
const emit = defineEmits(['close'])

const thread = ref(null)
const label = () => t.sprintf(/* translators: %s: the author's name */ t.__("%s's post", 'membuild-community'), props.post.author?.name)
const heading = () => t.sprintf(/* translators: %s: the author's first name. The dialog's heading. */ t.__("%s's Post", 'membuild-community'), props.post.author?.name?.split(' ')[0])

// Following a link to a profile closes the dialog (the page it sits on stays alive in the background).
const route = useRoute()
watch(() => route.path, () => emit('close'))
</script>

<template>
  <BaseDialog :title="heading()" :label="label()" :width="700" :z="80" phone="full" @close="emit('close')">
    <div class="body">
      <div class="post-head">
        <Avatar :user="post.author" :size="40" badge link />
        <div class="who">
          <RouterLink v-if="post.author" :to="{ name: 'member', params: { id: post.author.id } }" class="author-link"><strong>{{ post.author.name }}</strong></RouterLink>
          <span>{{ timeAgo(post.created_at) }}</span>
          <RouterLink v-if="post.course" class="place" :to="post.lesson ? { name: 'course', params: { courseId: post.course.id, lessonId: post.lesson.id } } : { name: 'course', params: { courseId: post.course.id }, query: { tab: 'discussion' } }">
            <Icon name="cap" :size="13" /> {{ post.lesson ? post.lesson.title : post.course.title }}
          </RouterLink>
        </div>
      </div>
      <RichText v-if="post.content" class="text" :text="post.content" />
      <MediaGrid v-if="post.media.length" class="media" :media="post.media" />
      <PostActions :post="post" class="actions" @comment="thread?.focus()" />

      <div class="comments">
        <CommentThread ref="thread" :post="post" :autofocus="focusComment" />
      </div>
    </div>
  </BaseDialog>
</template>

<style scoped>
.author-link {
  color: var(--text);
}

.author-link:hover {
  text-decoration: underline;
}

.post-head {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 14px 16px 8px;
}

.who {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

.who span {
  font-size: 13px;
  color: var(--text-3);
}

.text {
  padding: 0 16px 10px;
}

.place {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 3px;
  font-size: 12.5px;
  font-weight: 650;
  color: var(--accent-text);
}

.actions {
  border-bottom: 1px solid var(--border);
}

.comments {
  padding: 12px 16px 16px;
}
</style>
