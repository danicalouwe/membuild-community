<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'

/**
 * "Your answers" for the latest finished try: each question right or wrong, the member's own
 * answer, the right answers (passed or not, since 1.22.1) and the Why.
 */
const props = defineProps({
  lesson: { type: Object, required: true },
})
const emit = defineEmits(['back', 'retry'])

const data = ref(null)
const loadError = ref('')
watch(
  () => props.lesson.id,
  async (id) => {
    loadError.value = ''
    data.value = null
    try {
      data.value = await api.quizAnswers(id)
    } catch (err) {
      loadError.value = err.message
    }
  },
  { immediate: true },
)

const lessonName = computed(() => (props.lesson.number ? t.sprintf(/* translators: 1: lesson number, 2: lesson title */ t.__('Lesson %1$d: %2$s', 'membuild-community'), props.lesson.number, props.lesson.title) : props.lesson.title))
const scoreLine = computed(() => {
  const d = data.value
  if (!d) return ''
  return d.passed
    ? t.sprintf(/* translators: 1: right answers, 2: number of questions */ t.__('%1$d of %2$d. You passed.', 'membuild-community'), d.score, d.total)
    : t.sprintf(/* translators: 1: right answers, 2: number of questions, 3: pass mark */ t.__('%1$d of %2$d. You need %3$d to pass.', 'membuild-community'), d.score, d.total, d.pass_mark)
})
const numbered = (i) => t.sprintf(/* translators: %d: question number */ t.__('Question %d', 'membuild-community'), i + 1)
</script>

<template>
  <div class="qa">
    <button type="button" class="back-link" @click="emit('back')"><Icon name="chevronLeft" :size="18" /> {{ lessonName }}</button>
    <h1 class="page-title">{{ t.__('Your answers', 'membuild-community') }}</h1>
    <p v-if="loadError" class="error-text" role="alert">{{ loadError }}</p>
    <div v-else-if="!data" class="card qa-item"><div class="skeleton" style="height: 20px; width: 60%" /><div class="skeleton" style="height: 60px; margin-top: 14px" /></div>
    <template v-else>
      <div class="qa-score">
        <strong>{{ scoreLine }}</strong>
        <span v-if="data.passed" class="status-tag ok">{{ t.__('Passed', 'membuild-community') }}</span>
        <span v-else class="status-tag warn">{{ t.__('Not passed yet', 'membuild-community') }}</span>
      </div>

      <section v-for="(item, i) in data.items" :key="item.id" class="card qa-item">
        <div class="qa-top">
          <small>{{ numbered(i) }}</small>
          <span v-if="item.right" class="status-tag ok"><Icon name="check" :size="14" /> {{ t.__('Right', 'membuild-community') }}</span>
          <span v-else class="status-tag bad"><Icon name="close" :size="14" /> {{ t.__('Wrong', 'membuild-community') }}</span>
        </div>
        <h2>{{ item.question }}</h2>
        <p class="qa-line"><span>{{ t.__('Your answer:', 'membuild-community') }}</span> {{ item.picked.length ? item.picked.join(' · ') : t.__('Not answered', 'membuild-community') }}</p>
        <p v-if="!item.right && item.right_answers" class="qa-line"><span>{{ t.__('Right answer:', 'membuild-community') }}</span> {{ item.right_answers.join(' · ') }}</p>
        <div v-if="item.why" class="qa-why">
          <strong>{{ t.__('Why this is the answer', 'membuild-community') }}</strong>
          <p>{{ item.why }}</p>
        </div>
      </section>

      <div class="qa-btns">
        <button v-if="!data.passed" type="button" class="btn btn-lg btn-primary" @click="emit('retry')"><Icon name="retry" :size="18" /> {{ t.__('Try again', 'membuild-community') }}</button>
        <button type="button" class="btn btn-lg btn-ghost" @click="emit('back')">{{ t.__('Back to the lesson', 'membuild-community') }}</button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.qa {
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: 100%;
  max-width: 720px;
  margin: 0 auto;
  padding: 14px 16px 40px;
}
.qa .back-link {
  align-self: flex-start;
  color: var(--text-2);
  text-align: left;
}
.qa .page-title {
  margin: 0;
}
.qa-score {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: var(--fs-lg);
}
.qa .info-note {
  margin: 0;
}
.qa-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 20px 20px;
  border-radius: var(--radius-lg);
}
.qa-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.qa-top small {
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 650;
}
.qa-item h2 {
  margin: 0;
  font-size: var(--fs-xl);
  line-height: 1.35;
}
.qa-line {
  margin: 0;
}
.qa-line span {
  color: var(--text-2);
}
.qa-why {
  padding: 12px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
}
.qa-why strong {
  font-size: var(--fs-md);
}
.qa-why p {
  margin: 4px 0 0;
  line-height: 1.5;
}
.qa-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
@media (max-width: 600px) {
  .qa-item {
    padding: 16px;
  }
  .qa-btns .btn {
    flex: 1 1 100%;
  }
}
</style>
