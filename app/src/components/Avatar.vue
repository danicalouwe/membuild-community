<script setup>
import { ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { isOnline } from '../lib/online.js'
import { t } from '../lib/i18n.js'

// `badge` shows the member's level on the avatar, Skool-style; `link` opens their profile.
// `online` adds the green dot while they're active (bottom-right; 'top' puts it top-right,
// where a level badge is in the way).
const props = defineProps({
  online: { type: [Boolean, String], default: false },
  user: { type: Object, default: null },
  size: { type: Number, default: 40 },
  badge: { type: Boolean, default: false },
  link: { type: Boolean, default: false },
})

// The design system's dot: 14px, 12px on small photos, a little bigger on a profile's big photo.
const dotSize = (size) => (size <= 40 ? 12 : size >= 100 ? 20 : 14)
// The level badge grows with small photos but stops at 20px across, so it never crowds a big one.
const badgeFont = (size) => Math.min(12, Math.max(9, Math.round(size * 0.3)))
const badgeWidth = (size) => Math.min(20, Math.max(15, Math.round(size * 0.44)))
const profileLabel = (name) => t.sprintf(/* translators: %s: member's name */ t.__("%s's profile", 'membuild-community'), name)
// No photo, or it won't load (a deleted file): their initials in a grey circle, never a broken image.
const failed = ref(false)
watch(
  () => props.user?.avatar,
  () => (failed.value = false),
)
const initials = (name) =>
  String(name || '?')
    .trim()
    .split(/\s+/)
    .map((w) => Array.from(w)[0] || '')
    .join('')
    .slice(0, 2)
    .toUpperCase()
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
    <span v-if="!user?.avatar || failed" class="avatar initials" role="img" :aria-label="user?.name || ''" :style="{ fontSize: `${Math.round(size * 0.36)}px` }">{{ initials(user?.name) }}</span>
    <img
      v-else
      class="avatar"
      :src="user?.avatar"
      :alt="user?.name || ''"
      :width="size"
      :height="size"
      loading="lazy"
      decoding="async"
      @error="failed = true"
    />
    <span
      v-if="badge && user?.level"
      class="level"
      :style="{ fontSize: `${badgeFont(size)}px`, minWidth: `${badgeWidth(size)}px` }"
      :aria-label="levelLabel(user.level)"
    >{{ user.level }}</span>
    <span
      v-if="online && isOnline(user)"
      class="dot"
      :class="{ top: online === 'top' }"
      :style="{ width: `${dotSize(size)}px`, height: `${dotSize(size)}px` }"
      role="img"
      :aria-label="t.__('Active now', 'membuild-community')"
    />
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
  border-radius: var(--radius-pill);
  background: var(--accent);
  color: var(--accent-ink);
  font-weight: 800;
  line-height: 1;
  box-shadow: 0 0 0 2px var(--surface);
}
.dot {
  position: absolute;
  right: 0;
  bottom: 0;
  border-radius: 50%;
  background: var(--ok);
  box-shadow: 0 0 0 2.5px var(--surface);
}

.dot.top {
  top: 0;
  bottom: auto;
}
.initials {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-2);
  font-weight: 800;
  line-height: 1;
}
</style>
