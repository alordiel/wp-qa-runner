<script setup>
/**
 * A read-only status pill: colour, glyph and label together, never colour alone.
 */

import {computed} from 'vue';

import {statusLabel, statusMeta} from '../utils/status.js';

const props = defineProps({
  status: {type: String, required: true}
});

const meta = computed(() => statusMeta(props.status));
const label = computed(() => statusLabel(props.status));
</script>

<template>
  <span class="qa-status" :class="`qa-status--${meta.tone}`">
    <span class="qa-status__glyph" aria-hidden="true">{{ meta.glyph }}</span>
    <span>{{ label }}</span>
  </span>
</template>
