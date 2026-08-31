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
    ui.toast('Suite created.');
  } catch (error) {
    ui.toastError(error, 'The suite could not be created.');
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
    ui.toast('Suite saved.');
  } catch (error) {
    ui.toastError(error, 'The suite could not be saved.');
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
  const warning = archived
    ? ` Its ${archived} archived ${archived === 1 ? 'case' : 'cases'} will be deleted with it.`
    : '';

  if (!window.confirm(`Delete the suite "${suite.name}"?${warning}`)) {
    return;
  }

  try {
    await caseStore.deleteSuite(suite.id);
    ui.toast('Suite deleted.');
  } catch (error) {
    ui.toastError(error, 'The suite could not be deleted.');
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
    ui.toast('Archived cases moved and suite deleted.');
  } catch (error) {
    ui.toastError(error, 'The suite could not be deleted.');
  } finally {
    moving.value = false;
  }
}

onMounted(async () => {
  try {
    await caseStore.loadSuites(true);
  } catch (error) {
    ui.toastError(error, 'The suites could not be loaded.');
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="qa-stack">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>Suites</h2>
        <p class="qa-subtitle">Suites group related cases — Login, Account, Checkout.</p>
      </div>
    </div>

    <form class="qa-card" @submit.prevent="create">
      <div class="qa-card__head"><h3>New suite</h3></div>
      <div class="qa-card__body qa-inline-form">
        <div class="qa-field" style="flex: 1; min-width: 180px">
          <label class="qa-field__label" for="suite-name">Name</label>
          <input
            id="suite-name"
            v-model="draft.name"
            class="qa-input"
            type="text"
            placeholder="Checkout"
            required
          />
        </div>
        <div class="qa-field" style="flex: 2; min-width: 220px">
          <label class="qa-field__label" for="suite-description">Description</label>
          <input
            id="suite-description"
            v-model="draft.description"
            class="qa-input"
            type="text"
            placeholder="What this area covers"
          />
        </div>
        <button type="submit" class="qa-button qa-button--primary" :disabled="saving">
          {{ saving ? 'Adding…' : 'Add suite' }}
        </button>
      </div>
    </form>

    <div class="qa-card">
      <p v-if="loading" class="qa-skeleton">Loading suites…</p>

      <EmptyState
        v-else-if="!caseStore.suites.length"
        title="No suites yet. Add one above to start grouping cases."
      />

      <div v-else class="qa-table-scroll">
        <table class="qa-table">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Description</th>
              <th scope="col">Cases</th>
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
                      aria-label="Suite name"
                    />
                  </td>
                  <td>
                    <input
                      v-model="editDraft.description"
                      class="qa-input"
                      type="text"
                      aria-label="Suite description"
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
                        Save
                      </button>
                      <button
                        type="button"
                        class="qa-button qa-button--small qa-button--quiet"
                        @click="editingId = 0"
                      >
                        Cancel
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
                      + {{ suite.archived_case_count }} archived
                    </span>
                  </td>
                  <td>
                    <div class="qa-row">
                      <button
                        type="button"
                        class="qa-button qa-button--small"
                        @click="startEdit(suite)"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        class="qa-button qa-button--small qa-button--danger"
                        :disabled="suite.case_count > 0"
                        :title="
                          suite.case_count > 0
                            ? 'Archive or move this suite\'s cases first.'
                            : 'Delete this suite'
                        "
                        @click="remove(suite)"
                      >
                        Delete
                      </button>
                    </div>
                  </td>
                </template>
              </tr>

              <tr v-if="movingId === suite.id">
                <td colspan="4">
                  <div class="qa-inline-form">
                    <p class="qa-muted" style="flex: 1; min-width: 220px; margin: 0">
                      {{ suite.retained_case_count }} archived
                      {{ suite.retained_case_count === 1 ? 'case' : 'cases' }} here still
                      {{ suite.retained_case_count === 1 ? 'appears' : 'appear' }} in past runs, so
                      {{ suite.retained_case_count === 1 ? 'it needs' : 'they need' }} a suite to
                      stay in. Move {{ suite.retained_case_count === 1 ? 'it' : 'them' }} to:
                    </p>

                    <template v-if="moveTargets(suite).length">
                      <label class="qa-sr-only" :for="`move-target-${suite.id}`">
                        Destination suite
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
                        {{ moving ? 'Moving…' : 'Move & delete suite' }}
                      </button>
                    </template>
                    <span v-else class="qa-muted">
                      Add another suite first — there is nowhere to move them.
                    </span>

                    <button
                      type="button"
                      class="qa-button qa-button--small qa-button--quiet"
                      @click="movingId = 0"
                    >
                      Cancel
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
