<script setup>
import { useSettings } from './context.js'
import { t } from '../../lib/i18n.js'

// How people get in: creating an account (WordPress's own "Anyone can register"),
// the minimum age, the pages they agree to, the login pages and where they land.
const { form, meta } = useSettings()

const AGES = [
  { value: 0, label: t.__('No minimum age', 'membuild-community') },
  { value: 13, label: t.__('13 or older', 'membuild-community') },
  { value: 16, label: t.__('16 or older', 'membuild-community') },
  { value: 18, label: t.__('18 or older', 'membuild-community') },
]
</script>

<template>
  <section id="s-joining" class="card group">
    <h2>{{ t.__('Joining & signing in', 'membuild-community') }}</h2>

    <label class="toggle-row first" :class="{ locked: meta.registration_locked }">
      <span>
        <strong>{{ t.__('Let people create a free account', 'membuild-community') }}</strong>
        <small v-if="meta.registration_locked">{{ t.__('On a network of sites, only the network admin can change this (Network Admin → Settings → Registration).', 'membuild-community') }}</small>
        <small v-else>{{ t.__('Adds “Create new account” to the login page. This is the same switch as WordPress’s “Anyone can register” (Settings → General), so the two always match.', 'membuild-community') }}</small>
      </span>
      <input v-model="form.registration" type="checkbox" class="switch" role="switch" :disabled="meta.registration_locked" />
    </label>

    <label v-if="!form.registration" class="field">
      <span class="label">{{ t.__('Sign-up link (optional)', 'membuild-community') }}</span>
      <input v-model.trim="form.signup_url" type="url" placeholder="https://" />
      <small>{{ t.__('Selling memberships with another plugin? Paste its sign-up or checkout page, and “Create new account” goes there instead.', 'membuild-community') }}</small>
    </label>

    <label v-if="form.registration" class="field">
      <span class="label">{{ t.__('Minimum age', 'membuild-community') }}</span>
      <select v-model.number="form.min_age">
        <option v-for="a in AGES" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
      <small>{{ t.__('New members choose their birth month and year when they sign up. Their birthday isn’t saved. In the US, communities open to children under 13 must follow COPPA, so 13 is the default.', 'membuild-community') }}</small>
    </label>

    <label class="field">
      <span class="label">{{ t.__('Terms page (optional)', 'membuild-community') }}</span>
      <input v-model.trim="form.terms_url" type="url" placeholder="https://" />
      <small>
        {{ t.__('Shown when people sign up, with your Privacy Policy.', 'membuild-community') }}
        <a :href="meta.privacy_admin_url">{{ meta.privacy_url ? t.__('Change the Privacy Policy page', 'membuild-community') : t.__('Choose a Privacy Policy page', 'membuild-community') }}</a>
      </small>
    </label>

    <label class="toggle-row">
      <span>
        <strong>{{ t.__('Community login pages', 'membuild-community') }}</strong>
        <small>{{ t.__('Signing up, logging in and resetting a password happen on pages in your community’s colors. Turn this off if a security plugin needs WordPress’s standard login screen.', 'membuild-community') }}</small>
      </span>
      <input v-model="form.login_page" type="checkbox" class="switch" role="switch" />
    </label>

    <fieldset class="field">
      <legend class="label">{{ t.__('Where members land', 'membuild-community') }}</legend>
      <label class="radio"><input v-model="form.landing" type="radio" value="members" /> {{ t.__('Members go straight to the community', 'membuild-community') }} <small>{{ t.__("Signed-in members who open your home page see the feed. Visitors who aren't signed in still see your home page.", 'membuild-community') }}</small></label>
      <label class="radio"><input v-model="form.landing" type="radio" value="everyone" /> {{ t.__('The community is the whole site', 'membuild-community') }} <small>{{ t.__('Everyone who opens your home page goes to the community. Visitors are asked to log in first.', 'membuild-community') }}</small></label>
      <label class="radio"><input v-model="form.landing" type="radio" value="off" /> {{ t.__('Leave my home page alone', 'membuild-community') }} <small>{{ t.__('The community stays at its own address only.', 'membuild-community') }}</small></label>
      <small class="note">{{ t.__('Members also land on the feed after logging in; admins still go to the dashboard. Your other pages open as usual.', 'membuild-community') }}</small>
    </fieldset>
  </section>
</template>
