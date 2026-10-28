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
    :z="210"
    @confirm="answerConfirm(true)"
    @close="answerConfirm(false)"
  >
    {{ confirmState.open.text }}
  </ConfirmDialog>
</template>
