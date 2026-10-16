<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { cfg } from '../config.js'
import { eventList, loadMore, refresh } from '../stores/feed.js'
import EventCard from '../components/EventCard.vue'
import Icon from '../components/Icon.vue'
import { stateOf, zoneName } from '../lib/events.js'
import { t } from '../lib/i18n.js'
const EventDialog = defineAsyncComponent(() => import('../components/EventDialog.vue'))

// The calendar: what's coming up, and what already happened (with replays).
const when = ref('upcoming')
const list = computed(() => eventList(when.value))
watch(list, (l) => (l.loaded ? refresh(l) : loadMore(l)), { immediate: true })

const live = computed(() => list.value.items.filter((p) => stateOf(p.event) === 'live'))
const rest = computed(() => list.value.items.filter((p) => stateOf(p.event) !== 'live'))
const creating = ref(false)
function created(post) {
  // Straight into the list, soonest first.
  const l = eventList('upcoming')
  l.items = [...l.items, post].sort((a, b) => new Date(a.event.at) - new Date(b.event.at))
}

const sentinel = ref(null)
let observer = null
onMounted(() => {
  observer = new IntersectionObserver(([e]) => e.isIntersecting && loadMore(list.value), { rootMargin: '600px' })
  if (sentinel.value) observer.observe(sentinel.value)
})
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="events">
    <header class="head">
      <h1>{{ t.__('Events', 'membuild-community') }}</h1>
      <span class="zone">{{ t.sprintf(/* translators: %s: time zone, e.g. "PDT" */ t.__('Times in your time zone (%s)', 'membuild-community'), zoneName()) }}</span>
      <div class="tabs" role="tablist">
        <button role="tab" :aria-selected="when === 'upcoming'" :class="{ on: when === 'upcoming' }" @click="when = 'upcoming'">{{ t.__('Upcoming', 'membuild-community') }}</button>
        <button role="tab" :aria-selected="when === 'past'" :class="{ on: when === 'past' }" @click="when = 'past'">{{ t.__('Past', 'membuild-community') }}</button>
      </div>
      <button v-if="cfg.community.isStaff" class="btn btn-primary" @click="creating = true"><Icon name="plus" :size="16" /> {{ t.__('New event', 'membuild-community') }}</button>
    </header>

    <section v-if="live.length" class="section">
      <h2 class="live-h">● {{ t.__('Happening now', 'membuild-community') }}</h2>
      <div class="grid">
        <EventCard v-for="p in live" :key="p.id" :post="p" />
      </div>
    </section>

    <div class="grid">
      <EventCard v-for="p in rest" :key="p.id" :post="p" />
    </div>

    <div v-if="list.loading && !list.items.length" class="grid">
      <div v-for="n in 3" :key="n" class="card sk"><div class="skeleton" style="aspect-ratio: 16 / 7" /><div style="padding: 14px"><div class="skeleton" style="height: 12px; width: 40%; margin-bottom: 8px" /><div class="skeleton" style="height: 16px; width: 70%" /></div></div>
    </div>
    <div v-else-if="list.loaded && !list.items.length" class="empty card">
      <Icon name="calendar" :size="36" />
      <h2>{{ when === 'past' ? t.__('No past events yet.', 'membuild-community') : t.__('Nothing scheduled yet.', 'membuild-community') }}</h2>
      <p v-if="cfg.community.isStaff && when === 'upcoming'">{{ t.__('Add a live call, a Q&A or a workshop. Members get a reminder the day before and an hour before.', 'membuild-community') }}</p>
      <p v-else-if="when === 'upcoming'">{{ t.__('Check back soon.', 'membuild-community') }}</p>
    </div>
    <p v-if="list.error && !list.items.length" class="empty">{{ list.error }}</p>
    <div ref="sentinel" class="sentinel" aria-hidden="true" />

    <EventDialog v-if="creating" @close="creating = false" @saved="created" />
  </div>
</template>

<style scoped>
.events {
  max-width: 1080px;
  margin: 0 auto;
  padding: 20px 16px;
}

.head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 14px;
  margin-bottom: 16px;
}

.head h1 {
  margin: 0;
  font-size: 24px;
}

.zone {
  color: var(--text-3);
  font-size: 13px;
}

.tabs {
  display: flex;
  gap: 4px;
  margin-left: auto;
  padding: 3px;
  border-radius: 999px;
  background: var(--surface-2);
}

.tabs button {
  padding: 7px 14px;
  border-radius: 999px;
  font-size: 14px;
  font-weight: 650;
  color: var(--text-2);
}

.tabs button.on {
  background: var(--surface);
  color: var(--text);
  box-shadow: 0 1px 3px rgb(0 0 0 / 12%);
}

.section {
  margin-bottom: 20px;
}

.live-h {
  margin: 0 0 10px;
  font-size: 15px;
  color: #e5484d;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
}

.sk {
  overflow: hidden;
}

.empty {
  margin: 8px 0;
  padding: 40px 24px;
  text-align: center;
  color: var(--text-2);
}

.empty h2 {
  margin: 10px 0 4px;
  font-size: 18px;
  color: var(--text);
}

.empty p {
  margin: 0;
}

.sentinel {
  height: 1px;
}

@media (max-width: 640px) {
  .tabs {
    margin-left: 0;
  }

  .head .btn {
    width: 100%;
    justify-content: center;
  }
}
</style>
