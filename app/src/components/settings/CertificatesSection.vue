<script setup>
import { computed, ref } from 'vue'
import { cfg } from '../../config.js'
import { startAttachment } from '../../lib/attachments.js'
import { t } from '../../lib/i18n.js'
import CertificateCard from '../CertificateCard.vue'
import { useSettings } from './context.js'

/**
 * Settings › Certificates (Content): give them or not, for all courses or chosen ones, what they
 * say, how they look, and whether they show on profiles and can be checked by anyone with the
 * link. Turning them off, or unticking a course, keeps the ones already given (rule 8).
 */
const { form, meta } = useSettings()
const c = computed(() => form.value.certificates)
const courses = computed(() => meta.value?.course_choices || [])
const look = computed(() => meta.value?.certificate_look || {})

const picking = ref(false)
const chosenLabel = computed(() => {
  const n = c.value.courses.length
  return n ? t.sprintf(/* translators: %d: number of courses */ t._n('%d course chosen', '%d courses chosen', n, 'membuild-community'), n) : t.__('No courses chosen', 'membuild-community')
})
function toggleCourse(id, on) {
  const list = c.value.courses.filter((x) => x !== id)
  if (on) list.push(id)
  c.value.courses = list
}

/* ---------- The signature ---------- */
const sigInput = ref(null)
const sigUpload = ref(null)
function pickSignature(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file?.type.startsWith('image/')) return
  sigUpload.value = startAttachment(file)
  sigUpload.value.promise.then(() => {
    const up = sigUpload.value
    if (up?.status === 'done') c.value.signature = { id: up.media.id, url: up.previewUrl }
  })
}
function removeSignature() {
  sigUpload.value = null
  c.value.signature = null
}

// The preview: an example member and course, in this community's look and these words.
const preview = computed(() => ({
  id: 0,
  community: look.value.community || cfg.community.name,
  logo: form.value.logo?.url || null,
  initials: look.value.initials,
  title: c.value.title.trim() || t.__('Certificate of completion', 'membuild-community'),
  name: t.__('Ben Lee', 'membuild-community'),
  course: courses.value[0]?.title || t.__('Your first course', 'membuild-community'),
  finished_on: new Date().toISOString().slice(0, 10),
  lessons: 6,
  signed_by: c.value.signed_by.trim() || look.value.signed_by || '',
  role: c.value.role.trim() || t.__('Host', 'membuild-community'),
  signature: c.value.signature?.url || null,
}))
</script>

<template>
  <section id="s-certificates" class="card group">
    <h2>{{ t.__('Certificates', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Members get a certificate when they finish a course. It uses your logo and main color.', 'membuild-community') }}</p>

    <label class="toggle-row first">
      <span>
        <strong>{{ t.__('Give certificates', 'membuild-community') }}</strong>
        <small>{{ t.__('Members get one when they finish a course. Turning this off keeps the ones already given.', 'membuild-community') }}</small>
      </span>
      <input v-model="c.on" type="checkbox" class="switch" role="switch" />
    </label>

    <fieldset class="field cs-which" :disabled="!c.on">
      <legend class="label">{{ t.__('Which courses give a certificate?', 'membuild-community') }}</legend>
      <div class="choices cols-2">
        <label class="choice pick-card" :class="{ on: c.which === 'all' }">
          <input v-model="c.which" type="radio" name="cert-which" value="all" />
          <span class="mark pick-dot" /><strong>{{ t.__('All courses', 'membuild-community') }}</strong><small>{{ t.__('Every course, as soon as it is finished.', 'membuild-community') }}</small>
        </label>
        <label class="choice pick-card" :class="{ on: c.which === 'chosen' }">
          <input v-model="c.which" type="radio" name="cert-which" value="chosen" />
          <span class="mark pick-dot" /><strong>{{ t.__('Chosen courses', 'membuild-community') }}</strong><small>{{ t.__('Pick them from a list.', 'membuild-community') }}</small>
        </label>
      </div>
      <div v-if="c.which === 'chosen'" class="cs-chosen">
        <span class="label">{{ t.__('Chosen courses', 'membuild-community') }}</span>
        <button type="button" class="btn cs-pick" :aria-expanded="picking" @click="picking = !picking">{{ chosenLabel }}</button>
        <div v-if="picking" class="cs-list" role="group" :aria-label="t.__('Tick the courses that give a certificate', 'membuild-community')">
          <p class="cs-list-title">{{ t.__('Tick the courses that give a certificate', 'membuild-community') }}</p>
          <p v-if="!courses.length" class="cs-none">{{ t.__('No courses yet. Make one in the Classroom first.', 'membuild-community') }}</p>
          <label v-for="co in courses" :key="co.id" class="cs-course">
            <input type="checkbox" class="sr-only" :checked="c.courses.includes(co.id)" @change="toggleCourse(co.id, $event.target.checked)" />
            <span class="tick-box"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke-linecap="round" stroke-linejoin="round" /></svg></span>
            <span>{{ co.title }}<small v-if="co.status === 'draft'"> · {{ t.__('Draft', 'membuild-community') }}</small></span>
          </label>
        </div>
        <p v-if="!c.courses.length" class="info-note">{{ t.__('No course is chosen, so no certificates will be given. Pick at least one course, or choose All courses.', 'membuild-community') }}</p>
      </div>
    </fieldset>

    <hr class="rule" />

    <fieldset class="field">
      <legend class="label">{{ t.__('What does it say?', 'membuild-community') }}</legend>
      <div class="sub">
        <label class="field">
          <span class="label">{{ t.__('Title', 'membuild-community') }}</span>
          <input v-model="c.title" type="text" maxlength="80" :placeholder="t.__('Certificate of completion', 'membuild-community')" />
        </label>
        <label class="field">
          <span class="label">{{ t.__('Signed by', 'membuild-community') }}</span>
          <input v-model="c.signed_by" type="text" maxlength="80" :placeholder="look.signed_by" />
        </label>
        <label class="field">
          <span class="label">{{ t.__('Their role', 'membuild-community') }}</span>
          <input v-model="c.role" type="text" maxlength="60" :placeholder="t.__('Host', 'membuild-community')" />
        </label>
        <div class="field">
          <span class="label">{{ t.__('Signature', 'membuild-community') }}</span>
          <div class="cs-sig">
          <img v-if="c.signature" :src="c.signature.url" :alt="t.__('Signature', 'membuild-community')" />
          <button type="button" class="btn" :disabled="sigUpload?.status === 'working'" @click="sigInput.click()">
            {{ sigUpload?.status === 'working' ? t.__('Uploading…', 'membuild-community') : c.signature ? t.__('Change signature', 'membuild-community') : t.__('Upload a signature', 'membuild-community') }}
          </button>
          <button v-if="c.signature" type="button" class="link danger" @click="removeSignature">{{ t.__('Remove', 'membuild-community') }}</button>
          <input ref="sigInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/png,image/jpeg,image/webp" @change="pickSignature" />
          </div>
          <small v-if="sigUpload?.status === 'error'" class="err">{{ sigUpload.error }}</small>
          <small v-else>{{ t.__('Optional. A picture of a signature on a plain background.', 'membuild-community') }}</small>
        </div>
      </div>
    </fieldset>

    <div class="field">
      <span class="label">{{ t.__('How it looks', 'membuild-community') }}</span>
      <CertificateCard :cert="preview" small class="cs-preview" />
    </div>

    <label class="toggle-row">
      <span>
        <strong>{{ t.__('Show certificates on profiles', 'membuild-community') }}</strong>
        <small>{{ t.__('Other members can see which courses they finished.', 'membuild-community') }}</small>
      </span>
      <input v-model="c.on_profiles" type="checkbox" class="switch" role="switch" />
    </label>
    <label class="toggle-row">
      <span>
        <strong>{{ t.__('Let anyone check a certificate', 'membuild-community') }}</strong>
        <small>{{ t.__('Each one gets a private link for employers.', 'membuild-community') }}</small>
      </span>
      <input v-model="c.checkable" type="checkbox" class="switch" role="switch" />
    </label>
  </section>
</template>

<style scoped>
.cs-which {
  margin-top: 8px;
}
.cs-chosen {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  margin-top: 14px;
}
.cs-pick {
  min-width: 200px;
  justify-content: flex-start;
}
.cs-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  max-width: 460px;
  max-height: 320px;
  overflow: auto;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
}
.cs-list-title {
  margin: 4px 8px 6px;
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 650;
}
.cs-none {
  margin: 4px 8px;
  color: var(--text-2);
}
.cs-course {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  padding: 6px 8px;
  border-radius: var(--radius-sm);
  cursor: pointer;
}
.cs-course:hover {
  background: var(--surface-2);
}
.cs-course small {
  color: var(--text-2);
}
.cs-sig {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.cs-sig .btn.btn {
  background: var(--surface);
}
.cs-sig img {
  max-width: 160px;
  max-height: 52px;
  object-fit: contain;
}
.cs-preview {
  max-width: 460px;
}
</style>
