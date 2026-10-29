<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '../api.js'
import { cfg, feature } from '../config.js'
import Avatar from '../components/Avatar.vue'
import BaseDialog from '../components/BaseDialog.vue'
import Icon from '../components/Icon.vue'
import { t } from '../lib/i18n.js'

/**
 * Edit profile, its own page: what other members see. Name, About you and Where you
 * live save by themselves. Photo and cover change on the profile itself (the camera).
 * Below: Emails you get, Privacy and Blocked people, each showing where it stands.
 */
const BIO_MAX = 160

const form = ref(null) // { name, bio, location }
const saved = ref('')
const status = ref('') // '' | 'saving' | 'saved' | 'error'
const error = ref('')

onMounted(async () => {
  try {
    const m = await api.member(cfg.user.id)
    form.value = { name: m.name, bio: m.bio || '', location: m.location || '' }
    saved.value = JSON.stringify(form.value)
  } catch (e) {
    error.value = e.message
  }
})

let timer = null
watch(
  form,
  (f) => {
    if (!f || JSON.stringify(f) === saved.value) return
    clearTimeout(timer)
    if (!f.name.trim()) {
      status.value = 'error'
      error.value = t.__('Your name can’t be empty.', 'membuild-community')
      return
    }
    status.value = 'saving'
    timer = setTimeout(save, 700)
  },
  { deep: true },
)
async function save() {
  const body = { name: form.value.name.trim(), bio: form.value.bio.trim(), location: form.value.location.trim() }
  try {
    const m = await api.updateProfile(body)
    saved.value = JSON.stringify(form.value)
    Object.assign(cfg.user, { name: m.name }) // your name shows all over the app
    status.value = 'saved'
    error.value = ''
  } catch (e) {
    status.value = 'error'
    error.value = e.message
  }
}

/* ---------- Emails you get (its own page) ---------- */
const email = ref(null)
api.emailPrefs().then((p) => (email.value = p)).catch(() => {})
const FREQ = computed(() => ({ instant: t.__('As it happens', 'membuild-community'), daily: t.__('Once a day', 'membuild-community'), off: t.__('No emails', 'membuild-community') }))

/* ---------- Privacy ---------- */
const privacy = ref(null)
const onlineOn = feature('messages') && cfg.community.features?.online !== false
if (onlineOn) api.privacy().then((p) => (privacy.value = p)).catch(() => {})

/* ---------- Blocked people ---------- */
const blocked = ref([])
const blockedOpen = ref(false)
api.blocks().then((res) => (blocked.value = res.items)).catch(() => {})
async function unblock(person) {
  try {
    await api.unblock(person.id)
    blocked.value = blocked.value.filter((p) => p.id !== person.id)
  } catch (e) {
    error.value = e.message
  }
}
const blockedNote = computed(() =>
  blocked.value.length ? t.sprintf(/* translators: %d: number of people */ t._n('%d person', '%d people', blocked.value.length, 'membuild-community'), blocked.value.length) : t.__('None', 'membuild-community'),
)
</script>

<template>
  <div class="edit">
    <RouterLink :to="{ name: 'me' }" class="back-link"><Icon name="chevronLeft" :size="18" /> {{ t.__('Your profile', 'membuild-community') }}</RouterLink>
    <div class="head">
      <h1 class="page-title">{{ t.__('Edit profile', 'membuild-community') }}</h1>
      <span v-if="status === 'saved'" class="status-tag lg ok"><Icon name="check" :size="16" /> {{ t.__('Saved', 'membuild-community') }}</span>
      <span v-else-if="status === 'saving'" class="status-tag lg">{{ t.__('Saving…', 'membuild-community') }}</span>
    </div>
    <p class="page-intro">{{ t.__('This is what other members see. Changes save by themselves. To change your photo or cover, point at it on your profile and click the camera.', 'membuild-community') }}</p>
    <p v-if="error" class="error-text" role="alert">{{ error }}</p>

    <section v-if="form" class="card box">
      <h2>{{ t.__('Your details', 'membuild-community') }}</h2>
      <label class="field">
        <span class="lbl">{{ t.__('Name', 'membuild-community') }}</span>
        <input v-model="form.name" type="text" maxlength="50" required autocomplete="name" />
      </label>
      <label class="field">
        <span class="lbl">{{ t.__('About you', 'membuild-community') }}</span>
        <textarea v-model="form.bio" rows="3" :maxlength="BIO_MAX" />
        <span class="hint-row"><small>{{ t.__('A line or two, so people know what you do.', 'membuild-community') }}</small><small>{{ form.bio.length }} / {{ BIO_MAX }}</small></span>
      </label>
      <label class="field">
        <span class="lbl">{{ t.__('Where you live', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
        <input v-model="form.location" type="text" maxlength="60" :placeholder="t.__('For example, Austin, TX', 'membuild-community')" autocomplete="address-level2" />
        <small>{{ t.__('Shown on your profile and card. Leave it empty to hide it.', 'membuild-community') }}</small>
      </label>
    </section>
    <p v-else-if="!error" class="card box">{{ t.__('Loading…', 'membuild-community') }}</p>

    <section class="card links">
      <RouterLink :to="{ name: 'emails' }" class="row">
        <strong>{{ t.__('Emails you get', 'membuild-community') }}</strong>
        <span>{{ email ? FREQ[email.frequency] : '' }} ›</span>
      </RouterLink>
      <RouterLink v-if="onlineOn" :to="{ name: 'privacy' }" class="row">
        <strong>{{ t.__('Privacy', 'membuild-community') }}</strong>
        <span>{{ privacy && !privacy.show_online ? t.__('Hidden when you’re online', 'membuild-community') : t.__('Showing when you’re online', 'membuild-community') }} ›</span>
      </RouterLink>
      <button class="row" @click="blockedOpen = true">
        <strong>{{ t.__('Blocked people', 'membuild-community') }}</strong>
        <span>{{ blockedNote }} ›</span>
      </button>
    </section>

    <BaseDialog v-if="blockedOpen" :label="t.__('Blocked people', 'membuild-community')" :width="520" style="border-radius: 24px" @close="blockedOpen = false">
      <template #header><h2 class="dlg-title">{{ t.__('Blocked people', 'membuild-community') }}</h2></template>
      <div class="dlg">
        <p class="small">{{ t.__('You don’t see each other’s posts, comments or stories, and you can’t message each other. They aren’t told.', 'membuild-community') }}</p>
        <ul v-if="blocked.length" class="people">
          <li v-for="p in blocked" :key="p.id">
            <Avatar :user="p" :size="44" />
            <span>{{ p.name }}</span>
            <button class="btn btn-lg btn-ghost" @click="unblock(p)">{{ t.__('Unblock', 'membuild-community') }}</button>
          </li>
        </ul>
        <p v-else class="small">{{ t.__('You haven’t blocked anyone.', 'membuild-community') }}</p>
        <div class="dlg-actions">
          <button class="btn btn-lg btn-primary" @click="blockedOpen = false">{{ t.__('Done', 'membuild-community') }}</button>
        </div>
      </div>
    </BaseDialog>
  </div>
</template>

<style scoped>
.edit {
  max-width: 760px;
  margin: 0 auto;
  padding: 24px 16px 40px;
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.box {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 28px 30px;
  border-radius: var(--radius-lg);
}

h2 {
  margin: 0;
  font-size: var(--fs-3xl);
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.lbl {
  font-size: var(--fs-lg);
  font-weight: 700;
}

.lbl em {
  color: var(--text-2);
  font-style: normal;
  font-weight: 400;
}

.field input,
.field textarea {
  width: 100%;
  min-height: 52px;
  padding: 12px 16px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-xl);
  line-height: 1.5;
}

.field textarea {
  resize: vertical;
}

.field small {
  color: var(--text-2);
  font-size: var(--fs-md);
}

.hint-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.hint-row small:last-child {
  flex: none;
  white-space: nowrap;
}

.links {
  display: flex;
  flex-direction: column;
  margin-top: 20px;
  padding: 10px 30px;
  border-radius: var(--radius-lg);
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 54px;
  padding: 0;
  border: 0;
  border-top: 1px solid var(--border);
  background: none;
  color: var(--text);
  font: inherit;
  text-align: left;
  text-decoration: none;
  cursor: pointer;
}

.row:first-child {
  border-top: 0;
}

.row strong {
  font-size: var(--fs-xl);
}

.row span {
  color: var(--text-2);
  font-size: var(--fs-base);
}

/* Dialogs */
.dlg-title {
  margin: 0;
  padding: 30px 30px 0;
  font-size: var(--fs-5xl);
}

.dlg {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 10px 30px 28px;
}

.small {
  margin: 0 0 6px;
  color: var(--text-2);
  font-size: var(--fs-lg);
  line-height: 1.5;
}

.people {
  margin: 0;
  padding: 0;
  list-style: none;
}

.people li {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  border-top: 1px solid var(--border);
}

.people span {
  flex: 1;
  font-weight: 650;
}

.dlg-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 12px;
}

@media (max-width: 600px) {
  .box {
    padding: 22px 18px;
  }

  .links {
    padding: 6px 18px;
  }
}
</style>
