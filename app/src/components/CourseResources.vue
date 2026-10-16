<script setup>
import { ref, watch } from 'vue'
import { api } from '../api.js'
import Icon from './Icon.vue'
import { fileSize } from '../lib/files.js'
import { t } from '../lib/i18n.js'

// The Resources tab: every lesson's files in one place, in course order.
const props = defineProps({ courseId: { type: Number, required: true } })
const emit = defineEmits(['open-lesson'])

const items = ref([])
const loading = ref(true)
const error = ref('')

watch(
  () => props.courseId,
  async (id) => {
    loading.value = true
    error.value = ''
    try {
      items.value = (await api.courseResources(id)).items
    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)
const opensOn = (iso) => t.sprintf(/* translators: %s: a date */ t.__('Opens %s', 'membuild-community'), new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }))
</script>

<template>
  <section class="resources">
    <div v-if="loading" class="card pad">
      <div v-for="n in 3" :key="n" class="skeleton" style="height: 44px; margin-bottom: 8px; border-radius: 10px" />
    </div>
    <p v-else-if="error" class="card pad empty">{{ error }}</p>
    <div v-else-if="!items.length" class="card pad empty">
      <Icon name="paperclip" :size="28" />
      <p>{{ t.__('No files in this course yet.', 'membuild-community') }}</p>
    </div>

    <div v-for="group in items" :key="group.lesson_id" class="card group">
      <button class="lesson-link" @click="emit('open-lesson', group.lesson_id)">
        <small>{{ group.module_title }}</small>
        <strong>{{ group.lesson_title }}</strong>
      </button>
      <template v-for="f in group.files" :key="f.media_id">
        <a v-if="f.url" :href="f.url" class="file" target="_blank" rel="noopener" download>
          <span class="type" :data-ext="f.ext">{{ f.ext || t.__('File', 'membuild-community') }}</span>
          <span class="name">{{ f.name }}</span>
          <span v-if="f.size" class="size">{{ fileSize(f.size) }}</span>
          <Icon name="download" :size="18" class="dl" />
        </a>
        <span v-else class="file locked">
          <span class="type" :data-ext="f.ext">{{ f.ext || t.__('File', 'membuild-community') }}</span>
          <span class="name">{{ f.name }}</span>
          <span class="size">{{ group.unlock_at ? opensOn(group.unlock_at) : '' }}</span>
          <Icon name="lock" :size="16" class="dl" />
        </span>
      </template>
    </div>
  </section>
</template>

<style scoped>
.resources {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.pad {
  padding: 20px 22px;
}

.empty {
  text-align: center;
  color: var(--text-3);
}

.empty p {
  margin: 8px 0 0;
}

.group {
  padding: 14px 16px;
}

.lesson-link {
  display: flex;
  flex-direction: column;
  margin-bottom: 8px;
  text-align: left;
}

.lesson-link small {
  color: var(--text-3);
  font-size: 12px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.lesson-link strong {
  font-size: 15px;
}

.lesson-link:hover strong {
  text-decoration: underline;
}

.file {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  margin-top: 6px;
  border-radius: 10px;
  background: var(--surface-2);
  color: var(--text);
  font-weight: 600;
  font-size: 14px;
}

a.file:hover {
  background: color-mix(in srgb, var(--surface-2) 85%, var(--text) 8%);
}

.file.locked {
  color: var(--text-3);
}

.type {
  display: grid;
  place-items: center;
  flex: none;
  min-width: 42px;
  height: 30px;
  padding: 0 6px;
  border-radius: 6px;
  background: #6b7280;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.03em;
}

.type[data-ext='PDF'] {
  background: #e5484d;
}

.type[data-ext='DOC'],
.type[data-ext='DOCX'] {
  background: #2f6fe4;
}

.type[data-ext='XLS'],
.type[data-ext='XLSX'],
.type[data-ext='CSV'] {
  background: #2fb36b;
}

.type[data-ext='PPT'],
.type[data-ext='PPTX'],
.type[data-ext='KEY'] {
  background: #f07b16;
}

.type[data-ext='ZIP'] {
  background: #8d5cf6;
}

.name {
  flex: 1;
  min-width: 0;
  overflow-wrap: anywhere;
}

.size {
  flex: none;
  color: var(--text-3);
  font-size: 13px;
  font-weight: 500;
}

.dl {
  flex: none;
  color: var(--accent-text);
}

.locked .dl {
  color: var(--text-3);
}
</style>
