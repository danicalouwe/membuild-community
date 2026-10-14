<script setup>
import NotificationsPanel from '../components/NotificationsPanel.vue'

// Phones: the bell is a tab, so the list gets its own page.
</script>

<template>
  <div class="page card">
    <NotificationsPanel page />
  </div>
</template>

<style scoped>
.page {
  max-width: 680px;
  margin: 16px auto 0;
}

@media (max-width: 767px) {
  .page {
    margin: 0;
    border: 0;
    border-radius: 0;
  }
}
</style>
