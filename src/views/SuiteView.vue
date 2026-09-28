<script setup>
/**
 * Suite management.
 */

import {onMounted, ref} from 'vue';

import EmptyState from '../components/EmptyState.vue';
import {useCaseStore} from '../stores/cases.js';
import {useUiStore} from '../stores/ui.js';

const caseStore = useCaseStore();
const ui = useUiStore();

const loading = ref(true);
const draft = ref({name: '', description: ''});
const saving = ref(false);
const editingId = ref(0);
const editDraft = ref({name: '', description: '', sort_order: 0});
const movingId = ref(0);
const moveTarget = ref('');
const moving = ref(false);

/**
 * Creates a suite.
 *
 * @returns {Promise<void>}
 */
async function create() {
  if (!draft.value.name.trim()) {
    return;
  }

  saving.value = true;

  try {
    await caseStore.createSuite({...draft.value, sort_order: caseStore.suites.length});
    draft.value = {name: '', description: ''};
    ui.toast(wp.i18n.__('Suite created.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The suite could not be created.', 'mandragora-qa-test-manager'));
  } finally {
    saving.value = false;
  }
}

/**
 * Opens the inline editor for a suite.
 *
 * @param {Object} suite Suite row.
 * @returns {void}
 */
function startEdit(suite) {
  editingId.value = suite.id;
  editDraft.value = {
    name: suite.name,
    description: suite.description,
    sort_order: suite.sort_order
  };
}

/**
 * Saves the inline edit.
 *
 * @returns {Promise<void>}
 */
async function saveEdit() {
  try {
    await caseStore.updateSuite(editingId.value, editDraft.value);
    editingId.value = 0;
    ui.toast(wp.i18n.__('Suite saved.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The suite could not be saved.', 'mandragora-qa-test-manager'));
  }
}

/**
 * The suites an archived case could be moved into.
 *
 * @param {Object} suite Suite being deleted.
 * @returns {Array} Every other suite.
 */
function moveTargets(suite) {
  return caseStore.suites.filter((item) => item.id !== suite.id);
}

/**
 * Deletes a suite that has no live cases.
 *
 * Archived cases that past runs still reference have to keep a suite, so those are moved
 * first; the rest go with the suite.
 *
 * @param {Object} suite Suite row.
 * @returns {Promise<void>}
 */
async function remove(suite) {
  if (suite.retained_case_count > 0) {
    movingId.value = suite.id;
    moveTarget.value = String(moveTargets(suite)[0]?.id ?? '');

    return;
  }

  const archived = suite.archived_case_count ?? 0;
  const question = archived
    ? wp.i18n.sprintf(
        /* translators: 1: suite name, 2: number of archived cases. */
        wp.i18n._n(
          'Delete the suite "%1$s"? Its %2$d archived case will be deleted with it.',
          'Delete the suite "%1$s"? Its %2$d archived cases will be deleted with it.',
          archived,
          'mandragora-qa-test-manager'
        ),
        suite.name,
        archived
      )
    : wp.i18n.sprintf(
        /* translators: %s: suite name. */
        wp.i18n.__('Delete the suite "%s"?', 'mandragora-qa-test-manager'),
        suite.name
      );

  if (!window.confirm(question)) {
    return;
  }

  try {
    await caseStore.deleteSuite(suite.id);
    ui.toast(wp.i18n.__('Suite deleted.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The suite could not be deleted.', 'mandragora-qa-test-manager'));
  }
}

/**
 * Moves the suite's archived cases into the chosen suite, then deletes it.
 *
 * @param {Object} suite Suite row.
 * @returns {Promise<void>}
 */
async function confirmMove(suite) {
  moving.value = true;

  try {
    await caseStore.deleteSuite(suite.id, Number(moveTarget.value));
    movingId.value = 0;
    ui.toast(wp.i18n.__('Archived cases moved and suite deleted.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The suite could not be deleted.', 'mandragora-qa-test-manager'));
  } finally {
    moving.value = false;
  }
}

onMounted(async () => {
  try {
    await caseStore.loadSuites(true);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The suites could not be loaded.', 'mandragora-qa-test-manager'));
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="qa-stack">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>{{ __('Suites', 'mandragora-qa-test-manager') }}</h2>
      </div>
    </div>

    <form class="qa-card" @submit.prevent="create">
      <div class="qa-card__head">
        <h3>{{ __('New suite', 'mandragora-qa-test-manager') }}</h3>
      </div>
      <div class="qa-card__body qa-inline-form">
        <div class="qa-field" style="flex: 1; min-width: 180px">
          <label class="qa-field__label" for="suite-name">{{ __('Name', 'mandragora-qa-test-manager') }}</label>
          <input
            id="suite-name"
            v-model="draft.name"
            class="qa-input"
            type="text"
            :placeholder="__('Checkout', 'mandragora-qa-test-manager')"
            required
          />
        </div>
        <div class="qa-field" style="flex: 2; min-width: 220px">
          <label class="qa-field__label" for="suite-description">{{
            __('Description', 'mandragora-qa-test-manager')
          }}</label>
          <input
            id="suite-description"
            v-model="draft.description"
            class="qa-input"
            type="text"
            :placeholder="__('What this area covers', 'mandragora-qa-test-manager')"
          />
        </div>
        <button type="submit" class="qa-button qa-button--primary" :disabled="saving">
          {{ saving ? __('Adding…', 'mandragora-qa-test-manager') : __('Add suite', 'mandragora-qa-test-manager') }}
        </button>
      </div>
    </form>

    <div class="qa-card">
      <p v-if="loading" class="qa-skeleton">{{ __('Loading suites…', 'mandragora-qa-test-manager') }}</p>

      <EmptyState
        v-else-if="!caseStore.suites.length"
        :title="__('No suites yet. Add one above to start grouping cases.', 'mandragora-qa-test-manager')"
      />

      <div v-else class="qa-table-scroll">
        <table class="qa-table">
          <thead>
            <tr>
              <th scope="col">{{ __('Name', 'mandragora-qa-test-manager') }}</th>
              <th scope="col">{{ __('Description', 'mandragora-qa-test-manager') }}</th>
              <th scope="col">{{ __('Cases', 'mandragora-qa-test-manager') }}</th>
              <th scope="col" />
            </tr>
          </thead>
          <tbody>
            <template v-for="suite in caseStore.suites" :key="suite.id">
              <tr>
                <template v-if="editingId === suite.id">
                  <td>
                    <input
                      v-model="editDraft.name"
                      class="qa-input"
                      type="text"
                      :aria-label="__('Suite name', 'mandragora-qa-test-manager')"
                    />
                  </td>
                  <td>
                    <input
                      v-model="editDraft.description"
                      class="qa-input"
                      type="text"
                      :aria-label="__('Suite description', 'mandragora-qa-test-manager')"
                    />
                  </td>
                  <td class="qa-count">{{ suite.case_count }}</td>
                  <td>
                    <div class="qa-row">
                      <button
                        type="button"
                        class="qa-button qa-button--small qa-button--primary"
                        @click="saveEdit"
                      >
                        {{ __('Save', 'mandragora-qa-test-manager') }}
                      </button>
                      <button
                        type="button"
                        class="qa-button qa-button--small qa-button--quiet"
                        @click="editingId = 0"
                      >
                        {{ __('Cancel', 'mandragora-qa-test-manager') }}
                      </button>
                    </div>
                  </td>
                </template>
                <template v-else>
                  <td>{{ suite.name }}</td>
                  <td class="qa-muted">{{ suite.description || '—' }}</td>
                  <td class="qa-count">
                    {{ suite.case_count }}
                    <span v-if="suite.archived_case_count" class="qa-muted">
                      {{ sprintf(__('+ %d archived', 'mandragora-qa-test-manager'), suite.archived_case_count) }}
                    </span>
                  </td>
                  <td>
                    <div class="qa-row">
                      <button
                        type="button"
                        class="qa-button qa-button--small"
                        @click="startEdit(suite)"
                      >
                        {{ __('Edit', 'mandragora-qa-test-manager') }}
                      </button>
                      <button
                        type="button"
                        class="qa-button qa-button--small qa-button--danger"
                        :disabled="suite.case_count > 0"
                        :title="
                          suite.case_count > 0
                            ? __('Archive or move this suite’s cases first.', 'mandragora-qa-test-manager')
                            : __('Delete this suite', 'mandragora-qa-test-manager')
                        "
                        @click="remove(suite)"
                      >
                        {{ __('Delete', 'mandragora-qa-test-manager') }}
                      </button>
                    </div>
                  </td>
                </template>
              </tr>

              <tr v-if="movingId === suite.id">
                <td colspan="4">
                  <div class="qa-inline-form">
                    <p class="qa-muted" style="flex: 1; min-width: 220px; margin: 0">
                      {{
                        sprintf(
                          _n(
                            '%d archived case here still appears in past runs, so it needs a suite to stay in. Move it to:',
                            '%d archived cases here still appear in past runs, so they need a suite to stay in. Move them to:',
                            suite.retained_case_count,
                            'mandragora-qa-test-manager'
                          ),
                          suite.retained_case_count
                        )
                      }}
                    </p>

                    <template v-if="moveTargets(suite).length">
                      <label class="qa-sr-only" :for="`move-target-${suite.id}`">
                        {{ __('Destination suite', 'mandragora-qa-test-manager') }}
                      </label>
                      <select
                        :id="`move-target-${suite.id}`"
                        v-model="moveTarget"
                        class="qa-select"
                        style="width: auto"
                      >
                        <option
                          v-for="target in moveTargets(suite)"
                          :key="target.id"
                          :value="String(target.id)"
                        >
                          {{ target.name }}
                        </option>
                      </select>
                      <button
                        type="button"
                        class="qa-button qa-button--small qa-button--danger"
                        :disabled="!moveTarget || moving"
                        @click="confirmMove(suite)"
                      >
                        {{
                          moving
                            ? __('Moving…', 'mandragora-qa-test-manager')
                            : __('Move & delete suite', 'mandragora-qa-test-manager')
                        }}
                      </button>
                    </template>
                    <span v-else class="qa-muted">
                      {{
                        __('Add another suite first — there is nowhere to move them.', 'mandragora-qa-test-manager')
                      }}
                    </span>

                    <button
                      type="button"
                      class="qa-button qa-button--small qa-button--quiet"
                      @click="movingId = 0"
                    >
                      {{ __('Cancel', 'mandragora-qa-test-manager') }}
                    </button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
