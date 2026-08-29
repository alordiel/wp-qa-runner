/**
 * Toasts, global loading state and the expired-session banner.
 */

import {defineStore} from 'pinia';
import {ref} from 'vue';

let nextId = 1;

export const useUiStore = defineStore('ui', () => {
  const toasts = ref([]);
  const loading = ref(0);
  const sessionExpired = ref(false);

  /**
   * Shows a toast.
   *
   * @param {string} message Sentence-case message.
   * @param {'success'|'error'|'info'} [tone] Visual tone.
   * @returns {void}
   */
  function toast(message, tone = 'success') {
    const id = nextId;
    nextId += 1;

    toasts.value.push({id, message, tone});

    window.setTimeout(() => dismiss(id), tone === 'error' ? 8000 : 4000);
  }

  /**
   * Shows an error toast, preferring the server's own message.
   *
   * @param {Error} error Thrown error.
   * @param {string} [fallback] Message used when the error carries none.
   * @returns {void}
   */
  function toastError(error, fallback = 'Something went wrong. Please try again.') {
    toast(error?.message || fallback, 'error');
  }

  /**
   * Removes a toast.
   *
   * @param {number} id Toast identifier.
   * @returns {void}
   */
  function dismiss(id) {
    toasts.value = toasts.value.filter((item) => item.id !== id);
  }

  /**
   * Marks the session as expired. The banner stays until the page is reloaded.
   *
   * @returns {void}
   */
  function expireSession() {
    sessionExpired.value = true;
  }

  /**
   * Runs an async task while the global loading indicator is shown.
   *
   * @param {Function} task Async task.
   * @returns {Promise<*>}
   */
  async function withLoading(task) {
    loading.value += 1;

    try {
      return await task();
    } finally {
      loading.value -= 1;
    }
  }

  return {toasts, loading, sessionExpired, toast, toastError, dismiss, expireSession, withLoading};
});
