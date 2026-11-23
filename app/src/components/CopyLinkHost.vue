<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import BaseDialog from './BaseDialog.vue'
import Icon from './Icon.vue'
import { closeCopyBox, copyBox } from '../lib/copyBox.js'
import { t } from '../lib/i18n.js'

/**
 * "Copy this link" in our own box (#147): the link in a read-only field, already selected, then
 * Done and Copy link. Copy link tries again from inside the box; if it works the button says
 * Copied and the box closes a moment later, if not the selected link is there to copy by hand.
 */
const field = ref(null)
const copied = ref(false)
let timer = null

function selectLink() {
  field.value?.focus({ preventScroll: true })
  field.value?.select()
  field.value?.setSelectionRange(0, field.value.value.length) // older iPhones ignore select()
}

watch(
  () => copyBox.open?.id,
  (id) => {
    clearTimeout(timer)
    copied.value = false
    if (id) nextTick(selectLink)
  },
  { immediate: true },
)
onBeforeUnmount(() => clearTimeout(timer))

async function copy() {
  const url = copyBox.open?.url
  if (!url) return
  let ok = false
  try {
    await navigator.clipboard.writeText(url)
    ok = true
  } catch {
    // The old way, from the selected field itself.
    selectLink()
    try {
      ok = document.execCommand('copy')
    } catch {
      ok = false
    }
  }
  if (!ok) return selectLink() // still selected, to copy by hand
  copied.value = true
  timer = setTimeout(closeCopyBox, 900)
}
</script>

<template>
  <BaseDialog v-if="copyBox.open" :label="copyBox.open.title" :width="520" :z="210" @close="closeCopyBox">
    <template #header>
      <span />
    </template>
    <div class="cl">
      <h2>{{ copyBox.open.title }}</h2>
      <input ref="field" class="link" type="text" readonly :value="copyBox.open.url" :aria-label="copyBox.open.title" @focus="$event.target.select()" />
      <div class="actions">
        <button type="button" class="btn btn-lg btn-ghost" @click="closeCopyBox">{{ t.__('Done', 'membuild-community') }}</button>
        <button type="button" class="btn btn-lg btn-primary" :aria-live="copied ? 'polite' : null" @click="copy">
          <Icon :name="copied ? 'check' : 'copy'" :size="18" /> {{ copied ? t.__('Copied', 'membuild-community') : t.__('Copy link', 'membuild-community') }}
        </button>
      </div>
    </div>
  </BaseDialog>
</template>

<style scoped>
/* The same spacing and heading as ConfirmDialog. */
.cl {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 28px 28px 24px;
}

h2 {
  margin: 0;
  font-size: var(--fs-5xl);
  line-height: 1.25;
}

.link {
  width: 100%;
  min-width: 0;
}

.actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 4px;
}
</style>
