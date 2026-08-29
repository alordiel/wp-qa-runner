<script setup>
/**
 * An empty state that says what to do next, rather than "No data found".
 */

defineProps({
  title: {type: String, required: true},
  description: {type: String, default: ''}
});
</script>

<template>
  <div class="qa-empty">
    <p class="qa-empty__title">{{ title }}</p>
    <p v-if="description">{{ description }}</p>
    <slot />
  </div>
</template>
