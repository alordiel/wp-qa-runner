<script setup>
/**
 * Settings: the notification pause and the uninstall opt-in, plus — for site
 * administrators only — the QA team.
 */

import {onMounted, ref} from 'vue';

import TeamPanel from '../components/TeamPanel.vue';
import {api, bootstrap} from '../api/client.js';
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
    ui.toast(wp.i18n.__('Settings saved.', 'qa-runner'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The settings could not be saved.', 'qa-runner'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    settings.value = await api.settings.get();
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The settings could not be loaded.', 'qa-runner'));
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="qa-stack">
    <form class="qa-stack" @submit.prevent="save">
      <div class="qa-page-head">
        <div class="qa-page-head__meta">
          <h2>{{ __('Settings', 'qa-runner') }}</h2>
        </div>
        <button type="submit" class="qa-button qa-button--primary" :disabled="saving || loading">
          {{ saving ? __('Saving…', 'qa-runner') : __('Save settings', 'qa-runner') }}
        </button>
      </div>

      <p v-if="loading" class="qa-skeleton">{{ __('Loading settings…', 'qa-runner') }}</p>

      <div v-else class="qa-card">
        <div class="qa-card__body qa-stack">
          <label class="qa-checkbox">
            <input v-model="settings.notificationsPaused" type="checkbox" />
            <span>
              {{ __('Pause notifications', 'qa-runner') }}
              <span class="qa-field__hint">
                {{
                  __(
                    'Stops the emails sent when someone assigns another person to a run or a case.',
                    'qa-runner'
                  )
                }}
              </span>
            </span>
          </label>

          <label class="qa-checkbox">
            <input v-model="settings.deleteDataOnUninstall" type="checkbox" />
            <span>
              {{ __('Delete all QA data when the plugin is uninstalled', 'qa-runner') }}
              <span class="qa-field__hint">
                {{
                  __(
                    'Off by default. With this off, uninstalling removes the roles and settings but leaves every run, result and issue in the database.',
                    'qa-runner'
                  )
                }}
              </span>
            </span>
          </label>
        </div>
      </div>
    </form>

    <TeamPanel v-if="bootstrap.caps?.manageTeam" />
  </div>
</template>
