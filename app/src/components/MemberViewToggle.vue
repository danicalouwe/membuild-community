<script setup>
import { computed, onMounted, ref } from 'vue'
import { showToast } from '../lib/toast.js'
import Icon from './Icon.vue'
import PopFrame from './PopFrame.vue'
import { asMember, memberPick, seeAs } from '../lib/preview.js'
import { cfg, levelLabel } from '../config.js'
import { t } from '../lib/i18n.js'

// The admin's floating switch, on the right of every page. It asks which member to be
// (a membership or none, and a level), then the whole community is that member's view
// until Back to your view (dark, so it's clear).
defineProps({
  docked: { type: Boolean, default: false }, // in the chat dock's column, or on its own
  compact: { type: Boolean, default: false }, // a chat window is open beside it: just the eye, round
})
// Always just a round eye, like the pencil: white in your own view; dark with the level in a
// small lime badge while you're seeing it as a member, so the two are told apart at a glance.
const round = true

// Just after switching: one short note saying how to get back.
onMounted(() => {
  let note = false
  try {
    note = sessionStorage.getItem('mbcom-as-member-note') === '1'
    sessionStorage.removeItem('mbcom-as-member-note')
  } catch {
    /* private mode */
  }
  if (note && asMember.value && memberPick) {
    showToast(t.sprintf(/* translators: %d: level number */ t.__('You’re seeing it as a member at Level %d. Press the dark eye to go back.', 'membuild-community'), memberPick.level), '', { icon: 'eye' })
  }
})

const memberships = cfg.community.memberships || []
const levels = (cfg.community.levelNames || []).map((_, i) => i + 1)
const open = ref(false)
const group = ref(0)
const level = ref(1)
// The small label above the button: who you are right now.
const youAre = computed(() => {
  if (!memberPick) return t.__('Admin', 'membuild-community')
  const g = memberships.find((m) => m.id === memberPick.group)
  return [t.__('Member', 'membuild-community'), g?.name, t.sprintf(/* translators: %d: level number */ t.__('Level %d', 'membuild-community'), memberPick.level)].filter(Boolean).join(' · ')
})
const who = computed(() => {
  if (!memberPick) return ''
  const g = memberships.find((m) => m.id === memberPick.group)
  return `${g ? g.name : t.__('No membership', 'membuild-community')} · ${levelLabel(memberPick.level)}`
})
function press() {
  if (asMember.value) asMember.value = false
  else open.value = !open.value
}
</script>

<template>
  <div class="mvt-wrap" :class="{ docked, compact: round }">
    <PopFrame v-if="open" class="pick" :title="t.__('See it as a member', 'membuild-community')" @close="open = false">
      <div class="pick-body">
        <h3>{{ t.__('See it as a member', 'membuild-community') }}</h3>
        <p class="lbl">{{ t.__('With', 'membuild-community') }}</p>
        <label class="opt" :class="{ on: group === 0 }"><input v-model="group" type="radio" :value="0" /> {{ t.__('No membership', 'membuild-community') }}</label>
        <label v-for="m in memberships" :key="m.id" class="opt" :class="{ on: group === m.id }"><input v-model="group" type="radio" :value="m.id" /> {{ m.name }}</label>
        <label class="lbl" for="mvt-level">{{ t.__('At', 'membuild-community') }}</label>
        <select id="mvt-level" v-model.number="level">
          <option v-for="n in levels" :key="n" :value="n">{{ levelLabel(n) }}</option>
        </select>
        <button class="btn btn-lg btn-primary go" @click="seeAs(group, level)"><Icon name="eye" :size="18" /> {{ t.__('See it', 'membuild-community') }}</button>
      </div>
    </PopFrame>
    <button class="mvt" :class="{ on: asMember }" data-pop-trigger :aria-expanded="asMember ? undefined : open" :aria-label="round ? `${asMember ? t.__('Back to your view', 'membuild-community') : t.__('See it as a member', 'membuild-community')} (${t.__('You’re:', 'membuild-community')} ${youAre})` : undefined" :title="`${asMember ? t.__('Back to your view', 'membuild-community') : t.__('See it as a member', 'membuild-community')} · ${t.__('You’re:', 'membuild-community')} ${youAre}`" @click="press">
      <Icon name="eye" :size="22" />
      <span v-if="asMember && memberPick" class="lvl" aria-hidden="true">{{ memberPick.level }}</span>
      <span class="txt" :class="{ 'sr-only': round }" :aria-hidden="round ? 'true' : undefined">
        <small>{{ t.__('You’re:', 'membuild-community') }} <b>{{ youAre }}</b></small>
        <span>{{ asMember ? t.__('Back to your view', 'membuild-community') : t.__('See it as a member', 'membuild-community') }}</span>
      </span>
    </button>
  </div>
</template>

<style scoped>
.mvt-wrap {
  position: relative;
  pointer-events: auto;
}

/* On its own (no chat dock, e.g. phones): right side, above the tab bar. */
.mvt-wrap:not(.docked) {
  position: fixed;
  right: 16px;
  bottom: 20px;
  z-index: 40;
}

.mvt {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  min-height: 60px;
  padding: 8px 24px 8px 20px;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
  font-size: 17px;
  font-weight: 700;
  white-space: nowrap;
  box-shadow: 0 8px 24px rgb(0 0 0 / 16%);
}

.mvt.on {
  border-color: transparent;
  background: var(--text);
  color: var(--surface);
}

/* Two lines in the one button: who you are (small), then what it does. */
.txt {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.25;
  text-align: left;
}

.txt small {
  max-width: 260px;
  overflow: hidden;
  color: var(--text-2);
  font-size: 13px;
  font-weight: 500;
  text-overflow: ellipsis;
}

.txt small b {
  color: var(--text);
  font-weight: 700;
}

.on .txt small,
.on .txt small b {
  color: var(--accent);
}

/* The picker opens above the button, right-aligned. */
.pick {
  left: auto !important;
  right: 0 !important;
  width: 300px;
  max-height: none !important;
}

.pick-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
}

.pick-body h3 {
  margin: 0 0 4px;
  font-size: 17px;
}

.lbl {
  margin: 6px 0 0;
  color: var(--text-2);
  font-size: 14px;
  font-weight: 700;
}

.opt {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 44px;
  padding: 0 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  font-weight: 600;
  cursor: pointer;
}

.opt.on {
  border: 2px solid var(--accent);
  background: var(--accent-soft);
}

.opt input {
  width: 18px;
  height: 18px;
  accent-color: var(--text);
}

select {
  min-height: 44px;
  padding: 0 12px;
  border: 1px solid var(--border-strong);
  border-radius: 12px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
}

.go {
  margin-top: 8px;
  justify-content: center;
}

@media (max-width: 767px) {
  .mvt-wrap:not(.docked) {
    right: 12px;
    bottom: calc(var(--tabbar-h) + 14px + env(safe-area-inset-bottom));
  }

  .mvt {
    min-height: 54px;
    padding: 6px 18px 6px 16px;
    font-size: 16px;
  }
}

/* Beside an open chat: a round eye button in the dock's column, like the pencil. */
.mvt {
  position: relative;
}

/* The level you're seeing it at, on the dark eye. */
.lvl {
  position: absolute;
  top: -4px;
  right: -4px;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border-radius: 999px;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: 12px;
  font-weight: 800;
  line-height: 22px;
  text-align: center;
  box-shadow: 0 0 0 2px var(--surface);
}

.compact .mvt {
  justify-content: center;
  width: 52px;
  min-height: 52px;
  height: 52px;
  padding: 0;
}
</style>
