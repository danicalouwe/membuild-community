<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { cfg } from '../config.js'
import { eventList, loadMore, refresh } from '../stores/feed.js'
import EventRow from '../components/EventRow.vue'
import Icon from '../components/Icon.vue'
import { monthOf, stateOf } from '../lib/events.js'
import { useRouter } from 'vue-router'
import { t } from '../lib/i18n.js'
const EventForm = defineAsyncComponent(() => import('../components/EventForm.vue'))

// The calendar, as a list grouped by month: what's coming up (soonest first), and what
// already happened (newest first, with replays). Owners also see how many are going.
const when = ref('upcoming')
const list = computed(() => eventList(when.value))
watch(list, (l) => (l.loaded ? refresh(l) : loadMore(l)), { immediate: true })

const owner = !!cfg.community.isStaff
const router = useRouter()
const live = computed(() => list.value.items.filter((p) => stateOf(p.event) === 'live'))
// Months in order, each with its events: [{ month, items }].
const months = computed(() => {
  const out = []
  for (const p of list.value.items) {
    if (stateOf(p.event) === 'live') continue
    const m = monthOf(p.event)
    if (out.at(-1)?.month !== m) out.push({ month: m, items: [] })
    out.at(-1).items.push(p)
  }
  return out
})
// "+ Add the replay" on a past event: its page asks for the link.
const addReplay = (p) => router.push({ name: 'event', params: { id: p.id }, query: { replay: 1 } })
const creating = ref(false)
function created(post) {
  const l = eventList('upcoming')
  // A repeating event made several: load the list again. One: straight in, soonest first.
  if (post.event?.series) return refresh(l)
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
  <EventForm v-if="creating" @close="creating = false" @saved="created" />
  <div v-else class="events page-in">
    <header class="head">
      <h1>{{ t.__('Events', 'membuild-community') }}</h1>
      <div class="tabs" role="tablist">
        <button class="filter-pill" role="tab" :aria-selected="when === 'upcoming'" :class="{ on: when === 'upcoming' }" @click="when = 'upcoming'">{{ t.__('Upcoming', 'membuild-community') }}</button>
        <button class="filter-pill" role="tab" :aria-selected="when === 'past'" :class="{ on: when === 'past' }" @click="when = 'past'">{{ t.__('Past', 'membuild-community') }}</button>
      </div>
      <button v-if="owner" class="btn btn-primary new" @click="creating = true"><Icon name="plus" :size="16" /> {{ t.__('New event', 'membuild-community') }}</button>
    </header>

    <section v-if="live.length && when === 'upcoming'" class="month">
      <h2 class="live-h">● {{ t.__('Happening now', 'membuild-community') }}</h2>
      <EventRow v-for="(p, i) in live" :key="p.id" class="rise-in" :style="{ '--i': i }" :post="p" :owner="owner" />
    </section>

    <section v-for="g in months" :key="g.month" class="month">
      <h2>{{ g.month }}</h2>
      <EventRow v-for="(p, i) in g.items" :key="p.id" class="rise-in" :style="{ '--i': i }" :post="p" :owner="owner" @add-replay="addReplay" />
    </section>

    <div v-if="list.loading && !list.items.length" class="month">
      <div v-for="n in 3" :key="n" class="card sk"><div class="skeleton" style="width: 208px; aspect-ratio: 16 / 9; border-radius: 10px" /><div style="flex: 1"><div class="skeleton" style="height: 12px; width: 30%; margin-bottom: 10px" /><div class="skeleton" style="height: 18px; width: 70%" /></div></div>
    </div>
    <div v-else-if="list.loaded && !list.items.length" class="empty-state card">
      <span class="empty-state-icon" aria-hidden="true"><Icon name="calendar" :size="28" /></span>
      <h2 class="empty-state-title">{{ when === 'past' ? t.__('No past events yet.', 'membuild-community') : t.__('Nothing scheduled yet.', 'membuild-community') }}</h2>
      <p class="empty-state-text" v-if="cfg.community.isStaff && when === 'upcoming'">{{ t.__('Add a live call, a Q&A or a workshop. Members get a reminder the day before and an hour before.', 'membuild-community') }}</p>
      <p class="empty-state-text" v-else-if="when === 'upcoming'">{{ t.__('Check back soon.', 'membuild-community') }}</p>
    </div>
    <p v-if="list.error && !list.items.length" class="error-text list-error" role="alert">{{ list.error }}</p>
    <div ref="sentinel" class="sentinel" aria-hidden="true" />

  </div>
</template>

<style scoped>
.list-error {
  margin: 8px 0;
  padding: 24px;
  text-align: center;
}

.events {
  max-width: 808px;
  margin: 0 auto;
  padding: 20px 16px 40px;
}

.head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 20px;
}

.head h1 {
  margin: 0 auto 0 0;
  font-size: var(--fs-6xl);
}

.tabs {
  display: flex;
  gap: 6px;
}

.new {
  margin-left: 12px;
}

.month {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 26px;
}

.month h2 {
  margin: 0;
  color: var(--text-2);
  font-size: var(--fs-md);
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.month h2.live-h {
  color: var(--live);
  letter-spacing: 0;
  text-transform: none;
  font-size: var(--fs-base);
}

.sk {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 14px;
}

.sentinel {
  height: 1px;
}

@media (max-width: 600px) {
  .head {
    flex-wrap: wrap;
  }

  .new {
    width: 100%;
    margin-left: 0;
    justify-content: center;
  }
}

</style>
