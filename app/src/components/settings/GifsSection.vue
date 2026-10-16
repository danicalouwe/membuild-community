<script setup>
import { around, t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// GIPHY: with a key, a GIF button shows in posts, comments and chats.
const { form } = useSettings()
const keyHelp = around(/* translators: %s: "GIPHY's developer dashboard", a link */ t.__('Create a free API key (choose “API”, not “SDK”) in %s and paste it here.', 'membuild-community'))
const RATINGS = [
  { key: 'g', label: t.__('G: fine for everyone', 'membuild-community') },
  { key: 'pg', label: t.__('PG: mild', 'membuild-community') },
  { key: 'pg-13', label: t.__('PG-13: some adult humour', 'membuild-community') },
  { key: 'r', label: t.__('R: adult', 'membuild-community') },
]
</script>

<template>
  <section id="s-gifs" class="card group">
    <h2>{{ t.__('GIFs', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Add a GIF button to posts, comments and chats, with GIPHY’s search. Members can always upload GIF files like photos; this adds the search.', 'membuild-community') }}</p>

    <label class="field">
      <span class="label">{{ t.__('GIPHY API key', 'membuild-community') }}</span>
      <input v-model.trim="form.gifs.key" type="text" autocomplete="off" spellcheck="false" placeholder="a1B2c3D4e5F6g7H8i9J0" />
      <small>{{ keyHelp[0] }}<a href="https://developers.giphy.com/dashboard/" target="_blank" rel="noopener">{{ t.__('GIPHY’s developer dashboard', 'membuild-community') }}</a>{{ keyHelp[1] }}</small>
      <small>{{ t.__('New keys allow 100 searches an hour across the whole community. When that’s not enough, ask GIPHY to upgrade it from the same dashboard (free for most communities).', 'membuild-community') }}</small>
    </label>

    <label class="field">
      <span class="label">{{ t.__('Which GIFs can show', 'membuild-community') }}</span>
      <select v-model="form.gifs.rating">
        <option v-for="r in RATINGS" :key="r.key" :value="r.key">{{ r.label }}</option>
      </select>
    </label>

    <small class="privacy">{{ t.__('Searches go from members’ browsers to GIPHY, and GIFs load from GIPHY. Nothing is copied to your site.', 'membuild-community') }}</small>
  </section>
</template>

<style scoped>
.privacy {
  display: block;
  color: var(--text-3);
}
</style>
