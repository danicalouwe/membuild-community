<script setup>
import { computed, ref, watch } from 'vue'
import { startAttachment } from '../../lib/attachments.js'
import Icon from '../Icon.vue'
import { initialsOf } from '../../config.js'
import { t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// Who the community is: name, description, logo and color.
const { form, meta, logoUpload } = useSettings()
const logoInput = ref(null)
const customAccent = computed(() => form.value?.accent && !meta.value.accents.includes(form.value.accent))

// A brand color typed or pasted as a hex code ("#5b4bff", "5b4bff", or "#5bf" once you leave the field).
// The field keeps what's typed; the color changes as soon as it's a full code.
const hexDraft = ref(null)
const hexShown = computed(() => hexDraft.value ?? (form.value?.accent || meta.value.accents[0]))
const normalize = (value, short) => {
  const hex = value.trim().replace(/^#?/, '#').toLowerCase()
  if (short && /^#[0-9a-f]{3}$/.test(hex)) return '#' + [...hex.slice(1)].map((c) => c + c).join('')
  return /^#[0-9a-f]{6}$/.test(hex) ? hex : null
}
const apply = (hex) => (form.value.accent = hex === meta.value.accents[0] ? '' : hex)
function typeHex(value) {
  hexDraft.value = value
  const hex = normalize(value, false)
  if (hex) apply(hex)
}
function leaveHex() {
  const hex = hexDraft.value === null ? null : normalize(hexDraft.value, true)
  if (hex) apply(hex)
  if (hex || hexDraft.value === null) hexDraft.value = null
}
// Picked a swatch, or pressed Discard: show the color that's really set.
watch(
  () => form.value?.accent,
  (accent) => {
    if (hexDraft.value !== null && normalize(hexDraft.value, false) !== (accent || meta.value.accents[0])) hexDraft.value = null
  },
)
const hexValid = computed(() => hexDraft.value === null || /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hexDraft.value.trim()))

function pickLogo(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file?.type.startsWith('image/')) return
  logoUpload.value = startAttachment(file)
  logoUpload.value.promise.then(() => {
    const up = logoUpload.value
    if (up?.status === 'done') form.value.logo = { id: up.media.id, url: up.previewUrl }
  })
}

function removeLogo() {
  logoUpload.value = null
  form.value.logo = null
}
</script>

<template>
  <section id="s-general" class="card group">
    <h2>{{ t.__('Your community', 'membuild-community') }}</h2>

    <label class="field">
      <span class="label">{{ t.__('Community name', 'membuild-community') }}</span>
      <input v-model="form.name" type="text" maxlength="60" :placeholder="meta.site_name" />
      <small>{{ t.__('Shown in the top bar and the browser tab. Leave empty to use your site name.', 'membuild-community') }}</small>
    </label>

    <label class="field">
      <span class="label">{{ t.__('Description', 'membuild-community') }}</span>
      <textarea v-model="form.description" rows="2" maxlength="300" :placeholder="t.__('What this community is about, in a sentence or two.', 'membuild-community')" />
    </label>

    <div class="field">
      <span class="label">{{ t.__('Logo', 'membuild-community') }}</span>
      <div class="logo-row">
        <span class="logo-box">
          <img v-if="form.logo" :src="form.logo.url" :alt="t.__('Logo', 'membuild-community')" />
          <span v-else-if="logoUpload?.status === 'working'" class="spin" />
          <span v-else class="initial">{{ initialsOf(form.name || meta.site_name) }}</span>
        </span>
        <button type="button" class="btn" :disabled="logoUpload?.status === 'working'" @click="logoInput.click()">
          {{ logoUpload?.status === 'working' ? t.__('Uploading…', 'membuild-community') : form.logo ? t.__('Change', 'membuild-community') : t.__('Upload', 'membuild-community') }}
        </button>
        <button v-if="form.logo" type="button" class="link danger" @click="removeLogo">{{ t.__('Remove', 'membuild-community') }}</button>
        <input ref="logoInput" class="sr-only" type="file" accept="image/*" @change="pickLogo" />
      </div>
      <small v-if="logoUpload?.status === 'error'" class="err">{{ logoUpload.error }}</small>
      <small v-else>{{ t.__('A square image works best.', 'membuild-community') }}</small>
    </div>

    <div class="field">
      <span class="label">{{ t.__('Color', 'membuild-community') }}</span>
      <div class="swatches" role="radiogroup" :aria-label="t.__('Accent color', 'membuild-community')">
        <button
          v-for="hex in meta.accents"
          :key="hex"
          type="button"
          role="radio"
          class="swatch"
          :class="{ on: (form.accent || meta.accents[0]) === hex }"
          :style="{ background: hex }"
          :aria-checked="(form.accent || meta.accents[0]) === hex"
          :aria-label="hex"
          @click="form.accent = hex === meta.accents[0] ? '' : hex; hexDraft = null"
        />
        <label class="swatch custom" :class="{ on: customAccent }" :style="customAccent ? { background: form.accent } : null" :title="t.__('Pick a custom color', 'membuild-community')">
          <Icon :name="customAccent ? 'compose' : 'plus'" :size="15" />
          <input type="color" :value="form.accent || meta.accents[0]" :aria-label="t.__('Pick a custom color', 'membuild-community')" @input="form.accent = $event.target.value; hexDraft = null" />
        </label>
      </div>
      <label class="hex">
        <span>{{ t.__('Custom', 'membuild-community') }}</span>
        <span class="hex-input" :class="{ bad: !hexValid }">
          <i :style="{ background: form.accent || meta.accents[0] }" aria-hidden="true"></i>
          <input
            type="text"
            inputmode="text"
            spellcheck="false"
            autocomplete="off"
            maxlength="7"
            :value="hexShown"
            :aria-label="t.__('Custom color as a hex code', 'membuild-community')"
            placeholder="#5b4bff"
            @input="typeHex($event.target.value)"
            @blur="leaveHex"
          />
        </span>
      </label>
      <small v-if="!hexValid" class="err">{{ t.__('Use a hex code like #5b4bff.', 'membuild-community') }}</small>
      <small v-else>{{ t.__('Used for buttons, links and highlights. Pick one, or paste your brand color’s hex code.', 'membuild-community') }}</small>
    </div>

  </section>
</template>

<style scoped>
.hex {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
  font-weight: 600;
}

/* The settings page's own input style, with the color shown inside it. */
.hex-input {
  position: relative;
  display: block;
  width: 150px;
}

.hex-input i {
  position: absolute;
  top: 50%;
  left: 12px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  transform: translateY(-50%);
  box-shadow: inset 0 0 0 1px rgb(255 255 255 / 15%);
  pointer-events: none;
}

/* Specific enough to win over the settings page's input style, which sets the padding. */
.hex .hex-input input[type='text'] {
  width: 100%;
  padding-left: 36px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.hex .hex-input.bad input[type='text'] {
  border-color: var(--danger);
}
</style>
