<script setup>
/**
 * Settings: digest time, the notification pause and the uninstall opt-in.
 */

import {onMounted, ref} from 'vue';

import {api} from '../api/client.js';
import {useUiStore} from '../stores/ui.js';

const ui = useUiStore();

const settings = ref({
  digestTime: '09:00',
  notificationsPaused: false,
  deleteDataOnUninstall: false
});
const loading = ref(true);
const saving = ref(false);

/**
 * Saves the settings. Changing the digest time reschedules the cron server-side.
 *
 * @returns {Promise<void>}
 */
async function save() {
  saving.value = true;

  try {
    settings.value = await api.settings.update(settings.value);
    ui.toast('Settings saved.');
  } catch (error) {
    ui.toastError(error, 'The settings could not be saved.');
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    settings.value = await api.settings.get();
  } catch (error) {
    ui.toastError(error, 'The settings could not be loaded.');
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <form class="qa-stack" @submit.prevent="save">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>Settings</h2>
        <p class="qa-subtitle">Notification timing and what happens when the plugin is removed.</p>
      </div>
      <button type="submit" class="qa-button qa-button--primary" :disabled="saving || loading">
        {{ saving ? 'Saving…' : 'Save settings' }}
      </button>
    </div>

    <p v-if="loading" class="qa-skeleton">Loading settings…</p>

    <div v-else class="qa-card">
      <div class="qa-card__body qa-stack">
        <div class="qa-field" style="max-width: 220px">
          <label class="qa-field__label" for="digest-time">Daily digest time</label>
          <input
            id="digest-time"
            v-model="settings.digestTime"
            class="qa-input"
            type="time"
            required
          />
          <span class="qa-field__hint">
            In the site timezone. The digest only goes to people with cases still to test.
          </span>
        </div>

        <label class="qa-checkbox">
          <input v-model="settings.notificationsPaused" type="checkbox" />
          <span>
            Pause notifications
            <span class="qa-field__hint">Stops assignment emails and the daily digest.</span>
          </span>
        </label>

        <label class="qa-checkbox">
          <input v-model="settings.deleteDataOnUninstall" type="checkbox" />
          <span>
            Delete all QA data when the plugin is uninstalled
            <span class="qa-field__hint">
              Off by default. With this off, uninstalling removes the role and settings but leaves
              every run, result and issue in the database.
            </span>
          </span>
        </label>
      </div>
    </div>
  </form>
</template>
