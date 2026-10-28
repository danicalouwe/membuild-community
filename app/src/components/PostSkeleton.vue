<script setup>
/** A post in grey while the real ones load (Home, profiles): photo, name, two lines, a picture. */
defineProps({ picture: { type: Boolean, default: true } })
</script>

<template>
  <div class="card post-sk" aria-hidden="true">
    <div class="row">
      <div class="skeleton" style="width: 40px; height: 40px; border-radius: 50%" />
      <div style="flex: 1">
        <div class="skeleton" style="width: 40%; height: 12px; margin-bottom: 8px" />
        <div class="skeleton" style="width: 20%; height: 10px" />
      </div>
    </div>
    <div class="skeleton" style="height: 14px; margin: 14px 0 8px" />
    <div class="skeleton" style="height: 14px; width: 70%" />
    <div v-if="picture" class="skeleton" style="height: 220px; margin-top: 14px; border-radius: 12px" />
  </div>
</template>

<style scoped>
.post-sk {
  padding: 14px;
}

.row {
  display: flex;
  gap: 10px;
  align-items: center;
}
</style>
