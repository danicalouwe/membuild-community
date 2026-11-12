<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api.js'
import { askConfirm } from '../lib/confirm.js'
import { useMenu } from '../lib/useMenu.js'
import { showToast } from '../lib/toast.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'
import QuestionEditor from './QuestionEditor.vue'

/**
 * The owner's quiz editor (course builder): the questions (drag to change the order, ⋯ for Edit
 * and Delete question), Add a question with its type picker, the pass mark, what happens if they
 * don't pass, and how members are doing. One quiz per lesson, at the end (canvas: Quizzes · Owner).
 * The question editor opens in its place (?question=<id>|new&type=…), so the browser's back works.
 */
const props = defineProps({
  lessonId: { type: Number, required: true },
  courseId: { type: Number, required: true },
})
const emit = defineEmits(['changed'])

const route = useRoute()
const router = useRouter()
const view = ref(null) // { lesson_title, quiz: { id, pass_mark, pass_chosen, on_fail, questions, stats } }
const loadError = ref('')
const passMark = ref('')
const passError = ref('')
const saving = ref(false)

const TYPES = [
  { id: 'one', icon: 'radio', name: () => t.__('One right answer', 'membuild-community'), line: () => t.__('Members pick one.', 'membuild-community') },
  { id: 'many', icon: 'checkBox', name: () => t.__('More than one right answer', 'membuild-community'), line: () => t.__('Members tick every right one.', 'membuild-community') },
  { id: 'truefalse', icon: 'check', name: () => t.__('True or false', 'membuild-community'), line: () => t.__('Two answers, already filled in.', 'membuild-community') },
]
const typeName = (id) => (TYPES.find((x) => x.id === id) || TYPES[0]).name()

async function load() {
  loadError.value = ''
  try {
    let v = await api.quizEdit(props.lessonId)
    if (!v.quiz) v = await api.createQuiz(props.lessonId) // "Add a quiz" opened it: start an empty one
    apply(v)
  } catch (err) {
    loadError.value = err.message
  }
}
function apply(v) {
  view.value = v
  passMark.value = String(v.quiz.pass_mark || '')
  passError.value = ''
  emit('changed', v)
}
watch(() => props.lessonId, load, { immediate: true })

const quiz = computed(() => view.value?.quiz)
const questions = computed(() => quiz.value?.questions || [])
const editing = computed(() => route.query.question || null) // 'new' or a question id
const editingQuestion = computed(() => (editing.value && editing.value !== 'new' ? questions.value.find((q) => String(q.id) === String(editing.value)) || null : null))
const editingNumber = computed(() => (editingQuestion.value ? questions.value.indexOf(editingQuestion.value) + 1 : questions.value.length + 1))
const newType = computed(() => (TYPES.some((x) => x.id === route.query.type) ? route.query.type : 'one'))

const backHref = computed(() => router.resolve({ name: 'course', params: { courseId: props.courseId, lessonId: props.lessonId }, query: { edit: 1 } }).href)
const quizTitle = computed(() => t.sprintf(/* translators: %s: lesson title */ t.__('Quiz: %s', 'membuild-community'), view.value?.lesson_title || ''))

function openQuestion(id, type) {
  router.push({ query: { ...route.query, question: id, type: type || undefined } })
}
function closeQuestion() {
  router.push({ query: { ...route.query, question: undefined, type: undefined } })
}
function onSaved(v) {
  apply(v)
  showToast(t.__('Question saved', 'membuild-community'))
  closeQuestion()
}

/* ---------- Add a question: the type picker ---------- */
const { open: picker, root: pickerRoot, trigger: pickerTrigger, list: pickerList, toggle: togglePicker, close: closePicker, onKeydown: onPickerKey } = useMenu()
function pick(type) {
  closePicker({ focus: false })
  openQuestion('new', type)
}

/* ---------- One row's ⋯ menu ---------- */
const menuFor = ref(null)
const { open: rowMenu, root: rowRoot, trigger: rowTrigger, list: rowList, toggle: toggleRow, close: closeRow, onKeydown: onRowKey } = useMenu()
watch(rowMenu, (open) => !open && (menuFor.value = null))
function rowMenuToggle(e, q) {
  if (rowMenu.value && menuFor.value !== q.id) closeRow({ focus: false })
  menuFor.value = q.id
  nextTick(() => toggleRow(e))
}
async function removeQuestion(q) {
  closeRow({ focus: false })
  const ok = await askConfirm({
    title: t.__('Delete this question?', 'membuild-community'),
    text: t.sprintf(/* translators: %s: the question */ t.__('“%s” goes away for good. Members who already passed keep their pass.', 'membuild-community'), q.question),
    confirm: t.__('Delete question', 'membuild-community'),
    cancel: t.__('Keep it', 'membuild-community'),
    danger: true,
  })
  if (!ok) return
  try {
    apply(await api.deleteQuestion(q.id))
    showToast(t.__('Question deleted', 'membuild-community'))
  } catch (err) {
    showToast(err.message)
  }
}

/* ---------- Changing the order: drag a handle, or press it for Move up / Move down ---------- */
let dragging = null
const moveFor = ref(null)
function dragStart(e, i) {
  dragging = i
  e.dataTransfer.effectAllowed = 'move'
}
function dropOn(i) {
  const from = dragging
  dragging = null
  if (from === null || from === i) return
  move(from, i)
}
async function move(from, to) {
  moveFor.value = null
  if (to < 0 || to >= questions.value.length) return
  const list = [...questions.value]
  const [q] = list.splice(from, 1)
  list.splice(to, 0, q)
  const before = view.value.quiz.questions
  view.value.quiz.questions = list
  try {
    apply(await api.orderQuestions(quiz.value.id, list.map((x) => x.id)))
  } catch (err) {
    view.value.quiz.questions = before
    showToast(err.message)
  }
}

/* ---------- Pass mark and what happens if they don't pass: they save by themselves ---------- */
async function savePass() {
  const raw = String(passMark.value ?? '').trim() // a number box gives a number, or '' when empty
  const n = Number(raw)
  if (String(n) === String(quiz.value.pass_mark) && quiz.value.pass_chosen) return
  passError.value = ''
  if (!raw || !Number.isInteger(n) || n < 1) {
    passError.value = t.__('Type a number of 1 or more.', 'membuild-community')
    return
  }
  await save({ pass_mark: n })
}
async function save(body) {
  saving.value = true
  try {
    apply(await api.updateQuiz(props.lessonId, body))
  } catch (err) {
    if (err.code === 'mbcom_pass_mark') passError.value = err.message
    else showToast(err.message)
  } finally {
    saving.value = false
  }
}
const ofCount = computed(() => t.sprintf(/* translators: %d: number of questions */ t.__('of %d', 'membuild-community'), questions.value.length))
const passedOf = (s) => t.sprintf(/* translators: 1: members who passed, 2: members who tried */ t.__('%1$d of %2$d members who tried', 'membuild-community'), s.passed, s.tried)
const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0)
const rightPct = (n) => t.sprintf(/* translators: %d: percent */ t.__('%d%% right', 'membuild-community'), n)
const countLabel = computed(() => t.sprintf(/* translators: %d: number of questions */ t.__('Questions · %d', 'membuild-community'), questions.value.length))
</script>

<template>
  <div class="qe">
    <QuestionEditor
      v-if="view && editing"
      :key="String(editing)"
      :quiz-id="quiz.id"
      :question="editingQuestion"
      :type="newType"
      :number="editingNumber"
      :quiz-title="quizTitle"
      :types="TYPES"
      @saved="onSaved"
      @cancel="closeQuestion"
    />

    <template v-else>
      <RouterLink :to="backHref" class="back-link"><Icon name="chevronLeft" :size="18" /> {{ t.__('Course builder', 'membuild-community') }}</RouterLink>
      <p v-if="loadError" class="error-text" role="alert">{{ loadError }}</p>
      <div v-else-if="!view" class="card qe-card"><div class="skeleton" style="height: 24px; width: 50%" /></div>
      <template v-else>
        <h1 class="page-title">{{ quizTitle }}</h1>
        <p class="page-intro">{{ t.__('Questions at the end of a lesson. Members answer them before moving on.', 'membuild-community') }}</p>

        <section class="card qe-card">
          <header class="qe-head">
            <h2><span class="long">{{ t.__('Questions', 'membuild-community') }}</span><span class="short">{{ countLabel }}</span></h2>
            <span v-if="questions.length" :ref="pickerRoot" class="qe-add">
              <button :ref="pickerTrigger" class="btn btn-lg btn-primary" aria-haspopup="menu" :aria-expanded="picker" :disabled="questions.length >= 50" @click="togglePicker"><Icon name="plus" :size="20" /> {{ t.__('Add a question', 'membuild-community') }}</button>
              <div v-if="picker" :ref="pickerList" class="qe-picker" role="menu" :aria-label="t.__('Question type', 'membuild-community')" @keydown="onPickerKey">
                <p class="qe-picker-title">{{ t.__('What kind of question?', 'membuild-community') }}</p>
                <button v-for="ty in TYPES" :key="ty.id" role="menuitem" type="button" @click="pick(ty.id)">
                  <Icon :name="ty.icon" :size="20" />
                  <span><strong>{{ ty.name() }}</strong><small>{{ ty.line() }}</small></span>
                </button>
                <p class="qe-picker-note">{{ t.__('All three are marked for you. Each can have a picture and a “Why this is the answer” line, shown after answering.', 'membuild-community') }}</p>
              </div>
            </span>
          </header>

          <div v-if="!questions.length" class="empty-state small dashed qe-empty">
            <span class="empty-state-icon"><Icon name="quiz" :size="24" /></span>
            <p class="empty-state-title">{{ t.__('No questions yet', 'membuild-community') }}</p>
            <p class="empty-state-text">{{ t.__('Add the first one. Members see the quiz once it has a question.', 'membuild-community') }}</p>
            <span :ref="pickerRoot" class="qe-add">
              <button :ref="pickerTrigger" class="btn btn-lg btn-primary" aria-haspopup="menu" :aria-expanded="picker" @click="togglePicker"><Icon name="plus" :size="20" /> {{ t.__('Add a question', 'membuild-community') }}</button>
              <div v-if="picker" :ref="pickerList" class="qe-picker center" role="menu" :aria-label="t.__('Question type', 'membuild-community')" @keydown="onPickerKey">
                <p class="qe-picker-title">{{ t.__('What kind of question?', 'membuild-community') }}</p>
                <button v-for="ty in TYPES" :key="ty.id" role="menuitem" type="button" @click="pick(ty.id)">
                  <Icon :name="ty.icon" :size="20" />
                  <span><strong>{{ ty.name() }}</strong><small>{{ ty.line() }}</small></span>
                </button>
              </div>
            </span>
          </div>

          <ol v-else class="qe-list">
            <li v-for="(q, i) in questions" :key="q.id" class="qe-row" @dragover.prevent @drop.prevent="dropOn(i)">
              <span class="qe-handle-wrap">
                <button class="qe-handle" draggable="true" :aria-label="t.sprintf(/* translators: %d: question number */ t.__('Move question %d', 'membuild-community'), i + 1)" :aria-expanded="moveFor === q.id" @dragstart="dragStart($event, i)" @click="moveFor = moveFor === q.id ? null : q.id"><Icon name="grip" :size="20" /></button>
                <span v-if="moveFor === q.id" class="qe-menu">
                  <button type="button" :disabled="i === 0" @click="move(i, i - 1)">{{ t.__('Move up', 'membuild-community') }}</button>
                  <button type="button" :disabled="i === questions.length - 1" @click="move(i, i + 1)">{{ t.__('Move down', 'membuild-community') }}</button>
                </span>
              </span>
              <span class="qe-num">{{ i + 1 }}</span>
              <button type="button" class="qe-text" @click="openQuestion(q.id)">
                <strong>{{ q.question }}</strong>
                <span class="status-tag">{{ typeName(q.type) }}</span>
              </button>
              <span :ref="(el) => menuFor === q.id && rowRoot(el)" class="qe-more">
                <button :ref="(el) => menuFor === q.id && rowTrigger(el)" class="round-btn tap-44" :aria-label="t.sprintf(/* translators: %d: question number */ t.__('More for question %d', 'membuild-community'), i + 1)" aria-haspopup="menu" :aria-expanded="rowMenu && menuFor === q.id" @click="rowMenuToggle($event, q)"><Icon name="more" :size="20" /></button>
                <span v-if="rowMenu && menuFor === q.id" :ref="rowList" class="qe-menu right" role="menu" :aria-label="t.sprintf(t.__('Question %d', 'membuild-community'), i + 1)" @keydown="onRowKey">
                  <button role="menuitem" type="button" @click="closeRow({ focus: false }); openQuestion(q.id)"><Icon name="pencil" :size="18" /> {{ t.__('Edit', 'membuild-community') }}</button>
                  <button role="menuitem" type="button" class="danger" @click="removeQuestion(q)"><Icon name="trash" :size="18" /> {{ t.__('Delete question', 'membuild-community') }}</button>
                </span>
              </span>
            </li>
          </ol>
          <p v-if="questions.length > 1" class="qe-hint">{{ t.__('Drag a question by its handle to change the order. It saves by itself.', 'membuild-community') }}</p>
        </section>

        <template v-if="questions.length">
          <section class="card qe-card">
            <h2>{{ t.__('How many do they need to get right?', 'membuild-community') }}</h2>
            <label class="qe-label" for="qe-pass">{{ t.__('Pass mark', 'membuild-community') }}</label>
            <span class="qe-pass">
              <input id="qe-pass" v-model="passMark" type="number" inputmode="numeric" min="1" :max="questions.length" :aria-invalid="!!passError" aria-describedby="qe-pass-error" @change="savePass" @keydown.enter.prevent="savePass" />
              <span>{{ ofCount }}</span>
            </span>
            <p id="qe-pass-error" class="error-text qe-field-error" :class="{ shown: passError }" role="alert">{{ passError }}</p>
          </section>

          <section class="card qe-card">
            <h2 id="qe-fail">{{ t.__('What happens if they do not pass?', 'membuild-community') }}</h2>
            <div class="qe-fail" role="radiogroup" aria-labelledby="qe-fail">
              <label class="pick-card qe-choice" :class="{ on: quiz.on_fail === 'retry' }">
                <input type="radio" name="qe-on-fail" class="sr-only" value="retry" :checked="quiz.on_fail === 'retry'" @change="save({ on_fail: 'retry' })" />
                <span class="pick-dot" />
                <span><strong>{{ t.__('They can try again', 'membuild-community') }}</strong><small>{{ t.__('Any number of times. The next lesson stays closed until they pass.', 'membuild-community') }}</small></span>
              </label>
              <label class="pick-card qe-choice" :class="{ on: quiz.on_fail === 'move_on' }">
                <input type="radio" name="qe-on-fail" class="sr-only" value="move_on" :checked="quiz.on_fail === 'move_on'" @change="save({ on_fail: 'move_on' })" />
                <span class="pick-dot" />
                <span><strong>{{ t.__('They can move on anyway', 'membuild-community') }}</strong><small>{{ t.__('The result is saved, nothing is locked.', 'membuild-community') }}</small></span>
              </label>
            </div>
          </section>

          <section v-if="quiz.stats.tried" class="card qe-card">
            <h2>{{ t.__('How are members doing?', 'membuild-community') }}</h2>
            <div class="qe-stat">
              <span><strong>{{ t.__('Passed', 'membuild-community') }}</strong><small>{{ passedOf(quiz.stats) }}</small></span>
              <span class="status-tag ok">{{ pct(quiz.stats.passed, quiz.stats.tried) }}%</span>
            </div>
            <div v-if="quiz.stats.hardest" class="qe-stat">
              <span><strong>{{ t.__('Hardest question', 'membuild-community') }}</strong><small>{{ quiz.stats.hardest.question }}</small></span>
              <span class="status-tag warn">{{ rightPct(quiz.stats.hardest.pct) }}</span>
            </div>
          </section>
        </template>
      </template>
    </template>
  </div>
</template>

<style scoped>
.qe {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
  max-width: 730px;
  margin: 0 auto;
}
.qe .back-link {
  align-self: flex-start;
  color: var(--text-2);
}
.qe .page-title,
.qe .page-intro {
  margin-top: 0;
  margin-bottom: 0;
}
.qe-card {
  padding: 22px 24px;
  border-radius: var(--radius-lg);
}
.qe-card h2 {
  margin: 0;
  font-size: var(--fs-2xl);
}
.qe-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--border);
}
.short {
  display: none;
}
.qe-add {
  position: relative;
}
.qe-picker {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 20;
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 340px;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  box-shadow: var(--shadow-pop);
  text-align: left;
}
.qe-picker.center {
  right: auto;
  left: 50%;
  transform: translateX(-50%);
}
.qe-picker-title {
  margin: 4px 8px 6px;
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 650;
}
.qe-picker button {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-height: 44px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  color: var(--text);
  text-align: left;
}
.qe-picker button:hover,
.qe-picker button:focus-visible {
  background: var(--surface-2);
}
.qe-picker button strong {
  display: block;
  font-size: var(--fs-lg);
}
.qe-picker button small {
  display: block;
  color: var(--text-2);
  font-size: var(--fs-md);
}
.qe-picker-note {
  margin: 6px 0 0;
  padding: 10px 8px 4px;
  border-top: 1px solid var(--border);
  color: var(--text-2);
  font-size: var(--fs-sm);
  line-height: 1.5;
}
.qe-empty {
  margin-top: 16px;
  border-radius: var(--radius-md);
}
.qe-list {
  margin: 0;
  padding: 0;
  list-style: none;
}
.qe-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 0;
  border-bottom: 1px solid var(--border);
}
.qe-row:last-child {
  border-bottom: 0;
}
.qe-handle-wrap,
.qe-more {
  position: relative;
  flex: none;
}
.qe-handle {
  display: grid;
  place-items: center;
  width: 32px;
  height: 44px;
  color: var(--text-3);
  cursor: grab;
}
.qe-num {
  flex: none;
  width: 20px;
  color: var(--text-2);
  font-weight: 700;
  text-align: center;
}
.qe-text {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;
  min-height: 44px;
  padding: 4px 6px;
  border-radius: var(--radius-xs);
  color: var(--text);
  text-align: left;
}
.qe-text strong {
  display: -webkit-box;
  max-width: 100%;
  overflow: hidden;
  font-size: var(--fs-lg);
  font-weight: 500;
  overflow-wrap: anywhere;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}
.qe-text:hover strong {
  text-decoration: underline;
}
.qe-more .round-btn {
  background: none;
}
.qe-more .round-btn:hover {
  background: var(--surface-2);
}
.qe-menu {
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 10;
  display: flex;
  flex-direction: column;
  min-width: 190px;
  padding: 6px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--surface);
  box-shadow: var(--shadow-pop);
}
.qe-menu.right {
  right: 0;
  left: auto;
}
.qe-menu button {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 8px 10px;
  border-radius: var(--radius-xs);
  color: var(--text);
  font-weight: 600;
  text-align: left;
  white-space: nowrap;
}
.qe-menu button:hover:not(:disabled) {
  background: var(--surface-2);
}
.qe-menu button:disabled {
  opacity: 0.45;
}
.qe-menu .danger {
  color: var(--danger-text);
}
.qe-hint {
  margin: 12px 0 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}
.qe-label {
  display: block;
  margin: 14px 0 8px;
  font-weight: 700;
}
.qe-pass {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: var(--fs-xl);
}
.qe-pass input {
  width: 96px;
  height: 52px;
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font-size: var(--fs-2xl);
  text-align: center;
}
.qe-pass input[aria-invalid='true'] {
  border-color: var(--danger-strong);
}
.qe-field-error {
  display: none;
  margin-top: 8px;
  font-size: var(--fs-md);
}
.qe-field-error.shown {
  display: block;
}
.qe-fail {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-top: 14px;
}
.qe-choice {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 16px;
  border-radius: var(--radius-md);
}
.qe-choice .pick-dot {
  margin-top: 2px;
}
.qe-choice strong {
  display: block;
  font-size: var(--fs-lg);
}
.qe-choice small {
  display: block;
  margin-top: 2px;
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.45;
}
.qe-choice:has(input:focus-visible) {
  outline: 2px solid var(--accent-text);
  outline-offset: 2px;
}
.qe-stat {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 0;
  border-top: 1px solid var(--border);
}
.qe-stat:first-of-type {
  margin-top: 12px;
}
.qe-stat strong {
  display: block;
}
.qe-stat small {
  display: block;
  color: var(--text-2);
  font-size: var(--fs-md);
}
@media (max-width: 600px) {
  .qe-card {
    padding: 18px 16px;
  }
  .qe-head {
    flex-direction: column-reverse; /* phones: Add a question first, then "Questions · 5" */
    align-items: stretch;
  }
  .qe-head .btn {
    width: 100%;
  }
  .long {
    display: none;
  }
  .short {
    display: inline;
  }
  .qe-picker {
    width: 100%;
  }
  .qe-fail {
    grid-template-columns: 1fr;
  }
}
</style>
