<script setup>
import { computed, ref, watch } from 'vue'
import { api } from '../api.js'
import { t } from '../lib/i18n.js'
import CertificateActions from './CertificateActions.vue'
import CertificateCard from './CertificateCard.vue'
import Icon from './Icon.vue'

/**
 * Course finished, with the certificate (canvas: Member, course finished): "You finished …",
 * the certificate, Download PDF and Share on Home, and the check link. Opens at
 * ?certificate=1 on the course, so it stays put on a reload. Without a certificate (off, or not
 * for this course) the course's usual "You finished the course!" window shows instead.
 */
const props = defineProps({
  course: { type: Object, required: true },
  cert: { type: Object, default: null }, // from finishing just now; loaded otherwise
})
const emit = defineEmits(['none'])

const mine = ref(props.cert)
const loading = ref(!props.cert)
watch(
  () => props.course.id,
  async (id) => {
    if (mine.value?.course_id === id) return
    loading.value = true
    try {
      mine.value = (await api.courseCertificate(id)).certificate
    } catch {
      mine.value = null
    }
    loading.value = false
    if (!mine.value) emit('none')
  },
  { immediate: true },
)
const heading = computed(() => t.sprintf(/* translators: %s: course title */ t.__('You finished %s', 'membuild-community'), mine.value?.course || props.course.title))
</script>

<template>
  <div class="cf">
    <div v-if="loading" class="card cf-loading"><div class="skeleton" style="height: 28px; width: 60%" /><div class="skeleton" style="height: 320px; margin-top: 16px" /></div>
    <template v-else-if="mine">
      <span class="status-tag ok cf-tag"><Icon name="check" :size="14" /> {{ t.__('Course finished', 'membuild-community') }}</span>
      <h1 class="page-title">{{ heading }}</h1>
      <p class="page-intro">{{ t.__('Your certificate is ready. It stays on your profile.', 'membuild-community') }}</p>
      <CertificateCard :cert="mine" class="cf-cert" />
      <CertificateActions :cert="mine" :course="course" class="cf-actions" />
    </template>
  </div>
</template>

<style scoped>
.cf {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
  width: 100%;
  max-width: 760px;
  margin: 0 auto;
  padding: 18px 16px 40px;
}
.cf .page-title,
.cf .page-intro {
  margin: 0;
}
.cf-loading {
  width: 100%;
  padding: 20px;
}
.cf-cert {
  width: 100%;
  max-width: 420px;
  margin-top: 6px;
}
.cf-actions {
  width: 100%;
  max-width: 420px;
}
@media (max-width: 600px) {
  .cf-cert,
  .cf-actions {
    max-width: none;
  }
}
</style>
