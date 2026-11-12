<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { api } from '../api.js'
import { startAttachment } from '../lib/attachments.js'
import { askConfirm } from '../lib/confirm.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'

/**
 * Writing one quiz question (owner): its type (with Change), the question, an optional picture,
 * the answers with the right one(s) marked, and an optional "Why this is the answer". Saved with
 * Save question; the server checks it and each problem shows under its own field. Leaving with
 * changes asks first (Leave without saving?). Canvas: Quizzes · Owner, writing a question.
 */
const props = defineProps({
  quizId: { type: Number, required: true },
  question: { type: Object, default: null }, // null: a new one
  type: { type: String, default: 'one' }, // a new question's type
  number: { type: Number, required: true },
  quizTitle: { type: String, required: true },
  types: { type: Array, required: true },
})
const emit = defineEmits(['saved', 'cancel'])

const trueFalse = () => [
  { id: 1, text: t.__('True', 'membuild-community'), right: false },
  { id: 2, text: t.__('False', 'membuild-community'), right: false },
]
const blank = () => [{ text: '', right: false }, { text: '', right: false }, { text: '', right: false }]

const q = props.question
const kind = ref(q ? q.type : props.type)
const text = ref(q ? q.question : '')
const why = ref(q ? q.why : '')
const answers = ref(q ? q.answers.map((a) => ({ ...a })) : kind.value === 'truefalse' ? trueFalse() : blank())
const image = ref(q?.image || null) // the saved picture { id, url }
const upload = ref(null) // a new picture on its way
const errors = ref({})
const topError = ref('')
const topErrorEl = ref(null)
const saving = ref(false)
const changing = ref(false) // the type picker
const fileInput = ref(null)

const start = JSON.stringify([kind.value, text.value, why.value, answers.value, image.value?.id || 0])
const dirty = () => !!upload.value || JSON.stringify([kind.value, text.value, why.value, answers.value, image.value?.id || 0]) !== start

const typeName = computed(() => (props.types.find((x) => x.id === kind.value) || props.types[0]).name())
const many = computed(() => kind.value === 'many')
const instruction = computed(() =>
  many.value
    ? t.__('Tick every right answer. Members need all of them, and no wrong ones, to get this question right.', 'membuild-community')
    : kind.value === 'truefalse'
      ? t.__('Pick the right one.', 'membuild-community')
      : t.__('Pick the one right answer.', 'membuild-community'),
)
const heading = computed(() => t.sprintf(/* translators: %d: question number */ t.__('Question %d', 'membuild-community'), props.number))

function changeType(to) {
  changing.value = false
  if (to === kind.value) return
  const was = kind.value
  kind.value = to
  if (to === 'truefalse') answers.value = trueFalse()
  else if (was === 'truefalse') answers.value = blank()
  else if (to === 'one') {
    // One right answer: keep only the first one marked.
    let seen = false
    answers.value.forEach((a) => {
      a.right = a.right && !seen
      if (a.right) seen = true
    })
  }
}
function mark(i) {
  if (many.value) answers.value[i].right = !answers.value[i].right
  else answers.value.forEach((a, j) => (a.right = i === j))
  delete errors.value.right
}
function addAnswer() {
  if (answers.value.length < 10) answers.value.push({ text: '', right: false })
}
function removeAnswer(i) {
  answers.value.splice(i, 1)
}

/* ---------- The picture ---------- */
function pickPicture(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  upload.value?.cancel()
  upload.value = startAttachment(file)
  delete errors.value.image
}
function removePicture() {
  upload.value?.cancel()
  upload.value = null
  image.value = null
}
const pictureUrl = computed(() => upload.value?.previewUrl || image.value?.url || '')
onBeforeUnmount(() => upload.value?.release())

/* ---------- Saving ---------- */
async function save() {
  if (saving.value) return
  saving.value = true
  errors.value = {}
  topError.value = ''
  try {
    let mediaId = image.value?.id || 0
    if (upload.value) {
      await upload.value.promise
      if (upload.value.status !== 'done') throw Object.assign(new Error(upload.value.error || t.__('Upload the picture again, please.', 'membuild-community')), { fields: { image: upload.value.error || t.__('Upload the picture again, please.', 'membuild-community') } })
      mediaId = upload.value.media.id
    }
    const body = {
      type: kind.value,
      question: text.value,
      why: why.value,
      answers: answers.value.map((a) => ({ id: a.id, text: a.text, right: !!a.right })),
      media_id: mediaId,
    }
    const saved = q ? await api.updateQuestion(q.id, body) : await api.addQuestion(props.quizId, body)
    leaving = true
    emit('saved', saved)
  } catch (err) {
    errors.value = err.fields || {}
    topError.value = err.message
    // On a long phone page the message is far above Save question: take the owner (and a screen
    // reader, which reads it on arriving) to it.
    await nextTick()
    topErrorEl.value?.focus({ preventScroll: true })
    topErrorEl.value?.scrollIntoView({ block: 'center' })
  } finally {
    saving.value = false
  }
}

let leaving = false
async function mayLeave() {
  if (leaving || !dirty()) return true
  const ok = await askConfirm({
    title: t.__('Leave without saving?', 'membuild-community'),
    text: t.__('You changed this question. If you leave now, those changes are lost.', 'membuild-community'),
    confirm: t.__('Discard changes', 'membuild-community'),
    cancel: t.__('Keep editing', 'membuild-community'),
    safe: true,
  })
  if (ok) {
    leaving = true
    upload.value?.cancel()
  }
  return ok
}
// The browser's back, a menu link, or Cancel: the same question first.
onBeforeRouteLeave(mayLeave)
async function cancel() {
  if (await mayLeave()) emit('cancel')
}
</script>

<template>
  <form class="qn" novalidate @submit.prevent="save">
    <button type="button" class="back-link" @click="cancel"><Icon name="chevronLeft" :size="18" /> {{ quizTitle }}</button>
    <h1 class="page-title">{{ heading }}</h1>
    <div class="qn-type">
      <span class="status-tag">{{ typeName }}</span>
      <span class="qn-change-wrap">
        <button type="button" class="qn-change" :aria-expanded="changing" @click="changing = !changing">{{ t.__('Change', 'membuild-community') }}</button>
        <span v-if="changing" class="qn-types" role="group" :aria-label="t.__('What kind of question?', 'membuild-community')">
          <button v-for="ty in types" :key="ty.id" type="button" :aria-pressed="ty.id === kind" @click="changeType(ty.id)">
            <Icon :name="ty.icon" :size="20" />
            <span><strong>{{ ty.name() }}</strong><small>{{ ty.line() }}</small></span>
          </button>
        </span>
      </span>
    </div>

    <!-- Focus moves here after Save question, which reads it out, so no role="alert" (it would be read twice). -->
    <p v-if="topError" ref="topErrorEl" class="info-note warn qn-top" tabindex="-1"><Icon name="flag" :size="20" /> <span>{{ topError }}</span></p>

    <section class="card qn-card">
      <label class="qn-label" for="qn-text">{{ t.__('Question', 'membuild-community') }}</label>
      <input id="qn-text" v-model="text" class="qn-input" type="text" maxlength="500" :aria-invalid="!!errors.question" :aria-describedby="errors.question ? 'qn-text-error' : null" @input="delete errors.question" />
      <p v-if="errors.question" id="qn-text-error" class="error-text qn-error">{{ errors.question }}</p>

      <p class="qn-label">{{ t.__('Picture', 'membuild-community') }} <span class="qn-opt">{{ t.__('(optional)', 'membuild-community') }}</span></p>
      <div v-if="pictureUrl" class="qn-picture">
        <img :src="pictureUrl" alt="" />
        <span class="qn-picture-btns">
          <small v-if="upload?.status === 'working'">{{ t.__('Uploading…', 'membuild-community') }}</small>
          <button type="button" class="btn btn-ghost" @click="fileInput.click()">{{ t.__('Change picture', 'membuild-community') }}</button>
          <button type="button" class="btn btn-ghost" @click="removePicture">{{ t.__('Remove', 'membuild-community') }}</button>
        </span>
      </div>
      <button v-else type="button" class="btn btn-ghost" @click="fileInput.click()"><Icon name="image" :size="18" /> {{ t.__('Add a picture', 'membuild-community') }}</button>
      <input ref="fileInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickPicture" />
      <p v-if="errors.image" class="error-text qn-error">{{ errors.image }}</p>
    </section>

    <section class="card qn-card">
      <h2>{{ t.__('Answers', 'membuild-community') }}</h2>
      <p class="qn-sub">{{ instruction }}</p>
      <ul class="qn-answers">
        <li v-for="(a, i) in answers" :key="i" class="qn-answer" :class="{ right: a.right }">
          <label class="qn-mark" :aria-label="many ? t.__('This is a right answer', 'membuild-community') : t.__('Make this the right answer', 'membuild-community')">
            <input :type="many ? 'checkbox' : 'radio'" name="qn-right" :checked="a.right" @change="mark(i)" />
          </label>
          <input v-model="a.text" class="qn-input" type="text" maxlength="200" :aria-label="t.__('Answer', 'membuild-community')" :readonly="kind === 'truefalse'" @input="delete errors.answers" />
          <span v-if="a.right" class="status-tag ok qn-right"><Icon name="check" :size="14" /> {{ t.__('Right answer', 'membuild-community') }}</span>
          <button v-if="kind !== 'truefalse'" type="button" class="round-btn qn-remove" :aria-label="t.__('Remove this answer', 'membuild-community')" :disabled="answers.length <= 2" @click="removeAnswer(i)"><Icon name="close" :size="18" /></button>
        </li>
      </ul>
      <p v-if="errors.answers" class="error-text qn-error">{{ errors.answers }}</p>
      <p v-if="errors.right" class="error-text qn-error">{{ errors.right }}</p>
      <button v-if="kind !== 'truefalse'" type="button" class="btn btn-ghost qn-add" :disabled="answers.length >= 10" @click="addAnswer"><Icon name="plus" :size="18" /> {{ t.__('Add an answer', 'membuild-community') }}</button>
    </section>

    <section class="card qn-card">
      <label class="qn-label" for="qn-why">{{ t.__('Why this is the answer', 'membuild-community') }} <span class="qn-opt">{{ t.__('(optional)', 'membuild-community') }}</span></label>
      <textarea id="qn-why" v-model="why" class="qn-input" rows="3" maxlength="1000" aria-describedby="qn-why-help" />
      <p id="qn-why-help" class="qn-sub">{{ t.__('Members see this after they answer, right or wrong.', 'membuild-community') }}</p>
    </section>

    <footer class="qn-foot">
      <button type="button" class="btn btn-lg btn-ghost" @click="cancel">{{ t.__('Cancel', 'membuild-community') }}</button>
      <button type="submit" class="btn btn-lg btn-primary" :disabled="saving">{{ saving ? t.__('Saving…', 'membuild-community') : t.__('Save question', 'membuild-community') }}</button>
    </footer>
  </form>
</template>

<style scoped>
.qn {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.qn .back-link {
  align-self: flex-start;
  color: var(--text-2);
}
.qn .page-title {
  margin: 0;
}
.qn-type {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: -6px;
}
.qn-change-wrap {
  position: relative;
}
.qn-change {
  min-height: 44px;
  color: var(--accent-text);
  font-weight: 700;
  text-decoration: underline;
}
.qn-types {
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 20;
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 320px;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  box-shadow: var(--shadow-pop);
}
.qn-types button {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-height: 44px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  color: var(--text);
  text-align: left;
}
.qn-types button:hover,
.qn-types button[aria-pressed='true'] {
  background: var(--surface-2);
}
.qn-types strong {
  display: block;
}
.qn-types small {
  display: block;
  color: var(--text-2);
  font-size: var(--fs-md);
}
.qn-top {
  margin: 0;
  scroll-margin: 80px; /* clear of the glass header */
}
.qn-top:focus {
  outline: none; /* focused by Save question, not by the owner: the yellow box is enough */
}
.qn-card {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  padding: 22px 24px;
  border-radius: var(--radius-lg);
}
.qn-card h2 {
  margin: 0;
  font-size: var(--fs-2xl);
}
.qn-label {
  margin: 6px 0 0;
  font-weight: 700;
}
.qn-opt {
  color: var(--text-2);
  font-weight: 500;
}
.qn-sub {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
}
.qn-input {
  box-sizing: border-box;
  width: 100%;
  min-height: 52px;
  padding: 12px 14px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-lg);
}
textarea.qn-input {
  resize: vertical;
}
.qn-input[aria-invalid='true'] {
  border-color: var(--danger-strong);
}
.qn-input[readonly] {
  background: var(--surface-2);
}
.qn-error {
  font-size: var(--fs-md);
}
.qn-picture {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.qn-picture img {
  max-width: 260px;
  max-height: 180px;
  border-radius: var(--radius-md);
  object-fit: cover;
}
.qn-picture-btns {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.qn-picture-btns small {
  color: var(--text-2);
}
.qn-answers {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
  margin: 6px 0 0;
  padding: 0;
  list-style: none;
}
.qn-answer {
  display: flex;
  align-items: center;
  gap: 10px;
}
.qn-mark {
  display: grid;
  flex: none;
  place-items: center;
  width: 44px;
  height: 44px;
  cursor: pointer;
}
.qn-mark input {
  width: 22px;
  height: 22px;
  margin: 0;
  accent-color: var(--accent-text);
  cursor: pointer;
}
.qn-answer .qn-input {
  flex: 1;
  min-width: 0;
}
.qn-right {
  flex: none;
}
.qn-remove {
  background: none;
}
.qn-remove:hover:not(:disabled) {
  background: var(--surface-2);
}
.qn-remove:disabled {
  opacity: 0.35;
}
.qn-add {
  margin-top: 4px;
}
.qn-foot {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
@media (max-width: 600px) {
  .qn {
    padding-bottom: 84px; /* room for the pinned buttons */
  }
  .qn-card {
    padding: 18px 16px;
  }
  .qn-right {
    display: none; /* the ticked box says it on a narrow screen */
  }
  .qn-types {
    width: min(320px, calc(100vw - 32px));
  }
  /* Phones: Cancel and Save question stay pinned at the bottom, above the app's tabs. */
  .qn-foot {
    position: fixed;
    right: 0;
    bottom: calc(var(--tabbar-h, 64px) + env(safe-area-inset-bottom));
    left: 0;
    z-index: 30;
    padding: 12px 16px;
    border-top: 1px solid var(--border);
    background: var(--surface);
  }
  .qn-foot .btn {
    flex: 1;
  }
}
</style>

<style>
/* Phones: the floating See it as a member eye sits above the pinned Cancel and Save question, not over them. */
@media (max-width: 600px) {
  html:has(.qn-foot) .mvt-wrap:not(.docked) {
    bottom: calc(var(--tabbar-h, 64px) + 92px + env(safe-area-inset-bottom));
  }
}
</style>
