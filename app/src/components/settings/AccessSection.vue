<script setup>
import AccessGroups from '../AccessGroups.vue'
import { t } from '../../lib/i18n.js'

// Access groups are saved as you go, not with the form, so this section has no form state.
// It keeps its own few styles: the shared section styles would reach into AccessGroups.
</script>

<template>
  <section id="s-access" class="card group">
    <h2>{{ t.__('Member groups', 'membuild-community') }}</h2>
    <p class="intro">{{ t.__('Use groups to give some members access to certain courses, for example paying members. Add people by hand, or link a WordPress role so your membership plugin keeps the group up to date. Admins and moderators can open every course.', 'membuild-community') }}</p>
    <AccessGroups />
  </section>
</template>

<style scoped>
.group {
  padding: 20px 22px;
  scroll-margin-top: calc(var(--header-h) + 16px);
}

.group h2 {
  margin: 0 0 14px;
  font-size: 19px;
}

.intro {
  margin: -6px 0 14px;
  color: var(--text-2);
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
