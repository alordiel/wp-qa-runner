<script setup>
/**
 * Priority indicator. The dot is decorative; the accessible name carries the meaning.
 */

import {computed} from 'vue';

import {PRIORITIES} from '../utils/status.js';

const props = defineProps({
  priority: {type: String, required: true}
});

const label = computed(
  () => PRIORITIES.find((item) => item.value === props.priority)?.label ?? 'Normal'
);
</script>

<template>
  <span class="qa-row" style="gap: 5px">
    <span class="qa-priority" :class="`qa-priority--${priority}`" aria-hidden="true" />
    <span class="qa-sr-only">{{ label }} priority</span>
  </span>
</template>
