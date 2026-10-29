<script setup>
import { ref } from 'vue'
import { api } from '../api.js'
import Icon from '../components/Icon.vue'
import LevelsCard from '../components/LevelsCard.vue'
import EarnPoints from '../components/EarnPoints.vue'
import { t } from '../lib/i18n.js'

/** Levels & points (phones): your level, every level stacked, then How to earn points. */
const data = ref(null)
api.leaderboard('all').then((d) => (data.value = d)).catch(() => {})
</script>

<template>
  <div class="lv-page page-in">
    <div class="top">
      <RouterLink :to="{ name: 'leaderboard' }" class="back-link"><Icon name="chevronLeft" :size="18" /> {{ t.__('Leaderboard', 'membuild-community') }}</RouterLink>
      <h1>{{ t.__('Levels & points', 'membuild-community') }}</h1>
    </div>
    <template v-if="data">
      <LevelsCard :levels="data.levels" :progress="data.progress" stacked />
      <EarnPoints :rules="data.rules" class="earn-flat" />
    </template>
    <div v-else class="sk-wrap" aria-hidden="true">
      <div class="skeleton" style="height: 56px; width: 60%; border-radius: 28px" />
      <div v-for="n in 6" :key="n" class="skeleton" style="height: 44px; margin-top: 14px" />
    </div>
  </div>
</template>

<style scoped>
.lv-page {
  max-width: 560px;
  margin: 0 auto;
  padding-bottom: 32px;
}

.top {
  padding: 4px 16px 14px;
  border-bottom: 1px solid var(--border);
  background: var(--surface-2);
}

.back-link {
  min-height: 52px;
  font-size: 17px;
}

h1 {
  margin: 0;
  font-size: 26px;
}

.earn-flat {
  margin: 8px 16px 0;
}

.sk-wrap {
  padding: 18px 16px;
}
</style>
