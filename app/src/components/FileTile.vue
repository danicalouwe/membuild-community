<script setup>
import { computed } from 'vue'

// A file's type at a glance: a coloured tile with a flat white icon and the short type
// (PDF red, Excel green, Word blue, PowerPoint orange). Used in the lesson and its editor.
const props = defineProps({ ext: { type: String, default: '' }, size: { type: Number, default: 48 } })

const TYPES = {
  PDF: { kind: 'pdf', color: '#e5484d' },
  DOC: { kind: 'doc', color: '#2f6fe4' },
  DOCX: { kind: 'doc', color: '#2f6fe4' },
  TXT: { kind: 'doc', color: '#6b7280' },
  XLS: { kind: 'sheet', color: '#1f8a4c' },
  XLSX: { kind: 'sheet', color: '#1f8a4c' },
  CSV: { kind: 'sheet', color: '#1f8a4c' },
  PPT: { kind: 'slides', color: '#e8590c' },
  PPTX: { kind: 'slides', color: '#e8590c' },
  ZIP: { kind: 'zip', color: '#8a5a00' },
  PNG: { kind: 'image', color: '#7c4ddb' },
  JPG: { kind: 'image', color: '#7c4ddb' },
  JPEG: { kind: 'image', color: '#7c4ddb' },
  WEBP: { kind: 'image', color: '#7c4ddb' },
  GIF: { kind: 'image', color: '#7c4ddb' },
}
const type = computed(() => TYPES[(props.ext || '').toUpperCase()] || { kind: 'file', color: '#6b7280' })
const label = computed(() => ((props.ext || '').toUpperCase() === 'JPEG' ? 'JPG' : (props.ext || '').toUpperCase()))
</script>

<template>
  <span class="tile" :style="{ background: type.color, width: `${size}px`, height: `${size}px` }" aria-hidden="true">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
      <!-- the page shape, then what's on it -->
      <path d="M6 2.8h8l4.2 4.2v13.2c0 .6-.4 1-1 1H6c-.6 0-1-.4-1-1V3.8c0-.6.4-1 1-1z" fill="rgb(255 255 255 / 18%)" />
      <path d="M14 2.8V7h4.2" />
      <template v-if="type.kind === 'sheet'"><path d="M8 11h8v7H8zM8 14.5h8M12 11v7" /></template>
      <template v-else-if="type.kind === 'slides'"><path d="M8 11h8v5H8zM12 16v2.5" /></template>
      <template v-else-if="type.kind === 'image'"><path d="M8 17.5l2.6-3 2 2 1.4-1.5 2 2.5" /><circle cx="10" cy="11.5" r="1.1" /></template>
      <template v-else-if="type.kind === 'zip'"><path d="M11 3v2M13 5v2M11 7v2M13 9v2M11 13h2v3h-2z" /></template>
      <template v-else><path d="M8 11.5h8M8 14.5h8M8 17.5h5" /></template>
    </svg>
    <span v-if="label" class="lbl">{{ label }}</span>
  </span>
</template>

<style scoped>
.tile {
  display: inline-flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1px;
  border-radius: var(--radius-sm);
  color: var(--on-color);
}

.lbl {
  font-size: var(--fs-3xs);
  font-weight: 800;
  line-height: 1;
  letter-spacing: 0.04em;
}
</style>
