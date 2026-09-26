<script setup>
/**
 * Settings: the notification pause and the uninstall opt-in.
 */

import {onMounted, ref} from 'vue';

import {api} from '../api/client.js';
import {useUiStore} from '../stores/ui.js';

const ui = useUiStore();

const settings = ref({
  notificationsPaused: false,
  deleteDataOnUninstall: false
});
const loading = ref(true);
const saving = ref(false);

/**
 * Saves the settings.
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
      </div>
      <button type="submit" class="qa-button qa-button--primary" :disabled="saving || loading">
        {{ saving ? 'Saving…' : 'Save settings' }}
      </button>
    </div>

    <p v-if="loading" class="qa-skeleton">Loading settings…</p>

    <div v-else class="qa-card">
      <div class="qa-card__body qa-stack">
        <label class="qa-checkbox">
          <input v-model="settings.notificationsPaused" type="checkbox" />
          <span>
            Pause notifications
            <span class="qa-field__hint">
              Stops the emails sent when someone assigns another person to a run or a case.
            </span>
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
