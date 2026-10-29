<script setup>
import { t } from '../lib/i18n.js'

// A post that links to a course shows this instead of the bare link: the cover on the
// left (on top on phones), the name, a two-line summary and how many lessons. The whole card
// opens the course; there's no button. In Create post it's a preview that can't be clicked.
const props = defineProps({ course: { type: Object, required: true }, still: { type: Boolean, default: false } })

const fallback = (id) => `linear-gradient(135deg, hsl(${(id * 67) % 360} 65% 55%), hsl(${(id * 67 + 50) % 360} 60% 38%))`
// The pill is where this member stands: Completed, In progress, or just "Course".
const status = () => {
  const c = props.course
  if (c.lessons_count && c.progress >= 100) return { key: 'done', label: t.__('Completed', 'membuild-community') }
  if (c.completed_count > 0) return { key: 'going', label: t.__('In progress', 'membuild-community') }
  return { key: '', label: t.__('Course', 'membuild-community') }
}
const meta = () => t.sprintf(/* translators: %d: number of lessons */ t._n('%d lesson', '%d lessons', props.course.lessons_count || 0, 'membuild-community'), props.course.lessons_count || 0)
</script>

<template>
  <component :is="still ? 'div' : 'RouterLink'" class="cc" :class="{ still }" v-bind="still ? {} : { to: { name: 'course', params: { courseId: course.id } } }">
    <span class="cover" :style="course.cover ? null : { background: fallback(course.id) }">
      <img v-if="course.cover" :src="course.cover" alt="" loading="lazy" decoding="async" />
    </span>
    <span class="info">
      <strong class="name">{{ course.title }}</strong>
      <span v-if="course.description" class="sum">{{ course.description }}</span>
      <span class="meta">{{ meta() }}</span>
    </span>
    <span class="status-tag on-card" :class="{ done: 'ok', going: 'warn' }[status().key] || 'accent'">{{ status().label }}</span>
  </component>
</template>

<style scoped>
.on-card {
  position: absolute;
  top: 18px;
  right: 18px;
}

.cc {
  position: relative;
  display: flex;
  margin: 4px 14px 12px;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--surface);
  color: var(--text);
  text-decoration: none;
  overflow: hidden;
  transition: border-color 0.15s, background 0.15s;
}

.cc:not(.still):hover {
  border-color: var(--border-strong);
  background: var(--surface-2);
}

.cc:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

.cover {
  flex: none;
  width: 40%;
  aspect-ratio: 16 / 9;
  margin: 12px 0 12px 12px;
  border-radius: 10px;
  overflow: hidden;
}

.cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.info {
  display: flex;
  flex: 1;
  flex-direction: column;
  justify-content: center;
  gap: 6px;
  min-width: 0;
  padding: 16px 18px;
}

.name {
  padding-right: 120px;
  font-size: 19px;
  line-height: 1.3;
}

.sum {
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  color: var(--text-2);
  font-size: 15px;
  line-height: 1.45;
}

.meta {
  color: var(--text-2);
  font-size: 14px;
  font-weight: 600;
}

@media (max-width: 600px) {
  .cc {
    flex-direction: column;
  }

  .cover {
    width: auto;
    margin: 10px 10px 0;
  }

  .info {
    padding: 12px 14px 14px;
  }

  .name {
    padding-right: 0;
  }

  /* On the cover: clear of its rounded corner. */
  .on-card {
    top: 22px;
    right: 22px;
  }
}
</style>
