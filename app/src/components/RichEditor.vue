<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import { renderMarkdown } from '../lib/markdown.js'
import { htmlToMarkdown } from '../lib/richtext.js'
import { startAttachment } from '../lib/attachments.js'
import { t } from '../lib/i18n.js'

/**
 * A visual editor for lesson text: type normally, then use the buttons for a
 * heading, bold, italic, a list, a link or a picture. The buttons are words, not
 * icons (owners read labels). It stores the same simple text the lesson page
 * shows, so older lessons open here unchanged.
 */
const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  pictures: { type: Boolean, default: true },
  label: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const box = ref(null)
const pictureInput = ref(null)
const linking = ref(false)
const linkUrl = ref('')
const linkInput = ref(null)
const uploading = ref(0)
const empty = ref(true)
let last = null // what we last sent up, so our own edits don't reset the cursor
let saved = null // the selection while the link box has focus

function paint(md) {
  if (!box.value) return
  box.value.innerHTML = renderMarkdown(md || '')
  empty.value = !box.value.textContent.trim() && !box.value.querySelector('img')
}
onMounted(() => {
  last = props.modelValue
  paint(props.modelValue)
})
watch(
  () => props.modelValue,
  (v) => {
    if (v !== last) {
      last = v
      paint(v)
    }
  },
)

function changed() {
  const md = htmlToMarkdown(box.value)
  empty.value = !box.value.textContent.trim() && !box.value.querySelector('img')
  if (md !== last) {
    last = md
    emit('update:modelValue', md)
  }
}

// New lines become paragraphs, not <div>s, so what's saved matches what's seen.
function useParagraphs() {
  document.execCommand('defaultParagraphSeparator', false, 'p')
}

function run(cmd, value = null) {
  box.value.focus()
  useParagraphs()
  document.execCommand(cmd, false, value)
  changed()
}
function heading() {
  const block = document.queryCommandValue('formatBlock').toLowerCase()
  run('formatBlock', /h[1-6]/.test(block) ? 'p' : 'h3')
}

function remember() {
  const sel = window.getSelection()
  saved = sel.rangeCount && box.value.contains(sel.anchorNode) ? sel.getRangeAt(0).cloneRange() : null
}
function restore() {
  box.value.focus()
  if (saved) {
    const sel = window.getSelection()
    sel.removeAllRanges()
    sel.addRange(saved)
  }
}
function startLink() {
  remember()
  linkUrl.value = ''
  linking.value = true
  nextTick(() => linkInput.value?.focus())
}
function addLink() {
  let url = linkUrl.value.trim()
  if (!url) return (linking.value = false)
  if (!/^https?:\/\//i.test(url)) url = `https://${url}`
  restore()
  if (window.getSelection().isCollapsed) document.execCommand('insertText', false, url)
  else document.execCommand('createLink', false, url)
  linking.value = false
  changed()
}

function pickPicture(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  remember()
  const item = startAttachment(file)
  uploading.value++
  item.promise
    .then(() => {
      if (!item.media?.url) return
      restore()
      document.execCommand('insertHTML', false, `<p><img src="${item.media.url}" alt=""></p><p><br></p>`)
      changed()
    })
    .finally(() => uploading.value--)
}

// Pasting keeps the words, not another site's formatting.
function paste(e) {
  e.preventDefault()
  const text = e.clipboardData.getData('text/plain')
  document.execCommand('insertText', false, text)
}
</script>

<template>
  <div class="rich">
    <div class="tools" role="toolbar" :aria-label="t.__('Text formatting', 'membuild-community')">
      <button type="button" @mousedown.prevent @click="heading">{{ t.__('Heading', 'membuild-community') }}</button>
      <button type="button" class="b" @mousedown.prevent @click="run('bold')">{{ t.__('Bold', 'membuild-community') }}</button>
      <button type="button" class="i" @mousedown.prevent @click="run('italic')">{{ t.__('Italic', 'membuild-community') }}</button>
      <button type="button" @mousedown.prevent @click="run('insertUnorderedList')">{{ t.__('List', 'membuild-community') }}</button>
      <button type="button" class="u" @mousedown.prevent @click="startLink">{{ t.__('Link', 'membuild-community') }}</button>
      <button v-if="pictures" type="button" @mousedown.prevent @click="remember(); pictureInput.click()">{{ uploading ? t.__('Adding picture…', 'membuild-community') : t.__('Picture', 'membuild-community') }}</button>
      <input v-if="pictures" ref="pictureInput" class="sr-only" type="file" accept="image/*" @change="pickPicture" />
    </div>
    <form v-if="linking" class="linkbox" @submit.prevent="addLink">
      <input ref="linkInput" v-model="linkUrl" type="text" inputmode="url" :placeholder="t.__('Paste the web address, e.g. example.com', 'membuild-community')" :aria-label="t.__('Web address', 'membuild-community')" @keydown.esc="linking = false" />
      <button type="submit" class="btn btn-lg btn-primary">{{ t.__('Add link', 'membuild-community') }}</button>
      <button type="button" class="btn btn-lg btn-ghost" @click="linking = false">{{ t.__('Cancel', 'membuild-community') }}</button>
    </form>
    <div
      ref="box"
      class="area md"
      :class="{ empty }"
      contenteditable="true"
      role="textbox"
      aria-multiline="true"
      :aria-label="label || placeholder"
      :data-placeholder="placeholder"
      @focus="useParagraphs"
      @input="changed"
      @blur="changed"
      @paste="paste"
    />
  </div>
</template>

<style scoped>
.rich {
  border: 1px solid var(--border-strong, #c9ced6);
  border-radius: var(--radius-md);
  background: var(--surface);
  overflow: hidden;
}

.tools {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding: 6px;
  border-bottom: 1px solid var(--border);
  background: var(--surface-2);
}

.tools button {
  height: 44px;
  padding: 0 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-lg);
  font-weight: 650;
  color: var(--text);
}

.tools button:hover {
  background: var(--surface);
}

.tools .b {
  font-weight: 800;
}

.tools .i {
  font-style: italic;
}

.tools .u {
  text-decoration: underline;
}

.linkbox {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px;
  border-bottom: 1px solid var(--border);
}

.linkbox input {
  flex: 1;
  min-width: 200px;
  height: 52px;
  padding: 0 14px;
  border: 1px solid var(--border-strong, #c9ced6);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font-size: var(--fs-xl);
}

.area {
  position: relative;
  min-height: 160px;
  padding: 14px 16px;
  font-size: var(--fs-xl);
  line-height: 1.6;
  outline: none;
}

.area.empty::before {
  content: attr(data-placeholder);
  color: var(--text-3);
  pointer-events: none;
  position: absolute;
}

.area :deep(img) {
  max-width: 100%;
  border-radius: var(--radius-sm);
}
</style>
