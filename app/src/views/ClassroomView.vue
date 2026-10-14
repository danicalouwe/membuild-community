<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api.js'
import Icon from '../components/Icon.vue'
import { accessTag, lockLabel } from '../lib/access.js'
import { t } from '../lib/i18n.js'

const router = useRouter()
const courses = ref([])
const canManage = ref(false)
const loading = ref(true)
const error = ref('')
const creating = ref(false)

// A course without a cover gets a gradient from its ID, so the grid never looks empty.
const fallback = (id) => `linear-gradient(135deg, hsl(${(id * 67) % 360} 65% 55%), hsl(${(id * 67 + 50) % 360} 60% 38%))`

onMounted(async () => {
  try {
    const res = await api.courses()
    courses.value = res.items
    canManage.value = res.can_manage
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
})

async function newCourse() {
  if (creating.value) return
  creating.value = true
  try {
    const course = await api.createCourse({ title: t.__('Untitled course', 'membuild-community') })
    router.push({ name: 'course', params: { courseId: course.id }, query: { edit: 1 } })
  } finally {
    creating.value = false
  }
}
const percentDone = (pct) => t.sprintf(/* translators: %d: percent of the course completed */ t.__('%d%% complete', 'membuild-community'), pct)
</script>

<template>
  <div class="classroom">
    <header class="head">
      <h1>{{ t.__('Classroom', 'membuild-community') }}</h1>
    </header>

    <div class="grid">
      <template v-if="loading">
        <div v-for="n in 3" :key="n" class="card course">
          <div class="skeleton cover-sk" />
          <div class="body">
            <div class="skeleton" style="height: 16px; width: 60%" />
            <div class="skeleton" style="height: 12px; width: 90%; margin-top: 10px" />
          </div>
        </div>
      </template>

      <RouterLink
        v-for="c in courses"
        :key="c.id"
        class="card course"
        :class="{ locked: c.lock }"
        :to="{ name: 'course', params: { courseId: c.id } }"
      >
        <div class="cover" :style="c.cover ? null : { background: fallback(c.id) }">
          <img v-if="c.cover" :src="c.cover" alt="" loading="lazy" decoding="async" />
          <span v-else class="cover-title">{{ c.title }}</span>
          <span v-if="c.status === 'draft'" class="draft">{{ t._x('Draft', 'course status', 'membuild-community') }}</span>
          <span v-if="c.lock" class="lock">
            <Icon name="lock" :size="22" />
            {{ lockLabel(c.lock) }}
          </span>
          <span v-else-if="canManage && accessTag(c)" class="draft level-tag"><Icon name="lock" :size="12" /> {{ accessTag(c) }}<template v-if="c.hide_locked"> · {{ t.__('hidden', 'membuild-community') }}</template></span>
        </div>
        <div class="body">
          <h2>{{ c.title }}</h2>
          <p class="desc">{{ c.description }}</p>
          <div class="progress" :aria-label="percentDone(c.progress)">
            <div class="track"><i :style="{ width: `${c.progress}%` }" /></div>
            <span>{{ c.progress }}%</span>
          </div>
        </div>
      </RouterLink>

      <button v-if="canManage" class="card course new" :disabled="creating" @click="newCourse">
        <span class="plus"><Icon name="plus" :size="28" /></span>
        <strong>{{ creating ? t.__('Creating…', 'membuild-community') : t.__('New course', 'membuild-community') }}</strong>
        <span class="hint">{{ t.__('Add modules, lessons, videos and files', 'membuild-community') }}</span>
      </button>
    </div>

    <p v-if="error" class="status">{{ error }}</p>
    <p v-else-if="!loading && !courses.length && !canManage" class="status">{{ t.__('No courses yet. Check back soon.', 'membuild-community') }}</p>
  </div>
</template>

<style scoped>
.classroom {
  max-width: 1080px;
  margin: 0 auto;
  padding: 20px 16px;
}

.head h1 {
  margin: 0 0 16px;
  font-size: 24px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.course {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  color: var(--text);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.course:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px rgb(0 0 0 / 18%);
}

.cover,
.cover-sk {
  position: relative;
  aspect-ratio: 16 / 9;
  overflow: hidden;
  display: grid;
  place-items: center;
}

.cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover-title {
  padding: 16px;
  color: #fff;
  font-size: 22px;
  font-weight: 800;
  text-align: center;
  text-shadow: 0 1px 3px rgb(0 0 0 / 25%);
}

.draft {
  position: absolute;
  top: 10px;
  left: 10px;
  padding: 3px 10px;
  border-radius: 999px;
  background: rgb(0 0 0 / 65%);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
}

.course.locked .cover img,
.course.locked .cover-title {
  filter: grayscale(0.6) brightness(0.55);
}

.lock {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: #fff;
  font-weight: 750;
  font-size: 15px;
  text-shadow: 0 1px 3px rgb(0 0 0 / 40%);
}

.level-tag {
  left: auto;
  right: 10px;
}

.body {
  display: flex;
  flex-direction: column;
  flex: 1;
  padding: 14px 16px 16px;
}

.body h2 {
  margin: 0;
  font-size: 17px;
}

.desc {
  flex: 1;
  margin: 6px 0 14px;
  color: var(--text-2);
  font-size: 14px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.progress {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 650;
  color: var(--text-2);
}

.track {
  flex: 1;
  height: 8px;
  border-radius: 999px;
  background: var(--surface-2);
  overflow: hidden;
}

.track i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: #2fb36b;
  transition: width 0.4s ease;
}

.new {
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 260px;
  border-style: dashed;
  background: transparent;
  box-shadow: none;
  color: var(--text-2);
}

.new .plus {
  display: grid;
  place-items: center;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
}

.new strong {
  color: var(--text);
  font-size: 16px;
}

.hint {
  font-size: 13px;
}

.status {
  color: var(--text-3);
  text-align: center;
  margin: 24px 0;
}
</style>
