<script setup>
import { computed, ref } from 'vue'
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

// The login page's picture: our illustration, or the owner's own photo.
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
    <p class="intro">{{ t.__('How people join, and where they land.', 'membuild-community') }}</p>

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
      <h3>{{ t.__('How your login and join pages look', 'membuild-community') }}</h3>
      <label class="field">
        <span class="label">{{ t.__('Big headline', 'membuild-community') }}</span>
        <input v-model="form.login_headline" type="text" maxlength="80" :placeholder="t.__('Learn, share and grow together.', 'membuild-community')" />
        <small>{{ t.__('The last word shows in your main color.', 'membuild-community') }}</small>
      </label>
      <label class="field">
        <span class="label">{{ t.__('Text under it', 'membuild-community') }}</span>
        <input v-model="form.login_text" type="text" maxlength="200" :placeholder="t.__('Same as your community description', 'membuild-community')" />
        <small>{{ t.__('Leave it to use your description, or write a line just for this page.', 'membuild-community') }}</small>
      </label>
      <div class="field">
        <span class="label">{{ t.__('Picture', 'membuild-community') }}</span>
        <div class="choices cols-2 pics">
          <label class="choice pic" :class="{ on: !form.login_picture }">
            <input type="radio" name="picture" :checked="!form.login_picture" @change="form.login_picture = null; pictureUpload = null" />
            <span class="thumb art"><i /><b /><span /></span>
            <span class="mark" /><strong>{{ t.__('Our illustration', 'membuild-community') }}</strong><small>{{ t.__('Drawn in your main color.', 'membuild-community') }}</small>
          </label>
          <label class="choice pic" :class="{ on: !!form.login_picture }">
            <input type="radio" name="picture" :checked="!!form.login_picture" @change="!form.login_picture && pictureInput.click()" />
            <button type="button" class="thumb drop" @click.prevent="pictureInput.click()">
              <img v-if="form.login_picture" :src="form.login_picture.url" alt="" />
              <template v-else-if="pictureUpload?.status === 'working'">{{ t.__('Uploading…', 'membuild-community') }}</template>
              <template v-else><Icon name="upload" :size="18" /> {{ t.__('Upload a photo', 'membuild-community') }}</template>
            </button>
            <span class="mark" /><strong>{{ t.__('Your own photo', 'membuild-community') }}</strong><small>{{ form.login_picture ? t.__('Shown beside the form. Click the photo to change it.', 'membuild-community') : t.__('Shown beside the form.', 'membuild-community') }}</small>
          </label>
        </div>
        <input ref="pictureInput" class="sr-only" type="file" tabindex="-1" aria-hidden="true" accept="image/*" @change="pickPicture" />
        <small v-if="pictureUpload?.status === 'error'" class="err">{{ pictureUpload.error }}</small>
        <small><strong>{{ t.__('Photo tips:', 'membuild-community') }}</strong> {{ t.__('a wide photo, under 2 MB. Keep faces in the middle.', 'membuild-community') }}</small>
        <a :href="meta.login_url" target="_blank" rel="noopener" class="open-link">{{ t.__('Open the login page', 'membuild-community') }} <Icon name="external" :size="14" /></a>
      </div>
    </div>

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

/* The two picture choices: a small picture on top, then the mark and words. */
.pic {
  grid-template-rows: auto auto auto;
}

.thumb {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 92px;
  margin-bottom: 8px;
  overflow: hidden;
  border-radius: var(--radius-sm);
  color: var(--text-2);
  font-weight: 700;
}

.thumb.art {
  position: relative;
  background: linear-gradient(135deg, var(--accent-soft), var(--surface-2));
}

.thumb.art i,
.thumb.art b,
.thumb.art span {
  position: absolute;
  border-radius: var(--radius-xs);
}

.thumb.art i {
  top: 18px;
  left: 30%;
  width: 46px;
  height: 58px;
  background: var(--accent);
}

.thumb.art b {
  top: 26px;
  left: calc(30% - 34px);
  width: 46px;
  height: 30px;
  background: var(--surface);
  box-shadow: 0 4px 10px color-mix(in srgb, var(--shadow-color) 10%, transparent);
}

.thumb.art span {
  top: 60px;
  left: calc(30% + 34px);
  width: 18px;
  height: 18px;
  border: 3px solid var(--surface);
  border-radius: 50%;
  background: var(--accent);
}

.thumb.drop {
  border: 2px dashed var(--border-strong);
  background: var(--surface);
}

.thumb.drop img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pic .mark {
  grid-row: auto;
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
