<script setup>
import Icon from '../components/Icon.vue'

defineProps({ icon: String, title: String, text: String })
</script>

<template>
  <div class="placeholder">
    <div class="card inner">
      <span class="badge"><Icon :name="icon" :size="30" /></span>
      <h1>{{ title }}</h1>
      <p>{{ text }}</p>
    </div>
  </div>
</template>

<style scoped>
.placeholder {
  max-width: 640px;
  margin: 0 auto;
  padding: 24px 12px;
}

.inner {
  padding: 40px 24px;
  text-align: center;
}

.badge {
  display: inline-grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: var(--radius-xl);
  background: var(--accent-soft);
  color: var(--accent-text);
}

h1 {
  margin: 16px 0 6px;
  font-size: var(--fs-4xl);
}

p {
  margin: 0;
  color: var(--text-2);
}
</style>
