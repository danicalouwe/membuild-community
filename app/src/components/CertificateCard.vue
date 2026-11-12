<script setup>
import { computed } from 'vue'
import { lessonsOf, shortDay } from '../lib/certificatePdf.js'
import { t } from '../lib/i18n.js'

/**
 * A certificate as it looks on screen (the Course finished page, the opened certificate, the
 * Settings preview): the logo and community, the title, the member's name, the course, the day
 * and the lessons, then who signed it. `small` for the narrow places.
 */
const props = defineProps({
  cert: { type: Object, required: true },
  small: { type: Boolean, default: false },
})
const finishedLine = computed(() => t.sprintf(/* translators: %s: course title */ t.__('finished %s', 'membuild-community'), props.cert.course))
const meta = computed(() => [shortDay(props.cert.finished_on), props.cert.lessons ? lessonsOf(props.cert.lessons) : ''].filter(Boolean).join(' · '))
</script>

<template>
  <div class="cert" :class="{ small }">
    <span class="cert-logo" aria-hidden="true">
      <img v-if="cert.logo" :src="cert.logo" alt="" />
      <template v-else>{{ cert.initials }}</template>
    </span>
    <p class="cert-community">{{ cert.community }}</p>
    <p class="cert-title">{{ cert.title }}</p>
    <p class="cert-say">{{ t.__('This is to say that', 'membuild-community') }}</p>
    <p class="cert-name">{{ cert.name }}</p>
    <p class="cert-course">{{ finishedLine }}</p>
    <p class="cert-meta">{{ meta }}</p>
    <span class="cert-sign">
      <img v-if="cert.signature" :src="cert.signature" alt="" />
      <strong>{{ cert.signed_by }}</strong>
      <small>{{ cert.role }}</small>
    </span>
  </div>
</template>

<style scoped>
.cert {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 36px 28px 32px;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface);
  text-align: center;
}
.cert p {
  margin: 0;
}
.cert-logo {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  overflow: hidden;
  border-radius: var(--radius-sm);
  background: var(--accent);
  color: var(--accent-ink);
  font-size: var(--fs-3xl);
  font-weight: 800;
}
.cert-logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.cert-community {
  margin-top: 12px !important;
  color: var(--text-2);
  font-size: var(--fs-lg);
  font-weight: 650;
}
.cert-title {
  margin-top: 10px !important;
  font-size: var(--fs-8xl);
  font-weight: 750;
  line-height: 1.2;
}
.cert-say {
  margin-top: 14px !important;
  color: var(--text-2);
  font-size: var(--fs-base);
}
.cert-name {
  margin-top: 10px !important;
  font-size: var(--fs-9xl);
  font-weight: 800;
  line-height: 1.2;
  overflow-wrap: anywhere;
}
.cert-course {
  margin-top: 12px !important;
  font-size: var(--fs-xl);
}
.cert-meta {
  margin-top: 8px !important;
  color: var(--text-2);
  font-size: var(--fs-base);
}
.cert-sign {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 160px;
  margin-top: 18px;
  padding-top: 10px;
  border-top: 1px solid var(--text);
}
.cert-sign img {
  order: -1;
  max-width: 150px;
  max-height: 48px;
  margin: -58px 0 10px;
  object-fit: contain;
}
.cert-sign strong {
  font-size: var(--fs-base);
}
.cert-sign small {
  color: var(--text-2);
  font-size: var(--fs-sm);
}
.small {
  padding: 22px 18px 20px;
}
.small .cert-logo {
  width: 40px;
  height: 40px;
  font-size: var(--fs-lg);
}
.small .cert-community {
  margin-top: 8px !important;
  font-size: var(--fs-sm);
}
.small .cert-title {
  margin-top: 6px !important;
  font-size: var(--fs-2xl);
}
.small .cert-say {
  margin-top: 8px !important;
  font-size: var(--fs-sm);
}
.small .cert-name {
  margin-top: 6px !important;
  font-size: var(--fs-5xl);
}
.small .cert-course {
  margin-top: 6px !important;
  font-size: var(--fs-base);
}
.small .cert-meta {
  font-size: var(--fs-sm);
}
@media (max-width: 600px) {
  .cert:not(.small) {
    padding: 24px 18px 22px;
  }
  .cert:not(.small) .cert-title {
    font-size: var(--fs-3xl);
  }
  .cert:not(.small) .cert-name {
    font-size: var(--fs-6xl);
  }
}
</style>
