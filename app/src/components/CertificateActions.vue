<script setup>
import { defineAsyncComponent, ref, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { saveCertificatePdf } from '../lib/certificatePdf.js'
import { showToast } from '../lib/toast.js'
import { t } from '../lib/i18n.js'
import Icon from './Icon.vue'
import QrCode from './QrCode.vue'
const CreatePostDialog = defineAsyncComponent(() => import('./CreatePostDialog.vue'))

/**
 * Under the member's own certificate: Download PDF, Share on Home, and the check link with
 * Copy link when the owner lets anyone check a certificate (the server only sends it to its member).
 * Someone else viewing it sees the certificate only.
 */
const props = defineProps({
  cert: { type: Object, required: true },
  course: { type: Object, default: null }, // for the course card in the post; built from the certificate otherwise
  checkLink: { type: Boolean, default: true },
})
const emit = defineEmits(['shared'])

const router = useRouter()
const busy = ref(false)
async function download() {
  busy.value = true
  try {
    await saveCertificatePdf(props.cert, { accent: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() })
  } catch {
    showToast(t.__('The PDF didn’t download. Try again, please.', 'membuild-community'))
  } finally {
    busy.value = false
  }
}

const sharing = shallowRef(null)
function share() {
  const c = props.course || { id: props.cert.course_id, title: props.cert.course, lessons_count: props.cert.lessons }
  const url = location.origin + router.resolve({ name: 'course', params: { courseId: c.id } }).href
  sharing.value = {
    text: t.sprintf(/* translators: %s: course title */ t.__('I just finished “%s” and got my certificate. Thank you for this course!', 'membuild-community'), c.title),
    share: { url, course: c },
  }
}

const copied = ref(false)
async function copy() {
  try {
    await navigator.clipboard.writeText(props.cert.check_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    showToast(t.__('Couldn’t copy. Select the link and copy it yourself.', 'membuild-community'))
  }
}
const shown = (url) => url.replace(/^https?:\/\//, '')
</script>

<template>
  <div v-if="cert.mine" class="ca">
    <div class="ca-btns">
      <button class="btn btn-lg btn-primary" type="button" :disabled="busy" @click="download"><Icon name="download" :size="20" /> {{ busy ? t.__('Making the PDF…', 'membuild-community') : t.__('Download PDF', 'membuild-community') }}</button>
      <button class="btn btn-lg btn-ghost" type="button" @click="share"><Icon name="share" :size="20" /> {{ t.__('Share on Home', 'membuild-community') }}</button>
    </div>
    <div v-if="checkLink && cert.check_url" class="ca-check">
      <span class="ca-qr">
        <QrCode :value="cert.check_url" :size="96" class="ca-qr-code" />
        <small>{{ t.__('Scan to check', 'membuild-community') }}</small>
      </span>
      <span class="ca-link">
      <label class="ca-label" :for="`ca-link-${cert.id}`">{{ t.__('Check link', 'membuild-community') }}</label>
      <span class="ca-row">
        <input :id="`ca-link-${cert.id}`" class="ca-input" type="text" readonly :value="shown(cert.check_url)" @focus="$event.target.select()" />
        <button class="btn" :class="copied ? 'ca-copied' : 'btn-ghost'" type="button" @click="copy">
          <Icon :name="copied ? 'check' : 'copy'" :size="18" /> {{ copied ? t.__('Copied', 'membuild-community') : t.__('Copy link', 'membuild-community') }}
        </button>
      </span>
      <small class="ca-help">{{ t.__('Anyone with this link can see that your certificate is real. Handy for an employer or a client.', 'membuild-community') }}</small>
      </span>
    </div>
    <CreatePostDialog v-if="sharing" :draft="sharing" @close="sharing = null; emit('shared')" />
  </div>
</template>

<style scoped>
.ca {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.ca-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.ca-check {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
}
/* The QR code opens the same check page, for holding your phone up to someone. */
.ca-qr {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  color: var(--text-2);
  font-size: var(--fs-sm);
}
.ca-qr-code {
  box-shadow: 0 0 0 1px var(--border);
}
.ca-link {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}
.ca-label {
  font-weight: 700;
}
.ca-row {
  display: flex;
  gap: 8px;
}
.ca-input {
  flex: 1;
  min-width: 0;
  height: 44px;
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-sm);
  background: var(--surface-2);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-md);
}
.ca-copied {
  background: var(--ok-soft);
  color: var(--ok-text);
}
.ca-help {
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.45;
}
@media (max-width: 600px) {
  .ca-btns .btn {
    flex: 1 1 100%;
  }
  .ca-qr-code {
    width: 80px;
    height: 80px;
  }
  /* Beside the QR code there's room for the link or the button, not both: Copy goes under it. */
  .ca-row {
    flex-wrap: wrap;
  }
  .ca-input,
  .ca-row .btn {
    flex: 1 1 100%;
  }
}
</style>
