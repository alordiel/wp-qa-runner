<script setup>
/**
 * The five-button segmented status control.
 *
 * Set straight from the list: requiring a click into the detail view for every pass would
 * make a forty-case run tedious. Every button is a real button, so the whole control is
 * keyboard-navigable and each option has a visible focus ring.
 */

import {RESULT_STATUSES} from '../utils/status.js';

const props = defineProps({
  modelValue: {type: String, required: true},
  disabled: {type: Boolean, default: false},
  caseTitle: {type: String, default: ''}
});

const emit = defineEmits(['update:modelValue']);

/**
 * Emits the chosen status.
 *
 * @param {string} status Status value.
 * @returns {void}
 */
function choose(status) {
  if (props.disabled || status === props.modelValue) {
    return;
  }

  emit('update:modelValue', status);
}
</script>

<template>
  <div
    class="qa-segmented"
    role="group"
    :aria-label="caseTitle ? `Result for ${caseTitle}` : 'Result'"
  >
    <button
      v-for="status in RESULT_STATUSES"
      :key="status.value"
      type="button"
      class="qa-segmented__button"
      :class="{'is-selected': status.value === modelValue}"
      :data-tone="status.tone"
      :disabled="disabled"
      :aria-pressed="status.value === modelValue"
      :title="
        status.value === 'untested' ? 'Clear this result' : `Mark as ${status.label.toLowerCase()}`
      "
      @click="choose(status.value)"
    >
      <span class="qa-segmented__glyph" aria-hidden="true">{{ status.glyph }}</span>
      <span>{{ status.label }}</span>
    </button>
  </div>
</template>
