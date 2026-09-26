<script setup>
/**
 * Run detail — the main working screen.
 *
 * The case list is grouped by suite and every row carries its own status control, so a
 * forty-case run can be worked through without leaving this screen.
 */

import {computed, onBeforeUnmount, onMounted, ref, watch} from 'vue';
import {RouterLink} from 'vue-router';

import AssigneeDialog from '../components/AssigneeDialog.vue';
import AvatarStack from '../components/AvatarStack.vue';
import EmptyState from '../components/EmptyState.vue';
import PriorityDot from '../components/PriorityDot.vue';
import ProgressBar from '../components/ProgressBar.vue';
import RunCasesDialog from '../components/RunCasesDialog.vue';
import RunDetailsDialog from '../components/RunDetailsDialog.vue';
import StatusBadge from '../components/StatusBadge.vue';
import StatusControl from '../components/StatusControl.vue';
import {api, bootstrap} from '../api/client.js';
import {absoluteTime, relativeTime} from '../utils/format.js';
import {PRIORITIES, RESULT_STATUSES, RUN_STATUSES, statusLabel} from '../utils/status.js';
import {useCaseStore} from '../stores/cases.js';
import {useRunStore} from '../stores/runs.js';
import {useUiStore} from '../stores/ui.js';

const props = defineProps({
  id: {type: [String, Number], required: true}
});

const runStore = useRunStore();
const caseStore = useCaseStore();
const ui = useUiStore();

const runId = computed(() => Number(props.id));
const loading = ref(true);
const cloning = ref(false);
const runAssigneeDialogOpen = ref(false);
const savingRunAssignees = ref(false);
const runDetailsDialogOpen = ref(false);
const savingRunDetails = ref(false);
const runCasesDialogOpen = ref(false);
const savingRunCases = ref(false);
const loadingLibrary = ref(false);

const filters = ref({
  status: '',
  suite: '',
  priority: '',
  onlyMine: false,
  onlyUnassigned: false,
  onlyFailedLastRun: false
});

const isOpen = computed(() => runStore.run?.status === 'open');
const canTest = computed(() => Boolean(bootstrap.caps?.runTests) && isOpen.value);

/**
 * Whether this tester was put on the run, and may therefore claim its cases. Managers are
 * included so they can tidy up a board they oversee without joining the run.
 */
const canAssignSelf = computed(
  () =>
    canTest.value &&
    (Boolean(bootstrap.caps?.manageCases) ||
      (runStore.run?.assignees ?? []).some((person) => person.id === bootstrap.currentUser?.id))
);

const filtered = computed(() =>
  runStore.results.filter((result) => {
    if (filters.value.status && result.status !== filters.value.status) {
      return false;
    }

    if (filters.value.suite && String(result.case.suite_id) !== filters.value.suite) {
      return false;
    }

    if (filters.value.priority && result.case.priority !== filters.value.priority) {
      return false;
    }

    if (filters.value.onlyMine && result.tested_by?.id !== bootstrap.currentUser?.id) {
      return false;
    }

    if (filters.value.onlyUnassigned && (result.assignees?.length ?? 0) > 0) {
      return false;
    }

    if (
      filters.value.onlyFailedLastRun &&
      runStore.previousStatus[result.case.id]?.status !== 'fail'
    ) {
      return false;
    }

    return true;
  })
);

/**
 * The filtered results, grouped by suite in display order.
 */
const groups = computed(() => {
  const bySuite = new Map();

  filtered.value.forEach((result) => {
    const key = result.case.suite_id;

    if (!bySuite.has(key)) {
      bySuite.set(key, {id: key, name: result.case.suite_name, results: []});
    }

    bySuite.get(key).results.push(result);
  });

  return [...bySuite.values()];
});

const regressions = computed(() =>
  runStore.results.filter(
    (result) =>
      result.status === 'fail' && runStore.previousStatus[result.case.id]?.status === 'pass'
  )
);

const hasFilters = computed(
  () =>
    filters.value.status !== '' ||
    filters.value.suite !== '' ||
    filters.value.priority !== '' ||
    filters.value.onlyMine ||
    filters.value.onlyUnassigned ||
    filters.value.onlyFailedLastRun
);

/**
 * Loads the run, its results and the previous-run comparison.
 *
 * @returns {Promise<void>}
 */
async function load() {
  loading.value = true;

  try {
    await runStore.loadRun(runId.value);
    await Promise.all([
      runStore.loadPreviousStatus(runId.value),
      caseStore.loadSuites(),
      caseStore.loadUsers()
    ]);
    runStore.startPolling(runId.value);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('This run could not be loaded.', 'qa-runner'));
  } finally {
    loading.value = false;
  }
}

/**
 * Sets a result status optimistically.
 *
 * @param {Object} result Result row.
 * @param {string} status New status.
 * @returns {Promise<void>}
 */
async function setStatus(result, status) {
  try {
    await runStore.setStatus(result.id, status);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('That result could not be saved.', 'qa-runner'));
  }
}

/**
 * Opens the case picker, fetching the library the first time it is needed.
 *
 * The library is not part of the run's own payload and most visits to this screen never
 * ask for it, so it is fetched on demand rather than on load.
 *
 * @returns {Promise<void>}
 */
async function openRunCasesDialog() {
  runCasesDialogOpen.value = true;

  if (caseStore.cases.length) {
    return;
  }

  loadingLibrary.value = true;

  try {
    await caseStore.loadCases({active: true});
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The case library could not be loaded.', 'qa-runner'));
  } finally {
    loadingLibrary.value = false;
  }
}

/**
 * Applies the case picker's draft to the run.
 *
 * @param {Object} changes Cases to add and remove, as {add: [ids], remove: [ids]}.
 * @returns {Promise<void>}
 */
async function saveRunCases(changes) {
  savingRunCases.value = true;

  try {
    await runStore.setRunCases(runId.value, changes);
    runCasesDialogOpen.value = false;
    ui.toast(wp.i18n.__('Run cases updated.', 'qa-runner'));
  } catch (error) {
    // The dialog stays open on failure, so the draft is still there to retry or cancel.
    ui.toastError(error, wp.i18n.__('The run cases could not be saved.', 'qa-runner'));
  } finally {
    savingRunCases.value = false;
  }
}

/**
 * Saves the edited run metadata.
 *
 * @param {Object} changes Changed fields only, as {name, environment, version, notes}.
 * @returns {Promise<void>}
 */
async function saveRunDetails(changes) {
  savingRunDetails.value = true;

  try {
    await runStore.updateRun(runId.value, changes);
    runDetailsDialogOpen.value = false;
    ui.toast(wp.i18n.__('Run details updated.', 'qa-runner'));
  } catch (error) {
    // The dialog stays open on failure, so the draft is still there to retry or cancel.
    ui.toastError(error, wp.i18n.__('The run details could not be saved.', 'qa-runner'));
  } finally {
    savingRunDetails.value = false;
  }
}

/**
 * Applies the dialog's draft to the run.
 *
 * @param {Object} changes People to add and remove, as {add: [], remove: []}.
 * @returns {Promise<void>}
 */
async function saveRunAssignees(changes) {
  savingRunAssignees.value = true;

  try {
    await runStore.setRunAssignees(runId.value, changes);
    runAssigneeDialogOpen.value = false;
    ui.toast(wp.i18n.__('Run assignees updated.', 'qa-runner'));
  } catch (error) {
    // The dialog stays open on failure, so the draft is still there to retry or cancel.
    ui.toastError(error, wp.i18n.__('The run assignees could not be saved.', 'qa-runner'));
  } finally {
    savingRunAssignees.value = false;
  }
}

/**
 * Claims or releases one case for the current tester.
 *
 * @param {Object} result Result row.
 * @returns {Promise<void>}
 */
async function toggleAssignment(result) {
  try {
    await runStore.setAssignment(result.id, bootstrap.currentUser, !isMine(result));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('That assignment could not be saved.', 'qa-runner'));
  }
}

/**
 * Translated label for a run status.
 *
 * @param {string} status Run status.
 * @returns {string}
 */
function runStatusLabel(status) {
  return RUN_STATUSES.find((item) => item.value === status)?.label ?? status;
}

/**
 * Whether the current tester holds this case.
 *
 * @param {Object} result Result row.
 * @returns {boolean}
 */
function isMine(result) {
  return (result.assignees ?? []).some((person) => person.id === bootstrap.currentUser?.id);
}

/**
 * Completes the run. Always an explicit action — testers revisit results, so nothing
 * completes a run on its own when the last case is set.
 *
 * @returns {Promise<void>}
 */
async function completeRun() {
  try {
    await runStore.updateRun(runId.value, {status: 'completed'});
    runStore.stopPolling();
    ui.toast(wp.i18n.__('Run completed.', 'qa-runner'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The run could not be completed.', 'qa-runner'));
  }
}

/**
 * Reopens a completed run so results can be edited again.
 *
 * @returns {Promise<void>}
 */
async function reopenRun() {
  try {
    await runStore.updateRun(runId.value, {status: 'open'});
    runStore.startPolling(runId.value);
    ui.toast(wp.i18n.__('Run reopened.', 'qa-runner'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The run could not be reopened.', 'qa-runner'));
  }
}

/**
 * Clones the run into a fresh one with the same case selection.
 *
 * @returns {Promise<void>}
 */
async function cloneRun() {
  cloning.value = true;

  try {
    const clone = await api.runs.clone(runId.value);

    ui.toast(wp.i18n.__('Run cloned.', 'qa-runner'));
    window.location.hash = `#/runs/${clone.id}`;
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The run could not be cloned.', 'qa-runner'));
  } finally {
    cloning.value = false;
  }
}

/**
 * Clears every list filter.
 *
 * @returns {void}
 */
function clearFilters() {
  filters.value = {
    status: '',
    suite: '',
    priority: '',
    onlyMine: false,
    onlyUnassigned: false,
    onlyFailedLastRun: false
  };
}

watch(runId, load);

onMounted(load);

onBeforeUnmount(() => runStore.reset());
</script>

<template>
  <div class="qa-stack">
    <p v-if="loading" class="qa-skeleton">{{ __('Loading run…', 'qa-runner') }}</p>

    <template v-else-if="runStore.run">
      <div class="qa-page-head">
        <div class="qa-page-head__meta">
          <h2 class="qa-run-title">{{ runStore.run.name }}</h2>
          <p class="qa-subtitle">
            {{ __('environment:', 'qa-runner') }}
            <strong>{{ runStore.run.environment }}</strong> ·
            {{ __('version:', 'qa-runner') }}
            <strong>{{ runStore.run.version }}</strong> <br />
            {{ __('created by', 'qa-runner') }}
            <strong>{{ runStore.run.created_by.name }}</strong> &nbsp;
            <strong>
              <span :title="absoluteTime(runStore.run.created_at)">{{
                relativeTime(runStore.run.created_at)
              }}</span>
            </strong>
          </p>
          <p v-if="runStore.run.notes" class="qa-run-description">{{ runStore.run.notes }}</p>
        </div>

        <div class="qa-row">
          <button
            v-if="bootstrap.caps?.runTests"
            type="button"
            class="qa-button qa-button--quiet"
            @click="runDetailsDialogOpen = true"
          >
            {{ __('Edit details', 'qa-runner') }}
          </button>
          <button
            v-if="bootstrap.caps?.runTests"
            type="button"
            class="qa-button qa-button--quiet"
            :disabled="cloning"
            @click="cloneRun"
          >
            {{ cloning ? __('Cloning…', 'qa-runner') : __('Clone run', 'qa-runner') }}
          </button>
          <button
            v-if="bootstrap.caps?.runTests && isOpen"
            type="button"
            class="qa-button qa-button--primary"
            @click="completeRun"
          >
            {{ __('Complete run', 'qa-runner') }}
          </button>
          <button
            v-else-if="bootstrap.caps?.runTests && runStore.run.status === 'completed'"
            type="button"
            class="qa-button"
            @click="reopenRun"
          >
            {{ __('Reopen run', 'qa-runner') }}
          </button>
        </div>

        <RunDetailsDialog
          :open="runDetailsDialogOpen"
          :run="runStore.run"
          :saving="savingRunDetails"
          @close="runDetailsDialogOpen = false"
          @save="saveRunDetails"
        />
      </div>

      <div class="qa-card">
        <div class="qa-card__body qa-row" style="justify-content: space-between; gap: 24px">
          <ProgressBar :counts="runStore.run.counts" />
          <div class="qa-row" style="gap: 16px">
            <span class="qa-badge">{{ runStatusLabel(runStore.run.status) }}</span>

            <AvatarStack :people="runStore.run.assignees" />

            <button
              v-if="bootstrap.caps?.runTests"
              type="button"
              class="qa-button qa-button--small"
              @click="runAssigneeDialogOpen = true"
            >
              {{ __('Edit assignees', 'qa-runner') }}
            </button>
          </div>
        </div>

        <AssigneeDialog
          :open="runAssigneeDialogOpen"
          :title="__('Who is on this run', 'qa-runner')"
          :empty-text="
            __('No testers exist yet. Give somebody the QA Tester role first.', 'qa-runner')
          "
          :removal-warning="
            __(
              'Removing %s also drops the cases they claimed on this run. Adding them back will not restore those claims.',
              'qa-runner'
            )
          "
          :candidates="caseStore.users"
          :assigned="runStore.run.assignees"
          :saving="savingRunAssignees"
          @close="runAssigneeDialogOpen = false"
          @save="saveRunAssignees"
        />
      </div>

      <div v-if="!isOpen" class="qa-notice qa-notice--warning">
        {{
          sprintf(
            __('This run is %s. Results, comments and locks are read-only.', 'qa-runner'),
            runStatusLabel(runStore.run.status).toLowerCase()
          )
        }}
      </div>

      <div v-if="regressions.length" class="qa-notice qa-notice--error">
        <strong>{{
          sprintf(
            _n('%d regression.', '%d regressions.', regressions.length, 'qa-runner'),
            regressions.length
          )
        }}</strong>
        {{
          sprintf(
            _n(
              'This case passed in the previous run and fails now: %s',
              'These cases passed in the previous run and fail now: %s',
              regressions.length,
              'qa-runner'
            ),
            regressions.map((result) => result.case.title).join(', ')
          )
        }}
      </div>

      <div class="qa-card">
        <div class="qa-card__head" style="flex-wrap: wrap">
          <div class="qa-row">
            <label class="qa-sr-only" for="filter-status">{{ __('Status', 'qa-runner') }}</label>
            <select
              id="filter-status"
              v-model="filters.status"
              class="qa-select"
              style="width: auto"
            >
              <option value="">{{ __('All statuses', 'qa-runner') }}</option>
              <option v-for="status in RESULT_STATUSES" :key="status.value" :value="status.value">
                {{ statusLabel(status.value) }}
              </option>
            </select>

            <label class="qa-sr-only" for="filter-suite">{{ __('Suite', 'qa-runner') }}</label>
            <select id="filter-suite" v-model="filters.suite" class="qa-select" style="width: auto">
              <option value="">{{ __('All suites', 'qa-runner') }}</option>
              <option v-for="suite in caseStore.suites" :key="suite.id" :value="String(suite.id)">
                {{ suite.name }}
              </option>
            </select>

            <label class="qa-sr-only" for="filter-priority">{{
              __('Priority', 'qa-runner')
            }}</label>
            <select
              id="filter-priority"
              v-model="filters.priority"
              class="qa-select"
              style="width: auto"
            >
              <option value="">{{ __('All priorities', 'qa-runner') }}</option>
              <option v-for="priority in PRIORITIES" :key="priority.value" :value="priority.value">
                {{ priority.label }}
              </option>
            </select>

            <label class="qa-checkbox">
              <input v-model="filters.onlyMine" type="checkbox" />
              <span>{{ __('Only mine', 'qa-runner') }}</span>
            </label>

            <label class="qa-checkbox">
              <input v-model="filters.onlyUnassigned" type="checkbox" />
              <span>{{ __('Unassigned only', 'qa-runner') }}</span>
            </label>

            <label class="qa-checkbox">
              <input v-model="filters.onlyFailedLastRun" type="checkbox" />
              <span>{{ __('Only failed last run', 'qa-runner') }}</span>
            </label>
          </div>

          <div class="qa-row">
            <button
              v-if="hasFilters"
              type="button"
              class="qa-button qa-button--small qa-button--quiet"
              @click="clearFilters"
            >
              {{ __('Clear filters', 'qa-runner') }}
            </button>

            <button
              v-if="canTest"
              type="button"
              class="qa-button qa-button--small"
              @click="openRunCasesDialog"
            >
              {{ __('Edit cases', 'qa-runner') }}
            </button>
          </div>
        </div>

        <RunCasesDialog
          :open="runCasesDialogOpen"
          :results="runStore.results"
          :library="caseStore.activeCases"
          :suites="caseStore.suites"
          :loading="loadingLibrary"
          :saving="savingRunCases"
          @close="runCasesDialogOpen = false"
          @save="saveRunCases"
        />

        <EmptyState
          v-if="!filtered.length"
          :title="
            hasFilters
              ? __('No cases match these filters.', 'qa-runner')
              : __('This run has no cases yet.', 'qa-runner')
          "
          :description="hasFilters ? __('Clear a filter to see more of the run.', 'qa-runner') : ''"
        />

        <div v-for="group in groups" :key="group.id" class="qa-suite-group">
          <div class="qa-card__head">
            <h3>{{ group.name }}</h3>
            <span class="qa-muted qa-count">
              {{
                sprintf(
                  _n('%d case', '%d cases', group.results.length, 'qa-runner'),
                  group.results.length
                )
              }}
            </span>
          </div>

          <div v-for="result in group.results" :key="result.id" class="qa-case-row">
            <div class="qa-case-row__main">
              <div class="qa-case-row__title">
                <PriorityDot :priority="result.case.priority" />
                <RouterLink :to="`/runs/${runId}/cases/${result.case.id}`">
                  {{ result.case.title }}
                </RouterLink>
              </div>
              <div class="qa-case-row__meta">
                <AvatarStack v-if="result.assignees?.length" :people="result.assignees" />

                <span v-if="result.tested_by">
                  {{ result.tested_by.name }}
                  <span :title="absoluteTime(result.tested_at)">{{
                    relativeTime(result.tested_at)
                  }}</span>
                </span>
                <span v-else>{{ __('Not tested yet', 'qa-runner') }}</span>

                <span v-if="result.comment_count" class="qa-badge">
                  {{
                    sprintf(
                      _n('%d comment', '%d comments', result.comment_count, 'qa-runner'),
                      result.comment_count
                    )
                  }}
                </span>

                <span v-if="result.open_issue_count" class="qa-badge qa-badge--issue">
                  {{
                    sprintf(
                      _n('%d open issue', '%d open issues', result.open_issue_count, 'qa-runner'),
                      result.open_issue_count
                    )
                  }}
                </span>

                <span
                  v-if="
                    result.in_progress_by && result.in_progress_by.id !== bootstrap.currentUser?.id
                  "
                  class="qa-badge qa-badge--lock"
                >
                  {{ sprintf(__('%s is testing this', 'qa-runner'), result.in_progress_by.name) }}
                </span>

                <span
                  v-if="runStore.previousStatus[result.case.id]"
                  class="qa-muted"
                  :title="
                    sprintf(
                      __('Previous run: %s', 'qa-runner'),
                      runStore.previousStatus[result.case.id].run_name
                    )
                  "
                >
                  {{
                    sprintf(
                      __('Last run: %s', 'qa-runner'),
                      statusLabel(runStore.previousStatus[result.case.id].status)
                    )
                  }}
                </span>
              </div>
            </div>

            <div class="qa-case-row__controls">
              <button
                v-if="canAssignSelf"
                type="button"
                class="qa-button qa-button--small qa-button--quiet"
                :title="
                  isMine(result)
                    ? __('Take yourself off this case', 'qa-runner')
                    : __('Claim this case', 'qa-runner')
                "
                @click="toggleAssignment(result)"
              >
                {{ isMine(result) ? __('Unassign me', 'qa-runner') : __('Assign me', 'qa-runner') }}
              </button>

              <StatusControl
                v-if="canTest"
                :model-value="result.status"
                :case-title="result.case.title"
                @update:model-value="setStatus(result, $event)"
              />
              <StatusBadge v-else :status="result.status" />
            </div>
          </div>
        </div>
      </div>
    </template>

    <EmptyState
      v-else
      :title="__('That run could not be found.', 'qa-runner')"
      :description="__('It may have been deleted.', 'qa-runner')"
    >
      <RouterLink class="qa-button" to="/">{{ __('Back to runs', 'qa-runner') }}</RouterLink>
    </EmptyState>
  </div>
</template>
