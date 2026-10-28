<script setup>
import { cfg } from '../config.js'
import { computed, defineAsyncComponent, onMounted, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import BaseDialog from './BaseDialog.vue'
import { api } from '../api.js'
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
  // The words go in the box; the link stays out of sight and is added when they post,
  // so Create post shows the course card instead of a bare URL.
  sharing.value = {
    text: t.sprintf(/* translators: %s: course title */ t.__('I just finished “%s”. Thank you for this course!', 'membuild-community'), props.course.title),
    share: { url, course: props.course },
  }
}
// Confetti like the design: pieces of different colours, shapes and sizes fall at their
// own pace across the whole card for a few seconds, drifting a little as they go.
const COLORS = ['#c8f542', '#ff8a3d', '#e85d9a', '#3b5bfd', '#f5c542', '#2fb36b']
const pieces = Array.from({ length: 40 }, (_, i) => {
  const r = (n) => {
    const x = Math.sin((i + 1) * 12.9898 + n * 78.233) * 43758.5453
    return x - Math.floor(x) // scattered, but the same every time
  }
  const round = r(1) < 0.3
  const size = round ? 6 : 6 + Math.round(r(2) * 4)
  return {
    left: `${(r(3) * 98).toFixed(1)}%`,
    width: `${size}px`,
    height: `${round ? 6 : 6 + Math.round(r(4) * 5)}px`,
    background: COLORS[i % COLORS.length],
    borderRadius: round ? '50%' : '2px',
    '--dx': `${Math.round((r(5) - 0.5) * 100)}px`,
    '--rot': `${Math.round((r(6) - 0.5) * 1000)}deg`,
    animationDuration: `${(2.6 + r(7) * 0.7).toFixed(2)}s`,
    animationDelay: `${(r(8) * 0.9).toFixed(2)}s`,
  }
})

// "Next you might like": a course they can open and haven't finished, ones already started first.
const next = shallowRef(null)
onMounted(async () => {
  if (cfg.community.courses?.suggest === false) return // Settings › Features › Courses
  try {
    const items = (await api.courses()).items || []
    const open = items.filter((c) => c.id !== props.course.id && !c.lock && c.status !== 'draft' && c.progress < 100)
    next.value = open.find((c) => c.progress > 0) || open[0] || null
  } catch {
    // No suggestion is fine.
  }
})
const lessons = computed(() => props.course.lessons_count || 0)
</script>

<template>
  <BaseDialog v-if="!sharing" :label="t.__('You finished the course', 'membuild-community')" :width="520" :z="95" style="border-radius: 24px; overflow: hidden" @close="emit('close')">
    <template #header="{ close }">
      <button class="x" :aria-label="t.__('Close', 'membuild-community')" @click="close"><Icon name="close" :size="20" /></button>
    </template>
    <div class="done">
      <span v-if="cfg.community.courses?.confetti !== false" class="confetti" aria-hidden="true"><i v-for="(p, n) in pieces" :key="n" :style="p" /></span>
      <span class="badge"><Icon name="trophy" :size="44" /></span>
      <h2>{{ t.__('You finished the course!', 'membuild-community') }}</h2>
      <p class="say">
        {{ t.__('Well done finishing', 'membuild-community') }} <b>{{ course.title }}</b>.
      </p>
      <div class="chips">
        <span v-if="lessons" class="chip ok"><Icon name="check" :size="16" />{{ t.sprintf(/* translators: 1: lessons done, 2: lessons in the course */ t.__('%1$d of %2$d lessons', 'membuild-community'), lessons, lessons) }}</span>
        <span v-if="course.finish_points" class="chip">{{ t.sprintf(/* translators: %d: points earned */ t.__('+%d points', 'membuild-community'), course.finish_points) }}</span>
      </div>
      <button class="btn btn-lg btn-dark" @click="share">{{ t.__('Share it with the community', 'membuild-community') }}</button>
      <RouterLink to="/classroom" class="btn btn-lg btn-outline" @click="emit('close')">{{ t.__('Back to Classroom', 'membuild-community') }}</RouterLink>
      <RouterLink v-if="next" :to="{ name: 'course', params: { courseId: next.id } }" class="more" @click="emit('close')">
        {{ t.__('Next you might like:', 'membuild-community') }} <b>{{ next.title }}</b><Icon name="chevronRight" :size="16" />
      </RouterLink>
    </div>
  </BaseDialog>
  <CreatePostDialog v-else :draft="sharing" @close="emit('close')" />
</template>

<style scoped>
.x {
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border: 0;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
  cursor: pointer;
}

.done {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  padding: 40px 40px 32px;
  text-align: center;
  overflow: hidden;
}

.badge {
  display: grid;
  place-items: center;
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background: #c8f542;
  color: #111214;
  box-shadow: 0 0 0 10px rgb(200 245 66 / 14%);
  animation: pop 0.7s cubic-bezier(0.3, 1.6, 0.5, 1);
}

@keyframes pop {
  from {
    transform: scale(0.2) rotate(-30deg);
  }
}

h2 {
  position: relative;
  margin: 10px 0 0;
  font-size: 30px;
  line-height: 1.2;
}

.say {
  position: relative;
  margin: 0;
  color: var(--text-2);
  font-size: 18px;
  line-height: 1.55;
}

.say b {
  color: var(--text);
}

.chips {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
  margin: 6px 0 10px;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0 14px;
  border-radius: 999px;
  background: var(--surface-2);
  font-weight: 700;
  line-height: 24px;
}

.chip.ok {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.btn {
  position: relative;
  width: 100%;
  justify-content: center;
}

.btn-outline {
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
}

.more {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 6px;
  color: var(--text-2);
  font-size: 15px;
  text-decoration: none;
}

.more b {
  color: var(--text);
}

@media (max-width: 600px) {
  .done {
    padding: 36px 22px 26px;
  }

  h2 {
    font-size: 26px;
  }
}

.confetti {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.confetti i {
  position: absolute;
  top: -20px;
  opacity: 0;
  animation-name: confetti;
  animation-timing-function: cubic-bezier(0.2, 0.6, 0.4, 1);
  animation-iteration-count: 2; /* a few seconds, then calm */
  animation-fill-mode: both;
}

@keyframes confetti {
  0% {
    opacity: 0;
    transform: translate(0, 0) rotate(0);
  }
  8% {
    opacity: 1;
  }
  70% {
    opacity: 1;
  }
  100% {
    opacity: 0;
    transform: translate(var(--dx), 460px) rotate(var(--rot));
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
