<script setup>
import { ref, watch } from 'vue'
import { api } from '../../api.js'
import Icon from '../Icon.vue'
import { t } from '../../lib/i18n.js'
import { useSettings } from './context.js'

// GIPHY: with a key, a GIF button shows in posts, comments and chats.
const { form } = useSettings()
const RATINGS = [
  { key: 'g', label: 'G', hint: t.__('Everyone', 'membuild-community') },
  { key: 'pg', label: 'PG', hint: t.__('Mild', 'membuild-community') },
  { key: 'pg-13', label: 'PG-13', hint: t.__('Some adult humour', 'membuild-community') },
  { key: 'r', label: 'R', hint: t.__('Adult', 'membuild-community') },
]

// Working / Not working beside the key, checked with GIPHY a moment after it changes.
const status = ref(null) // null | 'checking' | true | false
let timer = null
let asked = 0
watch(
  () => form.value.gifs.key,
  (key) => {
    clearTimeout(timer)
    status.value = null
    if (!key) return
    timer = setTimeout(async () => {
      const mine = ++asked
      status.value = 'checking'
      const res = await api.checkGifKey(key).catch(() => ({ working: null }))
      if (mine === asked) status.value = res.working
    }, 600)
  },
  { immediate: true },
)
</script>

<template>
  <section id="s-gifs" class="card group">
    <h2>{{ t.__('GIFs', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Adds a GIF search button to posts, comments and chats. Members can always upload GIF files like photos.', 'membuild-community') }}</p>

    <label class="field">
      <span class="label">{{ t.__('GIPHY key', 'membuild-community') }}</span>
      <span class="key-row">
        <input v-model.trim="form.gifs.key" :class="{ bad: status === false }" type="password" autocomplete="off" spellcheck="false" placeholder="a1B2c3D4e5F6g7H8i9J0" />
        <span v-if="status === true" class="pill ok"><Icon name="check" :size="14" /> {{ t.__('Working', 'membuild-community') }}</span>
        <span v-else-if="status === false" class="pill bad"><Icon name="close" :size="14" /> {{ t.__('Not working', 'membuild-community') }}</span>
        <span v-else-if="status === 'checking'" class="pill">{{ t.__('Checking…', 'membuild-community') }}</span>
      </span>
      <small v-if="status === false" class="err">{{ t.__('GIPHY didn’t accept that key. Copy it again from your GIPHY dashboard (it’s about 32 letters and numbers, no spaces) and paste it here. Until it works, members can still upload their own GIF files.', 'membuild-community') }}</small>
      <small>{{ t.__('Free from GIPHY (choose “API”, not “SDK”).', 'membuild-community') }} <a href="https://developers.giphy.com/dashboard/" target="_blank" rel="noopener" class="open-link">{{ t.__('Get a free key', 'membuild-community') }} <Icon name="external" :size="13" /></a></small>
    </label>

    <hr class="rule" />

    <fieldset class="field">
      <legend class="label">{{ t.__('Which GIFs can show', 'membuild-community') }}</legend>
      <div class="choices cols-4">
        <label v-for="r in RATINGS" :key="r.key" class="choice" :class="{ on: form.gifs.rating === r.key }">
          <input v-model="form.gifs.rating" type="radio" name="rating" :value="r.key" />
          <span class="mark" /><strong>{{ r.label }}</strong><small>{{ r.hint }}</small>
        </label>
      </div>
      <small>{{ t.__('GIFs load from GIPHY. Nothing is copied to your site.', 'membuild-community') }}</small>
    </fieldset>
  </section>
</template>

<style scoped>
.key-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.key-row input {
  flex: 1;
}

.key-row input.bad {
  border-color: var(--danger) !important;
}

.pill {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 999px;
  background: var(--surface-2);
  font-size: 14px;
  font-weight: 700;
}

.pill.ok {
  background: var(--ok-soft);
  color: var(--ok-text);
}

.pill.bad {
  background: var(--danger-soft);
  color: var(--danger-text);
}

.open-link {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  color: var(--text);
  font-weight: 700;
  text-decoration: underline;
}
</style>
