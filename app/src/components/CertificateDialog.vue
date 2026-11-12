<script setup>
import BaseDialog from './BaseDialog.vue'
import CertificateActions from './CertificateActions.vue'
import CertificateCard from './CertificateCard.vue'

/**
 * An opened certificate (View on a profile): the certificate, Download PDF, Share on Home and the
 * check link for its own member. Full screen on phones, like the design's "‹ Ben Lee" page.
 */
defineProps({ cert: { type: Object, required: true } })
const emit = defineEmits(['close'])
</script>

<template>
  <BaseDialog :title="cert.course" :width="560" :z="80" phone="full" @close="emit('close')"><!-- under the Share on Home composer (85), like PostDialog -->
    <div class="cd">
      <CertificateCard :cert="cert" small />
      <CertificateActions :cert="cert" @shared="emit('close')" />
    </div>
  </BaseDialog>
</template>

<style scoped>
.cd {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 4px 24px 24px;
}
@media (max-width: 600px) {
  .cd {
    padding: 4px 16px 24px;
  }
}
</style>
