<script setup>
import ConfirmDialog from './ConfirmDialog.vue'
import Icon from './Icon.vue'
import { answerConfirm, confirmState } from '../lib/confirm.js'
</script>

<template>
  <ConfirmDialog
    v-if="confirmState.open"
    :title="confirmState.open.title"
    :confirm="confirmState.open.confirm"
    :cancel="confirmState.open.cancel"
    :danger="confirmState.open.danger"
    :safe="confirmState.open.safe"
    :tick="confirmState.open.tick || undefined"
    :z="210"
    @confirm="answerConfirm(true)"
    @close="answerConfirm(false)"
  >
    <p v-if="confirmState.open.note || confirmState.open.points?.length">{{ confirmState.open.text }}</p><template v-else>{{ confirmState.open.text }}</template>
    <p v-if="confirmState.open.note" :class="confirmState.open.plainNote ? 'info-note' : 'note warn'">{{ confirmState.open.note }}</p>
    <ul v-if="confirmState.open.points?.length" class="points">
      <li v-for="(p, i) in confirmState.open.points" :key="i"><Icon :name="p.icon || 'check'" :size="16" />{{ p.text }}</li>
    </ul>
  </ConfirmDialog>
</template>
