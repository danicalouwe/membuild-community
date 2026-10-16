<script setup>
import { defineAsyncComponent, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'
import { t } from '../lib/i18n.js'
const CreatePostDialog = defineAsyncComponent(() => import('./CreatePostDialog.vue'))

// The moment a member finishes a course: a celebration, and a one-tap post to the feed.
const props = defineProps({ course: { type: Object, required: true } })
const emit = defineEmits(['close'])

const router = useRouter()
const sharing = shallowRef(null)
function share() {
  const url = location.origin + router.resolve({ name: 'course', params: { courseId: props.course.id } }).href
  sharing.value = {
    text: t.sprintf(/* translators: 1: course title, 2: a link to the course */ t.__('I just finished “%1$s” 🎓 Thank you for this course! %2$s', 'membuild-community'), props.course.title, url),
  }
}
const finished = () => t.sprintf(/* translators: %s: course title */ t.__('You finished “%s”', 'membuild-community'), props.course.title)
</script>

<template>
  <BaseDialog v-if="!sharing" :label="finished()" :width="440" :z="95" @close="emit('close')">
    <div class="done">
      <span class="confetti" aria-hidden="true"><i v-for="n in 24" :key="n" :style="{ '--i': n }" /></span>
      <span class="badge"><Icon name="trophy" :size="40" /></span>
      <h2>{{ finished() }}</h2>
      <p>{{ t.__('Every lesson, done. That takes commitment. Tell the community, it might inspire someone to start.', 'membuild-community') }}</p>
      <button class="btn btn-primary" @click="share">{{ t.__('Share it with the community', 'membuild-community') }}</button>
      <RouterLink to="/classroom" class="btn btn-ghost" @click="emit('close')">{{ t.__('Back to Classroom', 'membuild-community') }}</RouterLink>
    </div>
  </BaseDialog>
  <CreatePostDialog v-else :draft="sharing" @close="emit('close')" />
</template>

<style scoped>
.done {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 18px 24px 26px;
  text-align: center;
  overflow: hidden;
}

.badge {
  display: grid;
  place-items: center;
  width: 84px;
  height: 84px;
  border-radius: 50%;
  background: linear-gradient(135deg, #ffd34d, #f5a300);
  color: #6b3d00;
  box-shadow: 0 10px 28px rgb(245 163 0 / 40%);
  animation: pop 0.7s cubic-bezier(0.3, 1.6, 0.5, 1);
}

@keyframes pop {
  from {
    transform: scale(0.2) rotate(-30deg);
  }
}

h2 {
  margin: 6px 0 0;
  font-size: 22px;
  line-height: 1.25;
}

p {
  margin: 0 0 8px;
  color: var(--text-2);
}

.btn {
  width: 100%;
  justify-content: center;
}

.btn-ghost {
  color: var(--text);
}

.confetti i {
  position: absolute;
  top: -12px;
  left: calc(var(--i) * 4%);
  width: 8px;
  height: 13px;
  border-radius: 2px;
  background: hsl(calc(var(--i) * 47) 85% 60%);
  animation: fall 1.8s ease-in forwards;
  animation-delay: calc(var(--i) * 35ms);
  pointer-events: none;
}

@keyframes fall {
  to {
    transform: translateY(380px) rotate(calc(var(--i) * 70deg));
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .confetti {
    display: none;
  }

  .badge {
    animation: none;
  }
}
</style>
