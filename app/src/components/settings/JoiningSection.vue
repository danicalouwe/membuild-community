<script setup>
import { computed, ref, watch } from 'vue'
import { startAttachment } from '../../lib/attachments.js'
import Icon from '../Icon.vue'
import { useSettings } from './context.js'
import { t } from '../../lib/i18n.js'

// How people get in (a free account, the owner's own sign-up or checkout page, or invite
// only), how the login and join pages look, and where members land.
const { form, meta } = useSettings()

const AGES = [
  { value: 0, label: t.__('Any age', 'membuild-community'), hint: t.__('Nobody is asked.', 'membuild-community') },
  { value: 13, label: t.__('13 or older', 'membuild-community'), hint: t.__('Recommended for most communities.', 'membuild-community') },
  { value: 16, label: t.__('16 or older', 'membuild-community'), hint: t.__('Teens and adults.', 'membuild-community') },
  { value: 18, label: t.__('18 or older', 'membuild-community'), hint: t.__('Adults only.', 'membuild-community') },
]

// The three ways in, from WordPress's "Anyone can register" and the sign-up link.
const picked = ref(null)
const join = computed({
  get: () => picked.value || (form.value.registration ? 'free' : form.value.signup_url ? 'own' : 'invite'),
  set: (v) => {
    picked.value = v
    form.value.registration = v === 'free'
    if (v === 'invite') form.value.signup_url = ''
  },
})
const WAYS = [
  { key: 'free', label: t.__('Free account, right here', 'membuild-community'), hint: t.__('Your join page lets anyone create an account. Same as WordPress’s “Anyone can register”.', 'membuild-community') },
  { key: 'own', label: t.__('Through my own sign-up or checkout page', 'membuild-community'), hint: t.__('For paid communities: the join button goes to your membership plugin, checkout or sales page.', 'membuild-community') },
  { key: 'invite', label: t.__('Invite only', 'membuild-community'), hint: t.__('No join button. You add people yourself in WordPress.', 'membuild-community') },
]
const LANDING = [
  { key: 'members', label: t.__('Straight to the community', 'membuild-community'), hint: t.__('Signed-in members who open your home page see the feed. Visitors still see your home page.', 'membuild-community'), tag: t.__('Recommended', 'membuild-community') },
  { key: 'everyone', label: t.__('The community is the whole site', 'membuild-community'), hint: t.__('Everyone who opens your home page goes to the community. Visitors log in first.', 'membuild-community') },
  { key: 'off', label: t.__('Keep my home page as it is', 'membuild-community'), hint: t.__('The community stays at its own address only.', 'membuild-community') },
]

// Beside the login and join forms: the host's photo, name and one line (see Login::host).
const hosts = computed(() => meta.value.hosts || [])
const firstAdmin = computed(() => hosts.value[0] || null)
const host = computed(() => hosts.value.find((h) => h.id === form.value.login_host) || firstAdmin.value)
const photoUrl = computed(() => form.value.login_picture?.url || host.value?.avatar || '')
const firstSentence = computed(() => (String(form.value.description || '').match(/^.+?[.!?](?=\s|$)/) || [form.value.description || ''])[0])

// The preview: the real login page with these choices, before saving (admins only, see Login::preview_values).
const previewUrl = ref('')
const previewQuery = () => {
  const u = new URL(meta.value.login_url, window.location.href)
  const f = form.value
  u.searchParams.set('pv[host]', f.login_host || 0)
  u.searchParams.set('pv[line]', f.login_text || '')
  u.searchParams.set('pv[photo]', f.login_picture?.id || 0)
  u.searchParams.set('pv[live]', f.login_live ? 1 : 0)
  u.searchParams.set('pv[magic]', f.login_magic ? 1 : 0)
  return u.toString()
}
let previewTimer
watch(
  () => [form.value.login_host, form.value.login_text, form.value.login_picture?.id, form.value.login_live, form.value.login_magic, meta.value.login_url],
  () => {
    clearTimeout(previewTimer)
    previewTimer = setTimeout(() => (previewUrl.value = meta.value.login_url ? previewQuery() : ''), previewUrl.value ? 500 : 0)
  },
  { immediate: true },
)

// The host's photo: their own upload, or (empty) their profile photo.
const pictureUpload = ref(null)
const pictureInput = ref(null)
function pickPicture(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file?.type.startsWith('image/')) return
  pictureUpload.value = startAttachment(file)
  pictureUpload.value.promise.then(() => {
    const up = pictureUpload.value
    if (up?.status === 'done') form.value.login_picture = { id: up.media.id, url: up.previewUrl }
  })
}
</script>

<template>
  <section id="s-joining" class="card group">
    <h2>{{ t.__('Joining & logging in', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('How people join, how your login and join pages look, and where members land.', 'membuild-community') }}</p>

    <fieldset class="field" :disabled="meta.registration_locked">
      <legend class="label">{{ t.__('How people join', 'membuild-community') }}</legend>
      <div class="choices">
        <label v-for="w in WAYS" :key="w.key" class="choice" :class="{ on: join === w.key }">
          <input v-model="join" type="radio" name="join" :value="w.key" />
          <span class="mark" /><strong>{{ w.label }}</strong><small>{{ w.hint }}</small>
        </label>
      </div>
      <small v-if="meta.registration_locked">{{ t.__('On a network of sites, only the network admin can change this (Network Admin → Settings → Registration).', 'membuild-community') }}</small>

      <div v-if="join === 'own'" class="sub">
        <label class="field">
          <span class="label">{{ t.__('Where the join button goes', 'membuild-community') }}</span>
          <input v-model.trim="form.signup_url" type="url" placeholder="https://" />
          <small>{{ t.__('Your checkout or sign-up page. After paying, members log in here.', 'membuild-community') }}</small>
        </label>
        <label class="field">
          <span class="label">{{ t.__('Button text', 'membuild-community') }}</span>
          <input v-model="form.signup_label" type="text" maxlength="40" :placeholder="t.__('Create new account', 'membuild-community')" />
          <small>{{ t.__('A few words, like “Become a member”.', 'membuild-community') }}</small>
        </label>
      </div>

      <div v-else-if="join === 'free'" class="sub">
        <fieldset class="field">
          <legend class="label">{{ t.__('Minimum age', 'membuild-community') }}</legend>
          <div class="choices cols-4">
            <label v-for="a in AGES" :key="a.value" class="choice" :class="{ on: form.min_age === a.value }">
              <input v-model.number="form.min_age" type="radio" name="min_age" :value="a.value" />
              <span class="mark" /><strong>{{ a.label }}</strong><small>{{ a.hint }}</small>
            </label>
          </div>
          <small>{{ t.__('New members pick their birth month and year. It isn’t saved.', 'membuild-community') }}</small>
          <details class="why">
            <summary>{{ t.__('Why 13?', 'membuild-community') }}</summary>
            <small>{{ t.__('In the US, communities open to children under 13 must follow a law called COPPA, so 13 is the default.', 'membuild-community') }}</small>
          </details>
        </fieldset>
        <label class="field">
          <span class="label">{{ t.__('Terms page', 'membuild-community') }} <em>{{ t.__('(optional)', 'membuild-community') }}</em></span>
          <input v-model.trim="form.terms_url" type="url" placeholder="https://" />
          <small>
            {{ t.__('Shown when people sign up, with your Privacy Policy.', 'membuild-community') }}
            <a :href="meta.privacy_admin_url">{{ meta.privacy_url ? t.__('Change the Privacy Policy page', 'membuild-community') : t.__('Choose a Privacy Policy page', 'membuild-community') }}</a>
          </small>
        </label>
      </div>
    </fieldset>

    <hr class="rule" />

    <label class="toggle-row first">
      <span>
        <strong>{{ t.__('Our login pages', 'membuild-community') }}</strong>
        <small>{{ t.__('Log in, join and new password, in your colors. Only turn this off if a security plugin needs WordPress’s own login.', 'membuild-community') }}</small>
      </span>
      <input v-model="form.login_page" type="checkbox" class="switch" role="switch" />
    </label>

    <div v-if="form.login_page" class="sub look">
      <div class="look-cols">
        <div class="look-fields">
          <h3>{{ t.__('How your login and join pages look', 'membuild-community') }}</h3>
          <div class="field">
            <span class="label">{{ t.__('Photo', 'membuild-community') }}</span>
            <div class="photo-row">
              <span class="host-photo">
                <img v-if="photoUrl" :src="photoUrl" alt="" />
                <template v-else-if="pictureUpload?.status === 'working'">{{ t.__('Uploading…', 'membuild-community') }}</template>
                <Icon v-else name="image" :size="22" />
              </span>
              <span class="photo-actions">
                <span class="photo-btns">
                  <button type="button" class="btn-soft" @click="pictureInput.click()">{{ t.__('Change photo', 'membuild-community') }}</button>
                  <button v-if="form.login_picture" type="button" class="btn-text" @click="form.login_picture = null; pictureUpload = null">{{ t.__('Use my profile photo', 'membuild-community') }}</button>
                </span>
                <small>{{ t.__('A photo of you, the host. No photo? We use your profile photo, or your logo.', 'membuild-community') }}</small>
              </span>
            </div>
            <input ref="pictureInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickPicture" />
            <small v-if="pictureUpload?.status === 'error'" class="err">{{ pictureUpload.error }}</small>
          </div>
          <label class="field">
            <span class="label">{{ t.__('Host', 'membuild-community') }}</span>
            <span class="host-select">
              <img v-if="host?.avatar" :src="host.avatar" alt="" />
              <select v-model.number="form.login_host">
                <option :value="0">{{ firstAdmin ? t.sprintf(/* translators: %s: a person's name */ t.__('%s (the first admin)', 'membuild-community'), firstAdmin.name) : t.__('The first admin', 'membuild-community') }}</option>
                <option v-for="h in hosts" :key="h.id" :value="h.id">{{ h.name }}</option>
              </select>
            </span>
            <small>{{ t.sprintf(/* translators: %s: community name */ t.__('Their name shows on the card as “Host of %s”. Admins and moderators can be the host.', 'membuild-community'), form.name) }}</small>
          </label>
          <label class="field">
            <span class="label">{{ t.__('Your line', 'membuild-community') }}</span>
            <input v-model="form.login_text" type="text" maxlength="200" :placeholder="firstSentence || t.__('What members get from you, in one sentence', 'membuild-community')" />
            <small>{{ t.__('One sentence about what members get from you. Empty: the first sentence of your community description.', 'membuild-community') }}</small>
          </label>
          <label class="field">
            <span class="label">{{ t.__('Join page headline', 'membuild-community') }}</span>
            <input v-model="form.login_headline" type="text" maxlength="80" :placeholder="t.__('Join now.', 'membuild-community')" />
            <small>{{ t.__('Empty: “Join now.” Log in always says “Welcome back.”', 'membuild-community') }}</small>
          </label>
        </div>
        <div class="preview">
          <span class="preview-title">{{ t.__('Preview · Log in', 'membuild-community') }}</span>
          <div class="preview-frame">
            <iframe :src="previewUrl" :title="t.__('Preview of your login page', 'membuild-community')" tabindex="-1" loading="lazy" />
          </div>
          <small>{{ t.__('Changes show here as you type.', 'membuild-community') }} <a :href="meta.login_url" target="_blank" rel="noopener" class="open-link">{{ t.__('Open the login page', 'membuild-community') }} <Icon name="external" :size="14" /></a></small>
        </div>
      </div>
      <label class="toggle-row">
        <span>
          <strong>{{ t.__('Show what’s happening', 'membuild-community') }}</strong>
          <small>{{ t.__('The next live call on Log in, and member faces on Join, once there’s something real to show.', 'membuild-community') }}</small>
        </span>
        <input v-model="form.login_live" type="checkbox" class="switch" role="switch" />
      </label>
      <label class="toggle-row">
        <span>
          <strong>{{ t.__('“Email me a login link”', 'membuild-community') }}</strong>
          <small>{{ t.__('Members can log in with a link sent to their email, no password needed. It works once, for 15 minutes.', 'membuild-community') }}</small>
        </span>
        <input v-model="form.login_magic" type="checkbox" class="switch" role="switch" />
      </label>
    </div>
    <p v-else class="off-note">
      <span class="i" aria-hidden="true">i</span>
      <span><strong>{{ t.__('Members use WordPress’s own login', 'membuild-community') }}</strong> {{ t.__('(or your security plugin’s) to log in, join and reset their password. After they log in, the community opens as usual. Checkout and Thank you don’t change.', 'membuild-community') }}</span>
    </p>

    <hr class="rule" />

    <fieldset class="field">
      <legend class="label">{{ t.__('Where members land', 'membuild-community') }}</legend>
      <div class="choices">
        <label v-for="l in LANDING" :key="l.key" class="choice" :class="{ on: form.landing === l.key }">
          <input v-model="form.landing" type="radio" name="landing" :value="l.key" />
          <span class="mark" /><strong>{{ l.label }}<span v-if="l.tag" class="tag">{{ l.tag }}</span></strong><small>{{ l.hint }}</small>
        </label>
      </div>
      <small class="note">{{ t.__('After logging in, members go to the feed and admins to the dashboard.', 'membuild-community') }}</small>
    </fieldset>
  </section>
</template>

<style scoped>
.look h3 {
  margin: 0 0 16px;
  font-size: var(--fs-xl);
}

.look-cols {
  display: flex;
  gap: 36px;
  align-items: flex-start;
}

.look-fields {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.look-fields h3 {
  margin: 0;
}

.photo-row {
  display: flex;
  gap: 16px;
  align-items: center;
}

.host-photo {
  flex: none;
  display: grid;
  place-items: center;
  width: 96px;
  height: 110px;
  overflow: hidden;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-sm);
}

.host-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center 25%;
}

.photo-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.photo-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.btn-soft,
.btn-text {
  min-height: 44px;
  padding: 0 18px;
  border: 1px solid var(--border);
  border-radius: var(--radius-pill);
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: var(--fs-md);
  font-weight: 800;
  cursor: pointer;
}

.btn-text {
  padding: 0 12px;
  border-color: transparent;
  background: none;
  font-weight: 700;
  text-decoration: underline;
}

.host-select {
  position: relative;
  display: block;
}

.host-select img {
  position: absolute;
  top: 50%;
  left: 14px;
  width: 28px;
  height: 28px;
  margin-top: -14px;
  border-radius: 50%;
  object-fit: cover;
  pointer-events: none;
}

.host-select img + select {
  padding-left: 52px;
}

.preview {
  flex: none;
  width: 420px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.preview-title {
  color: var(--text-2);
  font-size: var(--fs-sm);
  font-weight: 800;
  letter-spacing: 0.4px;
  text-transform: uppercase;
}

/* The real page at 1280 × 900, shrunk to fit. */
.preview-frame {
  width: 420px;
  height: 295px;
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
}

.preview-frame iframe {
  width: 1280px;
  height: 900px;
  border: 0;
  transform: scale(0.328);
  transform-origin: 0 0;
  pointer-events: none;
}

.look > .toggle-row {
  margin-top: 8px;
}

.off-note {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin: 12px 0 0;
  padding: 14px 16px;
  border-radius: var(--radius-md);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--fs-md);
  line-height: 1.5;
}

.off-note strong {
  color: var(--text);
}

.off-note .i {
  flex: none;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--fs-sm);
  font-weight: 800;
}

/* Side by side only when the card is wide; otherwise the preview sits under the fields, and on phones it hides. */
.look {
  container-type: inline-size;
}

@container (max-width: 900px) {
  .look-cols {
    flex-direction: column;
    gap: 24px;
  }

  .look-fields {
    width: 100%;
  }
}

@container (max-width: 460px) {
  .preview {
    display: none;
  }
}

.open-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 4px;
  color: var(--text);
  font-weight: 700;
  text-decoration: underline;
}

/* "Why 13?": the reason, one tap away. */
.why summary {
  width: fit-content;
  color: var(--accent-text);
  font-size: var(--fs-md);
  font-weight: 650;
  cursor: pointer;
}

.why small {
  display: block;
  margin-top: 4px;
}
</style>
