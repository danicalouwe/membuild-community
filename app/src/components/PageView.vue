<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { cfg } from '../config.js'
import { asMember } from '../lib/preview.js'
import { pageProps, pageSlotFor } from '../lib/slots.js'
import ThemeSlot from './ThemeSlot.vue'

/**
 * The page that's open now, in its page slot (page.home, page.classroom…, lib/slots.js) when it
 * has one. With no theme it's the router's page as before. The owner's own view (not using
 * "See it as a member") always keeps the community's pages, and an event's host keeps theirs.
 */
const route = useRoute()
const owner = computed(() => !!cfg.community.canManage && !asMember.value)
const host = computed(() => !!cfg.community.isStaff && !asMember.value)
const slot = computed(() => {
  const name = pageSlotFor(route, owner.value)
  return name === 'page.event' && host.value ? '' : name
})

// Pages kept in memory (the last five): coming back shows them at once, where you were, and
// they refresh quietly. Only lists and sections; forms, editors, detail pages, Reels (its videos),
// Messages (its open chat keeps listening), Search and Settings start fresh each time.
const KEPT = ['FeedView', 'PlacesView', 'ClassroomView', 'EventsView', 'MembersView', 'LeaderboardView']
</script>

<template>
  <RouterView v-slot="{ Component }">
    <ThemeSlot :name="slot || 'page.none'" :off="!slot" :data="pageProps(route)">
      <KeepAlive :include="KEPT" :max="5">
        <component :is="Component" />
      </KeepAlive>
    </ThemeSlot>
  </RouterView>
</template>
