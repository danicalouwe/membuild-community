<script setup>
import { onErrorCaptured, ref } from 'vue'
import { reportProblem } from '../../lib/problems.js'
import { t } from '../../lib/i18n.js'

/**
 * One add-on's Settings section (lib/addons.js). If the add-on's piece throws, it's swapped for a
 * short note so the rest of Settings keeps working, and the Problems page says which add-on.
 */
const props = defineProps({ section: { type: Object, required: true } })
const broken = ref(false)
onErrorCaptured((err) => {
  reportProblem(err, `add-on: ${props.section.addon}, settings`, 0, 'settings')
  broken.value = true
  return false
})
</script>

<template>
  <section v-if="broken" :id="`s-${section.id}`" class="card addon-broken">
    <h2>{{ section.label }}</h2>
    <p class="error-text">{{ t.__('This add-on’s settings couldn’t open. Try updating the add-on.', 'membuild-community') }}</p>
  </section>
  <component :is="section.component" v-else />
</template>

<style scoped>
.addon-broken {
  padding: 20px 22px;
  scroll-margin-top: calc(var(--header-h) + 16px);
}

.addon-broken h2 {
  margin: 0 0 6px;
  font-size: var(--fs-3xl);
}
</style>
