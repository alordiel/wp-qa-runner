<script setup>
/**
 * The toast region and the persistent expired-session banner.
 */

import {useUiStore} from '../stores/ui.js';

const ui = useUiStore();
</script>

<template>
  <div class="qa-toasts" role="region" aria-label="Notifications">
    <div aria-live="polite" aria-atomic="false" class="qa-stack qa-stack--tight">
      <div
        v-for="toast in ui.toasts"
        :key="toast.id"
        class="qa-toast"
        :class="`qa-toast--${toast.tone}`"
      >
        <span class="qa-toast__message">{{ toast.message }}</span>
        <button
          type="button"
          class="qa-toast__dismiss"
          aria-label="Dismiss notification"
          @click="ui.dismiss(toast.id)"
        >
          ×
        </button>
      </div>
    </div>
  </div>
</template>
