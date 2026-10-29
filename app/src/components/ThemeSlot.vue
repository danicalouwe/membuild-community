<script setup>
import { computed, onErrorCaptured } from 'vue'
import { additions, dropPiece, replacement } from '../lib/slots.js'

/**
 * A named place a Community theme can fill (see lib/slots.js). With nothing registered it
 * shows its default content and adds no element of its own, so pages look exactly as
 * before. A theme's piece gets `data` as props and the default as its default slot.
 *
 *   <ThemeSlot name="classroom.course-card" :data="{ course: c, to, state }"> …default… </ThemeSlot>
 */
const props = defineProps({
  name: { type: String, required: true },
  data: { type: Object, default: () => ({}) },
  off: { type: Boolean, default: false }, // the page shows only its default here (e.g. the owner's own view)
})

const entry = computed(() => (props.off ? null : replacement(props.name)))
const extras = computed(() => (props.off ? [] : additions(props.name)))

// A piece that throws while drawing or updating: drop it, show the default, tell the owner.
onErrorCaptured((err, instance) => {
  const owner = [entry.value, ...extras.value].find((e) => e && instance && isFrom(instance, e.piece))
  if (!owner) return true // the default's own error: let the app handle it as usual
  // Vue skips a redraw asked for while this slot is still drawing, so swap right after.
  queueMicrotask(() => dropPiece(props.name, owner, err))
  return false
})

// Vue hands over the component's public face; its own record ($) knows its type and parent.
function isFrom(instance, piece) {
  for (let i = instance.$; i; i = i.parent) if (i.type === piece) return true
  return false
}
</script>

<template>
  <component :is="entry.piece" v-if="entry" v-bind="data"><slot /></component>
  <slot v-else />
  <component :is="x.piece" v-for="(x, i) in extras" :key="`${x.theme}-${i}`" v-bind="data" />
</template>
