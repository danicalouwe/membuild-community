<script setup>
import { RouterLink } from 'vue-router'
import { t } from '../lib/i18n.js'

// `badge` shows the member's level on the avatar, Skool-style; `link` opens their profile.
defineProps({
  user: { type: Object, default: null },
  size: { type: Number, default: 40 },
  badge: { type: Boolean, default: false },
  link: { type: Boolean, default: false },
})

const profileLabel = (name) => t.sprintf(/* translators: %s: member's name */ t.__("%s's profile", 'membuild-community'), name)
const levelLabel = (level) => t.sprintf(/* translators: %d: level number */ t.__('Level %d', 'membuild-community'), level)
</script>

<template>
  <component
    :is="link && user?.id ? RouterLink : 'span'"
    class="avatar-wrap"
    :to="link && user?.id ? { name: 'member', params: { id: user.id } } : undefined"
    :aria-label="link && user?.id ? profileLabel(user.name) : undefined"
    :style="{ width: `${size}px`, height: `${size}px` }"
  >
    <img
      class="avatar"
      :src="user?.avatar"
      :alt="user?.name || ''"
      :width="size"
      :height="size"
      loading="lazy"
      decoding="async"
    />
    <span
      v-if="badge && user?.level"
      class="level"
      :style="{ fontSize: `${Math.max(9, Math.round(size * 0.3))}px`, minWidth: `${Math.max(15, Math.round(size * 0.44))}px` }"
      :aria-label="levelLabel(user.level)"
    >{{ user.level }}</span>
  </component>
</template>

<style scoped>
.avatar-wrap {
  position: relative;
  display: inline-flex;
  flex: none;
}

.avatar {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: var(--surface-2);
  object-fit: cover;
}

.level {
  position: absolute;
  right: -4px;
  bottom: -3px;
  display: grid;
  place-items: center;
  aspect-ratio: 1;
  padding: 0 2px;
  border-radius: 999px;
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 800;
  line-height: 1;
  box-shadow: 0 0 0 2px var(--surface);
}
</style>
