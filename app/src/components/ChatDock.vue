<script setup>
import { computed, defineAsyncComponent } from 'vue'
import { useRouter } from 'vue-router'
import { cfg } from '../config.js'
import { messages } from '../stores/messages.js'
import { dock, openChat, minimizeChat, closeChat, describeChat } from '../stores/chatDock.js'
import Avatar from './Avatar.vue'
import Icon from './Icon.vue'
import ChatView from './ChatView.vue'
import MemberViewToggle from './MemberViewToggle.vue'
import { canSeeAsMember } from '../lib/preview.js'
import { t } from '../lib/i18n.js'

const NewChatDialog = defineAsyncComponent(() => import('./NewChatDialog.vue'))

// Facebook's bottom-right chat: open windows side by side, minimized chats as
// round heads stacked on the right edge, and a "New message" pencil below them.
const router = useRouter()
const windows = computed(() => dock.chats.filter((c) => !c.minimized))
const heads = computed(() => dock.chats.filter((c) => c.minimized))

const nameOf = (c) => c.title || c.others.map((o) => o.name).join(', ') || t.__('Chat', 'membuild-community')

function loaded(c, chat) {
  describeChat(c.id, { title: chat.title, type: chat.type, others: chat.members.filter((m) => m.id !== cfg.user.id && !m.left) })
}

function expand(id) {
  closeChat(id)
  router.push({ name: 'messages', params: { threadId: id } })
}

function started(chat) {
  dock.composing = false
  openChat(chat)
}

const openLabel = (c) => t.sprintf(/* translators: %s: the chat's name or the people in it */ t.__('Open chat with %s', 'membuild-community'), nameOf(c))
const closeLabel = (c) => t.sprintf(/* translators: %s: the chat's name or the people in it */ t.__('Close chat with %s', 'membuild-community'), nameOf(c))
</script>

<template>
  <div class="dock" data-chat-dock>
    <div class="windows">
      <!-- New message opens as a chat window, rightmost, like Facebook. -->
      <div v-if="dock.composing" class="window card"><NewChatDialog docked @close="dock.composing = false" @started="started" /></div>
      <div v-for="c in windows" :key="c.id" class="window card">
        <ChatView
          :thread-id="c.id"
          docked
          @loaded="loaded(c, $event)"
          @read="messages.unread = $event"
          @minimize="minimizeChat(c.id)"
          @close="closeChat(c.id)"
          @expand="expand(c.id)"
          @left="closeChat(c.id)"
        />
      </div>
    </div>

    <div class="rail">
      <!-- Admins: See it as a member, at the top of the right-hand column. -->
      <MemberViewToggle v-if="canSeeAsMember" docked :compact="windows.length > 0 || dock.composing" :class="{ mv: !(windows.length > 0 || dock.composing) }" />
      <div v-for="c in heads" :key="c.id" class="head-wrap">
        <button class="head" :aria-label="openLabel(c)" :title="nameOf(c)" @click="openChat(c)">
          <span class="faces" :class="{ group: c.others.length > 1 }">
            <Avatar v-for="o in c.others.slice(0, 2)" :key="o.id" :user="o" :size="c.others.length > 1 ? 34 : 48" :online="c.others.length === 1" />
          </span>
        </button>
        <button class="x" :aria-label="closeLabel(c)" @click="closeChat(c.id)"><Icon name="close" :size="12" /></button>
      </div>
      <button class="compose" :aria-label="t.__('New message', 'membuild-community')" :title="t.__('New message', 'membuild-community')" @click="dock.composing = true"><Icon name="compose" :size="22" /></button>
    </div>

  </div>
</template>

<style scoped>
.dock {
  position: fixed;
  right: 0;
  bottom: 0;
  /* Under the top bar (20), so its dropdowns open over chat windows. */
  z-index: 19;
  display: flex;
  align-items: flex-end;
  pointer-events: none;
}

.windows {
  position: relative;
  z-index: 2; /* an open chat sits over the See it as a member switch */
  display: flex;
  flex-direction: row-reverse;
  align-items: flex-end;
  gap: 10px;
  margin-right: 12px;
}

.window {
  width: 338px;
  height: min(455px, calc(100vh - var(--header-h) - 24px)); /* fallback for browsers without dvh */
  height: min(455px, calc(100dvh - var(--header-h) - 24px));
  border-radius: 10px 10px 0 0;
  border-bottom: 0;
  overflow: hidden;
  box-shadow: 0 12px 28px rgb(0 0 0 / 20%), 0 2px 4px rgb(0 0 0 / 10%);
  pointer-events: auto;
}

.rail {
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  gap: 10px;
  padding: 0 16px 16px 0;
  pointer-events: auto;
}

.rail:has(.pick) {
  z-index: 3; /* the See it as a member picker opens over the chat */
}

.mv {
  position: absolute !important;
  right: 16px;
  bottom: calc(100% + 10px);
}

.head-wrap {
  position: relative;
}

.head {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  box-shadow: 0 4px 12px rgb(0 0 0 / 20%);
}

.faces.group {
  position: relative;
  width: 48px;
  height: 48px;
}

.faces.group :deep(.avatar-wrap) {
  position: absolute;
}

.faces.group :deep(.avatar-wrap:first-child) {
  top: 0;
  right: 0;
}

.faces.group :deep(.avatar-wrap:nth-child(2)) {
  bottom: 0;
  left: 0;
  box-shadow: 0 0 0 2px var(--surface);
  border-radius: 50%;
}

.x {
  position: absolute;
  top: -4px;
  right: -4px;
  display: none;
  place-items: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--surface);
  color: var(--text-2);
  box-shadow: 0 1px 4px rgb(0 0 0 / 25%);
}

.head-wrap:hover .x,
.x:focus-visible {
  display: grid;
}

.compose {
  display: grid;
  place-items: center;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text);
  box-shadow: 0 4px 12px rgb(0 0 0 / 20%);
}

.compose:hover {
  background: var(--surface-2);
}
</style>
