<script setup>
import ConfirmDialog from './ConfirmDialog.vue'
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
    :z="210"
    @confirm="answerConfirm(true)"
    @close="answerConfirm(false)"
  >
    <p v-if="confirmState.open.note">{{ confirmState.open.text }}</p><template v-else>{{ confirmState.open.text }}</template>
    <p v-if="confirmState.open.note" class="note warn">{{ confirmState.open.note }}</p>
  </ConfirmDialog>
</template>
