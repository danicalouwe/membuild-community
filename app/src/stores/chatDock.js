import { reactive, watch } from 'vue'

/**
 * Facebook-style chat windows docked at the bottom right (desktop only).
 * Up to MAX_OPEN windows are expanded; the rest wait as round heads on the
 * right edge. Kept per tab in sessionStorage so a reload keeps the same chats.
 */
const MAX_OPEN = 2
const MAX_HEADS = 6
const KEY = 'mbcom-chat-dock'

function restore() {
  try {
    return JSON.parse(sessionStorage.getItem(KEY)) || []
  } catch {
    return []
  }
}

// Each chat: { id, title, type, others, minimized }. Newest first.
export const dock = reactive({ chats: restore(), composing: false })

watch(
  () => dock.chats,
  (chats) => {
    try {
      sessionStorage.setItem(KEY, JSON.stringify(chats))
    } catch {
      /* private mode: the dock just won't survive a reload */
    }
  },
  { deep: true },
)

/** Open (or bring forward) a chat window. `thread` is an inbox row or a new chat. */
export function openChat(thread) {
  const existing = dock.chats.find((c) => c.id === thread.id)
  const chat = existing || { id: thread.id, title: thread.title, type: thread.type, others: thread.others || [], minimized: false }
  dock.chats = [{ ...chat, minimized: false }, ...dock.chats.filter((c) => c.id !== thread.id)].slice(0, MAX_HEADS)
  // Too many expanded windows: the oldest ones fold into heads.
  let shown = 0
  for (const c of dock.chats) if (!c.minimized && ++shown > MAX_OPEN) c.minimized = true
}

export function minimizeChat(id) {
  const c = dock.chats.find((x) => x.id === id)
  if (c) c.minimized = true
}

export function closeChat(id) {
  dock.chats = dock.chats.filter((c) => c.id !== id)
}

/** Keep the head's picture and name in step with what the chat window loaded. */
export function describeChat(id, info) {
  const c = dock.chats.find((x) => x.id === id)
  if (c) Object.assign(c, info)
}

/** Docked windows are a desktop thing; phones open the Messages page. */
export const canDock = () => window.matchMedia('(min-width: 768px)').matches
