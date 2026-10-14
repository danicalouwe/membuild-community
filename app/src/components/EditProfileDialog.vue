<script setup>
import { computed, ref, useId } from 'vue'
import { api } from '../api.js'
import { startAttachment } from '../lib/attachments.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import BaseDialog from './BaseDialog.vue'
import { t } from '../lib/i18n.js'

// Your photo, name and bio. A new photo uploads as soon as it's picked.
const props = defineProps({ member: { type: Object, required: true } })
const emit = defineEmits(['close', 'saved'])

const BIO_MAX = 160
const name = ref(props.member.name)
const bio = ref(props.member.bio || '')
const photo = ref(null) // a new upload
const removePhoto = ref(false)
const fileInput = ref(null)
const saving = ref(false)
const error = ref('')

// People you blocked, so you can unblock them (they don't show up in search).
const blocked = ref([])
api.blocks().then((res) => (blocked.value = res.items)).catch(() => {})
async function unblock(person) {
  try {
    await api.unblock(person.id)
    blocked.value = blocked.value.filter((p) => p.id !== person.id)
  } catch (err) {
    error.value = err.message
  }
}

// Email preferences live here too; they save along with the profile.
const email = ref(null)
const emailSaved = ref('')
api.emailPrefs().then((p) => {
  email.value = p
  emailSaved.value = JSON.stringify(p)
}).catch(() => {})
const emailDirty = computed(() => !!email.value && JSON.stringify(email.value) !== emailSaved.value)

const preview = computed(() => (photo.value ? photo.value.previewUrl : removePhoto.value ? null : props.member.avatar_large))
const profileDirty = computed(() => name.value !== props.member.name || bio.value !== (props.member.bio || '') || photo.value || removePhoto.value)
const dirty = computed(() => profileDirty.value || emailDirty.value)
const canSave = computed(() => dirty.value && name.value.trim() && !saving.value && photo.value?.status !== 'working' && photo.value?.status !== 'error')

function pick(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file?.type.startsWith('image/')) return
  photo.value?.cancel()
  photo.value = startAttachment(file)
  removePhoto.value = false
}

function clearPhoto() {
  photo.value?.cancel()
  photo.value = null
  removePhoto.value = true
}

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  try {
    if (emailDirty.value) {
      const { frequency, messages, activity } = email.value
      email.value = await api.saveEmailPrefs({ frequency, messages, activity })
      emailSaved.value = JSON.stringify(email.value)
    }
    const body = { name: name.value.trim(), bio: bio.value.trim() }
    if (photo.value) body.avatar = { id: photo.value.media.id }
    else if (removePhoto.value) body.avatar = null
    const saved = await api.updateProfile(body)
    photo.value?.release()
    emit('saved', saved)
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

function close() {
  if (dirty.value && !confirm(t.__('Discard your changes?', 'membuild-community'))) return
  photo.value?.cancel()
  emit('close')
}

// The Save button sits in the dialog's pinned footer, outside the form, so it names the form.
const formId = useId()
const toEmail = (address) => t.sprintf(/* translators: %s: email address */ t.__('to %s', 'membuild-community'), address)
</script>

<template>
  <BaseDialog :title="t.__('Edit profile', 'membuild-community')" :z="85" phone="full" @close="close">
    <form :id="formId" class="body" @submit.prevent="save">
      <div class="photo-row">
        <span class="photo">
          <img v-if="preview" :src="preview" alt="" />
          <span v-else class="initial">{{ name.slice(0, 1).toUpperCase() }}</span>
          <i v-if="photo?.status === 'working'" :style="{ transform: `scaleX(${photo.progress})` }" />
        </span>
        <div class="photo-actions">
          <button type="button" class="btn" @click="fileInput.click()"><Icon name="camera" :size="16" /> {{ preview ? t.__('Change photo', 'membuild-community') : t.__('Add photo', 'membuild-community') }}</button>
          <button v-if="preview && (photo || member.has_photo)" type="button" class="link" @click="clearPhoto">{{ t.__('Remove photo', 'membuild-community') }}</button>
          <small v-if="photo?.status === 'error'" class="err">{{ photo.error }}</small>
          <small v-else-if="removePhoto && !member.has_photo">{{ t.__("You're using your Gravatar.", 'membuild-community') }}</small>
        </div>
        <input ref="fileInput" class="sr-only" type="file" accept="image/*" @change="pick" />
      </div>

      <label class="field">
        <span>{{ t.__('Name', 'membuild-community') }}</span>
        <input v-model="name" type="text" maxlength="50" required autocomplete="name" />
      </label>

      <label class="field">
        <span>{{ t.__('Bio', 'membuild-community') }} <small>{{ bio.length }}/{{ BIO_MAX }}</small></span>
        <textarea v-model="bio" rows="3" :maxlength="BIO_MAX" :placeholder="t.__('A line or two about you', 'membuild-community')" />
      </label>

      <fieldset v-if="email && email.enabled" class="field email">
        <legend>{{ t.__('Email notifications', 'membuild-community') }} <small>{{ toEmail(email.email) }}</small></legend>
        <select v-model="email.frequency" :aria-label="t.__('How often', 'membuild-community')">
          <option value="instant">{{ t.__('As it happens', 'membuild-community') }}</option>
          <option value="daily">{{ t.__('Once a day', 'membuild-community') }}</option>
          <option value="off">{{ t.__('Off', 'membuild-community') }}</option>
        </select>
        <label class="check" :class="{ dim: email.frequency === 'off' }"><input v-model="email.messages" type="checkbox" :disabled="email.frequency === 'off'" /> {{ t.__('New messages', 'membuild-community') }}</label>
        <label class="check" :class="{ dim: email.frequency === 'off' }"><input v-model="email.activity" type="checkbox" :disabled="email.frequency === 'off'" /> {{ t.__('Comments on my posts and replies to my comments', 'membuild-community') }}</label>
      </fieldset>

      <fieldset v-if="blocked.length" class="field blocked">
        <legend>{{ t.__('Blocked people', 'membuild-community') }}</legend>
        <ul>
          <li v-for="p in blocked" :key="p.id">
            <Avatar :user="p" :size="28" />
            <span>{{ p.name }}</span>
            <button type="button" class="btn small" @click="unblock(p)">{{ t.__('Unblock', 'membuild-community') }}</button>
          </li>
        </ul>
      </fieldset>

      <p v-if="error" class="err">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="btn" @click="close">{{ t.__('Cancel', 'membuild-community') }}</button>
      <button type="submit" :form="formId" class="btn btn-primary" :disabled="!canSave">{{ saving ? t.__('Saving…', 'membuild-community') : t.__('Save', 'membuild-community') }}</button>
    </template>
  </BaseDialog>
</template>

<style scoped>
.blocked ul {
  list-style: none;
  margin: 0;
  padding: 0;
}

.blocked li {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
}

.blocked li span {
  flex: 1;
  min-width: 0;
  font-weight: 600;
}

.btn.small {
  padding: 5px 12px;
  font-size: 13px;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 18px;
  overflow-y: auto;
}

.photo-row {
  display: flex;
  align-items: center;
  gap: 16px;
}

.photo {
  position: relative;
  display: grid;
  place-items: center;
  flex: none;
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--accent-ink);
  overflow: hidden;
}

.photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.photo i {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 4px;
  background: var(--accent);
  transform-origin: left;
}

.initial {
  font-size: 36px;
  font-weight: 800;
}

.photo-actions {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-weight: 650;
}

.field span {
  display: flex;
  justify-content: space-between;
}

.field small,
.photo-actions small {
  font-weight: 400;
  color: var(--text-3);
}

.field input,
.field textarea {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-weight: 400;
  resize: vertical;
}

.field input:focus,
.field textarea:focus {
  outline: 2px solid var(--accent);
  outline-offset: -1px;
}

.email {
  margin: 0;
  padding: 0;
  border: 0;
}

.email legend {
  margin-bottom: 6px;
  font-weight: 650;
}

.email legend small {
  font-weight: 400;
  color: var(--text-3);
}

.email select {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
}

.check {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 400;
}

.check.dim {
  opacity: 0.5;
}

.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 16px;
  border-radius: 10px;
  font-weight: 650;
}

.btn:not(.btn-primary) {
  background: var(--surface-2);
}

.link {
  color: var(--danger);
  font-weight: 600;
  font-size: 14px;
}

.err {
  margin: 0;
  color: var(--danger);
}
</style>
