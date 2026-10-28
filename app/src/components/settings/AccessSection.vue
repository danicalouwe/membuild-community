<script setup>
import AccessGroups from '../AccessGroups.vue'

// Access groups are saved as you go, not with the form, so this section has no form state.
// It keeps its own few styles: the shared section styles would reach into AccessGroups.
</script>

<template>
  <section id="s-access" class="card group">
    <AccessGroups />
  </section>
</template>

<style scoped>
.group {
  padding: 20px 22px;
  scroll-margin-top: calc(var(--header-h) + 16px);
}

@media (max-width: 767px) {
  .group {
    border-radius: 0;
    border-left: 0;
    border-right: 0;
    padding: 18px 16px;
  }
}
</style>
