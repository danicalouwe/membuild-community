<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'

/**
 * Taking a lesson's quiz (canvas: Quizzes · Member): one question at a time, Check answer, then
 * right or wrong on what they picked and the Why, then Next question. The right answer is never
 * sent before checking, and after a wrong one it isn't shown (rule 11). Answers are kept as they
 * go, so leaving and coming back carries on at the question they left (rule 9). The last answer
 * shows the result: passed, not passed (Try again), or saved (They can move on anyway).
 */
const props = defineProps({
  lesson: { type: Object, required: true }, // the open lesson: id, title, number, next_id
  inOrder: { type: Boolean, default: false },
  // This try finished the whole course: the result leads on to Course finished instead of Next lesson.
  courseDone: { type: Object, default: null }, // { cert }
})
const emit = defineEmits(['back', 'answers', 'next', 'finished', 'done'])

const data = ref(null) // { quiz, questions, answers }
const loadError = ref('')
const idx = ref(0)
const given = ref({}) // question id → { picked, right, why }
const picked = ref([])
const busy = ref(false)
const error = ref('')
const result = ref(null) // { score, total, pass_mark, passed, on_fail }

async function load() {
  loadError.value = ''
  try {
    const d = await api.quiz(props.lesson.id)
    data.value = d
    const open = d.quiz?.state === 'open'
    given.value = open ? { ...d.answers } : {}
    idx.value = open ? Math.max(0, Math.min(d.questions.length - 1, (d.quiz.at || 1) - 1)) : 0
    picked.value = []
    result.value = null
  } catch (err) {
    loadError.value = err.message
  }
}
watch(() => props.lesson.id, load, { immediate: true })

const questions = computed(() => data.value?.questions || [])
const total = computed(() => questions.value.length)
const q = computed(() => questions.value[idx.value] || null)
const checked = computed(() => (q.value ? given.value[q.value.id] || null : null))
const many = computed(() => q.value?.type === 'many')
const quiz = computed(() => data.value?.quiz || {})
const mark = computed(() => quiz.value.pass_mark || 0)
const moveOn = computed(() => quiz.value.on_fail === 'move_on')

const lessonName = computed(() => (props.lesson.number ? t.sprintf(/* translators: 1: lesson number, 2: lesson title */ t.__('Lesson %1$d: %2$s', 'membuild-community'), props.lesson.number, props.lesson.title) : props.lesson.title))
const stepLabel = computed(() => t.sprintf(/* translators: 1: question number, 2: number of questions */ t.__('Question %1$d of %2$d', 'membuild-community'), idx.value + 1, total.value))
const doneLabel = computed(() => t.sprintf(/* translators: 1: questions answered, 2: number of questions */ t.__('Done · %1$d of %2$d', 'membuild-community'), total.value, total.value))
const footLine = computed(() =>
  moveOn.value
    ? t.sprintf(/* translators: 1: pass mark, 2: number of questions */ t.__('You need %1$d of %2$d to pass. If you don’t, you can still move on.', 'membuild-community'), mark.value, total.value)
    : t.sprintf(/* translators: 1: pass mark, 2: number of questions */ t.__('You need %1$d of %2$d to pass. You can try again as many times as you like.', 'membuild-community'), mark.value, total.value),
)

function toggle(id) {
  if (checked.value) return
  if (many.value) picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id]
  else picked.value = [id]
  error.value = ''
}
const isPicked = (id) => (checked.value ? checked.value.picked.includes(id) : picked.value.includes(id))
// Only the member's own pick is marked: Right or Wrong. With more than one right answer the
// whole question is right or wrong, so the mark sits beside the Why instead.
const rowState = (id) => (!checked.value || many.value || !checked.value.picked.includes(id) ? '' : checked.value.right ? 'right' : 'wrong')

async function check() {
  if (!picked.value.length || busy.value) return
  busy.value = true
  error.value = ''
  try {
    const res = await api.answerQuiz(props.lesson.id, { question_id: q.value.id, picked: picked.value })
    given.value = { ...given.value, [res.question_id]: { picked: res.picked, right: res.right, why: res.why } }
    picked.value = []
    if (res.finished) {
      pending = res
      emit('finished', res)
    }
  } catch (err) {
    if (err.status === 409) return load() // the owner changed the quiz: start from what it is now
    error.value = err.message
  } finally {
    busy.value = false
  }
}
let pending = null
const isLast = computed(() => idx.value === total.value - 1)
function next() {
  if (isLast.value && pending) {
    result.value = pending.result
    pending = null
    window.scrollTo({ top: 0 })
    return
  }
  // Forward only, as drawn: there's no Back (See your answers shows them all at the end).
  idx.value = Math.min(total.value - 1, idx.value + 1)
  picked.value = []
  window.scrollTo({ top: 0 })
}
function tryAgain() {
  given.value = {}
  picked.value = []
  result.value = null
  idx.value = 0
  window.scrollTo({ top: 0 })
}
// The last question of a quiz finished earlier in this visit: show the result, not Next question.
const lastAnswered = computed(() => isLast.value && !!checked.value && Object.keys(given.value).length >= total.value)

const resultTitle = computed(() => {
  const r = result.value
  if (!r) return ''
  if (r.passed) return t.sprintf(/* translators: 1: right answers, 2: number of questions */ t.__('You passed: %1$d of %2$d', 'membuild-community'), r.score, r.total)
  if (r.on_fail === 'move_on') return t.sprintf(/* translators: 1: right answers, 2: number of questions */ t.__('%1$d of %2$d. Saved.', 'membuild-community'), r.score, r.total)
  return t.sprintf(/* translators: 1: right answers, 2: number of questions, 3: pass mark */ t.__('%1$d of %2$d. You need %3$d to pass.', 'membuild-community'), r.score, r.total, r.pass_mark)
})
const resultLine = computed(() => {
  const r = result.value
  if (!r) return ''
  if (r.passed) return props.lesson.next_id ? t.__('This lesson is done. The next lesson is open.', 'membuild-community') : t.__('This lesson is done.', 'membuild-community')
  if (r.on_fail === 'move_on') return t.__('You can move on. Try again any time if you want a better score.', 'membuild-community')
  return props.inOrder && props.lesson.next_id
    ? t.__('Have another look at the lesson, then try again. The next lesson opens when you pass.', 'membuild-community')
    : t.__('Have another look at the lesson, then try again. The lesson counts as done when you pass.', 'membuild-community')
})
</script>

<template>
  <div class="qt">
    <button type="button" class="back-link" @click="emit('back')"><Icon name="chevronLeft" :size="18" /> {{ lessonName }}</button>

    <p v-if="loadError" class="error-text" role="alert">{{ loadError }}</p>
    <div v-else-if="!data" class="card qt-card"><div class="skeleton" style="height: 24px; width: 60%" /><div class="skeleton" style="height: 52px; margin-top: 18px" /><div class="skeleton" style="height: 52px; margin-top: 10px" /></div>

    <!-- The result -->
    <template v-else-if="result">
      <div class="qt-step">
        <span>{{ doneLabel }}</span>
        <span class="qt-track" aria-hidden="true"><i style="width: 100%" /></span>
      </div>
      <section class="card qt-card qt-result" aria-live="polite">
        <div class="qt-result-head">
          <span class="qt-badge" :class="result.passed ? 'ok' : result.on_fail === 'move_on' ? 'plain' : 'warn'"><Icon :name="result.passed || result.on_fail === 'move_on' ? 'check' : 'retry'" :size="20" /></span>
          <span>
            <strong>{{ resultTitle }}</strong>
            <small>{{ resultLine }}</small>
          </span>
        </div>
        <div class="qt-btns">
          <template v-if="result.passed">
            <button v-if="courseDone" type="button" class="btn btn-lg btn-primary" @click="emit('done')">{{ courseDone.cert ? t.__('See your certificate', 'membuild-community') : t.__('Finish course', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
            <button v-else-if="lesson.next_id" type="button" class="btn btn-lg btn-primary" @click="emit('next')">{{ t.__('Next lesson', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
            <button type="button" class="btn btn-lg btn-ghost" @click="emit('answers')">{{ t.__('See your answers', 'membuild-community') }}</button>
          </template>
          <template v-else-if="result.on_fail === 'move_on'">
            <button v-if="courseDone" type="button" class="btn btn-lg btn-primary" @click="emit('done')">{{ courseDone.cert ? t.__('See your certificate', 'membuild-community') : t.__('Finish course', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
            <button v-else-if="lesson.next_id" type="button" class="btn btn-lg btn-primary" @click="emit('next')">{{ t.__('Next lesson', 'membuild-community') }} <Icon name="arrowRight" :size="18" /></button>
            <button type="button" class="btn btn-lg btn-ghost" @click="tryAgain"><Icon name="retry" :size="18" /> {{ t.__('Try again', 'membuild-community') }}</button>
            <button type="button" class="btn btn-lg btn-ghost" @click="emit('answers')">{{ t.__('See your answers', 'membuild-community') }}</button>
          </template>
          <template v-else>
            <button type="button" class="btn btn-lg btn-primary" @click="tryAgain"><Icon name="retry" :size="18" /> {{ t.__('Try again', 'membuild-community') }}</button>
            <button type="button" class="btn btn-lg btn-ghost" @click="emit('answers')">{{ t.__('See your answers', 'membuild-community') }}</button>
          </template>
        </div>
      </section>
    </template>

    <!-- One question -->
    <template v-else-if="q">
      <div class="qt-step">
        <span>{{ stepLabel }}</span>
        <span class="qt-track" role="progressbar" :aria-valuenow="idx + 1" aria-valuemin="1" :aria-valuemax="total" :aria-label="stepLabel"><i :style="{ width: `${((idx + 1) / total) * 100}%` }" /></span>
      </div>
      <section class="card qt-card" :aria-labelledby="`qt-q-${q.id}`">
        <h1 :id="`qt-q-${q.id}`" class="qt-question">{{ q.question }}</h1>
        <p v-if="many" class="qt-how">{{ t.__('Tick every right answer.', 'membuild-community') }}</p>
        <img v-if="q.image" class="qt-image" :src="q.image.url" :width="q.image.width || null" :height="q.image.height || null" alt="" />
        <div class="qt-answers" :role="many ? 'group' : 'radiogroup'" :aria-labelledby="`qt-q-${q.id}`">
          <label v-for="a in q.answers" :key="a.id" class="qt-answer" :class="[{ on: isPicked(a.id), done: !!checked }, rowState(a.id)]">
            <input :type="many ? 'checkbox' : 'radio'" :name="`qt-${q.id}`" :checked="isPicked(a.id)" :aria-disabled="!!checked || null" @click="checked && $event.preventDefault()" @change="toggle(a.id)" />
            <span class="qt-text">{{ a.text }}</span>
            <span v-if="rowState(a.id) === 'right'" class="qt-mark right"><Icon name="check" :size="14" /> {{ t.__('Right', 'membuild-community') }}</span>
            <span v-else-if="rowState(a.id) === 'wrong'" class="qt-mark wrong"><Icon name="close" :size="14" /> {{ t.__('Wrong', 'membuild-community') }}</span>
          </label>
        </div>
        <div v-if="checked" class="qt-why" aria-live="polite">
          <span v-if="many" class="status-tag" :class="checked.right ? 'ok' : 'bad'"><Icon :name="checked.right ? 'check' : 'close'" :size="14" /> {{ checked.right ? t.__('Right', 'membuild-community') : t.__('Wrong', 'membuild-community') }}</span>
          <template v-if="checked.why">
            <strong>{{ t.__('Why this is the answer', 'membuild-community') }}</strong>
            <p>{{ checked.why }}</p>
          </template>
        </div>
        <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        <div class="qt-nav">
          <span class="qt-grow" />
          <button v-if="!checked" type="button" class="btn btn-lg btn-primary" :disabled="!picked.length || busy" @click="check">{{ busy ? t.__('Checking…', 'membuild-community') : t.__('Check answer', 'membuild-community') }}</button>
          <button v-else-if="lastAnswered" type="button" class="btn btn-lg btn-primary" @click="next">{{ t.__('See your result', 'membuild-community') }}</button>
          <button v-else type="button" class="btn btn-lg btn-primary" @click="next">{{ t.__('Next question', 'membuild-community') }}</button>
        </div>
      </section>
      <p class="qt-foot">{{ footLine }}</p>
    </template>
  </div>
</template>

<style scoped>
.qt {
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: 100%;
  max-width: 680px;
  margin: 0 auto;
  padding: 14px 16px 40px;
}
.qt .back-link {
  align-self: flex-start;
  color: var(--text-2);
  text-align: left;
}
.qt-step {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 700;
  white-space: nowrap;
}
.qt-track {
  flex: 1;
  height: 6px;
  overflow: hidden;
  border-radius: var(--radius-pill);
  background: var(--surface-2);
}
.qt-track i {
  display: block;
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--accent);
  transition: width 0.3s ease;
}
.qt-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 22px 20px 20px;
  border-radius: var(--radius-lg);
}
.qt-question {
  margin: 0;
  font-size: var(--fs-3xl);
  line-height: 1.3;
}
.qt-how {
  margin: -4px 0 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}
.qt-image {
  width: 100%;
  height: auto;
  max-height: 360px;
  border-radius: var(--radius-md);
  object-fit: contain;
  background: var(--surface-2);
}
.qt-answers {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.qt-answer {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 52px;
  padding: 10px 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  cursor: pointer;
}
.qt-answer.done {
  cursor: default;
}
.qt-answer.on {
  border-color: var(--accent-text);
  background: var(--accent-soft);
  box-shadow: inset 0 0 0 1px var(--accent-text);
}
.qt-answer.right {
  border-color: var(--ok-text);
  background: var(--ok-soft);
  box-shadow: inset 0 0 0 1px var(--ok-text);
}
.qt-answer.wrong {
  border-color: var(--danger-text);
  background: var(--danger-soft);
  box-shadow: inset 0 0 0 1px var(--danger-text);
}
.qt-answer:has(input:focus-visible) {
  outline: 2px solid var(--accent-text);
  outline-offset: 2px;
}
.qt-answer input {
  flex: none;
  width: 22px;
  height: 22px;
  margin: 0;
  accent-color: var(--accent-text);
}
.qt-answer.done input {
  pointer-events: none; /* answered: it stays as they picked it */
}
.qt-text {
  flex: 1;
  min-width: 0;
  font-size: var(--fs-lg);
}
.qt-mark {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 4px;
  font-size: var(--fs-sm);
  font-weight: 700;
}
.qt-mark.right {
  color: var(--ok-text);
}
.qt-mark.wrong {
  color: var(--danger-text);
}
.qt-why {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
}
.qt-why strong {
  font-size: var(--fs-md);
}
.qt-why p {
  margin: 0;
  line-height: 1.5;
}
.qt-nav {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 4px;
}
.qt-grow {
  flex: 1;
}
.qt-foot {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}
.qt-result-head {
  display: flex;
  align-items: flex-start;
  gap: 14px;
}
.qt-result-head strong {
  display: block;
  font-size: var(--fs-xl);
}
.qt-result-head small {
  display: block;
  margin-top: 2px;
  color: var(--text-2);
  font-size: var(--fs-base);
  line-height: 1.45;
}
.qt-badge {
  display: grid;
  flex: none;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-sm);
}
.qt-badge.ok {
  background: var(--accent);
  color: var(--accent-ink);
}
.qt-badge.warn {
  background: var(--warn-soft);
  color: var(--warn-text);
}
.qt-badge.plain {
  background: var(--surface-2);
  color: var(--text);
}
.qt-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
@media (max-width: 600px) {
  .qt {
    padding: 8px 16px 32px;
  }
  .qt-card {
    padding: 18px 16px 16px;
  }
  .qt-question {
    font-size: var(--fs-2xl);
  }
  .qt-btns .btn {
    flex: 1 1 calc(50% - 5px);
  }
  .qt-btns .btn-primary {
    flex-basis: 100%;
  }
}
</style>
